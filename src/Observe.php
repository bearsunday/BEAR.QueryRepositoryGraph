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
use function in_array;
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
 * matter. The reason of a `dependency_skipped` is what its body showed at write time: `no_body` is read as a hole
 * (a run that left no body, such as a 204, reads the same), and `no_etag_or_surrogate_key` is a child the page
 * read that emits no tag (`untagged-material`): a change to its
 * data does not reach the page's template through it. A log without a reason falls back to the declaration's
 * `usage`, a source-text guess.
 *
 * A `put_donut` says the page's children were classified. A declared child that then left no event was no longer
 * an embedded request at the top of the page's body: `unclassified`.
 *
 * The shape is `docs/schemas/graph/observe.json`.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type ObserveLine = array{page: string, child: string, observed: list<string>, declared: string|null, verdict: string}
 */
final class Observe
{
    private const EVENTS = ['depends_on', 'dependency_skipped'];

    /** Observation => declared usage => verdict; a skip with a run-time reason does not need the usage */
    private const VERDICTS = [
        'depends_on' => ['referenced' => 'material', 'unreferenced' => 'tagged-hole', 'embedded' => 'dependency'],
        'no_body' => ['referenced' => 'hole', 'unreferenced' => 'hole', 'embedded' => 'hole'],
        'no_etag_or_surrogate_key' => ['referenced' => 'untagged-material', 'unreferenced' => 'untagged-material', 'embedded' => 'untagged-material'],
        'dependency_skipped' => ['referenced' => 'untagged-material', 'unreferenced' => 'hole', 'embedded' => 'skipped'],
    ];

    /** Every verdict a line can carry, as docs/schemas/graph/observe.json enumerates them; the vocabulary `--fail-on` validates against */
    public const ALL_VERDICTS = ['material', 'hole', 'dependency', 'untagged-material', 'tagged-hole', 'unclassified', 'skipped', 'undeclared', 'mixed'];

    private readonly UriConverter $converter;

    /** @var array<string, array<string, array{uri: string, usage: string}>> page key => child key => declared embed */
    private array $declared = [];

    /** @var array<string, array{uri: string, children: array<string, array{uri: string, types: array<string, true>}>}> page key => logged page and children, each observation a VERDICTS key */
    private array $observed = [];

    /** @var array<string, string> page key => logged page URI, for every page written by `put_donut` */
    private array $written = [];

    /** Whether any session recorded `dependency_skipped`; without it holes and untagged children read as `unclassified` */
    public bool $sawSkipped = false;

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
     * @throws RuntimeException when no session is found or one is not JSON.
     */
    public function __invoke(array $paths): array
    {
        $this->read($paths);
        foreach ($this->written as $pageKey => $page) {
            $this->observed[$pageKey] ??= ['uri' => $page, 'children' => []];
        }

        uasort($this->observed, static fn (array $left, array $right): int => $left['uri'] <=> $right['uri']);
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
     * @param list<string> $paths
     *
     * @throws RuntimeException when no session is found or one is not JSON.
     */
    private function read(array $paths): void
    {
        $this->observed = [];
        $this->written = [];
        $this->sawSkipped = false;
        foreach ($this->sessions($paths) as $file) {
            try {
                /** @var mixed $session */
                $session = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('%s: not JSON (%s)', $file, $e->getMessage()), 0, $e);
            }

            $this->collect($session);
        }
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

            $found = glob($path . '/*.json');
            foreach ($found === false ? [] : $found as $file) {
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

        /** @var mixed $type */
        $type = $node['type'] ?? null;
        /** @var mixed $context */
        $context = $node['context'] ?? null;
        if (is_array($context)) {
            $this->record($type, $context);
        }

        /** @var mixed $value */
        foreach ($node as $value) {
            $this->collect($value);
        }
    }

    /** @param array<array-key, mixed> $context */
    private function record(mixed $type, array $context): void
    {
        /** @var mixed $uri */
        $uri = $context['uri'] ?? null;
        if ($type === 'put_donut' && is_string($uri)) {
            $page = self::withoutQuery($uri);
            $this->written[$this->converter->key($page)] = $page;

            return;
        }

        $parent = $context['parent'] ?? null;
        $child = $context['child'] ?? null;
        if (! in_array($type, self::EVENTS, true) || ! is_string($parent) || ! is_string($child)) {
            return;
        }

        $this->sawSkipped = $this->sawSkipped || $type === 'dependency_skipped';
        /** @var mixed $reason */
        $reason = $context['reason'] ?? null;
        $observation = $type === 'dependency_skipped' && is_string($reason) && isset(self::VERDICTS[$reason]) ? $reason : $type;
        $page = self::withoutQuery($parent);
        $child = self::withoutQuery($child);
        $pageKey = $this->converter->key($page);
        $childKey = $this->converter->key($child);
        $this->observed[$pageKey] ??= ['uri' => $page, 'children' => []];
        $this->observed[$pageKey]['children'][$childKey] ??= ['uri' => $child, 'types' => []];
        $this->observed[$pageKey]['children'][$childKey]['types'][$observation] = true;
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
