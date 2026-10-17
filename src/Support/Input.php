<?php

declare(strict_types=1);

namespace ZeroForLaravel\Support;

use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/** @phpstan-import-type Column from \ZeroForLaravel\Metadata\ModelMetadata */
final class Input
{
    /**
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail($path, 'Must be an object.');
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                self::fail($path, 'Must use string field names.');
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    public static function keys(array $value, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            self::fail("{$path}.{$key}", 'Unknown or protected field.');
        }
    }

    /**
     * @param  Column  $column
     */
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

        if ($column['date']) {
            self::timestamp($value, $path);
        }

        return $value;
    }

    public static function timestamp(mixed $value, string $path): int|float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs($value) > 8640000000000000 || floor($value) !== (float) $value) {
            self::fail($path, 'Must be a valid epoch millisecond timestamp.');
        }

        return $value;
    }

    /** @param Column $column */
    public static function eloquentValue(mixed $value, array $column, string $path): mixed
    {
        if (is_string($value) && $column['type'] === 'string' && in_array($column['cast'] ?? null, ['array', 'object'], true)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                self::fail($path, 'Expected valid JSON.');
            }

            if (! is_array($value)) {
                self::fail($path, 'Expected a JSON array or object.');
            }
        }

        if ($value === null) {
            return null;
        }

        if (($column['type'] === 'json' || in_array($column['cast'] ?? null, ['array', 'object'], true)) && (is_array($value) || $value instanceof stdClass)) {
            // Match Laravel's ordinary JSON request input, including nested objects.
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        return match ($column['cast'] ?? null) {
            'boolean' => (bool) $value,
            'integer' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /** @param Column $column */
    public static function wireValue(mixed $value, array $column, string $path): mixed
    {
        if ($value === null || ! isset($column['cast'])) {
            return $value;
        }

        if (in_array($column['cast'], ['array', 'object'], true)) {
            if (! is_array($value) && ! $value instanceof stdClass) {
                self::fail($path, 'Expected a normalized JSON array or object.');
            }

            return $column['type'] === 'string' ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }

        if (! is_scalar($value)) {
            self::fail($path, 'Expected a normalized scalar.');
        }

        return match ($column['type']) {
            'string' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            'number' => is_bool($value) ? ($value ? 1 : 0) : (is_numeric($value) ? $value + 0 : $value),
            'boolean' => (bool) $value,
            default => $value,
        };
    }

    public static function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }

    private static function isJson(mixed $value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);

            return is_array($value) || $value instanceof stdClass || is_scalar($value);
        } catch (JsonException) {
            return false;
        }
    }
}
