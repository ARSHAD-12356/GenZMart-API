<?php
/**
 * GenZMart API - User Logout Endpoint
 * 
 * Endpoint: POST /api/auth/logout.php
 * Destroys/revokes active session token.
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

// Handle CORS
ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();
    $token = AuthHelper::getTokenFromRequest();

    if ($token) {
        AuthHelper::destroySession($pdo, $token);
    }

    ResponseHelper::send(200, true, 'Successfully logged out');

} catch (Throwable $e) {
    ResponseHelper::send(200, true, 'Successfully logged out');
}
