<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\Resource\ResourceObject;
use Ray\WebContextParam\Annotation\AbstractWebContextParam;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function array_keys;
use function array_map;
use function array_unique;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function parse_str;
use function parse_url;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function strval;
use function uri_template;

use const PHP_URL_QUERY;

/**
 * #[DependsOn] declarations whose tag no write purges, or that purge more than they should
 *
 * None of these fails at run time, so only the declarations show it.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type Target from GraphBuilder
 * @psalm-type Warning = array{warning: string, resource: string, source: string, dependsOn?: string, message: string}
 * @psalm-type Purged = array{uri: string, keys: list<string>}
 */
final class DependsOnWarnings
{
    private readonly UriConverter $converter;

    public function __construct(private readonly NodeDeclarationsInterface $declarations)
    {
        $this->converter = new UriConverter();
    }

    /**
     * @param array<class-string<ResourceObject>, ReflectionClass<ResourceObject>> $classes
     * @param list<Node>                                                           $nodes
     *
     * @return list<Warning>
     */
    public function __invoke(array $classes, array $nodes): array
    {
        $refs = [];
        foreach ($classes as $ref) {
            $converted = $this->converter->fromClass($ref->getName());
            if ($converted !== null) {
                $refs[$this->converter->key($converted['uri'])] = $ref;
            }
        }

        $byKey = [];
        foreach ($nodes as $node) {
            $byKey[$this->converter->key($node['uri'])] = $node;
        }

        $purged = $this->purged($nodes, $refs);
        $warnings = [];
        foreach ($nodes as $node) {
            $onGet = $node['methods']['onGet'] ?? null;
            $ref = $refs[$this->converter->key($node['uri'])] ?? null;
            if ($ref === null || ! isset($onGet['dependsOn'])) {
                continue;
            }

            $where = ['resource' => $node['uri'], 'source' => $onGet['source']];
            $defaults = $ref->getDefaultProperties();
            if ($defaults['body'] !== null || $defaults['code'] !== 200) {
                $warnings[] = ['warning' => 'depends-on-default-body'] + $where + ['message' => 'The class gives $body a default, or $code one other than 200, so as a hole it reads as already run: the page takes its declarations and is purged whole on every write to them, instead of recomposed. Set them in onGet().'];
            }

            foreach ($onGet['dependsOn'] as $target) {
                $warnings = [...$warnings, ...$this->ofTarget($where, $target, $this->webContextParameters($ref), $refs, $byKey, $purged)];
            }
        }

        return $warnings;
    }

    /**
     * The tags each write purges, by the key of the resource they belong to
     *
     * A write refreshes its own resource with the required onGet() arguments, under their names or all in snake_case;
     * an onPost() does so only when its own arguments carry them. A #[Refresh]/#[Purge] purges the URI it names.
     *
     * @param list<Node>                                     $nodes
     * @param array<string, ReflectionClass<ResourceObject>> $refs
     *
     * @return array<string, list<Purged>>
     */
    private function purged(array $nodes, array $refs): array
    {
        $purged = [];
        foreach ($nodes as $node) {
            $nodeKey = $this->converter->key($node['uri']);
            foreach ($node['methods'] ?? [] as $name => $method) {
                foreach ([...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
                    if (! isset($target['implicit'])) {
                        $purged[$this->converter->key($target['uri'])][] = ['uri' => $this->tagUri($target), 'keys' => $this->queryKeys($target['template'] ?? $target['uri'])];
                        continue;
                    }

                    $required = isset($refs[$nodeKey]) ? $this->requiredParameters($refs[$nodeKey]) : null;
                    if ($required === null || (str_starts_with($name, 'onPost') && ! $this->carries($refs[$nodeKey]->getMethod($name), $required))) {
                        continue;
                    }

                    $purged[$nodeKey][] = ['uri' => $node['uri'], 'keys' => $required];
                    $purged[$nodeKey][] = ['uri' => $node['uri'], 'keys' => $this->snake($required)];
                }
            }
        }

        return $purged;
    }

    /**
     * @param array{resource: string, source: string}        $where
     * @param Target                                         $target
     * @param list<string>                                   $bound  onGet() parameters a web-context attribute binds
     * @param array<string, ReflectionClass<ResourceObject>> $refs   keyed by UriConverter::key()
     * @param array<string, Node>                            $byKey
     * @param array<string, list<Purged>>                    $purged
     *
     * @return list<Warning>
     */
    private function ofTarget(array $where, array $target, array $bound, array $refs, array $byKey, array $purged): array
    {
        $template = $target['template'] ?? $target['uri'];
        $where += ['dependsOn' => $template];
        $warnings = [];
        foreach ($bound as $name) {
            if (in_array($name, $this->variables($template), true)) {
                $warnings[] = ['warning' => 'depends-on-bound-parameter'] + $where + [
                    'message' => sprintf("'%s' is bound by a web-context attribute, not by the request query: the URI expands from the parameter's default, so a write to the bound value never purges it.", $name),
                ];
            }
        }

        $targetKey = $this->converter->key($target['uri']);
        if (! isset($refs[$targetKey])) {
            return [...$warnings, ['warning' => 'depends-on-unknown-resource'] + $where + ['message' => sprintf('No resource %s.', $target['uri'])]];
        }

        $candidates = $purged[$targetKey] ?? [];
        if ($candidates === []) {
            return [
                ...$warnings,
                ['warning' => 'depends-on-never-invalidated'] + $where +
                [
                    'message' => sprintf('No write refreshes or purges %s: %s', $target['uri'], $refs[$targetKey]->hasMethod('onGet') ? $this->refreshAdvice($byKey[$targetKey] ?? null) : 'it has no onGet(), so no write refreshes it; name it in a #[Purge].'),
                ],
            ];
        }

        $declared = ['uri' => $this->tagUri($target), 'keys' => $this->queryKeys($template)];
        if (! in_array($declared, $candidates, true)) {
            $warnings[] = ['warning' => 'depends-on-tag-mismatch'] + $where + [
                'message' => sprintf('The tag is %s, but writes purge %s.', $this->describe($declared), implode(', ', array_unique(array_map($this->describe(...), $candidates)))),
            ];
        }

        return $warnings;
    }

    /**
     * What makes a write refresh the resource, by the cache it declares and the writes it has
     *
     * @param Node|null $node
     */
    private function refreshAdvice(array|null $node): string
    {
        foreach ($node['methods'] ?? [] as $method) {
            foreach ($method['refresh'] ?? [] as $target) {
                if (isset($target['implicit'])) {
                    return 'the writes that refresh it are onPost() whose arguments, the keys of a POST query that uses their names, do not carry the required onGet() ones; add them, or name it in a #[Purge]/#[Refresh].';
                }
            }
        }

        if ($node === null || ! $this->declarations->isCached($node)) {
            return 'make it #[Cacheable], or name it in a #[Purge]/#[Refresh].';
        }

        if (isset($node['cacheable']) || isset($node['cacheableResponse'])) {
            return 'it is cached, but has no write method; add one, or name it in a #[Purge]/#[Refresh].';
        }

        return 'it is cached by #[DonutCache] or by #[CacheableResponse] on onGet(), so a write refreshes it only when the write method carries #[RefreshCache] or #[CacheableResponse]; add one, or name it in a #[Purge]/#[Refresh].';
    }

    /** @param Purged $tag */
    private function describe(array $tag): string
    {
        return sprintf('%s?[%s]', $tag['uri'], implode(', ', $tag['keys']));
    }

    /**
     * The URI a tag is made from: the declaration as written, which may spell the resource's URI another way
     *
     * @param Target $target
     */
    private function tagUri(array $target): string
    {
        return $this->converter->withoutQuery($target['template'] ?? $target['uri']);
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return list<string> onGet() parameters a web-context attribute (#[QueryParam], #[CookieParam], ...) binds
     */
    private function webContextParameters(ReflectionClass $ref): array
    {
        $bound = [];
        foreach ($ref->getMethod('onGet')->getParameters() as $parameter) {
            if ($parameter->getAttributes(AbstractWebContextParam::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $bound[] = $parameter->name;
            }
        }

        return $bound;
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return list<string>|null sorted; null when the resource has no onGet()
     */
    private function requiredParameters(ReflectionClass $ref): array|null
    {
        if (! $ref->hasMethod('onGet')) {
            return null;
        }

        $required = [];
        foreach ($ref->getMethod('onGet')->getParameters() as $parameter) {
            if (! $parameter->isDefaultValueAvailable()) {
                $required[] = $parameter->name;
            }
        }

        sort($required);

        return $required;
    }

    /**
     * Whether a query keyed by the write's argument names carries each required onGet() argument
     *
     * BEAR.QueryRepository's MatchQuery looks each one up under its name, then under its snake_case form.
     *
     * @param list<string> $required
     */
    private function carries(ReflectionMethod $write, array $required): bool
    {
        $query = array_map(static fn ($parameter): string => $parameter->name, $write->getParameters());
        foreach ($required as $name) {
            if (! in_array($name, $query, true) && ! in_array($this->snake([$name])[0], $query, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> sorted query keys of the URI the template expands to */
    private function queryKeys(string $template): array
    {
        $values = [];
        foreach ($this->variables($template) as $variable) {
            $values[$variable] = 'x';
        }

        parse_str((string) parse_url(uri_template($template, $values), PHP_URL_QUERY), $query);
        $keys = array_map(strval(...), array_keys($query));
        sort($keys);

        return $keys;
    }

    /** @return list<string> the RFC 6570 variable names in the template */
    private function variables(string $template): array
    {
        preg_match_all('/\{[+#.\/;?&]?([^}]+)\}/', $template, $matches);
        $variables = [];
        foreach ($matches[1] as $expression) {
            foreach (explode(',', $expression) as $spec) {
                $variables[] = (string) preg_replace('/(\*|:\d+)$/', '', $spec);
            }
        }

        return $variables;
    }

    /**
     * The spelling BEAR.Resource also accepts for a parameter
     *
     * @param list<string> $names
     *
     * @return list<string> sorted
     */
    private function snake(array $names): array
    {
        $snake = array_map(static fn (string $name): string => ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_'), $names);
        sort($snake);

        return $snake;
    }
}
