<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo        = getDB();
$page_title = 'Domains & Certificates';

$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

/**
 * Talk to the Node API on the loopback listener, forwarding this page's session
 * cookie. The Node side owns the RDAP/TLS probes — PHP never calls the internet.
 */
function dm_api(string $origin, string $path, ?array $body = null): array {
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST]         = true;
        $opts[CURLOPT_POSTFIELDS]   = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['error' => $err ?: 'API request failed'];
    $json = json_decode((string)$resp, true);
    return is_array($json) ? $json : ['error' => 'Unexpected API response'];
}

$success = '';
$error   = '';
$history = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_domain') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains', [
            'name'        => trim($_POST['name'] ?? ''),
            'client_id'   => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'registrar'   => trim($_POST['registrar'] ?? ''),
            'expires_at'  => trim($_POST['expires_at'] ?? ''),
            'auto_renew'  => isset($_POST['auto_renew']),
            'notes'       => trim($_POST['notes'] ?? ''),
        ]);
        if (!empty($r['success'])) $success = 'Domain added (#' . (int)$r['id'] . '). Use “Check now” to read its live registry expiry.';
        else                       $error   = $r['error'] ?? 'Could not add that domain.';

    } elseif ($action === 'add_cert') {
        $r = dm_api($internalOrigin, '/portal/api/admin/certificates', [
            'hostname'  => trim($_POST['hostname'] ?? ''),
            'client_id' => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'port'      => ($_POST['port'] ?? '') !== '' ? (int)$_POST['port'] : 443,
            'notes'     => trim($_POST['cert_notes'] ?? ''),
        ]);
        if (!empty($r['success'])) $success = 'Certificate target added (#' . (int)$r['id'] . ').';
        else                       $error   = $r['error'] ?? 'Could not add that hostname.';

    } elseif ($action === 'check_all') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains/check', []);
        if (!empty($r['success'])) {
            $success = 'Probe complete: ' . (int)($r['ok'] ?? 0) . ' record(s) checked, ' . (int)($r['changed'] ?? 0) . ' date change(s) recorded, ' . count($r['errors'] ?? []) . ' error(s).';
            if (!empty($r['errors'])) {
                $msgs = [];
                foreach (array_slice($r['errors'], 0, 6) as $e) $msgs[] = $e['item'] . ': ' . $e['error'];
                $error = 'Probe errors — ' . implode(' · ', $msgs);
            }
        } else { $error = $r['error'] ?? 'Probe failed.'; }

    } elseif ($action === 'check_one') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains/check', ['domain_id' => (int)($_POST['id'] ?? 0)]);
        if (!empty($r['success'])) {
            $success = 'Checked ' . (int)($r['ok'] ?? 0) . ' record(s).';
            if (!empty($r['errors'])) $error = $r['errors'][0]['item'] . ': ' . $r['errors'][0]['error'];
        } else { $error = $r['error'] ?? 'Probe failed.'; }

    } elseif ($action === 'check_cert') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains/check', ['certificate_id' => (int)($_POST['id'] ?? 0)]);
        if (!empty($r['success'])) {
            $success = 'Checked ' . (int)($r['ok'] ?? 0) . ' certificate(s).';
            if (!empty($r['errors'])) $error = $r['errors'][0]['item'] . ': ' . $r['errors'][0]['error'];
        } else { $error = $r['error'] ?? 'Probe failed.'; }

    } elseif ($action === 'save_domain') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains/' . (int)($_POST['id'] ?? 0) . '/update', [
            'client_id'  => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'registrar'  => trim($_POST['registrar'] ?? ''),
            'expires_at' => trim($_POST['expires_at'] ?? ''),
            'auto_renew' => isset($_POST['auto_renew']),
            'notes'      => trim($_POST['notes'] ?? ''),
        ]);
        if (!empty($r['success'])) $success = 'Domain updated — the change is recorded in its history.';
        else                       $error   = $r['error'] ?? 'Could not update that domain.';

    } elseif ($action === 'history') {
        $r = dm_api($internalOrigin, '/portal/api/admin/domains/' . (int)($_POST['id'] ?? 0) . '/history');
        if (isset($r['history'])) { $history = $r['history']; }
        else                      { $error = $r['error'] ?? 'Could not load that history.'; }
    }
}

$q         = trim($_GET['q'] ?? '');
$bucket    = trim($_GET['bucket'] ?? '');
$clientF   = trim($_GET['client_id'] ?? '');
$query     = http_build_query(array_filter(['q' => $q, 'bucket' => $bucket, 'client_id' => $clientF]));

$status  = dm_api($internalOrigin, '/portal/api/admin/domains/status');
$buckets = $status['buckets'] ?? ['domains' => [], 'certificates' => []];
$lastRun = $status['last_run'] ?? null;
$bErr    = $status['error'] ?? '';

$list        = dm_api($internalOrigin, '/portal/api/admin/domains' . ($query ? '?' . $query : ''));
$domains     = $list['domains'] ?? [];
$listError   = $list['error'] ?? '';

$certList   = dm_api($internalOrigin, '/portal/api/admin/certificates' . ($clientF ? '?client_id=' . (int)$clientF : ''));
$certs      = $certList['certificates'] ?? [];
$certError  = $certList['error'] ?? '';

$clients = [];
try {
    $clients = $pdo->query("SELECT id, name, email FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $clients = []; }

/** Colour + label for a days-left value. Mirrors the 30/14/7 buckets the API reports. */
function dm_badge($days) {
    if ($days === null || $days === '') return ['bg-gray-100 text-gray-600 border-gray-200', 'no date yet'];
    $d = (int)$days;
    if ($d < 0)   return ['bg-red-100 text-red-700 border-red-200',       'EXPIRED ' . abs($d) . 'd ago'];
    if ($d <= 7)  return ['bg-red-100 text-red-700 border-red-200',       $d . ' days left'];
    if ($d <= 14) return ['bg-orange-100 text-orange-700 border-orange-200', $d . ' days left'];
    if ($d <= 30) return ['bg-amber-100 text-amber-700 border-amber-200', $d . ' days left'];
    return ['bg-green-100 text-green-700 border-green-200', $d . ' days left'];
}

$bTotal = (int)($buckets['domains']['total'] ?? 0) + (int)($buckets['certificates']['total'] ?? 0);
$bAtRisk = (int)($buckets['domains']['expired'] ?? 0) + (int)($buckets['domains']['d7'] ?? 0)
         + (int)($buckets['certificates']['expired'] ?? 0) + (int)($buckets['certificates']['d7'] ?? 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domains &amp; Certificates — Blue Mogul Admin</title>
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-globe text-amber-500 mr-2"></i>Domains &amp; Certificates</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Registry registrations and TLS certificates — probed daily, every date change kept in history</p>
                </div>
                <div class="flex items-center gap-2">
                    <form method="post" class="inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="check_all">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="btn-check-all">
                            <i class="fas fa-rotate mr-1"></i>Check now
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="p-6 space-y-6">

            <?php if ($success): ?>
              <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm" data-testid="msg-success"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
              <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm" data-testid="msg-error"><i class="fas fa-triangle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($bErr): ?>
              <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">Probe service unavailable: <?php echo htmlspecialchars($bErr); ?></div>
            <?php endif; ?>

            <!-- Expiry buckets -->
            <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <?php
                $cards = [
                    ['Expired',   $buckets['domains']['expired'] ?? 0, 'text-red-600'],
                    ['≤ 7 days',  $buckets['domains']['d7'] ?? 0,      'text-red-600'],
                    ['≤ 14 days', $buckets['domains']['d14'] ?? 0,     'text-orange-600'],
                    ['≤ 30 days', $buckets['domains']['d30'] ?? 0,     'text-amber-600'],
                    ['No date yet', (int)($buckets['domains']['unknown'] ?? 0) + (int)($buckets['certificates']['unknown'] ?? 0), 'text-gray-600'],
                    ['Tracked',   $bTotal,                            'text-gray-900'],
                ];
                foreach ($cards as $c): ?>
                  <div class="bg-white rounded-xl border border-gray-200 p-4">
                      <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo htmlspecialchars($c[0]); ?></div>
                      <div class="text-2xl font-semibold <?php echo $c[2]; ?>" data-testid="bucket-<?php echo strtolower(preg_replace('/[^a-z0-9]+/i', '-', $c[0])); ?>"><?php echo (int)$c[1]; ?></div>
                  </div>
                <?php endforeach; ?>
            </section>

            <?php if ($bAtRisk > 0): ?>
              <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-800">
                  <i class="fas fa-bell mr-2"></i><strong><?php echo $bAtRisk; ?></strong> record(s) expire within 7 days — domains and certificates below.
              </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
                <?php if (!empty($lastRun['created_at'])): ?>
                  <span><i class="fas fa-clock mr-1"></i>Last probe <?php echo htmlspecialchars((string)$lastRun['created_at']); ?>
                    — <?php echo (int)$lastRun['ok_count']; ?> ok / <?php echo (int)$lastRun['changed_count']; ?> changed / <?php echo (int)$lastRun['error_count']; ?> error</span>
                <?php else: ?>
                  <span><i class="fas fa-clock mr-1"></i>No probe has run yet on this deployment.</span>
                <?php endif; ?>
            </div>

            <!-- Add forms -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-plus-circle text-blue-600 mr-2"></i>Track a domain</h2>
                    <form method="post" class="space-y-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_domain">
                        <input name="name" required placeholder="example.com" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-domain-name">
                        <div class="grid grid-cols-2 gap-3">
                            <select name="client_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option value="">— no client —</option>
                                <?php foreach ($clients as $c): ?>
                                  <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="registrar" placeholder="registrar (optional)" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <input name="expires_at" type="date" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" title="Optional — the daily probe fills this from the registry">
                            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="auto_renew" value="1" checked> auto-renew</label>
                        </div>
                        <input name="notes" placeholder="notes (optional)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium" data-testid="btn-add-domain">Add domain</button>
                    </form>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-lock text-blue-600 mr-2"></i>Track a TLS certificate</h2>
                    <form method="post" class="space-y-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_cert">
                        <div class="grid grid-cols-3 gap-3">
                            <input name="hostname" required placeholder="host.example.com" class="col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-cert-host">
                            <input name="port" type="number" value="443" min="1" max="65535" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <select name="client_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">— no client —</option>
                            <?php foreach ($clients as $c): ?>
                              <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="cert_notes" placeholder="notes (optional)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium" data-testid="btn-add-cert">Add certificate target</button>
                    </form>
                </div>
            </section>

            <!-- Filters -->
            <form method="get" class="flex flex-wrap items-center gap-3">
                <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search domain" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <select name="bucket" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All dates</option>
                    <?php foreach (['expired' => 'Expired', 'd7' => '≤ 7 days', 'd14' => '≤ 14 days', 'd30' => '≤ 30 days', 'unknown' => 'No date yet'] as $k => $lbl): ?>
                      <option value="<?php echo $k; ?>" <?php echo $bucket === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="client_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All clients</option>
                    <?php foreach ($clients as $c): ?>
                      <option value="<?php echo (int)$c['id']; ?>" <?php echo (string)$clientF === (string)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg text-sm">Filter</button>
                <?php if ($query): ?><a href="admin-domains.php" class="text-sm text-gray-500 underline">reset</a><?php endif; ?>
            </form>

            <?php if ($history): ?>
              <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                  <div class="text-sm font-semibold text-amber-900 mb-2"><i class="fas fa-clock-rotate-left mr-2"></i>Change history</div>
                  <table class="w-full text-xs">
                      <thead><tr class="text-left text-amber-800"><th class="py-1">When</th><th>Field</th><th>From</th><th>To</th></tr></thead>
                      <tbody>
                      <?php foreach ($history as $h): ?>
                        <tr class="border-t border-amber-200">
                            <td class="py-1"><?php echo htmlspecialchars((string)$h['at']); ?></td>
                            <td><?php echo htmlspecialchars((string)$h['field']); ?></td>
                            <td><?php echo htmlspecialchars((string)($h['old_value'] ?? '—')); ?></td>
                            <td class="font-medium"><?php echo htmlspecialchars((string)($h['new_value'] ?? '—')); ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
            <?php endif; ?>

            <!-- Domains -->
            <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Domains</h2>
                    <span class="text-xs text-gray-500"><?php echo count($domains); ?> record(s)</span>
                </div>
                <?php if ($listError): ?>
                  <div class="px-5 py-6 text-sm text-red-700"><?php echo htmlspecialchars($listError); ?></div>
                <?php elseif (!$domains): ?>
                  <div class="px-5 py-10 text-center text-sm text-gray-500">No domains tracked yet. Add one above — the daily probe will read its registry expiry automatically.</div>
                <?php else: ?>
                  <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-domains">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                          <tr>
                            <th class="text-left px-5 py-3">Domain</th>
                            <th class="text-left px-3 py-3">Client</th>
                            <th class="text-left px-3 py-3">Registrar</th>
                            <th class="text-left px-3 py-3">Expires</th>
                            <th class="text-left px-3 py-3">Status</th>
                            <th class="text-right px-5 py-3">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($domains as $d):
                            [$cls, $label] = dm_badge($d['days_left'] ?? null); ?>
                          <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars((string)$d['name']); ?></div>
                                <?php if (!empty($d['last_check_error'])): ?>
                                  <div class="text-xs text-red-600 mt-0.5"><i class="fas fa-circle-exclamation mr-1"></i><?php echo htmlspecialchars((string)$d['last_check_error']); ?></div>
                                <?php elseif (!empty($d['last_checked_at'])): ?>
                                  <div class="text-xs text-gray-400 mt-0.5">checked <?php echo htmlspecialchars((string)$d['last_checked_at']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 text-gray-600"><?php echo htmlspecialchars((string)($d['client_name'] ?? '—')); ?></td>
                            <td class="px-3 py-3 text-gray-600"><?php echo htmlspecialchars((string)($d['registrar'] ?? '—')); ?></td>
                            <td class="px-3 py-3">
                                <div class="text-gray-800"><?php echo htmlspecialchars($d['expires_at'] ? substr((string)$d['expires_at'], 0, 10) : '—'); ?></div>
                                <?php if (!empty($d['auto_renew'])): ?><div class="text-xs text-gray-400">auto-renew</div><?php endif; ?>
                            </td>
                            <td class="px-3 py-3">
                                <span class="px-2 py-1 rounded-full border text-xs font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars($label); ?></span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="check_one">
                                    <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                    <button class="text-blue-600 hover:underline text-xs mr-3" data-testid="btn-check-<?php echo (int)$d['id']; ?>">Check</button>
                                </form>
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="history">
                                    <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                    <button class="text-gray-600 hover:underline text-xs">History</button>
                                </form>
                                <?php if (!empty($d['registry_status'])): ?>
                                  <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars((string)$d['registry_status']); ?></div>
                                <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                  </div>
                <?php endif; ?>
            </section>

            <!-- Certificates -->
            <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">TLS certificates</h2>
                    <span class="text-xs text-gray-500"><?php echo count($certs); ?> record(s)</span>
                </div>
                <?php if ($certError): ?>
                  <div class="px-5 py-6 text-sm text-red-700"><?php echo htmlspecialchars($certError); ?></div>
                <?php elseif (!$certs): ?>
                  <div class="px-5 py-10 text-center text-sm text-gray-500">No certificate targets yet. Add a hostname above and the daily TLS handshake will record its expiry.</div>
                <?php else: ?>
                  <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-certificates">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                          <tr>
                            <th class="text-left px-5 py-3">Host</th>
                            <th class="text-left px-3 py-3">Client</th>
                            <th class="text-left px-3 py-3">Issuer</th>
                            <th class="text-left px-3 py-3">Expires</th>
                            <th class="text-right px-5 py-3">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($certs as $t):
                            [$cls, $label] = dm_badge($t['days_left'] ?? null); ?>
                          <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars((string)$t['hostname']); ?><?php echo ((int)($t['port'] ?? 443) !== 443) ? ':' . (int)$t['port'] : ''; ?></div>
                                <?php if (!empty($t['last_check_error'])): ?>
                                  <div class="text-xs text-red-600 mt-0.5"><i class="fas fa-circle-exclamation mr-1"></i><?php echo htmlspecialchars((string)$t['last_check_error']); ?></div>
                                <?php elseif (!empty($t['sans'])): ?>
                                  <div class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars(mb_substr((string)$t['sans'], 0, 90)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 text-gray-600"><?php echo htmlspecialchars((string)($t['client_name'] ?? '—')); ?></td>
                            <td class="px-3 py-3 text-gray-600"><?php echo htmlspecialchars((string)($t['issuer'] ?? '—')); ?></td>
                            <td class="px-3 py-3">
                                <div class="text-gray-800"><?php echo htmlspecialchars($t['expires_at'] ? substr((string)$t['expires_at'], 0, 10) : '—'); ?></div>
                                <span class="px-2 py-0.5 rounded-full border text-xs font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars($label); ?></span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="check_cert">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button class="text-blue-600 hover:underline text-xs" data-testid="btn-check-cert-<?php echo (int)$t['id']; ?>">Check</button>
                                </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                  </div>
                <?php endif; ?>
            </section>

        </main>
    </div>
</div>
</body>
</html>