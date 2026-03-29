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

// ─── Mailbox definitions ──────────────────────────────────────────────────────
$MAILBOXES = [
    'staff'   => ['label'=>'Staff',   'icon'=>'fa-users',       'color'=>'#2563eb', 'bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'email'=>'staff@bluemogul.biz'],
    'dealers' => ['label'=>'Dealers', 'icon'=>'fa-handshake',   'color'=>'#059669', 'bg'=>'bg-green-100',  'text'=>'text-green-700',  'email'=>'dealers@bluemogul.biz'],
    'sales'   => ['label'=>'Sales',   'icon'=>'fa-chart-line',  'color'=>'#d97706', 'bg'=>'bg-orange-100', 'text'=>'text-orange-700', 'email'=>'sales@bluemogul.biz'],
];

// ─── DB Bootstrap ─────────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_messages (
            id          SERIAL PRIMARY KEY,
            mailbox     VARCHAR(50)  NOT NULL DEFAULT 'staff',
            folder      VARCHAR(20)  NOT NULL DEFAULT 'inbox',
            subject     TEXT         NOT NULL DEFAULT '(no subject)',
            body        TEXT         DEFAULT '',
            body_html   TEXT         DEFAULT '',
            from_name   VARCHAR(200) DEFAULT '',
            from_email  VARCHAR(200) DEFAULT '',
            to_name     VARCHAR(200) DEFAULT '',
            to_email    VARCHAR(200) DEFAULT '',
            thread_id   INTEGER,
            parent_id   INTEGER,
            is_read     BOOLEAN      DEFAULT false,
            is_starred  BOOLEAN      DEFAULT false,
            source      VARCHAR(50)  DEFAULT 'internal',
            sent_by     INTEGER,
            to_user_id  INTEGER,
            attachments JSONB        DEFAULT '[]',
            received_at TIMESTAMP    DEFAULT NOW(),
            created_at  TIMESTAMP    DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_mail_mailbox_folder ON mail_messages(mailbox, folder);
        CREATE INDEX IF NOT EXISTS idx_mail_thread ON mail_messages(thread_id);
        CREATE INDEX IF NOT EXISTS idx_mail_to_user ON mail_messages(to_user_id);

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
    // Add to_user_id column if it doesn't exist yet (migration-safe)
    try { $pdo->exec("ALTER TABLE mail_messages ADD COLUMN IF NOT EXISTS to_user_id INTEGER"); } catch(Exception $e2) {}
} catch (Exception $e) {}

// ─── Load current user's email profile ────────────────────────────────────────
$my_email_cfg = ['work_email'=>'', 'display_name'=>$user_name, 'receive_group'=>true];
try {
    $mec = $pdo->prepare("SELECT * FROM user_email_settings WHERE user_id=?");
    $mec->execute([$user_id]);
    $mec_row = $mec->fetch(PDO::FETCH_ASSOC);
    if ($mec_row) $my_email_cfg = array_merge($my_email_cfg, $mec_row);
} catch(Exception $e) {}
$my_work_email   = $my_email_cfg['work_email'] ?? '';
$my_display_name = $my_email_cfg['display_name'] ?: $user_name;

// Load which groups this user belongs to
$my_groups = [];
try {
    $mg = $pdo->prepare("SELECT group_slug FROM mail_group_members WHERE user_id=?");
    $mg->execute([$user_id]); $my_groups = array_column($mg->fetchAll(PDO::FETCH_ASSOC), 'group_slug');
} catch(Exception $e) {}

// ─── State ────────────────────────────────────────────────────────────────────
$view_mailbox = $_GET['mailbox'] ?? 'personal';
if ($view_mailbox !== 'personal' && $view_mailbox !== 'all' && !array_key_exists($view_mailbox, $MAILBOXES)) $view_mailbox = 'personal';

$view_folder  = $_GET['folder']  ?? 'inbox';
if (!in_array($view_folder, ['inbox','starred','sent','archived','trash'])) $view_folder = 'inbox';

$view_msg_id  = isset($_GET['msg']) ? intval($_GET['msg']) : 0;
$search       = trim($_GET['q']   ?? '');
$page         = max(1, intval($_GET['p'] ?? 1));
$per_page     = 30;

$success_msg = '';
$error_msg   = '';

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── Compose
    if ($action === 'compose') {
        $to_mailbox    = $_POST['to_mailbox']    ?? 'staff';
        $external_to   = trim(filter_var($_POST['external_to'] ?? '', FILTER_SANITIZE_EMAIL));
        $send_mode     = $_POST['send_mode']     ?? 'internal'; // 'internal' | 'external'
        if (!array_key_exists($to_mailbox, $MAILBOXES)) $to_mailbox = 'staff';
        $subject  = trim($_POST['subject']  ?? '') ?: '(no subject)';
        $body_html= trim($_POST['body_html']?? '');

        // Determine From identity
        $from_email_use = $my_work_email ?: ($my_display_name.'@portal');
        $from_name_use  = $my_display_name ?: $user_name;

        $smtp_result = null;
        // If external recipient provided, send a real SMTP email too
        if ($send_mode === 'external' && $external_to && filter_var($external_to, FILTER_VALIDATE_EMAIL)) {
            if ($my_work_email) {
                $smtp_result = send_email_as($external_to, $subject, $body_html, $my_work_email, $from_name_use);
            } else {
                // Fall back to system email
                $smtp_result = send_email($external_to, $subject, $body_html);
            }
            if (!($smtp_result['success'] ?? false)) {
                $error_msg = 'SMTP send failed: '.($smtp_result['error'] ?? 'unknown error');
            }
        }

        // Store in target mailbox inbox (internal delivery)
        try {
            $mb = $MAILBOXES[$to_mailbox];
            $dest_to_name  = $send_mode === 'external' ? $external_to : $mb['label'];
            $dest_to_email = $send_mode === 'external' ? $external_to : $mb['email'];

            $ins = $pdo->prepare("INSERT INTO mail_messages
                (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, source, sent_by, is_read, received_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,false,NOW()) RETURNING id");
            $ins->execute([
                $to_mailbox, 'inbox', $subject, $body_html,
                $from_name_use, $from_email_use,
                $dest_to_name, $dest_to_email,
                $send_mode === 'external' ? 'outbound' : 'internal', $user_id,
            ]);
            $new_id = $ins->fetchColumn();
            $pdo->prepare("UPDATE mail_messages SET thread_id=id WHERE id=?")->execute([$new_id]);

            // Fan out to group members' personal inboxes
            try {
                $gm = $pdo->prepare("SELECT gm.user_id FROM mail_group_members gm
                    JOIN user_email_settings ues ON ues.user_id=gm.user_id AND ues.receive_group=true
                    WHERE gm.group_slug=? AND gm.user_id != ?");
                $gm->execute([$to_mailbox, $user_id]);
                foreach ($gm->fetchAll(PDO::FETCH_ASSOC) as $member) {
                    $pdo->prepare("INSERT INTO mail_messages
                        (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, source, sent_by, to_user_id, thread_id, is_read, received_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,false,NOW())")
                        ->execute([$to_mailbox,'inbox',$subject,$body_html,$from_name_use,$from_email_use,$mb['label'],$mb['email'],'internal',$user_id,$member['user_id'],$new_id]);
                }
            } catch(Exception $fe) {}

            // Sent copy in personal sent folder
            $ins2 = $pdo->prepare("INSERT INTO mail_messages
                (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, source, sent_by, to_user_id, is_read, received_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,true,NOW())");
            $ins2->execute([
                $to_mailbox, 'sent', $subject, $body_html,
                $from_name_use, $from_email_use,
                $dest_to_name, $dest_to_email,
                $send_mode === 'external' ? 'outbound' : 'internal', $user_id, $user_id,
            ]);

            $success_msg = $send_mode === 'external'
                ? "Email sent to $external_to via SMTP."
                : "Message sent to {$mb['label']} inbox.";
            $view_mailbox = 'personal';
            $view_folder  = 'sent';
            $view_msg_id  = 0;
        } catch(Exception $e) { $error_msg = 'Failed to send: '.$e->getMessage(); }
    }

    // ── Reply
    if ($action === 'reply') {
        $parent_id  = intval($_POST['parent_id'] ?? 0);
        $body_html  = trim($_POST['body_html'] ?? '');
        if ($parent_id && $body_html) {
            $orig = $pdo->prepare("SELECT * FROM mail_messages WHERE id=?")->execute([$parent_id]) ? null : null;
            $pdo->prepare("SELECT * FROM mail_messages WHERE id=?")->execute([$parent_id]);
            $st = $pdo->prepare("SELECT * FROM mail_messages WHERE id=?");
            $st->execute([$parent_id]);
            $orig = $st->fetch(PDO::FETCH_ASSOC);
            if ($orig) {
                $thread_id = $orig['thread_id'] ?? $parent_id;
                $subject   = preg_match('/^re:/i',$orig['subject']) ? $orig['subject'] : 'Re: '.$orig['subject'];
                $mb = $MAILBOXES[$orig['mailbox']] ?? $MAILBOXES['staff'];
                try {
                    $ins = $pdo->prepare("INSERT INTO mail_messages
                        (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, thread_id, parent_id, source, sent_by, is_read, received_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,false,NOW()) RETURNING id");
                    $ins->execute([
                        $orig['mailbox'], 'inbox', $subject, $body_html,
                        $user_name, $user_name.'@portal',
                        $mb['label'], $mb['email'],
                        $thread_id, $parent_id, 'internal', $user_id,
                    ]);
                    $new_id = $ins->fetchColumn();
                    // Sent copy
                    $ins2 = $pdo->prepare("INSERT INTO mail_messages
                        (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, thread_id, parent_id, source, sent_by, is_read, received_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,true,NOW())");
                    $ins2->execute([
                        $orig['mailbox'], 'sent', $subject, $body_html,
                        $user_name, $user_name.'@portal',
                        $mb['label'], $mb['email'],
                        $thread_id, $parent_id, 'internal', $user_id,
                    ]);
                    $success_msg = 'Reply sent.';
                    $view_msg_id = $new_id;
                } catch(Exception $e) { $error_msg = 'Reply failed: '.$e->getMessage(); }
            }
        }
    }

    // ── Mark read / unread (AJAX-compatible)
    if ($action === 'mark_read' || $action === 'mark_unread') {
        $msg_id = intval($_POST['msg_id'] ?? 0);
        $val = $action === 'mark_read' ? 'true' : 'false';
        if ($msg_id) $pdo->prepare("UPDATE mail_messages SET is_read=$val WHERE id=?")->execute([$msg_id]);
        if (isset($_POST['ajax'])) { echo json_encode(['ok'=>true]); exit; }
    }

    // ── Star / Unstar
    if ($action === 'star' || $action === 'unstar') {
        $msg_id = intval($_POST['msg_id'] ?? 0);
        $val = $action === 'star' ? 'true' : 'false';
        if ($msg_id) $pdo->prepare("UPDATE mail_messages SET is_starred=$val WHERE id=?")->execute([$msg_id]);
        if (isset($_POST['ajax'])) { echo json_encode(['ok'=>true]); exit; }
    }

    // ── Archive
    if ($action === 'archive') {
        $msg_id = intval($_POST['msg_id'] ?? 0);
        if ($msg_id) $pdo->prepare("UPDATE mail_messages SET folder='archived' WHERE id=?")->execute([$msg_id]);
        $view_msg_id = 0;
        $success_msg = 'Message archived.';
    }

    // ── Delete (move to trash)
    if ($action === 'delete') {
        $msg_id = intval($_POST['msg_id'] ?? 0);
        if ($msg_id) {
            $row = $pdo->prepare("SELECT folder FROM mail_messages WHERE id=?");
            $row->execute([$msg_id]);
            $cur = $row->fetchColumn();
            if ($cur === 'trash') {
                $pdo->prepare("DELETE FROM mail_messages WHERE id=?")->execute([$msg_id]);
                $success_msg = 'Message permanently deleted.';
            } else {
                $pdo->prepare("UPDATE mail_messages SET folder='trash' WHERE id=?")->execute([$msg_id]);
                $success_msg = 'Message moved to trash.';
            }
        }
        $view_msg_id = 0;
    }

    // ── Restore from trash
    if ($action === 'restore') {
        $msg_id = intval($_POST['msg_id'] ?? 0);
        if ($msg_id) $pdo->prepare("UPDATE mail_messages SET folder='inbox' WHERE id=?")->execute([$msg_id]);
        $success_msg = 'Message restored to inbox.';
        $view_msg_id = 0;
    }
}

// ─── Build message query ──────────────────────────────────────────────────────
$where = ['folder = ?'];
$params = [$view_folder];

if ($view_mailbox === 'personal') {
    // Personal inbox: messages delivered to this user specifically
    // OR sent by this user (sent folder), OR group messages if they're a member
    if ($view_folder === 'sent') {
        $where[] = 'sent_by = ?';
        $params[] = $user_id;
    } else {
        $where[] = '(to_user_id = ? OR (to_user_id IS NULL AND sent_by = ?))';
        $params[] = $user_id;
        $params[] = $user_id;
    }
} elseif ($view_mailbox !== 'all') {
    $where[] = 'mailbox = ?';
    $params[] = $view_mailbox;
    // Non-admin: only if member of that group
    if (!$is_admin && !in_array($view_mailbox, $my_groups)) {
        $where[] = '1=0'; // No access
    }
}

if ($view_folder === 'starred') {
    $where = ['is_starred = true'];
    $params = [];
    if ($view_mailbox === 'personal') {
        $where[] = '(to_user_id = ? OR sent_by = ?)';
        $params = [$user_id, $user_id];
    } elseif ($view_mailbox !== 'all') {
        $where[] = 'mailbox = ?';
        $params[] = $view_mailbox;
    }
}
if ($search) {
    $where[] = "(subject ILIKE ? OR body_html ILIKE ? OR from_name ILIKE ? OR from_email ILIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}

$wh_sql = 'WHERE ' . implode(' AND ', $where);

// Unread counts
$unread_counts = ['personal' => 0];
try {
    // Personal unread
    $pu = $pdo->prepare("SELECT COUNT(*) FROM mail_messages WHERE folder='inbox' AND is_read=false AND (to_user_id=? OR sent_by=?)");
    $pu->execute([$user_id, $user_id]); $unread_counts['personal'] = (int)$pu->fetchColumn();
    // Group unread
    $uc = $pdo->query("SELECT mailbox, COUNT(*) as cnt FROM mail_messages WHERE folder='inbox' AND is_read=false AND to_user_id IS NULL GROUP BY mailbox");
    foreach($uc->fetchAll(PDO::FETCH_ASSOC) as $r) $unread_counts[$r['mailbox']] = (int)$r['cnt'];
} catch(Exception $e) {}

// Total for pagination
$total_msgs = 0;
try {
    $ct = $pdo->prepare("SELECT COUNT(*) FROM mail_messages $wh_sql");
    $ct->execute($params); $total_msgs = (int)$ct->fetchColumn();
} catch(Exception $e) {}

// Message list
$messages = [];
try {
    $off = ($page-1)*$per_page;
    $ls = $pdo->prepare("SELECT * FROM mail_messages $wh_sql ORDER BY received_at DESC LIMIT ? OFFSET ?");
    $ls->execute(array_merge($params, [$per_page, $off]));
    $messages = $ls->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Current message + thread
$current_msg   = null;
$thread_msgs   = [];
if ($view_msg_id) {
    try {
        $ms = $pdo->prepare("SELECT * FROM mail_messages WHERE id=?");
        $ms->execute([$view_msg_id]);
        $current_msg = $ms->fetch(PDO::FETCH_ASSOC);
        if ($current_msg) {
            // Mark as read
            if (!$current_msg['is_read']) {
                $pdo->prepare("UPDATE mail_messages SET is_read=true WHERE id=?")->execute([$view_msg_id]);
                $current_msg['is_read'] = true;
            }
            // Load thread
            $tid = $current_msg['thread_id'] ?? $current_msg['id'];
            $ts = $pdo->prepare("SELECT * FROM mail_messages WHERE (thread_id=? OR id=?) AND folder != 'trash' ORDER BY received_at ASC");
            $ts->execute([$tid, $tid]);
            $thread_msgs = $ts->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(Exception $e) {}
}

function mail_avatar($name, $color='#2563eb') {
    $init = mb_strtoupper(mb_substr(trim($name)?:'?', 0, 1));
    return "<span style='width:34px;height:34px;border-radius:50%;background:$color;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;flex-shrink:0;'>$init</span>";
}
function mail_time($ts) {
    $t = strtotime($ts ?? 'now');
    $diff = time() - $t;
    if ($diff < 86400) return date('g:i A', $t);
    if ($diff < 604800) return date('D', $t);
    return date('M j', $t);
}
function mail_preview($html) {
    return htmlspecialchars(mb_substr(strip_tags($html ?? ''), 0, 90));
}

$mb_info = $view_mailbox !== 'all' ? ($MAILBOXES[$view_mailbox] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $mb_info ? htmlspecialchars($mb_info['label']).' Mail' : 'All Mail' ?> | Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-family: Inter, system-ui, sans-serif; }

/* Scrollable panes */
#mail-list { overflow-y: auto; }
#mail-detail { overflow-y: auto; }

/* Message row */
.msg-row { border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background .1s; }
.msg-row:hover { background:#f8fafc; }
.msg-row.active { background:#eff6ff; border-left:3px solid #2563eb; }
.msg-row.unread .msg-subject { font-weight:700; color:#0f172a; }
.msg-row .msg-subject { font-weight:500; color:#374151; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.msg-row .msg-from { font-size:12.5px; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; }
.msg-row .msg-preview { font-size:12px; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.msg-row .msg-time { font-size:11px; color:#94a3b8; white-space:nowrap; flex-shrink:0; }

/* Star */
.star-btn { color:#d1d5db; cursor:pointer; transition:color .15s; }
.star-btn.starred { color:#f59e0b; }
.star-btn:hover { color:#f59e0b; }

/* Sidebar nav items */
.nav-item { display:flex; align-items:center; gap:9px; padding:7px 14px; border-radius:8px; cursor:pointer; font-size:13px; color:#e2e8f0; transition:background .15s; text-decoration:none; }
.nav-item:hover { background:rgba(255,255,255,.08); }
.nav-item.active { background:rgba(255,255,255,.15); color:#fff; font-weight:600; }
.nav-item .cnt { margin-left:auto; font-size:11px; background:rgba(239,68,68,.9); color:#fff; min-width:18px; height:18px; border-radius:9px; display:flex; align-items:center; justify-content:center; padding:0 4px; font-weight:700; }

/* Mail header */
.mail-hd { background:#fff; border-bottom:1px solid #e2e8f0; padding:14px 20px; }
.mail-hd .subj { font-size:17px; font-weight:700; color:#0f172a; line-height:1.3; }
.mail-hd-meta { display:flex; align-items:center; gap:10px; margin-top:8px; flex-wrap:wrap; }
.hd-pill { display:inline-flex; align-items:center; gap:4px; font-size:12px; padding:2px 8px; border-radius:20px; }

/* Rich text viewer */
.mail-body { font-size:14px; line-height:1.75; color:#374151; word-break:break-word; }
.mail-body img { max-width:100%; height:auto; border-radius:6px; }
.mail-body a { color:#2563eb; text-decoration:underline; }
.mail-body blockquote { border-left:3px solid #e2e8f0; padding-left:12px; color:#6b7280; margin:8px 0; }
.mail-body pre { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; font-family:monospace; font-size:12px; overflow-x:auto; white-space:pre-wrap; }

/* Compose modal */
#compose-modal { display:none; position:fixed; inset:0; z-index:9000; }
#compose-modal.open { display:flex; }
.compose-box { position:fixed; bottom:0; right:24px; width:520px; max-height:620px; background:#fff; border-radius:14px 14px 0 0; box-shadow:0 -4px 40px rgba(0,0,0,.18); display:flex; flex-direction:column; overflow:hidden; }

/* Reply */
.reply-box { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; margin:16px 20px 20px; }
.reply-toolbar { display:flex; flex-wrap:wrap; gap:3px; padding:8px 10px; border-bottom:1px solid #e2e8f0; }
.reply-toolbar button { width:26px; height:26px; display:flex; align-items:center; justify-content:center; border:none; border-radius:5px; background:transparent; cursor:pointer; color:#6b7280; }
.reply-toolbar button:hover { background:#e2e8f0; color:#374151; }
.reply-editor { min-height:80px; max-height:160px; overflow-y:auto; padding:10px 13px; outline:none; font-size:13.5px; line-height:1.65; }
.reply-footer { display:flex; justify-content:flex-end; padding:8px 10px; gap:8px; border-top:1px solid #e2e8f0; }

/* Thread messages */
.thread-sep { display:flex; align-items:center; gap:10px; margin:16px 20px; }
.thread-sep::before, .thread-sep::after { content:''; flex:1; height:1px; background:#e2e8f0; }
.thread-sep span { font-size:11px; color:#94a3b8; white-space:nowrap; }
</style>
</head>
<body class="flex h-screen overflow-hidden bg-gray-100">

<?php require_once 'includes/admin-sidebar.php'; ?>

<!-- ══════ MAIL APP ══════ -->
<div class="flex-1 flex overflow-hidden">

<!-- ── Mail sidebar (230px) ────────────────────────────────────────────── -->
<div class="w-56 flex-shrink-0 flex flex-col" style="background:#1e293b;">
    <div class="p-4">
        <button onclick="openCompose()"
            class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2.5 text-sm font-semibold transition shadow-sm"
            data-testid="btn-compose">
            <i class="fas fa-pen-to-square"></i> Compose
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-3 pb-4 space-y-1">

        <!-- ── My Inbox ── -->
        <div class="mt-1 mb-1 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">My Mail</div>

        <?php
        $personal_folders = [
            ['inbox',    'fa-inbox',       'My Inbox',  $unread_counts['personal'] ?? 0],
            ['starred',  'fa-star',        'Starred',   0],
            ['sent',     'fa-paper-plane', 'Sent',      0],
            ['archived', 'fa-box-archive', 'Archived',  0],
            ['trash',    'fa-trash',       'Trash',     0],
        ];
        foreach ($personal_folders as [$f,$ico,$lbl,$cnt]): ?>
        <a href="admin-mail.php?mailbox=personal&folder=<?= $f ?>"
           class="nav-item <?= $view_mailbox==='personal' && $view_folder===$f && !$search ? 'active':'' ?>"
           data-testid="nav-personal-<?= $f ?>">
            <i class="fas <?= $ico ?> w-4 text-center opacity-75"></i>
            <span><?= $lbl ?></span>
            <?php if ($f==='inbox' && $cnt>0): ?>
            <span class="cnt"><?= $cnt ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <!-- ── Group Mailboxes ── -->
        <div class="mt-3 mb-1 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Group Mailboxes</div>

        <a href="admin-mail.php?mailbox=all&folder=<?= $view_folder ?>"
           class="nav-item <?= $view_mailbox==='all'?'active':'' ?>" data-testid="nav-mailbox-all">
            <i class="fas fa-layer-group w-4 text-center opacity-75"></i>
            <span>All Groups</span>
        </a>

        <?php foreach ($MAILBOXES as $slug=>$mb):
            $uc = $unread_counts[$slug] ?? 0;
            $is_member = in_array($slug, $my_groups) || $is_admin; ?>
        <a href="admin-mail.php?mailbox=<?= $slug ?>&folder=<?= $view_folder ?>"
           class="nav-item <?= $view_mailbox===$slug?'active':'' ?> <?= !$is_member?'opacity-40':'' ?>"
           title="<?= !$is_member?'Not a member — contact admin':''.htmlspecialchars($mb['label']).' mailbox' ?>"
           data-testid="nav-mailbox-<?= $slug ?>">
            <i class="fas <?= $mb['icon'] ?> w-4 text-center opacity-75"></i>
            <span><?= htmlspecialchars($mb['label']) ?></span>
            <?php if ($uc>0 && $is_member): ?>
            <span class="cnt"><?= $uc ?></span>
            <?php endif; ?>
            <?php if ($is_member && !$is_admin): ?>
            <span class="w-1.5 h-1.5 rounded-full bg-green-400 flex-shrink-0 ml-auto opacity-75"></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <!-- ── Settings ── -->
        <div class="mt-3 mb-1 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Settings</div>

        <a href="admin-mail-profile.php" class="nav-item <?= basename($_SERVER['PHP_SELF'])==='admin-mail-profile.php'?'active':'' ?>"
           data-testid="nav-email-profile">
            <i class="fas fa-at w-4 text-center opacity-75"></i>
            <span>Email Profile</span>
            <?php if (!$my_work_email): ?>
            <span class="text-xs text-yellow-400 ml-auto" title="Work email not set">!</span>
            <?php endif; ?>
        </a>

        <a href="#" onclick="document.getElementById('webhook-info').classList.toggle('hidden');return false;"
           class="nav-item" data-testid="nav-webhook">
            <i class="fas fa-plug w-4 text-center opacity-75"></i>
            <span>Inbound Setup</span>
        </a>
    </div>

    <!-- Work email status bar -->
    <?php if ($my_work_email): ?>
    <div class="px-3 py-2 border-t border-slate-700 text-xs text-slate-400 flex items-center gap-2 flex-shrink-0">
        <i class="fas fa-at text-green-400"></i>
        <span class="truncate"><?= htmlspecialchars($my_work_email) ?></span>
    </div>
    <?php else: ?>
    <a href="admin-mail-profile.php" class="px-3 py-2 border-t border-slate-700 text-xs text-yellow-400 flex items-center gap-2 flex-shrink-0 hover:text-yellow-300">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Set up work email</span>
    </a>
    <?php endif; ?>
</div>

<!-- ── Message list (340px) ────────────────────────────────────────────── -->
<div class="flex flex-col border-r border-gray-200 bg-white" style="width:340px;flex-shrink:0;">
    <!-- List header -->
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-3 flex-shrink-0">
        <div class="flex-1">
            <?php if ($mb_info): ?>
            <h2 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                <i class="fas <?= $mb_info['icon'] ?> text-xs" style="color:<?= $mb_info['color'] ?>"></i>
                <?= htmlspecialchars($mb_info['label']) ?> ·
                <span class="capitalize text-gray-500 font-normal"><?= $view_folder ?></span>
            </h2>
            <?php else: ?>
            <h2 class="font-bold text-gray-900 text-sm">All Mail · <span class="text-gray-500 font-normal capitalize"><?= $view_folder ?></span></h2>
            <?php endif; ?>
        </div>
        <span class="text-xs text-gray-400"><?= number_format($total_msgs) ?></span>
    </div>

    <!-- Search -->
    <form method="GET" class="px-3 py-2 border-b border-gray-100 flex-shrink-0">
        <input type="hidden" name="mailbox" value="<?= htmlspecialchars($view_mailbox) ?>">
        <input type="hidden" name="folder"  value="<?= htmlspecialchars($view_folder) ?>">
        <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5">
            <i class="fas fa-search text-gray-400 text-xs"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search mail…"
                class="flex-1 bg-transparent outline-none text-sm text-gray-700"
                data-testid="input-mail-search">
            <?php if ($search): ?>
            <a href="admin-mail.php?mailbox=<?= $view_mailbox ?>&folder=<?= $view_folder ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xs"></i></a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Message list -->
    <div id="mail-list" class="flex-1">
        <?php if (empty($messages)): ?>
        <div class="p-10 text-center text-gray-400">
            <i class="fas fa-inbox text-4xl mb-3 block opacity-25"></i>
            <p class="text-sm"><?= $search ? 'No messages match your search.' : ucfirst($view_folder).' is empty.' ?></p>
        </div>
        <?php else: ?>
        <?php foreach ($messages as $msg):
            $is_active = $msg['id'] == $view_msg_id;
            $mb_def = $MAILBOXES[$msg['mailbox']] ?? ['color'=>'#64748b','label'=>$msg['mailbox']];
            $url = "admin-mail.php?mailbox={$view_mailbox}&folder={$view_folder}&msg={$msg['id']}".($search?"&q=".urlencode($search):'');
        ?>
        <div class="msg-row px-3 py-3 flex gap-2.5 <?= $is_active?'active':'' ?> <?= !$msg['is_read']&&!$is_active?'unread':'' ?>"
             onclick="location.href='<?= $url ?>'"
             data-id="<?= $msg['id'] ?>"
             data-testid="msg-row-<?= $msg['id'] ?>">

            <!-- Avatar -->
            <div class="flex-shrink-0 pt-0.5">
                <?= mail_avatar($msg['from_name'], $mb_def['color']) ?>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 mb-0.5">
                    <span class="msg-from flex-1"><?= htmlspecialchars($msg['from_name'] ?: $msg['from_email']) ?></span>
                    <span class="msg-time"><?= mail_time($msg['received_at']) ?></span>
                    <!-- Unread dot -->
                    <?php if (!$msg['is_read']): ?>
                    <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:<?= $mb_def['color'] ?>"></span>
                    <?php endif; ?>
                </div>
                <div class="msg-subject"><?= htmlspecialchars($msg['subject']) ?></div>
                <div class="msg-preview mt-0.5"><?= mail_preview($msg['body_html']) ?></div>
                <!-- Mailbox tag if viewing "all" -->
                <?php if ($view_mailbox === 'all'): ?>
                <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full" style="background:<?= $mb_def['color'] ?>22;color:<?= $mb_def['color'] ?>;">
                    <?= htmlspecialchars($mb_def['label']) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Star -->
            <button class="star-btn <?= $msg['is_starred']?'starred':'' ?> flex-shrink-0 mt-0.5"
                onclick="event.stopPropagation();toggleStar(<?= $msg['id'] ?>,this)"
                data-testid="btn-star-<?= $msg['id'] ?>">
                <i class="<?= $msg['is_starred']?'fas':'far' ?> fa-star text-sm"></i>
            </button>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($total_msgs > $per_page): ?>
        <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-400">
            <span><?= number_format($total_msgs) ?> total</span>
            <div class="flex gap-1">
                <?php $total_pages = ceil($total_msgs/$per_page); for($pp=1;$pp<=$total_pages&&$pp<=8;$pp++): ?>
                <a href="?mailbox=<?= $view_mailbox ?>&folder=<?= $view_folder ?>&p=<?= $pp ?><?= $search?'&q='.urlencode($search):'' ?>"
                   class="px-2 py-1 rounded <?= $pp===$page?'bg-blue-600 text-white':'hover:bg-gray-100' ?>"><?= $pp ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Message detail (flex) ───────────────────────────────────────────── -->
<div id="mail-detail" class="flex-1 bg-white flex flex-col overflow-y-auto">

<?php if ($success_msg || $error_msg): ?>
<div class="mx-5 mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2 <?= $success_msg?'bg-green-50 border border-green-200 text-green-700':'bg-red-50 border border-red-200 text-red-700' ?>">
    <i class="fas <?= $success_msg?'fa-check-circle':'fa-exclamation-circle' ?>"></i>
    <?= htmlspecialchars($success_msg ?: $error_msg) ?>
</div>
<?php endif; ?>

<?php if ($current_msg): ?>
    <?php $mb_def = $MAILBOXES[$current_msg['mailbox']] ?? ['color'=>'#64748b','label'=>$current_msg['mailbox'],'email'=>'']; ?>

    <!-- Message header -->
    <div class="mail-hd flex-shrink-0">
        <div class="flex items-start gap-3">
            <div class="flex-1 min-w-0">
                <div class="subj"><?= htmlspecialchars($current_msg['subject']) ?></div>
                <div class="mail-hd-meta">
                    <?php if ($current_msg['source']==='inbound'): ?>
                    <span class="hd-pill bg-blue-50 text-blue-700 border border-blue-200">
                        <i class="fas fa-inbox text-xs"></i> Inbound
                    </span>
                    <?php endif; ?>
                    <span class="hd-pill" style="background:<?= $mb_def['color'] ?>18;color:<?= $mb_def['color'] ?>;">
                        <i class="fas fa-tag text-xs"></i> <?= htmlspecialchars($mb_def['label']) ?>
                    </span>
                    <span class="text-xs text-gray-500">
                        <?= date('D, M j, Y g:i A', strtotime($current_msg['received_at'])) ?>
                    </span>
                </div>
            </div>
            <!-- Action buttons -->
            <div class="flex gap-2 flex-shrink-0">
                <button onclick="toggleStar(<?= $current_msg['id'] ?>, this)" class="star-btn <?= $current_msg['is_starred']?'starred':'' ?> text-lg" title="Star" data-testid="btn-detail-star">
                    <i class="<?= $current_msg['is_starred']?'fas':'far' ?> fa-star"></i>
                </button>
                <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="msg_id" value="<?= $current_msg['id'] ?>">
                    <button type="submit" class="text-gray-400 hover:text-gray-700 p-1.5 rounded hover:bg-gray-100" title="Archive" data-testid="btn-detail-archive">
                        <i class="fas fa-box-archive text-sm"></i>
                    </button>
                </form>
                <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $current_msg['folder']==='trash'?'restore':'delete' ?>">
                    <input type="hidden" name="msg_id" value="<?= $current_msg['id'] ?>">
                    <button type="submit"
                        onclick="return <?= $current_msg['folder']==='trash'?'true':'confirm(\'Move to trash?\')' ?>"
                        class="text-gray-400 hover:text-red-600 p-1.5 rounded hover:bg-red-50 transition" title="<?= $current_msg['folder']==='trash'?'Restore':'Delete' ?>"
                        data-testid="btn-detail-delete">
                        <i class="fas <?= $current_msg['folder']==='trash'?'fa-rotate-left':'fa-trash' ?> text-sm"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- From / To -->
        <div class="mt-3 space-y-1">
            <div class="flex gap-2 text-sm">
                <span class="text-gray-400 w-8 text-right flex-shrink-0">From</span>
                <span class="font-medium text-gray-800"><?= htmlspecialchars($current_msg['from_name']) ?></span>
                <span class="text-gray-400">&lt;<?= htmlspecialchars($current_msg['from_email']) ?>&gt;</span>
            </div>
            <div class="flex gap-2 text-sm">
                <span class="text-gray-400 w-8 text-right flex-shrink-0">To</span>
                <span class="text-gray-600"><?= htmlspecialchars($current_msg['to_name'] ?: $current_msg['to_email']) ?></span>
            </div>
        </div>
    </div>

    <!-- Thread messages -->
    <?php foreach ($thread_msgs as $ti => $tmsg): ?>
    <?php if ($ti > 0): ?>
    <div class="thread-sep"><span><?= date('M j', strtotime($tmsg['received_at'])) ?> · <?= htmlspecialchars($tmsg['from_name']) ?></span></div>
    <?php endif; ?>

    <div class="px-5 py-5" data-testid="msg-body-<?= $tmsg['id'] ?>">
        <div class="flex items-center gap-3 mb-4">
            <?= mail_avatar($tmsg['from_name'], $MAILBOXES[$tmsg['mailbox']]['color'] ?? '#64748b') ?>
            <div>
                <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($tmsg['from_name']) ?></div>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($tmsg['from_email']) ?> · <?= date('M j, Y g:i A', strtotime($tmsg['received_at'])) ?></div>
            </div>
        </div>
        <div class="mail-body">
            <?= $tmsg['body_html'] ?: '<p class="text-gray-400 italic">No content.</p>' ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Reply box -->
    <?php if ($current_msg['folder'] !== 'trash'): ?>
    <div class="reply-box" id="reply-wrap">
        <form method="POST" onsubmit="syncReplyBody(this)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="parent_id" value="<?= $current_msg['id'] ?>">
            <input type="hidden" name="body_html" id="reply-html-input">

            <!-- Toolbar -->
            <div class="reply-toolbar">
                <button type="button" onclick="fmtReply('bold')" title="Bold"><b class="text-xs">B</b></button>
                <button type="button" onclick="fmtReply('italic')" title="Italic"><i class="text-xs">I</i></button>
                <button type="button" onclick="fmtReply('underline')" title="Underline"><u class="text-xs">U</u></button>
                <span style="width:1px;background:#e2e8f0;margin:2px 4px;"></span>
                <button type="button" onclick="fmtReply('insertOrderedList')"><i class="fas fa-list-ol text-xs"></i></button>
                <button type="button" onclick="fmtReply('insertUnorderedList')"><i class="fas fa-list-ul text-xs"></i></button>
                <span style="width:1px;background:#e2e8f0;margin:2px 4px;"></span>
                <div title="Text color">
                    <button type="button" onclick="document.getElementById('reply-color').click()" class="font-bold text-xs w-7 h-7" style="color:var(--rc,#000)">A</button>
                    <input type="color" id="reply-color" class="sr-only" oninput="fmtReplyColor(this.value)" value="#000000">
                </div>
                <button type="button" onclick="insertReplyImg()" title="Image"><i class="fas fa-image text-xs"></i></button>
                <button type="button" onclick="insertReplyLink()" title="Link"><i class="fas fa-link text-xs"></i></button>
            </div>

            <!-- Reply editor -->
            <div id="reply-editor" contenteditable="true" class="reply-editor"
                placeholder="Write your reply…"
                data-testid="reply-editor"></div>

            <div class="reply-footer">
                <button type="button" onclick="document.getElementById('reply-editor').innerHTML=''" class="text-xs text-gray-400 hover:text-gray-700 px-3 py-1.5 rounded border border-gray-200 hover:bg-gray-50">
                    Discard
                </button>
                <button type="submit" class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded font-medium transition" data-testid="btn-send-reply">
                    <i class="fas fa-paper-plane mr-1"></i>Send Reply
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

<?php elseif ($view_mailbox !== 'all' && empty($messages) && !$search): ?>
    <!-- Empty welcome for mailbox -->
    <div class="flex-1 flex flex-col items-center justify-center text-center p-12">
        <?php if ($mb_info): ?>
        <div class="w-16 h-16 rounded-2xl flex items-center justify-center text-white text-2xl mb-4"
             style="background:<?= $mb_info['color'] ?>;">
            <i class="fas <?= $mb_info['icon'] ?>"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars($mb_info['label']) ?> Mailbox</h2>
        <p class="text-gray-500 text-sm mb-1">Mailbox address: <code class="bg-gray-100 px-2 py-0.5 rounded font-mono text-xs"><?= htmlspecialchars($mb_info['email']) ?></code></p>
        <p class="text-gray-400 text-sm mb-6">No messages yet. Send a message or configure inbound email routing.</p>
        <button onclick="openCompose('<?= $view_mailbox ?>')" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-medium text-sm transition">
            <i class="fas fa-pen-to-square mr-2"></i>Compose First Message
        </button>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- No message selected -->
    <div class="flex-1 flex flex-col items-center justify-center text-center p-12 text-gray-400">
        <i class="fas fa-envelope-open text-6xl mb-4 opacity-20"></i>
        <p class="text-sm">Select a message to read it</p>
    </div>
<?php endif; ?>

    <!-- Webhook Info Panel -->
    <div id="webhook-info" class="hidden mx-5 my-4 bg-slate-50 border border-slate-200 rounded-xl p-5 text-sm">
        <h4 class="font-bold text-gray-900 mb-3 flex items-center gap-2"><i class="fas fa-plug text-indigo-500"></i>Inbound Email Configuration</h4>
        <p class="text-gray-600 mb-4">Configure your email provider to forward incoming emails to the webhook URL below. Each team mailbox has its own address:</p>
        <div class="space-y-3 mb-4">
            <?php foreach ($MAILBOXES as $slug=>$mb): ?>
            <div class="flex items-center gap-3">
                <span class="w-20 text-xs font-semibold" style="color:<?= $mb['color'] ?>"><?= $mb['label'] ?></span>
                <code class="flex-1 bg-white border border-slate-200 rounded px-3 py-1.5 text-xs font-mono text-gray-700"><?= htmlspecialchars($mb['email']) ?></code>
                <span class="text-xs text-gray-400">→ /<?= $slug ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-3 mb-3">
            <div class="text-xs font-semibold text-gray-600 mb-1.5">Webhook URL (Mailgun / SendGrid / Postmark):</div>
            <code class="text-xs font-mono text-blue-700 break-all">
                <?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') ?>://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') ?>/mail-webhook.php
            </code>
        </div>
        <p class="text-xs text-gray-400">The webhook reads the <code>To:</code> header to route to the right mailbox. Set up an MX record and inbound routing in your email provider's dashboard.</p>
        <button onclick="document.getElementById('webhook-info').classList.add('hidden')" class="mt-3 text-xs text-gray-400 hover:text-gray-700">Dismiss</button>
    </div>
</div>

</div><!-- /mail app -->

<!-- ══════ COMPOSE MODAL ══════ -->
<div id="compose-modal">
    <div class="fixed inset-0 bg-black/30 backdrop-blur-sm" onclick="closeCompose()"></div>
    <div class="compose-box">
        <!-- Header -->
        <div class="flex items-center gap-3 px-5 py-3 bg-gray-900 text-white flex-shrink-0 rounded-t-xl">
            <i class="fas fa-pen-to-square text-sm opacity-75"></i>
            <span class="font-semibold text-sm">New Message</span>
            <button onclick="closeCompose()" class="ml-auto text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" onsubmit="syncComposeBody(this)" class="flex flex-col flex-1 overflow-hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="compose">
            <input type="hidden" name="body_html" id="compose-html-input">
            <input type="hidden" name="send_mode" id="compose-send-mode" value="internal">

            <!-- Send mode toggle -->
            <div class="px-4 py-2 bg-gray-50 border-b border-gray-100 flex items-center gap-2 flex-shrink-0">
                <span class="text-xs text-gray-500">Send as:</span>
                <div class="flex rounded-lg overflow-hidden border border-gray-200 text-xs">
                    <button type="button" id="mode-internal-btn" onclick="setSendMode('internal')"
                        class="px-3 py-1.5 bg-blue-600 text-white font-medium" data-testid="btn-mode-internal">
                        <i class="fas fa-layer-group mr-1"></i>Internal
                    </button>
                    <button type="button" id="mode-external-btn" onclick="setSendMode('external')"
                        class="px-3 py-1.5 bg-white text-gray-600 hover:bg-gray-50 font-medium" data-testid="btn-mode-external">
                        <i class="fas fa-globe mr-1"></i>External Email
                    </button>
                </div>
                <?php if ($my_work_email): ?>
                <span class="text-xs text-gray-400 ml-auto">From: <strong class="text-gray-600"><?= htmlspecialchars($my_work_email) ?></strong></span>
                <?php else: ?>
                <a href="admin-mail-profile.php" class="text-xs text-yellow-600 ml-auto hover:underline"><i class="fas fa-exclamation-triangle mr-1"></i>Set work email</a>
                <?php endif; ?>
            </div>

            <!-- To: internal mailbox -->
            <div id="row-to-internal" class="px-4 py-2.5 border-b border-gray-100 flex items-center gap-3">
                <span class="text-xs text-gray-400 w-14">Mailbox</span>
                <select name="to_mailbox" id="compose-to" class="flex-1 text-sm border-none outline-none bg-transparent text-gray-800" data-testid="select-compose-to">
                    <?php foreach ($MAILBOXES as $slug=>$mb): ?>
                    <option value="<?= $slug ?>" <?= ($view_mailbox===$slug||$view_mailbox==='personal'&&$slug==='staff')?'selected':'' ?>><?= htmlspecialchars($mb['label']) ?> Inbox — <?= htmlspecialchars($mb['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- To: external email (hidden by default) -->
            <div id="row-to-external" class="px-4 py-2.5 border-b border-gray-100 flex items-center gap-3 hidden">
                <span class="text-xs text-gray-400 w-14">To Email</span>
                <input type="email" name="external_to" id="compose-external-to" placeholder="recipient@example.com"
                    class="flex-1 text-sm outline-none bg-transparent text-gray-800"
                    data-testid="input-compose-external-to">
            </div>

            <!-- CC mailbox (when external) -->
            <div id="row-cc-mailbox" class="px-4 py-2 border-b border-gray-100 flex items-center gap-3 hidden">
                <span class="text-xs text-gray-400 w-14">CC to</span>
                <select name="to_mailbox" id="compose-cc-mailbox" class="flex-1 text-sm border-none outline-none bg-transparent text-gray-800">
                    <option value="">(no group copy)</option>
                    <?php foreach ($MAILBOXES as $slug=>$mb): ?>
                    <option value="<?= $slug ?>"><?= htmlspecialchars($mb['label']) ?> group inbox</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subject -->
            <div class="px-4 py-2.5 border-b border-gray-100 flex items-center gap-3">
                <span class="text-xs text-gray-400 w-14">Subject</span>
                <input type="text" name="subject" id="compose-subject" placeholder="Message subject"
                    class="flex-1 text-sm outline-none bg-transparent text-gray-800"
                    data-testid="input-compose-subject" required>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-wrap gap-0.5 px-3 py-1.5 border-b border-gray-100 bg-gray-50">
                <button type="button" onclick="fmtCompose('bold')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 font-bold text-gray-700 text-sm"><b>B</b></button>
                <button type="button" onclick="fmtCompose('italic')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 italic text-gray-700 text-sm"><i>I</i></button>
                <button type="button" onclick="fmtCompose('underline')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 underline text-gray-700 text-sm">U</button>
                <span class="w-px bg-gray-200 mx-0.5 self-stretch"></span>
                <button type="button" onclick="fmtCompose('justifyLeft')"   class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700"><i class="fas fa-align-left text-xs"></i></button>
                <button type="button" onclick="fmtCompose('justifyCenter')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700"><i class="fas fa-align-center text-xs"></i></button>
                <span class="w-px bg-gray-200 mx-0.5 self-stretch"></span>
                <button type="button" onclick="fmtCompose('insertOrderedList')"   class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700"><i class="fas fa-list-ol text-xs"></i></button>
                <button type="button" onclick="fmtCompose('insertUnorderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700"><i class="fas fa-list-ul text-xs"></i></button>
                <span class="w-px bg-gray-200 mx-0.5 self-stretch"></span>
                <select onchange="composeFontSize(this.value)" class="h-7 text-xs border border-gray-200 rounded px-1 bg-white text-gray-700">
                    <option value="">Size</option>
                    <option value="12px">12</option>
                    <option value="14px" selected>14</option>
                    <option value="16px">16</option>
                    <option value="18px">18</option>
                    <option value="24px">24</option>
                </select>
                <div>
                    <button type="button" onclick="document.getElementById('compose-color').click()" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 font-bold text-gray-700">A</button>
                    <input type="color" id="compose-color" class="sr-only" oninput="fmtCompose('foreColor',this.value)" value="#000000">
                </div>
                <button type="button" onclick="composeInsertImg()" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Insert image"><i class="fas fa-image text-xs"></i></button>
                <button type="button" onclick="composeInsertLink()" class="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 text-gray-700" title="Link"><i class="fas fa-link text-xs"></i></button>
            </div>

            <!-- Body editor -->
            <div id="compose-editor" contenteditable="true"
                class="flex-1 overflow-y-auto px-4 py-3 text-sm text-gray-800 outline-none"
                style="min-height:200px;line-height:1.75;"
                placeholder="Write your message…"
                data-testid="compose-editor"></div>

            <!-- Footer -->
            <div class="px-4 py-3 border-t border-gray-100 flex items-center gap-3 flex-shrink-0">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition" data-testid="btn-send-compose">
                    <i class="fas fa-paper-plane mr-1.5"></i>Send
                </button>
                <button type="button" onclick="closeCompose()" class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition">
                    Discard
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Compose modal
function openCompose(mailbox) {
    document.getElementById('compose-modal').classList.add('open');
    if (mailbox && document.getElementById('compose-to'))
        document.getElementById('compose-to').value = mailbox;
    setTimeout(() => document.getElementById('compose-subject')?.focus(), 100);
}
function closeCompose() {
    document.getElementById('compose-modal').classList.remove('open');
    document.getElementById('compose-editor').innerHTML = '';
    document.getElementById('compose-subject').value = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeCompose(); });

// ── Send mode toggle (internal ↔ external SMTP)
function setSendMode(mode) {
    document.getElementById('compose-send-mode').value = mode;
    const isExt = mode === 'external';
    document.getElementById('row-to-internal').classList.toggle('hidden', isExt);
    document.getElementById('row-to-external').classList.toggle('hidden', !isExt);
    document.getElementById('row-cc-mailbox').classList.toggle('hidden', !isExt);
    document.getElementById('mode-internal-btn').className = 'px-3 py-1.5 font-medium ' + (isExt ? 'bg-white text-gray-600 hover:bg-gray-50' : 'bg-blue-600 text-white');
    document.getElementById('mode-external-btn').className = 'px-3 py-1.5 font-medium ' + (isExt ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50');
    if (isExt) {
        // Disable mailbox select so it doesn't interfere
        document.getElementById('compose-to').removeAttribute('name');
        // Enable CC mailbox select
        document.getElementById('compose-cc-mailbox').setAttribute('name', 'to_mailbox');
        setTimeout(() => document.getElementById('compose-external-to')?.focus(), 50);
    } else {
        document.getElementById('compose-to').setAttribute('name', 'to_mailbox');
        document.getElementById('compose-cc-mailbox').removeAttribute('name');
    }
}

// ── Format helpers
function fmtCompose(cmd, val) { document.getElementById('compose-editor').focus(); document.execCommand(cmd, false, val||null); }
function fmtReply(cmd, val)   { document.getElementById('reply-editor').focus();   document.execCommand(cmd, false, val||null); }
function fmtReplyColor(color) {
    document.getElementById('reply-editor').focus();
    document.execCommand('foreColor', false, color);
    document.getElementById('reply-editor').focus();
}
function composeFontSize(size) {
    if (!size) return;
    const ed = document.getElementById('compose-editor');
    ed.focus();
    document.execCommand('fontSize', false, '7');
    ed.querySelectorAll('font[size="7"]').forEach(el => {
        const sp = document.createElement('span');
        sp.style.fontSize = size; sp.innerHTML = el.innerHTML;
        el.parentNode.replaceChild(sp, el);
    });
}
function insertReplyImg() {
    const url = prompt('Image URL:'); if (!url) return;
    document.getElementById('reply-editor').focus();
    document.execCommand('insertHTML', false, `<img src="${url}" style="max-width:100%;height:auto;" />`);
}
function insertReplyLink() {
    const url = prompt('Link URL:'); if (!url) return;
    const txt = prompt('Link text:', url) || url;
    document.getElementById('reply-editor').focus();
    document.execCommand('insertHTML', false, `<a href="${url}" style="color:#2563eb;">${txt}</a>`);
}
function composeInsertImg() {
    const url = prompt('Image URL:'); if (!url) return;
    document.getElementById('compose-editor').focus();
    document.execCommand('insertHTML', false, `<img src="${url}" style="max-width:100%;height:auto;" />`);
}
function composeInsertLink() {
    const url = prompt('Link URL:'); if (!url) return;
    const txt = prompt('Link text:', url) || url;
    document.getElementById('compose-editor').focus();
    document.execCommand('insertHTML', false, `<a href="${url}" style="color:#2563eb;">${txt}</a>`);
}

// ── Sync contenteditable to hidden input on submit
function syncComposeBody(form) {
    document.getElementById('compose-html-input').value = document.getElementById('compose-editor').innerHTML;
}
function syncReplyBody(form) {
    document.getElementById('reply-html-input').value = document.getElementById('reply-editor').innerHTML;
}

// ── Star toggle (AJAX)
function toggleStar(id, btn) {
    const isStarred = btn.classList.contains('starred');
    const action = isStarred ? 'unstar' : 'star';
    btn.classList.toggle('starred', !isStarred);
    btn.querySelector('i').className = (!isStarred ? 'fas' : 'far') + ' fa-star text-sm';

    const csrf = document.querySelector('meta[name=csrf-token]')?.content
              || document.querySelector('input[name=_csrf]')?.value
              || '<?= csrf_token() ?>';
    fetch('admin-mail.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=${action}&msg_id=${id}&ajax=1&_csrf=${encodeURIComponent(csrf)}`
    }).catch(()=>{});
}

// ── Auto-mark as read when row is visible
document.querySelectorAll('.msg-row.unread').forEach(row => {
    row.addEventListener('click', () => {
        row.classList.remove('unread');
        const dot = row.querySelector('.rounded-full:last-child');
        if (dot && !dot.closest('button')) dot.remove();
    });
});
</script>

</body>
</html>
