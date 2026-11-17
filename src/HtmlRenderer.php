<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use RuntimeException;

use function array_diff_key;
use function array_keys;
use function array_values;
use function fclose;
use function fwrite;
use function implode;
use function json_encode;
use function ksort;
use function max;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function sort;
use function sprintf;
use function stream_get_contents;
use function strpos;
use function strtr;
use function substr;
use function tmpfile;
use function trim;

use const JSON_HEX_AMP;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The `dot --cached` picture as one self-contained HTML page: clicking a resource lights what `impact` reaches from
 * it and what reaches it. Below it, the declarations that affect no cache, and what the picture leaves out.
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type ImpactLine from Impact
 * @psalm-import-type Warning from DependsOnWarnings
 * @psalm-type Lit = array{nodes: list<string>, edges: list<array{string, string}>, writes: list<array{string, string}>}
 * @psalm-type Pending = array{nodes: array<string, true>, edges: array<string, array{string, string}>, writes: array<string, array{string, string}>}
 * @psalm-type Glow = array<string, array{reaches: Lit, reachedBy: Lit, writers: list<string>}>
 */
final class HtmlRenderer
{
    private const NONE = ['nodes' => [], 'edges' => [], 'writes' => []];
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR;
    private const NOTHING_CACHED = '<p>Nothing is cached: no resource declares #[Cacheable], #[CacheableResponse] or #[DonutCache], so there is no picture. <code>qr-graph dot | dot -Tsvg</code> draws the whole graph.</p>';

    /** @param non-empty-list<string> $dot the command that reads dot on stdin and writes SVG on stdout */
    public function __construct(private readonly NodeDeclarationsInterface $declarations, private readonly array $dot = ['dot', '-Tsvg'])
    {
    }

    /**
     * @param list<Node>    $nodes     the whole graph: what is reached is computed on it, not only on what is drawn
     * @param list<Warning> $dependsOn listed with the other warnings
     * @param string|null   $editor    a key of HtmlWarnings::EDITORS to link each `file:line` to; null for text
     *
     * @throws RuntimeException when the dot command cannot run or fails.
     */
    public function __invoke(array $nodes, array $dependsOn, string|null $editor = null): string
    {
        $dot = new DotRenderer($this->declarations);
        $drawn = $dot->cachedUris($nodes);
        $table = $dot->cachedTable($nodes);
        $json = json_encode($this->glow($nodes, $table), self::JSON);
        $layout = $table->isEmpty() ? ['{{layoutCss}}' => '', '{{layoutData}}' => '', '{{layoutInit}}' => '', '{{layoutSelect}}' => ''] : [
            '{{layoutCss}}' => self::LAYOUT_CSS,
            '{{layoutData}}' => '<script type="application/json" id="qr-graph-layouts">' . json_encode($table->ids(), self::JSON) . "</script>\n",
            '{{layoutInit}}' => self::LAYOUT_INIT,
            '{{layoutSelect}}' => self::LAYOUT_SELECT,
        ];

        return strtr(self::PAGE, [
            '{{svg}}' => $drawn === [] ? self::NOTHING_CACHED : $this->picture($dot, $table, $nodes),
            '{{warnings}}' => (new HtmlWarnings($nodes, $drawn, $this->declarations, $editor))($dependsOn),
            '{{json}}' => $json,
        ] + $layout);
    }

    /**
     * @param list<Node> $nodes
     *
     * @throws RuntimeException
     */
    private function picture(DotRenderer $dot, LayoutTable $table, array $nodes): string
    {
        if ($table->isEmpty()) {
            return $this->svg($dot->cached($nodes));
        }

        return $this->stack($this->svg($table->graph()), $this->svg($dot->cachedGraph($nodes)));
    }

    /** The table's SVG at the top-left, the picture's below it, in one SVG: the page's script and styles act on one */
    private function stack(string $table, string $picture): string
    {
        $gap = 24.0;
        [$tableWidth, $tableHeight, $table] = $this->placed($table, 0.0);
        [$width, $height, $picture] = $this->placed($picture, $tableHeight + $gap);

        return sprintf(
            "<svg width=\"%1\$spt\" height=\"%2\$spt\" viewBox=\"0 0 %1\$s %2\$s\" xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">\n%3\$s%4\$s</svg>\n",
            max($width, $tableWidth),
            $tableHeight + $gap + $height,
            $table,
            $picture,
        );
    }

    /**
     * Graphviz sizes its SVG in points; nested, the SVG takes the outer one's units, which are points too
     *
     * @return array{float, float, string} the width and height, and the SVG placed `$top` points down
     */
    private function placed(string $svg, float $top): array
    {
        $root = '/^<svg width="([\d.]+)pt" height="([\d.]+)pt"/';
        if (preg_match($root, $svg, $size) !== 1) {
            return [0.0, 0.0, $svg];
        }

        return [(float) $size[1], (float) $size[2], (string) preg_replace($root, sprintf('<svg x="0" y="%s" width="$1" height="$2"', $top), $svg, 1)];
    }

    /**
     * For every drawable URI: what invalidating it, or calling one of its write methods, reaches (`reaches`), the URIs
     * whose `reaches` hold it (`reachedBy`), and among those the ones that reach it only through a write method
     * (`writers`). Edges are `[by, uri]` of the impact lines on the way, one shortest path per reached URI and start;
     * `writes` are the edges that are a refresh or a purge.
     *
     * @param list<Node>  $nodes
     * @param LayoutTable $table names each layout's entry in the glow data
     *
     * @return Glow
     */
    private function glow(array $nodes, LayoutTable $table): array
    {
        $impact = new Impact($nodes, $this->declarations);
        $uris = $this->uris($nodes);
        $reaches = [];
        $reachedBy = [];
        // How each start reaches each URI: invalidated itself, or through one of its write methods.
        $from = ['plain' => [], 'written' => []];
        foreach ($uris as $uri => $methods) {
            $starts = [['plain', $impact($uri)]];
            foreach ($methods as $method) {
                $starts[] = ['written', $impact($uri, $method)];
            }

            foreach ($starts as [$how, $lines]) {
                foreach ($this->paths($uri, $lines) as $reached => $path) {
                    $reaches[$uri] = $this->add($reaches[$uri] ?? self::NONE, $reached, [$path[0]]);
                    $reachedBy[$reached] = $this->add($reachedBy[$reached] ?? self::NONE, $uri, $path);
                    $from[$how][$reached][$uri] = true;
                }
            }
        }

        $glow = [];
        foreach ([...array_keys($uris), ...array_keys($reachedBy)] as $uri) {
            $writers = array_keys(array_diff_key($from['written'][$uri] ?? [], $from['plain'][$uri] ?? []));
            sort($writers);
            $glow[$uri] = [
                'reaches' => $this->lit($reaches[$uri] ?? self::NONE),
                'reachedBy' => $this->lit($reachedBy[$uri] ?? self::NONE),
                'writers' => $writers,
            ];
        }

        $glow += $this->layoutGlow($nodes, $reaches, $reachedBy, $from, $table);
        ksort($glow);

        return $glow;
    }

    /**
     * Each layout as a resource that depends on its parts: it reaches the pages carrying it and what they reach, and
     * is reached by its parts, through their ● (`[part, page]`), and by what reaches them
     *
     * @param list<Node>                                                                                    $nodes
     * @param array<string, Pending>                                                                        $reaches
     * @param array<string, Pending>                                                                        $reachedBy
     * @param array{plain: array<string, array<string, true>>, written: array<string, array<string, true>>} $from
     *
     * @return Glow
     */
    private function layoutGlow(array $nodes, array $reaches, array $reachedBy, array $from, LayoutTable $table): array
    {
        $layouts = [];
        foreach ($nodes as $node) {
            if (isset($node['layout'])) {
                $layouts[$node['layout']['class']][] = $node;
            }
        }

        $glow = [];
        foreach ($layouts as $class => $carriers) {
            $lit = ['reaches' => self::NONE, 'reachedBy' => self::NONE];
            // As for a page: a writer reaches the layout through a write only, never through a part or what reaches one.
            $written = [];
            $plain = [];
            foreach ($carriers as $node) {
                $page = $node['uri'];
                $lit['reaches'] = $this->merge($this->add($lit['reaches'], $page, []), $reaches[$page] ?? self::NONE);
                foreach ($this->declarations->layoutEmbeds($node) as $embed) {
                    $part = $embed['uri'];
                    $lit['reachedBy'] = $this->merge($this->add($lit['reachedBy'], $part, []), $reachedBy[$part] ?? self::NONE);
                    $lit['reachedBy']['edges'][$part . ' ' . $page] = [$part, $page];
                    $written += $from['written'][$part] ?? [];
                    $plain += [$part => true] + ($from['plain'][$part] ?? []);
                }
            }

            $writers = array_keys(array_diff_key($written, $plain));
            sort($writers);
            $glow[$table->key($class)] = ['reaches' => $this->lit($lit['reaches']), 'reachedBy' => $this->lit($lit['reachedBy']), 'writers' => $writers];
        }

        return $glow;
    }

    /**
     * @param Pending $into
     * @param Pending $more
     *
     * @return Pending
     */
    private function merge(array $into, array $more): array
    {
        return ['nodes' => $into['nodes'] + $more['nodes'], 'edges' => $into['edges'] + $more['edges'], 'writes' => $into['writes'] + $more['writes']];
    }

    /**
     * @param list<ImpactLine> $lines
     *
     * @return array<string, non-empty-list<ImpactLine>> each URI the lines reach, with the lines from it back to `$start`
     */
    private function paths(string $start, array $lines): array
    {
        $byUri = [];
        foreach ($lines as $line) {
            $byUri[$line['uri']] = $line;
        }

        $paths = [];
        foreach ($lines as $line) {
            if ($line['uri'] === $start || $line['by'] === null) {
                continue;
            }

            $path = [$line];
            for ($step = $byUri[$line['by']] ?? null; $step !== null && $step['by'] !== null && $step['uri'] !== $start; $step = $byUri[$step['by']] ?? null) {
                $path[] = $step;
            }

            $paths[$line['uri']] = $path;
        }

        return $paths;
    }

    /**
     * @param Pending          $lit
     * @param list<ImpactLine> $steps lines with their `by`
     *
     * @return Pending
     */
    private function add(array $lit, string $node, array $steps): array
    {
        $lit['nodes'][$node] = true;
        foreach ($steps as $step) {
            $edge = [(string) $step['by'], $step['uri']];
            $key = $edge[0] . ' ' . $edge[1];
            $lit['edges'][$key] = $edge;
            if ($step['via'] === 'refresh' || $step['via'] === 'purge') {
                $lit['writes'][$key] = $edge;
            }
        }

        return $lit;
    }

    /**
     * The scanned resources with their write methods that refresh or purge, then the URIs only a declaration names
     *
     * @param list<Node> $nodes
     *
     * @return array<string, list<string>>
     */
    private function uris(array $nodes): array
    {
        $uris = [];
        foreach ($nodes as $node) {
            $uris[$node['uri']] = [];
            foreach ($node['methods'] ?? [] as $name => $method) {
                if (($method['refresh'] ?? []) !== [] || ($method['purge'] ?? []) !== []) {
                    $uris[$node['uri']][] = $name;
                }
            }
        }

        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                foreach ([...$method['embed'] ?? [], ...$method['dependsOn'] ?? [], ...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
                    $uris[$target['uri']] ??= [];
                }
            }

            foreach ($this->declarations->layoutEmbeds($node) as $target) {
                $uris[$target['uri']] ??= [];
            }
        }

        return $uris;
    }

    /**
     * @param Pending $pending
     *
     * @return Lit
     */
    private function lit(array $pending): array
    {
        ksort($pending['nodes']);
        ksort($pending['edges']);
        ksort($pending['writes']);

        return ['nodes' => array_keys($pending['nodes']), 'edges' => array_values($pending['edges']), 'writes' => array_values($pending['writes'])];
    }

    /** @throws RuntimeException */
    private function svg(string $source): string
    {
        $command = implode(' ', $this->dot);
        // stderr goes to a file: a pipe left unread while stdout is read could fill and stall the command.
        $stderr = tmpfile() ?: throw new RuntimeException('Cannot create a temporary file for ' . $command);

        // A missing binary, or a command that exits before reading its input, is a warning; the exceptions below report it.
        set_error_handler(static fn (): bool => true);
        try {
            $process = proc_open($this->dot, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
            if ($process !== false) {
                fwrite($pipes[0], $source);
                fclose($pipes[0]);
            }
        } finally {
            restore_error_handler();
        }

        if ($process === false) {
            fclose($stderr);

            throw new RuntimeException("Cannot run {$command}: install Graphviz to render html");
        }

        $svg = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        rewind($stderr);
        $error = trim((string) stream_get_contents($stderr));
        fclose($stderr);
        $start = strpos($svg, '<svg');
        if ($code !== 0) {
            throw new RuntimeException(sprintf('%s failed with exit code %d: %s', $command, $code, $error));
        }

        if ($start === false) {
            throw new RuntimeException("{$command} printed no SVG");
        }

        return substr($svg, $start);
    }

    private const LAYOUT_CSS = <<<'CSS'
  svg.lit .node.layout-table { opacity: 1; }
  .layout-cell { transition: opacity .15s; }
  svg.lit .layout-cell { opacity: .22; }
  svg.lit .layout-cell.selected, svg.lit .layout-cell.reaches, svg.lit .layout-cell.reached-by { opacity: 1; }
  svg.lit.layout-lit .layout-row { opacity: 1; }
  .layout-cell.reaches text { fill: var(--goes-stale); font-weight: bold; }
  .layout-cell.reached-by text { fill: var(--makes-stale); font-weight: bold; }
  .layout-cell.reached-by.writer text { fill: var(--write); }
  .layout-cell.selected text { fill: var(--selected); font-weight: bold; }
  .layout-head { cursor: pointer; transition: opacity .15s; }
  svg.lit .layout-head { opacity: .22; }
  svg.lit .layout-head.selected, svg.lit .layout-head.reaches, svg.lit .layout-head.reached-by { opacity: 1; }
  .layout-head.reached-by path { filter: drop-shadow(0 0 2px var(--makes-stale)) drop-shadow(0 0 6px var(--makes-stale)); }
  .layout-head.reaches path { filter: drop-shadow(0 0 2px var(--goes-stale)) drop-shadow(0 0 6px var(--goes-stale)); }
  .layout-head.selected path { filter: drop-shadow(0 0 2px var(--selected)) drop-shadow(0 0 8px var(--selected)); }
  .layout-head.selected text:first-of-type { font-weight: bold; }
  /* A click focuses what it selects: no focus ring frames the table or a cell, the glow shows the selection. */
  .layout-table:focus, .layout-table :focus { outline: none; }

CSS;

    private const LAYOUT_INIT = "\n" . <<<'JS'
  // The layout table lights cell by cell: a row is its part, a ● the arrows from that part to the pages carrying the
  // column's layout, a column head the layout. Graphviz names a cell's group `a_` and the cell's ID.
  const layouts = JSON.parse(document.getElementById('qr-graph-layouts').textContent);
  const table = nodes.get('layouts');
  nodes.delete('layouts');
  table.classList.add('layout-table');
  ['tabindex', 'role', 'aria-label'].forEach((name) => table.removeAttribute(name));
  const cell = (id, kind = 'layout-cell') => {
    const g = document.getElementById('a_' + id);
    g.classList.add(kind);
    const box = g.getBBox();
    const hit = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    Object.entries({ x: box.x - 6, y: box.y - 6, width: box.width + 12, height: box.height + 12, fill: 'transparent' })
      .forEach(([name, value]) => hit.setAttribute(name, value));
    g.insertBefore(hit, g.firstChild);
    return g;
  };
  // A row or a ● selects the row's part, a column head its layout; anywhere else in the table selects nothing.
  const selects = (g, key) => g.addEventListener('click', (event) => {
    event.stopPropagation();
    select(key);
  });
  const focusable = (g, key, label = key) => {
    Object.entries({ tabindex: '0', role: 'button', 'aria-label': label })
      .forEach(([name, value]) => g.setAttribute(name, value));
    g.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        event.stopPropagation();
        select(key);
      }
    });
  };
  // A part with arrows of its own is also a node: the node stands for it, and its row lights with it.
  const twins = [];
  const rowCells = [];
  layouts.rows.forEach(({ id, uri }) => {
    const g = cell(id);
    nodes.has(uri) ? twins.push([nodes.get(uri), g]) : nodes.set(uri, g);
    g.classList.add('layout-row');
    rowCells.push(g);
    selects(g, uri);
    focusable(g, uri);
  });
  layouts.cells.forEach(({ id, uri, pages }) => {
    const g = cell(id);
    selects(g, uri);
    pages.forEach((page) => edges.set(uri + '->' + page, [...(edges.get(uri + '->' + page) ?? []), g]));
  });
  // The status line names a layout as it is written, `#[ContentPage]`.
  const labels = new Map();
  const heads = layouts.heads.map(({ id, key, label, pages }) => {
    const g = cell(id, 'layout-head');
    nodes.set(key, g);
    labels.set(key, label);
    selects(g, key);
    focusable(g, key, label);
    return [g, pages];
  });

JS;

    private const LAYOUT_SELECT = <<<'JS'
    twins.forEach(([node, row]) => ['selected', 'reaches', 'reached-by', 'writer']
      .forEach((name) => node.classList.contains(name) && row.classList.add(name)));
    heads.forEach(([head, pages]) => pages.includes(uri) && head.classList.add('reached-by'));
    // A selected layout lights its column; the rows, its parts, stay as they are, neither lit nor dimmed.
    svg.classList.toggle('layout-lit', labels.has(uri));
    if (labels.has(uri)) {
      rowCells.forEach((row) => row.classList.remove('reaches', 'reached-by', 'writer'));
    }
    status.textContent = labels.get(uri) ?? uri;

JS;

    private const PAGE = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>qr-graph</title>
<style>
  :root { --goes-stale: #ff8c1a; --makes-stale: #2eb85c; --write: #3b73b9; --selected: #8e44ad; }
  body { margin: 0; font: 13px/1.4 Helvetica, Arial, sans-serif; color: #222; background: #fff; }
  header { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; gap: 6px 20px; align-items: center;
    padding: 8px 16px; background: rgba(255, 255, 255, .94); border-bottom: 1px solid #e4e4e4; }
  .key { display: inline-flex; align-items: center; gap: 6px; }
  .swatch { width: 11px; height: 11px; border-radius: 2px; }
  .swatch.reached-by { background: var(--makes-stale); box-shadow: 0 0 6px 1px var(--makes-stale); }
  .swatch.reaches { background: var(--goes-stale); box-shadow: 0 0 6px 1px var(--goes-stale); }
  .swatch.writer { background: var(--write); box-shadow: 0 0 6px 1px var(--write); }
  .swatch.selected { background: var(--selected); box-shadow: 0 0 6px 1px var(--selected); }
  .count { font-weight: bold; }
  #status { color: #666; }
  main { padding: 16px; overflow: auto; }
  #warnings { padding: 4px 16px 24px; border-top: 1px solid #e4e4e4; }
  #warnings > details > summary, #warnings h2 { font-size: 15px; margin: 8px 0; }
  #warnings h3 { font-size: 13px; margin: 14px 0 2px; }
  #warnings p { margin: 0 0 4px; color: #666; }
  #warnings ul { margin: 0; padding-left: 18px; }
  #warnings li { margin: 3px 0; }
  #warnings a.uri { color: var(--selected); }
  #warnings .source { color: #666; font-size: 11px; }
  #warnings a.source { text-decoration: none; }
  #warnings a.source:hover { text-decoration: underline; }
  #warnings .message { color: #444; }
  #warnings summary { margin-top: 14px; font-weight: bold; cursor: pointer; }
  svg .node { cursor: pointer; }
  svg .node, svg .edge { transition: opacity .15s; }
  svg.lit .node, svg.lit .edge { opacity: .22; }
  svg.lit .selected, svg.lit .reaches, svg.lit .reached-by { opacity: 1; }
  .node.reached-by { filter: drop-shadow(0 0 2px var(--makes-stale)) drop-shadow(0 0 6px var(--makes-stale)); }
  .node.reaches { filter: drop-shadow(0 0 2px var(--goes-stale)) drop-shadow(0 0 6px var(--goes-stale)); }
  .node.reaches.reached-by { filter: drop-shadow(0 0 2px var(--goes-stale)) drop-shadow(0 0 6px var(--makes-stale)); }
  .node.reached-by.writer { filter: drop-shadow(0 0 2px var(--write)) drop-shadow(0 0 6px var(--write)); }
  .node.selected { filter: drop-shadow(0 0 2px var(--selected)) drop-shadow(0 0 8px var(--selected)); }
  .node.selected text:first-of-type { font-weight: bold; }
  .edge.reached-by path, .edge.reached-by polygon { stroke: var(--makes-stale); stroke-width: 2; }
  .edge.reaches path, .edge.reaches polygon { stroke: var(--goes-stale); stroke-width: 2; }
  .edge.reached-by { filter: drop-shadow(0 0 3px var(--makes-stale)); }
  .edge.reaches { filter: drop-shadow(0 0 3px var(--goes-stale)); }
  /* A lit write keeps its blue, so a refresh or purge never reads as an embed. */
  .edge.write.reached-by path, .edge.write.reached-by polygon,
  .edge.write.reaches path, .edge.write.reaches polygon { stroke: var(--write); }
  .edge.write.reached-by, .edge.write.reaches { filter: drop-shadow(0 0 3px var(--write)); }
  svg .node:focus-visible { outline: 2px solid var(--selected); outline-offset: 2px; }
{{layoutCss}}</style>
</head>
<body>
<header>
  <span class="key"><span class="swatch writer"></span>writes that invalidate this <span class="count" id="count-writer"></span></span>
  <span class="key"><span class="swatch reached-by"></span>invalidates this <span class="count" id="count-reached-by"></span></span>
  <span class="key"><span class="swatch selected"></span>clicked</span>
  <span class="key"><span class="swatch reaches"></span>invalidated by this <span class="count" id="count-reaches"></span></span>
  <span id="status">Click a resource, or focus it and press Enter; click it again, elsewhere, or press Escape to clear.</span>
</header>
<main>
{{svg}}
</main>
<section id="warnings">
{{warnings}}
</section>
<script type="application/json" id="qr-graph-data">{{json}}</script>
{{layoutData}}<script>
(() => {
  const graph = JSON.parse(document.getElementById('qr-graph-data').textContent);
  const svg = document.querySelector('main svg');
  if (!svg) {
    return;
  }
  const status = document.getElementById('status');
  const counts = {
    reaches: document.getElementById('count-reaches'),
    reachedBy: document.getElementById('count-reached-by'),
    writer: document.getElementById('count-writer'),
  };
  const hint = status.textContent;
  const title = (g) => g.querySelector('title')?.textContent ?? '';
  const nodes = new Map();
  const edges = new Map();
  svg.querySelectorAll('g.node').forEach((g) => {
    nodes.set(title(g), g);
    // A plaintext node is only its letters: give it a box to click.
    const box = g.getBBox();
    const hit = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    Object.entries({ x: box.x, y: box.y, width: box.width, height: box.height, fill: 'transparent' })
      .forEach(([name, value]) => hit.setAttribute(name, value));
    g.insertBefore(hit, g.querySelector('title').nextSibling);
    Object.entries({ tabindex: '0', role: 'button', 'aria-label': title(g) })
      .forEach(([name, value]) => g.setAttribute(name, value));
  });
  svg.querySelectorAll('g.edge').forEach((g) => {
    edges.set(title(g), [...(edges.get(title(g)) ?? []), g]);
  });
{{layoutInit}}
  const classes = ['selected', 'reaches', 'reached-by', 'writer', 'write'];
  let selected = null;
  const clear = () => {
    classes.forEach((name) => svg.querySelectorAll('.' + name).forEach((el) => el.classList.remove(name)));
    svg.classList.remove('lit');
    status.textContent = hint;
    Object.values(counts).forEach((count) => { count.textContent = ''; });
    selected = null;
  };
  // An impact step runs from `by` to `uri`; the arrow drawn for it points either way, a write's from `by`.
  const light = (set, name) => {
    set.nodes.forEach((uri) => nodes.get(uri)?.classList.add(name));
    set.edges.forEach(([by, uri]) => [...(edges.get(by + '->' + uri) ?? []), ...(edges.get(uri + '->' + by) ?? [])]
      .forEach((g) => g.classList.add(name)));
    set.writes.forEach(([by, uri]) => (edges.get(by + '->' + uri) ?? []).forEach((g) => g.classList.add('write')));
  };
  // The legend counts what the picture draws: it leaves some resources out.
  const drawn = (uris) => uris.filter((uri) => nodes.has(uri)).length;
  const select = (uri) => {
    const again = uri === selected;
    clear();
    const sets = graph[uri];
    if (again || !sets) {
      return;
    }
    selected = uri;
    svg.classList.add('lit');
    light(sets.reaches, 'reaches');
    light(sets.reachedBy, 'reached-by');
    sets.writers.forEach((writer) => nodes.get(writer)?.classList.add('writer'));
    nodes.get(uri)?.classList.add('selected');
    status.textContent = uri;
{{layoutSelect}}    const writers = new Set(sets.writers);
    counts.reaches.textContent = `(${drawn(sets.reaches.nodes)})`;
    counts.reachedBy.textContent = `(${drawn(sets.reachedBy.nodes.filter((node) => !writers.has(node)))})`;
    counts.writer.textContent = `(${drawn(sets.writers)})`;
  };

  document.addEventListener('click', (event) => {
    const item = event.target.closest?.('[data-uri]');
    if (item) {
      event.preventDefault();
      select(item.dataset.uri);
      nodes.get(selected)?.scrollIntoView({ block: 'center', inline: 'center' });
      return;
    }
    // A click on the warnings, their summary included, keeps what is selected.
    if (event.target.closest?.('#warnings')) {
      return;
    }
    const node = event.target.closest?.('g.node');
    node ? select(title(node)) : clear();
  });
  document.addEventListener('keydown', (event) => {
    const node = event.target.closest?.('g.node');
    if (node && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      select(title(node));
    } else if (event.key === 'Escape') {
      clear();
    }
  });
})();
</script>
</body>
</html>

HTML;
}
