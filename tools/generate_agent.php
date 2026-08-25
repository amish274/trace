<?php
// tools/generate_agent.php - Direct EXE & Authenticode-Safe Zip Package Generator

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

ini_set('memory_limit', '512M');


/**
 * Generate a device-specific Windows bootstrapper package.
 * 
 * Supports both:
 * 1. 'zip' format (default): Authenticode-Safe ZIP bundle containing untouched signed executable + system-utility.config.json.
 * 2. 'exe' format: Single executable with embedded binary overlay (legacy / testing mode).
 * 
 * @param int $deviceId Device ID from database
 * @param string $token Plaintext 32-byte enrollment token
 * @param string $serverUrl Base URL of the TeamTrace server
 * @param string $outputPath Optional path to write output file
 * @param string $format 'zip' (default) or 'exe'
 * @return string Returns path to generated package file
 * @throws Exception On invalid parameters or file write errors
 */
function generateAgentPackage(int $deviceId, string $token, string $serverUrl = SERVER_BASE_URL, string $outputPath = '', string $format = 'zip'): string {
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

    // Ensure production URLs never contain localhost or dev ports in production mode
    $cleanServerUrl = rtrim($serverUrl, '/');
    if (defined('APP_ENV') && APP_ENV === 'production') {
        if (strpos($cleanServerUrl, '127.0.0.1') !== false || strpos($cleanServerUrl, 'localhost') !== false || strpos($cleanServerUrl, ':8888') !== false) {
            $cleanServerUrl = 'https://ethnicboost.com/Trace';
        }
    }

    $format = strtolower($format);
    if (!in_array($format, ['exe', 'zip'])) {
        $format = 'zip';
    }

    if (empty($outputPath)) {
        $ext = $format === 'exe' ? 'exe' : 'zip';
        $outputPath = __DIR__ . "/../storage/packages/System Utility-{$deviceId}.{$ext}";
    }

    $parentDir = dirname($outputPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }

    // Create canonical configuration payload data
    $bootstrapData = [
        'server_base_url' => $cleanServerUrl,
        'server_url' => $cleanServerUrl,
        'enrollment_token' => $token,
        'device_id' => (int)$deviceId,
        'device_name' => $device['device_name'],
        'employee_name' => $device['employee_name'],
        'agent_version' => $device['agent_version'] ?: '1.0.0',
        'created_at' => date('Y-m-d H:i:s')
    ];

    $bootstrapJson = json_encode($bootstrapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($format === 'zip' || str_ends_with(strtolower($outputPath), '.zip')) {
        // Authenticode-Safe ZIP Package Generation (Untouched Signed EXE + system-utility.config.json)
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Failed creating ZIP archive at {$outputPath}");
        }

        // Add signed executable under both 'System Utility.exe' and 'TeamTraceBootstrap.exe'
        $zip->addFile($bootstrapBase, 'System Utility.exe');
        $zip->addFile($bootstrapBase, 'TeamTraceBootstrap.exe');

        // Add canonical system-utility.config.json AND teamtrace.config.json for fallback compatibility
        $zip->addFromString('system-utility.config.json', $bootstrapJson);
        $zip->addFromString('teamtrace.config.json', $bootstrapJson);
        $zip->close();
    } else {
        // Legacy Single-File Binary Overlay Package
        $payloadBlock = "\n###TEAMTRACE_BOOTSTRAP_START###\n" . $bootstrapJson . "\n###TEAMTRACE_BOOTSTRAP_END###\n";
        $baseBytes = file_get_contents($bootstrapBase);
        if ($baseBytes === false) {
            throw new Exception("Failed reading base bootstrapper binary.");
        }

        $finalExeBytes = $baseBytes . $payloadBlock;
        if (file_put_contents($outputPath, $finalExeBytes) === false) {
            throw new Exception("Failed writing device bootstrapper executable to {$outputPath}");
        }
    }

    chmod($outputPath, 0755);

    // Update package status in database
    $updatePackage = $db->prepare("UPDATE devices SET package_status = 'generated' WHERE id = :id");
    $updatePackage->execute([':id' => $deviceId]);

    return $outputPath;
}

// CLI Execution Entry Point
if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $options = getopt("", ["device-id:", "token:", "server-url:", "output:", "format:"]);
    $deviceId = (int)($options['device-id'] ?? 0);
    $token = trim($options['token'] ?? '');
    $serverUrl = trim($options['server-url'] ?? SERVER_BASE_URL);
    $outputPath = trim($options['output'] ?? '');
    $format = trim($options['format'] ?? 'zip');

    if ($deviceId <= 0 || empty($token)) {
        die("Usage: php tools/generate_agent.php --device-id=123 --token=\"RAW_TOKEN\" [--server-url=\"https://...\"] [--format=zip|exe] [--output=\"/path/to/output\"]\n");
    }

    try {
        $result = generateAgentPackage($deviceId, $token, $serverUrl, $outputPath, $format);
        echo "SUCCESS: Created device package at {$result} (" . filesize($result) . " bytes)\n";
    } catch (Exception $e) {
        die("Error: " . $e->getMessage() . "\n");
    }
}
