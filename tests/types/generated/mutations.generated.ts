// This file is generated. Do not edit directly.

import {defineMutators, defineMutator} from '@rocicorp/zero';
import {z} from 'zod';
import {createPartyInputSchema} from './inputs.generated';
import './context.generated';

export const mutations = defineMutators({
  directory: {
    party: {
      create: defineMutator(
        createPartyInputSchema,
        async ({tx, ctx, args}) => {
          await tx.mutate.party.insert({id: args.id, displayName: args.display_name, userId: ctx.user_id});
        },
      ),
      createPair: defineMutator(
        z.object({firstId: z.string(), secondId: z.string()}),
        async ({tx, ctx, args}) => {
          await tx.mutate.party.insert({id: args.firstId, userId: ctx.user_id, displayName: "First"});
          await tx.mutate.party.insert({id: args.secondId, userId: ctx.user_id, displayName: "Second"});
        },
      ),
      createThenFail: defineMutator(
        createPartyInputSchema,
        async ({tx, ctx, args}) => {
          await tx.mutate.party.insert({id: args.id, displayName: args.display_name, referenceCode: args.reference_code, userId: ctx.user_id});
        },
      ),
      deny: defineMutator(
        async ({tx, ctx}) => {},
      ),
    },
  },
});
