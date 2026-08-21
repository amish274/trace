<?php
// admin/settings.php - Device Monitoring Settings & Storage Estimator

require_once __DIR__ . '/header.php';

$db = getDbConnection();
$message = '';
$error = '';

$deviceId = (int)($_GET['device_id'] ?? $_POST['device_id'] ?? 0);

// Fetch available devices
$devices = $db->query("SELECT d.id, d.device_name, e.name as employee_name FROM devices d JOIN employees e ON d.employee_id = e.id ORDER BY e.name ASC")->fetchAll();

if (!$deviceId && !empty($devices)) {
    $deviceId = $devices[0]['id'];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        $monitoringEnabled = isset($_POST['monitoring_enabled']) ? 1 : 0;
        $screenshotEnabled = isset($_POST['screenshot_enabled']) ? 1 : 0;
        $screenshotInterval = (int)($_POST['screenshot_interval_seconds'] ?? 30);
        $screenshotQuality = (int)($_POST['screenshot_quality'] ?? 70);
        $resolutionStr = $_POST['resolution'] ?? '0x0';
        list($w, $h) = explode('x', $resolutionStr);
        $screenshotWidth = (int)$w;
        $screenshotHeight = (int)$h;
        $idleThreshold = (int)($_POST['idle_threshold_seconds'] ?? 120);
        $retentionDays = (int)($_POST['retention_days'] ?? 30);

        // Update device monitor settings
        $stmt = $db->prepare("
            INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality, screenshot_width, screenshot_height, idle_threshold_seconds)
            VALUES (:device_id, :m_enabled, :s_enabled, :interval, :quality, :w, :h, :idle)
            ON DUPLICATE KEY UPDATE
                monitoring_enabled = VALUES(monitoring_enabled),
                screenshot_enabled = VALUES(screenshot_enabled),
                screenshot_interval_seconds = VALUES(screenshot_interval_seconds),
                screenshot_quality = VALUES(screenshot_quality),
                screenshot_width = VALUES(screenshot_width),
                screenshot_height = VALUES(screenshot_height),
                idle_threshold_seconds = VALUES(idle_threshold_seconds),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':device_id' => $deviceId,
            ':m_enabled' => $monitoringEnabled,
            ':s_enabled' => $screenshotEnabled,
            ':interval' => $screenshotInterval,
            ':quality' => $screenshotQuality,
            ':w' => $screenshotWidth,
            ':h' => $screenshotHeight,
            ':idle' => $idleThreshold
        ]);

        // Update global retention setting
        $retStmt = $db->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('retention_days', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $retStmt->execute([':val' => (string)$retentionDays]);

        $message = 'Settings updated successfully! Connected agent will fetch updated settings within 30-60 seconds.';
    }
}

// Fetch current device settings
$settings = null;
if ($deviceId) {
    $stmt = $db->prepare("SELECT * FROM monitor_settings WHERE device_id = :id");
    $stmt->execute([':id' => $deviceId]);
    $settings = $stmt->fetch();
}

if (!$settings) {
    $settings = [
        'monitoring_enabled' => 1,
        'screenshot_enabled' => 1,
        'screenshot_interval_seconds' => 30,
        'screenshot_quality' => 70,
        'screenshot_width' => 0,
        'screenshot_height' => 0,
        'idle_threshold_seconds' => 120
    ];
}

// Fetch global retention setting
$retentionStmt = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'retention_days'");
$currentRetention = (int)($retentionStmt->fetchColumn() ?: 30);

$csrfToken = generateCsrfToken();
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;">Monitoring & Storage Settings</h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Configure screenshot frequencies, quality settings, and retention policies.</p>
    </div>

    <?php if (count($devices) > 1): ?>
        <form method="GET" action="settings.php">
            <select name="device_id" onchange="this.form.submit()" style="padding:0.5rem 0.75rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
                <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $deviceId ? 'selected' : '' ?>>
                        Device: <?= htmlspecialchars($d['employee_name']) ?> (<?= htmlspecialchars($d['device_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert-warning" style="background-color: rgba(34, 197, 94, 0.2); color: #86efac; border-color: #22c55e;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-warning" style="background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; border-color: #ef4444;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <!-- Form Card -->
    <div class="card">
        <div class="card-title">Device Configuration Form</div>
        <form method="POST" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="device_id" value="<?= $deviceId ?>">

            <div style="margin-bottom:1rem; display:flex; gap:2rem;">
                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                    <input type="checkbox" name="monitoring_enabled" value="1" <?= $settings['monitoring_enabled'] ? 'checked' : '' ?>>
                    <span>Enable Activity Monitoring</span>
                </label>
                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                    <input type="checkbox" name="screenshot_enabled" value="1" <?= $settings['screenshot_enabled'] ? 'checked' : '' ?>>
                    <span>Enable Screenshot Capture</span>
                </label>
            </div>

            <div style="margin-bottom:1.2rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">SCREENSHOT INTERVAL</label>
                <select id="intervalSelect" name="screenshot_interval_seconds" onchange="updateStorageEstimate()" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
                    <?php
                    $intervals = [1, 2, 5, 10, 15, 30, 60, 120, 300];
                    foreach ($intervals as $sec) {
                        $sel = ($settings['screenshot_interval_seconds'] == $sec) ? 'selected' : '';
                        echo "<option value='{$sec}' {$sel}>{$sec} second" . ($sec > 1 ? 's' : '') . "</option>";
                    }
                    ?>
                </select>
                <div id="intervalWarning" class="alert-warning" style="margin-top:0.5rem; display:none;"></div>
            </div>

            <div style="margin-bottom:1.2rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">JPEG QUALITY</label>
                <select name="screenshot_quality" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
                    <?php
                    $qualities = [50, 60, 70, 80, 90];
                    foreach ($qualities as $q) {
                        $sel = ($settings['screenshot_quality'] == $q) ? 'selected' : '';
                        echo "<option value='{$q}' {$sel}>{$q}% quality</option>";
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom:1.2rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">MAX RESOLUTION</label>
                <select name="resolution" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
                    <?php
                    $curRes = $settings['screenshot_width'] . 'x' . $settings['screenshot_height'];
                    $resOptions = [
                        '0x0' => 'Original Display Resolution',
                        '1920x1080' => '1920 x 1080 (1080p)',
                        '1600x900' => '1600 x 900',
                        '1280x720' => '1280 x 720 (720p)'
                    ];
                    foreach ($resOptions as $val => $lbl) {
                        $sel = ($curRes === $val) ? 'selected' : '';
                        echo "<option value='{$val}' {$sel}>{$lbl}</option>";
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom:1.2rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">IDLE THRESHOLD (SECONDS)</label>
                <select name="idle_threshold_seconds" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
                    <?php
                    $idles = [30, 60, 120, 300, 600];
                    foreach ($idles as $sec) {
                        $sel = ($settings['idle_threshold_seconds'] == $sec) ? 'selected' : '';
                        echo "<option value='{$sec}' {$sel}>{$sec} seconds</option>";
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">GLOBAL RETENTION PERIOD</label>
                <select name="retention_days" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
                    <?php
                    $retentions = [7 => '7 Days', 14 => '14 Days', 30 => '30 Days', 60 => '60 Days', 90 => '90 Days', 0 => 'Never (Keep Indefinitely)'];
                    foreach ($retentions as $val => $lbl) {
                        $sel = ($currentRetention == $val) ? 'selected' : '';
                        echo "<option value='{$val}' {$sel}>{$lbl}</option>";
                    }
                    ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:0.75rem;">
                Save Settings
            </button>
        </form>
    </div>

    <!-- Storage Estimator Card -->
    <div class="card">
        <div class="card-title">Estimated Storage Calculator</div>
        <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.25rem;">
            Estimated disk space based on selected screenshot interval assuming ~100 KB per JPEG image. Actual screenshot sizes vary by screen activity.
        </p>

        <div style="display:flex; flex-direction:column; gap:1rem;">
            <div class="stat-box">
                <div class="stat-label">Screenshots per Hour</div>
                <div class="stat-value" id="estShotsHour">120</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Screenshots per 8-Hour Workday</div>
                <div class="stat-value" id="estShotsDay">960</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Estimated Storage per Day</div>
                <div class="stat-value" id="estStorageDay" style="color:var(--accent-blue)">96 MB</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Estimated Storage per 30 Days</div>
                <div class="stat-value" id="estStorageMonth" style="color:var(--accent-indigo)">2.88 GB</div>
            </div>
        </div>
    </div>
</div>

<script>
function updateStorageEstimate() {
    const intervalSelect = document.getElementById('intervalSelect');
    const intervalSec = parseInt(intervalSelect.value, 10);
    const warningDiv = document.getElementById('intervalWarning');

    // Display high volume warning
    if (intervalSec <= 5) {
        warningDiv.style.display = 'block';
        warningDiv.textContent = `WARNING: Very high screenshot volume (${intervalSec}s interval). This may consume several GB per employee per day.`;
    } else {
        warningDiv.style.display = 'none';
    }

    const shotsPerHour = Math.round(3600 / intervalSec);
    const shotsPerDay = shotsPerHour * 8;
    
    // Assume average screenshot size ~ 100 KB
    const kbPerDay = shotsPerDay * 100;
    const mbPerDay = (kbPerDay / 1024).toFixed(1);
    const gbPerDay = (kbPerDay / (1024 * 1024)).toFixed(2);

    const mbPerMonth = ((kbPerDay * 30) / 1024).toFixed(1);
    const gbPerMonth = ((kbPerDay * 30) / (1024 * 1024)).toFixed(2);

    document.getElementById('estShotsHour').textContent = shotsPerHour.toLocaleString();
    document.getElementById('estShotsDay').textContent = shotsPerDay.toLocaleString();

    document.getElementById('estStorageDay').textContent = (mbPerDay > 1000) ? `${gbPerDay} GB` : `${mbPerDay} MB`;
    document.getElementById('estStorageMonth').textContent = (mbPerMonth > 1000) ? `${gbPerMonth} GB` : `${mbPerMonth} MB`;
}

// Run on page load
updateStorageEstimate();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
