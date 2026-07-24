<?php

namespace NickWelsh\LaravelZero\Frontend;

use NickWelsh\LaravelZero\Support\GeneratedPaths;

final readonly class React extends Frontend
{
    private const GLOBALS = [
        'ZERO_CACHE_URL' => 'export const ZERO_CACHE_URL = import.meta.env.VITE_ZERO_CACHE_URL;',
        'ZERO_MUTATE_URL' => 'export const ZERO_MUTATE_URL = import.meta.env.VITE_ZERO_MUTATE_URL;',
        'ZERO_QUERY_URL' => 'export const ZERO_QUERY_URL = import.meta.env.VITE_ZERO_QUERY_URL;',
    ];

    protected function generatedFiles(string $outputPath): array
    {
        $providerPath = $outputPath.'/provider.tsx';
        $stub = config('laravel-zero.frontend.use_globals', true) === true
            ? __DIR__.'/../../stubs/react/provider.globals.tsx.stub'
            : __DIR__.'/../../stubs/react/provider.tsx.stub';

        return [
            'provider.tsx' => str_replace(
                ['{{ context_import }}', '{{ mutations_import }}', '{{ schema_import }}', '{{ props_declaration }}'],
                [
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::outputDirectory().'/context.generated.ts'),
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::outputDirectory().'/mutations.generated.ts'),
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::schema()),
                    $this->propsDeclaration(),
                ],
                $this->files->get($stub),
            ),
        ];
    }

    protected function barrel(string $barrelPath, string $outputPath): string
    {
        return "export * from '".GeneratedPaths::moduleImport($barrelPath, $outputPath.'/provider.tsx')."';\n";
    }

    protected function scaffoldAdditionalFiles(): array
    {
        if (config('laravel-zero.frontend.use_globals', true) !== true) {
            return [];
        }

        $path = config('laravel-zero.frontend.globals_path', resource_path('js/globals.ts'));

        return is_string($path) && $this->appendMissingGlobals($path) ? [$path] : [];
    }

    private function propsDeclaration(): string
    {
        /** @var scalar|\Stringable|null $style */
        $style = config('laravel-zero.generation.declaration_style', 'interface');

        return match ((string) $style) {
            'interface' => "interface AppZeroProviderProps {\n    children?: ReactNode;\n    userId: string;\n}",
            'type' => "type AppZeroProviderProps = {\n    children?: ReactNode;\n    userId: string;\n};",
            default => throw new \InvalidArgumentException('TypeScript declaration style must be [interface] or [type].'),
        };
    }

    private function appendMissingGlobals(string $path): bool
    {
        $contents = $this->files->exists($path) ? $this->files->get($path) : '';
        $missing = array_filter(
            self::GLOBALS,
            fn (string $definition, string $name): bool => preg_match('/^[\\t ]*(?:export[\\t ]+)?(?:const|let|var)[\\t ]+'.preg_quote($name, '/').'\\b/m', $contents) !== 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing === []) {
            return false;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $separator = $contents === '' ? '' : (str_ends_with($contents, "\n\n") ? '' : (str_ends_with($contents, "\n") ? "\n" : "\n\n"));
        $this->files->put($path, $contents.$separator.implode("\n", $missing)."\n");

        return true;
    }
}
