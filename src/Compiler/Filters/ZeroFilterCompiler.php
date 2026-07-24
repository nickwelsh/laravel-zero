<?php

namespace NickWelsh\LaravelZero\Compiler\Filters;

use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use RuntimeException;

/**
 * Compiles a filter definition graph into self-contained TypeScript.
 *
 * Domain integration assumptions are isolated in ZeroFilterDomainAdapter.
 *
 * @phpstan-import-type FilterDefinition from ZeroFilterDomainAdapter
 * @phpstan-import-type FilterField from ZeroFilterDomainAdapter
 * @phpstan-import-type FilterRelationship from ZeroFilterDomainAdapter
 */
final readonly class ZeroFilterCompiler
{
    private ZeroFilterDomainAdapter $adapter;

    public function __construct(private ZeroSchemaRegistry $schemas)
    {
        $this->adapter = new ZeroFilterDomainAdapter($schemas);
    }

    /** @param class-string $definitionClass */
    public static function metadataName(string $definitionClass): string
    {
        return lcfirst(self::basename($definitionClass));
    }

    /** @param class-string $definitionClass */
    public static function schemaName(string $definitionClass): string
    {
        return lcfirst(self::nodeStem($definitionClass)).'Schema';
    }

    /** @param class-string $definitionClass */
    public static function applyName(string $definitionClass): string
    {
        return 'apply'.self::basename($definitionClass);
    }

    /** @param class-string $definitionClass */
    public static function typeName(string $definitionClass): string
    {
        return self::nodeStem($definitionClass).'Node';
    }

    /** @param class-string $definitionClass */
    public function compile(string $definitionClass): string
    {
        $definitions = [];
        $classesById = [];
        $this->collect($definitionClass, $definitions, $classesById);
        ksort($definitions, SORT_STRING);

        $metadata = self::metadataName($definitionClass);
        $schema = self::schemaName($definitionClass);
        $apply = self::applyName($definitionClass);
        $type = self::typeName($definitionClass);
        $rootDefinitionId = $this->adapter->definitionId($definitionClass);
        $metadataJson = json_encode([
            'rootDefinitionId' => $rootDefinitionId,
            'definitions' => $definitions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $source = <<<'TYPESCRIPT'
import { z as __ZOD__ } from 'zod';

type __PREFIX__Value = string | number | boolean | readonly (string | number | boolean)[];

export type __NODE_TYPE__ =
  | {type: 'condition'; field: string; operator: string; value?: __PREFIX__Value}
  | {type: 'group'; combinator: 'and' | 'or'; children: __NODE_TYPE__[]}
  | {type: 'relationship'; relationship: string; quantifier: 'some'; filter?: __NODE_TYPE__};

export const __METADATA__ = __METADATA_JSON__ as const;

type __PREFIX__RuntimeField = {
  readonly id: string;
  readonly label: string;
  readonly column: string;
  readonly clientColumn: string;
  readonly kind: string;
  readonly operators: readonly string[];
  readonly values: readonly {readonly value: unknown; readonly label: string}[];
};

type __PREFIX__RuntimeRelationship = {
  readonly id: string;
  readonly label: string;
  readonly relationship: string;
  readonly targetDefinitionId: string;
};

type __PREFIX__RuntimeDefinition = {
  readonly id: string;
  readonly modelClass: string;
  readonly fields: readonly __PREFIX__RuntimeField[];
  readonly relationships: readonly __PREFIX__RuntimeRelationship[];
  readonly limits: {
    readonly maxDepth: number;
    readonly maxRelationshipDepth: number;
    readonly maxNodes: number;
    readonly maxChildren: number;
    readonly maxInValues: number;
    readonly maxStringLength: number;
  };
};

type __PREFIX__SemanticOperator = {
  readonly zql: string;
  readonly mode: 'literal' | 'in' | 'null' | 'contains' | 'startsWith' | 'endsWith' | 'empty';
  readonly negate?: boolean;
};

const __PREFIX__Definitions = __METADATA__.definitions as unknown as Readonly<Record<string, __PREFIX__RuntimeDefinition>>;

const __PREFIX__IsRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const __PREFIX__HasOnlyKeys = (
  value: Record<string, unknown>,
  allowed: readonly string[],
  path: (string | number)[],
  issue: (message: string, path: (string | number)[]) => void,
): boolean => {
  const unknown = Object.keys(value).find(key => !allowed.includes(key));
  if (unknown === undefined) return true;
  issue(`Filter node contains unknown key [${unknown}].`, path);
  return false;
};

const __PREFIX__Normalize = (value: string): string =>
  value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

const __PREFIX__Semantic = (operator: string): __PREFIX__SemanticOperator | null => {
  switch (operator.trim().toUpperCase()) {
    case '=': return {zql: '=', mode: 'literal'};
    case '!=':
    case '<>': return {zql: '!=', mode: 'literal'};
    case '>': return {zql: '>', mode: 'literal'};
    case '>=': return {zql: '>=', mode: 'literal'};
    case '<': return {zql: '<', mode: 'literal'};
    case '<=': return {zql: '<=', mode: 'literal'};
    case 'IN': return {zql: 'IN', mode: 'in'};
    case 'NOT IN': return {zql: 'NOT IN', mode: 'in'};
    case 'IS NULL': return {zql: 'IS', mode: 'null'};
    case 'IS NOT NULL': return {zql: 'IS NOT', mode: 'null'};
    case 'LIKE': return {zql: 'LIKE', mode: 'literal'};
    case 'NOT LIKE': return {zql: 'NOT LIKE', mode: 'literal'};
    case 'ILIKE': return {zql: 'ILIKE', mode: 'literal'};
    case 'NOT ILIKE': return {zql: 'NOT ILIKE', mode: 'literal'};
  }

  switch (__PREFIX__Normalize(operator)) {
    case 'eq':
    case 'equal':
    case 'equals':
    case 'is': return {zql: '=', mode: 'literal'};
    case 'ne':
    case 'neq':
    case 'not_equal':
    case 'not_equals':
    case 'is_not': return {zql: '!=', mode: 'literal'};
    case 'gt':
    case 'greater_than':
    case 'after': return {zql: '>', mode: 'literal'};
    case 'gte':
    case 'greater_than_or_equal':
    case 'greater_than_or_equal_to':
    case 'on_or_after': return {zql: '>=', mode: 'literal'};
    case 'lt':
    case 'less_than':
    case 'before': return {zql: '<', mode: 'literal'};
    case 'lte':
    case 'less_than_or_equal':
    case 'less_than_or_equal_to':
    case 'on_or_before': return {zql: '<=', mode: 'literal'};
    case 'in': return {zql: 'IN', mode: 'in'};
    case 'not_in': return {zql: 'NOT IN', mode: 'in'};
    case 'is_null':
    case 'null': return {zql: 'IS', mode: 'null'};
    case 'is_not_null':
    case 'not_null': return {zql: 'IS NOT', mode: 'null'};
    case 'contains': return {zql: 'ILIKE', mode: 'contains'};
    case 'not_contains':
    case 'does_not_contain': return {zql: 'NOT ILIKE', mode: 'contains'};
    case 'starts_with': return {zql: 'ILIKE', mode: 'startsWith'};
    case 'not_starts_with':
    case 'does_not_start_with': return {zql: 'NOT ILIKE', mode: 'startsWith'};
    case 'ends_with': return {zql: 'ILIKE', mode: 'endsWith'};
    case 'not_ends_with':
    case 'does_not_end_with': return {zql: 'NOT ILIKE', mode: 'endsWith'};
    case 'is_empty':
    case 'empty': return {zql: '=', mode: 'empty'};
    case 'is_not_empty':
    case 'not_empty': return {zql: '!=', mode: 'empty'};
    default: return null;
  }
};

const __PREFIX__EscapeLike = (value: unknown): string =>
  String(value).replace(/[\\%_]/g, '\\$&');

const __PREFIX__ValidateScalar = (
  value: unknown,
  field: __PREFIX__RuntimeField,
  maxStringLength: number,
  path: (string | number)[],
  issue: (message: string, path: (string | number)[]) => void,
): void => {
  const kind = __PREFIX__Normalize(field.kind);
  let valid = true;

  if (['string', 'text', 'uuid', 'date', 'datetime', 'date_time', 'timestamp'].includes(kind)) {
    valid = typeof value === 'string';
  } else if (['number', 'numeric', 'float', 'decimal'].includes(kind)) {
    valid = typeof value === 'number' && Number.isFinite(value);
  } else if (['integer', 'int'].includes(kind)) {
    valid = typeof value === 'number' && Number.isInteger(value);
  } else if (['boolean', 'bool'].includes(kind)) {
    valid = typeof value === 'boolean';
  } else if (['enum', 'option', 'options', 'select'].includes(kind)) {
    valid = typeof value === 'string' || (typeof value === 'number' && Number.isInteger(value));
  } else {
    issue(`Field [${field.id}] has unsupported kind [${field.kind}].`, path);
    return;
  }

  if (!valid) {
    issue(`Value has the wrong type for field [${field.id}] of kind [${field.kind}].`, path);
    return;
  }
  if (typeof value === 'string' && Array.from(value).length > maxStringLength) {
    issue(`Field [${field.id}] exceeds the maximum string length.`, path);
    return;
  }
  if (field.values.length > 0 && !field.values.some(option => Object.is(option.value, value))) {
    issue(`Value is not an allowed option for field [${field.id}].`, path);
  }
};

const __PREFIX__Validate = (
  input: unknown,
  issue: (message: string, path: (string | number)[]) => void,
): void => {
  const root = __PREFIX__Definitions[__METADATA__.rootDefinitionId];
  if (!root) {
    issue('Root filter definition metadata is missing.', []);
    return;
  }

  let nodes = 0;
  const visit = (
    value: unknown,
    definitionId: string,
    groupDepth: number,
    relationshipDepth: number,
    path: (string | number)[],
  ): void => {
    nodes += 1;
    const definition = __PREFIX__Definitions[definitionId];
    if (!definition) {
      issue(`Unknown filter definition [${definitionId}].`, path);
      return;
    }
    if (nodes > root.limits.maxNodes) {
      issue('Filter exceeds the maximum node count.', path);
      return;
    }
    if (!__PREFIX__IsRecord(value) || typeof value.type !== 'string') {
      issue('Filter node must be an object with a type.', path);
      return;
    }

    if (value.type === 'condition') {
      if (!__PREFIX__HasOnlyKeys(value, ['type', 'field', 'operator', 'value'], path, issue)) return;
      if (typeof value.field !== 'string') {
        issue('Condition field must be a string.', [...path, 'field']);
        return;
      }
      if (typeof value.operator !== 'string') {
        issue('Condition operator must be a string.', [...path, 'operator']);
        return;
      }
      const field = definition.fields.find(candidate => candidate.id === value.field);
      if (!field) {
        issue(`Unknown field [${value.field}] for definition [${definitionId}].`, [...path, 'field']);
        return;
      }
      if (!field.operators.includes(value.operator)) {
        issue(`Operator [${value.operator}] is not allowed for field [${field.id}].`, [...path, 'operator']);
        return;
      }
      const semantic = __PREFIX__Semantic(value.operator);
      if (!semantic) {
        issue(`Operator [${value.operator}] has no ZQL mapping.`, [...path, 'operator']);
        return;
      }
      if (semantic.mode === 'null' || semantic.mode === 'empty') {
        if ('value' in value) {
          issue(`Operator [${value.operator}] does not accept a value.`, [...path, 'value']);
        }
        return;
      }
      if (!('value' in value)) {
        issue(`Operator [${value.operator}] requires a value.`, [...path, 'value']);
        return;
      }
      if (semantic.mode === 'in') {
        if (!Array.isArray(value.value)) {
          issue(`Operator [${value.operator}] requires an array value.`, [...path, 'value']);
          return;
        }
        if (value.value.length === 0) issue('IN values must not be empty.', [...path, 'value']);
        if (value.value.length > definition.limits.maxInValues) {
          issue('IN values exceed the configured maximum.', [...path, 'value']);
          return;
        }
        value.value.forEach((entry, index) => __PREFIX__ValidateScalar(
          entry,
          field,
          definition.limits.maxStringLength,
          [...path, 'value', index],
          issue,
        ));
        return;
      }
      __PREFIX__ValidateScalar(value.value, field, definition.limits.maxStringLength, [...path, 'value'], issue);
      return;
    }

    if (value.type === 'group') {
      if (!__PREFIX__HasOnlyKeys(value, ['type', 'combinator', 'children'], path, issue)) return;
      if (value.combinator !== 'and' && value.combinator !== 'or') {
        issue('Group combinator must be "and" or "or".', [...path, 'combinator']);
      }
      if (!Array.isArray(value.children)) {
        issue('Group children must be an array.', [...path, 'children']);
        return;
      }
      const nextGroupDepth = groupDepth + 1;
      if (nextGroupDepth > root.limits.maxDepth) {
        issue('Filter exceeds the maximum group depth.', path);
        return;
      }
      if (value.children.length === 0) issue('Group children must not be empty.', [...path, 'children']);
      if (value.children.length > definition.limits.maxChildren) {
        issue('Group exceeds the maximum child count.', [...path, 'children']);
        return;
      }
      value.children.forEach((child, index) => visit(
        child,
        definitionId,
        nextGroupDepth,
        relationshipDepth,
        [...path, 'children', index],
      ));
      return;
    }

    if (value.type === 'relationship') {
      if (!__PREFIX__HasOnlyKeys(value, ['type', 'relationship', 'quantifier', 'filter'], path, issue)) return;
      if (typeof value.relationship !== 'string') {
        issue('Relationship id must be a string.', [...path, 'relationship']);
        return;
      }
      if (value.quantifier !== 'some') {
        issue('Relationship quantifier must be "some".', [...path, 'quantifier']);
      }
      const relationship = definition.relationships.find(candidate => candidate.id === value.relationship);
      if (!relationship) {
        issue(`Unknown relationship [${value.relationship}] for definition [${definitionId}].`, [...path, 'relationship']);
        return;
      }
      const nextRelationshipDepth = relationshipDepth + 1;
      if (nextRelationshipDepth > root.limits.maxRelationshipDepth) {
        issue('Filter exceeds the maximum relationship depth.', path);
        return;
      }
      if ('filter' in value) {
        visit(value.filter, relationship.targetDefinitionId, groupDepth, nextRelationshipDepth, [...path, 'filter']);
      }
      return;
    }

    issue(`Unknown filter node type [${value.type}].`, [...path, 'type']);
  };

  visit(input, __METADATA__.rootDefinitionId, 0, 0, []);
};

export const __SCHEMA__ = __ZOD__.custom<__NODE_TYPE__>((value): value is __NODE_TYPE__ => true).superRefine(
  (value, context) => __PREFIX__Validate(
    value,
    (message, path) => context.addIssue({code: 'custom', message, path}),
  ),
);

const __PREFIX__CanonicalValue = (field: __PREFIX__RuntimeField, value: unknown): unknown => {
  if (field.values.length === 0) return value;
  const option = field.values.find(candidate => Object.is(candidate.value, value));
  if (!option) throw new Error(`Value is not an allowed option for filter field [${field.id}].`);
  return option.value;
};

const __PREFIX__ApplyNode = (expressionBuilder: any, node: __NODE_TYPE__, definitionId: string): any => {
  const definition = __PREFIX__Definitions[definitionId];
  if (!definition) throw new Error(`Unknown filter definition [${definitionId}].`);

  if (node.type === 'group') {
    const conditions = node.children.map(child => __PREFIX__ApplyNode(expressionBuilder, child, definitionId));
    return node.combinator === 'and' ? expressionBuilder.and(...conditions) : expressionBuilder.or(...conditions);
  }

  if (node.type === 'relationship') {
    if (node.quantifier !== 'some') throw new Error(`Unsupported relationship quantifier [${node.quantifier as string}].`);
    const relationship = definition.relationships.find(candidate => candidate.id === node.relationship);
    if (!relationship) throw new Error(`Unknown filter relationship [${node.relationship}].`);
    if (node.filter === undefined) return expressionBuilder.exists(relationship.relationship);
    return expressionBuilder.exists(
      relationship.relationship,
      (query: any) => query.where(
        (relatedExpressionBuilder: any) => __PREFIX__ApplyNode(relatedExpressionBuilder, node.filter as __NODE_TYPE__, relationship.targetDefinitionId),
      ),
    );
  }

  const field = definition.fields.find(candidate => candidate.id === node.field);
  if (!field) throw new Error(`Unknown filter field [${node.field}].`);
  if (!field.operators.includes(node.operator)) throw new Error(`Operator [${node.operator}] is not allowed for filter field [${field.id}].`);
  const semantic = __PREFIX__Semantic(node.operator);
  if (!semantic) throw new Error(`Operator [${node.operator}] has no ZQL mapping.`);

  if (semantic.mode === 'null') return expressionBuilder.cmp(field.clientColumn, semantic.zql, null);
  if (semantic.mode === 'empty') return expressionBuilder.cmp(field.clientColumn, semantic.zql, '');
  if (semantic.mode === 'in') {
    if (!Array.isArray(node.value)) throw new Error(`Operator [${node.operator}] requires an array value.`);
    return expressionBuilder.cmp(
      field.clientColumn,
      semantic.zql,
      node.value.map(value => __PREFIX__CanonicalValue(field, value)),
    );
  }

  const value = __PREFIX__CanonicalValue(field, node.value);
  if (semantic.mode === 'contains') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `%${__PREFIX__EscapeLike(value)}%`);
  if (semantic.mode === 'startsWith') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `${__PREFIX__EscapeLike(value)}%`);
  if (semantic.mode === 'endsWith') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `%${__PREFIX__EscapeLike(value)}`);
  return expressionBuilder.cmp(field.clientColumn, semantic.zql, value);
};

export const __APPLY__ = (expressionBuilder: any, node: __NODE_TYPE__): any =>
  __PREFIX__ApplyNode(expressionBuilder, node, __METADATA__.rootDefinitionId);
TYPESCRIPT;

        return strtr($source, [
            '__ZOD__' => $metadata.'Zod',
            '__NODE_TYPE__' => $type,
            '__METADATA__' => $metadata,
            '__METADATA_JSON__' => $metadataJson,
            '__PREFIX__' => $metadata,
            '__SCHEMA__' => $schema,
            '__APPLY__' => $apply,
        ])."\n";
    }

    /**
     * @param  class-string  $definitionClass
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, class-string>  $classesById
     */
    private function collect(string $definitionClass, array &$definitions, array &$classesById): void
    {
        $definitionId = $this->adapter->definitionId($definitionClass);
        if (isset($classesById[$definitionId]) && $classesById[$definitionId] !== $definitionClass) {
            throw new RuntimeException("Filter definition id [{$definitionId}] is shared by [{$classesById[$definitionId]}] and [{$definitionClass}].");
        }
        if (isset($definitions[$definitionId])) {
            return;
        }

        $definition = $this->adapter->definition($definitionClass);
        $classesById[$definitionId] = $definitionClass;
        $definitions[$definitionId] = $this->metadataDefinition($definition);

        $relationships = array_values($definition['relationships']);
        usort($relationships, static fn (array $left, array $right): int => $left['targetDefinitionId'] <=> $right['targetDefinitionId'] ?: $left['id'] <=> $right['id']);

        foreach ($relationships as $relationship) {
            $target = $this->adapter->definition($relationship['definitionClass']);
            $this->adapter->verifyRelationshipModel(
                $definition['modelClass'],
                $relationship['relationship'],
                $target['modelClass'],
            );
            $this->collect($relationship['definitionClass'], $definitions, $classesById);
        }
    }

    /**
     * @param  FilterDefinition  $definition
     * @return array<string, mixed>
     */
    private function metadataDefinition(array $definition): array
    {
        return [
            'id' => $definition['id'],
            'modelClass' => $definition['modelClass'],
            'fields' => array_values($definition['fields']),
            'relationships' => array_map(
                static fn (array $relationship): array => [
                    'id' => $relationship['id'],
                    'label' => $relationship['label'],
                    'relationship' => $relationship['relationship'],
                    'targetDefinitionId' => $relationship['targetDefinitionId'],
                ],
                array_values($definition['relationships']),
            ),
            'limits' => $definition['limits'],
        ];
    }

    /** @param class-string $definitionClass */
    private static function basename(string $definitionClass): string
    {
        $position = strrpos($definitionClass, '\\');

        return $position === false ? $definitionClass : substr($definitionClass, $position + 1);
    }

    /** @param class-string $definitionClass */
    private static function nodeStem(string $definitionClass): string
    {
        $basename = self::basename($definitionClass);

        return str_ends_with($basename, 'Filters') ? substr($basename, 0, -1) : $basename;
    }
}
