<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

use function array_filter;
use function array_values;
use function file_get_contents;
use function getcwd;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;

/**
 * Builds the declared cache graph: one record per resource, its declarations nested under it
 *
 * Every edge starts at the resource that declares it, so a record carries no `from`.
 * The record's shape is the `resource` definition in `docs/schemas/graph/dump.json`.
 *
 * @psalm-type Target = array{uri: string, template?: string, implicit?: string}
 * @psalm-type Method = array{source: string, cacheableResponse?: true, embed?: list<array{rel: string, usage?: string, uri: string, template?: string}>, dependsOn?: list<array{uri: string, template?: string}>, refresh?: list<Target>, purge?: list<Target>}
 * @psalm-type Node = array{uri: string, source: string, cacheable?: array{expiry: string, expirySecond: int, expiryAt: string, update: bool, type: string}, donutCache?: true, cacheableResponse?: true, httpCache?: string, methods?: array<string, Method>, dynamic?: list<string>}
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class GraphBuilder
{
    /** Methods the command interceptors bind to: they refresh the resource's own onGet */
    private const WRITE_METHODS = ['onPut', 'onPost', 'onPatch', 'onDelete'];

    private readonly string $cwd;

    /** @var array<string, string> node URIs by UriConverter::key(), for the build in progress */
    private array $nodeUris = [];

    public function __construct(
        private readonly UriConverter $uriConverter = new UriConverter(),
        string|null $cwd = null,
    ) {
        $this->cwd = str_replace('\\', '/', $cwd ?? (string) getcwd());
    }

    /**
     * @param array<class-string<ResourceObject>, ReflectionClass<ResourceObject>> $classes
     *
     * @return array{namespaces: array<string, string>, nodes: list<Node>}
     */
    public function build(array $classes): array
    {
        $namespace = null;
        $resources = [];
        foreach ($classes as $ref) {
            $converted = $this->uriConverter->fromClass($ref->getName());
            if ($converted === null) {
                continue;
            }

            $namespace ??= $converted['namespace'];
            if ($converted['namespace'] !== $namespace) {
                throw new RuntimeException(sprintf('One application per graph: found %s and %s', $namespace, $converted['namespace']));
            }

            $resources[] = [$ref, $converted['uri']];
        }

        $this->nodeUris = [];
        foreach ($resources as [, $uri]) {
            $this->nodeUris[$this->uriConverter->key($uri)] = $uri;
        }

        $nodes = [];
        foreach ($resources as [$ref, $uri]) {
            $nodes[] = $this->node($ref, $uri);
        }

        usort($nodes, static fn (array $left, array $right): int => $left['uri'] <=> $right['uri']);

        return ['namespaces' => $namespace === null ? [] : ['self' => $namespace], 'nodes' => $nodes];
    }

    /**
     * A declared URI named by the node it reaches, another spelling kept in `template`
     *
     * @return array{uri: string, template?: string}
     */
    private function target(string $declared, string $base): array
    {
        $resolved = $this->uriConverter->resolve($declared, $base);
        $uri = $this->nodeUris[$this->uriConverter->key($resolved['uri'])] ?? $resolved['uri'];

        return $uri === $resolved['uri'] ? $resolved : ['uri' => $uri, 'template' => $resolved['template'] ?? $resolved['uri']];
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return Node
     */
    private function node(ReflectionClass $ref, string $uri): array
    {
        $node = ['uri' => $uri, 'source' => $this->source((string) $ref->getFileName(), (int) $ref->getStartLine())] + $this->classDeclarations($ref);
        $cacheable = isset($node['cacheable']);
        $classResponse = isset($node['cacheableResponse']);

        $methods = [];
        $donut = $classResponse || isset($node['donutCache']);
        $classSource = (string) file_get_contents((string) $ref->getFileName());
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with($method->getName(), 'on') || $method->getDeclaringClass()->getName() === ResourceObject::class) {
                continue;
            }

            $declared = $this->method($method, $uri, $cacheable, $classResponse, $donut, $classSource);
            if ($declared !== null) {
                $methods[$method->getName()] = $declared;
            }
        }

        if ($methods !== []) {
            $node['methods'] = $methods;
        }

        $dynamic = $this->dynamic((string) $ref->getFileName());
        if ($dynamic !== []) {
            $node['dynamic'] = $dynamic;
        }

        return $node;
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return array{cacheable?: array{expiry: string, expirySecond: int, expiryAt: string, update: bool, type: string}, donutCache?: true, cacheableResponse?: true, httpCache?: string}
     */
    private function classDeclarations(ReflectionClass $ref): array
    {
        $declared = [];
        $cacheable = $this->attribute($ref, Cacheable::class);
        if ($cacheable !== null) {
            $declared['cacheable'] = [
                'expiry' => $cacheable->expiry,
                'expirySecond' => $cacheable->expirySecond,
                'expiryAt' => $cacheable->expiryAt,
                'update' => $cacheable->update,
                'type' => $cacheable->type,
            ];
        }

        if ($this->attribute($ref, DonutCache::class) !== null) {
            $declared['donutCache'] = true;
        }

        if ($this->attribute($ref, CacheableResponse::class) !== null) {
            $declared['cacheableResponse'] = true;
        }

        $cacheControl = $ref->getAttributes(AbstractCacheControl::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($cacheControl !== []) {
            $declared['httpCache'] = (string) $cacheControl[0]->newInstance();
        }

        return $declared;
    }

    /** @return Method|null null when the method declares nothing the cache acts on */
    private function method(ReflectionMethod $method, string $uri, bool $cacheable, bool $classResponse, bool $donut, string $classSource): array|null
    {
        $embed = [];
        foreach ($method->getAttributes(Embed::class) as $attribute) {
            $instance = $attribute->newInstance();
            $declaredEmbed = ['rel' => $instance->rel];
            if ($donut) {
                $declaredEmbed['usage'] = $this->referenced($classSource, $instance->rel) ? 'referenced' : 'unreferenced';
            }

            $embed[] = $declaredEmbed + $this->target($instance->src, $uri);
        }

        // The command interceptors refresh the resource's own onGet before any #[Refresh]/#[Purge].
        $refresh = [];
        $implicit = $this->implicitRefresh($method, $cacheable, $classResponse);
        if ($implicit !== null) {
            $refresh[] = ['uri' => $uri, 'implicit' => $implicit];
        }

        foreach ($method->getAttributes(Refresh::class) as $attribute) {
            $refresh[] = $this->target($attribute->newInstance()->uri, $uri);
        }

        $purge = [];
        foreach ($method->getAttributes(Purge::class) as $attribute) {
            $purge[] = $this->target($attribute->newInstance()->uri, $uri);
        }

        $declared = [];
        if ($method->getAttributes(CacheableResponse::class) !== []) {
            $declared['cacheableResponse'] = true;
        }

        $declared += array_filter(['embed' => $embed, 'dependsOn' => $this->dependsOn($method, $uri), 'refresh' => $refresh, 'purge' => $purge]);
        if ($declared === []) {
            return null;
        }

        return ['source' => $this->source((string) $method->getFileName(), (int) $method->getStartLine())] + $declared;
    }

    /**
     * #[DependsOn] is read on onGet() only, together with each onGet() it overrides
     *
     * @return list<array{uri: string, template?: string}>
     */
    private function dependsOn(ReflectionMethod $method, string $uri): array
    {
        if ($method->getName() !== 'onGet') {
            return [];
        }

        $targets = [];
        for ($ref = $method->getDeclaringClass(); $ref !== false && $ref->hasMethod('onGet'); $ref = $ref->getParentClass()) {
            foreach ($ref->getMethod('onGet')->getAttributes(DependsOn::class) as $attribute) {
                $target = $this->target($attribute->newInstance()->uri, $uri);
                $targets[$target['template'] ?? $target['uri']] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * Whether the class reads this embed relation
     *
     * A donut page reads a relation through `$this['rel']` (or `$this->body['rel']`),
     * often from a helper called by onGet; an unread one is a hole, rendered per request
     * and left out of the stored template's tags. The scan is a heuristic: a relation can
     * be read indirectly, as Series does when it replaces `body['tags']` with the child's
     * body array, and a read relation still contributes tags only when the child ran with
     * them.
     */
    private function referenced(string $classSource, string $rel): bool
    {
        $quoted = preg_quote($rel, '/');
        $read = '/\$this\s*\[\s*[\'"]' . $quoted . '[\'"]\s*\]/';
        $body = '/->body\s*\[\s*[\'"]' . $quoted . '[\'"]\s*\]/';

        return preg_match($read, $classSource) === 1 || preg_match($body, $classSource) === 1;
    }

    /** @return string|null the attribute that makes the write refresh its own resource */
    private function implicitRefresh(ReflectionMethod $method, bool $cacheable, bool $classResponse): string|null
    {
        if ($method->getAttributes(RefreshCache::class) !== []) {
            return 'RefreshCache';
        }

        $isWrite = false;
        foreach (self::WRITE_METHODS as $prefix) {
            $isWrite = $isWrite || str_starts_with($method->getName(), $prefix);
        }

        if (! $isWrite) {
            return null;
        }

        if ($cacheable) {
            return 'Cacheable';
        }

        if ($classResponse || $method->getAttributes(CacheableResponse::class) !== []) {
            return 'CacheableResponse';
        }

        return null;
    }

    /**
     * Source-text hints that the declarations may not tell the whole story
     *
     * A hint is a reason to read the code; its absence proves nothing.
     *
     * @return list<string>
     */
    private function dynamic(string $file): array
    {
        $code = (string) file_get_contents($file);
        $hints = [];
        if (preg_match('/SURROGATE_KEY|surrogate-key/i', $code)) {
            $hints[] = 'surrogate-key';
        }

        if (preg_match('/\b(?:ResourceInject|ResourceInterface)\b/', $code)) {
            $hints[] = 'resource-request';
        }

        return $hints;
    }

    private function source(string $file, int $line): string
    {
        // Forward slashes on every OS, so a source reads and matches the same on Windows.
        $file = str_replace('\\', '/', $file);
        $relative = str_starts_with($file, $this->cwd . '/') ? substr($file, strlen($this->cwd) + 1) : $file;

        return "{$relative}:{$line}";
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     * @param class-string<T>                 $name
     *
     * @return T|null
     *
     * @template T of object
     */
    private function attribute(ReflectionClass $ref, string $name): object|null
    {
        $attributes = $ref->getAttributes($name);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
