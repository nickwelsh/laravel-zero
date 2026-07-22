import {expect, test} from 'bun:test';
import {mutateResponseSchema} from '../../node_modules/@rocicorp/zero/out/zero-protocol/src/mutate-server.js';
import {queryResponseSchema} from '../../node_modules/@rocicorp/zero/out/zero-protocol/src/query-server.js';
import {parse} from '../../node_modules/@rocicorp/zero/out/shared/src/valita.js';

test('Laravel query response matches Zero 1.8 schema', () => {
  const response = {
    kind: 'QueryResponse',
    userID: 'user-1',
    queries: [{id: 'q1', name: 'directory.party.byId', ast: {table: 'parties', limit: 1}}],
  };

  expect(parse(response, queryResponseSchema)).toEqual(response);
});

test('Laravel mutate responses match Zero 1.8 schema', () => {
  const response = {
    kind: 'MutateResponse',
    userID: 'user-1',
    mutations: [{id: {clientID: 'c1', id: 1}, result: {}}],
  };
  const failed = {
    kind: 'PushFailed',
    origin: 'server',
    reason: 'oooMutation',
    message: 'out of order',
    mutationIDs: [{clientID: 'c1', id: 2}],
  };

  expect(parse(response, mutateResponseSchema)).toEqual(response);
  expect(parse(failed, mutateResponseSchema)).toEqual(failed);
});
