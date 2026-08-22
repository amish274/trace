<?php
// includes/db.php - Centralized PDO Database Connection Helper

if (!function_exists('getDatabaseEnvironment')) {
    function getDatabaseEnvironment(): string {
        // 1. Explicit APP_ENV environment variable
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

        // 2. Hostname detection for Web requests
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
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

        // 3. CLI execution check by OS family
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'local';
        }

        // Safe default for production live server
        return 'production';
    }
}

if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        static $pdo = null;
        if ($pdo === null) {
            $configFile = __DIR__ . '/../config/database.php';
            $exampleFile = __DIR__ . '/../config/database.example.php';

            if (file_exists($configFile)) {
                $configs = require $configFile;
            } elseif (file_exists($exampleFile)) {
                $configs = require $exampleFile;
            } else {
                die("Database Configuration Error: config/database.php not found.\n");
            }

            $env = getDatabaseEnvironment();
            if (!isset($configs[$env])) {
                die("Database Configuration Error: Environment '{$env}' not defined in config/database.php.\n");
            }

            $dbConfig = $configs[$env];
            $host = $dbConfig['host'] ?? '127.0.0.1';
            $port = $dbConfig['port'] ?? '3306';
            $dbname = $dbConfig['database'] ?? 'employee_monitor';
            $user = $dbConfig['username'] ?? '';
            $pass = $dbConfig['password'] ?? '';

            if (empty($user)) {
                die("Database Configuration Error: Username missing for environment '{$env}'.\n");
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5
            ];

            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // Detailed Audit Server-Side Error Logging (NEVER log passwords, keys or tokens)
                $timestamp = date('Y-m-d H:i:s');
                $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';
                $phpVersion = PHP_VERSION;

                $logMessage = sprintf(
                    "[TeamTrace][DB] Timestamp: %s | Environment: %s | Host: %s | Database: %s | User: %s | PHP: %s | URI: %s | Error: %s",
                    $timestamp,
                    $env,
                    $host,
                    $dbname,
                    $user,
                    $phpVersion,
                    $requestUri,
                    $e->getMessage()
                );
                error_log($logMessage);

                if (php_sapi_name() === 'cli') {
                    die("Database Connection Error [{$env}]: " . $e->getMessage() . "\n");
                }

                $isJsonRequest = false;
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

                if (
                    strpos($uri, '/api/') !== false ||
                    strpos($accept, 'application/json') !== false ||
                    strpos($contentType, 'application/json') !== false
                ) {
                    $isJsonRequest = true;
                }

                http_response_code(500);

                if ($isJsonRequest) {
                    header('Content-Type: application/json');
                    echo json_encode(["success" => false, "error" => "Internal Server Error"]);
                } else {
                    echo "<!DOCTYPE html><html><head><title>System Error</title></head><body style='font-family:sans-serif; padding:2rem; text-align:center;'>";
                    echo "<h2>Service Temporarily Unavailable</h2>";
                    echo "<p style='color:#666;'>The system is currently unable to connect to the database. Please contact system administrator.</p>";
                    echo "</body></html>";
                }
                exit;
            }
        }
        return $pdo;
    }
}
