<?php
// api/agent/download.php - Secure Authenticated Canonical MonitorAgent Download Endpoint

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// 1. Authenticate device via Bearer Token header
$device = authenticateAgentDevice();

if (!$device) {
    // Check if token header matches active enrollment token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $rawToken = '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $rawToken = trim($matches[1]);
    }

    if (!empty($rawToken)) {
        $tokenHash = hash('sha256', $rawToken);
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT id FROM device_enrollment_tokens WHERE token_hash = :hash AND (status = 'ready' OR status = 'used')");
        $stmt->execute([':hash' => $tokenHash]);
        if (!$stmt->fetch()) {
            http_response_code(401);
            respondJson(['success' => false, 'error' => 'Unauthorized device download access'], 401);
        }
    } else {
        http_response_code(401);
        respondJson(['success' => false, 'error' => 'Missing authorization header'], 401);
    }
}

$agentPath = __DIR__ . '/../../storage/agent/MonitorAgent.exe';

if (!file_exists($agentPath) || filesize($agentPath) <= 0) {
    http_response_code(500);
    respondJson(['success' => false, 'error' => 'Canonical agent binary not available on server'], 500);
}

// Clean output buffer before streaming binary
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="MonitorAgent.exe"');
header('Content-Length: ' . filesize($agentPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($agentPath);
exit;
