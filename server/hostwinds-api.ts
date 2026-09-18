import pg from "pg";

/**
 * Hostwinds White Label Reseller API — server-side module.
 *
 * Endpoint: POST https://clients.hostwinds.com/HostwindsResellerAPI/api.php
 * Reads ONLY $_POST and checks exactly three case-sensitive fields:
 *   action, reseller_api_key, reseller_email
 * (headers, cookies, JSON bodies and the query string are all ignored).
 *
 * Verified live 2026-09-18:
 *   TestConnection            → {"result":1,"msg":"OK"}
 *   ProductsList              → 27 products in 3 groups
 *   ProductDetailsForCreation → per-product pricing (product_details.USD.monthly),
 *                               description, welcome-email template; with no
 *                               product_id it lists all 33 sellable ids
 *   CreateAccount             → validates in order: product_id first, and the
 *                               product must be sellable ("This product is
 *                               unavailable for sale" otherwise)
 *
 * There is deliberately NO service-listing call: GetServicesData needs
 * hostings_ids, which Hostwinds' own module builds from its WHMCS database
 * (classes/ServiceSync.php → SELECT … FROM tblhosting). So services are only
 * knowable by the ids THIS portal stored when it created them — hence the
 * hostwinds_services table and the ServicesStatus poll below.
 *
 * SCOPES: this API manages the sub-accounts the reseller SELLS. It exposes no
 * invoice/billing endpoints and cannot see the reseller's own Hostwinds
 * services (see admin-hostwinds.php's scope card).
 */

const HW_URL = process.env.HOSTWINDS_API_URL || "https://clients.hostwinds.com/HostwindsResellerAPI/api.php";

export interface HwCredentials { email: string; apiKey: string }

export interface HwServiceRow {
  client_id: number;
  product_id: number;
  product_name: string;
  hosting_id: string | null;
  domain: string | null;
  status: string;
  raw?: any;
}

/** Resolve reseller credentials (settings first, env fallback), case-insensitive. */
export async function getHwCredentials(pool: pg.Pool): Promise<HwCredentials> {
  let email = "", apiKey = "";
  try {
    const { rows } = await pool.query(
      `SELECT setting_key, setting_value FROM system_settings
        WHERE lower(setting_key) IN ('hostwinds_api_email','hostwinds_api_key')`
    );
    for (const r of rows) {
      const k = String(r.setting_key).toLowerCase();
      if (k === "hostwinds_api_email") email = String(r.setting_value || "");
      if (k === "hostwinds_api_key") apiKey = String(r.setting_value || "");
    }
  } catch { /* fall through to env */ }
  if (!email || !apiKey) {
    try {
      const { rows } = await pool.query(
        `SELECT key_name, key_value FROM provider_settings WHERE provider = 'hostwinds'`
      );
      for (const r of rows) {
        if (r.key_name === "api_email" && !email) email = String(r.key_value || "");
        if (r.key_name === "api_key" && !apiKey) apiKey = String(r.key_value || "");
      }
    } catch { /* ignore */ }
  }
  return {
    email: email || process.env.HOSTWINDS_API_EMAIL || "",
    apiKey: apiKey || process.env.HOSTWINDS_API_KEY || "",
  };
}

/**
 * One API call. Returns the parsed body; throws on transport failure.
 * The API reports application errors as {"result":0,"msg":"…"} — callers decide
 * whether that is fatal, because some "errors" are informative ("No param - x").
 */
export async function hwCall(pool: pg.Pool, action: string, params: Record<string, string | number | string[]> = {}): Promise<any> {
  const creds = await getHwCredentials(pool);
  if (!creds.email || !creds.apiKey) {
    throw new Error("Hostwinds not configured (missing reseller api_email / api_key)");
  }
  const body = new URLSearchParams({ action, reseller_email: creds.email, reseller_api_key: creds.apiKey });
  for (const [k, v] of Object.entries(params)) {
    if (Array.isArray(v)) for (const item of v) body.append(`${k}[]`, String(item));
    else body.set(k, String(v));
  }
  const resp = await fetch(HW_URL, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
    body: body.toString(),
    signal: AbortSignal.timeout(45000),
  });
  const text = await resp.text();
  if (!resp.ok) throw new Error(`Hostwinds HTTP ${resp.status}: ${text.slice(0, 180)}`);
  let json: any;
  try { json = JSON.parse(text); } catch { throw new Error(`Hostwinds returned non-JSON: ${text.slice(0, 160)}`); }
  return json;
}

/** Credential health check. */
export async function hwTestConnection(pool: pg.Pool): Promise<{ ok: boolean; message: string }> {
  const r = await hwCall(pool, "TestConnection");
  return { ok: Number(r?.result) === 1, message: String(r?.msg ?? "unknown") };
}

/** Sellable catalogue, flattened from ProductsList. */
export async function hwProducts(pool: pg.Pool): Promise<any[]> {
  const r = await hwCall(pool, "ProductsList");
  const out: any[] = [];
  for (const [group, items] of Object.entries(r?.products ?? {})) {
    for (const p of (Array.isArray(items) ? items : [])) {
      if (!p?.id) continue;
      const price = p.price ?? {};
      out.push({
        id: Number(p.id),
        name: p.name ?? null,
        group,
        paytype: price.paytype ?? null,
        monthly: price.monthly ?? price.msetupfee ?? null,
      });
    }
  }
  return out;
}

/** Per-product creation spec (prices, description, welcome-email template). */
export async function hwProductDetails(pool: pg.Pool, productIds?: number[]): Promise<any> {
  const params: Record<string, string | number | string[]> = {};
  if (productIds?.length) params.product_id = productIds.join(",");
  return await hwCall(pool, "ProductDetailsForCreation", params);
}

/** Monthly price for a product in USD, as a number (null when unknown). */
export async function hwMonthlyPrice(pool: pg.Pool, productId: number): Promise<number | null> {
  const d = await hwProductDetails(pool, [productId]);
  const p = d?.products?.[productId] ?? d?.products?.[String(productId)];
  const monthly = p?.product_details?.USD?.monthly;
  const n = monthly === undefined ? null : parseFloat(String(monthly));
  return n !== null && Number.isFinite(n) ? n : null;
}

/**
 * Provision a sub-account (client + service) at Hostwinds.
 *
 * ⚠️ WRITE OPERATION — creates a billable service. Only ever called from an
 * explicit admin action; every attempt is recorded in hostwinds_orders with the
 * request (password redacted) and the raw response, so a first attempt that
 * trips a parameter the API wants documents itself and can be retried.
 */
export async function hwCreateAccount(pool: pg.Pool, fields: Record<string, string | number>): Promise<any> {
  if (!fields.product_id) throw new Error("product_id is required (Hostwinds validates it first)");
  return await hwCall(pool, "CreateAccount", fields);
}

/** Status of one service by its hosting id. */
export async function hwServiceStatus(pool: pg.Pool, hostingId: string | number): Promise<any> {
  return await hwCall(pool, "ServicesStatus", { hosting_id: hostingId });
}

/** Pull a batch of services by id in one call (the batch call Hostwinds' own module uses). */
export async function hwServicesData(pool: pg.Pool, hostingIds: (string | number)[], paramsToSync = 1): Promise<any> {
  return await hwCall(pool, "GetServicesData", {
    hostings_ids: hostingIds.map(String),
    paramsToSync,
  });
}

/** Redact secrets before anything is written to an audit table or rendered. */
export function redactHwFields(fields: Record<string, any>): Record<string, any> {
  const out: Record<string, any> = {};
  for (const [k, v] of Object.entries(fields)) {
    out[k] = /pass|secret|key|token/i.test(k) ? "***redacted***" : v;
  }
  return out;
}

/** Store a provisioned service so the portal can poll it later. */
export async function saveHwService(pool: pg.Pool, svc: HwServiceRow): Promise<void> {
  await pool.query(
    `INSERT INTO hostwinds_services (client_id, product_id, product_name, hosting_id, domain, status, raw, created_at, last_synced_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,NOW(),NOW())`,
    [svc.client_id, svc.product_id, svc.product_name, svc.hosting_id, svc.domain, svc.status, JSON.stringify(svc.raw ?? {})]
  );
}

export async function logHwOrder(
  pool: pg.Pool,
  entry: { client_id: number | null; product_id: number; status: string; request: Record<string, any>; response: any; error?: string | null }
): Promise<number> {
  const { rows } = await pool.query(
    `INSERT INTO hostwinds_orders (client_id, product_id, status, request, response, error, created_at)
     VALUES ($1,$2,$3,$4,$5,$6,NOW()) RETURNING id`,
    [
      entry.client_id,
      entry.product_id,
      entry.status,
      JSON.stringify(redactHwFields(entry.request ?? {})),
      JSON.stringify(entry.response ?? {}).slice(0, 8000),
      entry.error ?? null,
    ]
  );
  return rows[0].id;
}

/** Refresh stored services' statuses (single batch call, ids from our own table). */
export async function syncHwServices(pool: pg.Pool): Promise<{ checked: number; updated: number; errors: string[] }> {
  const errors: string[] = [];
  const { rows } = await pool.query(
    `SELECT id, hosting_id, status FROM hostwinds_services WHERE hosting_id IS NOT NULL ORDER BY id`
  );
  if (!rows.length) return { checked: 0, updated: 0, errors: ["no services stored yet"] };

  const ids = rows.map((r) => String(r.hosting_id));
  let updated = 0;
  try {
    const batch = await hwServicesData(pool, ids);
    const data = batch?.hostings_ids ?? batch?.services ?? {};
    for (const row of rows) {
      const entry = Array.isArray(data) ? data.find((d: any) => String(d?.id ?? d?.hosting_id) === String(row.hosting_id)) : data[String(row.hosting_id)];
      if (!entry) continue;
      const status = String(entry.status ?? entry.domainstatus ?? entry.domainStatus ?? row.status);
      await pool.query(
        `UPDATE hostwinds_services SET status = $1, raw = $2, last_synced_at = NOW() WHERE id = $3`,
        [status, JSON.stringify(entry).slice(0, 8000), row.id]
      );
      if (status !== row.status) updated++;
    }
  } catch (e: any) {
    errors.push(`GetServicesData: ${e.message}`);
    // Fall back to per-service status so one bad batch doesn't lose everything.
    for (const row of rows) {
      try {
        const one = await hwServiceStatus(pool, row.hosting_id);
        const status = String(one?.status ?? one?.msg ?? row.status);
        if (Number(one?.result) === 1) {
          await pool.query(`UPDATE hostwinds_services SET status = $1, last_synced_at = NOW() WHERE id = $2`, [status, row.id]);
          if (status !== row.status) updated++;
        }
      } catch (e2: any) {
        errors.push(`ServicesStatus ${row.hosting_id}: ${e2.message}`);
      }
    }
  }
  return { checked: rows.length, updated, errors };
}
