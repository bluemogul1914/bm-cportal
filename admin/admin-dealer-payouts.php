<?php
/**
 * admin/admin-dealer-payouts.php
 * Blue Mogul portal admin — Payout queue and commission approvals
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

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_commission') {
        $cid = (int)$_POST['commission_id'];
        $pdo->prepare("UPDATE commissions SET status='approved',approved_at=NOW() WHERE id=? AND status='pending'")->execute([$cid]);
        $success = "Commission #{$cid} approved.";
    }

    if ($action === 'approve_all') {
        $pdo->exec("UPDATE commissions SET status='approved',approved_at=NOW() WHERE status='pending'");
        $success = "All pending commissions approved.";
    }

    if ($action === 'mark_paid') {
        $pid = (int)$_POST['payout_id'];
        $pdo->prepare("UPDATE dealer_payouts SET status='sent',sent_at=NOW() WHERE id=?")->execute([$pid]);
        $pdo->prepare("UPDATE commissions SET status='paid',paid_at=NOW() WHERE payout_id=? AND status='approved'")->execute([$pid]);
        $success = "Payout #{$pid} marked as sent.";
    }

    if ($action === 'run_payout') {
        $dealers_with_approved = $pdo->query(
            "SELECT dealer_id, SUM(amount_cents) AS total, COUNT(*) AS cnt, ARRAY_AGG(id) AS ids
             FROM commissions WHERE status='approved'
             GROUP BY dealer_id"
        );
        $batch = $dealers_with_approved->fetchAll();
        $payout_count = 0;

        foreach ($batch as $b) {
            $ids = array_map('intval', array_filter($b['ids'] ?? []));
            if (empty($ids)) continue;

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO dealer_payouts (dealer_id, amount_cents, commission_count, status, initiated_at)
                     VALUES (?,?,?,'processing',NOW()) RETURNING id"
                );
                $ins->execute([$b['dealer_id'], $b['total'], $b['cnt']]);
                $pid = $ins->fetchColumn();

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare(
                    "UPDATE commissions SET status='paid',paid_at=NOW(),payout_id=? WHERE id IN ($placeholders)"
                )->execute(array_merge([$pid], $ids));

                $pdo->commit();
                $payout_count++;
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
        $success = "Payout run complete — {$payout_count} dealer payout(s) queued for ACH.";
    }
}

$pending_comms = $pdo->query(
    "SELECT c.id, c.amount_cents, c.created_at,
            d.full_name AS dealer_name, d.dealer_code,
            o.order_ref, o.client_name, o.product_line
     FROM commissions c
     JOIN dealers d ON d.id = c.dealer_id
     LEFT JOIN dealer_orders o ON o.id = c.order_id
     WHERE c.status = 'pending'
     ORDER BY c.created_at"
)->fetchAll();

$pending_payouts = $pdo->query(
    "SELECT p.*, d.full_name AS dealer_name, d.dealer_code,
            d.ach_routing, d.ach_account
     FROM dealer_payouts p
     JOIN dealers d ON d.id = p.dealer_id
     WHERE p.status IN ('pending','processing')
     ORDER BY p.created_at"
)->fetchAll();

$recent_sent = $pdo->query(
    "SELECT p.*, d.full_name AS dealer_name
     FROM dealer_payouts p
     JOIN dealers d ON d.id = p.dealer_id
     WHERE p.status = 'sent'
     ORDER BY p.sent_at DESC LIMIT 20"
)->fetchAll();

$approved_ready = $pdo->query(
    "SELECT COUNT(*) AS cnt, SUM(amount_cents) AS total,
            COUNT(DISTINCT dealer_id) AS dealer_count
     FROM commissions WHERE status='approved'"
)->fetch();

$product_labels = [
    'frontier_fiber'  => 'Frontier Fiber',
    'xfinity_prepaid' => 'Xfinity Prepaid',
    'verizon_prepaid' => 'Verizon Prepaid',
    'black_wireless'  => 'Black Wireless',
    'travelsim'       => 'TravelSim',
    'sling_tv'        => 'Sling TV',
];

$current_page = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payout Queue — Blue Mogul Admin</title>
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
    <div>
      <div class="topbar-title">Payout queue</div>
      <div class="topbar-sub">Approve commissions and run weekly ACH payouts</div>
    </div>
    <div class="topbar-right">
      <?php if ((int)$approved_ready['total'] > 0): ?>
      <form method="POST" onsubmit="return confirm('Run payout for all dealers with approved commissions?');">
        <input type="hidden" name="action" value="run_payout">
        <button type="submit" class="btn btn-primary">
          Run payout — $<?= dollars($approved_ready['total']) ?> to <?= $approved_ready['dealer_count'] ?> dealer<?= $approved_ready['dealer_count']!=1?'s':'' ?>
        </button>
      </form>
      <?php else: ?>
      <span class="btn btn-outline" style="cursor:default;color:var(--text-lt);">No payouts pending</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
      <div class="stat-card">
        <div class="stat-label">Commissions to review</div>
        <div class="stat-value" style="color:var(--amber);"><?= count($pending_comms) ?></div>
        <div class="stat-sub">Awaiting approval</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Ready for payout</div>
        <div class="stat-value" style="color:var(--green);">$<?= dollars($approved_ready['total'] ?? 0) ?></div>
        <div class="stat-sub"><?= $approved_ready['cnt'] ?> commission<?= $approved_ready['cnt']!=1?'s':'' ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Queued ACH</div>
        <div class="stat-value"><?= count($pending_payouts) ?></div>
        <div class="stat-sub">Payout<?= count($pending_payouts)!=1?'s':'' ?> in progress</div>
      </div>
    </div>

    <?php if (!empty($pending_comms)): ?>
    <div class="card" style="padding:0;margin-bottom:16px;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Pending commission review (<?= count($pending_comms) ?>)</span>
        <form method="POST">
          <input type="hidden" name="action" value="approve_all">
          <button type="submit" class="btn btn-primary btn-sm"
                  onclick="return confirm('Approve all <?= count($pending_comms) ?> pending commissions?');">
            Approve all
          </button>
        </form>
      </div>
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Dealer</th><th>Order ref</th>
            <th>Client</th><th>Product</th><th>Amount</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_comms as $c): ?>
          <tr>
            <td style="font-size:12px;"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
            <td>
              <div style="font-weight:500;font-size:13px;"><?= htmlspecialchars($c['dealer_name']) ?></div>
              <div style="font-size:11px;color:var(--text-lt);font-family:monospace;"><?= htmlspecialchars($c['dealer_code'] ?? '') ?></div>
            </td>
            <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($c['order_ref'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($c['client_name'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= $product_labels[$c['product_line'] ?? ''] ?? htmlspecialchars($c['product_line'] ?? '—') ?></td>
            <td style="font-weight:600;color:var(--amber);">$<?= dollars($c['amount_cents']) ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action"        value="approve_commission">
                <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($pending_payouts)): ?>
    <div class="card" style="padding:0;margin-bottom:16px;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);">
        <span class="card-title">ACH payouts in queue</span>
      </div>
      <table>
        <thead>
          <tr><th>Dealer</th><th>Bank (last 4)</th><th>Amount</th><th>Acts</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pending_payouts as $p): ?>
          <tr>
            <td>
              <div style="font-weight:500;"><?= htmlspecialchars($p['dealer_name']) ?></div>
              <div style="font-size:11px;color:var(--text-lt);"><?= date('M j, Y', strtotime($p['created_at'])) ?></div>
            </td>
            <td style="font-size:12px;color:var(--text-m);">
              <?= $p['ach_routing'] ? 'Routing •'.substr($p['ach_routing'],-4) : '<span style="color:var(--red);">No bank on file</span>' ?>
            </td>
            <td style="font-weight:700;font-size:15px;color:var(--green);">$<?= dollars($p['amount_cents']) ?></td>
            <td style="text-align:center;"><?= $p['commission_count'] ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action"    value="mark_paid">
                <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Mark sent</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($recent_sent)): ?>
    <div class="card" style="padding:0;">
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);">
        <span class="card-title">Recently sent</span>
      </div>
      <table>
        <thead>
          <tr><th>Dealer</th><th>Amount</th><th>Acts</th><th>Sent</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent_sent as $p): ?>
          <tr>
            <td style="font-weight:500;"><?= htmlspecialchars($p['dealer_name']) ?></td>
            <td style="font-weight:600;color:var(--teal);">$<?= dollars($p['amount_cents']) ?></td>
            <td style="text-align:center;"><?= $p['commission_count'] ?></td>
            <td style="font-size:12px;color:var(--text-lt);"><?= $p['sent_at'] ? date('M j, Y', strtotime($p['sent_at'])) : '—' ?></td>
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
