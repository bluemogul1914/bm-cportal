<?php
/**
 * admin/admin-dealer-payouts.php
 * Payout queue management — run weekly batch, mark individual payouts as sent,
 * view all outstanding commissions by dealer
 */

$page_title = 'Dealer Payouts';
require_once __DIR__ . '/../includes/dealer-header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /portal/login.php'); exit;
}

$pdo     = get_db();
$success = $error = '';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_batch') {
        // Create payout records for all dealers with approved commissions
        try {
            $pdo->beginTransaction();

            $dealers = $pdo->query(
                "SELECT dealer_id, SUM(amount_cents) AS total, COUNT(*) AS cnt
                 FROM commissions WHERE status='approved'
                 GROUP BY dealer_id"
            )->fetchAll();

            $count = 0;
            $total_cents = 0;
            foreach ($dealers as $row) {
                $payout = $pdo->prepare(
                    "INSERT INTO dealer_payouts
                       (dealer_id,amount_cents,commission_count,status,initiated_at)
                     VALUES (?,?,?,'processing',NOW()) RETURNING id"
                );
                $payout->execute([$row['dealer_id'],$row['total'],$row['cnt']]);
                $payout_id = $payout->fetchColumn();

                $pdo->prepare(
                    "UPDATE commissions SET status='paid', paid_at=NOW(), payout_id=?
                     WHERE dealer_id=? AND status='approved'"
                )->execute([$payout_id, $row['dealer_id']]);

                $count++;
                $total_cents += $row['total'];
            }

            $pdo->commit();
            $success = "Payout batch initiated — $count dealer" . ($count!=1?'s':'') .
                       ", total $" . number_format($total_cents/100,2) . ". Mark each as Sent once ACH confirms.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Batch failed: ' . $e->getMessage();
        }
    }

    if ($action === 'mark_sent') {
        $payout_id = (int)($_POST['payout_id'] ?? 0);
        $ach_ref   = trim($_POST['ach_ref'] ?? '');
        $pdo->prepare(
            "UPDATE dealer_payouts SET status='sent', sent_at=NOW(), ach_batch_ref=? WHERE id=?"
        )->execute([$ach_ref ?: null, $payout_id]);
        $success = 'Payout #' . $payout_id . ' marked as sent.';
    }

    if ($action === 'mark_failed') {
        $payout_id = (int)($_POST['payout_id'] ?? 0);
        $note      = trim($_POST['note'] ?? '');
        $pdo->prepare(
            "UPDATE dealer_payouts SET status='failed', notes=? WHERE id=?"
        )->execute([$note ?: 'Marked failed by admin', $payout_id]);
        // Revert commissions to 'approved' so they're included in next batch
        $pdo->prepare(
            "UPDATE commissions SET status='approved', paid_at=NULL, payout_id=NULL
             WHERE payout_id=?"
        )->execute([$payout_id]);
        $success = 'Payout #' . $payout_id . ' marked failed — commissions returned to queue.';
    }

    header('Location: /portal/admin/admin-dealer-payouts.php?msg=' . urlencode($success ?: $error));
    exit;
}

if (isset($_GET['msg'])) $success = $_GET['msg'];

// ── Summary ───────────────────────────────────────────────────
$summary = $pdo->query(
    "SELECT
       COALESCE(SUM(amount_cents) FILTER (WHERE status='approved'),0) AS queued_cents,
       COUNT(*) FILTER (WHERE status='approved')                       AS queued_dealers,
       COALESCE(SUM(amount_cents) FILTER (WHERE status='processing'),0) AS processing_cents,
       COUNT(*) FILTER (WHERE status='processing')                      AS processing_cnt,
       COALESCE(SUM(amount_cents) FILTER (WHERE status='sent'),0)      AS sent_cents_total
     FROM (
       SELECT d.dealer_id, d.status,
              SUM(c.amount_cents) AS amount_cents
       FROM dealer_payouts d
       LEFT JOIN commissions c ON c.payout_id=d.id
       GROUP BY d.dealer_id, d.status
     ) agg"
)->fetch();

// ── Pending payout queue (approved commissions not yet in a payout) ───
$queue = $pdo->query(
    "SELECT d.id AS dealer_id, d.full_name, d.email, d.dealer_code, d.tier,
            COUNT(c.id) AS comm_count,
            SUM(c.amount_cents) AS total_cents
     FROM commissions c
     JOIN dealers d ON d.id=c.dealer_id
     WHERE c.status='approved'
     GROUP BY d.id, d.full_name, d.email, d.dealer_code, d.tier
     ORDER BY total_cents DESC"
)->fetchAll();

// ── Processing payouts (initiated but not yet confirmed sent) ─
$processing = $pdo->query(
    "SELECT p.id, p.dealer_id, p.amount_cents, p.commission_count,
            p.initiated_at, p.status, d.full_name, d.email, d.ach_routing, d.ach_account
     FROM dealer_payouts p
     JOIN dealers d ON d.id=p.dealer_id
     WHERE p.status='processing'
     ORDER BY p.initiated_at DESC"
)->fetchAll();

// ── Recent payout history (sent/failed, last 30) ──────────────
$history = $pdo->query(
    "SELECT p.id, p.dealer_id, p.amount_cents, p.commission_count,
            p.status, p.ach_batch_ref, p.sent_at, p.created_at, d.full_name
     FROM dealer_payouts p
     JOIN dealers d ON d.id=p.dealer_id
     WHERE p.status IN ('sent','failed')
     ORDER BY p.created_at DESC LIMIT 30"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dealer Payouts — Blue Mogul Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    <?php include __DIR__ . '/../includes/admin-styles.css.php'; ?>
    .mark-sent-form{display:inline-flex;align-items:center;gap:6px;}
    .ach-input{padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius);font-size:12px;font-family:var(--font);width:160px;color:var(--text);}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>

<div class="main">

  <div class="topbar">
    <div>
      <div class="topbar-title">Dealer payouts</div>
      <div class="topbar-sub">Commission payout queue &amp; ACH batch management</div>
    </div>
    <div class="topbar-right">
      <?php if (!empty($queue)): ?>
      <form method="POST"
            onsubmit="return confirm('Run payout batch for <?= count($queue) ?> dealer(s) totaling $<?= number_format(array_sum(array_column($queue,'total_cents'))/100,2) ?>?')">
        <input type="hidden" name="action" value="run_batch">
        <button type="submit" class="btn btn-primary">
          Run payout batch — $<?= number_format(array_sum(array_column($queue,'total_cents'))/100,2) ?>
        </button>
      </form>
      <?php else: ?>
      <span style="font-size:13px;color:var(--text-lt);">No commissions queued</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- SUMMARY STATS -->
    <div class="stat-grid" style="margin-bottom:22px;">
      <div class="stat-card">
        <div class="stat-label">Queued to pay</div>
        <div class="stat-value" style="color:var(--amber);">
          $<?= number_format(array_sum(array_column($queue,'total_cents'))/100,2) ?>
        </div>
        <div class="stat-sub"><?= count($queue) ?> dealer<?= count($queue)!=1?'s':'' ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Processing (ACH pending)</div>
        <div class="stat-value" style="color:var(--blue);">
          $<?= dollars($summary['processing_cents']) ?>
        </div>
        <div class="stat-sub"><?= $summary['processing_cnt'] ?> payout<?= $summary['processing_cnt']!=1?'s':'' ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total paid out (all time)</div>
        <div class="stat-value" style="color:var(--teal);">
          $<?= dollars($summary['sent_cents_total']) ?>
        </div>
        <div class="stat-sub"><?= count($history) ?> transactions shown</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Next auto-batch</div>
        <div class="stat-value" style="font-size:16px;padding-top:4px;">Friday</div>
        <div class="stat-sub">9:00 AM CT via N8N</div>
      </div>
    </div>

    <!-- SECTION 1: QUEUED COMMISSIONS -->
    <?php if (!empty($queue)): ?>
    <div class="card" style="margin-bottom:18px;padding:0;">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
        <span class="card-title">Ready to pay out</span>
        <span style="font-size:12px;color:var(--text-lt);"><?= count($queue) ?> dealer<?= count($queue)!=1?'s':'' ?></span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead style="background:var(--bg);">
          <tr>
            <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Dealer</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Tier</th>
            <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Commissions</th>
            <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Amount</th>
            <th style="padding:9px 14px;border-bottom:1px solid var(--border);"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($queue as $q): ?>
          <tr onmouseover="this.style.background='#fafbfd'" onmouseout="this.style.background=''">
            <td style="padding:11px 14px;">
              <div style="font-weight:500;"><?= htmlspecialchars($q['full_name']) ?></div>
              <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars($q['email']) ?></div>
            </td>
            <td style="padding:11px 12px;"><?= tier_badge($q['tier']) ?></td>
            <td style="padding:11px 12px;text-align:right;font-size:13px;"><?= $q['comm_count'] ?></td>
            <td style="padding:11px 12px;text-align:right;font-weight:700;font-size:15px;color:var(--green);">
              $<?= dollars($q['total_cents']) ?>
            </td>
            <td style="padding:11px 14px;">
              <a href="/portal/admin/admin-dealer-detail.php?id=<?= $q['dealer_id'] ?>"
                 class="btn btn-outline btn-sm">View dealer</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- SECTION 2: PROCESSING (need mark-sent) -->
    <?php if (!empty($processing)): ?>
    <div class="card" style="margin-bottom:18px;padding:0;">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
        <span class="card-title">Processing — awaiting ACH confirmation</span>
        <div style="font-size:12px;color:var(--text-lt);margin-top:3px;">Enter ACH reference and mark sent once your bank confirms.</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead style="background:var(--bg);">
          <tr>
            <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">#</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Dealer</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Bank</th>
            <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Amount</th>
            <th style="padding:9px 14px;border-bottom:1px solid var(--border);"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($processing as $p): ?>
          <tr onmouseover="this.style.background='#fafbfd'" onmouseout="this.style.background=''">
            <td style="padding:10px 14px;font-family:monospace;font-size:11px;color:var(--text-lt);">#<?= $p['id'] ?></td>
            <td style="padding:10px 12px;">
              <div style="font-weight:500;"><?= htmlspecialchars($p['full_name']) ?></div>
              <div style="font-size:11px;color:var(--text-lt);">
                <?= $p['ach_routing'] ? 'Acct …'.substr($p['ach_account']??'????',-4) : 'No bank on file' ?>
              </div>
            </td>
            <td style="padding:10px 12px;font-size:12px;color:var(--text-m);">
              <?= $p['ach_routing'] ? 'Routing …'.substr($p['ach_routing'],-4) : '—' ?>
            </td>
            <td style="padding:10px 12px;text-align:right;font-weight:700;font-size:15px;color:var(--blue);">
              $<?= dollars($p['amount_cents']) ?>
            </td>
            <td style="padding:10px 14px;">
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <form method="POST" class="mark-sent-form">
                  <input type="hidden" name="action" value="mark_sent">
                  <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                  <input type="text" name="ach_ref" class="ach-input" placeholder="ACH ref (optional)">
                  <button type="submit" class="btn btn-sm"
                          style="background:var(--green-bg);color:var(--green-text);border:1px solid #86efac;">
                    Mark sent
                  </button>
                </form>
                <form method="POST"
                      onsubmit="return confirm('Mark payout #<?= $p['id'] ?> as failed? Commissions will return to queue.')">
                  <input type="hidden" name="action" value="mark_failed">
                  <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline"
                          style="color:var(--red-text);border-color:#fca5a5;">Failed</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- SECTION 3: HISTORY -->
    <?php if (!empty($history)): ?>
    <div class="card" style="padding:0;">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
        <span class="card-title">Payout history</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead style="background:var(--bg);">
          <tr>
            <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">#</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Dealer</th>
            <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Amount</th>
            <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Activations</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">ACH ref</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Status</th>
            <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);border-bottom:1px solid var(--border);text-transform:uppercase;">Sent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr onmouseover="this.style.background='#fafbfd'" onmouseout="this.style.background=''">
            <td style="padding:10px 14px;font-family:monospace;font-size:11px;color:var(--text-lt);">#<?= $h['id'] ?></td>
            <td style="padding:10px 12px;font-weight:500;">
              <a href="/portal/admin/admin-dealer-detail.php?id=<?= $h['dealer_id'] ?>"
                 style="color:var(--text);text-decoration:none;">
                <?= htmlspecialchars($h['full_name']) ?>
              </a>
            </td>
            <td style="padding:10px 12px;text-align:right;font-weight:600;color:<?= $h['status']==='sent'?'var(--teal)':'var(--red-text)' ?>;">
              $<?= dollars($h['amount_cents']) ?>
            </td>
            <td style="padding:10px 12px;text-align:right;font-size:12px;color:var(--text-m);"><?= $h['commission_count'] ?></td>
            <td style="padding:10px 12px;font-family:monospace;font-size:11px;color:var(--text-lt);">
              <?= htmlspecialchars($h['ach_batch_ref'] ?? '—') ?>
            </td>
            <td style="padding:10px 12px;"><?= status_badge($h['status']) ?></td>
            <td style="padding:10px 12px;font-size:12px;color:var(--text-lt);">
              <?= $h['sent_at'] ? date('M j, Y', strtotime($h['sent_at'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
