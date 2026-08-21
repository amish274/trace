<?php
// tools/reset_admin.php - Secure CLI Admin Credential Reset Utility

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    die("Access denied: CLI execution only.\n");
}

echo "=====================================================\n";
echo "   TeamTrace Admin Credential Reset Utility (CLI)    \n";
echo "=====================================================\n\n";

$options = getopt("", ["username:", "password:"]);

$username = $options['username'] ?? 'admin';
$password = $options['password'] ?? 'password123';

if (!isset($options['username']) && !isset($options['password'])) {
    echo "Defaulting to user '{$username}' and reset password.\n";
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$db = getDbConnection();

// Upsert admin record
$stmt = $db->prepare("
    INSERT INTO admin_users (username, password_hash, email) 
    VALUES (:username, :hash, :email)
    ON DUPLICATE KEY UPDATE 
        password_hash = VALUES(password_hash),
        updated_at = NOW()
");

$stmt->execute([
    ':username' => $username,
    ':hash' => $hash,
    ':email' => "{$username}@example.com"
]);

// Verify database record
$verifyStmt = $db->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = :username");
$verifyStmt->execute([':username' => $username]);
$admin = $verifyStmt->fetch();

if ($admin && password_verify($password, $admin['password_hash'])) {
    echo "SUCCESS: Admin user '{$username}' updated successfully.\n";
    echo "Password verification: PASS\n";
} else {
    echo "FAILURE: Failed to verify updated password hash in database.\n";
    echo "Password verification: FAIL\n";
    exit(1);
}
