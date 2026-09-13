<?php
/**
 * GenZMart API - Centralized Configuration
 *
 * Configured with environment variable support for cloud deployment (Render, Docker)
 * while preserving fallback defaults for local development (XAMPP).
 *
 * Contains database credentials, environment settings, site metadata,
 * base URLs, upload/media paths, and CORS settings.
 */

// Helper to fetch environment variables with fallback defaults
$getEnvVar = function (string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    return $default;
};

// Global configuration array
return [

    // =========================================================
    // Database Configuration
    // =========================================================
    'db' => [
        'host'     => $getEnvVar('DB_HOST', '127.0.0.1'),
        'port'     => (int) $getEnvVar('DB_PORT', '3306'),
        'dbname'   => $getEnvVar('DB_NAME', 'genzmart_db'),
        'username' => $getEnvVar('DB_USER', 'root'),
        'password' => $getEnvVar('DB_PASS', ''),
        'charset'  => $getEnvVar('DB_CHARSET', 'utf8mb4'),
        'ssl_ca'   => $getEnvVar('DB_SSL_CA', '')
    ],


    // =========================================================
    // Application Configuration
    // =========================================================
    'app' => [
        'name'       => $getEnvVar('APP_NAME', 'GenZMart API'),
        'version'    => '1.0.0',
        'env'        => $getEnvVar('APP_ENV', 'production'),
        'timezone'   => $getEnvVar('APP_TIMEZONE', 'Asia/Kolkata'),
        'base_url'   => $getEnvVar('APP_BASE_URL', 'https://genzmart-api.42web.io'),
        'api_prefix' => '/api'
    ],


    // =========================================================
    // File Storage / Upload Paths
    // =========================================================
    'uploads' => [
        'path' => __DIR__ . '/../uploads/',
        'url'  => $getEnvVar('UPLOADS_URL', rtrim($getEnvVar('APP_BASE_URL', 'https://genzmart-api.42web.io'), '/') . '/uploads/')
    ],


    // =========================================================
    // CORS Settings
    // =========================================================
    'cors' => [
        'allowed_origins' => explode(',', $getEnvVar('CORS_ALLOWED_ORIGINS', 'https://genzemart.vercel.app,http://localhost:3000,http://localhost:5173')),

        'allowed_methods' => [
            'GET',
            'POST',
            'PUT',
            'DELETE',
            'OPTIONS',
            'PATCH'
        ],

        'allowed_headers' => [
            'Content-Type',
            'Authorization',
            'X-Requested-With'
        ]
    ]

];