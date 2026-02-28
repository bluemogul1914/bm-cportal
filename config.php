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
define('SITE_NAME', 'Blue Mogul Client Portal');
define('SITE_URL', 'https://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));
define('ADMIN_EMAIL', 'contact@bluemogul.biz');
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
// ITARIAN RMM CONFIGURATION
// ============================================
define('ITARIAN_API_KEY', getenv('ITARIAN_API_KEY') ?: '');
define('ITARIAN_API_URL', 'https://api.comodo.com/v1');

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
// AI AGENT CONFIGURATION
// ============================================
define('AI_AGENTS_ENABLED', true);
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('N8N_WEBHOOK_URL', getenv('N8N_WEBHOOK_URL') ?: '');
define('FLOWISE_URL', getenv('FLOWISE_URL') ?: '');
define('ANYTHINGLLM_URL', getenv('ANYTHINGLLM_URL') ?: '');

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
// ERROR HANDLING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production

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
