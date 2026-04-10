<?php
$page_title = 'Products & Spiffs';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$spiffs = $pdo->query(
    "SELECT product_line, tier, spiff_cents
     FROM spiff_schedule
     WHERE active=TRUE
     ORDER BY product_line, CASE tier WHEN 'base' THEN 1 WHEN 'silver' THEN 2 WHEN 'gold' THEN 3 END"
)->fetchAll();

$product_labels = [
    'frontier_fiber'  => 'Frontier Fiber',
    'xfinity_prepaid' => 'Xfinity Prepaid Internet',
    'verizon_prepaid' => 'Verizon Prepaid Wireless',
    'black_wireless'  => 'Black Wireless',
    'travelsim'       => 'TravelSim / eSIM',
    'sling_tv'        => 'Sling TV',
];

$by_product = [];
foreach ($spiffs as $s) {
    $by_product[$s['product_line']][$s['tier']] = $s['spiff_cents'];
}
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Products &amp; spiff rates</div>
      <div class="topbar-sub">Current commission schedule for all products</div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier']) ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($dealer['tier'] !== 'gold'): ?>
    <div class="alert alert-info" style="margin-bottom:20px;">
      You are on the <strong><?= ucfirst($dealer['tier']) ?></strong> tier.
      Reach <strong>Gold</strong> (10 activations/month) to unlock +20% spiff on all products.
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
      You are on the <strong>Gold</strong> tier — +20% spiff bonus is active on all products!
    </div>
    <?php endif; ?>

    <div class="card" style="padding:0;">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
        <span class="card-title">Spiff schedule</span>
      </div>
      <table>
        <thead>
          <tr>
            <th style="padding:10px 20px;">Product</th>
            <th style="text-align:center;">Base tier</th>
            <th style="text-align:center;">Silver tier</th>
            <th style="text-align:center;">Gold tier <span style="font-size:10px;color:var(--amber);">(+20%)</span></th>
            <th style="text-align:center;">Your rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($product_labels as $key => $label):
            $base   = $by_product[$key]['base']   ?? 0;
            $silver = $by_product[$key]['silver']  ?? $base;
            $gold   = $by_product[$key]['gold']    ?? $base;
            $yours  = $by_product[$key][$dealer['tier']] ?? $base;
          ?>
          <tr>
            <td style="padding:12px 20px;font-weight:500;"><?= $label ?></td>
            <td style="text-align:center;color:var(--text-m);">$<?= dollars($base) ?></td>
            <td style="text-align:center;color:var(--text-m);">$<?= dollars($silver) ?></td>
            <td style="text-align:center;color:var(--amber);font-weight:500;">$<?= dollars($gold) ?></td>
            <td style="text-align:center;font-weight:700;font-size:15px;color:var(--green);">$<?= dollars($yours) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="two-col" style="margin-top:16px;">
      <div class="card">
        <div class="card-title" style="margin-bottom:12px;">Tier requirements</div>
        <table>
          <thead><tr><th>Tier</th><th>Activations / month</th><th>Bonus</th></tr></thead>
          <tbody>
            <tr><td><?= tier_badge('base') ?></td><td>0–4</td><td style="color:var(--text-lt);">Standard rates</td></tr>
            <tr><td><?= tier_badge('silver') ?></td><td>5–9</td><td style="color:var(--text-lt);">Standard rates</td></tr>
            <tr><td><?= tier_badge('gold') ?></td><td>10+</td><td style="color:var(--amber);font-weight:600;">+20% on all spiffs</td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="card-title" style="margin-bottom:12px;">How spiffs work</div>
        <ul style="list-style:none;padding:0;font-size:13px;color:var(--text-m);line-height:2;">
          <li>✅ Spiff locked in at order submission (at your current tier)</li>
          <li>✅ Commission approved within 24 hrs of confirmed activation</li>
          <li>✅ ACH payout every Friday at 9 AM CT</li>
          <li>✅ Request early payout anytime from the Payouts page</li>
          <li>✅ Tier resets monthly on the 1st</li>
        </ul>
        <div style="margin-top:12px;">
          <a href="/portal/dealer-orders.php" class="btn btn-primary btn-sm">Submit an order now</a>
        </div>
      </div>
    </div>

  </div>

</div>
</body>
</html>
