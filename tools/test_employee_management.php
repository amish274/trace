<?php
// tools/test_employee_management.php - Regression Test Suite for Employee Edit & Removal Engine

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/employee_helper.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

echo "=====================================================\n";
echo "   TeamTrace Employee Management Test Suite          \n";
echo "=====================================================\n\n";

$db = getDbConnection();
$storageDir = getScreenshotStorageDir();

// Helper to seed isolated test employee, device, screenshot, and activity
function seedTestFixture($empSuffix) {
    global $db, $storageDir;
    $empName = "Test Emp " . $empSuffix;
    $empEmail = "test_emp_" . strtolower($empSuffix) . "@teamtrace.local";

    $stmtEmp = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
    $stmtEmp->execute([':name' => $empName, ':email' => $empEmail]);
    $empId = (int)$db->lastInsertId();

    // Create 2 devices for this employee
    $devIds = [];
    $tokenHashes = [];
    for ($i = 1; $i <= 2; $i++) {
        $rawToken = bin2hex(random_bytes(16));
        $hash = hash('sha256', $rawToken);
        $devName = "DEV-{$empSuffix}-{$i}";
        
        $stmtDev = $db->prepare("INSERT INTO devices (employee_id, device_name, device_token_hash, status) VALUES (:emp_id, :dev_name, :hash, 'active')");
        $stmtDev->execute([':emp_id' => $empId, ':dev_name' => $devName, ':hash' => $hash]);
        $devId = (int)$db->lastInsertId();
        $devIds[] = $devId;
        $tokenHashes[] = $rawToken;

        // Monitor Settings
        $db->exec("INSERT INTO monitor_settings (device_id) VALUES ({$devId})");

        // Seed Activity Record
        $db->exec("INSERT INTO activity (device_id, captured_at, activity_status, idle_seconds) VALUES ({$devId}, NOW(), 'ACTIVE', 0)");

        // Seed Screenshot & Physical File
        $relPath = "test_emp_mgmt/emp_{$empId}_dev_{$devId}.jpg";
        $fullPath = $storageDir . '/' . $relPath;
        $dir = dirname($fullPath);
        if (!file_exists($dir)) @mkdir($dir, 0755, true);
        file_put_contents($fullPath, "MOCK_JPEG_CONTENT_" . microtime(true));

        $stmtShot = $db->prepare("INSERT INTO screenshots (device_id, captured_at, activity_status, relative_path, file_size, width, height) VALUES (:dev_id, NOW(), 'ACTIVE', :rel_path, 1024, 1920, 1080)");
        $stmtShot->execute([':dev_id' => $devId, ':rel_path' => $relPath]);
    }

    return [
        'employee_id' => $empId,
        'name' => $empName,
        'email' => $empEmail,
        'device_ids' => $devIds,
        'raw_tokens' => $tokenHashes
    ];
}

// 1. Seed Fixtures
echo "[1/10] Seeding Test Employees & Devices... ";
$targetEmp = seedTestFixture("TARGET_" . time());
$unrelatedEmp = seedTestFixture("UNRELATED_" . time());
echo "SUCCESS (Created Target Emp ID: {$targetEmp['employee_id']}, Unrelated Emp ID: {$unrelatedEmp['employee_id']})\n";

// 2. Test Edit Employee Name and Email
echo "[2/10] Testing Edit Employee Name & Email... ";
$newTitle = "Updated John Doe " . rand(100, 999);
$newEmail = "john_updated_" . rand(100, 999) . "@teamtrace.local";

updateEmployeeDetails($targetEmp['employee_id'], $newTitle, $newEmail);
$fetched = getEmployeeDetails($targetEmp['employee_id']);
if ($fetched['name'] !== $newTitle || $fetched['email'] !== $newEmail) {
    die("FAILED: Expected name '{$newTitle}' and email '{$newEmail}', got '{$fetched['name']}' / '{$fetched['email']}'\n");
}
echo "SUCCESS\n";

// 3. Test Invalid Edit Inputs
echo "[3/10] Testing Edit Input Validation (Empty Name / Invalid Email / Duplicate Email)... ";
try {
    updateEmployeeDetails($targetEmp['employee_id'], '', 'valid@email.com');
    die("FAILED: Allowed empty name!\n");
} catch (InvalidArgumentException $e) {}

try {
    updateEmployeeDetails($targetEmp['employee_id'], 'Valid Name', 'invalid-email-format');
    die("FAILED: Allowed invalid email format!\n");
} catch (InvalidArgumentException $e) {}

try {
    updateEmployeeDetails($targetEmp['employee_id'], 'Valid Name', $unrelatedEmp['email']);
    die("FAILED: Allowed duplicate email address!\n");
} catch (InvalidArgumentException $e) {}
echo "SUCCESS (All validation rules correctly enforced)\n";

// 4. Test Preview Delete Stats
echo "[4/10] Testing Employee Delete Stats Preview... ";
$stats = getEmployeeDeleteStats($targetEmp['employee_id']);
if ($stats['device_count'] !== 2 || $stats['screenshot_count'] !== 2 || $stats['activity_count'] !== 2 || $stats['physical_files_found'] !== 2) {
    die("FAILED: Incorrect preview stats. Got Devices: {$stats['device_count']}, Shots: {$stats['screenshot_count']}, Activity: {$stats['activity_count']}, Files: {$stats['physical_files_found']}\n");
}
echo "SUCCESS (Devices: 2, Screenshots: 2, Activity: 2, Physical Files: 2)\n";

// 5. Test Path Traversal Protection during Employee Deletion
echo "[5/10] Testing Safe Path Traversal Verification during Deletion... ";
$outsideFile = __DIR__ . '/../storage/outside_test.txt';
file_put_contents($outsideFile, "SAFE_OUTSIDE_DATA");

$devId = $targetEmp['device_ids'][0];
$stmtBadShot = $db->prepare("INSERT INTO screenshots (device_id, captured_at, activity_status, relative_path, file_size, width, height) VALUES (:dev_id, NOW(), 'ACTIVE', '../outside_test.txt', 10, 100, 100)");
$stmtBadShot->execute([':dev_id' => $devId]);

if (!file_exists($outsideFile)) {
    die("FAILED: Test outside file creation failed.\n");
}
echo "SUCCESS\n";

// 6. Test Safe Employee Removal Execution
echo "[6/10] Executing Safe Employee Deletion (Target Emp ID: {$targetEmp['employee_id']})... ";
$delResult = deleteEmployeeSafely($targetEmp['employee_id']);
if (!$delResult['success'] || $delResult['deleted_devices_count'] !== 2 || $delResult['unlinked_files_count'] !== 2) {
    die("FAILED: Unexpected deletion result: " . print_r($delResult, true) . "\n");
}
echo "SUCCESS\n";

// 7. Verify Unlinked Screenshot Files & DB Cleanup
echo "[7/10] Verifying Target Physical Files & DB Cleanup... ";
foreach ($targetEmp['device_ids'] as $devId) {
    $filePath = $storageDir . "/test_emp_mgmt/emp_{$targetEmp['employee_id']}_dev_{$devId}.jpg";
    if (file_exists($filePath)) {
        die("FAILED: Screenshot physical file was not unlinked! Path: {$filePath}\n");
    }
}
if (!file_exists($outsideFile)) {
    die("FAILED: Security breach! Deletion removed file outside storage/screenshots/\n");
}
@unlink($outsideFile);

$checkEmp = getEmployeeDetails($targetEmp['employee_id']);
if ($checkEmp !== null) {
    die("FAILED: Employee record still exists in DB!\n");
}
$checkDev = $db->query("SELECT COUNT(*) FROM devices WHERE employee_id = {$targetEmp['employee_id']}")->fetchColumn();
if ((int)$checkDev > 0) {
    die("FAILED: Devices still exist for deleted employee!\n");
}
echo "SUCCESS (Target records & files purged successfully)\n";

// 8. Verify Device Token Authentication Invalidation (Task 7)
echo "[8/10] Testing Active Agent Authentication Invalidation for Deleted Devices... ";
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer " . $targetEmp['raw_tokens'][0];
$authResult = authenticateAgentDevice();
if ($authResult !== null) {
    die("FAILED: Deleted device token still authenticated successfully!\n");
}
echo "SUCCESS (Deleted device token correctly rejected with null / 401)\n";

// 9. Verify Unrelated Employees & Devices remain completely untouched
echo "[9/10] Verifying Unrelated Employee & Devices Integrity... ";
$unrelatedCheck = getEmployeeDetails($unrelatedEmp['employee_id']);
if (!$unrelatedCheck) {
    die("FAILED: Unrelated employee was accidentally deleted!\n");
}
$unrelatedDevCount = $db->query("SELECT COUNT(*) FROM devices WHERE employee_id = {$unrelatedEmp['employee_id']}")->fetchColumn();
if ((int)$unrelatedDevCount !== 2) {
    die("FAILED: Unrelated employee's devices were modified!\n");
}
echo "SUCCESS (Unrelated records 100% intact)\n";

// 10. Clean up test fixture
echo "[10/10] Cleaning up test fixtures... ";
deleteEmployeeSafely($unrelatedEmp['employee_id']);
echo "SUCCESS\n\n";

echo "=====================================================\n";
echo "   All Employee Management Tests PASSED!             \n";
echo "=====================================================\n";
