<?php
/**
 * Temporary Diagnostic Script - GenZMart API Database Connection Test
 * 
 * Endpoint: GET /db-test.php
 * Tests PDO connection to MySQL using parameters loaded from config/config.php.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/database/Database.php';

// Reuse existing config/config.php
$config = require __DIR__ . '/config/config.php';
$db = $config['db'] ?? [];

$host     = (string) ($db['host'] ?? '');
$port     = (int) ($db['port'] ?? 3306);
$dbname   = (string) ($db['dbname'] ?? '');
$username = (string) ($db['username'] ?? '');
$password = (string) ($db['password'] ?? '');
$charset  = (string) ($db['charset'] ?? 'utf8mb4');
$sslCa    = (string) ($db['ssl_ca'] ?? '');

try {
    $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s", $host, $port, $dbname, $charset);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    $caPath = Database::resolveCaPath($sslCa);
    if ($caPath !== null) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
    }

    $pdo = new PDO($dsn, $username, $password, $options);
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Query active SSL cipher status if connected via SSL
    $sslCipher = null;
    $sslActive = false;
    try {
        $stmt = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'");
        if ($stmt && ($row = $stmt->fetch())) {
            $cipher = $row['Value'] ?? $row['value'] ?? null;
            if (!empty($cipher)) {
                $sslCipher = $cipher;
                $sslActive = true;
            }
        }
    } catch (Throwable $e) {
        // Silently ignore if query fails
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Database connection established successfully.',
        'diagnostics' => [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbname,
            'db_user' => $username,
            'ssl_configured' => !empty($sslCa),
            'ssl_active' => $sslActive,
            'ssl_cipher' => $sslCipher,
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
            'ssl_configured' => !empty($sslCa),
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
            'ssl_configured' => !empty($sslCa),
            'error'   => $e->getMessage()
        ],
        'timestamp' => date('Y-m-d H:i:s T')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}
