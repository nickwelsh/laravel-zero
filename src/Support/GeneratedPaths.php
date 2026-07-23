<?php

namespace NickWelsh\LaravelZero\Support;

final class GeneratedPaths
{
    public static function outputDirectory(): string
    {
        return rtrim((string) config('laravel-zero.generation.output_directory', resource_path('js/zero/generated')), '/\\');
    }

    public static function schema(): string
    {
        $configured = config('laravel-zero.generation.schema_path');
        $legacy = resource_path('js/zero/schema.ts');

        return ! is_string($configured) || $configured === $legacy
            ? self::outputDirectory().'/schema.generated.ts'
            : $configured;
    }

    public static function provider(): string
    {
        $configured = config('laravel-zero.frontend.provider_path');
        $legacy = resource_path('js/zero/provider.tsx');

        return ! is_string($configured) || $configured === $legacy
            ? self::outputDirectory().'/provider.generated.tsx'
            : $configured;
    }

    public static function barrel(): string
    {
        $configured = config('laravel-zero.generation.barrel_path');

        return is_string($configured) ? $configured : dirname(self::outputDirectory()).'/index.ts';
    }

    public static function moduleImport(string $from, string $to): string
    {
        $from = str_replace('\\', '/', dirname($from));
        $to = preg_replace('/\.(?:[cm]?[jt]sx?)$/', '', str_replace('\\', '/', $to));
        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $relative = implode('/', [...array_fill(0, count($fromParts), '..'), ...$toParts]);

        return str_starts_with($relative, '.') ? $relative : './'.$relative;
    }
}
