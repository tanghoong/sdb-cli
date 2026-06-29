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

### Findings (all addressed)

1. **Unpinned, abandoned dependency (supply-chain) — RESOLVED.** The project used to
   require `tanghoong/simpledb: dev-master` (Composer-flagged **abandoned**) pulled from a
   GitHub `repositories` entry under `minimum-stability: dev`. The MIT-licensed storage
   engine is now **vendored in-tree** at [`lib/simpledb/`](../lib/simpledb) and autoloaded
   via a `SimpleDB\` PSR-4 mapping. `composer.json` has no external/unpublished package, no
   `repositories` block, and no dev stability — `symfony/console` is the only runtime dep.
2. **No query/output size ceiling — DOCUMENTED.** `find`/`export`/`count` accept an
   unbounded collection; on the **file** adapter a filtered `find`/`count` reads *every*
   document (see perf below). `export` streams in constant memory and `find` now streams its
   `--ndjson` output, so the residual risk is CPU on file-adapter full scans — called out in
   the README with the guidance to use `--limit` and prefer the sqlite adapter at scale.
3. **Lock-file accumulation (file adapter) — RESOLVED.** `FileAdapter::write` used a
   per-document `<id>.json.lock` (one permanent inode per id ever written). It now uses a
   single reusable per-collection `.write.lock`; atomic `rename()` still guarantees
   integrity, and the lock is excluded from `listIds`. Verified: 5 writes → 1 lock file.
4. **Error messages echoed raw user input — RESOLVED.** `SdbApplication::fail` and the
   `get`/`delete` not-found messages now pass user-supplied names through
   `OutputFormatter::escape()`, so a name like `</error><info>x` renders literally.
5. **Placeholder install URL — RESOLVED.** The README install step now points at the GitHub
   release asset and verifies a published `sdb.phar.sha256` before installing.

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

1. **`find` double-buffered its result set (fixed).** `QueryBuilder::get()` returns a
   fully-materialised array, and `FindCommand` used to copy it into a second `$docs` array
   (to inject `_id`) before `writeList` iterated — so `--ndjson` held two full copies.
   `FindCommand` now injects `_id` through a generator, so the `--ndjson` path writes each
   row as it is produced and only the single array from `get()` is held. The remaining
   materialisation lives in the library's `get()`; a *fully* lazy `find` (no copy at all)
   would need a generator-returning `get()` upstream. For whole-collection streaming with
   constant memory, `export` remains the right tool, and `find` is bounded by `--limit`.
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
> markedly slower on the file adapter's per-document I/O. Time figures vary ±20% run to
> run (filesystem caching); memory figures are stable.
>
> **Memory column = `+MiB`**, the increment in true peak allocation *for that operation*
> over a fixed **48 MiB resident baseline** (the prebuilt 50K-doc dataset that stays in
> memory for the whole run). The harness calls `memory_reset_peak_usage()` before each
> operation, so each row measures what that operation allocated — not a process-lifetime
> high-water mark. `+0.0 MiB` means the operation ran in constant memory (streaming / O(1)).

### sqlite adapter

| Operation | Time | Throughput | +MiB |
|---|--:|--:|--:|
| import (batchPut, 1000/txn) | 653 ms | ~77K docs/s | +0.0 |
| count (no filter) | 1.8 ms | O(1) | +0.0 |
| count `--where status=paid` | 39 ms | full scan, 50K | +0.0 |
| find `price<500 order:asc limit:10` | 40 ms | 10 returned | +0.0 |
| find `shipping.country=MY` (12.5K hits) | 70 ms | ~178K docs/s | +20.0 |
| export (stream all) | 89 ms | ~560K docs/s | +0.0 |
| list ids | 8 ms | ~6.2M docs/s | +0.0 |
| get single (cold cache) ×1000 | 11 ms | ~91K gets/s | +0.0 |

### file adapter

| Operation | Time | Throughput | +MiB |
|---|--:|--:|--:|
| import (batchPut) | 5044 ms | ~10K docs/s | +0.0 |
| count (no filter) | 69 ms | dir scan | +6.0 |
| count `--where status=paid` | 700 ms | O(N) reads | +4.0 |
| find `price<500 order:asc limit:10` | 959 ms | O(N) reads + sort | +22.0 |
| find `shipping.country=MY` (12.5K hits) | 699 ms | ~18K docs/s | +2.0 |
| export (stream all) | 569 ms | ~88K docs/s | +2.0 |
| list ids | 79 ms | ~631K docs/s | +2.0 |
| get single (cold cache) ×1000 | 12 ms | ~85K gets/s | +0.0 |

### Headline ratios (50K docs, this machine)

- **Bulk import: sqlite ~8–13× faster** than file (≈0.65 s vs 5–8 s; file time swings with
  fsync/filesystem state) — one fsync/1000 rows beats 50K individual create+lock+rename cycles.
- **Filtered count/find: sqlite ~18× faster** than file (SQL push-down vs full scan).
- **Point ops (`get`, `list`)** are comparable on both — neither parses the whole set.
- **Memory:** `export`/`list`/`count` run in constant memory on both adapters; the cost of
  a large or ordered `find` is visible as a +20 MiB materialised result set (the only ops
  that buffer) — which is exactly finding #1 above.
- **Plus ~43 ms fixed cost** per CLI invocation regardless of adapter.

### Rule of thumb for capacity

On hardware like the above, the sqlite adapter comfortably handles **hundreds of
thousands to low millions of documents** with sub-second filtered queries (single
`json_extract` scan, ~28 docs/ms here → ~1.7 s for a 50× larger 2.5M-doc full scan,
faster with selective filters). The file adapter is best kept to the **low tens of
thousands** if you run filtered `find`/`count`; beyond that, switch to sqlite.
