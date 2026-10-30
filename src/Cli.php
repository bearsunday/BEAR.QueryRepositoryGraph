<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use RuntimeException;
use Throwable;

use function array_shift;
use function class_exists;
use function count;
use function fwrite;
use function getcwd;
use function in_array;
use function is_dir;
use function json_encode;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * `qr-graph` - the declared cache graph of a BEAR.Sunday application
 *
 * Output is JSONL on stdout; diagnostics (skipped files, errors) are JSONL on stderr.
 */
final class Cli
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    public const USAGE = <<<'USAGE'
Usage:
  qr-graph dump   [--dir=src/Resource]                      one JSONL line per resource
  qr-graph impact <uri> [--method=onPut] [--allow-unknown] [--dir=src/Resource]  what invalidating <uri> (or calling its write method) reaches
  qr-graph dot    [--dir=src/Resource]                      Graphviz view

USAGE;

    public function __construct(private readonly string|null $autoload = null)
    {
    }

    /**
     * @param list<string> $argv   without the program name
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public function __invoke(array $argv, $stdout, $stderr): int
    {
        $command = array_shift($argv);
        $options = ['dir' => 'src/Resource', 'method' => null, 'allowUnknown' => false];
        $args = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--dir=')) {
                $options['dir'] = substr($arg, strlen('--dir='));
                continue;
            }

            if (str_starts_with($arg, '--method=')) {
                $options['method'] = substr($arg, strlen('--method='));
                continue;
            }

            if ($arg === '--allow-unknown') {
                $options['allowUnknown'] = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                fwrite($stderr, "Unknown option: {$arg}\n" . self::USAGE);

                return 2;
            }

            $args[] = $arg;
        }

        if (! is_dir($options['dir'])) {
            fwrite($stderr, "No such directory: {$options['dir']}\n" . self::USAGE);

            return 2;
        }

        $isCommand = in_array($command, ['dump', 'dot', 'impact'], true);
        $isValid = $isCommand
            && ($command === 'impact' ? count($args) === 1 : $args === [])
            && ($command === 'impact' || ($options['method'] === null && ! $options['allowUnknown']));
        if (! $isValid) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        try {
            $cwd = (string) getcwd();
            $scanner = new ResourceScanner();
            $graph = (new GraphBuilder(new UriConverter(), $cwd))->build($scanner->scan($options['dir']));
            $skipped = count($scanner->skipped);
            foreach ($scanner->skipped as $file) {
                fwrite($stderr, json_encode(['skipped' => $file['file'], 'reason' => $file['reason']], self::JSON) . "\n");
            }

            if ($graph['nodes'] === []) {
                throw new RuntimeException(sprintf(
                    'No resource classes found in %s%s',
                    $options['dir'],
                    $skipped === 0 ? '' : " ({$skipped} file(s) skipped)",
                ));
            }

            if ($command === 'dot') {
                fwrite($stdout, (new DotRenderer())($graph['nodes']));

                return 0;
            }

            if ($command === 'impact') {
                self::assertTarget($graph['nodes'], $args[0], $options['method'], $options['allowUnknown']);
            }

            $lines = $command === 'dump'
                ? [[
                    'meta' => [
                        'version' => 2,
                        'namespaces' => $graph['namespaces'],
                        'dir' => $options['dir'],
                        'counts' => self::counts($graph['nodes'], $skipped),
                        'tool' => ['name' => 'qr-graph', 'version' => self::version()],
                        'autoload' => self::relativeAutoload($this->autoload, $cwd),
                    ],
                ], ...$graph['nodes']]
                : (new Impact($graph['nodes']))($args[0], $options['method']);
            foreach ($lines as $line) {
                fwrite($stdout, json_encode($line, self::JSON) . "\n");
            }
        } catch (Throwable $e) {
            fwrite($stderr, json_encode(['error' => $e->getMessage(), 'exceptionClass' => $e::class], self::JSON) . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * @param list<array{uri: string, methods?: array<string, array{embed?: list<mixed>, refresh?: list<mixed>, purge?: list<mixed>}>, cacheable?: mixed, donutCache?: mixed, cacheableResponse?: mixed}> $nodes
     *
     * @throws RuntimeException when the target URI or its write method is not in the graph
     */
    private static function assertTarget(array $nodes, string $uri, string|null $method, bool $allowUnknown): void
    {
        $uri = (new UriConverter())->resolve($uri, $uri)['uri'];
        foreach ($nodes as $node) {
            if ($node['uri'] !== $uri) {
                continue;
            }

            if ($method === null) {
                return;
            }

            $declared = $node['methods'][$method] ?? null;
            if ($declared !== null && (($declared['refresh'] ?? []) !== [] || ($declared['purge'] ?? []) !== [])) {
                return;
            }

            throw new RuntimeException("No write method {$method} on {$uri}");
        }

        if ($allowUnknown) {
            return;
        }

        throw new RuntimeException("No resource {$uri}");
    }

    /**
     * @param list<array{uri: string, methods?: array<string, array{embed?: list<mixed>, refresh?: list<mixed>, purge?: list<mixed>}>}> $nodes
     *
     * @return array{nodes: int, edges: int, skipped: int}
     */
    private static function counts(array $nodes, int $skipped): array
    {
        $edges = 0;
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                $edges += count($method['embed'] ?? []) + count($method['refresh'] ?? []) + count($method['purge'] ?? []);
            }
        }

        return ['nodes' => count($nodes), 'edges' => $edges, 'skipped' => $skipped];
    }

    /** The autoloader as the application refers to it: relative to the working directory, never a machine path. */
    private static function relativeAutoload(string|null $autoload, string $cwd): string|null
    {
        if ($autoload === null) {
            return null;
        }

        $path = str_replace('\\', '/', $autoload);
        $root = str_replace('\\', '/', $cwd);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : null;
    }

    /** The installed package version, or "unknown" when this is not a composer install. */
    private static function version(): string
    {
        if (! class_exists('Composer\InstalledVersions')) {
            return 'unknown';
        }

        try {
            return \Composer\InstalledVersions::getPrettyVersion('bear/query-repository-graph') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
