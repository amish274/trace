<?php
// admin/screenshots.php - Chronological Screenshot Viewer & Bulk Deletion Manager

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

$db = getDbConnection();

// AJAX JSON Endpoint for lightweight polling
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $deviceId = (int)($_GET['device_id'] ?? 0);
    $selectedDate = $_GET['date'] ?? date('Y-m-d');
    
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
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $screenshots[] = [
                'id' => (int)$row['id'],
                'captured_at' => $row['captured_at'],
                'time_ist' => date('h:i:s A', strtotime($row['captured_at'])),
                'file_size' => (int)$row['file_size'],
                'size_kb' => number_format($row['file_size'] / 1024, 1),
                'activity_status' => $row['activity_status'],
                'device_name' => $row['device_name'],
                'employee_name' => $row['employee_name'],
                'image_url' => 'screenshot.php?id=' . $row['id']
            ];
        }
    }
    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'date' => $selectedDate,
        'count' => count($screenshots),
        'screenshots' => $screenshots
    ]);
    exit;
}

$deviceId = (int)($_GET['device_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$csrfToken = generateCsrfToken();

// Fetch all active devices for dropdown filter
$devices = $db->query("
    SELECT d.id, d.device_name, e.name as employee_name 
    FROM devices d 
    JOIN employees e ON d.employee_id = e.id 
    ORDER BY e.name ASC
")->fetchAll();

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

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<style>
/* Lightbox Modal & UX Enhancements */
.lightbox-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.96);
    backdrop-filter: blur(12px);
    z-index: 10000;
    display: flex;
    justify-content: center;
    align-items: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease-in-out;
}

.lightbox-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

.lightbox-container {
    width: 95vw;
    max-width: 1200px;
    height: 90vh;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.8);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.lightbox-header {
    padding: 0.9rem 1.25rem;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.lightbox-pos-badge {
    background: rgba(99, 102, 241, 0.2);
    color: var(--accent-indigo);
    border: 1px solid rgba(99, 102, 241, 0.4);
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.lightbox-close-btn {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 2rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.5rem;
    transition: color 0.15s;
}

.lightbox-close-btn:hover {
    color: #ef4444;
}

.lightbox-body {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    padding: 1rem;
    background: #080c14;
    overflow: hidden;
}

.lightbox-img-wrapper {
    flex: 1;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lightbox-img-wrapper img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 6px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.6);
}

.lightbox-nav-btn {
    background: rgba(30, 41, 59, 0.85);
    color: #fff;
    border: 1px solid var(--border-color);
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    cursor: pointer;
    z-index: 10;
    transition: all 0.2s;
    user-select: none;
    margin: 0 0.5rem;
    flex-shrink: 0;
}

.lightbox-nav-btn:hover:not(:disabled) {
    background: var(--accent-indigo);
    border-color: var(--accent-indigo);
    transform: scale(1.08);
}

.lightbox-nav-btn:disabled {
    opacity: 0.2;
    cursor: not-allowed;
    border-color: transparent;
}

.lightbox-footer {
    padding: 0.85rem 1.25rem;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.retention-toggle-btn {
    cursor: pointer;
    font-size: 0.85rem;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    background: var(--bg-hover);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    transition: background 0.2s;
}

.retention-toggle-btn:hover {
    background: var(--accent-indigo);
    border-color: var(--accent-indigo);
    color: #fff;
}
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h1 style="font-size:1.8rem; font-weight:700;">Screenshot Timeline & Management</h1>
        <p style="color:var(--text-muted); font-size:0.9rem;">Browse desktop screenshots and manage file retention (Timezone: Asia/Kolkata IST).</p>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="screenshots.php" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
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

<?php if ($flashSuccess): ?>
    <div class="alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError): ?>
    <div class="alert-danger"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<!-- Collapsible Bulk Screenshot Management Card -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-title" style="cursor: pointer; user-select: none;" onclick="toggleRetentionPanel()">
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span>Screenshot Retention & Bulk Deletion</span>
            <span style="font-size:0.85rem; color:var(--text-muted);">(Asia/Kolkata IST)</span>
        </div>
        <button type="button" id="retentionToggleBtn" class="retention-toggle-btn">
            Show Retention & Deletion Options ▼
        </button>
    </div>

    <div id="retentionPanelBody" style="display: none; margin-top: 1rem;">
        <div class="grid-2">
            <!-- Delete by Day & Selected -->
            <div style="background:var(--bg-secondary); padding:1rem; border-radius:8px; border:1px solid var(--border-color);">
                <h4 style="color:var(--accent-blue); margin-bottom:0.75rem;">1. Delete Selected or Specific Day</h4>
                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:1rem; flex-wrap:wrap;">
                    <button type="button" class="btn btn-secondary" onclick="triggerDeleteSelected()">
                        Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                </div>

                <hr style="border:0; border-top:1px solid var(--border-color); margin:0.75rem 0;">

                <label style="font-size:0.85rem; color:var(--text-muted); display:block; margin-bottom:0.3rem;">Delete By Specific Day:</label>
                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                    <input type="date" id="deleteDayDate" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:0.4rem 0.6rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
                    <button type="button" class="btn btn-secondary" onclick="previewDelete('day')">Preview & Delete Day</button>
                </div>
            </div>

            <!-- Delete by Week & Custom Date Range -->
            <div style="background:var(--bg-secondary); padding:1rem; border-radius:8px; border:1px solid var(--border-color);">
                <h4 style="color:var(--accent-blue); margin-bottom:0.75rem;">2. Delete by Week or Date Range</h4>
                
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.85rem; color:var(--text-muted); display:block; margin-bottom:0.3rem;">Delete By Week (Mon-Sun in IST):</label>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input type="date" id="deleteWeekDate" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:0.4rem 0.6rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
                        <button type="button" class="btn btn-secondary" onclick="previewDelete('week')">Preview & Delete Week</button>
                    </div>
                </div>

                <div>
                    <label style="font-size:0.85rem; color:var(--text-muted); display:block; margin-bottom:0.3rem;">Delete By Custom Range:</label>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input type="date" id="deleteStartDate" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:0.4rem 0.6rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
                        <span style="color:var(--text-muted);">to</span>
                        <input type="date" id="deleteEndDate" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:0.4rem 0.6rem; border-radius:6px; background:var(--bg-card); border:1px solid var(--border-color); color:#fff;">
                        <button type="button" class="btn btn-secondary" onclick="previewDelete('range')">Preview & Delete Range</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Danger Zone: Delete All -->
        <div class="danger-zone" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
            <div>
                <strong style="color:#ef4444; font-size:0.95rem;">Danger Zone: Delete All Screenshots</strong>
                <p style="font-size:0.8rem; color:var(--text-muted);">Permanently purge all screenshots across all devices and history. Require strong confirmation.</p>
            </div>
            <button type="button" class="btn btn-danger" onclick="previewDelete('all')">Delete All Screenshots</button>
        </div>
    </div>
</div>

<!-- Screenshot Timeline Grid Card -->
<div class="card">
    <div class="card-title">
        <div style="display:flex; align-items:center; gap:0.75rem;">
            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" style="width:18px; height:18px; cursor:pointer; accent-color:var(--accent-indigo);">
            <span>Captured Screenshots (<span id="screenshotTotalCount"><?= count($screenshots) ?></span>)</span>
        </div>
        <span style="font-size:0.85rem; color:var(--text-muted);">Date: <?= htmlspecialchars(date('d M Y', strtotime($selectedDate))) ?></span>
    </div>

    <div id="screenshotGridContainer">
        <?php if (empty($screenshots)): ?>
            <div style="text-align:center; padding:3rem; color:var(--text-muted);">
                No screenshots found for the selected device and date.
            </div>
        <?php else: ?>
            <div class="screenshot-grid">
                <?php foreach ($screenshots as $shot): ?>
                    <?php $shotIstTime = date('h:i:s A', strtotime($shot['captured_at'])); ?>
                    <div class="screenshot-card" data-id="<?= $shot['id'] ?>">
                        <input type="checkbox" class="screenshot-card-checkbox shot-checkbox" value="<?= $shot['id'] ?>" onchange="updateSelectedCount()" onclick="event.stopPropagation()">
                        <img class="screenshot-thumb" 
                             src="screenshot.php?id=<?= $shot['id'] ?>" 
                             alt="Screenshot <?= $shotIstTime ?>"
                             onclick="openLightboxById(<?= $shot['id'] ?>)"
                        >
                        <div class="screenshot-info">
                            <div>
                                <strong><?= $shotIstTime ?></strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= number_format($shot['file_size'] / 1024, 1) ?> KB</div>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.4rem;">
                                <span class="badge <?= $shot['activity_status'] === 'ACTIVE' ? 'badge-active' : 'badge-idle' ?>">
                                    <?= $shot['activity_status'] ?>
                                </span>
                                <button type="button" class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                        onclick="openLightboxById(<?= $shot['id'] ?>); event.stopPropagation();">
                                    VIEW
                                </button>
                                <button type="button" class="btn btn-danger" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                        onclick="previewDelete('selected', [<?= $shot['id'] ?>]); event.stopPropagation();">
                                    DEL
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Enhanced Lightbox Modal -->
<div id="lightboxModal" class="lightbox-overlay" onclick="closeLightbox()">
    <div class="lightbox-container" onclick="event.stopPropagation()">
        <!-- Lightbox Header -->
        <div class="lightbox-header">
            <div class="lightbox-title">
                <span id="lightboxEmpDevice" style="font-weight:600; color:#fff;">--</span>
                <span id="lightboxTime" style="color:var(--accent-blue); font-size:0.9rem; margin-left:0.5rem;">--</span>
            </div>
            <div style="display:flex; align-items:center; gap:1rem;">
                <span id="lightboxPosition" class="lightbox-pos-badge">Screenshot 0 of 0</span>
                <button type="button" class="lightbox-close-btn" onclick="closeLightbox()" title="Close (Esc)">&times;</button>
            </div>
        </div>

        <!-- Lightbox Body with Nav -->
        <div class="lightbox-body">
            <button type="button" id="lightboxPrevBtn" class="lightbox-nav-btn prev" onclick="prevScreenshot()" title="Previous (Left Arrow)">
                &#10094;
            </button>
            
            <div class="lightbox-img-wrapper">
                <img id="lightboxImg" src="" alt="Screenshot Full View">
            </div>

            <button type="button" id="lightboxNextBtn" class="lightbox-nav-btn next" onclick="nextScreenshot()" title="Next (Right Arrow)">
                &#10095;
            </button>
        </div>

        <!-- Lightbox Footer -->
        <div class="lightbox-footer">
            <span id="lightboxStatusBadge" class="badge badge-active">ACTIVE</span>
            <span id="lightboxSize" style="color:var(--text-muted); font-size:0.85rem;">0.0 KB</span>
            <span id="lightboxTimestamp" style="color:var(--text-muted); font-size:0.85rem;">--</span>
        </div>
    </div>
</div>

<!-- Bulk Deletion Preview & Confirmation Modal -->
<div id="deletePreviewModal" class="modal-backdrop" onclick="closeDeleteModal()">
    <div class="modal-content" style="max-width:550px; text-align:left; align-items:stretch;" onclick="event.stopPropagation()">
        <h3 style="color:#ef4444; margin-bottom:0.75rem; font-size:1.25rem;">Confirm Screenshot Deletion</h3>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1rem;">Please review the summary of screenshots to be permanently purged.</p>

        <div id="previewLoading" style="text-align:center; padding:1.5rem; color:var(--text-muted);">
            Loading deletion stats...
        </div>

        <div id="previewDetails" style="display:none; background:var(--bg-secondary); border:1px solid var(--border-color); padding:1rem; border-radius:8px; margin-bottom:1rem;">
            <table style="width:100%; font-size:0.875rem; border-collapse:collapse;">
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Deletion Target:</td>
                    <td style="font-weight:600; text-align:right;" id="prevDescription">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Start Date (IST):</td>
                    <td style="font-weight:600; text-align:right;" id="prevStartDate">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">End Date (IST):</td>
                    <td style="font-weight:600; text-align:right;" id="prevEndDate">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Database Records:</td>
                    <td style="font-weight:700; color:#38bdf8; text-align:right;" id="prevDbCount">0</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Physical Files Found:</td>
                    <td style="font-weight:700; color:#22c55e; text-align:right;" id="prevFileCount">0</td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted); padding:0.4rem 0;">Missing Files:</td>
                    <td style="font-weight:700; color:#eab308; text-align:right;" id="prevMissingCount">0</td>
                </tr>
            </table>
        </div>

        <div id="dangerConfirmBox" style="display:none; margin-bottom:1rem; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.4); padding:0.75rem; border-radius:6px;">
            <label style="display:block; font-size:0.8rem; color:#fca5a5; margin-bottom:0.3rem;">Type <strong>DELETE ALL</strong> to confirm purging entire system database:</label>
            <input type="text" id="dangerConfirmInput" placeholder="DELETE ALL" style="width:100%; padding:0.4rem; border-radius:4px; border:1px solid #7f1d1d; background:#000; color:#fff;">
        </div>

        <form id="deleteExecuteForm" method="POST" action="delete_screenshots.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="mode" id="execMode" value="">
            <input type="hidden" name="date" id="execDate" value="">
            <input type="hidden" name="week" id="execWeek" value="">
            <input type="hidden" name="start_date" id="execStartDate" value="">
            <input type="hidden" name="end_date" id="execEndDate" value="">
            <input type="hidden" name="ids" id="execIds" value="">
            <input type="hidden" name="device_id" value="<?= $deviceId ?>">

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="btn btn-danger" onclick="submitDeleteExecution()">Confirm & Execute Deletion</button>
            </div>
        </form>
    </div>
</div>

<script>
// State Management
let currentDeviceId = <?= (int)$deviceId ?>;
let currentSelectedDate = '<?= htmlspecialchars($selectedDate, ENT_QUOTES) ?>';
let screenshotsData = <?= json_encode(array_map(function($s) {
    return [
        'id' => (int)$s['id'],
        'captured_at' => $s['captured_at'],
        'time_ist' => date('h:i:s A', strtotime($s['captured_at'])),
        'file_size' => (int)$s['file_size'],
        'size_kb' => number_format($s['file_size'] / 1024, 1),
        'activity_status' => $s['activity_status'],
        'device_name' => $s['device_name'],
        'employee_name' => $s['employee_name'],
        'image_url' => 'screenshot.php?id=' . $s['id']
    ];
}, $screenshots), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let currentLightboxIndex = -1;
let isLightboxOpen = false;

// Retention Panel Toggle
function toggleRetentionPanel() {
    const body = document.getElementById('retentionPanelBody');
    const btn = document.getElementById('retentionToggleBtn');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        btn.innerText = 'Hide Retention & Deletion Options ▲';
    } else {
        body.style.display = 'none';
        btn.innerText = 'Show Retention & Deletion Options ▼';
    }
}

// Lightbox Controller
function openLightboxById(shotId) {
    const idx = screenshotsData.findIndex(s => s.id === shotId);
    if (idx !== -1) {
        openLightbox(idx);
    }
}

function openLightbox(index) {
    if (index < 0 || index >= screenshotsData.length) return;
    
    currentLightboxIndex = index;
    isLightboxOpen = true;
    const shot = screenshotsData[index];

    const modal = document.getElementById('lightboxModal');
    const img = document.getElementById('lightboxImg');
    const empDevice = document.getElementById('lightboxEmpDevice');
    const timeSpan = document.getElementById('lightboxTime');
    const posBadge = document.getElementById('lightboxPosition');
    const prevBtn = document.getElementById('lightboxPrevBtn');
    const nextBtn = document.getElementById('lightboxNextBtn');
    const statusBadge = document.getElementById('lightboxStatusBadge');
    const sizeSpan = document.getElementById('lightboxSize');
    const timestampSpan = document.getElementById('lightboxTimestamp');

    img.src = shot.image_url;
    empDevice.innerText = `${shot.employee_name} (${shot.device_name})`;
    timeSpan.innerText = `${shot.time_ist} (IST)`;
    posBadge.innerText = `Screenshot ${index + 1} of ${screenshotsData.length}`;
    
    statusBadge.innerText = shot.activity_status;
    statusBadge.className = `badge ${shot.activity_status === 'ACTIVE' ? 'badge-active' : 'badge-idle'}`;
    sizeSpan.innerText = `${shot.size_kb} KB`;
    timestampSpan.innerText = shot.captured_at + ' IST';

    prevBtn.disabled = (index === 0);
    nextBtn.disabled = (index === screenshotsData.length - 1);

    modal.classList.add('active');
}

function closeLightbox() {
    const modal = document.getElementById('lightboxModal');
    modal.classList.remove('active');
    isLightboxOpen = false;
}

function prevScreenshot() {
    if (currentLightboxIndex > 0) {
        openLightbox(currentLightboxIndex - 1);
    }
}

function nextScreenshot() {
    if (currentLightboxIndex < screenshotsData.length - 1) {
        openLightbox(currentLightboxIndex + 1);
    }
}

// Global Keyboard Navigation
document.addEventListener('keydown', function(e) {
    if (!isLightboxOpen) return;

    if (e.key === 'Escape') {
        closeLightbox();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prevScreenshot();
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        nextScreenshot();
    }
});

// Active Tab Detection & Refresh Polling
function isScreenshotPageActive() {
    return document.visibilityState === 'visible' && document.hasFocus();
}

let pollIntervalTimer = null;
const POLL_INTERVAL_MS = 10000; // 10 seconds

function startPolling() {
    stopPolling();
    if (isScreenshotPageActive()) {
        pollIntervalTimer = setInterval(pollScreenshotUpdates, POLL_INTERVAL_MS);
    }
}

function stopPolling() {
    if (pollIntervalTimer) {
        clearInterval(pollIntervalTimer);
        pollIntervalTimer = null;
    }
}

function handleStateChange() {
    if (isScreenshotPageActive()) {
        pollScreenshotUpdates();
        startPolling();
    } else {
        stopPolling();
    }
}

document.addEventListener('visibilitychange', handleStateChange);
window.addEventListener('focus', handleStateChange);
window.addEventListener('blur', handleStateChange);
window.addEventListener('pageshow', handleStateChange);
window.addEventListener('beforeunload', stopPolling);

// AJAX Screenshot Update
function pollScreenshotUpdates() {
    if (!isScreenshotPageActive()) return;
    if (!currentDeviceId) return;

    const url = `screenshots.php?ajax=1&device_id=${currentDeviceId}&date=${encodeURIComponent(currentSelectedDate)}`;

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !Array.isArray(data.screenshots)) return;

            const newScreenshots = data.screenshots;
            const existingIds = screenshotsData.map(s => s.id);
            const fetchedIds = newScreenshots.map(s => s.id);

            const hasChanged = (existingIds.length !== fetchedIds.length) || 
                               existingIds.some((id, idx) => id !== fetchedIds[idx]);

            if (hasChanged) {
                const currentOpenId = (isLightboxOpen && currentLightboxIndex >= 0 && currentLightboxIndex < screenshotsData.length)
                    ? screenshotsData[currentLightboxIndex].id
                    : null;

                screenshotsData = newScreenshots;

                updateScreenshotGridDOM();

                if (isLightboxOpen && currentOpenId) {
                    const newIndex = screenshotsData.findIndex(s => s.id === currentOpenId);
                    if (newIndex !== -1) {
                        openLightbox(newIndex);
                    } else {
                        if (screenshotsData.length > 0) {
                            openLightbox(Math.min(currentLightboxIndex, screenshotsData.length - 1));
                        } else {
                            closeLightbox();
                        }
                    }
                }
            }
        })
        .catch(err => {
            console.warn('Screenshot poll failed:', err);
        });
}

function updateScreenshotGridDOM() {
    const gridContainer = document.getElementById('screenshotGridContainer');
    const countSpan = document.getElementById('screenshotTotalCount');

    if (countSpan) {
        countSpan.innerText = screenshotsData.length;
    }

    if (!gridContainer) return;

    if (screenshotsData.length === 0) {
        gridContainer.innerHTML = `
            <div style="text-align:center; padding:3rem; color:var(--text-muted);">
                No screenshots found for the selected device and date.
            </div>
        `;
        updateSelectedCount();
        return;
    }

    const checkedIds = new Set(
        Array.from(document.querySelectorAll('.shot-checkbox:checked')).map(cb => parseInt(cb.value))
    );

    let html = '<div class="screenshot-grid">';
    screenshotsData.forEach(shot => {
        const isChecked = checkedIds.has(shot.id) ? 'checked' : '';
        html += `
            <div class="screenshot-card" data-id="${shot.id}">
                <input type="checkbox" class="screenshot-card-checkbox shot-checkbox" value="${shot.id}" ${isChecked} onchange="updateSelectedCount()" onclick="event.stopPropagation()">
                <img class="screenshot-thumb" 
                     src="${shot.image_url}" 
                     alt="Screenshot ${shot.time_ist}"
                     onclick="openLightboxById(${shot.id})"
                >
                <div class="screenshot-info">
                    <div>
                        <strong>${shot.time_ist}</strong>
                        <div style="font-size:0.75rem; color:var(--text-muted);">${shot.size_kb} KB</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.4rem;">
                        <span class="badge ${shot.activity_status === 'ACTIVE' ? 'badge-active' : 'badge-idle'}">
                            ${shot.activity_status}
                        </span>
                        <button type="button" class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                onclick="openLightboxById(${shot.id}); event.stopPropagation();">
                            VIEW
                        </button>
                        <button type="button" class="btn btn-danger" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                onclick="previewDelete('selected', [${shot.id}]); event.stopPropagation();">
                            DEL
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    html += '</div>';

    gridContainer.innerHTML = html;
    updateSelectedCount();
}

// Bulk Deletion JS Helpers
function closeDeleteModal() {
    document.getElementById('deletePreviewModal').classList.remove('active');
}

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.shot-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.shot-checkbox:checked');
    const counter = document.getElementById('selectedCount');
    if (counter) {
        counter.innerText = checked.length;
    }
}

function triggerDeleteSelected() {
    const checked = document.querySelectorAll('.shot-checkbox:checked');
    const ids = Array.from(checked).map(cb => parseInt(cb.value)).filter(id => id > 0);
    if (ids.length === 0) {
        alert('Please select at least one screenshot using checkboxes first.');
        return;
    }
    previewDelete('selected', ids);
}

let activePreviewMode = null;

function previewDelete(mode, customIds = null) {
    activePreviewMode = mode;
    const modal = document.getElementById('deletePreviewModal');
    const loading = document.getElementById('previewLoading');
    const details = document.getElementById('previewDetails');
    const dangerBox = document.getElementById('dangerConfirmBox');
    const confirmBtn = document.getElementById('confirmDeleteBtn');

    loading.style.display = 'block';
    details.style.display = 'none';
    dangerBox.style.display = (mode === 'all') ? 'block' : 'none';
    if (mode === 'all') {
        document.getElementById('dangerConfirmInput').value = '';
    }

    modal.classList.add('active');

    let formData = new FormData();
    formData.append('csrf_token', '<?= $csrfToken ?>');
    formData.append('action', 'preview');
    formData.append('mode', mode);
    formData.append('device_id', '<?= $deviceId ?>');

    document.getElementById('execMode').value = mode;

    if (mode === 'selected') {
        const ids = customIds || Array.from(document.querySelectorAll('.shot-checkbox:checked')).map(cb => cb.value);
        const idsStr = ids.join(',');
        formData.append('ids', idsStr);
        document.getElementById('execIds').value = idsStr;
    } else if (mode === 'day') {
        const val = document.getElementById('deleteDayDate').value;
        formData.append('date', val);
        document.getElementById('execDate').value = val;
    } else if (mode === 'week') {
        const val = document.getElementById('deleteWeekDate').value;
        formData.append('date', val);
        document.getElementById('execWeek').value = val;
    } else if (mode === 'range') {
        const sVal = document.getElementById('deleteStartDate').value;
        const eVal = document.getElementById('deleteEndDate').value;
        formData.append('start_date', sVal);
        formData.append('end_date', eVal);
        document.getElementById('execStartDate').value = sVal;
        document.getElementById('execEndDate').value = eVal;
    }

    fetch('delete_screenshots.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        loading.style.display = 'none';
        if (data.success) {
            details.style.display = 'block';
            document.getElementById('prevDescription').innerText = data.description;
            document.getElementById('prevStartDate').innerText = data.start_date;
            document.getElementById('prevEndDate').innerText = data.end_date;
            document.getElementById('prevDbCount').innerText = data.total_db_records.toLocaleString();
            document.getElementById('prevFileCount').innerText = data.physical_files_found.toLocaleString();
            document.getElementById('prevMissingCount').innerText = data.missing_files.toLocaleString();

            if (data.total_db_records === 0) {
                confirmBtn.disabled = true;
                confirmBtn.innerText = 'No Screenshots to Delete';
            } else {
                confirmBtn.disabled = false;
                confirmBtn.innerText = `Confirm & Delete ${data.total_db_records.toLocaleString()} Screenshots`;
            }
        } else {
            alert('Preview Error: ' + (data.error || 'Failed to load preview details.'));
            closeDeleteModal();
        }
    })
    .catch(err => {
        loading.style.display = 'none';
        alert('Network Error: Unable to fetch preview data.');
        closeDeleteModal();
    });
}

function submitDeleteExecution() {
    if (activePreviewMode === 'all') {
        const confirmTxt = document.getElementById('dangerConfirmInput').value.trim();
        if (confirmTxt !== 'DELETE ALL') {
            alert('Security Requirement: You must type DELETE ALL to confirm purging entire system database.');
            return;
        }
    }
    document.getElementById('deleteExecuteForm').submit();
}

// Start auto-refresh polling
startPolling();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
