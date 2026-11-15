<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_column;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function sprintf;
use function str_ends_with;
use function strval;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * The HTML page's list of the declarations that affect no cache, then, collapsed, the scanned resources it neither draws nor lists
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type ImpactLine from Impact
 * @psalm-import-type Warning from DependsOnWarnings
 * @psalm-type Item = array{string, list<string>}
 */
final class HtmlWarnings
{
    private readonly Impact $impact;
    private readonly UriConverter $converter;

    /** @var array<string, Node> by UriConverter::key() */
    private array $byKey = [];

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn the URIs the picture draws: an item naming one selects it
     */
    public function __construct(private readonly array $nodes, private readonly array $drawn)
    {
        $this->impact = new Impact($nodes);
        $this->converter = new UriConverter();
        foreach ($nodes as $node) {
            $this->byKey[$this->converter->key($node['uri'])] = $node;
        }
    }

    /** @param list<Warning> $dependsOn */
    public function __invoke(array $dependsOn): string
    {
        $groups = [
            ['invalidates-no-cache', 'Invalidates no cache', 'Invalidating what a #[Purge] or #[Refresh] names reaches no cached resource.', $this->idleTargets()],
            ['depends-on-not-cached', 'Depends on, but not cached', 'The resource declares #[DependsOn], but it is not cached and no cached resource embeds it, so no cache stores the tags it declares.', $this->unstoredDependents()],
            ['no-such-resource', 'No such resource', 'A declaration names a URI no scanned resource serves: in this application a typo or a removed resource, in another one possibly fine.', $this->unknown()],
            ['depends-on', '#[DependsOn] warnings', 'Every command also writes these on stderr.', array_map($this->dependsOnItem(...), $dependsOn)],
        ];
        $html = '';
        $warned = [];
        foreach ($groups as [$kind, $title, $about, $items]) {
            $html .= self::group($kind, $title, $about, array_column($items, 0));
            foreach (array_column($items, 1) as $uris) {
                foreach ($uris as $uri) {
                    $warned[$uri] = true;
                }
            }
        }

        $premise = "<p>Read from the declarations alone: a dependency made only through a #[ResourceParam] read, a row class's #[DependsOn] or a template's resource() call is not seen, so check such a case at runtime before removing anything.</p>\n";

        return "<h2>Warnings</h2>\n" . ($html === '' ? "<p>No warnings.</p>\n" : $premise . $html) . $this->notDrawn($warned);
    }

    /**
     * Each #[Refresh]/#[Purge] target in this application whose invalidation reaches no cached resource
     *
     * @return list<Item>
     */
    private function idleTargets(): array
    {
        $items = [];
        foreach ($this->nodes as $node) {
            foreach ($node['methods'] ?? [] as $name => $method) {
                foreach (['refresh' => $method['refresh'] ?? [], 'purge' => $method['purge'] ?? []] as $via => $targets) {
                    foreach ($targets as $target) {
                        $uri = $this->node($target['uri'])['uri'] ?? $target['uri'];
                        if (isset($target['implicit']) || ! self::isSelf($uri) || self::reachesCache(($this->impact)($uri))) {
                            continue;
                        }

                        $html = $this->uri($uri) . " {$via} by " . $this->uri($node['uri']) . ' ' . self::code($name) . ' ' . self::source($method['source']);
                        $items[$html] = [$html, [$uri, $node['uri']]];
                    }
                }
            }
        }

        return array_values($items);
    }

    /** @return list<Item> */
    private function unstoredDependents(): array
    {
        $stored = $this->stored();
        $items = [];
        foreach ($this->nodes as $node) {
            $onGet = $node['methods']['onGet'] ?? null;
            if (isset($onGet['dependsOn']) && ! isset($stored[$node['uri']])) {
                $items[] = [$this->uri($node['uri']) . ' ' . self::source($onGet['source']), [$node['uri']]];
            }
        }

        return $items;
    }

    /** @return array<string, true> the cached resources and what they embed, directly or not: the resources whose tags a cache stores */
    private function stored(): array
    {
        $stored = [];
        $pending = [];
        foreach ($this->nodes as $node) {
            if (Impact::isCached($node)) {
                $stored[$node['uri']] = true;
                $pending[] = $node;
            }
        }

        while ($pending !== []) {
            foreach (array_pop($pending)['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $child = $this->node($embed['uri']);
                    if ($child !== null && ! isset($stored[$child['uri']])) {
                        $stored[$child['uri']] = true;
                        $pending[] = $child;
                    }
                }
            }
        }

        return $stored;
    }

    /**
     * The URIs declarations name that no scanned resource serves, this application's first
     *
     * @return list<Item>
     */
    private function unknown(): array
    {
        $named = [];
        foreach ($this->nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                $declared = ['embed' => $method['embed'] ?? [], 'dependsOn' => $method['dependsOn'] ?? [], 'refresh' => $method['refresh'] ?? [], 'purge' => $method['purge'] ?? []];
                foreach ($declared as $via => $targets) {
                    foreach ($targets as $target) {
                        if ($this->node($target['uri']) === null) {
                            $named[$target['uri']][$via . $method['source']] = $via . ' ' . self::source($method['source']);
                        }
                    }
                }
            }
        }

        $uris = array_map(strval(...), array_keys($named));
        usort($uris, static fn (string $left, string $right): int => [! self::isSelf($left), $left] <=> [! self::isSelf($right), $right]);
        $items = [];
        foreach ($uris as $uri) {
            $items[] = [$this->uri($uri) . (self::isSelf($uri) ? '' : ' (another application)') . ' ' . implode(', ', $named[$uri]), [$uri]];
        }

        return $items;
    }

    /**
     * @param Warning $warning
     *
     * @return Item
     */
    private function dependsOnItem(array $warning): array
    {
        $html = $this->uri($warning['resource']) . ' ' . self::code($warning['warning'])
            . (isset($warning['dependsOn']) ? ' ' . self::code($warning['dependsOn']) : '')
            . ' ' . self::source($warning['source'])
            . '<div class="message">' . self::escape($warning['message']) . '</div>';

        return [$html, [$warning['resource']]];
    }

    /** @param array<string, true> $warned the URIs the warnings list */
    private function notDrawn(array $warned): string
    {
        $embedding = [];
        foreach ($this->nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $embedding[$node['uri']] = true;
                    $embedding[$this->node($embed['uri'])['uri'] ?? $embed['uri']] = true;
                }
            }
        }

        $lists = ['embeds-uncached' => [], 'no-declarations' => [], 'writes-only' => []];
        foreach ($this->nodes as $node) {
            $uri = $node['uri'];
            if (isset($this->drawn[$uri]) || isset($warned[$uri])) {
                continue;
            }

            $kind = match (true) {
                isset($embedding[$uri]) => 'embeds-uncached',
                ! isset($node['methods']) => 'no-declarations',
                default => 'writes-only',
            };
            $lists[$kind][] = $this->uri($uri) . ' ' . self::source($node['source']);
        }

        $html = self::group('embeds-uncached', 'Embedded among uncached resources only', 'Not cached, and embeds or is embedded by resources that are not cached either.', $lists['embeds-uncached'])
            . self::group('no-declarations', 'No declarations', 'Declares nothing the cache acts on.', $lists['no-declarations'])
            . self::group('writes-only', 'Writes only', 'Not cached, and declares writes none of the warnings above names.', $lists['writes-only']);

        return $html === '' ? '' : "<details>\n<summary>Not drawn</summary>\n{$html}</details>\n";
    }

    /**
     * The scanned resource a declared URI names; a trailing `/` names its `index`, as BEAR.Resource resolves it
     *
     * @return Node|null
     */
    private function node(string $uri): array|null
    {
        return $this->byKey[$this->converter->key($uri)] ?? (str_ends_with($uri, '/') ? $this->byKey[$this->converter->key($uri . 'index')] ?? null : null);
    }

    /** @param list<ImpactLine> $lines */
    private static function reachesCache(array $lines): bool
    {
        return in_array(true, array_column($lines, 'cached'), true);
    }

    private static function isSelf(string $uri): bool
    {
        return explode('/', explode('://', $uri, 2)[1] ?? '', 2)[0] === 'self';
    }

    /** @param list<string> $items each item's HTML */
    private static function group(string $kind, string $title, string $about, array $items): string
    {
        if ($items === []) {
            return '';
        }

        return sprintf(
            "<section data-kind=\"%s\">\n<h3>%s (<span class=\"count\">%d</span>)</h3>\n<p>%s</p>\n<ul>\n<li>%s</li>\n</ul>\n</section>\n",
            $kind,
            self::escape($title),
            count($items),
            self::escape($about),
            implode("</li>\n<li>", $items),
        );
    }

    private function uri(string $uri): string
    {
        $escaped = self::escape($uri);

        return isset($this->drawn[$uri]) ? "<a class=\"uri\" href=\"#\" data-uri=\"{$escaped}\">{$escaped}</a>" : "<span class=\"uri\">{$escaped}</span>";
    }

    private static function code(string $text): string
    {
        return '<code>' . self::escape($text) . '</code>';
    }

    private static function source(string $source): string
    {
        return '<span class="source">' . self::escape($source) . '</span>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
    }
}
