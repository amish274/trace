<?php
// api/agent/activity.php - Agent Activity State Endpoint

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$device = authenticateAgentDevice();
if (!$device) {
    respondJson(['success' => false, 'error' => 'Unauthorized device authentication token'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Invalid HTTP method'], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

$activityStatus = strtoupper(trim($data['activity_status'] ?? ($data['active'] ? 'ACTIVE' : 'IDLE')));
if (!in_array($activityStatus, ['ACTIVE', 'IDLE'])) {
    $activityStatus = 'ACTIVE';
}
$idleSeconds = isset($data['idle_seconds']) ? (int)$data['idle_seconds'] : 0;
$capturedAt = !empty($data['captured_at']) ? date('Y-m-d H:i:s', strtotime($data['captured_at'])) : date('Y-m-d H:i:s');

$db = getDbConnection();

$stmt = $db->prepare("INSERT INTO activity (device_id, captured_at, activity_status, idle_seconds) VALUES (:device_id, :captured_at, :status, :idle_seconds)");
$stmt->execute([
    ':device_id' => $device['id'],
    ':captured_at' => $capturedAt,
    ':status' => $activityStatus,
    ':idle_seconds' => $idleSeconds
]);

// Update device last_seen_at
$updateStmt = $db->prepare("UPDATE devices SET last_seen_at = NOW() WHERE id = :id");
$updateStmt->execute([':id' => $device['id']]);

respondJson(['success' => true]);
