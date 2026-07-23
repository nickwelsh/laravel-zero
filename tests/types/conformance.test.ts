import {expect, test} from 'bun:test';
import {asQueryInternals} from '../../node_modules/@rocicorp/zero/out/zql/src/query/query-internals.js';
import expected from './query-asts.json';
import {createPartyInputSchema} from './generated/inputs.generated';
import {queries} from './generated/queries.generated';

const ctx = {user_id: 'user-1', tenant_id: 'tenant-1'};

test('generated scalar query matches PHP AST', () => {
  const query = queries.directory.party.byId.fn({ctx, args: 'party-1'});
  expect(asQueryInternals(query).ast).toEqual(expected.byId);
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
