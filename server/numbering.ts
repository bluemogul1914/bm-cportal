import { pool } from "./db";

/**
 * Generate a unified display number using the bm_prefix_registry.
 * Format: {PREFIX}-{YYYYMMDD}-{NNNN} (e.g., BMA-20261104-0001)
 * Backed by the next_bm_code() PostgreSQL function.
 */
export async function generateNumber(prefix: string): Promise<string> {
  const res = await pool.query("SELECT next_bm_code($1) AS code", [prefix]);
  return res.rows[0].code as string;
}
