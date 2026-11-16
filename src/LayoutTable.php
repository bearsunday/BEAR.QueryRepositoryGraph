<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function htmlspecialchars;
use function implode;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * The layouts of the drawn resources as one table node: a column per layout, a row per part, ● where the layout has the part
 *
 * A part is drawn as its row, never as a node of its own: an arrow to or from it attaches to the row's port.
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type Column = array{name: string, key: string, parts: array<string, true>, pages: list<string>}
 * @psalm-type Ids = array{rows: list<array{id: string, uri: string}>, cells: list<array{id: string, uri: string, pages: list<string>}>, heads: list<array{id: string, pages: list<string>}>}
 */
final class LayoutTable
{
    public const NODE = 'layouts';
    private const LINE = '#e6e2f2';

    /** @var array<string, Column> by layout class, in the order the drawn resources carry them */
    private array $columns = [];

    /** @var array<string, string> part URI => port, in declaration order */
    private array $ports = [];

    /** @param array<string, Node> $drawn */
    public function __construct(array $drawn)
    {
        $keys = [];
        $ports = [];
        foreach ($drawn as $uri => $node) {
            $layout = $node['layout'] ?? null;
            if ($layout === null) {
                continue;
            }

            $class = $layout['class'];
            $this->columns[$class] ??= ['name' => $layout['name'], 'key' => self::unique(self::id($layout['name']), $keys), 'parts' => [], 'pages' => []];
            $this->columns[$class]['pages'][] = $uri;
            foreach ($layout['embed'] as $embed) {
                $this->columns[$class]['parts'][$embed['uri']] = true;
                $this->ports[$embed['uri']] ??= self::unique(self::id(DotRenderer::path($embed['uri'])), $ports);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function has(string $uri): bool
    {
        return isset($this->ports[$uri]);
    }

    /** @return array<string, true> the parts the rows stand for */
    public function uris(): array
    {
        return array_fill_keys(array_keys($this->ports), true);
    }

    /** @param 'e'|'w' $side the side an arrow leaves or enters the row on */
    public function endpoint(string $uri, string $side): string
    {
        return sprintf('%s:"%s":%s', self::NODE, $this->ports[$uri], $side);
    }

    /** Graphviz writes a cell's ID into the SVG only when the cell has a TOOLTIP, so every cell with an ID has one. */
    public function node(): string
    {
        $uris = array_keys($this->ports);
        $kinds = array_values(array_unique(array_map(DotRenderer::kind(...), $uris)));
        $mixed = count($kinds) > 1;
        $prefix = $mixed ? '' : self::prefix(array_map(DotRenderer::path(...), $uris));
        $corner = ($mixed ? '' : self::escape($kinds[0] ?? '')) . ($prefix === '' ? '' : '&nbsp;&nbsp;' . self::escape($prefix));
        $header = [sprintf('<TD ALIGN="LEFT" %s><FONT POINT-SIZE="10" COLOR="#999999">%s</FONT></TD>', self::line(false), $corner)];
        foreach ($this->columns as $column) {
            $header[] = sprintf('<TD ID="layout-%s" TOOLTIP="#[%2$s]" %3$s><FONT COLOR="#3d2f7a">#[%2$s]</FONT></TD>', $column['key'], self::escape($column['name']), self::line(false));
        }

        $rows = ['<TR>' . implode('', $header) . '</TR>'];
        foreach ($uris as $i => $uri) {
            $line = self::line($i === count($uris) - 1);
            $port = $this->ports[$uri];
            $label = $mixed ? $uri : substr(DotRenderer::path($uri), strlen($prefix));
            $cells = [sprintf('<TD ALIGN="LEFT" ID="esi-%1$s" PORT="%1$s" TOOLTIP="%2$s" %3$s>%4$s</TD>', $port, self::escape($uri), $line, self::escape($label))];
            foreach ($this->columns as $column) {
                $cells[] = isset($column['parts'][$uri])
                    ? sprintf('<TD ID="cell-%s-%s" TOOLTIP="#[%s] %s" %s><FONT POINT-SIZE="9" COLOR="#5b4a9e">●</FONT></TD>', $port, $column['key'], self::escape($column['name']), self::escape($uri), $line)
                    : sprintf('<TD %s> </TD>', $line);
            }

            $rows[] = '<TR>' . implode('', $cells) . '</TR>';
        }

        return sprintf('%s [shape=plaintext, margin=0, label=<<TABLE BORDER="0" CELLBORDER="0" CELLSPACING="0" CELLPADDING="7">%s</TABLE>>];', self::NODE, implode('', $rows));
    }

    /**
     * The cell IDs the page lights: each row stands for its part, each ● for the arrows from its part to the pages carrying its layout
     *
     * @return Ids
     */
    public function ids(): array
    {
        $ids = ['rows' => [], 'cells' => [], 'heads' => []];
        foreach ($this->ports as $uri => $port) {
            $ids['rows'][] = ['id' => 'esi-' . $port, 'uri' => $uri];
        }

        foreach ($this->columns as $column) {
            $ids['heads'][] = ['id' => 'layout-' . $column['key'], 'pages' => $column['pages']];
            foreach (array_keys($column['parts']) as $uri) {
                $ids['cells'][] = ['id' => sprintf('cell-%s-%s', $this->ports[$uri], $column['key']), 'uri' => $uri, 'pages' => $column['pages']];
            }
        }

        return $ids;
    }

    /**
     * The directory every path starts with, cut at a `/` so that each path keeps a name of its own; empty when there is none
     *
     * @param list<string> $paths
     */
    private static function prefix(array $paths): string
    {
        $prefix = $paths[0] ?? '';
        foreach ($paths as $path) {
            while (! str_starts_with($path, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        $slash = strrpos($prefix, '/');
        $prefix = $slash === false ? '' : substr($prefix, 0, $slash + 1);
        foreach ($paths as $path) {
            if ($path === $prefix) {
                return '';
            }
        }

        return $prefix;
    }

    private static function line(bool $last): string
    {
        // The last row's line is white rather than absent, so its text lines up with the rows above.
        return sprintf('BORDER="1" SIDES="B" COLOR="%s"', $last ? 'white' : self::LINE);
    }

    /** A Graphviz port and SVG id: anything but a letter, digit, `_` or `-` becomes `-` */
    private static function id(string $text): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $text);
    }

    /** @param array<string, true> $taken */
    private static function unique(string $id, array &$taken): string
    {
        $unique = $id;
        for ($n = 2; isset($taken[$unique]); $n++) {
            $unique = "{$id}-{$n}";
        }

        $taken[$unique] = true;

        return $unique;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }
}
