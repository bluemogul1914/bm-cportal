<?php
require_once 'config.php';
require_once 'includes/email.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$user_id   = intval($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? 'Admin';
$is_admin  = $_SESSION['is_admin'] ?? false;
$pdo = getDB();

// ─── DB Bootstrap ─────────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_email_settings (
            id           SERIAL PRIMARY KEY,
            user_id      INTEGER NOT NULL UNIQUE,
            work_email   VARCHAR(200) DEFAULT '',
            display_name VARCHAR(200) DEFAULT '',
            receive_group BOOLEAN DEFAULT true,
            created_at   TIMESTAMP DEFAULT NOW(),
            updated_at   TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS mail_group_members (
            id          SERIAL PRIMARY KEY,
            group_slug  VARCHAR(50) NOT NULL,
            user_id     INTEGER NOT NULL,
            assigned_by INTEGER,
            assigned_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(group_slug, user_id)
        );
    ");
} catch(Exception $e) {}

$MAILBOXES = [
    'staff'   => ['label'=>'Staff',   'icon'=>'fa-users',      'color'=>'#2563eb'],
    'dealers' => ['label'=>'Dealers', 'icon'=>'fa-handshake',  'color'=>'#059669'],
    'sales'   => ['label'=>'Sales',   'icon'=>'fa-chart-line', 'color'=>'#d97706'],
];

$success = ''; $error = '';

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── Save personal email settings
    if ($action === 'save_profile') {
        $target_uid   = $is_admin && isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : $user_id;
        $work_email   = trim(filter_var($_POST['work_email']   ?? '', FILTER_SANITIZE_EMAIL));
        $display_name = trim(htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES));
        $recv_group   = isset($_POST['receive_group']) ? true : false;

        try {
            $pdo->prepare("
                INSERT INTO user_email_settings (user_id, work_email, display_name, receive_group, updated_at)
                VALUES (?,?,?,?,NOW())
                ON CONFLICT (user_id) DO UPDATE SET work_email=EXCLUDED.work_email, display_name=EXCLUDED.display_name, receive_group=EXCLUDED.receive_group, updated_at=NOW()
            ")->execute([$target_uid, $work_email, $display_name, $recv_group ? 'true' : 'false']);
            $success = 'Email profile saved.';
        } catch(Exception $e) { $error = 'Save failed: '.$e->getMessage(); }
    }

    // ── Admin: save group assignments for a user
    if ($action === 'save_groups' && $is_admin) {
        $target_uid  = intval($_POST['target_user_id'] ?? $user_id);
        $new_groups  = $_POST['groups'] ?? [];
        $valid_slugs = array_keys($MAILBOXES);
        $new_groups  = array_filter($new_groups, fn($g) => in_array($g, $valid_slugs));

        try {
            $pdo->prepare("DELETE FROM mail_group_members WHERE user_id=?")->execute([$target_uid]);
            foreach ($new_groups as $g) {
                $pdo->prepare("INSERT INTO mail_group_members (group_slug, user_id, assigned_by) VALUES (?,?,?) ON CONFLICT DO NOTHING")
                    ->execute([$g, $target_uid, $user_id]);
            }
            $success = 'Group memberships updated.';
        } catch(Exception $e) { $error = 'Group save failed: '.$e->getMessage(); }
    }

    // ── Test email
    if ($action === 'test_email') {
        $target_uid = $is_admin && isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : $user_id;
        $cfg = $pdo->prepare("SELECT * FROM user_email_settings WHERE user_id=?");
        $cfg->execute([$target_uid]); $cfg = $cfg->fetch(PDO::FETCH_ASSOC);
        $work_email = $cfg['work_email'] ?? '';
        $disp_name  = $cfg['display_name'] ?? $user_name;
        if (!$work_email) {
            $error = 'No work email configured.';
        } else {
            $result = send_email_as(
                $work_email, 'Test Email — Blue Mogul Portal',
                '<p>This is a test email from <strong>Blue Mogul Admin Portal</strong>.</p><p>Your work email is correctly configured and ready to send.</p>',
                $work_email, $disp_name
            );
            if ($result['success']) $success = "Test email sent to $work_email ✓";
            else $error = 'Test failed: '.$result['error'];
        }
    }
}

// ─── Load all admin users (for admin view) ────────────────────────────────────
$all_users = [];
if ($is_admin) {
    try {
        $us = $pdo->query("SELECT u.id, u.name, u.email, u.role,
            ues.work_email, ues.display_name, ues.receive_group
            FROM users u
            LEFT JOIN user_email_settings ues ON ues.user_id = u.id
            WHERE u.is_admin = true
            ORDER BY u.name");
        $all_users = $us->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// Current viewing user (admin can view/edit any, others see own)
$view_uid = $is_admin && isset($_GET['uid']) ? intval($_GET['uid']) : $user_id;
if (!$is_admin) $view_uid = $user_id;

// Load settings for view_uid
$view_cfg = [];
try {
    $vs = $pdo->prepare("SELECT ues.*, u.name, u.email FROM user_email_settings ues
        JOIN users u ON u.id = ues.user_id WHERE ues.user_id=?");
    $vs->execute([$view_uid]); $view_cfg = $vs->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($view_cfg)) {
        // Load just from users table
        $vu = $pdo->prepare("SELECT name, email FROM users WHERE id=?");
        $vu->execute([$view_uid]); $vu = $vu->fetch(PDO::FETCH_ASSOC) ?: [];
        $view_cfg['name']  = $vu['name'] ?? '';
        $view_cfg['email'] = $vu['email'] ?? '';
        $view_cfg['work_email'] = '';
        $view_cfg['display_name'] = $vu['name'] ?? '';
        $view_cfg['receive_group'] = true;
    }
} catch(Exception $e) {}

// Load groups for view_uid
$view_groups = [];
try {
    $vg = $pdo->prepare("SELECT group_slug FROM mail_group_members WHERE user_id=?");
    $vg->execute([$view_uid]);
    $view_groups = array_column($vg->fetchAll(PDO::FETCH_ASSOC), 'group_slug');
} catch(Exception $e) {}

// Group member counts
$group_counts = [];
try {
    $gc = $pdo->query("SELECT group_slug, COUNT(*) as cnt FROM mail_group_members GROUP BY group_slug");
    foreach($gc->fetchAll(PDO::FETCH_ASSOC) as $r) $group_counts[$r['group_slug']] = (int)$r['cnt'];
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Profile | Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>body{font-family:Inter,system-ui,sans-serif;}</style>
</head>
<body class="bg-gray-100 min-h-screen flex">

<?php require_once 'includes/admin-sidebar.php'; ?>

<div class="flex-1 overflow-y-auto">
<div class="max-w-5xl mx-auto px-6 py-8">

    <!-- Header -->
    <div class="flex items-center gap-4 mb-8">
        <a href="admin-mail.php" class="text-gray-400 hover:text-gray-700 transition"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Email Profile Settings</h1>
            <p class="text-sm text-gray-500 mt-0.5">Link your work email address and configure group memberships</p>
        </div>
        <a href="admin-mail.php" class="ml-auto flex items-center gap-2 text-sm bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition">
            <i class="fas fa-inbox"></i> Back to Mail
        </a>
    </div>

    <?php if ($success): ?>
    <div class="mb-5 flex items-center gap-2 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-5 flex items-center gap-2 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 <?= $is_admin ? 'lg:grid-cols-3' : '' ?> gap-6">

    <!-- ── Left: Staff user list (admin only) ── -->
    <?php if ($is_admin): ?>
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900 text-sm"><i class="fas fa-users text-blue-500 mr-1.5"></i>Staff Members</h2>
            </div>
            <div class="divide-y divide-gray-50 max-h-[600px] overflow-y-auto">
                <?php foreach ($all_users as $au): ?>
                <a href="admin-mail-profile.php?uid=<?= $au['id'] ?>"
                   class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition <?= $au['id']==$view_uid?'bg-blue-50 border-l-2 border-blue-600':'' ?>"
                   data-testid="user-link-<?= $au['id'] ?>">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0"
                         style="background:#2563eb">
                        <?= strtoupper(substr($au['name'],0,1)) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($au['name']) ?></div>
                        <div class="text-xs text-gray-400 truncate"><?= htmlspecialchars($au['work_email'] ?: $au['email']) ?></div>
                    </div>
                    <?php if ($au['work_email']): ?>
                    <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0" title="Work email configured"></span>
                    <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-gray-300 flex-shrink-0" title="No work email"></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Group summary -->
        <div class="bg-white rounded-xl border border-gray-200 mt-4 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900 text-sm"><i class="fas fa-layer-group text-indigo-500 mr-1.5"></i>Group Overview</h2>
            </div>
            <div class="p-4 space-y-3">
                <?php foreach ($MAILBOXES as $slug=>$mb): ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs" style="background:<?= $mb['color'] ?>">
                        <i class="fas <?= $mb['icon'] ?>"></i>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-medium text-gray-800"><?= $mb['label'] ?></div>
                        <div class="text-xs text-gray-400"><?= $group_counts[$slug] ?? 0 ?> member<?= ($group_counts[$slug] ?? 0)===1?'':'s' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Right: Profile + Groups forms ── -->
    <div class="<?= $is_admin ? 'lg:col-span-2' : '' ?> space-y-6">

        <!-- Personal email settings -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-at text-blue-600"></i>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900">Work Email Address</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Used as the From address when sending emails from this portal</p>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_profile">
                <?php if ($is_admin): ?><input type="hidden" name="target_user_id" value="<?= $view_uid ?>"><?php endif; ?>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Work Email *</label>
                        <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2.5 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-200">
                            <i class="fas fa-envelope text-gray-400 text-xs"></i>
                            <input type="email" name="work_email" value="<?= htmlspecialchars($view_cfg['work_email'] ?? '') ?>"
                                placeholder="john@bluemogul.biz" required
                                class="flex-1 outline-none text-sm text-gray-800"
                                data-testid="input-work-email">
                        </div>
                        <p class="mt-1 text-xs text-gray-400">Must be a valid address at mail.bluemogul.biz</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Display Name</label>
                        <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2.5 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-200">
                            <i class="fas fa-user text-gray-400 text-xs"></i>
                            <input type="text" name="display_name" value="<?= htmlspecialchars($view_cfg['display_name'] ?? $view_cfg['name'] ?? '') ?>"
                                placeholder="John Smith"
                                class="flex-1 outline-none text-sm text-gray-800"
                                data-testid="input-display-name">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-lg border border-gray-100">
                    <input type="checkbox" name="receive_group" id="recv-group" class="rounded" value="1"
                        <?= ($view_cfg['receive_group'] ?? true) ? 'checked' : '' ?>
                        data-testid="check-receive-group">
                    <label for="recv-group" class="flex-1">
                        <div class="text-sm font-medium text-gray-800">Receive group emails in personal inbox</div>
                        <div class="text-xs text-gray-500">Emails to your assigned group mailboxes will also appear in My Inbox</div>
                    </label>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition"
                        data-testid="btn-save-profile">
                        <i class="fas fa-save mr-1.5"></i>Save Profile
                    </button>
                    <?php if (!empty($view_cfg['work_email'])): ?>
                    <button type="submit" form="test-email-form" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-lg text-sm font-medium transition"
                        data-testid="btn-test-email">
                        <i class="fas fa-paper-plane mr-1.5"></i>Send Test Email
                    </button>
                    <?php endif; ?>
                </div>
            </form>
            <form id="test-email-form" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test_email">
                <?php if ($is_admin): ?><input type="hidden" name="target_user_id" value="<?= $view_uid ?>"><?php endif; ?>
            </form>
        </div>

        <!-- Group memberships -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center">
                    <i class="fas fa-layer-group text-indigo-600"></i>
                </div>
                <div class="flex-1">
                    <h2 class="font-semibold text-gray-900">Group Mailbox Memberships</h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <?= $is_admin ? 'Assign this staff member to receive group mailbox emails' : 'Group mailboxes you are assigned to receive emails from' ?>
                    </p>
                </div>
            </div>
            <form method="POST" class="p-6">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_groups">
                <?php if ($is_admin): ?><input type="hidden" name="target_user_id" value="<?= $view_uid ?>"><?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <?php foreach ($MAILBOXES as $slug => $mb): ?>
                    <?php $is_member = in_array($slug, $view_groups); ?>
                    <label class="relative flex flex-col gap-3 p-4 rounded-xl border-2 cursor-pointer transition
                        <?= $is_member ? 'border-2 bg-opacity-5' : 'border-gray-200 hover:border-gray-300' ?>"
                        style="<?= $is_member ? "border-color:{$mb['color']};background:{$mb['color']}11;" : '' ?>"
                        data-testid="label-group-<?= $slug ?>">
                        <div class="flex items-center justify-between">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center text-white" style="background:<?= $mb['color'] ?>">
                                <i class="fas <?= $mb['icon'] ?>"></i>
                            </div>
                            <input type="checkbox" name="groups[]" value="<?= $slug ?>"
                                <?= $is_member ? 'checked' : '' ?>
                                <?= !$is_admin ? 'disabled' : '' ?>
                                class="rounded"
                                data-testid="check-group-<?= $slug ?>">
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900 text-sm"><?= $mb['label'] ?></div>
                            <div class="text-xs text-gray-500 mt-0.5"><?= $group_counts[$slug] ?? 0 ?> member<?= ($group_counts[$slug]??0)===1?'':'s' ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($is_admin): ?>
                <div class="mt-5 flex justify-end">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition"
                        data-testid="btn-save-groups">
                        <i class="fas fa-save mr-1.5"></i>Save Group Assignments
                    </button>
                </div>
                <?php else: ?>
                <p class="mt-4 text-xs text-gray-400 flex items-center gap-1.5">
                    <i class="fas fa-lock"></i> Group assignments are managed by your administrator
                </p>
                <?php endif; ?>
            </form>
        </div>

        <!-- SMTP info card -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl p-6 text-white">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-server text-blue-300"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-white mb-1">Company SMTP Server</h3>
                    <p class="text-sm text-slate-300 mb-3">All emails are sent via the company mail server. Your work email is used as the From address.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-white/10 rounded-lg px-3 py-2">
                            <div class="text-xs text-slate-400">Host</div>
                            <div class="text-sm font-mono text-white">mail.bluemogul.biz</div>
                        </div>
                        <div class="bg-white/10 rounded-lg px-3 py-2">
                            <div class="text-xs text-slate-400">Port</div>
                            <div class="text-sm font-mono text-white">587 (STARTTLS)</div>
                        </div>
                        <div class="bg-white/10 rounded-lg px-3 py-2 col-span-2">
                            <div class="text-xs text-slate-400">Authentication</div>
                            <div class="text-sm text-white">Company SMTP credentials (configured in Settings → Email SMTP)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /right col -->
    </div><!-- /grid -->
</div>
</div>

</body>
</html>
