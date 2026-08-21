<?php
// tools/generate_agent.php - Canonical Direct EXE Agent Bootstrapper Generator

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Generate a device-specific Windows bootstrapper executable in-process.
 * 
 * @param int $deviceId Device ID from database
 * @param string $token Plaintext 32-byte enrollment token
 * @param string $serverUrl Base URL of the TeamTrace server
 * @param string $outputPath Optional path to write output EXE
 * @return string Returns path to generated executable file
 * @throws Exception On invalid parameters or file write errors
 */
function generateAgentPackage(int $deviceId, string $token, string $serverUrl = SERVER_BASE_URL, string $outputPath = ''): string {
    if ($deviceId <= 0) {
        throw new Exception("Invalid device ID parameter.");
    }
    if (empty($token)) {
        throw new Exception("Missing enrollment token parameter.");
    }

    $db = getDbConnection();
    $stmt = $db->prepare("SELECT d.*, e.name as employee_name FROM devices d JOIN employees e ON d.employee_id = e.id WHERE d.id = :id");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        throw new Exception("Device ID {$deviceId} not found in database.");
    }

    // Locate precompiled base TeamTraceBootstrap.exe
    $bootstrapBase = __DIR__ . '/../build/windows/TeamTraceBootstrap.exe';
    if (!file_exists($bootstrapBase) || filesize($bootstrapBase) <= 0) {
        throw new Exception("Base TeamTraceBootstrap.exe binary not found at {$bootstrapBase}.");
    }

    $deviceNameSanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['device_name']);
    if (empty($outputPath)) {
        $outputPath = __DIR__ . "/../storage/packages/System-Utility-{$deviceNameSanitized}.exe";
    }

    $parentDir = dirname($outputPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }

    // Create embedded payload
    $bootstrapData = [
        'server_base_url' => rtrim($serverUrl, '/'),
        'enrollment_token' => $token,
        'device_id' => (int)$deviceId,
        'device_name' => $device['device_name'],
        'employee_name' => $device['employee_name'],
        'agent_version' => $device['agent_version'] ?: '1.0.0',
        'created_at' => date('Y-m-d H:i:s')
    ];

    $bootstrapJson = json_encode($bootstrapData, JSON_UNESCAPED_SLASHES);
    $payloadBlock = "\n###TEAMTRACE_BOOTSTRAP_START###\n" . $bootstrapJson . "\n###TEAMTRACE_BOOTSTRAP_END###\n";

    // Read base executable bytes and append overlay payload
    $baseBytes = file_get_contents($bootstrapBase);
    if ($baseBytes === false) {
        throw new Exception("Failed reading base bootstrapper binary.");
    }

    $finalExeBytes = $baseBytes . $payloadBlock;
    if (file_put_contents($outputPath, $finalExeBytes) === false) {
        throw new Exception("Failed writing device bootstrapper executable to {$outputPath}");
    }

    chmod($outputPath, 0755);

    // Update package status in database
    $updatePackage = $db->prepare("UPDATE devices SET package_status = 'generated' WHERE id = :id");
    $updatePackage->execute([':id' => $deviceId]);

    return $outputPath;
}

// CLI Execution Entry Point
if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $options = getopt("", ["device-id:", "token:", "server-url:", "output:"]);
    $deviceId = (int)($options['device-id'] ?? 0);
    $token = trim($options['token'] ?? '');
    $serverUrl = trim($options['server-url'] ?? SERVER_BASE_URL);
    $outputPath = trim($options['output'] ?? '');

    if ($deviceId <= 0 || empty($token)) {
        die("Usage: php tools/generate_agent.php --device-id=123 --token=\"RAW_TOKEN\" [--server-url=\"https://...\"] [--output=\"/path/to/output.exe\"]\n");
    }

    try {
        $result = generateAgentPackage($deviceId, $token, $serverUrl, $outputPath);
        echo "SUCCESS: Created device bootstrap executable at {$result} (" . filesize($result) . " bytes)\n";
    } catch (Exception $e) {
        die("Error: " . $e->getMessage() . "\n");
    }
}
