<?php
// api/agent/config.php - Fetch Agent Configuration Settings

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$device = authenticateAgentDevice();
if (!$device) {
    respondJson(['success' => false, 'error' => 'Unauthorized device authentication token'], 401);
}

$db = getDbConnection();

// Fetch device monitor settings
$stmt = $db->prepare("SELECT * FROM monitor_settings WHERE device_id = :device_id");
$stmt->execute([':device_id' => $device['id']]);
$settings = $stmt->fetch();

if (!$settings) {
    // Insert default settings if none exist
    $insertStmt = $db->prepare("INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality, screenshot_width, screenshot_height, idle_threshold_seconds) VALUES (:device_id, 1, 1, 30, 70, 0, 0, 120)");
    $insertStmt->execute([':device_id' => $device['id']]);
    
    $stmt->execute([':device_id' => $device['id']]);
    $settings = $stmt->fetch();
}

// Update last_seen_at
$updateSeen = $db->prepare("UPDATE devices SET last_seen_at = NOW() WHERE id = :id");
$updateSeen->execute([':id' => $device['id']]);

respondJson([
    'success' => true,
    'device_id' => (int)$device['id'],
    'config' => [
        'monitoring_enabled' => (bool)$settings['monitoring_enabled'],
        'screenshot_enabled' => (bool)$settings['screenshot_enabled'],
        'screenshot_interval_seconds' => (int)$settings['screenshot_interval_seconds'],
        'screenshot_quality' => (int)$settings['screenshot_quality'],
        'screenshot_width' => (int)$settings['screenshot_width'],
        'screenshot_height' => (int)$settings['screenshot_height'],
        'idle_threshold_seconds' => (int)$settings['idle_threshold_seconds'],
        'config_version' => strtotime($settings['updated_at'])
    ]
]);
