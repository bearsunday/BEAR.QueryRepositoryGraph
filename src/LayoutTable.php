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
 * No arrow touches the table: a part with arrows of its own is also drawn as a node.
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type Column = array{name: string, key: string, parts: array<string, true>, pages: list<string>}
 * @psalm-type Ids = array{rows: list<array{id: string, uri: string}>, cells: list<array{id: string, uri: string, pages: list<string>}>, heads: list<array{id: string, key: string, label: string, pages: list<string>}>}
 */
final class LayoutTable
{
    public const NODE = 'layouts';
    private const LINE = '#e6e2f2';

    /** @var array<string, Column> by layout class, in the order the drawn resources carry them */
    private array $columns = [];

    /** @var array<string, string> part URI => the key its row and cells are named by, in declaration order */
    private array $rows = [];

    /** @param array<string, Node> $drawn */
    public function __construct(array $drawn, private readonly DotRenderer $dot)
    {
        $keys = [];
        $rowKeys = [];
        foreach ($drawn as $uri => $node) {
            $layout = $node['layout'] ?? null;
            if ($layout === null) {
                continue;
            }

            $class = $layout['class'];
            $this->columns[$class] ??= ['name' => $layout['name'], 'key' => $this->unique($this->id($layout['name']), $keys), 'parts' => [], 'pages' => []];
            $this->columns[$class]['pages'][] = $uri;
            foreach ($layout['embed'] as $embed) {
                $this->columns[$class]['parts'][$embed['uri']] = true;
                $this->rows[$embed['uri']] ??= $this->unique($this->id($this->dot->path($embed['uri'])), $rowKeys);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /** @return array<string, true> the parts the rows stand for */
    public function uris(): array
    {
        return array_fill_keys(array_keys($this->rows), true);
    }

    /** The table alone, as html draws it above the picture; its graph id keeps the SVG ids Graphviz makes apart from the picture's */
    public function graph(): string
    {
        return sprintf("digraph %1\$s {\n  id=\"%1\$s\";\n  node [fontname=\"Helvetica\", fontsize=13];\n  %2\$s\n}\n", self::NODE, $this->node());
    }

    /** Graphviz writes a cell's ID into the SVG only when the cell has a TOOLTIP, so every cell with an ID has one. */
    public function node(): string
    {
        $uris = array_keys($this->rows);
        $kinds = array_values(array_unique(array_map($this->dot->kind(...), $uris)));
        $mixed = count($kinds) > 1;
        $prefix = $mixed ? '' : $this->prefix(array_map($this->dot->path(...), $uris));
        $corner = ($mixed ? '' : $this->escape($kinds[0] ?? '')) . ($prefix === '' ? '' : '&nbsp;&nbsp;' . $this->escape($prefix));
        $rows = ['<TR>' . $this->header($corner) . '</TR>'];
        foreach ($uris as $i => $uri) {
            $line = $this->line($i === count($uris) - 1);
            $key = $this->rows[$uri];
            $label = $mixed ? $uri : substr($this->dot->path($uri), strlen($prefix));
            $cells = [sprintf('<TD ALIGN="LEFT" ID="esi-%s" TOOLTIP="%s" %s>%s</TD>', $key, $this->escape($uri), $line, $this->escape($label))];
            foreach ($this->columns as $column) {
                $cells[] = isset($column['parts'][$uri])
                    ? sprintf('<TD ID="cell-%s-%s" TOOLTIP="#[%s] %s" %s><FONT POINT-SIZE="9" COLOR="#5b4a9e">●</FONT></TD>', $key, $column['key'], $this->escape($column['name']), $this->escape($uri), $line)
                    : sprintf('<TD %s> </TD>', $line);
            }

            $rows[] = '<TR>' . implode('', $cells) . '</TR>';
        }

        return sprintf('%s [shape=plaintext, margin=0, label=<<TABLE BORDER="0" CELLBORDER="0" CELLSPACING="0" CELLPADDING="7">%s</TABLE>>];', self::NODE, implode('', $rows));
    }

    /** The corner cell, then a head per layout */
    private function header(string $corner): string
    {
        // Graphviz rejects an empty FONT: with nothing shared, the corner is an empty cell.
        $cells = [sprintf('<TD ALIGN="LEFT" %s>%s</TD>', $this->line(false), $corner === '' ? '' : '<FONT POINT-SIZE="10" COLOR="#999999">' . $corner . '</FONT>')];
        foreach ($this->columns as $column) {
            $cells[] = sprintf(
                '<TD ID="layout-%s" TOOLTIP="#[%2$s]" %3$s><TABLE BORDER="0" CELLPADDING="6" BGCOLOR="#e8e8e8" STYLE="rounded"><TR><TD>#[%2$s]</TD></TR></TABLE></TD>',
                $column['key'],
                $this->escape($column['name']),
                $this->line(false),
            );
        }

        return implode('', $cells);
    }

    /**
     * The cell IDs the page lights: each row stands for its part, each ● for the arrows from its part to the pages
     * carrying its layout, each column head for its layout, selected by the head's `key` in the glow data
     *
     * @return Ids
     */
    public function ids(): array
    {
        $ids = ['rows' => [], 'cells' => [], 'heads' => []];
        foreach ($this->rows as $uri => $key) {
            $ids['rows'][] = ['id' => 'esi-' . $key, 'uri' => $uri];
        }

        foreach ($this->columns as $class => $column) {
            $ids['heads'][] = ['id' => 'layout-' . $column['key'], 'key' => $this->key($class), 'label' => '#[' . $column['name'] . ']', 'pages' => $column['pages']];
            foreach (array_keys($column['parts']) as $uri) {
                $ids['cells'][] = ['id' => sprintf('cell-%s-%s', $this->rows[$uri], $column['key']), 'uri' => $uri, 'pages' => $column['pages']];
            }
        }

        return $ids;
    }

    /** The layout's entry in the html page's glow data, next to the URIs' */
    public function key(string $class): string
    {
        return 'layout:' . $class;
    }

    /**
     * The directory every path starts with, cut at a `/` so that each path keeps a name of its own; empty when there is none
     *
     * @param list<string> $paths
     */
    private function prefix(array $paths): string
    {
        $prefix = $paths[0] ?? '';
        foreach ($paths as $path) {
            while (! str_starts_with($path, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        $slash = strrpos($prefix, '/');

        return $slash === false ? '' : substr($prefix, 0, $slash + 1);
    }

    private function line(bool $last): string
    {
        // The last row's line is white rather than absent, so its text lines up with the rows above.
        return sprintf('BORDER="1" SIDES="B" COLOR="%s"', $last ? 'white' : self::LINE);
    }

    /** A Graphviz port and SVG id: anything but a letter, digit, `_` or `-` becomes `-` */
    private function id(string $text): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $text);
    }

    /** @param array<string, true> $taken */
    private function unique(string $id, array &$taken): string
    {
        $unique = $id;
        for ($n = 2; isset($taken[$unique]); $n++) {
            $unique = "{$id}-{$n}";
        }

        $taken[$unique] = true;

        return $unique;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }
}
