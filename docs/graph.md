# The declared cache graph (`qr-graph`)

Cache behavior in BEAR.QueryRepository is declared: `#[Embed]` and `#[DependsOn]` say what a resource depends on,
`#[Refresh]`/`#[Purge]` say what a write invalidates, and `#[Cacheable]`/`#[CacheableResponse]`/`#[DonutCache]`
say what is cached at all. `qr-graph` reads those declarations, without running the application, and
prints the graph they form.

The semantic log records what the cache *did*, as instance URIs (`depends_on`, `invalidate`, ...).
This graph records what the code *declares*, as resource URIs. Comparing the two finds a dependency that
is declared but never observed, or observed but never declared.

> `qr-graph` is a development tool. Install it with `composer require --dev bear/query-repository-graph`;
> it is not installed by `composer install --no-dev`. Its contract is the command line and the schemas below, not the PHP classes.

## Commands

```bash
vendor/bin/qr-graph dump [--dir=src/Resource]
vendor/bin/qr-graph impact <uri> [--method=onPut] [--allow-unknown] [--json] [--dir=src/Resource]
vendor/bin/qr-graph dot [--cached] [--dir=src/Resource]
vendor/bin/qr-graph snapshot [--dir=src/Resource]
vendor/bin/qr-graph observe <session.json|dir>... [--dir=src/Resource] [--fail-on=<verdict>[,<verdict>...]]
```

Run it from the application root: classes are loaded through the application's autoloader, and a file
the autoloader does not resolve is reported on stderr as skipped, never included. `dump`, `impact --json` and
`observe` print JSONL on stdout; `impact`, `dot` and `snapshot` print text.
Skipped files, warnings and tool errors are JSONL on stderr; usage errors are plain text, as are PHP's own notices.
Exit code: 0 ok, 1 error, 2 usage, 3 `observe --fail-on` matched a printed line. An unknown option or an extra
argument is a usage error (2); `--method` naming a method that refreshes and purges nothing is an error (1).
A scan that finds no resource classes is an error (1) rather than an empty success: that is what a missing
application autoloader looks like. `impact` on a URI no scanned resource serves is also an error (1) unless
`--allow-unknown` is given.

### `dump` — one line per resource

The first line is `meta`; every other line is a resource and the declarations it makes
([schema](schemas/graph/dump.json)). Edges start at the resource that declares them, so a line has no `from`.
`meta` also records `counts` (nodes, edges, skipped), `tool.name` and the installed package version, and the
`autoload` file relative to the working directory. `meta.version` is 5: v2 added those fields, v3 the embed `usage`,
v4 `dependsOn`, v5 dropped the source-text guesses, `dynamic` and the embed `usage`.

```json
{"uri":"app://self/user","source":"src/Resource/App/User.php:17","cacheable":{"expiry":"never","expirySecond":0,"expiryAt":"","update":false,"type":"value"},"methods":{"onPut":{"source":"src/Resource/App/User.php:52","refresh":[{"uri":"app://self/user","implicit":"Cacheable"},{"uri":"app://self/user/profile","template":"app://self/user/profile?user_id={id}"}],"purge":[{"uri":"app://self/user/friend","template":"app://self/user/friend?user_id={id}"}]}}}
```

- `uri` is `scheme://host/path` without query, exact-matchable everywhere it appears; `template` keeps the
  declaration as written when it differs, including another spelling of a resource's URI (`galleryArticle` is
  listed as `gallery-article`). The PHP class is not repeated: it follows from `uri` and `meta.namespaces`.
- `source` is `file:line`, to open the declaration.
- `dependsOn` on `onGet` lists its `#[DependsOn]` declarations, including those on an `onGet()` it overrides.
  A declaration on a row class is not in the graph: see [what the graph does not know](#what-the-graph-does-not-know).
- `implicit` marks a refresh nobody wrote: a write method of a `#[Cacheable]`/`#[CacheableResponse]` class,
  or one marked `#[RefreshCache]`, refreshes its own resource.

Local questions are a `grep` away: `grep '"uri":"app://self/user/profile"'` finds the resource, and
`grep 'app://self/user/profile"'` also finds every line that embeds, refreshes or purges it.

### `impact` — what an invalidation reaches

Multi-hop questions ("if this changes, what goes stale?") are answered by `impact`, not by repeated `grep`.
It walks the embed and `dependsOn` edges breadth-first, one line per reached URI by its shortest path.
With `--method`, it starts from what that write method refreshes and purges.
A `dependsOn` edge is followed only from a URI that is purged itself (the one asked about, or a `refresh`/`purge`
target): a declaration carries the exact tag of the URI it names, not the tags that URI carries. `impact` does
not compare tags: an edge whose declaration does not match what the write purges is reported on stderr as
`depends-on-tag-mismatch` or `depends-on-never-invalidated`, and still listed here.

It prints the tree `snapshot` prints for the same start, also when nothing is reached (the start alone) and for a
write that only refreshes its own resource implicitly, which `snapshot` leaves out.
`--json` prints the lines as JSONL instead ([schema](schemas/graph/impact.json)), with each line's `source`:

```json
{"uri":"page://self/dep/diamond-top","via":"embed","by":"page://self/dep/diamond-left","depth":2,"cached":true,"source":"src/Resource/Page/Dep/DiamondTop.php:10"}
```

`by` leads back to the start. URIs are matched as BEAR.Resource's AppAdapter resolves them: path segments
are case-insensitive and `-` is ignored, so `galleryArticle` finds `gallery-article`. `cached: false` on the
resource named by `by` means the cache does not carry that dependency: tags reach a parent only from a child
that already ran, carrying an ETag (the non-donut path), or an ETag or Surrogate-Key (donut materials; holes
have not run yet). A resource reached via `dependsOn` is the exception: once it has run inside the page (not as a
hole), its declared tags reach the page whether it is cached or not.

### `dot` — a picture

```bash
vendor/bin/qr-graph dot | dot -Tsvg > graph.svg
```

Arrows point as declared (a page to what it embeds, labelled with the relation; a resource to what it depends on,
grey; a write to what it refreshes or purges, blue); add `edge [dir=back]` for the direction
invalidation travels. Declarations that differ only in their query (`?type=news`, `?type=sns`) are one arrow;
`dump` keeps each `template`. A page is a grey box, any other resource plain text. Under the path, the scheme (with the
host when it is not `self`) and the cache attributes as declared: `page (cacheable, ttl: medium)`,
`app (cacheable view, ttl: never)` for `type: 'view'`, `page (cacheable response)`, `page (donut cache)`. Only
`#[Cacheable]` expires by time, so only it shows a TTL.
A resource no arrow touches is left out unless it is cached: then it shows a cache no declared write invalidates.
Pages no drawn arrow points to (other than their own arrows to themselves), where a request starts, line up in the leftmost
column; with `--cached`, an arrow from a resource left out does not count.

`--cached` draws the cached resources (what `impact` reports as `cached: true`) and what they embed, directly or
not; then what those name in `#[DependsOn]`, often a resource nothing caches whose URI writes purge, and the
resources whose writes refresh or purge any of these. Other arrows are kept when both of their ends are drawn.

### `snapshot` — impacts to review as a diff

```bash
vendor/bin/qr-graph snapshot > qr-graph.snapshot.txt
vendor/bin/qr-graph snapshot | diff -u qr-graph.snapshot.txt -   # in CI: what a change does to the cache
```

Every `impact` as an indented tree: a block per resource whose invalidation reaches another, then a block
(`<uri> <method>`) per write method declaring `#[Refresh]` or `#[Purge]`. A line reads
`<uri> (<via>[, <cache>])` under the URI named by its `by`, the cache named as `dot` names it
(`cacheable, ttl: medium`, `cacheable response`); children are sorted by URI and no line
carries a `source`, so moving code does not change the text. Like `by`, the tree shows each reached URI once,
by one shortest path. The text is for diffs, not parsing: regenerate it after upgrading `qr-graph`.

To design the cache before the code, write the resource classes with their attributes and empty methods, agree on
`dot` and `snapshot`, commit the snapshot, then write the methods: the snapshot stays the same.

### `observe` — the log against the declarations

```bash
vendor/bin/qr-graph observe var/log/query-repository
```

Reads semantic log sessions (a directory contributes its timestamped files, not `latest.json`) and writes one
line per page and embedded child that a donut write classified ([schema](schemas/graph/observe.json)):

```json
{"page":"page://self/blog","child":"app://self/channel","observed":["no_etag_or_surrogate_key"],"declared":"donut","verdict":"untagged-material"}
```

`depends_on` and `dependency_skipped` (BEAR.QueryRepository 1.19+) carry the run-time facts: a skipped child's
`reason` says whether it had no body at write time (`no_body`, read as a hole; a run that left no body, such as a
204, looks the same) or ran without a tag (`no_etag_or_surrogate_key`). A skip logged without a reason tells
neither apart and is `skipped`. `declared` is `donut` when the page's class declares `#[CacheableResponse]` or
`#[DonutCache]`, `embedded` otherwise. The verdicts that need reading:

- `untagged-material` — the page read the child, but the child carried neither an ETag nor a Surrogate-Key, so a
  change to the child's data does not reach the page's template **through this child**. `observe` writes a
  matching warning on stderr for every such line
  (`{"warning":"untagged-material","page":...,"child":...,"message":...}`), even when the page covers the child
  with a hand-written Surrogate-Key: `observe` cannot see that from here, and a hand-written key is not a
  supported fix for this — it is not part of the rollout below. The fix is always on the child: make it
  cacheable (so it carries its own ETag/Surrogate-Key) or render it as a hole.
- `unclassified` — the page was written (`put_donut`), yet the declared child was no longer an embedded request at
  the top of its body: removed with `unset()`, replaced by its body array, or moved into a nested array. The
  classification looks at the top of the body only, so such a child adds no tag to the page even when the page
  read it. An `unset()` of a child the page never needed is the same verdict and harmless.

Only pages written in the sessions read are judged; a page served from cache classifies nothing. Sessions without a
single `dependency_skipped` are reported with a warning on stderr: they may come from a version that does not record
it, where holes and untagged children read as `unclassified`.

`--fail-on=<verdict>[,<verdict>...]` (repeatable or comma-separated) makes `observe` exit 3 when a printed line
carries a listed verdict; without it the warnings above are the only signal, and the exit code is unchanged. An
unknown verdict name is a usage error (2). Roll it out gradually: watch the `untagged-material` warnings on
stderr until there are none, then turn on `--fail-on=untagged-material` in CI so a new one fails the build
instead of aging silently behind the page's TTL.

## Warnings on `#[DependsOn]`

Every command checks the `onGet()` declarations and writes one line on stderr per finding, without changing
the exit code. None of them fails at run time:

```json
{"warning":"depends-on-tag-mismatch","resource":"app://self/entries","source":"src/Resource/App/Entries.php:14","dependsOn":"app://self/article{?articleId}","message":"The tag is app://self/article?[articleId], but writes purge app://self/article?[id]."}
```

These leave the resource stale after a write to what it depends on:

- `depends-on-unknown-resource` — no scanned resource serves the URI.
- `depends-on-never-invalidated` — no write refreshes or purges the URI. A write method refreshes its own resource
  implicitly, but an `onPost()` does so only when its arguments carry every required `onGet()` one, under the same
  name or its snake_case form: otherwise the refresh is skipped for lack of them. This assumes the POST query uses
  the `onPost()` argument names. A resource cached by `#[DonutCache]`, or by `#[CacheableResponse]` on `onGet()`
  only, is refreshed only by a write method that carries `#[RefreshCache]` or `#[CacheableResponse]`.
- `depends-on-tag-mismatch` — the tag the declaration expands to (its path as written, and its query keys) is none
  of the tags a write purges. A write refreshes its own resource under the required `onGet()` parameters, as named
  or all in snake_case, at the resource's own path; a `#[Refresh]`/`#[Purge]` purges the URI it names.
- `depends-on-bound-parameter` — a template variable is a parameter bound by a web-context attribute
  (`#[QueryParam]`, `#[CookieParam]`, ...). The value is not in the request query, so the URI expands from the
  parameter's default.

This one purges more than it should:

- `depends-on-default-body` — the class gives `$body` a default, or `$code` one other than 200. Rendered as a hole
  it reads as already run, so the page takes its declarations and is purged whole on every write to them, instead
  of recomposed.

A template variable that is not an `onGet()` parameter, one bound by `#[ResourceParam]`, and a declaration on the
resource class are not reported: they fail the first GET with `DependsOnNotExpandable`.

## What the graph does not know

- Surrogate keys set in code and resources requested in code.
- `#[DependsOn]` on a row class: the class lives outside the scanned directory, and which resource's body holds
  its instances is known only at run time.
- Which instance a template reaches: `{id}` is filled from the request at run time. That is the log's part.
- Bindings: the graph reads attributes, not the application's modules, so it does not know whether
  `DonutCacheModule` is installed or a CDN purger is bound.
