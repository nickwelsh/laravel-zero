<?php

namespace NickWelsh\LaravelZero\Filters;

use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;

final readonly class ZeroFilterValidator
{
    public function __construct(private ZeroSchemaRegistry $registry) {}

    /**
     * @param  class-string<ZeroFilterDefinition>  $definition
     * @return array<string, mixed>
     */
    public function validate(mixed $value, string $definition): array
    {
        /** @var array<class-string<ZeroFilterDefinition>, ZeroFilterSchema> $schemas */
        $schemas = [];
        $schema = $this->schema($definition, $schemas);
        $nodes = 0;

        return $this->node($value, $schema, '$', 0, 0, $nodes, $schema, $schemas);
    }

    /**
     * @param  array<class-string<ZeroFilterDefinition>, ZeroFilterSchema>  $schemas
     * @return array<string, mixed>
     */
    private function node(
        mixed $value,
        ZeroFilterSchema $schema,
        string $path,
        int $groupDepth,
        int $relationshipDepth,
        int &$nodes,
        ZeroFilterSchema $rootSchema,
        array &$schemas,
    ): array {
        $value = $this->object($value, $path);

        $nodes++;
        if ($nodes > $rootSchema->maxNodes) {
            $this->invalid($path, "exceeds the maximum of {$rootSchema->maxNodes} filter nodes.");
        }

        $type = $value['type'] ?? null;
        if (! is_string($type)) {
            $this->invalid($path.'.type', 'is required and must be a string.');
        }

        return match ($type) {
            'condition' => $this->condition($value, $schema, $path),
            'group' => $this->group(
                $value,
                $schema,
                $path,
                $groupDepth,
                $relationshipDepth,
                $nodes,
                $rootSchema,
                $schemas,
            ),
            'relationship' => $this->relationship(
                $value,
                $schema,
                $path,
                $groupDepth,
                $relationshipDepth,
                $nodes,
                $rootSchema,
                $schemas,
            ),
            default => $this->invalid($path.'.type', "must be one of [condition, group, relationship]; [{$type}] given."),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function condition(array $node, ZeroFilterSchema $schema, string $path): array
    {
        $this->keys($node, ['type', 'field', 'operator'], ['type', 'field', 'operator', 'value'], $path);

        $fieldId = $node['field'];
        if (! is_string($fieldId) || $fieldId === '') {
            $this->invalid($path.'.field', 'must be a non-empty string.');
        }
        $field = $schema->fields[$fieldId] ?? null;
        if ($field === null) {
            $this->invalid($path.'.field', "references unknown or private field [{$fieldId}].");
        }

        $operatorValue = $node['operator'];
        if (! is_string($operatorValue)) {
            $this->invalid($path.'.operator', 'must be a string.');
        }
        $operator = ZeroFilterOperator::tryFrom($operatorValue);
        if ($operator === null || ! in_array($operator, $field->operators, true)) {
            $allowed = implode(', ', array_map(
                static fn (ZeroFilterOperator $allowed): string => $allowed->value,
                $field->operators,
            ));
            $this->invalid($path.'.operator', "is not allowed for field [{$fieldId}]; expected one of [{$allowed}].");
        }

        if (! $operator->requiresValue()) {
            if (array_key_exists('value', $node)) {
                $this->invalid($path.'.value', "must be omitted for operator [{$operator->value}].");
            }

            return [
                'type' => 'condition',
                'field' => $fieldId,
                'operator' => $operator->value,
            ];
        }

        if (! array_key_exists('value', $node)) {
            $this->invalid($path.'.value', "is required for operator [{$operator->value}].");
        }

        $conditionValue = $node['value'];
        if ($operator->requiresList()) {
            if (! is_array($conditionValue) || ! array_is_list($conditionValue)) {
                $this->invalid($path.'.value', "must be a list for operator [{$operator->value}].");
            }
            if ($conditionValue === []) {
                $this->invalid($path.'.value', "must contain at least one item for operator [{$operator->value}].");
            }
            if (count($conditionValue) > $schema->maxInValues) {
                $this->invalid($path.'.value', "may contain at most {$schema->maxInValues} items.");
            }
            foreach ($conditionValue as $index => $item) {
                $this->fieldValue($item, $field, $schema, $path.'.value['.$index.']');
            }
        } else {
            $this->fieldValue($conditionValue, $field, $schema, $path.'.value');
        }

        return [
            'type' => 'condition',
            'field' => $fieldId,
            'operator' => $operator->value,
            'value' => $conditionValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<class-string<ZeroFilterDefinition>, ZeroFilterSchema>  $schemas
     * @return array<string, mixed>
     */
    private function group(
        array $node,
        ZeroFilterSchema $schema,
        string $path,
        int $groupDepth,
        int $relationshipDepth,
        int &$nodes,
        ZeroFilterSchema $rootSchema,
        array &$schemas,
    ): array {
        $this->keys($node, ['type', 'combinator', 'children'], ['type', 'combinator', 'children'], $path);

        $groupDepth++;
        if ($groupDepth > $rootSchema->maxDepth) {
            $this->invalid($path, "exceeds the maximum group depth of {$rootSchema->maxDepth}.");
        }

        $combinatorValue = $node['combinator'];
        $combinator = is_string($combinatorValue) ? ZeroFilterCombinator::tryFrom($combinatorValue) : null;
        if ($combinator === null) {
            $this->invalid($path.'.combinator', 'must be one of [and, or].');
        }

        $children = $node['children'];
        if (! is_array($children) || ! array_is_list($children)) {
            $this->invalid($path.'.children', 'must be a list.');
        }
        if ($children === []) {
            $this->invalid($path.'.children', 'must contain at least one filter node.');
        }
        if (count($children) > $schema->maxChildren) {
            $this->invalid($path.'.children', "may contain at most {$schema->maxChildren} nodes.");
        }

        $validatedChildren = [];
        foreach ($children as $index => $child) {
            $validatedChildren[] = $this->node(
                $child,
                $schema,
                $path.'.children['.$index.']',
                $groupDepth,
                $relationshipDepth,
                $nodes,
                $rootSchema,
                $schemas,
            );
        }

        return [
            'type' => 'group',
            'combinator' => $combinator->value,
            'children' => $validatedChildren,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<class-string<ZeroFilterDefinition>, ZeroFilterSchema>  $schemas
     * @return array<string, mixed>
     */
    private function relationship(
        array $node,
        ZeroFilterSchema $schema,
        string $path,
        int $groupDepth,
        int $relationshipDepth,
        int &$nodes,
        ZeroFilterSchema $rootSchema,
        array &$schemas,
    ): array {
        $this->keys($node, ['type', 'relationship', 'quantifier'], ['type', 'relationship', 'quantifier', 'filter'], $path);

        $relationshipId = $node['relationship'];
        if (! is_string($relationshipId) || $relationshipId === '') {
            $this->invalid($path.'.relationship', 'must be a non-empty string.');
        }
        $relationship = $schema->relationships[$relationshipId] ?? null;
        if ($relationship === null) {
            $this->invalid($path.'.relationship', "references unknown or private relationship [{$relationshipId}].");
        }

        $quantifierValue = $node['quantifier'];
        $quantifier = is_string($quantifierValue) ? ZeroFilterQuantifier::tryFrom($quantifierValue) : null;
        if ($quantifier === null) {
            $this->invalid($path.'.quantifier', 'must be [some].');
        }

        $relationshipDepth++;
        if ($relationshipDepth > $rootSchema->maxRelationshipDepth) {
            $this->invalid($path, "exceeds the maximum relationship depth of {$rootSchema->maxRelationshipDepth}.");
        }

        $validated = [
            'type' => 'relationship',
            'relationship' => $relationshipId,
            'quantifier' => $quantifier->value,
        ];

        if (array_key_exists('filter', $node)) {
            $relatedSchema = $this->schema($relationship->definition, $schemas);
            $validated['filter'] = $this->node(
                $node['filter'],
                $relatedSchema,
                $path.'.filter',
                $groupDepth,
                $relationshipDepth,
                $nodes,
                $rootSchema,
                $schemas,
            );
        }

        return $validated;
    }

    private function fieldValue(mixed $value, ZeroFilterField $field, ZeroFilterSchema $schema, string $path): void
    {
        $valid = match ($field->kind) {
            ZeroFilterKind::String, ZeroFilterKind::Date => is_string($value),
            ZeroFilterKind::Number => is_int($value) || (is_float($value) && is_finite($value)),
            ZeroFilterKind::Boolean => is_bool($value),
            ZeroFilterKind::Enum => is_int($value) || is_string($value),
        };
        if (! $valid) {
            $expected = match ($field->kind) {
                ZeroFilterKind::String => 'a string',
                ZeroFilterKind::Number => 'a finite integer or float',
                ZeroFilterKind::Boolean => 'a boolean',
                ZeroFilterKind::Date => 'a date string',
                ZeroFilterKind::Enum => 'an integer or string enum value',
            };
            $this->invalid($path, "must be {$expected} for field [{$field->id}].");
        }

        if (is_string($value) && $this->stringLength($value) > $schema->maxStringLength) {
            $this->invalid($path, "may not exceed {$schema->maxStringLength} characters.");
        }

        if ($field->values !== []) {
            $allowed = array_column($field->values, 'value');
            if (! in_array($value, $allowed, true)) {
                $printable = implode(', ', array_map(
                    static fn (bool|float|int|string $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item,
                    $allowed,
                ));
                $this->invalid($path, "must be one of [{$printable}] for field [{$field->id}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     */
    private function keys(array $node, array $required, array $allowed, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $node)) {
                $this->invalid($path.'.'.$key, 'is required.');
            }
        }

        foreach (array_keys($node) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->invalid($path, 'contains unknown key ['.$key.'].');
            }
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->invalid($path, 'must be an object-shaped array.');
        }
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                $this->invalid($path, 'must contain only string keys.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  class-string<ZeroFilterDefinition>  $definition
     * @param  array<class-string<ZeroFilterDefinition>, ZeroFilterSchema>  $schemas
     */
    private function schema(string $definition, array &$schemas): ZeroFilterSchema
    {
        if (isset($schemas[$definition])) {
            return $schemas[$definition];
        }

        return $schemas[$definition] = ZeroFilterDefinition::make($definition)->schema($this->registry);
    }

    private function stringLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException('Filter strings must contain valid UTF-8.');
        }

        return $length;
    }

    private function invalid(string $path, string $message): never
    {
        throw new InvalidArgumentException("Invalid filter at [{$path}]: {$message}");
    }
}
