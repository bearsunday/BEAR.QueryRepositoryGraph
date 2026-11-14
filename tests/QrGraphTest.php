<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_key_first;
use function array_map;
use function array_slice;
use function count;
use function dirname;
use function explode;
use function fopen;
use function is_array;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The declared cache graph of the fake application, as `qr-graph` prints it
 */
class QrGraphTest extends TestCase
{
    private const DIR = 'tests/Fake/fake-app/src/Resource';
    private const SESSIONS = 'tests/Fixture/Sessions';

    /** @var array<string, array<string, mixed>> */
    private static array $nodes = [];

    /** @var list<array<string, mixed>> */
    private static array $skipped = [];

    public static function setUpBeforeClass(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=' . self::DIR]);
        self::assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        $meta = $lines[0];
        self::assertSame(4, self::at($meta, 'meta', 'version'));
        self::assertSame(['self' => 'FakeVendor\HelloWorld'], self::at($meta, 'meta', 'namespaces'));
        self::assertSame(self::DIR, self::at($meta, 'meta', 'dir'));
        self::assertSame(['nodes' => 115, 'edges' => 123, 'skipped' => 2], self::at($meta, 'meta', 'counts'));
        self::assertSame('qr-graph', self::at($meta, 'meta', 'tool', 'name'));
        self::assertIsString(self::at($meta, 'meta', 'tool', 'version'));
        self::assertNotSame('', self::at($meta, 'meta', 'tool', 'version'));
        self::assertNull(self::at($meta, 'meta', 'autoload'));
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }

            /** @var string $uri */
            $uri = $line['uri'];
            self::$nodes[$uri] = $line;
        }

        self::$skipped = self::jsonl($err);
    }

    public function testUriConverterIsTheInverseOfTheResourceNaming(): void
    {
        $converter = new UriConverter();
        $this->assertSame(
            ['namespace' => 'FakeVendor\HelloWorld', 'uri' => 'page://self/html/blog-posting'],
            $converter->fromClass('FakeVendor\HelloWorld\Resource\Page\Html\BlogPosting'),
        );
        $this->assertNull($converter->fromClass('FakeVendor\HelloWorld\Module\AppModule'));
        $this->assertSame(['uri' => 'page://self/dep/level-two'], $converter->resolve('/dep/level-two', 'page://self/dep/level-one'));
        $this->assertSame(
            ['uri' => 'app://self/refresh-dest', 'template' => 'app://self/refresh-dest{?id}'],
            $converter->resolve('app://self/refresh-dest{?id}', 'app://self/refresh-src'),
        );
        $this->assertSame(
            ['uri' => 'app://self/user/friend', 'template' => 'app://self/user/friend?user_id={id}'],
            $converter->resolve('app://self/user/friend?user_id={id}', 'app://self/user'),
        );
        $this->assertSame(['uri' => 'app://self/user/{id}'], $converter->resolve('app://self/user/{id}', 'app://self/user'));
    }

    public function testEveryLineMatchesItsSchema(): void
    {
        [, $out] = self::qrGraph(['dump', '--dir=' . self::DIR]);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('dump.json', $line);
        }

        [, $out] = self::qrGraph(['impact', 'page://self/dep/diamond-bottom', '--json', '--dir=' . self::DIR]);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('impact.json', $line);
        }
    }

    public function testEmbedResolvesRelativeSrcAgainstTheDeclaringScheme(): void
    {
        $node = self::$nodes['page://self/dep/level-one'];
        $this->assertSame(['expiry' => 'never', 'expirySecond' => 0, 'expiryAt' => '', 'update' => false, 'type' => 'value'], $node['cacheable']);
        $this->assertSame([['rel' => 'level-two', 'uri' => 'page://self/dep/level-two']], self::at($node, 'methods', 'onGet', 'embed'));
        $source = $node['source'];
        $this->assertIsString($source);
        $this->assertStringStartsWith('tests/Fake/fake-app/src/Resource/Page/Dep/LevelOne.php:', $source);
    }

    public function testAFileTheAutoloaderNeverLoadsIsSkippedNotRead(): void
    {
        // App/Dep/LevelOne.php declares the Page\Dep\LevelOne class, which autoloads from Page/Dep.
        $this->assertContains('tests/Fake/fake-app/src/Resource/App/Dep/LevelOne.php', array_map(
            static fn (array $skipped): mixed => $skipped['skipped'],
            self::$skipped,
        ));
    }

    public function testWriteMethodsListImplicitAndDeclaredCommands(): void
    {
        $user = self::$nodes['app://self/user'];
        $this->assertSame([
            ['uri' => 'app://self/user', 'implicit' => 'Cacheable'],
            ['uri' => 'app://self/user/profile', 'template' => 'app://self/user/profile?user_id={id}'],
        ], self::at($user, 'methods', 'onPut', 'refresh'));
        $this->assertSame([['uri' => 'app://self/user/friend', 'template' => 'app://self/user/friend?user_id={id}']], self::at($user, 'methods', 'onPut', 'purge'));
        $this->assertSame([['uri' => 'app://self/user', 'implicit' => 'Cacheable']], self::at($user, 'methods', 'onDelete', 'refresh'));
        $this->assertNull(self::at($user, 'methods', 'onGet'), 'a method declaring nothing is left out');

        // Without #[Cacheable] only the declared command runs.
        $this->assertSame(
            [['uri' => 'app://self/refresh-dest', 'template' => 'app://self/refresh-dest{?id}']],
            self::at(self::$nodes['app://self/refresh-src'], 'methods', 'onPut', 'refresh'),
        );
    }

    public function testDonutPageAndDynamicSurrogateKey(): void
    {
        $node = self::$nodes['page://self/html/blog-posting'];
        $this->assertTrue($node['cacheableResponse']);
        $this->assertSame(['surrogate-key'], $node['dynamic']);
        $this->assertSame([['uri' => 'page://self/html/blog-posting', 'implicit' => 'CacheableResponse']], self::at($node, 'methods', 'onDelete', 'refresh'));
        $this->assertArrayNotHasKey('dynamic', self::$nodes['page://self/dep/level-one']);
    }

    public function testHttpCacheIsTheDeclaredCacheControl(): void
    {
        $this->assertSame('private, no-store, no-cache, must-revalidate', self::$nodes['app://self/no-http-cache-control']['httpCache']);
    }

    public function testImpactReachesEachEmbedderOnceByItsShortestPath(): void
    {
        [$code, $out] = self::qrGraph(['impact', 'page://self/dep/diamond-bottom?id=1', '--json', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $lines = self::jsonl($out);
        $this->assertSame(
            [
                'page://self/dep/diamond-bottom',
                'page://self/dep/diamond-left',
                'page://self/dep/diamond-right',
                'page://self/dep/tagged-parent',
                'page://self/dep/diamond-top',
            ],
            array_column($lines, 'uri'),
        );
        $this->assertSame(['target', 'embed', 'embed', 'embed', 'embed'], array_column($lines, 'via'));
        $this->assertSame([0, 1, 1, 1, 2], array_column($lines, 'depth'));
        // The top is reached through both sides of the diamond, and listed once.
        $this->assertSame('page://self/dep/diamond-left', $lines[4]['by']);
        $this->assertTrue($lines[4]['cached']);
    }

    public function testImpactOfAWriteMethodStartsFromWhatItInvalidates(): void
    {
        [, $out] = self::qrGraph(['impact', 'app://self/user', '--method=onPut', '--json', '--dir=' . self::DIR]);
        $lines = self::jsonl($out);
        $this->assertSame(
            [
                ['uri' => 'app://self/user', 'via' => 'refresh'],
                ['uri' => 'app://self/user/profile', 'via' => 'refresh'],
                ['uri' => 'app://self/user/friend', 'via' => 'purge'],
            ],
            array_map(static fn (array $line): array => ['uri' => $line['uri'], 'via' => $line['via']], $lines),
        );
        // No scanned resource serves app://self/user/friend.
        $this->assertNull($lines[2]['source']);
        $this->assertFalse($lines[2]['cached']);
    }

    public function testDotDrawsDeclaredEdges(): void
    {
        [$code, $out] = self::qrGraph(['dot', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith('digraph qr {', $out);
        $this->assertStringContainsString('"page://self/dep/level-one" -> "page://self/dep/level-two" [label="level-two"];', $out);
        $this->assertStringContainsString('"app://self/user" -> "app://self/user/friend" [label="onPut purge", style=dashed];', $out);
        $this->assertMatchesRegularExpression('#"page://self/dep/level-one" \[shape=box, [^\n]*>dep/level-one</TD>[^\n]*>page \(cacheable, ttl: never\)<#', $out);
        $this->assertMatchesRegularExpression('#"app://self/view" \[[^\n]*>app \(cacheable view, ttl: never\)<#', $out, 'a non-default #[Cacheable] type is named');
        $this->assertMatchesRegularExpression('#"app://self/counter" \[shape=plaintext, [^\n]*>counter</TD>[^\n]*>app<#', $out);
        $this->assertMatchesRegularExpression('#\{rank=min;[^\n]* "page://self/dep/level-one";#', $out, 'a page nothing embeds starts the leftmost column');
        $this->assertDoesNotMatchRegularExpression('#\{rank=min;[^\n]*"page://self/dep/level-two"#', $out, 'an embedded page stays where the layout puts it');
    }

    public function testDotCachedKeepsCachedResourcesAndWhatTheyEmbed(): void
    {
        [$code, $out, $err] = self::qrGraph(['dot', '--cached', '--dir=' . self::DIR]);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('"page://self/html/counter-cache" -> "app://self/counter" [label="counter"];', $out);
        $this->assertStringContainsString('"app://self/counter" [shape=plaintext', $out);
        $this->assertStringNotContainsString('app://self/user/friend', $out);

        [, $out] = self::qrGraph(['dot', '--cached', '--dir=tests/Fixture/DependsOnWarn/Resource']);
        $this->assertMatchesRegularExpression('#"app://self/method-cached" \[[^\n]*>app \(cacheable response\)<#', $out, 'a resource cached by #[CacheableResponse] on onGet() only is cached');
        $this->assertStringNotContainsString('method-cached-list', $out);

        [$code] = self::qrGraph(['dump', '--cached', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
    }

    public function testSnapshotNestsEveryImpactUnderWhatReachesIt(): void
    {
        [$code, $out, $err] = self::qrGraph(['snapshot', '--dir=tests/Fixture/DependsOn/Resource']);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString("app://self/author\n  app://self/archive (dependsOn)\n  app://self/entries (dependsOn)\n    page://self/index (embed, cacheable, ttl: never)\n\n", $out);
        $this->assertStringContainsString("app://self/writer onPost\n  app://self/article (purge, cacheable, ttl: never)\n    app://self/archive (dependsOn)\n", $out);
        $this->assertStringNotContainsString('.php:', $out);
        $this->assertDoesNotMatchRegularExpression('#^app://self/digest$#m', $out, 'a resource whose invalidation reaches nothing has no block');

        [, $out] = self::qrGraph(['snapshot', '--dir=' . self::DIR]);
        $this->assertStringContainsString("app://self/user onPut\n  app://self/user (refresh, cacheable, ttl: never)\n  app://self/user/friend (purge)\n", $out);
        $this->assertStringNotContainsString('app://self/entry onPatch', $out, 'a write that only refreshes its own resource has no block');
    }

    public function testImpactPrintsItsSnapshotBlock(): void
    {
        $dir = '--dir=tests/Fixture/DependsOn/Resource';
        [, $snapshot] = self::qrGraph(['snapshot', $dir]);
        [$code, $out, $err] = self::qrGraph(['impact', 'app://self/author', $dir]);
        $this->assertSame(0, $code, $err);
        $this->assertSame("app://self/author\n  app://self/archive (dependsOn)\n  app://self/entries (dependsOn)\n    page://self/index (embed, cacheable, ttl: never)\n", $out);
        $this->assertStringContainsString($out, $snapshot);

        [, $out] = self::qrGraph(['impact', 'app://self/writer', '--method=onPost', $dir]);
        $this->assertStringStartsWith("app://self/writer onPost\n  app://self/article (purge, cacheable, ttl: never)\n", $out);
        $this->assertStringContainsString($out, $snapshot);

        [, $out] = self::qrGraph(['impact', 'app://self/Digest', $dir]);
        $this->assertSame("app://self/digest\n", $out, 'a resource that reaches nothing is its name alone, as the graph spells it');

        [$code] = self::qrGraph(['dump', '--json', $dir]);
        $this->assertSame(2, $code);
    }

    public function testTokensFindOneLineDeclarationsAndBracedNamespaces(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Resource']);
        $this->assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        $meta = $lines[0];
        $this->assertSame(4, self::at($meta, 'meta', 'version'));
        $this->assertSame(['self' => 'BEAR\QueryRepositoryGraph\Fixture'], self::at($meta, 'meta', 'namespaces'));
        $this->assertSame('tests/Fixture/Resource', self::at($meta, 'meta', 'dir'));
        $this->assertSame(['nodes' => 2, 'edges' => 0, 'skipped' => 0], self::at($meta, 'meta', 'counts'));
        $this->assertSame(['page://self/braced', 'page://self/inline-attr'], array_column(array_slice($lines, 1), 'uri'));
        $this->assertSame([], self::jsonl($err), 'a declaration PHP can see is never dropped silently');
    }

    public function testUnknownOptionsAndMethodsAreErrors(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--bogus', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown option: --bogus', $err);

        [$code, , $err] = self::qrGraph(['dump', '--method=onPut', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Usage:', $err);

        [$code, , $err] = self::qrGraph(['dump', '--allow-unknown', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Usage:', $err);

        [$code, , $err] = self::qrGraph(['impact', 'app://self/user', '--method=onFoo', '--dir=' . self::DIR]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No write method onFoo on app://self/user', $err);

        [$code, , $err] = self::qrGraph(['impact', 'page://self/dep/level-one', '--method=onGet', '--dir=' . self::DIR]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No write method onGet on page://self/dep/level-one', $err);
    }

    public function testNoResourceClassesIsAnError(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Orphan/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame('skipped', array_key_first(self::jsonl($err)[0]));
        $this->assertStringContainsString('No resource classes found in tests/Fixture/Orphan/Resource', $this->lastError($err));
    }

    public function testUnknownTargetUriIsAnErrorUnlessAllowed(): void
    {
        [$code, $out, $err] = self::qrGraph(['impact', 'app://self/typo', '--dir=' . self::DIR]);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('No resource app://self/typo', $this->lastError($err));

        [$code, $out] = self::qrGraph(['impact', 'app://self/typo', '--allow-unknown', '--json', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $this->assertSame(['app://self/typo'], array_column(self::jsonl($out), 'uri'));
    }

    public function testUriMatchingFollowsTheAdapterEquivalence(): void
    {
        [$code, $out, $err] = self::qrGraph(['impact', 'app://self/galleryArticle', '--json', '--dir=tests/Fixture/Camel/Resource']);
        $this->assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        $this->assertSame(['app://self/gallery-article', 'page://self/camel-parent'], array_column($lines, 'uri'));
        $this->assertSame(['target', 'embed'], array_column($lines, 'via'));
        $this->assertSame('app://self/gallery-article', $lines[1]['by']);
    }

    public function testDeclaredTargetNamesTheNodeItReaches(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Camel/Resource']);
        $this->assertSame(0, $code, $err);
        $parent = self::jsonl($out)[2];
        $this->assertSame('page://self/camel-parent', $parent['uri']);
        $this->assertSame(
            [['rel' => 'article', 'uri' => 'app://self/gallery-article', 'template' => 'app://self/galleryArticle']],
            self::at($parent, 'methods', 'onGet', 'embed'),
        );

        [, $out] = self::qrGraph(['dot', '--dir=tests/Fixture/Camel/Resource']);
        $this->assertStringContainsString('"page://self/camel-parent" -> "app://self/gallery-article" [label="article"];', $out);
        $this->assertStringNotContainsString('galleryArticle', $out);
    }

    public function testDonutEmbedUsageAndImpactRole(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        $nodes = [];
        foreach (array_slice($lines, 1) as $line) {
            /** @var string $uri */
            $uri = $line['uri'];
            $nodes[$uri] = $line;
        }

        $embeds = self::at($nodes['page://self/donut-page'], 'methods', 'onGet', 'embed');
        $this->assertIsArray($embeds);
        $this->assertSame(['read' => 'referenced', 'ignored' => 'unreferenced'], array_column($embeds, 'usage', 'rel'));

        [$code, $out] = self::qrGraph(['impact', 'app://self/ignored-child', '--json', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(0, $code);
        $lines = self::jsonl($out);
        $this->assertSame(['app://self/ignored-child', 'page://self/donut-page'], array_column($lines, 'uri'));
        $this->assertSame('hole', $lines[1]['role']);

        [$code, $out] = self::qrGraph(['impact', 'app://self/read-child', '--json', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(0, $code);
        $lines = self::jsonl($out);
        $this->assertSame('material', $lines[1]['role']);
    }

    public function testDependsOnIsAnEdgeImpactFollowsOnlyFromAUriItselfPurged(): void
    {
        $dir = '--dir=tests/Fixture/DependsOn/Resource';
        [$code, $out, $err] = self::qrGraph(['dump', $dir]);
        $this->assertSame(0, $code, $err);
        $this->assertSame('', $err, 'a query in the snake_case of the required parameter, with a defaulted one left out, is not a mismatch');
        $nodes = [];
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('dump.json', $line);
        }

        foreach (self::jsonl($out) as $decoded) {
            /** @var string $uri */
            $uri = $decoded['uri'] ?? 'meta';
            $nodes[$uri] = $decoded;
        }

        $this->assertSame(14, self::at($nodes['meta'], 'meta', 'counts', 'edges'));
        $this->assertSame([
            ['uri' => 'app://self/article', 'template' => 'app://self/article?id={articleId}'],
            ['uri' => 'app://self/author', 'template' => 'app://self/author{?author_id}'],
        ], self::at($nodes['app://self/entries'], 'methods', 'onGet', 'dependsOn'));
        // Archive overrides Entries::onGet(): its own declaration first, then the overridden one's, each template kept
        $this->assertSame([
            ['uri' => 'app://self/author', 'template' => 'app://self/author?author_id={author_id}'],
            ['uri' => 'app://self/article', 'template' => 'app://self/article?id={articleId}'],
            ['uri' => 'app://self/author', 'template' => 'app://self/author{?author_id}'],
        ], self::at($nodes['app://self/archive'], 'methods', 'onGet', 'dependsOn'));
        $this->assertNull(self::at($nodes['app://self/entries'], 'methods', 'onPost'), 'only onGet() declarations are read');

        [$code, $out] = self::qrGraph(['impact', 'app://self/article', '--method=onPut', '--json', $dir]);
        $this->assertSame(0, $code);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('impact.json', $line);
        }

        // Outer depends on middle and Digest on index: neither URI is purged itself, so neither is reached.
        $this->assertSame([
            ['app://self/article', 'refresh', 'app://self/article', 1],
            ['app://self/archive', 'dependsOn', 'app://self/article', 2],
            ['app://self/entries', 'dependsOn', 'app://self/article', 2],
            ['app://self/middle', 'dependsOn', 'app://self/article', 2],
            ['page://self/index', 'embed', 'app://self/entries', 3],
        ], array_map(static fn (array $line): array => [$line['uri'], $line['via'], $line['by'], $line['depth']], self::jsonl($out)));

        // A #[Purge] on another resource: `by` names the purged URI from the second hop on.
        [, $out] = self::qrGraph(['impact', 'app://self/writer', '--method=onPost', '--json', $dir]);
        $this->assertSame([
            ['app://self/article', 'purge', 'app://self/writer', 1],
            ['app://self/archive', 'dependsOn', 'app://self/article', 2],
            ['app://self/entries', 'dependsOn', 'app://self/article', 2],
            ['app://self/middle', 'dependsOn', 'app://self/article', 2],
            ['page://self/index', 'embed', 'app://self/entries', 3],
        ], array_map(static fn (array $line): array => [$line['uri'], $line['via'], $line['by'], $line['depth']], self::jsonl($out)));

        [, $out] = self::qrGraph(['impact', 'app://self/middle', '--json', $dir]);
        $this->assertSame(['app://self/middle', 'app://self/outer'], array_column(self::jsonl($out), 'uri'), 'a URI asked about is purged itself');

        [, $out] = self::qrGraph(['dot', $dir]);
        $this->assertStringContainsString('"app://self/entries" -> "app://self/article" [label="dependsOn", style=dotted];', $out);
    }

    public function testDependsOnDeclarationsNoWriteInvalidatesAreWarnedOnStderr(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/DependsOnWarn/Resource']);
        $this->assertSame(0, $code, $err);
        $warnings = [];
        $messages = [];
        foreach (self::jsonl($err) as $line) {
            $this->assertIsString($line['message'] ?? null);
            $this->assertIsString($line['resource'] ?? null);
            $messages[$line['resource']] = $line['message'];
            unset($line['message'], $line['source']);
            $warnings[] = $line;
        }

        // Not warned: PairList, CamelKeys and SnakeKeys name the keys in another order or in snake_case, PurgedList
        // matches a #[Purge], PostCarriesList's target has an onPost() whose argument is the key in snake_case (PostCamel's, the reverse, is not found), and ResourceBound fails
        // its first GET instead.
        $this->assertSame([
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/alias', 'dependsOn' => 'app://self/camelPair{?leftId,rightId}'],
            ['warning' => 'depends-on-bound-parameter', 'resource' => 'app://self/bound', 'dependsOn' => 'app://self/article{?id}'],
            ['warning' => 'depends-on-default-body', 'resource' => 'app://self/default-body'],
            ['warning' => 'depends-on-default-body', 'resource' => 'app://self/default-code'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/donut-refresh-post-list', 'dependsOn' => 'app://self/donut-refresh-post{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/method-cached-list', 'dependsOn' => 'app://self/method-cached{?id}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/mismatch', 'dependsOn' => 'app://self/article{?articleId}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/mixed-keys', 'dependsOn' => 'app://self/camel-pair{?leftId,right_id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/no-write-list', 'dependsOn' => 'app://self/no-write{?id}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/origin-by-id', 'dependsOn' => 'app://self/origin{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/plain-refresh-list', 'dependsOn' => 'app://self/plain-refresh{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/post-camel-list', 'dependsOn' => 'app://self/post-camel?article_id={id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/post-only-list', 'dependsOn' => 'app://self/post-only{?id}'],
            ['warning' => 'depends-on-unknown-resource', 'resource' => 'app://self/typo', 'dependsOn' => 'app://self/artcle{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/unpurged', 'dependsOn' => 'app://self/plain{?id}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/with-page', 'dependsOn' => 'app://self/camel-pair{?leftId,rightId,page}'],
        ], $warnings);
        $this->assertSame('The tag is app://self/article?[articleId], but writes purge app://self/article?[id].', $messages['app://self/mismatch']);
        $this->assertSame('The tag is app://self/origin?[id], but writes purge app://self/origin?[articleId], app://self/origin?[].', $messages['app://self/origin-by-id']);
        $this->assertStringContainsString('make it #[Cacheable]', $messages['app://self/unpurged']);
        $this->assertStringContainsString('the writes that refresh it are onPost() whose arguments', $messages['app://self/post-only-list']);
        $this->assertStringContainsString('the writes that refresh it are onPost() whose arguments', $messages['app://self/donut-refresh-post-list'], 'its onPost() already has #[RefreshCache]');
        $this->assertStringContainsString('it is cached, but has no write method', $messages['app://self/no-write-list']);
        $this->assertStringContainsString('the writes that refresh it are onPost() whose arguments', $messages['app://self/plain-refresh-list'], 'uncached, refreshed only by a #[RefreshCache] onPost() that is skipped');
        $this->assertStringContainsString('only when the write method carries #[RefreshCache] or #[CacheableResponse]', $messages['app://self/method-cached-list'], '#[CacheableResponse] on onGet() caches it, and binds no write');
    }

    public function testUsageErrors(): void
    {
        [$code, , $err] = self::qrGraph(['impact', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Usage:', $err);

        [$code, , $err] = self::qrGraph(['dump', '--dir=no/such/dir']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('No such directory: no/such/dir', $err);
    }

    public function testObserveJudgesEachLoggedChildAgainstItsDeclaredUsage(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'hole'],
            ['page://self/donut-page', 'app://self/read-child', 'material'],
            ['page://self/donut-page', 'app://self/unknown-child', 'undeclared'],
        ], $this->verdicts(self::SESSIONS . '/expected.json'));
    }

    public function testObserveFlagsAReadChildThatCarriedNoTag(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'tagged-hole'],
            ['page://self/donut-page', 'app://self/read-child', 'untagged-material'],
        ], $this->verdicts(self::SESSIONS . '/contrary.json'));
    }

    public function testObserveTrustsTheRunTimeReasonOverTheSourceGuess(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'tagged-hole'],
            ['page://self/donut-page', 'app://self/read-child', 'hole'],
        ], $this->verdicts(self::SESSIONS . '/runtime.json'), 'the scan guesses read-child is referenced, but it had not run');
    }

    public function testObserveFallsBackToTheSourceGuessWithoutAReason(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'tagged-hole'],
            ['page://self/donut-page', 'app://self/read-child', 'untagged-material'],
        ], $this->verdicts(self::SESSIONS . '/reasonless.json'));
    }

    public function testObserveReadsADirectoryWithoutItsLatestCopy(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'mixed'],
            ['page://self/donut-page', 'app://self/read-child', 'mixed'],
            ['page://self/donut-page', 'app://self/unknown-child', 'undeclared'],
        ], $this->verdicts(self::SESSIONS . '/dir'));
    }

    public function testObserveNamesADeclaredChildAWrittenPageNoLongerHeld(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'unclassified'],
            ['page://self/donut-page', 'app://self/read-child', 'material'],
        ], $this->verdicts(self::SESSIONS . '/written.json'));
    }

    public function testObserveWarnsWhenTheLogPredatesDependencySkipped(): void
    {
        [, , $err] = self::qrGraph(['observe', self::SESSIONS . '/written.json', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertStringContainsString('No dependency_skipped in the sessions read', $err);

        [, , $err] = self::qrGraph(['observe', self::SESSIONS . '/expected.json', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertStringNotContainsString('No dependency_skipped', $err);
    }

    public function testObserveWarnsOnStderrForEachUntaggedMaterialLine(): void
    {
        [, , $err] = self::qrGraph(['observe', self::SESSIONS . '/contrary.json', '--dir=tests/Fixture/Usage/Resource']);
        $warnings = [];
        foreach (self::jsonl($err) as $line) {
            if (($line['warning'] ?? null) === 'untagged-material') {
                $warnings[] = $line;
            }
        }

        $this->assertCount(1, $warnings);
        $this->assertSame('page://self/donut-page', $warnings[0]['page']);
        $this->assertSame('app://self/read-child', $warnings[0]['child']);
        $this->assertSame('The page read this child, but the child carries no ETag or Surrogate-Key, so a change to the child never reaches the page; make the child cacheable or render it as a hole.', $warnings[0]['message']);
        $this->assertStringNotContainsString('hand-written', $warnings[0]['message'], 'a hand-written Surrogate-Key on the page is not a supported remedy');
    }

    public function testObserveFailOnExitsNonZeroOnlyWhenAListedVerdictIsPrinted(): void
    {
        [$code, $out] = self::qrGraph(['observe', self::SESSIONS . '/contrary.json', '--dir=tests/Fixture/Usage/Resource', '--fail-on=untagged-material']);
        $this->assertSame(3, $code);
        $this->assertNotSame('', $out, 'stdout is still written before the non-zero exit');

        [$code] = self::qrGraph(['observe', self::SESSIONS . '/contrary.json', '--dir=tests/Fixture/Usage/Resource', '--fail-on=hole,untagged-material']);
        $this->assertSame(3, $code, 'comma-separated verdicts are all checked');

        [$code] = self::qrGraph(['observe', self::SESSIONS . '/expected.json', '--dir=tests/Fixture/Usage/Resource', '--fail-on=untagged-material']);
        $this->assertSame(0, $code, 'no matching verdict leaves the exit code unchanged');
    }

    public function testObserveFailOnRejectsAnUnknownVerdict(): void
    {
        [$code, , $err] = self::qrGraph(['observe', self::SESSIONS . '/contrary.json', '--dir=tests/Fixture/Usage/Resource', '--fail-on=not-a-verdict']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown --fail-on verdict: not-a-verdict', $err);
        $this->assertStringContainsString('Usage:', $err);
    }

    public function testObserveWithoutASessionIsAnError(): void
    {
        [$code, , $err] = self::qrGraph(['observe', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Usage:', $err);

        [$code, , $err] = self::qrGraph(['observe', 'no/such/session.json', '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No such session file or directory: no/such/session.json', $err);
    }

    /** @return list<array{string, string, string}> page, child, verdict */
    private function verdicts(string $path): array
    {
        [$code, $out, $err] = self::qrGraph(['observe', $path, '--dir=tests/Fixture/Usage/Resource']);
        $this->assertSame(0, $code, $err);
        $verdicts = [];
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('observe.json', $line);
            /** @var array{page: string, child: string, verdict: string} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $verdicts[] = [$decoded['page'], $decoded['child'], $decoded['verdict']];
        }

        return $verdicts;
    }

    /** The message of the error line Cli writes last on stderr */
    private function lastError(string $err): string
    {
        $lines = self::jsonl($err);
        $error = self::at($lines[count($lines) - 1] ?? [], 'error');
        $this->assertIsString($error);

        return $error;
    }

    /**
     * The value at a key path of a decoded line, null where the path does not exist
     *
     * @param array<string, mixed> $line
     */
    private static function at(array $line, string ...$path): mixed
    {
        $value = $line;
        foreach ($path as $key) {
            if (! is_array($value) || ! isset($value[$key])) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private function assertSchema(string $schema, string $line): void
    {
        $data = json_decode($line);
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__) . '/docs/schemas/graph/' . $schema]);
        $this->assertTrue($validator->isValid(), $line . "\n" . json_encode($validator->getErrors()));
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private static function qrGraph(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Cli())($argv, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonl(string $text): array
    {
        $lines = [];
        foreach (explode("\n", trim($text)) as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $lines[] = $decoded;
        }

        return $lines;
    }
}
