<?php
/**
 * GenZMart API - Database Health Check Endpoint
 * 
 * Endpoint: GET /api/health/database.php
 * Verifies PDO MySQL database connectivity, configuration parameters,
 * and prepared statement execution safety.
 */

// Load Configuration and Required Dependencies
$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

// Set Headers & Standard Headers via ResponseHelper
ResponseHelper::sendCorsHeaders();

try {
    // Attempt PDO Connection
    $pdo = Database::getConnection();

    // Verify prepared statement execution
    $stmt = $pdo->prepare("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = :dbname");
    $stmt->execute(['dbname' => $config['db']['dbname']]);
    $result = $stmt->fetch();

    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Send successful health status JSON
    $responsePayload = [
        'success' => true,
        'database_connected' => true,
        'database' => $config['db']['dbname'],
        'message' => 'Database connection successful',
        'details' => [
            'host' => $config['db']['host'],
            'port' => $config['db']['port'],
            'charset' => $config['db']['charset'],
            'tables_count' => (int) $result['table_count'],
            'server_version' => $serverVersion
        ]
    ];

    http_response_code(200);
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();

} catch (PDOException $e) {
    // Handle error without exposing sensitive credentials
    $responsePayload = [
        'success' => false,
        'database_connected' => false,
        'database' => $config['db']['dbname'],
        'message' => 'Database connection failed. Please ensure MySQL service is running in XAMPP and configuration settings are correct.'
    ];

    http_response_code(500);
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
} catch (Throwable $e) {
    $responsePayload = [
        'success' => false,
        'database_connected' => false,
        'database' => $config['db']['dbname'],
        'message' => 'An unexpected error occurred during database health check.'
    ];

    http_response_code(500);
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}
