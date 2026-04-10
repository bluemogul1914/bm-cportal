<?php
/**
 * admin/admin-dealers.php
 * Blue Mogul bm-cportal — Admin Dealer Management
 * Requires existing admin session (role = 'admin')
 */

$page_title = 'Dealer Management';
require_once __DIR__ . '/../includes/dealer-header.php'; // for get_db(), dollars(), badges

// Admin auth — reuse portal's existing admin check
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /portal/login.php');
    exit;
}

$pdo = get_db();

// ── Handle quick actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']    ?? '';
    $dealer_id = (int)($_POST['dealer_id'] ?? 0);

    if ($dealer_id) {
        switch ($action) {
            case 'approve':
                $pdo->prepare("UPDATE dealers SET status='active', updated_at=NOW() WHERE id=?")
                    ->execute([$dealer_id]);
                // TODO: trigger HERALD email notification via N8N webhook
                break;
            case 'suspend':
                $pdo->prepare("UPDATE dealers SET status='suspended', updated_at=NOW() WHERE id=?")
                    ->execute([$dealer_id]);
                break;
            case 'reactivate':
                $pdo->prepare("UPDATE dealers SET status='active', updated_at=NOW() WHERE id=?")
                    ->execute([$dealer_id]);
                break;
        }
    }
    header('Location: /portal/admin/admin-dealers.php?msg=' . urlencode("Dealer updated."));
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$status_filter = in_array($_GET['status'] ?? '', ['pending','active','suspended']) ? $_GET['status'] : '';
$search        = trim($_GET['q'] ?? '');

$where  = 'WHERE 1=1';
$params = [];
if ($status_filter) { $where .= ' AND d.status=?'; $params[] = $status_filter; }
if ($search) {
    $where .= ' AND (d.full_name ILIKE ? OR d.email ILIKE ? OR d.company ILIKE ? OR d.dealer_code ILIKE ?)';
    $like   = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

// ── Summary stats ─────────────────────────────────────────────
$sum = $pdo->query(
    "SELECT
       COUNT(*) FILTER (WHERE status='pending')   AS pending_cnt,
       COUNT(*) FILTER (WHERE status='active')    AS active_cnt,
       COUNT(*) FILTER (WHERE status='suspended') AS suspended_cnt,
       COUNT(*) AS total_cnt
     FROM dealers"
)->fetch();

// ── Dealer list with commission totals ────────────────────────
$stmt = $pdo->prepare(
    "SELECT d.id, d.dealer_code, d.full_name, d.email, d.company,
            d.status, d.tier, d.activations_mtd, d.created_at,
            COALESCE(ord.order_count, 0)   AS order_count,
            COALESCE(ord.activated, 0)     AS activations_total,
            COALESCE(comm.earned_cents, 0) AS earned_cents,
            COALESCE(comm.pending_cents,0) AS pending_payout_cents
     FROM dealers d
     LEFT JOIN (
       SELECT dealer_id,
              COUNT(*) AS order_count,
              COUNT(*) FILTER (WHERE status='activated') AS activated
       FROM dealer_orders GROUP BY dealer_id
     ) ord ON ord.dealer_id = d.id
     LEFT JOIN (
       SELECT dealer_id,
              SUM(amount_cents) FILTER (WHERE status IN ('approved','paid')) AS earned_cents,
              SUM(amount_cents) FILTER (WHERE status='approved')             AS pending_cents
       FROM commissions GROUP BY dealer_id
     ) comm ON comm.dealer_id = d.id
     $where
     ORDER BY
       CASE d.status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
       d.created_at DESC"
);
$stmt->execute($params);
$dealers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dealer Management — Blue Mogul Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    <?php include __DIR__ . '/../includes/admin-styles.css.php'; // your existing shared admin CSS ?>
    /* Dealer-specific additions */
    .pending-badge{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;background:#dc2626;color:#fff;font-size:10px;font-weight:700;border-radius:50%;margin-left:6px;line-height:1;}
    .dealer-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;}
    .action-group{display:flex;gap:6px;}
    .filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap;}
    .search-input{padding:7px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font);font-size:13px;color:var(--text);width:240px;outline:none;}
    .search-input:focus{border-color:var(--blue);}
  </style>
</head>
<body>
<?php
// Render existing admin sidebar — just add dealer nav items
// (See admin-sidebar-snippet.php for the lines to add to your existing sidebar include)
include __DIR__ . '/../includes/admin-sidebar.php';
?>

<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="topbar-title">Dealer management</div>
      <div class="topbar-sub">ISO / dealer program oversight</div>
    </div>
    <div class="topbar-right">
      <?php if ($sum['pending_cnt'] > 0): ?>
      <span style="font-size:13px;color:#dc2626;font-weight:500;">
        <?= $sum['pending_cnt'] ?> pending approval<?= $sum['pending_cnt'] != 1 ? 's' : '' ?>
      </span>
      <?php endif; ?>
      <a href="/portal/dealer-register.php" target="_blank" class="btn btn-outline btn-sm">View signup page</a>
    </div>
  </div>

  <div class="page-body">

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Active dealers</div>
        <div class="stat-value" style="color:var(--green);"><?= $sum['active_cnt'] ?></div>
        <div class="stat-sub">Approved &amp; selling</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Pending approval</div>
        <div class="stat-value" style="color:<?= $sum['pending_cnt'] > 0 ? '#dc2626' : 'var(--text-lt)' ?>;">
          <?= $sum['pending_cnt'] ?>
        </div>
        <div class="stat-sub">Awaiting review</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Suspended</div>
        <div class="stat-value" style="color:var(--text-lt);"><?= $sum['suspended_cnt'] ?></div>
        <div class="stat-sub">Total: <?= $sum['total_cnt'] ?></div>
      </div>
      <div class="stat-card">
        <a href="/portal/admin/admin-dealer-payouts.php" style="text-decoration:none;">
          <div class="stat-label">Pending payouts</div>
          <?php
          $ppay = $pdo->query(
              "SELECT COALESCE(SUM(amount_cents),0) AS c FROM commissions WHERE status='approved'"
          )->fetchColumn();
          ?>
          <div class="stat-value" style="color:var(--amber);">$<?= dollars($ppay) ?></div>
          <div class="stat-sub" style="color:var(--blue);">Run payout batch →</div>
        </a>
      </div>
    </div>

    <!-- FILTER BAR -->
    <form method="GET" class="filter-bar">
      <input type="text" name="q" class="search-input"
             placeholder="Search name, email, company, code..."
             value="<?= htmlspecialchars($search) ?>">
      <?php
      $filters = ['' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended'];
      foreach ($filters as $val => $label):
        $active = $status_filter === $val ? 'btn-primary' : 'btn-outline';
        $badge  = $val === 'pending' && $sum['pending_cnt'] > 0
          ? '<span class="pending-badge">'.$sum['pending_cnt'].'</span>' : '';
      ?>
      <button type="submit" name="status" value="<?= $val ?>"
              class="btn btn-sm <?= $active ?>">
        <?= $label ?><?= $badge ?>
      </button>
      <?php endforeach; ?>
    </form>

    <!-- DEALER TABLE -->
    <div class="card" style="padding:0;">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead>
          <tr style="background:var(--bg);">
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Dealer</th>
            <th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Tier</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Orders</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Earned</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Pending out</th>
            <th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Status</th>
            <th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;">Joined</th>
            <th style="padding:10px 16px;border-bottom:1px solid var(--border);"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($dealers)): ?>
          <tr>
            <td colspan="8" style="padding:24px 16px;text-align:center;color:var(--text-lt);font-size:13px;">
              No dealers found<?= $search ? " matching \"$search\"" : '' ?>.
            </td>
          </tr>
          <?php endif; ?>
          <?php foreach ($dealers as $d):
            $initials = strtoupper(substr($d['full_name'],0,1)) .
                        (strpos($d['full_name'],' ') !== false
                          ? strtoupper(substr(strrchr($d['full_name'],' '),1,1)) : '');
            $av_colors = ['pending'=>['#fef3e2','#92400e'],'active'=>['#e6f1fb','#1a56a0'],'suspended'=>['#f1f5f9','#64748b']];
            [$av_bg, $av_tx] = $av_colors[$d['status']] ?? ['#f1f5f9','#64748b'];
          ?>
          <tr style="<?= $d['status']==='pending' ? 'background:#fffbeb;' : '' ?>"
              onmouseover="this.style.background='#fafbfd'"
              onmouseout="this.style.background='<?= $d['status']==='pending' ? '#fffbeb' : '' ?>'">
            <td style="padding:12px 16px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="dealer-avatar" style="background:<?= $av_bg ?>;color:<?= $av_tx ?>;"><?= $initials ?></div>
                <div>
                  <div style="font-weight:500;">
                    <a href="/portal/admin/admin-dealer-detail.php?id=<?= $d['id'] ?>"
                       style="color:var(--text);text-decoration:none;">
                      <?= htmlspecialchars($d['full_name']) ?>
                    </a>
                  </div>
                  <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($d['email']) ?></div>
                  <?php if ($d['company']): ?>
                  <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($d['company']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="padding:10px 12px;"><?= tier_badge($d['tier']) ?></td>
            <td style="padding:10px 12px;text-align:right;font-size:13px;">
              <?= $d['activations_total'] ?> / <?= $d['order_count'] ?>
              <div style="font-size:10px;color:var(--text-lt);">activated/total</div>
            </td>
            <td style="padding:10px 12px;text-align:right;font-weight:600;color:var(--green);">
              $<?= dollars($d['earned_cents']) ?>
            </td>
            <td style="padding:10px 12px;text-align:right;font-weight:500;color:<?= $d['pending_payout_cents']>0?'var(--amber)':'var(--text-lt)' ?>;">
              $<?= dollars($d['pending_payout_cents']) ?>
            </td>
            <td style="padding:10px 12px;"><?= status_badge($d['status']) ?></td>
            <td style="padding:10px 12px;font-size:12px;color:var(--text-lt);">
              <?= date('M j, Y', strtotime($d['created_at'])) ?>
            </td>
            <td style="padding:10px 16px;">
              <div class="action-group">
                <a href="/portal/admin/admin-dealer-detail.php?id=<?= $d['id'] ?>"
                   class="btn btn-outline btn-sm">View</a>
                <?php if ($d['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                  <button type="submit" name="action" value="approve"
                          class="btn btn-sm" style="background:var(--green-bg);color:var(--green-text);border:1px solid #86efac;">
                    Approve
                  </button>
                </form>
                <?php elseif ($d['status'] === 'active'): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Suspend <?= htmlspecialchars($d['full_name']) ?>?')">
                  <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                  <button type="submit" name="action" value="suspend"
                          class="btn btn-sm" style="background:var(--red-bg);color:var(--red-text);border:1px solid #fca5a5;">
                    Suspend
                  </button>
                </form>
                <?php elseif ($d['status'] === 'suspended'): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                  <button type="submit" name="action" value="reactivate"
                          class="btn btn-sm btn-outline">Reactivate</button>
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

</body>
</html>
