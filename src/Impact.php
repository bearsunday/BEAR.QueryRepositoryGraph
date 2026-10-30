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

    /** @var array<string, list<array{uri: string, role: string|null}>> child key => embedders */
    private array $embeddedBy = [];

    /** @param list<Node> $nodes */
    public function __construct(array $nodes)
    {
        $this->converter = new UriConverter();
        foreach ($nodes as $node) {
            $this->nodes[$this->converter->key($node['uri'])] = $node;
            foreach ($node['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $this->embeddedBy[$this->converter->key($embed['uri'])][] = [
                        'uri' => $node['uri'],
                        'role' => $this->role($node, $embed),
                    ];
                }
            }
        }
    }

    /**
     * @param string      $uri    an instance (`app://self/user?id=1`) or a node URI
     * @param string|null $method a write method of `$uri`: start from what it refreshes and purges
     *
     * @return list<ImpactLine>
     */
    public function __invoke(string $uri, string|null $method = null): array
    {
        $uri = $this->converter->resolve($uri, $uri)['uri'];
        $key = $this->converter->key($uri);
        $start = $this->nodes[$key]['uri'] ?? $uri;
        /** @var list<array{uri: string, via: string, by: string|null, depth: int, role?: string}> $queue */
        $queue = [];
        if ($method === null) {
            $queue[] = ['uri' => $start, 'via' => 'target', 'by' => null, 'depth' => 0];
        } else {
            $declared = $this->nodes[$key]['methods'][$method] ?? [];
            foreach ($declared['refresh'] ?? [] as $target) {
                $queue[] = ['uri' => $this->canonical($target['uri']), 'via' => 'refresh', 'by' => $start, 'depth' => 1];
            }

            foreach ($declared['purge'] ?? [] as $target) {
                $queue[] = ['uri' => $this->canonical($target['uri']), 'via' => 'purge', 'by' => $start, 'depth' => 1];
            }
        }

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
                'cached' => $node !== null && $this->isCached($node),
                'source' => $node['source'] ?? null,
            ];
            if (isset($next['role'])) {
                $line['role'] = $next['role'];
            }

            $lines[] = $line;
            foreach ($this->embeddedBy[$key] ?? [] as $embedder) {
                $parent = [
                    'uri' => $embedder['uri'],
                    'via' => 'embed',
                    'by' => $uri,
                    'depth' => $next['depth'] + 1,
                ];
                if ($embedder['role'] !== null) {
                    $parent['role'] = $embedder['role'];
                }

                $queue[] = $parent;
            }
        }

        return $lines;
    }

    /**
     * Whether the embedding page stores this child as a material or renders it as a hole
     *
     * @param Node $node
     * @param array{rel: string, uri: string, template?: string, usage?: string} $embed
     */
    private function role(array $node, array $embed): string|null
    {
        if (! isset($node['cacheableResponse']) && ! isset($node['donutCache'])) {
            return null;
        }

        $usage = $embed['usage'] ?? null;
        if ($usage === null) {
            return null;
        }

        return $usage === 'referenced' ? 'material' : 'hole';
    }

    /** The node URI a declaration points at, or the declaration itself when no node serves it. */
    private function canonical(string $uri): string
    {
        return $this->nodes[$this->converter->key($uri)]['uri'] ?? $uri;
    }

    /** @param Node $node */
    private function isCached(array $node): bool
    {
        if (isset($node['cacheable']) || isset($node['donutCache']) || isset($node['cacheableResponse'])) {
            return true;
        }

        foreach ($node['methods'] ?? [] as $name => $method) {
            if (str_starts_with($name, 'onGet') && isset($method['cacheableResponse'])) {
                return true;
            }
        }

        return false;
    }
}
