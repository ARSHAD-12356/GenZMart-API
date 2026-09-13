<?php
/**
 * GenZMart API - Database Connection Layer
 * 
 * Reusable PDO Database Connection class implementing a singleton-style instance.
 * Configured with prepared statement safety and associative fetching by default.
 */

class Database {
    private static ?PDO $instance = null;

    /**
     * Resolves the SSL CA certificate file path.
     * Supports both file paths (e.g., /etc/secrets/ca.pem) and raw certificate strings/base64.
     *
     * @param string|null $sslCa File path or PEM certificate string
     * @return string|null Path to certificate file, or null if unconfigured/invalid
     */
    public static function resolveCaPath(?string $sslCa): ?string {
        if ($sslCa === null) {
            return null;
        }

        $sslCa = trim($sslCa);
        if ($sslCa === '') {
            return null;
        }

        // Case 1: Direct file path on disk (e.g. Render Secret File /etc/secrets/ca.pem)
        if (@file_exists($sslCa) && @is_file($sslCa)) {
            return $sslCa;
        }

        // Case 2: Certificate content provided as string or base64
        $certContent = $sslCa;
        if (strpos($sslCa, '-----BEGIN CERTIFICATE-----') === false) {
            $decoded = @base64_decode($sslCa, true);
            if ($decoded !== false && strpos($decoded, '-----BEGIN CERTIFICATE-----') !== false) {
                $certContent = $decoded;
            }
        }

        if (strpos($certContent, '-----BEGIN CERTIFICATE-----') === false) {
            return null;
        }

        // Write certificate string to temp directory safely
        $tempDir = sys_get_temp_dir();
        $caPath = $tempDir . DIRECTORY_SEPARATOR . 'mysql_ca_' . md5($certContent) . '.pem';

        if (!@file_exists($caPath) || @file_get_contents($caPath) !== $certContent) {
            @file_put_contents($caPath, $certContent);
        }

        return $caPath;
    }

    /**
     * Get or create a PDO Database Connection
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $dbConfig = $config['db'];

            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['dbname'],
                $dbConfig['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            // Configure SSL/TLS when DB_SSL_CA is provided
            if (!empty($dbConfig['ssl_ca'])) {
                $caPath = self::resolveCaPath($dbConfig['ssl_ca']);
                if ($caPath !== null) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                    }
                }
            }

            self::$instance = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        }

        return self::$instance;
    }

    /**
     * Test connection status without throwing uncaught exceptions
     *
     * @return array
     */
    public static function checkConnection(): array {
        try {
            $pdo = self::getConnection();
            return [
                'connected' => true,
                'message' => 'Database connection successful'
            ];
        } catch (PDOException $e) {
            $config = require __DIR__ . '/../config/config.php';
            $password = $config['db']['password'] ?? '';
            $errorMessage = $e->getMessage();
            if (!empty($password)) {
                $errorMessage = str_replace($password, '********', $errorMessage);
            }
            return [
                'connected' => false,
                'message' => 'Database connection error: ' . $errorMessage
            ];
        }
    }
}
