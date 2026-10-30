<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use RuntimeException;
use Throwable;

use function array_shift;
use function count;
use function fwrite;
use function getcwd;
use function in_array;
use function is_dir;
use function json_encode;
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
  qr-graph impact <uri> [--method=onPut] [--dir=src/Resource]  what invalidating <uri> (or calling its write method) reaches
  qr-graph dot    [--dir=src/Resource]                      Graphviz view

USAGE;

    /**
     * @param list<string> $argv   without the program name
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public function __invoke(array $argv, $stdout, $stderr): int
    {
        $command = array_shift($argv);
        $options = ['dir' => 'src/Resource', 'method' => null];
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
            && ($command === 'impact' || $options['method'] === null);
        if (! $isValid) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        try {
            $scanner = new ResourceScanner();
            $graph = (new GraphBuilder(new UriConverter(), (string) getcwd()))->build($scanner->scan($options['dir']));
            foreach ($scanner->skipped as $skipped) {
                fwrite($stderr, json_encode(['skipped' => $skipped['file'], 'reason' => $skipped['reason']], self::JSON) . "\n");
            }

            if ($command === 'dot') {
                fwrite($stdout, (new DotRenderer())($graph['nodes']));

                return 0;
            }

            if ($command === 'impact' && $options['method'] !== null) {
                self::assertWriteMethod($graph['nodes'], $args[0], $options['method']);
            }

            $lines = $command === 'dump'
                ? [['meta' => ['version' => 1, 'namespaces' => $graph['namespaces'], 'dir' => $options['dir']]], ...$graph['nodes']]
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
     * @param list<array{uri: string, methods?: array<string, array{refresh?: list<mixed>, purge?: list<mixed>}>}> $nodes
     *
     * @throws RuntimeException when the method refreshes and purges nothing
     */
    private static function assertWriteMethod(array $nodes, string $uri, string $method): void
    {
        $uri = (new UriConverter())->resolve($uri, $uri)['uri'];
        foreach ($nodes as $node) {
            if ($node['uri'] !== $uri) {
                continue;
            }

            $declared = $node['methods'][$method] ?? null;
            if ($declared !== null && (($declared['refresh'] ?? []) !== [] || ($declared['purge'] ?? []) !== [])) {
                return;
            }
        }

        throw new RuntimeException("No write method {$method} on {$uri}");
    }
}
