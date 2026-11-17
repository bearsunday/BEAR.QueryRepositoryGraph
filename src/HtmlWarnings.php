<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_column;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function basename;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function preg_match;
use function rawurlencode;
use function realpath;
use function sprintf;
use function strrpos;
use function strval;
use function substr;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * The HTML page's list of the declarations that affect no cache, then, collapsed, the scanned resources it neither draws nor lists
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type ImpactLine from Impact
 * @psalm-import-type EmbedTarget from GraphBuilder
 * @psalm-import-type Warning from DependsOnWarnings
 * @psalm-type Item = array{string, list<string>}
 */
final class HtmlWarnings
{
    /** The URL each `--editor` opens a `file:line` with: the file's absolute path and the line */
    public const EDITORS = ['phpstorm' => 'phpstorm://open?file=%s&line=%s'];

    private readonly Impact $impact;
    private readonly UriConverter $converter;

    /** @var array<string, Node> by UriConverter::key() */
    private array $byKey = [];

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn  the URIs the picture draws: an item naming one selects it
     * @param string|null         $editor a key of EDITORS; null keeps each `file:line` text, so the page carries no absolute path
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $drawn,
        private readonly NodeDeclarationsInterface $declarations,
        private readonly string|null $editor = null,
    ) {
        $this->impact = new Impact($nodes, $declarations);
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
        $count = 0;
        $warned = [];
        foreach ($groups as [$kind, $title, $about, $items]) {
            $html .= $this->group($kind, $title, $about, array_column($items, 0));
            $count += count($items);
            foreach (array_column($items, 1) as $uris) {
                foreach ($uris as $uri) {
                    $warned[$uri] = true;
                }
            }
        }

        if ($html === '') {
            return "<h2>Warnings</h2>\n<p>No warnings.</p>\n" . $this->notDrawn($warned);
        }

        $premise = "<p>A resource depends on what it embeds: the warnings follow the #[Embed], #[DependsOn], #[Refresh] and #[Purge] declarations.</p>\n";

        return "<details data-kind=\"warnings\">\n<summary>Warnings ({$count})</summary>\n" . $premise . $html . "</details>\n" . $this->notDrawn($warned);
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
                        if (isset($target['implicit']) || ! $this->isSelf($uri) || $this->reachesCache(($this->impact)($uri))) {
                            continue;
                        }

                        $html = $this->uri($uri) . " {$via} by " . $this->uri($node['uri']) . ' ' . $this->code($name) . ' ' . $this->source($method['source']);
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
                $items[] = [$this->uri($node['uri']) . ' ' . $this->source($onGet['source']), [$node['uri']]];
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
            if ($this->declarations->isCached($node)) {
                $stored[$node['uri']] = true;
                $pending[] = $node;
            }
        }

        while (($node = array_pop($pending)) !== null) {
            foreach ($this->embeds($node) as $embed) {
                $child = $this->node($embed['uri']);
                if ($child !== null && ! isset($stored[$child['uri']])) {
                    $stored[$child['uri']] = true;
                    $pending[] = $child;
                }
            }
        }

        return $stored;
    }

    /**
     * What the resource embeds, by #[Embed] or by its layout
     *
     * @param Node $node
     *
     * @return list<EmbedTarget>
     */
    private function embeds(array $node): array
    {
        $embeds = $this->declarations->layoutEmbeds($node);
        foreach ($node['methods'] ?? [] as $method) {
            $embeds = [...$embeds, ...$method['embed'] ?? []];
        }

        return $embeds;
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
                            $named[$target['uri']][$via . $method['source']] = $via . ' ' . $this->source($method['source']);
                        }
                    }
                }
            }

            foreach ($this->declarations->layoutEmbeds($node) as $embed) {
                $source = $node['layout']['source'] ?? '';
                if ($this->node($embed['uri']) === null) {
                    $named[$embed['uri']]['layout' . $source] = 'layout ' . $this->source($source);
                }
            }
        }

        $uris = array_map(strval(...), array_keys($named));
        usort($uris, fn (string $left, string $right): int => [! $this->isSelf($left), $left] <=> [! $this->isSelf($right), $right]);
        $items = [];
        foreach ($uris as $uri) {
            $items[] = [$this->uri($uri) . ($this->isSelf($uri) ? '' : ' (another application)') . ' ' . implode(', ', $named[$uri]), [$uri]];
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
        $html = $this->uri($warning['resource']) . ' ' . $this->code($warning['warning'])
            . (isset($warning['dependsOn']) ? ' ' . $this->code($warning['dependsOn']) : '')
            . ' ' . $this->source($warning['source'])
            . '<div class="message">' . $this->escape($warning['message']) . '</div>';

        return [$html, [$warning['resource']]];
    }

    /** @param array<string, true> $warned the URIs the warnings list */
    private function notDrawn(array $warned): string
    {
        $embedding = [];
        foreach ($this->nodes as $node) {
            foreach ($this->embeds($node) as $embed) {
                $embedding[$node['uri']] = true;
                $embedding[$this->node($embed['uri'])['uri'] ?? $embed['uri']] = true;
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
            $lists[$kind][] = $this->uri($uri) . ' ' . $this->source($node['source']);
        }

        $html = $this->group('embeds-uncached', 'Not embedded by a cache', 'Not cached, and no cached resource embeds it, directly or not.', $lists['embeds-uncached'])
            . $this->group('no-declarations', 'No declarations', 'Declares nothing the cache acts on.', $lists['no-declarations'])
            . $this->group('writes-only', 'Writes only', 'Not cached, and declares writes none of the warnings above names.', $lists['writes-only']);

        return $html === '' ? '' : "<details data-kind=\"not-drawn\">\n<summary>Not drawn</summary>\n{$html}</details>\n";
    }

    /**
     * The scanned resource a declared URI names
     *
     * @return Node|null
     */
    private function node(string $uri): array|null
    {
        return $this->byKey[$this->converter->key($uri)] ?? null;
    }

    /** @param list<ImpactLine> $lines */
    private function reachesCache(array $lines): bool
    {
        return in_array(true, array_column($lines, 'cached'), true);
    }

    private function isSelf(string $uri): bool
    {
        return explode('/', explode('://', $uri, 2)[1] ?? '', 2)[0] === 'self';
    }

    /** @param list<string> $items each item's HTML */
    private function group(string $kind, string $title, string $about, array $items): string
    {
        if ($items === []) {
            return '';
        }

        return sprintf(
            "<section data-kind=\"%s\">\n<h3>%s (<span class=\"count\">%d</span>)</h3>\n<p>%s</p>\n<ul>\n<li>%s</li>\n</ul>\n</section>\n",
            $kind,
            $this->escape($title),
            count($items),
            $this->escape($about),
            implode("</li>\n<li>", $items),
        );
    }

    private function uri(string $uri): string
    {
        $escaped = $this->escape($uri);

        return isset($this->drawn[$uri]) ? "<a class=\"uri\" href=\"#\" data-uri=\"{$escaped}\">{$escaped}</a>" : "<span class=\"uri\">{$escaped}</span>";
    }

    private function code(string $text): string
    {
        return '<code>' . $this->escape($text) . '</code>';
    }

    /** With an editor, a `file:line` whose file resolves from the working directory, which the scan ran from, opens in it */
    private function source(string $source): string
    {
        $escaped = $this->escape($source);
        $colon = (int) strrpos($source, ':');
        $file = substr($source, 0, $colon);
        $line = substr($source, $colon + 1);
        $url = self::EDITORS[$this->editor ?? ''] ?? null;
        $path = $url !== null && $colon > 0 && preg_match('/\A\d+\z/', $line) === 1 ? realpath($file) : false;
        if ($url === null || $path === false) {
            // A file outside the working directory keeps its absolute path in the graph: the page shows its name only.
            $label = preg_match('#\A(/|[A-Za-z]:/)#', $file) === 1 ? $this->escape(basename($file) . ':' . $line) : $escaped;

            return "<span class=\"source\">{$label}</span>";
        }

        $href = $this->escape(sprintf($url, rawurlencode($path), $line));

        return "<a class=\"source\" href=\"{$href}\">{$escaped}</a>";
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
    }
}
