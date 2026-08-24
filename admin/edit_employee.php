<?php
// admin/edit_employee.php - Edit Employee Details Form

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/employee_helper.php';

$employeeId = (int)($_GET['id'] ?? $_POST['employee_id'] ?? 0);
if (!$employeeId) {
    header('Location: index.php');
    exit;
}

$employee = getEmployeeDetails($employeeId);
if (!$employee) {
    echo "<div class='alert-danger' style='margin-top:1.5rem;'>Error: Employee not found.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $error = "Security Error: Invalid or expired CSRF token.";
    } else {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';

        try {
            updateEmployeeDetails($employeeId, $name, $email);
            $_SESSION['flash_success'] = "Employee '{$name}' updated successfully.";
            header('Location: index.php');
            exit;
        } catch (Exception $ex) {
            $error = $ex->getMessage();
            $employee['name'] = $name;
            $employee['email'] = $email;
        }
    }
}

$csrfToken = generateCsrfToken();
?>

<div style="max-width:600px; margin:2rem auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <div>
            <h1 style="font-size:1.8rem; font-weight:700;">Edit Employee</h1>
            <p style="color:var(--text-muted); font-size:0.9rem;">Update employee profile details.</p>
        </div>
        <a href="index.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="edit_employee.php?id=<?= $employeeId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="employee_id" value="<?= $employeeId ?>">

            <div style="margin-bottom:1.25rem;">
                <label style="display:block; font-size:0.875rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">Employee Full Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($employee['name']) ?>" required
                       style="width:100%; padding:0.6rem 0.8rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff; font-size:1rem;">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block; font-size:0.875rem; font-weight:600; color:var(--text-muted); margin-bottom:0.4rem;">Employee Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($employee['email']) ?>" required
                       style="width:100%; padding:0.6rem 0.8rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff; font-size:1rem;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
