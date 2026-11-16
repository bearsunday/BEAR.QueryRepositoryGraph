<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\QueryRepositoryGraph\Exception\TrailingSlashUriException;

use function array_shift;
use function count;
use function explode;
use function implode;
use function lcfirst;
use function min;
use function preg_replace;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Class name <-> resource URI, the same mapping BEAR.Resource's AppAdapter applies in reverse
 *
 * `{Namespace}\Resource\Page\Html\BlogPosting` is `page://self/html/blog-posting`: the segment
 * after `Resource` is the scheme, each further segment is a kebab-cased path segment.
 */
final class UriConverter
{
    private const RESOURCE = '\\Resource\\';

    /** @return array{namespace: string, uri: string}|null null for a class outside any `\Resource\` namespace */
    public function fromClass(string $class): array|null
    {
        $pos = strpos($class, self::RESOURCE);
        if ($pos === false) {
            return null;
        }

        $namespace = substr($class, 0, $pos);
        $segments = explode('\\', substr($class, $pos + strlen(self::RESOURCE)));
        if (count($segments) < 2) {
            return null;
        }

        $scheme = strtolower(array_shift($segments));
        $path = [];
        foreach ($segments as $segment) {
            $path[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', lcfirst($segment)));
        }

        return ['namespace' => $namespace, 'uri' => $scheme . '://self/' . implode('/', $path)];
    }

    /**
     * Resolves a declared URI (an #[Embed] src, a #[Refresh]/#[Purge] uri) against the
     * declaring resource, and splits off its query part
     *
     * @return array{uri: string, template?: string} `uri` is exact-matchable against node URIs;
     *                                               `template` is the declaration as written, when it differs
     *
     * @throws TrailingSlashUriException
     */
    public function resolve(string $declared, string $base): array
    {
        $absolute = $declared;
        if (str_starts_with($declared, '/')) {
            // A scheme-less src is relative to the declaring resource's scheme and host.
            $absolute = substr($base, 0, (int) strpos($base, '://')) . '://self' . $declared;
        }

        $uri = $this->withoutQuery($absolute);
        if (str_ends_with($uri, '/')) {
            $path = $this->withoutQuery($declared);

            throw new TrailingSlashUriException($declared, $path . 'index' . substr($declared, strlen($path)), $declared === $base ? null : $base);
        }

        return $uri === $absolute ? ['uri' => $uri] : ['uri' => $uri, 'template' => $absolute];
    }

    /** The URI up to its query: a path template ({id}) stays; a query ({?id}, ?id=, {&id}) or fragment does not */
    public function withoutQuery(string $uri): string
    {
        $cut = strlen($uri);
        foreach (['?', '#', '{?', '{&'] as $marker) {
            $pos = strpos($uri, $marker);
            if ($pos !== false) {
                $cut = min($cut, $pos);
            }
        }

        return substr($uri, 0, $cut);
    }

    /**
     * The lookup key of a URI
     *
     * BEAR.Resource's AppAdapter resolves a path by `ucwords($path, '/-')` and removing `-`,
     * so `galleryArticle` and `gallery-article` reach the same class. Matching on this key
     * keeps those declarations on one node; the node's own URI stays canonical.
     */
    public function key(string $uri): string
    {
        $schemeEnd = strpos($uri, '://');
        if ($schemeEnd === false) {
            return strtolower($uri);
        }

        $rest = substr($uri, $schemeEnd + 3);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return strtolower($uri);
        }

        return strtolower(substr($uri, 0, $schemeEnd))
            . '://'
            . strtolower(substr($rest, 0, $slash))
            . '/'
            . strtolower(str_replace('-', '', substr($rest, $slash + 1)));
    }
}
