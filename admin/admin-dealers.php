<?php
/**
 * admin/admin-dealers.php
 * Blue Mogul portal admin — Dealer management list
 * Served at /portal/admin/admin-dealers.php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_dealer') {
    $id     = (int)$_POST['dealer_id'];
    $status = in_array($_POST['status'], ['pending','active','suspended']) ? $_POST['status'] : 'pending';
    $tier   = in_array($_POST['tier'],   ['base','silver','gold'])         ? $_POST['tier']   : 'base';
    $pdo->prepare("UPDATE dealers SET status=?,tier=?,updated_at=NOW() WHERE id=?")->execute([$status,$tier,$id]);
    $success = "Dealer #{$id} updated.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_commission') {
    $cid = (int)$_POST['commission_id'];
    $pdo->prepare("UPDATE commissions SET status='approved',approved_at=NOW() WHERE id=? AND status='pending'")->execute([$cid]);
    $success = "Commission approved.";
}

$search   = trim($_GET['q']      ?? '');
$filter   = in_array($_GET['status'] ?? '', ['pending','active','suspended']) ? $_GET['status'] : '';
$sql_w    = "WHERE 1=1";
$params   = [];
if ($search) {
    $sql_w   .= " AND (d.full_name ILIKE ? OR d.email ILIKE ? OR d.dealer_code ILIKE ? OR d.company ILIKE ?)";
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
if ($filter) {
    $sql_w  .= " AND d.status=?";
    $params[] = $filter;
}

$dealers = $pdo->prepare(
    "SELECT d.*,
            COUNT(DISTINCT o.id)                                             AS order_count,
            COALESCE(SUM(c.amount_cents) FILTER (WHERE c.status='approved'),0) AS pending_pay,
            COALESCE(SUM(c.amount_cents) FILTER (WHERE c.status='paid'),0)     AS paid_total
     FROM dealers d
     LEFT JOIN dealer_orders  o ON o.dealer_id = d.id
     LEFT JOIN commissions c    ON c.dealer_id = d.id
     $sql_w
     GROUP BY d.id
     ORDER BY d.created_at DESC"
);
$dealers->execute($params);
$rows = $dealers->fetchAll();

$pending_comm_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM commissions WHERE status='pending'"
)->fetchColumn();

$current_page = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="/portal/">
  <title>Dealer Management — Blue Mogul Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/css/tailwind.css">
  <style>
    <?php include __DIR__ . '/../includes/admin-styles.css.php'; ?>
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>

<div class="main">

  <div class="topbar">
    <div>
      <div class="topbar-title">Dealer management</div>
      <div class="topbar-sub">All registered dealer / ISO partners</div>
    </div>
    <div class="topbar-right">
      <a href="/portal/dealer-register.php" target="_blank" class="btn btn-outline btn-sm">+ Invite dealer</a>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div style="display:flex;gap:10px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
      <form method="GET" action="/portal/admin/admin-dealers.php" style="display:flex;gap:8px;flex:1;min-width:200px;">
        <input type="text" name="q" class="form-control" placeholder="Search name, email, code…"
               value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
        <?php if ($filter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"><br><?php endif; ?>
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
      </form>
      <div style="display:flex;gap:6px;">
        <?php foreach (['' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended'] as $v => $l): ?>
        <a href="?status=<?= $v ?><?= $search ? '&q='.urlencode($search) : '' ?>"
           class="btn btn-sm <?= $filter === $v ? 'btn-primary' : 'btn-outline' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card" style="padding:0;">
      <table>
        <thead>
          <tr>
            <th style="padding:10px 16px;">Dealer</th>
            <th>Code</th>
            <th>Tier</th>
            <th>Status</th>
            <th>Orders</th>
            <th>Pending $</th>
            <th>Paid out</th>
            <th style="padding:10px 16px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-lt);">No dealers found.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $d): ?>
          <tr>
            <td style="padding:10px 16px;">
              <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($d['full_name'] ?? '—') ?></div>
              <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($d['email'] ?? '') ?></div>
              <?php if ($d['company']): ?>
              <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($d['company']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:12px;color:var(--text-m);"><?= htmlspecialchars($d['dealer_code'] ?? '—') ?></td>
            <td><?= tier_badge($d['tier'] ?? 'base') ?></td>
            <td><?= status_badge($d['status'] ?? 'pending') ?></td>
            <td style="font-size:13px;text-align:center;"><?= $d['order_count'] ?></td>
            <td style="font-weight:600;color:var(--amber);">$<?= dollars($d['pending_pay']) ?></td>
            <td style="color:var(--teal);">$<?= dollars($d['paid_total']) ?></td>
            <td style="padding:10px 16px;">
              <div style="display:flex;gap:6px;">
                <a href="/portal/admin/admin-dealer-detail.php?id=<?= $d['id'] ?>"
                   class="btn btn-outline btn-sm">Detail</a>
                <?php if ($d['status'] === 'pending'): ?>
                <form method="POST" action="/portal/admin/admin-dealers.php" style="display:inline;">
                  <input type="hidden" name="action"    value="update_dealer">
                  <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                  <input type="hidden" name="status"    value="active">
                  <input type="hidden" name="tier"      value="<?= $d['tier'] ?? 'base' ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

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
