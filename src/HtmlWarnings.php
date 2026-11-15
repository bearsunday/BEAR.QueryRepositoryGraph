<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_column;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function sprintf;
use function strval;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * The HTML page's list of the declarations that affect no cache, then, collapsed, the resources it leaves out
 *
 * @internal
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type Method from GraphBuilder
 * @psalm-import-type ImpactLine from Impact
 * @psalm-import-type Warning from DependsOnWarnings
 */
final class HtmlWarnings
{
    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn     the URIs the picture draws: an item naming one selects it
     * @param list<Warning>       $dependsOn
     */
    public function __invoke(array $nodes, array $drawn, array $dependsOn): string
    {
        $impact = new Impact($nodes);
        $groups = self::group(
            'invalidates-no-cache',
            'Invalidates no cache',
            'The write declares #[Purge] or #[Refresh], but what it invalidates reaches no cached resource.',
            self::idleWrites($nodes, $impact, $drawn),
        ) . self::group(
            'depends-on-not-cached',
            'Depends on, but not cached',
            'The resource declares #[DependsOn], but neither it nor anything its invalidation reaches is cached.',
            self::uncachedDependents($nodes, $impact, $drawn),
        ) . self::group(
            'no-such-resource',
            'No such resource',
            'A declaration names a URI no scanned resource serves: in this application a typo or a removed resource, in another one possibly fine.',
            self::unknown($nodes, $drawn),
        ) . self::group(
            'depends-on',
            '#[DependsOn] warnings',
            'Every command also writes these on stderr.',
            array_map(static fn (array $warning): string => self::dependsOnItem($warning, $drawn), $dependsOn),
        );

        return "<h2>Warnings</h2>\n" . ($groups === '' ? "<p>No warnings.</p>\n" : $groups) . self::notDrawn($nodes, $drawn);
    }

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn
     *
     * @return list<string>
     */
    private static function idleWrites(array $nodes, Impact $impact, array $drawn): array
    {
        $items = [];
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $name => $method) {
                if (self::declaresWrite($method) && ! self::reachesCache($impact($node['uri'], $name))) {
                    $items[] = self::uri($node['uri'], $drawn) . ' ' . self::code($name) . ' ' . self::source($method['source']);
                }
            }
        }

        return $items;
    }

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn
     *
     * @return list<string>
     */
    private static function uncachedDependents(array $nodes, Impact $impact, array $drawn): array
    {
        $items = [];
        foreach ($nodes as $node) {
            $onGet = $node['methods']['onGet'] ?? null;
            if (isset($onGet['dependsOn']) && ! self::reachesCache($impact($node['uri']))) {
                $items[] = self::uri($node['uri'], $drawn) . ' ' . self::source($onGet['source']);
            }
        }

        return $items;
    }

    /**
     * The URIs declarations name that no scanned resource serves, this application's first
     *
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn
     *
     * @return list<string>
     */
    private static function unknown(array $nodes, array $drawn): array
    {
        $scanned = array_column($nodes, 'uri', 'uri');
        $named = [];
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                $declared = ['embed' => $method['embed'] ?? [], 'dependsOn' => $method['dependsOn'] ?? [], 'refresh' => $method['refresh'] ?? [], 'purge' => $method['purge'] ?? []];
                foreach ($declared as $via => $targets) {
                    foreach ($targets as $target) {
                        if (! isset($scanned[$target['uri']])) {
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
            $items[] = self::uri($uri, $drawn) . (self::isSelf($uri) ? '' : ' (another application)') . ' ' . implode(', ', $named[$uri]);
        }

        return $items;
    }

    /**
     * @param Warning             $warning
     * @param array<string, true> $drawn
     */
    private static function dependsOnItem(array $warning, array $drawn): string
    {
        return self::uri($warning['resource'], $drawn) . ' ' . self::code($warning['warning'])
            . (isset($warning['dependsOn']) ? ' ' . self::code($warning['dependsOn']) : '')
            . ' ' . self::source($warning['source'])
            . '<div class="message">' . self::escape($warning['message']) . '</div>';
    }

    /**
     * The scanned resources the picture leaves out and no warning lists
     *
     * @param list<Node>          $nodes
     * @param array<string, true> $drawn
     */
    private static function notDrawn(array $nodes, array $drawn): string
    {
        $embedding = [];
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                foreach ($method['embed'] ?? [] as $embed) {
                    $embedding[$node['uri']] = true;
                    $embedding[$embed['uri']] = true;
                }
            }
        }

        $embeds = [];
        $bare = [];
        foreach ($nodes as $node) {
            if (isset($drawn[$node['uri']])) {
                continue;
            }

            $item = self::uri($node['uri'], $drawn) . ' ' . self::source($node['source']);
            if (isset($embedding[$node['uri']]) && ! self::declaresMore($node)) {
                $embeds[] = $item;
                continue;
            }

            if (! isset($node['methods'])) {
                $bare[] = $item;
            }
        }

        $lists = self::group('embeds-uncached', 'Embedded among uncached resources only', 'Not cached, and embeds or is embedded by resources that are not cached either.', $embeds)
            . self::group('no-declarations', 'No declarations', 'Declares nothing the cache acts on.', $bare);

        return $lists === '' ? '' : "<details>\n<summary>Not drawn</summary>\n{$lists}</details>\n";
    }

    /** @param Method $method */
    private static function declaresWrite(array $method): bool
    {
        foreach ([...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
            if (! isset($target['implicit'])) {
                return true;
            }
        }

        return false;
    }

    /** @param Node $node */
    private static function declaresMore(array $node): bool
    {
        foreach ($node['methods'] ?? [] as $method) {
            if (isset($method['dependsOn']) || self::declaresWrite($method)) {
                return true;
            }
        }

        return false;
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

    /** @param array<string, true> $drawn */
    private static function uri(string $uri, array $drawn): string
    {
        $escaped = self::escape($uri);

        return isset($drawn[$uri]) ? "<a class=\"uri\" href=\"#\" data-uri=\"{$escaped}\">{$escaped}</a>" : "<span class=\"uri\">{$escaped}</span>";
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
