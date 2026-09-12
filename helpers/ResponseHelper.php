<?php
/**
 * GenZMart API - Response Helper
 * 
 * Standardized JSON API response structure helper with CORS support
 * and HTTP status code handling.
 */

class ResponseHelper {
    /**
     * Send CORS headers based on application configuration
     */
    public static function sendCorsHeaders(): void {
        $config = require __DIR__ . '/../config/config.php';
        $cors = $config['cors'];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Dynamically allow requested origin if it's localhost or listed in config
        if (!empty($origin) && (in_array('*', $cors['allowed_origins']) || in_array($origin, $cors['allowed_origins']) || preg_match('/^http:\/\/localhost(:\d+)?$/', $origin))) {
            header("Access-Control-Allow-Origin: {$origin}");
        } else {
            $allowedOrigins = implode(', ', $cors['allowed_origins']);
            header("Access-Control-Allow-Origin: {$allowedOrigins}");
        }

        header("Access-Control-Allow-Methods: " . implode(', ', $cors['allowed_methods']));
        header("Access-Control-Allow-Headers: " . implode(', ', $cors['allowed_headers']));
        header("Access-Control-Allow-Credentials: true");
        header("Content-Type: application/json; charset=UTF-8");

        // Handle preflight OPTIONS request
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
