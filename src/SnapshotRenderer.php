<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use function array_filter;
use function implode;
use function ksort;
use function sprintf;
use function str_repeat;
use function usort;

/**
 * Every impact as an indented tree, to keep in the repository and review as a diff
 *
 * One block per resource whose invalidation reaches another, then one per write method that declares
 * #[Refresh] or #[Purge]. Children are sorted by URI and no line carries a source line, so the text changes
 * only when what an invalidation reaches changes.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type Method from GraphBuilder
 * @psalm-import-type ImpactLine from Impact
 */
final class SnapshotRenderer
{
    /** @param list<Node> $nodes */
    public function __invoke(array $nodes): string
    {
        $impact = new Impact($nodes);
        $caches = self::caches($nodes);

        $blocks = [];
        foreach ($nodes as $node) {
            $reached = array_filter($impact($node['uri']), static fn (array $line): bool => $line['depth'] > 0);
            if ($reached !== []) {
                $blocks[] = $node['uri'] . "\n" . self::tree($reached, $node['uri'], 0, $caches);
            }
        }

        foreach ($nodes as $node) {
            $methods = $node['methods'] ?? [];
            ksort($methods);
            foreach ($methods as $name => $method) {
                if (self::declaresInvalidation($method)) {
                    $blocks[] = $node['uri'] . ' ' . $name . "\n" . self::tree($impact($node['uri'], $name), $node['uri'], 0, $caches);
                }
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * What invalidating `$uri`, or calling its write method, reaches: the snapshot's block for it, printed even when it reaches nothing
     *
     * @param list<Node> $nodes
     */
    public function impact(array $nodes, string $uri, string|null $method): string
    {
        $impact = new Impact($nodes);
        $root = $impact->start($uri);

        return ($method === null ? $root : "{$root} {$method}") . "\n" . self::tree($impact($uri, $method), $root, 0, self::caches($nodes));
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array<string, list<string>>
     */
    private static function caches(array $nodes): array
    {
        $caches = [];
        foreach ($nodes as $node) {
            $caches[$node['uri']] = Impact::caches($node);
        }

        return $caches;
    }

    /**
     * The lines one step deeper whose `by` is the parent: a write's own resource can be both its root and one of its lines
     *
     * @param array<ImpactLine>           $lines
     * @param array<string, list<string>> $caches the cache attributes of each resource, as Impact::caches() names them
     */
    private static function tree(array $lines, string $parent, int $depth, array $caches): string
    {
        $children = array_filter($lines, static fn (array $line): bool => $line['by'] === $parent && $line['depth'] === $depth + 1);
        usort($children, static fn (array $left, array $right): int => $left['uri'] <=> $right['uri']);
        $text = '';
        foreach ($children as $line) {
            $notes = [$line['via'], ...$caches[$line['uri']] ?? []];
            $text .= sprintf("%s%s (%s)\n", str_repeat('  ', $depth + 1), $line['uri'], implode(', ', $notes));
            $text .= self::tree($lines, $line['uri'], $depth + 1, $caches);
        }

        return $text;
    }

    /** @param Method $method */
    private static function declaresInvalidation(array $method): bool
    {
        foreach ([...$method['refresh'] ?? [], ...$method['purge'] ?? []] as $target) {
            if (! isset($target['implicit'])) {
                return true;
            }
        }

        return false;
    }
}
