<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Authenticate incoming Agent Bearer Token
 * @return array|null Returns device row array on success, null on failure
 */
function authenticateAgentDevice() {
    $headers = getallheaders();
    $authHeader = null;

    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } else if (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return null;
    }

    $rawToken = trim($matches[1]);
    if (empty($rawToken)) {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);

    $db = getDbConnection();
    $stmt = $db->prepare("SELECT d.*, e.name as employee_name, e.email as employee_email 
                          FROM devices d 
                          JOIN employees e ON d.employee_id = e.id 
                          WHERE d.device_token_hash = :hash AND d.status = 'active'");
    $stmt->execute([':hash' => $tokenHash]);
    $device = $stmt->fetch();

    return $device ?: null;
}

/**
 * Require valid Admin Session or redirect to login
 */
function requireAdminSession() {
    if (empty($_SESSION['admin_user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Check if Admin is logged in
 */
function isAdminLoggedIn() {
    return !empty($_SESSION['admin_user_id']);
}

/**
 * Generate CSRF Token
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 */
function verifyCsrfToken($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate short-lived signed download URL for an agent package
 */
function generateSignedDownloadUrl($deviceId, $expirationMinutes = 5) {
    $expires = time() + ($expirationMinutes * 60);
    $signature = hash_hmac('sha256', "device_id={$deviceId}&expires={$expires}", APP_KEY);
    return "agent_download.php?device_id={$deviceId}&expires={$expires}&sig={$signature}";
}

/**
 * Verify signed download URL signature & expiration
 */
function verifySignedDownloadUrl($deviceId, $expires, $signature) {
    if (empty($deviceId) || empty($expires) || empty($signature)) {
        return false;
    }
    if (time() > (int)$expires) {
        return false;
    }
    $expectedSignature = hash_hmac('sha256', "device_id={$deviceId}&expires={$expires}", APP_KEY);
    return hash_equals($expectedSignature, $signature);
}

/**
 * Respond with JSON API result
 */
function respondJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
