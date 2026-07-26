<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;

final readonly class ModelDiscovery
{
    public function __construct(private Filesystem $files) {}

    /** @return list<class-string<Model>> */
    public function classes(): array
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
                    if (! is_string($directory) || ! is_dir($directory)) {
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
                $value .= is_string($token[1]) ? $token[1] : '';
                if ($stopAfterName) {
                    break;
                }
            }
        }

        return $value;
    }
}
