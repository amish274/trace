<?php
// config/config.php - Centralized Application Configuration

require_once __DIR__ . '/../includes/db.php';

// Helper function to parse .env file if available
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Load root .env file if present
loadEnv(__DIR__ . '/../.env');

$env = getenv('APP_ENV') ?: 'production';

if (!defined('APP_ENV')) define('APP_ENV', $env);
if (!defined('SERVER_BASE_URL')) {
    $baseUrl = getenv('SERVER_BASE_URL') ?: (PHP_OS_FAMILY === 'Darwin' ? 'http://127.0.0.1:8888' : 'https://ethnicboost.com/Trace');
    define('SERVER_BASE_URL', rtrim($baseUrl, '/'));
}

if (!defined('APP_KEY')) define('APP_KEY', getenv('APP_KEY') ?: 'default_secret_key_change_in_production');
if (!defined('SCREENSHOT_STORAGE_PATH')) define('SCREENSHOT_STORAGE_PATH', __DIR__ . '/../' . (getenv('SCREENSHOT_STORAGE_PATH') ?: 'storage/screenshots'));

// Error Display Settings based on Environment
if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
