<?php
/**
 * admin/admin-dealer-detail.php
 * Blue Mogul portal admin — Dealer profile, commissions, payout history
 */

if (session_status() === PHP_SESSION_NONE) session_start();

$is_admin = (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin')
         || (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
         || (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === 1);

if (!$is_admin) {
    header('Location: /portal/index.php?session_expired=1');
    exit;
}

require_once __DIR__ . '/../includes/dealer-functions.php';
$pdo = get_db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /portal/admin/admin-dealers.php'); exit; }

$dealer = $pdo->prepare(
    "SELECT d.*, COALESCE(d.full_name, u.name) AS full_name, COALESCE(d.email, u.email) AS email
     FROM dealers d
     LEFT JOIN users u ON u.id = d.user_id
     WHERE d.id=?"
)->execute([$id]) ? null : null;

$stmt = $pdo->prepare(
    "SELECT d.*, COALESCE(d.full_name, u.name) AS full_name, COALESCE(d.email, u.email) AS email
     FROM dealers d
     LEFT JOIN users u ON u.id = d.user_id
     WHERE d.id=?"
);
$stmt->execute([$id]);
$dealer = $stmt->fetch();

if (!$dealer) { header('Location: /portal/admin/admin-dealers.php?error=not_found'); exit; }

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_dealer') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $company   = trim($_POST['company']   ?? '');
        $status    = in_array($_POST['status'], ['pending','active','suspended']) ? $_POST['status'] : $dealer['status'];
        $tier      = in_array($_POST['tier'],   ['base','silver','gold'])         ? $_POST['tier']   : $dealer['tier'];
        $notes     = trim($_POST['notes'] ?? '');

        $pdo->prepare(
            "UPDATE dealers SET full_name=?,email=?,phone=?,company=?,status=?,tier=?,notes=?,updated_at=NOW() WHERE id=?"
        )->execute([$full_name,$email,$phone,$company,$status,$tier,$notes,$id]);

        $success = "Dealer profile updated.";
        $stmt->execute([$id]);
        $dealer = $stmt->fetch();
    }

    if ($action === 'approve_commission') {
        $cid = (int)$_POST['commission_id'];
        $pdo->prepare("UPDATE commissions SET status='approved',approved_at=NOW() WHERE id=? AND dealer_id=? AND status='pending'")->execute([$cid,$id]);
        $success = "Commission approved.";
    }

    if ($action === 'add_commission') {
        $amount_dollars = (float)($_POST['amount'] ?? 0);
        $note           = trim($_POST['note'] ?? '');
        if ($amount_dollars > 0) {
            $pdo->prepare(
                "INSERT INTO commissions (dealer_id, amount_cents, status, notes, created_at)
                 VALUES (?,?,'approved',?,NOW())"
            )->execute([$id, (int)round($amount_dollars * 100), $note ?: null]);
            $success = "Manual commission of \${$amount_dollars} added and approved.";
        } else {
            $error = 'Enter a valid amount.';
        }
    }

    if ($action === 'mark_paid') {
        $pid = (int)$_POST['payout_id'];
        $pdo->prepare("UPDATE dealer_payouts SET status='sent',sent_at=NOW() WHERE id=? AND dealer_id=?")->execute([$pid,$id]);
        $success = "Payout marked as sent.";
    }
}

$commissions = $pdo->prepare(
    "SELECT c.*, o.order_ref, o.client_name, o.product_line
     FROM commissions c
     LEFT JOIN dealer_orders o ON o.id=c.order_id
     WHERE c.dealer_id=?
     ORDER BY c.created_at DESC"
);
$commissions->execute([$id]);
$comms = $commissions->fetchAll();

$payouts = $pdo->prepare(
    "SELECT * FROM dealer_payouts WHERE dealer_id=? ORDER BY created_at DESC"
);
$payouts->execute([$id]);
$payout_hist = $payouts->fetchAll();

$orders_stmt = $pdo->prepare(
    "SELECT o.*, c.amount_cents AS comm_cents, c.status AS comm_status
     FROM dealer_orders o
     LEFT JOIN commissions c ON c.order_id=o.id
     WHERE o.dealer_id=?
     ORDER BY o.created_at DESC LIMIT 30 "
);
$orders_stmt->execute([$id]);
$orders = $orders_stmt->fetchAll();

$totals = $pdo->prepare(
    "SELECT
       COALESCE(SUM(amount_cents) FILTER (WHERE status='approved'),0) AS approved,
       COALESCE(SUM(amount_cents) FILTER (WHERE status='paid'),0)     AS paid,
       COALESCE(SUM(amount_cents) FILTER (WHERE status='pending'),0)  AS pending_c
     FROM commissions WHERE dealer_id=?"
);
$totals->execute([$id]);
$t = $totals->fetch();

$product_labels = [
    'frontier_fiber'  => 'Frontier Fiber',
    'xfinity_prepaid' => 'Xfinity Prepaid',
    'verizon_prepaid' => 'Verizon Prepaid',
    'black_wireless'  => 'Black Wireless',
    'travelsim'       => 'TravelSim',
    'sling_tv'        => 'Sling TV',
];

$tab = $_GET['tab'] ?? 'overview';
$current_page = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="/portal/">
  <title><?= htmlspecialchars($dealer['full_name']) ?> — Dealer Detail</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
  <script src="https://cdn.tailwindcss.com?v=3"></script>
  <style>
    <?php include __DIR__ . '/../includes/admin-styles.css.php'; ?>
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>

<div class="main">

  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <a href="/portal/admin/admin-dealers.php" class="btn btn-outline btn-sm">← Back</a>
      <div>
        <div class="topbar-title"><?= htmlspecialchars($dealer['full_name']) ?></div>
        <div class="topbar-sub"><?= htmlspecialchars($dealer['dealer_code'] ?? $dealer['email']) ?></div>
      </div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier'] ?? 'base') ?>
      <?= status_badge($dealer['status'] ?? 'pending') ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
      <div class="stat-card">
        <div class="stat-label">Pending payout</div>
        <div class="stat-value" style="color:var(--amber);">$<?= dollars($t['approved']) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Pending review</div>
        <div class="stat-value" style="color:var(--blue);">$<?= dollars($t['pending_c']) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">All-time paid</div>
        <div class="stat-value">$<?= dollars($t['paid']) ?></div>
      </div>
    </div>

    <div class="tab-nav">
      <?php foreach (['overview' => 'Overview', 'commissions' => 'Commissions', 'orders' => 'Orders', 'payouts' => 'Payouts'] as $k => $l): ?>
      <a href="?id=<?= $id ?>&tab=<?= $k ?>"
         class="tab-btn <?= $tab === $k ? 'active' : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'overview'): ?>

    <div class="two-col">
      <div class="card">
        <div class="card-title" style="margin-bottom:14px;">Edit profile</div>
        <form method="POST" action="/portal/admin/admin-dealer-detail.php?id=<?= $id ?>">
          <input type="hidden" name="action" value="update_dealer">
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Full name</label>
              <input type="text" name="full_name" class="form-control"
                     value="<?= htmlspecialchars($dealer['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= htmlspecialchars($dealer['email'] ?? '') ?>">
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control"
                     value="<?= htmlspecialchars($dealer['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-control"
                     value="<?= htmlspecialchars($dealer['company'] ?? '') ?>">
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="pending"   <?= $dealer['status']==='pending'   ?'selected':'' ?>>Pending</option>
                <option value="active"    <?= $dealer['status']==='active'    ?'selected':'' ?>>Active</option>
                <option value="suspended" <?= $dealer['status']==='suspended' ?'selected':'' ?>>Suspended</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Tier</label>
              <select name="tier" class="form-control">
                <option value="base"   <?= $dealer['tier']==='base'   ?'selected':'' ?>>Base</option>
                <option value="silver" <?= $dealer['tier']==='silver' ?'selected':'' ?>>Silver</option>
                <option value="gold"   <?= $dealer['tier']==='gold'   ?'selected':'' ?>>Gold</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control"
                   value="<?= htmlspecialchars($dealer['notes'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
        </form>
      </div>

      <div>
        <div class="card">
          <div class="card-title" style="margin-bottom:12px;">Add manual commission</div>
          <form method="POST" action="/portal/admin/admin-dealer-detail.php?id=<?= $id ?>">
            <input type="hidden" name="action" value="add_commission">
            <div class="form-group">
              <label class="form-label">Amount ($)</label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
              <label class="form-label">Note</label>
              <input type="text" name="note" class="form-control" placeholder="Reason for manual commission">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add &amp; approve</button>
          </form>
        </div>

        <div class="card">
          <div class="card-title" style="margin-bottom:10px;">Bank info</div>
          <div class="info-grid">
            <div class="info-row">
              <div class="info-label">Routing</div>
              <div class="info-val"><?= $dealer['ach_routing'] ? str_repeat('•',5).substr($dealer['ach_routing'],-4) : '<span style="color:var(--text-lt);">Not set</span>' ?></div>
            </div>
            <div class="info-row">
              <div class="info-label">Account</div>
              <div class="info-val"><?= $dealer['ach_account'] ? str_repeat('•',6).substr($dealer['ach_account'],-4) : '<span style="color:var(--text-lt);">Not set</span>' ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php elseif ($tab === 'commissions'): ?>

    <div class="card" style="padding:0;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Commissions</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Order ref</th><th>Client</th>
            <th>Product</th><th>Amount</th><th>Status</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($comms)): ?>
          <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-lt);">No commissions.</td></tr>
          <?php endif; ?>
          <?php foreach ($comms as $c): ?>
          <tr>
            <td style="font-size:12px;"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
            <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($c['order_ref'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($c['client_name'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= $product_labels[$c['product_line'] ?? ''] ?? htmlspecialchars($c['product_line'] ?? '—') ?></td>
            <td style="font-weight:600;">$<?= dollars($c['amount_cents']) ?></td>
            <td><?= status_badge($c['status']) ?></td>
            <td>
              <?php if ($c['status'] === 'pending'): ?>
              <form method="POST" action="/portal/admin/admin-dealer-detail.php?id=<?= $id ?>" style="display:inline;">
                <input type="hidden" name="action"        value="approve_commission">
                <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              </form>
              <?php else: ?>
              <span style="font-size:11px;color:var(--text-lt);"><?= $c['status'] === 'paid' ? 'Paid '.date('M j', strtotime($c['paid_at'])) : '—' ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'orders'): ?>

    <div class="card" style="padding:0;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);">
        <span class="card-title">Orders</span>
      </div>
      <table>
        <thead>
          <tr><th>Date</th><th>Ref</th><th>Client</th><th>Product</th><th>Commission</th><th>Ticket</th><th>Invoice</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--text-lt);">No orders.</td></tr>
          <?php endif; ?>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td style="font-size:12px;"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
            <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($o['order_ref'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($o['client_name'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= $product_labels[$o['product_line'] ?? ''] ?? htmlspecialchars($o['product_line'] ?? '—') ?></td>
            <td style="font-weight:600;color:<?= $o['comm_status']==='paid'?'var(--teal)':'var(--green)' ?>;">
              <?= $o['comm_cents'] ? '$'.dollars($o['comm_cents']) : '—' ?>
            </td>
            <td>
              <?php if (!empty($o['ticket_id'])): ?>
              <a href="/portal/admin-tickets.php?id=<?= $o['ticket_id'] ?>" class="badge badge-blue" style="text-decoration:none;" target="_blank">#<?= $o['ticket_id'] ?></a>
              <?php else: ?>
              <span style="color:var(--text-lt);font-size:11px;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($o['invoice_id'])): ?>
              <a href="/portal/admin-invoice-detail.php?id=<?= $o['invoice_id'] ?>" class="badge badge-teal" style="text-decoration:none;" target="_blank">#<?= $o['invoice_id'] ?></a>
              <?php else: ?>
              <span style="color:var(--text-lt);font-size:11px;">—</span>
              <?php endif; ?>
            </td>
            <td><?= status_badge($o['status'] ?? 'submitted') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'payouts'): ?>

    <div class="card" style="padding:0;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);">
        <span class="card-title">Payout history</span>
      </div>
      <table>
        <thead>
          <tr><th>Date</th><th>Activations</th><th>Amount</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php if (empty($payout_hist)): ?>
          <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-lt);">No payouts.</td></tr>
          <?php endif; ?>
          <?php foreach ($payout_hist as $p): ?>
          <tr>
            <td style="font-size:12px;"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
            <td style="text-align:center;"><?= $p['commission_count'] ?></td>
            <td style="font-weight:600;">$<?= dollars($p['amount_cents']) ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td>
              <?php if (in_array($p['status'], ['pending','processing'])): ?>
              <form method="POST" action="/portal/admin/admin-dealer-detail.php?id=<?= $id ?>" style="display:inline;">
                <input type="hidden" name="action"    value="mark_paid">
                <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Mark sent</button>
              </form>
              <?php else: ?>
              <span style="font-size:11px;color:var(--text-lt);"><?= $p['sent_at'] ? date('M j', strtotime($p['sent_at'])) : '—' ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>

  </div>
</div>

<script>
function toggleClients(){
  const sub=document.getElementById('clients-subnav'),ch=document.getElementById('clients-chevron');
  if(!sub)return;sub.classList.toggle('hidden');if(ch)ch.style.transform=sub.classList.contains('hidden')?'':'rotate(180deg)';
}
function toggleLeads(){
  const sub=document.getElementById('leads-subnav'),ch=document.getElementById('leads-chevron');
  if(!sub)return;sub.classList.toggle('hidden');if(ch)ch.style.transform=sub.classList.contains('hidden')?'':'rotate(180deg)';
}
document.addEventListener('DOMContentLoaded',function(){
  ['clients','leads'].forEach(function(n){
    const sub=document.getElementById(n+'-subnav'),ch=document.getElementById(n+'-chevron');
    if(sub&&ch)ch.style.transform=sub.classList.contains('hidden')?'':'rotate(180deg)';
  });
});
</script>
</body>
</html>
