<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Typed accessors for decoded JSON values in tests.
 *
 * `json_decode()` returns `mixed`, so walking a decoded response with raw
 * `['key']` / `->prop` chains is untypable at PHPStan level max. These helpers
 * assert the runtime shape (failing the test loudly on a mismatch) and return a
 * precise type, mirroring the production `BaseService::asString()/asInt()/
 * asAssocArray()` coercion idiom — but with assertions instead of silent
 * defaults, so they double as real test checks.
 */
trait NarrowsJson
{
    /**
     * Narrow a decoded JSON object to a string-keyed array.
     *
     * @return array<string, mixed>
     */
    protected static function asArray(mixed $value, string $message = 'Expected a JSON array/object'): array
    {
        Assert::assertIsArray($value, $message);

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * Narrow a decoded JSON array to a list.
     *
     * @return list<mixed>
     */
    protected static function asList(mixed $value, string $message = 'Expected a JSON list'): array
    {
        Assert::assertIsArray($value, $message);

        return array_values($value);
    }

    protected static function asObject(mixed $value, string $message = 'Expected a JSON object'): \stdClass
    {
        Assert::assertInstanceOf(\stdClass::class, $value, $message);

        return $value;
    }

    protected static function asString(mixed $value, string $message = 'Expected a string'): string
    {
        Assert::assertIsString($value, $message);

        return $value;
    }

    protected static function asInt(mixed $value, string $message = 'Expected an int'): int
    {
        Assert::assertIsInt($value, $message);

        return $value;
    }

    /**
     * Coerce a numeric-ish scalar (e.g. a Doctrine DBAL `fetchOne()` result,
     * which is a numeric string for integer columns) to int. Fails the test if
     * the value is not numeric/bool, so it cannot silently swallow a bad shape.
     */
    protected static function coerceInt(mixed $value, string $message = 'Expected a numeric value'): int
    {
        if (is_int($value) || is_float($value) || is_bool($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        Assert::fail($message . ', got ' . get_debug_type($value));
    }

    /**
     * Coerce a scalar to string (e.g. a DBAL `fetchOne()` result), failing the
     * test on arrays/objects/null rather than emitting a PHP cast warning.
     */
    protected static function coerceString(mixed $value, string $message = 'Expected a scalar value'): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        Assert::fail($message . ', got ' . get_debug_type($value));
    }

    /**
     * Walk a decoded JSON value (array or stdClass) by key path and return the
     * leaf as `mixed` (fine for assertSame/assertNotNull/etc., which accept
     * mixed). Fails the test if any segment is missing.
     */
    protected static function jsonGet(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
                continue;
            }

            if (is_object($data) && property_exists($data, (string) $segment)) {
                $data = $data->{(string) $segment};
                continue;
            }

            Assert::fail(sprintf('JSON path segment "%s" not found in response', (string) $segment));
        }

        return $data;
    }
}
