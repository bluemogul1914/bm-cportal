<?php
$page_title = 'Payouts';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$success = $error = '';

// ── Handle early payout request ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_payout') {
    try {
        $pdo->beginTransaction();

        $avail = $pdo->prepare(
            "SELECT id, amount_cents FROM commissions
             WHERE dealer_id=? AND status='approved' FOR UPDATE"
        );
        $avail->execute([$dealer['id']]);
        $rows = $avail->fetchAll();

        if (empty($rows)) {
            $pdo->rollBack();
            $error = 'No approved commissions available for payout.';
        } else {
            $total = array_sum(array_column($rows, 'amount_cents'));
            $ids   = array_column($rows, 'id');

            $ins = $pdo->prepare(
                "INSERT INTO dealer_payouts
                   (dealer_id, amount_cents, commission_count, status, initiated_at)
                 VALUES (?,?,?,'pending',NOW()) RETURNING id"
            );
            $ins->execute([$dealer['id'], $total, count($ids)]);
            $payout_id = $ins->fetchColumn();

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $pdo->prepare(
                "UPDATE commissions SET status='paid', paid_at=NOW(), payout_id=?
                 WHERE id IN ($placeholders)"
            );
            $upd->execute(array_merge([$payout_id], $ids));

            $pdo->commit();

            // Clear dealer session cache so tier/stats refresh
            unset($_SESSION['dealer_cache']);

            header("Location: /portal/dealer-payouts.php?success=1&amount=" . urlencode(dollars($total)));
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Payout request failed. Please try again.';
    }
}

if (isset($_GET['success'])) {
    $success = 'Payout of $' . htmlspecialchars($_GET['amount']) . ' requested. Funds sent to your bank by end of next business day.';
}

// ── Handle bank info update ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bank') {
    $routing = preg_replace('/\D/', '', $_POST['ach_routing'] ?? '');
    $account = preg_replace('/\D/', '', $_POST['ach_account'] ?? '');
    if (strlen($routing) === 9 && strlen($account) >= 4) {
        $pdo->prepare(
            "UPDATE dealers SET ach_routing=?, ach_account=?, updated_at=NOW() WHERE id=?"
        )->execute([$routing, $account, $dealer['id']]);
        unset($_SESSION['dealer_cache']);
        $success_bank = 'Bank info updated.';
        $dealer = dealer_me();
    } else {
        $error_bank = 'Please enter a valid 9-digit routing number and account number.';
    }
}

// ── Available balance ─────────────────────────────────────────
$avail_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_cents),0) AS cents, COUNT(*) AS cnt
     FROM commissions WHERE dealer_id=? AND status='approved'"
);
$avail_stmt->execute([$dealer['id']]);
$avail = $avail_stmt->fetch();

// ── Payout history ────────────────────────────────────────────
$hist = $pdo->prepare(
    "SELECT id, amount_cents, commission_count, status, initiated_at, sent_at, created_at
     FROM dealer_payouts WHERE dealer_id=?
     ORDER BY created_at DESC"
);
$hist->execute([$dealer['id']]);
$payouts = $hist->fetchAll();

// ── Lifetime totals ───────────────────────────────────────────
$lifetime = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_cents),0) AS total FROM dealer_payouts
     WHERE dealer_id=? AND status IN ('processing','sent')"
);
$lifetime->execute([$dealer['id']]);
$lifetime_total = $lifetime->fetchColumn();

$has_bank = !empty($dealer['ach_routing']);
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Payouts</div>
      <div class="topbar-sub">ACH payouts every Friday — or request early</div>
    </div>
    <div class="topbar-right">
      <?= tier_badge($dealer['tier']) ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="two-col" style="align-items:start;">

      <!-- LEFT: balance + request -->
      <div>
        <!-- BALANCE CARD -->
        <div class="card" style="text-align:center;padding:28px 24px;">
          <div style="font-size:12px;color:var(--text-lt);font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Available for payout</div>
          <div style="font-size:40px;font-weight:700;color:<?= $avail['cents'] > 0 ? 'var(--green)' : 'var(--text-lt)' ?>;">
            $<?= dollars($avail['cents']) ?>
          </div>
          <div style="font-size:12px;color:var(--text-lt);margin-top:4px;margin-bottom:20px;">
            From <?= $avail['cnt'] ?> approved commission<?= $avail['cnt'] != 1 ? 's' : '' ?>
          </div>

          <?php if ((float)$avail['cents'] > 0): ?>
            <?php if (!$has_bank): ?>
            <div class="alert alert-warning" style="text-align:left;margin-bottom:14px;">
              Add your bank details below before requesting a payout.
            </div>
            <?php else: ?>
            <form method="POST">
              <input type="hidden" name="action" value="request_payout">
              <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                Request payout — $<?= dollars($avail['cents']) ?>
              </button>
            </form>
            <?php endif; ?>
          <?php else: ?>
            <div style="font-size:13px;color:var(--text-lt);">No approved commissions yet.</div>
          <?php endif; ?>

          <div style="margin-top:12px;font-size:11px;color:var(--text-lt);">
            Auto-payout runs every <strong>Friday at 9 AM CT</strong>
          </div>
        </div>

        <!-- LIFETIME STAT -->
        <div class="stat-card" style="margin-bottom:16px;">
          <div class="stat-label">Lifetime paid out</div>
          <div class="stat-value">$<?= dollars($lifetime_total) ?></div>
          <div class="stat-sub"><?= count($payouts) ?> payout<?= count($payouts) != 1 ? 's' : '' ?> total</div>
        </div>

        <!-- BANK INFO -->
        <div class="card">
          <div class="card-title" style="margin-bottom:14px;">
            Payout method (ACH)
          </div>

          <?php if (isset($success_bank)): ?>
          <div class="alert alert-success" style="margin-bottom:12px;"><?= $success_bank ?></div>
          <?php endif; ?>
          <?php if (isset($error_bank)): ?>
          <div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);margin-bottom:12px;"><?= $error_bank ?></div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="action" value="update_bank">
            <div class="form-group">
              <label class="form-label">Routing number (9 digits)</label>
              <input type="text" name="ach_routing" class="form-control"
                     placeholder="021000021" maxlength="9"
                     value="<?= $has_bank ? str_repeat('•', 5) . substr($dealer['ach_routing'], -4) : '' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Account number</label>
              <input type="text" name="ach_account" class="form-control"
                     placeholder="Account number"
                     value="<?= !empty($dealer['ach_account']) ? str_repeat('•', 6) . substr($dealer['ach_account'], -4) : '' ?>">
            </div>
            <button type="submit" class="btn btn-outline btn-sm">
              <?= $has_bank ? 'Update bank info' : 'Save bank info' ?>
            </button>
          </form>
          <?php if ($has_bank): ?>
          <div style="margin-top:10px;font-size:11px;color:var(--text-lt);">
            Bank on file ending in <?= substr($dealer['ach_account'] ?? '????', -4) ?>.
            Enter new numbers above to update.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: payout history -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Payout history</span>
        </div>

        <?php if (empty($payouts)): ?>
          <p style="font-size:13px;color:var(--text-lt);padding:12px 0;">No payouts yet. Build up approved commissions by activating clients!</p>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Date</th><th>Activations</th><th>Amount</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($payouts as $p): ?>
            <tr>
              <td style="font-size:12px;">
                <?= date('M j, Y', strtotime($p['created_at'])) ?>
              </td>
              <td style="font-size:12px;color:var(--text-m);">
                <?= $p['commission_count'] ?> activation<?= $p['commission_count'] != 1 ? 's' : '' ?>
              </td>
              <td style="font-weight:600;color:<?= $p['status']==='sent'?'var(--teal)':'var(--text)' ?>;">
                $<?= dollars($p['amount_cents']) ?>
              </td>
              <td><?= status_badge($p['status']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div><!-- /.main -->
</body>
</html>
