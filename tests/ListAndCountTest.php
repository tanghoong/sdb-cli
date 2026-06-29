<?php

declare(strict_types=1);

namespace Sdb\Tests;

final class ListAndCountTest extends AbstractCliTestCase
{
    public function test_list_returns_all_ids(): void
    {
        $this->put('users', 'alice', ['n' => 1]);
        $this->put('users', 'bob', ['n' => 2]);

        $list = $this->sdb(['command' => 'list', 'collection' => 'users']);
        self::assertSame(0, $list['code']);

        $ids = $this->decode($list['out']);
        sort($ids);
        self::assertSame(['alice', 'bob'], $ids);
    }

    public function test_list_empty_collection_is_empty_array(): void
    {
        $list = $this->sdb(['command' => 'list', 'collection' => 'nope']);
        self::assertSame(0, $list['code']);
        self::assertSame([], $this->decode($list['out']));
    }

    public function test_list_ndjson_one_id_per_line(): void
    {
        $this->put('users', 'alice', ['n' => 1]);
        $this->put('users', 'bob', ['n' => 2]);

        $list = $this->sdb(['command' => 'list', 'collection' => 'users', '--ndjson' => true]);
        $lines = array_filter(explode("\n", trim($list['out'])));
        sort($lines);
        self::assertSame(['"alice"', '"bob"'], $lines);
    }

    public function test_count_all(): void
    {
        $this->put('orders', 'o1', ['status' => 'pending']);
        $this->put('orders', 'o2', ['status' => 'pending']);
        $this->put('orders', 'o3', ['status' => 'shipped']);

        $count = $this->sdb(['command' => 'count', 'collection' => 'orders']);
        self::assertSame(0, $count['code']);
        self::assertSame('3', trim($count['out']));
    }

    public function test_count_with_where(): void
    {
        $this->put('orders', 'o1', ['status' => 'pending']);
        $this->put('orders', 'o2', ['status' => 'pending']);
        $this->put('orders', 'o3', ['status' => 'shipped']);

        $count = $this->sdb([
            'command'    => 'count',
            'collection' => 'orders',
            '--where'    => ['status=pending'],
        ]);
        self::assertSame('2', trim($count['out']));
    }
}
