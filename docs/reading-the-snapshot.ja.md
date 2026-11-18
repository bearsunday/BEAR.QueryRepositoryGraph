# スナップショットの読み方

`qr-graph snapshot` は、すべての影響をツリーで出力します。各リソースを無効化したときに何に届くか、各書き込みが何に
届くかです。アプリケーションを実行せず、宣言だけから作ります。リポジトリに置いて CI で diff を取れば、キャッシュの
無効化の範囲が変わったとき、それがレビューで diff として見えます。正確な形式は
[graph.md の `snapshot`](graph.md#snapshot--impacts-to-review-as-a-diff) にあります。

## 用語

1 行は `<uri> (<via>[, <cache>])` の形です。親の行から `<via>` で `<uri>` に届き、`<cache>` は `<uri>` のキャッシュの
宣言です。

| スナップショット上の表記 | 元になる宣言 |
|---|---|
| ブロックの見出し `<uri>` | 無効化が別のリソースに届くリソース |
| ブロックの見出し `<uri> <method>` | `#[Refresh]` か `#[Purge]` を宣言した書き込みメソッド |
| `embed` | `<uri>` が親を `#[Embed]` で埋め込む。リソースレイアウト経由の埋め込みも含む |
| `dependsOn` | `<uri>` が `#[DependsOn]` で親を指定している |
| `refresh` / `purge` | 書き込みメソッドが `#[Refresh]` / `#[Purge]` で `<uri>` を指定している |
| `cacheable, ttl: …` | `#[Cacheable]` とその `expiry`。`type: 'view'` なら `cacheable view, ttl: …` |
| `cacheable response` | `#[CacheableResponse]` |
| `donut cache` | `#[DonutCache]` |

## 読み方の決まり

- 字下げは「1 段浅い直前の行を経由して届く」という意味です。
- 各 URI はブロックの中で 1 回だけ、最短経路の 1 つの下に出ます。それより長い経路は出しません。
- 子は URI の順に並びます。
- 行に `file:line` はありません。コードを移動してもテキストは変わりません。
- 宣言された経路だけが出ます。テンプレートが描画中に `resource()` で取りに行くリソースは出ません。

## 例

[demo/qr-graph.snapshot.txt](../demo/qr-graph.snapshot.txt) の `onPost` のブロックです。

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

`app://self/admin/article` の `onPost` は `articles` と `tags` を purge します。`category`、`tag`、`author`、`index` は
`articles` を埋め込んでいるので、一緒に古くなります。`page://self/category` は `app://self/category` を埋め込んでいる
ので、さらに 1 段先です。`pickup` と `page://self/tag` は `tags` を埋め込んでいます。`index` は `pickup` も埋め込んで
いますが、`articles` の下に短い経路で出ているので、`pickup` の下には繰り返しません。

ここで `Page/Layout/Pickup.php` から `#[Embed(rel: 'tags', src: 'app://self/tags')]` を外し、diff を取ります。

```bash
php bin/qr-graph snapshot --dir=demo/src/Resource | diff -u demo/qr-graph.snapshot.txt -
```

diff は 5 つの hunk になります。`pickup` を通っていたブロックごとに 1 つです。`onPost` の hunk は次のとおりです。

```diff
@@ -93,7 +89,6 @@
     page://self/author (embed, cacheable, ttl: medium)
     page://self/index (embed, cacheable, ttl: short)
   app://self/tags (purge, cacheable, ttl: never)
-    page://self/layout/pickup (embed, cacheable, ttl: short)
     page://self/tag (embed, cacheable, ttl: medium)
 
 app://self/admin/article onPut
```

読み方は「この書き込みは、もう `page://self/layout/pickup` に届かない」です。pickup がまだタグを表示しているなら、
`onPost` の後も `short` の TTL が切れるまで古いタグを返します。diff がそれをレビューで捕まえたことになります。pickup
がタグを表示しなくなったのなら意図どおりの変更なので、新しいスナップショットを commit します。

## ログと合わせて

スナップショットは宣言されたこと、BEAR.QueryRepository のキャッシュログは実際に起きたことです
([ログの読み方](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/reading-the-log.ja.md))。
