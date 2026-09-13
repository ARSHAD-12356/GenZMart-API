<?php
/**
 * GenZMart API - Response Helper
 * 
 * Standardized JSON API response structure helper with CORS support
 * and HTTP status code handling.
 */

class ResponseHelper {
    /**
     * Send CORS headers centrally based on application configuration.
     * Guarantees exactly ONE origin header is sent and handles OPTIONS preflight.
     */
    public static function sendCorsHeaders(): void {
        static $headersSent = false;
        if ($headersSent) {
            return;
        }
        $headersSent = true;

        $config = require __DIR__ . '/../config/config.php';
        $cors = $config['cors'] ?? [];

        // Extract requesting Origin or Referer host
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (empty($requestOrigin) && !empty($_SERVER['HTTP_REFERER'])) {
            $parsed = parse_url($_SERVER['HTTP_REFERER']);
            if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
                $requestOrigin = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            }
        }

        // Process allowed origins list from config/env
        $configuredOrigins = $cors['allowed_origins'] ?? [];
        if (is_string($configuredOrigins)) {
            $configuredOrigins = explode(',', $configuredOrigins);
        }

        $allowedOrigins = [];
        foreach ((array) $configuredOrigins as $orig) {
            $trimmed = trim($orig);
            // Filter out old typo origin 'https://genzmart.vercel.app' and wildcard '*'
            if (!empty($trimmed) && $trimmed !== 'https://genzmart.vercel.app' && $trimmed !== '*') {
                $allowedOrigins[] = $trimmed;
            }
        }

        // Ensure correct production origin and localhost origins exist in allowed set
        $defaultAllowed = [
            'https://genzemart.vercel.app',
            'http://localhost:3000',
            'http://localhost:5173',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173'
        ];
        foreach ($defaultAllowed as $def) {
            if (!in_array($def, $allowedOrigins, true)) {
                $allowedOrigins[] = $def;
            }
        }

        // Determine single valid origin string to output
        $selectedOrigin = 'https://genzemart.vercel.app'; // Default production fallback
        if (!empty($requestOrigin)) {
            if (in_array($requestOrigin, $allowedOrigins, true) || preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $requestOrigin)) {
                $selectedOrigin = $requestOrigin;
            }
        }

        $allowedMethods = implode(', ', $cors['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']);
        $allowedHeaders = implode(', ', $cors['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']);

        // Send single CORS headers
        header("Access-Control-Allow-Origin: {$selectedOrigin}");
        header("Access-Control-Allow-Methods: {$allowedMethods}");
        header("Access-Control-Allow-Headers: {$allowedHeaders}");
        header("Access-Control-Allow-Credentials: true");
        header("Content-Type: application/json; charset=UTF-8");

        // Handle preflight OPTIONS request cleanly
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    /**
     * Return structured JSON response
     *
     * @param int $statusCode HTTP status code (200, 201, 400, 401, 403, 404, 500)
     * @param bool $success Success boolean status
     * @param string $message Descriptive response message
     * @param mixed $data Data payload (optional)
     * @param mixed $errors Validation or server error details (optional)
     */
    public static function send(int $statusCode, bool $success, string $message, $data = null, $errors = null): void {
        self::sendCorsHeaders();
        http_response_code($statusCode);

        $response = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Helper for successful responses (HTTP 200/201)
     */
    public static function success(string $message = 'Success', $data = null, int $statusCode = 200): void {
        self::send($statusCode, true, $message, $data);
    }

    /**
     * Helper for error responses (HTTP 4xx / 5xx)
     */
    public static function error(string $message = 'An error occurred', int $statusCode = 400, $errors = null): void {
        self::send($statusCode, false, $message, null, $errors);
    }
}
