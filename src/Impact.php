<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_shift;
use function str_starts_with;

/**
 * What a write or an invalidation reaches, transitively, through the declared graph
 *
 * A line per reached URI, in breadth-first order, each reached once by its shortest path.
 * The shape is `docs/schemas/graph/impact.json`.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type ImpactLine = array{uri: string, via: string, by: string|null, depth: int, cached: bool, source: string|null}
 */
final class Impact
{
    private readonly UriConverter $converter;

    /** @var array<string, Node> keyed by UriConverter::key(), the value carries the canonical URI */
    private array $nodes = [];

    /** @var array<string, list<string>> child key => resources that embed it */
    private array $embeddedBy = [];

    /** @var array<string, list<string>> target key => resources whose onGet() declares #[DependsOn] on it */
    private array $dependedBy = [];

    /** @param list<Node> $nodes */
    public function __construct(array $nodes)
    {
        $this->converter = new UriConverter();
        foreach ($nodes as $node) {
            $this->nodes[$this->converter->key($node['uri'])] = $node;
            foreach ($node['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $this->embeddedBy[$this->converter->key($embed['uri'])][] = $node['uri'];
                }

                foreach ($method['dependsOn'] ?? [] as $target) {
                    $this->dependedBy[$this->converter->key($target['uri'])][] = $node['uri'];
                }
            }
        }
    }

    /** The URI an impact of `$uri` starts from: the scanned resource's spelling, or `$uri` without its query */
    public function start(string $uri): string
    {
        $uri = $this->converter->resolve($uri, $uri)['uri'];

        return $this->nodes[$this->converter->key($uri)]['uri'] ?? $uri;
    }

    /**
     * @param string      $uri    an instance (`app://self/user?id=1`) or a node URI
     * @param string|null $method a write method of `$uri`: start from what it refreshes and purges
     *
     * @return list<ImpactLine>
     */
    public function __invoke(string $uri, string|null $method = null): array
    {
        $start = $this->start($uri);
        $key = $this->converter->key($start);
        /** @var list<array{uri: string, via: string, by: string|null, depth: int}> $queue */
        $queue = $method === null ? [['uri' => $start, 'via' => 'target', 'by' => null, 'depth' => 0]] : $this->writeTargets($key, $start, $method);

        $lines = [];
        $seen = [];
        while ($queue !== []) {
            $next = array_shift($queue);
            $key = $this->converter->key($next['uri']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $node = $this->nodes[$key] ?? null;
            $uri = $node['uri'] ?? $next['uri'];
            $line = [
                'uri' => $uri,
                'via' => $next['via'],
                'by' => $next['by'],
                'depth' => $next['depth'],
                'cached' => $node !== null && self::isCached($node),
                'source' => $node['source'] ?? null,
            ];
            $lines[] = $line;
            foreach ($this->embeddedBy[$key] ?? [] as $embedder) {
                $queue[] = ['uri' => $embedder, 'via' => 'embed', 'by' => $uri, 'depth' => $next['depth'] + 1];
            }

            // A declaration carries the exact tag of the URI it names, so it reaches its resource only when that URI itself is purged.
            if ($next['via'] === 'embed' || $next['via'] === 'dependsOn') {
                continue;
            }

            foreach ($this->dependedBy[$key] ?? [] as $dependent) {
                $queue[] = ['uri' => $dependent, 'via' => 'dependsOn', 'by' => $uri, 'depth' => $next['depth'] + 1];
            }
        }

        return $lines;
    }

    /** @return list<array{uri: string, via: string, by: string, depth: int}> what the write method refreshes, then what it purges */
    private function writeTargets(string $key, string $start, string $method): array
    {
        $declared = $this->nodes[$key]['methods'][$method] ?? [];
        $targets = [];
        foreach ($declared['refresh'] ?? [] as $target) {
            $targets[] = ['uri' => $target['uri'], 'via' => 'refresh', 'by' => $start, 'depth' => 1];
        }

        foreach ($declared['purge'] ?? [] as $target) {
            $targets[] = ['uri' => $target['uri'], 'via' => 'purge', 'by' => $start, 'depth' => 1];
        }

        return $targets;
    }

    /**
     * Whether the resource declares a cache at all, on the class or on onGet()
     *
     * @param Node $node
     */
    public static function isCached(array $node): bool
    {
        return self::caches($node) !== [];
    }

    /**
     * The cache attributes the resource declares, as named in the code; only #[Cacheable] expires by time, so only it carries a TTL
     *
     * @param Node $node
     *
     * @return list<string> `cacheable, ttl: medium`, `cacheable view, ttl: medium`, `cacheable response`, `donut cache`
     */
    public static function caches(array $node): array
    {
        $caches = [];
        $cacheable = $node['cacheable'] ?? null;
        if ($cacheable !== null) {
            $caches[] = ($cacheable['type'] === 'view' ? 'cacheable view' : 'cacheable') . ', ttl: ' . self::ttl($cacheable);
        }

        if (isset($node['cacheableResponse']) || self::onGetCacheableResponse($node)) {
            $caches[] = 'cacheable response';
        }

        if (isset($node['donutCache'])) {
            $caches[] = 'donut cache';
        }

        return $caches;
    }

    /** @param array{expiry: string, expirySecond: int, expiryAt: string, update: bool, type: string} $cacheable */
    private static function ttl(array $cacheable): string
    {
        if ($cacheable['expirySecond'] > 0) {
            return $cacheable['expirySecond'] . 's';
        }

        return $cacheable['expiryAt'] !== '' ? 'until ' . $cacheable['expiryAt'] : $cacheable['expiry'];
    }

    /** @param Node $node */
    private static function onGetCacheableResponse(array $node): bool
    {
        foreach ($node['methods'] ?? [] as $name => $method) {
            if (str_starts_with($name, 'onGet') && isset($method['cacheableResponse'])) {
                return true;
            }
        }

        return false;
    }
}
