<?php
// cron/cleanup_screenshots.php - CLI Screenshot Retention Purge Cron Job

if (php_sapi_name() !== 'cli' && empty($_GET['secret_key'])) {
    die("Access denied: CLI execution only.");
}

require_once __DIR__ . '/../includes/db.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting TeamTrace Screenshot Retention Cleanup Job...\n";

$db = getDbConnection();

// Fetch global retention days
$stmt = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'retention_days'");
$retentionDays = (int)($stmt->fetchColumn() ?: 30);

if ($retentionDays <= 0) {
    echo "Retention set to Never (0 days). Skipping screenshot deletion.\n";
    exit;
}

$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
echo "Retention threshold: {$retentionDays} days (Purging before {$cutoffDate})\n";

// Find screenshots older than cutoff date
$findStmt = $db->prepare("SELECT id, relative_path FROM screenshots WHERE captured_at < :cutoff");
$findStmt->execute([':cutoff' => $cutoffDate]);
$expiredScreenshots = $findStmt->fetchAll();

$deletedFiles = 0;
$failedFiles = 0;
$storageDir = realpath(SCREENSHOT_STORAGE_PATH);

foreach ($expiredScreenshots as $shot) {
    $fullPath = $storageDir . '/' . $shot['relative_path'];
    if (file_exists($fullPath)) {
        if (@unlink($fullPath)) {
            $deletedFiles++;
        } else {
            $failedFiles++;
            echo "Failed to unlink file: {$fullPath}\n";
        }
    }

    // Delete DB record
    $delDb = $db->prepare("DELETE FROM screenshots WHERE id = :id");
    $delDb->execute([':id' => $shot['id']]);
}

echo "[" . date('Y-m-d H:i:s') . "] Cleanup Complete! Removed {$deletedFiles} physical files ({$failedFiles} errors).\n";
