<?php
// health.php - System Health Check Endpoint

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$status = "ok";
$dbStatus = "disconnected";

try {
    $db = getDbConnection();
    $stmt = $db->query("SELECT 1");
    if ($stmt) {
        $dbStatus = "connected";
    }
} catch (Exception $e) {
    $status = "error";
    $dbStatus = "error: " . $e->getMessage();
}

echo json_encode([
    "status" => $status,
    "php" => PHP_VERSION,
    "database" => $dbStatus,
    "timestamp" => date('Y-m-d H:i:s')
]);
