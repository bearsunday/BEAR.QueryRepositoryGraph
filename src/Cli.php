<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use Composer\InstalledVersions;
use RuntimeException;
use Throwable;

use function array_shift;
use function class_exists;
use function count;
use function explode;
use function fwrite;
use function getcwd;
use function in_array;
use function is_dir;
use function is_string;
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
 * `dump`, `observe` and `impact --json` print JSONL on stdout, `impact`, `dot` and `snapshot` text, `html` a page; diagnostics
 * (skipped files, warnings, errors) are JSONL on stderr.
 *
 * @psalm-import-type Node from GraphBuilder
 * @psalm-type Options = array{dir: string, method: string|null, allowUnknown: bool, failOn: list<string>, cached: bool, json: bool}
 */
final class Cli
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    public const USAGE = <<<'USAGE'
Usage:
  qr-graph dump   [--dir=src/Resource]                      one JSONL line per resource
  qr-graph impact <uri> [--method=onPut] [--allow-unknown] [--json] [--dir=src/Resource]  what invalidating <uri> (or calling its write method) reaches, as a tree; --json prints one JSONL line per reached resource
  qr-graph dot    [--cached] [--dir=src/Resource]           Graphviz view; --cached keeps the cached resources, what they embed and depend on, and the writes that invalidate them
  qr-graph html   [--cached] [--dir=src/Resource]           the dot picture as one HTML page (needs Graphviz dot); click a resource to light what invalidates it and what it invalidates
  qr-graph snapshot [--dir=src/Resource]                    every impact as an indented tree, to keep and review as a diff
  qr-graph observe <session.json|dir>... [--dir=src/Resource] [--fail-on=<verdict>[,<verdict>...]]  each embedded child the log saw, joined with its declaration; --fail-on is repeatable or comma-separated and exits 3 when a printed line carries a listed verdict (e.g. --fail-on=untagged-material)

Exit codes: 0 ok, 1 error, 2 usage, 3 observe printed a line matching --fail-on.
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
        $command = (string) array_shift($argv);
        $parsed = self::parse($command, $argv);
        if (is_string($parsed)) {
            fwrite($stderr, $parsed . self::USAGE);

            return 2;
        }

        try {
            return $this->run($command, $parsed[0], $parsed[1], $stdout, $stderr);
        } catch (Throwable $e) {
            fwrite($stderr, json_encode(['error' => $e->getMessage(), 'exceptionClass' => $e::class], self::JSON) . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return array{Options, list<string>}|string the options and arguments, or the line to print before the usage
     */
    private static function parse(string $command, array $argv): array|string
    {
        $options = ['dir' => 'src/Resource', 'method' => null, 'allowUnknown' => false, 'failOn' => [], 'cached' => false, 'json' => false];
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

            if (str_starts_with($arg, '--fail-on=')) {
                foreach (explode(',', substr($arg, strlen('--fail-on='))) as $verdict) {
                    $options['failOn'][] = $verdict;
                }

                continue;
            }

            if ($arg === '--allow-unknown') {
                $options['allowUnknown'] = true;
                continue;
            }

            if ($arg === '--cached') {
                $options['cached'] = true;
                continue;
            }

            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                return "Unknown option: {$arg}\n";
            }

            $args[] = $arg;
        }

        return self::invalid($command, $options, $args) ?? [$options, $args];
    }

    /**
     * @param Options      $options
     * @param list<string> $args
     *
     * @return string|null the line to print before the usage; null when the command line is valid
     */
    private static function invalid(string $command, array $options, array $args): string|null
    {
        foreach ($options['failOn'] as $verdict) {
            if (! in_array($verdict, Observe::ALL_VERDICTS, true)) {
                return "Unknown --fail-on verdict: {$verdict}\n";
            }
        }

        if (! is_dir($options['dir'])) {
            return "No such directory: {$options['dir']}\n";
        }

        $isValid = in_array($command, ['dump', 'dot', 'html', 'impact', 'observe', 'snapshot'], true)
            && match ($command) {
                'impact' => count($args) === 1,
                'observe' => $args !== [],
                default => $args === [],
            }
            && ($command === 'impact' || ($options['method'] === null && ! $options['allowUnknown'] && ! $options['json']))
            && ($command === 'observe' || $options['failOn'] === [])
            && ($command === 'dot' || $command === 'html' || ! $options['cached']);

        return $isValid ? null : '';
    }

    /**
     * @param Options      $options
     * @param list<string> $args
     * @param resource     $stdout
     * @param resource     $stderr
     */
    private function run(string $command, array $options, array $args, $stdout, $stderr): int
    {
        $cwd = (string) getcwd();
        $graph = self::graph($options['dir'], $cwd, $stderr);
        $skipped = $graph['skipped'];

        if ($command === 'impact') {
            self::assertTarget($graph['nodes'], $args[0], $options['method'], $options['allowUnknown']);
        }

        $text = self::text($command, $options, $args, $graph['nodes']);
        if ($text !== null) {
            fwrite($stdout, $text);

            return 0;
        }

        $lines = match ($command) {
            'dump' => [
                [
                    'meta' => [
                        'version' => 5,
                        'namespaces' => $graph['namespaces'],
                        'dir' => $options['dir'],
                        'counts' => self::counts($graph['nodes'], $skipped),
                        'tool' => ['name' => 'qr-graph', 'version' => self::version()],
                        'autoload' => self::relativeAutoload($this->autoload, $cwd),
                    ],
                ],
                ...$graph['nodes'],
            ],
            'observe' => $this->observe($graph['nodes'], $args, $stderr),
            default => (new Impact($graph['nodes']))($args[0], $options['method']),
        };
        foreach ($lines as $line) {
            fwrite($stdout, json_encode($line, self::JSON) . "\n");
        }

        return $command === 'observe' && self::carries($lines, $options['failOn']) ? 3 : 0;
    }

    /**
     * @param Options      $options
     * @param list<string> $args
     * @param list<Node>   $nodes
     *
     * @return string|null what the command prints as text; null for a command that prints JSONL
     */
    private static function text(string $command, array $options, array $args, array $nodes): string|null
    {
        $dot = new DotRenderer();

        return match (true) {
            $command === 'dot' => $options['cached'] ? $dot->cached($nodes) : $dot($nodes),
            $command === 'html' => (new HtmlRenderer())($nodes, $options['cached'] ? $dot->cached($nodes) : $dot($nodes)),
            $command === 'snapshot' => (new SnapshotRenderer())($nodes),
            $command === 'impact' && ! $options['json'] => (new SnapshotRenderer())->impact($nodes, $args[0], $options['method']),
            default => null,
        };
    }

    /**
     * Scans the directory, writing the skipped files and the #[DependsOn] warnings on stderr
     *
     * @param resource $stderr
     *
     * @return array{namespaces: array<string, string>, nodes: non-empty-list<Node>, skipped: int}
     *
     * @throws RuntimeException when no resource class is found.
     */
    private static function graph(string $dir, string $cwd, $stderr): array
    {
        $scanner = new ResourceScanner();
        $classes = $scanner->scan($dir);
        $graph = (new GraphBuilder(new UriConverter(), $cwd))->build($classes);
        $skipped = count($scanner->skipped);
        foreach ($scanner->skipped as $file) {
            fwrite($stderr, json_encode(['skipped' => $file['file'], 'reason' => $file['reason']], self::JSON) . "\n");
        }

        if ($graph['nodes'] === []) {
            throw new RuntimeException(sprintf(
                'No resource classes found in %s%s',
                $dir,
                $skipped === 0 ? '' : " ({$skipped} file(s) skipped)",
            ));
        }

        foreach ((new DependsOnWarnings())($classes, $graph['nodes']) as $warning) {
            fwrite($stderr, json_encode($warning, self::JSON) . "\n");
        }

        return $graph + ['skipped' => $skipped];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<string>               $verdicts
     */
    private static function carries(array $lines, array $verdicts): bool
    {
        foreach ($lines as $line) {
            if (in_array($line['verdict'] ?? null, $verdicts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Node>   $nodes
     * @param list<string> $paths
     * @param resource     $stderr
     *
     * @return list<array<string, mixed>>
     */
    private function observe(array $nodes, array $paths, $stderr): array
    {
        $observe = new Observe($nodes);
        $lines = $observe($paths);
        if (! $observe->sawSkipped) {
            fwrite($stderr, json_encode(['warning' => 'No dependency_skipped in the sessions read. BEAR.QueryRepository before 1.19 does not record it, and then holes and untagged children read as unclassified.'], self::JSON) . "\n");
        }

        foreach ($lines as $line) {
            if ($line['verdict'] === 'untagged-material') {
                fwrite($stderr, json_encode([
                    'warning' => 'untagged-material',
                    'page' => $line['page'],
                    'child' => $line['child'],
                    'message' => 'The page read this child, but the child carries no ETag or Surrogate-Key, so a change to the child never reaches the page; make the child cacheable or render it as a hole.',
                ], self::JSON) . "\n");
            }
        }

        return $lines;
    }

    /**
     * @param list<Node> $nodes
     *
     * @throws RuntimeException when the target URI or its write method is not in the graph.
     */
    private static function assertTarget(array $nodes, string $uri, string|null $method, bool $allowUnknown): void
    {
        $converter = new UriConverter();
        $uri = $converter->resolve($uri, $uri)['uri'];
        $key = $converter->key($uri);
        foreach ($nodes as $node) {
            if ($converter->key($node['uri']) !== $key) {
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
     * @param list<Node> $nodes
     *
     * @return array{nodes: int, edges: int, skipped: int}
     */
    private static function counts(array $nodes, int $skipped): array
    {
        $edges = 0;
        foreach ($nodes as $node) {
            foreach ($node['methods'] ?? [] as $method) {
                $edges += count($method['embed'] ?? []) + count($method['dependsOn'] ?? []) + count($method['refresh'] ?? []) + count($method['purge'] ?? []);
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
            return InstalledVersions::getPrettyVersion('bear/query-repository-graph') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
