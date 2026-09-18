import pg from "pg";

/**
 * Xero Accounting integration — ITFlow `module_financial` parity (ledger side).
 *
 * ⚠️ This Xero app is a **Custom Connection**, not a standard app:
 *   - it authenticates with the client-credentials grant (NO browser consent,
 *     NO redirect URI, NO refresh token),
 *   - every API call must carry `Xero-tenant-id`,
 *   - GET /connections is NOT available (it answers 400 "Xero-User-Id and/or
 *     Xero-Tenant-Id header must be supplied").
 * Verified live 2026-09-18: POST /connect/token with grant_type=client_credentials
 * returns a 30-minute access token carrying the full accounting scope set.
 *
 * Config lives in `provider_settings` — which is a KEY/VALUE table
 * `(provider, key_name, key_value)`, NOT a JSON blob. (The original Xero code
 * here read/wrote `provider_name`/`settings` columns that do not exist, so it
 * could never have stored a token.)
 *
 * Env fallbacks: XERO_CLIENT_ID / XERO_CLIENT_SECRET / XERO_TENANT_ID.
 */

const XERO_TOKEN_URL = "https://identity.xero.com/connect/token";
const XERO_API_BASE = "https://api.xero.com/api.xro/2.0";
const TOKEN_SKEW_MS = 60_000;

export interface XeroSyncResult {
  organisation: string | null;
  contacts: number;
  invoices: number;
  payments: number;
  accounts: number;
  errors: string[];
}

function num(v: any): number | null {
  if (v === null || v === undefined || v === "") return null;
  const n = typeof v === "number" ? v : parseFloat(String(v).replace(/[^0-9.\-]/g, ""));
  return Number.isFinite(n) ? n : null;
}

function isoDate(v: any): string | null {
  if (!v) return null;
  const m = String(v).match(/\/Date\((\d+)/); // Xero sometimes returns /Date(1690000000000+0000)/
  const d = m ? new Date(Number(m[1])) : new Date(String(v));
  if (isNaN(d.getTime())) return null;
  return d.toISOString().slice(0, 10);
}

export interface XeroConfig {
  clientId: string;
  clientSecret: string;
  tenantId: string;
  accessToken: string;
  expiresAt: number;
}

/** Read the Xero config from `provider_settings` (k/v rows), env as fallback. */
export async function getXeroConfig(pool: pg.Pool): Promise<XeroConfig> {
  let kv: Record<string, string> = {};
  try {
    const { rows } = await pool.query(
      `SELECT key_name, key_value FROM provider_settings WHERE provider = 'xero'`
    );
    for (const r of rows) kv[String(r.key_name)] = r.key_value == null ? "" : String(r.key_value);
  } catch {
    kv = {};
  }
  return {
    clientId: kv["client_id"] || process.env.XERO_CLIENT_ID || "",
    clientSecret: kv["client_secret"] || process.env.XERO_CLIENT_SECRET || "",
    tenantId: kv["tenant_id"] || process.env.XERO_TENANT_ID || "",
    accessToken: kv["access_token"] || "",
    expiresAt: Number(kv["expires_at"] || 0),
  };
}

/** Upsert one or more Xero config keys into the k/v provider_settings table. */
export async function saveXeroConfig(pool: pg.Pool, patch: Record<string, string | number>): Promise<void> {
  for (const [key, value] of Object.entries(patch)) {
    await pool.query(
      `INSERT INTO provider_settings (provider, key_name, key_value, updated_at)
       VALUES ('xero', $1, $2, NOW())
       ON CONFLICT (provider, key_name) DO UPDATE SET key_value = EXCLUDED.key_value, updated_at = NOW()`,
      [key, String(value)]
    );
  }
}

/**
 * Mint (or reuse) an access token. Custom connections have no refresh token —
 * a new one is simply requested when the cached one is within its skew window.
 */
export async function xeroToken(pool: pg.Pool, force = false): Promise<string> {
  const cfg = await getXeroConfig(pool);
  if (!force && cfg.accessToken && cfg.expiresAt && Date.now() < cfg.expiresAt - TOKEN_SKEW_MS) {
    return cfg.accessToken;
  }
  if (!cfg.clientId || !cfg.clientSecret) {
    throw new Error("Xero not configured (missing client_id / client_secret)");
  }
  const resp = await fetch(XERO_TOKEN_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Authorization: "Basic " + Buffer.from(`${cfg.clientId}:${cfg.clientSecret}`).toString("base64"),
    },
    body: new URLSearchParams({ grant_type: "client_credentials" }).toString(),
    signal: AbortSignal.timeout(30000),
  });
  const text = await resp.text();
  if (!resp.ok) {
    throw new Error(`Xero token request failed (HTTP ${resp.status}): ${text.slice(0, 200)}`);
  }
  let json: any;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`Xero token response was not JSON: ${text.slice(0, 160)}`);
  }
  if (!json.access_token) throw new Error("Xero token response contained no access_token");
  await saveXeroConfig(pool, {
    access_token: json.access_token,
    expires_at: Date.now() + Number(json.expires_in || 1800) * 1000,
  });
  return json.access_token;
}

/** Authenticated Xero API call. Retries once with a fresh token on 401. */
export async function xeroRequest(pool: pg.Pool, path: string, query: Record<string, string | number> = {}): Promise<any> {
  const cfg = await getXeroConfig(pool);
  if (!cfg.tenantId) {
    throw new Error(
      "Xero tenant_id is not set — paste the Tenant ID from the custom connection page in developer.xero.com into the Xero settings"
    );
  }
  const qs = new URLSearchParams(
    Object.fromEntries(Object.entries(query).map(([k, v]) => [k, String(v)]))
  ).toString();
  const url = `${XERO_API_BASE}/${path.replace(/^\/+/, "")}${qs ? `?${qs}` : ""}`;

  for (let attempt = 0; attempt < 2; attempt++) {
    const token = await xeroToken(pool, attempt > 0);
    const resp = await fetch(url, {
      headers: {
        Authorization: `Bearer ${token}`,
        "Xero-tenant-id": cfg.tenantId,
        Accept: "application/json",
      },
      signal: AbortSignal.timeout(45000),
    });
    const text = await resp.text();
    if (resp.status === 401 && attempt === 0) continue; // expired mid-flight — retry once
    if (!resp.ok) {
      throw new Error(`Xero ${path} failed (HTTP ${resp.status}): ${text.replace(/\s+/g, " ").slice(0, 220)}`);
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(`Xero ${path} returned non-JSON: ${text.slice(0, 160)}`);
    }
  }
  throw new Error(`Xero ${path} failed after token refresh`);
}

/** Walk a Xero paged endpoint (100 records per page) up to a cap. */
async function xeroPaged(pool: pg.Pool, path: string, collection: string, maxPages = 30): Promise<any[]> {
  const out: any[] = [];
  for (let page = 1; page <= maxPages; page++) {
    const data = await xeroRequest(pool, path, { page });
    const batch: any[] = data?.[collection] ?? [];
    out.push(...batch);
    if (batch.length < 100) break;
  }
  return out;
}

/**
 * Pull the Xero ledger into the local mirror. Sections are isolated so a single
 * failing endpoint can't blank the whole sync.
 */
export async function syncXero(pool: pg.Pool): Promise<XeroSyncResult> {
  const r: XeroSyncResult = { organisation: null, contacts: 0, invoices: 0, payments: 0, accounts: 0, errors: [] };
  const cfg = await getXeroConfig(pool);
  if (!cfg.clientId || !cfg.clientSecret) throw new Error("Xero not configured (missing client_id / client_secret)");

  const { rows: runRows } = await pool.query(`INSERT INTO xero_sync_runs (status) VALUES ('running') RETURNING id`);
  const runId = runRows[0].id;

  try {
    // ── Organisation ────────────────────────────────────────────────────────
    try {
      const data = await xeroRequest(pool, "Organisation");
      const org = data?.Organisations?.[0];
      if (org) {
        r.organisation = org.Name ?? null;
        await pool.query(
          `INSERT INTO xero_organisation (organisation_id, name, legal_name, base_currency, country_code, org_type, tax_number, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,NOW())
           ON CONFLICT (organisation_id) DO UPDATE SET name = EXCLUDED.name, legal_name = EXCLUDED.legal_name,
             base_currency = EXCLUDED.base_currency, country_code = EXCLUDED.country_code, org_type = EXCLUDED.org_type,
             tax_number = EXCLUDED.tax_number, synced_at = NOW()`,
          [
            org.OrganisationID ?? cfg.tenantId, org.Name ?? null, org.LegalName ?? null,
            org.BaseCurrency ?? null, org.CountryCode ?? null, org.OrganisationType ?? null, org.TaxNumber ?? null,
          ]
        );
      }
    } catch (e: any) {
      r.errors.push(`organisation: ${e.message}`);
    }

    // ── Contacts ────────────────────────────────────────────────────────────
    try {
      const contacts = await xeroPaged(pool, "Contacts", "Contacts");
      for (const c of contacts) {
        const clientId = await matchPortalClient(pool, c.Name ?? "", c.EmailAddress ?? "");
        await pool.query(
          `INSERT INTO xero_contacts (contact_id, name, email, first_name, last_name, phone, is_customer, is_supplier, status, client_id, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,NOW())
           ON CONFLICT (contact_id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email,
             is_customer = EXCLUDED.is_customer, is_supplier = EXCLUDED.is_supplier, status = EXCLUDED.status,
             client_id = COALESCE(EXCLUDED.client_id, xero_contacts.client_id), synced_at = NOW()`,
          [
            c.ContactID, c.Name ?? null, c.EmailAddress ?? null, c.FirstName ?? null, c.LastName ?? null,
            [c.Phones?.[0]?.PhoneNumber].filter(Boolean).join("") || null,
            !!c.IsCustomer, !!c.IsSupplier, c.ContactStatus ?? null, clientId,
          ]
        );
        r.contacts++;
      }
    } catch (e: any) {
      r.errors.push(`contacts: ${e.message}`);
    }

    // ── Invoices ────────────────────────────────────────────────────────────
    try {
      const invoices = await xeroPaged(pool, "Invoices", "Invoices");
      for (const inv of invoices) {
        let clientId: number | null = null;
        if (inv.Contact?.ContactID) {
          const lk = await pool.query(`SELECT client_id FROM xero_contacts WHERE contact_id = $1`, [inv.Contact.ContactID]);
          clientId = lk.rows[0]?.client_id ?? null;
        }
        await pool.query(
          `INSERT INTO xero_invoices (invoice_id, invoice_number, type, contact_id, contact_name, client_id, status,
             invoice_date, due_date, subtotal, total_tax, total, amount_paid, amount_due, amount_credited, currency,
             reference, line_item_count, updated_utc, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,NOW())
           ON CONFLICT (invoice_id) DO UPDATE SET status = EXCLUDED.status, total = EXCLUDED.total,
             amount_paid = EXCLUDED.amount_paid, amount_due = EXCLUDED.amount_due, amount_credited = EXCLUDED.amount_credited,
             due_date = EXCLUDED.due_date, reference = EXCLUDED.reference, line_item_count = EXCLUDED.line_item_count,
             client_id = COALESCE(EXCLUDED.client_id, xero_invoices.client_id), updated_utc = EXCLUDED.updated_utc,
             synced_at = NOW()`,
          [
            inv.InvoiceID, inv.InvoiceNumber ?? null, inv.Type ?? null, inv.Contact?.ContactID ?? null,
            inv.Contact?.Name ?? null, clientId, inv.Status ?? null,
            isoDate(inv.Date), isoDate(inv.DueDate),
            num(inv.SubTotal), num(inv.TotalTax), num(inv.Total), num(inv.AmountPaid), num(inv.AmountDue),
            num(inv.AmountCredited), inv.CurrencyCode ?? null, inv.Reference ?? null,
            Array.isArray(inv.LineItems) ? inv.LineItems.length : 0, inv.UpdatedDateUTC ?? null,
          ]
        );
        r.invoices++;
      }
    } catch (e: any) {
      r.errors.push(`invoices: ${e.message}`);
    }

    // ── Payments ────────────────────────────────────────────────────────────
    try {
      const payments = await xeroPaged(pool, "Payments", "Payments");
      for (const p of payments) {
        let clientId: number | null = null;
        if (p.Invoice?.Contact?.ContactID) {
          const lk = await pool.query(`SELECT client_id FROM xero_contacts WHERE contact_id = $1`, [p.Invoice.Contact.ContactID]);
          clientId = lk.rows[0]?.client_id ?? null;
        }
        await pool.query(
          `INSERT INTO xero_payments (payment_id, invoice_id, invoice_number, contact_name, client_id, payment_date, amount, currency, account_name, payment_type, status, reference, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,NOW())
           ON CONFLICT (payment_id) DO UPDATE SET amount = EXCLUDED.amount, status = EXCLUDED.status,
             reference = EXCLUDED.reference, synced_at = NOW()`,
          [
            p.PaymentID, p.Invoice?.InvoiceID ?? null, p.Invoice?.InvoiceNumber ?? null,
            p.Invoice?.Contact?.Name ?? null, clientId, isoDate(p.Date), num(p.Amount), p.CurrencyRate ? null : null,
            p.Account?.Name ?? null, p.PaymentType ?? null, p.Status ?? null, p.Reference ?? null,
          ]
        );
        r.payments++;
      }
    } catch (e: any) {
      r.errors.push(`payments: ${e.message}`);
    }

    // ── Chart of accounts ───────────────────────────────────────────────────
    try {
      const accounts = await xeroPaged(pool, "Accounts", "Accounts");
      for (const a of accounts) {
        await pool.query(
          `INSERT INTO xero_accounts (account_id, code, name, type, tax_type, status, description, currency, enable_payments, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NOW())
           ON CONFLICT (account_id) DO UPDATE SET code = EXCLUDED.code, name = EXCLUDED.name, type = EXCLUDED.type,
             tax_type = EXCLUDED.tax_type, status = EXCLUDED.status, description = EXCLUDED.description,
             currency = EXCLUDED.currency, synced_at = NOW()`,
          [
            a.AccountID, a.Code ?? null, a.Name ?? null, a.Type ?? null, a.TaxType ?? null,
            a.Status ?? null, a.Description ?? null, a.CurrencyCode ?? null, !!a.EnablePaymentsToAccount,
          ]
        );
        r.accounts++;
      }
    } catch (e: any) {
      r.errors.push(`accounts: ${e.message}`);
    }

    await pool.query(
      `UPDATE xero_sync_runs SET finished_at = NOW(), status = $1, organisation = $2, contacts = $3,
         invoices = $4, payments = $5, accounts = $6, error = $7 WHERE id = $8`,
      [
        r.errors.length ? "partial" : "ok", r.organisation, r.contacts, r.invoices, r.payments, r.accounts,
        r.errors.length ? r.errors.join(" | ").slice(0, 2000) : null, runId,
      ]
    );
    return r;
  } catch (e: any) {
    await pool.query(`UPDATE xero_sync_runs SET finished_at = NOW(), status = 'failed', error = $1 WHERE id = $2`,
      [String(e.message).slice(0, 2000), runId]);
    throw e;
  }
}

/** Match a Xero contact onto a portal client (email → exact name/company → substring). */
async function matchPortalClient(pool: pg.Pool, name: string, email: string): Promise<number | null> {
  if (email) {
    const r = await pool.query(`SELECT id FROM clients WHERE lower(email) = lower($1) LIMIT 1`, [email]);
    if (r.rows[0]) return r.rows[0].id;
  }
  if (name) {
    const r = await pool.query(
      `SELECT id FROM clients WHERE lower(name) = lower($1) OR lower(COALESCE(company,'')) = lower($1) LIMIT 1`,
      [name]
    );
    if (r.rows[0]) return r.rows[0].id;
    const s = await pool.query(
      `SELECT id FROM clients WHERE lower($1) LIKE '%' || lower(name) || '%' OR lower(name) LIKE '%' || lower($1) || '%'
          OR lower(COALESCE(company,'')) LIKE '%' || lower($1) || '%' LIMIT 1`,
      [name]
    );
    if (s.rows[0]) return s.rows[0].id;
  }
  return null;
}

/** Connection state for the Xero page / status endpoint. */
export async function xeroStatus(pool: pg.Pool): Promise<any> {
  const cfg = await getXeroConfig(pool);
  const last = await pool.query(`SELECT * FROM xero_sync_runs ORDER BY id DESC LIMIT 1`);
  const counts = await pool.query(
    `SELECT (SELECT COUNT(*)::int FROM xero_contacts) AS contacts,
            (SELECT COUNT(*)::int FROM xero_invoices) AS invoices,
            (SELECT COUNT(*)::int FROM xero_payments) AS payments,
            (SELECT COUNT(*)::int FROM xero_accounts) AS accounts`
  );
  const totals = await pool.query(
    `SELECT COALESCE(SUM(total),0)::numeric AS invoiced,
            COALESCE(SUM(amount_paid),0)::numeric AS collected,
            COALESCE(SUM(amount_due),0)::numeric AS outstanding
       FROM xero_invoices WHERE type = 'ACCREC'`
  );
  const org = await pool.query(`SELECT name, legal_name, base_currency FROM xero_organisation LIMIT 1`);
  return {
    credentials_set: !!(cfg.clientId && cfg.clientSecret),
    client_id: cfg.clientId || null,
    tenant_configured: !!cfg.tenantId,
    token_cached: !!(cfg.accessToken && cfg.expiresAt > Date.now()),
    token_expires_at: cfg.expiresAt || null,
    organisation: org.rows[0] ?? null,
    last_run: last.rows[0] ?? null,
    counts: counts.rows[0],
    totals: totals.rows[0],
  };
}
