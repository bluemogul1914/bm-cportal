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
    </div>

    <!-- Tab nav -->
    <div class="bg-white border-b border-gray-200 px-6 flex gap-1">
        <?php foreach ([
            ['compose',   'fa-pen-to-square', 'Compose'],
            ['history',   'fa-clock-rotate-left', 'Sent History'],
            ['templates', 'fa-file-lines',    'Templates'],
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
                    <!-- Simple rich text toolbar -->
                    <div id="rich-toolbar" class="flex gap-1 bg-gray-50 border border-b-0 border-gray-200 rounded-t-lg px-2 py-1.5">
                        <button type="button" onclick="fmt('bold')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-sm font-bold text-gray-700" title="Bold"><b>B</b></button>
                        <button type="button" onclick="fmt('italic')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-sm italic text-gray-700" title="Italic"><i>I</i></button>
                        <button type="button" onclick="fmt('underline')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-sm underline text-gray-700" title="Underline">U</button>
                        <span class="w-px bg-gray-300 mx-1"></span>
                        <button type="button" onclick="fmt('insertOrderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-sm text-gray-700" title="Ordered list"><i class="fas fa-list-ol text-xs"></i></button>
                        <button type="button" onclick="fmt('insertUnorderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-sm text-gray-700" title="Unordered list"><i class="fas fa-list-ul text-xs"></i></button>
                        <span class="w-px bg-gray-300 mx-1"></span>
                        <button type="button" onclick="insertPlaceholder('{{ client.name }}')" class="text-xs text-blue-600 hover:bg-blue-50 px-2 h-7 rounded" title="Insert client name">{{client}}</button>
                    </div>
                    <div id="rich-editor" contenteditable="true"
                        class="w-full border border-gray-200 rounded-b-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none min-h-48 bg-white"
                        style="line-height:1.7;"
                        data-testid="rich-editor"></div>
                    <textarea id="plain-editor" name="body" class="hidden w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none min-h-48" placeholder="Type your message…" data-testid="textarea-body"></textarea>
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

<script>
// ── Recipient toggle
function toggleClientList(val) {
    document.getElementById('client-select-wrap').classList.toggle('hidden', val !== 'selected');
}

// ── Select all / clear checkboxes
function selectAll() { document.querySelectorAll('#client-list input[type=checkbox]').forEach(c => c.checked = true); }
function clearAll()   { document.querySelectorAll('#client-list input[type=checkbox]').forEach(c => c.checked = false); }

// ── Filter client list
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
    const rich  = document.getElementById('rich-editor');
    const plain = document.getElementById('plain-editor');
    const toolbar = document.getElementById('rich-toolbar');
    if (mode === 'rich') {
        const txt = plain.value;
        rich.innerHTML = txt ? txt.replace(/\n/g,'<br>') : '';
        rich.classList.remove('hidden');
        toolbar.classList.remove('hidden');
        plain.classList.add('hidden');
    } else {
        plain.value = rich.innerText;
        plain.classList.remove('hidden');
        plain.name = 'body';
        rich.classList.add('hidden');
        toolbar.classList.add('hidden');
    }
}

// Sync rich editor → hidden textarea on submit
document.getElementById('compose-form').addEventListener('submit', function () {
    if (bodyMode === 'rich') {
        const plain = document.getElementById('plain-editor');
        plain.classList.remove('hidden');
        plain.name = 'body';
        plain.value = document.getElementById('rich-editor').innerHTML;
    }
});

// ── Rich text formatting
function fmt(cmd) { document.execCommand(cmd, false, null); document.getElementById('rich-editor').focus(); }

// ── Insert placeholder into active editor
function insertPlaceholder(text) {
    if (bodyMode === 'rich') {
        const editor = document.getElementById('rich-editor');
        editor.focus();
        const sel = window.getSelection();
        if (sel && sel.rangeCount) {
            const range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(document.createTextNode(text));
            range.collapse(false);
        } else {
            editor.innerHTML += text;
        }
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
    document.getElementById('subject-input').value = subject;
    if (bodyMode === 'rich') {
        document.getElementById('rich-editor').innerHTML = body.replace(/\n/g,'<br>');
    } else {
        document.getElementById('plain-editor').value = body;
    }
}

// ── Pre-fill modal with current compose content
document.getElementById('save-tpl-modal')?.addEventListener('transitionend', prefillModal);
document.querySelector('[onclick*="save-tpl-modal"]')?.addEventListener('click', prefillModal);
function prefillModal() {
    const subj = document.getElementById('subject-input');
    const mSubj = document.getElementById('modal-subject');
    const mBody = document.getElementById('modal-body');
    if (subj && mSubj) mSubj.value = subj.value;
    if (mBody) {
        mBody.value = bodyMode === 'rich'
            ? document.getElementById('rich-editor').innerHTML
            : document.getElementById('plain-editor').value;
    }
}
</script>

</body>
</html>
