<?php
// admin/screenshot.php - Authenticated Screenshot Image Serving Endpoint

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminSession();

$screenshotId = (int)($_GET['id'] ?? 0);
if (!$screenshotId) {
    http_response_code(404);
    die("Screenshot ID missing");
}

$db = getDbConnection();
$stmt = $db->prepare("SELECT * FROM screenshots WHERE id = :id");
$stmt->execute([':id' => $screenshotId]);
$screenshot = $stmt->fetch();

if (!$screenshot) {
    http_response_code(404);
    die("Screenshot record not found");
}

$storageDir = realpath(SCREENSHOT_STORAGE_PATH);
$fullPath = realpath($storageDir . '/' . $screenshot['relative_path']);

// Prevent Path Traversal attacks
if ($fullPath === false || strpos($fullPath, $storageDir) !== 0 || !file_exists($fullPath)) {
    http_response_code(404);
    die("Screenshot image file missing on disk");
}

// Serve Image with proper headers
$mime = 'image/jpeg';
if (str_ends_with(strtolower($fullPath), '.png')) {
    $mime = 'image/png';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=86400');
readfile($fullPath);
exit;
