<?php
// admin/agent_download.php - Dedicated Authenticated/Signed Direct EXE Binary Streamer

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$deviceId = (int)($_GET['device_id'] ?? 0);
$expires = $_GET['expires'] ?? '';
$signature = $_GET['sig'] ?? '';

// 1. Verify Access: Require either valid signed download URL OR active admin session
$isSignedValid = verifySignedDownloadUrl($deviceId, $expires, $signature);
$isAdminSession = isAdminLoggedIn();

if (!$isSignedValid && !$isAdminSession) {
    if (!empty($expires) && time() > (int)$expires) {
        http_response_code(403);
        die("Error: Download link has expired. Please generate a new link from the Admin Panel.");
    }
    http_response_code(401);
    header('Location: login.php');
    exit;
}

if ($deviceId <= 0) {
    http_response_code(400);
    die("Error: Invalid device parameter.");
}

// 2. Fetch device record from database
$db = getDbConnection();
$stmt = $db->prepare("
    SELECT d.*, e.name as employee_name 
    FROM devices d 
    JOIN employees e ON d.employee_id = e.id 
    WHERE d.id = :id
");
$stmt->execute([':id' => $deviceId]);
$device = $stmt->fetch();

if (!$device) {
    http_response_code(404);
    die("Error: Device record not found.");
}

// 3. Construct package path and perform security checks
$sanitizedDeviceName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['device_name']);
$packageFilename = "System-Utility-{$sanitizedDeviceName}.exe";
$storageDir = realpath(__DIR__ . '/../storage/packages');
$packagePath = __DIR__ . "/../storage/packages/{$packageFilename}";

if (!file_exists($packagePath) || filesize($packagePath) <= 0) {
    http_response_code(404);
    die("Error: Windows Agent executable not generated yet. Please click 'Generate Agent' in the Admin Panel.");
}

$realPackagePath = realpath($packagePath);
if (!$realPackagePath || !$storageDir || strpos($realPackagePath, $storageDir) !== 0) {
    http_response_code(403);
    die("Error: Invalid package path (Path traversal blocked).");
}

// 4. Update package status in database
$updateStatus = $db->prepare("UPDATE devices SET package_status = 'downloaded' WHERE id = :id AND package_status != 'enrolled'");
$updateStatus->execute([':id' => $deviceId]);

// 5. Clear all output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 6. Set HTTP headers for direct binary stream
header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="' . $packageFilename . '"');
header('Content-Length: ' . filesize($realPackagePath));
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 7. Stream binary content directly
readfile($realPackagePath);
exit;
