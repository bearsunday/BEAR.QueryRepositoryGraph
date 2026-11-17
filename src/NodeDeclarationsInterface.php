<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

/**
 * What one scanned resource declares, read off its node alone
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-import-type EmbedTarget from GraphBuilder
 */
interface NodeDeclarationsInterface
{
    /**
     * The parts the resource's layout places in its body, each embedded as an onGet() #[Embed] is
     *
     * @param Node $node
     *
     * @return list<EmbedTarget>
     */
    public function layoutEmbeds(array $node): array;

    /**
     * Whether the resource declares a cache at all, on the class or on onGet()
     *
     * @param Node $node
     */
    public function isCached(array $node): bool;

    /**
     * The cache attributes the resource declares, as named in the code; only #[Cacheable] expires by time, so only it carries a TTL
     *
     * @param Node $node
     *
     * @return list<string> `cacheable, ttl: medium`, `cacheable view, ttl: medium`, `cacheable response`, `donut cache`
     */
    public function caches(array $node): array;
}
