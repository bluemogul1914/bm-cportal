<?php
/**
 * admin-accounting.php — Accounting tab: real-time bookkeeping workspace.
 *
 * Pulls the banking + accounting picture straight from Xero through the loopback
 * API (GET /portal/api/accounting/overview) and renders it for the bookkeeper:
 * cash accounts, the bank-transaction feed, what needs reconciling, the ledger
 * snapshot, the balance sheet, and an explicit "is there anything to book yet?"
 * readiness checklist.
 *
 * This page also forwards the portal session cookie to the loopback listener —
 * that only works because the PHP shim now populates $_COOKIE (before that fix
 * every such call came back "Admin only").
 *
 * The Xero *configuration* pages live under Integrations:
 *   admin-xero.php       — Xero connection, tenant, consent, report passthrough
 *   admin-financials.php — Wave Accounting mirror (invoices/payments/vendors)
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');
$action_message = '';
$action_error = '';

/** Loopback API call carrying this admin session. */
function acc_api(string $origin, string $path, ?array $body = null): array {
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'http' => 0, 'error' => $err];
    $data = json_decode((string)$resp, true);
    if ($http >= 400) {
        return ['ok' => false, 'http' => $http, 'error' => $data['error'] ?? substr((string)$resp, 0, 220)];
    }
    return ['ok' => true, 'http' => $http, 'data' => is_array($data) ? $data : []];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'sync') {
        $r = acc_api($internalOrigin, '/portal/api/xero/sync', []);
        if ($r['ok']) {
            $d = $r['data'];
            $action_message = sprintf(
                'Xero refresh complete — %s: %d invoice(s), %d payment(s), %d contact(s), %d account(s).',
                (string)($d['organisation'] ?? 'organisation'),
                (int)($d['invoices'] ?? 0), (int)($d['payments'] ?? 0),
                (int)($d['contacts'] ?? 0), (int)($d['accounts'] ?? 0)
            );
            if (!empty($d['errors'])) {
                $action_message .= ' Warnings: ' . implode(' | ', array_map('strval', $d['errors']));
            }
        } else {
            $action_error = 'Refresh failed: ' . htmlspecialchars((string)$r['error']);
        }
    }
}

/* ── Live snapshot ─────────────────────────────────────────────────────── */
$ov = acc_api($internalOrigin, '/portal/api/accounting/overview');
$S = $ov['ok'] ? ($ov['data']['sections'] ?? []) : [];
$errors = $ov['ok'] ? ($ov['data']['errors'] ?? []) : [];
if (!$ov['ok']) {
    $action_error = $action_error !== '' ? $action_error : ('Could not load the accounting snapshot: ' . htmlspecialchars((string)$ov['error']));
}

$auth          = $S['auth'] ?? null;
$bank_accts    = $S['bank_accounts'] ?? null;
$bank_tx       = $S['bank_transactions'] ?? null;
$ledger        = $S['ledger'] ?? null;
$bsheet        = $S['balance_sheet'] ?? null;
$mirror        = $S['mirror'] ?? null;

$accounts_list = $bank_accts['accounts'] ?? [];
$tx_recent     = $bank_tx['recent'] ?? [];
$bank_count    = (int)($bank_accts['bank_account_count'] ?? 0);
$tx_count      = (int)($bank_tx['sample_size'] ?? 0);
$unreconciled  = (int)($bank_tx['unreconciled'] ?? 0);
$receivable    = (float)($ledger['receivable'] ?? 0);
$contacts      = (int)($ledger['contacts'] ?? 0);
$mode          = $auth['mode'] ?? 'none';
$org_name      = $auth['status']['organisation']['name'] ?? null;

/* Reports that the current consent can/can't reach (banksummary + P&L were not
   granted, so they 401 until the scopes are ticked and consent re-run). */
$report_scope_missing = false;
foreach ($errors as $e) {
    if (stripos((string)$e, 'Reports/') !== false) { $report_scope_missing = true; }
}

/** Readiness checklist — is there anything to book? */
$checks = [
    ['Xero connected',                 $mode !== 'none',                         $mode === 'none' ? 'Connect Xero under Integrations → Xero Accounting.' : 'Auth mode: ' . $mode],
    ['Bank accounts defined',          $bank_count > 0,                          $bank_count . ' bank account(s) in the chart of accounts'],
    ['Bank transactions imported',     $tx_count > 0,                            $tx_count > 0 ? $tx_count . ' transaction(s)' : 'No bank feed data — bank transactions must be imported in Xero (bank feeds / statement import)'],
    ['Contacts exist',                 $contacts > 0,                            $contacts . ' contact(s)'],
    ['Invoices exist',                 (int)($ledger['invoices']['total'] ?? 0) > 0, (int)($ledger['invoices']['total'] ?? 0) . ' invoice(s)'],
    ['Balance sheet available',        !empty($bsheet['available']),             !empty($bsheet['available']) ? 'Live from Xero' : 'Report call failed'],
    ['Bank summary / P&L scopes',      !$report_scope_missing,                   $report_scope_missing ? 'Not granted — tick accounting.reports.banksummary.read + profitandloss.read on the Xero app Configuration page, then re-run Connect with Xero' : 'Granted'],
];
$ready_count = count(array_filter($checks, fn($c) => $c[1]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting — Blue Mogul</title>
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-scale-balanced text-emerald-500 mr-2"></i>Accounting</h1>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Real-time bookkeeping from Xero<?php echo $org_name ? ' — ' . htmlspecialchars((string)$org_name) : ''; ?>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400">Snapshot <?php echo htmlspecialchars((string)date('H:i:s', strtotime((string)($ov['data']['generated_at'] ?? 'now')))); ?> UTC</span>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync">
                        <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition" data-testid="button-accounting-sync">
                            <i class="fas fa-rotate mr-2"></i>Refresh from Xero
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($action_message !== ''): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-accounting-success"><?php echo $action_message; ?></div><?php endif; ?>
            <?php if ($action_error !== ''): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-accounting-error"><?php echo $action_error; ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm" data-testid="alert-accounting-warnings">
                    <p class="font-medium mb-1"><i class="fas fa-triangle-exclamation mr-2"></i>Some sections could not be read from Xero:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars((string)$e); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Bank accounts</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $bank_count; ?></p>
                    <p class="text-xs text-gray-500 mt-1">of <?php echo (int)($bank_accts['total_accounts'] ?? 0); ?> accounts</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Bank transactions</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($tx_count); ?></p>
                    <p class="text-xs text-gray-500 mt-1">in the current page of the feed</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">To reconcile</p>
                    <p class="text-3xl font-bold <?php echo $unreconciled > 0 ? 'text-amber-600' : 'text-emerald-600'; ?>"><?php echo $unreconciled; ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo $bank_tx['oldest_unreconciled'] ? 'oldest ' . htmlspecialchars((string)$bank_tx['oldest_unreconciled']) : 'nothing outstanding'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Receivable</p>
                    <p class="text-3xl font-bold text-blue-600">$<?php echo number_format($receivable, 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo (int)($ledger['invoices']['awaiting_payment'] ?? 0); ?> awaiting payment</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Contacts</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $contacts; ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo (int)($ledger['payments'] ?? 0); ?> payment(s)</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Bank transactions</h2>
                        <span class="text-xs text-gray-500">Latest 25 from Xero</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Date</th><th class="px-6 py-3 text-left">Type</th>
                                <th class="px-6 py-3 text-left">Contact</th><th class="px-6 py-3 text-left">Account</th>
                                <th class="px-6 py-3 text-left">Status</th><th class="px-6 py-3 text-right">Amount</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($tx_recent)): ?>
                                <tr><td colspan="6" class="px-6 py-10 text-center text-gray-500" data-testid="accounting-tx-empty">
                                    No bank transactions in Xero yet. Import bank feeds or statements in Xero and they will appear here on the next refresh.
                                </td></tr>
                            <?php else: foreach ($tx_recent as $t): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($t['date'] ?? '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($t['type'] ?? '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($t['contact'] ?? '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($t['account'] ?? '—')); ?></td>
                                    <td class="px-6 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs <?php echo !empty($t['reconciled']) ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'; ?>">
                                            <?php echo !empty($t['reconciled']) ? 'reconciled' : 'to reconcile'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-right font-medium <?php echo ((float)($t['total'] ?? 0) < 0) ? 'text-red-600' : 'text-gray-900'; ?>">
                                        $<?php echo number_format((float)($t['total'] ?? 0), 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-3">Bookkeeping readiness</h2>
                    <p class="text-xs text-gray-500 mb-3"><?php echo $ready_count; ?> of <?php echo count($checks); ?> checks passing</p>
                    <ul class="space-y-3">
                        <?php foreach ($checks as [$label, $ok, $detail]): ?>
                        <li class="flex items-start gap-2">
                            <i class="fas <?php echo $ok ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-amber-500'; ?> mt-0.5"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars((string)$label); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars((string)$detail); ?></p>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Bookkeeper agent</h3>
                        <p class="text-xs text-gray-500">
                            Planned: nightly run at 01:00 CT writing a cashflow / goals / profitability report for 08:00 CT.
                            <span class="inline-block mt-1 px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">not scheduled yet</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Bank &amp; cash accounts</h2>
                        <span class="text-xs text-gray-500">Live from Xero</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Account</th><th class="px-6 py-3 text-left">Code</th>
                                <th class="px-6 py-3 text-left">Number</th><th class="px-6 py-3 text-left">Status</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($accounts_list)): ?>
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No bank accounts found.</td></tr>
                            <?php else: foreach ($accounts_list as $a): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-900"><?php echo htmlspecialchars((string)($a['name'] ?? '')); ?></td>
                                    <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars((string)($a['code'] ?? '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars((string)($a['number'] ?? '—')); ?></td>
                                    <td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700"><?php echo htmlspecialchars((string)($a['status'] ?? '—')); ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-6 py-3 text-xs text-gray-500 border-t border-gray-100">
                        Xero's Accounting API does not return live bank balances; they come from the Bank Summary report
                        (requires <code>accounting.reports.banksummary.read</code> on the consent).
                    </p>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">
                            <?php echo htmlspecialchars((string)($bsheet['name'] ?? 'Balance sheet')); ?>
                        </h2>
                        <span class="text-xs text-gray-500">Live from Xero</span>
                    </div>
                    <div class="overflow-x-auto max-h-96">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($bsheet['available']) || empty($bsheet['rows'])): ?>
                                <tr><td class="px-6 py-8 text-center text-gray-500">Balance sheet not available for this consent.</td></tr>
                            <?php else: foreach ($bsheet['rows'] as $row): ?>
                                <?php $isHeader = strtolower((string)($row['type'] ?? '')) === 'header'; ?>
                                <tr class="<?php echo $isHeader ? 'bg-gray-50' : ''; ?>">
                                    <?php $n = count($row['cells'] ?? []); foreach (($row['cells'] ?? []) as $i => $cell): ?>
                                        <td class="px-6 py-2 <?php echo $i > 0 ? 'text-right' : ''; ?> <?php echo $isHeader ? 'font-semibold text-gray-600 text-xs uppercase' : 'text-gray-800'; ?>"
                                            style="padding-left: <?php echo 24 + ((int)($row['depth'] ?? 0) * 14); ?>px">
                                            <?php echo htmlspecialchars((string)$cell); ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-6 bg-white rounded-lg border border-gray-200 p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-3">Related</h2>
                <div class="flex flex-wrap gap-3 text-sm">
                    <a href="admin-xero.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-plug mr-2 text-sky-500"></i>Xero connection &amp; reports
                    </a>
                    <a href="admin-financials.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-water mr-2 text-emerald-500"></i>Wave Accounting
                    </a>
                    <a href="admin-billing-reminders.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-bell mr-2 text-amber-500"></i>Client prepaid balances
                    </a>
                </div>
                <p class="text-xs text-gray-500 mt-3">
                    Mirror store: <?php echo (int)($mirror['counts']['xero_accounts'] ?? 0); ?> Xero accounts ·
                    <?php echo (int)($mirror['counts']['xero_invoices'] ?? 0); ?> Xero invoices ·
                    <?php echo (int)($mirror['counts']['wave_invoices'] ?? 0); ?> Wave invoices ·
                    last Xero sync <?php echo htmlspecialchars((string)($mirror['last_sync_run']['finished_at'] ?? 'never')); ?>
                </p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
