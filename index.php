<?php
/**
 * GenZMart API - Root Health-Check & Entry Point
 * 
 * Primary API entry point confirming API status, database status check,
 * and presenting available foundation module routes.
 */

// Load Configuration and Helpers
$config = require __DIR__ . '/config/config.php';
require_once __DIR__ . '/database/Database.php';
require_once __DIR__ . '/helpers/ResponseHelper.php';

// Set default timezone
date_default_timezone_set($config['app']['timezone']);

// Perform database connection check
$dbCheck = Database::checkConnection();

// Prepare health-check payload
$healthData = [
    'api' => [
        'name' => $config['app']['name'],
        'version' => $config['app']['version'],
        'environment' => $config['app']['env'],
        'status' => 'ONLINE',
        'server_time' => date('Y-m-d H:i:s T')
    ],
    'database' => [
        'connected' => $dbCheck['connected'],
        'status' => $dbCheck['message']
    ],
    'architecture_modules' => [
        '/auth',
        '/users',
        '/categories',
        '/brands',
        '/products',
        '/cart',
        '/wishlist',
        '/orders',
        '/reviews',
        '/notifications',
        '/seller',
        '/admin'
    ]
];

// Send standardized JSON response
ResponseHelper::success('GenZMart API is running successfully', $healthData);
