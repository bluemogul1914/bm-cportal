import pg from "pg";
import { sendEmail } from "./email";

// ── Types ─────────────────────────────────────────────────────────────────

export interface BalanceCheckResult {
  clientId: number;
  clientName: string;
  clientEmail: string;
  balance: string;
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