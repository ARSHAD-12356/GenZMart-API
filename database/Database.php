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
            self::ensureSchemaIntegrity(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Checks database tables for missing AUTO_INCREMENT attributes (common when
     * phpMyAdmin SQL exports omit trailing ALTER TABLE MODIFY statements during import)
     * and automatically applies ALTER TABLE to restore AUTO_INCREMENT safely.
     *
     * @param PDO $pdo
     */
    public static function ensureSchemaIntegrity(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            // Check EXTRA column attribute for users.id
            $stmt = $pdo->query("
                SELECT EXTRA 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'users' 
                  AND COLUMN_NAME = 'id'
                LIMIT 1
            ");
            $extra = $stmt ? $stmt->fetchColumn() : '';

            if (is_string($extra) && strpos(strtolower($extra), 'auto_increment') === false) {
                $alterQueries = [
                    "ALTER TABLE `roles` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `users` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `seller_profiles` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `user_addresses` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `user_sessions` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `categories` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `subcategories` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `brands` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `products` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `product_images` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `product_variants` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `carts` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `cart_items` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `wishlists` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `wishlist_items` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `orders` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `order_items` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `order_addresses` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `order_status_history` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `reviews` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `review_images` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `coupons` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `coupon_usage` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `notifications` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    "ALTER TABLE `site_settings` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT"
                ];

                foreach ($alterQueries as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (Throwable $ignored) {
                        // Silently ignore individual table alter failures if table missing or already modified
                    }
                }
            }
        } catch (Throwable $ignored) {
            // Silently ignore if information_schema is restricted or unreadable
        }
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
