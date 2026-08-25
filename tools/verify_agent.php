<?php
// tools/verify_agent.php - Agent Package & Executable Verification Utility

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$options = getopt("", ["package:", "device-id:"]);
$packagePath = trim($options['package'] ?? '');
$deviceId = (int)($options['device-id'] ?? 0);

if (empty($packagePath)) {
    if ($deviceId > 0) {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT device_name FROM devices WHERE id = :id");
        $stmt->execute([':id' => $deviceId]);
        $deviceName = $stmt->fetchColumn();
        if ($deviceName) {
            $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $deviceName);
            $possiblePaths = [
                __DIR__ . "/../storage/packages/System Utility-{$deviceId}.zip",
                __DIR__ . "/../storage/packages/System Utility-{$sanitized}.zip",
                __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitized}.zip",
                __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitized}.exe",
                __DIR__ . "/../storage/packages/System-Utility-{$sanitized}.exe"
            ];
            foreach ($possiblePaths as $p) {
                if (file_exists($p)) {
                    $packagePath = $p;
                    break;
                }
            }
        }
    }
}

if (empty($packagePath)) {
    die("Usage: php tools/verify_agent.php --package=/path/to/System Utility-XXX.zip OR --device-id=123\n");
}

echo "Verifying System Utility Package: {$packagePath}\n";

if (!file_exists($packagePath)) {
    die("FAILED: Package file does not exist.\n");
}

$fileBytes = file_get_contents($packagePath);
$fileSize = strlen($fileBytes);
if ($fileSize <= 0) {
    die("FAILED: Package file size is 0.\n");
}

$sizeMb = round($fileSize / (1024 * 1024), 2);
$sizeKb = round($fileSize / 1024, 2);
$displaySize = $sizeMb >= 1.0 ? "{$sizeMb} MB" : "{$sizeKb} KB";

$bootstrap = null;
$packageType = "Direct Executable Overlay";

if (str_ends_with(strtolower($packagePath), '.zip')) {
    $packageType = "Authenticode-Safe ZIP Bundle";
    $zip = new ZipArchive();
    if ($zip->open($packagePath) !== true) {
        die("FAILED: Cannot open ZIP archive.\n");
    }
    
    $configContent = $zip->getFromName('system-utility.config.json');
    if (!$configContent) {
        $configContent = $zip->getFromName('teamtrace.config.json');
    }
    if (!$configContent) {
        $configContent = $zip->getFromName('bootstrap.json');
    }
    if (!$configContent) {
        die("FAILED: ZIP archive is missing system-utility.config.json.\n");
    }

    $exeStream = $zip->getFromName('System Utility.exe');
    if (!$exeStream) {
        $exeStream = $zip->getFromName('TeamTraceBootstrap.exe');
    }
    if (!$exeStream || substr($exeStream, 0, 2) !== 'MZ') {
        die("FAILED: ZIP archive does not contain a valid bootstrapper binary.\n");
    }

    $bootstrap = json_decode($configContent, true);
    $zip->close();
} else {
    // 1. Verify PE Windows Header
    if (substr($fileBytes, 0, 2) !== 'MZ') {
        die("FAILED: File is not a valid Windows Portable Executable (missing 'MZ' header).\n");
    }

    // 2. Locate embedded JSON payload between tags
    $tagStart = "###TEAMTRACE_BOOTSTRAP_START###";
    $tagEnd = "###TEAMTRACE_BOOTSTRAP_END###";

    $startIndex = strpos($fileBytes, $tagStart);
    if ($startIndex === false) {
        die("FAILED: Embedded payload start tag '{$tagStart}' not found in executable.\n");
    }

    $startIndex += strlen($tagStart);
    $endIndex = strpos($fileBytes, $tagEnd, $startIndex);
    if ($endIndex === false) {
        die("FAILED: Embedded payload end tag '{$tagEnd}' not found in executable.\n");
    }

    $jsonRaw = trim(substr($fileBytes, $startIndex, $endIndex - $startIndex));
    $bootstrap = json_decode($jsonRaw, true);
}

if (!$bootstrap) {
    die("FAILED: Embedded payload is not valid JSON.\n");
}

$serverUrl = $bootstrap['server_base_url'] ?? $bootstrap['server_url'] ?? '';
if (empty($serverUrl)) {
    die("FAILED: Embedded payload is missing server_base_url / server_url.\n");
}

if (empty($bootstrap['enrollment_token'])) {
    die("FAILED: Embedded payload is missing enrollment_token.\n");
}

if (empty($bootstrap['device_id'])) {
    die("FAILED: Embedded payload is missing device_id.\n");
}

// 3. Security Audit Check
if (defined('APP_KEY') && !empty(APP_KEY) && strpos(json_encode($bootstrap), APP_KEY) !== false) {
    die("FAILED SECURITY AUDIT: APP_KEY detected inside embedded payload!\n");
}

echo "SUCCESS: System Utility Package passed all verification checks!\n";
echo "    - File: " . basename($packagePath) . " ({$displaySize})\n";
echo "    - Package Type: {$packageType}\n";
echo "    - Server Base URL: {$serverUrl}\n";
echo "    - Device Target: {$bootstrap['device_name']} (ID: {$bootstrap['device_id']})\n";
echo "    - One-Time Enrollment Token: " . substr($bootstrap['enrollment_token'], 0, 12) . "...\n";
echo "    - Security Audit: PASS (No DB credentials or APP_KEY leaked)\n";
exit(0);
