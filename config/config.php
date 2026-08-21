<?php
// config/config.php - Centralized Configuration Loader

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

// Load environment configuration resolver
$envConfig = require __DIR__ . '/environment.php';

if (!defined('APP_ENV')) define('APP_ENV', $envConfig['APP_ENV'] ?? 'production');
if (!defined('SERVER_BASE_URL')) define('SERVER_BASE_URL', rtrim($envConfig['SERVER_BASE_URL'] ?? 'http://127.0.0.1:8888', '/'));

if (!defined('DB_HOST')) define('DB_HOST', $envConfig['DB_HOST'] ?? '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', $envConfig['DB_PORT'] ?? '3306');
if (!defined('DB_DATABASE')) define('DB_DATABASE', $envConfig['DB_DATABASE'] ?? 'employee_monitor');
if (!defined('DB_USERNAME')) define('DB_USERNAME', $envConfig['DB_USERNAME'] ?? 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $envConfig['DB_PASSWORD'] ?? '');

if (!defined('APP_KEY')) define('APP_KEY', $envConfig['APP_KEY'] ?? 'default_secret_key_change_in_production');
if (!defined('SCREENSHOT_STORAGE_PATH')) define('SCREENSHOT_STORAGE_PATH', __DIR__ . '/../' . ($envConfig['SCREENSHOT_STORAGE_PATH'] ?? 'storage/screenshots'));

// Error Display Settings based on Environment
if (APP_ENV === 'development' || APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
