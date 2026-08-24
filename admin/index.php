<?php
// admin/index.php - Primary Dashboard showing Employee/Device Overview & Agent Status

require_once __DIR__ . '/header.php';

$db = getDbConnection();
$csrfToken = generateCsrfToken();

// Fetch employees with their device stats, enrollment status, and active/idle metrics
$query = "
    SELECT 
        e.id as employee_id,
        e.name as employee_name,
        e.email as employee_email,
        d.id as device_id,
        d.device_name,
        d.agent_version,
        d.status as device_status,
        d.package_status,
        d.last_seen_at,
        ms.screenshot_interval_seconds,
        ms.monitoring_enabled,
        (
            SELECT activity_status FROM activity 
            WHERE device_id = d.id 
            ORDER BY captured_at DESC LIMIT 1
        ) as latest_status,
        (
            SELECT captured_at FROM screenshots 
            WHERE device_id = d.id 
            ORDER BY captured_at DESC LIMIT 1
        ) as last_screenshot_at,
        (
            SELECT SUM(CASE WHEN activity_status = 'ACTIVE' THEN 30 ELSE 0 END) 
            FROM activity 
            WHERE device_id = d.id AND DATE(captured_at) = CURDATE()
        ) as today_active_seconds,
        (
            SELECT SUM(CASE WHEN activity_status = 'IDLE' THEN 30 ELSE 0 END) 
            FROM activity 
            WHERE device_id = d.id AND DATE(captured_at) = CURDATE()
        ) as today_idle_seconds
    FROM employees e
    LEFT JOIN devices d ON d.employee_id = e.id
    LEFT JOIN monitor_settings ms ON ms.device_id = d.id
    ORDER BY e.created_at DESC
";

$devices = $db->query($query)->fetchAll();

function formatDuration($seconds) {
    if (!$seconds || $seconds <= 0) return '0m';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
}

function getAgentStatusBadge($status, $lastSeenAt) {
    if ($status === 'revoked') {
        return '<span class="badge" style="background:rgba(239, 68, 68, 0.2); color:#fca5a5; border:1px solid #ef4444;">REVOKED</span>';
    }
    if (!$lastSeenAt) return '<span class="badge badge-offline">OFFLINE</span>';
    $diff = time() - strtotime($lastSeenAt);
    if ($diff < 120) { // < 2 min
        return '<span class="badge badge-active">🟢 ONLINE</span>';
    } else if ($diff < 600) { // < 10 min
        return '<span class="badge badge-idle">STALE</span>';
    } else {
        return '<span class="badge badge-offline">OFFLINE</span>';
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;">Employee Monitoring Dashboard</h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Overview of connected employee devices & real-time monitoring state.</p>
    </div>
    <a href="enroll.php" class="btn btn-primary">+ Add Employee / Device</a>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError): ?>
    <div class="alert-danger"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">
        <span>Monitored Employees & Devices</span>
        <span style="font-size:0.85rem; font-weight:normal; color:var(--text-muted);">Total: <?= count($devices) ?></span>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Device</th>
                    <th>Agent Status</th>
                    <th>Package Status</th>
                    <th>Today Active</th>
                    <th>Today Idle</th>
                    <th>Last Seen</th>
                    <th>Last Screenshot</th>
                    <th>Interval</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; color:var(--text-muted); padding:2rem;">
                            No devices registered yet. Click "+ Add Employee / Device" to enroll a device.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $row): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['employee_name']) ?></strong>
                                <div style="font-size:0.8rem; color:var(--text-muted);"><?= htmlspecialchars($row['employee_email']) ?></div>
                            </td>
                            <td>
                                <?= htmlspecialchars($row['device_name'] ?? 'No device') ?>
                                <?php if (!empty($row['agent_version'])): ?>
                                    <div style="font-size:0.75rem; color:var(--text-muted);">v<?= htmlspecialchars($row['agent_version']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= getAgentStatusBadge($row['device_status'] ?? 'active', $row['last_seen_at']) ?></td>
                            <td>
                                <span class="badge badge-<?= ($row['package_status'] ?? '') === 'enrolled' ? 'success' : (($row['package_status'] ?? '') === 'generated' || ($row['package_status'] ?? '') === 'downloaded' ? 'warning' : 'secondary') ?>">
                                    <?= strtoupper($row['package_status'] ?? 'NOT GENERATED') ?>
                                </span>
                            </td>
                            <td><strong style="color:var(--status-active)"><?= formatDuration($row['today_active_seconds']) ?></strong></td>
                            <td><strong style="color:var(--status-idle)"><?= formatDuration($row['today_idle_seconds']) ?></strong></td>
                            <td><?= $row['last_seen_at'] ? date('H:i:s', strtotime($row['last_seen_at'])) : 'Never' ?></td>
                            <td><?= $row['last_screenshot_at'] ? date('H:i:s', strtotime($row['last_screenshot_at'])) : 'None' ?></td>
                            <td><?= $row['screenshot_interval_seconds'] ? $row['screenshot_interval_seconds'] . ' sec' : '30 sec' ?></td>
                            <td>
                                <div style="display:flex; gap:0.25rem; flex-wrap:wrap; align-items:center;">
                                    <?php if ($row['device_id']): ?>
                                        <a href="device.php?id=<?= $row['device_id'] ?>" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;">Detail</a>
                                    <?php endif; ?>

                                    <a href="edit_employee.php?id=<?= $row['employee_id'] ?>" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;">Edit</a>

                                    <?php if ($row['device_id']): ?>
                                        <a href="generate_agent.php?device_id=<?= $row['device_id'] ?>" class="btn btn-primary" style="padding:0.25rem 0.5rem; font-size:0.75rem;">Get Agent</a>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;" onclick="openRemoveModal(<?= $row['employee_id'] ?>)">
                                        Remove
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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

<div class="alert-warning" style="margin-top:2rem;">
    <strong>Workplace Monitoring Notice:</strong> Metrics displayed are accurately defined as 
    <em>"Active / Idle based on keyboard/mouse input"</em>. Do not use input inactivity alone to determine overall employee working time.
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
