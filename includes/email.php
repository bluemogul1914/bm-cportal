<?php

// ─── Per-user SMTP credential helpers ────────────────────────────────────────

function mail_encrypt_password(string $plain): string {
    if (empty($plain)) return '';
    $encrypted = openssl_encrypt($plain, 'aes-256-cbc', MAIL_CRYPT_KEY, 0, MAIL_CRYPT_IV);
    return $encrypted ?: '';
}

function mail_decrypt_password(string $encrypted): string {
    if (empty($encrypted)) return '';
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', MAIL_CRYPT_KEY, 0, MAIL_CRYPT_IV);
    return $decrypted ?: '';
}

/**
 * Split an HTML body into lines of at most $maxLen columns BEFORE embedding it
 * in the raw SMTP payload. SMTP transports (Google et al.) reject messages
 * whose lines exceed 2048 bytes ("lines too long for transport"). We split on
 * whitespace boundaries and NEVER inside a quoted attribute value, so tags and
 * attributes are never corrupted. Because we break at whitespace, consecutive
 * runs of whitespace collapse to a newline — which is harmless in HTML.
 */
function wrap_html_for_smtp(string $html, int $maxLen = 76): string {
    $lines = [];
    $current = '';
    $i = 0;
    $len = strlen($html);
    $inTag = false;
    $inQuote = null; // null | "'" | '"'
    $lineLen = 0;
    $lastSafeBreak = -1; // index into $current where a space was seen (a break point)

    while ($i < $len) {
        $ch = $html[$i];

        // Track tag / quote state so we never break inside a quoted attribute.
        if ($ch === '<') { $inTag = true; }
        elseif ($ch === '>' && $inTag) { $inTag = false; }
        elseif ($inTag && ($ch === '"' || $ch === "'")) {
            $inQuote = ($inQuote === null) ? $ch : ($inQuote === $ch ? null : $inQuote);
        }

        $current .= $ch;
        $lineLen++;
        $i++;

        // Record a safe break point when we just appended a space OUTSIDE tags/quotes.
        if (!$inTag && $inQuote === null && $ch === ' ') {
            $lastSafeBreak = strlen($current) - 1;
        }

        // If we've hit the hard ceiling, cut at the last safe break if one exists,
        // else hard-break now (never inside a tag/quote).
        if ($lineLen >= $maxLen) {
            if ($lastSafeBreak > 0) {
                $cut = $lastSafeBreak;
                $lines[] = substr($current, 0, $cut) . "\r";
                $current = ltrim(substr($current, $cut)); // drop the space we broke on
            } else {
                // No safe break: hard cut. Only cut between chars; if we're inside a
                // tag/quote we fall through to the next char until we exit (roadsafe).
                if (!$inTag && $inQuote === null) {
                    $lines[] = $current . "\r";
                    $current = '';
                }
            }
            $lineLen = strlen($current);
            $lastSafeBreak = -1;
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }
    return implode("\n", $lines);
}

/**
 * Get per-user SMTP settings. Returns null if not configured,
 * falls back to company SMTP shape if only work email is set.
 */
function getUserSmtpSettings(int $user_id): ?array {
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT work_email, display_name, smtp_user, smtp_password, smtp_host, smtp_port FROM user_email_settings WHERE user_id=?");
        $st->execute([$user_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['work_email'])) return null;

        $has_creds = !empty($row['smtp_user']) && !empty($row['smtp_password']);
        return [
            'work_email'   => $row['work_email'],
            'display_name' => $row['display_name'] ?: $row['work_email'],
            'has_own_creds'=> $has_creds,
            'smtp_user'    => $row['smtp_user'] ?? '',
            'smtp_pass'    => $has_creds ? mail_decrypt_password($row['smtp_password']) : '',
            'smtp_host'    => $row['smtp_host'] ?: 'mail.bluemogul.biz',
            'smtp_port'    => intval($row['smtp_port'] ?: 587),
        ];
    } catch (Exception $e) {
        error_log("getUserSmtpSettings error: ".$e->getMessage());
        return null;
    }
}

function getSmtpSettings() {
    try {
        $pdo = getDB();

        // Primary: provider_settings table (saved by the SMTP Setup page in Leads)
        try {
            $st = $pdo->prepare("SELECT key_name,key_value FROM provider_settings WHERE provider='smtp'");
            $st->execute();
            $ps = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($ps['host']) && !empty($ps['username'])) {
                return [
                    'host'       => $ps['host'],
                    'port'       => intval($ps['port'] ?? 587),
                    'user'       => $ps['username'],
                    'pass'       => $ps['password'] ?? '',
                    'from_email' => $ps['from_email'] ?? 'noreply@bluemogul.biz',
                    'from_name'  => $ps['from_name']  ?? 'Blue Mogul',
                    'encryption' => $ps['encryption'] ?? 'tls',
                ];
            }
        } catch (\Exception $e) {}

        // Fallback: system_settings table (saved by the old Admin Settings page)
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','from_email','from_name','company_name')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'host'       => $settings['smtp_host'] ?? '',
            'port'       => intval($settings['smtp_port'] ?? 587),
            'user'       => $settings['smtp_user'] ?? '',
            'pass'       => $settings['smtp_pass'] ?? '',
            'from_email' => $settings['from_email'] ?? 'noreply@bluemogul.biz',
            'from_name'  => $settings['from_name'] ?? ($settings['company_name'] ?? 'Blue Mogul'),
            'encryption' => 'tls',
        ];
    } catch (\Exception $e) {
        return null;
    }
}

function isSmtpConfigured() {
    $s = getSmtpSettings();
    return $s && !empty($s['host']) && !empty($s['user']) && !empty($s['pass']);
}

function sanitize_smtp_value($val) {
    return str_replace(["\r", "\n", "\0"], '', $val);
}

function send_email($to, $subject, $html_body, $plain_body = '') {
    $smtp = getSmtpSettings();
    if (!$smtp || empty($smtp['host']) || empty($smtp['user'])) {
        error_log("SMTP not configured — cannot send email to $to");
        return ['success' => false, 'error' => 'SMTP not configured'];
    }

    $subject = sanitize_smtp_value($subject);
    $smtp['from_name'] = sanitize_smtp_value($smtp['from_name']);
    $smtp['from_email'] = sanitize_smtp_value($smtp['from_email']);
    if (is_array($to)) {
        $to = array_map('sanitize_smtp_value', $to);
    } else {
        $to = sanitize_smtp_value($to);
    }

    if (empty($plain_body)) {
        $plain_body = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html_body));
    }

    // Some HTML templates are stored/served as one long minified line. SMTP
    // transports (e.g. Google) reject messages with lines > 2048 bytes with
    // "lines too long for transport". Wrap long lines safely so email never
    // bounces on line length. We split only between tags/attributes on
    // whitespace boundaries, never inside a quoted attribute value.
    $html_body = wrap_html_for_smtp($html_body);

    $boundary = md5(time() . rand());
    $headers = [];
    $headers[] = 'From: ' . $smtp['from_name'] . ' <' . $smtp['from_email'] . '>';
    $headers[] = 'Reply-To: ' . $smtp['from_email'];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: BlueMogul-Portal/1.0';

    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plain_body . "\r\n\r\n";
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $html_body . "\r\n\r\n";
    $message .= "--$boundary--";

    $errno = 0;
    $errstr = '';

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);

    if ($smtp['port'] === 465) {
        $socket = @stream_socket_client('ssl://' . $smtp['host'] . ':' . $smtp['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    } else {
        $socket = @stream_socket_client('tcp://' . $smtp['host'] . ':' . $smtp['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    }

    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return ['success' => false, 'error' => "Connection failed: $errstr"];
    }

    stream_set_timeout($socket, 15);

    $greeting = '';
    while ($line = fgets($socket, 512)) {
        $greeting .= $line;
        if (substr($line, 3, 1) === ' ' || substr($line, 3, 1) === "\r" || strlen(trim($line)) === 3) break;
    }
    if (substr(trim($greeting), 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'error' => "Server greeting failed: $greeting"];
    }

    fwrite($socket, "EHLO " . gethostname() . "\r\n");
    $ehlo_response = '';
    while ($line = fgets($socket, 512)) {
        $ehlo_response .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }

    if ($smtp['port'] === 587) {
        if (strpos($ehlo_response, 'STARTTLS') === false) {
            fclose($socket);
            return ['success' => false, 'error' => 'Server does not support STARTTLS on port 587 — refusing plaintext auth'];
        }

        fwrite($socket, "STARTTLS\r\n");
        $tls_response = fgets($socket, 512);
        if (substr($tls_response, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: $tls_response"];
        }

        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$crypto) {
            fclose($socket);
            return ['success' => false, 'error' => 'TLS negotiation failed'];
        }

        fwrite($socket, "EHLO " . gethostname() . "\r\n");
        while ($line = fgets($socket, 512)) {
            if (substr($line, 3, 1) === ' ') break;
        }
    }

    fwrite($socket, "AUTH LOGIN\r\n");
    $auth_response = fgets($socket, 512);
    if (substr($auth_response, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => "AUTH failed: $auth_response"];
    }

    fwrite($socket, base64_encode($smtp['user']) . "\r\n");
    $user_response = fgets($socket, 512);
    if (substr($user_response, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => "Username rejected: $user_response"];
    }

    fwrite($socket, base64_encode($smtp['pass']) . "\r\n");
    $pass_response = fgets($socket, 512);
    if (substr($pass_response, 0, 3) !== '235') {
        fclose($socket);
        return ['success' => false, 'error' => "Authentication failed: $pass_response"];
    }

    fwrite($socket, "MAIL FROM:<" . $smtp['from_email'] . ">\r\n");
    $from_resp = fgets($socket, 512);
    if (substr($from_resp, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM rejected: $from_resp"];
    }

    $recipients = is_array($to) ? $to : [$to];
    foreach ($recipients as $rcpt) {
        fwrite($socket, "RCPT TO:<" . trim($rcpt) . ">\r\n");
        $rcpt_resp = fgets($socket, 512);
        if (substr($rcpt_resp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "RCPT TO rejected for $rcpt: $rcpt_resp"];
        }
    }

    fwrite($socket, "DATA\r\n");
    $data_resp = fgets($socket, 512);
    if (substr($data_resp, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'error' => "DATA rejected: $data_resp"];
    }

    $full_message = "Date: " . date('r') . "\r\n";
    $full_message .= "To: " . (is_array($to) ? implode(', ', $to) : $to) . "\r\n";
    $full_message .= "Subject: " . $subject . "\r\n";
    $full_message .= implode("\r\n", $headers) . "\r\n";
    $full_message .= "\r\n";
    $full_message .= $message;

    fwrite($socket, $full_message . "\r\n.\r\n");
    $send_resp = fgets($socket, 512);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    $to_str = is_array($to) ? implode(', ', $to) : $to;
    if (substr($send_resp, 0, 3) === '250') {
        error_log("Email sent successfully to $to_str");
        log_email_sent($to_str, $subject, true, null);
        return ['success' => true];
    } else {
        error_log("Email send failed: $send_resp");
        log_email_sent($to_str, $subject, false, "Send failed: $send_resp");
        return ['success' => false, 'error' => "Send failed: $send_resp"];
    }
}

/**
 * Send an email with a custom From address (staff member's work email).
 * Uses per-user SMTP credentials if configured; falls back to company SMTP.
 * Pass $user_id to automatically resolve credentials from user profile.
 */
function send_email_as($to, $subject, $html_body, $from_email, $from_name, $plain_body = '', $user_id = null) {
    // Try per-user credentials first
    $user_creds = null;
    if ($user_id) {
        $user_creds = getUserSmtpSettings((int)$user_id);
    }

    if ($user_creds && $user_creds['has_own_creds']) {
        // Use individual credentials
        $smtp = [
            'host' => $user_creds['smtp_host'],
            'port' => $user_creds['smtp_port'],
            'user' => $user_creds['smtp_user'],
            'pass' => $user_creds['smtp_pass'],
        ];
    } else {
        // Fall back to company SMTP
        $smtp = getSmtpSettings();
    }

    if (!$smtp || empty($smtp['host']) || empty($smtp['user'])) {
        return ['success' => false, 'error' => 'SMTP not configured — set up credentials in Email Profile'];
    }

    $subject    = sanitize_smtp_value($subject);
    $from_email = sanitize_smtp_value($from_email);
    $from_name  = sanitize_smtp_value($from_name);
    if (is_array($to)) $to = array_map('sanitize_smtp_value', $to);
    else $to = sanitize_smtp_value($to);

    if (empty($plain_body)) {
        $plain_body = strip_tags(str_replace(['<br>','<br/>','<br />','</p>','</div>','</li>'], "\n", $html_body));
    }

    $boundary = md5(time().rand());
    $headers  = [
        "From: $from_name <$from_email>",
        "Reply-To: $from_email",
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        'X-Mailer: BlueMogul-Portal/1.0',
    ];

    $message  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$plain_body\r\n\r\n";
    $message .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html_body\r\n\r\n";
    $message .= "--$boundary--";

    $ctx = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);

    if ($smtp['port'] === 465) {
        $socket = @stream_socket_client('ssl://'.$smtp['host'].':'.$smtp['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    } else {
        $socket = @stream_socket_client('tcp://'.$smtp['host'].':'.$smtp['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    }
    if (!$socket) return ['success'=>false,'error'=>"Connection failed: $errstr"];

    stream_set_timeout($socket, 15);
    $greeting = '';
    while ($line = fgets($socket, 512)) {
        $greeting .= $line;
        if (substr($line,3,1)===' '||substr($line,3,1)==="\r"||strlen(trim($line))===3) break;
    }
    if (substr(trim($greeting),0,3)!=='220') { fclose($socket); return ['success'=>false,'error'=>"Greeting: $greeting"]; }

    fwrite($socket, "EHLO ".gethostname()."\r\n");
    $ehlo = '';
    while ($line = fgets($socket,512)) { $ehlo .= $line; if (substr($line,3,1)===' ') break; }

    if ($smtp['port']===587) {
        if (strpos($ehlo,'STARTTLS')===false) { fclose($socket); return ['success'=>false,'error'=>'No STARTTLS']; }
        fwrite($socket,"STARTTLS\r\n"); $tls=fgets($socket,512);
        if (substr($tls,0,3)!=='220') { fclose($socket); return ['success'=>false,'error'=>"STARTTLS: $tls"]; }
        @stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        fwrite($socket,"EHLO ".gethostname()."\r\n");
        while ($line=fgets($socket,512)) { if (substr($line,3,1)===' ') break; }
    }

    fwrite($socket,"AUTH LOGIN\r\n"); $a1=fgets($socket,512);
    if (substr($a1,0,3)!=='334') { fclose($socket); return ['success'=>false,'error'=>"AUTH: $a1"]; }
    fwrite($socket,base64_encode($smtp['user'])."\r\n"); $a2=fgets($socket,512);
    if (substr($a2,0,3)!=='334') { fclose($socket); return ['success'=>false,'error'=>"User: $a2"]; }
    fwrite($socket,base64_encode($smtp['pass'])."\r\n"); $a3=fgets($socket,512);
    if (substr($a3,0,3)!=='235') { fclose($socket); return ['success'=>false,'error'=>"Pass: $a3"]; }

    fwrite($socket,"MAIL FROM:<$from_email>\r\n"); $mf=fgets($socket,512);
    if (substr($mf,0,3)!=='250') { fclose($socket); return ['success'=>false,'error'=>"MAIL FROM: $mf"]; }

    $recipients = is_array($to) ? $to : [$to];
    foreach ($recipients as $rcpt) {
        fwrite($socket,"RCPT TO:<".trim($rcpt).">\r\n"); $rr=fgets($socket,512);
        if (substr($rr,0,3)!=='250') { fclose($socket); return ['success'=>false,'error'=>"RCPT: $rr"]; }
    }

    fwrite($socket,"DATA\r\n"); $dr=fgets($socket,512);
    if (substr($dr,0,3)!=='354') { fclose($socket); return ['success'=>false,'error'=>"DATA: $dr"]; }

    $full  = "Date: ".date('r')."\r\n";
    $full .= "To: ".(is_array($to)?implode(', ',$to):$to)."\r\n";
    $full .= "Subject: $subject\r\n";
    $full .= implode("\r\n",$headers)."\r\n\r\n".$message;
    fwrite($socket,$full."\r\n.\r\n");
    $sr=fgets($socket,512);
    fwrite($socket,"QUIT\r\n"); fclose($socket);

    $to_str = is_array($to)?implode(', ',$to):$to;
    if (substr($sr,0,3)==='250') {
        log_email_sent($to_str, $subject, true, null);
        return ['success'=>true];
    }
    log_email_sent($to_str, $subject, false, "Send failed: $sr");
    return ['success'=>false,'error'=>"Send failed: $sr"];
}

function log_email_sent($to, $subject, $success, $error = null) {
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO email_log (recipient, subject, status, error_message, sent_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$to, $subject, $success ? 'sent' : 'failed', $error]);
    } catch (\Exception $e) {
        error_log("Failed to log email: " . $e->getMessage());
    }
}

function email_template($title, $body_html, $footer_text = '') {
    $company = 'Blue Mogul';
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $company = $row['setting_value'];
    } catch (\Exception $e) {}

    if (empty($footer_text)) {
        $footer_text = "This is an automated message from $company. Please do not reply directly to this email.";
    }

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td style="background-color:#0d1b3e;padding:24px 32px;">
<h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;">' . htmlspecialchars($company) . '</h1>
</td></tr>
<tr><td style="padding:32px;">
<h2 style="margin:0 0 16px;color:#111827;font-size:18px;font-weight:600;">' . $title . '</h2>
' . $body_html . '
</td></tr>
<tr><td style="background-color:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb;">
<p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">' . htmlspecialchars($footer_text) . '</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

function notify_ticket_created($ticket_id, $subject, $client_email, $client_name) {
    if (!isSmtpConfigured()) return;
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($client_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">Your support ticket has been created successfully.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Ticket ID</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">#' . $ticket_id . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Subject</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($subject) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Status</td>
<td style="padding:8px 12px;font-size:14px;color:#1a56db;font-weight:600;">Open</td></tr>
</table>
<p style="color:#374151;font-size:14px;">Our team will review your ticket and respond as soon as possible.</p>';

    $html = email_template('Ticket Created: #' . $ticket_id, $body);
    send_email($client_email, 'Ticket #' . $ticket_id . ' Created: ' . $subject, $html);
}

function notify_ticket_reply($ticket_id, $subject, $client_email, $client_name, $reply_text, $replier_name) {
    if (!isSmtpConfigured()) return;
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($client_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">A new reply has been added to your ticket <strong>#' . $ticket_id . '</strong>.</p>
<div style="background:#f3f4f6;border-left:4px solid #1a56db;padding:12px 16px;margin:16px 0;border-radius:0 4px 4px 0;">
<p style="margin:0 0 4px;font-size:12px;color:#6b7280;font-weight:600;">' . htmlspecialchars($replier_name) . ' replied:</p>
<p style="margin:0;font-size:14px;color:#374151;line-height:1.5;">' . nl2br(htmlspecialchars($reply_text)) . '</p>
</div>
<p style="color:#374151;font-size:14px;">Log in to your portal to view the full conversation and reply.</p>';

    $html = email_template('Reply on Ticket #' . $ticket_id, $body);
    send_email($client_email, 'Re: Ticket #' . $ticket_id . ' - ' . $subject, $html);
}

function notify_invoice_created($invoice_number, $amount, $due_date, $client_email, $client_name) {
    if (!isSmtpConfigured()) return;
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($client_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">A new invoice has been generated for your account.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Invoice #</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($invoice_number) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Amount</td>
<td style="padding:8px 12px;font-size:18px;color:#111827;font-weight:700;">$' . number_format($amount, 2) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Due Date</td>
<td style="padding:8px 12px;font-size:14px;color:#dc2626;font-weight:600;">' . htmlspecialchars($due_date) . '</td></tr>
</table>
<p style="color:#374151;font-size:14px;">Please log in to your portal to view and pay this invoice.</p>';

    $html = email_template('Invoice ' . $invoice_number, $body);
    send_email($client_email, 'Invoice ' . $invoice_number . ' - $' . number_format($amount, 2) . ' Due', $html);
}

function notify_invoice_paid($invoice_number, $amount, $client_email, $client_name) {
    if (!isSmtpConfigured()) return;
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($client_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">Your payment has been received. Thank you!</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Invoice #</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($invoice_number) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Amount Paid</td>
<td style="padding:8px 12px;font-size:18px;color:#059669;font-weight:700;">$' . number_format($amount, 2) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Status</td>
<td style="padding:8px 12px;font-size:14px;color:#059669;font-weight:600;">✓ Paid</td></tr>
</table>';

    $html = email_template('Payment Received', $body);
    send_email($client_email, 'Payment Received - Invoice ' . $invoice_number, $html);
}

function notify_document_uploaded($doc_name, $category, $client_email, $client_name) {
    if (!isSmtpConfigured()) return;
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($client_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">A new document has been added to your account.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Document</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($doc_name) . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Category</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($category) . '</td></tr>
</table>
<p style="color:#374151;font-size:14px;">Log in to your portal to view and download the document.</p>';

    $html = email_template('New Document Available', $body);
    send_email($client_email, 'New Document: ' . $doc_name, $html);
}

function send_test_email($to) {
    $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">This is a test email from your Blue Mogul portal.</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">If you received this message, your SMTP configuration is working correctly.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Server Time</td>
<td style="padding:8px 12px;font-size:14px;color:#111827;">' . date('Y-m-d H:i:s T') . '</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Status</td>
<td style="padding:8px 12px;font-size:14px;color:#059669;font-weight:600;">✓ SMTP Connected</td></tr>
</table>';

    $html = email_template('Test Email', $body);
    return send_email($to, 'Blue Mogul Portal - Test Email', $html);
}
