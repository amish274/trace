<?php
// config/environments/production.php - Active Production Environment Configuration (Git Ignored)

return [
    'APP_ENV' => 'production',
    'SERVER_BASE_URL' => getenv('SERVER_BASE_URL') ?: 'https://ethnicboost.com',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'employee_monitor',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'employee_monitor',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '4ckaCg6L3yeEgF9.',
    'APP_KEY' => getenv('APP_KEY') ?: 'kM;v~6[9#e[33sT6H(z@+8a4l@w-B5tT',
    'SCREENSHOT_STORAGE_PATH' => getenv('SCREENSHOT_STORAGE_PATH') ?: 'storage/screenshots'
];
