<?php
// health.php - System Health Check Endpoint

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

$status = "ok";
$dbStatus = "disconnected";

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT 1");
    if ($stmt) {
        $dbStatus = "connected";
    }
} catch (Exception $e) {
    $status = "error";
    $dbStatus = "disconnected";
}

if ($status === "error") {
    http_response_code(500);
}

echo json_encode([
    "status" => $status,
    "environment" => defined('APP_ENV') ? APP_ENV : 'production',
    "php" => PHP_VERSION,
    "database" => $dbStatus
], JSON_PRETTY_PRINT);
