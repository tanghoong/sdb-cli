<?php

declare(strict_types=1);

namespace Sdb\Tests;

final class FindTest extends AbstractCliTestCase
{
    private function seed(): void
    {
        $this->put('products', 'p1', ['name' => 'Widget', 'price' => 300, 'stock' => 5,  'tag' => 'a']);
        $this->put('products', 'p2', ['name' => 'Gadget', 'price' => 700, 'stock' => 0,  'tag' => 'b']);
        $this->put('products', 'p3', ['name' => 'Gizmo',  'price' => 150, 'stock' => 12, 'tag' => 'a']);
    }

    public function test_find_all_injects_id(): void
    {
        $this->seed();

        $find = $this->sdb(['command' => 'find', 'collection' => 'products']);
        self::assertSame(0, $find['code']);

        $rows = $this->decode($find['out']);
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertArrayHasKey('_id', $row);
            self::assertArrayHasKey('name', $row);
        }
    }

    public function test_find_with_comparison_operator_and_order(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['price:<:500'],
            '--order'    => ['name:asc'],
        ]);
        self::assertSame(0, $find['code']);

        $names = array_column($this->decode($find['out']), 'name');
        self::assertSame(['Gizmo', 'Widget'], $names); // price < 500, sorted asc by name
    }

    public function test_find_word_alias_operators_match_symbols(): void
    {
        $this->seed();

        // price:lt:500 must behave exactly like price:<:500 (shell-safe form).
        $alias = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['price:lt:500'],
            '--order'    => ['name:asc'],
        ]);
        self::assertSame(['Gizmo', 'Widget'], array_column($this->decode($alias['out']), 'name'));

        $gte = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['price:gte:700'],
        ]);
        self::assertSame(['Gadget'], array_column($this->decode($gte['out']), 'name'));
    }

    public function test_find_equality_coerces_numeric_value(): void
    {
        $this->seed();

        // 300 stored as a JSON number; the flag value "300" must coerce to int to match (===).
        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['price=300'],
        ]);
        $ids = array_column($this->decode($find['out']), '_id');
        self::assertSame(['p1'], $ids);
    }

    public function test_find_in_operator(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['name:in:Widget,Gizmo'],
            '--order'    => ['price:desc'],
        ]);
        $names = array_column($this->decode($find['out']), 'name');
        self::assertSame(['Widget', 'Gizmo'], $names); // 300 then 150
    }

    public function test_find_limit_and_offset(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--order'    => ['price:asc'],
            '--limit'    => '1',
            '--offset'   => '1',
        ]);
        $names = array_column($this->decode($find['out']), 'name');
        self::assertSame(['Widget'], $names); // sorted: Gizmo(150), Widget(300), Gadget(700) -> offset 1 limit 1
    }

    public function test_find_ndjson_one_object_per_line(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['stock:>:0'],
            '--ndjson'   => true,
        ]);
        $lines = array_filter(explode("\n", trim($find['out'])));
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertIsArray(json_decode($line, true));
        }
    }

    public function test_find_string_operator_does_not_coerce_numeric_looking_value(): void
    {
        // A leading-zero code must stay a string; coercion would search for "12".
        $this->put('codes', 'c1', ['code' => '0012', 'label' => 'first']);
        $this->put('codes', 'c2', ['code' => '9999', 'label' => 'second']);

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'codes',
            '--where'    => ['code:starts_with:0012'],
        ]);
        $ids = array_column($this->decode($find['out']), '_id');
        self::assertSame(['c1'], $ids);
    }

    public function test_find_equality_keeps_leading_zero_value_as_string(): void
    {
        // A zip stored as the string "00123" must match an "00123" flag, not 123.
        $this->put('addr', 'a1', ['zip' => '00123', 'city' => 'Foo']);
        $this->put('addr', 'a2', ['zip' => '99999', 'city' => 'Bar']);

        $hit = $this->sdb(['command' => 'find', 'collection' => 'addr', '--where' => ['zip=00123']]);
        self::assertSame(['a1'], array_column($this->decode($hit['out']), '_id'));

        // And the coerced int form must NOT match the string-stored zip.
        $miss = $this->sdb(['command' => 'find', 'collection' => 'addr', '--where' => ['zip=123']]);
        self::assertSame([], $this->decode($miss['out']));
    }

    public function test_find_negative_limit_is_usage_error(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--limit'    => '-5',
        ]);
        self::assertSame(2, $find['code']);
    }

    public function test_find_contains_operator(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['name:contains:idg'], // Widget, but not Gadget/Gizmo
        ]);
        $names = array_column($this->decode($find['out']), 'name');
        self::assertSame(['Widget'], $names);
    }

    public function test_find_bad_operator_is_usage_error(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--where'    => ['price:bogus:5'],
        ]);
        self::assertSame(2, $find['code']);
        self::assertStringContainsString('Malformed', $find['err']);
    }

    public function test_find_bad_limit_is_usage_error(): void
    {
        $this->seed();

        $find = $this->sdb([
            'command'    => 'find',
            'collection' => 'products',
            '--limit'    => 'abc',
        ]);
        self::assertSame(2, $find['code']);
    }
}
