// D&H Distributing — Customer Order Management API client.
// Grounded in D&H live OpenAPI spec (CustomerOrderManagementAPI.json v2.0.1):
//   token: https://auth.dandh.com/api/oauth/token (client_credentials)  [prod]
//          https://test.auth.dandh.com/api/oauth/token                 [test]
//   base:  https://api.dandh.com/customerOrderManagement/v2            [prod]
//          https://test.api.dandh.com/customerOrderManagement/v2       [test]
//
// REQUIRED header on every request: `dandh-tenant` (e.g. "dhus").
// Item endpoints take a single {itemId} path param (e.g. "TI83PLUS").
// List endpoints return `elements` arrays; pageSize must be a multiple
// of 10 within 10–200.
import type { Pool } from "pg";

const PROD_TOKEN_URL = "https://auth.dandh.com/api/oauth/token";
const TEST_TOKEN_URL = "https://test.auth.dandh.com/api/oauth/token";
const PROD_BASE = "https://api.dandh.com/customerOrderManagement/v2";
const TEST_BASE = "https://test.api.dandh.com/customerOrderManagement/v2";
const DEFAULT_TENANT = "dhus";

export interface DhCredentials {
  clientId: string;
  clientSecret: string;
  account: string; // e.g. "3054540000"
  env: string;     // "TEST" or "PRODUCTION"
  tenant: string;  // e.g. "dhus"
}

let cachedToken: { value: string; expiresAt: number } | null = null;

function getBaseUrl(env: string): string {
  return env === "PRODUCTION" ? PROD_BASE : TEST_BASE;
}

export async function getDhCredentials(pool: Pool): Promise<DhCredentials> {
  const raw = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dh_client_id','dh_client_secret','dh_account','dh_env','dh_tenant')`
  );
  const m: Record<string, string> = {};
  for (const r of raw.rows) m[r.setting_key] = r.setting_value;
  if (!m.dh_client_id || !m.dh_client_secret) {
    throw new Error("D&H credentials not configured (set dh_client_id / dh_client_secret)");
  }
  return {
    clientId: m.dh_client_id,
    clientSecret: m.dh_client_secret,
    account: m.dh_account || "3054540000",
    env: m.dh_env || "TEST",
    tenant: m.dh_tenant || DEFAULT_TENANT,
  };
}

export async function getDhToken(c: DhCredentials): Promise<string> {
  if (cachedToken && Date.now() < cachedToken.expiresAt) return cachedToken.value;
  const tokenUrl = c.env === "PRODUCTION" ? PROD_TOKEN_URL : TEST_TOKEN_URL;
  const body = new URLSearchParams({
    grant_type: "client_credentials",
    client_id: c.clientId,
    client_secret: c.clientSecret,
  });
  const r = await fetch(tokenUrl, {
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
  const base = getBaseUrl(creds.env);
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
    "dandh-tenant": creds.tenant,
  };
  if (opts.body) headers["Content-Type"] = "application/json";
  const r = await fetch(`${base}${path}`, {
    method: opts.method || "GET",
    headers,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  if (!r.ok) throw new Error(`D&H API ${opts.method || "GET"} ${path}: HTTP ${r.status} ${await r.text()}`);
  return r.json();
}

// ── Convenience wrappers ────────────────────────────────────────────

/** Price & Availability for a specific item (single itemId) */
export async function dhPriceAvailability(pool: Pool, itemId: string, quantity?: number) {
  const creds = await getDhCredentials(pool);
  const q = quantity ? `?quantity=${quantity}` : "";
  return dhRequest(pool, `/customers/${creds.account}/items/${encodeURIComponent(itemId)}/priceAndAvailability${q}`);
}

/** Order tracking list */
export async function dhOrderTracking(pool: Pool, orderNumber?: string) {
  const creds = await getDhCredentials(pool);
  let path = `/customers/${creds.account}/salesOrders/tracking`;
  if (orderNumber) path += `?orderNumber=${encodeURIComponent(orderNumber)}`;
  return dhRequest(pool, path);
}

/** List /items catalog page (paginated with scrollId; elements array) */
export async function dhItemsList(pool: Pool, scrollId?: string, pageSize: number = 200) {
  const creds = await getDhCredentials(pool);
  // pageSize must be a multiple of 10 within 10–200
  const size = Math.max(10, Math.min(200, Math.round(pageSize / 10) * 10));
  let path = `/customers/${creds.account}/items?pageSize=${size}`;
  if (scrollId) path += `&scrollId=${encodeURIComponent(scrollId)}`;
  return dhRequest(pool, path);
}

/** Item inquiry by single itemId */
export async function dhItemInquiry(pool: Pool, itemId: string) {
  const creds = await getDhCredentials(pool);
  return dhRequest(pool, `/customers/${creds.account}/items/${encodeURIComponent(itemId)}`);
}

/** List carriers (auth check) */
export async function dhCarriers(pool: Pool) {
  const creds = await getDhCredentials(pool);
  return dhRequest(pool, `/customers/${creds.account}/carriers`);
}

/** Search catalog by keyword — pages through items and filters client-side */
export async function dhSearchCatalog(
  pool: Pool,
  query: string,
  opts: { scrollId?: string; maxPages?: number; pageSize?: number } = {}
) {
  const maxPages = opts.maxPages || 5;
  const pageSize = opts.pageSize || 200;
  const q = query.toLowerCase().trim();
  const matches: any[] = [];
  let scroll = opts.scrollId || "";
  let pagesScanned = 0;
  let hasMore = true;

  while (pagesScanned < maxPages && hasMore) {
    const data: any = await dhItemsList(pool, scroll || undefined, pageSize);
    pagesScanned++;

    const items = data?.elements || data?.items || data?.data || [];
    if (!Array.isArray(items) || items.length === 0) {
      hasMore = false;
      break;
    }

    for (const item of items) {
      const desc = ((item.description || item.itemDescription || "") + " " +
                    (item.vendorName || item.manufacturer || "") + " " +
                    (item.itemId || item.itemNumber || "") + " " +
                    (item.vendorItemId || "") + " " +
                    (item.manufacturerItemId || "") + " " +
                    (item.universalProductCode || "")).toLowerCase();
      if (desc.includes(q)) {
        matches.push(item);
      }
    }

    scroll = data.scrollId || data.nextScrollId || "";
    if (!scroll) hasMore = false;
  }

  return {
    matches,
    scrollId: scroll,
    pagesScanned,
    hasMore,
    totalScanned: pagesScanned * pageSize,
  };
}

export function clearDhTokenCache() { cachedToken = null; }

// ── Sales order creation ────────────────────────────────────────────

export interface DhOrderLine {
  item: string;              // D&H item number (e.g. "TI83PLUS")
  orderQuantity: number;
  unitPrice?: string;        // optional requested price
}

export interface DhOrderInput {
  customerPurchaseOrder: string;  // max 30 chars
  branch?: string;                // e.g. "BR01"
  lines: DhOrderLine[];
  enrollDevices?: boolean;
}

/**
 * POST /customers/{account}/salesOrders — submit an order to D&H.
 * Returns { orderNumber, ... } on success.
 */
export async function dhCreateSalesOrder(pool: Pool, input: DhOrderInput): Promise<any> {
  const creds = await getDhCredentials(pool);
  const lines = input.lines.map((l) => {
    const line: Record<string, unknown> = { item: l.item, orderQuantity: l.orderQuantity };
    if (l.unitPrice) line.unitPrice = l.unitPrice;
    return line;
  });
  const body: Record<string, unknown> = {
    customerPurchaseOrder: input.customerPurchaseOrder,
    shipments: [{ lines }],
  };
  if (input.branch) (body.shipments as any)[0].branch = input.branch;
  if (input.enrollDevices) body.enrollDevices = true;
  return dhRequest(pool, `/customers/${creds.account}/salesOrders`, { method: "POST", body });
}

// ── Order history (tracking list, optional orderNumber filter) ──────
export async function dhOrdersList(pool: Pool, orderNumber?: string) {
  return dhOrderTracking(pool, orderNumber);
}