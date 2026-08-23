<?php
// admin/screenshots.php - Chronological Screenshot Viewer & Bulk Deletion Manager

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/screenshot_helper.php';

$deviceId = (int)($_GET['device_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$db = getDbConnection();
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

<!-- Bulk Screenshot Management Card -->
<div class="card">
    <div class="card-title">
        <span>Screenshot Retention & Bulk Deletion</span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Asia/Kolkata IST</span>
    </div>

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

<!-- Screenshot Timeline Grid Card -->
<div class="card">
    <div class="card-title">
        <div style="display:flex; align-items:center; gap:0.75rem;">
            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" style="width:18px; height:18px; cursor:pointer; accent-color:var(--accent-indigo);">
            <span>Captured Screenshots (<?= count($screenshots) ?>)</span>
        </div>
        <span style="font-size:0.85rem; color:var(--text-muted);">Date: <?= htmlspecialchars(date('d M Y', strtotime($selectedDate))) ?></span>
    </div>

    <?php if (empty($screenshots)): ?>
        <div style="text-align:center; padding:3rem; color:var(--text-muted);">
            No screenshots found for the selected device and date.
        </div>
    <?php else: ?>
        <div class="screenshot-grid">
            <?php foreach ($screenshots as $shot): ?>
                <?php $shotIstTime = date('h:i:s A', strtotime($shot['captured_at'])); ?>
                <div class="screenshot-card">
                    <input type="checkbox" class="screenshot-card-checkbox shot-checkbox" value="<?= $shot['id'] ?>" onchange="updateSelectedCount()">
                    <img class="screenshot-thumb" 
                         src="screenshot.php?id=<?= $shot['id'] ?>" 
                         alt="Screenshot <?= $shotIstTime ?>"
                         onclick="openImageModal('screenshot.php?id=<?= $shot['id'] ?>', 'Screenshot at <?= $shotIstTime ?> (IST) (<?= $shot['activity_status'] ?>)')"
                    >
                    <div class="screenshot-info">
                        <div>
                            <strong><?= $shotIstTime ?></strong>
                            <div style="font-size:0.75rem; color:var(--text-muted);"><?= round($shot['file_size'] / 1024, 1) ?> KB</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:0.4rem;">
                            <span class="badge <?= $shot['activity_status'] === 'ACTIVE' ? 'badge-active' : 'badge-idle' ?>">
                                <?= $shot['activity_status'] ?>
                            </span>
                            <button type="button" class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                    onclick="openImageModal('screenshot.php?id=<?= $shot['id'] ?>', 'Screenshot at <?= $shotIstTime ?>')">
                                VIEW
                            </button>
                            <button type="button" class="btn btn-danger" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                                    onclick="previewDelete('selected', [<?= $shot['id'] ?>])">
                                DEL
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Image View Modal -->
<div id="imageModal" class="modal-backdrop" onclick="closeImageModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <img id="modalImage" class="modal-image" src="" alt="Screenshot">
        <div id="modalCaption" style="color:var(--text-muted); font-size:0.9rem; margin-bottom:1rem;"></div>
        <button class="btn btn-secondary" onclick="closeImageModal()">Close Preview</button>
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
function openImageModal(src, caption) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalCaption').innerText = caption;
    document.getElementById('imageModal').classList.add('active');
}

function closeImageModal() {
    document.getElementById('imageModal').classList.remove('active');
}

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
    document.getElementById('selectedCount').innerText = checked.length;
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
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
