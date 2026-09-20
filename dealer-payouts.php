<?php
$page_title = 'Payouts';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

// ── Self-serve payout destination (Stripe / PayPal / bank) ───────────────────
// The dealer picks how they get paid. Money movement stays server-side; this
// page only talks to the dealer-scoped endpoints, which derive the dealer from
// the session — so a dealer can never touch another dealer's payout details.
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

function dpd_api(string $origin, string $path, ?array $body = null): array {
    $cookie = (string)($_COOKIE['connect.sid'] ?? '');
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . $cookie,
    ];
    if ($body !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    // Session-cookie presence is reported in every response so a 403 can never be
    // mistaken for a logic error: the platform's PHP child process only has a
    // session cookie if the shim forwarded it (see buildCookiePhpCode).
    $diag = ['cookie_present' => $cookie !== '', 'cookie_len' => strlen($cookie), 'http_code' => $code];
    if ($err) return ['error' => $err, 'http_code' => 0] + $diag;
    $j = json_decode((string)$resp, true);
    return is_array($j) ? ($j + $diag)
                        : ['error' => 'Invalid JSON (HTTP ' . $code . ')', 'http_code' => $code] + $diag;
}

/** Redirect even though this page's shell has already been printed. */
function dpd_redirect(string $url): void {
    if (!headers_sent()) { header('Location: ' . $url); exit; }
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<div class="alert alert-warning" style="margin:16px;">If you are not redirected automatically, '
       . '<a href="' . htmlspecialchars($url) . '">continue to Stripe</a>.</div>';
    echo '</div></div></body></html>';
    exit;
}

$success = $error = '';

// CSRF guard for all POST actions on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '' ?? '' ?? '') === 'request_payout') {
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
            unset($_SESSION['dealer_cache']);

            portal_redirect('/portal/dealer-payouts.php?success=1&amount=' . urlencode(dollars($total)));
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Payout request failed. Please try again.';
    }
}

if (isset($_GET['success'])) {
    $success = 'Payout of $' . htmlspecialchars($_GET['amount'] ?? '0.00') . ' requested. Funds sent to your bank by end of next business day.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '' ?? '' ?? '') === 'update_bank') {
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

$avail_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_cents),0) AS cents, COUNT(*) AS cnt
     FROM commissions WHERE dealer_id=? AND status='approved'"
);
$avail_stmt->execute([$dealer['id']]);
$avail = $avail_stmt->fetch();

$hist = $pdo->prepare(
    "SELECT id, amount_cents, commission_count, status, initiated_at, sent_at, created_at
     FROM dealer_payouts WHERE dealer_id=?
     ORDER BY created_at DESC"
);
$hist->execute([$dealer['id']]);
$payouts = $hist->fetchAll();

$lifetime = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_cents),0) AS total FROM dealer_payouts
     WHERE dealer_id=? AND status IN ('processing','sent')"
);
$lifetime->execute([$dealer['id']]);
$lifetime_total = $lifetime->fetchColumn();

// Save the chosen payout destination (session-scoped dealer).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_payout_destination') {
    $r = dpd_api($internalOrigin, '/portal/api/dealer/payout-method', [
        'method'       => $_POST['payout_method'] ?? 'manual',
        'paypal_email' => trim((string)($_POST['paypal_email'] ?? '')),
    ]);
    if (!empty($r['ok'])) {
        $success = 'Payout method saved.';
        unset($_SESSION['dealer_cache']);
        $dealer = dealer_me();
    } else {
        $error = 'Could not save payout method: ' . ($r['error'] ?? 'unknown error')
               . ' [session cookie forwarded: ' . (!empty($r['cookie_present']) ? 'yes' : 'NO — please log out and back in') . ']';
    }
}

// Start (or continue) Stripe Connect onboarding for this dealer's own account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect_stripe') {
    $r = dpd_api($internalOrigin, '/portal/api/dealer/connect-link', [
        'return_url' => 'https://portal.bluemogul.us/portal/dealer-payouts.php',
    ]);
    if (!empty($r['url'])) dpd_redirect((string)$r['url']);
    $error = 'Could not start Stripe setup: ' . ($r['error'] ?? 'unknown error');
}

// Fresh payout profile + Stripe onboarding status for the panel below.
$payoutProfile = dpd_api($internalOrigin, '/portal/api/dealer/payout-profile');
$ppDealer   = (is_array($payoutProfile) && empty($payoutProfile['error'])) ? ($payoutProfile['dealer'] ?? []) : [];
$ppStripe   = (is_array($payoutProfile) && empty($payoutProfile['error'])) ? ($payoutProfile['stripe_connect'] ?? []) : [];
$curMethod  = $ppDealer['method'] ?? ($dealer['payout_method'] ?? 'manual');
$stripeAcct = $ppStripe['account_id'] ?? null;
$stripeReady = !empty($ppStripe['ready']);

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

      <div>
        <div class="card" style="text-align:center;padding:28px 24px;">
          <div style="font-size:12px;color:var(--text-lt);font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Available for payout</div>
          <div style="font-size:40px;font-weight:700;color:<?= $avail['cents'] > 0 ? 'var(--green)' : 'var(--text-lt)' ?>;">
            $<?= dollars($avail['cents']) ?>
          </div>
          <div style="font-size:12px;color:var(--text-lt);margin-top:4px;margin-bottom:20px;">
            From <?= $avail['cnt'] ?> approved commission<?= $avail['cnt'] != 1 ? 's' : '' ?>
          </div>

          <?php if ((int)$avail['cents'] > 0): ?>
            <?php if (!$has_bank): ?>
            <div class="alert alert-warning" style="text-align:left;margin-bottom:14px;">
              Add your bank details below before requesting a payout.
            </div>
            <?php else: ?>
            <form method="POST">
        <?= csrf_field() ?>
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

        <div class="stat-card" style="margin-bottom:16px;">
          <div class="stat-label">Lifetime paid out</div>
          <div class="stat-value">$<?= dollars($lifetime_total) ?></div>
          <div class="stat-sub"><?= count($payouts) ?> payout<?= count($payouts) != 1 ? 's' : '' ?> total</div>
        </div>

        <div class="card" style="margin-bottom:16px;" data-testid="card-payout-destination">
          <div class="card-title" style="margin-bottom:6px;">How you get paid</div>
          <div style="font-size:11px;color:var(--text-lt);margin-bottom:14px;">
            Choose Stripe (paid straight to your bank) or PayPal (paid to your PayPal email).
          </div>

          <?php if ($stripeAcct): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:12px;">
            <span style="background:<?= $stripeReady ? 'var(--green-bg)' : 'var(--amber-bg)' ?>;color:<?= $stripeReady ? 'var(--green-text)' : 'var(--amber-text)' ?>;padding:3px 9px;border-radius:12px;font-weight:600;">
              <?= $stripeReady ? 'Stripe ready' : 'Stripe setup unfinished' ?>
            </span>
            <span style="color:var(--text-lt);font-family:monospace;"><?= htmlspecialchars((string)$stripeAcct) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($ppStripe['message'])): ?>
          <div style="font-size:11px;color:var(--text-lt);margin-bottom:12px;"><?= htmlspecialchars((string)$ppStripe['message']) ?></div>
          <?php endif; ?>

          <form method="POST" data-testid="form-payout-destination">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_payout_destination">

            <div class="form-group">
              <label class="form-label">Payout method</label>
              <select name="payout_method" class="form-control" data-testid="select-payout-method">
                <option value="stripe_connect" <?= $curMethod === 'stripe_connect' ? 'selected' : '' ?>>Stripe Connect (bank deposit)</option>
                <option value="paypal"         <?= $curMethod === 'paypal' ? 'selected' : '' ?>>PayPal</option>
                <option value="ach"            <?= $curMethod === 'ach' ? 'selected' : '' ?>>Bank details on file (ACH)</option>
                <option value="manual"         <?= $curMethod === 'manual' ? 'selected' : '' ?>>Not sure / arrange with Blue Mogul</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">PayPal email (for PayPal payouts)</label>
              <input type="email" name="paypal_email" class="form-control" placeholder="you@example.com"
                     value="<?= htmlspecialchars((string)($ppDealer['paypal_email'] ?? '')) ?>" data-testid="input-paypal-email">
            </div>

            <button type="submit" class="btn btn-primary btn-sm" data-testid="button-save-payout-method">Save payout method</button>
          </form>

          <div style="border-top:1px solid var(--border);margin-top:14px;padding-top:14px;">
            <div style="font-size:11px;color:var(--text-lt);margin-bottom:8px;">
              Stripe pays out automatically to your bank once setup is complete.
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="connect_stripe">
              <button type="submit" class="btn btn-outline btn-sm" data-testid="button-connect-stripe-dealer">
                <?= $stripeAcct ? ($stripeReady ? 'Update Stripe details' : 'Finish Stripe setup') : 'Connect with Stripe' ?>
              </button>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-title" style="margin-bottom:14px;">Bank details (ACH)</div>

          <?php if (isset($success_bank)): ?>
          <div class="alert alert-success" style="margin-bottom:12px;"><?= $success_bank ?></div>
          <?php endif; ?>
          <?php if (isset($error_bank)): ?>
          <div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);margin-bottom:12px;"><?= $error_bank ?></div>
          <?php endif; ?>

          <form method="POST">
        <?= csrf_field() ?>
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
                <?= dealer_fmt_date($p['created_at'] ?? null) ?>
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

</div>
</body>
</html>
