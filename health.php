<?php
// health.php - System Health Check Endpoint

require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

$status = "ok";
$dbStatus = "disconnected";

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ];
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
    $stmt = $pdo->query("SELECT 1");
    if ($stmt) {
        $dbStatus = "connected";
    }
} catch (Exception $e) {
    $status = "error";
    $dbStatus = "disconnected";
    error_log("[TeamTrace][HealthCheck] DB Connection failed: " . $e->getMessage());
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
