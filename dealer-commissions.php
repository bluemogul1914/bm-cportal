<?php
$page_title = 'Commissions';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$totals = $pdo->prepare(
  "SELECT
     COALESCE(SUM(amount_cents) FILTER (WHERE status='approved'), 0) AS approved,
     COALESCE(SUM(amount_cents) FILTER (WHERE status='paid'),     0) AS paid,
     COALESCE(SUM(amount_cents) FILTER (WHERE status='pending'),  0) AS pending,
     COUNT(*)                                                          AS total_count
   FROM commissions WHERE dealer_id=?"
);
$totals->execute([$dealer['id']]);
$t = $totals->fetch();

$filter  = in_array($_GET['status'] ?? '', ['pending','approved','paid','reversed']) ? $_GET['status'] : '';
$sql     = "SELECT c.id, c.amount_cents, c.status, c.approved_at, c.paid_at, c.created_at,
                   o.order_ref, o.client_name, o.product_line, o.plan_name, o.created_at AS order_date
            FROM commissions c
            JOIN dealer_orders o ON o.id = c.order_id
            WHERE c.dealer_id=?";
$params  = [$dealer['id']];
if ($filter) { $sql .= " AND c.status=?"; $params[] = $filter; }
$sql    .= " ORDER BY c.created_at DESC";
$stmt    = $pdo->prepare($sql);
$stmt->execute($params);
$commissions = $stmt->fetchAll();

$product_labels = [
    'frontier_fiber'  => 'Frontier Fiber',
    'xfinity_prepaid' => 'Xfinity Prepaid',
    'verizon_prepaid' => 'Verizon Prepaid',
    'black_wireless'  => 'Black Wireless',
    'travelsim'       => 'TravelSim / eSIM',
    'sling_tv'        => 'Sling TV',
];
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Commissions</div>
      <div class="topbar-sub">Earnings log for all your activations</div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier']) ?>
    </div>
  </div>

  <div class="page-body">

    <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
      <div class="stat-card">
        <div class="stat-label">Approved (available)</div>
        <div class="stat-value" style="color:var(--green);">$<?= dollars($t['approved']) ?></div>
        <div class="stat-sub">Ready for payout</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Pending review</div>
        <div class="stat-value" style="color:var(--amber);">$<?= dollars($t['pending']) ?></div>
        <div class="stat-sub">Awaiting activation confirm</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">All-time paid out</div>
        <div class="stat-value">$<?= dollars($t['paid']) ?></div>
        <div class="stat-sub"><?= $t['total_count'] ?> commissions total</div>
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:16px;">
      <?php
      $filters = ['' => 'All', 'approved' => 'Approved', 'pending' => 'Pending', 'paid' => 'Paid', 'reversed' => 'Reversed'];
      foreach ($filters as $val => $label):
        $active = $filter === $val ? 'btn-primary' : 'btn-outline';
      ?>
      <a href="?status=<?= $val ?>" class="btn btn-sm <?= $active ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title">
          <?= $filter ? ucfirst($filter) . ' commissions' : 'All commissions' ?>
        </span>
        <span style="font-size:12px;color:var(--text-lt);"><?= count($commissions) ?> records</span>
      </div>

      <?php if (empty($commissions)): ?>
        <p style="font-size:13px;color:var(--text-lt);padding:12px 0;">No commissions found<?= $filter ? " with status \"$filter\"" : '' ?>.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Order ref</th>
            <th>Client</th>
            <th>Product</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Approved</th>
            <th>Paid</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($commissions as $c): ?>
          <tr>
            <td style="font-family:monospace;font-size:11px;color:var(--text-lt);">
              <?= htmlspecialchars($c['order_ref'] ?? '—') ?>
            </td>
            <td>
              <div style="font-weight:500;font-size:13px;"><?= htmlspecialchars($c['client_name'] ?? '—') ?></div>
              <div style="font-size:11px;color:var(--text-lt);"><?= $c['order_date'] ? date('M j, Y', strtotime($c['order_date'])) : '—' ?></div>
            </td>
            <td style="font-size:12px;color:var(--text-m);">
              <?= $product_labels[$c['product_line']] ?? htmlspecialchars($c['product_line']) ?>
              <?php if ($c['plan_name']): ?>
                <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($c['plan_name']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-weight:600;font-size:14px;
              color:<?php
                if ($c['status'] === 'paid')     echo 'var(--teal)';
                elseif ($c['status'] === 'approved') echo 'var(--green)';
                else echo 'var(--text-lt)';
              ?>;">
              $<?= dollars($c['amount_cents']) ?>
            </td>
            <td><?= status_badge($c['status']) ?></td>
            <td style="font-size:12px;color:var(--text-lt);">
              <?= $c['approved_at'] ? date('M j', strtotime($c['approved_at'])) : '—' ?>
            </td>
            <td style="font-size:12px;color:var(--text-lt);">
              <?= $c['paid_at'] ? date('M j', strtotime($c['paid_at'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ((int)$t['approved'] > 0): ?>
    <div style="display:flex;justify-content:flex-end;">
      <a href="/portal/dealer-payouts.php" class="btn btn-primary">
        Request payout — $<?= dollars($t['approved']) ?> available
      </a>
    </div>
    <?php endif; ?>

  </div>

</div>
</body>
</html>
