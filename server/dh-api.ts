// D&H Distributing — Customer Order Management API client.
// Grounded in D&H live OpenAPI spec (CustomerOrderManagementAPI.json v2.0.1):
//   token: https://auth.dandh.com/api/oauth/token (client_credentials)
//   base:  https://api.dandh.com/customerOrderManagement/v2
import type { Pool } from "pg";

const TOKEN_URL = "https://auth.dandh.com/api/oauth/token";
const BASE = "https://api.dandh.com/customerOrderManagement/v2";

export interface DhCredentials {
  clientId: string;
  clientSecret: string;
  account: string; // e.g. "3054540000"
}

let cachedToken: { value: string; expiresAt: number } | null = null;

export async function getDhCredentials(pool: Pool): Promise<DhCredentials> {
  const raw = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dh_client_id','dh_client_secret','dh_account')`
  );
  const m: Record<string, string> = {};
  for (const r of raw.rows) m[r.setting_key] = r.setting_value;
  if (!m.dh_client_id || !m.dh_client_secret) {
    throw new Error("D&H credentials not configured (set dh_client_id / dh_client_secret)");
  }
  return { clientId: m.dh_client_id, clientSecret: m.dh_client_secret, account: m.dh_account || "3054540000" };
}

export async function getDhToken(c: DhCredentials): Promise<string> {
  if (cachedToken && Date.now() < cachedToken.expiresAt) return cachedToken.value;
  const body = new URLSearchParams({
    grant_type: "client_credentials",
    client_id: c.clientId,
    client_secret: c.clientSecret,
  });
  const r = await fetch(TOKEN_URL, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body.toString(),
  });
  if (!r.ok) throw new Error(`D&H token error: HTTP ${r.status} ${await r.text()}`);
  const j = (await r.json()) as { access_token: string; expires_in?: number };
  cachedToken = { value: j.access_token, expiresAt: Date.now() + ((j.expires_in ?? 3600) * 1000 - 60_000) };
  return cachedToken.value;
}

export async function dhRequest(pool: Pool, path: string, opts: { method?: string; body?: unknown } = {}) {
  const creds = await getDhCredentials(pool);
  const token = await getDhToken(creds);
  const headers: Record<string, string> = { Authorization: `Bearer ${token}`, Accept: "application/json" };
  if (opts.body) headers["Content-Type"] = "application/json";
  const r = await fetch(`${BASE}${path}`, {
    method: opts.method || "GET",
    headers,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  if (!r.ok) throw new Error(`D&H API ${opts.method || "GET"} ${path}: HTTP ${r.status} ${await r.text()}`);
  return r.json();
}

// Convenience wrappers (paths use account number; item format to confirm with D&H)
export async function dhPriceAvailability(pool: Pool, manufacturer: string, itemNumber: string) {
  return dhRequest(pool, `/customers/${(await getDhCredentials(pool)).account}/items/${encodeURIComponent(manufacturer)}/${encodeURIComponent(itemNumber)}/priceAndAvailability`);
}

export async function dhOrderTracking(pool: Pool) {
  return dhRequest(pool, `/customers/${(await getDhCredentials(pool)).account}/salesOrders/tracking`);
}

export function clearDhTokenCache() { cachedToken = null; }