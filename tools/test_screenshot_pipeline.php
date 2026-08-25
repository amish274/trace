<?php
// tools/test_screenshot_pipeline.php - End-to-End Screenshot Pipeline Diagnostic & Verification

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

echo "=====================================================\n";
echo "   System Utility Screenshot Pipeline Forensic Suite \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// [1/8] Seed/Fetch Test Employee & Device
$empName = "Screenshot Test Emp " . rand(1000, 9999);
$empEmail = "shot_test_" . time() . "@example.local";

$stmt = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
$stmt->execute([':name' => $empName, ':email' => $empEmail]);
$empId = (int)$db->lastInsertId();

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$devStmt = $db->prepare("INSERT INTO devices (employee_id, device_name, device_token_hash, status, package_status, last_seen_at) VALUES (:emp_id, 'SHOT-TEST-PC', :hash, 'active', 'enrolled', NOW())");
$devStmt->execute([':emp_id' => $empId, ':hash' => $tokenHash]);
$deviceId = (int)$db->lastInsertId();

$settStmt = $db->prepare("INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality) VALUES (:id, 1, 1, 30, 70)");
$settStmt->execute([':id' => $deviceId]);

echo "[1/8] Created Test Employee (ID: {$empId}) & Active Device (ID: {$deviceId})... SUCCESS\n";

// [2/8] Create Controlled Test Image
$im = imagecreatetruecolor(400, 300);
$bgColor = imagecolorallocate($im, 40, 120, 220);
$textColor = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $bgColor);
imagestring($im, 5, 20, 140, "System Utility Test Image - Device {$deviceId}", $textColor);

$tmpImgPath = sys_get_temp_dir() . '/shot_' . time() . '_' . rand(100, 999) . '.jpg';
imagejpeg($im, $tmpImgPath, 80);
imagedestroy($im);

$imgSize = filesize($tmpImgPath);
echo "[2/8] Generated Controlled Test Image ({$tmpImgPath}, {$imgSize} bytes)... SUCCESS\n";

// [3/8] Test Upload API via cURL
$serverUrl = rtrim(SERVER_BASE_URL, '/');
$uploadUrl = $serverUrl . '/api/agent/screenshot.php';

$ch = curl_init($uploadUrl);
$cfile = new CURLFile($tmpImgPath, 'image/jpeg', 'test_shot.jpg');
$data = [
    'screenshot' => $cfile,
    'activity_status' => 'ACTIVE',
    'idle_seconds' => '0',
    'captured_at' => date('c')
];

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$rawToken}"
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (file_exists($tmpImgPath)) {
    @unlink($tmpImgPath);
}

echo "[3/8] Executed Upload HTTP Request to {$uploadUrl}...\n";
echo "      HTTP Status: {$httpCode}\n";
echo "      Response: {$response}\n";

if ($httpCode !== 200) {
    echo "FAILED: Expected HTTP 200 from upload endpoint.\n";
    exit(1);
}

$respData = json_decode($response, true);
if (empty($respData['success']) || empty($respData['screenshot_id'])) {
    echo "FAILED: API response missing success or screenshot_id.\n";
    exit(1);
}
$screenshotId = (int)$respData['screenshot_id'];
echo "      Assigned Screenshot ID: {$screenshotId}... SUCCESS\n";

// [4/8] Verify DB Screenshot Record
$shotStmt = $db->prepare("SELECT * FROM screenshots WHERE id = :id");
$shotStmt->execute([':id' => $screenshotId]);
$shotRecord = $shotStmt->fetch(PDO::FETCH_ASSOC);

if (!$shotRecord) {
    echo "[4/8] Verifying Database Screenshot Record... FAILED (Record not found in screenshots table)\n";
    exit(1);
}
echo "[4/8] Verifying Database Screenshot Record... SUCCESS (Found record for device_id: {$shotRecord['device_id']}, path: {$shotRecord['relative_path']})\n";

// [5/8] Verify Physical Storage File
$storageDir = getScreenshotStorageDir();
$physicalFile = $storageDir . '/' . ltrim($shotRecord['relative_path'], '/');

if (!file_exists($physicalFile) || filesize($physicalFile) === 0) {
    echo "[5/8] Verifying Physical File on Disk ({$physicalFile})... FAILED (File missing or empty)\n";
    exit(1);
}
echo "[5/8] Verifying Physical File on Disk ({$physicalFile})... SUCCESS (" . filesize($physicalFile) . " bytes)\n";

// [6/8] Verify Dashboard Query Matching
$dashStmt = $db->prepare("
    SELECT s.*, d.device_name, e.name as employee_name 
    FROM screenshots s
    JOIN devices d ON s.device_id = d.id
    JOIN employees e ON d.employee_id = e.id
    WHERE s.device_id = :device_id AND DATE(s.captured_at) = CURDATE()
");
$dashStmt->execute([':device_id' => $deviceId]);
$foundDashboardShots = $dashStmt->fetchAll();

if (empty($foundDashboardShots)) {
    echo "[6/8] Verifying Dashboard Screenshots Query... FAILED (Query returned 0 rows)\n";
    exit(1);
}
echo "[6/8] Verifying Dashboard Screenshots Query... SUCCESS (Matched " . count($foundDashboardShots) . " row(s))\n";

// [7/8] Test Image Serving Helper
$servePath = realpath($storageDir . '/' . ltrim($shotRecord['relative_path'], '/'));
if ($servePath === false || !file_exists($servePath)) {
    echo "[7/8] Verifying Image Serving Path Resolution... FAILED\n";
    exit(1);
}
echo "[7/8] Verifying Image Serving Path Resolution ({$servePath})... SUCCESS\n";

// [8/8] Clean up test data
$db->exec("DELETE FROM screenshots WHERE id = {$screenshotId}");
$db->exec("DELETE FROM activity WHERE device_id = {$deviceId}");
$db->exec("DELETE FROM monitor_settings WHERE device_id = {$deviceId}");
$db->exec("DELETE FROM devices WHERE id = {$deviceId}");
$db->exec("DELETE FROM employees WHERE id = {$empId}");
if (file_exists($physicalFile)) {
    @unlink($physicalFile);
}

echo "[8/8] Cleaned test fixtures... SUCCESS\n\n";
echo "=====================================================\n";
echo "   All Screenshot Pipeline Diagnostics PASSED!      \n";
echo "=====================================================\n";
