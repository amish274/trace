<?php
// config/environment.php - Central Environment Resolver & Configuration Loader

function detectEnvironment(): string {
    // Priority 1: Explicit APP_ENV environment variable (from system / web server / .env)
    $appEnv = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? ($_ENV['APP_ENV'] ?? ''));
    if (!empty($appEnv)) {
        $normalized = strtolower(trim($appEnv));
        if (in_array($normalized, ['production', 'prod', 'live'])) {
            return 'production';
        }
        if (in_array($normalized, ['local', 'development', 'dev', 'testing'])) {
            return 'local';
        }
    }

    // Priority 2: Hostname detection for Web requests (check HTTP_HOST and HTTP_X_FORWARDED_HOST)
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    if (!empty($host)) {
        $hostName = strtolower(explode(':', $host)[0]);
        
        // If host is explicitly a public domain or IP (not localhost/127.0.0.1), return production
        if ($hostName !== 'localhost' && $hostName !== '127.0.0.1' && $hostName !== '::1' && !str_ends_with($hostName, '.local')) {
            return 'production';
        }

        // If host is localhost/127.0.0.1 on macOS, return local
        if (
            ($hostName === 'localhost' || $hostName === '127.0.0.1' || $hostName === '::1' || str_ends_with($hostName, '.local'))
            && PHP_OS_FAMILY === 'Darwin'
        ) {
            return 'local';
        }
    }

    // Priority 3: OS / Production Server Detection
    // On Linux / CentOS VPS, default to production if production.php exists
    if (PHP_OS_FAMILY === 'Linux' && file_exists(__DIR__ . '/environments/production.php')) {
        return 'production';
    }

    // On macOS local development machine, default to local if local.php exists
    if (PHP_OS_FAMILY === 'Darwin' && file_exists(__DIR__ . '/environments/local.php')) {
        return 'local';
    }

    // Fallback: Check for production configuration file
    if (file_exists(__DIR__ . '/environments/production.php')) {
        return 'production';
    }

    return 'local';
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
