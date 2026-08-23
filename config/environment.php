<?php
// config/environment.php - Application Environment Alias

require_once __DIR__ . '/config.php';

return [
    'APP_ENV' => defined('APP_ENV') ? APP_ENV : 'production',
    'ENVIRONMENT_NAME' => defined('APP_ENV') ? APP_ENV : 'production'
];
