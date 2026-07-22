import {expect, test} from 'bun:test';
import {asQueryInternals} from '../../node_modules/@rocicorp/zero/out/zql/src/query/query-internals.js';
import expected from './query-asts.json';
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
