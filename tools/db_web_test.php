<?php
// tools/db_web_test.php - Temporary Diagnostic Endpoint for Live Server Audit

header('Content-Type: application/json; charset=utf-8');

// Require central configuration and DB helper
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$env = defined('APP_ENV') ? APP_ENV : 'unknown';
$dbHost = defined('DB_HOST') ? DB_HOST : 'unknown';
$dbPort = defined('DB_PORT') ? DB_PORT : 'unknown';
$dbName = defined('DB_DATABASE') ? DB_DATABASE : 'unknown';
$dbUser = defined('DB_USERNAME') ? DB_USERNAME : 'unknown';

// Test primary connection using getDbConnection()
$connectionSuccess = false;
$safeError = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    $stmt = $pdo->query("SELECT 1");
    if ($stmt) {
        $connectionSuccess = true;
    }
} catch (PDOException $e) {
    $safeError = $e->getMessage();
}

// If primary failed, test localhost socket fallback
$fallbackSuccess = false;
$fallbackError = '';
if (!$connectionSuccess) {
    try {
        $dsnFallback = "mysql:host=localhost;port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";
        $pdo2 = new PDO($dsnFallback, DB_USERNAME, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3
        ]);
        $stmt2 = $pdo2->query("SELECT 1");
        if ($stmt2) {
            $fallbackSuccess = true;
        }
    } catch (PDOException $e2) {
        $fallbackError = $e2->getMessage();
    }
}

if ($connectionSuccess) {
    echo json_encode([
        "status" => "ok",
        "environment" => $env,
        "database" => "connected",
        "host_used" => DB_HOST,
        "user_used" => $dbUser,
        "db_name" => $dbName
    ], JSON_PRETTY_PRINT);
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "environment" => $env,
        "database" => "failed",
        "host_attempted" => DB_HOST,
        "user_attempted" => $dbUser,
        "db_name" => $dbName,
        "primary_error" => $safeError,
        "localhost_fallback_success" => $fallbackSuccess,
        "fallback_error" => $fallbackError
    ], JSON_PRETTY_PRINT);
}
