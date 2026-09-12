<?php
/**
 * Temporary Diagnostic Script - GenZMart API Database Connection Test
 * 
 * Endpoint: GET /db-test.php
 * Tests PDO connection to MySQL using parameters loaded from config/config.php.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Reuse existing config/config.php
$config = require __DIR__ . '/config/config.php';
$db = $config['db'] ?? [];

$host     = (string) ($db['host'] ?? '');
$port     = (int) ($db['port'] ?? 3306);
$dbname   = (string) ($db['dbname'] ?? '');
$username = (string) ($db['username'] ?? '');
$password = (string) ($db['password'] ?? '');
$charset  = (string) ($db['charset'] ?? 'utf8mb4');

try {
    $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s", $host, $port, $dbname, $charset);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Database connection established successfully.',
        'diagnostics' => [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbname,
            'db_user' => $username,
            'server_version' => $serverVersion
        ],
        'timestamp' => date('Y-m-d H:i:s T')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();

} catch (PDOException $e) {
    http_response_code(500);

    // Ensure password is never exposed in error output
    $errorMessage = $e->getMessage();
    if ($password !== '') {
        $errorMessage = str_replace($password, '********', $errorMessage);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.',
        'diagnostics' => [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbname,
            'db_user' => $username,
            'error'   => $errorMessage
        ],
        'timestamp' => date('Y-m-d H:i:s T')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error during database diagnosis.',
        'diagnostics' => [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbname,
            'db_user' => $username,
            'error'   => $e->getMessage()
        ],
        'timestamp' => date('Y-m-d H:i:s T')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}
