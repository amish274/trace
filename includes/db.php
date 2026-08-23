<?php
// includes/db.php - Centralized PDO Database Connection Engine

if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        static $pdo = null;
        if ($pdo === null) {
            $configFile = __DIR__ . '/../config/database.php';
            $exampleFile = __DIR__ . '/../config/database.example.php';

            if (file_exists($configFile)) {
                $dbConfig = require $configFile;
            } elseif (file_exists($exampleFile)) {
                $dbConfig = require $exampleFile;
            } else {
                die("Database Configuration Error: config/database.php not found.\n");
            }

            $host = $dbConfig['host'] ?? '127.0.0.1';
            $port = $dbConfig['port'] ?? '3306';
            $dbname = $dbConfig['database'] ?? 'employee_monitor';
            $user = $dbConfig['username'] ?? '';
            $pass = $dbConfig['password'] ?? '';

            if (empty($user)) {
                die("Database Configuration Error: Username missing in config/database.php.\n");
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
                $timestamp = date('Y-m-d H:i:s');
                $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';
                $phpVersion = PHP_VERSION;

                $logMessage = sprintf(
                    "[TeamTrace][DB] Timestamp: %s | Host: %s | Port: %s | Database: %s | User: %s | PHP: %s | URI: %s | Error: %s",
                    $timestamp,
                    $host,
                    $port,
                    $dbname,
                    $user,
                    $phpVersion,
                    $requestUri,
                    $e->getMessage()
                );
                error_log($logMessage);

                if (php_sapi_name() === 'cli') {
                    die("Database Connection Error: " . $e->getMessage() . "\n");
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
