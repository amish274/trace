<?php
// admin/activity.php - Daily Active/Idle Timeline Analysis

require_once __DIR__ . '/header.php';

$deviceId = (int)($_GET['device_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$db = getDbConnection();
$devices = $db->query("SELECT d.id, d.device_name, e.name as employee_name FROM devices d JOIN employees e ON d.employee_id = e.id ORDER BY e.name ASC")->fetchAll();

if (!$deviceId && !empty($devices)) {
    $deviceId = $devices[0]['id'];
}

$hourlyStats = [];
$totalActive = 0;
$totalIdle = 0;
$firstSeen = 'N/A';
$lastSeen = 'N/A';

if ($deviceId) {
    // Fetch Hourly Active vs Idle breakdown
    $stmt = $db->prepare("
        SELECT 
            HOUR(captured_at) as hr,
            SUM(CASE WHEN activity_status = 'ACTIVE' THEN 30 ELSE 0 END) as active_sec,
            SUM(CASE WHEN activity_status = 'IDLE' THEN 30 ELSE 0 END) as idle_sec
        FROM activity
        WHERE device_id = :device_id AND DATE(captured_at) = :date
        GROUP BY HOUR(captured_at)
        ORDER BY hr ASC
    ");
    $stmt->execute([':device_id' => $deviceId, ':date' => $selectedDate]);
    $rawHourly = $stmt->fetchAll();

    foreach ($rawHourly as $row) {
        $hourlyStats[(int)$row['hr']] = [
            'active_sec' => (int)$row['active_sec'],
            'idle_sec' => (int)$row['idle_sec']
        ];
        $totalActive += (int)$row['active_sec'];
        $totalIdle += (int)$row['idle_sec'];
    }

    // First and last seen on this date
    $rangeStmt = $db->prepare("
        SELECT MIN(captured_at) as first_seen, MAX(captured_at) as last_seen 
        FROM activity 
        WHERE device_id = :device_id AND DATE(captured_at) = :date
    ");
    $rangeStmt->execute([':device_id' => $deviceId, ':date' => $selectedDate]);
    $range = $rangeStmt->fetch();
    if ($range['first_seen']) {
        $firstSeen = date('H:i:s', strtotime($range['first_seen']));
        $lastSeen = date('H:i:s', strtotime($range['last_seen']));
    }
}

function fmtSec($sec) {
    if (!$sec) return '0m';
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;">Daily Input Activity Timeline</h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Hourly breakdown of keyboard/mouse input activity.</p>
    </div>

    <form method="GET" action="activity.php" style="display:flex; gap:0.75rem; align-items:center;">
        <select name="device_id" onchange="this.form.submit()" style="padding:0.5rem 0.75rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
            <?php foreach ($devices as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $d['id'] == $deviceId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['employee_name']) ?> (<?= htmlspecialchars($d['device_name']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()" style="padding:0.5rem 0.75rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
    </form>
</div>

<div class="grid-4" style="margin-bottom:1.5rem;">
    <div class="stat-box">
        <div class="stat-label">Keyboard/Mouse Active Time</div>
        <div class="stat-value" style="color:var(--status-active)"><?= fmtSec($totalActive) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Keyboard/Mouse Idle Time</div>
        <div class="stat-value" style="color:var(--status-idle)"><?= fmtSec($totalIdle) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">First Seen Today</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= htmlspecialchars($firstSeen) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Last Seen Today</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= htmlspecialchars($lastSeen) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-title">
        <span>Hourly Activity Bar (00:00 - 23:59)</span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Date: <?= htmlspecialchars($selectedDate) ?></span>
    </div>

    <div style="display:flex; flex-direction:column; gap:0.75rem; margin-top:1rem;">
        <?php for ($hour = 8; $hour <= 20; $hour++): 
            $stats = $hourlyStats[$hour] ?? ['active_sec' => 0, 'idle_sec' => 0];
            $actSec = $stats['active_sec'];
            $idlSec = $stats['idle_sec'];
            $totalSec = $actSec + $idlSec;
            $actPct = $totalSec > 0 ? round(($actSec / 3600) * 100) : 0;
            $idlPct = $totalSec > 0 ? round(($idlSec / 3600) * 100) : 0;
            $timeLabel = sprintf('%02d:00', $hour);
        ?>
            <div style="display:flex; align-items:center; gap:1rem;">
                <div style="width:60px; font-weight:600; font-size:0.85rem; color:var(--text-muted);"><?= $timeLabel ?></div>
                <div style="flex:1; height:24px; background:var(--bg-primary); border-radius:6px; overflow:hidden; display:flex;">
                    <div style="width:<?= $actPct ?>%; background:var(--status-active);" title="Active: <?= fmtSec($actSec) ?>"></div>
                    <div style="width:<?= $idlPct ?>%; background:var(--status-idle);" title="Idle: <?= fmtSec($idlSec) ?>"></div>
                </div>
                <div style="width:140px; font-size:0.8rem; text-align:right; color:var(--text-muted);">
                    <?php if ($totalSec > 0): ?>
                        <span style="color:var(--status-active);"><?= fmtSec($actSec) ?> Act</span> / 
                        <span style="color:var(--status-idle);"><?= fmtSec($idlSec) ?> Idl</span>
                    <?php else: ?>
                        No Data
                    <?php endif; ?>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="alert-warning">
    Notice: Accuracy label enforced &bull; Metrics represent <strong>Keyboard/Mouse Active Time</strong> and <strong>Keyboard/Mouse Idle Time</strong>.
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
