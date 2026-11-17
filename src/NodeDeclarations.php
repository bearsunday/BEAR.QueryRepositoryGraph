<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use Override;

use function str_starts_with;

/**
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type EmbedTarget from GraphBuilder
 */
final class NodeDeclarations implements NodeDeclarationsInterface
{
    /**
     * @param Node $node
     *
     * @return list<EmbedTarget>
     */
    #[Override]
    public function layoutEmbeds(array $node): array
    {
        return $node['layout']['embed'] ?? [];
    }

    /** @param Node $node */
    #[Override]
    public function isCached(array $node): bool
    {
        return $this->caches($node) !== [];
    }

    /**
     * @param Node $node
     *
     * @return list<string>
     */
    #[Override]
    public function caches(array $node): array
    {
        $caches = [];
        $cacheable = $node['cacheable'] ?? null;
        if ($cacheable !== null) {
            $caches[] = ($cacheable['type'] === 'view' ? 'cacheable view' : 'cacheable') . ', ttl: ' . $this->ttl($cacheable);
        }

        if (isset($node['cacheableResponse']) || $this->onGetCacheableResponse($node)) {
            $caches[] = 'cacheable response';
        }

        if (isset($node['donutCache'])) {
            $caches[] = 'donut cache';
        }

        return $caches;
    }

    /** @param array{expiry: string, expirySecond: int, expiryAt: string, update: bool, type: string} $cacheable */
    private function ttl(array $cacheable): string
    {
        if ($cacheable['expirySecond'] > 0) {
            return $cacheable['expirySecond'] . 's';
        }

        return $cacheable['expiryAt'] !== '' ? 'until ' . $cacheable['expiryAt'] : $cacheable['expiry'];
    }

    /** @param Node $node */
    private function onGetCacheableResponse(array $node): bool
    {
        foreach ($node['methods'] ?? [] as $name => $method) {
            if (str_starts_with($name, 'onGet') && isset($method['cacheableResponse'])) {
                return true;
            }
        }

        return false;
    }
}
