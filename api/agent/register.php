<?php
// api/agent/register.php - Secure Zero-Touch Enrollment & Agent Registration API

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Invalid HTTP method'], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    $data = $_POST;
}

$enrollmentToken = trim($data['enrollment_token'] ?? '');
$deviceName = trim($data['device_name'] ?? '');
$os = trim($data['operating_system'] ?? 'Windows 10/11');
$agentVersion = trim($data['agent_version'] ?? '1.0.0');

if (empty($enrollmentToken)) {
    respondJson(['success' => false, 'error' => 'Missing enrollment_token parameter'], 400);
}

$db = getDbConnection();
$tokenHash = hash('sha256', $enrollmentToken);

// 1. Query secure device_enrollment_tokens table
$stmt = $db->prepare("
    SELECT t.*, d.id as dev_id, d.device_name as original_device_name, d.status as dev_status
    FROM device_enrollment_tokens t
    JOIN devices d ON t.device_id = d.id
    WHERE t.token_hash = :hash AND t.status = 'ready' AND t.expires_at > NOW()
");
$stmt->execute([':hash' => $tokenHash]);
$enrollmentRecord = $stmt->fetch();

$deviceId = 0;
$enrollmentTokenId = 0;

if ($enrollmentRecord) {
    if ($enrollmentRecord['dev_status'] === 'revoked') {
        respondJson(['success' => false, 'error' => 'Device authorization is revoked'], 403);
    }
    $deviceId = (int)$enrollmentRecord['dev_id'];
    $enrollmentTokenId = (int)$enrollmentRecord['id'];
} else {
    // 2. Fallback check for legacy plaintext token column
    $legacyStmt = $db->prepare("SELECT * FROM devices WHERE enrollment_token = :token AND status = 'active'");
    $legacyStmt->execute([':token' => $enrollmentToken]);
    $legacyDevice = $legacyStmt->fetch();

    if ($legacyDevice) {
        $deviceId = (int)$legacyDevice['id'];
    } else {
        respondJson(['success' => false, 'error' => 'Invalid, expired, or already used enrollment token'], 401);
    }
}

// Mark enrollment token as used (single-use restriction)
if ($enrollmentTokenId > 0) {
    $markUsed = $db->prepare("UPDATE device_enrollment_tokens SET status = 'used', used_at = NOW() WHERE id = :id");
    $markUsed->execute([':id' => $enrollmentTokenId]);
}

// Generate strong random permanent device authentication token
$rawDeviceToken = bin2hex(random_bytes(32));
$permTokenHash = hash('sha256', $rawDeviceToken);

if (empty($deviceName) && !empty($enrollmentRecord['original_device_name'])) {
    $deviceName = $enrollmentRecord['original_device_name'];
}

// Update device record with permanent token hash & set package status to enrolled
$updateStmt = $db->prepare("UPDATE devices SET 
    device_name = COALESCE(NULLIF(:device_name, ''), device_name),
    device_token_hash = :hash,
    enrollment_token = NULL,
    status = 'active',
    package_status = 'enrolled',
    operating_system = :os,
    agent_version = :version,
    last_seen_at = NOW()
    WHERE id = :id");

$updateStmt->execute([
    ':device_name' => $deviceName,
    ':hash' => $permTokenHash,
    ':os' => $os,
    ':version' => $agentVersion,
    ':id' => $deviceId
]);

respondJson([
    'success' => true,
    'device_id' => $deviceId,
    'device_token' => $rawDeviceToken,
    'message' => 'Device enrolled successfully'
]);
