<?php
// config/environments/production.example.php - Production Environment Template

return [
    'APP_ENV' => 'production',
    'SERVER_BASE_URL' => 'https://YOUR_PRODUCTION_DOMAIN_OR_IP',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'employee_monitor',
    'DB_USERNAME' => 'prod_db_user',
    'DB_PASSWORD' => 'REPLACE_WITH_STRONG_PRODUCTION_PASSWORD',
    'APP_KEY' => 'REPLACE_WITH_PRODUCTION_APP_KEY_32_CHARS',
    'SCREENSHOT_STORAGE_PATH' => 'storage/screenshots'
];
