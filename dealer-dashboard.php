<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$stats = $pdo->prepare(
  "SELECT
     COALESCE(SUM(c.amount_cents) FILTER (WHERE c.status='approved'),0) AS pending_cents,
     COALESCE(SUM(c.amount_cents) FILTER (WHERE c.status='paid'),0)     AS paid_cents,
     COUNT(*) FILTER (WHERE o.status='activated'
       AND o.activated_at >= date_trunc('month',NOW()))                  AS act_mtd,
     COUNT(*) FILTER (WHERE o.status='activated')                        AS act_total
   FROM dealer_orders o
   LEFT JOIN commissions c ON c.order_id = o.id
   WHERE o.dealer_id = ?"
);
$stats->execute([$dealer['id']]);
$s = $stats->fetch();

$avail = $pdo->prepare(
  "SELECT COALESCE(SUM(amount_cents),0) AS cents FROM commissions
   WHERE dealer_id=? AND status='approved'"
);
$avail->execute([$dealer['id']]);
$available = $avail->fetchColumn();

$recent = $pdo->prepare(
  "SELECT o.order_ref, o.client_name, o.product_line, o.status,
          o.created_at, c.amount_cents, c.status AS comm_status
   FROM dealer_orders o
   LEFT JOIN commissions c ON c.order_id=o.id
   WHERE o.dealer_id=?
   ORDER BY o.created_at DESC LIMIT 8"
);
$recent->execute([$dealer['id']]);
$orders = $recent->fetchAll();

$act_mtd   = (int)$s['act_mtd'];
$tier_next = $dealer['tier'] === 'gold' ? 10 : ($dealer['tier'] === 'silver' ? 10 : 5);
$tier_pct  = min(100, round(($act_mtd / max($tier_next,1)) * 100));
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Dashboard</div>
      <div class="topbar-sub">Welcome back, <?= htmlspecialchars(explode(' ', $dealer['full_name'])[0]) ?></div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier']) ?>
      <a href="/portal/dealer-orders.php" class="btn btn-primary btn-sm">+ New order</a>
    </div>
  </div>

  <div class="page-body">

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e6f1fb;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1a56a0" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <div class="stat-label">This month</div>
        <div class="stat-value">$<?= dollars((int)$s['pending_cents'] + (int)$s['paid_cents']) ?></div>
        <div class="stat-sub stat-up"><?= $act_mtd ?> activation<?= $act_mtd !== 1 ? 's' : '' ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e6f4ec;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#15893e" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        </div>
        <div class="stat-label">Available payout</div>
        <div class="stat-value">$<?= dollars($available) ?></div>
        <div class="stat-sub">Pays out next Friday</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3e2;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="stat-label">All-time paid</div>
        <div class="stat-value">$<?= dollars($s['paid_cents']) ?></div>
        <div class="stat-sub"><?= $s['act_total'] ?> total activations</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </div>
        <div class="stat-label">Tier</div>
        <div class="stat-value" style="font-size:18px;padding-top:4px;"><?= ucfirst($dealer['tier']) ?></div>
        <div class="stat-sub">
          <?php if ($dealer['tier'] !== 'gold'): ?>
            <?= $tier_next - $act_mtd ?> more for <?= $dealer['tier'] === 'base' ? 'Silver' : 'Gold' ?>
          <?php else: ?>
            Max tier — +20% spiff active
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="two-col">

      <div class="card">
        <div class="card-header">
          <span class="card-title">Recent orders</span>
          <a href="/portal/dealer-orders.php" class="btn btn-outline btn-sm">View all</a>
        </div>
        <?php if (empty($orders)): ?>
          <p style="font-size:13px;color:var(--text-lt);padding:12px 0;">No orders yet. <a href="/portal/dealer-orders.php" style="color:var(--blue);">Submit your first order</a>.</p>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Client</th><th>Product</th><th>Spiff</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
              <td>
                <div style="font-weight:500;"><?= htmlspecialchars($o['client_name'] ?? '—') ?></div>
                <div style="font-size:11px;color:var(--text-lt);"><?= date('M j', strtotime($o['created_at'])) ?></div>
              </td>
              <td style="font-size:12px;color:var(--text-m);"><?= htmlspecialchars(str_replace('_',' ', $o['product_line'])) ?></td>
              <td style="font-weight:600;color:<?= $o['comm_status'] === 'paid' ? 'var(--teal)' : 'var(--green)' ?>;">
                <?= $o['amount_cents'] ? '$' . dollars($o['amount_cents']) : '—' ?>
              </td>
              <td><?= status_badge($o['status']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div>
        <div class="card">
          <div class="card-header">
            <span class="card-title">Bonus tier progress</span>
            <span style="font-size:12px;color:var(--text-lt);"><?= $act_mtd ?> / <?= $tier_next ?> this month</span>
          </div>
          <div style="margin-bottom:14px;">
            <div class="progress-track">
              <div class="progress-fill" style="width:<?= $tier_pct ?>%;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;">
              <span style="font-size:11px;color:var(--text-lt);">Current: <?= ucfirst($dealer['tier']) ?></span>
              <?php if ($dealer['tier'] !== 'gold'): ?>
              <span style="font-size:11px;color:var(--text-lt);">Goal: <?= $dealer['tier'] === 'base' ? 'Silver (5)' : 'Gold (10)' ?></span>
              <?php else: ?>
              <span style="font-size:11px;color:var(--amber);">+20% active on all spiffs</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($dealer['tier'] !== 'gold'): ?>
          <div class="alert alert-info" style="margin-bottom:0;font-size:12px;">
            <?= $tier_next - $act_mtd ?> more activation<?= ($tier_next - $act_mtd) !== 1 ? 's' : '' ?> this month to reach <?= $dealer['tier'] === 'base' ? 'Silver' : 'Gold' ?> and unlock the +20% spiff bonus.
          </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-title" style="margin-bottom:12px;">Quick links</div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <a href="/portal/dealer-orders.php" class="btn btn-primary" style="justify-content:center;">Submit a new order</a>
            <a href="/portal/dealer-payouts.php" class="btn btn-outline" style="justify-content:center;">Request early payout</a>
            <a href="/portal/dealer-spiffs.php" class="btn btn-outline" style="justify-content:center;">View current spiff rates</a>
          </div>
        </div>
      </div>

    </div>
  </div>

</div>
</body>
</html>
