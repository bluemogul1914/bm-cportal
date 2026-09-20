<?php
$page_title = 'Commissions';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$totals = $pdo->prepare(
  "SELECT
     COALESCE(SUM(amount) FILTER (WHERE status='approved'), 0) AS approved,
     COALESCE(SUM(amount) FILTER (WHERE status='paid'),     0) AS paid,
     COALESCE(SUM(amount) FILTER (WHERE status='pending'),  0) AS pending,
     COUNT(*)                                                          AS total_count
   FROM commissions WHERE dealer_id=?"
);
$totals->execute([$dealer['id']]);
$t = $totals->fetch();

$filter  = in_array($_GET['status'] ?? '', ['pending','approved','paid','reversed']) ? $_GET['status'] : '';
$sql     = "SELECT c.id, c.amount, c.status, c.approved_at, c.paid_at, c.created_at,
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

<?php
// ── Sales team performance (P4) ──────────────────────────────────────────────
// The DEALER pays reps out of their own payout, so these are reporting figures.
// Attribution is read from the order (who sold it) joined to its commission row.
$__did = (int)($_SESSION['dealer_id'] ?? 0);
if (!$__did) {
    $__q = $pdo->prepare("SELECT id FROM dealers WHERE user_id = ? LIMIT 1");
    $__q->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $__did = (int)$__q->fetchColumn();
}
$team_perf = [];
if ($__did) {
    $__p = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(o.sales_name), ''), '(unassigned)') AS rep,
               o.sales_user_id,
               COUNT(DISTINCT o.id) AS orders,
               COALESCE(SUM(c.amount) FILTER (WHERE c.status = 'pending'),  0) AS pending,
               COALESCE(SUM(c.amount) FILTER (WHERE c.status = 'approved'), 0) AS approved,
               COALESCE(SUM(c.amount) FILTER (WHERE c.status = 'paid'),     0) AS paid,
               COALESCE(SUM(c.amount), 0) AS total
          FROM dealer_orders o
          LEFT JOIN dealer_commissions c ON c.order_id = o.id
         WHERE o.dealer_id = ?
         GROUP BY 1, 2
         ORDER BY total DESC, orders DESC");
    $__p->execute([$__did]);
    $team_perf = $__p->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="card" data-testid="card-team-performance">
  <div class="card-header">
    <span class="card-title">Sales team performance</span>
    <span style="font-size:12px;color:var(--text-lt);"><?= count($team_perf) ?> rep<?= count($team_perf) === 1 ? '' : 's' ?> with sales</span>
  </div>
  <?php if (empty($team_perf)): ?>
    <p style="font-size:13px;color:var(--text-lt);padding:12px 0;">No sales attributed yet. Pick a salesperson on the order form and their numbers appear here.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Salesperson</th><th>Orders</th><th>Pending</th><th>Approved</th><th>Paid</th><th>Total</th></tr>
    </thead>
    <tbody>
      <?php foreach ($team_perf as $r): ?>
      <tr>
        <td style="font-weight:500;font-size:13px;">
          <?= htmlspecialchars($r['rep']) ?>
          <?php if (empty($r['sales_user_id'])): ?><div style="font-size:11px;color:var(--text-lt);">named only</div><?php endif; ?>
        </td>
        <td><?= (int)$r['orders'] ?></td>
        <td style="color:var(--amber);">$<?= dollars($r['pending']) ?></td>
        <td style="color:var(--green);">$<?= dollars($r['approved']) ?></td>
        <td style="color:var(--teal);">$<?= dollars($r['paid']) ?></td>
        <td style="font-weight:600;">$<?= dollars($r['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="font-size:11px;color:var(--text-lt);padding:10px 0 0;">You pay your reps out of your own payout — these figures are reporting, not separate payments.</p>
  <?php endif; ?>
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
              <div style="font-size:11px;color:var(--text-lt);"><?= dealer_fmt_date($c['order_date'] ?? null) ?></div>
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
              $<?= dollars($c['amount']) ?>
            </td>
            <td><?= status_badge($c['status']) ?></td>
            <td style="font-size:12px;color:var(--text-lt);">
              <?= dealer_fmt_date($c['approved_at'] ?? null, 'M j') ?>
            </td>
            <td style="font-size:12px;color:var(--text-lt);">
              <?= dealer_fmt_date($c['paid_at'] ?? null, 'M j') ?>
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
