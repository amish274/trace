<?php
// tools/test_agent_generation_download.php - End-to-End Agent Package Generation & Download Verification

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/generate_agent.php';

echo "=====================================================\n";
echo "   TeamTrace Package Generation & Download Test      \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// 1. Create/use a test device
echo "[1/10] Creating Test Employee and Device... ";
$empName = 'Pkg Test Emp ' . time();
$empEmail = 'pkg_test_' . time() . '@example.local';
$devName = 'PKG-TEST-PC-' . rand(100, 999);

$insEmp = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
$insEmp->execute([':name' => $empName, ':email' => $empEmail]);
$empId = (int)$db->lastInsertId();

$insDev = $db->prepare("INSERT INTO devices (employee_id, device_name, status, package_status) VALUES (:emp_id, :dev_name, 'active', 'not_generated')");
$insDev->execute([':emp_id' => $empId, ':dev_name' => $devName]);
$deviceId = (int)$db->lastInsertId();

echo "SUCCESS (Device ID: {$deviceId}, Name: {$devName})\n";

// 2. Generate its package
echo "[2/10] Generating Device Bootstrapper Package (Authenticode-Safe ZIP)... ";
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

// 3. Confirm the package physically exists
echo "[3/10] Confirming package physically exists on disk... ";
if (file_exists($generatedPath) && filesize($generatedPath) > 0) {
    $sizeKb = round(filesize($generatedPath) / 1024, 2);
    echo "SUCCESS ({$generatedPath}, {$sizeKb} KB)\n";
} else {
    die("FAILED: Package file missing or 0 bytes.\n");
}

// 4. Confirm the ZIP is valid
echo "[4/10] Confirming ZIP archive validity... ";
$zip = new ZipArchive();
if ($zip->open($generatedPath) !== true) {
    die("FAILED: Unable to open ZIP archive.\n");
}
echo "SUCCESS (Valid ZIP archive)\n";

// 5. Confirm TeamTraceBootstrap.exe exists inside it and is byte-identical to base binary
echo "[5/10] Confirming TeamTraceBootstrap.exe inside ZIP (Authenticode preservation)... ";
$zippedExeData = $zip->getFromName('TeamTraceBootstrap.exe');
$baseExeData = file_get_contents(__DIR__ . '/../build/windows/TeamTraceBootstrap.exe');

if (!$zippedExeData || strlen($zippedExeData) <= 0) {
    die("FAILED: TeamTraceBootstrap.exe missing from ZIP archive.\n");
}

if ($zippedExeData !== $baseExeData) {
    die("FAILED: TeamTraceBootstrap.exe inside ZIP is NOT byte-identical to base binary! Signature would be invalidated.\n");
}
echo "SUCCESS (TeamTraceBootstrap.exe present and 100% byte-identical to base binary)\n";

// 6. Confirm teamtrace.config.json exists
echo "[6/10] Confirming teamtrace.config.json exists inside ZIP... ";
$jsonRaw = $zip->getFromName('teamtrace.config.json');
if (!$jsonRaw) {
    die("FAILED: teamtrace.config.json missing from ZIP archive.\n");
}
echo "SUCCESS\n";

// 7. Confirm the config contains correct server URL and device information
echo "[7/10] Verifying teamtrace.config.json contents... ";
$configData = json_decode($jsonRaw, true);
$zip->close();

if (!$configData) {
    die("FAILED: teamtrace.config.json is not valid JSON.\n");
}

if (rtrim($configData['server_base_url'], '/') !== rtrim(SERVER_BASE_URL, '/') ||
    $configData['enrollment_token'] !== $rawToken ||
    $configData['device_id'] !== $deviceId ||
    $configData['device_name'] !== $devName) {
    die("FAILED: Configuration JSON payload mismatch!\n");
}
echo "SUCCESS (Server URL: {$configData['server_base_url']}, Device ID: {$configData['device_id']}, Name: {$configData['device_name']})\n";

// 8. Confirm the download endpoint resolves and streams the exact package
echo "[8/10] Testing signed download endpoint (admin/agent_download.php)... ";
$signedUrl = generateSignedDownloadUrl($deviceId, 5);
$fullUrl = SERVER_BASE_URL . "/admin/" . $signedUrl;
$downloadDest = "/tmp/test_download_" . time() . ".zip";

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

if ($httpCode === 200 && strlen($downloadData) === filesize($generatedPath)) {
    file_put_contents($downloadDest, $downloadData);
    $testZip = new ZipArchive();
    if ($testZip->open($downloadDest) === true) {
        $testCfg = $testZip->getFromName('teamtrace.config.json');
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
    die("FAILED: Download endpoint returned HTTP {$httpCode}, Received " . strlen($downloadData) . " bytes (Expected " . filesize($generatedPath) . " bytes).\n");
}

// 9. Confirm nonexistent package handling
echo "[9/10] Testing nonexistent package handling... ";
$fakeDeviceId = 999999;
$fakeSignedUrl = generateSignedDownloadUrl($fakeDeviceId, 5);
$fakeFullUrl = SERVER_BASE_URL . "/admin/" . $fakeSignedUrl;

$ch = curl_init($fakeFullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$fakeData = curl_exec($ch);
$fakeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($fakeHttpCode === 404 && strpos($fakeData, "Error:") !== false) {
    echo "SUCCESS (HTTP 404 with diagnostic error message: " . trim($fakeData) . ")\n";
} else {
    die("FAILED: Nonexistent package did not return 404! HTTP: {$fakeHttpCode}\n");
}

// 10. Clean up test data/files
echo "[10/10] Cleaning up test data and files... ";
$db->exec("DELETE FROM device_enrollment_tokens WHERE device_id = {$deviceId}");
$db->exec("DELETE FROM devices WHERE id = {$deviceId}");
$db->exec("DELETE FROM employees WHERE id = {$empId}");
@unlink($generatedPath);
echo "SUCCESS\n\n";

echo "=====================================================\n";
echo "   All 10 Package Generation & Download Tests PASSED! \n";
echo "=====================================================\n";
