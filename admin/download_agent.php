<?php
// admin/download_agent.php - Authenticated Agent Download Proxy

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminSession();

$deviceId = (int)($_GET['device_id'] ?? 0);
if ($deviceId <= 0) {
    http_response_code(400);
    die("Invalid device ID parameter.");
}

$signedUrl = generateSignedDownloadUrl($deviceId, 5);
header("Location: {$signedUrl}");
exit;
