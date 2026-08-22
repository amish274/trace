<?php
// config/database.example.php - Database Configuration Template

return [
    'local' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'employee_monitor',
        'username' => 'root',
        'password' => 'root',
    ],
    'production' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'employee_monitor',
        'username' => 'employee_monitor',
        'password' => 'REPLACE_WITH_PRODUCTION_PASSWORD',
    ],
];
