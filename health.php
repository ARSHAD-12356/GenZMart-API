<?php
/**
 * GenZMart API - Render Health Check Endpoint
 * 
 * Endpoint: GET /health.php
 * Lightweight health check endpoint for cloud service health probes (Render).
 * Returns HTTP 200 OK without requiring active database connection.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

http_response_code(200);

echo json_encode([
    'status' => 'OK',
    'service' => 'GenZMart API',
    'timestamp' => date('Y-m-d H:i:s T')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit();
