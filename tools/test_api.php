<?php
// tools/test_api.php - Automated Comprehensive API Verification Tool

require_once __DIR__ . '/../config/config.php';

echo "=====================================================\n";
echo "    TeamTrace Employee Monitor - API Diagnostic Test \n";
echo "=====================================================\n\n";

$baseUrl = SERVER_BASE_URL;
echo "Target Base URL: {$baseUrl}\n";

function makeRequest($url, $method = 'GET', $headers = [], $postData = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Disable SSL verify for local testing if self-signed
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($postData !== null) {
        if (is_array($postData) && isset($postData['screenshot'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($postData) ? $postData : json_encode($postData));
            $headers[] = 'Content-Type: application/json';
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// 1. Test Health Check
echo "[1/6] Testing Health Endpoint (/health.php)... ";
$res = makeRequest($baseUrl . '/health.php');
if ($res['code'] === 200) {
    echo "SUCCESS (HTTP 200)\n    Response: " . trim($res['response']) . "\n";
} else {
    echo "FAILED (HTTP {$res['code']}) Error: {$res['error']}\n";
}

// 2. Test Device Registration
echo "\n[2/6] Testing Agent Enrollment API (/api/agent/register.php)... ";
$regRes = makeRequest($baseUrl . '/api/agent/register.php', 'POST', [], [
    'enrollment_token' => 'ENROLL-DEMO-2026',
    'device_name' => 'TEST-SUITE-PC',
    'operating_system' => 'Windows 11 Pro',
    'agent_version' => '1.0.0'
]);

$deviceToken = '';
if ($regRes['code'] === 200) {
    $json = json_decode($regRes['response'], true);
    if ($json['success'] ?? false) {
        $deviceToken = $json['device_token'];
        echo "SUCCESS (Registered token hash updated)\n";
    } else {
        echo "FAILED - " . ($json['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "FAILED (HTTP {$regRes['code']})\n";
}

// Fallback to seed token if enrollment token already consumed
if (empty($deviceToken)) {
    $deviceToken = 'demo_token_123456789012345678901234';
    echo "    Notice: Using default demo seed token: {$deviceToken}\n";
}

$authHeaders = ["Authorization: Bearer {$deviceToken}"];

// 3. Test Config Endpoint
echo "\n[3/6] Testing Agent Config API (/api/agent/config.php)... ";
$cfgRes = makeRequest($baseUrl . '/api/agent/config.php', 'GET', $authHeaders);
if ($cfgRes['code'] === 200) {
    echo "SUCCESS\n    Config: " . trim($cfgRes['response']) . "\n";
} else {
    echo "FAILED (HTTP {$cfgRes['code']})\n";
}

// 4. Test Heartbeat Endpoint
echo "\n[4/6] Testing Agent Heartbeat API (/api/agent/heartbeat.php)... ";
$hbRes = makeRequest($baseUrl . '/api/agent/heartbeat.php', 'POST', $authHeaders, [
    'agent_version' => '1.0.0',
    'active' => 1,
    'idle_seconds' => 5
]);
if ($hbRes['code'] === 200) {
    echo "SUCCESS\n";
} else {
    echo "FAILED (HTTP {$hbRes['code']})\n";
}

// 5. Test Activity Endpoint
echo "\n[5/6] Testing Agent Activity API (/api/agent/activity.php)... ";
$actRes = makeRequest($baseUrl . '/api/agent/activity.php', 'POST', $authHeaders, [
    'activity_status' => 'ACTIVE',
    'idle_seconds' => 12
]);
if ($actRes['code'] === 200) {
    echo "SUCCESS\n";
} else {
    echo "FAILED (HTTP {$actRes['code']})\n";
}

// 6. Test Screenshot Upload Endpoint (Create a 1x1 test JPEG image in memory)
echo "\n[6/6] Testing Agent Screenshot Upload API (/api/agent/screenshot.php)... ";
$im = imagecreatetruecolor(100, 100);
$red = imagecolorallocate($im, 255, 0, 0);
imagefill($im, 0, 0, $red);
$tmpImgPath = sys_get_temp_dir() . '/test_shot_' . time() . '.jpg';
imagejpeg($im, $tmpImgPath, 70);
imagedestroy($im);

$cFile = new CURLFile($tmpImgPath, 'image/jpeg', 'test_shot.jpg');
$shotPostData = [
    'screenshot' => $cFile,
    'activity_status' => 'ACTIVE',
    'idle_seconds' => 0
];

$shotRes = makeRequest($baseUrl . '/api/agent/screenshot.php', 'POST', $authHeaders, $shotPostData);
@unlink($tmpImgPath);

if ($shotRes['code'] === 200) {
    echo "SUCCESS\n    Response: " . trim($shotRes['response']) . "\n";
} else {
    echo "FAILED (HTTP {$shotRes['code']}) Response: " . trim($shotRes['response']) . "\n";
}

echo "\n=====================================================\n";
echo "    API Diagnostic Test Completed!\n";
echo "=====================================================\n";
