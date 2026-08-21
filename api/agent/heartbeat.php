<?php
// api/agent/heartbeat.php - Agent Periodic Heartbeat Endpoint

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

$agentVersion = trim($data['agent_version'] ?? $device['agent_version'] ?? '1.0.0');
$active = isset($data['active']) ? (int)(bool)$data['active'] : 1;
$idleSeconds = isset($data['idle_seconds']) ? (int)$data['idle_seconds'] : 0;
$timestamp = !empty($data['timestamp']) ? date('Y-m-d H:i:s', strtotime($data['timestamp'])) : date('Y-m-d H:i:s');

$db = getDbConnection();

// Insert heartbeat log
$stmt = $db->prepare("INSERT INTO agent_heartbeats (device_id, agent_version, sent_at, active, idle_seconds) VALUES (:device_id, :version, :sent_at, :active, :idle_seconds)");
$stmt->execute([
    ':device_id' => $device['id'],
    ':version' => $agentVersion,
    ':sent_at' => $timestamp,
    ':active' => $active,
    ':idle_seconds' => $idleSeconds
]);

// Update device last_seen_at & version
$updateStmt = $db->prepare("UPDATE devices SET last_seen_at = NOW(), agent_version = :version WHERE id = :id");
$updateStmt->execute([
    ':version' => $agentVersion,
    ':id' => $device['id']
]);

respondJson(['success' => true]);
