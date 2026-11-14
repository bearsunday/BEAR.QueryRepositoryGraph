<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function addcslashes;
use function array_filter;
use function array_keys;
use function array_map;
use function array_pop;
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
 * render with `edge [dir=back]`.
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
     * Only the cached resources and what they embed, directly or not
     *
     * @param list<Node> $nodes
     */
    public function cached(array $nodes): string
    {
        return self::render($nodes, self::cachedAndEmbedded($nodes));
    }

    /**
     * @param list<Node>               $nodes
     * @param array<string, true>|null $shown the URIs to draw; null draws every resource
     */
    private static function render(array $nodes, array|null $shown): string
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
        foreach ($edges as [$from, $to]) {
            if ($from !== $to) {
                $targets[$to] = true;
            }
        }

        // A page nothing embeds is where a request starts: those line up in the leftmost column.
        $entries = array_keys(array_filter($drawn, static fn (array $node): bool => self::scheme($node['uri']) === 'page' && ! isset($targets[$node['uri']])));
        $lines = [
            'digraph qr {',
            '  rankdir=LR; nodesep=0.35; ranksep=1.1;',
            '  node [fontname="Helvetica", fontsize=13];',
            '  edge [fontname="Helvetica", fontsize=10, fontcolor="#333333", arrowhead=empty];',
            ...self::nodeLines($drawn, array_keys($targets)),
        ];
        foreach ($edges as [$from, $to, $attrs]) {
            $lines[] = sprintf('  %s -> %s [%s];', self::id($from), self::id($to), $attrs);
        }

        if ($entries !== []) {
            $lines[] = '  {rank=min; ' . implode('; ', array_map(self::id(...), $entries)) . ';}';
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The scanned resources, then the URIs only an arrow names
     *
     * @param array<string, Node> $drawn
     * @param list<string>        $targets
     *
     * @return list<string>
     */
    private static function nodeLines(array $drawn, array $targets): array
    {
        $lines = [];
        foreach ($drawn as $uri => $node) {
            $lines[] = self::node($uri, $node);
        }

        foreach ($targets as $uri) {
            if (! isset($drawn[$uri])) {
                $lines[] = self::node($uri, null);
            }
        }

        return $lines;
    }

    /**
     * A page is a grey box, any other resource bare text; the second row names the scheme and the cache it declares
     *
     * @param Node|null $node null for a URI no scanned resource serves
     */
    private static function node(string $uri, array|null $node): string
    {
        [$scheme, $host] = explode('://', $uri, 2) + [1 => ''];
        $host = explode('/', $host, 2)[0];
        $kind = $host === 'self' ? $scheme : $scheme . '://' . $host;
        $cache = $node === null ? [] : Impact::caches($node);
        if ($cache !== []) {
            $kind .= ' (' . implode(', ', $cache) . ')';
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

    private static function path(string $uri): string
    {
        return explode('/', explode('://', $uri, 2)[1] ?? '', 2)[1] ?? '';
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array<string, true> URIs of the cached resources and of everything they embed
     */
    private static function cachedAndEmbedded(array $nodes): array
    {
        $byUri = [];
        foreach ($nodes as $node) {
            $byUri[$node['uri']] = $node;
        }

        $shown = [];
        $pending = [];
        foreach (array_filter($nodes, Impact::isCached(...)) as $node) {
            $shown[$node['uri']] = true;
            $pending[] = $node['uri'];
        }

        while ($pending !== []) {
            $uri = array_pop($pending);
            foreach ($byUri[$uri]['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    if (! isset($shown[$embed['uri']])) {
                        $shown[$embed['uri']] = true;
                        $pending[] = $embed['uri'];
                    }
                }
            }
        }

        return $shown;
    }

    /**
     * @param Method                   $method
     * @param array<string, true>|null $shown  edges to other resources are left out
     *
     * @return list<array{string, string, string}> from, to and the edge attributes
     */
    private static function edges(string $from, string $name, array $method, array|null $shown): array
    {
        $edges = [];
        foreach ($method['embed'] ?? [] as $embed) {
            $edges[] = [$from, $embed['uri'], sprintf('label="%s"', addcslashes($embed['rel'], '"\\'))];
        }

        foreach ($method['dependsOn'] ?? [] as $target) {
            if ($shown === null || isset($shown[$target['uri']])) {
                $edges[] = [$from, $target['uri'], 'label="dependsOn", style=dotted'];
            }
        }

        foreach ($method['refresh'] ?? [] as $target) {
            if ($shown === null || isset($shown[$target['uri']])) {
                $edges[] = [$from, $target['uri'], sprintf('label="%s refresh", style=bold', $name)];
            }
        }

        foreach ($method['purge'] ?? [] as $target) {
            if ($shown === null || isset($shown[$target['uri']])) {
                $edges[] = [$from, $target['uri'], sprintf('label="%s purge", style=dashed', $name)];
            }
        }

        return $edges;
    }

    private static function id(string $uri): string
    {
        return '"' . addcslashes($uri, '"\\') . '"';
    }
}
