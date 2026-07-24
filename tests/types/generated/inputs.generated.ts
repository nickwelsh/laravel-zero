// This file is generated. Do not edit directly.

import {z} from 'zod';

type partyFiltersValue = string | number | boolean | readonly (string | number | boolean)[];

export type PartyFilterNode =
  | {type: 'condition'; field: string; operator: string; value?: partyFiltersValue}
  | {type: 'group'; combinator: 'and' | 'or'; children: PartyFilterNode[]}
  | {type: 'relationship'; relationship: string; quantifier: 'some'; filter?: PartyFilterNode};

export const partyFilters = {
    "rootDefinitionId": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\PartyFilters",
    "definitions": {
        "NickWelsh\\LaravelZero\\Tests\\Fixtures\\EmailAddressFilters": {
            "id": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\EmailAddressFilters",
            "modelClass": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\EmailAddress",
            "fields": [
                {
                    "id": "primary",
                    "label": "Primary email",
                    "column": "is_primary",
                    "clientColumn": "isPrimary",
                    "kind": "boolean",
                    "operators": [
                        "equals",
                        "not_equals"
                    ],
                    "values": []
                },
                {
                    "id": "id",
                    "label": "Email ID",
                    "column": "id",
                    "clientColumn": "id",
                    "kind": "string",
                    "operators": [
                        "equals",
                        "not_equals",
                        "contains",
                        "not_contains",
                        "starts_with",
                        "ends_with",
                        "in",
                        "not_in"
                    ],
                    "values": []
                }
            ],
            "relationships": [],
            "limits": {
                "maxDepth": 3,
                "maxRelationshipDepth": 3,
                "maxNodes": 50,
                "maxChildren": 20,
                "maxInValues": 100,
                "maxStringLength": 1000
            }
        },
        "NickWelsh\\LaravelZero\\Tests\\Fixtures\\PartyFilters": {
            "id": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\PartyFilters",
            "modelClass": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\Party",
            "fields": [
                {
                    "id": "name",
                    "label": "Party name",
                    "column": "display_name",
                    "clientColumn": "displayName",
                    "kind": "string",
                    "operators": [
                        "equals",
                        "contains"
                    ],
                    "values": []
                },
                {
                    "id": "kind",
                    "label": "Party type",
                    "column": "reference_code",
                    "clientColumn": "referenceCode",
                    "kind": "enum",
                    "operators": [
                        "equals",
                        "not_equals",
                        "in",
                        "not_in"
                    ],
                    "values": [
                        {
                            "value": "person",
                            "label": "Person"
                        },
                        {
                            "value": "company",
                            "label": "Company"
                        },
                        {
                            "value": "household",
                            "label": "Household"
                        }
                    ]
                }
            ],
            "relationships": [
                {
                    "id": "emails",
                    "label": "Email addresses",
                    "relationship": "emailAddresses",
                    "targetDefinitionId": "NickWelsh\\LaravelZero\\Tests\\Fixtures\\EmailAddressFilters"
                }
            ],
            "limits": {
                "maxDepth": 3,
                "maxRelationshipDepth": 3,
                "maxNodes": 50,
                "maxChildren": 20,
                "maxInValues": 100,
                "maxStringLength": 1000
            }
        }
    }
} as const;

type partyFiltersRuntimeField = {
  readonly id: string;
  readonly label: string;
  readonly column: string;
  readonly clientColumn: string;
  readonly kind: string;
  readonly operators: readonly string[];
  readonly values: readonly {readonly value: unknown; readonly label: string}[];
};

type partyFiltersRuntimeRelationship = {
  readonly id: string;
  readonly label: string;
  readonly relationship: string;
  readonly targetDefinitionId: string;
};

type partyFiltersRuntimeDefinition = {
  readonly id: string;
  readonly modelClass: string;
  readonly fields: readonly partyFiltersRuntimeField[];
  readonly relationships: readonly partyFiltersRuntimeRelationship[];
  readonly limits: {
    readonly maxDepth: number;
    readonly maxRelationshipDepth: number;
    readonly maxNodes: number;
    readonly maxChildren: number;
    readonly maxInValues: number;
    readonly maxStringLength: number;
  };
};

type partyFiltersSemanticOperator = {
  readonly zql: string;
  readonly mode: 'literal' | 'in' | 'null' | 'contains' | 'startsWith' | 'endsWith' | 'empty';
  readonly negate?: boolean;
};

const partyFiltersDefinitions = partyFilters.definitions as unknown as Readonly<Record<string, partyFiltersRuntimeDefinition>>;

const partyFiltersIsRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const partyFiltersHasOnlyKeys = (
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

const partyFiltersNormalize = (value: string): string =>
  value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

const partyFiltersSemantic = (operator: string): partyFiltersSemanticOperator | null => {
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

  switch (partyFiltersNormalize(operator)) {
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

const partyFiltersEscapeLike = (value: unknown): string =>
  String(value).replace(/[\\%_]/g, '\\$&');

const partyFiltersValidateScalar = (
  value: unknown,
  field: partyFiltersRuntimeField,
  maxStringLength: number,
  path: (string | number)[],
  issue: (message: string, path: (string | number)[]) => void,
): void => {
  const kind = partyFiltersNormalize(field.kind);
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

const partyFiltersValidate = (
  input: unknown,
  issue: (message: string, path: (string | number)[]) => void,
): void => {
  const root = partyFiltersDefinitions[partyFilters.rootDefinitionId];
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
    const definition = partyFiltersDefinitions[definitionId];
    if (!definition) {
      issue(`Unknown filter definition [${definitionId}].`, path);
      return;
    }
    if (nodes > root.limits.maxNodes) {
      issue('Filter exceeds the maximum node count.', path);
      return;
    }
    if (!partyFiltersIsRecord(value) || typeof value.type !== 'string') {
      issue('Filter node must be an object with a type.', path);
      return;
    }

    if (value.type === 'condition') {
      if (!partyFiltersHasOnlyKeys(value, ['type', 'field', 'operator', 'value'], path, issue)) return;
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
      const semantic = partyFiltersSemantic(value.operator);
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
        value.value.forEach((entry, index) => partyFiltersValidateScalar(
          entry,
          field,
          definition.limits.maxStringLength,
          [...path, 'value', index],
          issue,
        ));
        return;
      }
      partyFiltersValidateScalar(value.value, field, definition.limits.maxStringLength, [...path, 'value'], issue);
      return;
    }

    if (value.type === 'group') {
      if (!partyFiltersHasOnlyKeys(value, ['type', 'combinator', 'children'], path, issue)) return;
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
      if (!partyFiltersHasOnlyKeys(value, ['type', 'relationship', 'quantifier', 'filter'], path, issue)) return;
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

  visit(input, partyFilters.rootDefinitionId, 0, 0, []);
};

export const partyFilterSchema = z.custom<PartyFilterNode>((value): value is PartyFilterNode => true).superRefine(
  (value, context) => partyFiltersValidate(
    value,
    (message, path) => context.addIssue({code: 'custom', message, path}),
  ),
);

const partyFiltersCanonicalValue = (field: partyFiltersRuntimeField, value: unknown): unknown => {
  if (field.values.length === 0) return value;
  const option = field.values.find(candidate => Object.is(candidate.value, value));
  if (!option) throw new Error(`Value is not an allowed option for filter field [${field.id}].`);
  return option.value;
};

const partyFiltersApplyNode = (expressionBuilder: any, node: PartyFilterNode, definitionId: string): any => {
  const definition = partyFiltersDefinitions[definitionId];
  if (!definition) throw new Error(`Unknown filter definition [${definitionId}].`);

  if (node.type === 'group') {
    const conditions = node.children.map(child => partyFiltersApplyNode(expressionBuilder, child, definitionId));
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
        (relatedExpressionBuilder: any) => partyFiltersApplyNode(relatedExpressionBuilder, node.filter as PartyFilterNode, relationship.targetDefinitionId),
      ),
    );
  }

  const field = definition.fields.find(candidate => candidate.id === node.field);
  if (!field) throw new Error(`Unknown filter field [${node.field}].`);
  if (!field.operators.includes(node.operator)) throw new Error(`Operator [${node.operator}] is not allowed for filter field [${field.id}].`);
  const semantic = partyFiltersSemantic(node.operator);
  if (!semantic) throw new Error(`Operator [${node.operator}] has no ZQL mapping.`);

  if (semantic.mode === 'null') return expressionBuilder.cmp(field.clientColumn, semantic.zql, null);
  if (semantic.mode === 'empty') return expressionBuilder.cmp(field.clientColumn, semantic.zql, '');
  if (semantic.mode === 'in') {
    if (!Array.isArray(node.value)) throw new Error(`Operator [${node.operator}] requires an array value.`);
    return expressionBuilder.cmp(
      field.clientColumn,
      semantic.zql,
      node.value.map(value => partyFiltersCanonicalValue(field, value)),
    );
  }

  const value = partyFiltersCanonicalValue(field, node.value);
  if (semantic.mode === 'contains') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `%${partyFiltersEscapeLike(value)}%`);
  if (semantic.mode === 'startsWith') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `${partyFiltersEscapeLike(value)}%`);
  if (semantic.mode === 'endsWith') return expressionBuilder.cmp(field.clientColumn, semantic.zql, `%${partyFiltersEscapeLike(value)}`);
  return expressionBuilder.cmp(field.clientColumn, semantic.zql, value);
};

export const applyPartyFilters = (expressionBuilder: any, node: PartyFilterNode): any =>
  partyFiltersApplyNode(expressionBuilder, node, partyFilters.rootDefinitionId);

export const createPartyInputSchema = z.object({id: z.string(), display_name: z.string().min(2), password_confirmation: z.any().optional(), reference_code: z.any().optional()}).refine(data => data["password_confirmation"] === undefined || data["password_confirmation"] === data["display_name"], { error: "The confirmation does not match the display name.", path: ["password_confirmation"] });
export const partyGridInputSchema = z.object({limit: z.number().int().gte(1).lte(100), filter: partyFilterSchema});
