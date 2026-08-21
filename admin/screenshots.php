<?php
// admin/screenshots.php - Chronological Screenshot Viewer

require_once __DIR__ . '/header.php';

$deviceId = (int)($_GET['device_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$db = getDbConnection();

// Fetch all active devices for dropdown filter
$devices = $db->query("SELECT d.id, d.device_name, e.name as employee_name FROM devices d JOIN employees e ON d.employee_id = e.id ORDER BY e.name ASC")->fetchAll();

if (!$deviceId && !empty($devices)) {
    $deviceId = $devices[0]['id'];
}

$screenshots = [];
if ($deviceId) {
    $stmt = $db->prepare("
        SELECT s.*, d.device_name, e.name as employee_name 
        FROM screenshots s
        JOIN devices d ON s.device_id = d.id
        JOIN employees e ON d.employee_id = e.id
        WHERE s.device_id = :device_id AND DATE(s.captured_at) = :date
        ORDER BY s.captured_at DESC
    ");
    $stmt->execute([
        ':device_id' => $deviceId,
        ':date' => $selectedDate
    ]);
    $screenshots = $stmt->fetchAll();
}
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;">Screenshot Timeline</h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Browse captured desktop screenshots chronologically.</p>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="screenshots.php" style="display:flex; gap:0.75rem; align-items:center;">
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

<div class="card">
    <div class="card-title">
        <span>Captured Screenshots (<?= count($screenshots) ?>)</span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Date: <?= htmlspecialchars($selectedDate) ?></span>
    </div>

    <?php if (empty($screenshots)): ?>
        <div style="text-align:center; padding:3rem; color:var(--text-muted);">
            No screenshots found for the selected device and date.
        </div>
    <?php else: ?>
        <div class="screenshot-grid">
            <?php foreach ($screenshots as $shot): ?>
                <div class="screenshot-card">
                    <img class="screenshot-thumb" 
                         src="screenshot.php?id=<?= $shot['id'] ?>" 
                         alt="Screenshot <?= date('H:i:s', strtotime($shot['captured_at'])) ?>"
                         onclick="openImageModal('screenshot.php?id=<?= $shot['id'] ?>', 'Screenshot at <?= date('H:i:s', strtotime($shot['captured_at'])) ?> (<?= $shot['activity_status'] ?>)')"
                    >
                    <div class="screenshot-info">
                        <div>
                            <strong><?= date('H:i:s', strtotime($shot['captured_at'])) ?></strong>
                            <div style="font-size:0.75rem; color:var(--text-muted);"><?= round($shot['file_size'] / 1024, 1) ?> KB</div>
                        </div>
                        <div>
                            <span class="badge <?= $shot['activity_status'] === 'ACTIVE' ? 'badge-active' : 'badge-idle' ?>">
                                <?= $shot['activity_status'] ?>
                            </span>
                            <button class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.75rem; margin-left:0.4rem;"
                                    onclick="openImageModal('screenshot.php?id=<?= $shot['id'] ?>', 'Screenshot at <?= date('H:i:s', strtotime($shot['captured_at'])) ?>')">
                                VIEW
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
