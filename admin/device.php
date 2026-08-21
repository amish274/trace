<?php
// admin/device.php - Detailed Device & Employee View with Package Management

require_once __DIR__ . '/header.php';

$deviceId = (int)($_GET['id'] ?? 0);
if (!$deviceId) {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();

// Fetch Device & Employee Details
$stmt = $db->prepare("
    SELECT d.*, e.name as employee_name, e.email as employee_email,
           ms.screenshot_interval_seconds, ms.screenshot_quality, ms.screenshot_width, ms.screenshot_height, ms.idle_threshold_seconds, ms.monitoring_enabled, ms.screenshot_enabled
    FROM devices d
    JOIN employees e ON d.employee_id = e.id
    LEFT JOIN monitor_settings ms ON ms.device_id = d.id
    WHERE d.id = :id
");
$stmt->execute([':id' => $deviceId]);
$device = $stmt->fetch();

if (!$device) {
    echo "<div class='alert-warning'>Device not found.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

// Stats Today
$statStmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN activity_status = 'ACTIVE' THEN 30 ELSE 0 END) as active_sec,
        SUM(CASE WHEN activity_status = 'IDLE' THEN 30 ELSE 0 END) as idle_sec
    FROM activity 
    WHERE device_id = :id AND DATE(captured_at) = CURDATE()
");
$statStmt->execute([':id' => $deviceId]);
$todayStats = $statStmt->fetch();

$shotCountStmt = $db->prepare("SELECT COUNT(*) FROM screenshots WHERE device_id = :id AND DATE(captured_at) = CURDATE()");
$shotCountStmt->execute([':id' => $deviceId]);
$screenshotsToday = $shotCountStmt->fetchColumn();

// Latest Activity Status
$latestActStmt = $db->prepare("SELECT activity_status FROM activity WHERE device_id = :id ORDER BY captured_at DESC LIMIT 1");
$latestActStmt->execute([':id' => $deviceId]);
$latestStatus = $latestActStmt->fetchColumn() ?: 'UNKNOWN';

// Determine Online / Stale / Offline / Revoked Badge
$onlineBadge = '<span class="badge badge-offline">OFFLINE</span>';
if (($device['status'] ?? 'active') === 'revoked') {
    $onlineBadge = '<span class="badge" style="background:rgba(239, 68, 68, 0.2); color:#fca5a5; border:1px solid #ef4444;">REVOKED</span>';
} else if ($device['last_seen_at']) {
    $diff = time() - strtotime($device['last_seen_at']);
    if ($diff < 120) {
        $onlineBadge = '<span class="badge badge-active">🟢 ONLINE</span>';
    } else if ($diff < 600) {
        $onlineBadge = '<span class="badge badge-idle">STALE</span>';
    }
}

function formatSecs($seconds) {
    if (!$seconds || $seconds <= 0) return '0m';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
}

$csrfToken = generateCsrfToken();
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg === 'revoked'): ?>
    <div class="alert-warning" style="margin-bottom: 1.5rem; background:rgba(239, 68, 68, 0.2); color:#fca5a5; border-color:#ef4444;">
        <strong>Device Authorization Revoked:</strong> Permanent device token has been invalidated. The agent on this PC will no longer be permitted to connect.
    </div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;"><?= htmlspecialchars($device['employee_name']) ?></h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Device Details: <strong><?= htmlspecialchars($device['device_name']) ?></strong></p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <a href="generate_agent.php?device_id=<?= $device['id'] ?>" class="btn btn-primary">⚡ Generate Windows Agent</a>
        <a href="settings.php?device_id=<?= $device['id'] ?>" class="btn btn-secondary">Edit Settings</a>
        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<div class="grid-4" style="margin-bottom:1.5rem;">
    <div class="stat-box">
        <div class="stat-label">Agent Status</div>
        <div style="margin-top:0.4rem;"><?= $onlineBadge ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Enrollment Status</div>
        <div style="margin-top:0.4rem;">
            <span class="badge badge-<?= $device['package_status'] === 'enrolled' ? 'success' : ($device['package_status'] === 'generated' || $device['package_status'] === 'downloaded' ? 'warning' : 'secondary') ?>">
                <?= strtoupper($device['package_status'] ?: 'NOT GENERATED') ?>
            </span>
        </div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Today's Active</div>
        <div class="stat-value" style="color:var(--status-active)"><?= formatSecs($todayStats['active_sec']) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Today's Idle</div>
        <div class="stat-value" style="color:var(--status-idle)"><?= formatSecs($todayStats['idle_sec']) ?></div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-title">Device Specifications & Monitoring Config</div>
    <div class="grid-2">
        <div>
            <p><strong>Employee Email:</strong> <?= htmlspecialchars($device['employee_email']) ?></p>
            <p><strong>OS Version:</strong> <?= htmlspecialchars($device['operating_system'] ?: 'Unknown') ?></p>
            <p><strong>Agent Version:</strong> <?= htmlspecialchars($device['agent_version'] ?: '1.0.0') ?></p>
            <p><strong>Last Seen:</strong> <?= $device['last_seen_at'] ? date('Y-m-d H:i:s', strtotime($device['last_seen_at'])) : 'Never' ?></p>
        </div>
        <div>
            <p><strong>Monitoring Enabled:</strong> <?= $device['monitoring_enabled'] ? 'Yes' : 'No' ?></p>
            <p><strong>Screenshot Interval:</strong> <?= (int)$device['screenshot_interval_seconds'] ?> seconds</p>
            <p><strong>JPEG Quality:</strong> <?= (int)$device['screenshot_quality'] ?>%</p>
            <p><strong>Screenshots Today:</strong> <?= (int)$screenshotsToday ?></p>
        </div>
    </div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem;">
    <div style="display:flex; gap:1rem;">
        <a href="screenshots.php?device_id=<?= $device['id'] ?>" class="btn btn-secondary">View Screenshots Timeline &rarr;</a>
        <a href="activity.php?device_id=<?= $device['id'] ?>" class="btn btn-secondary">View Today's Activity Timeline &rarr;</a>
    </div>

    <?php if (($device['status'] ?? 'active') === 'active'): ?>
        <form method="POST" action="revoke.php" onsubmit="return confirm('Are you sure you want to revoke this device? It will immediately cut off API access for this agent.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
            <button type="submit" class="btn" style="background:rgba(239, 68, 68, 0.2); color:#fca5a5; border:1px solid #ef4444; padding:0.5rem 1rem; border-radius:6px; font-weight:600; cursor:pointer;">
                🚫 REVOKE DEVICE
            </button>
        </form>
    <?php else: ?>
        <a href="generate_agent.php?device_id=<?= $device['id'] ?>" class="btn btn-primary">
            ↺ GENERATE NEW WINDOWS AGENT
        </a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
