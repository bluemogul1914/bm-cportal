<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo = getDB();

/* ── Load Xero settings ────────────────────────────────────────────── */
$xero = [];
try {
    $row = $pdo->query("SELECT settings FROM provider_settings WHERE provider_name='xero'")->fetch(PDO::FETCH_ASSOC);
    if ($row) $xero = $row['settings'] ?? [];
} catch (PDOException $e) { $xero = []; }

$connected    = !empty($xero['access_token']);
$tenant_id    = $xero['tenant_id'] ?? '';
$tenants      = $xero['tenants']   ?? [];
$expires_at   = $xero['expires_at'] ?? 0;
$xero_client_id = getenv('XERO_CLIENT_ID') ?: '';
/* Compute the redirect URI the same way the server does:
   prefer XERO_REDIRECT_URI env var, otherwise auto-build WITHOUT /portal/ prefix */
$_xero_redirect_uri = getenv('XERO_REDIRECT_URI') ?: '';
if (!$_xero_redirect_uri) {
    // Detect proto: honour reverse-proxy headers first (Coolify/Nginx sets X-Forwarded-Proto)
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
          ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : null)
          ?? 'https'; // default to https for production deployments
    // Prefer X-Forwarded-Host so this works behind Nginx/Coolify
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'portal.bluemogul.us';
    // Strip port from host for clean URLs
    $host  = explode(':', $host)[0] . (strpos($host, ':') !== false && !in_array(explode(':',$host)[1],['80','443']) ? ':'.explode(':',$host)[1] : '');
    $_xero_redirect_uri = "$proto://$host/api/xero/callback";
}
$connected_msg = $_GET['connected'] ?? '';
$error_msg_url = $_GET['error'] ?? '';

/* ── Disconnect ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect_xero') {
    require_csrf();
    try {
        $pdo->query("DELETE FROM provider_settings WHERE provider_name='xero'");
        portal_redirect('/portal/admin-xero.php?disconnected=1');
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xero Integration — Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e',xero:'#13B5EA'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <svg class="w-7 h-7" viewBox="0 0 512 512" fill="none"><circle cx="256" cy="256" r="256" fill="#13B5EA"/><path d="M256 151c-57.9 0-105 47.1-105 105s47.1 105 105 105 105-47.1 105-105-47.1-105-105-105zm0 172.5c-37.2 0-67.5-30.3-67.5-67.5s30.3-67.5 67.5-67.5 67.5 30.3 67.5 67.5-30.3 67.5-67.5 67.5z" fill="white"/></svg>
                    <h1 class="text-xl font-semibold text-gray-900">Xero Integration</h1>
                    <?php if ($connected): ?>
                    <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded-full" data-testid="status-xero-connected">Connected</span>
                    <?php else: ?>
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-xs font-medium rounded-full" data-testid="status-xero-disconnected">Not Connected</span>
                    <?php endif; ?>
                </div>
                <?php if ($connected): ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disconnect_xero">
                    <button type="submit" class="px-3 py-1.5 text-sm text-red-600 border border-red-200 rounded-md hover:bg-red-50 transition" data-testid="button-xero-disconnect">Disconnect</button>
                </form>
                <?php endif; ?>
            </div>
        </header>

        <div class="p-6 max-w-5xl space-y-6">

            <?php if ($connected_msg === '1'): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md flex items-center" data-testid="alert-xero-connected">
                <i class="fas fa-check-circle mr-2"></i>Xero connected successfully! Your organisation data is now syncing.
            </div>
            <?php endif; ?>

            <?php if ($_GET['disconnected'] ?? ''): ?>
            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-md" data-testid="alert-xero-disconnected">
                Xero has been disconnected.
            </div>
            <?php endif; ?>

            <?php if ($error_msg_url): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md" data-testid="alert-xero-error">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars(urldecode($error_msg_url)) ?>
            </div>
            <?php endif; ?>

            <?php if (!$connected): ?>
            <!-- Setup Instructions -->
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Connect Your Xero Account</h2>

                <?php if (!$xero_client_id): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-md p-4 mb-5">
                    <h3 class="font-semibold text-amber-800 mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Setup Required</h3>
                    <p class="text-sm text-amber-700 mb-3">You need to create a Xero app and add credentials to your environment variables before connecting.</p>
                    <ol class="text-sm text-amber-700 space-y-2 list-decimal list-inside">
                        <li>Go to <a href="https://developer.xero.com/app/manage" target="_blank" class="underline font-medium">developer.xero.com/app/manage</a></li>
                        <li>Click <strong>New app</strong> → choose <strong>Web app</strong></li>
                        <li>Set Redirect URI to: <code class="bg-amber-100 px-1 rounded text-xs"><?php echo htmlspecialchars($_xero_redirect_uri); ?></code></li>
                        <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong></li>
                        <li>Add to environment: <code class="bg-amber-100 px-1 rounded text-xs">XERO_CLIENT_ID</code> and <code class="bg-amber-100 px-1 rounded text-xs">XERO_CLIENT_SECRET</code></li>
                    </ol>
                </div>
                <?php else: ?>
                <p class="text-sm text-gray-600 mb-5">Click the button below to authorise Blue Mogul Admin to access your Xero organisation's financial data. You'll be redirected to Xero's secure login page.</p>
                <div class="mb-5 p-3 bg-gray-50 border border-gray-200 rounded-md text-xs text-gray-500">
                    <strong>Redirect URI registered in your Xero app:</strong><br>
                    <code><?php echo htmlspecialchars($_xero_redirect_uri); ?></code>
                    <?php if (!getenv('XERO_REDIRECT_URI')): ?>
                    <div class="mt-1 text-gray-400">To override, set the <code>XERO_REDIRECT_URI</code> environment variable.</div>
                    <?php endif; ?>
                </div>
                <a href="/portal/api/xero/connect" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#13B5EA] hover:bg-[#0fa3d4] text-white rounded-md font-medium transition" data-testid="button-xero-connect">
                    <svg class="w-5 h-5" viewBox="0 0 512 512" fill="none"><circle cx="256" cy="256" r="256" fill="white" fill-opacity="0.3"/><path d="M256 151c-57.9 0-105 47.1-105 105s47.1 105 105 105 105-47.1 105-105-47.1-105-105-105zm0 172.5c-37.2 0-67.5-30.3-67.5-67.5s30.3-67.5 67.5-67.5 67.5 30.3 67.5 67.5-30.3 67.5-67.5 67.5z" fill="white"/></svg>
                    Connect with Xero
                </a>
                <?php endif; ?>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <i class="fas fa-file-invoice-dollar text-2xl text-blue-500 mb-2"></i>
                        <div class="font-medium text-sm">Invoice Sync</div>
                        <p class="text-xs text-gray-500 mt-1">Pull Xero invoices into the portal for each client</p>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <i class="fas fa-chart-line text-2xl text-green-500 mb-2"></i>
                        <div class="font-medium text-sm">Financial Reports</div>
                        <p class="text-xs text-gray-500 mt-1">P&L, Balance Sheet, Aged Receivables from Xero</p>
                    </div>
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <i class="fas fa-users text-2xl text-purple-500 mb-2"></i>
                        <div class="font-medium text-sm">Contact Matching</div>
                        <p class="text-xs text-gray-500 mt-1">Auto-match Xero contacts to portal clients</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Connected Dashboard -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-xs text-gray-500 mb-1">Organisation</div>
                    <div class="font-semibold text-gray-900 text-sm" data-testid="text-xero-org"><?= htmlspecialchars($tenants[0]['tenantName'] ?? 'Connected') ?></div>
                    <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($tenants[0]['tenantType'] ?? '') ?></div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-xs text-gray-500 mb-1">Token Status</div>
                    <div class="font-semibold text-sm <?= ($expires_at && $expires_at < time()*1000) ? 'text-orange-500' : 'text-green-600' ?>">
                        <?= ($expires_at && $expires_at < time()*1000) ? 'Needs Refresh' : 'Active' ?>
                    </div>
                    <?php if ($expires_at): ?>
                    <div class="text-xs text-gray-400 mt-1">Expires <?= date('M j, Y g:i a', intval($expires_at/1000)) ?></div>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5 flex items-center gap-3">
                    <button onclick="loadXeroData('Invoices')" class="flex-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-md transition" data-testid="button-xero-load-invoices">Load Invoices</button>
                    <button onclick="loadXeroData('Contacts')" class="flex-1 px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-md transition" data-testid="button-xero-load-contacts">Load Contacts</button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="flex border-b border-gray-200">
                    <?php foreach (['invoices' => 'Invoices', 'contacts' => 'Contacts', 'reports' => 'Reports'] as $tab => $label): ?>
                    <button onclick="switchTab('<?= $tab ?>')" id="tab-<?= $tab ?>"
                        class="px-5 py-3 text-sm font-medium border-b-2 transition -mb-px <?= $tab === 'invoices' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>"
                        data-testid="tab-xero-<?= $tab ?>">
                        <?= $label ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Invoices Tab -->
                <div id="pane-invoices" class="p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex gap-2">
                            <select id="invoice-status-filter" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm" data-testid="select-invoice-status">
                                <option value="AUTHORISED">Authorised</option>
                                <option value="PAID">Paid</option>
                                <option value="DRAFT">Draft</option>
                                <option value="">All</option>
                            </select>
                            <button onclick="loadXeroData('Invoices', {Statuses: document.getElementById('invoice-status-filter').value})"
                                class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 transition" data-testid="button-refresh-invoices">
                                <i class="fas fa-sync-alt mr-1"></i>Refresh
                            </button>
                        </div>
                        <span id="invoices-count" class="text-sm text-gray-500" data-testid="text-invoices-count"></span>
                    </div>
                    <div id="invoices-loading" class="hidden text-center py-8 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading invoices from Xero…</div>
                    <div id="invoices-table" class="overflow-x-auto"></div>
                </div>

                <!-- Contacts Tab -->
                <div id="pane-contacts" class="p-5 hidden">
                    <div class="flex items-center justify-between mb-4">
                        <button onclick="loadXeroData('Contacts')"
                            class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 transition" data-testid="button-refresh-contacts">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh Contacts
                        </button>
                        <span id="contacts-count" class="text-sm text-gray-500" data-testid="text-contacts-count"></span>
                    </div>
                    <div id="contacts-loading" class="hidden text-center py-8 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading contacts from Xero…</div>
                    <div id="contacts-table" class="overflow-x-auto"></div>
                </div>

                <!-- Reports Tab -->
                <div id="pane-reports" class="p-5 hidden">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                        <?php foreach ([
                            ['ProfitAndLoss', 'P&L', 'chart-line', 'green'],
                            ['BalanceSheet',  'Balance Sheet', 'balance-scale', 'blue'],
                            ['AgedReceivablesByContact', 'Aged Receivables', 'clock', 'orange'],
                            ['BankSummary',   'Bank Summary', 'university', 'purple'],
                        ] as [$rpt, $label, $icon, $color]): ?>
                        <button onclick="loadReport('<?= $rpt ?>')"
                            class="p-3 border border-gray-200 rounded-lg hover:border-<?= $color ?>-300 hover:bg-<?= $color ?>-50 transition text-left"
                            data-testid="button-report-<?= strtolower(str_replace(' ','',strtolower($label))) ?>">
                            <i class="fas fa-<?= $icon ?> text-<?= $color ?>-500 mb-1"></i>
                            <div class="text-sm font-medium text-gray-700"><?= $label ?></div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div id="report-loading" class="hidden text-center py-8 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading report…</div>
                    <div id="report-output" class="overflow-x-auto"></div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($connected): ?>
<script>
const tabs = ['invoices','contacts','reports'];
function switchTab(tab) {
    tabs.forEach(t => {
        document.getElementById('tab-' + t).className = 'px-5 py-3 text-sm font-medium border-b-2 transition -mb-px ' +
            (t === tab ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700');
        document.getElementById('pane-' + t).classList.toggle('hidden', t !== tab);
    });
}

async function loadXeroData(resource, extraParams = {}) {
    const loadingEl = document.getElementById(resource.toLowerCase() + '-loading');
    const tableEl   = document.getElementById(resource.toLowerCase() + '-table');
    const countEl   = document.getElementById(resource.toLowerCase() + '-count');

    // Switch to the right tab
    const tabMap = { Invoices: 'invoices', Contacts: 'contacts' };
    if (tabMap[resource]) switchTab(tabMap[resource]);

    if (loadingEl) { loadingEl.classList.remove('hidden'); if (tableEl) tableEl.innerHTML = ''; }

    try {
        const params = new URLSearchParams({ resource, ...extraParams });
        const resp = await fetch('/portal/api/xero/data?' + params.toString());
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'Xero API error');

        if (loadingEl) loadingEl.classList.add('hidden');

        if (resource === 'Invoices') renderInvoices(data, tableEl, countEl);
        else if (resource === 'Contacts') renderContacts(data, tableEl, countEl);
    } catch(e) {
        if (loadingEl) loadingEl.classList.add('hidden');
        if (tableEl) tableEl.innerHTML = `<div class="text-red-600 text-sm py-4"><i class="fas fa-exclamation-circle mr-1"></i>${e.message}</div>`;
    }
}

function renderInvoices(data, el, countEl) {
    const inv = data.Invoices || [];
    if (countEl) countEl.textContent = inv.length + ' invoices';
    if (!inv.length) { el.innerHTML = '<p class="text-gray-500 text-sm py-4">No invoices found.</p>'; return; }
    let html = `<table class="w-full text-sm border-collapse">
        <thead><tr class="bg-gray-50 border-b border-gray-200">
            <th class="text-left px-3 py-2 font-medium text-gray-600">Invoice #</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Contact</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Date</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Due</th>
            <th class="text-right px-3 py-2 font-medium text-gray-600">Amount</th>
            <th class="text-right px-3 py-2 font-medium text-gray-600">Balance</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Status</th>
        </tr></thead><tbody>`;
    const statusColors = { AUTHORISED:'blue', PAID:'green', DRAFT:'gray', VOIDED:'red' };
    inv.forEach(i => {
        const c = statusColors[i.Status] || 'gray';
        html += `<tr class="border-b border-gray-100 hover:bg-gray-50">
            <td class="px-3 py-2 font-mono text-xs">${i.InvoiceNumber || i.InvoiceID?.slice(0,8) || '—'}</td>
            <td class="px-3 py-2">${(i.Contact?.Name || '').replace(/</g,'&lt;')}</td>
            <td class="px-3 py-2 text-gray-500">${i.DateString?.split('T')[0] || ''}</td>
            <td class="px-3 py-2 text-gray-500">${i.DueDateString?.split('T')[0] || ''}</td>
            <td class="px-3 py-2 text-right">$${Number(i.Total||0).toFixed(2)}</td>
            <td class="px-3 py-2 text-right font-medium ${i.AmountDue > 0 ? 'text-orange-600' : 'text-green-600'}">$${Number(i.AmountDue||0).toFixed(2)}</td>
            <td class="px-3 py-2"><span class="px-2 py-0.5 bg-${c}-100 text-${c}-700 text-xs rounded-full">${i.Status}</span></td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table>';
}

function renderContacts(data, el, countEl) {
    const contacts = data.Contacts || [];
    if (countEl) countEl.textContent = contacts.length + ' contacts';
    if (!contacts.length) { el.innerHTML = '<p class="text-gray-500 text-sm py-4">No contacts found.</p>'; return; }
    let html = `<table class="w-full text-sm border-collapse">
        <thead><tr class="bg-gray-50 border-b border-gray-200">
            <th class="text-left px-3 py-2 font-medium text-gray-600">Name</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Email</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Phone</th>
            <th class="text-left px-3 py-2 font-medium text-gray-600">Status</th>
        </tr></thead><tbody>`;
    contacts.forEach(c => {
        const email = c.EmailAddress || '';
        const phone = c.Phones?.find(p => p.PhoneNumber)?.PhoneNumber || '';
        html += `<tr class="border-b border-gray-100 hover:bg-gray-50">
            <td class="px-3 py-2 font-medium">${(c.Name||'').replace(/</g,'&lt;')}</td>
            <td class="px-3 py-2 text-gray-600">${email}</td>
            <td class="px-3 py-2 text-gray-600">${phone}</td>
            <td class="px-3 py-2"><span class="px-2 py-0.5 text-xs rounded-full ${c.IsCustomer ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'}">${c.IsCustomer ? 'Customer' : c.IsSupplier ? 'Supplier' : 'Contact'}</span></td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table>';
}

async function loadReport(reportName) {
    const loadingEl = document.getElementById('report-loading');
    const outputEl  = document.getElementById('report-output');
    switchTab('reports');
    loadingEl.classList.remove('hidden');
    outputEl.innerHTML = '';
    try {
        const params = new URLSearchParams({ resource: `Reports/${reportName}` });
        const resp = await fetch('/portal/api/xero/data?' + params.toString());
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'Report error');
        loadingEl.classList.add('hidden');
        const rpts = data.Reports || [];
        if (!rpts.length) { outputEl.innerHTML = '<p class="text-gray-500 text-sm">No report data returned.</p>'; return; }
        let html = '';
        rpts.forEach(rpt => {
            html += `<h3 class="font-semibold text-gray-800 mb-3">${rpt.ReportName || reportName}</h3>`;
            if (rpt.Reports) {
                rpt.Reports.forEach(section => {
                    if (section.Rows) {
                        html += '<table class="w-full text-sm border-collapse mb-4"><tbody>';
                        section.Rows.forEach(row => {
                            if (row.RowType === 'Header') {
                                html += '<tr class="bg-gray-50 border-b border-gray-200">';
                                (row.Cells || []).forEach(c => { html += `<th class="px-3 py-2 text-left font-medium text-gray-600">${c.Value||''}</th>`; });
                                html += '</tr>';
                            } else {
                                const bold = row.RowType === 'SummaryRow' ? 'font-semibold bg-gray-50' : '';
                                html += `<tr class="border-b border-gray-100 ${bold}">`;
                                (row.Cells || []).forEach((c, ci) => { html += `<td class="px-3 py-1.5 ${ci > 0 ? 'text-right' : ''}">${c.Value||''}</td>`; });
                                html += '</tr>';
                            }
                        });
                        html += '</tbody></table>';
                    }
                });
            }
        });
        outputEl.innerHTML = html || '<p class="text-gray-500 text-sm">Report returned no rows.</p>';
    } catch(e) {
        loadingEl.classList.add('hidden');
        outputEl.innerHTML = `<div class="text-red-600 text-sm py-4"><i class="fas fa-exclamation-circle mr-1"></i>${e.message}</div>`;
    }
}

// Auto-load invoices on page load
window.addEventListener('DOMContentLoaded', () => loadXeroData('Invoices', { Statuses: 'AUTHORISED' }));
</script>
<?php endif; ?>
</body>
</html>
