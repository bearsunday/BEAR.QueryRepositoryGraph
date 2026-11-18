# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Changed

- `html` draws only the cached part of the picture, as `dot --cached` does, so the whole graph is no longer clickable; draw it with `qr-graph dot | dot -Tsvg`. `--cached` is still accepted and changes nothing.
- A URI ending in `/` is an error, in `#[Embed]`, `#[DependsOn]`, `#[Refresh]`, `#[Purge]` and as a command argument: write the `index` it names (`page://self/index` for `page://self/`).

### Added

- `html` lists, below the picture in a section that starts collapsed, the declarations that affect no cache, each with its `file:line`: `#[Purge]`/`#[Refresh]` targets that reach no cached resource, `#[DependsOn]` on resources no cache stores, URIs no scanned resource serves, and the `#[DependsOn]` warnings. A collapsed "Not drawn" lists the resources the picture leaves out.
- `html --editor=phpstorm` links each `file:line` to the file in PhpStorm; without it the page holds no absolute path, so it can be shared.
- The `EMBED` of a resource layout attribute (a class attribute extending `AbstractResourceLayout`) is read as embeds of the page's `onGet()`, after its own `#[Embed]`, so `impact`, `snapshot`, `dot`, `html` and `observe` show them. Requires bear/resource 1.36 or later.

## [0.1.0] - 2026-11-15

First release. `vendor/bin/qr-graph` reads the BEAR.QueryRepository attributes of an application, without running it, and prints the cache graph they declare.
Requires PHP 8.2, bear/query-repository 1.20.2 and bear/resource 1.34.2 or later.

### Added

- `impact <uri> [--method=…]`: what invalidating a resource, or calling one of its write methods, reaches, as a tree; `--json` prints one JSONL line per reached resource.
- `snapshot`: every impact as one text file to keep in the repository and check in CI with `diff`, so a change to what a write invalidates shows up in review.
- `dot [--cached]`: a Graphviz picture of embeds, `#[DependsOn]` (grey) and writes (blue); `--cached` keeps the cached part and the writes that reach it.
- `html [--cached]`: the same picture as one page; click a resource to light what invalidates it and what it invalidates. Needs Graphviz `dot`.
- `dump`: one JSONL line per resource with its declarations, for tools and AI agents ([schema](https://github.com/bearsunday/BEAR.QueryRepositoryGraph/blob/1.x/docs/schemas/graph/dump.json)).
- `observe <session…>`: the semantic log of BEAR.QueryRepository 1.19+ against the declarations; `--fail-on=untagged-material` fails CI on a read child that carries no tag.
- `#[DependsOn]` declarations that leave a resource stale after a write (unknown resource, never invalidated, tag mismatch, bound parameter), or that purge more than needed (a default body), are reported on stderr.

[0.1.0]: https://github.com/bearsunday/BEAR.QueryRepositoryGraph/releases/tag/0.1.0
