<?php

namespace NickWelsh\LaravelZero\Frontend;

use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Dynamic\DynamicQueryRegistry;
use NickWelsh\LaravelZero\Support\GeneratedPaths;

final readonly class React extends Frontend
{
    private const GLOBALS = [
        'ZERO_CACHE_URL' => 'export const ZERO_CACHE_URL = import.meta.env.VITE_ZERO_CACHE_URL;',
        'ZERO_MUTATE_URL' => 'export const ZERO_MUTATE_URL = import.meta.env.VITE_ZERO_MUTATE_URL;',
        'ZERO_QUERY_URL' => 'export const ZERO_QUERY_URL = import.meta.env.VITE_ZERO_QUERY_URL;',
    ];

    public function __construct(Filesystem $files, private DynamicQueryRegistry $dynamic)
    {
        parent::__construct($files);
    }

    protected function generatedFiles(string $outputPath): array
    {
        $providerPath = $outputPath.'/provider.tsx';
        $stub = config('laravel-zero.frontend.use_globals', true) === true
            ? __DIR__.'/../../stubs/react/provider.globals.tsx.stub'
            : __DIR__.'/../../stubs/react/provider.tsx.stub';

        $files = [
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

        if ($this->dynamic->all() !== []) {
            $dynamicPath = $outputPath.'/dynamic.ts';
            $queriesImport = GeneratedPaths::moduleImport($dynamicPath, GeneratedPaths::outputDirectory().'/queries.generated.ts');
            $files['dynamic.ts'] = <<<TS
import type { QueryResultDetails } from '@rocicorp/zero';
import { useQuery, type UseQueryOptions } from '@rocicorp/zero/react';
import type { DynamicQueryRequest } from '{$queriesImport}';

export function useDynamicQuery<TResult>(
    query: DynamicQueryRequest<TResult>,
    options?: UseQueryOptions | boolean,
): readonly [TResult, QueryResultDetails] {
    const [value, details] = useQuery(query.request as never, options);

    return [query.parse(value), details];
}
TS;
        }

        return $files;
    }

    protected function barrel(string $barrelPath, string $outputPath): string
    {
        $exports = [
            "export * from '".GeneratedPaths::moduleImport($barrelPath, $outputPath.'/provider.tsx')."';",
        ];
        if ($this->dynamic->all() !== []) {
            $exports[] = "export * from '".GeneratedPaths::moduleImport($barrelPath, $outputPath.'/dynamic.ts')."';";
        }

        return implode("\n", $exports)."\n";
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
