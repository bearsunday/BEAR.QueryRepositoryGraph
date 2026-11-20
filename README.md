# BEAR.QueryRepositoryGraph

Static analysis of the cache graph that [BEAR.QueryRepository](https://github.com/bearsunday/BEAR.QueryRepository)
attributes declare. `qr-graph` reads the declarations without running the application: what each resource embeds,
what each write refreshes and purges, and what an invalidation reaches.

[![dot of the demo application](docs/images/dot.svg)](https://bearsunday.github.io/BEAR.QueryRepositoryGraph/)

Click the picture for the [interactive version](https://bearsunday.github.io/BEAR.QueryRepositoryGraph/) (`qr-graph html` on the demo).

## Installation

```bash
composer require --dev bear/query-repository-graph
```

## Usage

Run it from the application root.

```bash
vendor/bin/qr-graph dot | dot -Tsvg > graph.svg                   # the picture
vendor/bin/qr-graph dot --cached | dot -Tsvg > cached.svg         # the cached part and the writes that reach it
vendor/bin/qr-graph html > graph.html                             # the cached part to click, and the declarations that reach no cache
vendor/bin/qr-graph impact app://self/article                     # what invalidating a resource reaches
vendor/bin/qr-graph impact app://self/admin/article --method=onPut  # what a write reaches
vendor/bin/qr-graph snapshot > qr-graph.snapshot.txt              # every impact, kept in the repository
vendor/bin/qr-graph dump                                          # one JSONL line per resource
vendor/bin/qr-graph observe var/log/query-repository              # the semantic log against the declarations
```

People read the picture, `impact` and `snapshot`. Tools and AI agents read `dump`, `impact --json` and `observe`:
JSONL, described by the [JSON Schemas](docs/schemas/graph). Each command and its limits are in
[docs/graph.md](docs/graph.md). The picture above is `php bin/qr-graph dot --dir=demo/src/Resource`, run on
[demo](demo/src/Resource): resource classes that carry their attributes and nothing else.

## Reading the picture

| | |
|---|---|
| grey rounded box | a `page` resource; pages nothing points to, where a request starts, line up on the left |
| plain text | any other resource (`app`); a host other than `self` is shown, as in `app://blog` |
| second row | the scheme and the cache as declared: `cacheable, ttl: short`, `cacheable view, ttl: …`, `cacheable response`, `donut cache`; nothing when the resource is not cached |
| black arrow | embeds, labelled with the relation (`index` embeds `ranking` as `ranking`) |
| grey arrow | `#[DependsOn]` |
| blue arrow | a write that refreshes or purges, labelled `onPut refresh`, `onPost purge` |

Arrows point the way they are declared. Invalidation travels against them: refreshing `article` reaches `ranking`
through its grey arrow, and `index` through the black one.

`qr-graph html > graph.html` writes the cached part of the picture (`dot --cached`) as one page; the whole graph is
`qr-graph dot | dot -Tsvg`. Click a resource: what invalidates it glows green, the writes that invalidate it blue, and
what it invalidates orange, each side counted; the rest dims. Below the picture, a collapsed Warnings section lists
the declarations that affect no cache, each with its `file:line` (a PhpStorm link with `--editor=phpstorm`), and a
collapsed Not drawn after it lists the resources left out. A `#[DependsOn]` on a resource no cache stores is listed in
its own group of Not drawn, not as a warning: its tags reach a cache once a cached page embeds it. A resource in that
group can also be drawn, or carry another `#[DependsOn]` warning.

### Resource layouts

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class ContentPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = ['header' => 'page://self/esi/header', 'footer' => 'page://self/esi/footer'];
}

#[Attribute(Attribute::TARGET_CLASS)]
final class SearchPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [...ContentPage::EMBED, 'search' => '/esi/search{?q}'];
}
```

A resource layout, a class attribute extending BEAR.Resource's `AbstractResourceLayout` such as `#[ContentPage]`, is
drawn as one table at the top-left instead of arrows: a column per layout, a row per part it embeds, ● where the layout
has the part. A column head is the grey rounded box of a page holding `#[ContentPage]`: a layout depends on its parts
as a page does. The top-left cell holds the scheme and the directory the rows share. Each page names its layout after its
cache, `page (cacheable response, #[ContentPage])`. No arrow touches the table: a part with arrows of its own, or a
cached one, is also drawn as a node. In `html`, a column head selects like a resource: the pages carrying the layout
light as invalidated by it, its parts and their writers as invalidating it. Clicking a row lights the pages whose
layout has the part, with its ●; clicking a page lights its layout's column. `impact`, `snapshot` and the warnings
take each part as an `#[Embed]` of every page carrying the layout.

## What a change reaches

```
$ vendor/bin/qr-graph impact app://self/admin/article --method=onPut
app://self/admin/article onPut
  app://self/article (refresh, cacheable, ttl: never)
    app://self/articles (dependsOn, cacheable, ttl: never)
      app://self/category (embed, cacheable, ttl: never)
        page://self/category (embed, cacheable, ttl: medium)
      app://self/tag (embed, cacheable, ttl: never)
      page://self/author (embed, cacheable, ttl: medium)
    app://self/related (dependsOn, cacheable, ttl: never)
    app://self/tags (dependsOn, cacheable, ttl: never)
      page://self/layout/pickup (embed, cacheable, ttl: short)
      page://self/tag (embed, cacheable, ttl: medium)
    page://self/article (embed, cacheable, ttl: medium)
  app://self/ranking (purge, cacheable, ttl: short)
    page://self/index (embed, cacheable, ttl: short)

$ vendor/bin/qr-graph impact app://self/login
app://self/login
```

Each line is reached from the line above it at one less indent, by the relation in parentheses, and shows its
cache. A resource two paths reach is listed once, under a shortest one. `app://self/login` alone means a change to
it reaches nothing.

## The snapshot as the source of truth

`snapshot` writes such a tree for every resource and every write. Keep it in the repository and check it in CI:

```bash
vendor/bin/qr-graph snapshot | diff -u qr-graph.snapshot.txt -
```

The demo keeps [its snapshot](demo/qr-graph.snapshot.txt), checked this way by [CI](.github/workflows/snapshot.yml).

The runtime acts on the declarations; people and agents read and agree on the snapshot; CI keeps the two equal, as
`composer.lock` follows `composer.json`. A pull request that changes what a write invalidates shows it as a diff of
this file. Design the cache before the code the same way:

1. Write the resource classes with their attributes only, as in [demo](demo/src/Resource).
2. Agree on the picture and the snapshot, and commit the snapshot.
3. Write the methods. The snapshot does not change.

Only declarations reach the graph. Invalidation written as code, a `purge()` call or a surrogate key set by hand,
never shows in the snapshot, so it cannot produce the tree that was agreed: what the cache does stays declared.
