import {expect, test} from 'bun:test';
import {asQueryInternals} from '../../node_modules/@rocicorp/zero/out/zql/src/query/query-internals.js';
import expected from './query-asts.json';
import {createPartyInputSchema, partyFilters, partyGridInputSchema} from './generated/inputs.generated';
import {queries} from './generated/queries.generated';
import {zql} from './generated/schema.generated';

const ctx = {user_id: 'user-1', tenant_id: 'tenant-1'};
const gridFilter = {
  type: 'group' as const,
  combinator: 'and' as const,
  children: [
    {type: 'condition' as const, field: 'name', operator: 'contains', value: 'Acme'},
    {
      type: 'group' as const,
      combinator: 'or' as const,
      children: [
        {type: 'condition' as const, field: 'kind', operator: 'equals', value: 'person'},
        {type: 'condition' as const, field: 'kind', operator: 'equals', value: 'company'},
      ],
    },
    {
      type: 'relationship' as const,
      relationship: 'emails',
      quantifier: 'some' as const,
      filter: {type: 'condition' as const, field: 'primary', operator: 'equals', value: true},
    },
  ],
};

test('generated scalar query matches PHP AST', () => {
  const query = queries.directory.party.byId.fn({ctx, args: 'party-1'});
  expect(asQueryInternals(query).ast).toEqual(expected.byId);
});

test('relationship existence query matches PHP AST', () => {
  const query = zql.party.whereExists('emailAddresses', q => q.where('isPrimary', true));
  expect(asQueryInternals(query).ast).toEqual(expected.withPrimaryEmailExists);
});

test('generated filter metadata and recursive schema are UI-ready', () => {
  const root = partyFilters.definitions[partyFilters.rootDefinitionId];
  const name = root.fields.find(field => field.id === 'name');
  const kind = root.fields.find(field => field.id === 'kind');
  const emails = root.relationships.find(relationship => relationship.id === 'emails');
  expect({id: name?.id, label: name?.label, values: name?.values}).toEqual({
    id: 'name',
    label: 'Party name',
    values: [],
  });
  expect(kind?.values).toEqual([
    {value: 'person', label: 'Person'},
    {value: 'company', label: 'Company'},
    {value: 'household', label: 'Household'},
  ]);
  expect(emails?.label).toEqual('Email addresses');
  expect(partyGridInputSchema.safeParse({filter: gridFilter, limit: 25}).success).toEqual(true);
  expect(partyGridInputSchema.safeParse({
    filter: {type: 'condition', field: 'user_id', operator: 'equals', value: 'attacker'},
    limit: 25,
  }).success).toEqual(false);
  expect(partyGridInputSchema.safeParse({
    filter: {type: 'condition', field: 'name', operator: 'equals', value: 'Acme', unexpected: true},
    limit: 25,
  }).success).toEqual(false);
});

test('generated recursive grid query matches PHP AST', () => {
  const query = queries.directory.party.grid.fn({ctx, args: {filter: gridFilter, limit: 25}});
  expect(asQueryInternals(query).ast).toEqual(expected.grid);
});

test('generated relationship query matches PHP AST', () => {
  const query = queries.directory.party.withPrimaryEmail.fn({ctx, args: 'party-1'});
  expect(asQueryInternals(query).ast).toEqual(expected.withPrimaryEmail);
});

test('generated same refinement uses the custom field message and path', () => {
  const result = createPartyInputSchema.safeParse({
    id: 'party-1',
    display_name: 'Party',
    password_confirmation: 'Different',
  });

  expect(result.success).toEqual(false);
  if (!result.success) {
    const issue = result.error.issues[0];
    expect({message: issue.message, path: issue.path}).toEqual({
      message: 'The confirmation does not match the display name.',
      path: ['password_confirmation'],
    });
  }
});
