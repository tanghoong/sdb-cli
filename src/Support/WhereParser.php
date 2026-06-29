<?php

declare(strict_types=1);

namespace Sdb\Support;

use Sdb\Exception\UsageException;
use SimpleDB\Query\QueryBuilder;

/**
 * Translates CLI query flags into QueryBuilder calls.
 *
 *   --where field=value          equality (value is type-coerced)
 *   --where field:op:value       explicit operator
 *   --where field:null           unary operator, no value
 *   --order field                ascending
 *   --order field:asc|desc       explicit direction
 *
 * Supported operators: =  !=  >  >=  <  <=  in  not_in
 *                       contains  starts_with  ends_with  null  not_null
 *
 * Values are coerced: integers, floats, true/false, and null are detected;
 * everything else stays a string. `in`/`not_in` take a comma-separated list.
 */
final class WhereParser
{
    private const OPERATORS = [
        '=', '!=', '>', '>=', '<', '<=', 'in', 'not_in',
        'contains', 'starts_with', 'ends_with', 'null', 'not_null',
    ];

    private const UNARY = ['null', 'not_null'];
    private const LIST_OPS = ['in', 'not_in'];
    private const STRING_OPS = ['contains', 'starts_with', 'ends_with'];

    /**
     * Apply every --where clause to the builder.
     *
     * @param string[] $clauses
     */
    public static function applyWhere(QueryBuilder $qb, array $clauses): void
    {
        foreach ($clauses as $clause) {
            [$field, $op, $value] = self::parseClause($clause);

            match (true) {
                $op === 'null'     => $qb->whereNull($field),
                $op === 'not_null' => $qb->whereNotNull($field),
                default            => $qb->where($field, $op, $value),
            };
        }
    }

    /**
     * Apply every --order clause to the builder.
     *
     * @param string[] $clauses
     */
    public static function applyOrder(QueryBuilder $qb, array $clauses): void
    {
        foreach ($clauses as $clause) {
            [$field, $direction] = self::parseOrder($clause);
            $qb->orderBy($field, $direction);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: mixed}  [field, operator, value]
     */
    private static function parseClause(string $clause): array
    {
        // field:op[:value]  — op restricted to known operator characters
        if (preg_match('/^([^:]+):([a-z_!<>=]+)(?::(.*))?$/s', $clause, $m) === 1
            && in_array($m[2], self::OPERATORS, true)
        ) {
            $field = $m[1];
            $op    = $m[2];
            $rawValue = $m[3] ?? null;

            if (in_array($op, self::UNARY, true)) {
                return [$field, $op, null];
            }

            if ($rawValue === null) {
                throw new UsageException("Operator '{$op}' in --where '{$clause}' requires a value.");
            }

            if (in_array($op, self::LIST_OPS, true)) {
                return [$field, $op, self::coerceList($rawValue)];
            }

            // Substring matches are always string comparisons — never coerce
            // (otherwise "0012" would be searched for as 12).
            if (in_array($op, self::STRING_OPS, true)) {
                return [$field, $op, $rawValue];
            }

            return [$field, $op, self::coerceScalar($rawValue)];
        }

        // field=value  — equality shorthand
        if (str_contains($clause, '=')) {
            [$field, $value] = explode('=', $clause, 2);
            if ($field === '') {
                throw new UsageException("Malformed --where clause '{$clause}': empty field name.");
            }
            return [$field, '=', self::coerceScalar($value)];
        }

        throw new UsageException(
            "Malformed --where clause '{$clause}'. Use field=value or field:op:value."
        );
    }

    /**
     * @return array{0: string, 1: string}  [field, direction]
     */
    private static function parseOrder(string $clause): array
    {
        $parts     = explode(':', $clause, 2);
        $field     = $parts[0];
        $direction = strtolower($parts[1] ?? 'asc');

        if ($field === '') {
            throw new UsageException("Malformed --order clause '{$clause}': empty field name.");
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new UsageException(
                "--order direction must be 'asc' or 'desc'; got '{$direction}' in '{$clause}'."
            );
        }

        return [$field, $direction];
    }

    /** @return list<mixed> */
    private static function coerceList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_map(self::coerceScalar(...), explode(',', $value));
    }

    /**
     * Best-effort scalar typing so numeric documents match numeric flags.
     * Strict equality (===) in the query builder means '5' would not match 5.
     *
     * Coercion only happens when the value round-trips exactly, so id-like
     * strings keep their identity: "00123" (leading zero) and integers beyond
     * PHP_INT_MAX stay strings instead of silently changing value.
     */
    private static function coerceScalar(string $value): mixed
    {
        return match (true) {
            $value === 'true'  => true,
            $value === 'false' => false,
            $value === 'null'  => null,
            self::isCanonicalInt($value)   => (int) $value,
            self::isCanonicalFloat($value) => (float) $value,
            default            => $value,
        };
    }

    private static function isCanonicalInt(string $value): bool
    {
        // No leading zeros, and the int round-trips (rejects overflow).
        return preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1
            && (string) (int) $value === $value;
    }

    private static function isCanonicalFloat(string $value): bool
    {
        return preg_match('/^-?\d*\.\d+$/', $value) === 1
            && (string) (float) $value === $value;
    }
}
