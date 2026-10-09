<?php

namespace LaravelZero\Support;

use Illuminate\Validation\ValidationException;
use stdClass;

final class Input
{
    public static function object(mixed $value, string $path): array
    {
        if ($value instanceof stdClass) {
            return (array) $value;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail($path, 'Must be an object.');
        }

        return $value;
    }

    public static function keys(array $value, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            self::fail("{$path}.{$key}", 'Unknown or protected field.');
        }
    }

    public static function value(mixed $value, array $column, string $path): mixed
    {
        if ($value === null && $column['nullable']) {
            return null;
        }
        $valid = match ($column['type']) {
            'string' => is_string($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'json' => self::isJson($value),
        };
        if ($value === null || ! $valid || ($column['enum'] !== null && ! in_array($value, $column['enum'], true))) {
            self::fail($path, 'Invalid field value.');
        }
        if ($column['date'] && (abs($value) > 8640000000000000 || floor($value) !== (float) $value)) {
            self::fail($path, 'Must be a valid epoch millisecond timestamp.');
        }

        return $value;
    }

    private static function isJson(mixed $value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);

            return is_array($value) || $value instanceof stdClass || is_scalar($value);
        } catch (\JsonException) {
            return false;
        }
    }

    public static function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
