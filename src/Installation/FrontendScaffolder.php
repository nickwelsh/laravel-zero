<?php

namespace NickWelsh\LaravelZero\Installation;

use Illuminate\Filesystem\Filesystem;

final readonly class FrontendScaffolder
{
    private const GLOBALS = [
        'ZERO_CACHE_URL' => 'export const ZERO_CACHE_URL = import.meta.env.VITE_ZERO_CACHE_URL;',
        'ZERO_MUTATE_URL' => 'export const ZERO_MUTATE_URL = import.meta.env.VITE_ZERO_MUTATE_URL;',
        'ZERO_QUERY_URL' => 'export const ZERO_QUERY_URL = import.meta.env.VITE_ZERO_QUERY_URL;',
    ];

    public function __construct(private Filesystem $files) {}

    /** @return list<string> */
    public function scaffold(): array
    {
        if (config('laravel-zero.frontend.framework', 'react') !== 'react') {
            return [];
        }

        $changed = [];
        $providerPath = config('laravel-zero.frontend.provider_path', resource_path('js/zero/provider.tsx'));

        if (is_string($providerPath) && ! $this->files->exists($providerPath)) {
            $this->files->ensureDirectoryExists(dirname($providerPath));
            $stub = config('laravel-zero.frontend.use_globals', true) === true
                ? __DIR__.'/../../stubs/react/provider.globals.tsx.stub'
                : __DIR__.'/../../stubs/react/provider.tsx.stub';
            $this->files->copy($stub, $providerPath);
            $changed[] = $providerPath;
        }

        if (config('laravel-zero.frontend.use_globals', true) === true) {
            $globalsPath = config('laravel-zero.frontend.globals_path', resource_path('js/globals.ts'));
            if (is_string($globalsPath) && $this->appendMissingGlobals($globalsPath)) {
                $changed[] = $globalsPath;
            }
        }

        return $changed;
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
