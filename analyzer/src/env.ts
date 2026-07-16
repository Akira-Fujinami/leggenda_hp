import { z } from "zod";

const envSchema = z.object({
  PORT: z.coerce.number().int().positive().default(3001),
  HOST: z.string().default("0.0.0.0"),
  LOG_LEVEL: z.string().default("info"),
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  MAX_CONCURRENT_BROWSER_SESSIONS: z.coerce.number().int().positive().default(2),
  BROWSER_TIMEOUT_MS: z.coerce.number().int().positive().default(30_000),
  MAX_HTML_BYTES: z.coerce.number().int().positive().default(5 * 1024 * 1024),
  MAX_REDIRECTS: z.coerce.number().int().nonnegative().default(3),
});

export const env = envSchema.parse(process.env);
