// This file is generated. Do not edit directly.

import {boolean, createBuilder, createSchema, relationships, string, table} from '@rocicorp/zero';
import type {Row} from '@rocicorp/zero';
import {z} from 'zod';

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

const tag = table('tag')
  .from('tags')
  .columns({
    id: string(),
    name: string(),
  })
  .primaryKey('id');

const taggable = table('taggables')
  .columns({
    tagId: string().from('tag_id'),
    taggableType: string().from('taggable_type'),
    taggableId: string().from('taggable_id'),
  })
  .primaryKey('tagId', 'taggableType', 'taggableId');

const partyRelationships = relationships(party, ({many}) => ({
  emailAddresses: many({
    sourceField: ['id'],
    destSchema: emailAddress,
    destField: ['partyId'],
  }),
  __zeroMorphTagsPivot: many({
    sourceField: ['id'],
    destSchema: taggable,
    destField: ['taggableId'],
  }),
}));

const taggableRelationships = relationships(taggable, ({one}) => ({
  __zeroMorphPartiesTagsRelated: one({
    sourceField: ['tagId'],
    destSchema: tag,
    destField: ['id'],
  }),
}));

export const schema = createSchema({tables: [party, emailAddress, tag, taggable], relationships: [partyRelationships, taggableRelationships]});
export type Schema = Row<typeof schema>;
export const partySchema = z.object({
  id: z.coerce.string(),
  userId: z.coerce.string(),
  displayName: z.coerce.string(),
  referenceCode: z.coerce.string().nullish(),
});
export type ParsedParty = z.output<typeof partySchema>;
export const emailAddressSchema = z.object({
  id: z.coerce.string(),
  partyId: z.coerce.string(),
  isPrimary: z.coerce.boolean(),
});
export type ParsedEmailAddress = z.output<typeof emailAddressSchema>;
export const tagSchema = z.object({
  id: z.coerce.string(),
  name: z.coerce.string(),
});
export type ParsedTag = z.output<typeof tagSchema>;
export const zql = createBuilder(schema);
