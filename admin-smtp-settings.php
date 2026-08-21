<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$success_msg = ''; $error_msg = '';

// Load current config
$smtp = leads_smtp_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_smtp') {
            $fields = ['host','port','username','password','encryption','from_name','from_email'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                if ($f === 'password' && $val === '••••••••') continue; // don't overwrite with placeholder
                $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('smtp',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")->execute([$f,$val]);
                $smtp[$f] = $val;
            }
            $success_msg = 'SMTP settings saved successfully.';
        }

        if ($action === 'test_smtp') {
            $host = $smtp['host'];
            $port = (int)($smtp['port'] ?: 587);
            if (!$host) {
                $error_msg = 'No SMTP host configured. Save settings first.';
            } else {
                $conn = @fsockopen($host, $port, $errno, $errstr, 5);
                if ($conn) {
                    fclose($conn);
                    $success_msg = "SMTP connection to {$host}:{$port} is reachable!";
                } else {
                    $error_msg = "Cannot connect to {$host}:{$port} — {$errstr} ({$errno})";
                }
            }
        }

        if ($action === 'send_test_email') {
            $to   = trim($_POST['test_email'] ?? '');
            $host = $smtp['host'];
            $port = (int)($smtp['port'] ?: 587);
            $user = $smtp['username'];
            $pass = $smtp['password'];
            $from = $smtp['from_email'];
            $fname= $smtp['from_name'];
            $enc  = $smtp['encryption'] ?? 'tls';
            if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $error_msg = 'Enter a valid test email address.';
            } elseif (!$host || !$user || !$pass) {
                $error_msg = 'Save complete SMTP credentials before sending a test email.';
            } else {
                // Helper: read all lines of a multi-line SMTP response (e.g. EHLO returns many 250- lines)
                $readSmtpResponse = function($conn) {
                    $buf = '';
                    while ($line = fgets($conn, 512)) {
                        $buf .= $line;
                        // Final line has a space after the 3-digit code: "250 OK\r\n"
                        if (strlen($line) >= 4 && $line[3] === ' ') break;
                    }
                    return $buf;
                };

                try {
                    $ctx = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
                    if ($enc === 'ssl' || $port === 465) {
                        $conn = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
                    } else {
                        $conn = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
                    }
                    if (!$conn) throw new Exception("Cannot connect: $errstr ($errno)");
                    stream_set_timeout($conn, 10);

                    $recv = $readSmtpResponse($conn);
                    if (substr(trim($recv), 0, 3) !== '220') throw new Exception("Unexpected greeting: " . trim($recv));

                    fputs($conn, "EHLO " . gethostname() . "\r\n");
                    $ehlo = $readSmtpResponse($conn);

                    // STARTTLS on port 587 (regardless of encryption setting)
                    if ($port === 587 || $enc === 'tls') {
                        if (stripos($ehlo, 'STARTTLS') === false) throw new Exception("Server does not offer STARTTLS");
                        fputs($conn, "STARTTLS\r\n");
                        $tlsResp = $readSmtpResponse($conn);
                        if (substr(trim($tlsResp), 0, 3) !== '220') throw new Exception("STARTTLS rejected: " . trim($tlsResp));
                        $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                        if (!$ok) throw new Exception("TLS handshake failed");
                        fputs($conn, "EHLO " . gethostname() . "\r\n");
                        $readSmtpResponse($conn); // consume post-TLS EHLO
                    }

                    fputs($conn, "AUTH LOGIN\r\n");
                    $a1 = $readSmtpResponse($conn);
                    if (substr(trim($a1), 0, 3) !== '334') throw new Exception("AUTH LOGIN not accepted: " . trim($a1));

                    fputs($conn, base64_encode($user) . "\r\n");
                    $a2 = $readSmtpResponse($conn);
                    if (substr(trim($a2), 0, 3) !== '334') throw new Exception("Username rejected: " . trim($a2));

                    fputs($conn, base64_encode($pass) . "\r\n");
                    $a3 = $readSmtpResponse($conn);
                    if (substr(trim($a3), 0, 3) !== '235') throw new Exception("Authentication failed — check your username/password. Server said: " . trim($a3));

                    fputs($conn, "MAIL FROM:<{$from}>\r\n");
                    $mf = $readSmtpResponse($conn);
                    if (substr(trim($mf), 0, 3) !== '250') throw new Exception("MAIL FROM rejected: " . trim($mf));

                    fputs($conn, "RCPT TO:<{$to}>\r\n");
                    $rt = $readSmtpResponse($conn);
                    if (substr(trim($rt), 0, 3) !== '250') throw new Exception("Recipient rejected: " . trim($rt));

                    fputs($conn, "DATA\r\n");
                    $dr = $readSmtpResponse($conn);
                    if (substr(trim($dr), 0, 3) !== '354') throw new Exception("DATA rejected: " . trim($dr));

                    $subject = "Blue Mogul Portal - SMTP Test";
                    $body    = "This is a test email confirming your SMTP configuration is working.\r\n\r\nSent: " . date('Y-m-d H:i:s T');
                    fputs($conn, "Date: " . date('r') . "\r\nFrom: {$fname} <{$from}>\r\nTo: {$to}\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n.\r\n");
                    $sr = $readSmtpResponse($conn);
                    fputs($conn, "QUIT\r\n");
                    fclose($conn);

                    if (substr(trim($sr), 0, 3) === '250') {
                        $success_msg = "✓ Test email sent to {$to} successfully!";
                    } else {
                        $error_msg = "Send failed: " . trim($sr);
                    }
                } catch (Exception $ex) {
                    $error_msg = "SMTP error: " . $ex->getMessage();
                }
            }
        }
    }
}

$smtp_configured = !empty($smtp['host']) && !empty($smtp['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email SMTP Settings — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-4">
        <div>
            <nav class="text-xs text-gray-400 mb-0.5">
                <a href="admin-leads-dashboard.php" class="hover:text-blue-600">Leads</a> / Communications /
            </nav>
            <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center"><i class="fas fa-envelope-open-text text-white text-sm"></i></span>
                Email SMTP Setup
            </h1>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($smtp_configured): ?>
            <span class="flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                <i class="fas fa-circle text-[8px]"></i>SMTP Configured
            </span>
            <?php else: ?>
            <span class="flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium">
                <i class="fas fa-circle text-[8px]"></i>SMTP Not Configured
            </span>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="p-6 space-y-6 max-w-4xl">

<?php if ($success_msg): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center gap-3" data-testid="alert-success">
    <i class="fas fa-check-circle"></i><?= htmlspecialchars($success_msg) ?>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center gap-3" data-testid="alert-error">
    <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error_msg) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- SMTP Form -->
<div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
    <h3 class="text-base font-semibold text-gray-900 mb-1">
        <i class="fas fa-server text-blue-500 mr-2"></i>SMTP Server Configuration
    </h3>
    <p class="text-sm text-gray-500 mb-5">Configure your outgoing mail server to enable email communications from the Leads section. Credentials are stored securely in the database.</p>

    <form method="POST" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_smtp">

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host *</label>
                <input type="text" name="host" value="<?= htmlspecialchars($smtp['host']) ?>"
                    placeholder="smtp.gmail.com"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-smtp-host">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Port *</label>
                <input type="number" name="port" value="<?= htmlspecialchars($smtp['port']?:'587') ?>"
                    placeholder="587"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-smtp-port">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
            <div class="flex gap-4">
                <?php foreach (['none'=>'None','tls'=>'TLS / STARTTLS','ssl'=>'SSL'] as $k=>$v): ?>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="radio" name="encryption" value="<?= $k ?>" <?= ($smtp['encryption']??'tls')===$k?'checked':'' ?> class="text-blue-600">
                    <?= $v ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username / Email *</label>
                <input type="text" name="username" value="<?= htmlspecialchars($smtp['username']) ?>"
                    placeholder="your@email.com"
                    autocomplete="off"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-smtp-username">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                <input type="password" name="password" value="<?= $smtp['password'] ? '••••••••' : '' ?>"
                    placeholder="App password or SMTP password"
                    autocomplete="new-password"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-smtp-password">
            </div>
        </div>

        <div class="pt-2 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-500 uppercase mb-3">From Address (shown to recipients)</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                    <input type="text" name="from_name" value="<?= htmlspecialchars($smtp['from_name']) ?>"
                        placeholder="Blue Mogul Support"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        data-testid="input-smtp-from-name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
                    <input type="email" name="from_email" value="<?= htmlspecialchars($smtp['from_email']) ?>"
                        placeholder="support@bluemogul.biz"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        data-testid="input-smtp-from-email">
                </div>
            </div>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-smtp">
                <i class="fas fa-save mr-2"></i>Save Settings
            </button>
            <?php if ($smtp_configured): ?>
            <button type="submit" form="test-form" class="px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-test-smtp">
                <i class="fas fa-plug mr-2"></i>Test Connection
            </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Right panel: info + test email -->
<div class="space-y-5">

    <!-- Test connection -->
    <form method="POST" id="test-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_smtp">
        <?php if (!$smtp_configured): ?>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-500 mb-2">Configure and save your SMTP settings first, then test the connection.</p>
        </div>
        <?php endif; ?>
    </form>

    <!-- Send test email -->
    <?php if ($smtp_configured): ?>
    <div class="bg-white rounded-lg border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-1"><i class="fas fa-paper-plane text-green-500 mr-2"></i>Send Test Email</h3>
        <p class="text-xs text-gray-500 mb-3">Verify your configuration by sending a test email to any address.</p>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_test_email">
            <input type="email" name="test_email" placeholder="recipient@example.com" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                data-testid="input-test-email">
            <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-send-test">
                <i class="fas fa-paper-plane mr-2"></i>Send Test Email
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Provider guides -->
    <div class="bg-white rounded-lg border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-book text-blue-500 mr-2"></i>Common SMTP Settings</h3>
        <div class="space-y-3 text-xs text-gray-600">
            <?php foreach ([
                ['Gmail',   'smtp.gmail.com',     '587','TLS','Use an App Password (2FA required)'],
                ['Outlook', 'smtp.office365.com', '587','TLS','Use your Microsoft 365 email & password'],
                ['Yahoo',   'smtp.mail.yahoo.com','465','SSL','Requires App Password in account settings'],
                ['SendGrid','smtp.sendgrid.net',  '587','TLS','Username: apikey | Password: your API key'],
                ['Mailgun', 'smtp.mailgun.org',   '587','TLS','SMTP credentials from Mailgun dashboard'],
            ] as [$n,$h,$p,$e,$note]): ?>
            <div class="border border-gray-100 rounded-lg p-2.5">
                <p class="font-semibold text-gray-700 mb-1"><?= $n ?></p>
                <p class="font-mono text-gray-500"><?= $h ?>:<?= $p ?> (<?= $e ?>)</p>
                <p class="text-gray-400 mt-0.5"><?= $note ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
</div>

<!-- Status indicators -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Current Configuration Status</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ([
            ['SMTP Host',       $smtp['host']        ?: 'Not set', !empty($smtp['host'])],
            ['Port',            $smtp['port']        ?: 'Not set', !empty($smtp['port'])],
            ['Authentication',  $smtp['username']    ? '✓ Set'     : 'Not set', !empty($smtp['username'])],
            ['Encryption',      $smtp['encryption']  ?: 'Not set', !empty($smtp['encryption'])],
            ['From Name',       $smtp['from_name']   ?: 'Not set', !empty($smtp['from_name'])],
            ['From Email',      $smtp['from_email']  ?: 'Not set', !empty($smtp['from_email'])],
        ] as [$lbl,$val,$ok]): ?>
        <div class="p-3 rounded-lg <?= $ok?'bg-green-50 border border-green-200':'bg-gray-50 border border-gray-200' ?>">
            <p class="text-xs font-medium <?= $ok?'text-green-600':'text-gray-400' ?> mb-1"><?= $lbl ?></p>
            <p class="text-sm font-semibold <?= $ok?'text-green-800':'text-gray-600' ?> truncate"><?= htmlspecialchars($val) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /max-w-4xl -->
</div>
</div>
</body>
</html>
