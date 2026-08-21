<?php
// admin/revoke.php - Revoke Device Access Endpoint

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$deviceId = (int)($_POST['device_id'] ?? 0);
$csrfToken = $_POST['csrf_token'] ?? '';

if ($deviceId <= 0 || !verifyCsrfToken($csrfToken)) {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();

// Revoke device permanent auth token and mark status as revoked
$stmt = $db->prepare("UPDATE devices SET status = 'revoked', device_token_hash = NULL WHERE id = :id");
$stmt->execute([':id' => $deviceId]);

// Invalidate any active enrollment tokens for this device
$tokenStmt = $db->prepare("UPDATE device_enrollment_tokens SET status = 'revoked' WHERE device_id = :id");
$tokenStmt->execute([':id' => $deviceId]);

header("Location: device.php?id={$deviceId}&msg=revoked");
exit;
