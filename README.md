# sdb

![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

**A standalone PHP CLI for persistent JSON document storage. Zero infrastructure.**

`sdb` is *jq meets sqlite3* — but you think in **documents**, not tables. Put a JSON
document, get it back, query a collection with filters and ordering, stream it as NDJSON,
import it back. No database server, no schema, no migrations. Just a directory (or a single
SQLite file) under your home folder.

It is a thin CLI over the [`tanghoong/simpledb`](https://github.com/tanghoong/SimpleDB)
document-storage library.

---

## Install

### Option A — download the phar (recommended)

```bash
# a single self-contained executable
curl -L -o sdb.phar https://example.com/sdb.phar   # or build it yourself (see "Building")
chmod +x sdb.phar
sudo mv sdb.phar /usr/local/bin/sdb

sdb --version
```

### Option B — Composer global

```bash
composer global require tanghoong/sdb
# ensure ~/.composer/vendor/bin (or ~/.config/composer/vendor/bin) is on your PATH
sdb --version
```

### Option C — from source

```bash
git clone https://github.com/tanghoong/sdb-cli
cd sdb-cli
composer install
php bin/sdb --version
```

**Requires:** PHP ≥ 8.2 with `ext-json` (always on). The `sqlite` and `memory` adapters
also need `ext-pdo_sqlite` (ships with PHP).

> **Note on the dependency:** until `tanghoong/simpledb` is published to Packagist, this
> project pulls it straight from GitHub via a `repositories` entry in `composer.json`
> (`"tanghoong/simpledb": "dev-master"`). Once it's on Packagist, delete that `repositories`
> block and pin a released version — no code changes required.

---

## Quick start

```bash
sdb put users alice '{"name":"Alice","age":30,"role":"admin"}'
sdb get users alice
#  {
#      "name": "Alice",
#      "age": 30,
#      "role": "admin"
#  }

sdb put users bob '{"name":"Bob","age":25,"role":"member"}'

sdb list users                       # ["alice","bob"]
sdb count users                      # 2
sdb find users --where role=admin    # [ { "_id": "alice", ... } ]

sdb export users > users.ndjson      # back up
sdb import users-copy < users.ndjson # restore into another collection
```

By default everything is stored under `~/.sdb/`. Override with `--data <dir>` or the
`SDB_DATA_DIR` environment variable.

---

## Commands

Every command takes a `<collection>` and the global flags below.

| Command | Purpose |
|---|---|
| `sdb put <collection> <id> '<json>'` | Create or overwrite a document |
| `sdb get <collection> <id>` | Read one document |
| `sdb delete <collection> <id>` | Delete a document (aliases: `del`, `rm`) |
| `sdb list <collection>` | List all document IDs (alias: `ls`) |
| `sdb find <collection> [filters]` | Query with filters, ordering, pagination |
| `sdb count <collection> [--where ...]` | Count documents, optionally filtered |
| `sdb export <collection>` | Stream the collection to stdout as NDJSON |
| `sdb import <collection>` | Load NDJSON from stdin (or a file) |

Run `sdb <command> --help` for full per-command help.

### put

```bash
sdb put products p1 '{"name":"Widget","price":300,"stock":5}'
```

Stores the JSON document under the explicit id `p1`, replacing any existing one. Prints the
id on success. The JSON body must be a single object (or array).

### get

```bash
sdb get products p1            # pretty JSON
sdb get products p1 --raw      # compact, single line
```

Exits `1` (and prints nothing to stdout) if the document does not exist.

### delete

```bash
sdb delete products p1
sdb rm products p1             # alias
```

Exits `1` if the document does not exist.

### list

```bash
sdb list products             # ["p1","p2","p3"]
sdb list products --raw       # compact array
sdb list products --ndjson    # one id per line
```

### find

```bash
sdb find products --where 'price:<:500' --order name:asc --limit 10
sdb find users    --where role=admin --where 'age:>=:21'
sdb find users    --where 'role:in:admin,moderator' --ndjson
```

Returns matching documents as a JSON array; each result carries its storage id in an
injected **`_id`** field. Supports `--raw` and `--ndjson`.

**Options:** `--where` / `-w` (repeatable), `--order` / `-o` (repeatable), `--limit` / `-l`,
`--offset`.

### count

```bash
sdb count orders
sdb count orders --where status=pending
```

Prints a plain integer.

### export / import

```bash
sdb export users > users.ndjson          # dump
sdb import users < users.ndjson          # load from stdin
sdb import users --from users.ndjson     # load from a file (handy on Windows)
sdb export users | sdb import users-copy # clone a collection
```

`export` emits one JSON object per line, each with an `_id` field. `import` reads the same
format: a line's `_id` (if present) becomes the storage id, otherwise an id is generated.
The pair round-trips losslessly. `import` prints the number of documents loaded.

> **`_id` is reserved.** It always denotes the storage id in `find`/`export` output. You
> cannot `put` a document that contains an `_id` field (it's rejected as a usage error), so
> a stored document never collides with the injected id — that's what keeps the round-trip
> lossless.

---

## Query syntax

`--where` accepts two forms:

```
--where field=value          # equality shorthand
--where field:op:value       # explicit operator
--where field:null           # unary operators take no value
```

**Operators:** `=` `!=` `>` `>=` `<` `<=` `in` `not_in` `contains` `starts_with`
`ends_with` `null` `not_null`. Nested fields use dot-notation: `--where shipping.country=MY`.

`in` / `not_in` take a comma-separated list: `--where 'status:in:new,paid,shipped'`.

**Value typing.** Because the underlying matcher uses strict comparison, CLI values are
coerced so they match JSON numbers/booleans: `300` → int, `1.5` → float, `true`/`false` →
bool, `null` → null; everything else stays a string. So `--where price=300` matches the
number `300`.

Coercion only applies when the value round-trips exactly, so id-like strings keep their
identity: a leading-zero value (`00123`) or an integer beyond `PHP_INT_MAX` stays a **string**
and matches a string-stored field. The substring operators `contains` / `starts_with` /
`ends_with` are never coerced.

`--order` accepts `field` (ascending) or `field:asc` / `field:desc`, and is repeatable for
multi-key sorts.

> ⚠️ **Shell quoting:** operators `<` and `>` are shell redirection characters. Always quote
> the clause: `--where 'price:<:500'` (single quotes), not `--where price:<:500`.

---

## Global flags

| Flag / env | Default | Description |
|---|---|---|
| `--adapter file\|sqlite\|memory` | `file` | Storage backend |
| `--data <dir>` | — | Storage directory (overrides everything below) |
| `SDB_DATA_DIR` | — | Storage directory (env var) |
| *(fallback)* | `~/.sdb` | Default storage root |
| `--raw` | off | Compact, single-line JSON output |
| `--ndjson` | off | Newline-delimited JSON (streams / lists) |

### Adapters & storage layout

| Adapter | Where data lives | Notes |
|---|---|---|
| `file` (default) | `<dataDir>/<collection>/<id>.json` | One JSON file per document. Works anywhere. |
| `sqlite` | `<dataDir>/sdb.sqlite` | All collections in one WAL-mode SQLite file; filters push down to SQL. Needs `pdo_sqlite`. |
| `memory` | `:memory:` | Ephemeral — exists only for the duration of a single command. Useful for testing. Needs `pdo_sqlite`. |

---

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Document / collection not found |
| `2` | Usage error (bad flag, malformed `--where`, unknown command) |
| `3` | Storage error (I/O failure, missing extension, corrupt data) |

These make `sdb` easy to script:

```bash
if sdb get users alice >/dev/null 2>&1; then echo "exists"; else echo "missing"; fi
```

---

## Building the phar

```bash
make phar        # strips dev deps, builds ./sdb.phar, restores dev deps
```

No `make` (e.g. on Windows)? Run the steps directly:

```bash
composer install --no-dev --optimize-autoloader
php -d phar.readonly=0 build/build-phar.php
composer install        # restore dev deps for testing
```

The build needs `phar.readonly=Off`; the `-d phar.readonly=0` flag sets it just for that run.

---

## Development

```bash
composer install
make test            # or: php vendor/bin/phpunit
php bin/sdb --help
```

### Hacking on the SimpleDB library at the same time

To co-develop `tanghoong/simpledb` locally, point Composer at your checkout by replacing the
`repositories` entry in `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../SimpleDB", "options": { "symlink": true } }
],
"require": { "tanghoong/simpledb": "@dev" }
```

Then `composer update tanghoong/simpledb`. Edits in `../SimpleDB` are picked up immediately.

---

## License

MIT.
