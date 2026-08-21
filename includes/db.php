<?php
// includes/db.php - Centralized PDO Database Connection Helper

require_once __DIR__ . '/../config/config.php';

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5
        ];

        $dsnList = [
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4"
        ];

        $altHost = (DB_HOST === '127.0.0.1') ? 'localhost' : '127.0.0.1';
        $dsnList[] = "mysql:host=" . $altHost . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";

        if (file_exists('/var/lib/mysql/mysql.sock')) {
            $dsnList[] = "mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=" . DB_DATABASE . ";charset=utf8mb4";
        }
        if (file_exists('/tmp/mysql.sock')) {
            $dsnList[] = "mysql:unix_socket=/tmp/mysql.sock;dbname=" . DB_DATABASE . ";charset=utf8mb4";
        }

        $lastException = null;
        foreach ($dsnList as $dsn) {
            try {
                $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
                break;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        if ($pdo === null && $lastException !== null) {
            // Detailed Audit Server-Side Error Logging (NEVER log passwords, keys or tokens)
            $timestamp = date('Y-m-d H:i:s');
            $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';
            $phpVersion = PHP_VERSION;
            $dbHost = DB_HOST;
            $dbName = DB_DATABASE;
            $env = APP_ENV;

            $logMessage = sprintf(
                "[TeamTrace][DB] Timestamp: %s | Environment: %s | Host: %s | Database: %s | PHP: %s | URI: %s | Error: %s",
                $timestamp,
                $env,
                $dbHost,
                $dbName,
                $phpVersion,
                $requestUri,
                $lastException->getMessage()
            );
            error_log($logMessage);

            if (php_sapi_name() === 'cli') {
                die("Database Connection Error [{$env}]: " . $lastException->getMessage() . "\n");
            }

            // Determine if request expects JSON (API endpoints) or HTML page
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

            if (APP_ENV === 'development') {
                if ($isJsonRequest) {
                    header('Content-Type: application/json');
                    echo json_encode(["success" => false, "error" => "Database Connection Error: " . $lastException->getMessage()]);
                } else {
                    echo "<!DOCTYPE html><html><head><title>Database Error</title></head><body style='font-family:sans-serif; padding:2rem;'>";
                    echo "<h1>Database Connection Error</h1>";
                    echo "<p style='color:red; font-weight:bold;'>" . htmlspecialchars($lastException->getMessage()) . "</p>";
                    echo "<p>Environment: <code>" . htmlspecialchars($env) . "</code> | Host: <code>" . htmlspecialchars($dbHost) . "</code></p>";
                    echo "</body></html>";
                }
            } else {
                if ($isJsonRequest) {
                    header('Content-Type: application/json');
                    echo json_encode(["success" => false, "error" => "Internal Server Error"]);
                } else {
                    echo "<!DOCTYPE html><html><head><title>System Error</title></head><body style='font-family:sans-serif; padding:2rem; text-align:center;'>";
                    echo "<h2>Service Temporarily Unavailable</h2>";
                    echo "<p style='color:#666;'>The system is currently unable to connect to the database. Please contact system administrator.</p>";
                    echo "</body></html>";
                }
            }
            exit;
        }
    }
    return $pdo;
}
