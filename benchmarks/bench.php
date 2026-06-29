<?php

declare(strict_types=1);

/**
 * sdb micro-benchmark harness.
 *
 * Exercises the hot paths of both query-capable adapters (sqlite, file) against
 * a synthetic product collection, reporting wall-clock time, throughput, and
 * peak RSS for each operation.
 *
 * Usage:
 *   php benchmarks/bench.php [N]      # N = document count, default 50000
 *
 * It writes to a throwaway temp directory and cleans up afterwards, so it never
 * touches your real ~/.sdb store.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Adapters\SqliteAdapter;
use SimpleDB\SimpleDB;

function bench(string $label, callable $fn): void
{
    // memory_get_peak_usage() is a high-water mark since process start, so it must
    // be reset before each operation — otherwise every row inherits the peak left
    // by the prebuilt dataset and earlier imports/finds. We report the increment
    // over the pre-op baseline (which already holds the resident $docs array), so
    // the figure reflects what *this* operation allocated.
    memory_reset_peak_usage();
    $base = memory_get_usage(true);

    $t0    = hrtime(true);
    $n     = $fn();
    $ms    = (hrtime(true) - $t0) / 1e6;
    $delta = max(0, memory_get_peak_usage(true) - $base) / 1048576;

    printf(
        "  %-40s %9.1f ms  %9s docs  %11s docs/s  +%.1f MiB\n",
        $label,
        $ms,
        number_format($n),
        number_format($n / max($ms / 1000, 1e-9)),
        $delta,
    );
}

function makeDoc(int $i): array
{
    return [
        'name'     => 'Product ' . $i,
        'price'    => ($i % 1000) + 0.5,
        'status'   => ['new', 'paid', 'shipped', 'cancelled'][$i % 4],
        'tags'     => ['a', 'b', 'c'],
        'shipping' => ['country' => ['MY', 'SG', 'US', 'JP'][$i % 4], 'weight' => $i % 50],
        'desc'     => str_repeat('lorem ipsum ', 8),
    ];
}

$N   = (int) ($argv[1] ?? 50000);
$tmp = sys_get_temp_dir() . '/sdb-bench-' . bin2hex(random_bytes(4));
mkdir($tmp, 0750, true);

printf("=== sdb benchmark — N=%s documents ===\n", number_format($N));
printf("PHP %s   opcache.enable_cli=%s\n\n", PHP_VERSION, ini_get('opcache.enable_cli') ?: '0');

$docs = [];
for ($i = 0; $i < $N; $i++) {
    $docs[(string) $i] = makeDoc($i);
}

// The prebuilt dataset stays resident for the whole run; the per-op "+MiB"
// columns are measured on top of this floor.
printf(
    "resident baseline (prebuilt dataset): %.1f MiB\n\n",
    memory_get_usage(true) / 1048576,
);

foreach (['sqlite', 'file'] as $adapter) {
    printf("--- adapter: %s ---\n", $adapter);

    $dir = $tmp . '/' . $adapter;
    mkdir($dir, 0750, true);

    $storage = $adapter === 'sqlite'
        ? new SqliteAdapter($dir . '/sdb.sqlite')
        : new FileAdapter($dir);
    $db = new SimpleDB('products', $storage);

    bench('import (batchPut, 1000/txn)', function () use ($db, $docs): int {
        $buf = [];
        $c   = 0;
        foreach ($docs as $id => $d) {
            $buf[$id] = $d;
            $c++;
            if (count($buf) >= 1000) {
                $db->batchPut($buf);
                $buf = [];
            }
        }
        if ($buf) {
            $db->batchPut($buf);
        }
        return $c;
    });

    $db->clearCache();
    bench('count (no filter)', function () use ($db): int { $db->count(); return 1; });

    $db->clearCache();
    bench('count --where status=paid', function () use ($db): int {
        $db->newQuery()->where('status', '=', 'paid')->count();
        return 1;
    });

    $db->clearCache();
    bench('find price<500 order:asc limit:10', function () use ($db): int {
        $r = $db->newQuery()->where('price', '<', 500.0)->orderBy('price', 'asc')->limit(10)->get();
        return count($r) ?: 1;
    });

    $db->clearCache();
    bench('find shipping.country=MY (no limit)', function () use ($db): int {
        return count($db->newQuery()->where('shipping.country', '=', 'MY')->get());
    });

    $db->clearCache();
    bench('export (stream all)', function () use ($db): int {
        $c = 0;
        foreach ($db->stream() as $d) {
            $c++;
        }
        return $c;
    });

    $db->clearCache();
    bench('list ids', fn (): int => count($storage->listIds('products')));

    $db->clearCache();
    bench('get single (cold cache) x1000', function () use ($db, $N): int {
        for ($k = 0; $k < 1000; $k++) {
            $db->clearCache();
            $db->get((string) (($k * 7919) % $N));
        }
        return 1000;
    });

    echo "\n";
}

exec('rm -rf ' . escapeshellarg($tmp));
echo "done.\n";
