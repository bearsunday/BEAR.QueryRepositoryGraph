# BEAR.QueryRepositoryGraph

`qr-graph` prints the cache graph that [BEAR.QueryRepository](https://github.com/bearsunday/BEAR.QueryRepository)
attributes declare, without running the application: what each resource embeds, what each write refreshes and
purges, and what an invalidation reaches.

## Installation

```bash
composer require --dev bear/query-repository-graph
```

## Usage

Run it from the application root.

```bash
vendor/bin/qr-graph dump                                  # one JSONL line per resource
vendor/bin/qr-graph impact app://self/user --method=onPut  # what the write reaches
vendor/bin/qr-graph dot | dot -Tsvg > graph.svg           # a picture
vendor/bin/qr-graph observe var/log/query-repository      # the semantic log against the declarations
```

The commands, their output and its limits are described in [docs/graph.md](docs/graph.md); the output shapes are
the JSON Schemas under [docs/schemas/graph](docs/schemas/graph).
