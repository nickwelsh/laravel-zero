<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ZeroForLaravel\Attributes;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Generation\RuleExtractor;

/** @phpstan-import-type Column from \ZeroForLaravel\Metadata\ModelMetadata */
final class ModelRegistry
{
    /** @var array<string, ModelMetadata> */
    private array $models = [];

    /**
     * @var array<string, list<string>>
     */
    private array $pivots = [];

    /**
     * @var array<string, list<string>>
     */
    private array $pivotKeys = [];

    /**
     * @var array<string, array<string, array{type: 'string'|'number'|'boolean'|'json', nullable: bool}>>
     */
    private array $pivotColumns = [];

    private ?string $connectionName = null;

    /**
     * @param  list<class-string<Model>>  $classes
     */
    public function __construct(array $classes)
    {
        foreach ($classes as $class) {
            $metadata = $this->describe($class);
            if (isset($this->models[$metadata->name])) {
                throw new LogicException("Duplicate Zero model name: {$metadata->name}.");
            }

            foreach ($this->models as $existing) {
                if ($existing->table === $metadata->table) {
                    throw new LogicException("Duplicate Zero table: {$metadata->table}.");
                }
            }

            $this->models[$metadata->name] = $metadata;
        }

        foreach ($this->models as $metadata) {
            $this->describeRelations($metadata);
        }

        foreach ($this->models as $metadata) {
            $this->validateAuthorizationPath($metadata, []);
            $this->validateNestedMutations($metadata);
        }

        // Relationship-owned inputs can have validation rules without being caller-writable.
        $boundFields = [];
        foreach ($this->models as $parent) {
            foreach ($parent->definition->nestedMutations as $relations) {
                foreach (array_keys($relations) as $name) {
                    $relation = $parent->relations[$name];
                    $belongsTo = ($relation['kind'] ?? null) === 'belongsTo';
                    $dependent = $belongsTo ? $parent->name : $relation['model'];
                    $boundFields[$dependent][] = $relation['path'][0][$belongsTo ? 'source' : 'dest'][0];
                }
            }
        }

        foreach ($this->models as $metadata) {
            [$metadata->validation, $metadata->diagnostics] = (new RuleExtractor)->extract(
                $metadata->definition->requests,
                array_values(array_unique([...$metadata->writable, ...($boundFields[$metadata->name] ?? [])])),
                $metadata->columns,
                ['create', 'update', 'destroy', 'restore', 'force_delete'],
            );
        }
    }

    public function get(string $name): ModelMetadata
    {
        return $this->models[$name] ?? throw new LogicException("Unknown Zero model: {$name}.");
    }

    /**
     * @return array<string, ModelMetadata>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * @return array<string, list<string>>
     */
    public function pivots(): array
    {
        return $this->pivots;
    }

    /**
     * @return array<string, array<string, array{type: 'string'|'number'|'boolean'|'json', nullable: bool}>>
     */
    public function pivotColumns(): array
    {
        return $this->pivotColumns;
    }

    /**
     * @return array<string, list<string>>
     */
    public function pivotKeys(): array
    {
        return $this->pivotKeys;
    }

    public function connection(): Connection
    {
        if ($this->models === []) {
            throw new LogicException('Register at least one Zero model.');
        }

        return (new (reset($this->models)->class))->getConnection();
    }

    /** @param list<string> $ancestors */
    private function validateAuthorizationPath(ModelMetadata $model, array $ancestors): void
    {
        if (in_array($model->name, $ancestors, true)) {
            throw new LogicException("{$model->name}: Cyclic authorization relations are unsupported.");
        }

        if (count($ancestors) > 8) {
            throw new LogicException('Authorization relations exceed the maximum depth.');
        }

        foreach ($model->authorizationRelations as $relation) {
            $this->validateAuthorizationPath($this->get($model->relations[$relation]['model']), [...$ancestors, $model->name]);
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string  $class
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    private function attributes(string $class, string $attribute): array
    {
        $values = [];
        for ($reflection = new ReflectionClass($class); $reflection; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getAttributes($attribute) as $instance) {
                $values[] = $instance->newInstance();
            }
        }

        return $values;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function describe(string $class): ModelMetadata
    {
        if (! is_subclass_of($class, Model::class)) {
            throw new LogicException("{$class} must be an Eloquent model.");
        }

        $model = new $class;
        $name = (new ReflectionClass($class))->getShortName();
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $model->getTable())) {
            throw new LogicException("{$name}: Zero tables must use unqualified identifiers.");
        }

        $connection = $model->getConnection()->getName();
        if ($model->getConnection()->getTablePrefix() !== '' || ($this->connectionName === null && $model->getConnection()->getDriverName() === 'pgsql' && $model->getConnection()->scalar('SELECT current_schema()') !== 'public')) {
            throw new LogicException('Zero models require unprefixed tables in the public PostgreSQL schema.');
        }

        if ($this->connectionName !== null && $this->connectionName !== $connection) {
            throw new LogicException('Zero models must share one upstream connection.');
        }

        $this->connectionName = $connection;
        $definition = method_exists($class, 'zero') ? $class::zero() : ZeroDefinition::make();
        if (! $definition instanceof ZeroDefinition) {
            throw new LogicException("{$name}: zero() must return ZeroDefinition.");
        }

        $queries = ($this->attributes($class, Attributes\ZeroQuery::class)[0] ?? null)->queries ?? [];
        $mutations = ($this->attributes($class, Attributes\ZeroMutate::class)[0] ?? null)->operations ?? [];
        if (array_diff($queries, ['all', 'one']) || array_diff($mutations, ['create', 'update', 'destroy', 'restore', 'force_delete'])) {
            throw new LogicException("{$name}: unsupported automatic operation.");
        }

        $authorization = array_map(fn (Attributes\ZeroAuthorize $a): array => [
            'field' => $a->field, 'op' => $a->operator,
            'value' => $a->value instanceof ContextField ? ['context' => $a->value->value] : ['literal' => $a->value],
        ], $this->attributes($class, Attributes\ZeroAuthorize::class));
        $authorization = [...$authorization, ...$definition->authorization];

        $authorizationRelations = array_values(array_unique([...$definition->authorizationRelations, ...array_map(fn (Attributes\ZeroAuthorizeRelation $attribute): string => $attribute->relation, $this->attributes($class, Attributes\ZeroAuthorizeRelation::class))]));
        if ($authorization === [] && $authorizationRelations === [] && $this->attributes($class, Attributes\ZeroPublic::class) === [] && ! $definition->public && $definition->serverScopes === []) {
            throw new LogicException("{$name}: declare publicReads, authorize, authorizeRelation, or enforceServerScope visibility (or a legacy ZeroPublic/ZeroAuthorize attribute).");
        }

        $fills = $definition->serverFill;
        foreach ($this->attributes($class, Attributes\ZeroServerFill::class) as $fill) {
            if (isset($fills[$fill->field])) {
                throw new LogicException("{$name}: conflicting server fill for {$fill->field}.");
            }

            $fills[$fill->field] = $fill->source->value;
        }

        $traits = class_uses_recursive($class);
        $deleted = in_array(SoftDeletes::class, $traits) && method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;
        if ($deleted !== null && ! is_string($deleted)) {
            throw new LogicException("{$name}: deleted-at column must be a string.");
        }

        if ($deleted === null && array_intersect($mutations, ['restore', 'force_delete'])) {
            throw new LogicException("{$name}: restore and force_delete require SoftDeletes.");
        }

        $created = $model->usesTimestamps() ? $model->getCreatedAtColumn() : null;
        $updated = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;
        $excluded = $model->getHidden();
        foreach ($this->attributes($class, Attributes\ZeroExclude::class) as $a) {
            $excluded = array_merge($excluded, $a->columns);
        }

        $overrides = ($this->attributes($class, Attributes\ZeroIncludeColumns::class)[0] ?? null)->columns ?? [];
        $only = ($this->attributes($class, Attributes\ZeroColumns::class)[0] ?? null)?->columns;
        $visible = $only === null ? $model->getVisible() : [];
        $columns = [];
        $casts = $model->getCasts();
        $dbColumns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        $dbNames = array_column($dbColumns, 'name');
        foreach (array_merge($excluded, $overrides, $only ?? []) as $field) {
            if (! in_array($field, $dbNames, true)) {
                throw new LogicException("{$name}: unknown column {$field}.");
            }
        }

        foreach ($dbColumns as $column) {
            $field = $column['name'];
            if ($only !== null && ! in_array($field, $only, true)) {
                continue;
            }

            if (! in_array($field, $overrides, true) && (($visible !== [] && ! in_array($field, $visible, true)) || in_array($field, $excluded, true))) {
                continue;
            }

            $type = strtolower($column['type_name']);
            $zeroType = $this->databaseType($type, "{$name}.{$field}");
            $cast = $casts[$field] ?? '';
            if ($model->getConnection()->getDriverName() === 'sqlite' && in_array($cast, ['boolean', 'bool'], true)) {
                $zeroType = 'boolean';
            }

            $castType = match (explode(':', $cast)[0]) {
                'bool', 'boolean' => 'boolean', 'int', 'integer', 'timestamp' => 'integer',
                'real', 'double', 'float' => 'float', 'string' => 'string',
                'array', 'json' => 'array', 'object' => 'object', default => null,
            };
            $date = in_array($type, ['date', 'datetime', 'timestamp', 'timestamptz'], true) || in_array(explode(':', $cast)[0], ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true);
            if ($date && ! preg_match('/^(date|datetime|timestamp|timestamptz)$/D', $type)) {
                throw new LogicException("{$name}.{$field}: Date casts require a date or timestamp column (Zero epoch milliseconds).");
            }

            $enum = enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) ? array_map(fn (BackedEnum $case): int|string => $case->value, $cast::cases()) : null;
            $default = $this->literalDefault($column['default'], $zeroType);
            $columns[$field] = ['type' => $zeroType, 'nullable' => $column['nullable'], 'date' => $date, 'enum' => $enum,
                'hasDefault' => $column['default'] !== null && $default['known'], 'default' => $default['value'],
                ...($castType !== null && ! $date && $enum === null ? ['cast' => $castType] : [])];
        }

        $required = array_filter(array_merge([$model->getKeyName(), $created, $updated, $deleted], array_keys($fills), array_column($authorization, 'field')));
        foreach ($required as $field) {
            $this->requireColumn($columns, $name, $field);
        }

        $indexes = $model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable());
        $primary = array_values(array_filter($indexes, fn (array $index): bool => $index['primary']))[0]['columns'] ?? [];
        if ($primary !== [$model->getKeyName()]) {
            throw new LogicException("{$name}: the model key must match a single-column database primary key.");
        }

        foreach ($authorization as $rule) {
            if (! in_array($rule['op'], ['=', '!=', '<', '<=', '>', '>=', 'IS', 'IS NOT'], true)) {
                throw new LogicException("{$name}: unsupported authorization operator.");
            }
        }

        $generator = in_array(HasUlids::class, $traits) ? 'ulid' : (in_array(HasUuids::class, $traits) ? 'uuid' : null);
        if (in_array('create', $mutations, true)) {
            if ($generator === null || $model->getIncrementing() || $columns[$model->getKeyName()]['type'] !== 'string') {
                throw new LogicException("{$name}: automatic create requires HasUuids or HasUlids and a string key.");
            }

            $owner = (new ReflectionMethod($class, 'newUniqueId'))->getDeclaringClass()->getName();
            $source = (new ReflectionMethod($class, 'newUniqueId'))->getFileName();
            if (! str_contains(str_replace('\\', '/', $source ?: ''), '/Illuminate/Database/Eloquent/Concerns/')) {
                throw new LogicException("{$owner}: custom newUniqueId needs a matching client generator; automatic generation is unsupported.");
            }

            if ($model->uniqueIds() !== [$model->getKeyName()]) {
                throw new LogicException("{$name}: custom uniqueIds are unsupported.");
            }
        }

        $writable = array_values(array_intersect(array_diff($this->fillable($model), array_filter(array_merge([$model->getKeyName(), $created, $updated, $deleted], array_keys($fills)))), array_keys($columns)));
        $filterDefinitions = array_values($definition->filters);
        foreach ($definition->filters as $filter) {
            foreach ($filter['members'] ?? [] as $member) {
                $filterDefinitions[] = $member;
            }

            if ($filter['kind'] === 'callback' && $filter['field'] !== '') {
                $this->requireColumn($columns, $name, $filter['field']);
            }
        }

        foreach ($filterDefinitions as $filter) {
            if (! is_string($filter['kind']) || ! is_string($filter['field'])) {
                throw new LogicException('Invalid filter definition.');
            }

            if (in_array($filter['kind'], ['exact', 'partial', 'beginsWith', 'endsWith', 'operator'], true)) {
                $this->requireColumn($columns, $name, $filter['field']);
                if ($columns[$filter['field']]['type'] === 'json') {
                    throw new LogicException("{$name}: JSON filters are not supported by Zero.");
                }

                if (in_array($filter['kind'], ['partial', 'beginsWith', 'endsWith'], true) && $columns[$filter['field']]['type'] !== 'string') {
                    throw new LogicException("{$name}: partial filters require a string field.");
                }
            }

            if ($filter['kind'] === 'trashed' && $deleted === null) {
                throw new LogicException("{$name}: trashed filter requires SoftDeletes.");
            }
        }

        foreach (array_merge($definition->sorts, array_column($definition->defaultOrder, 0)) as $field) {
            $this->requireColumn($columns, $name, $field);
            if ($columns[$field]['type'] === 'json') {
                throw new LogicException("{$name}: JSON sorting is not supported by Zero.");
            }
        }

        $metadata = new ModelMetadata($class, $name, $model->getTable(), $model->getKeyName(), $columns, $queries, $mutations, $authorization, $fills, $writable, $generator, $created, $updated, $deleted, $definition, authorizationRelations: $authorizationRelations);
        foreach ($definition->filters as $filterName => $filter) {
            if (isset($filter['callback'])) {
                $query = new \ZeroForLaravel\Query\ZQLBuilder(new \ZeroForLaravel\Query\ZeroOperator($metadata, fn () => throw new LogicException('Callback relationship predicates belong in an explicit server scope.')));
                ($filter['callback'])($query, new \ZeroForLaravel\Query\FilterParameter());
                unset($definition->filters[$filterName]['callback']);
                $definition->filters[$filterName]['expression'] = $query->condition();
            }
        }

        return $metadata;
    }

    private function validateNestedMutations(ModelMetadata $parent): void
    {
        foreach ($parent->definition->nestedMutations as $relations) {
            foreach ($relations as $name => $operations) {
                $relation = $parent->relations[$name];
                $child = $this->get($relation['model']);
                $hop = $relation['path'][0];
                $belongsTo = ($relation['kind'] ?? null) === 'belongsTo';
                $owner = $belongsTo ? $child : $parent;
                $dependent = $belongsTo ? $parent : $child;
                $key = $belongsTo ? $hop['dest'][0] : $hop['source'][0];
                $foreignKey = $belongsTo ? $hop['source'][0] : $hop['dest'][0];
                if ($key !== $owner->key || $foreignKey === $dependent->key || isset($dependent->serverFill[$foreignKey]) || in_array($foreignKey, [$dependent->createdAt, $dependent->updatedAt, $dependent->deletedAt], true)) {
                    throw new LogicException("{$parent->name}.{$name}: nested mutations require primary-key relations and an unprotected foreign key.");
                }

                if (isset($operations['create'])) {
                    $model = new $child->class;
                    if ($child->idGenerator === null || $model->getIncrementing() || $child->columns[$child->key]['type'] !== 'string' || $model->uniqueIds() !== [$child->key]) {
                        throw new LogicException("{$child->name}: nested create requires HasUuids or HasUlids and a string primary key.");
                    }

                    $source = (new ReflectionMethod($child->class, 'newUniqueId'))->getFileName();
                    if (! str_contains(str_replace('\\', '/', $source ?: ''), '/Illuminate/Database/Eloquent/Concerns/')) {
                        throw new LogicException("{$child->name}: custom newUniqueId is unsupported for nested create.");
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function fillable(Model $model): array
    {
        $fields = [];
        foreach ($model->getFillable() as $field) {
            if (! is_string($field)) {
                throw new LogicException('Fillable columns must be strings.');
            }

            $fields[] = $field;
        }

        return $fields;
    }

    private function describeRelations(ModelMetadata $metadata): void
    {
        $model = new $metadata->class;
        $filters = array_filter($metadata->definition->filters, fn (array $f): bool => $f['kind'] === 'belongsTo');
        foreach (array_unique(array_merge($metadata->definition->includes, $metadata->authorizationRelations, $metadata->definition->scopeRelations, array_column($filters, 'field'), ...array_map(array_keys(...), array_values($metadata->definition->nestedMutations)))) as $name) {
            if (isset($metadata->columns[$name]) || $name === 'data' || in_array($name, array_diff($metadata->mutations, ['create']), true)) {
                throw new LogicException("{$metadata->name}.{$name}: relation name conflicts with a model column or instance API.");
            }

            if (! method_exists($model, $name)) {
                throw new LogicException("{$metadata->name}: unknown relation {$name}.");
            }

            $relation = Relation::noConstraints(fn () => $model->$name());
            if (! $relation instanceof Relation) {
                throw new LogicException("{$metadata->name}.{$name}: method must return an Eloquent relation.");
            }

            $targetName = (new ReflectionClass($relation->getRelated()))->getShortName();
            $target = $this->get($targetName);
            if ($target->class !== $relation->getRelated()::class) {
                throw new LogicException("{$metadata->name}.{$name}: target model is not registered.");
            }

            // Eloquent constraints cannot silently disappear when exported to Zero.
            $query = $relation->getQuery();
            $base = $query->getQuery();
            $joins = $base->joins ?? [];
            $join = $joins[0] ?? null;
            $standardJoin = $relation instanceof BelongsToMany && count($joins) === 1 && $join instanceof \Illuminate\Database\Query\JoinClause && is_array($join->wheres) && $join->table === $relation->getTable() && $join->type === 'inner' && count($join->wheres) === 1 && $join->wheres[0] === ['type' => 'Column', 'first' => $relation->getQualifiedRelatedKeyName(), 'operator' => '=', 'second' => $relation->getQualifiedRelatedPivotKeyName(), 'boolean' => 'and'];
            if ($base->wheres || $base->orders || $base->limit || $base->offset || $base->groups || $base->havings || $base->unions || $base->distinct || ($base->columns !== null && $base->columns !== ['*']) || ($joins !== [] && ! $standardJoin)) {
                throw new LogicException("{$metadata->name}.{$name}: constrained Eloquent relations require a custom query, which is not supported yet.");
            }

            if ($relation instanceof BelongsTo) {
                $path = [['source' => [$relation->getForeignKeyName()], 'dest' => [$relation->getOwnerKeyName()], 'table' => $target->table]];
                $cardinality = 'one';
            } elseif ($relation instanceof HasMany || $relation instanceof HasOne) {
                $path = [['source' => [$relation->getLocalKeyName()], 'dest' => [$relation->getForeignKeyName()], 'table' => $target->table]];
                $cardinality = $relation instanceof HasOne ? 'one' : 'many';
            } elseif ($relation instanceof BelongsToMany) {
                $pivot = $relation->getTable();
                $keys = [$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName()];
                $indexes = $model->getConnection()->getSchemaBuilder()->getIndexes($pivot);
                $primary = array_values(array_filter($indexes, fn (array $index): bool => $index['primary']))[0]['columns'] ?? null;
                if ($primary === null) {
                    throw new LogicException("{$metadata->name}.{$name}: the pivot table needs a primary key for Zero.");
                }

                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $pivot) || in_array($pivot, array_column($this->models, 'table'), true)) {
                    throw new LogicException("{$metadata->name}.{$name}: pivots require an unqualified table that is not also a registered model.");
                }

                $this->pivotKeys[$pivot] = $primary;
                $this->pivots[$pivot] = array_values(array_unique(array_merge($this->pivots[$pivot] ?? [], $keys, $primary)));
                $dbColumns = array_column($model->getConnection()->getSchemaBuilder()->getColumns($pivot), null, 'name');
                foreach ($this->pivots[$pivot] as $field) {
                    $column = $dbColumns[$field] ?? throw new LogicException("{$pivot}: missing required pivot column {$field}.");
                    $this->pivotColumns[$pivot][$field] = ['type' => $this->databaseType($column['type_name'], "{$pivot}.{$field}"), 'nullable' => $column['nullable']];
                }

                $path = [
                    ['source' => [$relation->getParentKeyName()], 'dest' => [$keys[0]], 'table' => $pivot],
                    ['source' => [$keys[1]], 'dest' => [$relation->getRelatedKeyName()], 'table' => $target->table],
                ];
                $cardinality = 'many';
            } else {
                throw new LogicException("{$metadata->name}.{$name}: unsupported relation type.");
            }

            foreach ($path[0]['source'] as $field) {
                $this->requireColumn($metadata->columns, $metadata->name, $field);
            }

            foreach (end($path)['dest'] as $field) {
                $this->requireColumn($target->columns, $target->name, $field);
            }

            foreach ($filters as $filter) {
                if ($filter['field'] === $name && ! $relation instanceof BelongsTo) {
                    throw new LogicException("{$metadata->name}.{$name}: belongsTo filter needs BelongsTo.");
                }
            }

            $nested = array_filter($metadata->definition->nestedMutations, fn (array $relations): bool => isset($relations[$name])) !== [];
            if ($nested && (! $relation instanceof BelongsTo && ! $relation instanceof HasOne && ! $relation instanceof HasMany)) {
                throw new LogicException("{$metadata->name}.{$name}: nested mutations require a direct belongsTo, hasOne, or hasMany relation.");
            }

            $metadata->relations[$name] = ['model' => $targetName, 'cardinality' => $cardinality, 'path' => $path,
                ...($nested ? ['kind' => $relation instanceof BelongsTo ? 'belongsTo' : ($relation instanceof HasMany ? 'hasMany' : 'hasOne')] : []),
            ];
        }
    }

    /**
     * @param  array<string, Column>  $columns
     */
    private function requireColumn(array $columns, string $name, string $field): void
    {
        if (! isset($columns[$field])) {
            throw new LogicException("{$name}: required column {$field} is missing or excluded.");
        }
    }

    /**
     * @return 'string'|'number'|'boolean'|'json'
     */
    private function databaseType(string $type, string $path): string
    {
        return match (strtolower($type)) {
            'bool', 'boolean' => 'boolean',
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8', 'float', 'float4', 'float8', 'double', 'double precision', 'real', 'numeric', 'decimal', 'date', 'timestamp', 'timestamptz', 'datetime' => 'number',
            'json', 'jsonb' => 'json',
            'varchar', 'char', 'text', 'uuid', 'bpchar', 'character varying', 'character' => 'string',
            default => throw new LogicException("{$path}: unsupported database type {$type}."),
        };
    }

    /**
     * @return array{known: bool, value: mixed}
     */
    private function literalDefault(mixed $default, string $type): array
    {
        if ($default === null) {
            return ['known' => true, 'value' => null];
        }

        if (! is_scalar($default)) {
            return ['known' => false, 'value' => null];
        }

        $default = preg_replace('/::[a-zA-Z_ ]+$/', '', (string) $default) ?? '';
        if (preg_match("/^'((?:[^']|'')*)'$/s", $default, $match)) {
            if ($type === 'boolean' && in_array(strtolower($match[1]), ['0', '1', 'true', 'false'], true)) {
                return ['known' => true, 'value' => in_array(strtolower($match[1]), ['1', 'true'], true)];
            }

            return ['known' => true, 'value' => $type === 'json' ? json_decode(str_replace("''", "'", $match[1]), true, flags: JSON_THROW_ON_ERROR) : str_replace("''", "'", $match[1])];
        }

        if ($type === 'boolean' && in_array(strtolower($default), ['0', '1', 'true', 'false'], true)) {
            return ['known' => true, 'value' => in_array(strtolower($default), ['1', 'true'], true)];
        }

        if (is_numeric($default)) {
            return ['known' => true, 'value' => $default + 0];
        }

        return ['known' => false, 'value' => null];
    }
}
