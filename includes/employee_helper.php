<?php
// includes/employee_helper.php - Admin Employee Management Engine (Edit, Safe Delete, Validation)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/screenshot_helper.php';

/**
 * Fetch employee record by ID.
 */
function getEmployeeDetails(int $employeeId) {
    if ($employeeId <= 0) return null;
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = :id");
    $stmt->execute([':id' => $employeeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Update an employee's name and email address.
 */
function updateEmployeeDetails(int $employeeId, string $name, string $email) {
    $name = trim($name);
    $email = trim(strtolower($email));

    if ($employeeId <= 0) {
        throw new InvalidArgumentException("Invalid employee ID.");
    }
    if (empty($name)) {
        throw new InvalidArgumentException("Employee name cannot be empty.");
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Please provide a valid email address.");
    }

    $db = getDbConnection();

    // Check existing employee
    $existing = getEmployeeDetails($employeeId);
    if (!$existing) {
        throw new InvalidArgumentException("Employee not found.");
    }

    // Check email uniqueness against other employees
    $dupStmt = $db->prepare("SELECT id FROM employees WHERE email = :email AND id != :id");
    $dupStmt->execute([':email' => $email, ':id' => $employeeId]);
    if ($dupStmt->fetch()) {
        throw new InvalidArgumentException("An employee with email '{$email}' already exists.");
    }

    $stmt = $db->prepare("UPDATE employees SET name = :name, email = :email, updated_at = NOW() WHERE id = :id");
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':id' => $employeeId
    ]);

    return true;
}

/**
 * Compute screenshot, device, and activity statistics for an employee prior to deletion.
 */
function getEmployeeDeleteStats(int $employeeId) {
    $employee = getEmployeeDetails($employeeId);
    if (!$employee) {
        throw new InvalidArgumentException("Employee not found.");
    }

    $db = getDbConnection();
    $storageDir = getScreenshotStorageDir();

    // Fetch device IDs
    $devStmt = $db->prepare("SELECT id, device_name FROM devices WHERE employee_id = :id");
    $devStmt->execute([':id' => $employeeId]);
    $devices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

    $deviceCount = count($devices);
    $deviceIds = array_map(function($d) { return (int)$d['id']; }, $devices);

    $screenshotCount = 0;
    $physicalFilesFound = 0;
    $missingFilesCount = 0;
    $activityCount = 0;

    if ($deviceCount > 0) {
        $inClause = implode(',', array_fill(0, count($deviceIds), '?'));

        // Activity Count
        $actStmt = $db->prepare("SELECT COUNT(*) FROM activity WHERE device_id IN ({$inClause})");
        $actStmt->execute($deviceIds);
        $activityCount = (int)$actStmt->fetchColumn();

        // Screenshots List & Physical Files check
        $shotStmt = $db->prepare("SELECT relative_path FROM screenshots WHERE device_id IN ({$inClause})");
        $shotStmt->execute($deviceIds);
        $shots = $shotStmt->fetchAll(PDO::FETCH_ASSOC);
        $screenshotCount = count($shots);

        foreach ($shots as $shot) {
            $targetPath = $storageDir . '/' . ltrim($shot['relative_path'], '/');
            $realTarget = realpath($targetPath);
            if ($realTarget !== false && strpos($realTarget, $storageDir) === 0 && file_exists($realTarget)) {
                $physicalFilesFound++;
            } else {
                $missingFilesCount++;
            }
        }
    }

    return [
        'employee_id' => $employeeId,
        'employee_name' => $employee['name'],
        'employee_email' => $employee['email'],
        'device_count' => $deviceCount,
        'devices' => $devices,
        'screenshot_count' => $screenshotCount,
        'physical_files_found' => $physicalFilesFound,
        'missing_files' => $missingFilesCount,
        'activity_count' => $activityCount
    ];
}

/**
 * Safely remove an employee and all associated monitoring data, unlinking physical files safely.
 */
function deleteEmployeeSafely(int $employeeId) {
    $employee = getEmployeeDetails($employeeId);
    if (!$employee) {
        throw new InvalidArgumentException("Employee not found.");
    }

    $db = getDbConnection();
    $storageDir = getScreenshotStorageDir();

    // Fetch device IDs
    $devStmt = $db->prepare("SELECT id FROM devices WHERE employee_id = :id");
    $devStmt->execute([':id' => $employeeId]);
    $deviceIds = $devStmt->fetchAll(PDO::FETCH_COLUMN);

    $unlinkedFilesCount = 0;
    $missingFilesCount = 0;

    // Phase 1: Physical screenshot file cleanup
    if (!empty($deviceIds)) {
        $inClause = implode(',', array_fill(0, count($deviceIds), '?'));
        $shotStmt = $db->prepare("SELECT relative_path FROM screenshots WHERE device_id IN ({$inClause})");
        $shotStmt->execute($deviceIds);
        $shots = $shotStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($shots as $shot) {
            $targetPath = $storageDir . '/' . ltrim($shot['relative_path'], '/');
            $realTarget = realpath($targetPath);

            // Strict security check: path must resolve inside storageDir
            if ($realTarget !== false && strpos($realTarget, $storageDir) === 0 && file_exists($realTarget)) {
                if (@unlink($realTarget)) {
                    $unlinkedFilesCount++;
                }
            } else {
                $missingFilesCount++;
            }
        }
    }

    // Phase 2: Transactional database purge
    $db->beginTransaction();
    try {
        if (!empty($deviceIds)) {
            $inClause = implode(',', array_fill(0, count($deviceIds), '?'));

            // 1. Delete screenshots DB records
            $delShots = $db->prepare("DELETE FROM screenshots WHERE device_id IN ({$inClause})");
            $delShots->execute($deviceIds);

            // 2. Delete activity records
            $delAct = $db->prepare("DELETE FROM activity WHERE device_id IN ({$inClause})");
            $delAct->execute($deviceIds);

            // 3. Delete agent heartbeats
            $delHb = $db->prepare("DELETE FROM agent_heartbeats WHERE device_id IN ({$inClause})");
            $delHb->execute($deviceIds);

            // 4. Delete device enrollment tokens
            $delTokens = $db->prepare("DELETE FROM device_enrollment_tokens WHERE device_id IN ({$inClause})");
            $delTokens->execute($deviceIds);

            // 5. Delete monitor settings
            $delSettings = $db->prepare("DELETE FROM monitor_settings WHERE device_id IN ({$inClause})");
            $delSettings->execute($deviceIds);

            // 6. Delete devices
            $delDev = $db->prepare("DELETE FROM devices WHERE employee_id = :emp_id");
            $delDev->execute([':emp_id' => $employeeId]);
        }

        // 7. Delete employee record
        $delEmp = $db->prepare("DELETE FROM employees WHERE id = :emp_id");
        $delEmp->execute([':emp_id' => $employeeId]);

        $db->commit();
    } catch (Exception $ex) {
        $db->rollBack();
        throw $ex;
    }

    return [
        'success' => true,
        'employee_id' => $employeeId,
        'employee_name' => $employee['name'],
        'deleted_devices_count' => count($deviceIds),
        'unlinked_files_count' => $unlinkedFilesCount
    ];
}
