<?php
/**
 * admin-financials.php — Financials: Wave Accounting mirror (ITFlow module_financial parity).
 *
 * Reads the local mirror tables (wave_*) that server/wave-api.ts keeps in sync from
 * Wave's GraphQL API. Nothing here talks to Wave directly except the "Sync now"
 * button, which proxies to POST /api/admin/wave/sync on the loopback listener.
 *
 * Wave's public API exposes invoices / payments / customers / accounts / vendors —
 * NOT expenses (see docs/itflow-parity-build-plan.md, Phase 2).
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';
$pdo = getDB();

$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        $ch = curl_init($internalOrigin . '/api/admin/wave/sync');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // The endpoint authenticates on the portal session; forward ours.
            CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr) {
            $error_message = 'Could not reach the Wave sync endpoint: ' . $cerr;
        } else {
            $data = json_decode((string)$resp, true);
            if ($http === 200 && is_array($data)) {
                $t = $data['totals'] ?? [];
                $success_message = sprintf(
                    'Wave sync complete — %d invoice(s), %d payment(s), %d customer(s), %d account(s), %d vendor(s).',
                    $t['invoices'] ?? 0, $t['payments'] ?? 0, $t['customers'] ?? 0, $t['accounts'] ?? 0, $t['vendors'] ?? 0
                );
                if (!empty($data['errors'])) {
                    $success_message .= ' Warnings: ' . implode(' | ', array_map('strval', $data['errors']));
                }
            } elseif ($http === 403) {
                $error_message = 'The sync endpoint rejected the request — admin session not recognised. Reload and try again.';
            } else {
                $error_message = 'Wave sync failed (HTTP ' . $http . '): ' . htmlspecialchars((string)($data['error'] ?? substr((string)$resp, 0, 200)));
            }
        }
    } elseif ($action === 'set_business') {
        $bid = trim((string)($_POST['business_id'] ?? ''));
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('wave_business_id', ?, NOW())
                       ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()")
            ->execute([$bid]);
        $success_message = 'Wave business updated.';
        if (!empty($_POST['sync_after'])) {
            $_POST['action'] = 'sync';
        }
    }
}

// ── Selected business ────────────────────────────────────────────────────────
$businesses = $pdo->query("SELECT * FROM wave_businesses ORDER BY is_personal, name")->fetchAll(PDO::FETCH_ASSOC);
$selected_setting = '';
try {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'wave_business_id'");
    $st->execute();
    $selected_setting = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) { $selected_setting = ''; }

$business = null;
foreach ($businesses as $b) {
    if ($selected_setting !== '' && $b['wave_id'] === $selected_setting) { $business = $b; break; }
}
if (!$business) {
    foreach ($businesses as $b) { if (!($b['is_personal'])) { $business = $b; break; } }
}
if (!$business && !empty($businesses)) { $business = $businesses[0]; }
$bid = $business['wave_id'] ?? '';

$last_run = $pdo->query("SELECT * FROM wave_sync_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

$token_configured = false;
try {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE lower(setting_key) = 'wave_token'");
    $st->execute();
    $token_configured = (bool)$st->fetchColumn();
} catch (Throwable $e) { $token_configured = false; }

// ── KPIs (whole mirror, current business when selected) ──────────────────────
$where = $bid !== '' ? "WHERE business_wave_id = " . $pdo->quote($bid) : "";
$inv = $pdo->query("SELECT COUNT(*) AS n,
                           COALESCE(SUM(total),0)       AS invoiced,
                           COALESCE(SUM(amount_paid),0) AS collected,
                           COALESCE(SUM(amount_due),0)  AS outstanding,
                           COALESCE(SUM(CASE WHEN status = 'OVERDUE' THEN amount_due ELSE 0 END),0) AS overdue,
                           COALESCE(SUM(CASE WHEN status = 'PAID' THEN 1 ELSE 0 END),0) AS paid_count
                    FROM wave_invoices $where")->fetch(PDO::FETCH_ASSOC);

$recent_invoices = $pdo->query("SELECT i.*, c.name AS portal_client_name
                                FROM wave_invoices i LEFT JOIN clients c ON c.id = i.client_id
                                $where ORDER BY i.invoice_date DESC NULLS LAST, i.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);

$recent_payments = $pdo->query("SELECT p.*, c.name AS portal_client_name
                                FROM wave_payments p LEFT JOIN clients c ON c.id = p.client_id
                                " . ($bid !== '' ? "WHERE p.business_wave_id = " . $pdo->quote($bid) : "") . "
                                ORDER BY p.payment_date DESC NULLS LAST, p.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$accounts = $pdo->query("SELECT * FROM wave_accounts
                         " . ($bid !== '' ? "WHERE business_wave_id = " . $pdo->quote($bid) : "") . "
                         AND is_archived = false
                         ORDER BY type_name, subtype_name, name")->fetchAll(PDO::FETCH_ASSOC);

$customers = $pdo->query("SELECT wc.*, c.name AS portal_client_name FROM wave_customers wc
                          LEFT JOIN clients c ON c.id = wc.client_id
                          " . ($bid !== '' ? "WHERE wc.business_wave_id = " . $pdo->quote($bid) : "") . "
                          ORDER BY COALESCE(wc.outstanding,0) DESC, wc.name LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

$vendors = $pdo->query("SELECT * FROM wave_vendors
                        " . ($bid !== '' ? "WHERE business_wave_id = " . $pdo->quote($bid) : "") . "
                        ORDER BY name LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

/** Status pill classes for a Wave invoice status. */
function wave_status_class($status) {
    switch (strtoupper((string)$status)) {
        case 'PAID':     return 'bg-green-100 text-green-700';
        case 'PARTIAL':  return 'bg-amber-100 text-amber-700';
        case 'OVERDUE':  return 'bg-red-100 text-red-700';
        case 'UNPAID':
        case 'SENT':
        case 'VIEWED':   return 'bg-blue-100 text-blue-700';
        case 'DRAFT':    return 'bg-gray-100 text-gray-600';
        default:         return 'bg-gray-100 text-gray-600';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financials — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/tailwind-shared.js"></script>
    <script>tailwind.config = window.bmTailwindConfig;</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-file-invoice-dollar text-emerald-500 mr-2"></i>Financials</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Wave Accounting mirror — invoices, payments, accounts &amp; vendors</p>
                </div>
                <div class="flex items-center gap-2">
                    <?php if (count($businesses) > 1): ?>
                    <form method="POST" class="flex items-center gap-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_business">
                        <select name="business_id" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <?php foreach ($businesses as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['wave_id']); ?>" <?php echo ($bid === $b['wave_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['name'] . ($b['is_personal'] ? ' (personal)' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync">
                        <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync mr-2"></i>Sync from Wave
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_message !== ''): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if ($error_message !== ''): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <?php if (!$token_configured): ?>
                <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm">
                    <i class="fas fa-triangle-exclamation mr-2"></i>
                    No Wave token stored. Add <code>wave_token</code> in Settings → Integrations before syncing.
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Invoiced</p>
                    <p class="text-3xl font-bold text-gray-900">$<?php echo number_format((float)$inv['invoiced'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo (int)$inv['n']; ?> invoice(s) · <?php echo (int)$inv['paid_count']; ?> paid</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Collected</p>
                    <p class="text-3xl font-bold text-emerald-600">$<?php echo number_format((float)$inv['collected'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Payments recorded in Wave</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Outstanding</p>
                    <p class="text-3xl font-bold text-blue-600">$<?php echo number_format((float)$inv['outstanding'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Unpaid balance</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Overdue</p>
                    <p class="text-3xl font-bold text-red-600">$<?php echo number_format((float)$inv['overdue'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Past due date</p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <span class="text-gray-500">Business:</span>
                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($business['name'] ?? '— none synced —'); ?></span>
                    <span class="text-gray-500">Currency:</span>
                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($business['currency'] ?? '—'); ?></span>
                    <span class="text-gray-500">Last sync:</span>
                    <span class="font-medium text-gray-900">
                        <?php if ($last_run): ?>
                            <?php echo htmlspecialchars((string)($last_run['finished_at'] ?? $last_run['started_at'])); ?>
                            <span class="ml-1 px-2 py-0.5 rounded-full text-xs <?php echo $last_run['status'] === 'ok' ? 'bg-green-100 text-green-700' : ($last_run['status'] === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'); ?>"><?php echo htmlspecialchars((string)$last_run['status']); ?></span>
                        <?php else: ?>never<?php endif; ?>
                    </span>
                </div>
                <?php if ($last_run && !empty($last_run['error'])): ?>
                    <p class="mt-2 text-xs text-red-600"><?php echo htmlspecialchars((string)$last_run['error']); ?></p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Invoices</h2>
                    <span class="text-xs text-gray-500">Latest 40</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                            <tr>
                                <th class="px-6 py-3 text-left">Invoice</th>
                                <th class="px-6 py-3 text-left">Customer</th>
                                <th class="px-6 py-3 text-left">Portal client</th>
                                <th class="px-6 py-3 text-left">Date</th>
                                <th class="px-6 py-3 text-left">Due</th>
                                <th class="px-6 py-3 text-left">Status</th>
                                <th class="px-6 py-3 text-right">Total</th>
                                <th class="px-6 py-3 text-right">Paid</th>
                                <th class="px-6 py-3 text-right">Due</th>
                                <th class="px-6 py-3 text-left">PDF</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php if (empty($recent_invoices)): ?>
                            <tr><td colspan="10" class="px-6 py-10 text-center text-gray-500">No invoices mirrored yet — press “Sync from Wave”.</td></tr>
                        <?php else: foreach ($recent_invoices as $r): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-medium text-gray-900"><?php echo htmlspecialchars((string)($r['invoice_number'] ?: '—')); ?></td>
                                <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($r['customer_name'] ?: '—')); ?></td>
                                <td class="px-6 py-3">
                                    <?php if (!empty($r['portal_client_name'])): ?>
                                        <a class="text-blue-600 hover:underline" href="admin-client-detail.php?id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars((string)$r['portal_client_name']); ?></a>
                                    <?php else: ?><span class="text-gray-400">unmatched</span><?php endif; ?>
                                </td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($r['invoice_date'] ?: '—')); ?></td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($r['due_date'] ?: '—')); ?></td>
                                <td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-xs <?php echo wave_status_class($r['status']); ?>"><?php echo htmlspecialchars((string)($r['status'] ?: '—')); ?></span></td>
                                <td class="px-6 py-3 text-right text-gray-900">$<?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="px-6 py-3 text-right text-emerald-600">$<?php echo number_format((float)$r['amount_paid'], 2); ?></td>
                                <td class="px-6 py-3 text-right <?php echo ((float)$r['amount_due'] > 0) ? 'text-red-600 font-medium' : 'text-gray-500'; ?>">$<?php echo number_format((float)$r['amount_due'], 2); ?></td>
                                <td class="px-6 py-3"><?php if (!empty($r['pdf_url'])): ?><a href="<?php echo htmlspecialchars((string)$r['pdf_url']); ?>" target="_blank" class="text-gray-400 hover:text-gray-700"><i class="fas fa-file-pdf"></i></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200"><h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Recent payments</h2></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Date</th><th class="px-6 py-3 text-left">Customer</th>
                                <th class="px-6 py-3 text-left">Invoice</th><th class="px-6 py-3 text-left">Method</th><th class="px-6 py-3 text-right">Amount</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($recent_payments)): ?>
                                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No payments mirrored yet.</td></tr>
                            <?php else: foreach ($recent_payments as $p): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($p['payment_date'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($p['customer_name'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($p['invoice_number'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($p['payment_method'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-right text-emerald-600 font-medium">$<?php echo number_format((float)$p['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200"><h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Customers with balances</h2></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Customer</th><th class="px-6 py-3 text-left">Portal client</th>
                                <th class="px-6 py-3 text-right">Outstanding</th><th class="px-6 py-3 text-right">Overdue</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No customers mirrored yet.</td></tr>
                            <?php else: foreach ($customers as $cu): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-900"><?php echo htmlspecialchars((string)$cu['name']); ?>
                                        <?php if (!empty($cu['email'])): ?><span class="block text-xs text-gray-500"><?php echo htmlspecialchars((string)$cu['email']); ?></span><?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3">
                                        <?php if (!empty($cu['portal_client_name'])): ?>
                                            <a class="text-blue-600 hover:underline" href="admin-client-detail.php?id=<?php echo (int)$cu['client_id']; ?>"><?php echo htmlspecialchars((string)$cu['portal_client_name']); ?></a>
                                        <?php else: ?><span class="text-gray-400">unmatched</span><?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-right text-gray-900">$<?php echo number_format((float)$cu['outstanding'], 2); ?></td>
                                    <td class="px-6 py-3 text-right <?php echo ((float)$cu['overdue'] > 0) ? 'text-red-600 font-medium' : 'text-gray-500'; ?>">$<?php echo number_format((float)$cu['overdue'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Chart of accounts</h2>
                        <span class="text-xs text-gray-500"><?php echo count($accounts); ?> account(s)</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Account</th><th class="px-6 py-3 text-left">Type</th>
                                <th class="px-6 py-3 text-left">Subtype</th><th class="px-6 py-3 text-right">Balance</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($accounts)): ?>
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No accounts mirrored yet.</td></tr>
                            <?php else: foreach ($accounts as $a): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-900"><?php echo htmlspecialchars((string)$a['name']); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($a['type_name'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars((string)($a['subtype_name'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-right text-gray-900"><?php echo $a['balance'] === null ? '—' : '$' . number_format((float)$a['balance'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Vendors</h2>
                        <span class="text-xs text-gray-500"><?php echo count($vendors); ?></span>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <?php if (empty($vendors)): ?>
                            <li class="px-6 py-8 text-center text-gray-500 text-sm">No vendors mirrored yet.</li>
                        <?php else: foreach ($vendors as $v): ?>
                            <li class="px-6 py-3">
                                <p class="text-sm text-gray-900"><?php echo htmlspecialchars((string)$v['name']); ?></p>
                                <?php if (!empty($v['email'])): ?><p class="text-xs text-gray-500"><?php echo htmlspecialchars((string)$v['email']); ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
