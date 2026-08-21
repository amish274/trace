<?php
// config/config.php - Centralized Configuration Loader

// Parse .env file if available
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
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

// Load root .env file
loadEnv(__DIR__ . '/../.env');

define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('SERVER_BASE_URL', rtrim(getenv('SERVER_BASE_URL') ?: 'https://YOUR_VPS_IP_OR_DOMAIN', '/'));

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_DATABASE', getenv('DB_DATABASE') ?: 'employee_monitor');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');

define('APP_KEY', getenv('APP_KEY') ?: 'default_secret_key_change_in_production');
define('SCREENSHOT_STORAGE_PATH', __DIR__ . '/../' . (getenv('SCREENSHOT_STORAGE_PATH') ?: 'storage/screenshots'));

// Error Display Settings based on Environment
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
