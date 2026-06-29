<?php

declare(strict_types=1);

namespace Sdb\Tests;

final class PutGetDeleteTest extends AbstractCliTestCase
{
    public function test_put_then_get_returns_the_document(): void
    {
        $put = $this->sdb([
            'command'    => 'put',
            'collection' => 'users',
            'id'         => 'alice',
            'json'       => '{"name":"Alice","age":30}',
        ]);
        self::assertSame(0, $put['code']);
        self::assertSame('alice', trim($put['out']));

        $get = $this->sdb(['command' => 'get', 'collection' => 'users', 'id' => 'alice']);
        self::assertSame(0, $get['code']);
        self::assertSame(['name' => 'Alice', 'age' => 30], $this->decode($get['out']));
    }

    public function test_put_overwrites_existing_document(): void
    {
        $this->put('users', 'alice', ['name' => 'Alice', 'age' => 30]);
        $this->put('users', 'alice', ['name' => 'Alice B.', 'age' => 31]);

        $get = $this->sdb(['command' => 'get', 'collection' => 'users', 'id' => 'alice']);
        self::assertSame(['name' => 'Alice B.', 'age' => 31], $this->decode($get['out']));
    }

    public function test_get_missing_document_exits_1(): void
    {
        $get = $this->sdb(['command' => 'get', 'collection' => 'users', 'id' => 'ghost']);
        self::assertSame(1, $get['code']);
        self::assertStringContainsString('not found', $get['err']);
    }

    public function test_get_raw_is_single_line(): void
    {
        $this->put('users', 'alice', ['name' => 'Alice', 'age' => 30]);

        $get = $this->sdb(['command' => 'get', 'collection' => 'users', 'id' => 'alice', '--raw' => true]);
        self::assertSame('{"name":"Alice","age":30}', trim($get['out']));
    }

    public function test_put_invalid_json_is_usage_error(): void
    {
        $put = $this->sdb([
            'command'    => 'put',
            'collection' => 'users',
            'id'         => 'bad',
            'json'       => '{not valid',
        ]);
        self::assertSame(2, $put['code']);
        self::assertStringContainsString('Invalid JSON', $put['err']);
    }

    public function test_put_rejects_reserved_id_field(): void
    {
        $put = $this->sdb([
            'command'    => 'put',
            'collection' => 'users',
            'id'         => 'alice',
            'json'       => '{"name":"Alice","_id":"sneaky"}',
        ]);
        self::assertSame(2, $put['code']);
        self::assertStringContainsString("reserved '_id'", $put['err']);
    }

    public function test_delete_then_delete_missing(): void
    {
        $this->put('users', 'alice', ['name' => 'Alice']);

        $del = $this->sdb(['command' => 'delete', 'collection' => 'users', 'id' => 'alice']);
        self::assertSame(0, $del['code']);

        $again = $this->sdb(['command' => 'delete', 'collection' => 'users', 'id' => 'alice']);
        self::assertSame(1, $again['code']);
        self::assertStringContainsString('not found', $again['err']);
    }

    public function test_delete_alias_rm_works(): void
    {
        $this->put('users', 'bob', ['name' => 'Bob']);

        $del = $this->sdb(['command' => 'rm', 'collection' => 'users', 'id' => 'bob']);
        self::assertSame(0, $del['code']);
    }
}
