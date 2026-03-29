<?php
/**
 * Inbound Email Webhook Receiver
 * Supports: Mailgun, SendGrid, Postmark, or any provider that POSTs parsed email fields.
 *
 * Route inbound emails to the right team mailbox based on the "To" address:
 *   staff@bluemogul.biz   → staff mailbox
 *   dealers@bluemogul.biz → dealers mailbox
 *   sales@bluemogul.biz   → sales mailbox
 *
 * Configure your email provider to POST to:
 *   https://yourdomain.com/mail-webhook.php
 */

require_once 'config.php';

// Webhook security: optional secret token check
$WEBHOOK_SECRET = '';
try {
    $pdo = getDB();
    $row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='mail_webhook_secret'")->fetch(PDO::FETCH_ASSOC);
    if ($row) $WEBHOOK_SECRET = $row['setting_value'] ?? '';
} catch(Exception $e) {}

if ($WEBHOOK_SECRET) {
    $provided = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_GET['secret'] ?? '';
    if (!hash_equals($WEBHOOK_SECRET, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// ─── Mailbox routing ──────────────────────────────────────────────────────────
$MAILBOX_MAP = [
    'staff@bluemogul.biz'   => 'staff',
    'dealers@bluemogul.biz' => 'dealers',
    'sales@bluemogul.biz'   => 'sales',
];
$MAILBOX_LABELS = [
    'staff'   => ['label'=>'Staff',   'email'=>'staff@bluemogul.biz'],
    'dealers' => ['label'=>'Dealers', 'email'=>'dealers@bluemogul.biz'],
    'sales'   => ['label'=>'Sales',   'email'=>'sales@bluemogul.biz'],
];

// ─── Parse provider payload ───────────────────────────────────────────────────
$raw = file_get_contents('php://input') ?: '';
$payload = [];

// Try JSON body first (Postmark, some providers)
if ($raw && $raw[0] === '{') {
    $payload = json_decode($raw, true) ?? [];
}

// Fall back to POST fields (Mailgun, SendGrid multipart)
if (empty($payload)) {
    $payload = $_POST;
}

// ── Normalise fields across providers ────────────────────────────────────────
// Mailgun:   From, To, Subject, body-html / body-plain, sender
// SendGrid:  from, to, subject, html, text
// Postmark:  From, To, Subject, HtmlBody, TextBody

function pick($data, $keys, $default='') {
    foreach ($keys as $k) {
        if (!empty($data[$k])) return trim($data[$k]);
    }
    return $default;
}

$from_raw   = pick($payload, ['From','from','sender','Sender']);
$to_raw     = pick($payload, ['To','to','envelope-to','EnvelopeTo']);
$subject    = pick($payload, ['Subject','subject','Subject']) ?: '(no subject)';
$body_html  = pick($payload, ['body-html','html','HtmlBody','body_html']);
$body_text  = pick($payload, ['body-plain','text','TextBody','body_text','stripped-text']);

// Parse "Name <email>" format
function parseAddress($addr) {
    if (preg_match('/^(.+?)\s*<([^>]+)>$/', trim($addr), $m)) {
        return ['name' => trim($m[1],'" '), 'email' => strtolower(trim($m[2]))];
    }
    $addr = strtolower(trim($addr));
    return ['name' => $addr, 'email' => $addr];
}

$from = parseAddress($from_raw);
$to   = parseAddress($to_raw);

// Determine target mailbox from To: address
$mailbox = null;
foreach ($MAILBOX_MAP as $addr => $slug) {
    if (stripos($to['email'], $addr) !== false) {
        $mailbox = $slug;
        break;
    }
}
// Default to staff if no match
if (!$mailbox) $mailbox = 'staff';

$mb_info = $MAILBOX_LABELS[$mailbox];

// Sanitize body
if (!$body_html && $body_text) {
    $body_html = '<p>' . nl2br(htmlspecialchars($body_text)) . '</p>';
}
if (!$body_html) $body_html = '';

// ─── Store in DB ──────────────────────────────────────────────────────────────
try {
    $pdo = getDB();

    // Ensure table exists
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
            source      VARCHAR(50)  DEFAULT 'inbound',
            sent_by     INTEGER,
            attachments JSONB        DEFAULT '[]',
            received_at TIMESTAMP    DEFAULT NOW(),
            created_at  TIMESTAMP    DEFAULT NOW()
        )
    ");

    $ins = $pdo->prepare("
        INSERT INTO mail_messages
            (mailbox, folder, subject, body_html, from_name, from_email, to_name, to_email, source, is_read, received_at)
        VALUES (?,?,?,?,?,?,?,?,?,false,NOW()) RETURNING id
    ");
    $ins->execute([
        $mailbox, 'inbox', $subject, $body_html,
        $from['name'], $from['email'],
        $mb_info['label'], $mb_info['email'],
        'inbound',
    ]);
    $new_id = $ins->fetchColumn();

    // Set thread_id = self
    $pdo->prepare("UPDATE mail_messages SET thread_id=id WHERE id=?")->execute([$new_id]);

    // Log
    error_log("[mail-webhook] Routed email from {$from['email']} to mailbox:{$mailbox} (msg#{$new_id}) Subject: $subject");

    http_response_code(200);
    echo json_encode(['ok' => true, 'mailbox' => $mailbox, 'id' => $new_id]);

} catch(Exception $e) {
    error_log("[mail-webhook] DB error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
