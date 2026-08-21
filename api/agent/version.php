<?php
// api/agent/version.php - Returns Agent Version Info & Compatibility

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

respondJson([
    'success' => true,
    'latest_agent_version' => '1.0.0',
    'min_supported_version' => '1.0.0',
    'download_url' => SERVER_BASE_URL . '/agent/MonitorAgent.exe'
]);
