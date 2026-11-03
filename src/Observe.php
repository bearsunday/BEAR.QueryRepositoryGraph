<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\QueryRepository\Log\LogFileWriter;
use JsonException;
use RuntimeException;

use function array_keys;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ksort;
use function sort;
use function sprintf;
use function strpos;
use function substr;
use function uasort;

use const JSON_THROW_ON_ERROR;

/**
 * What the semantic log observed about each embedded child, joined with what the code declares
 *
 * `depends_on` and `dependency_skipped` name their parent and child, so where an event sits in the tree does not
 * matter. A skipped child cannot say whether it is a hole or a child that ran and emits no tag; the declaration's
 * `usage` can, and `untagged-material` is that case: the page reads the child, yet a change to the child's data
 * does not reach the page's template through it.
 *
 * A `put_donut` says the page's children were classified. A declared child that then left no event was no longer
 * an embedded request in the page's body: `unclassified`.
 *
 * The shape is `docs/schemas/graph/observe.json`.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type ObserveLine = array{page: string, child: string, observed: list<string>, declared: string|null, verdict: string}
 */
final class Observe
{
    private const VERDICTS = [
        'depends_on' => ['referenced' => 'material', 'unreferenced' => 'tagged-hole', 'embedded' => 'dependency'],
        'dependency_skipped' => ['referenced' => 'untagged-material', 'unreferenced' => 'hole', 'embedded' => 'skipped'],
    ];

    private readonly UriConverter $converter;

    /** @var array<string, array<string, array{uri: string, usage: string}>> page key => child key => declared embed */
    private array $declared = [];

    /** @var array<string, array{uri: string, children: array<string, array{uri: string, types: array<string, true>}>}> page key => logged page and children */
    private array $observed = [];

    /** @var array<string, string> page key => logged page URI, for every page written by `put_donut` */
    private array $written = [];

    /** @param list<Node> $nodes */
    public function __construct(array $nodes)
    {
        $this->converter = new UriConverter();
        foreach ($nodes as $node) {
            foreach ($node['methods']['onGet']['embed'] ?? [] as $embed) {
                $this->declared[$this->converter->key($node['uri'])][$this->converter->key($embed['uri'])] = [
                    'uri' => $embed['uri'],
                    'usage' => $embed['usage'] ?? 'embedded',
                ];
            }
        }
    }

    /**
     * @param list<string> $paths session files, or directories of them
     *
     * @return list<ObserveLine>
     *
     * @throws RuntimeException when no session is found or one is not JSON
     */
    public function __invoke(array $paths): array
    {
        $this->observed = $this->written = [];
        foreach ($this->sessions($paths) as $file) {
            try {
                $session = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('%s: not JSON (%s)', $file, $e->getMessage()), 0, $e);
            }

            $this->collect($session);
        }

        foreach ($this->written as $pageKey => $page) {
            $this->observed[$pageKey] ??= ['uri' => $page, 'children' => []];
        }

        uasort($this->observed, static fn (array $a, array $b): int => $a['uri'] <=> $b['uri']);
        $lines = [];
        foreach ($this->observed as $pageKey => $page) {
            $children = [];
            foreach ($page['children'] as $childKey => $child) {
                $children[$child['uri']] = $this->line($page['uri'], $child['uri'], $child['types'], $this->declared[$pageKey][$childKey]['usage'] ?? null);
            }

            if (isset($this->written[$pageKey])) {
                foreach ($this->declared[$pageKey] ?? [] as $childKey => $embed) {
                    if (! isset($page['children'][$childKey])) {
                        $children[$embed['uri']] = $this->line($page['uri'], $embed['uri'], [], $embed['usage']);
                    }
                }
            }

            ksort($children);
            foreach ($children as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Directories contribute their timestamped sessions; `latest.json` repeats one of them
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function sessions(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            if (! is_dir($path)) {
                throw new RuntimeException(sprintf('No such session file or directory: %s', $path));
            }

            foreach (glob($path . '/*.json') ?: [] as $file) {
                if (basename($file) !== LogFileWriter::LATEST) {
                    $files[] = $file;
                }
            }
        }

        if ($files === []) {
            throw new RuntimeException(sprintf('No session found in %s', implode(', ', $paths)));
        }

        sort($files);

        return $files;
    }

    private function collect(mixed $node): void
    {
        if (! is_array($node)) {
            return;
        }

        $type = $node['type'] ?? null;
        $context = $node['context'] ?? null;
        if (is_array($context)) {
            $this->record($type, $context);
        }

        foreach ($node as $value) {
            $this->collect($value);
        }
    }

    /** @param array<array-key, mixed> $context */
    private function record(mixed $type, array $context): void
    {
        if ($type === 'put_donut' && is_string($context['uri'] ?? null)) {
            $page = self::withoutQuery($context['uri']);
            $this->written[$this->converter->key($page)] = $page;

            return;
        }

        if (! isset(self::VERDICTS[$type]) || ! is_string($context['parent'] ?? null) || ! is_string($context['child'] ?? null)) {
            return;
        }

        $page = self::withoutQuery($context['parent']);
        $child = self::withoutQuery($context['child']);
        $pageKey = $this->converter->key($page);
        $childKey = $this->converter->key($child);
        $this->observed[$pageKey] ??= ['uri' => $page, 'children' => []];
        $this->observed[$pageKey]['children'][$childKey] ??= ['uri' => $child, 'types' => []];
        $this->observed[$pageKey]['children'][$childKey]['types'][$type] = true;
    }

    /**
     * @param array<string, true> $types
     *
     * @return ObserveLine
     */
    private function line(string $page, string $child, array $types, string|null $declared): array
    {
        ksort($types);
        $observed = array_keys($types);
        $verdict = match (true) {
            $observed === [] => 'unclassified',
            count($observed) > 1 => 'mixed',
            $declared === null => 'undeclared',
            default => self::VERDICTS[$observed[0]][$declared],
        };

        return ['page' => $page, 'child' => $child, 'observed' => $observed, 'declared' => $declared, 'verdict' => $verdict];
    }

    private static function withoutQuery(string $uri): string
    {
        $query = strpos($uri, '?');

        return $query === false ? $uri : substr($uri, 0, $query);
    }
}
