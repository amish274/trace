<?php
// includes/screenshot_helper.php - Admin Bulk Screenshot Deletion & Management Engine

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Ensures PHP operates in Asia/Kolkata application timezone.
 */
if (date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

/**
 * Get canonical screenshot storage directory.
 */
function getScreenshotStorageDir() {
    $path = SCREENSHOT_STORAGE_PATH;
    if (!file_exists($path)) {
        @mkdir($path, 0755, true);
    }
    $real = realpath($path);
    if (!$real) {
        throw new Exception("Invalid screenshot storage path.");
    }
    return $real;
}

/**
 * Calculate start/end date-time boundaries in IST for various deletion modes.
 */
function resolveScreenshotDateRange($mode, array $params) {
    date_default_timezone_set('Asia/Kolkata');

    $startDate = null;
    $endDate = null;
    $description = '';
    $ids = [];

    switch ($mode) {
        case 'selected':
            $rawIds = $params['ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = explode(',', (string)$rawIds);
            }
            foreach ($rawIds as $val) {
                $id = (int)$val;
                if ($id > 0) $ids[] = $id;
            }
            $description = count($ids) . " selected screenshot(s)";
            break;

        case 'day':
            $dayStr = trim($params['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr)) {
                throw new InvalidArgumentException("Invalid date format. Expected YYYY-MM-DD.");
            }
            $startDate = $dayStr . ' 00:00:00';
            $endDate = $dayStr . ' 23:59:59';
            $formattedDay = date('d M Y', strtotime($dayStr));
            $description = "Screenshots for Day: {$formattedDay}";
            break;

        case 'week':
            $weekRefStr = trim($params['date'] ?? $params['week'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRefStr)) {
                $weekRefStr = date('Y-m-d');
            }
            $dt = new DateTime($weekRefStr, new DateTimeZone('Asia/Kolkata'));
            $dayOfWeek = (int)$dt->format('N'); // 1 (Mon) to 7 (Sun)
            
            $monday = clone $dt;
            $monday->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
            
            $sunday = clone $monday;
            $sunday->modify('+6 days')->setTime(23, 59, 59);

            $startDate = $monday->format('Y-m-d H:i:s');
            $endDate = $sunday->format('Y-m-d H:i:s');
            $description = "Week of " . $monday->format('d M Y') . " through " . $sunday->format('d M Y');
            break;

        case 'range':
            $startStr = trim($params['start_date'] ?? '');
            $endStr = trim($params['end_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endStr)) {
                throw new InvalidArgumentException("Invalid start or end date format. Expected YYYY-MM-DD.");
            }
            if ($startStr > $endStr) {
                throw new InvalidArgumentException("Start date cannot be after End date.");
            }
            $startDate = $startStr . ' 00:00:00';
            $endDate = $endStr . ' 23:59:59';
            $description = "Date range: " . date('d M Y', strtotime($startStr)) . " through " . date('d M Y', strtotime($endStr));
            break;

        case 'all':
            $description = "ALL screenshots in the system";
            break;

        default:
            throw new InvalidArgumentException("Unsupported deletion mode.");
    }

    return [
        'mode' => $mode,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'description' => $description,
        'ids' => $ids
    ];
}

/**
 * Preview screenshots matching deletion criteria before actual removal.
 */
function previewScreenshotDeletion($mode, array $params, $deviceId = null) {
    $range = resolveScreenshotDateRange($mode, $params);
    $db = getDbConnection();
    $storageDir = getScreenshotStorageDir();

    $whereClause = "WHERE 1=1";
    $queryParams = [];

    if ($deviceId) {
        $whereClause .= " AND device_id = ?";
        $queryParams[] = (int)$deviceId;
    }

    if ($range['mode'] === 'selected') {
        if (empty($range['ids'])) {
            return [
                'success' => true,
                'mode' => $mode,
                'description' => $range['description'],
                'start_date' => null,
                'end_date' => null,
                'total_db_records' => 0,
                'physical_files_found' => 0,
                'missing_files' => 0
            ];
        }
        $inClause = implode(',', array_fill(0, count($range['ids']), '?'));
        $whereClause .= " AND id IN ({$inClause})";
        $queryParams = array_merge($queryParams, $range['ids']);
    } elseif ($range['start_date'] && $range['end_date']) {
        $whereClause .= " AND captured_at >= ? AND captured_at <= ?";
        $queryParams[] = $range['start_date'];
        $queryParams[] = $range['end_date'];
    }

    // Count total DB records
    $countSql = "SELECT COUNT(*) FROM screenshots {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($queryParams);
    $totalDbRecords = (int)$stmt->fetchColumn();

    // Sample/check physical files
    $filesFound = 0;
    $missingFiles = 0;

    if ($totalDbRecords > 0) {
        $selectSql = "SELECT id, relative_path FROM screenshots {$whereClause}";
        $stmtSelect = $db->prepare($selectSql);
        $stmtSelect->execute($queryParams);
        
        while ($row = $stmtSelect->fetch(PDO::FETCH_ASSOC)) {
            $targetPath = $storageDir . '/' . ltrim($row['relative_path'], '/');
            $realTarget = realpath($targetPath);
            if ($realTarget !== false && strpos($realTarget, $storageDir) === 0 && file_exists($realTarget)) {
                $filesFound++;
            } else {
                $missingFiles++;
            }
        }
    }

    return [
        'success' => true,
        'mode' => $mode,
        'description' => $range['description'],
        'start_date' => $range['start_date'] ? date('d M Y, h:i A', strtotime($range['start_date'])) : 'N/A',
        'end_date' => $range['end_date'] ? date('d M Y, h:i A', strtotime($range['end_date'])) : 'N/A',
        'raw_start_date' => $range['start_date'],
        'raw_end_date' => $range['end_date'],
        'total_db_records' => $totalDbRecords,
        'physical_files_found' => $filesFound,
        'missing_files' => $missingFiles
    ];
}

/**
 * Safely execute screenshot deletion in memory-efficient batches.
 */
function executeScreenshotDeletion($mode, array $params, $deviceId = null) {
    $range = resolveScreenshotDateRange($mode, $params);
    $db = getDbConnection();
    $storageDir = getScreenshotStorageDir();

    $totalDbDeleted = 0;
    $totalFilesUnlinked = 0;
    $missingFilesCount = 0;
    $failedUnlinks = 0;

    $batchSize = 200;

    while (true) {
        $whereClause = "WHERE 1=1";
        $queryParams = [];

        if ($deviceId) {
            $whereClause .= " AND device_id = ?";
            $queryParams[] = (int)$deviceId;
        }

        if ($range['mode'] === 'selected') {
            if (empty($range['ids'])) break;
            // Slice batch of IDs
            $batchIds = array_splice($range['ids'], 0, $batchSize);
            $inClause = implode(',', array_fill(0, count($batchIds), '?'));
            $whereClause .= " AND id IN ({$inClause})";
            $queryParams = array_merge($queryParams, $batchIds);
        } elseif ($range['start_date'] && $range['end_date']) {
            $whereClause .= " AND captured_at >= ? AND captured_at <= ?";
            $queryParams[] = $range['start_date'];
            $queryParams[] = $range['end_date'];
        }

        // Select batch of screenshots
        $selectSql = "SELECT id, relative_path FROM screenshots {$whereClause} ORDER BY id ASC LIMIT {$batchSize}";
        $stmt = $db->prepare($selectSql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            break; // No more rows to process
        }

        $idsToDelete = [];
        foreach ($rows as $row) {
            $idsToDelete[] = (int)$row['id'];
            $targetPath = $storageDir . '/' . ltrim($row['relative_path'], '/');
            $realTarget = realpath($targetPath);

            // Strict security check: path must resolve inside storageDir
            if ($realTarget !== false && strpos($realTarget, $storageDir) === 0 && file_exists($realTarget)) {
                if (@unlink($realTarget)) {
                    $totalFilesUnlinked++;
                } else {
                    $failedUnlinks++;
                }
            } else {
                $missingFilesCount++;
            }
        }

        if (!empty($idsToDelete)) {
            $db->beginTransaction();
            try {
                $inDelete = implode(',', array_fill(0, count($idsToDelete), '?'));
                $deleteSql = "DELETE FROM screenshots WHERE id IN ({$inDelete})";
                $stmtDel = $db->prepare($deleteSql);
                $stmtDel->execute($idsToDelete);
                $totalDbDeleted += $stmtDel->rowCount();
                $db->commit();
            } catch (Exception $ex) {
                $db->rollBack();
                throw $ex;
            }
        }

        // For selected mode, array_splice reduces $range['ids'] until empty.
        // For range/day/all modes, deleting rows in loop will eventually cause selectSql to return 0 rows.
    }

    return [
        'success' => true,
        'mode' => $mode,
        'description' => $range['description'],
        'deleted_db_records' => $totalDbDeleted,
        'deleted_physical_files' => $totalFilesUnlinked,
        'missing_files_cleaned' => $missingFilesCount,
        'failed_unlinks' => $failedUnlinks
    ];
}
