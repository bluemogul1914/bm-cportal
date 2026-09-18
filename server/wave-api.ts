import pg from "pg";

/**
 * Wave Accounting integration (ITFlow `module_financial` parity — accounting side).
 *
 * Wave's public API is GraphQL at https://gql.waveapps.com/graphql/public and
 * authenticates with a bearer token (a developer-portal full-access token, or an
 * OAuth access token). All credentials live in `system_settings`:
 *   wave_token          — bearer token (required)
 *   wave_client_id      — OAuth client id (stored for future refresh flows)
 *   wave_client_secret  — OAuth client secret
 *   wave_business_id    — which Wave business to mirror (else the first non-personal)
 *
 * Verified live 2026-09-18 against the Blue Mogul Wave account: businesses,
 * accounts, customers, invoices, invoice payments and vendors all return data.
 * NOTE: Wave's public API does NOT expose expenses/transactions — only
 * invoices/estimates/customers/vendors/products/accounts/salesTaxes. Expense
 * tracking therefore stays portal-side (see docs/itflow-parity-build-plan.md).
 */

const WAVE_GQL = process.env.WAVE_API_URL || "https://gql.waveapps.com/graphql/public";
const PAGE_SIZE = 100;
const MAX_PAGES = 40;

export interface WaveSyncResult {
  business: string;
  business_wave_id: string;
  accounts: number;
  customers: number;
  invoices: number;
  payments: number;
  vendors: number;
  errors: string[];
}

/**
 * Normalise a Wave amount to a number.
 *
 * ⚠️ TRAP (cost an hour on 2026-09-18): Wave's `Money.value` is a
 * **locale-formatted string with thousands separators** — `"2,350.00"`.
 * `parseFloat("2,350.00")` silently returns `2`, so every amount ≥ $1,000 was
 * truncated to its first group. The authoritative numeric field is
 * `minorUnitValue` (integer minor units, e.g. cents) — divide by the currency
 * exponent (2 for USD). Never parse `value` without stripping separators.
 */
function money(v: any): number | null {
  if (v === null || v === undefined) return null;
  if (typeof v === "object") {
    if (v.minorUnitValue !== undefined && v.minorUnitValue !== null && v.minorUnitValue !== "") {
      const exponent = Number(v.currency?.exponent ?? 2);
      const n = Number(v.minorUnitValue) / Math.pow(10, Number.isFinite(exponent) ? exponent : 2);
      if (Number.isFinite(n)) return n;
    }
    if (v.value !== undefined) return money(v.value);
    return null;
  }
  // Strip thousands separators, currency symbols and spaces before parsing.
  const cleaned = String(v).replace(/[^0-9.\-]/g, "");
  const n = parseFloat(cleaned);
  return Number.isFinite(n) ? n : null;
}

function dateOrNull(v: any): string | null {
  if (!v) return null;
  const s = String(v).slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : null;
}

export async function getWaveToken(pool: pg.Pool): Promise<string> {
  const { rows } = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings
      WHERE lower(setting_key) IN ('wave_token','wave_access_token')`
  );
  for (const r of rows) if (r.setting_value) return String(r.setting_value).trim();
  return (process.env.WAVE_TOKEN || "").trim();
}

async function gql(token: string, query: string, variables?: Record<string, unknown>): Promise<any> {
  const resp = await fetch(WAVE_GQL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ query, variables: variables ?? {} }),
    signal: AbortSignal.timeout(30000),
  });
  const text = await resp.text();
  let json: any;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`Wave returned non-JSON (HTTP ${resp.status}): ${text.slice(0, 140)}`);
  }
  if (Array.isArray(json.errors) && json.errors.length) {
    throw new Error(`Wave API error: ${json.errors.map((e: any) => e.message).join("; ")}`);
  }
  if (!resp.ok) throw new Error(`Wave HTTP ${resp.status}: ${text.slice(0, 140)}`);
  return json.data;
}

/** Walk a Wave connection (`edges { node }`) page by page.
 * `build(args)` receives a pagination argument string (e.g. `(pageSize: 100, page: 2)`)
 * or "" when the connection rejects pagination args — we retry unpaged once. */
async function fetchPaged(
  token: string,
  build: (args: string) => string,
  pick: (data: any) => any
): Promise<any[]> {
  const out: any[] = [];
  const nodes = (data: any) => {
    const edges: any[] = pick(data)?.edges ?? [];
    return edges.map((e) => e?.node).filter(Boolean);
  };

  for (let page = 1; page <= MAX_PAGES; page++) {
    let data: any;
    try {
      data = await gql(token, build(`(pageSize: ${PAGE_SIZE}, page: ${page})`));
    } catch (e: any) {
      if (page > 1) throw e;
      // Some Wave connections reject pagination arguments outright.
      data = await gql(token, build(""));
      return nodes(data);
    }
    const batch = nodes(data);
    out.push(...batch);
    const totalPages = Number(pick(data)?.pageInfo?.totalPages ?? 1);
    if (!batch.length || page >= totalPages) break;
  }
  return out;
}

/** Match a Wave customer/vendor onto a portal client (email → exact name/company → substring). */
async function matchPortalClient(pool: pg.Pool, name: string, email: string): Promise<number | null> {
  if (email) {
    const r = await pool.query(`SELECT id FROM clients WHERE lower(email) = lower($1) LIMIT 1`, [email]);
    if (r.rows[0]) return r.rows[0].id;
  }
  if (name) {
    const r = await pool.query(
      `SELECT id FROM clients
        WHERE lower(name) = lower($1) OR lower(COALESCE(company,'')) = lower($1)
        LIMIT 1`,
      [name]
    );
    if (r.rows[0]) return r.rows[0].id;
    const s = await pool.query(
      `SELECT id FROM clients
        WHERE lower($1) LIKE '%' || lower(name) || '%'
           OR lower(name) LIKE '%' || lower($1) || '%'
        LIMIT 1`,
      [name]
    );
    if (s.rows[0]) return s.rows[0].id;
  }
  return null;
}

export async function fetchWaveBusinesses(token: string): Promise<any[]> {
  const data = await gql(token, `{ businesses { edges { node { id name currency { code } isPersonal } } } }`);
  return (data?.businesses?.edges ?? []).map((e: any) => e.node).filter(Boolean);
}

/** Mirror every selected Wave business into the portal. */
export async function syncWave(pool: pg.Pool): Promise<WaveSyncResult[]> {
  const token = await getWaveToken(pool);
  if (!token) throw new Error("Wave not configured (set wave_token in system_settings)");

  const { rows: sel } = await pool.query(
    `SELECT setting_value FROM system_settings WHERE lower(setting_key) = 'wave_business_id'`
  );
  const wanted = sel[0]?.setting_value ? String(sel[0].setting_value) : "";

  const all = await fetchWaveBusinesses(token);
  const targets = wanted
    ? all.filter((b) => b.id === wanted)
    : (all.filter((b) => !b.isPersonal).slice(0, 1));

  if (!targets.length) {
    throw new Error(
      wanted
        ? `Wave business ${wanted} not found (check wave_business_id)`
        : "No non-personal Wave business found"
    );
  }

  const { rows: runRows } = await pool.query(
    `INSERT INTO wave_sync_runs (status) VALUES ('running') RETURNING id`
  );
  const runId = runRows[0].id;

  const results: WaveSyncResult[] = [];
  try {
    for (const b of targets) {
      const r: WaveSyncResult = {
        business: b.name,
        business_wave_id: b.id,
        accounts: 0,
        customers: 0,
        invoices: 0,
        payments: 0,
        vendors: 0,
        errors: [],
      };

      await pool.query(
        `INSERT INTO wave_businesses (wave_id, name, currency, is_personal, synced_at)
         VALUES ($1,$2,$3,$4,NOW())
         ON CONFLICT (wave_id) DO UPDATE SET name = EXCLUDED.name, currency = EXCLUDED.currency,
           is_personal = EXCLUDED.is_personal, synced_at = NOW()`,
        [b.id, b.name ?? "", b.currency?.code ?? "USD", !!b.isPersonal]
      );

      // ── Accounts (balances) ────────────────────────────────────────────────
      try {
        const accounts = await fetchPaged(
          token,
          (args) =>
            `{ business(id: "${b.id}") { accounts${args} { edges { node { id name displayId description currency { code } type { name value } subtype { name } isArchived sequence balance balanceInBusinessCurrency } } pageInfo { totalPages } } } }`,
          (d) => d?.business?.accounts
        );
        for (const a of accounts) {
          await pool.query(
            `INSERT INTO wave_accounts (wave_id, business_wave_id, name, display_id, description, type_name, type_value, subtype_name, currency, balance, balance_business_currency, is_archived, sequence, synced_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,NOW())
             ON CONFLICT (wave_id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description,
               type_name = EXCLUDED.type_name, type_value = EXCLUDED.type_value, subtype_name = EXCLUDED.subtype_name,
               currency = EXCLUDED.currency, balance = EXCLUDED.balance,
               balance_business_currency = EXCLUDED.balance_business_currency,
               is_archived = EXCLUDED.is_archived, sequence = EXCLUDED.sequence, synced_at = NOW()`,
            [
              a.id, b.id, a.name ?? "", a.displayId ?? null, a.description ?? null,
              a.type?.name ?? null, String(a.type?.value ?? "") || null, a.subtype?.name ?? null,
              a.currency?.code ?? null,
              money(a.balance), money(a.balanceInBusinessCurrency),
              !!a.isArchived, Number.isFinite(a.sequence) ? a.sequence : null,
            ]
          );
          r.accounts++;
        }
      } catch (e: any) {
        r.errors.push(`accounts: ${e.message}`);
      }

      // ── Customers (and their portal-client match) ──────────────────────────
      try {
        const customers = await fetchPaged(
          token,
          (args) =>
            `{ business(id: "${b.id}") { customers${args} { edges { node { id name email displayId phone currency { code } isArchived outstandingAmount { value } overdueAmount { value } } } pageInfo { totalPages } } } }`,
          (d) => d?.business?.customers
        );
        for (const c of customers) {
          const clientId = await matchPortalClient(pool, c.name ?? "", c.email ?? "");
          await pool.query(
            `INSERT INTO wave_customers (wave_id, business_wave_id, name, email, display_id, phone, currency, outstanding, overdue, is_archived, client_id, synced_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,NOW())
             ON CONFLICT (wave_id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email,
               outstanding = EXCLUDED.outstanding, overdue = EXCLUDED.overdue,
               is_archived = EXCLUDED.is_archived,
               client_id = COALESCE(EXCLUDED.client_id, wave_customers.client_id), synced_at = NOW()`,
            [
              c.id, b.id, c.name ?? "", c.email ?? null, c.displayId ?? null, c.phone ?? null,
              c.currency?.code ?? null, money(c.outstandingAmount), money(c.overdueAmount),
              !!c.isArchived, clientId,
            ]
          );
          r.customers++;
        }
      } catch (e: any) {
        r.errors.push(`customers: ${e.message}`);
      }

      // ── Invoices + their payments ──────────────────────────────────────────
      try {
        const invoices = await fetchPaged(
          token,
          (args) =>
            `{ business(id: "${b.id}") { invoices${args} { edges { node { id invoiceNumber status title poNumber invoiceDate dueDate total { value } amountPaid { value } amountDue { value } taxTotal { value } subtotal { value } currency { code } customer { id name email } pdfUrl viewUrl payments { id paymentDate amount memo paymentMethod } } } pageInfo { totalPages } } } }`,
          (d) => d?.business?.invoices
        );
        for (const inv of invoices) {
          const custId: string | null = inv.customer?.id ?? null;
          let clientId: number | null = null;
          if (custId) {
            const lk = await pool.query(`SELECT client_id FROM wave_customers WHERE wave_id = $1`, [custId]);
            clientId = lk.rows[0]?.client_id ?? null;
          }
          await pool.query(
            `INSERT INTO wave_invoices (wave_id, business_wave_id, invoice_number, status, title, po_number, invoice_date, due_date, customer_wave_id, customer_name, customer_email, client_id, total, amount_paid, amount_due, tax_total, subtotal, currency, pdf_url, view_url, synced_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,NOW())
             ON CONFLICT (wave_id) DO UPDATE SET status = EXCLUDED.status, title = EXCLUDED.title,
               invoice_date = EXCLUDED.invoice_date, due_date = EXCLUDED.due_date,
               total = EXCLUDED.total, amount_paid = EXCLUDED.amount_paid, amount_due = EXCLUDED.amount_due,
               tax_total = EXCLUDED.tax_total, subtotal = EXCLUDED.subtotal, currency = EXCLUDED.currency,
               pdf_url = EXCLUDED.pdf_url, view_url = EXCLUDED.view_url,
               client_id = COALESCE(EXCLUDED.client_id, wave_invoices.client_id), synced_at = NOW()`,
            [
              inv.id, b.id, inv.invoiceNumber ?? null, inv.status ?? null, inv.title ?? null,
              inv.poNumber ?? null, dateOrNull(inv.invoiceDate), dateOrNull(inv.dueDate),
              custId, inv.customer?.name ?? null, inv.customer?.email ?? null, clientId,
              money(inv.total), money(inv.amountPaid), money(inv.amountDue),
              money(inv.taxTotal), money(inv.subtotal), inv.currency?.code ?? null,
              inv.pdfUrl ?? null, inv.viewUrl ?? null,
            ]
          );
          r.invoices++;

          for (const pmt of inv.payments ?? []) {
            if (!pmt?.id) continue;
            await pool.query(
              `INSERT INTO wave_payments (wave_id, business_wave_id, invoice_wave_id, invoice_number, customer_name, client_id, payment_date, amount, currency, payment_method, memo, synced_at)
               VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,NOW())
               ON CONFLICT (wave_id) DO UPDATE SET payment_date = EXCLUDED.payment_date, amount = EXCLUDED.amount,
                 payment_method = EXCLUDED.payment_method, memo = EXCLUDED.memo, synced_at = NOW()`,
              [
                pmt.id, b.id, inv.id, inv.invoiceNumber ?? null, inv.customer?.name ?? null, clientId,
                dateOrNull(pmt.paymentDate), money(pmt.amount), inv.currency?.code ?? null,
                pmt.paymentMethod ?? null, pmt.memo ?? null,
              ]
            );
            r.payments++;
          }
        }
      } catch (e: any) {
        r.errors.push(`invoices: ${e.message}`);
      }

      // ── Vendors ────────────────────────────────────────────────────────────
      try {
        const vendors = await fetchPaged(
          token,
          (args) =>
            `{ business(id: "${b.id}") { vendors${args} { edges { node { id name email displayId phone isArchived } } pageInfo { totalPages } } } }`,
          (d) => d?.business?.vendors
        );
        for (const v of vendors) {
          await pool.query(
            `INSERT INTO wave_vendors (wave_id, business_wave_id, name, email, display_id, phone, is_archived, synced_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7,NOW())
             ON CONFLICT (wave_id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email,
               is_archived = EXCLUDED.is_archived, synced_at = NOW()`,
            [v.id, b.id, v.name ?? "", v.email ?? null, v.displayId ?? null, v.phone ?? null, !!v.isArchived]
          );
          r.vendors++;
        }
      } catch (e: any) {
        r.errors.push(`vendors: ${e.message}`);
      }

      results.push(r);
    }

    const totalErrors = results.flatMap((r) => r.errors);
    await pool.query(
      `UPDATE wave_sync_runs SET finished_at = NOW(), status = $1, businesses = $2, accounts = $3,
         customers = $4, invoices = $5, payments = $6, vendors = $7, error = $8
       WHERE id = $9`,
      [
        totalErrors.length ? "partial" : "ok",
        results.length,
        results.reduce((n, r) => n + r.accounts, 0),
        results.reduce((n, r) => n + r.customers, 0),
        results.reduce((n, r) => n + r.invoices, 0),
        results.reduce((n, r) => n + r.payments, 0),
        results.reduce((n, r) => n + r.vendors, 0),
        totalErrors.length ? totalErrors.join(" | ").slice(0, 2000) : null,
        runId,
      ]
    );
    return results;
  } catch (e: any) {
    await pool.query(
      `UPDATE wave_sync_runs SET finished_at = NOW(), status = 'failed', error = $1 WHERE id = $2`,
      [String(e.message).slice(0, 2000), runId]
    );
    throw e;
  }
}

/** Connection state for the Financials page / status endpoint. */
export async function waveStatus(pool: pg.Pool): Promise<any> {
  const token = await getWaveToken(pool);
  const { rows: settings } = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings
      WHERE lower(setting_key) IN ('wave_business_id','wave_client_id')`
  );
  const s: Record<string, string> = {};
  for (const r of settings) s[String(r.setting_key).toLowerCase()] = r.setting_value;

  const last = await pool.query(
    `SELECT * FROM wave_sync_runs ORDER BY id DESC LIMIT 1`
  );
  const counts = await pool.query(
    `SELECT (SELECT COUNT(*)::int FROM wave_accounts)  AS accounts,
            (SELECT COUNT(*)::int FROM wave_customers) AS customers,
            (SELECT COUNT(*)::int FROM wave_invoices)  AS invoices,
            (SELECT COUNT(*)::int FROM wave_payments)  AS payments,
            (SELECT COUNT(*)::int FROM wave_vendors)   AS vendors`
  );
  const totals = await pool.query(
    `SELECT COALESCE(SUM(total),0)::numeric       AS invoiced,
            COALESCE(SUM(amount_paid),0)::numeric AS collected,
            COALESCE(SUM(amount_due),0)::numeric  AS outstanding
       FROM wave_invoices`
  );
  return {
    configured: !!token,
    business_configured: !!s["wave_business_id"],
    oauth_client_stored: !!s["wave_client_id"],
    last_run: last.rows[0] ?? null,
    counts: counts.rows[0],
    totals: totals.rows[0],
  };
}
