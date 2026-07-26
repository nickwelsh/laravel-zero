<?php

namespace NickWelsh\LaravelZero\Frontend;

use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Dynamic\DynamicMutationRegistry;
use NickWelsh\LaravelZero\Dynamic\DynamicQueryRegistry;
use NickWelsh\LaravelZero\Support\GeneratedPaths;

final readonly class React extends Frontend
{
    private const GLOBALS = [
        'ZERO_CACHE_URL' => 'export const ZERO_CACHE_URL = import.meta.env.VITE_ZERO_CACHE_URL;',
        'ZERO_MUTATE_URL' => 'export const ZERO_MUTATE_URL = import.meta.env.VITE_ZERO_MUTATE_URL;',
        'ZERO_QUERY_URL' => 'export const ZERO_QUERY_URL = import.meta.env.VITE_ZERO_QUERY_URL;',
    ];

    public function __construct(
        Filesystem $files,
        private DynamicQueryRegistry $dynamic,
        private DynamicMutationRegistry $mutations,
    ) {
        parent::__construct($files);
    }

    protected function generatedFiles(string $outputPath): array
    {
        $providerPath = $outputPath.'/provider.tsx';
        $stub = config('laravel-zero.frontend.use_globals', true) === true
            ? __DIR__.'/../../stubs/react/provider.globals.tsx.stub'
            : __DIR__.'/../../stubs/react/provider.tsx.stub';
        $hasDefaultMutations = $this->mutations->all() !== [];
        $queriesImport = GeneratedPaths::moduleImport($providerPath, GeneratedPaths::outputDirectory().'/queries.generated.ts');

        $files = [
            'provider.tsx' => str_replace(
                [
                    '{{ context_import }}',
                    '{{ mutations_import }}',
                    '{{ default_mutation_import }}',
                    '{{ schema_import }}',
                    '{{ props_declaration }}',
                    '{{ default_mutation_init }}',
                    '{{ zero_provider_props }}',
                ],
                [
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::outputDirectory().'/context.generated.ts'),
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::outputDirectory().'/mutations.generated.ts'),
                    $hasDefaultMutations ? "import { configureDefaultModelMutations } from '{$queriesImport}';" : '',
                    GeneratedPaths::moduleImport($providerPath, GeneratedPaths::schema()),
                    $this->propsDeclaration(),
                    $hasDefaultMutations
                        ? "    const init = useCallback((zero: Parameters<NonNullable<ComponentProps<typeof ZeroProvider>['init']>>[0]) => {\n"
                            ."        configureDefaultModelMutations(request => zero.mutate(request as never), generateId);\n"
                            .'    }, [generateId]);'
                        : '',
                    'cacheURL, context, '.($hasDefaultMutations ? 'init, ' : '').'mutateURL, mutators: mutations, queryURL, schema',
                ],
                $this->files->get($stub),
            ),
        ];

        if ($this->dynamic->all() !== [] || $this->mutations->all() !== []) {
            $dynamicPath = $outputPath.'/dynamic.ts';
            $queriesImport = GeneratedPaths::moduleImport($dynamicPath, GeneratedPaths::outputDirectory().'/queries.generated.ts');
            $files['dynamic.ts'] = <<<TS
import type { QueryResultDetails } from '@rocicorp/zero';
import { useQuery as useZeroQuery, type UseQueryOptions } from '@rocicorp/zero/react';
import type { DynamicQueryBuilder, DynamicQueryRequest, ZeroModelInstance, ZeroModelKey } from '{$queriesImport}';
import * as models from '{$queriesImport}';

export function useQuery<TResult>(
    query: DynamicQueryRequest<TResult>,
    options?: UseQueryOptions | boolean,
): readonly [TResult, QueryResultDetails] {
    const [value, details] = useZeroQuery(query.request as never, options);

    return [query.parse(value), details];
}

export function useDynamicQuery<TResult>(
    query: DynamicQueryRequest<TResult>,
    options?: UseQueryOptions | boolean,
): readonly [TResult, QueryResultDetails] {
    return useQuery(query, options);
}

type AnyModel = DynamicQueryBuilder<any, any, any, any, any>;
type ModelInstance = ZeroModelInstance<Record<string, unknown>>;

function modelFor(target: AnyModel | ModelInstance): {model: AnyModel; key?: ZeroModelKey} {
    if ('__zero' in target) {
        const model = Object.values(models).find(candidate =>
            typeof candidate === 'object'
            && candidate !== null
            && 'modelName' in candidate
            && typeof candidate.modelName === 'function'
            && candidate.modelName() === target.__zero.model,
        );
        if (!model || typeof model !== 'object') {
            throw new Error(`Unknown Zero model metadata [\${target.__zero.model}].`);
        }
        return {model: model as AnyModel, key: target.__zero.key};
    }

    return {model: target};
}

export function useModel(target: AnyModel | ModelInstance) {
    const resolved = modelFor(target);
    const explicit = resolved.key === undefined;
    const key = (value: unknown): ZeroModelKey => explicit ? resolved.model.key(value) : resolved.key!;
    const shifted = (args: readonly unknown[]): readonly unknown[] => explicit ? args : [resolved.key, ...args];

    return {
        create: (values: Record<string, unknown>) => resolved.model.create(values),
        update: (...args: readonly unknown[]) => {
            const [identity, values] = shifted(args);
            return resolved.model.update(key(identity), values as Record<string, unknown>);
        },
        delete: (...args: readonly unknown[]) => {
            const [identity] = shifted(args);
            return resolved.model.delete(key(identity));
        },
        attach: (...args: readonly unknown[]) => relation(resolved.model, 'attach', shifted(args)),
        detach: (...args: readonly unknown[]) => relation(resolved.model, 'detach', shifted(args)),
        sync: (...args: readonly unknown[]) => relation(resolved.model, 'sync', shifted(args)),
        syncWithoutDetaching: (...args: readonly unknown[]) => relation(resolved.model, 'syncWithoutDetaching', shifted(args)),
        syncWithPivotValues: (...args: readonly unknown[]) => relation(resolved.model, 'syncWithPivotValues', shifted(args), true),
        toggle: (...args: readonly unknown[]) => relation(resolved.model, 'toggle', shifted(args)),
        updateExistingPivot: (...args: readonly unknown[]) => {
            const [identity, relationName, relatedId, pivot] = shifted(args);
            return resolved.model.mutateRelation(key(identity), relationName as string, 'updateExistingPivot', {
                relatedId,
                pivot: pivot as Record<string, unknown>,
            });
        },
    };
}

function relation(
    model: AnyModel,
    operation: string,
    args: readonly unknown[],
    hasPivot = false,
): Promise<unknown> {
    const [identity, relationName, ids, pivot] = args;
    return model.mutateRelation(model.key(identity), relationName as string, operation, {
        ids,
        ...(hasPivot || pivot !== undefined ? {pivot: pivot as Record<string, unknown>} : {}),
    });
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
        if ($this->dynamic->all() !== [] || $this->mutations->all() !== []) {
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
            'interface' => "interface AppZeroProviderProps {\n    children?: ReactNode;\n    generateId?: () => string | number;\n    userId: string;\n}",
            'type' => "type AppZeroProviderProps = {\n    children?: ReactNode;\n    generateId?: () => string | number;\n    userId: string;\n};",
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
