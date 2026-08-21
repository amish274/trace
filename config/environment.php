<?php
// config/environment.php - Central Environment Resolver & Configuration Loader

function detectEnvironment(): string {
    // 1. HTTP Host detection for Web requests (highest priority for browser)
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (!empty($host)) {
        $hostName = strtolower(explode(':', $host)[0]);
        if (
            $hostName === 'localhost' ||
            $hostName === '127.0.0.1' ||
            $hostName === '::1' ||
            str_ends_with($hostName, '.local')
        ) {
            return 'local';
        }
        return 'production';
    }

    // 2. Explicit environment setting from system env / .env
    $appEnv = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? ($_ENV['APP_ENV'] ?? ''));
    if (!empty($appEnv)) {
        $normalized = strtolower(trim($appEnv));
        if (in_array($normalized, ['local', 'development', 'dev', 'testing'])) {
            return 'local';
        }
        if (in_array($normalized, ['production', 'prod', 'live'])) {
            return 'production';
        }
    }

    // 3. CLI / Cron execution detection
    // On macOS local development machine, return local if local.php exists
    if (PHP_OS_FAMILY === 'Darwin' && file_exists(__DIR__ . '/environments/local.php')) {
        return 'local';
    }

    // On Linux / Production VPS, if production.php exists, default to production
    if (file_exists(__DIR__ . '/environments/production.php')) {
        return 'production';
    }

    return file_exists(__DIR__ . '/environments/local.php') ? 'local' : 'production';
}

function loadEnvironmentConfig(): array {
    $envName = detectEnvironment();
    $configDir = __DIR__ . '/environments';

    if ($envName === 'local') {
        $file = "{$configDir}/local.php";
        $fallback = "{$configDir}/local.example.php";
    } else {
        $file = "{$configDir}/production.php";
        $fallback = "{$configDir}/production.example.php";
    }

    $config = [];
    if (file_exists($file)) {
        $config = require $file;
    } elseif (file_exists($fallback)) {
        $config = require $fallback;
    }

    // Ensure environment key is explicitly set
    $config['APP_ENV'] = ($envName === 'local') ? 'development' : 'production';
    $config['ENVIRONMENT_NAME'] = $envName;

    return $config;
}

return loadEnvironmentConfig();
