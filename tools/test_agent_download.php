<?php
// tools/test_agent_download.php - Download Diagnostic & Health Verification Tool

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$options = getopt("", ["device-id:"]);
$deviceId = (int)($options['device-id'] ?? 5);

echo "=====================================================\n";
echo "   TeamTrace Agent Download Diagnostic Utility       \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// 1. Verify Device Record
echo "[1/8] Verifying device ID {$deviceId} in database... ";
$stmt = $db->prepare("SELECT d.*, e.name as employee_name FROM devices d JOIN employees e ON d.employee_id = e.id WHERE d.id = :id");
$stmt->execute([':id' => $deviceId]);
$device = $stmt->fetch();

if ($device) {
    echo "SUCCESS (Device: {$device['device_name']}, Employee: {$device['employee_name']})\n";
} else {
    echo "FAILED: Device ID {$deviceId} not found.\n";
    exit(1);
}

// 2. Package File Existence Check (ZIP Preference > EXE Fallback)
echo "[2/8] Checking package file location... ";
$sanitizedName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['device_name']);
$candidates = [
    __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitizedName}.zip",
    __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitizedName}.exe",
    __DIR__ . "/../storage/packages/System-Utility-{$sanitizedName}.exe"
];

$packagePath = '';
foreach ($candidates as $cand) {
    if (file_exists($cand) && filesize($cand) > 0) {
        $packagePath = $cand;
        break;
    }
}

if (!empty($packagePath)) {
    echo "SUCCESS ({$packagePath})\n";
} else {
    echo "NOTICE: Package not found. Triggering package generator... ";
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $exp = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $ins = $db->prepare("INSERT INTO device_enrollment_tokens (device_id, token_hash, status, expires_at) VALUES (:id, :h, 'ready', :e)");
    $ins->execute([':id' => $deviceId, ':h' => $hash, ':e' => $exp]);

    require_once __DIR__ . '/generate_agent.php';
    try {
        $packagePath = generateAgentPackage($deviceId, $token, SERVER_BASE_URL, '', 'zip');
        echo "SUCCESS (Generated " . basename($packagePath) . ")\n";
    } catch (Exception $e) {
        echo "FAILED: Could not generate package: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// 3. Realpath Security Check
echo "[3/8] Performing realpath path traversal audit... ";
$realPkg = realpath($packagePath);
$storageDir = realpath(__DIR__ . '/../storage/packages');

if ($realPkg && $storageDir && strpos($realPkg, $storageDir) === 0) {
    echo "SUCCESS (Path is safely confined within storage/packages)\n";
} else {
    echo "FAILED: Path traversal security check failed!\n";
    exit(1);
}

// 4. File Readability Check
echo "[4/8] Testing file readability... ";
if (is_readable($packagePath)) {
    echo "SUCCESS (File is readable)\n";
} else {
    echo "FAILED: File is not readable.\n";
    exit(1);
}

// 5. File Size Verification
echo "[5/8] Verifying file size... ";
$fileSize = filesize($packagePath);
$sizeKb = round($fileSize / 1024, 2);
if ($fileSize > 0) {
    echo "SUCCESS ({$fileSize} bytes / {$sizeKb} KB)\n";
} else {
    echo "FAILED: File size is 0.\n";
    exit(1);
}

// 6. Header Verification (ZIP or PE Windows header)
echo "[6/8] Auditing package binary/archive header... ";
$header = file_get_contents($packagePath, false, null, 0, 2);
if ($header === 'PK' || $header === 'MZ') {
    $hdrType = $header === 'PK' ? 'ZIP Archive (PK)' : 'Windows PE Executable (MZ)';
    echo "SUCCESS (Valid header: {$hdrType})\n";
} else {
    echo "FAILED: Invalid executable/archive header (got '{$header}').\n";
    exit(1);
}

// 7. Signed URL Construction
echo "[7/8] Testing signed download URL generation... ";
$signedUrl = generateSignedDownloadUrl($deviceId, 5);
parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
$isValidSig = verifySignedDownloadUrl($query['device_id'] ?? 0, $query['expires'] ?? '', $query['sig'] ?? '');

if ($isValidSig) {
    echo "SUCCESS\n    Signed URL: {$signedUrl}\n";
} else {
    echo "FAILED: Signature verification failed.\n";
    exit(1);
}

// 8. End-to-End Signed HTTP Download Test
echo "[8/8] Testing end-to-end HTTP download via curl... ";
$fullUrl = SERVER_BASE_URL . "/admin/" . $signedUrl;
$testOut = "/tmp/diagnostic_download_test_" . time();

$ch = curl_init($fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$downloadData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode === 200 && strlen($downloadData) === $fileSize) {
    file_put_contents($testOut, $downloadData);
    $downloadHeader = file_get_contents($testOut, false, null, 0, 2);
    if ($downloadHeader === 'PK' || $downloadHeader === 'MZ') {
        echo "SUCCESS (HTTP 200, Content-Type: {$contentType}, Size: " . strlen($downloadData) . " bytes, Header: {$downloadHeader})\n";
        @unlink($testOut);
    } else {
        echo "FAILED: Downloaded file header invalid.\n";
        exit(1);
    }
} else {
    echo "FAILED: HTTP {$httpCode}, Received " . strlen($downloadData) . " bytes (Expected {$fileSize} bytes).\n";
    exit(1);
}

echo "\n=====================================================\n";
echo "   All 8 Agent Download Diagnostics PASSED!          \n";
echo "=====================================================\n";
