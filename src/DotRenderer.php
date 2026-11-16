<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function addcslashes;
use function array_filter;
use function array_keys;
use function array_map;
use function array_pop;
use function array_unique;
use function explode;
use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_XML1;

/**
 * Graphviz view of the declared graph
 *
 * Arrows point the way they are declared (a page to what it embeds, a resource to what it
 * depends on, a write to what it refreshes or purges). For the direction invalidation travels,
 * render with `edge [dir=back]`. What resource layouts embed is one table, a layout per column and a part per row,
 * not arrows.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type Method from GraphBuilder
 */
final class DotRenderer
{
    /** @param list<Node> $nodes */
    public function __invoke(array $nodes): string
    {
        return self::render($nodes, null);
    }

    /**
     * The cached resources, what they embed, directly or not, what those depend on, and the writes that invalidate them
     *
     * @param list<Node> $nodes
     */
    public function cached(array $nodes): string
    {
        return self::render($nodes, self::cachedAndInvalidators($nodes));
    }

    /**
     * The URIs `cached()` draws
     *
     * @param list<Node> $nodes
     *
     * @return array<string, true>
     */
    public function cachedUris(array $nodes): array
    {
        [$drawn, , $targets] = self::select($nodes, self::cachedAndInvalidators($nodes));
        $uris = [];
        foreach ([...array_keys($drawn), ...array_keys($targets)] as $uri) {
            $uris[$uri] = true;
        }

        return $uris + (new LayoutTable($drawn))->uris();
    }

    /**
     * The layout table `cached()` draws
     *
     * @param list<Node> $nodes
     *
     * @internal
     */
    public function cachedTable(array $nodes): LayoutTable
    {
        return new LayoutTable(self::select($nodes, self::cachedAndInvalidators($nodes))[0]);
    }

    /**
     * @param list<Node>               $nodes
     * @param array<string, true>|null $shown the URIs to draw; null draws every resource
     */
    private static function render(array $nodes, array|null $shown): string
    {
        [$drawn, $edges, $targets] = self::select($nodes, $shown);
        $table = new LayoutTable($drawn);

        // A page nothing embeds is where a request starts: those line up in the leftmost column.
        $entries = array_keys(array_filter($drawn, static fn (array $node): bool => self::scheme($node['uri']) === 'page' && ! isset($targets[$node['uri']]) && ! $table->has($node['uri'])));
        $lines = [
            'digraph qr {',
            '  rankdir=LR; nodesep=0.35; ranksep=1.1;',
            '  node [fontname="Helvetica", fontsize=13];',
            '  edge [fontname="Helvetica", fontsize=10, fontcolor="#333333", arrowhead=empty];',
            ...($table->isEmpty() ? [] : ['  ' . $table->node()]),
            ...self::nodeLines($drawn, array_keys($targets), $table),
        ];
        $edgeLines = [];
        foreach ($edges as [$from, $to, $attrs]) {
            // The SVG title of an arrow at a row names the table, not the part: its id names both ends.
            $id = $table->has($from) || $table->has($to) ? sprintf(', id="%s"', addcslashes($from . '->' . $to, '"\\')) : '';
            $edgeLines[] = sprintf('  %s -> %s [%s%s];', self::end($from, 'e', $table), self::end($to, 'w', $table), $attrs, $id);
        }

        // Declarations that differ only in their query name the same resource: one arrow each.
        $lines = [...$lines, ...array_unique($edgeLines)];

        if ($entries !== []) {
            $lines[] = '  {rank=min; ' . implode('; ', array_map(self::id(...), $entries)) . ';}';
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<Node>               $nodes
     * @param array<string, true>|null $shown
     *
     * @return array{array<string, Node>, list<array{string, string, string}>, array<string, true>} the scanned resources drawn, the arrows, and the URIs an arrow points to
     */
    private static function select(array $nodes, array|null $shown): array
    {
        $drawn = [];
        $edges = [];
        foreach ($nodes as $node) {
            if ($shown !== null && ! isset($shown[$node['uri']])) {
                continue;
            }

            $drawn[$node['uri']] = $node;
            foreach ($node['methods'] ?? [] as $name => $method) {
                $edges = [...$edges, ...self::edges($node['uri'], $name, $method, $shown)];
            }
        }

        $targets = [];
        $touched = [];
        foreach ($edges as [$from, $to]) {
            $touched[$from] = true;
            $touched[$to] = true;
            if ($from !== $to) {
                $targets[$to] = true;
            }
        }

        // A resource no arrow touches says nothing about cache dependencies, unless it is a cache no declared write
        // invalidates, or carries a layout, which the layout table draws.
        $drawn = array_filter($drawn, static fn (array $node): bool => isset($touched[$node['uri']]) || Impact::isCached($node) || isset($node['layout']));

        return [$drawn, $edges, $targets];
    }

    /**
     * The scanned resources, then the URIs only an arrow names; a part of a layout is its table row instead
     *
     * @param array<string, Node> $drawn
     * @param list<string>        $targets
     *
     * @return list<string>
     */
    private static function nodeLines(array $drawn, array $targets, LayoutTable $table): array
    {
        $lines = [];
        foreach ($drawn as $uri => $node) {
            if (! $table->has($uri)) {
                $lines[] = self::node($uri, $node);
            }
        }

        foreach ($targets as $uri) {
            if (! isset($drawn[$uri]) && ! $table->has($uri)) {
                $lines[] = self::node($uri, null);
            }
        }

        return $lines;
    }

    /** @param 'e'|'w' $side */
    private static function end(string $uri, string $side, LayoutTable $table): string
    {
        return $table->has($uri) ? $table->endpoint($uri, $side) : self::id($uri);
    }

    /**
     * A page is a grey box, any other resource bare text; the second row names the scheme, the cache it declares and its layout
     *
     * @param Node|null $node null for a URI no scanned resource serves
     */
    private static function node(string $uri, array|null $node): string
    {
        $scheme = self::scheme($uri);
        $kind = self::kind($uri);
        $notes = $node === null ? [] : Impact::caches($node);
        if (isset($node['layout'])) {
            $notes[] = '#[' . $node['layout']['name'] . ']';
        }

        if ($notes !== []) {
            $kind .= ' (' . implode(', ', $notes) . ')';
        }

        $label = sprintf(
            '<<TABLE BORDER="0" CELLBORDER="0" CELLSPACING="0" CELLPADDING="3"><TR><TD ALIGN="LEFT">%s</TD></TR><TR><TD ALIGN="LEFT"><FONT POINT-SIZE="10" COLOR="#666666">%s</FONT></TD></TR></TABLE>>',
            htmlspecialchars(self::path($uri), ENT_XML1),
            htmlspecialchars($kind, ENT_XML1),
        );
        $shape = $scheme === 'page'
            ? 'shape=box, style="filled,rounded", fillcolor="#e8e8e8", color="#e8e8e8", margin="0.05"'
            : 'shape=plaintext, margin="0.11,0.055"';

        return sprintf('  %s [%s, label=%s];', self::id($uri), $shape, $label);
    }

    private static function scheme(string $uri): string
    {
        return explode('://', $uri, 2)[0];
    }

    /**
     * The scheme, with the host when it is not `self`
     *
     * @internal
     */
    public static function kind(string $uri): string
    {
        [$scheme, $host] = explode('://', $uri, 2) + [1 => ''];
        $host = explode('/', $host, 2)[0];

        return $host === 'self' ? $scheme : $scheme . '://' . $host;
    }

    /** @internal */
    public static function path(string $uri): string
    {
        return explode('/', explode('://', $uri, 2)[1] ?? '', 2)[1] ?? '';
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array<string, true> URIs of the cached resources, everything they embed, what those depend on, and the
     *                             resources whose writes refresh or purge any of them
     */
    private static function cachedAndInvalidators(array $nodes): array
    {
        $byUri = [];
        foreach ($nodes as $node) {
            $byUri[$node['uri']] = $node;
        }

        $shown = self::cachedAndEmbedded($byUri);

        // A #[DependsOn] target is often cached nowhere: it is the name writes purge to reach what depends on it.
        foreach (array_keys($shown) as $uri) {
            foreach ($byUri[$uri]['methods'] ?? [] as $method) {
                foreach ($method['dependsOn'] ?? [] as $target) {
                    $shown[$target['uri']] = true;
                }
            }
        }

        return $shown + self::writersOf($nodes, $shown);
    }

    /**
     * @param array<string, Node> $byUri
     *
     * @return array<string, true>
     */
    private static function cachedAndEmbedded(array $byUri): array
    {
        $shown = [];
        $pending = [];
        foreach (array_keys(array_filter($byUri, Impact::isCached(...))) as $uri) {
            $shown[$uri] = true;
            $pending[] = $uri;
        }

        while ($pending !== []) {
            $uri = array_pop($pending);
            $embeds = $byUri[$uri]['layout']['embed'] ?? [];
            foreach ($byUri[$uri]['methods'] ?? [] as $method) {
                $embeds = [...$embeds, ...$method['embed'] ?? []];
            }

            foreach ($embeds as $embed) {
                if (! isset($shown[$embed['uri']])) {
                    $shown[$embed['uri']] = true;
                    $pending[] = $embed['uri'];
                }
            }
        }

        return $shown;
    }

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $targets
     *
     * @return array<string, true> the resources with a write method that refreshes or purges one of the targets
     */
    private static function writersOf(array $nodes, array $targets): array
    {
        $writers = [];
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                foreach ([...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
                    if (isset($targets[$target['uri']])) {
                        $writers[$node['uri']] = true;
                    }
                }
            }
        }

        return $writers;
    }

    /**
     * @param Method                   $method
     * @param array<string, true>|null $shown  arrows to a resource not shown are left out
     *
     * @return list<array{string, string, string}> from, to and the edge attributes
     */
    private static function edges(string $from, string $name, array $method, array|null $shown): array
    {
        $arrows = [];
        foreach ($method['embed'] ?? [] as $embed) {
            $arrows[] = [$embed['uri'], sprintf('label="%s"', addcslashes($embed['rel'], '"\\'))];
        }

        foreach ($method['dependsOn'] ?? [] as $target) {
            $arrows[] = [$target['uri'], 'label="dependsOn", color="#9a9a9a", fontcolor="#777777", penwidth=1.6'];
        }

        foreach (['refresh' => $method['refresh'] ?? [], 'purge' => $method['purge'] ?? []] as $kind => $targets) {
            foreach ($targets as $target) {
                $arrows[] = [$target['uri'], sprintf('label="%s %s", color="#3b73b9", fontcolor="#2f5f9e"', $name, $kind)];
            }
        }

        $edges = [];
        foreach ($arrows as [$to, $attrs]) {
            if ($shown === null || isset($shown[$to])) {
                $edges[] = [$from, $to, $attrs];
            }
        }

        return $edges;
    }

    private static function id(string $uri): string
    {
        return '"' . addcslashes($uri, '"\\') . '"';
    }
}
