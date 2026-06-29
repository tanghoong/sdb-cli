# sdb

![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://img.shields.io/badge/tests-38%20passing-success)

**A standalone PHP CLI for persistent JSON document storage. Zero infrastructure.**
**一个独立的 PHP 命令行工具，用于持久化 JSON 文档存储。零基础设施。**

`sdb` is *jq meets sqlite3* — but you think in **documents**, not tables. Store a JSON
document, read it back, query a collection with filters and ordering, stream it as NDJSON,
import it back. No database server, no schema, no migrations — just a directory (or a single
SQLite file) under your home folder.

> `sdb` 就像「jq 遇上 sqlite3」——但你面对的是**文档**，而不是数据表。存入一个 JSON 文档、
> 读回来、用过滤和排序查询整个集合、以 NDJSON 流式导出、再导入回来。不需要数据库服务器、
> 不需要 schema、不需要迁移——只用你主目录下的一个文件夹（或一个 SQLite 文件）即可。

It is a thin CLI over the [`tanghoong/simpledb`](https://github.com/tanghoong/SimpleDB)
document-storage library. · 它是文档存储库 `tanghoong/simpledb` 之上的一层轻量命令行封装。

---

## Use cases · 使用场景

**EN — When `sdb` is the right tool:**

- **Scripts & CLI tools that need to remember things** — store config, job state, caches, or
  scraped data as JSON and query it later, without standing up Postgres/Mongo/Redis.
- **A config / feature-flag store you can edit from the shell** — `sdb put`, `sdb get`,
  `sdb find` instead of hand-editing JSON files.
- **Local prototyping & fixtures** — give a side project a real, queryable store in one line;
  seed test data with `sdb import`.
- **Data wrangling pipelines** — `sdb export | jq ... | sdb import`, or filter a collection
  down with `sdb find --ndjson` and pipe it onward.
- **Inspecting SimpleDB-backed app data** — because `sdb` shares the library's on-disk format,
  you can poke at an app's `~/.sdb` (or its SQLite file) straight from the terminal.
- **No-infra / shared hosting** — anywhere PHP runs, `sdb` runs. `pdo_sqlite` ships with PHP.

**中文 —— 什么时候该用 `sdb`：**

- **需要"记住"数据的脚本和命令行工具**——把配置、任务状态、缓存或抓取的数据以 JSON 存储，
  之后再查询，而无需搭建 Postgres / Mongo / Redis。
- **可以在命令行里直接编辑的配置 / 功能开关存储**——用 `sdb put`、`sdb get`、`sdb find`，
  而不是手动改 JSON 文件。
- **本地原型开发与测试数据**——一行命令就给小项目一个真正可查询的存储；用 `sdb import` 灌入测试数据。
- **数据处理流水线**——`sdb export | jq ... | sdb import`，或用 `sdb find --ndjson` 过滤集合后继续往下传。
- **查看由 SimpleDB 驱动的应用数据**——因为 `sdb` 与该库的磁盘格式一致，
  你可以直接在终端里查看应用的 `~/.sdb`（或它的 SQLite 文件）。
- **零基础设施 / 虚拟主机**——只要能跑 PHP 就能跑 `sdb`；`pdo_sqlite` 是 PHP 自带的。

When **not** to use it: high-concurrency multi-writer workloads, relational joins, or datasets
far beyond a single machine — reach for a real database there.
何时**不该**用：高并发多写入、需要关系型 JOIN，或远超单机的数据量——那些场景请用真正的数据库。

---

## Install · 安装

### Option A — download the phar (recommended) · 方式 A：下载 phar（推荐）

```bash
curl -L -o sdb.phar https://example.com/sdb.phar   # or build it yourself, see "Building"
chmod +x sdb.phar
sudo mv sdb.phar /usr/local/bin/sdb
sdb --version
```

A single self-contained executable. · 一个自包含的单文件可执行程序。

### Option B — Composer global · 方式 B：Composer 全局安装

```bash
composer global require tanghoong/sdb
# put ~/.composer/vendor/bin (or ~/.config/composer/vendor/bin) on your PATH
sdb --version
```

### Option C — from source · 方式 C：从源码运行

```bash
git clone https://github.com/tanghoong/sdb-cli
cd sdb-cli
composer install
php bin/sdb --version
```

**Requires · 环境要求:** PHP ≥ 8.2 with `ext-json` (always on). The `sqlite` and `memory`
adapters also need `ext-pdo_sqlite` (ships with PHP). · `sqlite` 和 `memory` 适配器还需要
`ext-pdo_sqlite`（PHP 自带）。

> **Dependency note · 依赖说明:** until `tanghoong/simpledb` is published to Packagist, this
> project pulls it from GitHub via a `repositories` entry in `composer.json`
> (`"tanghoong/simpledb": "dev-master"`). Once it's on Packagist, delete that block and pin a
> released version — no code changes needed. · 在 `tanghoong/simpledb` 发布到 Packagist 之前，
> 本项目通过 `composer.json` 的 `repositories` 从 GitHub 拉取它；发布后删掉该段并锁定正式版本即可。

---

## Try it in 30 seconds · 30 秒快速体验

A runnable demo (your "test page") seeds a throwaway store and runs every command, then cleans
up. · 一个可直接运行的演示（你的"测试页"），它会建立一个临时存储、跑一遍所有命令、然后清理掉。

```powershell
# Windows / PowerShell
pwsh examples/demo.ps1
```

```bash
# macOS / Linux / Git Bash
bash examples/demo.sh
```

It imports [`examples/products.ndjson`](examples/products.ndjson), then runs
`count`, `list`, `get`, `find`, and `export`. · 它会导入示例数据，然后依次运行
`count`、`list`、`get`、`find`、`export`。

---

## Quick start · 快速开始

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

sdb export users > users.ndjson      # back up · 备份
sdb import users-copy < users.ndjson # restore into another collection · 恢复到另一个集合
```

By default everything is stored under `~/.sdb/`. Override with `--data <dir>` or the
`SDB_DATA_DIR` environment variable. · 默认存储在 `~/.sdb/` 下；可用 `--data <目录>` 或环境变量
`SDB_DATA_DIR` 覆盖。

---

## Commands · 命令

Every command takes a `<collection>` plus the global flags below.
每个命令都接受一个 `<collection>`（集合名）以及下文的全局选项。

| Command | English | 中文 |
|---|---|---|
| `put <coll> <id> '<json>'` | Create or overwrite a document | 创建或覆盖一个文档 |
| `get <coll> <id>` | Read one document | 读取一个文档 |
| `delete <coll> <id>` | Delete a document (aliases: `del`, `rm`) | 删除一个文档（别名 `del`、`rm`） |
| `list <coll>` | List all document IDs (alias: `ls`) | 列出所有文档 ID（别名 `ls`） |
| `find <coll> [filters]` | Query with filters, ordering, pagination | 带过滤、排序、分页的查询 |
| `count <coll> [--where ...]` | Count documents, optionally filtered | 统计文档数量，可加过滤 |
| `export <coll>` | Stream the collection to stdout as NDJSON | 以 NDJSON 流式导出到标准输出 |
| `import <coll>` | Load NDJSON from stdin (or a file) | 从标准输入（或文件）导入 NDJSON |

Run `sdb <command> --help` for full per-command help. · 用 `sdb <命令> --help` 查看每个命令的详细帮助。

### Notes on individual commands · 各命令要点

- **put** — the JSON body must be a single object. `_id` is **reserved** (see below) and
  rejected. · JSON 内容必须是一个对象；`_id` 是**保留字段**，会被拒绝。
- **get / delete** — exit `1` if the document does not exist. · 文档不存在时退出码为 `1`。
- **find** — each result carries its storage id in an injected `_id` field; supports `--raw`
  and `--ndjson`. · 每条结果会注入一个 `_id` 字段表示存储 ID；支持 `--raw` 和 `--ndjson`。
- **export / import** — see the NDJSON format note below. · 见下文 NDJSON 格式说明。

```bash
sdb import users --from users.ndjson     # load from a file (handy on Windows) · 从文件导入（Windows 上更方便）
sdb export users | sdb import users-copy  # clone a collection · 克隆一个集合
```

> **`_id` is reserved · `_id` 是保留字段.** It always denotes the storage id in `find`/`export`
> output. You cannot `put` a document containing an `_id` field (it's a usage error), so a
> stored document never collides with the injected id — that's what keeps export→import
> **lossless**. · 它在 `find`/`export` 输出中始终表示存储 ID。你不能 `put` 一个含有 `_id`
> 字段的文档（会报使用错误），因此存储的文档永远不会与注入的 ID 冲突——这正是导出→导入**无损**的保证。

---

## Query syntax · 查询语法

`--where` accepts two forms · `--where` 接受两种写法：

```text
--where field=value          # equality shorthand · 相等简写
--where field:op:value       # explicit operator · 显式操作符
--where field:null           # unary operators take no value · 一元操作符不带值
```

**Operators · 操作符:** `=` `!=` `>` `>=` `<` `<=` `in` `not_in` `contains` `starts_with`
`ends_with` `null` `not_null`. Nested fields use dot-notation: `--where shipping.country=MY`.
嵌套字段用点号：`--where shipping.country=MY`。

`in` / `not_in` take a comma-separated list · 接受逗号分隔的列表:
`--where 'status:in:new,paid,shipped'`.

### Shell-safe operator aliases · 适配 Shell 的操作符别名

`<` and `>` are redirection characters in every shell (and **PowerShell mangles them even
inside quotes**). Use these word aliases to avoid quoting entirely:

`<` 和 `>` 在所有 shell 里都是重定向符号（而且 **PowerShell 即使加引号也会破坏它们**）。
用下面的单词别名就完全不用加引号：

| Symbol · 符号 | Alias · 别名 |
|---|---|
| `=`  | `eq` |
| `!=` | `ne` |
| `<`  | `lt` |
| `<=` | `lte` |
| `>`  | `gt` |
| `>=` | `gte` |

```bash
sdb find products --where price:lt:500 --order price:asc   # works in PowerShell, cmd, bash
sdb find products --where 'price:<:500'                     # same thing; quote it in bash
```

### Value typing · 值的类型推断

Because the matcher uses strict comparison, CLI values are coerced so they match JSON
numbers/booleans: `300` → int, `1.5` → float, `true`/`false` → bool, `null` → null; everything
else stays a string. Coercion **only happens when the value round-trips exactly**, so id-like
strings keep their identity — a leading-zero value (`00123`) or an integer beyond `PHP_INT_MAX`
stays a **string**. The substring operators `contains`/`starts_with`/`ends_with` are never coerced.

由于匹配使用严格比较，命令行的值会被推断类型以匹配 JSON 的数字 / 布尔值：`300` → 整数、
`1.5` → 浮点、`true`/`false` → 布尔、`null` → null；其余保持字符串。**只有在能够精确还原时才会推断**，
因此像 ID 的字符串能保持原样——带前导零的 `00123` 或超过 `PHP_INT_MAX` 的整数会**保持字符串**。
子串操作符 `contains`/`starts_with`/`ends_with` 永远不做类型推断。

`--order` accepts `field` (ascending) or `field:asc` / `field:desc`, and is repeatable for
multi-key sorts. · `--order` 接受 `field`（升序）或 `field:asc` / `field:desc`，可重复以做多键排序。

---

## Global flags & adapters · 全局选项与适配器

| Flag / env | Default | Description · 说明 |
|---|---|---|
| `--adapter file\|sqlite\|memory` | `file` | Storage backend · 存储后端 |
| `--data <dir>` | — | Storage directory (overrides everything below) · 存储目录（优先级最高） |
| `SDB_DATA_DIR` | — | Storage directory (env var) · 存储目录（环境变量） |
| *(fallback)* | `~/.sdb` | Default storage root · 默认存储根目录 |
| `--raw` | off | Compact, single-line JSON · 紧凑的单行 JSON |
| `--ndjson` | off | Newline-delimited JSON (streams / lists) · 按行分隔的 JSON（流 / 列表） |

| Adapter | Where data lives · 数据位置 | Notes · 说明 |
|---|---|---|
| `file` (default) | `<dataDir>/<collection>/<id>.json` | One JSON file per document. Works anywhere. · 每个文档一个 JSON 文件，到处可用。 |
| `sqlite` | `<dataDir>/sdb.sqlite` | All collections in one WAL-mode SQLite file; filters push down to SQL. · 所有集合放在一个 WAL 模式的 SQLite 文件；过滤下推到 SQL。 |
| `memory` | `:memory:` | Ephemeral — lasts only for a single command. Good for tests. · 临时的，仅在单条命令期间存在，适合测试。 |

---

## Performance · 性能

`sdb` is built to stay fast as collections grow · 随着集合增大依然保持高效：

- **`list` never reads documents** — it uses a directory scan (`file`) or `SELECT id`
  (`sqlite`), so listing IDs is O(entries), not O(read every doc).
  **`list` 不读取文档**——`file` 用目录扫描、`sqlite` 用 `SELECT id`，因此列出 ID 是 O(条目数)，而非读取每个文档。
- **`import` batches writes** — documents are committed in chunks (one transaction per 1000
  on `sqlite`) instead of one transaction per row. Measured **~17× faster** for bulk sqlite imports.
  **`import` 批量写入**——按块提交（`sqlite` 每 1000 条一个事务），而不是每行一个事务；实测批量导入 sqlite **快约 17 倍**。
- **`find` / `count` push down to SQL** on the `sqlite` adapter — `WHERE`, `ORDER BY`,
  `LIMIT`/`OFFSET`, and `COUNT(*)` run as a single `json_extract`-powered query; no documents
  are loaded into PHP. · 在 `sqlite` 适配器上，`find`/`count` 下推为单条 SQL 查询，不把文档载入 PHP。
- **Stream large result sets with `--ndjson`** instead of buffering a whole JSON array in memory.
  **用 `--ndjson` 流式输出大结果集**，避免把整个 JSON 数组缓存在内存里。
- **Repeated invocations?** Enable the OPcache CLI file cache to cut PHP's per-process startup:
  **频繁调用？** 开启 OPcache CLI 文件缓存以降低每次进程启动开销：

  ```ini
  ; php.ini
  opcache.enable_cli=1
  opcache.file_cache=/tmp/php-opcache
  ```

---

## Exit codes · 退出码

| Code | Meaning · 含义 |
|---|---|
| `0` | Success · 成功 |
| `1` | Document / collection not found · 文档 / 集合不存在 |
| `2` | Usage error (bad flag, malformed `--where`, unknown command) · 使用错误（参数错误、`--where` 格式错误、未知命令） |
| `3` | Storage error (I/O failure, missing extension, corrupt data) · 存储错误（I/O 失败、缺少扩展、数据损坏） |

```bash
if sdb get users alice >/dev/null 2>&1; then echo "exists"; else echo "missing"; fi
```

---

## Building the phar · 构建 phar

```bash
make phar        # strips dev deps, builds ./sdb.phar, restores dev deps · 去掉开发依赖、构建、再恢复
```

No `make` (e.g. Windows)? Run the steps directly · 没有 `make`（例如 Windows）？直接运行：

```bash
composer install --no-dev --optimize-autoloader
php -d phar.readonly=0 build/build-phar.php
composer install        # restore dev deps for testing · 恢复开发依赖以便测试
```

The build needs `phar.readonly=Off`; the `-d phar.readonly=0` flag sets it just for that run.
构建需要 `phar.readonly=Off`，`-d phar.readonly=0` 只为该次运行临时设置。

---

## Development · 开发

```bash
composer install
make test            # or: php vendor/bin/phpunit   ·   38 tests
php bin/sdb --help
```

### Co-developing the SimpleDB library · 同时开发 SimpleDB 库

To hack on `tanghoong/simpledb` locally, point Composer at your checkout by replacing the
`repositories` entry in `composer.json` · 要在本地修改该库，把 `composer.json` 的 `repositories` 改为：

```json
"repositories": [
    { "type": "path", "url": "../SimpleDB", "options": { "symlink": true } }
],
"require": { "tanghoong/simpledb": "@dev" }
```

Then `composer update tanghoong/simpledb`. Edits in `../SimpleDB` are picked up immediately.
然后执行 `composer update tanghoong/simpledb`；`../SimpleDB` 里的改动会立即生效。

---

## License · 许可证

MIT.
