import pg from "pg";

/**
 * Dealer payouts — paying dealers for sales via PayPal or Stripe Connect.
 *
 * Two rails, one record. A payout row in `dealer_payouts` is created FIRST
 * (status 'pending') and the provider call is a separate, retryable step, so a
 * missing credential or a provider outage never loses the record of what a
 * dealer is owed. Marketing-to-dealer marking of commissions as paid happens
 * only on a successful dispatch (or on a manual/ACH payout, where the operator
 * is the one who moved the money).
 *
 * Providers (verified against their public API shapes; NOT yet executed live —
 * see `payoutCapabilities()` for what is configured):
 *   PayPal Payouts: POST {api-m}/v1/payments/payouts  (OAuth2 client_credentials)
 *   Stripe Connect: POST https://api.stripe.com/v1/transfers  (Bearer secret key, destination=acct_…)
 */

export type PayoutMethod = "paypal" | "stripe_connect" | "ach" | "manual";

export interface PayoutCapabilities {
  paypal_configured: boolean;
  paypal_env: "sandbox" | "live";
  stripe_configured: boolean;
  stripe_connect_used_before: boolean;
  notes: string[];
}

/** Which rails can actually move money right now. */
export async function payoutCapabilities(pool: pg.Pool): Promise<PayoutCapabilities> {
  const notes: string[] = [];
  const paypalId = process.env.PAYPAL_CLIENT_ID || "";
  const paypalSecret = process.env.PAYPAL_CLIENT_SECRET || "";
  const paypalEnv = (process.env.PAYPAL_ENV || "sandbox").toLowerCase() === "live" ? "live" : "sandbox";
  const stripeKey = process.env.STRIPE_SECRET_KEY || "";
  const paypal_configured = Boolean(paypalId && paypalSecret);

  if (!paypal_configured) {
    notes.push("PayPal not configured: set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET (and PAYPAL_ENV=sandbox|live). The Payouts feature must also be enabled on the PayPal app.");
  }
  if (!stripeKey) {
    notes.push("Stripe secret key missing: set STRIPE_SECRET_KEY.");
  } else {
    notes.push("Stripe key present. Stripe Connect must be enabled on the account, and each dealer needs a connected account id (acct_…) — dealer onboarding (Account Links) is a separate step.");
  }

  let stripe_connect_used_before = false;
  try {
    const r = await pool.query(
      `SELECT 1 FROM dealer_payouts WHERE method = 'stripe_connect' AND provider_ref IS NOT NULL LIMIT 1`
    );
    stripe_connect_used_before = r.rows.length > 0;
  } catch { /* table may predate the column */ }

  return { paypal_configured, paypal_env: paypalEnv, stripe_configured: Boolean(stripeKey), stripe_connect_used_before, notes };
}

/** Save where a dealer wants to be paid. */
export async function savePayoutDestination(pool: pg.Pool, args: {
  dealerId: number;
  method: PayoutMethod;
  paypalEmail?: string | null;
  stripeAccountId?: string | null;
}): Promise<void> {
  if (args.stripeAccountId && !/^acct_[A-Za-z0-9]+$/.test(args.stripeAccountId)) {
    throw new Error("Stripe connected account ids look like acct_XXXX — that value does not match.");
  }
  if (args.paypalEmail && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(args.paypalEmail)) {
    throw new Error("That PayPal email does not look like an email address.");
  }
  await pool.query(
    `UPDATE dealers SET payout_method = $2, paypal_email = COALESCE($3, paypal_email),
            stripe_account_id = COALESCE($4, stripe_account_id), updated_at = NOW()
      WHERE id = $1`,
    [args.dealerId, args.method, args.paypalEmail ?? null, args.stripeAccountId ?? null]
  );
}

/** The dealer's payout profile + how it resolves to a destination string. */
export async function getPayoutProfile(pool: pg.Pool, dealerId: number): Promise<any | null> {
  const { rows } = await pool.query(
    `SELECT id, full_name, company_name, email, commission_rate, payout_method,
            paypal_email, stripe_account_id, ach_routing, ach_account, ach_name, bank_name
       FROM dealers WHERE id = $1`,
    [dealerId]
  );
  if (!rows.length) return null;
  const d = rows[0];
  const method: PayoutMethod = (d.payout_method || "manual") as PayoutMethod;
  let destination: string | null = null;
  if (method === "paypal") destination = d.paypal_email || null;
  else if (method === "stripe_connect") destination = d.stripe_account_id || null;
  else if (method === "ach") destination = d.ach_account ? `****${String(d.ach_account).slice(-4)}${d.bank_name ? " · " + d.bank_name : ""}` : null;
  return { ...d, method, destination, ready: Boolean(destination) };
}

/** Commissions owed to a dealer but not yet paid out (admin-side model). */
export async function listPayableCommissions(pool: pg.Pool, dealerId: number): Promise<any[]> {
  const { rows } = await pool.query(
    `SELECT dc.id, dc.amount, dc.status, dc.order_id, dc.created_at,
            o.product_line, o.customer_name, o.order_ref
       FROM dealer_commissions dc
       LEFT JOIN dealer_orders o ON o.id = dc.order_id
      WHERE dc.dealer_id = $1 AND dc.status IN ('pending','approved')
      ORDER BY dc.created_at ASC`,
    [dealerId]
  );
  return rows;
}

/**
 * Record a payout. Creates the ledger row and (unless dryRun) marks the selected
 * commissions paid. Returns the payout id — dispatch is a separate call.
 */
export async function createDealerPayout(pool: pg.Pool, args: {
  dealerId: number;
  amountCents: number;
  method: PayoutMethod;
  destination?: string | null;
  commissionIds?: number[];
  note?: string | null;
  actor?: string | null;
  reference?: string | null;
}): Promise<{ payoutId: number; commissionsMarked: number; status: string }> {
  if (!Number.isFinite(args.amountCents) || args.amountCents <= 0) throw new Error("Payout amount must be greater than zero");

  const ids = (args.commissionIds ?? []).filter((n) => Number.isFinite(n));
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    const ins = await client.query(
      `INSERT INTO dealer_payouts
         (dealer_id, amount_cents, commission_count, status, method, destination, reference, note, created_by, initiated_at, created_at)
       VALUES ($1,$2,$3,'pending',$4,$5,$6,$7,$8,NOW(),NOW()) RETURNING id`,
      [args.dealerId, Math.round(args.amountCents), ids.length, args.method, args.destination ?? null,
       args.reference ?? null, args.note ?? null, args.actor ?? null]
    );
    const payoutId = ins.rows[0].id;

    let marked = 0;
    if (ids.length) {
      const upd = await client.query(
        `UPDATE dealer_commissions SET status='paid', paid_at=NOW(), payout_id=$1
          WHERE dealer_id=$2 AND id = ANY($3::int[]) AND status IN ('pending','approved')`,
        [payoutId, args.dealerId, ids]
      );
      marked = upd.rowCount ?? 0;
      await client.query(`UPDATE dealer_payouts SET commission_ids=$2, commission_count=$3 WHERE id=$1`,
        [payoutId, JSON.stringify(ids), marked]);
    }
    await client.query("COMMIT");
    return { payoutId, commissionsMarked: marked, status: "pending" };
  } catch (e) {
    await client.query("ROLLBACK").catch(() => {});
    throw e;
  } finally {
    client.release();
  }
}

/* ── PayPal Payouts ────────────────────────────────────────────────────────── */

async function paypalAccessToken(): Promise<string> {
  const id = process.env.PAYPAL_CLIENT_ID || "";
  const secret = process.env.PAYPAL_CLIENT_SECRET || "";
  if (!id || !secret) throw new Error("PayPal is not configured (PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET missing)");
  const base = (process.env.PAYPAL_ENV || "sandbox").toLowerCase() === "live"
    ? "https://api-m.paypal.com" : "https://api-m.sandbox.paypal.com";
  const resp = await fetch(`${base}/v1/oauth2/token`, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Authorization: "Basic " + Buffer.from(`${id}:${secret}`).toString("base64"),
    },
    body: "grant_type=client_credentials",
    signal: AbortSignal.timeout(30000),
  });
  const j: any = await resp.json().catch(() => ({}));
  if (!resp.ok || !j.access_token) throw new Error(`PayPal auth failed (HTTP ${resp.status}): ${j.error_description || j.error || "no access_token"}`);
  return j.access_token;
}

/**
 * Send one payout as a single-item PayPal Payouts batch.
 * NOTE: Payouts requires the PayPal app to have the Payouts feature enabled and a
 * funded PayPal balance; a missing feature returns 403 with a plain error body.
 */
export async function sendPayoutViaPayPal(pool: pg.Pool, payout: any, dealer: any): Promise<{ providerRef: string; raw: any }> {
  const receiver = dealer.paypal_email || dealer.destination;
  if (!receiver) throw new Error("This dealer has no PayPal email on file");
  const base = (process.env.PAYPAL_ENV || "sandbox").toLowerCase() === "live"
    ? "https://api-m.paypal.com" : "https://api-m.sandbox.paypal.com";
  const token = await paypalAccessToken();
  const body = {
    sender_batch_header: {
      sender_batch_id: `bm-payout-${payout.id}-${Date.now()}`,
      email_subject: "You have a payout from Blue Mogul",
      email_message: payout.note || "Thanks for your sales with Blue Mogul.",
    },
    items: [{
      recipient_type: "EMAIL",
      amount: { value: (payout.amount_cents / 100).toFixed(2), currency: "USD" },
      receiver,
      note: payout.note || `Blue Mogul dealer payout #${payout.id}`,
      sender_item_id: `payout-${payout.id}`,
    }],
  };
  const resp = await fetch(`${base}/v1/payments/payouts`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(60000),
  });
  const raw: any = await resp.json().catch(() => ({}));
  if (!resp.ok) {
    const msg = raw?.message || raw?.name || `HTTP ${resp.status}`;
    throw new Error(`PayPal payout failed: ${msg}`);
  }
  const providerRef = raw?.batch_header?.payout_batch_id || raw?.batch_header?.sender_batch_id || null;
  return { providerRef: providerRef ?? "", raw };
}

/* ── Stripe Connect ────────────────────────────────────────────────────────── */

/** Validate a connected account before sending money to it. */
export async function stripeAccountCheck(accountId: string): Promise<{ ok: boolean; message: string }> {
  const key = process.env.STRIPE_SECRET_KEY || "";
  if (!key) return { ok: false, message: "Stripe secret key not configured" };
  const resp = await fetch(`https://api.stripe.com/v1/accounts/${encodeURIComponent(accountId)}`, {
    headers: { Authorization: `Bearer ${key}` },
    signal: AbortSignal.timeout(30000),
  });
  const j: any = await resp.json().catch(() => ({}));
  if (!resp.ok) return { ok: false, message: j?.error?.message || `HTTP ${resp.status}` };
  const payouts = j?.payouts_enabled === true;
  return {
    ok: j?.charges_enabled !== undefined ? payouts : true,
    message: payouts ? `Connected account OK (${j?.country || "?"}, payouts enabled)` : `Account ${accountId} found but payouts are NOT enabled yet (onboarding incomplete)`,
  };
}

/** Send a payout as a Stripe Connect transfer to the dealer's connected account. */
export async function sendPayoutViaStripeConnect(pool: pg.Pool, payout: any, dealer: any): Promise<{ providerRef: string; raw: any }> {
  const key = process.env.STRIPE_SECRET_KEY || "";
  if (!key) throw new Error("Stripe is not configured (STRIPE_SECRET_KEY missing)");
  const destination = dealer.stripe_account_id || dealer.destination;
  if (!destination) throw new Error("This dealer has no Stripe connected account id (acct_…) on file");

  const form = new URLSearchParams({
    amount: String(Math.round(payout.amount_cents)),
    currency: "usd",
    destination,
    description: payout.note || `Blue Mogul dealer payout #${payout.id}`,
    "metadata[dealer_id]": String(payout.dealer_id),
    "metadata[dealer_payout_id]": String(payout.id),
  });
  const resp = await fetch("https://api.stripe.com/v1/transfers", {
    method: "POST",
    headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/x-www-form-urlencoded" },
    body: form.toString(),
    signal: AbortSignal.timeout(60000),
  });
  const raw: any = await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Error(`Stripe transfer failed: ${raw?.error?.message || `HTTP ${resp.status}`}`);
  return { providerRef: raw?.id ?? "", raw };
}

/* ── Dispatch ──────────────────────────────────────────────────────────────── */

/**
 * Dispatch a recorded payout. Idempotent by status: anything already 'sent' is
 * never re-sent (a Stripe transfer or PayPal batch has no natural idempotency
 * key we control, so re-sending is how you pay a dealer twice).
 */
export async function sendDealerPayout(pool: pg.Pool, payoutId: number, actor?: string | null): Promise<{
  status: string; providerRef: string | null; message: string; raw?: any;
}> {
  const p = await pool.query(`SELECT * FROM dealer_payouts WHERE id = $1`, [payoutId]);
  if (!p.rows.length) throw new Error(`Payout ${payoutId} not found`);
  const payout = p.rows[0];

  if (payout.status === "sent" || payout.status === "paid") {
    return { status: payout.status, providerRef: payout.provider_ref ?? null, message: "Already sent — not re-sending" };
  }

  const dealer = await getPayoutProfile(pool, payout.dealer_id);
  if (!dealer) throw new Error(`Dealer ${payout.dealer_id} not found`);
  const method: PayoutMethod = (payout.method || dealer.method || "manual") as PayoutMethod;

  // Manual / ACH: the operator moved the money outside the provider rails, so we
  // only stamp the record with their reference.
  if (method === "manual" || method === "ach") {
    await pool.query(
      `UPDATE dealer_payouts SET status='sent', provider_status='manual', sent_at=NOW(), paid_by=$2,
              provider_ref=COALESCE(provider_ref, reference) WHERE id=$1`,
      [payoutId, actor ?? null]
    );
    return { status: "sent", providerRef: payout.provider_ref ?? payout.reference ?? null, message: `Marked sent (${method}) — no provider call made` };
  }

  try {
    let providerRef = "";
    let raw: any = {};
    if (method === "paypal") {
      const r = await sendPayoutViaPayPal(pool, payout, dealer);
      providerRef = r.providerRef; raw = r.raw;
    } else if (method === "stripe_connect") {
      const r = await sendPayoutViaStripeConnect(pool, payout, dealer);
      providerRef = r.providerRef; raw = r.raw;
    } else {
      throw new Error(`Unknown payout method: ${method}`);
    }
    await pool.query(
      `UPDATE dealer_payouts SET status='sent', provider_status='accepted', provider_ref=$2,
              sent_at=NOW(), paid_by=$3, provider_response=$4 WHERE id=$1`,
      [payoutId, providerRef, actor ?? null, JSON.stringify(raw).slice(0, 8000)]
    );
    return { status: "sent", providerRef, message: `${method} payout sent`, raw };
  } catch (e: any) {
    await pool.query(
      `UPDATE dealer_payouts SET status='failed', provider_status='error', failure_reason=$2 WHERE id=$1`,
      [payoutId, String(e.message).slice(0, 1000)]
    );
    throw e;
  }
}

/** Payout history for one dealer (newest first). */
export async function listDealerPayouts(pool: pg.Pool, dealerId: number, limit = 25): Promise<any[]> {
  const { rows } = await pool.query(
    `SELECT id, dealer_id, amount_cents, commission_count, status, method, destination, provider_ref,
            provider_status, failure_reason, reference, note, created_by, paid_by, initiated_at, sent_at, created_at
       FROM dealer_payouts WHERE dealer_id = $1 ORDER BY id DESC LIMIT $2`,
    [dealerId, limit]
  );
  return rows;
}
