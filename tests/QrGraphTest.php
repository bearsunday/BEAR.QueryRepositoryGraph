<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute\SearchPage;
use BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute\TopPage;
use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_filter;
use function array_intersect;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function bin2hex;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function fopen;
use function getcwd;
use function htmlspecialchars;
use function is_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function random_bytes;
use function rawurlencode;
use function realpath;
use function rewind;
use function rmdir;
use function shell_exec;
use function sort;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function touch;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LIBXML_NOERROR;
use const PHP_BINARY;
use const PHP_OS_FAMILY;

/**
 * The declared cache graph of the fake application, as `qr-graph` prints it
 *
 * @psalm-import-type Node from GraphBuilder
 */
class QrGraphTest extends TestCase
{
    private const DIR = 'tests/Fake/fake-app/src/Resource';
    private const SESSIONS = 'tests/Fixture/Sessions';
    private const WARNINGS = 'tests/Fixture/HtmlWarnings/Resource';
    private const LAYOUT = 'tests/Fixture/Layout/Resource';

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
        self::assertSame(7, self::at($meta, 'meta', 'version'));
        self::assertSame(['self' => 'FakeVendor\HelloWorld'], self::at($meta, 'meta', 'namespaces'));
        self::assertSame(self::DIR, self::at($meta, 'meta', 'dir'));
        self::assertSame(['nodes' => 115, 'edges' => 146, 'skipped' => 2], self::at($meta, 'meta', 'counts'));
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
        $this->assertSame('typo', $converter->key('Typo'), 'an argument without a scheme is matched as written, case-insensitively');
        $this->assertSame('app://self', $converter->key('App://Self'), 'so is one without a path');
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
        $this->assertSame(['expiry' => 'never', 'expirySecond' => 0, 'expiryAt' => '', 'type' => 'value'], $node['cacheable']);
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

    public function testDonutPageRefreshesItselfImplicitly(): void
    {
        $node = self::$nodes['page://self/html/blog-posting'];
        $this->assertTrue($node['cacheableResponse']);
        $this->assertSame([['uri' => 'page://self/html/blog-posting', 'implicit' => 'CacheableResponse']], self::at($node, 'methods', 'onDelete', 'refresh'));
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
        $this->assertStringContainsString('"app://self/user" -> "app://self/user/friend" [label="onPut purge"', $out);
        $this->assertMatchesRegularExpression('#"page://self/dep/level-one" \[shape=box, [^\n]*>dep/level-one</TD>[^\n]*>page \(cacheable, ttl: never\)<#', $out);
        $this->assertMatchesRegularExpression('#"app://self/view" \[[^\n]*>app \(cacheable view, ttl: never\)<#', $out, 'a non-default #[Cacheable] type is named');
        $this->assertMatchesRegularExpression('#"app://self/counter" \[shape=plaintext, [^\n]*>counter</TD>[^\n]*>app<#', $out);
        $this->assertMatchesRegularExpression('#\{rank=min;[^\n]* "page://self/dep/level-one";#', $out, 'a page nothing embeds starts the leftmost column');
        $this->assertDoesNotMatchRegularExpression('#\{rank=min;[^\n]*"page://self/dep/level-two"#', $out, 'an embedded page stays where the layout puts it');

        [, $out] = self::qrGraph(['dot', '--dir=tests/Fixture/CachedView/Resource']);
        $this->assertStringNotContainsString('app://self/plain', $out, 'a resource no arrow touches is left out');
        $this->assertStringContainsString('"app://self/settled" [', $out, 'unless it is cached, changed by its TTL alone');
    }

    public function testDotCachedKeepsCachedResourcesAndWhatInvalidatesThem(): void
    {
        [$code, $out, $err] = self::qrGraph(['dot', '--cached', '--dir=' . self::DIR]);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('"page://self/html/counter-cache" -> "app://self/counter" [label="counter"];', $out);
        $this->assertStringContainsString('"app://self/counter" [shape=plaintext', $out);
        $this->assertStringNotContainsString('app://self/user/friend', $out);

        [, $out] = self::qrGraph(['dot', '--cached', '--dir=tests/Fixture/DependsOnWarn/Resource']);
        $this->assertMatchesRegularExpression('#"app://self/method-cached" \[[^\n]*>app \(cacheable response\)<#', $out, 'a resource cached by #[CacheableResponse] on onGet() only is cached');
        $this->assertStringNotContainsString('method-cached-list', $out);

        [, $out] = self::qrGraph(['dot', '--cached', '--dir=tests/Fixture/CachedView/Resource']);
        $this->assertStringContainsString('"app://self/items" -> "app://self/item" [label="dependsOn"', $out, 'a #[DependsOn] target is drawn though nothing caches it');
        $this->assertStringContainsString('"app://self/editor" -> "app://self/item" [label="onPut purge"', $out, 'a write to it is drawn though the writer is not cached');
        $this->assertStringContainsString('"app://self/items" -> "app://self/remote-item" [label="dependsOn"', $out, 'two spellings of a URI no scanned resource serves are one node');
        $this->assertStringContainsString('"app://self/editor" -> "app://self/remote-item" [label="onPut purge"', $out);
        $this->assertStringNotContainsString('app://self/other', $out, 'a write that reaches nothing drawn is left out');

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

    public function testHtmlLightsWhatImpactReachesAndWhatReachesIt(): void
    {
        $dir = 'tests/Fixture/DependsOn/Resource';
        $nodes = (new GraphBuilder(new UriConverter(), (string) getcwd()))->build((new ResourceScanner())->scan($dir))['nodes'];
        $html = (new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo "<?xml version=\"1.0\"?>\n<svg></svg>\n";']))($nodes, []);
        $this->assertStringContainsString("<main>\n<svg></svg>", $html, 'the SVG goes in without its XML prolog');
        $glow = self::glow($html);

        [, $out] = self::qrGraph(['impact', 'app://self/article', '--json', '--dir=' . $dir]);
        $reached = array_column(array_slice(self::jsonl($out), 1), 'uri');
        sort($reached);
        $this->assertSame($reached, self::at($glow, 'app://self/article', 'reaches', 'nodes'));
        $this->assertContains(['app://self/entries', 'page://self/index'], (array) self::at($glow, 'app://self/article', 'reaches', 'edges'));
        $this->assertSame(['app://self/writer'], self::at($glow, 'app://self/article', 'reachedBy', 'nodes'));
        $this->assertSame(['app://self/archive', 'app://self/article', 'app://self/entries', 'app://self/middle', 'page://self/index'], self::at($glow, 'app://self/writer', 'reaches', 'nodes'), 'a write method reaches what it purges');
        $this->assertSame(
            [['app://self/article', 'app://self/entries'], ['app://self/author', 'app://self/entries'], ['app://self/entries', 'page://self/index'], ['app://self/writer', 'app://self/article']],
            self::at($glow, 'page://self/index', 'reachedBy', 'edges'),
            'reachedBy lights each start\'s path to it',
        );
    }

    public function testHtmlTellsAWriteFromWhatAResourceShows(): void
    {
        $nodes = (new GraphBuilder(new UriConverter(), (string) getcwd()))->build((new ResourceScanner())->scan('tests/Fixture/Html/Resource'))['nodes'];
        $html = (new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo "<svg></svg>";']))($nodes, []);
        $glow = self::glow($html);

        $this->assertSame(['app://self/admin', 'app://self/banner'], self::at($glow, 'page://self/front', 'reachedBy', 'nodes'));
        $this->assertSame(['app://self/admin'], self::at($glow, 'page://self/front', 'writers'), 'banner also reaches front as an embed, so it is not a writer only');
        $this->assertSame([['app://self/admin', 'app://self/banner'], ['app://self/banner', 'page://self/front']], self::at($glow, 'page://self/front', 'reachedBy', 'writes'), 'the purge and the refresh on the way are writes');
    }

    public function testHtmlRendersWithGraphviz(): void
    {
        if ((string) shell_exec('command -v dot') === '') {
            $this->markTestSkipped('Graphviz dot is not installed');
        }

        [$code, $out, $err] = self::qrGraph(['html', '--cached', '--dir=tests/Fixture/DependsOn/Resource']);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('<title>app://self/article</title>', $out);
        $this->assertSame(['app://self/writer'], self::at(self::glow($out), 'app://self/article', 'reachedBy', 'nodes'));
    }

    public function testHtmlDrawsOnlyTheCachedPart(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        $this->assertSame(1, substr_count($html, '<svg'), 'one picture');
        $this->assertStringContainsString('<svg>' . htmlspecialchars((new DotRenderer(new NodeDeclarations()))->cached(self::nodes(self::WARNINGS))) . '</svg>', $html, 'the cached part, as dot --cached draws it');
        $this->assertSame(
            ['app://self/editor', 'app://self/liker', 'app://self/liker', 'app://self/tally', 'app://ads/banner', 'app://self/summary', 'app://self/digest'],
            self::selectable($html),
            'only a URI the picture draws selects it',
        );
    }

    public function testHtmlListsEachTargetWhoseInvalidationReachesNoCache(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        // Editor's onPut, and liker's refresh of page://self/, reach the cached index; tally's own refresh reaches itself.
        $this->assertSame([
            'app://self/counter purge by app://self/editor onDelete',
            'app://self/counter purge by app://self/liker onPost',
            'app://self/removed purge by app://self/liker onPost',
            'app://self/counter purge by app://self/tally onPut',
        ], self::listed($html, 'invalidates-no-cache'), 'ad-writer purges another application: not listed here');
        $this->assertSame([
            'tests/Fixture/HtmlWarnings/Resource/App/Editor.php:17',
            'tests/Fixture/HtmlWarnings/Resource/App/Liker.php:14',
            'tests/Fixture/HtmlWarnings/Resource/App/Liker.php:14',
            'tests/Fixture/HtmlWarnings/Resource/App/Tally.php:18',
        ], self::sources($html, 'invalidates-no-cache'));
    }

    public function testHtmlListsADependsOnWhoseTagsAreStoredNowhereAsInformation(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        // Feed is embedded by the cached index and summary is cached; digest is only depended on by summary.
        $this->assertSame(['app://self/digest', 'app://self/stats'], self::listed($html, 'depends-on-not-cached'));
        $this->assertSame(
            ['tests/Fixture/HtmlWarnings/Resource/App/Digest.php:11', 'tests/Fixture/HtmlWarnings/Resource/App/Stats.php:12'],
            self::sources($html, 'depends-on-not-cached'),
        );
        $page = self::xpath($html);
        $this->assertSame([], self::elements($page, '//details[@data-kind="warnings"]//*[@data-kind="depends-on-not-cached"]'), 'not a warning');
        $this->assertSame('Depends on, but not cached (2)', self::text($page, '//details[@data-kind="not-drawn"]/section[1][@data-kind="depends-on-not-cached"]/h3'));
    }

    public function testHtmlListsTheUrisNoResourceServes(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        $this->assertSame([
            'app://self/removed purge',
            'app://ads/banner (another application) embed',
            'app://ads/campaign (another application) purge',
        ], self::listed($html, 'no-such-resource'), 'this application before another');
        $this->assertSame([
            'tests/Fixture/HtmlWarnings/Resource/App/Liker.php:14',
            'tests/Fixture/HtmlWarnings/Resource/App/Feed.php:13',
            'tests/Fixture/HtmlWarnings/Resource/App/AdWriter.php:11',
        ], self::sources($html, 'no-such-resource'));
    }

    public function testHtmlListsTheDependsOnWarnings(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        $this->assertSame([
            'app://self/stats depends-on-never-invalidated app://self/plain',
            'app://self/summary depends-on-never-invalidated app://self/digest',
        ], self::listed($html, 'depends-on'));
        $this->assertSame(
            ['tests/Fixture/HtmlWarnings/Resource/App/Stats.php:12', 'tests/Fixture/HtmlWarnings/Resource/App/Summary.php:13'],
            self::sources($html, 'depends-on'),
        );
    }

    public function testHtmlCountsWhatItDoesNotDraw(): void
    {
        $html = self::warningsPage(self::WARNINGS);
        $this->assertSame(['app://self/archive-list', 'app://self/blog/index', 'page://self/archive'], self::listed($html, 'embeds-uncached'), 'archive also embeds stats, which is listed above');
        $this->assertSame(['app://self/plain'], self::listed($html, 'no-declarations'), 'counter is listed above');
        $this->assertSame(['app://self/ad-writer'], self::listed($html, 'writes-only'));
        $page = self::xpath($html);
        $this->assertSame('3', self::text($page, '//*[@data-kind="embeds-uncached"]//*[@class="count"]'));
        $this->assertSame('Not drawn', self::text($page, '//details[@data-kind="not-drawn"][not(@open)]/summary'), 'collapsed');
    }

    public function testHtmlAccountsForEveryScannedResource(): void
    {
        foreach ([self::WARNINGS, 'tests/Fixture/DependsOnWarn/Resource'] as $dir) {
            $nodes = self::nodes($dir);
            $page = self::xpath(self::warningsPage($dir));
            $drawn = array_keys((new DotRenderer(new NodeDeclarations()))->cachedUris($nodes));
            $warned = self::texts($page, '//section[@data-kind][not(ancestor::*[@data-kind="not-drawn"])]//*[contains(@class, "uri")]');
            // Listed drawn or not, warned or not; every other list in Not drawn names only what is neither.
            $notDrawn = self::texts($page, '//*[@data-kind="not-drawn"]/section[@data-kind!="depends-on-not-cached"]//*[contains(@class, "uri")]');
            $dependents = self::texts($page, '//*[@data-kind="depends-on-not-cached"]//*[contains(@class, "uri")]');
            $scanned = array_column($nodes, 'uri');
            $accounted = array_values(array_unique(array_intersect([...$drawn, ...$warned, ...$notDrawn, ...$dependents], $scanned)));
            sort($accounted);
            $this->assertSame($scanned, $accounted, $dir);
            $this->assertSame([], array_values(array_intersect($notDrawn, [...$drawn, ...$warned, ...$dependents])), $dir);
        }
    }

    public function testHtmlExplainsAPictureWithNothingCached(): void
    {
        $declarations = new NodeDeclarations();
        $nodes = array_values(array_filter(self::nodes(self::WARNINGS), static fn (array $node): bool => ! $declarations->isCached($node)));
        $html = (new HtmlRenderer($declarations, [PHP_BINARY, '-r', 'exit(1);']))($nodes, []);
        $this->assertSame(0, substr_count($html, '<svg'));
        $this->assertStringContainsString('Nothing is cached', $html);
    }

    public function testHtmlWarningsStartCollapsed(): void
    {
        $page = self::xpath(self::warningsPage(self::WARNINGS));
        $this->assertSame('Warnings (9)', self::text($page, '//*[@id="warnings"]/details[not(@open)]/summary'), 'the items the three groups list');
    }

    public function testHtmlLinksSourcesOnlyForAnEditor(): void
    {
        $plain = self::warningsPage(self::WARNINGS);
        $this->assertStringNotContainsString('phpstorm://', $plain);
        $this->assertStringNotContainsString((string) getcwd(), $plain, 'no absolute path of the generating machine');
        $this->assertStringNotContainsString(rawurlencode((string) getcwd()), $plain);
        $this->assertSame('span', self::elements(self::xpath($plain), '//*[@data-kind="invalidates-no-cache"]//*[@class="source"]')[0]->tagName);

        $page = self::xpath(self::warningsPage(self::WARNINGS, 'phpstorm'));
        $source = self::elements($page, '//*[@data-kind="invalidates-no-cache"]//*[@class="source"]')[0];
        $this->assertSame('tests/Fixture/HtmlWarnings/Resource/App/Editor.php:17', $source->textContent);
        $this->assertSame('phpstorm://open?file=' . rawurlencode((string) realpath('tests/Fixture/HtmlWarnings/Resource/App/Editor.php')) . '&line=17', $source->getAttribute('href'));
    }

    public function testHtmlRejectsAnUnknownEditor(): void
    {
        foreach (['--editor=vscode', '--editor', '--editor='] as $option) {
            [$code, , $err] = self::qrGraph(['html', $option, '--dir=' . self::WARNINGS]);
            $this->assertSame(2, $code, $option);
            $this->assertStringContainsString('accepted: phpstorm', $err, $option);
        }

        [$code] = self::qrGraph(['dump', '--editor=phpstorm', '--dir=' . self::WARNINGS]);
        $this->assertSame(2, $code, 'only html takes an editor');
    }

    public function testHtmlLinksSourcesWithTheEditorOption(): void
    {
        if ((string) shell_exec('command -v dot') === '') {
            $this->markTestSkipped('Graphviz dot is not installed');
        }

        [$code, $out, $err] = self::qrGraph(['html', '--editor=phpstorm', '--dir=' . self::WARNINGS]);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('phpstorm://open?file=' . rawurlencode((string) realpath('tests/Fixture/HtmlWarnings/Resource/App/Editor.php')), $out);
        [, $out] = self::qrGraph(['html', '--dir=' . self::WARNINGS]);
        $this->assertStringNotContainsString('phpstorm://', $out);
    }

    public function testAUriEndingInSlashIsRefused(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/IndexSlash/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame(
            'A URI ending in "/" is no longer accepted since 0.2.0: "app://self/blog/" in an #[Embed], #[DependsOn], #[Refresh] or #[Purge] of page://self/index.'
            . ' Please write "app://self/blog/index" instead: BEAR.Resource serves both as the index, but the cache tags the two spellings apart.',
            $this->lastError($err),
        );
        $this->assertStringContainsString('TrailingSlashUriException', $err);

        [$code, , $err] = self::qrGraph(['impact', 'page://self/?id=1', '--dir=' . self::DIR]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('"page://self/?id=1". Please write "page://self/index?id=1" instead', $this->lastError($err));
    }

    public function testHtmlEscapesASourcePath(): void
    {
        // Windows forbids `"`, `<` and `>` in a file name.
        $names = PHP_OS_FAMILY === 'Windows' ? ["we'ird & #%.php"] : ["we'ird & #%.php", 'we"ird <&> #%.php'];
        foreach ($names as $name) {
            $dir = sys_get_temp_dir() . '/qr-graph-' . bin2hex(random_bytes(4));
            mkdir($dir);
            $file = $dir . '/' . $name;
            touch($file);
            try {
                $path = realpath($file);
                $this->assertIsString($path, $name);
                // GraphBuilder writes every source with forward slashes.
                $source = str_replace('\\', '/', $file) . ':7';
                $nodes = [
                    ['uri' => 'app://self/odd', 'source' => $source],
                    ['uri' => 'app://self/gone', 'source' => 'no/such/file.php:3'],
                ];
                $html = (new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'exit(1);']))($nodes, [], 'phpstorm');
                $this->assertStringNotContainsString($name, $html, 'escaped');
                $sources = self::elements(self::xpath($html), '//*[@class="source"]');
                $this->assertSame([$source, 'no/such/file.php:3'], array_map(static fn (DOMElement $element): string => $element->textContent, $sources), $name);
                $this->assertSame('phpstorm://open?file=' . rawurlencode($path) . '&line=7', $sources[0]->getAttribute('href'), $name);
                $this->assertSame(['a', 'span'], [$sources[0]->tagName, $sources[1]->tagName], 'a file that does not resolve stays text');
            } finally {
                unlink($file);
                rmdir($dir);
            }
        }
    }

    public function testHtmlKeepsAMachinePathOutWithoutAnEditor(): void
    {
        // A resource file outside the working directory keeps its absolute path in the graph.
        $nodes = [['uri' => 'app://self/external', 'source' => '/opt/shared/External.php:7']];
        $html = (new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'exit(1);']))($nodes, []);
        $this->assertSame(['External.php:7'], self::texts(self::xpath($html), '//*[@class="source"]'));
        $this->assertStringNotContainsString('/opt/shared', $html);
    }

    public function testHtmlSaysSoWhenNothingIsWarned(): void
    {
        $html = self::warningsPage('tests/Fixture/Html/Resource');
        $this->assertStringContainsString('No warnings', $html);
        $this->assertSame(0, substr_count($html, 'data-kind='));
        $this->assertSame(0, substr_count($html, '<details'), 'nothing to collapse, everything is drawn');
    }

    public function testHtmlPlacesNotDrawnAfterTheWarnings(): void
    {
        // The warnings fixture has warnings; tests/Fixture/Resource has none, and an uncached page left out.
        foreach ([self::WARNINGS => 'details[@data-kind="warnings"]', 'tests/Fixture/Resource' => 'p[.="No warnings."]'] as $dir => $warnings) {
            $page = self::xpath(self::warningsPage($dir));
            $this->assertSame('Not drawn', self::text($page, sprintf('//*[@id="warnings"]/%s/following-sibling::details[@data-kind="not-drawn"]/summary', $warnings)), $dir);
            $this->assertSame([], self::elements($page, '//details[@data-kind="warnings"]//details'), $dir);
        }
    }

    public function testHtmlIsTheCachedPictureWithOrWithoutCached(): void
    {
        if ((string) shell_exec('command -v dot') === '') {
            $this->markTestSkipped('Graphviz dot is not installed');
        }

        [$code, $out, $err] = self::qrGraph(['html', '--dir=' . self::WARNINGS]);
        $this->assertSame(0, $code, $err);
        [, $cached] = self::qrGraph(['html', '--cached', '--dir=' . self::WARNINGS]);
        $this->assertSame($out, $cached);
        $this->assertSame(1, substr_count($out, '<svg'));
        $this->assertStringContainsString('<title>app://self/editor</title>', $out);
        $this->assertStringContainsString('<title>app://self/liker</title>', $out, 'liker refreshes page://self/, the cached index');
        $this->assertStringNotContainsString('<title>app://self/ad-writer</title>', $out);
        $this->assertSame(self::listed(self::warningsPage(self::WARNINGS), 'depends-on'), self::listed($out, 'depends-on'), 'the CLI passes the #[DependsOn] warnings');
    }

    public function testHtmlFailsWhenDotCannotDraw(): void
    {
        foreach ([['/no/such/dot'], [PHP_BINARY, '-r', 'stream_get_contents(STDIN); fwrite(STDERR, "syntax error"); exit(1);'], [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo "no picture";']] as $dot) {
            try {
                (new HtmlRenderer(new NodeDeclarations(), $dot))(self::nodes('tests/Fixture/Html/Resource'), []);
                $this->fail('no exception');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($dot[0], $e->getMessage());
            }
        }

        [$code] = self::qrGraph(['html', 'app://self/article', '--dir=tests/Fixture/DependsOn/Resource']);
        $this->assertSame(2, $code);
    }

    public function testDumpRecordsTheLayoutWithItsPartsResolved(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=' . self::LAYOUT]);
        $this->assertSame(0, $code, $err);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('dump.json', $line);
        }

        $nodes = [];
        foreach (array_slice(self::jsonl($out), 1) as $node) {
            /** @var string $uri */
            $uri = $node['uri'];
            $nodes[$uri] = $node;
        }

        $this->assertSame([
            'name' => 'SearchPage',
            'class' => SearchPage::class,
            'source' => 'tests/Fixture/Layout/Attribute/SearchPage.php:9',
            'embed' => [
                ['rel' => 'footer', 'uri' => 'page://self/esi/footer'],
                ['rel' => 'search', 'uri' => 'page://self/esi/search', 'template' => 'page://self/esi/search{?q}'],
            ],
        ], $nodes['page://self/search']['layout'] ?? null, 'a relative part resolves against the page');
        $this->assertArrayNotHasKey('layout', $nodes['page://self/esi/footer']);
    }

    public function testALayoutWithoutAnEmbedOfItsOwnIsAnError(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/LayoutWithoutEmbed/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame(
            'BEAR\QueryRepositoryGraph\Fixture\LayoutWithoutEmbed\Attribute\Bare, the layout of BEAR\QueryRepositoryGraph\Fixture\LayoutWithoutEmbed\Resource\Page\Index, declares no EMBED of its own',
            $this->lastError($err),
        );
    }

    public function testTwoLayoutsOnOneClassAreAnError(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/LayoutTwice/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame('BEAR\QueryRepositoryGraph\Fixture\LayoutTwice\Resource\Page\Home has more than one layout', $this->lastError($err));
    }

    public function testARelBothTheLayoutAndAnOnGetEmbedDeclareIsAnError(): void
    {
        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/LayoutClash/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame('BEAR\QueryRepositoryGraph\Fixture\LayoutClash\Resource\Page\Home declares rel "header" in both its layout and a method #[Embed]', $this->lastError($err));
    }

    public function testLayoutRowsKeepTheirOwnIds(): void
    {
        [, $out] = self::qrGraph(['dot', '--dir=tests/Fixture/LayoutEdge/Resource']);
        preg_match_all('/ID="(esi-[^"]+)"[^>]*>([^<]*)<\/TD>/', $out, $rows);
        $this->assertSame(['esi-part-index', 'esi-part-a-b', 'esi-part-a-b-2'], $rows[1], 'part/a-b and part/a/b read the same as an id: the second takes a suffix');
        $this->assertSame(['index', 'a-b', 'a/b'], $rows[2], 'the rows leave out the directory they share');
        $this->assertStringContainsString('COLOR="#999999">page&nbsp;&nbsp;part/</FONT>', $out);

        $this->assertSame(
            ['page://self/part/a-b layout', 'page://self/part/a/b layout', 'page://self/part/index layout'],
            self::listed(self::warningsPage('tests/Fixture/LayoutEdge/Resource'), 'no-such-resource'),
            'a part no scanned resource serves is listed with the layout that names it',
        );
    }

    public function testHtmlDrawsLayoutPartsOfMixedSchemes(): void
    {
        $dir = '--dir=tests/Fixture/LayoutMixed/Resource';
        [, $out] = self::qrGraph(['dot', $dir]);
        preg_match_all('/ID="esi-[^"]+"[^>]*>([^<]*)<\/TD>/', $out, $rows);
        $this->assertSame(['page://self/layout/nav', 'app://self/own'], $rows[1], 'rows of more than one scheme show their whole URI');

        [, $out] = self::qrGraph(['impact', 'page://self/layout/nav', '--allow-unknown', $dir]);
        $this->assertSame("page://self/layout/nav\n  page://self/home (embed, cacheable, ttl: never)\n", $out, 'a class without onGet() embeds nothing through its layout');

        if ((string) shell_exec('command -v dot') === '') {
            $this->markTestSkipped('Graphviz dot is not installed');
        }

        [$code, , $err] = self::qrGraph(['html', $dir]);
        $this->assertSame(0, $code, $err);
    }

    public function testALayoutPartReachesEveryPageCarryingTheLayout(): void
    {
        $dir = '--dir=' . self::LAYOUT;
        [, $out] = self::qrGraph(['impact', 'app://self/footer-editor', '--method=onPut', $dir]);
        $this->assertSame("app://self/footer-editor onPut\n  page://self/esi/footer (purge)\n    page://self/article (embed, cacheable response)\n    page://self/search (embed, cacheable response)\n", $out);

        [, $out] = self::qrGraph(['impact', 'app://self/ranking-editor', '--method=onPut', $dir]);
        $this->assertStringContainsString("  app://self/ranking (purge)\n    page://self/esi/ranking (embed)\n      page://self/index (embed, cacheable, ttl: never)\n", $out, 'through the part\'s own child');

        [, $out] = self::qrGraph(['impact', 'page://self/esi/header', $dir]);
        $this->assertSame("page://self/esi/header\n  page://self/article (embed, cacheable response)\n  page://self/index (embed, cacheable, ttl: never)\n", $out, 'the search layout has no header part: search embeds its own');
    }

    public function testHtmlWarnsNoWriteToALayoutPartAsReachingNoCache(): void
    {
        // Neither the purged footer nor the ranking under the ranking part is cached: only the pages carrying them are.
        $this->assertSame([], self::listed(self::warningsPage(self::LAYOUT), 'invalidates-no-cache'));
    }

    public function testDotDrawsLayoutsAsOneTableInsteadOfArrows(): void
    {
        foreach ([['dot'], ['dot', '--cached']] as $command) {
            [$code, $out, $err] = self::qrGraph([...$command, '--dir=' . self::LAYOUT]);
            $this->assertSame(0, $code, $err);
            preg_match_all('/ID="cell-([^"]+)"/', $out, $cells);
            $this->assertSame(
                ['esi-header-ContentPage', 'esi-header-TopPage', 'esi-footer-ContentPage', 'esi-footer-SearchPage', 'esi-ranking-TopPage', 'esi-search-SearchPage'],
                $cells[1],
                'a ● where the layout has the part; a row per part in declaration order',
            );
            $this->assertStringContainsString('COLOR="#999999">page&nbsp;&nbsp;esi/</FONT>', $out, 'the scheme and directory the rows share go to the corner');
            $this->assertMatchesRegularExpression('#ID="esi-esi-footer" TOOLTIP="page://self/esi/footer"[^>]*>footer</TD>#', $out);
            $this->assertDoesNotMatchRegularExpression('#(-> layouts|layouts:|layouts ->)#', $out, 'no arrow touches the table');
            $this->assertStringContainsString('"app://self/footer-editor" -> "page://self/esi/footer" [label="onPut purge"', $out, 'a part with arrows of its own is also a node');
            $this->assertStringContainsString('"page://self/esi/ranking" -> "app://self/ranking" [label="ranking"', $out);
            $this->assertStringNotContainsString('"page://self/esi/search" [', $out, 'a part with no arrow is its row only');
            $this->assertStringContainsString('>page (cacheable response, #[ContentPage])<', $out);
            $this->assertDoesNotMatchRegularExpression('#"page://self/(article|index|search)" -> "page://self/esi/#', $out, 'no arrow from a page to a part');
            $this->assertStringContainsString('{rank=min; layouts;', $out, 'the table goes in the leftmost rank');
        }
    }

    public function testHtmlLightsTheLayoutTableCellByCell(): void
    {
        $html = self::warningsPage(self::LAYOUT);
        $glow = self::glow($html);
        $this->assertSame(['page://self/article', 'page://self/search'], self::at($glow, 'page://self/esi/footer', 'reaches', 'nodes'));
        $this->assertSame(
            ['app://self/article', 'app://self/footer-editor', 'page://self/esi/footer', 'page://self/esi/header'],
            self::at($glow, 'page://self/article', 'reachedBy', 'nodes'),
            'the parts its layout embeds reach the page',
        );
        $this->assertContains(['page://self/esi/footer', 'page://self/article'], (array) self::at($glow, 'page://self/article', 'reachedBy', 'edges'));
        $this->assertSame(['app://self/footer-editor'], self::at($glow, 'page://self/article', 'writers'));

        if (preg_match('#<script type="application/json" id="qr-graph-layouts">(.*?)</script>#s', $html, $match) !== 1) {
            $this->fail('no layout JSON');
        }

        /** @var array{rows: list<mixed>, cells: list<mixed>, heads: list<mixed>} $layouts */
        $layouts = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains(['id' => 'esi-esi-ranking', 'uri' => 'page://self/esi/ranking'], $layouts['rows']);
        $this->assertContains(['id' => 'cell-esi-footer-SearchPage', 'uri' => 'page://self/esi/footer', 'pages' => ['page://self/search']], $layouts['cells']);
        $this->assertContains(['id' => 'layout-TopPage', 'key' => 'layout:' . TopPage::class, 'label' => '#[TopPage]', 'pages' => ['page://self/index']], $layouts['heads'], 'a head selects its layout by key, and the status line names it by its label');

        $plain = self::warningsPage(self::WARNINGS);
        $this->assertStringNotContainsString('qr-graph-layouts', $plain, 'no table, no table data');
        $this->assertStringNotContainsString('{{', $plain);
    }

    public function testHtmlSelectsALayoutAsAResourceThatDependsOnItsParts(): void
    {
        $glow = self::glow(self::warningsPage(self::LAYOUT));
        $topPage = 'layout:' . TopPage::class;
        $this->assertSame(['page://self/index'], self::at($glow, $topPage, 'reaches', 'nodes'), 'it reaches the pages carrying it');
        $this->assertSame(
            ['app://self/ranking', 'app://self/ranking-editor', 'page://self/esi/header', 'page://self/esi/ranking'],
            self::at($glow, $topPage, 'reachedBy', 'nodes'),
            'its parts reach it, and what reaches them',
        );
        $this->assertContains(['page://self/esi/ranking', 'page://self/index'], (array) self::at($glow, $topPage, 'reachedBy', 'edges'), 'through the ● of each part');
        $this->assertContains(['app://self/ranking-editor', 'app://self/ranking'], (array) self::at($glow, $topPage, 'reachedBy', 'writes'));
        $this->assertSame(['app://self/ranking-editor'], self::at($glow, $topPage, 'writers'));

        $searchPage = 'layout:' . SearchPage::class;
        $this->assertNotContains('page://self/esi/header', (array) self::at($glow, $searchPage, 'reachedBy', 'nodes'), 'the search layout has no header part');
    }

    public function testALayoutsWritersReachItThroughAWriteOnly(): void
    {
        $cacheable = ['expiry' => 'never', 'expirySecond' => 0, 'expiryAt' => '', 'type' => 'value'];
        $layout = ['name' => 'L', 'class' => 'L', 'source' => 'l.php:1', 'embed' => [['rel' => 'a', 'uri' => 'page://self/a'], ['rel' => 'b', 'uri' => 'page://self/b']]];
        $nodes = [
            ['uri' => 'page://self/p', 'source' => 'p.php:1', 'cacheable' => $cacheable, 'layout' => $layout],
            ['uri' => 'page://self/a', 'source' => 'a.php:1', 'methods' => ['onGet' => ['source' => 'a.php:2', 'dependsOn' => [['uri' => 'app://self/w']]]]],
            ['uri' => 'page://self/b', 'source' => 'b.php:1'],
            ['uri' => 'app://self/w', 'source' => 'w.php:1', 'methods' => ['onPost' => ['source' => 'w.php:2', 'refresh' => [['uri' => 'page://self/b']]]]],
        ];
        $glow = self::glow((new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo "<svg></svg>";']))($nodes, []));

        $this->assertSame(['app://self/w'], self::at($glow, 'page://self/b', 'writers'), 'w reaches part b through its write only');
        $this->assertSame([], self::at($glow, 'layout:L', 'writers'), 'w also reaches part a, so the layout, as a plain origin');
    }

    public function testTheTableTakesItsOwnHeightAboveThePicture(): void
    {
        $layout = ['name' => 'L', 'class' => 'L', 'source' => 'l.php:1', 'embed' => [['rel' => 'a', 'uri' => 'page://self/a']]];
        $nodes = [['uri' => 'page://self/p', 'source' => 'p.php:1', 'cacheable' => ['expiry' => 'never', 'expirySecond' => 0, 'expiryAt' => '', 'type' => 'value'], 'layout' => $layout]];
        $html = (new HtmlRenderer(new NodeDeclarations(), [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo \'<svg width="100pt" height="50pt"><g/></svg>\';']))($nodes, []);

        $this->assertStringContainsString('<svg width="100pt" height="124pt" viewBox="0 0 100 124"', $html, 'both heights and the gap between them');
        $this->assertStringContainsString('<svg x="0" y="0" width="100" height="50"><g/></svg><svg x="0" y="74" width="100" height="50"><g/></svg>', $html, 'the picture starts below the table, in the outer viewBox units');
    }

    public function testHtmlDrawsTheTableAboveThePictureWithTheIdsItLightsBy(): void
    {
        if ((string) shell_exec('command -v dot') === '') {
            $this->markTestSkipped('Graphviz dot is not installed');
        }

        [$code, $out, $err] = self::qrGraph(['html', '--dir=' . self::LAYOUT]);
        $this->assertSame(0, $code, $err);
        $page = self::xpath($out);
        $svgs = self::elements($page, '//main/*[local-name()="svg"]/*[local-name()="svg"]');
        $this->assertCount(2, $svgs, 'one SVG holding the table and the picture');
        $this->assertSame('0', $svgs[0]->getAttribute('y'), 'the table first, at the top');
        $this->assertGreaterThan(0, (float) $svgs[1]->getAttribute('y'));
        foreach (['a_esi-esi-footer', 'a_cell-esi-footer-ContentPage', 'a_layout-ContentPage'] as $id) {
            $this->assertCount(1, self::elements($page, sprintf('.//*[@id="%s"]', $id), $svgs[0]), $id);
        }

        $this->assertSame('page://self/esi/footer', self::text($page, './/*[@class="node"][*[local-name()="title"]="page://self/esi/footer"]/*[local-name()="title"]', $svgs[1]), 'the part with a write to it is a node of the picture too');
    }

    public function testTokensFindOneLineDeclarationsAndBracedNamespaces(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Resource']);
        $this->assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        $meta = $lines[0];
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

    public function testDumpRecordsTheAutoloadRelativeToTheWorkingDirectory(): void
    {
        foreach ([[getcwd() . '/vendor/autoload.php', 'vendor/autoload.php'], ['/elsewhere/vendor/autoload.php', null]] as [$autoload, $recorded]) {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $this->assertIsResource($out);
            $this->assertIsResource($err);
            $this->assertSame(0, (new Cli($autoload))(['dump', '--dir=tests/Fixture/Resource'], $out, $err));
            rewind($out);
            $this->assertSame($recorded, self::at(self::jsonl((string) stream_get_contents($out))[0], 'meta', 'autoload'), 'never a path outside the working directory');
        }
    }

    public function testOneApplicationPerGraph(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/TwoApps/Resource']);
        $this->assertSame(0, $code, $err);
        $this->assertSame(['page://self/index'], array_column(array_slice(self::jsonl($out), 1), 'uri'), 'a resource class directly in the Resource namespace names no scheme: no node');

        [$code, , $err] = self::qrGraph(['dump', '--dir=tests/Fixture/TwoApps']);
        $this->assertSame(1, $code);
        $this->assertSame('One application per graph: found BEAR\QueryRepositoryGraph\Fixture\TwoApps\Other and BEAR\QueryRepositoryGraph\Fixture\TwoApps', $this->lastError($err));
    }

    public function testNoResourceClassesIsAnError(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=tests/Fixture/Orphan/Resource']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame('skipped', array_key_first(self::jsonl($err)[0]));
        $this->assertStringContainsString('No resource classes found in tests/Fixture/Orphan/Resource', $this->lastError($err));

        $dir = sys_get_temp_dir() . '/qr-graph-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/Broken.php', '<?php class');
            [$code, , $err] = self::qrGraph(['dump', '--dir=' . $dir]);
            $this->assertSame(1, $code);
            $this->assertSame("No resource classes found in {$dir}", $this->lastError($err), 'a file that ends at its class keyword declares nothing');
        } finally {
            @unlink($dir . '/Broken.php');
            rmdir($dir);
        }
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

        [$code, $out] = self::qrGraph(['impact', 'app://self/typo?id=1', '--allow-unknown', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $this->assertSame("app://self/typo\n", $out);

        [$code, $out, $err] = self::qrGraph(['impact', 'app://self/typo', '--allow-unknown', '--method=onPut', '--dir=' . self::DIR]);
        $this->assertSame(0, $code, $err);
        $this->assertSame("app://self/typo onPut\n", $out, 'an unknown resource has no write to reach anything from');
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

        $this->assertSame(16, self::at($nodes['meta'], 'meta', 'counts', 'edges'));
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
        $this->assertNull(self::at($nodes['app://self/entries'], 'methods', 'onPost', 'dependsOn'), 'only onGet() declarations are read');
        $this->assertSame([['uri' => 'app://self/entries', 'implicit' => 'onGet']], self::at($nodes['app://self/entries'], 'methods', 'onPost', 'purge'), 'an uncached write purges its own key');

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
        $this->assertStringContainsString('"app://self/entries" -> "app://self/article" [label="dependsOn"', $out);
        $this->assertSame(1, substr_count($out, '"app://self/archive" -> "app://self/author" [label="dependsOn"'), 'two declarations on one URI, differing in their query, are one arrow');
    }

    public function testEveryWriteToARowPurgesTheCollectionsItsOnGetLinks(): void
    {
        $dir = '--dir=tests/Fixture/Collection/Resource';
        [$code, $out, $err] = self::qrGraph(['dump', $dir]);
        $this->assertSame(0, $code, $err);
        $nodes = [];
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('dump.json', $line);
        }

        foreach (self::jsonl($out) as $decoded) {
            /** @var string $uri */
            $uri = $decoded['uri'] ?? 'meta';
            $nodes[$uri] = $decoded;
        }

        // A path-only href takes the resource's scheme and host, so `/posts` is the list already named; a `//host`
        // reference and a #[Link] of another rel purge nothing.
        $collections = [
            ['uri' => 'app://self/posts', 'collection' => true],
            ['uri' => 'app://self/author/posts', 'template' => 'app://self/author/posts?author_id={author_id}', 'collection' => true],
            ['uri' => 'app://self/archive', 'collection' => true],
            ['uri' => 'app://self{/kind}/posts', 'collection' => true],
        ];
        $this->assertSame($collections, self::at($nodes['app://self/post'], 'methods', 'onPut', 'purge'));
        $this->assertSame($collections, self::at($nodes['app://self/post'], 'methods', 'ONDELETE', 'purge'), 'a write handler in any letter case');
        $this->assertNull(self::at($nodes['app://self/post'], 'methods', 'onPostProcess'), 'a method that only begins with a write name is not a write, and its #[Purge] is not read');

        [, $out] = self::qrGraph(['impact', 'app://self/post', '--method=onPut', '--json', $dir]);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('impact.json', $line);
        }

        $this->assertSame([
            ['app://self/post', 'refresh', 'app://self/post', 1],
            ['app://self/posts', 'collection', 'app://self/post', 1],
            ['app://self/author/posts', 'collection', 'app://self/post', 1],
            ['app://self/archive', 'collection', 'app://self/post', 1],
            ['app://self{/kind}/posts', 'collection', 'app://self/post', 1],
            ['page://self/index', 'embed', 'app://self/posts', 2],
        ], array_map(static fn (array $line): array => [$line['uri'], $line['via'], $line['by'], $line['depth']], self::jsonl($out)));

        [, $out] = self::qrGraph(['dot', $dir]);
        $this->assertStringContainsString('"app://self/post" -> "app://self/posts" [label="onPut collection"', $out);
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
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/no-get-list', 'dependsOn' => 'app://self/no-get{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/no-write-list', 'dependsOn' => 'app://self/no-write{?id}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/origin-by-id', 'dependsOn' => 'app://self/origin{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/plain-put-list', 'dependsOn' => 'app://self/plain-put{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/plain-refresh-list', 'dependsOn' => 'app://self/plain-refresh{?id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/post-camel-list', 'dependsOn' => 'app://self/post-camel?article_id={id}'],
            ['warning' => 'depends-on-never-invalidated', 'resource' => 'app://self/post-only-list', 'dependsOn' => 'app://self/post-only{?id}'],
            ['warning' => 'depends-on-unknown-resource', 'resource' => 'app://self/typo', 'dependsOn' => 'app://self/artcle{?id}'],
            ['warning' => 'depends-on-tag-mismatch', 'resource' => 'app://self/with-page', 'dependsOn' => 'app://self/camel-pair{?leftId,rightId,page}'],
        ], $warnings, 'Unpurged is not warned: a write to a resource with an onGet() invalidates its own key, cached or not');
        $this->assertStringContainsString('write methods whose arguments', $messages['app://self/plain-put-list'], 'an uncached onPut() without the key skips its own key as an onPost() does; BoundKeyList is not warned, a #[ResourceParam] onGet() parameter is not in the key');
        $this->assertSame('The tag is app://self/article?[articleId], but writes purge app://self/article?[id].', $messages['app://self/mismatch']);
        $this->assertSame('The tag is app://self/origin?[id], but writes purge app://self/origin?[articleId], app://self/origin?[].', $messages['app://self/origin-by-id']);
        $this->assertStringContainsString('the writes that invalidate it are write methods whose arguments', $messages['app://self/post-only-list']);
        $this->assertStringContainsString('the writes that invalidate it are write methods whose arguments', $messages['app://self/donut-refresh-post-list'], 'its onPost() already has #[RefreshCache]');
        $this->assertStringContainsString('it has no write method', $messages['app://self/no-write-list']);
        $this->assertStringEndsWith('it has no onGet(), so no write refreshes it; name it in a #[Purge].', $messages['app://self/no-get-list'], 'its onPut() refreshes nothing, and a #[Refresh] of it fails at run time');
        $this->assertStringContainsString('the writes that invalidate it are write methods whose arguments', $messages['app://self/plain-refresh-list'], 'uncached, refreshed only by a #[RefreshCache] onPost() that is skipped');
        $this->assertStringContainsString('the writes that invalidate it are write methods whose arguments', $messages['app://self/method-cached-list'], 'cached by #[CacheableResponse] on onGet(), invalidated by its onPost() only when the POST carries the key');
    }

    public function testAWriteToACacheableResourceWithoutOnGetRefreshesNothing(): void
    {
        $nodes = (new GraphBuilder(new UriConverter(), (string) getcwd()))->build((new ResourceScanner())->scan('tests/Fixture/DependsOnWarn/Resource'))['nodes'];
        $noGet = array_values(array_filter($nodes, static fn (array $node): bool => $node['uri'] === 'app://self/no-get'));
        $this->assertCount(1, $noGet);
        $this->assertArrayNotHasKey('methods', $noGet[0], 'NoGet is #[Cacheable] and has an onPut() but no onGet()');
    }

    public function testImpactReadsTheWriteMethodInAnyLetterCase(): void
    {
        $dir = '--dir=tests/Fixture/Collection/Resource';
        [$code, $out, $err] = self::qrGraph(['impact', 'app://self/post', '--method=ondelete', '--json', $dir]);
        $this->assertSame(0, $code, $err);
        [, $expected] = self::qrGraph(['impact', 'app://self/post', '--method=ONDELETE', '--json', $dir]);
        $this->assertSame($expected, $out);
        $this->assertStringContainsString('app://self/posts', $out);
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

    public function testObserveJudgesEachLoggedChildAgainstItsDeclaration(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'hole'],
            ['page://self/donut-page', 'app://self/read-child', 'material'],
            ['page://self/donut-page', 'app://self/unknown-child', 'undeclared'],
        ], $this->verdicts(self::SESSIONS . '/expected.json'));
    }

    public function testObserveFlagsAChildThatCarriedNoTag(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'material'],
            ['page://self/donut-page', 'app://self/read-child', 'untagged-material'],
        ], $this->verdicts(self::SESSIONS . '/contrary.json'));
    }

    public function testObserveTakesAHoleFromTheRunTimeReason(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'material'],
            ['page://self/donut-page', 'app://self/read-child', 'hole'],
        ], $this->verdicts(self::SESSIONS . '/runtime.json'));
    }

    public function testObserveCannotTellASkipLoggedWithoutAReason(): void
    {
        $this->assertSame([
            ['page://self/donut-page', 'app://self/ignored-child', 'material'],
            ['page://self/donut-page', 'app://self/read-child', 'skipped'],
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

    public function testObserveTakesALayoutPartAsDeclared(): void
    {
        $this->assertSame([
            ['page://self/article', 'app://self/article', 'material'],
            ['page://self/article', 'page://self/esi/footer', 'material'],
            ['page://self/article', 'page://self/esi/header', 'material'],
            ['page://self/search', 'page://self/esi/search', 'hole'],
        ], $this->verdicts(self::SESSIONS . '/layout.json', self::LAYOUT));
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

        $dir = sys_get_temp_dir() . '/qr-graph-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            [$code, , $err] = self::qrGraph(['observe', $dir, '--dir=tests/Fixture/Usage/Resource']);
            $this->assertSame(1, $code);
            $this->assertSame("No session found in {$dir}", $this->lastError($err));

            file_put_contents($dir . '/broken.json', '{"log":');
            [$code, , $err] = self::qrGraph(['observe', $dir, '--dir=tests/Fixture/Usage/Resource']);
            $this->assertSame(1, $code);
            $this->assertStringStartsWith("{$dir}/broken.json: not JSON (", $this->lastError($err));
        } finally {
            @unlink($dir . '/broken.json');
            rmdir($dir);
        }
    }

    /** @return list<array{string, string, string}> page, child, verdict */
    private function verdicts(string $path, string $dir = 'tests/Fixture/Usage/Resource'): array
    {
        [$code, $out, $err] = self::qrGraph(['observe', $path, '--dir=' . $dir]);
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

    /**
     * The JSON the page embeds for its script
     *
     * @return array<string, mixed>
     */
    private static function glow(string $html): array
    {
        if (preg_match('#<script type="application/json" id="qr-graph-data">(.*?)</script>#s', $html, $match) !== 1) {
            self::fail('no embedded JSON');
        }

        /** @var array<string, mixed> $glow */
        $glow = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

        return $glow;
    }

    /** The page html renders for the directory, its picture the dot source as text */
    private static function warningsPage(string $dir, string|null $editor = null): string
    {
        $classes = (new ResourceScanner())->scan($dir);
        $nodes = (new GraphBuilder(new UriConverter(), (string) getcwd()))->build($classes)['nodes'];
        $echo = [PHP_BINARY, '-r', 'echo "<svg>", htmlspecialchars(stream_get_contents(STDIN)), "</svg>";'];
        $declarations = new NodeDeclarations();

        return (new HtmlRenderer($declarations, $echo))($nodes, (new DependsOnWarnings())($classes, $nodes), $editor);
    }

    /** @return list<Node> */
    private static function nodes(string $dir): array
    {
        return (new GraphBuilder(new UriConverter(), (string) getcwd()))->build((new ResourceScanner())->scan($dir))['nodes'];
    }

    /**
     * Each item a group of the page lists, as read without its sources and messages
     *
     * @return list<string>
     */
    private static function listed(string $html, string $kind): array
    {
        $xpath = self::xpath($html);
        $listed = [];
        foreach (self::elements($xpath, sprintf('//*[@data-kind="%s"]//li', $kind)) as $item) {
            $text = '';
            foreach ($xpath->query('.//text()[not(ancestor::*[@class="source" or @class="message"])]', $item) ?: [] as $node) {
                $text .= $node->nodeValue;
            }

            $listed[] = (string) preg_replace(['/\s+/', '/ ,/'], [' ', ','], trim($text));
        }

        return $listed;
    }

    /** @return list<string> the `file:line` of each item a group lists */
    private static function sources(string $html, string $kind): array
    {
        return self::texts(self::xpath($html), sprintf('//*[@data-kind="%s"]//*[@class="source"]', $kind));
    }

    /** @return list<string> */
    private static function texts(DOMXPath $xpath, string $query): array
    {
        return array_map(static fn (DOMElement $element): string => trim($element->textContent), self::elements($xpath, $query));
    }

    /** @return list<string> the URIs a click on a listed item selects in the picture */
    private static function selectable(string $html): array
    {
        $uris = [];
        foreach (self::elements(self::xpath($html), '//*[@data-uri]') as $element) {
            $uris[] = $element->getAttribute('data-uri');
        }

        return $uris;
    }

    private static function text(DOMXPath $xpath, string $query, DOMElement|null $context = null): string
    {
        $elements = self::elements($xpath, $query, $context);

        return trim($elements === [] ? '' : $elements[0]->textContent);
    }

    private static function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /** @return list<DOMElement> */
    private static function elements(DOMXPath $xpath, string $query, DOMElement|null $context = null): array
    {
        $elements = [];
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
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
