// This file is generated. Do not edit directly.

import {defineQueries, defineQuery} from '@rocicorp/zero';
import {z} from 'zod';
import {zql} from './schema.generatedgenerated';
import './context.generated';

export const queries = defineQueries({
  directory: {
    party: {
      byId: defineQuery(
        z.string(),
        ({ctx, args}) => zql.party.where("userId", ctx.user_id).where("id", args).one(),
      ),
      byIdWithArchived: defineQuery(
        z.object({id: z.string(), includeArchived: z.boolean().optional()}),
        ({ctx, args}) => zql.party.where("userId", ctx.user_id).where("id", args.id),
      ),
      withPrimaryEmail: defineQuery(
        z.string(),
        ({ctx, args}) => zql.party.where("userId", ctx.user_id).where("id", args).related("emailAddresses", query => query.where("isPrimary", true).limit(1)).one(),
      ),
    },
  },
});
