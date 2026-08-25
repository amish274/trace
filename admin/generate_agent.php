<?php
// admin/generate_agent.php - Authenticode-Safe Agent Package Generation Controller

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../tools/generate_agent.php';

requireAdminSession();

$db = getDbConnection();
$deviceId = (int)($_GET['device_id'] ?? $_POST['device_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$error = '';
$success = '';

if ($deviceId <= 0) {
    header('Location: index.php');
    exit;
}

// Fetch device & employee details
$stmt = $db->prepare("
    SELECT d.*, e.name as employee_name, e.email as employee_email
    FROM devices d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.id = :id
");
$stmt->execute([':id' => $deviceId]);
$device = $stmt->fetch();

if (!$device) {
    header('Location: index.php');
    exit;
}

// Handle Package Generation / Regeneration POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'generate' || $action === 'regenerate')) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // 1. Invalidate any existing unused enrollment tokens for this device
        $revokeOld = $db->prepare("UPDATE device_enrollment_tokens SET status = 'revoked' WHERE device_id = :id AND status = 'ready'");
        $revokeOld->execute([':id' => $deviceId]);

        // 2. Generate new secure 32-byte enrollment token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // 3. Store hashed token in database
        $insertToken = $db->prepare("
            INSERT INTO device_enrollment_tokens (device_id, token_hash, status, expires_at)
            VALUES (:device_id, :token_hash, 'ready', :expires_at)
        ");
        $insertToken->execute([
            ':device_id' => $deviceId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt
        ]);

        // 4. Generate device bootstrapper package (System Utility ZIP)
        try {
            $packagePath = generateAgentPackage($deviceId, $rawToken, SERVER_BASE_URL, '', 'zip');
            $success = 'System Utility package generated successfully!';
            $stmt->execute([':id' => $deviceId]);
            $device = $stmt->fetch();
        } catch (Exception $e) {
            $error = 'Agent generation failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Check latest enrollment token record
$tokenStmt = $db->prepare("
    SELECT * FROM device_enrollment_tokens 
    WHERE device_id = :id 
    ORDER BY id DESC LIMIT 1
");
$tokenStmt->execute([':id' => $deviceId]);
$latestToken = $tokenStmt->fetch();

// Resolve generated package location (System Utility-ID.zip preference > Fallbacks)
$sanitizedDevice = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['device_name']);
$possiblePackagePaths = [
    __DIR__ . "/../storage/packages/System Utility-{$deviceId}.zip",
    __DIR__ . "/../storage/packages/System Utility-{$sanitizedDevice}.zip",
    __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitizedDevice}.zip",
    __DIR__ . "/../storage/packages/TeamTraceSetup-{$sanitizedDevice}.exe",
    __DIR__ . "/../storage/packages/System-Utility-{$sanitizedDevice}.exe"
];

$packagePath = '';
foreach ($possiblePackagePaths as $candidate) {
    if (file_exists($candidate) && filesize($candidate) > 0) {
        $packagePath = $candidate;
        break;
    }
}

$packageExists = !empty($packagePath);
$packageFilename = $packageExists ? basename($packagePath) : "System Utility-{$deviceId}.zip";
$packageExtension = strtolower(pathinfo($packageFilename, PATHINFO_EXTENSION));
$packageSizeKb = $packageExists ? round(filesize($packagePath) / 1024, 2) : 0;
$packageSizeMb = $packageExists ? round(filesize($packagePath) / (1024 * 1024), 2) : 0;
$displaySize = $packageSizeMb >= 1.0 ? "{$packageSizeMb} MB" : "{$packageSizeKb} KB";
$displayType = $packageExtension === 'zip' ? 'Authenticode-Safe ZIP Bundle (System Utility.exe + system-utility.config.json)' : 'One-click Windows Installer';

// Generate signed short-lived download URL (valid 5 minutes)
$downloadUrl = generateSignedDownloadUrl($deviceId, 5);

$csrfToken = generateCsrfToken();
require_once __DIR__ . '/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.25rem;">Generate System Utility Package</h1>
            <p style="color: var(--text-muted); font-size:0.9rem;">Generate Authenticode-compliant setup package for this device.</p>
        </div>
        <a href="device.php?id=<?= $deviceId ?>" class="btn btn-secondary">← Back to Device</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-warning" style="background:rgba(239,68,68,0.2); color:#fca5a5; border-color:#ef4444; margin-bottom:1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success" style="margin-bottom:1rem;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1.5rem;">
        <h2 class="card-title" style="margin-bottom: 1.25rem;">
            Device Configuration Specifications
        </h2>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
            <div style="background:var(--bg-primary); padding:1rem; border-radius:6px; border:1px solid var(--border-color);">
                <div style="color:var(--text-muted); font-size:0.8rem; font-weight:600;">EMPLOYEE</div>
                <div style="font-size:1.1rem; font-weight:600; color:#fff; margin-top:0.25rem;">
                    <?= htmlspecialchars($device['employee_name']) ?>
                </div>
            </div>

            <div style="background:var(--bg-primary); padding:1rem; border-radius:6px; border:1px solid var(--border-color);">
                <div style="color:var(--text-muted); font-size:0.8rem; font-weight:600;">COMPUTER NAME</div>
                <div style="font-size:1.1rem; font-weight:600; color:var(--accent-blue); margin-top:0.25rem;">
                    <?= htmlspecialchars($device['device_name']) ?>
                </div>
            </div>

            <div style="background:var(--bg-primary); padding:1rem; border-radius:6px; border:1px solid var(--border-color);">
                <div style="color:var(--text-muted); font-size:0.8rem; font-weight:600;">SERVER BASE URL</div>
                <div style="font-size:0.95rem; font-weight:600; color:#34d399; margin-top:0.25rem;">
                    <?= htmlspecialchars(SERVER_BASE_URL) ?>
                </div>
            </div>

            <div style="background:var(--bg-primary); padding:1rem; border-radius:6px; border:1px solid var(--border-color);">
                <div style="color:var(--text-muted); font-size:0.8rem; font-weight:600;">ENROLLMENT STATUS</div>
                <div style="font-size:0.95rem; font-weight:600; margin-top:0.25rem;">
                    <span class="badge badge-<?= $device['package_status'] === 'enrolled' ? 'success' : ($device['package_status'] === 'generated' || $device['package_status'] === 'downloaded' ? 'warning' : 'secondary') ?>">
                        <?= strtoupper($device['package_status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($packageExists): ?>
            <div style="background:var(--bg-primary); border:1px solid var(--border-color); padding:1.25rem; border-radius:6px; margin-bottom:1.5rem;">
                <div style="font-weight:700; color:var(--accent-blue); font-size:1rem; margin-bottom:0.75rem;">
                    SYSTEM UTILITY SETUP PACKAGE
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem; font-size:0.9rem;">
                    <div><strong>Version:</strong> <?= htmlspecialchars($device['agent_version'] ?: '1.0.0') ?></div>
                    <div><strong>Package File:</strong> <?= htmlspecialchars($packageFilename) ?></div>
                    <div><strong>Package Format:</strong> <?= htmlspecialchars($displayType) ?></div>
                    <div><strong>File Size:</strong> <?= $displaySize ?></div>
                    <div><strong>Server URL:</strong> <?= htmlspecialchars(SERVER_BASE_URL) ?></div>
                    <div><strong>Enrollment:</strong> <span style="color:#34d399; font-weight:bold;">Ready</span></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($latestToken && $latestToken['status'] === 'ready'): ?>
            <div style="background:rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); padding:1rem; border-radius:6px; margin-bottom: 1.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-weight:600; color:#fff;">Enrollment Security Token</div>
                        <div style="font-family:monospace; color:var(--text-muted); margin-top:0.25rem;">
                            ****************************************************************
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:0.75rem; color:var(--text-muted);">EXPIRES AT</div>
                        <div style="font-size:0.85rem; font-weight:600; color:#fcd34d;">
                            <?= htmlspecialchars($latestToken['expires_at']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap: 1rem;">
                <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn btn-primary" style="flex:2; justify-content:center; padding:0.8rem;">
                    ↓ DOWNLOAD SYSTEM UTILITY PACKAGE
                </a>
                <form method="POST" action="generate_agent.php" style="flex:1;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="device_id" value="<?= $deviceId ?>">
                    <input type="hidden" name="action" value="regenerate">
                    <button type="submit" class="btn btn-secondary" style="width:100%; justify-content:center; padding:0.8rem;" onclick="return confirm('Regenerate package? This will invalidate any previously generated unused installer.');">
                        ↺ REGENERATE PACKAGE
                    </button>
                </form>
            </div>
        <?php else: ?>
            <form method="POST" action="generate_agent.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="device_id" value="<?= $deviceId ?>">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:0.85rem;">
                    ⚡ GENERATE SYSTEM UTILITY PACKAGE
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
