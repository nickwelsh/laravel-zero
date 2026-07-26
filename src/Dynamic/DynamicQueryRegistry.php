<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Support\Str;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use ReflectionMethod;
use RuntimeException;

final class DynamicQueryRegistry
{
    /** @var array<string, DynamicModelQuery>|null */
    private ?array $queries = null;

    public function __construct(
        private readonly ModelDiscovery $models,
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
        foreach ($this->models->classes() as $modelClass) {
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
}
