import pg from "pg";
import { sendEmail } from "./email";

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
export async function saveHwService(pool: pg.Pool, svc: HwServiceRow & {
  subscription_id?: number | null; portal_product_id?: number | null; note?: string | null;
}): Promise<number> {
  const { rows } = await pool.query(
    `INSERT INTO hostwinds_services
       (client_id, product_id, product_name, hosting_id, domain, status, raw, subscription_id, portal_product_id, note, created_at, last_synced_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,NOW(),NOW()) RETURNING id`,
    [svc.client_id, svc.product_id, svc.product_name, svc.hosting_id, svc.domain, svc.status,
     JSON.stringify(svc.raw ?? {}), svc.subscription_id ?? null, svc.portal_product_id ?? null, svc.note ?? null]
  );
  return rows[0].id;
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

/* ═══════════════════════════════════════════════════════════════════════════
 * PHASE B — the sell loop: client orders → wallet pays → auto-provision.
 *
 * The hook is the existing activation path (activatePaidPendingSubscriptions /
 * the wallet charge cycle): the moment a subscription flips to 'active' because
 * its invoice was paid, a product mapped to Hostwinds provisions itself here.
 *
 * Idempotent by construction: a (subscription_id) or (client_id,
 * portal_product_id) already present in hostwinds_services is never re-provisioned.
 * ═══════════════════════════════════════════════════════════════════════════ */

export interface HwProductMapRow {
  portal_product_id: number;
  hostwinds_product_id: number;
  product_name: string | null;
  billingcycle: string;
  active: boolean;
}

/** Which portal product sells which Hostwinds product (null = not a Hostwinds product). */
export async function getHwProductMap(pool: pg.Pool, portalProductId: number): Promise<HwProductMapRow | null> {
  const { rows } = await pool.query(
    `SELECT portal_product_id, hostwinds_product_id, product_name, billingcycle, active
       FROM hostwinds_product_map WHERE portal_product_id = $1`,
    [portalProductId]
  );
  return rows[0] ?? null;
}

export async function setHwProductMap(pool: pg.Pool, m: {
  portal_product_id: number; hostwinds_product_id: number; product_name?: string; billingcycle?: string; active?: boolean;
}): Promise<void> {
  await pool.query(
    `INSERT INTO hostwinds_product_map (portal_product_id, hostwinds_product_id, product_name, billingcycle, active, updated_at)
     VALUES ($1,$2,$3,$4,$5,NOW())
     ON CONFLICT (portal_product_id) DO UPDATE SET
       hostwinds_product_id = EXCLUDED.hostwinds_product_id,
       product_name = EXCLUDED.product_name,
       billingcycle = EXCLUDED.billingcycle,
       active = EXCLUDED.active,
       updated_at = NOW()`,
    [m.portal_product_id, m.hostwinds_product_id, m.product_name ?? null, m.billingcycle ?? "monthly", m.active ?? true]
  );
}

/** Generate a strong, paste-safe admin password for a new service. */
export function generateServicePassword(): string {
  const { randomBytes } = require("crypto");
  return randomBytes(12).toString("base64").replace(/[+/=]/g, "").slice(0, 16) + "!7";
}

/** Build the CreateAccount field set from a portal client row. */
export function buildHwCreateFields(client: any, opts: {
  hostwindsProductId: number; domainLabel: string; password: string; billingcycle?: string;
}): Record<string, string | number> {
  const name = String(client?.name ?? "Client").trim();
  const parts = name.split(/\s+/);
  return {
    product_id: opts.hostwindsProductId,
    firstname: client?.first_name || parts[0] || "Client",
    lastname: client?.last_name || parts.slice(1).join(" ") || "Account",
    email: client?.email || "",
    companyname: client?.company || client?.name || "",
    address1: client?.address || "",
    city: client?.city || "",
    state: client?.state || "",
    postcode: client?.zip || "",
    country: "US",
    phonenumber: client?.phone || "",
    domain: opts.domainLabel,
    password: opts.password,
    password2: opts.password,
    billingcycle: opts.billingcycle || "monthly",
    notes: `Blue Mogul Suite auto-provision (portal client ${client?.id ?? "?"})`,
  };
}

export interface HwProvisionResult {
  status: "skipped" | "already" | "provisioned" | "failed";
  hostingId: string | null;
  message: string;
  orderId?: number | null;
}

/**
 * Provision (or skip) the Hostwinds sub-account behind a newly-activated
 * subscription. Never throws: activation must not fail because provisioning did.
 */
export async function maybeProvisionHwService(pool: pg.Pool, args: {
  clientId: number; subscriptionId: number; portalProductId: number;
}): Promise<HwProvisionResult> {
  try {
    const map = await getHwProductMap(pool, args.portalProductId);
    if (!map || !map.active) {
      return { status: "skipped", hostingId: null, message: `portal product ${args.portalProductId} is not mapped to a Hostwinds product` };
    }

    // Idempotency — one service per subscription, and per (client, product).
    const dup = await pool.query(
      `SELECT id, hosting_id FROM hostwinds_services
        WHERE subscription_id = $1 OR (client_id = $2 AND portal_product_id = $3) LIMIT 1`,
      [args.subscriptionId, args.clientId, args.portalProductId]
    );
    if (dup.rows.length) {
      return { status: "already", hostingId: dup.rows[0].hosting_id, message: `service already provisioned (row ${dup.rows[0].id})` };
    }

    const cRes = await pool.query(`SELECT * FROM clients WHERE id = $1`, [args.clientId]);
    const client = cRes.rows[0];
    if (!client) return { status: "failed", hostingId: null, message: `client ${args.clientId} not found` };

    const password = generateServicePassword();
    const domainLabel = `bm-${String(client.name || "client").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 24)}`;
    const fields = buildHwCreateFields(client, {
      hostwindsProductId: map.hostwinds_product_id,
      domainLabel,
      password,
      billingcycle: map.billingcycle,
    });

    const response = await hwCreateAccount(pool, fields);
    const ok = Number(response?.result) === 1;
    const orderId = await logHwOrder(pool, {
      client_id: args.clientId,
      product_id: map.hostwinds_product_id,
      status: ok ? "created" : "rejected",
      request: fields,
      response,
      error: ok ? null : String(response?.msg ?? "unknown"),
    });

    if (!ok) {
      await saveHwService(pool, {
        client_id: args.clientId, product_id: map.hostwinds_product_id,
        product_name: map.product_name || `Hostwinds ${map.hostwinds_product_id}`,
        hosting_id: null, domain: domainLabel, status: "provision_failed",
        raw: response, subscription_id: args.subscriptionId, portal_product_id: args.portalProductId,
        note: `API said: ${String(response?.msg ?? "unknown")}`,
      });
      return { status: "failed", hostingId: null, orderId, message: String(response?.msg ?? "unknown") };
    }

    const hostingIdRaw =
      response?.hosting_id ?? response?.hostingid ?? response?.id ?? response?.service_id ?? null;
    const hostingId = hostingIdRaw === null ? null : String(hostingIdRaw);

    await saveHwService(pool, {
      client_id: args.clientId, product_id: map.hostwinds_product_id,
      product_name: map.product_name || `Hostwinds ${map.hostwinds_product_id}`,
      hosting_id: hostingId, domain: domainLabel,
      status: hostingId ? "active" : "created_id_unknown",
      raw: response, subscription_id: args.subscriptionId, portal_product_id: args.portalProductId,
    });

    // Deliver credentials. The password is deliberately NOT persisted: if it is
    // lost the admin resets it (ChangePassword) rather than reading it from a table.
    if (client.email) {
      const label = map.product_name || "Hostwinds service";
      await sendEmail({
        to: String(client.email),
        subject: `Your ${label} is ready — Blue Mogul`,
        text:
`Hello ${client.name},

Your ${label} has been activated on your Blue Mogul account.

Service label: ${domainLabel}
Hosting service ID: ${hostingId ?? "being assigned — our team will confirm shortly"}
Portal login: https://portal.bluemogul.us

Your Hostwinds welcome e-mail (with the server IP and control-panel details) follows separately.
Your initial service password will be provided by our team — for security it is not stored in the portal.

Questions? Just reply to this message.

— Blue Mogul Enterprise LLC
Veteran owned, Houston proud`,
        html:
`<p>Hello ${client.name},</p>
<p>Your <strong>${label}</strong> has been activated on your Blue Mogul account.</p>
<ul>
  <li><strong>Service label:</strong> ${domainLabel}</li>
  <li><strong>Hosting service ID:</strong> ${hostingId ?? "being assigned — our team will confirm shortly"}</li>
  <li><strong>Portal:</strong> <a href="https://portal.bluemogul.us">portal.bluemogul.us</a></li>
</ul>
<p>Your Hostwinds welcome e-mail (server IP and control-panel details) follows separately.
Your initial service password will be provided by our team — for security it is not stored in the portal.</p>
<p>— Blue Mogul Enterprise LLC<br>Veteran owned, Houston proud</p>`,
      }).catch((e: any) => console.error("[hostwinds] credential email failed:", e.message));
    }

    return { status: "provisioned", hostingId, orderId, message: `provisioned (hosting id ${hostingId ?? "not returned by API"})` };
  } catch (e: any) {
    console.error(`[hostwinds] auto-provision failed for client ${args.clientId} / product ${args.portalProductId}:`, e.message);
    return { status: "failed", hostingId: null, message: e.message };
  }
}
