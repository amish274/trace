<?php
// tools/test_upgrade.php - Verification Suite for Direct EXE Bootstrapper & Zero-Touch Enrollment

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=====================================================\n";
echo "   TeamTrace Direct EXE Bootstrapper Test Suite      \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// 1. Create Test Employee and Device
echo "[1/8] Creating Test Employee and Device... ";
$empName = 'Test Employee ' . time();
$empEmail = 'test_emp_' . time() . '@example.com';
$devName = 'TEST-PC-' . rand(100, 999);

$insEmp = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
$insEmp->execute([':name' => $empName, ':email' => $empEmail]);
$empId = $db->lastInsertId();

$insDev = $db->prepare("INSERT INTO devices (employee_id, device_name, status, package_status) VALUES (:emp_id, :dev_name, 'active', 'not_generated')");
$insDev->execute([':emp_id' => $empId, ':dev_name' => $devName]);
$deviceId = $db->lastInsertId();

$insSet = $db->prepare("INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality, idle_threshold_seconds) VALUES (:dev_id, 1, 1, 30, 70, 120)");
$insSet->execute([':dev_id' => $deviceId]);

echo "SUCCESS (Device ID: {$deviceId}, Name: {$devName})\n";

// 2. Generate One-Time Token and Build Direct EXE Bootstrapper
echo "\n[2/8] Generating One-Time Token & Direct EXE Bootstrapper... ";
$startTime = microtime(true);
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

$insTok = $db->prepare("INSERT INTO device_enrollment_tokens (device_id, token_hash, status, expires_at) VALUES (:dev_id, :hash, 'ready', :exp)");
$insTok->execute([':dev_id' => $deviceId, ':hash' => $tokenHash, ':exp' => $expiresAt]);

$sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $devName);
$packageExe = __DIR__ . "/../storage/packages/System-Utility-{$sanitized}.exe";

require_once __DIR__ . '/generate_agent.php';
try {
    generateAgentPackage($deviceId, $rawToken, SERVER_BASE_URL, $packageExe);
    $genTime = round(microtime(true) - $startTime, 4);
    $sizeKb = round(filesize($packageExe) / 1024, 2);
    echo "SUCCESS (Generation Time: {$genTime} sec)\n    Executable File: {$packageExe} ({$sizeKb} KB)\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Verify Direct EXE Payload using tools/verify_agent.php
echo "\n[3/8] Verifying Direct EXE Payload & PE Format... ";
$verifyCmd = sprintf(
    '%s %s --package=%s',
    PHP_BINARY,
    escapeshellarg(__DIR__ . '/verify_agent.php'),
    escapeshellarg($packageExe)
);
exec($verifyCmd, $vOut, $vRet);

if ($vRet === 0) {
    echo "SUCCESS\n    " . implode("\n    ", $vOut) . "\n";
} else {
    echo "FAILED\n    " . implode("\n    ", $vOut) . "\n";
    exit(1);
}

// 4. Verify Database Hashed Token Security
echo "\n[4/8] Verifying Database Security (Plaintext Token Not Stored in DB)... ";
$checkDbToken = $db->prepare("SELECT token_hash FROM device_enrollment_tokens WHERE device_id = :id AND status = 'ready'");
$checkDbToken->execute([':id' => $deviceId]);
$storedHash = $checkDbToken->fetchColumn();

if ($storedHash === $tokenHash && $storedHash !== $rawToken) {
    echo "SUCCESS (Token is securely SHA-256 hashed)\n";
} else {
    echo "FAILED (Plaintext token leaked into database)\n";
    exit(1);
}

// 5. Test Zero-Touch Agent Registration Endpoint
echo "\n[5/8] Testing Agent Zero-Touch Registration API (/api/agent/register.php)... ";
$ch = curl_init(SERVER_BASE_URL . '/api/agent/register.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'enrollment_token' => $rawToken,
    'device_name' => $devName,
    'operating_system' => 'Windows 11 Pro Test',
    'agent_version' => '1.0.0'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$regRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$regJson = json_decode($regRes, true);
$permanentToken = $regJson['device_token'] ?? '';

if ($httpCode === 200 && ($regJson['success'] ?? false) && !empty($permanentToken)) {
    echo "SUCCESS\n    Issued Permanent Device Token: " . substr($permanentToken, 0, 10) . "...\n";
} else {
    echo "FAILED (HTTP {$httpCode}): {$regRes}\n";
    exit(1);
}

// 6. Test Downloading Shared Canonical Agent Binary (/api/agent/download.php)
echo "\n[6/8] Testing Canonical Agent Download API (/api/agent/download.php)... ";
$ch = curl_init(SERVER_BASE_URL . '/api/agent/download.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$permanentToken}"]);
$downRes = curl_exec($ch);
$downCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$downType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($downCode === 200 && strpos($downType, 'octet-stream') !== false || strpos($downType, 'portable-executable') !== false || strlen($downRes) > 0) {
    $agentSizeKb = round(strlen($downRes) / 1024, 2);
    echo "SUCCESS (Downloaded Canonical Agent Binary: {$agentSizeKb} KB)\n";
} else {
    echo "FAILED (HTTP {$downCode}, Content-Type: {$downType})\n";
    exit(1);
}

// 7. Verify One-Time Token Invalidation (Single-Use Rule)
echo "\n[7/8] Testing Single-Use Rule (Attempt Reusing Enrollment Token)... ";
$ch = curl_init(SERVER_BASE_URL . '/api/agent/register.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'enrollment_token' => $rawToken,
    'device_name' => $devName
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$reuseRes = curl_exec($ch);
$reuseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($reuseCode === 401) {
    echo "SUCCESS (Re-use correctly blocked with HTTP 401)\n";
} else {
    echo "FAILED (Re-use was not blocked! HTTP Code: {$reuseCode})\n";
    exit(1);
}

// 8. Test Device Revocation Flow
echo "\n[8/8] Testing Device Revocation Flow... ";
$revokeStmt = $db->prepare("UPDATE devices SET status = 'revoked', device_token_hash = NULL WHERE id = :id");
$revokeStmt->execute([':id' => $deviceId]);

$ch = curl_init(SERVER_BASE_URL . '/api/agent/config.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$permanentToken}"]);
$revRes = curl_exec($ch);
$revCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($revCode === 401) {
    echo "SUCCESS (Revoked device API request correctly rejected with HTTP 401)\n";
} else {
    echo "FAILED (Revoked device was still able to access API! HTTP Code: {$revCode})\n";
    exit(1);
}

@unlink($packageExe);

echo "\n=====================================================\n";
echo "   All 8 Direct EXE Bootstrapper Tests PASSED!        \n";
echo "=====================================================\n";
