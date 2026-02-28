<?php
/**
 * Blue Mogul Portal - Login Handler
 * Processes login requests and manages authentication
 */

require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']) && $_POST['remember'] === '1';
    
    if (empty($email) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter both email and password'
        ]);
        exit;
    }
    
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT id, email, password, name, is_admin, role, status, created_at
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        logMessage('WARNING', 'Login attempt for non-existent user', ['email' => $email]);
        
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
        exit;
    }
    
    if (!password_verify($password, $user['password'])) {
        logMessage('WARNING', 'Failed login attempt - wrong password', ['email' => $email]);
        
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
        exit;
    }
    
    if (($user['status'] ?? 'active') === 'inactive') {
        echo json_encode([
            'success' => false,
            'message' => 'Your account has been deactivated. Please contact support.'
        ]);
        exit;
    }
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    
    $userRole = $user['role'] ?? 'user';
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['is_admin'] = (bool)$user['is_admin'];
    $_SESSION['user_role'] = $userRole;
    $_SESSION['logged_in_at'] = time();
    $_SESSION['last_activity'] = time();
    
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 60 * 60);
        
        $stmt = $db->prepare("
            UPDATE users
            SET remember_token = :token,
                remember_token_expires = :expiry
            WHERE id = :user_id
        ");
        
        $stmt->execute([
            'token' => hash('sha256', $token),
            'expiry' => date('Y-m-d H:i:s', $expiry),
            'user_id' => $user['id']
        ]);
        
        setcookie('remember_token', $token, $expiry, '/', '', isset($_SERVER['HTTPS']), true);
    }
    
    $stmt = $db->prepare("
        UPDATE users
        SET last_login = CURRENT_TIMESTAMP
        WHERE id = :user_id
    ");
    
    $stmt->execute(['user_id' => $user['id']]);
    
    logMessage('INFO', 'User logged in successfully', [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'is_admin' => $user['is_admin']
    ]);
    
    $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'login', 'user', $_SESSION['user_id'], 'User logged in', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    
    $redirect = 'dashboard.php';
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'redirect' => $redirect,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'is_admin' => (bool)$user['is_admin'],
            'role' => $userRole
        ]
    ]);
    
} catch (PDOException $e) {
    logMessage('ERROR', 'Database error during login', ['error' => $e->getMessage()]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.'
    ]);
    
} catch (Exception $e) {
    logMessage('ERROR', 'Unexpected error during login', ['error' => $e->getMessage()]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
