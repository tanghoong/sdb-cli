# sdb — Code Review & Benchmark

Security + performance review of the `sdb` CLI and the bundled `tanghoong/simpledb`
storage library, plus reproducible benchmark numbers measured on a known machine.

> Reproduce: `composer install && php benchmarks/bench.php 50000`

---

## 1. Security review

### Verdict

The storage layer is **well-hardened** for a local single-user CLI. The two classic
risks for a "name → file path → SQL" tool — path traversal and query injection — are
both closed. Probes confirmed it:

| Probe | Result |
|---|---|
| `put users '../escape' …` | rejected — `Invalid name '../escape'` |
| `put '../../etc' k …` | rejected — invalid collection name |
| `put users alice '{"_id":"x"}'` | rejected — `_id` is reserved |
| `find p --where "a');DROP TABLE documents;--=1"` (sqlite) | returns `[]`, table intact |

### What protects it

- **Name allow-list.** `FileAdapter` and `SqliteAdapter` both run every collection
  name and document id through `^[a-zA-Z0-9_-]+$`, so `/`, `..`, `\`, NUL, etc. can
  never reach a path or a column. (`vendor/.../FileAdapter.php:52`, `SqliteAdapter.php:390`)
- **Defence in depth on the filesystem.** `FileAdapter` additionally `realpath()`s the
  resolved path and verifies it is still under the storage root (`verifyWithinRoot`),
  catching symlink escapes even if the allow-list were bypassed.
- **Parameterised SQL throughout.** Every value *and every json path* is a bound PDO
  parameter — `json_extract(data, ?)` — so field names from `--where`/`--order` cannot
  inject. `ORDER BY` direction is validated against `asc|desc` before interpolation.
  (`SqliteAdapter.php:516`, `:683`)
- **Atomic, locked writes.** `FileAdapter::write` takes an exclusive `flock`, writes a
  temp file, `fflush`es, then `rename()`s — no torn reads, concurrent writers serialised.
- **Resource bound.** 5 MiB max document size on both adapters guards against a single
  oversized blob exhausting memory.
- **Strict CLI input typing.** `--limit`/`--offset` are integer-validated and rejected if
  negative; the `_id` reserved-field rule keeps export→import lossless.

### Findings (low severity, by design or local-trust)

1. **Unpinned, abandoned dependency (supply-chain).** `composer.json` requires
   `tanghoong/simpledb: dev-master` with `minimum-stability: dev`, and Composer reports
   the package as **abandoned**. `composer.lock` pins commit `b8cb57a`, so installs are
   reproducible *as long as the lock file is committed and honoured* — but any
   `composer update` silently pulls whatever `master` is at that moment. Recommend
   tagging a release and pinning a version constraint (`^1.0`).
2. **No query/output size ceiling.** `find`/`export`/`count` accept an unbounded
   collection. On the **file** adapter a filtered `find`/`count` reads *every* document
   (see perf below); a hostile or accidental 10M-doc collection is a local DoS. For a
   single-user CLI this is acceptable, but worth a doc note ("use `--limit`, prefer the
   sqlite adapter for large query workloads").
3. **Lock-file / temp-file accumulation (file adapter).** Each written id leaves a
   persistent `<id>.json.lock` next to it, and `tempnam` temp files are only cleaned on
   the failure path. They are correctly excluded from `listIds`, but they double the
   inode count of a large collection. Library-side cleanup would help.
4. **Error messages echo raw user input** inside Symfony's `<error>…</error>` markup
   (e.g. the rejected name). Cosmetic only — no escaping of pseudo-tags — not exploitable
   for a local terminal, but worth `OutputFormatter::escape()` for polish.
5. **Placeholder install URL.** README's `curl … https://example.com/sdb.phar` has no
   checksum/signature step; once a real download URL exists, publish a SHA-256.

None of these are blockers for the stated use case (local, single-user, no-infra store).

---

## 2. Performance review

### Strengths (verified)

- **`list` never reads documents** — directory scan / `SELECT id`. ~5.6M ids/s on sqlite,
  ~625K/s on file at 50K docs.
- **`count` and filtered `find`/`count` push down to SQL** on the sqlite adapter — a
  single `json_extract` query, no documents marshalled into PHP.
- **`import` batches** 1000 rows per transaction — one fsync per chunk, not per row.
- **`export` truly streams** via a generator on both adapters — 50K docs at ~48 MiB RSS.

### Findings

1. **`find` buffers the whole result set — even with `--ndjson`.** `QueryBuilder::get()`
   returns a fully-materialised array; `FindCommand` then copies it into a second `$docs`
   array (to inject `_id`) before `writeList` iterates. So `--ndjson` on `find` does **not**
   stream — contrary to the README's "stream large result sets with `--ndjson`" claim,
   which only holds for `export`. Impact: `find` with no `--limit` holds the entire match
   set (plus a copy) in memory. *Mitigation:* document that `find` is bounded by `--limit`
   and use `export` for whole-collection streaming; longer term, a streaming `find` would
   need a generator-returning `get()` in the library.
2. **File adapter filtered queries are O(N) full reads.** `find`/`count --where` on the
   file adapter read and JSON-decode *every* document in PHP. At 50K docs that's ~660 ms
   for a filtered count vs ~36 ms on sqlite (~18× slower) — and it scales linearly. The
   file adapter is fine for storage and `get`/`list`, but **the sqlite adapter is the
   right choice for any query-heavy workload.** This is inherent to one-file-per-doc; the
   value is making it explicit.
3. **CLI cold-start ≈ 43 ms/invocation** (autoloader + Symfony Console boot), and
   enabling `opcache.file_cache` made **no measurable difference** here. So a shell loop
   of N `sdb` calls pays ~43 ms × N. For bulk work, prefer one `import`/`export` over
   per-document `put`/`get` in a loop.

---

## 3. Benchmark — measured numbers

### Machine under test

| | |
|---|---|
| CPU | Intel Xeon @ 2.80 GHz, 4 vCPU |
| RAM | 15 GiB |
| Disk | virtio (`/dev/vda`), SSD-backed |
| OS / kernel | Linux 6.18.x |
| PHP | 8.4.19 (NTS), `pdo_sqlite` on, `opcache.enable_cli=0` |
| Dataset | 50,000 synthetic product docs (~180 B JSON each, one nested object) |

> These are **single-process, warm-disk** numbers from `benchmarks/bench.php`. Treat them
> as relative throughput, not a datacenter SLA; spinning disk or networked storage will be
> markedly slower on the file adapter's per-document I/O.

### sqlite adapter

| Operation | Time | Throughput | Peak RSS |
|---|--:|--:|--:|
| import (batchPut, 1000/txn) | 654 ms | ~76K docs/s | 48 MiB |
| count (no filter) | 1.9 ms | O(1) | 50 MiB |
| count `--where status=paid` | 36 ms | full scan, 50K | 50 MiB |
| find `price<500 order:asc limit:10` | 39 ms | 10 returned | 50 MiB |
| find `shipping.country=MY` (12.5K hits) | 72 ms | ~174K docs/s | 68 MiB |
| export (stream all) | 93 ms | ~541K docs/s | 68 MiB |
| list ids | 9 ms | ~5.6M docs/s | 68 MiB |
| get single (cold cache) ×1000 | 11 ms | ~90K gets/s | 68 MiB |

### file adapter

| Operation | Time | Throughput | Peak RSS |
|---|--:|--:|--:|
| import (batchPut) | 8341 ms | ~6K docs/s | 68 MiB |
| count (no filter) | 68 ms | dir scan | 74 MiB |
| count `--where status=paid` | 664 ms | O(N) reads | 74 MiB |
| find `price<500 order:asc limit:10` | 852 ms | O(N) reads | 92 MiB |
| find `shipping.country=MY` (12.5K hits) | 614 ms | ~20K docs/s | 92 MiB |
| export (stream all) | 554 ms | ~90K docs/s | 92 MiB |
| list ids | 80 ms | ~625K docs/s | 92 MiB |
| get single (cold cache) ×1000 | 12 ms | ~84K gets/s | 92 MiB |

### Headline ratios (50K docs, this machine)

- **Bulk import: sqlite ~13× faster** than file (654 ms vs 8.3 s) — one fsync/1000 rows
  beats 50K individual file create+lock+rename cycles.
- **Filtered count/find: sqlite ~18× faster** than file (SQL push-down vs full scan).
- **Point ops (`get`, `list`)** are comparable on both — neither parses the whole set.
- **Plus ~43 ms fixed cost** per CLI invocation regardless of adapter.

### Rule of thumb for capacity

On hardware like the above, the sqlite adapter comfortably handles **hundreds of
thousands to low millions of documents** with sub-second filtered queries (single
`json_extract` scan, ~28 docs/ms here → ~1.7 s for a 50× larger 2.5M-doc full scan,
faster with selective filters). The file adapter is best kept to the **low tens of
thousands** if you run filtered `find`/`count`; beyond that, switch to sqlite.
