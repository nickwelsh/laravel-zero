// This file is generated. Do not edit directly.

import type {Schema} from './schema.generated';

export type ZeroContext = {
  readonly user_id: string;
  readonly tenant_id: string | null;
};

declare module '@rocicorp/zero' {
  interface DefaultTypes {
    context: ZeroContext;
    schema: Schema;
  }
}
