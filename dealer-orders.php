<?php
$page_title = 'Submit Order';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$success = $error = '';

/**
 * Find or create the PORTAL CLIENT for a dealer's customer and attribute it to the
 * dealer. A dealer customer must exist as a real client (Clients/CRM) so the
 * relationship survives the dealer leaving the program.
 */
function bm_ensure_dealer_client(PDO $pdo, array $dealer, array $c): ?int {
    $email = strtolower(trim((string)($c['email'] ?? '')));
    try {
        if ($email !== '') {
            $q = $pdo->prepare("SELECT id FROM clients WHERE LOWER(email) = ? LIMIT 1");
            $q->execute([$email]);
            $id = (int)$q->fetchColumn();
            if ($id) {
                // Attribute only if unattributed (never steal another dealer's client),
                // and top up contact details we now know.
                $pdo->prepare("UPDATE clients SET dealer_id = COALESCE(dealer_id, ?),
                                  phone = COALESCE(NULLIF(?, ''), phone),
                                  address = COALESCE(NULLIF(?, ''), address),
                                  updated_at = NOW() WHERE id = ?")
                    ->execute([(int)$dealer['id'], (string)($c['phone'] ?? ''), (string)($c['address'] ?? ''), $id]);
                return $id;
            }
        }
        $ins = $pdo->prepare("INSERT INTO clients (name, email, phone, address, status, dealer_id, created_at, updated_at)
                              VALUES (?,?,?,?, 'active', ?, NOW(), NOW()) RETURNING id");
        $ins->execute([($c['name'] ?: 'Client'), ($email ?: null), ($c['phone'] ?: null), ($c['address'] ?: null), (int)$dealer['id']]);
        return (int)$ins->fetchColumn();
    } catch (Throwable $e) { return null; }
}


// Active logins on this dealer's team (tenant members) — used for sales attribution.
$team = [];
try {
    $tq = $pdo->prepare("SELECT du.user_id, du.role, u.name
                           FROM dealer_users du JOIN users u ON u.id = du.user_id
                          WHERE du.dealer_id = ? AND COALESCE(du.status,'active') = 'active'
                          ORDER BY u.name");
    $tq->execute([$dealer['id']]); $team = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $team = []; }

// Open leads owned by THIS dealer — the New Order form's picker fills from these.
$lead_pick = [];
try {
    $lq = $pdo->prepare("SELECT id, COALESCE(name, full_name) AS lead_name, email, phone, street, city, zip_code,
                                COALESCE(company, company_name) AS lead_company
                           FROM leads
                          WHERE dealer_id = ? AND COALESCE(status,'new') NOT IN ('won','lost')
                          ORDER BY created_at DESC LIMIT 200");
    $lq->execute([$dealer['id']]); $lead_pick = $lq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $lead_pick = []; }

$product_labels = [
    'frontier_fiber'  => 'Frontier Fiber',
    'xfinity_prepaid' => 'Xfinity Prepaid Internet',
    'verizon_prepaid' => 'Verizon Prepaid Wireless',
    'black_wireless'  => 'Black Wireless',
    'travelsim'       => 'TravelSim / eSIM',
    'sling_tv'        => 'Sling TV',
];

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $client_name  = trim($_POST['client_name']    ?? '');
    $client_email = trim($_POST['client_email']   ?? '');
    $client_phone = trim($_POST['client_phone']   ?? '');
    $service_addr = trim($_POST['service_address'] ?? '');
    $product_line = trim($_POST['product_line'] ?? '' ?? ''   ?? '');
    $plan_name    = trim($_POST['plan_name']      ?? '');
    $plan_price   = (float)($_POST['plan_price']  ?? 0);
    $dealer_notes = trim($_POST['dealer_notes']   ?? '');

    if (!$client_name || !$product_line) {
        $error = 'Client name and product are required.';
    } else {
        // Spiff lookup
        $tier = $dealer['tier'];
        $spiff_row = $pdo->prepare(
            "SELECT spiff_cents FROM spiff_schedule
             WHERE product_line=? AND tier=? AND active=TRUE
             ORDER BY effective_from DESC LIMIT 1"
        );
        $spiff_row->execute([$product_line, $tier]);
        $spiff_cents = (int)($spiff_row->fetchColumn() ?? 0);

        // Generate order ref
        $order_ref = 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1,9999),4,'0',STR_PAD_LEFT);
        $plan_price_cents = $plan_price > 0 ? (int)round($plan_price * 100) : null;

        // Who on the dealer's team made the sale. Either a team login (id) or a
        // typed name — a dealer with no logins yet can still attribute the sale.
        $sales_user_id = (int)($_POST['sales_user_id'] ?? 0) ?: null;
        $sales_name_in = trim((string)($_POST['sales_name'] ?? ''));
        $sales_name = null;
        if ($sales_user_id) {
            foreach ($team as $tm) { if ((int)$tm['user_id'] === $sales_user_id) { $sales_name = $tm['name']; break; } }
            if ($sales_name === null) $sales_user_id = null; // id not on this dealer's team — ignore it
        }
        if ($sales_name === null && $sales_name_in !== '') $sales_name = mb_substr($sales_name_in, 0, 120);

        // Optional: the order came from one of this dealer's leads. Validate against the
        // dealer's own open leads, then close the loop on conversion below.
        $lead_id_in = (int)($_POST['lead_id'] ?? 0);
        $lead_id = null;
        if ($lead_id_in) {
            foreach ($lead_pick as $lp) { if ((int)$lp['id'] === $lead_id_in) { $lead_id = $lead_id_in; break; } }
        }
        $product_label    = $product_labels[$product_line] ?? $product_line;

        // ── 1. Insert dealer order (RETURNING id) ─────────────
        $ins = $pdo->prepare(
            "INSERT INTO dealer_orders
               (dealer_id, order_ref, client_name, client_email, client_phone,
                service_address, product_line, plan_name, plan_price_cents,
                spiff_cents, tier_at_order, dealer_notes, sales_user_id, sales_name, lead_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             RETURNING id"
        );
        $ins->execute([
            $dealer['id'], $order_ref, $client_name,
            $client_email ?: null, $client_phone ?: null,
            $service_addr ?: null, $product_line,
            $plan_name ?: null,
            $plan_price_cents,
            $spiff_cents, $tier, $dealer_notes ?: null,
            $sales_user_id, $sales_name, $lead_id,
        ]);
        $order_id = (int)$ins->fetchColumn();

        // ── Promote the dealer's customer to a real portal client ──────────
        // Attributed to this dealer via clients.dealer_id, and linked back on the
        // order and on the dealer's own customer record.
        $client_id = bm_ensure_dealer_client($pdo, $dealer, [
            'name'    => $client_name,
            'email'   => $client_email,
            'phone'   => $client_phone,
            'address' => $service_addr,
        ]);
        if ($client_id) {
            try {
                $pdo->prepare("UPDATE dealer_orders SET client_id = ? WHERE id = ?")->execute([$client_id, $order_id]);
                // Keep/refresh the dealer's own customer row and link it to the client.
                $dup = $pdo->prepare("SELECT id FROM dealer_customers WHERE dealer_id = ? AND LOWER(email) = LOWER(?) LIMIT 1");
                $dup->execute([$dealer['id'], (string)$client_email]);
                $cust_id = (int)$dup->fetchColumn();
                if ($cust_id) {
                    $pdo->prepare("UPDATE dealer_customers SET client_id = ?, name = COALESCE(NULLIF(?, ''), name),
                                      phone = COALESCE(NULLIF(?, ''), phone), address = COALESCE(NULLIF(?, ''), address),
                                      updated_at = NOW() WHERE id = ?")
                        ->execute([$client_id, $client_name, $client_phone, $service_addr, $cust_id]);
                } else {
                    $pdo->prepare("INSERT INTO dealer_customers (dealer_id, type, name, email, phone, address, client_id, created_at, updated_at)
                                   VALUES (?, 'client', ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$dealer['id'], $client_name, ($client_email ?: null), ($client_phone ?: null), ($service_addr ?: null), $client_id]);
                }
            } catch (Throwable $e) { /* linking must never break the order */ }
        }

        // Lead -> won, linked to the order that came out of it. Scoped by dealer_id.
        if ($lead_id) {
            try {
                $pdo->prepare("UPDATE leads SET status = 'won', pipeline_status = 'activation_won',
                                                 converted_order_id = ?, last_contacted = NOW(), updated_at = NOW()
                                WHERE id = ? AND dealer_id = ?")->execute([$order_id, $lead_id, $dealer['id']]);
                $pdo->prepare("INSERT INTO lead_activities (lead_id, action, actor) VALUES (?,?,?)")
                    ->execute([$lead_id, 'Converted to dealer order ' . $order_ref, $dealer['full_name']]);
            } catch (Throwable $e) { /* conversion bookkeeping must never break the order */ }
        }

        $ticket_id  = null;
        $invoice_id = null;

        // ── 2. Create ticket ──────────────────────────────────
        try {
            $desc_parts = [
                "Order Ref: {$order_ref}",
                "Dealer: {$dealer['full_name']} ({$dealer['dealer_code']})",
                "Product: {$product_label}" . ($plan_name ? " — {$plan_name}" : ''),
                "Client: {$client_name}",
            ];
            if ($client_email) $desc_parts[] = "Email: {$client_email}";
            if ($client_phone) $desc_parts[] = "Phone: {$client_phone}";
            if ($service_addr) $desc_parts[] = "Service address: {$service_addr}";
            if ($plan_price)   $desc_parts[] = "Monthly price: \$" . number_format($plan_price, 2);
            if ($dealer_notes) $desc_parts[] = "Dealer notes: {$dealer_notes}";
            $desc_parts[] = "Commission: \$" . dollars($spiff_cents) . " (pending activation)";

            $tk = $pdo->prepare(
                "INSERT INTO tickets
                   (subject, description, status, priority, ticket_group, source, created_at, updated_at)
                 VALUES (?,?,'open','medium','dealer_order','dealer_portal',NOW(),NOW())
                 RETURNING id"
            );
            $tk->execute([
                "Dealer Order {$order_ref} — {$client_name}",
                implode("\n", $desc_parts),
            ]);
            $ticket_id = (int)$tk->fetchColumn();
        } catch (Exception $e) {}

        // ── 3. Create invoice ─────────────────────────────────
        try {
            if ($plan_price_cents && $plan_price_cents > 0) {
                $inv_number  = 'INV-' . date('Ymd') . '-' . str_pad(random_int(1,9999),4,'0',STR_PAD_LEFT);
                $inv_amount  = round($plan_price_cents / 100, 2);
                $inv_due     = date('Y-m-d', strtotime('+7 days'));
                $inv_items   = json_encode([[
                    'description' => $product_label . ($plan_name ? " — {$plan_name}" : ''),
                    'qty'         => 1,
                    'unit_price'  => $inv_amount,
                    'tax_rate'    => 0,
                    'amount'      => $inv_amount,
                    'tax_amount'  => 0,
                ]]);
                $inv_notes = "Dealer order {$order_ref} submitted by {$dealer['full_name']}. "
                           . "Client: {$client_name}"
                           . ($client_email ? " <{$client_email}>" : '')
                           . ($service_addr ? ". Address: {$service_addr}" : '');

                $iv = $pdo->prepare(
                    "INSERT INTO invoices
                       (invoice_number, amount, tax, total, status, due_date, notes, items, created_at)
                     VALUES (?,?,0,?,?,?,?,?,NOW())
                     RETURNING id"
                );
                $iv->execute([
                    $inv_number,
                    $inv_amount,
                    $inv_amount,
                    'draft',
                    $inv_due,
                    $inv_notes,
                    $inv_items,
                ]);
                $invoice_id = (int)$iv->fetchColumn();
            }
        } catch (Exception $e) {}

        // ── 4. Link ticket + invoice back to order ────────────
        if ($ticket_id || $invoice_id) {
            try {
                $pdo->prepare(
                    "UPDATE dealer_orders SET ticket_id=?, invoice_id=? WHERE id=?"
                )->execute([$ticket_id, $invoice_id, $order_id]);
            } catch (Exception $e) {}
        }

        // ── 5. Log ────────────────────────────────────────────
        try {
            $pdo->prepare(
                "INSERT INTO agent_logs (agent_key,action,status,message,metadata)
                 VALUES ('DEALER_MODULE','order_submitted','success',?,?)"
            )->execute([
                "Order {$order_ref} by dealer " . $dealer['id'],
                json_encode([
                    'order_ref'  => $order_ref,
                    'product'    => $product_line,
                    'ticket_id'  => $ticket_id,
                    'invoice_id' => $invoice_id,
                ])
            ]);
        } catch (Exception $e) {}

        // Build success message
        $msg = "Order <strong>{$order_ref}</strong> submitted! "
             . "Your commission of <strong>\$" . dollars($spiff_cents) . "</strong> "
             . "will be released on confirmed activation.";
        if ($ticket_id)  $msg .= " Ticket #<strong>{$ticket_id}</strong> created.";
        if ($invoice_id) $msg .= " Invoice <strong>" . (isset($inv_number) ? $inv_number : "#{$invoice_id}") . "</strong> drafted.";

        portal_redirect('/portal/dealer-orders.php?success=' . urlencode($msg));
        exit;
    }
}

if (isset($_GET['success'])) $success = $_GET['success'];

// ── Load spiff schedule for JS preview ───────────────────────
$spiffs_raw = $pdo->prepare(
    "SELECT product_line, tier, spiff_cents FROM spiff_schedule
     WHERE active=TRUE ORDER BY product_line, tier"
);
$spiffs_raw->execute();
$spiffs_map = [];
foreach ($spiffs_raw->fetchAll() as $r) {
    $spiffs_map[$r['product_line']][$r['tier']] = $r['spiff_cents'];
}
$dealer_tier = $dealer['tier'];

// ── Order history ─────────────────────────────────────────────
$history = $pdo->prepare(
    "SELECT o.order_ref, o.client_name, o.product_line, o.plan_name,
            o.status, o.created_at, o.ticket_id, o.invoice_id,
            c.amount_cents, c.status AS comm_status
     FROM dealer_orders o
     LEFT JOIN commissions c ON c.order_id=o.id
     WHERE o.dealer_id=?
     ORDER BY o.created_at DESC LIMIT 50"
);
$history->execute([$dealer['id']]);
$all_orders = $history->fetchAll();
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Submit order</div>
      <div class="topbar-sub">All services are prepaid — client pays before activation</div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier']) ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="two-col" style="align-items:start;">

      <!-- ORDER FORM -->
      <div class="card">
        <div class="card-title" style="margin-bottom:16px;">New order</div>

        <form method="POST">
        <?= csrf_field() ?>
          <?php
          $pre_lead = (int)($_POST['lead_id'] ?? ($_GET['lead_id'] ?? 0));
          ?>
          <?php if ($lead_pick): ?>
          <div class="form-group" style="background:var(--blue-bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:14px;">
            <label class="form-label" style="margin-bottom:6px;">Start from a lead <span style="color:var(--text-lt);font-weight:400;">(fills the client fields below)</span></label>
            <select name="lead_id" id="lead-picker" class="form-control" onchange="bmFillFromLead(this)" data-testid="select-lead-picker">
              <option value="">— no lead / new customer —</option>
              <?php foreach ($lead_pick as $lp): ?>
              <option value="<?= (int)$lp['id'] ?>"
                      data-name="<?= htmlspecialchars((string)($lp['lead_name'] ?? '')) ?>"
                      data-email="<?= htmlspecialchars((string)($lp['email'] ?? '')) ?>"
                      data-phone="<?= htmlspecialchars((string)($lp['phone'] ?? '')) ?>"
                      data-address="<?= htmlspecialchars(trim(($lp['street'] ?? '') . ' ' . ($lp['city'] ?? '') . ' ' . ($lp['zip_code'] ?? ''))) ?>"
                      <?= $pre_lead === (int)$lp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(trim(($lp['lead_name'] ?? 'Lead') . (($lp['lead_company'] ?? '') ? ' · ' . $lp['lead_company'] : '') . (($lp['email'] ?? '') ? ' · ' . $lp['email'] : ''))) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:var(--text-lt);margin-top:6px;">Picking a lead fills name, email, phone and service address — and marks that lead won when the order is submitted.</div>
          </div>
          <script>
          /* Auto-fill the client fields from the selected lead (only empties — never
             clobbers something the dealer already typed). */
          function bmFillFromLead(sel) {
            var o = sel.options[sel.selectedIndex];
            if (!o || !o.value) return;
            var map = { client_name: 'name', client_email: 'email', client_phone: 'phone', service_address: 'address' };
            for (var field in map) {
              var el = document.querySelector('[name="' + field + '"]');
              var v = o.getAttribute('data-' + map[field]) || '';
              if (el && v && !el.value.trim()) el.value = v;
            }
          }
          document.addEventListener('DOMContentLoaded', function () {
            var s = document.getElementById('lead-picker');
            if (s && s.value) bmFillFromLead(s);   /* prefill when arriving from a lead row */
          });
          </script>
          <?php endif; ?>

          <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-lt);margin-bottom:10px;">Client information</div>

          <div class="form-group">
            <label class="form-label">Full name <span style="color:var(--red);">*</span></label>
            <input type="text" name="client_name" class="form-control" required
                   placeholder="First Last" value="<?= htmlspecialchars($_POST['client_name'] ?? '') ?>">
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Email address</label>
              <input type="email" name="client_email" class="form-control"
                     placeholder="client@email.com" value="<?= htmlspecialchars($_POST['client_email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="tel" name="client_phone" class="form-control"
                     placeholder="(713) 000-0000" value="<?= htmlspecialchars($_POST['client_phone'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Service address</label>
            <input type="text" name="service_address" class="form-control"
                   placeholder="Street, City, State, ZIP" value="<?= htmlspecialchars($_POST['service_address'] ?? '') ?>">
          </div>

          <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-lt);margin:16px 0 10px;">Service</div>

          <div class="form-group">
            <label class="form-label">Product line <span style="color:var(--red);">*</span></label>
            <select name="product_line" id="product_line" class="form-control" required onchange="updateSpiff()">
              <option value="">— Select a product —</option>
              <?php foreach ($product_labels as $val => $label): ?>
              <option value="<?= $val ?>" <?= ($_POST['product_line'] ?? '' ?? '' ?? '') === $val ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Plan / tier</label>
              <input type="text" name="plan_name" class="form-control"
                     placeholder="e.g. 1 Gig" value="<?= htmlspecialchars($_POST['plan_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Monthly price ($) <span style="color:var(--text-lt);font-weight:400;">used for invoice</span></label>
              <input type="number" name="plan_price" class="form-control" step="0.01" min="0"
                     placeholder="0.00" value="<?= htmlspecialchars($_POST['plan_price'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Sold by <span style="color:var(--text-lt);font-weight:400;">(your sales team)</span></label>
            <?php if ($team): ?>
            <select name="sales_user_id" class="form-control">
              <option value="">— not specified —</option>
              <?php foreach ($team as $tm): ?>
              <option value="<?= (int)$tm['user_id'] ?>" <?= (int)($_POST['sales_user_id'] ?? 0) === (int)$tm['user_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$tm['name']) ?><?= $tm['role'] !== 'sales' ? ' (' . htmlspecialchars((string)$tm['role']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="sales_name" class="form-control" placeholder="Rep name (no team logins yet)"
                   value="<?= htmlspecialchars($_POST['sales_name'] ?? '') ?>">
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label">Dealer notes <span style="color:var(--text-lt);font-weight:400;">(optional)</span></label>
            <input type="text" name="dealer_notes" class="form-control"
                   placeholder="e.g. client needs same-day setup"
                   value="<?= htmlspecialchars($_POST['dealer_notes'] ?? '') ?>">
          </div>

          <!-- SPIFF PREVIEW -->
          <div id="spiff-preview" style="background:#f0faf4;border:1px solid #86efac;border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;display:none;">
            <div style="font-size:11px;color:var(--green-text);font-weight:500;">Your commission on this order</div>
            <div id="spiff-amount" style="font-size:22px;font-weight:600;color:var(--green);">$0.00</div>
            <div style="font-size:11px;color:var(--text-lt);margin-top:2px;">Released within 24 hrs of confirmed activation</div>
          </div>

          <!-- WHAT GETS CREATED -->
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--text-m);">
            <strong style="color:var(--text);">On submit:</strong>
            &nbsp;✓ Support ticket opened &nbsp;
            <span id="inv-note" style="display:none;">✓ Draft invoice created for client</span>
            <span id="inv-note-off">✓ Invoice created if monthly price provided</span>
          </div>

          <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Submit order →</button>
            <button type="reset" class="btn btn-outline" onclick="document.getElementById('spiff-preview').style.display='none';toggleInvNote(false)">Clear</button>
          </div>
        </form>
      </div>

      <!-- SPIFF REFERENCE -->
      <div class="card">
        <div class="card-title" style="margin-bottom:14px;">Current spiff rates</div>
        <table>
          <thead><tr><th>Product</th><th>Your tier</th><th>Spiff</th></tr></thead>
          <tbody>
            <?php foreach ($product_labels as $val => $label):
              $spiff_c = $spiffs_map[$val][$dealer_tier] ?? 0;
            ?>
            <tr>
              <td style="font-size:12px;"><?= $label ?></td>
              <td><?= tier_badge($dealer_tier) ?></td>
              <td style="font-weight:600;color:var(--green);">$<?= dollars($spiff_c) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($dealer_tier !== 'gold'): ?>
        <div class="alert alert-info" style="margin-top:14px;margin-bottom:0;font-size:12px;">
          Reach Gold tier (10 activations/month) to unlock +20% on all spiffs.
        </div>
        <?php endif; ?>

        <div style="margin-top:14px;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-size:12px;color:var(--text-m);">
          <div style="font-weight:600;color:var(--text);margin-bottom:6px;">What happens after you submit</div>
          <div style="display:flex;flex-direction:column;gap:6px;">
            <div>📋 <strong>Ticket</strong> — opens immediately in admin for processing</div>
            <div>🧾 <strong>Invoice</strong> — drafted and sent to client once plan price is entered</div>
            <div>💰 <strong>Commission</strong> — approved once service is confirmed active</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ORDER HISTORY -->
    <?php if (!empty($all_orders)): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">Order history</span>
        <span style="font-size:12px;color:var(--text-lt);"><?= count($all_orders) ?> orders</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Ref</th><th>Client</th><th>Product</th><th>Plan</th>
            <th>Commission</th><th>Ticket</th><th>Invoice</th><th>Status</th><th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($all_orders as $o): ?>
          <tr>
            <td style="font-family:monospace;font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($o['order_ref'] ?? '—') ?></td>
            <td style="font-weight:500;"><?= htmlspecialchars($o['client_name'] ?? '—') ?></td>
            <td style="font-size:12px;color:var(--text-m);"><?= $product_labels[$o['product_line']] ?? htmlspecialchars($o['product_line'] ?? '—') ?></td>
            <td style="font-size:12px;color:var(--text-lt);"><?= htmlspecialchars($o['plan_name'] ?? '—') ?></td>
            <td style="font-weight:600;color:<?= $o['comm_status']==='paid'?'var(--teal)':'var(--green)' ?>;">
              <?= $o['amount_cents'] ? '$'.dollars($o['amount_cents']) : '—' ?>
            </td>
            <td style="font-size:12px;">
              <?= $o['ticket_id'] ? '<span class="badge badge-blue">#'.$o['ticket_id'].'</span>' : '<span style="color:var(--text-lt);">—</span>' ?>
            </td>
            <td style="font-size:12px;">
              <?= $o['invoice_id'] ? '<span class="badge badge-teal">#'.$o['invoice_id'].'</span>' : '<span style="color:var(--text-lt);">—</span>' ?>
            </td>
            <td><?= status_badge($o['status']) ?></td>
            <td style="font-size:12px;color:var(--text-lt);"><?= dealer_fmt_date($o['created_at'] ?? null) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>

</div><!-- /.main -->

<script>
const SPIFFS = <?= json_encode($spiffs_map) ?>;
const TIER   = '<?= $dealer_tier ?>';

function updateSpiff() {
  const product = document.getElementById('product_line').value;
  const preview = document.getElementById('spiff-preview');
  const amount  = document.getElementById('spiff-amount');
  if (!product || !SPIFFS[product]) { preview.style.display='none'; return; }
  const cents = SPIFFS[product][TIER] || SPIFFS[product]['base'] || 0;
  amount.textContent = '$' + (cents / 100).toFixed(2);
  preview.style.display = 'block';
}

function toggleInvNote(show) {
  document.getElementById('inv-note').style.display     = show ? 'inline' : 'none';
  document.getElementById('inv-note-off').style.display = show ? 'none'   : 'inline';
}

document.querySelector('[name="plan_price"]')?.addEventListener('input', function() {
  toggleInvNote(this.value && parseFloat(this.value) > 0);
});
</script>

</body>
</html>
