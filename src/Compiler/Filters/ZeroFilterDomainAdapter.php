<?php

namespace NickWelsh\LaravelZero\Compiler\Filters;

use BackedEnum;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use RuntimeException;
use UnitEnum;

/**
 * Isolates the compiler from the forthcoming Filters domain API.
 *
 * The expected API is intentionally accessed only in this class so that naming
 * or shape changes in ZeroFilterDefinition/ZeroFilterSchema need one adapter edit.
 *
 * @phpstan-type FilterValue array{value: mixed, label: string}
 * @phpstan-type FilterField array{id: string, label: string, column: string, clientColumn: string, kind: string, operators: list<string>, values: list<FilterValue>}
 * @phpstan-type FilterRelationship array{id: string, label: string, relationship: string, definitionClass: class-string, targetDefinitionId: string}
 * @phpstan-type FilterLimits array{maxDepth: positive-int, maxRelationshipDepth: non-negative-int, maxNodes: positive-int, maxChildren: positive-int, maxInValues: positive-int, maxStringLength: positive-int}
 * @phpstan-type FilterDefinition array{id: string, class: class-string, modelClass: class-string, fields: array<string, FilterField>, relationships: array<string, FilterRelationship>, limits: FilterLimits}
 */
final class ZeroFilterDomainAdapter
{
    private const DEFAULT_LIMITS = [
        'maxDepth' => 8,
        'maxRelationshipDepth' => 3,
        'maxNodes' => 100,
        'maxChildren' => 20,
        'maxInValues' => 50,
        'maxStringLength' => 1000,
    ];

    /** @var array<class-string, array<string, mixed>> */
    private array $definitions = [];

    public function __construct(private readonly ZeroSchemaRegistry $schemas) {}

    /** @param class-string $definitionClass */
    public function definitionId(string $definitionClass): string
    {
        return ltrim($definitionClass, '\\');
    }

    /**
     * @param  class-string  $definitionClass
     * @return FilterDefinition
     */
    public function definition(string $definitionClass): array
    {
        if (isset($this->definitions[$definitionClass])) {
            /** @var FilterDefinition */
            return $this->definitions[$definitionClass];
        }

        if (! class_exists($definitionClass)) {
            throw new InvalidArgumentException("Filter definition [{$definitionClass}] does not exist.");
        }
        $schemaResolver = [$definitionClass, 'schema'];
        if (! is_callable($schemaResolver)) {
            $definition = new $definitionClass;
            $schemaResolver = $this->callableMethod($definition, 'schema');
        }
        if ($schemaResolver === null) {
            throw new InvalidArgumentException("Filter definition [{$definitionClass}] must expose schema(ZeroSchemaRegistry) statically or on a no-argument instance.");
        }

        $schema = $schemaResolver($this->schemas);
        if (! is_object($schema)) {
            throw new RuntimeException("Filter definition [{$definitionClass}] did not return an object schema.");
        }

        $modelClass = $this->classString(
            $this->requiredProperty($schema, ['modelClass', 'model'], "{$definitionClass} schema modelClass"),
            "{$definitionClass} schema modelClass",
        );
        $fields = [];
        foreach ($this->iterableProperty($schema, 'fields') as $descriptor) {
            $field = $this->field($descriptor, $definitionClass);
            if (isset($fields[$field['id']])) {
                throw new RuntimeException("Filter definition [{$definitionClass}] contains duplicate field id [{$field['id']}].");
            }
            $fields[$field['id']] = $field;
        }
        $relationships = [];
        foreach ($this->iterableProperty($schema, 'relationships') as $descriptor) {
            $relationship = $this->relationship($descriptor, $definitionClass);
            if (isset($relationships[$relationship['id']])) {
                throw new RuntimeException("Filter definition [{$definitionClass}] contains duplicate relationship id [{$relationship['id']}].");
            }
            $relationships[$relationship['id']] = $relationship;
        }
        /** @var FilterDefinition $normalized */
        $normalized = [
            'id' => $this->definitionId($definitionClass),
            'class' => $definitionClass,
            'modelClass' => $modelClass,
            'fields' => $fields,
            'relationships' => $relationships,
            'limits' => $this->limits($schema, $definitionClass),
        ];
        $this->definitions[$definitionClass] = $normalized;

        return $normalized;
    }

    /**
     * Verify against the existing Zero schema bridge when it exposes related
     * model metadata. If a future registry does not, this check is skipped.
     *
     * @param  class-string  $sourceModelClass
     * @param  class-string  $targetModelClass
     */
    public function verifyRelationshipModel(string $sourceModelClass, string $relationship, string $targetModelClass): void
    {
        $modelResolver = $this->callableMethod($this->schemas, 'model');
        if ($modelResolver === null) {
            return;
        }

        $modelSchema = $modelResolver($sourceModelClass);
        if (! is_object($modelSchema)) {
            return;
        }

        $relationshipResolver = $this->callableMethod($modelSchema, 'relationship');
        $relationshipSchema = $relationshipResolver === null ? null : $relationshipResolver($relationship);
        if ($relationshipSchema === null) {
            $relationships = $this->firstProperty($modelSchema, ['relationships']);
            if (is_array($relationships)) {
                $relationshipSchema = $relationships[$relationship] ?? null;
            }
        }

        if (! is_object($relationshipSchema)) {
            return;
        }

        $relatedModel = $this->firstProperty($relationshipSchema, ['relatedModel', 'modelClass']);
        if ($relatedModel === null) {
            return;
        }

        $relatedModel = $this->classString($relatedModel, "relationship {$sourceModelClass}::{$relationship} related model");
        if (ltrim($relatedModel, '\\') !== ltrim($targetModelClass, '\\')) {
            throw new RuntimeException(
                "Filter relationship [{$sourceModelClass}::{$relationship}] targets [{$targetModelClass}], but the Zero schema relationship targets [{$relatedModel}].",
            );
        }
    }

    /** @return FilterField */
    private function field(mixed $descriptor, string $definitionClass): array
    {
        $context = "filter field in {$definitionClass}";
        $id = $this->string($this->property($descriptor, 'id'), "{$context} id");
        $operators = [];

        foreach ($this->iterableProperty($descriptor, 'operators') as $operator) {
            $value = $operator instanceof BackedEnum ? $operator->value : $operator;
            if (! is_string($value) || $value === '') {
                throw new RuntimeException("{$context} [{$id}] operators must be non-empty string-backed enum values or strings.");
            }
            $operators[] = $value;
        }
        $operators = array_values(array_unique($operators));

        if ($operators === []) {
            throw new RuntimeException("{$context} [{$id}] must define at least one operator.");
        }

        $values = [];
        foreach ($this->iterableProperty($descriptor, 'values') as $value) {
            $values[] = [
                'value' => $this->property($value, 'value'),
                'label' => $this->string($this->property($value, 'label'), "{$context} [{$id}] value label"),
            ];
        }
        $kind = $this->property($descriptor, 'kind');
        $kind = match (true) {
            $kind instanceof BackedEnum => $kind->value,
            $kind instanceof UnitEnum => $kind->name,
            default => $kind,
        };

        return [
            'id' => $id,
            'label' => $this->label($this->property($descriptor, 'label'), $id, "{$context} [{$id}] label"),
            'column' => $this->string($this->property($descriptor, 'column'), "{$context} [{$id}] column"),
            'clientColumn' => $this->string($this->property($descriptor, 'clientColumn'), "{$context} [{$id}] clientColumn"),
            'kind' => $this->string($kind, "{$context} [{$id}] kind"),
            'operators' => $operators,
            'values' => $values,
        ];
    }

    /** @return FilterRelationship */
    private function relationship(mixed $descriptor, string $definitionClass): array
    {
        $context = "filter relationship in {$definitionClass}";
        $id = $this->string($this->property($descriptor, 'id'), "{$context} id");
        $target = $this->classString(
            $this->requiredProperty($descriptor, ['definitionClass', 'definition'], "{$context} [{$id}] definitionClass"),
            "{$context} [{$id}] definitionClass",
        );

        return [
            'id' => $id,
            'label' => $this->label($this->property($descriptor, 'label'), $id, "{$context} [{$id}] label"),
            'relationship' => $this->string($this->property($descriptor, 'relationship'), "{$context} [{$id}] relationship"),
            'definitionClass' => $target,
            'targetDefinitionId' => $this->definitionId($target),
        ];
    }

    /** @return FilterLimits */
    private function limits(object $schema, string $definitionClass): array
    {
        $container = property_exists($schema, 'limits') && is_object($schema->limits) ? $schema->limits : $schema;
        $aliases = [
            'maxDepth' => ['maxDepth', 'depth'],
            'maxRelationshipDepth' => ['maxRelationshipDepth', 'relationshipDepth'],
            'maxNodes' => ['maxNodes', 'nodes'],
            'maxChildren' => ['maxChildren', 'children'],
            'maxInValues' => ['maxInValues', 'maxIn', 'inValues', 'in'],
            'maxStringLength' => ['maxStringLength', 'maxString', 'stringLength', 'string'],
        ];
        $limits = [];

        foreach ($aliases as $name => $properties) {
            $value = $this->firstProperty($container, $properties) ?? self::DEFAULT_LIMITS[$name];
            $minimum = $name === 'maxRelationshipDepth' ? 0 : 1;
            if (! is_int($value) || $value < $minimum) {
                throw new RuntimeException("Filter definition [{$definitionClass}] limit [{$name}] must be an integer greater than or equal to {$minimum}.");
            }
            $limits[$name] = $value;
        }

        /** @var FilterLimits */
        return $limits;
    }

    /** @return iterable<mixed> */
    private function iterableProperty(mixed $source, string $name): iterable
    {
        $value = $this->property($source, $name);
        if (! is_iterable($value)) {
            throw new RuntimeException("Filter schema property [{$name}] must be iterable.");
        }

        return $value;
    }

    private function property(mixed $source, string $name): mixed
    {
        if (is_array($source) && array_key_exists($name, $source)) {
            return $source[$name];
        }
        if (is_object($source) && property_exists($source, $name)) {
            return $source->{$name};
        }

        throw new RuntimeException("Expected public filter domain property [{$name}].");
    }

    /** @param  list<string>  $names */
    private function requiredProperty(mixed $source, array $names, string $context): mixed
    {
        foreach ($names as $name) {
            if (is_array($source) && array_key_exists($name, $source)) {
                return $source[$name];
            }
            if (is_object($source) && property_exists($source, $name)) {
                return $source->{$name};
            }
        }

        throw new RuntimeException("Expected public filter domain property [{$context}].");
    }

    private function callableMethod(object $source, string $method): ?callable
    {
        $callable = [$source, $method];

        return is_callable($callable) ? $callable : null;
    }

    /** @param  list<string>  $names */
    private function firstProperty(object $source, array $names): mixed
    {
        foreach ($names as $name) {
            if (property_exists($source, $name)) {
                return $source->{$name};
            }
        }

        return null;
    }

    private function label(mixed $value, string $fallback, string $context): string
    {
        return $value === null ? $fallback : $this->string($value, $context);
    }

    private function string(mixed $value, string $context): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Expected {$context} to be a non-empty string.");
        }

        return $value;
    }

    /** @return class-string */
    private function classString(mixed $value, string $context): string
    {
        $value = $this->string($value, $context);
        if (! class_exists($value)) {
            throw new RuntimeException("Expected {$context} [{$value}] to name an existing class.");
        }

        return $value;
    }
}
