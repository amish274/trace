<?php
// admin/enroll.php - Add Employee and Device Registration & Agent Package Generator

require_once __DIR__ . '/header.php';

$db = getDbConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $employeeName = trim($_POST['employee_name'] ?? '');
        $employeeEmail = trim($_POST['employee_email'] ?? '');
        $deviceName = trim($_POST['device_name'] ?? '');

        if (empty($employeeName) || empty($deviceName)) {
            $error = 'Please fill out Employee Name and Device Name.';
        } else {
            if (empty($employeeEmail)) {
                $employeeEmail = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $employeeName)) . '@company.local';
            }

            // Find or create employee
            $stmt = $db->prepare("SELECT id FROM employees WHERE email = :email");
            $stmt->execute([':email' => $employeeEmail]);
            $employeeId = $stmt->fetchColumn();

            if (!$employeeId) {
                $insEmp = $db->prepare("INSERT INTO employees (name, email) VALUES (:name, :email)");
                $insEmp->execute([':name' => $employeeName, ':email' => $employeeEmail]);
                $employeeId = $db->lastInsertId();
            }

            // Create device entry
            $insDev = $db->prepare("
                INSERT INTO devices (employee_id, device_name, status, package_status)
                VALUES (:emp_id, :device_name, 'active', 'not_generated')
            ");
            $insDev->execute([
                ':emp_id' => $employeeId,
                ':device_name' => $deviceName
            ]);
            $deviceId = $db->lastInsertId();

            // Create default monitor settings for device
            $insSet = $db->prepare("
                INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality, idle_threshold_seconds)
                VALUES (:dev_id, 1, 1, 30, 70, 120)
            ");
            $insSet->execute([':dev_id' => $deviceId]);

            // Redirect to generate agent package page
            header("Location: generate_agent.php?device_id={$deviceId}&action=generate");
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.8rem; font-weight:700;">Add Employee & Register Device</h1>
    <p style="color:var(--text-muted); font-size:0.9rem;">Register a device to generate a pre-configured Windows Agent installer package.</p>
</div>

<?php if ($error): ?>
    <div class="alert-warning" style="background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; border-color: #ef4444;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <div class="card-title">Employee & Device Registration</div>
    <form method="POST" action="enroll.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div style="margin-bottom:1rem;">
            <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">EMPLOYEE FULL NAME *</label>
            <input type="text" name="employee_name" required placeholder="e.g. Rahul Sharma" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
        </div>

        <div style="margin-bottom:1rem;">
            <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">EMPLOYEE EMAIL (OPTIONAL)</label>
            <input type="email" name="employee_email" placeholder="e.g. rahul@company.com" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
        </div>

        <div style="margin-bottom:1.5rem;">
            <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">DEVICE / COMPUTER NAME *</label>
            <input type="text" name="device_name" required placeholder="e.g. OFFICE-PC-01" style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:0.75rem;">
            Continue to Generate Windows Agent &rarr;
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
