// This file is generated. Do not edit directly.

import {z} from 'zod';

export const createPartyInputSchema = z.object({id: z.string(), display_name: z.string().min(2), password_confirmation: z.any().optional(), reference_code: z.any().optional()}).refine(data => data["password_confirmation"] === undefined || data["password_confirmation"] === data["display_name"], { error: "The confirmation does not match the display name.", path: ["password_confirmation"] });
