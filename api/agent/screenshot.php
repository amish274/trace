<?php
// api/agent/screenshot.php - Agent Multipart Screenshot Upload Endpoint

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$device = authenticateAgentDevice();
if (!$device) {
    respondJson(['success' => false, 'error' => 'Unauthorized device authentication token'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Invalid HTTP method'], 405);
}

// Check uploaded screenshot file
if (!isset($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['screenshot']['error'] ?? 'NO_FILE';
    respondJson(['success' => false, 'error' => 'Screenshot upload failed with code: ' . $errCode], 400);
}

$uploadedFile = $_FILES['screenshot'];

// Validate File Size (max 10MB limit per image)
if ($uploadedFile['size'] > 10 * 1024 * 1024) {
    respondJson(['success' => false, 'error' => 'Screenshot file size exceeds 10MB limit'], 400);
}

// Validate Image MIME type & Format securely using getimagesize
$imageInfo = @getimagesize($uploadedFile['tmp_name']);
if (!$imageInfo) {
    respondJson(['success' => false, 'error' => 'Invalid image file format'], 400);
}

$mimeType = $imageInfo['mime'];
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
if (!in_array(strtolower($mimeType), $allowedTypes)) {
    respondJson(['success' => false, 'error' => 'Invalid MIME type. Only JPEG and PNG allowed'], 400);
}

$width = $imageInfo[0];
$height = $imageInfo[1];

// Parse metadata from request parameters
$activityStatus = strtoupper(trim($_POST['activity_status'] ?? ($_POST['active'] ?? 1 ? 'ACTIVE' : 'IDLE')));
if (!in_array($activityStatus, ['ACTIVE', 'IDLE'])) {
    $activityStatus = 'ACTIVE';
}
$idleSeconds = (int)($_POST['idle_seconds'] ?? 0);
$capturedAt = !empty($_POST['captured_at']) ? date('Y-m-d H:i:s', strtotime($_POST['captured_at'])) : date('Y-m-d H:i:s');

// Prepare screenshot storage destination with randomized, unpredictable filename
$storageDir = SCREENSHOT_STORAGE_PATH;
if (!file_exists($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// Subfolder by year/month/day to keep directory manageable
$subDir = date('Y/m/d');
$targetDirectory = $storageDir . '/' . $subDir;
if (!file_exists($targetDirectory)) {
    mkdir($targetDirectory, 0755, true);
}

$randomFileName = bin2hex(random_bytes(16)) . '.jpg';
$fullPath = $targetDirectory . '/' . $randomFileName;
$relativePath = $subDir . '/' . $randomFileName;

// Prevent path traversal
if (realpath(dirname($fullPath)) === false || strpos(realpath(dirname($fullPath)), realpath($storageDir)) !== 0) {
    respondJson(['success' => false, 'error' => 'Path traversal detected'], 400);
}

if (!move_uploaded_file($uploadedFile['tmp_name'], $fullPath)) {
    respondJson(['success' => false, 'error' => 'Failed to save screenshot file on server'], 500);
}

$fileSize = filesize($fullPath);

$db = getDbConnection();

// Save metadata into database
$stmt = $db->prepare("INSERT INTO screenshots (device_id, captured_at, activity_status, relative_path, file_size, width, height) 
                      VALUES (:device_id, :captured_at, :status, :path, :size, :width, :height)");

$stmt->execute([
    ':device_id' => $device['id'],
    ':captured_at' => $capturedAt,
    ':status' => $activityStatus,
    ':path' => $relativePath,
    ':size' => $fileSize,
    ':width' => $width,
    ':height' => $height
]);
$screenshotId = (int)$db->lastInsertId();

// Also record corresponding activity entry
$actStmt = $db->prepare("INSERT INTO activity (device_id, captured_at, activity_status, idle_seconds) VALUES (:device_id, :captured_at, :status, :idle_seconds)");
$actStmt->execute([
    ':device_id' => $device['id'],
    ':captured_at' => $capturedAt,
    ':status' => $activityStatus,
    ':idle_seconds' => $idleSeconds
]);

// Update device last_seen_at
$updateStmt = $db->prepare("UPDATE devices SET last_seen_at = NOW() WHERE id = :id");
$updateStmt->execute([':id' => $device['id']]);

respondJson([
    'success' => true,
    'message' => 'Screenshot uploaded successfully',
    'screenshot_id' => $screenshotId
]);
