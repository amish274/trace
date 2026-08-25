<?php
// admin/agent_download.php - Dedicated Authenticated/Signed Package Binary & ZIP Streamer

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

// 3. Construct candidate package paths (Canonical System Utility-ID.zip Preference > Legacy Fallbacks)
$sanitizedDeviceName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['device_name']);
$storageDir = realpath(__DIR__ . '/../storage/packages');

$candidateFiles = [
    "System Utility-{$deviceId}.zip",
    "System Utility-{$sanitizedDeviceName}.zip",
    "TeamTraceSetup-{$sanitizedDeviceName}.zip",
    "TeamTraceSetup-{$sanitizedDeviceName}.exe",
    "System-Utility-{$sanitizedDeviceName}.exe"
];

$packagePath = '';
$packageFilename = '';

foreach ($candidateFiles as $candidateName) {
    $fullPath = __DIR__ . "/../storage/packages/{$candidateName}";
    if (file_exists($fullPath) && filesize($fullPath) > 0) {
        $packagePath = $fullPath;
        $packageFilename = $candidateName;
        break;
    }
}

if (empty($packagePath)) {
    $expectedName = "System Utility-{$deviceId}.zip";
    http_response_code(404);
    die("Error: Agent package for device '" . htmlspecialchars($device['device_name']) . "' (ID: {$deviceId}) is not available on server. Expected file: {$expectedName}. Please click 'Generate Agent' in the Admin Panel.");
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

// 6. Set HTTP headers based on package extension (ZIP vs EXE)
$extension = strtolower(pathinfo($packageFilename, PATHINFO_EXTENSION));
if ($extension === 'zip') {
    header('Content-Type: application/zip');
} else {
    header('Content-Type: application/vnd.microsoft.portable-executable');
}

header('Content-Disposition: attachment; filename="' . $packageFilename . '"');
header('Content-Length: ' . filesize($realPackagePath));
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 7. Stream package content directly
readfile($realPackagePath);
exit;
