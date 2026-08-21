<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_id   = $_SESSION['user_id'];
$pdo = getDB();

// ─── DB bootstrap ────────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_log (
            id            SERIAL PRIMARY KEY,
            recipient     TEXT          NOT NULL,
            subject       TEXT          DEFAULT '',
            status        VARCHAR(20)   DEFAULT 'sent',
            error_message TEXT,
            client_id     INTEGER,
            sent_by       INTEGER,
            sent_at       TIMESTAMP     DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS messages (
            id              SERIAL PRIMARY KEY,
            subject         TEXT          DEFAULT '',
            body            TEXT          DEFAULT '',
            category        VARCHAR(100)  DEFAULT 'general',
            recipient_type  VARCHAR(50)   DEFAULT 'all',
            recipient_filter TEXT         DEFAULT '[]',
            sent_by         INTEGER,
            sent_count      INTEGER       DEFAULT 0,
            failed_count    INTEGER       DEFAULT 0,
            status          VARCHAR(20)   DEFAULT 'draft',
            sent_at         TIMESTAMP,
            created_at      TIMESTAMP     DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS message_templates (
            id          SERIAL PRIMARY KEY,
            name        VARCHAR(255)  NOT NULL,
            subject     TEXT          DEFAULT '',
            body        TEXT          DEFAULT '',
            category    VARCHAR(100)  DEFAULT 'general',
            created_by  INTEGER,
            created_at  TIMESTAMP     DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS staff_signatures (
            id           SERIAL PRIMARY KEY,
            user_id      INTEGER       NOT NULL,
            name         VARCHAR(100)  NOT NULL DEFAULT 'My Signature',
            html_content TEXT          DEFAULT '',
            is_default   BOOLEAN       DEFAULT false,
            created_at   TIMESTAMP     DEFAULT NOW(),
            updated_at   TIMESTAMP     DEFAULT NOW()
        );
    ");
    // Add client_id / sent_by columns if missing (existing installations)
    foreach (['client_id INTEGER', 'sent_by INTEGER'] as $col_def) {
        $col = explode(' ', $col_def)[0];
        try { $pdo->exec("ALTER TABLE email_log ADD COLUMN IF NOT EXISTS {$col} {$col_def}"); } catch(Exception $e){}
    }
} catch (Exception $e) {}

// ─── Load data ───────────────────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'compose';
$success_msg = '';
$error_msg   = '';

// Clients with email
$clients = [];
try {
    $clients = $pdo->query("SELECT id, name, email, company, status FROM clients WHERE email IS NOT NULL AND email != '' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Templates
$templates = [];
try {
    $templates = $pdo->query("SELECT * FROM message_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Company settings for placeholders
$company_name  = 'Blue Mogul';
$company_email = 'support@bluemogul.biz';
$company_phone = '';
try {
    $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name','company_email','company_phone')");
    $cs = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $company_name  = $cs['company_name']  ?? $company_name;
    $company_email = $cs['company_email'] ?? $company_email;
    $company_phone = $cs['company_phone'] ?? $company_phone;
} catch (Exception $e) {}

$smtp_ok = isSmtpConfigured();

// ─── Load this user's email signatures ───────────────────────────────────────
$my_signatures  = [];
$default_sig    = null;
try {
    $sigstmt = $pdo->prepare("SELECT * FROM staff_signatures WHERE user_id=? ORDER BY is_default DESC, name ASC");
    $sigstmt->execute([$user_id]);
    $my_signatures = $sigstmt->fetchAll(PDO::FETCH_ASSOC);
    $default_sig   = array_values(array_filter($my_signatures, fn($s) => $s['is_default']))[0] ?? ($my_signatures[0] ?? null);
} catch (Exception $e) {}

// ─── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── Send Email
    if ($action === 'send_email') {
        $subject        = trim($_POST['subject'] ?? '');
        $body           = trim($_POST['body']    ?? '');
        $recipient_type = $_POST['recipient_type'] ?? 'all';
        $sel_clients    = $_POST['selected_clients'] ?? [];
        $status_filter  = $_POST['status_filter'] ?? '';

        if (!$subject || !$body) {
            $error_msg = 'Subject and body are required.';
        } elseif (!$smtp_ok) {
            $error_msg = 'SMTP is not configured. Set up SMTP in Settings before sending.';
        } else {
            // Resolve target clients
            if ($recipient_type === 'all') {
                $targets = $clients;
            } elseif ($recipient_type === 'active') {
                $targets = array_filter($clients, fn($c) => ($c['status'] ?? '') === 'active');
            } elseif ($recipient_type === 'selected' && !empty($sel_clients)) {
                $ids = array_map('intval', $sel_clients);
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, name, email, company, status FROM clients WHERE id IN ($ph) AND email IS NOT NULL AND email != ''");
                $stmt->execute($ids);
                $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $targets = [];
            }

            if (empty($targets)) {
                $error_msg = 'No recipients found.';
            } else {
                $sent = $failed = 0;
                foreach ($targets as $cl) {
                    $rep = [
                        'client.name'    => $cl['name']    ?? '',
                        'client.email'   => $cl['email']   ?? '',
                        'client.company' => $cl['company'] ?? '',
                        'client.id'      => $cl['id']      ?? '',
                        'company.name'   => $company_name,
                        'company.email'  => $company_email,
                        'company.phone'  => $company_phone,
                        'date.today'     => date('F j, Y'),
                        'date.time'      => date('g:i A T'),
                    ];
                    $p_subject = $subject;
                    $p_body    = $body;
                    foreach ($rep as $k => $v) {
                        $pat       = '/\{\{\s*'.preg_quote($k,'/').'\\s*\}\}/';
                        $p_subject = preg_replace($pat, $v,                    $p_subject);
                        $p_body    = preg_replace($pat, htmlspecialchars($v),   $p_body);
                    }
                    $html   = email_template($p_subject, '<div style="font-size:14px;color:#374151;line-height:1.8;">'.$p_body.'</div>');
                    $result = send_email($cl['email'], $p_subject, $html);
                    if ($result['success']) {
                        $sent++;
                        try { $pdo->prepare("INSERT INTO email_log (recipient,subject,status,client_id,sent_by,sent_at) VALUES (?,?,'sent',?,?,NOW())")->execute([$cl['email'],$p_subject,$cl['id'],$user_id]); } catch(Exception $e){}
                    } else {
                        $failed++;
                        try { $pdo->prepare("INSERT INTO email_log (recipient,subject,status,error_message,client_id,sent_by,sent_at) VALUES (?,?,'failed',?,?,?,NOW())")->execute([$cl['email'],$p_subject,$result['error']??'unknown',$cl['id'],$user_id]); } catch(Exception $e){}
                    }
                }
                // Save to messages
                try {
                    $pdo->prepare("INSERT INTO messages (subject,body,category,recipient_type,recipient_filter,sent_by,sent_count,failed_count,status,sent_at) VALUES (?,?,'client_email',?,?,?,?,?,'sent',NOW())")
                        ->execute([$subject,$body,$recipient_type,json_encode($sel_clients),$user_id,$sent,$failed]);
                } catch(Exception $e){}
                try { $pdo->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")->execute([$user_id,'client_email_sent','message',0,"Sent '{$subject}' to {$sent} client(s) ({$failed} failed)",$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
                $success_msg = "Email sent to {$sent} client(s)".($failed > 0 ? " ({$failed} failed)" : '').'.';
                $active_tab = 'history';
            }
        }
    }

    // ── Save Template
    if ($action === 'save_template') {
        $tpl_name    = trim($_POST['template_name'] ?? '');
        $tpl_subject = trim($_POST['subject'] ?? '');
        $tpl_body    = trim($_POST['body']    ?? '');
        if (!$tpl_name || !$tpl_subject) {
            $error_msg = 'Template name and subject are required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO message_templates (name,subject,body,category,created_by) VALUES (?,?,?,'client_email',?)")
                    ->execute([$tpl_name,$tpl_subject,$tpl_body,$user_id]);
                $success_msg = "Template '{$tpl_name}' saved.";
                $templates = $pdo->query("SELECT * FROM message_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) { $error_msg = 'Failed to save template.'; }
        }
        $active_tab = 'templates';
    }

    // ── Delete Template
    if ($action === 'delete_template') {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid > 0) {
            try { $pdo->prepare("DELETE FROM message_templates WHERE id=?")->execute([$tid]); $success_msg = 'Template deleted.'; } catch(Exception $e){}
            $templates = $pdo->query("SELECT * FROM message_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }
        $active_tab = 'templates';
    }

    // ── Save / Update Signature
    if ($action === 'save_signature') {
        $sig_id      = intval($_POST['sig_id'] ?? 0);
        $sig_name    = trim($_POST['sig_name'] ?? '') ?: 'My Signature';
        $sig_html    = $_POST['sig_html'] ?? '';
        $sig_default = isset($_POST['sig_default']) ? 1 : 0;
        if ($sig_default) {
            try { $pdo->prepare("UPDATE staff_signatures SET is_default=false WHERE user_id=?")->execute([$user_id]); } catch(Exception $e){}
        }
        if ($sig_id > 0) {
            // Update existing
            try {
                $pdo->prepare("UPDATE staff_signatures SET name=?,html_content=?,is_default=?,updated_at=NOW() WHERE id=? AND user_id=?")
                    ->execute([$sig_name,$sig_html,$sig_default,$sig_id,$user_id]);
                $success_msg = "Signature '{$sig_name}' updated.";
            } catch(Exception $e) { $error_msg = 'Failed to update signature.'; }
        } else {
            // Insert new
            try {
                $pdo->prepare("INSERT INTO staff_signatures (user_id,name,html_content,is_default) VALUES (?,?,?,?)")
                    ->execute([$user_id,$sig_name,$sig_html,$sig_default]);
                $success_msg = "Signature '{$sig_name}' saved.";
            } catch(Exception $e) { $error_msg = 'Failed to save signature.'; }
        }
        // Reload
        try { $sigstmt = $pdo->prepare("SELECT * FROM staff_signatures WHERE user_id=? ORDER BY is_default DESC, name ASC"); $sigstmt->execute([$user_id]); $my_signatures = $sigstmt->fetchAll(PDO::FETCH_ASSOC); $default_sig = array_values(array_filter($my_signatures, fn($s) => $s['is_default']))[0] ?? ($my_signatures[0] ?? null); } catch(Exception $e){}
        $active_tab = 'signatures';
    }

    // ── Delete Signature
    if ($action === 'delete_signature') {
        $sid = intval($_POST['sig_id'] ?? 0);
        if ($sid > 0) {
            try { $pdo->prepare("DELETE FROM staff_signatures WHERE id=? AND user_id=?")->execute([$sid,$user_id]); $success_msg = 'Signature deleted.'; } catch(Exception $e){}
            try { $sigstmt = $pdo->prepare("SELECT * FROM staff_signatures WHERE user_id=? ORDER BY is_default DESC, name ASC"); $sigstmt->execute([$user_id]); $my_signatures = $sigstmt->fetchAll(PDO::FETCH_ASSOC); $default_sig = array_values(array_filter($my_signatures, fn($s) => $s['is_default']))[0] ?? ($my_signatures[0] ?? null); } catch(Exception $e){}
        }
        $active_tab = 'signatures';
    }

    // ── Set Default Signature
    if ($action === 'set_default_sig') {
        $sid = intval($_POST['sig_id'] ?? 0);
        if ($sid > 0) {
            try {
                $pdo->prepare("UPDATE staff_signatures SET is_default=false WHERE user_id=?")->execute([$user_id]);
                $pdo->prepare("UPDATE staff_signatures SET is_default=true WHERE id=? AND user_id=?")->execute([$sid,$user_id]);
                $success_msg = 'Default signature updated.';
            } catch(Exception $e){}
            try { $sigstmt = $pdo->prepare("SELECT * FROM staff_signatures WHERE user_id=? ORDER BY is_default DESC, name ASC"); $sigstmt->execute([$user_id]); $my_signatures = $sigstmt->fetchAll(PDO::FETCH_ASSOC); $default_sig = array_values(array_filter($my_signatures, fn($s) => $s['is_default']))[0] ?? ($my_signatures[0] ?? null); } catch(Exception $e){}
        }
        $active_tab = 'signatures';
    }
}

// ── History data (loaded per tab to stay lean)
$email_history = [];
$history_total = 0;
$h_page  = max(1, intval($_GET['hp'] ?? 1));
$h_limit = 50;
$h_off   = ($h_page - 1) * $h_limit;
$h_search = trim($_GET['hs'] ?? '');
$h_status = $_GET['hstatus'] ?? '';

if ($active_tab === 'history') {
    try {
        $where = []; $params = [];
        if ($h_search) { $where[] = "(el.recipient ILIKE ? OR el.subject ILIKE ?)"; $params[] = "%$h_search%"; $params[] = "%$h_search%"; }
        if ($h_status) { $where[] = "el.status = ?"; $params[] = $h_status; }
        $wh = $where ? 'WHERE '.implode(' AND ', $where) : '';
        $history_total = $pdo->prepare("SELECT COUNT(*) FROM email_log el $wh")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM email_log el $wh")->execute($params) : 0;
        $cstmt = $pdo->prepare("SELECT COUNT(*) FROM email_log el $wh");
        $cstmt->execute($params);
        $history_total = (int)$cstmt->fetchColumn();

        $lstmt = $pdo->prepare("SELECT el.*, c.name AS client_name FROM email_log el LEFT JOIN clients c ON c.id = el.client_id $wh ORDER BY el.sent_at DESC LIMIT ? OFFSET ?");
        $lstmt->execute(array_merge($params, [$h_limit, $h_off]));
        $email_history = $lstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// ── Stats for overview
$stat_sent = $stat_failed = $stat_total = 0;
try {
    $r = $pdo->query("SELECT status, COUNT(*) as cnt FROM email_log GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $row) {
        if ($row['status'] === 'sent')   $stat_sent   = (int)$row['cnt'];
        if ($row['status'] === 'failed') $stat_failed = (int)$row['cnt'];
        $stat_total += (int)$row['cnt'];
    }
} catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Email Communications | Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    body { font-family: Inter, system-ui, sans-serif; background: #f8fafc; }
    .tab-active { border-bottom: 2px solid #2563eb; color: #1d4ed8; font-weight: 600; }
    .tab-inactive { border-bottom: 2px solid transparent; color: #6b7280; }
    .tab-inactive:hover { color: #374151; border-color: #d1d5db; }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-gray-50">

<?php require_once 'includes/admin-sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between flex-shrink-0">
        <div>
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-envelope text-blue-600"></i> Client Email Communications
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Compose, send, and track emails to your clients</p>
        </div>
        <div class="flex items-center gap-3">
            <?php if (!$smtp_ok): ?>
            <a href="admin-settings.php" class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 border border-amber-200 text-xs px-3 py-1.5 rounded-lg hover:bg-amber-200 transition" data-testid="alert-smtp-not-configured">
                <i class="fas fa-exclamation-triangle"></i>SMTP not configured
            </a>
            <?php else: ?>
            <span class="inline-flex items-center gap-1.5 text-xs text-green-700 bg-green-50 border border-green-200 px-3 py-1.5 rounded-lg" data-testid="badge-smtp-ok">
                <i class="fas fa-check-circle"></i>SMTP Ready
            </span>
            <?php endif; ?>
            <span class="text-sm text-gray-500"><?= htmlspecialchars($user_name) ?></span>
        </div>
    </header>

    <!-- Stats Bar -->
    <div class="bg-white border-b border-gray-200 px-6 py-3 flex gap-8">
        <div class="flex items-center gap-2 text-sm">
            <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
            <span class="text-gray-500">Clients with email:</span>
            <span class="font-semibold text-gray-800" data-testid="stat-clients"><?= count($clients) ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            <span class="text-gray-500">Emails sent:</span>
            <span class="font-semibold text-gray-800" data-testid="stat-sent"><?= number_format($stat_sent) ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
            <span class="text-gray-500">Failed:</span>
            <span class="font-semibold text-gray-800" data-testid="stat-failed"><?= number_format($stat_failed) ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="w-2.5 h-2.5 rounded-full bg-purple-400"></span>
            <span class="text-gray-500">Templates:</span>
            <span class="font-semibold text-gray-800" data-testid="stat-templates"><?= count($templates) ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="w-2.5 h-2.5 rounded-full bg-indigo-400"></span>
            <span class="text-gray-500">My Signatures:</span>
            <span class="font-semibold text-gray-800" data-testid="stat-signatures"><?= count($my_signatures) ?></span>
        </div>
    </div>

    <!-- Tab nav -->
    <div class="bg-white border-b border-gray-200 px-6 flex gap-1">
        <?php foreach ([
            ['compose',    'fa-pen-to-square',     'Compose'],
            ['history',    'fa-clock-rotate-left',  'Sent History'],
            ['templates',  'fa-file-lines',          'Templates'],
            ['signatures', 'fa-signature',           'Signatures'],
        ] as [$t,$ico,$lbl]): ?>
        <a href="?tab=<?= $t ?>"
           class="flex items-center gap-2 px-5 py-3 text-sm transition <?= $active_tab===$t ? 'tab-active' : 'tab-inactive' ?>"
           data-testid="tab-<?= $t ?>">
            <i class="fas <?= $ico ?> text-xs"></i><?= $lbl ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Alerts -->
    <div class="px-6 pt-4">
        <?php if ($success_msg): ?>
        <div class="mb-4 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-success">
            <i class="fas fa-check-circle"></i><?= htmlspecialchars($success_msg) ?>
        </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div class="mb-4 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-error">
            <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error_msg) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto px-6 pb-8">

    <?php /* ══════ COMPOSE TAB ══════ */ if ($active_tab === 'compose'): ?>
    <div class="grid grid-cols-3 gap-6 mt-2">

        <!-- Compose Form (left 2/3) -->
        <div class="col-span-2 space-y-5">

            <!-- Recipient block -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-users text-blue-500 mr-2"></i>Recipients</h3>
                <form method="POST" id="compose-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_email">

                    <div class="flex gap-4 mb-4">
                        <?php foreach (['all'=>'All Clients','active'=>'Active Only','selected'=>'Select Specific'] as $v=>$l): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="radio" name="recipient_type" value="<?= $v ?>" <?= $v==='all'?'checked':'' ?>
                                class="accent-blue-600" data-testid="radio-recipient-<?= $v ?>"
                                onchange="toggleClientList(this.value)">
                            <span class="text-gray-700"><?= $l ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Client multi-select (shown only when 'selected') -->
                    <div id="client-select-wrap" class="hidden">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-gray-500"><?= count($clients) ?> clients with email</span>
                            <div class="flex gap-2">
                                <button type="button" onclick="selectAll()" class="text-xs text-blue-600 hover:underline">Select all</button>
                                <span class="text-xs text-gray-300">|</span>
                                <button type="button" onclick="clearAll()" class="text-xs text-blue-600 hover:underline">Clear</button>
                            </div>
                        </div>
                        <input type="text" id="client-search" placeholder="Filter clients…" oninput="filterClients()"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mb-2 focus:ring-2 focus:ring-blue-400 focus:outline-none"
                            data-testid="input-filter-clients">
                        <div id="client-list" class="max-h-48 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
                            <?php foreach ($clients as $cl): ?>
                            <label class="client-row flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer" data-name="<?= strtolower(htmlspecialchars($cl['name'])) ?> <?= strtolower(htmlspecialchars($cl['company']??'')) ?>">
                                <input type="checkbox" name="selected_clients[]" value="<?= $cl['id'] ?>" class="accent-blue-600"
                                    data-testid="check-client-<?= $cl['id'] ?>">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($cl['name']) ?></div>
                                    <div class="text-xs text-gray-400 truncate"><?= htmlspecialchars($cl['email']) ?></div>
                                </div>
                                <span class="text-xs px-2 py-0.5 rounded-full <?= ($cl['status']??'')!=='active' ? 'bg-gray-100 text-gray-500' : 'bg-green-100 text-green-700' ?>"><?= htmlspecialchars($cl['status']??'') ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <!-- Subject -->
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject <span class="text-red-500">*</span></label>
                    <input type="text" name="subject" id="subject-input" required placeholder="e.g. Important update from {{ company.name }}"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"
                        data-testid="input-subject">
                </div>

                <!-- Body -->
                <div class="mt-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm font-medium text-gray-700">Message Body <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer">
                                <input type="radio" name="body_mode" value="rich" checked onchange="toggleBodyMode('rich')" class="accent-blue-600"> Rich Text
                            </label>
                            <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer">
                                <input type="radio" name="body_mode" value="plain" onchange="toggleBodyMode('plain')" class="accent-blue-600"> Plain
                            </label>
                        </div>
                    </div>
                    <!-- Enhanced rich text toolbar -->
                    <div id="rich-toolbar" class="bg-gray-50 border border-b-0 border-gray-200 rounded-t-lg px-2 py-1.5 flex flex-wrap gap-0.5 items-center">
                        <!-- Font Family -->
                        <select onchange="fmtFontFamily(this.value)" title="Font family"
                            class="h-7 text-xs border border-gray-200 rounded px-1 bg-white text-gray-700 cursor-pointer mr-1 focus:ring-1 focus:ring-blue-400 focus:outline-none"
                            data-testid="select-font-family">
                            <option value="">Default Font</option>
                            <option value="Arial, sans-serif">Arial</option>
                            <option value="Georgia, serif">Georgia</option>
                            <option value="'Times New Roman', serif">Times New Roman</option>
                            <option value="Verdana, sans-serif">Verdana</option>
                            <option value="Tahoma, sans-serif">Tahoma</option>
                            <option value="'Trebuchet MS', sans-serif">Trebuchet MS</option>
                            <option value="'Courier New', monospace">Courier New</option>
                        </select>
                        <!-- Font Size -->
                        <select onchange="fmtFontSize(this.value)" title="Font size"
                            class="h-7 text-xs border border-gray-200 rounded px-1 bg-white text-gray-700 cursor-pointer mr-1 focus:ring-1 focus:ring-blue-400 focus:outline-none"
                            data-testid="select-font-size">
                            <option value="">Size</option>
                            <option value="10px">10</option>
                            <option value="11px">11</option>
                            <option value="12px">12</option>
                            <option value="13px">13</option>
                            <option value="14px" selected>14</option>
                            <option value="16px">16</option>
                            <option value="18px">18</option>
                            <option value="20px">20</option>
                            <option value="24px">24</option>
                            <option value="28px">28</option>
                            <option value="32px">32</option>
                            <option value="36px">36</option>
                        </select>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Bold / Italic / Underline / Strikethrough -->
                        <button type="button" onclick="fmt('bold')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 font-bold text-gray-700 text-sm" title="Bold" data-testid="btn-fmt-bold"><b>B</b></button>
                        <button type="button" onclick="fmt('italic')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 italic text-gray-700 text-sm" title="Italic" data-testid="btn-fmt-italic"><i>I</i></button>
                        <button type="button" onclick="fmt('underline')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 underline text-gray-700 text-sm" title="Underline" data-testid="btn-fmt-underline">U</button>
                        <button type="button" onclick="fmt('strikeThrough')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 line-through text-gray-700 text-sm" title="Strikethrough" data-testid="btn-fmt-strike">S</button>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Alignment -->
                        <button type="button" onclick="fmt('justifyLeft')"   class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Align left"><i class="fas fa-align-left text-xs"></i></button>
                        <button type="button" onclick="fmt('justifyCenter')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Align center"><i class="fas fa-align-center text-xs"></i></button>
                        <button type="button" onclick="fmt('justifyRight')"  class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Align right"><i class="fas fa-align-right text-xs"></i></button>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Lists -->
                        <button type="button" onclick="fmt('insertOrderedList')"   class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Numbered list"><i class="fas fa-list-ol text-xs"></i></button>
                        <button type="button" onclick="fmt('insertUnorderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Bullet list"><i class="fas fa-list-ul text-xs"></i></button>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Text color -->
                        <div class="relative group" title="Text color">
                            <button type="button" class="w-7 h-7 flex flex-col items-center justify-center rounded hover:bg-gray-200 gap-0.5" onclick="document.getElementById('txt-color-picker').click()" data-testid="btn-text-color">
                                <span class="font-bold text-sm text-gray-700 leading-none">A</span>
                                <span class="w-5 h-1 rounded-sm" id="txt-color-bar" style="background:#000000;"></span>
                            </button>
                            <input type="color" id="txt-color-picker" value="#000000" class="sr-only" oninput="fmtTextColor(this.value)">
                        </div>
                        <!-- Highlight color -->
                        <div class="relative group" title="Highlight color">
                            <button type="button" class="w-7 h-7 flex flex-col items-center justify-center rounded hover:bg-gray-200 gap-0.5" onclick="document.getElementById('hl-color-picker').click()" data-testid="btn-highlight-color">
                                <i class="fas fa-highlighter text-xs text-gray-700"></i>
                                <span class="w-5 h-1 rounded-sm" id="hl-color-bar" style="background:#ffff00;"></span>
                            </button>
                            <input type="color" id="hl-color-picker" value="#ffff00" class="sr-only" oninput="fmtHighlight(this.value)">
                        </div>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Insert Image -->
                        <button type="button" onclick="insertImageDialog('rich-editor')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Insert image" data-testid="btn-insert-image">
                            <i class="fas fa-image text-xs"></i>
                        </button>
                        <!-- Insert Link -->
                        <button type="button" onclick="insertLinkDialog('rich-editor')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Insert link" data-testid="btn-insert-link">
                            <i class="fas fa-link text-xs"></i>
                        </button>
                        <!-- Clear formatting -->
                        <button type="button" onclick="fmt('removeFormat')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-400" title="Clear formatting">
                            <i class="fas fa-text-slash text-xs"></i>
                        </button>
                        <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                        <!-- Placeholder shortcut -->
                        <button type="button" onclick="insertPlaceholder('{{ client.name }}')" class="text-xs text-blue-600 hover:bg-blue-50 px-2 h-7 rounded font-mono" title="Insert client name">{{client}}</button>
                    </div>
                    <div id="rich-editor" contenteditable="true"
                        class="w-full border border-gray-200 rounded-b-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none min-h-48 bg-white"
                        style="line-height:1.7;"
                        data-testid="rich-editor"></div>
                    <textarea id="plain-editor" name="body" class="hidden w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none min-h-48" placeholder="Type your message…" data-testid="textarea-body"></textarea>

                    <!-- Signature strip -->
                    <div class="mt-2 flex items-center gap-2 flex-wrap" id="sig-controls">
                        <i class="fas fa-signature text-indigo-400 text-xs"></i>
                        <span class="text-xs text-gray-500">Signature:</span>
                        <select id="sig-select" onchange="changeSig(this.value)"
                            class="text-xs border border-gray-200 rounded px-2 py-1 bg-white text-gray-700 focus:ring-1 focus:ring-blue-400 focus:outline-none"
                            data-testid="select-active-sig">
                            <option value="">— None —</option>
                            <?php foreach ($my_signatures as $sig): ?>
                            <option value="<?= $sig['id'] ?>" data-html="<?= htmlspecialchars($sig['html_content']) ?>"
                                <?= ($default_sig && $sig['id'] == $default_sig['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sig['name']) ?><?= $sig['is_default'] ? ' ★' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="?tab=signatures" class="text-xs text-indigo-500 hover:underline">Manage →</a>
                        <?php if (empty($my_signatures)): ?>
                        <a href="?tab=signatures" class="text-xs text-indigo-600 hover:underline font-medium">
                            <i class="fas fa-plus mr-1"></i>Create signature
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-5 flex gap-3 flex-wrap">
                    <button type="submit" <?= !$smtp_ok ? 'disabled title="SMTP not configured"' : '' ?>
                        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition"
                        data-testid="btn-send-email">
                        <i class="fas fa-paper-plane"></i>Send Email
                    </button>
                    <button type="button" onclick="document.getElementById('save-tpl-modal').classList.remove('hidden')"
                        class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-lg text-sm transition"
                        data-testid="btn-save-template">
                        <i class="fas fa-bookmark"></i>Save as Template
                    </button>
                </div>
                </form>
            </div>
        </div>

        <!-- Right sidebar: Template picker + placeholder help -->
        <div class="col-span-1 space-y-5">

            <!-- Template picker -->
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-800 text-sm mb-3"><i class="fas fa-file-lines text-purple-500 mr-1.5"></i>Load Template</h4>
                <?php if (empty($templates)): ?>
                <p class="text-xs text-gray-400">No templates yet. Compose a message and save it as a template.</p>
                <?php else: ?>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    <?php foreach ($templates as $tpl): ?>
                    <button type="button"
                        onclick="loadTemplate(<?= htmlspecialchars(json_encode($tpl['subject'])) ?>, <?= htmlspecialchars(json_encode($tpl['body'])) ?>)"
                        class="w-full text-left px-3 py-2 rounded-lg hover:bg-purple-50 border border-transparent hover:border-purple-200 transition text-sm"
                        data-testid="btn-load-tpl-<?= $tpl['id'] ?>">
                        <div class="font-medium text-gray-800 truncate"><?= htmlspecialchars($tpl['name']) ?></div>
                        <div class="text-xs text-gray-400 truncate"><?= htmlspecialchars($tpl['subject']) ?></div>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <a href="?tab=templates" class="mt-3 text-xs text-blue-500 hover:underline block">Manage templates →</a>
            </div>

            <!-- Placeholder reference -->
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-800 text-sm mb-3"><i class="fas fa-code text-green-500 mr-1.5"></i>Personalization</h4>
                <p class="text-xs text-gray-500 mb-2">Click to insert into your message:</p>
                <div class="space-y-1">
                    <?php foreach ([
                        ['{{ client.name }}',    'Client full name'],
                        ['{{ client.email }}',   'Client email'],
                        ['{{ client.company }}', 'Client company'],
                        ['{{ client.id }}',      'Client ID'],
                        ['{{ company.name }}',   'Your company name'],
                        ['{{ company.email }}',  'Your support email'],
                        ['{{ company.phone }}',  'Your phone number'],
                        ['{{ date.today }}',     "Today's date"],
                        ['{{ date.time }}',      'Current time'],
                    ] as [$var,$desc]): ?>
                    <button type="button" onclick="insertPlaceholder('<?= $var ?>')"
                        class="w-full text-left flex items-center justify-between px-2 py-1 rounded hover:bg-green-50 transition"
                        data-testid="ph-<?= preg_replace('/[^a-z0-9]/','-',strtolower($var)) ?>">
                        <code class="text-xs font-mono text-green-700 bg-green-50 px-1 rounded"><?= htmlspecialchars($var) ?></code>
                        <span class="text-xs text-gray-400 ml-2"><?= $desc ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php /* ══════ HISTORY TAB ══════ */ elseif ($active_tab === 'history'): ?>
    <div class="mt-2 space-y-4">
        <!-- Filters -->
        <form method="GET" class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex gap-3 flex-wrap items-center">
            <input type="hidden" name="tab" value="history">
            <input type="text" name="hs" value="<?= htmlspecialchars($h_search) ?>" placeholder="Search recipient or subject…"
                class="border border-gray-200 rounded-lg px-3 py-2 text-sm flex-1 min-w-48 focus:ring-2 focus:ring-blue-400 focus:outline-none"
                data-testid="input-history-search">
            <select name="hstatus" class="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none" data-testid="select-history-status">
                <option value="">All Statuses</option>
                <option value="sent" <?= $h_status==='sent'?'selected':'' ?>>Sent</option>
                <option value="failed" <?= $h_status==='failed'?'selected':'' ?>>Failed</option>
            </select>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded-lg transition" data-testid="btn-history-search">
                <i class="fas fa-search mr-1"></i>Search
            </button>
            <?php if ($h_search || $h_status): ?>
            <a href="?tab=history" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
            <?php endif; ?>
        </form>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <?php if (empty($email_history)): ?>
            <div class="p-12 text-center text-gray-400 text-sm">
                <i class="fas fa-inbox text-4xl mb-3 block opacity-30"></i>
                <?= $h_search || $h_status ? 'No emails match your filters.' : 'No emails sent yet.' ?>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date / Time</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Recipient</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Subject</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Client</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($email_history as $row): ?>
                    <tr class="hover:bg-gray-50" data-testid="history-row-<?= $row['id'] ?>">
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs"><?= $row['sent_at'] ? date('M j, Y g:i A', strtotime($row['sent_at'])) : '—' ?></td>
                        <td class="px-4 py-3 text-gray-800 font-mono text-xs"><?= htmlspecialchars($row['recipient']) ?></td>
                        <td class="px-4 py-3 text-gray-700 max-w-xs truncate"><?= htmlspecialchars($row['subject'] ?? '') ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($row['status']==='sent'): ?>
                            <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full">
                                <i class="fas fa-check text-xs"></i>Sent
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full" title="<?= htmlspecialchars($row['error_message']??'') ?>">
                                <i class="fas fa-times text-xs"></i>Failed
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php if (!empty($row['client_name'])): ?>
                            <a href="admin-client-detail.php?id=<?= (int)$row['client_id'] ?>" class="text-blue-500 hover:underline text-xs"><?= htmlspecialchars($row['client_name']) ?></a>
                            <?php else: ?>
                            <span class="text-gray-400 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <!-- Pagination -->
            <?php if ($history_total > $h_limit): ?>
            <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                <span><?= number_format($history_total) ?> emails total</span>
                <div class="flex gap-1">
                    <?php $total_pages = ceil($history_total/$h_limit); for ($p=1;$p<=$total_pages;$p++): ?>
                    <a href="?tab=history&hp=<?= $p ?>&hs=<?= urlencode($h_search) ?>&hstatus=<?= urlencode($h_status) ?>"
                       class="px-2 py-1 rounded <?= $p===$h_page?'bg-blue-600 text-white':'hover:bg-gray-100' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php /* ══════ TEMPLATES TAB ══════ */ elseif ($active_tab === 'templates'): ?>
    <div class="mt-2 grid grid-cols-3 gap-6">

        <!-- Template list -->
        <div class="col-span-2">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800"><i class="fas fa-file-lines text-purple-500 mr-2"></i>Saved Templates</h3>
                    <span class="text-xs text-gray-400"><?= count($templates) ?> template<?= count($templates)!==1?'s':'' ?></span>
                </div>
                <?php if (empty($templates)): ?>
                <div class="p-12 text-center text-gray-400 text-sm">
                    <i class="fas fa-file-circle-plus text-4xl mb-3 block opacity-30"></i>
                    No templates yet. Create one using the form on the right.
                </div>
                <?php else: ?>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($templates as $tpl): ?>
                    <div class="px-5 py-4 flex items-start gap-4 hover:bg-gray-50" data-testid="template-row-<?= $tpl['id'] ?>">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="font-medium text-gray-900"><?= htmlspecialchars($tpl['name']) ?></span>
                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full"><?= htmlspecialchars($tpl['category']??'general') ?></span>
                            </div>
                            <div class="text-sm text-gray-600 truncate mb-1"><strong>Subject:</strong> <?= htmlspecialchars($tpl['subject']) ?></div>
                            <div class="text-xs text-gray-400 line-clamp-2"><?= htmlspecialchars(strip_tags($tpl['body'] ?? '')) ?></div>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            <button type="button"
                                onclick="window.location='?tab=compose'; loadTemplate(<?= htmlspecialchars(json_encode($tpl['subject'])) ?>,<?= htmlspecialchars(json_encode($tpl['body'])) ?>)"
                                class="text-xs text-blue-600 hover:bg-blue-50 border border-blue-200 px-3 py-1 rounded transition"
                                data-testid="btn-use-tpl-<?= $tpl['id'] ?>">
                                Use
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this template?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
                                <button type="submit" class="text-xs text-red-600 hover:bg-red-50 border border-red-200 px-3 py-1 rounded transition" data-testid="btn-delete-tpl-<?= $tpl['id'] ?>">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- New template form -->
        <div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h4 class="font-semibold text-gray-800 mb-4"><i class="fas fa-plus text-green-500 mr-1.5"></i>New Template</h4>
                <form method="POST" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_template">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Template Name <span class="text-red-500">*</span></label>
                        <input type="text" name="template_name" required placeholder="e.g. Monthly Newsletter"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"
                            data-testid="input-tpl-name">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Subject <span class="text-red-500">*</span></label>
                        <input type="text" name="subject" required placeholder="Email subject line"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"
                            data-testid="input-tpl-subject">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Body</label>
                        <textarea name="body" rows="8" placeholder="Hi {{ client.name }},&#10;&#10;…"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none resize-y"
                            data-testid="textarea-tpl-body"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-sm py-2.5 rounded-lg font-medium transition" data-testid="btn-submit-tpl">
                        <i class="fas fa-bookmark mr-1"></i>Save Template
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php /* ══════ SIGNATURES TAB ══════ */ elseif ($active_tab === 'signatures'): ?>
    <div class="mt-2 grid grid-cols-3 gap-6">

        <!-- Left: Saved Signatures -->
        <div class="col-span-2">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800"><i class="fas fa-signature text-indigo-500 mr-2"></i>My Email Signatures</h3>
                    <span class="text-xs text-gray-400"><?= count($my_signatures) ?> signature<?= count($my_signatures)!==1?'s':'' ?></span>
                </div>
                <?php if (empty($my_signatures)): ?>
                <div class="p-12 text-center text-gray-400 text-sm">
                    <i class="fas fa-signature text-4xl mb-3 block opacity-25"></i>
                    No signatures yet. Create one using the form on the right.
                </div>
                <?php else: ?>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($my_signatures as $sig): ?>
                    <div class="px-5 py-4 hover:bg-gray-50" data-testid="sig-row-<?= $sig['id'] ?>">
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="font-medium text-gray-900"><?= htmlspecialchars($sig['name']) ?></span>
                                    <?php if ($sig['is_default']): ?>
                                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">
                                        <i class="fas fa-star text-xs mr-1"></i>Default
                                    </span>
                                    <?php endif; ?>
                                    <span class="text-xs text-gray-400">Updated <?= date('M j, Y', strtotime($sig['updated_at'])) ?></span>
                                </div>
                                <!-- Preview -->
                                <div class="border border-gray-200 rounded-lg p-3 bg-white text-sm max-h-28 overflow-hidden relative">
                                    <?= $sig['html_content'] ?: '<span class="text-gray-400 italic">No content</span>' ?>
                                    <div class="absolute bottom-0 left-0 right-0 h-6 bg-gradient-to-t from-white to-transparent pointer-events-none"></div>
                                </div>
                            </div>
                            <div class="flex flex-col gap-2 flex-shrink-0">
                                <!-- Set Default -->
                                <?php if (!$sig['is_default']): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_default_sig">
                                    <input type="hidden" name="sig_id" value="<?= $sig['id'] ?>">
                                    <button type="submit" class="text-xs text-indigo-600 hover:bg-indigo-50 border border-indigo-200 px-3 py-1 rounded transition whitespace-nowrap" data-testid="btn-set-default-sig-<?= $sig['id'] ?>">
                                        Set Default
                                    </button>
                                </form>
                                <?php endif; ?>
                                <!-- Edit -->
                                <button type="button"
                                    onclick="editSignature(<?= $sig['id'] ?>, <?= htmlspecialchars(json_encode($sig['name'])) ?>, <?= htmlspecialchars(json_encode($sig['html_content'])) ?>, <?= $sig['is_default'] ? 'true' : 'false' ?>)"
                                    class="text-xs text-blue-600 hover:bg-blue-50 border border-blue-200 px-3 py-1 rounded transition"
                                    data-testid="btn-edit-sig-<?= $sig['id'] ?>">
                                    Edit
                                </button>
                                <!-- Delete -->
                                <form method="POST" onsubmit="return confirm('Delete this signature?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_signature">
                                    <input type="hidden" name="sig_id" value="<?= $sig['id'] ?>">
                                    <button type="submit" class="text-xs text-red-600 hover:bg-red-50 border border-red-200 px-3 py-1 rounded transition" data-testid="btn-delete-sig-<?= $sig['id'] ?>">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Signature editor -->
        <div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h4 class="font-semibold text-gray-800 mb-4" id="sig-form-title">
                    <i class="fas fa-plus text-indigo-500 mr-1.5"></i>New Signature
                </h4>
                <form method="POST" class="space-y-4" id="sig-form" onsubmit="sigFormSubmit(this)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_signature">
                    <input type="hidden" name="sig_id" id="sig-form-id" value="0">
                    <input type="hidden" name="sig_html" id="sig-html-input">

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Signature Name <span class="text-red-500">*</span></label>
                        <input type="text" name="sig_name" id="sig-name-input" required placeholder="e.g. Tracy Williams — Support"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                            data-testid="input-sig-name">
                    </div>

                    <!-- Mini toolbar for signature editor -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Signature Content</label>
                        <div class="bg-gray-50 border border-b-0 border-gray-200 rounded-t-lg px-2 py-1.5 flex flex-wrap gap-0.5 items-center">
                            <select onchange="fmtFontFamily(this.value,'sig-rich-editor')" class="h-6 text-xs border border-gray-200 rounded px-1 bg-white text-gray-700 mr-1 focus:outline-none">
                                <option value="">Font</option>
                                <option value="Arial, sans-serif">Arial</option>
                                <option value="Georgia, serif">Georgia</option>
                                <option value="'Times New Roman', serif">Times New Roman</option>
                                <option value="Verdana, sans-serif">Verdana</option>
                                <option value="Tahoma, sans-serif">Tahoma</option>
                            </select>
                            <select onchange="fmtFontSize(this.value,'sig-rich-editor')" class="h-6 text-xs border border-gray-200 rounded px-1 bg-white text-gray-700 mr-1 focus:outline-none">
                                <option value="">Size</option>
                                <option value="11px">11</option>
                                <option value="12px">12</option>
                                <option value="13px">13</option>
                                <option value="14px">14</option>
                                <option value="16px">16</option>
                                <option value="18px">18</option>
                            </select>
                            <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                            <button type="button" onclick="fmtIn('bold','sig-rich-editor')" class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-200 font-bold text-gray-700 text-xs"><b>B</b></button>
                            <button type="button" onclick="fmtIn('italic','sig-rich-editor')" class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-200 italic text-gray-700 text-xs"><i>I</i></button>
                            <button type="button" onclick="fmtIn('underline','sig-rich-editor')" class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-200 underline text-gray-700 text-xs">U</button>
                            <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                            <div title="Text color">
                                <button type="button" class="w-6 h-6 flex flex-col items-center justify-center rounded hover:bg-gray-200" onclick="document.getElementById('sig-color-picker').click()">
                                    <span class="font-bold text-xs text-gray-700 leading-none">A</span>
                                    <span class="w-4 h-0.5 rounded-sm" id="sig-color-bar" style="background:#000;"></span>
                                </button>
                                <input type="color" id="sig-color-picker" value="#000000" class="sr-only" oninput="fmtTextColorIn(this.value,'sig-rich-editor','sig-color-bar')">
                            </div>
                            <span class="w-px bg-gray-300 mx-0.5 self-stretch"></span>
                            <button type="button" onclick="insertImageDialog('sig-rich-editor')" class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Insert image">
                                <i class="fas fa-image text-xs"></i>
                            </button>
                            <button type="button" onclick="insertLinkDialog('sig-rich-editor')" class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Insert link">
                                <i class="fas fa-link text-xs"></i>
                            </button>
                        </div>
                        <div id="sig-rich-editor" contenteditable="true"
                            class="w-full border border-gray-200 rounded-b-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none min-h-32 bg-white"
                            style="line-height:1.6;"
                            data-testid="sig-editor"
                            placeholder="Enter your signature HTML here…"></div>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                        <input type="checkbox" name="sig_default" id="sig-default-check" class="accent-indigo-600" data-testid="check-sig-default">
                        <span class="text-gray-700">Set as default signature</span>
                    </label>

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white text-sm py-2 rounded-lg font-medium transition" data-testid="btn-save-sig">
                            <i class="fas fa-save mr-1"></i><span id="sig-btn-label">Save Signature</span>
                        </button>
                        <button type="button" onclick="resetSigForm()" class="px-4 text-sm text-gray-500 border border-gray-200 rounded-lg hover:bg-gray-50 transition hidden" id="sig-cancel-btn">
                            Cancel
                        </button>
                    </div>
                </form>

                <!-- Quick-start tips -->
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <p class="text-xs font-medium text-gray-500 mb-2">Signature tips:</p>
                    <ul class="text-xs text-gray-400 space-y-1 list-disc list-inside">
                        <li>Add your name, title, phone &amp; email</li>
                        <li>Use an image for your logo or photo</li>
                        <li>Keep it concise — 4–6 lines max</li>
                        <li>Your default signature auto-appends when composing</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
    </div><!-- /content -->
</div><!-- /main -->

<!-- Save as Template modal (compose tab) -->
<div id="save-tpl-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-bookmark text-purple-500 mr-2"></i>Save as Template</h3>
            <button onclick="document.getElementById('save-tpl-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_template">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Template Name <span class="text-red-500">*</span></label>
                <input type="text" name="template_name" required placeholder="e.g. Monthly Newsletter"
                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"
                    data-testid="input-modal-tpl-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                <input type="text" name="subject" id="modal-subject" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Body</label>
                <textarea name="body" id="modal-body" rows="5" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"></textarea>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('save-tpl-modal').classList.add('hidden')"
                    class="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition" data-testid="btn-modal-save-tpl">
                    Save Template
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Contenteditable placeholder */
[contenteditable][placeholder]:empty:before {
    content: attr(placeholder);
    color: #9ca3af;
    pointer-events: none;
}
</style>
<script>
// ── Recipient toggle
function toggleClientList(val) {
    document.getElementById('client-select-wrap').classList.toggle('hidden', val !== 'selected');
}
function selectAll() { document.querySelectorAll('#client-list input[type=checkbox]').forEach(c => c.checked = true); }
function clearAll()   { document.querySelectorAll('#client-list input[type=checkbox]').forEach(c => c.checked = false); }
function filterClients() {
    const q = document.getElementById('client-search').value.toLowerCase();
    document.querySelectorAll('.client-row').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

// ── Body mode (rich/plain)
let bodyMode = 'rich';
function toggleBodyMode(mode) {
    bodyMode = mode;
    const rich = document.getElementById('rich-editor');
    const plain = document.getElementById('plain-editor');
    const toolbar = document.getElementById('rich-toolbar');
    const sigControls = document.getElementById('sig-controls');
    if (mode === 'rich') {
        rich.innerHTML = plain.value ? plain.value.replace(/\n/g,'<br>') : '';
        rich.classList.remove('hidden');
        toolbar.classList.remove('hidden');
        plain.classList.add('hidden');
        if (sigControls) sigControls.classList.remove('hidden');
    } else {
        plain.value = rich.innerText;
        plain.classList.remove('hidden');
        plain.name = 'body';
        rich.classList.add('hidden');
        toolbar.classList.add('hidden');
        if (sigControls) sigControls.classList.add('hidden');
    }
}

// Sync rich → hidden textarea on submit
document.getElementById('compose-form')?.addEventListener('submit', function () {
    if (bodyMode === 'rich') {
        const plain = document.getElementById('plain-editor');
        plain.classList.remove('hidden');
        plain.name = 'body';
        plain.value = document.getElementById('rich-editor').innerHTML;
    }
});

// ── Core formatting (targets the focused editor by default)
function fmt(cmd) { document.execCommand(cmd, false, null); }
function fmtIn(cmd, editorId) {
    const ed = document.getElementById(editorId);
    ed.focus();
    document.execCommand(cmd, false, null);
}

// ── Font family
function fmtFontFamily(family, editorId) {
    const ed = document.getElementById(editorId || 'rich-editor');
    ed.focus();
    if (family) {
        document.execCommand('styleWithCSS', false, true);
        document.execCommand('fontName', false, family);
    }
}

// ── Font size (uses size=7 marker trick then replaces with px)
function fmtFontSize(size, editorId) {
    const ed = document.getElementById(editorId || 'rich-editor');
    ed.focus();
    if (!size) return;
    document.execCommand('styleWithCSS', false, false);
    document.execCommand('fontSize', false, '7');
    // Replace all <font size="7"> with styled spans
    ed.querySelectorAll('font[size="7"]').forEach(el => {
        const span = document.createElement('span');
        span.style.fontSize = size;
        span.innerHTML = el.innerHTML;
        el.parentNode.replaceChild(span, el);
    });
    ed.focus();
}

// ── Text colour
function fmtTextColor(color) {
    document.execCommand('foreColor', false, color);
    const bar = document.getElementById('txt-color-bar');
    if (bar) bar.style.background = color;
    document.getElementById('rich-editor').focus();
}
function fmtTextColorIn(color, editorId, barId) {
    document.getElementById(editorId).focus();
    document.execCommand('foreColor', false, color);
    const bar = document.getElementById(barId);
    if (bar) bar.style.background = color;
    document.getElementById(editorId).focus();
}

// ── Highlight / background colour
function fmtHighlight(color) {
    document.execCommand('styleWithCSS', false, true);
    document.execCommand('hiliteColor', false, color);
    const bar = document.getElementById('hl-color-bar');
    if (bar) bar.style.background = color;
    document.getElementById('rich-editor').focus();
}

// ── Insert image dialog
function insertImageDialog(editorId) {
    const url = prompt('Image URL (https://…):');
    if (!url) return;
    const width = prompt('Width (e.g. 200px, 100%)', '300px') || '300px';
    const alt   = prompt('Alt text (optional)', '') || '';
    const ed = document.getElementById(editorId || 'rich-editor');
    ed.focus();
    document.execCommand('insertHTML', false,
        `<img src="${url.replace(/"/g,'&quot;')}" alt="${alt.replace(/"/g,'&quot;')}" style="max-width:${width};height:auto;display:inline-block;" />`
    );
}

// ── Insert link dialog
function insertLinkDialog(editorId) {
    const url  = prompt('Link URL (https://…):');
    if (!url) return;
    const text = prompt('Link text:', url) || url;
    const ed = document.getElementById(editorId || 'rich-editor');
    ed.focus();
    document.execCommand('insertHTML', false,
        `<a href="${url.replace(/"/g,'&quot;')}" style="color:#2563eb;">${text.replace(/</g,'&lt;')}</a>`
    );
}

// ── Insert placeholder into active editor
function insertPlaceholder(text) {
    if (bodyMode === 'rich') {
        const editor = document.getElementById('rich-editor');
        editor.focus();
        const sel = window.getSelection();
        if (sel && sel.rangeCount) {
            const r = sel.getRangeAt(0);
            r.deleteContents();
            r.insertNode(document.createTextNode(text));
            r.collapse(false);
        } else { editor.innerHTML += text; }
    } else {
        const ta = document.getElementById('plain-editor');
        const s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0,s) + text + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + text.length;
        ta.focus();
    }
}

// ── Load template into compose form
function loadTemplate(subject, body) {
    const si = document.getElementById('subject-input');
    if (si) si.value = subject;
    if (bodyMode === 'rich') {
        const re = document.getElementById('rich-editor');
        if (re) {
            // Keep signature, replace only message part before sig block
            const sigBlock = re.querySelector('[data-sig]');
            if (sigBlock) {
                re.innerHTML = body.replace(/\n/g,'<br>');
                re.appendChild(sigBlock);
            } else {
                re.innerHTML = body.replace(/\n/g,'<br>');
            }
        }
    } else {
        const pe = document.getElementById('plain-editor');
        if (pe) pe.value = body;
    }
}

// ── Pre-fill modal with current compose content
document.querySelector('[onclick*="save-tpl-modal"]')?.addEventListener('click', prefillModal);
function prefillModal() {
    const subj = document.getElementById('subject-input');
    const mSubj = document.getElementById('modal-subject');
    const mBody = document.getElementById('modal-body');
    if (subj && mSubj) mSubj.value = subj.value;
    if (mBody) {
        if (bodyMode === 'rich') {
            const re = document.getElementById('rich-editor');
            // Exclude sig block from template body
            const clone = re.cloneNode(true);
            clone.querySelectorAll('[data-sig]').forEach(el => el.remove());
            mBody.value = clone.innerHTML;
        } else {
            mBody.value = document.getElementById('plain-editor').value;
        }
    }
}

// ─── Signature management ──────────────────────────────────────────────────

// Change active signature in compose dropdown
function changeSig(sigId) {
    const ed = document.getElementById('rich-editor');
    if (!ed) return;
    // Remove existing sig block
    ed.querySelectorAll('[data-sig]').forEach(el => el.remove());
    if (!sigId) return;

    const opt = document.querySelector(`#sig-select option[value="${sigId}"]`);
    if (!opt) return;
    const html = opt.dataset.html || '';
    if (!html) return;

    const sigDiv = document.createElement('div');
    sigDiv.dataset.sig = '1';
    sigDiv.setAttribute('contenteditable', 'false');
    sigDiv.style.cssText = 'margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb;color:#374151;';
    sigDiv.innerHTML = html;
    ed.appendChild(sigDiv);
}

// Auto-inject default signature on page load
(function initSig() {
    const sel = document.getElementById('sig-select');
    if (sel && sel.value) {
        // Slight delay to let editor render
        setTimeout(() => changeSig(sel.value), 100);
    }
})();

// ── Signature form (create / edit)
function editSignature(id, name, html, isDefault) {
    document.getElementById('sig-form-id').value    = id;
    document.getElementById('sig-name-input').value = name;
    document.getElementById('sig-rich-editor').innerHTML = html;
    document.getElementById('sig-default-check').checked = isDefault;
    document.getElementById('sig-form-title').innerHTML = '<i class="fas fa-pen text-indigo-500 mr-1.5"></i>Edit Signature';
    document.getElementById('sig-btn-label').textContent = 'Update Signature';
    document.getElementById('sig-cancel-btn').classList.remove('hidden');
    document.getElementById('sig-form').scrollIntoView({ behavior:'smooth', block:'start' });
}

function resetSigForm() {
    document.getElementById('sig-form-id').value    = '0';
    document.getElementById('sig-name-input').value = '';
    document.getElementById('sig-rich-editor').innerHTML = '';
    document.getElementById('sig-default-check').checked = false;
    document.getElementById('sig-form-title').innerHTML = '<i class="fas fa-plus text-indigo-500 mr-1.5"></i>New Signature';
    document.getElementById('sig-btn-label').textContent = 'Save Signature';
    document.getElementById('sig-cancel-btn').classList.add('hidden');
}

function sigFormSubmit(form) {
    const html = document.getElementById('sig-rich-editor').innerHTML;
    document.getElementById('sig-html-input').value = html;
}
</script>

</body>
</html>
