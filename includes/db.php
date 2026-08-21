<?php
// includes/db.php - PDO Database Connection Helper

require_once __DIR__ . '/../config/config.php';

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
        } catch (PDOException $e) {
            if (APP_ENV === 'development' || php_sapi_name() === 'cli') {
                die("Database Connection Error: " . $e->getMessage() . "\n");
            } else {
                error_log("Database Connection Error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(["success" => false, "error" => "Internal Server Error"]);
                exit;
            }
        }
    }
    return $pdo;
}
