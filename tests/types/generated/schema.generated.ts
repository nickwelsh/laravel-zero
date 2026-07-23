// This file is generated. Do not edit directly.

import {boolean, createBuilder, createSchema, relationships, string, table} from '@rocicorp/zero';
import type {Row} from '@rocicorp/zero';

const party = table('party')
  .from('parties')
  .columns({
    id: string(),
    userId: string().from('user_id'),
    displayName: string().from('display_name'),
    referenceCode: string().from('reference_code').optional(),
  })
  .primaryKey('id');

const emailAddress = table('emailAddress')
  .from('email_addresses')
  .columns({
    id: string(),
    partyId: string().from('party_id'),
    isPrimary: boolean().from('is_primary'),
  })
  .primaryKey('id');

const partyRelationships = relationships(party, ({many}) => ({
  emailAddresses: many({
    sourceField: ['id'],
    destSchema: emailAddress,
    destField: ['partyId'],
  }),
}));

export const schema = createSchema({tables: [party, emailAddress], relationships: [partyRelationships]});
export type Schema = Row<typeof schema>;
export const zql = createBuilder(schema);
