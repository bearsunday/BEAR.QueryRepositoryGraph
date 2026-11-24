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
use BEAR\Resource\Annotation\AbstractResourceLayout;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function getcwd;
use function in_array;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function usort;

/**
 * Builds the declared cache graph: one record per resource, its declarations nested under it
 *
 * Every edge starts at the resource that declares it, so a record carries no `from`.
 * The record's shape is the `resource` definition in `docs/schemas/graph/dump.json`.
 *
 * @psalm-type Target = array{uri: string, template?: string, implicit?: string, collection?: true}
 * @psalm-type EmbedTarget = array{rel: string, uri: string, template?: string}
 * @psalm-type Method = array{source: string, cacheableResponse?: true, embed?: list<EmbedTarget>, dependsOn?: list<array{uri: string, template?: string}>, refresh?: list<Target>, purge?: list<Target>}
 * @psalm-type Layout = array{name: string, class: string, source: string, embed: list<EmbedTarget>}
 * @psalm-type Node = array{uri: string, source: string, cacheable?: array{expiry: string, expirySecond: int, expiryAt: string, type: string}, donutCache?: true, cacheableResponse?: true, httpCache?: string, layout?: Layout, methods?: array<string, Method>}
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class GraphBuilder
{
    /** The handlers BEAR.Resource dispatches a write to, in any letter case: the command interceptor binds to these alone */
    private const WRITE_METHODS = ['onput', 'onpost', 'onpatch', 'ondelete'];

    private const COLLECTION = 'collection';

    private readonly string $cwd;

    /** @var array<string, string> node URIs by UriConverter::key(), for the build in progress */
    private array $nodeUris = [];

    public function __construct(
        private readonly UriConverter $uriConverter,
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
     * A declared URI named by the node it reaches, or for a URI no scanned resource serves by its first spelling; another spelling kept in `template`
     *
     * @return array{uri: string, template?: string}
     */
    private function target(string $declared, string $base): array
    {
        $resolved = $this->uriConverter->resolve($declared, $base);
        $uri = $this->nodeUris[$this->uriConverter->key($resolved['uri'])] ??= $resolved['uri'];

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
        $layout = $this->layout($ref, $uri);
        if ($layout !== null) {
            $node['layout'] = $layout;
        }

        $cacheable = isset($node['cacheable']);
        $classResponse = isset($node['cacheableResponse']);

        $methods = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with(strtolower($method->getName()), 'on') || $method->getDeclaringClass()->getName() === ResourceObject::class) {
                continue;
            }

            $declared = $this->method($ref, $method, $uri, $cacheable, $classResponse);
            if ($declared !== null) {
                $methods[$method->getName()] = $declared;
            }
        }

        if ($methods !== []) {
            $node['methods'] = $methods;
        }

        return $node;
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return array{cacheable?: array{expiry: string, expirySecond: int, expiryAt: string, type: string}, donutCache?: true, cacheableResponse?: true, httpCache?: string}
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

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return Layout|null
     *
     * @throws RuntimeException when the class declares more than one layout, a layout without an EMBED of its own, or a rel
     *                          both its layout and an onGet() #[Embed] declare, all of which BEAR.Resource rejects too.
     */
    private function layout(ReflectionClass $ref, string $uri): array|null
    {
        $attributes = $ref->getAttributes(AbstractResourceLayout::class, ReflectionAttribute::IS_INSTANCEOF);
        // BEAR.Resource binds a layout to onGet(): a class without one embeds nothing through it.
        if ($attributes === [] || ! $ref->hasMethod('onGet')) {
            return null;
        }

        if (count($attributes) > 1) {
            throw new RuntimeException(sprintf('%s has more than one layout', $ref->getName()));
        }

        /** @var class-string<AbstractResourceLayout> $name IS_INSTANCEOF matched it */
        $name = $attributes[0]->getName();
        $layout = new ReflectionClass($name);
        $constant = $layout->getReflectionConstant('EMBED');
        if ($constant === false || $constant->getDeclaringClass()->getName() === AbstractResourceLayout::class) {
            throw new RuntimeException(sprintf('%s, the layout of %s, declares no EMBED of its own', $layout->getName(), $ref->getName()));
        }

        /** @var array<string, string> $parts */
        $parts = $constant->getValue();
        $methodRels = array_map(static fn (ReflectionAttribute $embed): string => $embed->newInstance()->rel, $ref->getMethod('onGet')->getAttributes(Embed::class));
        $embed = [];
        foreach ($parts as $rel => $src) {
            if (in_array($rel, $methodRels, true)) {
                throw new RuntimeException(sprintf('%s declares rel "%s" in both its layout and a method #[Embed]', $ref->getName(), $rel));
            }

            $embed[] = ['rel' => $rel] + $this->target($src, $uri);
        }

        return [
            'name' => $layout->getShortName(),
            'class' => $layout->getName(),
            'source' => $this->source((string) $layout->getFileName(), (int) $layout->getStartLine()),
            'embed' => $embed,
        ];
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return Method|null null when the method declares nothing the cache acts on
     */
    private function method(ReflectionClass $ref, ReflectionMethod $method, string $uri, bool $cacheable, bool $classResponse): array|null
    {
        $embed = [];
        foreach ($method->getAttributes(Embed::class) as $attribute) {
            $instance = $attribute->newInstance();
            $embed[] = ['rel' => $instance->rel] + $this->target($instance->src, $uri);
        }

        $declared = [];
        if ($method->getAttributes(CacheableResponse::class) !== []) {
            $declared['cacheableResponse'] = true;
        }

        // #[Refresh], #[Purge] and #[RefreshCache] act on a write handler alone.
        $writes = in_array(strtolower($method->getName()), self::WRITE_METHODS, true) ? $this->writes($ref, $method, $uri, $cacheable, $classResponse) : [];
        $declared += array_filter(['embed' => $embed, 'dependsOn' => $this->dependsOn($method, $uri)] + $writes);
        if ($declared === []) {
            return null;
        }

        return ['source' => $this->source((string) $method->getFileName(), (int) $method->getStartLine())] + $declared;
    }

    /**
     * What a write invalidates: its own key first, refreshed where its GET is stored and otherwise purged, then what it names
     *
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return array{refresh: list<Target>, purge: list<Target>}
     */
    private function writes(ReflectionClass $ref, ReflectionMethod $method, string $uri, bool $cacheable, bool $classResponse): array
    {
        $refresh = [];
        $purge = [];
        $implicit = $this->implicitRefresh($method, $cacheable, $classResponse);
        if ($implicit !== null) {
            $refresh[] = ['uri' => $uri, 'implicit' => $implicit];
        }

        if ($implicit === null && $ref->hasMethod('onGet')) {
            $purge[] = ['uri' => $uri, 'implicit' => 'onGet'];
        }

        foreach ($method->getAttributes(Refresh::class) as $attribute) {
            $refresh[] = $this->target($attribute->newInstance()->uri, $uri);
        }

        foreach ($method->getAttributes(Purge::class) as $attribute) {
            $purge[] = $this->target($attribute->newInstance()->uri, $uri);
        }

        return ['refresh' => $refresh, 'purge' => [...$purge, ...$this->collections($ref, $uri)]];
    }

    /**
     * The lists each #[Link(rel: 'collection')] on onGet() names, which every write to the resource purges
     *
     * Read as BEAR.Resource's Linker reads links, without walking parent classes. A path-only href (`/posts`, `{/kind}/posts`)
     * takes the resource's scheme and host; any other href that is not a resource URI purges nothing.
     *
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return list<Target>
     */
    private function collections(ReflectionClass $ref, string $uri): array
    {
        if (! $ref->hasMethod('onGet')) {
            return [];
        }

        $scheme = substr($uri, 0, (int) strpos($uri, '://'));
        $targets = [];
        foreach ($ref->getMethod('onGet')->getAttributes(Link::class) as $attribute) {
            $link = $attribute->newInstance();
            $href = $this->isPathOnly($link->href) ? "{$scheme}://self{$link->href}" : $link->href;
            if ($link->rel !== self::COLLECTION || ! str_contains($href, '://')) {
                continue;
            }

            $target = $this->target($href, $uri) + ['collection' => true];
            $targets[$target['template'] ?? $target['uri']] = $target;
        }

        return array_values($targets);
    }

    /** A path, or a template whose first part expands to one, but not a scheme-relative `//host` reference */
    private function isPathOnly(string $href): bool
    {
        return (str_starts_with($href, '/') && ! str_starts_with($href, '//')) || str_starts_with($href, '{/');
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

    /** @return string|null the attribute that makes the write refresh its own resource */
    private function implicitRefresh(ReflectionMethod $method, bool $cacheable, bool $classResponse): string|null
    {
        if ($method->getAttributes(RefreshCache::class) !== []) {
            return 'RefreshCache';
        }

        if ($cacheable) {
            return 'Cacheable';
        }

        if ($classResponse || $method->getAttributes(CacheableResponse::class) !== []) {
            return 'CacheableResponse';
        }

        return null;
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
