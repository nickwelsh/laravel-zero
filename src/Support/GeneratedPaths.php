<?php

namespace NickWelsh\LaravelZero\Support;

final class GeneratedPaths
{
    public static function outputDirectory(): string
    {
        /** @var scalar|\Stringable|null $directory */
        $directory = config('laravel-zero.generation.output_directory', resource_path('js/zero/generated'));

        return rtrim((string) $directory, '/\\');
    }

    public static function schema(): string
    {
        $configured = config('laravel-zero.generation.schema_path');
        $legacy = resource_path('js/zero/schema.ts');

        return ! is_string($configured) || $configured === $legacy
            ? self::outputDirectory().'/schema.generated.ts'
            : $configured;
    }

    public static function frontend(): string
    {
        $configured = config('laravel-zero.frontend.output_path');

        return is_string($configured)
            ? rtrim($configured, '/\\')
            : self::outputDirectory().'/frontend';
    }

    public static function frontendBarrel(): string
    {
        $configured = config('laravel-zero.frontend.barrel_path');

        return is_string($configured)
            ? $configured
            : dirname(self::outputDirectory()).'/frontend/index.ts';
    }

    public static function barrel(): string
    {
        $configured = config('laravel-zero.generation.barrel_path');

        return is_string($configured) ? $configured : dirname(self::outputDirectory()).'/index.ts';
    }

    public static function moduleImport(string $from, string $to): string
    {
        $from = str_replace('\\', '/', dirname($from));
        /** @var string $to */
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
