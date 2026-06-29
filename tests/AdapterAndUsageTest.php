<?php

declare(strict_types=1);

namespace Sdb\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class AdapterAndUsageTest extends AbstractCliTestCase
{
    public function test_unknown_adapter_is_usage_error(): void
    {
        $r = $this->sdb(['command' => 'count', 'collection' => 'x'], adapter: 'redis');
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('Unknown adapter', $r['err']);
    }

    public function test_unknown_command_is_usage_error(): void
    {
        $r = $this->sdb(['command' => 'frobnicate', 'collection' => 'x']);
        self::assertSame(2, $r['code']);
    }

    public function test_missing_required_argument_is_usage_error(): void
    {
        // `get` requires an <id> argument.
        $r = $this->sdb(['command' => 'get', 'collection' => 'users']);
        self::assertSame(2, $r['code']);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function test_sqlite_adapter_round_trip(): void
    {
        $put = $this->sdb([
            'command'    => 'put',
            'collection' => 'k',
            'id'         => 'one',
            'json'       => '{"v":1}',
        ], adapter: 'sqlite');
        self::assertSame(0, $put['code'], $put['err']);

        $get = $this->sdb(['command' => 'get', 'collection' => 'k', 'id' => 'one'], adapter: 'sqlite');
        self::assertSame(['v' => 1], $this->decode($get['out']));

        // The sqlite adapter must use a single file, not per-document files.
        self::assertFileExists($this->dataDir . DIRECTORY_SEPARATOR . 'sdb.sqlite');
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function test_memory_adapter_loads_but_is_ephemeral(): void
    {
        // A fresh :memory: store has nothing in it; the command should still succeed.
        $count = $this->sdb(['command' => 'count', 'collection' => 'whatever'], adapter: 'memory');
        self::assertSame(0, $count['code'], $count['err']);
        self::assertSame('0', trim($count['out']));
    }
}
