<?php

declare(strict_types=1);

namespace LaravelZero\Metadata;

use Illuminate\Database\Eloquent\Model;
use LaravelZero\Attributes;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class ModelDiscovery
{
    /**
     * @return list<class-string<Model>>
     */
    public function discover(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -4);
            $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', $relative);
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            for ($reflection = new ReflectionClass($class); $reflection; $reflection = $reflection->getParentClass()) {
                foreach ([Attributes\ZeroQuery::class, Attributes\ZeroMutate::class, Attributes\ZeroAuthorize::class, Attributes\ZeroPublic::class] as $attribute) {
                    if ($reflection->getAttributes($attribute) !== []) {
                        $classes[] = $class;

                        continue 3;
                    }
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
