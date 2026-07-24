<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class DynamicQueryRegistry
{
    /** @var array<string, DynamicModelQuery>|null */
    private ?array $queries = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly ZeroSchemaRegistry $schemas,
        private readonly EloquentScopeApplicator $scopes,
    ) {}

    /** @return array<string, DynamicModelQuery> */
    public function all(): array
    {
        if (config('laravel-zero.dynamic_queries.enabled', true) !== true) {
            return [];
        }
        if ($this->queries !== null) {
            return $this->queries;
        }

        $queries = [];
        foreach ($this->modelClasses() as $modelClass) {
            if (! method_exists($modelClass, 'zeroQueryBuilder')) {
                continue;
            }
            $reflection = new ReflectionMethod($modelClass, 'zeroQueryBuilder');
            if (! $reflection->isPublic() || $reflection->isStatic() || $reflection->getNumberOfRequiredParameters() !== 0) {
                throw new RuntimeException("[{$modelClass}::zeroQueryBuilder()] must be a public instance method with no required parameters.");
            }

            $name = 'models.'.Str::camel(class_basename($modelClass));
            if (isset($queries[$name])) {
                throw new RuntimeException("Dynamic Zero query name [{$name}] is shared by [{$queries[$name]->modelClass()}] and [{$modelClass}].");
            }
            $queries[$name] = new DynamicModelQuery($modelClass, $name, $this->schemas, $this->scopes);
        }
        ksort($queries);

        return $this->queries = $queries;
    }

    public function find(string $name): ?DynamicModelQuery
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<class-string<Model>> */
    private function modelClasses(): array
    {
        $classes = config('laravel-zero.generation.models', []);
        $classes = is_array($classes) ? array_values(array_filter($classes, 'is_string')) : [];
        $directories = config('laravel-zero.generation.model_search_directories', []);

        if (is_array($directories)) {
            foreach ($directories as $pattern) {
                if (! is_string($pattern)) {
                    continue;
                }
                $expanded = strpbrk($pattern, '*?[') === false ? [$pattern] : ($this->files->glob($pattern) ?: []);
                foreach ($expanded as $directory) {
                    if (! is_string($directory)) {
                        continue;
                    }
                    if (! is_dir($directory)) {
                        continue;
                    }
                    foreach ($this->files->allFiles($directory) as $file) {
                        if ($file->getExtension() !== 'php') {
                            continue;
                        }
                        $class = $this->extractClassName($file->getRealPath());
                        if ($class !== null && ! class_exists($class, false)) {
                            require_once $file->getRealPath();
                        }
                        if ($class !== null) {
                            $classes[] = $class;
                        }
                    }
                }
            }
        }

        $models = [];
        foreach (array_unique($classes) as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isAbstract()) {
                /** @var class-string<Model> $class */
                $models[] = $class;
            }
        }

        return $models;
    }

    private function extractClassName(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $tokens = token_get_all($contents);
        $namespace = '';

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->collectTokenValue($tokens, $index + 1);
            }
            if ($token[0] === T_CLASS) {
                $class = $this->collectTokenValue($tokens, $index + 1, true);

                return $class === '' ? null : ($namespace === '' ? $class : $namespace.'\\'.$class);
            }
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function collectTokenValue(array $tokens, int $index, bool $stopAfterName = false): string
    {
        $value = '';
        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            if (! is_array($token)) {
                if ($stopAfterName || $token === ';' || $token === '{') {
                    break;
                }

                continue;
            }
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                if (! is_string($token[1])) {
                    continue;
                }
                $value .= $token[1];
                if ($stopAfterName) {
                    break;
                }
            }
        }

        return $value;
    }
}
