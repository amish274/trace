<?php
// config/environment.php - Environment Resolver Alias

require_once __DIR__ . '/../includes/db.php';

$env = getDatabaseEnvironment();

return [
    'APP_ENV' => $env,
    'ENVIRONMENT_NAME' => $env
];
