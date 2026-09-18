import pg from "pg";
import { sendEmail } from "./email";
import { maybeProvisionHwService } from "./hostwinds-api";

// ── Types ─────────────────────────────────────────────────────────────────

export interface BalanceCheckResult {
  clientId: number;
  clientName: string;
  clientEmail: string;
  /** Numeric dollars — the scheduler parses `clients.credit_balance` with parseFloat. */
  balance: number;
  status: "ok" | "low_balance" | "suspended";
  actions: string[];
}

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Suspend a client: set clients.status='suspended' AND deactivate the linked
 * portal login (users.status='inactive') so the suspend actually blocks access.
 * login-handler.php gates on users.status, so clients.status alone is cosmetic.
 */
async function suspendClient(pool: pg.Pool, clientId: number): Promise<void> {
  await pool.query(
    `UPDATE clients SET status = 'suspended', updated_at = NOW() WHERE id = $1`,
    [clientId]
  );
  await pool.query(
    `UPDATE users SET status = 'inactive'
     WHERE id = (SELECT user_id FROM clients WHERE id = $1)
       AND COALESCE(is_admin, false) = false`,
    [clientId]
  );
}

/**
 * Reactivate a client: set clients.status='active' AND restore the linked
 * portal login (users.status='active').
 */
async function reactivateClient(pool: pg.Pool, clientId: number): Promise<void> {
  await pool.query(
    `UPDATE clients SET status = 'active', updated_at = NOW() WHERE id = $1`,
    [clientId]
  );
  await pool.query(
    `UPDATE users SET status = 'active'
     WHERE id = (SELECT user_id FROM clients WHERE id = $1)
       AND COALESCE(is_admin, false) = false`,
    [clientId]
  );
}

/**
 * Record a transaction in the ledger and update the client's credit_balance.
 * Returns { oldBalance, newBalance }.
 */
export async function recordTransaction(
  pool: pg.Pool,
  params: {
    clientId: number;
    type: "top_up" | "charge" | "refund" | "adjustment";
    amount: number;
    description: string;
    invoiceId?: number | null;
    stripePaymentId?: string | null;
    stripeSessionId?: string | null;
    metadata?: Record<string, unknown>;
  }
): Promise<{ balanceBefore: number; balanceAfter: number }> {
  // Get current balance
  const clientResult = await pool.query(
    `SELECT COALESCE(credit_balance, 0) AS credit_balance FROM clients WHERE id = $1`,
    [params.clientId]
  );
  if (!clientResult.rows.length) throw new Error(`Client ${params.clientId} not found`);

  const balanceBefore = parseFloat(clientResult.rows[0].credit_balance);

  // Compute new balance (top-ups/refunds add, charges/subtract)
  let balanceAfter: number;
  switch (params.type) {
    case "top_up":
    case "refund":
      balanceAfter = balanceBefore + params.amount;
      break;
    case "charge":
      balanceAfter = Math.max(0, balanceBefore - params.amount);
      break;
    case "adjustment":
      balanceAfter = balanceBefore + params.amount;
      break;
    default:
      throw new Error(`Unknown transaction type: ${params.type}`);
  }

  // Update client balance + insert ledger entry atomically on ONE connection.
  // (Previously used pool.query("BEGIN")/COMMIT, which grabs a different
  //  connection per call — the "transaction" scoped nothing.)
  const conn = await pool.connect();
  try {
    await conn.query("BEGIN");

    // Update client balance
    await conn.query(
      `UPDATE clients SET credit_balance = $1, updated_at = NOW() WHERE id = $2`,
      [String(balanceAfter), params.clientId]
    );

    // Insert ledger entry
    await conn.query(
      `INSERT INTO transaction_ledger (client_id, type, amount, balance_before, balance_after, description, invoice_id, stripe_payment_id, stripe_session_id, metadata)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
      [
        params.clientId,
        params.type,
        String(params.amount),
        String(balanceBefore),
        String(balanceAfter),
        params.description,
        params.invoiceId ?? null,
        params.stripePaymentId ?? null,
        params.stripeSessionId ?? null,
        params.metadata ? JSON.stringify(params.metadata) : "{}",
      ]
    );

    await conn.query("COMMIT");
    return { balanceBefore, balanceAfter };
  } catch (err) {
    await conn.query("ROLLBACK");
    throw err;
  } finally {
    conn.release();
  }
}

// ── Monthly auto-charge ──────────────────────────────────────────────────────

export interface MonthlyChargeResult { clientId: number; clientName: string; amount: number; }

/**
 * Charge active clients' prepaid wallets for their active service subscriptions.
 *
 * Selects clients with >=1 active subscription and positive summed MRR.
 * A charge is due when:
 *   - last_charged_at IS NULL  (first active service → charge now)
 *   - OR >=30 days since last_charged_at
 *
 * The existing recordTransaction() floors the balance at $0, so a client who
 * cannot cover the month naturally hits $0. The subsequent checkAllBalances()
 * call (which callers run after this) suspends them. This function does NOT
 * add suspend logic itself.
 *
 * The 30-day last_charged_at gate is the idempotency guard. Clients with NO
 * active subscriptions are never charged.
 */
export async function chargeMonthlySubscriptions(pool: pg.Pool): Promise<MonthlyChargeResult[]> {
  const results: MonthlyChargeResult[] = [];

  try {
    const { rows: clients } = await pool.query(`
      SELECT c.id, c.name, c.last_charged_at,
             COALESCE(SUM(s.mrr), 0)::numeric AS monthly_total
      FROM clients c
      JOIN subscriptions s ON s.client_id = c.id AND s.status = 'active'
      GROUP BY c.id
      HAVING COALESCE(SUM(s.mrr), 0) > 0
      ORDER BY c.id
    `);

    for (const row of clients) {
      try {
        const total = parseFloat(row.monthly_total);
        if (total <= 0) continue;

        const last = row.last_charged_at ? new Date(row.last_charged_at) : null;
        const due = last === null
          || (Date.now() - last.getTime()) >= 30 * 24 * 60 * 60 * 1000;

        if (!due) continue;

        await recordTransaction(pool, {
          clientId: row.id,
          type: "charge",
          amount: total,
          description: "Monthly prepaid service charge",
          metadata: { method: "monthly_charge" },
        });

        await pool.query(
          `UPDATE clients SET last_charged_at = NOW(), updated_at = NOW() WHERE id = $1`,
          [row.id]
        );

        results.push({ clientId: row.id, clientName: row.name, amount: total });
        console.log(`[monthly-charge] Client ${row.id} (${row.name}): $${total.toFixed(2)}`);
      } catch (e: any) {
        console.error(`[monthly-charge] Error charging client ${row.id} (${row.name}):`, e.message);
      }
    }
  } catch (e: any) {
    console.error("[monthly-charge] Error querying clients:", e.message);
  }

  return results;
}

// ── Pending activation on balance (wallet settle) ──────────────────────────

export interface PendingActivationResult { subscriptionId: number; clientId: number; productName: string; amount: number; }

/**
 * Activate pending subscriptions whose linked unpaid invoice is now covered
 * by the client's prepaid balance (the "wallet settles" half of the
 * order → invoice → paid-before-active gate).
 *
 * Services are added as 'pending' with an 'unpaid' invoice. When the balance
 * >= invoice amount, the balance is charged and both the invoice and the
 * subscription flip to paid/active. Subscriptions whose invoice is instead
 * paid via Stripe are handled by processPendingServiceInvoices().
 */
export async function activatePaidPendingSubscriptions(pool: pg.Pool): Promise<PendingActivationResult[]> {
  const results: PendingActivationResult[] = [];

  try {
    const { rows: candidates } = await pool.query(`
      SELECT s.id AS subscription_id, s.client_id, s.product_id, i.id AS invoice_id,
             i.amount::numeric AS inv_amount, p.name AS product_name,
             COALESCE(c.credit_balance, 0)::numeric AS balance
      FROM subscriptions s
      JOIN clients c    ON c.id = s.client_id
      JOIN products p   ON p.id = s.product_id
      JOIN invoices i   ON i.subscription_id = s.id
      WHERE s.status = 'pending' AND i.status = 'unpaid'
    `);

    for (const row of candidates) {
      try {
        const invAmount = parseFloat(row.inv_amount);
        const balance   = parseFloat(row.balance);
        const subId     = row.subscription_id;
        const clientId  = row.client_id;
        const invoiceId = row.invoice_id;
        const prodName  = row.product_name;

        if (balance < invAmount) continue;

        // Charge the client's prepaid balance for the first month
        await recordTransaction(pool, {
          clientId,
          type: "charge",
          amount: invAmount,
          description: `Service activation - ${prodName}`,
          metadata: { method: "pending_activation", subscription_id: subId, invoice_id: invoiceId },
        });

        // Mark invoice as paid
        await pool.query(
          `UPDATE invoices SET status = 'paid', paid_date = CURRENT_DATE, paid_at = NOW() WHERE id = $1`,
          [invoiceId]
        );

        // Activate the subscription
        await pool.query(
          `UPDATE subscriptions SET status = 'active', updated_at = NOW() WHERE id = $1`,
          [subId]
        );

        // Set last_charged_at so chargeMonthlySubscriptions doesn't
        // double-charge on the next cycle (it treats NULL as "due now").
        await pool.query(
          `UPDATE clients SET last_charged_at = NOW(), updated_at = NOW() WHERE id = $1`,
          [clientId]
        );

        results.push({ subscriptionId: subId, clientId, productName: prodName, amount: invAmount });
        console.log(`[pending-activation] Subscription ${subId} (${prodName}) activated for client ${clientId}: $${invAmount.toFixed(2)} charged from balance`);

        // Phase B: a product mapped to Hostwinds provisions its server the moment
        // the subscription is paid for. Never throws; never blocks activation.
        try {
          const prov = await maybeProvisionHwService(pool, {
            clientId, subscriptionId: subId, portalProductId: row.product_id,
          });
          if (prov.status !== "skipped") {
            console.log(`[pending-activation] Hostwinds ${prov.status}: ${prov.message}`);
          }
        } catch (e: any) {
          console.error(`[pending-activation] Hostwinds provisioning error: ${e.message}`);
        }
      } catch (e: any) {
        console.error(`[pending-activation] Error activating subscription ${row.subscription_id} for client ${row.client_id}:`, e.message);
      }
    }
  } catch (e: any) {
    console.error("[pending-activation] Error querying pending subscriptions:", e.message);
  }

  return results;
}

// ── Balance check ────────────────────────────────────────────────────────────

/**
 * Run the balance check cycle for all active clients.
 *
 * For each client:
 * 1. Skip if status != 'active'
 * 2. If balance <= $0 → suspend (set status='suspended'), send final notice
 * 3. If balance <= low_balance_threshold and not yet warned → send low-balance reminder
 * 4. If balance > low_balance_threshold → clear the low_balance_warned flag
 */
export async function checkAllBalances(pool: pg.Pool): Promise<BalanceCheckResult[]> {
  const results: BalanceCheckResult[] = [];

  try {
    const { rows: clients } = await pool.query(
      `SELECT id, name, email, COALESCE(credit_balance, 0) AS credit_balance,
              status, low_balance_warned, COALESCE(low_balance_threshold, 10.00) AS low_balance_threshold
       FROM clients
       ORDER BY id`
    );

    for (const client of clients) {
      const balance = parseFloat(client.credit_balance);
      const threshold = parseFloat(client.low_balance_threshold);
      const result: BalanceCheckResult = {
        clientId: client.id,
        clientName: client.name,
        clientEmail: client.email,
        balance,
        status: "ok",
        actions: [],
      };

      // Skip non-active clients entirely (suspended or archived stay suspended)
      if (client.status !== "active" && client.status !== "suspended") {
        result.status = "ok";
        results.push(result);
        continue;
      }

      // ── SUSPEND AT $0 ──────────────────────────────────────────────
      if (balance <= 0) {
        if (client.status !== "suspended") {
          // Suspend the client (blocks login via users.status='inactive')
          await suspendClient(pool, client.id);
          result.actions.push("suspended_at_zero_balance");

          // Send final notice email
          const emailSent = await sendEmail({
            to: client.email,
            subject: "Service Suspended — Balance Depleted",
            text: `Hi ${client.name},\n\nYour prepaid account balance has reached $0.00 and your services have been suspended.\n\nTo restore service, please make a top-up payment through your client portal. Once received, your account will be reactivated automatically.\n\nThank you,\nBlue Mogul Billing`,
          });
          if (emailSent) {
            result.actions.push("final_notice_sent");
          } else {
            console.log(`[balance-check] Final notice FAILED to send for ${client.name} (${client.email})`);
          }
        }
        result.status = "suspended";
        results.push(result);
        continue;
      }

      // ── LOW-BALANCE REMINDER ───────────────────────────────────────
      if (balance <= threshold && !client.low_balance_warned) {
        await pool.query(
          `UPDATE clients SET low_balance_warned = true, updated_at = NOW() WHERE id = $1`,
          [client.id]
        );
        result.actions.push("low_balance_warned");

        const emailSent = await sendEmail({
          to: client.email,
          subject: `Low Balance Reminder — $${balance.toFixed(2)} remaining`,
          text: `Hi ${client.name},\n\nYour prepaid account balance is running low at $${balance.toFixed(2)}.\n\nPlease top up your account to avoid service interruption.\n\nTop up here: https://portal.bluemogul.us/portal/billing.php\n\nThank you,\nBlue Mogul Billing`,
        });
        if (emailSent) {
          result.actions.push("reminder_email_sent");
        } else {
          console.log(`[balance-scheduler] Reminder email FAILED to send for ${client.name} (${client.email})`);
        }

        result.status = "low_balance";
      }

      // ── BALANCE RESTORED → CLEAR low_balance_warned ────────────────
      if (balance > threshold && client.low_balance_warned) {
        await pool.query(
          `UPDATE clients SET low_balance_warned = false, updated_at = NOW() WHERE id = $1`,
          [client.id]
        );
        result.actions.push("low_balance_warning_cleared");
      }

      // ── CLEAR SUSPEND if balance > 0 and status = suspended ───────
      if (balance > 0 && client.status === "suspended") {
        await reactivateClient(pool, client.id);
        result.actions.push("suspension_cleared_topup");
      }

      results.push(result);
    }
  } catch (e: any) {
    console.error("[balance-scheduler] Error checking balances:", e.message);
  }

  return results;
}

/**
 * Poll Stripe for completed checkout sessions with top_up metadata
 * that haven't been processed yet. Returns count processed.
 *
 * This is a fallback/recovery mechanism for webhook delivery failures.
 * The primary path is webhook-driven; this polls every cycle to catch
 * any missed sessions.
 */
export async function processPendingTopUps(pool: pg.Pool): Promise<number> {
  let processed = 0;

  try {
    const { getUncachableStripeClient } = await import("./stripeClient");
    const stripe = await getUncachableStripeClient();

    // Find sessions with our metadata that we haven't processed
    const { data: sessions } = await stripe.checkout.sessions.list({
      limit: 100,
      status: "complete",
      expand: ["data.payment_intent"],
    });

    for (const session of sessions) {
      const clientIdStr = session.metadata?.client_id;
      const isTopUp = session.metadata?.top_up === "true";
      if (!clientIdStr || !isTopUp || session.payment_status !== "paid") continue;

      const clientId = parseInt(clientIdStr, 10);
      if (isNaN(clientId)) continue;

      // Already processed?
      const existing = await pool.query(
        `SELECT id FROM transaction_ledger WHERE stripe_session_id = $1 LIMIT 1`,
        [session.id]
      );
      if (existing.rows.length > 0) continue;

      // Process the top-up
      const amountPaid = (session.amount_total ?? 0) / 100;
      const pi = session.payment_intent as any;
      const stripePaymentId = typeof pi === "string" ? pi : (pi?.id ?? null);
      const stripeSessionId = session.id;

      await recordTransaction(pool, {
        clientId,
        type: "top_up",
        amount: amountPaid,
        description: session.metadata?.description || "Prepaid account top-up (recovery)",
        stripePaymentId,
        stripeSessionId,
        metadata: {
          session_id: stripeSessionId,
          amount_cents: session.amount_total,
          currency: session.currency,
          recovered: true,
        },
      });

      // Generate receipt number
      const seqResult = await pool.query(
        `INSERT INTO invoice_sequences (client_id, seq)
         VALUES ($1, 1)
         ON CONFLICT (client_id) DO UPDATE SET seq = invoice_sequences.seq + 1
         RETURNING seq`,
        [clientId]
      );
      const seq = (seqResult.rows[0] as any)?.seq ?? 1;
      const datePart = new Date().toISOString().slice(0, 10).replace(/-/g, "");
      const invoiceNumber = `INV-${clientId}-${datePart}-${String(seq).padStart(4, "0")}`;

      // Create invoice record
      await pool.query(
        `INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, paid_date, stripe_payment_id, items, notes, created_at)
         VALUES ($1, $2, $3, '0.00', $3, 'paid', CURRENT_DATE, $4, $5, $6, NOW())
         ON CONFLICT DO NOTHING`,
        [
          clientId,
          invoiceNumber,
          String(amountPaid),
          stripePaymentId,
          JSON.stringify([{ name: "Prepaid Account Top-Up", description: session.metadata?.description || "Prepaid account top-up", amount: String(amountPaid) }]),
          `Top-up via Stripe (recovered: ${stripeSessionId})`,
        ]
      );

      // Clear suspension (blocks login via users.status='inactive')
      await reactivateClient(pool, clientId);

      processed++;
      console.log(`[top-up-recovery] Client ${clientId}: $${amountPaid} top-up (session ${stripeSessionId})`);
    }
  } catch (e: any) {
    console.error("[top-up-recovery] Error:", e.message);
  }

  return processed;
}

/**
 * Poll Stripe for completed checkout sessions with service_invoice metadata
 * that haven't been processed yet. Returns count activated.
 *
 * Mirror of processPendingTopUps for the invoice-gated activation flow.
 * When a service invoice's Stripe Checkout is completed, this marks the
 * invoice paid, activates the subscription, and seeds the 30-day wallet
 * cycle so month-1 is not double-charged.
 */
export async function processPendingServiceInvoices(pool: pg.Pool): Promise<number> {
  let activated = 0;

  try {
    const { getUncachableStripeClient } = await import("./stripeClient");
    const stripe = await getUncachableStripeClient();

    const { data: sessions } = await stripe.checkout.sessions.list({
      limit: 100,
      status: "complete",
      expand: ["data.payment_intent"],
    });

    for (const session of sessions) {
      const m = session.metadata || {};
      if (m.type !== "service_invoice") continue;
      if (session.payment_status !== "paid") continue;

      const invoiceId = parseInt(m.invoice_id, 10);
      const subId = parseInt(m.subscription_id, 10);
      if (!invoiceId || !subId) continue;

      // Already processed?
      const already = await pool.query(
        `SELECT id FROM invoices WHERE id=$1 AND status='paid'`, [invoiceId]
      );
      if (already.rows.length) continue;

      const pi = session.payment_intent as any;
      const piId = typeof pi === "string" ? pi : (pi?.id ?? null);

      // Mark invoice paid (invoices table has no updated_at or stripe_session_id column)
      await pool.query(
        `UPDATE invoices SET status='paid', paid_date=CURRENT_DATE, stripe_payment_id=$2 WHERE id=$1`,
        [invoiceId, piId]
      );

      // Activate subscription
      await pool.query(
        `UPDATE subscriptions SET status='active', updated_at=NOW() WHERE id=$1`, [subId]
      );

      // Seed the client's 30-day recurring wallet cycle so month-1 is NOT double-charged
      await pool.query(
        `UPDATE clients SET last_charged_at=NOW(), updated_at=NOW() WHERE id=$1`,
        [parseInt(m.client_id, 10) || 0]
      );

      activated++;
      console.log(`[service-invoice] Activated subscription ${subId} via invoice ${invoiceId} (session ${session.id})`);

      // Phase B: the card-payment route provisions too — a product mapped to
      // Hostwinds gets its server the moment Stripe confirms the invoice.
      try {
        const srow = await pool.query(`SELECT client_id, product_id FROM subscriptions WHERE id = $1`, [subId]);
        if (srow.rows.length) {
          const prov = await maybeProvisionHwService(pool, {
            clientId: srow.rows[0].client_id,
            subscriptionId: subId,
            portalProductId: srow.rows[0].product_id,
          });
          if (prov.status !== "skipped") console.log(`[service-invoice] Hostwinds ${prov.status}: ${prov.message}`);
        }
      } catch (e: any) {
        console.error(`[service-invoice] Hostwinds provisioning error: ${e.message}`);
      }
    }
  } catch (e: any) {
    console.error("[service-invoice] Error:", e.message);
  }

  return activated;
}