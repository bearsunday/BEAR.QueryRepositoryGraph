# Reading the Snapshot

`qr-graph snapshot` prints every impact as a tree: what invalidating each resource reaches, and what each write
reaches. It is built from the declarations alone, without running the application. Keep it in the repository and
diff it in CI, so a change to what the cache invalidates shows up as a diff in review. The exact format is in
[graph.md, `snapshot`](graph.md#snapshot--impacts-to-review-as-a-diff).

## Terms

A line reads `<uri> (<via>[, <cache>])`: the parent line reaches `<uri>` by `<via>`, and `<cache>` is how `<uri>`
is cached.

| In the snapshot | Comes from |
|---|---|
| block header `<uri>` | a resource whose invalidation reaches another resource |
| block header `<uri> <method>` | a write method declaring `#[Refresh]` or `#[Purge]` |
| `embed` | `<uri>` embeds the parent with `#[Embed]`, or through its resource layout |
| `dependsOn` | `<uri>` names the parent in `#[DependsOn]` |
| `refresh` / `purge` | the write method names `<uri>` in `#[Refresh]` / `#[Purge]` |
| `cacheable, ttl: …` | `#[Cacheable]` and its `expiry`; `cacheable view, ttl: …` for `type: 'view'` |
| `cacheable response` | `#[CacheableResponse]` |
| `donut cache` | `#[DonutCache]` |

## Reading rules

- Indentation means "reached through the line above at one less indent".
- Each URI appears once per block, under one shortest path. A longer path to it is not printed.
- Children are sorted by URI.
- No line carries a `file:line`, so moving code does not change the text.
- Only declared paths appear. A resource a template pulls in with `resource()` while rendering is not in it.

## Worked example

The `onPost` block of [demo/qr-graph.snapshot.txt](../demo/qr-graph.snapshot.txt):

```text
app://self/admin/article onPost
  app://self/articles (purge, cacheable, ttl: never)
    app://self/category (embed, cacheable, ttl: never)
      page://self/category (embed, cacheable, ttl: medium)
    app://self/tag (embed, cacheable, ttl: never)
    page://self/author (embed, cacheable, ttl: medium)
    page://self/index (embed, cacheable, ttl: short)
  app://self/tags (purge, cacheable, ttl: never)
    page://self/layout/pickup (embed, cacheable, ttl: short)
    page://self/tag (embed, cacheable, ttl: medium)
```

`onPost` of `app://self/admin/article` purges `articles` and `tags`. `category`, `tag`, `author` and `index` embed
`articles`, so they go stale with it; `page://self/category` embeds `app://self/category`, one level further. `pickup`
and `page://self/tag` embed `tags`. `index` also embeds `pickup`, but it is already listed under `articles` by a
shorter path, so it is not repeated under `pickup`.

Now remove `#[Embed(rel: 'tags', src: 'app://self/tags')]` from `Page/Layout/Pickup.php` and diff:

```bash
php bin/qr-graph snapshot --dir=demo/src/Resource | diff -u demo/qr-graph.snapshot.txt -
```

The diff has five hunks, one per block that went through `pickup`. The one for `onPost`:

```diff
@@ -93,7 +89,6 @@
     page://self/author (embed, cacheable, ttl: medium)
     page://self/index (embed, cacheable, ttl: short)
   app://self/tags (purge, cacheable, ttl: never)
-    page://self/layout/pickup (embed, cacheable, ttl: short)
     page://self/tag (embed, cacheable, ttl: medium)
 
 app://self/admin/article onPut
```

Read it as: this write no longer reaches `page://self/layout/pickup`. If pickup still shows the tags, it now serves
them stale after `onPost` until its `short` TTL runs out, and the diff caught that in review. If pickup no longer
shows them, the change is intended: commit the new snapshot.

## With the log

The snapshot is what is declared; BEAR.QueryRepository's cache log is what ran
([Reading the Log](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/reading-the-log.md)).
