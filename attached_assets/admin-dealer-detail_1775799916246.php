<?php
/**
 * admin/admin-dealer-detail.php
 * Full dealer profile for admin: approve/suspend, edit details,
 * view order history, commission log, payout history, manual commission entry
 */

$page_title = 'Dealer Detail';
require_once __DIR__ . '/../includes/dealer-header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /portal/login.php'); exit;
}

$pdo = get_db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /portal/admin/admin-dealers.php'); exit; }

$success = $error = '';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'update_status':
            $new_status = in_array($_POST['status'],['pending','active','suspended'])
                ? $_POST['status'] : 'active';
            $pdo->prepare("UPDATE dealers SET status=?, updated_at=NOW() WHERE id=?")
                ->execute([$new_status, $id]);
            $success = 'Status updated to ' . $new_status . '.';
            break;

        case 'update_tier':
            $new_tier = in_array($_POST['tier'],['base','silver','gold']) ? $_POST['tier'] : 'base';
            $pdo->prepare("UPDATE dealers SET tier=?, updated_at=NOW() WHERE id=?")
                ->execute([$new_tier, $id]);
            $success = 'Tier manually set to ' . $new_tier . '.';
            break;

        case 'update_notes':
            $pdo->prepare("UPDATE dealers SET notes=?, updated_at=NOW() WHERE id=?")
                ->execute([trim($_POST['notes']), $id]);
            $success = 'Notes saved.';
            break;

        case 'manual_commission':
            $amount_cents = (int)round((float)$_POST['amount'] * 100);
            $note         = trim($_POST['note'] ?? 'Manual commission by admin');
            if ($amount_cents > 0) {
                // Create a dummy order ref for the manual entry
                $order_ref = 'MANUAL-' . date('Ymd') . '-' . str_pad(random_int(1,9999),4,'0',STR_PAD_LEFT);
                $ord = $pdo->prepare(
                    "INSERT INTO dealer_orders
                       (dealer_id,order_ref,client_name,product_line,spiff_cents,tier_at_order,status,notes)
                     VALUES (?,?,'Manual adjustment','manual',?,?,  'activated',?)"
                );
                $ord->execute([$id,$order_ref,$amount_cents,'base',$note]);
                $order_id = $pdo->lastInsertId();
                $pdo->prepare(
                    "INSERT INTO commissions (dealer_id,order_id,amount_cents,status,approved_at)
                     VALUES (?,?,?,'approved',NOW())"
                )->execute([$id,$order_id,$amount_cents]);
                $success = 'Manual commission of $' . number_format($amount_cents/100,2) . ' added.';
            } else {
                $error = 'Amount must be greater than 0.';
            }
            break;

        case 'approve_commission':
            $comm_id = (int)($_POST['commission_id'] ?? 0);
            $pdo->prepare(
                "UPDATE commissions SET status='approved', approved_at=NOW() WHERE id=? AND dealer_id=?"
            )->execute([$comm_id, $id]);
            $success = 'Commission approved.';
            break;

        case 'reverse_commission':
            $comm_id = (int)($_POST['commission_id'] ?? 0);
            $pdo->prepare(
                "UPDATE commissions SET status='reversed' WHERE id=? AND dealer_id=?"
            )->execute([$comm_id, $id]);
            $success = 'Commission reversed.';
            break;
    }

    // Clear session cache in case dealer data changed
    unset($_SESSION['dealer_cache']);
}

// ── Load dealer ───────────────────────────────────────────────
$dealer = $pdo->prepare(
    "SELECT d.*,
            COALESCE(ord.order_count,0)   AS order_count,
            COALESCE(ord.activated,0)     AS activations_total,
            COALESCE(comm.earned_cents,0) AS earned_cents,
            COALESCE(comm.pending_cents,0)AS pending_payout
     FROM dealers d
     LEFT JOIN (
       SELECT dealer_id, COUNT(*) order_count,
              COUNT(*) FILTER (WHERE status='activated') activated
       FROM dealer_orders GROUP BY dealer_id
     ) ord ON ord.dealer_id=d.id
     LEFT JOIN (
       SELECT dealer_id,
              SUM(amount_cents) FILTER (WHERE status IN ('approved','paid')) earned_cents,
              SUM(amount_cents) FILTER (WHERE status='approved') pending_cents
       FROM commissions GROUP BY dealer_id
     ) comm ON comm.dealer_id=d.id
     WHERE d.id=?"
);
$dealer->execute([$id]);
$d = $dealer->fetch();
if (!$d) { header('Location: /portal/admin/admin-dealers.php'); exit; }

// ── Orders ────────────────────────────────────────────────────
$orders_stmt = $pdo->prepare(
    "SELECT o.*, c.amount_cents, c.status AS comm_status, c.id AS comm_id
     FROM dealer_orders o
     LEFT JOIN commissions c ON c.order_id=o.id
     WHERE o.dealer_id=? ORDER BY o.created_at DESC LIMIT 30"
);
$orders_stmt->execute([$id]);
$orders = $orders_stmt->fetchAll();

// ── Payout history ────────────────────────────────────────────
$payouts_stmt = $pdo->prepare(
    "SELECT * FROM dealer_payouts WHERE dealer_id=? ORDER BY created_at DESC LIMIT 10"
);
$payouts_stmt->execute([$id]);
$payouts = $payouts_stmt->fetchAll();

$product_labels = [
    'frontier_fiber'=>'Frontier Fiber','xfinity_prepaid'=>'Xfinity Prepaid',
    'verizon_prepaid'=>'Verizon Prepaid','black_wireless'=>'Black Wireless',
    'travelsim'=>'TravelSim / eSIM','sling_tv'=>'Sling TV','manual'=>'Manual'
];

$initials = strtoupper(substr($d['full_name'],0,1)) .
            (strpos($d['full_name'],' ')!==false
              ? strtoupper(substr(strrchr($d['full_name'],' '),1,1)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($d['full_name']) ?> — Dealer Detail</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    <?php include __DIR__ . '/../includes/admin-styles.css.php'; ?>
    .detail-header{display:flex;align-items:center;gap:16px;margin-bottom:22px;}
    .dealer-av-lg{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:600;}
    .tab-nav{display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:20px;}
    .tab-btn{padding:8px 16px;font-size:13px;font-weight:500;color:var(--text-lt);background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;font-family:var(--font);transition:color .12s;}
    .tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
    .tab-pane{display:none;} .tab-pane.active{display:block;}
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;}
    .info-row{padding:8px 0;border-bottom:1px solid var(--border);}
    .info-label{font-size:11px;color:var(--text-lt);margin-bottom:2px;}
    .info-val{font-size:13px;color:var(--text);font-weight:500;}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>

<div class="main">

  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="/portal/admin/admin-dealers.php" style="color:var(--text-lt);font-size:13px;text-decoration:none;">← Dealers</a>
      <span style="color:var(--border);">/</span>
      <div class="topbar-title"><?= htmlspecialchars($d['full_name']) ?></div>
      <?= status_badge($d['status']) ?>
    </div>
    <div class="topbar-right">
      <span style="font-family:monospace;font-size:12px;color:var(--text-lt);"><?= htmlspecialchars($d['dealer_code']) ?></span>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);margin-bottom:16px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- HEADER STATS -->
    <div class="stat-grid" style="margin-bottom:20px;">
      <div class="stat-card">
        <div class="stat-label">All-time earned</div>
        <div class="stat-value" style="color:var(--green);">$<?= dollars($d['earned_cents']) ?></div>
        <div class="stat-sub"><?= $d['activations_total'] ?> activations</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Pending payout</div>
        <div class="stat-value" style="color:var(--amber);">$<?= dollars($d['pending_payout']) ?></div>
        <div class="stat-sub">Approved, not yet paid</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">This month</div>
        <div class="stat-value"><?= $d['activations_mtd'] ?></div>
        <div class="stat-sub">activations / <?= ucfirst($d['tier']) ?> tier</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total orders</div>
        <div class="stat-value"><?= $d['order_count'] ?></div>
        <div class="stat-sub"><?= $d['activations_total'] ?> activated</div>
      </div>
    </div>

    <!-- TABS -->
    <div class="tab-nav">
      <button class="tab-btn active" onclick="showTab('orders',this)">Orders &amp; commissions</button>
      <button class="tab-btn" onclick="showTab('payouts',this)">Payout history</button>
      <button class="tab-btn" onclick="showTab('manage',this)">Manage dealer</button>
    </div>

    <!-- TAB: ORDERS -->
    <div id="tab-orders" class="tab-pane active">
      <div class="card" style="padding:0;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);">
          <span class="card-title">Orders &amp; commissions</span>
          <button class="btn btn-outline btn-sm"
                  onclick="document.getElementById('manual-commission-panel').style.display='block'">
            + Manual commission
          </button>
        </div>

        <!-- MANUAL COMMISSION PANEL (hidden by default) -->
        <div id="manual-commission-panel" style="display:none;background:#fffbeb;border-bottom:1px solid var(--border);padding:16px 18px;">
          <div style="font-size:13px;font-weight:500;color:var(--text);margin-bottom:10px;">Add manual commission</div>
          <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="manual_commission">
            <div>
              <label class="form-label">Amount ($)</label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                     placeholder="0.00" style="width:120px;" required>
            </div>
            <div style="flex:1;min-width:200px;">
              <label class="form-label">Note</label>
              <input type="text" name="note" class="form-control" placeholder="Reason for manual adjustment">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add commission</button>
            <button type="button" class="btn btn-outline btn-sm"
                    onclick="document.getElementById('manual-commission-panel').style.display='none'">
              Cancel
            </button>
          </form>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
          <thead style="background:var(--bg);">
            <tr>
              <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Order</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Client</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Product</th>
              <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Commission</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Order status</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Commission</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Date</th>
              <th style="padding:9px 14px;border-bottom:1px solid var(--border);"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders)): ?>
            <tr><td colspan="8" style="padding:20px 14px;text-align:center;color:var(--text-lt);">No orders yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
            <tr onmouseover="this.style.background='#fafbfd'" onmouseout="this.style.background=''">
              <td style="padding:10px 14px;font-family:monospace;font-size:11px;color:var(--text-lt);">
                <?= htmlspecialchars($o['order_ref']) ?>
              </td>
              <td style="padding:10px 12px;font-weight:500;"><?= htmlspecialchars($o['client_name']) ?></td>
              <td style="padding:10px 12px;font-size:12px;color:var(--text-m);">
                <?= $product_labels[$o['product_line']] ?? $o['product_line'] ?>
              </td>
              <td style="padding:10px 12px;text-align:right;font-weight:600;color:var(--green);">
                <?= $o['amount_cents'] ? '$'.dollars($o['amount_cents']) : '—' ?>
              </td>
              <td style="padding:10px 12px;"><?= status_badge($o['status']) ?></td>
              <td style="padding:10px 12px;"><?= $o['comm_status'] ? status_badge($o['comm_status']) : '—' ?></td>
              <td style="padding:10px 12px;font-size:12px;color:var(--text-lt);">
                <?= date('M j, Y', strtotime($o['created_at'])) ?>
              </td>
              <td style="padding:10px 14px;">
                <?php if ($o['comm_status'] === 'pending' && $o['comm_id']): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="approve_commission">
                  <input type="hidden" name="commission_id" value="<?= $o['comm_id'] ?>">
                  <button type="submit" class="btn btn-sm"
                          style="background:var(--green-bg);color:var(--green-text);border:1px solid #86efac;">
                    Approve
                  </button>
                </form>
                <?php elseif ($o['comm_status'] === 'approved' && $o['comm_id']): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Reverse this commission?')">
                  <input type="hidden" name="action" value="reverse_commission">
                  <input type="hidden" name="commission_id" value="<?= $o['comm_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline" style="color:var(--red-text);">Reverse</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB: PAYOUTS -->
    <div id="tab-payouts" class="tab-pane">
      <div class="card" style="padding:0;">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
          <span class="card-title">Payout history</span>
        </div>
        <?php if (empty($payouts)): ?>
        <div style="padding:20px 18px;font-size:13px;color:var(--text-lt);">No payouts yet.</div>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
          <thead style="background:var(--bg);">
            <tr>
              <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Date</th>
              <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Amount</th>
              <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Activations</th>
              <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Status</th>
              <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Sent</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payouts as $p): ?>
            <tr>
              <td style="padding:10px 14px;font-size:12px;"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
              <td style="padding:10px 12px;text-align:right;font-weight:600;color:var(--teal);">$<?= dollars($p['amount_cents']) ?></td>
              <td style="padding:10px 12px;text-align:right;font-size:12px;color:var(--text-m);"><?= $p['commission_count'] ?></td>
              <td style="padding:10px 12px;"><?= status_badge($p['status']) ?></td>
              <td style="padding:10px 14px;font-size:12px;color:var(--text-lt);">
                <?= $p['sent_at'] ? date('M j, Y', strtotime($p['sent_at'])) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: MANAGE -->
    <div id="tab-manage" class="tab-pane">
      <div class="two-col" style="align-items:start;">

        <!-- DEALER INFO -->
        <div class="card">
          <div class="card-title" style="margin-bottom:14px;">Dealer profile</div>
          <div class="info-grid">
            <div class="info-row">
              <div class="info-label">Full name</div>
              <div class="info-val"><?= htmlspecialchars($d['full_name']) ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Dealer code</div>
              <div class="info-val" style="font-family:monospace;"><?= htmlspecialchars($d['dealer_code']) ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Email</div>
              <div class="info-val"><?= htmlspecialchars($d['email']) ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Phone</div>
              <div class="info-val"><?= htmlspecialchars($d['phone'] ?? '—') ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Company</div>
              <div class="info-val"><?= htmlspecialchars($d['company'] ?? '—') ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Bank on file</div>
              <div class="info-val">
                <?= $d['ach_routing'] ? 'Routing ending ' . substr($d['ach_routing'],-4) . ' · Acct ending ' . substr($d['ach_account']??'????',-4) : 'Not set' ?>
              </div>
            </div>
          </div>
        </div>

        <!-- STATUS + TIER CONTROLS -->
        <div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-title" style="margin-bottom:14px;">Status &amp; tier</div>
            <form method="POST" style="margin-bottom:14px;">
              <input type="hidden" name="action" value="update_status">
              <div class="form-group">
                <label class="form-label">Account status</label>
                <select name="status" class="form-control">
                  <?php foreach (['pending','active','suspended'] as $s): ?>
                  <option value="<?= $s ?>" <?= $d['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">Update status</button>
            </form>

            <form method="POST">
              <input type="hidden" name="action" value="update_tier">
              <div class="form-group">
                <label class="form-label">Tier override</label>
                <select name="tier" class="form-control">
                  <?php foreach (['base','silver','gold'] as $t): ?>
                  <option value="<?= $t ?>" <?= $d['tier']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="font-size:11px;color:var(--text-lt);margin-bottom:10px;">
                Tier auto-updates monthly based on activation count. Override manually if needed.
              </div>
              <button type="submit" class="btn btn-outline btn-sm">Override tier</button>
            </form>
          </div>

          <div class="card">
            <div class="card-title" style="margin-bottom:14px;">Admin notes</div>
            <form method="POST">
              <input type="hidden" name="action" value="update_notes">
              <div class="form-group">
                <textarea name="notes" class="form-control" rows="4"
                          placeholder="Internal notes about this dealer..."><?= htmlspecialchars($d['notes'] ?? '') ?></textarea>
              </div>
              <button type="submit" class="btn btn-outline btn-sm">Save notes</button>
            </form>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<script>
function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}
</script>

</body>
</html>
