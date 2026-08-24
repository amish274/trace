<?php
// tools/test_authenticode_readiness.php - Authenticode & Enterprise Compatibility Test Suite

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/generate_agent.php';

echo "=====================================================\n";
echo "   TeamTrace Authenticode & AV Compatibility Suite   \n";
echo "=====================================================\n\n";

$db = getDbConnection();

// 1. Audit Assembly Metadata in .csproj files
echo "[1/6] Auditing Enterprise Publisher Metadata (.csproj)... ";
$bootCsproj = file_get_contents(__DIR__ . '/../bootstrapper/TeamTraceBootstrap.csproj');
$agentCsproj = file_get_contents(__DIR__ . '/../agent/MonitorAgent.csproj');

if (strpos($bootCsproj, '<Company>TeamTrace Software</Company>') === false ||
    strpos($bootCsproj, '<Product>TeamTrace Employee Monitoring</Product>') === false) {
    die("FAILED: TeamTraceBootstrap.csproj missing enterprise publisher metadata!\n");
}

if (strpos($agentCsproj, '<Company>TeamTrace Software</Company>') === false ||
    strpos($agentCsproj, '<Product>TeamTrace Employee Monitoring</Product>') === false) {
    die("FAILED: MonitorAgent.csproj missing enterprise publisher metadata!\n");
}
echo "SUCCESS (Verified Publisher: TeamTrace Software, Product: TeamTrace Employee Monitoring)\n";

// 2. Seed Test Employee and Device
echo "[2/6] Seeding Test Device for Package Generation... ";
$stmtEmp = $db->prepare("INSERT INTO employees (name, email) VALUES ('Auth Test User', 'authtest@teamtrace.local')");
$stmtEmp->execute();
$empId = (int)$db->lastInsertId();

$stmtDev = $db->prepare("INSERT INTO devices (employee_id, device_name, status) VALUES (:emp_id, 'AUTH-TEST-PC', 'active')");
$stmtDev->execute([':emp_id' => $empId]);
$deviceId = (int)$db->lastInsertId();

$token = bin2hex(random_bytes(16));
echo "SUCCESS (Created Device ID: {$deviceId})\n";

// 3. Test Direct EXE Overlay Package Generation
echo "[3/6] Testing Direct EXE Overlay Package Generation... ";
$exePath = generateAgentPackage($deviceId, $token, "https://ethnicboost.com/Trace", "", "exe");
if (!file_exists($exePath) || filesize($exePath) <= 0) {
    die("FAILED: Failed creating direct EXE package.\n");
}
echo "SUCCESS (" . basename($exePath) . ", " . round(filesize($exePath) / 1024, 2) . " KB)\n";

// 4. Test Authenticode-Safe ZIP Package Generation (Untouched Signed Binary + Sidecar)
echo "[4/6] Testing Authenticode-Safe ZIP Bundle Generation... ";
$zipPath = generateAgentPackage($deviceId, $token, "https://ethnicboost.com/Trace", "", "zip");
if (!file_exists($zipPath) || filesize($zipPath) <= 0) {
    die("FAILED: Failed creating ZIP bundle package.\n");
}

// Verify that the executable inside the ZIP bundle is 100% byte-identical to base TeamTraceBootstrap.exe
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    die("FAILED: Cannot open created ZIP archive.\n");
}

$zippedExeBytes = $zip->getFromName('TeamTraceBootstrap.exe');
$baseExeBytes = file_get_contents(__DIR__ . '/../build/windows/TeamTraceBootstrap.exe');
$zip->close();

if ($zippedExeBytes !== $baseExeBytes) {
    die("FAILED: Executable inside ZIP bundle was modified! Authenticode signature would be invalidated.\n");
}
echo "SUCCESS (Executable inside ZIP is 100% byte-identical to base binary; Authenticode signature preserved!)\n";

// 5. Test Verification Engine on Both Packages
echo "[5/6] Verifying Packages via tools/verify_agent.php... ";
$cmdExe = sprintf("%s %s --package=%s", PHP_BINARY, escapeshellarg(__DIR__ . '/verify_agent.php'), escapeshellarg($exePath));
exec($cmdExe, $outExe, $codeExe);
if ($codeExe !== 0) {
    die("FAILED: verify_agent.php failed for EXE package: " . implode("\n", $outExe) . "\n");
}

$cmdZip = sprintf("%s %s --package=%s", PHP_BINARY, escapeshellarg(__DIR__ . '/verify_agent.php'), escapeshellarg($zipPath));
exec($cmdZip, $outZip, $codeZip);
if ($codeZip !== 0) {
    die("FAILED: verify_agent.php failed for ZIP package: " . implode("\n", $outZip) . "\n");
}
echo "SUCCESS (Both EXE overlay and ZIP bundle passed verification!)\n";

// 6. Cleanup Test Fixture
echo "[6/6] Cleaning up test fixtures... ";
$db->exec("DELETE FROM devices WHERE id = {$deviceId}");
$db->exec("DELETE FROM employees WHERE id = {$empId}");
@unlink($exePath);
@unlink($zipPath);
echo "SUCCESS\n\n";

echo "=====================================================\n";
echo "   All Authenticode & AV Readiness Tests PASSED!     \n";
echo "=====================================================\n";
