<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\Resource\ResourceObject;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function class_exists;
use function file_get_contents;
use function in_array;
use function is_array;
use function ksort;
use function realpath;
use function str_ends_with;
use function str_replace;
use function token_get_all;
use function usort;

use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_NAMESPACE;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Finds the resource classes under a directory
 *
 * Classes are loaded through the application's autoloader only; a file is never included
 * directly, so a file the autoloader does not resolve is reported as skipped, not executed.
 */
final class ResourceScanner
{
    /** @var list<array{file: string, reason: string}> */
    public array $skipped = [];

    /** @return array<class-string<ResourceObject>, ReflectionClass<ResourceObject>> keyed and sorted by class */
    public function scan(string $dir): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            $reported = str_replace('\\', '/', $path);
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            foreach ($this->classNames($path) as $class) {
                if (! class_exists($class)) {
                    $this->skipped[] = ['file' => $reported, 'reason' => "{$class} is not autoloadable"];
                    continue;
                }

                $ref = new ReflectionClass($class);
                if ($ref->getFileName() !== realpath($path)) {
                    // The autoloader resolves this class to another file: this one is never loaded.
                    $loaded = str_replace('\\', '/', (string) $ref->getFileName());
                    $this->skipped[] = ['file' => $reported, 'reason' => "{$class} is loaded from {$loaded}"];
                    continue;
                }

                if ($ref->isAbstract() || ! $ref->isSubclassOf(ResourceObject::class)) {
                    continue;
                }

                /** @var ReflectionClass<ResourceObject> $resource */
                $resource = $ref;
                $classes[$resource->getName()] = $resource;
            }
        }

        ksort($classes);
        usort($this->skipped, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $classes;
    }

    /**
     * Classes declared in the file, in declaration order
     *
     * Tokens, not a regex: a declaration is found wherever PHP puts it (one line with an
     * attribute, a braced namespace), and a class is never mistaken for a mention in code.
     *
     * @return list<class-string>
     */
    private function classNames(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $namespace = '';
        $classes = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    $next = $tokens[$j];
                    if ($next === ';' || $next === '{') {
                        break;
                    }

                    if (! is_array($next) || in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    $namespace .= $next[1];
                }

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // Foo::class is not a declaration.
            for ($p = $i - 1; isset($tokens[$p]) && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE; $p--);
            if (isset($tokens[$p]) && is_array($tokens[$p]) && $tokens[$p][0] === T_DOUBLE_COLON) {
                continue;
            }

            // An anonymous class has no name token.
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if (! isset($tokens[$j]) || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }

            $classes[] = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j][1];
        }

        return $classes;
    }
}
