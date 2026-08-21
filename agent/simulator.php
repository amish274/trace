<?php
// agent/simulator.php - Development CLI Windows Agent Simulator with Bootstrap Support

require_once __DIR__ . '/../config/config.php';

echo "=====================================================\n";
echo "   TeamTrace Windows Agent CLI Simulator (DEV ONLY)  \n";
echo "=====================================================\n\n";

$baseUrl = SERVER_BASE_URL;
$deviceToken = 'demo_token_123456789012345678901234';

// Check for local bootstrap.json
$bootstrapFile = __DIR__ . '/bootstrap.json';
if (file_exists($bootstrapFile)) {
    echo "Found local bootstrap.json file!\n";
    $bData = json_decode(file_get_contents($bootstrapFile), true);
    if (!empty($bData['enrollment_token'])) {
        echo "Attempting zero-touch enrollment using enrollment token...\n";
        $regUrl = rtrim($bData['server_base_url'] ?? $baseUrl, '/') . '/api/agent/register.php';
        
        $ch = curl_init($regUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'enrollment_token' => $bData['enrollment_token'],
            'device_name' => $bData['device_name'] ?? 'SIMULATOR-PC',
            'operating_system' => 'Windows 11 Pro',
            'agent_version' => '1.0.0'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $regRes = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if ($regRes['success'] ?? false) {
            $deviceToken = $regRes['device_token'];
            echo "SUCCESS: Registered device! Permanent Device Token obtained.\n\n";
        } else {
            echo "Notice: Bootstrap enrollment failed: " . ($regRes['error'] ?? 'Unknown') . ". Falling back to demo seed token.\n\n";
        }
    }
}

echo "Server URL: {$baseUrl}\n";
echo "Simulating Employee Device with Token: " . substr($deviceToken, 0, 10) . "...\n";
echo "Press Ctrl+C to terminate simulator.\n\n";

function postApi($endpoint, $data = null, $token = '') {
    global $baseUrl;
    $url = $baseUrl . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $headers = [];
    if (!empty($token)) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    if ($data !== null) {
        if (is_array($data) && isset($data['screenshot'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function getApi($endpoint, $token = '') {
    global $baseUrl;
    $url = $baseUrl . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if (!empty($token)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

$lastScreenshotTime = 0;

while (true) {
    // 1. Poll Config
    $cfg = getApi('/api/agent/config.php', $deviceToken);
    $interval = 30;
    $quality = 70;
    $idleThresh = 120;
    $enabled = true;

    if ($cfg['success'] ?? false) {
        $interval = (int)($cfg['config']['screenshot_interval_seconds'] ?? 30);
        $quality = (int)($cfg['config']['screenshot_quality'] ?? 70);
        $idleThresh = (int)($cfg['config']['idle_threshold_seconds'] ?? 120);
        $enabled = (bool)($cfg['config']['screenshot_enabled'] ?? true);
    } else {
        echo "[" . date('H:i:s') . "] API Authorization Failed / Revoked!\n";
        sleep(5);
        continue;
    }

    // 2. Simulate Active or Idle state
    $idleSec = rand(0, 150);
    $active = $idleSec < $idleThresh;
    $statusStr = $active ? 'ACTIVE' : 'IDLE';

    // 3. Heartbeat
    $hb = postApi('/api/agent/heartbeat.php', [
        'agent_version' => '1.0.0-SIMULATOR',
        'active' => $active ? 1 : 0,
        'idle_seconds' => $idleSec
    ], $deviceToken);

    echo "[" . date('H:i:s') . "] Heartbeat sent | Status: {$statusStr} ({$idleSec}s idle) | Server Screenshot Interval: {$interval}s\n";

    // 4. Screenshot Capture Simulation based on dynamic interval
    if ($enabled && (time() - $lastScreenshotTime) >= $interval) {
        $lastScreenshotTime = time();

        // Create dynamic fake desktop screenshot with timestamp watermark
        $im = imagecreatetruecolor(800, 450);
        $bg = imagecolorallocate($im, 15, 23, 42); // dark background
        $txt = imagecolorallocate($im, 56, 189, 248); // blue text
        $red = imagecolorallocate($im, 239, 68, 68);
        $green = imagecolorallocate($im, 34, 197, 94);

        imagefill($im, 0, 0, $bg);
        imagestring($im, 5, 20, 20, "SIMULATED WINDOWS DESKTOP SCREENSHOT", $txt);
        imagestring($im, 5, 20, 50, "Simulated Device | Token: " . substr($deviceToken, 0, 10), $txt);
        imagestring($im, 5, 20, 80, "Captured At: " . date('Y-m-d H:i:s'), $txt);
        imagestring($im, 5, 20, 110, "Status: {$statusStr} (Idle: {$idleSec}s)", $active ? $green : $red);
        imagestring($im, 4, 20, 150, "Interval: {$interval}s | Quality: {$quality}%", $txt);

        $tmpFile = sys_get_temp_dir() . '/sim_shot_' . time() . '.jpg';
        imagejpeg($im, $tmpFile, $quality);
        imagedestroy($im);

        $cFile = new CURLFile($tmpFile, 'image/jpeg', 'sim_shot.jpg');
        $upRes = postApi('/api/agent/screenshot.php', [
            'screenshot' => $cFile,
            'activity_status' => $statusStr,
            'idle_seconds' => $idleSec,
            'captured_at' => date('Y-m-d H:i:s')
        ], $deviceToken);

        @unlink($tmpFile);

        if ($upRes['success'] ?? false) {
            echo "    -> SIMULATED SCREENSHOT UPLOADED SUCCESSFULLY! (Interval: {$interval}s)\n";
        } else {
            echo "    -> Screenshot upload error: " . ($upRes['error'] ?? 'Unknown') . "\n";
        }
    }

    sleep(3); // Tick loop every 3 seconds
}
