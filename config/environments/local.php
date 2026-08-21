<?php
// config/environments/local.php - Active Local Development Configuration (Git Ignored)

return [
    'APP_ENV' => 'development',
    'SERVER_BASE_URL' => 'http://127.0.0.1:8888',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'employee_monitor',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 'root',
    'APP_KEY' => 'local_dev_secret_key_32_characters_min',
    'SCREENSHOT_STORAGE_PATH' => 'storage/screenshots'
];
