<?php
// tools/test_bulk_screenshot_deletion.php - Automated Regression Test Suite for Bulk Screenshot Deletion & IST Timezone

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

echo "=====================================================\n";
echo "   TeamTrace Bulk Screenshot Deletion Test Suite     \n";
echo "=====================================================\n\n";

$db = getDbConnection();
$storageDir = getScreenshotStorageDir();

// Ensure test employee and test device exist
$db->exec("INSERT IGNORE INTO employees (id, name, email) VALUES (999, 'Test Employee Bulk', 'bulk_test@teamtrace.local')");
$db->exec("INSERT IGNORE INTO devices (id, employee_id, device_name, status) VALUES (999, 999, 'BULK-TEST-PC', 'active')");

// Test 1: Verify Timezone Configuration
echo "[1/10] Verifying Asia/Kolkata Application Timezone... ";
$tz = date_default_timezone_get();
if ($tz !== 'Asia/Kolkata') {
    die("FAILED: Expected timezone Asia/Kolkata, got {$tz}\n");
}
echo "SUCCESS (Timezone: {$tz})\n";

// Helper function to insert mock screenshot record and file
function createMockScreenshot($deviceId, $capturedAt, $relPath) {
    global $db, $storageDir;
    $fullPath = $storageDir . '/' . ltrim($relPath, '/');
    $dir = dirname($fullPath);
    if (!file_exists($dir)) @mkdir($dir, 0755, true);
    file_put_contents($fullPath, "MOCK_JPEG_DATA_" . microtime(true));

    $stmt = $db->prepare("INSERT INTO screenshots (device_id, captured_at, activity_status, relative_path, file_size, width, height) VALUES (:device_id, :captured_at, 'ACTIVE', :rel_path, 1024, 1920, 1080)");
    $stmt->execute([
        ':device_id' => $deviceId,
        ':captured_at' => $capturedAt,
        ':rel_path' => $relPath
    ]);
    return ['id' => $db->lastInsertId(), 'full_path' => $fullPath];
}

// Clean any previous test screenshots for device 999
$db->exec("DELETE FROM screenshots WHERE device_id = 999");

// Insert mock screenshots across different dates
$shot1 = createMockScreenshot(999, '2026-08-01 10:00:00', 'test_bulk/2026-08-01_1.jpg');
$shot2 = createMockScreenshot(999, '2026-08-01 14:00:00', 'test_bulk/2026-08-01_2.jpg');
$shot3 = createMockScreenshot(999, '2026-08-05 11:00:00', 'test_bulk/2026-08-05_1.jpg'); // Wednesday in 1st week of Aug
$shot4 = createMockScreenshot(999, '2026-08-10 09:00:00', 'test_bulk/2026-08-10_1.jpg'); // Next week
$shot5 = createMockScreenshot(999, '2026-08-15 16:00:00', 'test_bulk/2026-08-15_1.jpg');

echo "[2/10] Seeded Mock Screenshots for Isolation Testing... SUCCESS (Created 5 test records)\n";

// Test 2: Preview Delete by Day
echo "[3/10] Testing Preview Delete by Day (2026-08-01)... ";
$prevDay = previewScreenshotDeletion('day', ['date' => '2026-08-01'], 999);
if ($prevDay['total_db_records'] !== 2 || $prevDay['physical_files_found'] !== 2) {
    die("FAILED: Expected 2 db records and 2 physical files for 2026-08-01. Got DB: {$prevDay['total_db_records']}, Files: {$prevDay['physical_files_found']}\n");
}
echo "SUCCESS (Count: 2)\n";

// Test 3: Execute Delete by Day
echo "[4/10] Testing Execute Delete by Day (2026-08-01)... ";
$execDay = executeScreenshotDeletion('day', ['date' => '2026-08-01'], 999);
if ($execDay['deleted_db_records'] !== 2 || $execDay['deleted_physical_files'] !== 2) {
    die("FAILED: Expected 2 records & files deleted. Got DB: {$execDay['deleted_db_records']}, Files: {$execDay['deleted_physical_files']}\n");
}
if (file_exists($shot1['full_path']) || file_exists($shot2['full_path'])) {
    die("FAILED: Physical files still exist on disk after deletion!\n");
}
echo "SUCCESS (2 records & physical files unlinked)\n";

// Test 4: Delete by Week
echo "[5/10] Testing Delete by Week (2026-08-05 Wednesday -> Week 3 Aug-9 Aug)... ";
$prevWeek = previewScreenshotDeletion('week', ['date' => '2026-08-05'], 999);
if ($prevWeek['total_db_records'] !== 1) {
    die("FAILED: Expected 1 record for week of 2026-08-05. Got: {$prevWeek['total_db_records']}\n");
}
$execWeek = executeScreenshotDeletion('week', ['date' => '2026-08-05'], 999);
if ($execWeek['deleted_db_records'] !== 1 || file_exists($shot3['full_path'])) {
    die("FAILED: Week deletion failed.\n");
}
echo "SUCCESS\n";

// Test 5: Delete by Custom Range
echo "[6/10] Testing Delete by Custom Date Range (2026-08-10 to 2026-08-15)... ";
$prevRange = previewScreenshotDeletion('range', ['start_date' => '2026-08-10', 'end_date' => '2026-08-15'], 999);
if ($prevRange['total_db_records'] !== 2) {
    die("FAILED: Expected 2 records in date range. Got: {$prevRange['total_db_records']}\n");
}
$execRange = executeScreenshotDeletion('range', ['start_date' => '2026-08-10', 'end_date' => '2026-08-15'], 999);
if ($execRange['deleted_db_records'] !== 2 || file_exists($shot4['full_path']) || file_exists($shot5['full_path'])) {
    die("FAILED: Custom date range deletion failed.\n");
}
echo "SUCCESS\n";

// Test 6: Invalid Range Validation
echo "[7/10] Testing Invalid Date Range Validation (start > end)... ";
try {
    resolveScreenshotDateRange('range', ['start_date' => '2026-08-20', 'end_date' => '2026-08-10']);
    die("FAILED: Expected InvalidArgumentException for invalid start > end dates.\n");
} catch (InvalidArgumentException $ex) {
    echo "SUCCESS (Caught: " . $ex->getMessage() . ")\n";
}

// Test 7: Path Traversal Security Audit
echo "[8/10] Testing Path Traversal & Out-of-Bounds Security... ";
$dummyOutsideFile = __DIR__ . '/../storage/dummy_sensitive.txt';
file_put_contents($dummyOutsideFile, "SENSITIVE_DATA");

$stmt = $db->prepare("INSERT INTO screenshots (device_id, captured_at, activity_status, relative_path, file_size, width, height) VALUES (999, '2026-08-20 12:00:00', 'ACTIVE', '../dummy_sensitive.txt', 10, 100, 100)");
$stmt->execute();
$badId = $db->lastInsertId();

$execBad = executeScreenshotDeletion('selected', ['ids' => [$badId]], 999);
if (file_exists($dummyOutsideFile)) {
    echo "SUCCESS (Outside file protected from traversal!)\n";
    @unlink($dummyOutsideFile);
} else {
    die("FAILED: Path traversal succeeded and deleted file outside storage!\n");
}

// Test 8: CSRF Token Verification Test
echo "[9/10] Testing CSRF Token Verification... ";
$_SESSION['csrf_token'] = 'VALID_SECRET_TOKEN_123';
if (verifyCsrfToken('INVALID_TOKEN')) {
    die("FAILED: Invalid CSRF token was accepted!\n");
}
if (!verifyCsrfToken('VALID_SECRET_TOKEN_123')) {
    die("FAILED: Valid CSRF token was rejected!\n");
}
echo "SUCCESS\n";

// Test 9: Cleanup mock records
$db->exec("DELETE FROM screenshots WHERE device_id = 999");
$db->exec("DELETE FROM devices WHERE id = 999");
$db->exec("DELETE FROM employees WHERE id = 999");

echo "[10/10] Cleaned test environment... SUCCESS\n\n";

echo "=====================================================\n";
echo "   All Bulk Screenshot Deletion Tests PASSED!        \n";
echo "=====================================================\n";
