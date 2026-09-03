<?php
/**
 * Blue Mogul Portal - Configuration
 * Optimized for Replit deployment
 * 
 * IMPORTANT: Set these values in Replit Secrets for security
 * Tools → Secrets → Add each variable
 */

// ============================================
// DATABASE CONFIGURATION
// ============================================
// Option 1: Use Replit's built-in PostgreSQL
define('DB_TYPE', 'pgsql'); // or 'mysql'
define('DB_HOST', getenv('REPLIT_DB_URL') ?: 'localhost');
define('DB_NAME', 'bluemogul_portal');
define('DB_USER', getenv('DB_USER') ?: 'replit');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', '5432'); // PostgreSQL default

// Option 2: External MySQL (from Coolify)
// Uncomment these if using external MySQL:
/*
define('DB_TYPE', 'mysql');
define('DB_HOST', 'jckg8skw0ggocckwosogc4wk');
define('DB_NAME', 'bluemogul_portal');
define('DB_USER', 'mysql');
define('DB_PASS', getenv('EXTERNAL_DB_PASS') ?: '');
define('DB_PORT', '3306');
*/

// ============================================
// APPLICATION SETTINGS
// ============================================
define('SITE_NAME', 'Blue Mogul Suite');
define('ADMIN_EMAIL', 'contact@bluemogul.biz');

/**
 * Return the canonical portal base URL (no trailing slash).
 * Priority:
 *   1. APP_URL env var (set in Coolify for production)
 *   2. HTTP_X_FORWARDED_HOST reverse-proxy header
 *   3. HTTP_HOST from the request
 *   4. Hard-coded production fallback
 */
function portal_base_url(): string {
    $appUrl = getenv('APP_URL');
    if ($appUrl) return rtrim($appUrl, '/');
    $proto = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))
        ? 'https' : 'http';
    // Take the first host if comma-separated (some proxies send "host1, host2")
    $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'portal.bluemogul.us')[0]);
    // Never let a Replit dev URL sneak into production emails
    if (strpos($host, 'replit.app') !== false || strpos($host, 'repl.co') !== false) {
        return 'https://portal.bluemogul.us';
    }
    return $proto . '://' . $host;
}

define('SITE_URL', portal_base_url());
define('SUPPORT_PHONE', '346-309-5514');

// Session settings
define('SESSION_LIFETIME', 3600 * 24); // 24 hours
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => SESSION_LIFETIME,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
    ]);
}

// Timezone
date_default_timezone_set('America/Chicago');

// ============================================
// STRIPE CONFIGURATION
// ============================================
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
define('STRIPE_PUBLIC_KEY', getenv('STRIPE_PUBLIC_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');

// ============================================
// VOIP.MS CONFIGURATION
// ============================================
define('VOIP_API_USERNAME', getenv('VOIP_USERNAME') ?: '');
define('VOIP_API_PASSWORD', getenv('VOIP_PASSWORD') ?: '');
define('VOIP_API_TOKEN', getenv('VOIP_TOKEN') ?: '');
define('VOIP_API_URL', 'https://voip.ms/api/v1/rest.php');

// ============================================
// COOLIFY CONFIGURATION
// ============================================
define('COOLIFY_URL', getenv('COOLIFY_URL') ?: 'https://coolify.bluemogul.us');
define('COOLIFY_TOKEN', getenv('COOLIFY_TOKEN') ?: '');

// ============================================
// ITARIAN RMM CONFIGURATION (Legacy)
// ============================================
define('ITARIAN_API_KEY', getenv('ITARIAN_API_KEY') ?: '');
define('ITARIAN_API_URL', getenv('ITARIAN_API_URL') ?: '');

// VARPHONEX CONFIGURATION
// ============================================
define('VARPHONEX_USERNAME', getenv('VARPHONEX_USERNAME') ?: '');
define('VARPHONEX_PASSWORD', getenv('VARPHONEX_PASSWORD') ?: '');
define('VARPHONEX_API_URL',  'http://partners.varphonex.com/services/api.php');

// TRAVELSIM CONFIGURATION
// ============================================
define('TRAVELSIM_UNAME',   getenv('TRAVELSIM_UNAME')   ?: '');
define('TRAVELSIM_UPASS',   getenv('TRAVELSIM_UPASS')   ?: '');
define('TRAVELSIM_API_URL', 'https://xml2.travelsim.com/tsim_xml/service/xmlgate');

// RESELLERCLUB CONFIGURATION
// ============================================
define('RC_AUTH_USERID', getenv('RC_AUTH_USERID') ?: '');
define('RC_API_KEY',     getenv('RC_API_KEY')     ?: '');
define('RC_API_URL',     'https://httpapi.com/api');  // live; use https://test.httpapi.com/api for sandbox

// ENOM DOMAIN RESELLER CONFIGURATION
// ============================================
define('ENOM_UID', getenv('ENOM_UID') ?: '');
define('ENOM_PW',  getenv('ENOM_PW')  ?: '');
define('ENOM_API_URL', 'https://reseller.enom.com/interface.asp');

// HOSTWINDS CLOUD CONFIGURATION
// ============================================
define('HOSTWINDS_API_EMAIL', getenv('HOSTWINDS_API_EMAIL') ?: '');
define('HOSTWINDS_API_KEY',   getenv('HOSTWINDS_API_KEY')   ?: '');
define('HOSTWINDS_API_URL',   'https://clients.hostwinds.com/HostwindsResellerAPI/api.php');

// ACTION1 RMM CONFIGURATION
// ============================================
define('ACTION1_CLIENT_ID', getenv('ACTION1_CLIENT_ID') ?: '');
define('ACTION1_API_KEY', getenv('ACTION1_API_KEY') ?: '');
define('ACTION1_API_URL', getenv('ACTION1_API_URL') ?: 'https://app.action1.com/api/3.0');

// ============================================
// ITARIAN SERVICE DESK CONFIGURATION
// ============================================
define('ITARIAN_SD_API_KEY', getenv('ITARIAN_SD_API_KEY') ?: '');
define('ITARIAN_SD_URL', getenv('ITARIAN_SD_URL') ?: 'https://bluemogultech.ticketing-us.itarian.com');

function itarian_sd_api($serviceName, $body = []) {
    $base = rtrim(ITARIAN_SD_URL, '/');
    $url = $base . '/clientapi/index.php?serviceName=' . urlencode($serviceName);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . ITARIAN_SD_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error) {
        return ['error' => $curl_error, 'http_code' => 0];
    }
    if ($http_code < 200 || $http_code >= 300) {
        $decoded = json_decode($response, true);
        $msg = $decoded['message'] ?? $decoded['error'] ?? "HTTP $http_code";
        return ['error' => $msg, 'http_code' => $http_code];
    }
    $data = json_decode($response, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON: ' . json_last_error_msg(), 'raw' => substr($response, 0, 500)];
    }
    return $data;
}

// ============================================
// HUBSPOT CRM CONFIGURATION
// ============================================
define('HUBSPOT_TOKEN', getenv('HUBSPOT_TOKEN') ?: '');
define('HUBSPOT_API_URL', 'https://api.hubapi.com');

// ============================================
// MATRIX SYNAPSE CONFIGURATION
// ============================================
define('MATRIX_SERVER', 'https://matrix.bluemogul.us');
define('MATRIX_USER', getenv('MATRIX_USER') ?: '@ticket-bot:matrix.bluemogul.us');
define('MATRIX_PASSWORD', getenv('MATRIX_PASSWORD') ?: '');

// ============================================
// ITFLOW CONFIGURATION
// ============================================
define('ITFLOW_URL', 'https://itflow.bluemogul.us');
define('ITFLOW_API_KEY', getenv('ITFLOW_API_KEY') ?: '');

// ============================================
// UISP CONFIGURATION
// ============================================
define('UISP_URL', 'https://uisp.bluemogul.us');
define('UISP_API_KEY', getenv('UISP_API_KEY') ?: '');

// ============================================
// D&H DISTRIBUTING CONFIGURATION
// ============================================
define('DH_ACCOUNT', '3054540000');
define('DH_ENV', getenv('DH_ENV') ?: 'TEST');
define('DH_AUTH_URL', 'https://auth.dandh.com/api/oauth/token');
define('DH_AUTH_URL_TEST', 'https://test.auth.dandh.com/api/oauth/token');
define('DH_API_URL_TEST', 'https://test.api.dandh.com/customerOrderManagement/v2');
define('DH_API_URL_PROD', 'https://api.dandh.com/customerOrderManagement/v2');
define('DH_TENANT', getenv('DH_TENANT') ?: 'dhus');

// ============================================
// VULTR CLOUD CONFIGURATION
// ============================================
define('VULTR_API_KEY', getenv('VULTR_API_KEY') ?: '');
define('VULTR_API_URL', 'https://api.vultr.com/v2');

// ============================================
// JUMPCLOUD CONFIGURATION
// ============================================
define('JUMPCLOUD_API_KEY', getenv('JUMPCLOUD_API_KEY') ?: '');
define('JUMPCLOUD_API_V1',  'https://console.jumpcloud.com/api');
define('JUMPCLOUD_API_V2',  'https://console.jumpcloud.com/api/v2');
define('JUMPCLOUD_ORG_ID',  getenv('JUMPCLOUD_ORG_ID') ?: '');

// ============================================
// LINKEDIN / PROXYCURL CONFIGURATION
// ============================================
define('PROXYCURL_API_KEY', getenv('PROXYCURL_API_KEY') ?: '');

// ============================================
// NEXTCLOUD CONFIGURATION
// ============================================
define('NEXTCLOUD_URL', getenv('NEXTCLOUD_URL') ?: '');
define('NEXTCLOUD_USER', getenv('NEXTCLOUD_USER') ?: '');
define('NEXTCLOUD_PASSWORD', getenv('NEXTCLOUD_PASSWORD') ?: '');

// ============================================
// MONITORING CONFIGURATION
// ============================================
define('UPTIME_KUMA_URL', getenv('UPTIME_KUMA_URL') ?: 'https://status.bluemogul.us');
define('GRAFANA_URL', getenv('GRAFANA_URL') ?: 'https://grafana.bluemogul.us');

// ============================================
// AI AGENT CONFIGURATION
// ============================================
define('AI_AGENTS_ENABLED', true);
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('N8N_WEBHOOK_URL', getenv('N8N_WEBHOOK_URL') ?: '');
define('FLOWISE_URL', getenv('FLOWISE_URL') ?: '');
define('ANYTHINGLLM_URL', getenv('ANYTHINGLLM_URL') ?: '');

// ============================================
// MAIL CREDENTIAL ENCRYPTION
// ============================================
// Key derived from existing secrets — never stored directly
define('MAIL_CRYPT_KEY', substr(hash('sha256', (getenv('DB_PASS') ?: 'bm_fallback') . 'BlueMogulMailCrypt_v1'), 0, 32));
define('MAIL_CRYPT_IV',  substr(hash('md5',  (getenv('DB_PASS') ?: 'bm_fallback') . 'bm_iv_salt'), 0, 16));

// ============================================
// BRANDING
// ============================================
define('BRAND_PRIMARY_COLOR', '#1a56db');
define('BRAND_SECONDARY_COLOR', '#0d1b3e');
define('BRAND_ACCENT_COLOR', '#3b82f6');
define('BRAND_LOGO_URL', '/assets/img/logo.png');

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get database connection
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $databaseUrl = getenv('DATABASE_URL');
            if ($databaseUrl) {
                $parts = parse_url($databaseUrl);
                $host = $parts['host'] ?? 'localhost';
                $port = $parts['port'] ?? 5432;
                $dbname = ltrim($parts['path'] ?? '', '/');
                $user = $parts['user'] ?? '';
                $pass = $parts['pass'] ?? '';
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } else {
                $dsn = DB_TYPE . ':host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            }
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Portal redirect - works in both CLI and web modes
 * Outputs a marker that Express can detect for redirection
 */
function portal_redirect($url) {
    if (php_sapi_name() === 'cli') {
        echo "__REDIRECT__:" . $url;
        exit();
    } else {
        header('Location: ' . $url);
        exit();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/**
 * Redirect to URL
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Log message
 */
function logMessage($level, $message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
    $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}";
    error_log($logEntry);
}

/**
 * API response helper
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Sanitize input
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// ============================================
// SECURITY: CSRF PROTECTION
// ============================================

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Compatibility wrapper used by pages that call validate_csrf_token().
 * Checks both 'csrf_token' and '_csrf_token' POST keys.
 */
function validate_csrf_token($token = null) {
    if (empty($token)) {
        $token = $_POST['_csrf_token'] ?? $_POST['csrf_token'] ?? '';
    }
    return verify_csrf($token);
}

function require_csrf() {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        logMessage('WARNING', 'CSRF token validation failed', [
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
        http_response_code(403);
        die('<div style="font-family:Inter,sans-serif;text-align:center;padding:60px;color:#991b1b;"><h2>Security Error</h2><p>Invalid security token. Please go back and refresh the page.</p><a href="javascript:history.back()" style="color:#1a56db;">Go Back</a></div>');
    }
}

// ============================================
// SECURITY: LOGIN RATE LIMITING
// ============================================

function check_rate_limit($identifier, $max_attempts = 5, $window_seconds = 900) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempts 
        FROM activity_log 
        WHERE action = 'login_failed' 
        AND details LIKE ? 
        AND created_at > NOW() - INTERVAL '{$window_seconds} seconds'
    ");
    $stmt->execute(['%' . $identifier . '%']);
    $row = $stmt->fetch();
    return ($row['attempts'] ?? 0) < $max_attempts;
}

function record_failed_login($email, $ip) {
    $db = getDB();
    $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, details, ip_address) VALUES (NULL, ?, ?, ?, ?)")
       ->execute(['login_failed', 'auth', "Failed login for: {$email}", $ip]);
}

// ============================================
// SECURITY: HEADERS
// ============================================

function set_security_headers() {
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://js.stripe.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https://api.stripe.com; frame-src https://js.stripe.com;");
    }
}

set_security_headers();

// ============================================
// ERROR HANDLING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logMessage('ERROR', "$errstr in $errfile on line $errline");
    return false;
});

set_exception_handler(function($exception) {
    logMessage('EXCEPTION', $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
});

// ============================================
// AUTO-INCLUDE HELPERS
// ============================================
// Automatically include commonly used classes/functions
$autoloadDirs = [
    __DIR__ . '/includes',
    __DIR__ . '/classes',
    __DIR__ . '/agents',
    __DIR__ . '/connectors'
];

spl_autoload_register(function ($className) use ($autoloadDirs) {
    foreach ($autoloadDirs as $dir) {
        $file = $dir . '/' . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

logMessage('INFO', 'Configuration loaded successfully');
