<?php
// tools/test_agent_generation_download.php - End-to-End System Utility Package Generation & Download Verification

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/generate_agent.php';

echo "=====================================================\n";
echo "   System Utility Package Generation & Download Test \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// 1. Create/use a test device
echo "[1/13] Creating Test Employee and Device... ";
$empName = 'System Utility Emp ' . time();
$empEmail = 'su_test_' . time() . '@example.local';
$devName = 'SU-TEST-PC-' . rand(100, 999);

$insEmp = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
$insEmp->execute([':name' => $empName, ':email' => $empEmail]);
$empId = (int)$db->lastInsertId();

$insDev = $db->prepare("INSERT INTO devices (employee_id, device_name, status, package_status) VALUES (:emp_id, :dev_name, 'active', 'not_generated')");
$insDev->execute([':emp_id' => $empId, ':dev_name' => $devName]);
$deviceId = (int)$db->lastInsertId();

echo "SUCCESS (Device ID: {$deviceId}, Name: {$devName})\n";

// 2. Generate its package
echo "[2/13] Generating Device Package (System Utility-{$deviceId}.zip)... ";
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

$insTok = $db->prepare("INSERT INTO device_enrollment_tokens (device_id, token_hash, status, expires_at) VALUES (:dev_id, :hash, 'ready', :exp)");
$insTok->execute([':dev_id' => $deviceId, ':hash' => $tokenHash, ':exp' => $expiresAt]);

try {
    $generatedPath = generateAgentPackage($deviceId, $rawToken, SERVER_BASE_URL, '', 'zip');
    echo "SUCCESS (" . basename($generatedPath) . ")\n";
} catch (Exception $e) {
    die("FAILED: Package generation error: " . $e->getMessage() . "\n");
}

// 3. Confirm ZIP file exists
echo "[3/13] Confirming ZIP file exists... ";
if (file_exists($generatedPath) && filesize($generatedPath) > 0) {
    echo "SUCCESS ({$generatedPath})\n";
} else {
    die("FAILED: Package file missing or 0 bytes.\n");
}

// 4. Confirm filename matches System Utility-<number>.zip
echo "[4/13] Verifying filename pattern (System Utility-{$deviceId}.zip)... ";
$actualFilename = basename($generatedPath);
$expectedFilename = "System Utility-{$deviceId}.zip";
if ($actualFilename === $expectedFilename) {
    echo "SUCCESS ({$actualFilename})\n";
} else {
    die("FAILED: Expected filename '{$expectedFilename}', got '{$actualFilename}'.\n");
}

// 5. Confirm ZIP validity
echo "[5/13] Confirming ZIP archive validity... ";
$zip = new ZipArchive();
if ($zip->open($generatedPath) !== true) {
    die("FAILED: Unable to open ZIP archive.\n");
}
echo "SUCCESS (Valid ZIP archive)\n";

// 6. Confirm system-utility.config.json exists inside ZIP
echo "[6/13] Confirming system-utility.config.json exists inside ZIP... ";
$jsonRaw = $zip->getFromName('system-utility.config.json');
if (!$jsonRaw) {
    die("FAILED: system-utility.config.json missing from ZIP archive.\n");
}
echo "SUCCESS\n";

// 7. Confirm JSON payload validity & required enrollment fields
echo "[7/13] Validating system-utility.config.json schema & fields... ";
$configData = json_decode($jsonRaw, true);
if (!$configData) {
    die("FAILED: system-utility.config.json is not valid JSON.\n");
}

$requiredKeys = ['server_base_url', 'enrollment_token', 'device_id', 'device_name'];
foreach ($requiredKeys as $key) {
    if (empty($configData[$key])) {
        die("FAILED: Missing required key '{$key}' in system-utility.config.json.\n");
    }
}
echo "SUCCESS (All required schema fields present)\n";

// 8. Confirm SERVER_BASE_URL and Device ID match
echo "[8/13] Verifying production SERVER_BASE_URL & Device ID match... ";
if ($configData['device_id'] !== $deviceId) {
    die("FAILED: Device ID mismatch (Expected {$deviceId}, got {$configData['device_id']}).\n");
}

$expectedUrl = rtrim(SERVER_BASE_URL, '/');
if (defined('APP_ENV') && APP_ENV === 'production' && (strpos($expectedUrl, '127.0.0.1') !== false || strpos($expectedUrl, 'localhost') !== false)) {
    $expectedUrl = 'https://ethnicboost.com/Trace';
}

if (rtrim($configData['server_base_url'], '/') !== $expectedUrl) {
    die("FAILED: SERVER_BASE_URL mismatch (Expected {$expectedUrl}, got {$configData['server_base_url']}).\n");
}
echo "SUCCESS (Server URL: {$configData['server_base_url']}, Device ID: {$configData['device_id']})\n";

// 9. Confirm Executable inside ZIP is byte-identical to base binary (Authenticode preserved)
echo "[9/13] Confirming bootstrapper executable inside ZIP (Authenticode preservation)... ";
$zippedExeData = $zip->getFromName('System Utility.exe');
if (!$zippedExeData) {
    $zippedExeData = $zip->getFromName('TeamTraceBootstrap.exe');
}
$baseExeData = file_get_contents(__DIR__ . '/../build/windows/TeamTraceBootstrap.exe');

if (!$zippedExeData || strlen($zippedExeData) <= 0) {
    die("FAILED: Bootstrapper executable missing from ZIP archive.\n");
}

if ($zippedExeData !== $baseExeData) {
    die("FAILED: Bootstrapper executable inside ZIP is NOT byte-identical to base binary! Signature would be invalidated.\n");
}
$zip->close();
echo "SUCCESS (Executable present and 100% byte-identical to base binary)\n";

// 10. Confirm signed download endpoint streams exact freshly generated package
echo "[10/13] Testing signed download endpoint (admin/agent_download.php)... ";
$signedUrl = generateSignedDownloadUrl($deviceId, 5);
$fullUrl = SERVER_BASE_URL . "/admin/" . $signedUrl;
$downloadDest = "/tmp/test_download_" . time() . ".zip";

$ch = curl_init($fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$downloadData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && strlen($downloadData) === filesize($generatedPath)) {
    file_put_contents($downloadDest, $downloadData);
    $testZip = new ZipArchive();
    if ($testZip->open($downloadDest) === true) {
        $testCfg = $testZip->getFromName('system-utility.config.json');
        $testZip->close();
        if ($testCfg && strpos($testCfg, $rawToken) !== false) {
            echo "SUCCESS (HTTP 200, Content-Type: {$contentType}, Size: " . strlen($downloadData) . " bytes)\n";
        } else {
            die("FAILED: Downloaded ZIP payload corrupted.\n");
        }
    } else {
        die("FAILED: Downloaded file is not a valid ZIP.\n");
    }
    @unlink($downloadDest);
} else {
    die("FAILED: Download endpoint returned HTTP {$httpCode}, Received " . strlen($downloadData) . " bytes (Expected " . filesize($generatedPath) . " bytes). Curl Error: '{$curlErr}'\n");
}

// 11. Confirm nonexistent package handling
echo "[11/13] Testing nonexistent package handling... ";
$fakeDeviceId = 999999;
$fakeSignedUrl = generateSignedDownloadUrl($fakeDeviceId, 5);
$fakeFullUrl = SERVER_BASE_URL . "/admin/" . $fakeSignedUrl;

$ch = curl_init($fakeFullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$fakeData = curl_exec($ch);
$fakeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($fakeHttpCode === 404 && strpos($fakeData, "Error:") !== false) {
    echo "SUCCESS (HTTP 404 with diagnostic error message: " . trim($fakeData) . ")\n";
} else {
    die("FAILED: Nonexistent package did not return 404! HTTP: {$fakeHttpCode}\n");
}

// 12. Test verification via tools/verify_agent.php
echo "[12/13] Running tools/verify_agent.php on generated package... ";
$verifyCmd = sprintf(
    '%s %s --package=%s',
    PHP_BINARY,
    escapeshellarg(__DIR__ . '/verify_agent.php'),
    escapeshellarg($generatedPath)
);
exec($verifyCmd, $vOut, $vRet);
if ($vRet === 0) {
    echo "SUCCESS\n";
} else {
    die("FAILED: verify_agent.php failed on generated package!\n" . implode("\n", $vOut) . "\n");
}

// 13. Clean up test data/files
echo "[13/13] Cleaning up test data and files... ";
$db->exec("DELETE FROM device_enrollment_tokens WHERE device_id = {$deviceId}");
$db->exec("DELETE FROM devices WHERE id = {$deviceId}");
$db->exec("DELETE FROM employees WHERE id = {$empId}");
@unlink($generatedPath);
echo "SUCCESS\n\n";

echo "=====================================================\n";
echo "   All 13 System Utility Package Tests PASSED!        \n";
echo "=====================================================\n";
