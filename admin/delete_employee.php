<?php
// admin/delete_employee.php - Server-Side Endpoint for Safe Employee Deletion & Preview

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/employee_helper.php';

requireAdminSession();

$isJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJson) {
        respondJson(['success' => false, 'error' => 'Invalid request method. POST required.'], 405);
    } else {
        header('Location: index.php');
        exit;
    }
}

// Verify CSRF Token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    if ($isJson) {
        respondJson(['success' => false, 'error' => 'Security Error: Invalid or expired CSRF token.'], 403);
    } else {
        $_SESSION['flash_error'] = "Security Error: Invalid or expired CSRF token.";
        header('Location: index.php');
        exit;
    }
}

$employeeId = (int)($_POST['employee_id'] ?? 0);
$action = $_POST['action'] ?? 'execute';

if ($employeeId <= 0) {
    if ($isJson) {
        respondJson(['success' => false, 'error' => 'Invalid employee ID parameter.'], 400);
    } else {
        $_SESSION['flash_error'] = "Invalid employee ID specified.";
        header('Location: index.php');
        exit;
    }
}

try {
    if ($action === 'preview') {
        $stats = getEmployeeDeleteStats($employeeId);
        respondJson(['success' => true, 'stats' => $stats]);
    } elseif ($action === 'execute') {
        $result = deleteEmployeeSafely($employeeId);
        if ($isJson) {
            respondJson($result);
        } else {
            $_SESSION['flash_success'] = "Successfully removed employee '{$result['employee_name']}' and all associated monitoring data ({$result['deleted_devices_count']} device(s), {$result['unlinked_files_count']} screenshot file(s)).";
            header('Location: index.php');
            exit;
        }
    } else {
        throw new InvalidArgumentException("Unknown action requested.");
    }
} catch (Exception $ex) {
    if ($isJson) {
        respondJson(['success' => false, 'error' => $ex->getMessage()], 400);
    } else {
        $_SESSION['flash_error'] = "Employee Removal Failed: " . $ex->getMessage();
        header('Location: index.php');
        exit;
    }
}
