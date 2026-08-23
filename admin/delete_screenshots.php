<?php
// admin/delete_screenshots.php - Endpoint for Admin Bulk Screenshot Deletion & Preview

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

requireAdminSession();

$isJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJson) {
        respondJson(['success' => false, 'error' => 'Invalid request method. POST required.'], 405);
    } else {
        header('Location: screenshots.php');
        exit;
    }
}

// CSRF Verification
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    if ($isJson) {
        respondJson(['success' => false, 'error' => 'Security Error: Invalid or expired CSRF token.'], 403);
    } else {
        $_SESSION['flash_error'] = "Security Error: Invalid or expired CSRF token.";
        header('Location: screenshots.php');
        exit;
    }
}

$action = $_POST['action'] ?? 'execute';
$mode = $_POST['mode'] ?? 'day';
$deviceId = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;

try {
    if ($action === 'preview') {
        $result = previewScreenshotDeletion($mode, $_POST, $deviceId);
        respondJson($result);
    } elseif ($action === 'execute') {
        $result = executeScreenshotDeletion($mode, $_POST, $deviceId);
        
        if ($isJson) {
            respondJson($result);
        } else {
            $msg = "Successfully deleted {$result['deleted_db_records']} screenshot database records ({$result['deleted_physical_files']} physical files removed).";
            if ($result['missing_files_cleaned'] > 0) {
                $msg .= " Cleaned {$result['missing_files_cleaned']} missing file references.";
            }
            $_SESSION['flash_success'] = $msg;
            header('Location: screenshots.php' . ($deviceId ? "?device_id={$deviceId}" : ''));
            exit;
        }
    } else {
        throw new InvalidArgumentException("Unknown action requested.");
    }
} catch (Exception $ex) {
    if ($isJson) {
        respondJson(['success' => false, 'error' => $ex->getMessage()], 400);
    } else {
        $_SESSION['flash_error'] = "Deletion Failed: " . $ex->getMessage();
        header('Location: screenshots.php');
        exit;
    }
}
