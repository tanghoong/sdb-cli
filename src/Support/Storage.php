<?php

declare(strict_types=1);

namespace Sdb\Support;

use Sdb\Exception\UsageException;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Adapters\SqliteAdapter;
use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\StorageException;
use SimpleDB\SimpleDB;

/**
 * Resolves the requested adapter + storage location into a SimpleDB instance.
 *
 * Storage layout:
 *   file    →  <dataDir>/<collection>/<id>.json   (one file per document)
 *   sqlite  →  <dataDir>/sdb.sqlite               (all collections, one file)
 *   memory  →  :memory:                           (ephemeral, single invocation)
 */
final class Storage
{
    public const ADAPTERS = ['file', 'sqlite', 'memory'];

    /**
     * @throws UsageException  on an unknown adapter or a sqlite request without pdo_sqlite
     * @throws StorageException on a storage-level failure (e.g. unwritable directory)
     */
    public static function open(string $collection, string $adapter, ?string $dataDir): SimpleDB
    {
        return new SimpleDB($collection, self::adapter($adapter, $dataDir));
    }

    /**
     * Build just the storage adapter — used by commands (e.g. `list`) that can
     * call a cheap StorageInterface method directly without a SimpleDB wrapper.
     *
     * @throws UsageException|StorageException
     */
    public static function adapter(string $adapter, ?string $dataDir): StorageInterface
    {
        return self::makeAdapter($adapter, self::resolveDataDir($dataDir));
    }

    private static function makeAdapter(string $adapter, string $dataDir): StorageInterface
    {
        return match ($adapter) {
            'file'   => new FileAdapter($dataDir),
            'sqlite' => new SqliteAdapter(self::sqliteFile($dataDir)),
            'memory' => self::requireSqlite() ? new SqliteAdapter(':memory:') : throw self::sqliteMissing(),
            default  => throw new UsageException(
                "Unknown adapter '{$adapter}'. Use one of: " . implode(', ', self::ADAPTERS) . '.'
            ),
        };
    }

    private static function sqliteFile(string $dataDir): string
    {
        if (!self::requireSqlite()) {
            throw self::sqliteMissing();
        }

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
            throw new StorageException("Cannot create storage directory: {$dataDir}");
        }

        return rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'sdb.sqlite';
    }

    /**
     * Determine the storage root, in priority order:
     *   1. explicit --data flag
     *   2. $SDB_DATA_DIR
     *   3. ~/.sdb
     */
    public static function resolveDataDir(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $env = getenv('SDB_DATA_DIR');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.sdb';
    }

    private static function requireSqlite(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    private static function sqliteMissing(): UsageException
    {
        return new UsageException(
            'The sqlite/memory adapter needs the pdo_sqlite extension, which is not loaded. '
            . 'Install it or use --adapter file.'
        );
    }
}
