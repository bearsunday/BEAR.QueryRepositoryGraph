<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\Resource\Annotation\RequestParamInterface;
use BEAR\Resource\ResourceObject;
use ReflectionAttribute;
use ReflectionClass;

use function array_keys;
use function array_map;
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
use function strtolower;
use function strval;
use function uri_template;

use const PHP_URL_QUERY;

/**
 * #[DependsOn] declarations that add a tag no write invalidates
 *
 * Each of these stores without an error at run time and then serves stale, so only the declarations show it.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type Target from GraphBuilder
 * @psalm-type Warning = array{warning: string, resource: string, source: string, dependsOn?: string, message: string}
 */
final class DependsOnWarnings
{
    private readonly UriConverter $converter;

    public function __construct()
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

        $invalidated = [];
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                foreach ([...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
                    $invalidated[$this->converter->key($target['uri'])] = true;
                }
            }
        }

        $warnings = [];
        foreach ($nodes as $node) {
            $onGet = $node['methods']['onGet'] ?? null;
            $ref = $refs[$this->converter->key($node['uri'])] ?? null;
            if ($ref === null || ! isset($onGet['dependsOn'])) {
                continue;
            }

            $where = ['resource' => $node['uri'], 'source' => $onGet['source']];
            if ($ref->getDefaultProperties()['body'] !== null) {
                $warnings[] = ['warning' => 'depends-on-default-body'] + $where + ['message' => 'The class gives $body a default, so as a hole it reads as already run: the page takes its declarations and is purged whole instead of recomposed. Set the body in onGet().'];
            }

            foreach ($onGet['dependsOn'] as $target) {
                $warnings = [...$warnings, ...$this->ofTarget($where, $target, $this->boundParameters($ref), $refs, $invalidated)];
            }
        }

        return $warnings;
    }

    /**
     * @param array{resource: string, source: string}        $where
     * @param Target                                         $target
     * @param list<string>                                   $bound       onGet() parameters bound by an attribute
     * @param array<string, ReflectionClass<ResourceObject>> $refs        keyed by UriConverter::key()
     * @param array<string, true>                            $invalidated URI keys some write refreshes or purges
     *
     * @return list<Warning>
     */
    private function ofTarget(array $where, array $target, array $bound, array $refs, array $invalidated): array
    {
        $template = $target['template'] ?? $target['uri'];
        $where += ['dependsOn' => $template];
        $variables = $this->variables($template);
        $warnings = [];
        foreach ($bound as $name) {
            if (in_array($name, $variables, true)) {
                $warnings[] = ['warning' => 'depends-on-bound-parameter'] + $where + [
                    'message' => sprintf("'%s' is bound by a parameter attribute, not by the request query: the URI expands from its default, or fails without one.", $name),
                ];
            }
        }

        $targetKey = $this->converter->key($target['uri']);
        $targetRef = $refs[$targetKey] ?? null;
        if ($targetRef === null) {
            return [...$warnings, ['warning' => 'depends-on-unknown-resource'] + $where + ['message' => sprintf('No resource %s.', $target['uri'])]];
        }

        if (! isset($invalidated[$targetKey])) {
            $warnings[] = ['warning' => 'depends-on-never-invalidated'] + $where + [
                'message' => sprintf('No write refreshes or purges %s: make it #[Cacheable], or name it in a #[Purge]/#[Refresh].', $target['uri']),
            ];
        }

        $required = $this->requiredParameters($targetRef);
        $keys = $this->queryKeys($template, $variables);
        if ($required !== null && $keys !== $required && $keys !== $this->snake($required)) {
            $warnings[] = ['warning' => 'depends-on-query-mismatch'] + $where + [
                'message' => sprintf(
                    'The URI carries [%s], but a write to %s purges the tag of its required onGet() parameters [%s].',
                    implode(', ', $keys),
                    $target['uri'],
                    implode(', ', $required),
                ),
            ];
        }

        return $warnings;
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return list<string> onGet() parameters a #[ResourceParam] or web-context attribute binds
     */
    private function boundParameters(ReflectionClass $ref): array
    {
        $bound = [];
        foreach ($ref->getMethod('onGet')->getParameters() as $parameter) {
            if ($parameter->getAttributes(RequestParamInterface::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
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
     * @param list<string> $variables
     *
     * @return list<string> sorted query keys of the URI the template expands to
     */
    private function queryKeys(string $template, array $variables): array
    {
        $values = [];
        foreach ($variables as $variable) {
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
     * The spelling BEAR.Resource also accepts for a parameter, which a write purges as well
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function snake(array $names): array
    {
        $snake = array_map(static fn (string $name): string => ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_'), $names);
        sort($snake);

        return $snake;
    }
}
