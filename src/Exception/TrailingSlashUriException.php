<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph\Exception;

use RuntimeException;

use function sprintf;

/** A declared URI, or a URI given as an argument, ends in `/` */
final class TrailingSlashUriException extends RuntimeException
{
    /** The version that stopped accepting a URI ending in `/` */
    private const SINCE = '0.2.0';

    /**
     * @param string      $written    the URI as written
     * @param string      $index      the URI to write instead
     * @param string|null $declaredBy the resource whose attribute declares it, null for an argument
     */
    public function __construct(string $written, string $index, string|null $declaredBy = null)
    {
        parent::__construct(sprintf(
            'A URI ending in "/" is no longer accepted since %s: "%s"%s. Please write "%s" instead: BEAR.Resource serves both as the index, but the cache tags the two spellings apart.',
            self::SINCE,
            $written,
            $declaredBy === null ? '' : sprintf(' in an #[Embed], #[DependsOn], #[Refresh] or #[Purge] of %s', $declaredBy),
            $index,
        ));
    }
}
