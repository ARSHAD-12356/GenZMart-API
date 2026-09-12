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
            return [
                'connected' => false,
                'message' => 'Database connection error: ' . $e->getMessage()
            ];
        }
    }
}
