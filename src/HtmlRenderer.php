<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use RuntimeException;

use function array_keys;
use function array_values;
use function fclose;
use function fwrite;
use function implode;
use function json_encode;
use function ksort;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function strpos;
use function strtr;
use function substr;
use function trim;

use const JSON_HEX_AMP;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The Graphviz picture as one self-contained HTML page: clicking a resource lights what `impact` reaches from it
 * and what reaches it
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type Lit = array{nodes: list<string>, edges: list<array{string, string}>}
 * @psalm-type Glow = array<string, array{reaches: Lit, reachedBy: Lit}>
 */
final class HtmlRenderer
{
    /** @param non-empty-list<string> $dot the command that reads dot on stdin and writes SVG on stdout */
    public function __construct(private readonly array $dot = ['dot', '-Tsvg'])
    {
    }

    /**
     * @param list<Node> $nodes  the whole graph: what is reached is computed on it, not only on what is drawn
     * @param string     $source the dot to draw
     *
     * @throws RuntimeException when the dot command cannot run or fails.
     */
    public function __invoke(array $nodes, string $source): string
    {
        $json = json_encode(self::glow($nodes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        return strtr(self::PAGE, ['{{svg}}' => $this->svg($source), '{{json}}' => $json]);
    }

    /**
     * For every drawable URI: what invalidating it, or calling one of its write methods, reaches (`reaches`), and the
     * URIs whose `reaches` hold it (`reachedBy`). Edges are `[by, uri]` of the impact lines on the way, one shortest
     * path per reached URI and start.
     *
     * @param list<Node> $nodes
     *
     * @return Glow
     */
    private static function glow(array $nodes): array
    {
        $impact = new Impact($nodes);
        $reaches = [];
        $reachesEdges = [];
        $reachedBy = [];
        $reachedByEdges = [];
        $uris = self::uris($nodes);
        foreach ($uris as $uri => $methods) {
            $starts = [$impact($uri)];
            foreach ($methods as $method) {
                $starts[] = $impact($uri, $method);
            }

            foreach ($starts as $lines) {
                $byUri = [];
                foreach ($lines as $line) {
                    $byUri[$line['uri']] = $line;
                }

                foreach ($lines as $line) {
                    $reached = $line['uri'];
                    if ($reached === $uri || $line['by'] === null) {
                        continue;
                    }

                    $reaches[$uri][$reached] = true;
                    $reachesEdges[$uri][$line['by'] . ' ' . $reached] = [$line['by'], $reached];
                    $reachedBy[$reached][$uri] = true;
                    $step = $line;
                    while ($step !== null && $step['by'] !== null && $step['uri'] !== $uri) {
                        $reachedByEdges[$reached][$step['by'] . ' ' . $step['uri']] = [$step['by'], $step['uri']];
                        $step = $byUri[$step['by']] ?? null;
                    }
                }
            }
        }

        $glow = [];
        foreach ([...array_keys($uris), ...array_keys($reachedBy)] as $uri) {
            $glow[$uri] = [
                'reaches' => self::lit($reaches[$uri] ?? [], $reachesEdges[$uri] ?? []),
                'reachedBy' => self::lit($reachedBy[$uri] ?? [], $reachedByEdges[$uri] ?? []),
            ];
        }

        ksort($glow);

        return $glow;
    }

    /**
     * The scanned resources with their write methods that refresh or purge, then the URIs only a declaration names
     *
     * @param list<Node> $nodes
     *
     * @return array<string, list<string>>
     */
    private static function uris(array $nodes): array
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
        }

        return $uris;
    }

    /**
     * @param array<string, true>                  $nodes
     * @param array<string, array{string, string}> $edges keyed to sort them
     *
     * @return Lit
     */
    private static function lit(array $nodes, array $edges): array
    {
        ksort($nodes);
        ksort($edges);

        return ['nodes' => array_keys($nodes), 'edges' => array_values($edges)];
    }

    /** @throws RuntimeException */
    private function svg(string $source): string
    {
        $command = implode(' ', $this->dot);
        $process = @proc_open($this->dot, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException("Cannot run {$command}: install Graphviz to render html");
        }

        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $svg = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $start = strpos($svg, '<svg');
        if ($code !== 0 || $start === false) {
            throw new RuntimeException(sprintf('%s failed with exit code %d: %s', $command, $code, trim($error)));
        }

        return substr($svg, $start);
    }

    private const PAGE = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>qr-graph</title>
<style>
  :root { --goes-stale: #ff8c1a; --makes-stale: #2eb85c; --selected: #1f6feb; }
  body { margin: 0; font: 13px/1.4 Helvetica, Arial, sans-serif; color: #222; background: #fff; }
  header { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; gap: 6px 20px; align-items: center;
    padding: 8px 16px; background: rgba(255, 255, 255, .94); border-bottom: 1px solid #e4e4e4; }
  .key { display: inline-flex; align-items: center; gap: 6px; }
  .swatch { width: 11px; height: 11px; border-radius: 2px; }
  .swatch.reached-by { background: var(--makes-stale); box-shadow: 0 0 6px 1px var(--makes-stale); }
  .swatch.reaches { background: var(--goes-stale); box-shadow: 0 0 6px 1px var(--goes-stale); }
  .swatch.selected { background: var(--selected); box-shadow: 0 0 6px 1px var(--selected); }
  .count { font-weight: bold; }
  #status { color: #666; }
  main { padding: 16px; overflow: auto; }
  svg .node { cursor: pointer; }
  svg .node, svg .edge { transition: opacity .15s; }
  svg.lit .node, svg.lit .edge { opacity: .22; }
  svg.lit .selected, svg.lit .reaches, svg.lit .reached-by { opacity: 1; }
  .node.reached-by { filter: drop-shadow(0 0 2px var(--makes-stale)) drop-shadow(0 0 6px var(--makes-stale)); }
  .node.reaches { filter: drop-shadow(0 0 2px var(--goes-stale)) drop-shadow(0 0 6px var(--goes-stale)); }
  .node.reaches.reached-by { filter: drop-shadow(0 0 2px var(--goes-stale)) drop-shadow(0 0 6px var(--makes-stale)); }
  .node.selected { filter: drop-shadow(0 0 2px var(--selected)) drop-shadow(0 0 8px var(--selected)); }
  .node.selected text:first-of-type { font-weight: bold; }
  .edge.reached-by path, .edge.reached-by polygon { stroke: var(--makes-stale); stroke-width: 2; }
  .edge.reaches path, .edge.reaches polygon { stroke: var(--goes-stale); stroke-width: 2; }
  .edge.reached-by { filter: drop-shadow(0 0 3px var(--makes-stale)); }
  .edge.reaches { filter: drop-shadow(0 0 3px var(--goes-stale)); }
</style>
</head>
<body>
<header>
  <span class="key"><span class="swatch selected"></span>clicked</span>
  <span class="key"><span class="swatch reached-by"></span>makes it stale <span class="count" id="count-reached-by"></span></span>
  <span class="key"><span class="swatch reaches"></span>goes stale when it changes <span class="count" id="count-reaches"></span></span>
  <span id="status">Click a resource; click it again or the background to clear.</span>
</header>
<main>
{{svg}}
</main>
<script type="application/json" id="qr-graph-data">{{json}}</script>
<script>
(() => {
  const graph = JSON.parse(document.getElementById('qr-graph-data').textContent);
  const svg = document.querySelector('main svg');
  const status = document.getElementById('status');
  const counts = { reaches: document.getElementById('count-reaches'), reachedBy: document.getElementById('count-reached-by') };
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
  });
  svg.querySelectorAll('g.edge').forEach((g) => {
    edges.set(title(g), [...(edges.get(title(g)) ?? []), g]);
  });

  const classes = ['selected', 'reaches', 'reached-by'];
  let selected = null;
  const clear = () => {
    classes.forEach((name) => svg.querySelectorAll('.' + name).forEach((el) => el.classList.remove(name)));
    svg.classList.remove('lit');
    status.textContent = hint;
    counts.reaches.textContent = '';
    counts.reachedBy.textContent = '';
    selected = null;
  };
  // An impact step runs from `by` to `uri`; the arrow drawn for it points either way.
  const light = (set, name) => {
    set.nodes.forEach((uri) => nodes.get(uri)?.classList.add(name));
    set.edges.forEach(([by, uri]) => [...(edges.get(by + '->' + uri) ?? []), ...(edges.get(uri + '->' + by) ?? [])]
      .forEach((g) => g.classList.add(name)));
  };
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
    nodes.get(uri).classList.add('selected');
    status.textContent = uri;
    counts.reaches.textContent = `(${sets.reaches.nodes.length})`;
    counts.reachedBy.textContent = `(${sets.reachedBy.nodes.length})`;
  };

  svg.addEventListener('click', (event) => {
    const node = event.target.closest('g.node');
    node ? select(title(node)) : clear();
  });
  document.addEventListener('keydown', (event) => event.key === 'Escape' && clear());
})();
</script>
</body>
</html>

HTML;
}
