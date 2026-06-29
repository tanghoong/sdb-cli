<?php

declare(strict_types=1);

namespace Sdb\Tests;

final class ExportImportTest extends AbstractCliTestCase
{
    public function test_export_emits_ndjson_with_id(): void
    {
        $this->put('users', 'alice', ['name' => 'Alice']);
        $this->put('users', 'bob', ['name' => 'Bob']);

        $export = $this->sdb(['command' => 'export', 'collection' => 'users']);
        self::assertSame(0, $export['code']);

        $lines = array_filter(explode("\n", trim($export['out'])));
        self::assertCount(2, $lines);

        $byId = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('_id', $row);
            $byId[$row['_id']] = $row['name'];
        }
        self::assertSame(['Alice', 'Bob'], [$byId['alice'], $byId['bob']]);
    }

    public function test_export_import_round_trip_preserves_ids_and_data(): void
    {
        $this->put('src', 'alice', ['name' => 'Alice', 'age' => 30]);
        $this->put('src', 'bob', ['name' => 'Bob', 'age' => 25]);

        $export = $this->sdb(['command' => 'export', 'collection' => 'src']);
        $ndjson = $this->dataDir . DIRECTORY_SEPARATOR . 'dump.ndjson';
        file_put_contents($ndjson, $export['out']);

        $import = $this->sdb(['command' => 'import', 'collection' => 'dst', '--from' => $ndjson]);
        self::assertSame(0, $import['code']);
        self::assertSame('2', trim($import['out']));

        // The destination must be identical, id for id.
        $alice = $this->sdb(['command' => 'get', 'collection' => 'dst', 'id' => 'alice']);
        self::assertSame(['name' => 'Alice', 'age' => 30], $this->decode($alice['out']));

        $bob = $this->sdb(['command' => 'get', 'collection' => 'dst', 'id' => 'bob']);
        self::assertSame(['name' => 'Bob', 'age' => 25], $this->decode($bob['out']));
    }

    public function test_import_without_id_generates_one(): void
    {
        $ndjson = $this->dataDir . DIRECTORY_SEPARATOR . 'noid.ndjson';
        file_put_contents($ndjson, "{\"name\":\"X\"}\n{\"name\":\"Y\"}\n");

        $import = $this->sdb(['command' => 'import', 'collection' => 'gen', '--from' => $ndjson]);
        self::assertSame(0, $import['code']);
        self::assertSame('2', trim($import['out']));

        $count = $this->sdb(['command' => 'count', 'collection' => 'gen']);
        self::assertSame('2', trim($count['out']));
    }

    public function test_import_skips_blank_lines(): void
    {
        $ndjson = $this->dataDir . DIRECTORY_SEPARATOR . 'blanks.ndjson';
        file_put_contents($ndjson, "\n{\"_id\":\"a\",\"v\":1}\n\n\n{\"_id\":\"b\",\"v\":2}\n\n");

        $import = $this->sdb(['command' => 'import', 'collection' => 'c', '--from' => $ndjson]);
        self::assertSame('2', trim($import['out']));
    }

    public function test_import_malformed_line_is_usage_error(): void
    {
        $ndjson = $this->dataDir . DIRECTORY_SEPARATOR . 'bad.ndjson';
        file_put_contents($ndjson, "{\"_id\":\"a\",\"v\":1}\n{ broken\n");

        $import = $this->sdb(['command' => 'import', 'collection' => 'c', '--from' => $ndjson]);
        self::assertSame(2, $import['code']);
        self::assertStringContainsString('Line 2', $import['err']);
    }

    public function test_import_empty_id_is_usage_error(): void
    {
        $ndjson = $this->dataDir . DIRECTORY_SEPARATOR . 'emptyid.ndjson';
        file_put_contents($ndjson, "{\"_id\":\"\",\"v\":1}\n");

        $import = $this->sdb(['command' => 'import', 'collection' => 'c', '--from' => $ndjson]);
        self::assertSame(2, $import['code']);
        self::assertStringContainsString("'_id' must be a non-empty", $import['err']);
    }

    public function test_import_overlong_line_is_usage_error(): void
    {
        // A single line just over the 16 MiB cap must be rejected (exit 2) rather
        // than read unboundedly into memory. A valid first line confirms the
        // reader gets that far before the oversized second line trips the limit.
        $ndjson  = $this->dataDir . DIRECTORY_SEPARATOR . 'huge.ndjson';
        $oversized = '{"_id":"big","v":"' . str_repeat('x', 16 * 1024 * 1024 + 1024) . '"}';
        file_put_contents($ndjson, "{\"_id\":\"a\",\"v\":1}\n" . $oversized . "\n");

        $import = $this->sdb(['command' => 'import', 'collection' => 'c', '--from' => $ndjson]);
        self::assertSame(2, $import['code']);
        self::assertStringContainsString('Line 2', $import['err']);
        self::assertStringContainsString('maximum line length', $import['err']);
    }

    public function test_import_missing_file_is_usage_error(): void
    {
        $import = $this->sdb([
            'command'    => 'import',
            'collection' => 'c',
            '--from'     => $this->dataDir . DIRECTORY_SEPARATOR . 'does-not-exist.ndjson',
        ]);
        self::assertSame(2, $import['code']);
        self::assertStringContainsString('Cannot open file', $import['err']);
    }
}
