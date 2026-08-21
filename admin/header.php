<?php
// admin/header.php - Admin Header Layout
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminSession();

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lightweight Employee Monitor - Admin</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header>
        <a href="index.php" class="brand">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-9l6 4.5-6 4.5z"/></svg>
            <span>TeamTrace Monitor</span>
        </a>
        <nav>
            <a href="index.php" class="<?= $currentPage === 'index.php' || $currentPage === 'device.php' ? 'active' : '' ?>">Employees & Devices</a>
            <a href="screenshots.php" class="<?= $currentPage === 'screenshots.php' ? 'active' : '' ?>">Screenshots</a>
            <a href="activity.php" class="<?= $currentPage === 'activity.php' ? 'active' : '' ?>">Activity Log</a>
            <a href="settings.php" class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>">Settings</a>
            <a href="enroll.php" class="<?= $currentPage === 'enroll.php' ? 'active' : '' ?>">Enroll Device</a>
        </nav>
        <div class="user-profile">
            <span>Admin: <strong><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></strong></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </header>
    <main class="container">
