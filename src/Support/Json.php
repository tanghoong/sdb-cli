<?php

declare(strict_types=1);

namespace Sdb\Support;

use Sdb\Exception\UsageException;

/**
 * JSON encode/decode helpers with sdb's house formatting rules.
 */
final class Json
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Pretty, multi-line JSON — the default human-facing output.
     *
     * @throws \JsonException when the value cannot be encoded (e.g. malformed UTF-8 on disk)
     */
    public static function pretty(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS | JSON_PRETTY_PRINT);
    }

    /**
     * Compact, single-line JSON — used for --raw and each NDJSON line.
     *
     * @throws \JsonException when the value cannot be encoded
     */
    public static function compact(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * Decode a JSON document supplied on the command line.
     *
     * @throws UsageException when the text is not valid JSON or is not an object/array
     */
    public static function decodeDocument(string $text): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UsageException('Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new UsageException(
                'A document must be a JSON object or array; got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }
}
