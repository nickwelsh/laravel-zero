// This file is generated. Do not edit directly.

import {z} from 'zod';

export const createPartyInputSchema = z.object({id: z.string(), display_name: z.string().min(2), reference_code: z.json().optional()});
