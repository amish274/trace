<?php
// health.php - System Health Check Endpoint

require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

$status = "error";
$dbStatus = "disconnected";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 3
];

$dsnList = [
    "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4"
];

$altHost = (DB_HOST === '127.0.0.1') ? 'localhost' : '127.0.0.1';
$dsnList[] = "mysql:host=" . $altHost . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";

if (file_exists('/var/lib/mysql/mysql.sock')) {
    $dsnList[] = "mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=" . DB_DATABASE . ";charset=utf8mb4";
}
if (file_exists('/tmp/mysql.sock')) {
    $dsnList[] = "mysql:unix_socket=/tmp/mysql.sock;dbname=" . DB_DATABASE . ";charset=utf8mb4";
}

foreach ($dsnList as $tryDsn) {
    try {
        $pdo = new PDO($tryDsn, DB_USERNAME, DB_PASSWORD, $options);
        $stmt = $pdo->query("SELECT 1");
        if ($stmt) {
            $dbStatus = "connected";
            $status = "ok";
            break;
        }
    } catch (Exception $e) {
        error_log("[TeamTrace][HealthCheck] Connection failed for DSN ({$tryDsn}): " . $e->getMessage());
    }
}

if ($status === "error") {
    http_response_code(500);
}

echo json_encode([
    "status" => $status,
    "environment" => APP_ENV,
    "php" => PHP_VERSION,
    "database" => $dbStatus
], JSON_PRETTY_PRINT);
