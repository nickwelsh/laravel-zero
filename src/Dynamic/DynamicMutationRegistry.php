<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Support\Str;
use NickWelsh\LaravelZero\Attributes\ZeroAuthorizeMutations;
use NickWelsh\LaravelZero\Attributes\ZeroDefaultMutations;
use NickWelsh\LaravelZero\Contracts\ZeroDefaultMutation;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use ReflectionClass;
use RuntimeException;

final class DynamicMutationRegistry
{
    /** @var array<string, DynamicModelMutation>|null */
    private ?array $mutations = null;

    public function __construct(
        private readonly ModelDiscovery $models,
        private readonly ZeroSchemaRegistry $schemas,
    ) {}

    /** @return array<string, DynamicModelMutation> */
    public function all(): array
    {
        if (config('laravel-zero.default_mutations.enabled', true) !== true) {
            return [];
        }
        if ($this->mutations !== null) {
            return $this->mutations;
        }

        $mutations = [];
        foreach ($this->models->classes() as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $attributes = $reflection->getAttributes(ZeroDefaultMutations::class);
            if ($attributes === []) {
                continue;
            }
            if (count($attributes) !== 1) {
                throw new RuntimeException("[{$modelClass}] must declare at most one ".ZeroDefaultMutations::class.' attribute.');
            }
            $authorization = $reflection->getAttributes(ZeroAuthorizeMutations::class);
            if (count($authorization) !== 1) {
                throw new RuntimeException("[{$modelClass}] must declare exactly one ".ZeroAuthorizeMutations::class.' attribute when default mutations are enabled.');
            }
            $operations = [];
            foreach ($attributes[0]->newInstance()->mutations as $mutation) {
                if (! is_subclass_of($mutation, ZeroDefaultMutation::class)) {
                    throw new RuntimeException("Default Zero mutation [{$mutation}] on [{$modelClass}] must implement ".ZeroDefaultMutation::class.'.');
                }
                $operations[] = $mutation::operation();
            }
            $operations = array_values(array_unique($operations));
            $name = 'models.'.Str::camel(class_basename($modelClass));
            if (isset($mutations[$name])) {
                throw new RuntimeException("Default Zero mutation name [{$name}] is shared by [{$mutations[$name]->modelClass()}] and [{$modelClass}].");
            }
            $policy = $authorization[0]->newInstance()->policy;
            if (! class_exists($policy)) {
                throw new RuntimeException("Default Zero mutation policy [{$policy}] for [{$modelClass}] does not exist.");
            }
            $mutations[$name] = new DynamicModelMutation($modelClass, $name, $policy, $operations, $this->schemas);
        }
        ksort($mutations);

        return $this->mutations = $mutations;
    }

    /** @return array{DynamicModelMutation, string}|null */
    public function findOperation(string $name): ?array
    {
        $position = strrpos($name, '.');
        if ($position === false) {
            return null;
        }
        $model = $this->all()[substr($name, 0, $position)] ?? null;

        return $model === null ? null : [$model, substr($name, $position + 1)];
    }
}
