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
    SELECT d.*, e.id as employee_id, e.name as employee_name, e.email as employee_email,
           ms.screenshot_interval_seconds, ms.screenshot_quality, ms.screenshot_width, ms.screenshot_height, ms.idle_threshold_seconds, ms.monitoring_enabled, ms.screenshot_enabled
    FROM devices d
    JOIN employees e ON d.employee_id = e.id
    LEFT JOIN monitor_settings ms ON ms.device_id = d.id
    WHERE d.id = :id
");
$stmt->execute([':id' => $deviceId]);
$device = $stmt->fetch();

if (!$device) {
    echo "<div class='alert-warning' style='margin-top:1.5rem;'>Device not found.</div>";
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

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;"><?= htmlspecialchars($device['employee_name']) ?></h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Device Details: <strong><?= htmlspecialchars($device['device_name']) ?></strong></p>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <a href="edit_employee.php?id=<?= $device['employee_id'] ?>" class="btn btn-secondary">✏️ Edit Employee</a>
        <a href="generate_agent.php?device_id=<?= $device['id'] ?>" class="btn btn-primary">⚡ Generate Windows Agent</a>
        <a href="settings.php?device_id=<?= $device['id'] ?>" class="btn btn-secondary">Edit Settings</a>
        <button type="button" class="btn btn-danger" onclick="openRemoveModal(<?= $device['employee_id'] ?>)">🗑️ Remove Employee</button>
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

<div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; gap:1rem;">
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

<!-- Employee Removal Confirmation Modal -->
<div id="removeEmployeeModal" class="modal-backdrop" onclick="closeRemoveModal()">
    <div class="modal-content" style="max-width:520px; text-align:left; align-items:stretch;" onclick="event.stopPropagation()">
        <h3 style="color:#ef4444; margin-bottom:0.5rem; font-size:1.25rem;">Confirm Employee Removal</h3>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1rem;">Review employee statistics before removing.</p>

        <div id="remLoading" style="text-align:center; padding:1.5rem; color:var(--text-muted);">
            Loading employee details...
        </div>

        <div id="remDetails" style="display:none; background:var(--bg-secondary); border:1px solid var(--border-color); padding:1rem; border-radius:8px; margin-bottom:1rem;">
            <table style="width:100%; font-size:0.875rem; border-collapse:collapse;">
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Employee Name:</td>
                    <td style="font-weight:700; text-align:right;" id="remName">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Employee Email:</td>
                    <td style="font-weight:600; text-align:right;" id="remEmail">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Associated Devices:</td>
                    <td style="font-weight:700; color:#38bdf8; text-align:right;" id="remDevices">0</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Screenshots (DB):</td>
                    <td style="font-weight:700; color:#22c55e; text-align:right;" id="remShots">0</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Activity Records:</td>
                    <td style="font-weight:700; color:#eab308; text-align:right;" id="remActivity">0</td>
                </tr>
            </table>
        </div>

        <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.4); padding:0.75rem; border-radius:6px; margin-bottom:1rem; font-size:0.8rem; color:#fca5a5;">
            <strong>Warning:</strong> Deleting this employee will permanently delete all associated devices, desktop screenshots, physical files, and activity logs. <em>This action cannot be undone.</em>
        </div>

        <form id="removeExecuteForm" method="POST" action="delete_employee.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="employee_id" id="remEmployeeId" value="">

            <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
                <button type="button" class="btn btn-secondary" onclick="closeRemoveModal()">Cancel</button>
                <button type="submit" id="confirmRemoveBtn" class="btn btn-danger">Confirm & Remove Employee</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRemoveModal(employeeId) {
    const modal = document.getElementById('removeEmployeeModal');
    const loading = document.getElementById('remLoading');
    const details = document.getElementById('remDetails');
    
    loading.style.display = 'block';
    details.style.display = 'none';
    document.getElementById('remEmployeeId').value = employeeId;

    modal.classList.add('active');

    let formData = new FormData();
    formData.append('csrf_token', '<?= $csrfToken ?>');
    formData.append('action', 'preview');
    formData.append('employee_id', employeeId);

    fetch('delete_employee.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        loading.style.display = 'none';
        if (data.success && data.stats) {
            details.style.display = 'block';
            document.getElementById('remName').innerText = data.stats.employee_name;
            document.getElementById('remEmail').innerText = data.stats.employee_email;
            document.getElementById('remDevices').innerText = data.stats.device_count.toLocaleString();
            document.getElementById('remShots').innerText = data.stats.screenshot_count.toLocaleString();
            document.getElementById('remActivity').innerText = data.stats.activity_count.toLocaleString();
        } else {
            alert('Preview Error: ' + (data.error || 'Failed to fetch employee details.'));
            closeRemoveModal();
        }
    })
    .catch(err => {
        loading.style.display = 'none';
        alert('Network Error: Unable to fetch employee preview stats.');
        closeRemoveModal();
    });
}

function closeRemoveModal() {
    document.getElementById('removeEmployeeModal').classList.remove('active');
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
