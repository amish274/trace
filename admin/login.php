<?php
// admin/login.php - Secure Admin Login

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';

if (isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_user_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid admin credentials.';
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Employee Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            justify-content: center;
            align-items: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            margin: auto;
        }
    </style>
</head>
<body>
    <div class="card login-card">
        <h2 class="card-title" style="justify-content: center; margin-bottom: 1.5rem;">
            TeamTrace Admin Login
        </h2>
        
        <?php if ($error): ?>
            <div class="alert-warning" style="background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; border-color: #ef4444;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.4rem; color:var(--text-muted); font-size:0.85rem; font-weight:600;">USERNAME</label>
                <input type="text" name="username" required style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display:block; margin-bottom:0.4rem; color:var(--text-muted); font-size:0.85rem; font-weight:600;">PASSWORD</label>
                <input type="password" name="password" required style="width:100%; padding:0.65rem; border-radius:6px; background:var(--bg-primary); border:1px solid var(--border-color); color:#fff;">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:0.75rem;">
                Sign In to Dashboard
            </button>
        </form>
    </div>
</body>
</html>
