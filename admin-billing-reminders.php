<?php
/**
 * Billing Reminders — admin view of the PREPAID balance model.
 *
 * Blue Mogul billing is prepaid: the client pays first, credit lands on the
 * account, and service runs until the balance is exhausted. Consequently there
 * is no postpaid invoice-chasing here — the "recurring" job is a low-balance /
 * renewal reminder, and auto-suspend fires at $0 rather than on an overdue bill.
 *
 * This page replaces the postpaid recurring-invoice scheduler that previously
 * lived at admin-recurring-invoices.php. That engine was superseded by the
 * prepaid balance ledger (see docs/isp-gap-backlog.md, P0 #1) and never had a
 * backing table, so it could not have worked.
 *
 * Reads and writes go straight to Postgres via PDO. The one action that needs
 * the Node scheduler (sending reminder email, suspending, recovering top-ups)
 * is delegated to POST /api/admin/balance-check/run on the internal listener,
 * forwarding the admin session cookie so the endpoint's own admin check runs.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();
$success_message = '';
$error_message = '';

// Internal origin: PHP runs inside the app container, so the Node listener is on
// loopback. Deliberately NOT the public URL — no need to round-trip Cloudflare.
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // ── Run the balance scheduler now ────────────────────────────────────
    if ($action === 'run_check') {
        $ch = curl_init($internalOrigin . '/api/admin/balance-check/run');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // The endpoint authenticates on the portal session; forward ours.
            CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
        ]);
        $resp  = curl_exec($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr  = curl_error($ch);
        curl_close($ch);

        if ($cerr) {
            $error_message = 'Could not reach the balance scheduler: ' . $cerr;
        } else {
            $data = json_decode((string)$resp, true);
            if ($http === 200 && is_array($data)) {
                $success_message = sprintf(
                    'Balance check complete — %d client(s) checked, %d suspended at $0, %d low-balance reminder(s) sent, %d restored after top-up, %d top-up(s) recovered.',
                    $data['clients_checked'] ?? 0,
                    $data['suspended'] ?? 0,
                    $data['warned'] ?? 0,
                    $data['restored'] ?? 0,
                    $data['topups_recovered'] ?? 0
                );
            } elseif ($http === 403) {
                $error_message = 'The scheduler rejected the request — the admin session was not recognised. Reload the page and try again.';
            } else {
                $error_message = 'Balance check failed (HTTP ' . $http . '): '
                    . ($data['error'] ?? substr((string)$resp, 0, 200));
            }
        }
    }

    // ── Per-client low-balance threshold ─────────────────────────────────
    elseif ($action === 'set_threshold') {
        $clientId  = (int)($_POST['client_id'] ?? 0);
        $threshold = (float)($_POST['low_balance_threshold'] ?? 0);
        if ($clientId <= 0 || $threshold < 0) {
            $error_message = 'Invalid client or threshold value.';
        } else {
            $pdo->prepare("UPDATE clients SET low_balance_threshold = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$threshold, $clientId]);
            $success_message = 'Low-balance threshold set to $' . number_format($threshold, 2) . '.';
        }
    }

    // ── Clear the reminder flag so the next run re-sends ─────────────────
    elseif ($action === 'clear_warned') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId <= 0) {
            $error_message = 'Invalid client.';
        } else {
            $pdo->prepare("UPDATE clients SET low_balance_warned = false, updated_at = NOW() WHERE id = ?")
                ->execute([$clientId]);
            $success_message = 'Reminder flag cleared — the next balance check will send a fresh low-balance reminder.';
        }
    }
}

// ── Load data ────────────────────────────────────────────────────────────
$clients = $pdo->query("
    SELECT id, name, email,
           COALESCE(credit_balance, 0)            AS balance,
           COALESCE(low_balance_threshold, 10.00) AS threshold,
           COALESCE(low_balance_warned, false)    AS warned,
           COALESCE(status, 'active')             AS status
    FROM clients
    ORDER BY (COALESCE(status, 'active') = 'suspended') DESC,
             COALESCE(credit_balance, 0) ASC,
             name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalClients = count($clients);
$suspendedCount = $lowCount = $zeroCount = $warnedCount = 0;
$balanceTotal = 0.0;

foreach ($clients as $c) {
    $b = (float)$c['balance'];
    $t = (float)$c['threshold'];
    $balanceTotal += $b;
    if ($c['status'] === 'suspended') $suspendedCount++;
    if ($b <= 0)                      $zeroCount++;
    if ($b > 0 && $b <= $t)           $lowCount++;
    if (!empty($c['warned']))         $warnedCount++;
}

$ledger = $pdo->query("
    SELECT tl.created_at, tl.type, tl.amount, tl.balance_before, tl.balance_after,
           tl.description, c.name AS client_name
    FROM transaction_ledger tl
    LEFT JOIN clients c ON c.id = tl.client_id
    ORDER BY tl.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Reminders - Blue Mogul Admin</title>
    <meta name="description" content="Prepaid balance monitoring, low-balance reminders and auto-suspend for the Blue Mogul portal.">

    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

    <script src="/assets/js/tailwind-shared.js"></script>
    <script>
        tailwind.config = window.bmTailwindConfig;
    </script>
</head>
<body class="bg-gray-50 font-sans">

    <div class="flex h-screen overflow-hidden">

        <?php include 'includes/admin-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                                <i class="fas fa-bell mr-2 text-blue-600"></i>Billing Reminders
                            </h1>
                            <p class="text-sm text-gray-600 mt-1">Prepaid balance monitoring, low-balance reminders and auto-suspend</p>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

    <?php if ($success_message): ?>
        <div class="mb-5 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="mb-5 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
        <i class="fas fa-info-circle mr-2"></i>
        <strong>How this works:</strong> every Blue Mogul account is prepaid — the client tops up first and service
        runs until the credit is used. A single low-balance reminder goes out when the balance drops to or below
        the client's threshold, and the account is suspended at $0.00. Topping up restores service automatically.
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5 flex flex-col">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Clients</div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?= $totalClients ?></div>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5 flex flex-col">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Low balance</div>
            <div class="text-2xl font-bold <?= $lowCount ? 'text-yellow-700' : 'text-gray-900' ?> mt-1"><?= $lowCount ?></div>
            <div class="text-gray-500 text-xs mt-1"><?= $warnedCount ?> already reminded</div>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5 flex flex-col">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Suspended at $0</div>
            <div class="text-2xl font-bold <?= $suspendedCount ? 'text-red-700' : 'text-gray-900' ?> mt-1"><?= $suspendedCount ?></div>
            <div class="text-gray-500 text-xs mt-1"><?= $zeroCount ?> at or below zero</div>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5 flex flex-col">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Credit held</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">$<?= number_format($balanceTotal, 2) ?></div>
        </div>
    </div>

    <!-- Manual trigger -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 mb-6 flex items-center justify-between gap-4">
        <div>
            <div class="text-gray-900 font-medium">Run balance check now</div>
            <div class="text-gray-600 text-sm mt-1">
                Sends due low-balance reminders, suspends accounts at $0, restores accounts after top-up and
                recovers missed Stripe top-ups. The scheduler also runs this on a timer.
            </div>
        </div>
        <form method="POST" onsubmit="return confirm('Run the balance check across all clients now?')" class="flex-shrink-0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="run_check">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium">
                <i class="fas fa-play mr-2"></i>Run check
            </button>
        </form>
    </div>

    <!-- Clients -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm mb-6 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Client balances</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Client</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Reminder state</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Threshold</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                <?php if (!$clients): ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No clients yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($clients as $c):
                    $balance   = (float)$c['balance'];
                    $threshold = (float)$c['threshold'];
                    $isLow     = ($balance > 0 && $balance <= $threshold);
                    $isSusp    = ($c['status'] === 'suspended');
                ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <div class="flex items-center">
                                <div class="leading-tight">
                                    <div class="text-gray-900 font-medium"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="text-gray-500 text-xs"><?= htmlspecialchars($c['email'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right font-mono <?= $balance <= 0 ? 'text-red-700' : ($isLow ? 'text-yellow-700' : 'text-green-700') ?>">
                            $<?= number_format($balance, 2) ?>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center">
                                <?php if ($isSusp || $balance <= 0): ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 whitespace-nowrap">Final notice sent</span>
                                <?php elseif (!empty($c['warned'])): ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700 whitespace-nowrap">Reminder sent</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600 whitespace-nowrap">Not sent</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center">
                                <?php if ($isSusp): ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 whitespace-nowrap">Suspended</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 whitespace-nowrap">Active</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <form method="POST" class="flex items-center">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="set_threshold">
                                <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                <div class="flex items-center">
                                    <span class="text-gray-500 px-2 py-1 text-sm leading-tight">$</span>
                                    <input type="number" name="low_balance_threshold" step="0.01" min="0"
                                           value="<?= number_format($threshold, 2, '.', '') ?>"
                                           class="w-24 bg-white border border-gray-300 rounded-md px-2 py-1 text-gray-900 text-sm">
                                </div>
                                <button type="submit" class="ml-2 text-blue-600 hover:text-blue-800 text-xs font-medium">Save</button>
                            </form>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end">
                                <form method="POST" onsubmit="return confirm('Clear the reminder flag for <?= htmlspecialchars(addslashes($c['name'])) ?>? The next check will re-send a reminder.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="clear_warned">
                                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="text-xs text-gray-600 border border-gray-300 hover:bg-gray-50 rounded-md px-2 py-1"
                                            <?= empty($c['warned']) ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>>
                                        Clear flag
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ledger -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Recent balance activity</h2>
            <p class="text-gray-600 text-sm mt-1">Every top-up, charge, refund and adjustment appends a dated ledger entry.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">When</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Client</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance after</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                <?php if (!$ledger): ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No balance activity yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($ledger as $l):
                    $amt = (float)$l['amount'];
                    $sign = in_array($l['type'], ['top_up', 'refund'], true) ? '+' : ($l['type'] === 'charge' ? '-' : '');
                ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                            <?= htmlspecialchars(date('M j, Y g:ia', strtotime((string)$l['created_at']))) ?>
                        </td>
                        <td class="px-5 py-3 text-gray-900"><?= htmlspecialchars($l['client_name'] ?? '—') ?></td>
                        <td class="px-5 py-3">
                            <span class="px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700"><?= htmlspecialchars((string)$l['type']) ?></span>
                        </td>
                        <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars((string)($l['description'] ?? '')) ?></td>
                        <td class="px-5 py-3 text-right font-mono <?= $sign === '+' ? 'text-green-700' : ($sign === '-' ? 'text-red-700' : 'text-gray-600') ?>">
                            <?= $sign ?>$<?= number_format(abs($amt), 2) ?>
                        </td>
                        <td class="px-5 py-3 text-right font-mono text-gray-600">$<?= number_format((float)$l['balance_after'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
        </div>
    </div>

</body>
</html>
