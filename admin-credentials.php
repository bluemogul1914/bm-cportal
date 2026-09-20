<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo        = getDB();
$page_title = 'Credentials';

$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

/**
 * Talk to the Node API on the loopback listener, forwarding this page's session
 * cookie. Pages never call secrets directly — the Node side owns encryption.
 */
function cv_api(string $origin, string $path, ?array $body = null): array {
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST]           = true;
        $opts[CURLOPT_POSTFIELDS]     = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][]   = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['error' => $err ?: 'API request failed'];
    $json = json_decode((string)$resp, true);
    return is_array($json) ? $json : ['error' => 'Unexpected API response'];
}

$success      = '';
$error        = '';
$revealed     = null;   // plaintext shown ONCE, right after an audited reveal
$revealed_at  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $r = cv_api($internalOrigin, '/portal/api/admin/credentials', [
            'label'         => trim($_POST['label'] ?? ''),
            'client_id'     => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'username'      => trim($_POST['username'] ?? ''),
            'secret'        => (string)($_POST['secret'] ?? ''),
            'otp_secret'    => trim($_POST['otp_secret'] ?? ''),
            'url'           => trim($_POST['url'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
            'category'      => trim($_POST['category'] ?? 'general'),
            'rotation_days' => ($_POST['rotation_days'] ?? '') !== '' ? (int)$_POST['rotation_days'] : null,
            'tags'          => trim($_POST['tags'] ?? ''),
        ]);
        if (!empty($r['success'])) $success = 'Credential #' . (int)$r['id'] . ' stored — secret encrypted at rest.';
        else                       $error   = $r['error'] ?? 'Could not store the credential.';

    } elseif ($action === 'reveal') {
        $kind = ($_POST['kind'] ?? 'secret') === 'otp' ? 'otp' : 'secret';
        $r = cv_api($internalOrigin, '/portal/api/admin/credentials/' . (int)($_POST['id'] ?? 0) . '/reveal', ['kind' => $kind]);
        if (!empty($r['success'])) { $revealed = $r; $revealed_at = date('H:i:s'); }
        else                       $error = $r['error'] ?? 'Could not reveal that credential.';

    } elseif ($action === 'rotate') {
        $r = cv_api($internalOrigin, '/portal/api/admin/credentials/' . (int)($_POST['id'] ?? 0) . '/rotate', [
            'secret'     => (string)($_POST['new_secret'] ?? ''),
            'otp_secret' => trim($_POST['new_otp'] ?? ''),
        ]);
        if (!empty($r['success'])) $success = 'Rotated "' . htmlspecialchars((string)($r['label'] ?? '')) . '". A new value is stored and the rotation date is stamped.';
        else                       $error   = $r['error'] ?? 'Could not rotate that credential.';
    }
}

$status = cv_api($internalOrigin, '/portal/api/admin/credentials/status');
$vault_ready = !empty($status['vault_ready']);

$q         = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$listPath  = '/portal/api/admin/credentials?' . http_build_query(array_filter(['q' => $q, 'category' => $catFilter]));
$list      = cv_api($internalOrigin, $listPath);
$creds     = $list['credentials'] ?? [];
$listError = $list['error'] ?? '';

$clients = [];
try {
    $clients = $pdo->query("SELECT id, name, email FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $clients = []; }

$categories = ['general' => 'General', 'server' => 'Server', 'network' => 'Network', 'router' => 'Router / Firewall',
               'cloud' => 'Cloud / SaaS', 'email' => 'Email', 'database' => 'Database', 'vpn' => 'VPN',
               'website' => 'Website / Hosting', 'other' => 'Other'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credentials — Blue Mogul Admin</title>
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-key text-amber-500 mr-2"></i>Credentials Vault</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Client passwords, keys and OTP secrets — encrypted at rest, every reveal logged</p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <?php if ($vault_ready): ?>
                      <span class="px-3 py-1 rounded-full bg-green-100 text-green-700 font-semibold"><i class="fas fa-lock mr-1"></i>Vault ready</span>
                    <?php else: ?>
                      <span class="px-3 py-1 rounded-full bg-red-100 text-red-700 font-semibold"><i class="fas fa-triangle-exclamation mr-1"></i>Vault unavailable</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="p-6 space-y-5">

            <?php if (!$vault_ready): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm" data-testid="alert-vault-down">
                <strong>Encryption key unavailable.</strong> The vault derives its key from <code>PORTAL_SECRET</code>, which is not set for this service.
                Nothing will be stored until that is configured — the vault refuses to fall back to plaintext.
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 text-sm" data-testid="alert-success"><i class="fas fa-check-circle mr-1"></i><?= $success ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm" data-testid="alert-error"><i class="fas fa-circle-exclamation mr-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($listError): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 text-sm">Could not load the list: <?= htmlspecialchars($listError) ?></div>
            <?php endif; ?>

            <?php if ($revealed): ?>
            <div class="bg-slate-900 text-slate-100 rounded-xl p-4" data-testid="panel-revealed">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm font-semibold">
                        <i class="fas fa-eye text-amber-400 mr-1"></i>
                        <?= $revealed['kind'] === 'otp' ? 'OTP secret' : 'Secret' ?> for “<?= htmlspecialchars((string)$revealed['label']) ?>”
                    </div>
                    <span class="text-[11px] text-slate-400">revealed at <?= $revealed_at ?> — logged to the audit trail</span>
                </div>
                <div class="font-mono text-sm break-all bg-slate-800 rounded-lg p-3"><?= htmlspecialchars((string)$revealed['value']) ?></div>
                <div class="text-[11px] text-slate-400 mt-2">Copy it now — it is not stored anywhere in plaintext and re-revealing writes another audit row.</div>
            </div>
            <?php endif; ?>

            <!-- Summary -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Credentials</div>
                    <div class="text-2xl font-semibold text-gray-900"><?= (int)($status['total'] ?? 0) ?></div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Clients covered</div>
                    <div class="text-2xl font-semibold text-gray-900"><?= (int)($status['clients'] ?? 0) ?></div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">With OTP</div>
                    <div class="text-2xl font-semibold text-gray-900"><?= (int)($status['with_otp'] ?? 0) ?></div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Due for rotation</div>
                    <div class="text-2xl font-semibold <?= ((int)($status['due_for_rotation'] ?? 0) > 0) ? 'text-amber-600' : 'text-gray-900' ?>"><?= (int)($status['due_for_rotation'] ?? 0) ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Create -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-100">
                            <h2 class="font-semibold text-gray-900 text-sm"><i class="fas fa-plus text-blue-500 mr-1"></i>Add a credential</h2>
                        </div>
                        <form method="POST" class="p-5 space-y-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Client</label>
                                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-client">
                                    <option value="">— none / internal —</option>
                                    <?php foreach ($clients as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name'] ?: $c['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Label <span class="text-red-500">*</span></label>
                                <input type="text" name="label" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. Router admin" data-testid="input-label">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Username</label>
                                    <input type="text" name="username" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-username">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Category</label>
                                    <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-category">
                                        <?php foreach ($categories as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Password / secret</label>
                                <input type="password" name="secret" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono" placeholder="stored encrypted, never displayed again" data-testid="input-secret">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">OTP / 2FA secret <span class="text-gray-400 font-normal">(optional)</span></label>
                                <input type="text" name="otp_secret" autocomplete="off" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono" placeholder="base32 seed" data-testid="input-otp">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">URL / host</label>
                                <input type="text" name="url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="https://…" data-testid="input-url">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Rotate every (days)</label>
                                    <input type="number" name="rotation_days" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="90" data-testid="input-rotation-days">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Tags</label>
                                    <input type="text" name="tags" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="comma,separated" data-testid="input-tags">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
                                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-notes"></textarea>
                            </div>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-lg text-sm" data-testid="button-create-credential">
                                <i class="fas fa-lock mr-1"></i>Encrypt &amp; store
                            </button>
                            <p class="text-[11px] text-gray-400">AES-256-GCM with a key derived from <code>PORTAL_SECRET</code>. The key is never stored in the database, and the plaintext is discarded after this request.</p>
                        </form>
                    </div>
                </div>

                <!-- List -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                            <h2 class="font-semibold text-gray-900 text-sm"><i class="fas fa-list text-gray-400 mr-1"></i>Stored credentials</h2>
                            <form method="GET" class="flex items-center gap-2">
                                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="search label / user / client" class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs w-56" data-testid="input-search-credentials">
                                <select name="category" class="px-2 py-1.5 border border-gray-300 rounded-lg text-xs" data-testid="select-filter-category">
                                    <option value="">All categories</option>
                                    <?php foreach ($categories as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $catFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs hover:bg-gray-50" data-testid="button-search-credentials"><i class="fas fa-magnifying-glass"></i></button>
                            </form>
                        </div>

                        <?php if (!$creds): ?>
                        <p class="px-5 py-10 text-center text-gray-400 text-sm" data-testid="text-no-credentials">
                            No credentials stored yet<?= ($q || $catFilter) ? ' matching that filter' : '' ?>. Add the first one on the left.
                        </p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Client</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Label</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Username</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Category</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Rotation</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($creds as $cr): ?>
                                    <tr data-testid="row-credential-<?= (int)$cr['id'] ?>">
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($cr['client_name'] ?: '— internal —') ?></td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars((string)$cr['label']) ?></div>
                                            <?php if (!empty($cr['url'])): ?>
                                            <div class="text-[11px] text-gray-400"><?= htmlspecialchars((string)$cr['url']) ?></div>
                                            <?php endif; ?>
                                            <?php if (empty($cr['has_secret'])): ?>
                                            <span class="text-[11px] text-amber-600">no secret stored</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 font-mono text-xs"><?= htmlspecialchars((string)($cr['username'] ?? '—')) ?></td>
                                        <td class="px-4 py-3">
                                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= htmlspecialchars((string)$cr['category']) ?></span>
                                            <?php if (!empty($cr['has_otp'])): ?>
                                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 ml-1">OTP</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <?php $rot = $cr['rotation'] ?? ['state' => 'none', 'days' => null]; ?>
                                            <?php if (($rot['state'] ?? 'none') === 'due'): ?>
                                              <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold">due (<?= (int)$rot['days'] ?>d)</span>
                                            <?php elseif (($rot['state'] ?? 'none') === 'ok'): ?>
                                              <span class="text-gray-500"><?= (int)$rot['days'] ?>d / <?= (int)$cr['rotation_days'] ?>d</span>
                                            <?php else: ?>
                                              <span class="text-gray-300">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-2 flex-wrap">
                                                <?php if (!empty($cr['has_secret'])): ?>
                                                <form method="POST">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reveal">
                                                    <input type="hidden" name="id" value="<?= (int)$cr['id'] ?>">
                                                    <input type="hidden" name="kind" value="secret">
                                                    <button class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50" data-testid="button-reveal-<?= (int)$cr['id'] ?>"><i class="fas fa-eye mr-1"></i>Reveal</button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if (!empty($cr['has_otp'])): ?>
                                                <form method="POST">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reveal">
                                                    <input type="hidden" name="id" value="<?= (int)$cr['id'] ?>">
                                                    <input type="hidden" name="kind" value="otp">
                                                    <button class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50" data-testid="button-reveal-otp-<?= (int)$cr['id'] ?>"><i class="fas fa-shield-halved mr-1"></i>OTP</button>
                                                </form>
                                                <?php endif; ?>
                                                <details class="relative">
                                                    <summary class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50 cursor-pointer list-none" data-testid="button-rotate-open-<?= (int)$cr['id'] ?>"><i class="fas fa-rotate mr-1"></i>Rotate</summary>
                                                    <form method="POST" class="absolute right-0 mt-2 z-20 bg-white border border-gray-200 rounded-xl shadow-lg p-4 w-72 space-y-2">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="rotate">
                                                        <input type="hidden" name="id" value="<?= (int)$cr['id'] ?>">
                                                        <div class="text-xs font-semibold text-gray-700">Rotate “<?= htmlspecialchars((string)$cr['label']) ?>”</div>
                                                        <input type="password" name="new_secret" autocomplete="new-password" placeholder="new password / secret" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs font-mono" data-testid="input-new-secret-<?= (int)$cr['id'] ?>">
                                                        <input type="text" name="new_otp" autocomplete="off" placeholder="new OTP seed (optional)" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs font-mono">
                                                        <button class="w-full bg-slate-900 text-white rounded-lg px-3 py-2 text-xs font-semibold" data-testid="button-rotate-<?= (int)$cr['id'] ?>">Store new secret</button>
                                                    </form>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-5 py-3 border-t border-gray-100 text-[11px] text-gray-400">
                            Secrets are never sent to this page: the list shows metadata only. Revealing is a separate, audited action.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
</body>
</html>
