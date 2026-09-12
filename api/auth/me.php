<?php
/**
 * GenZMart API - Current User Endpoint
 * 
 * Endpoint: GET /api/auth/me.php
 * Returns the currently authenticated user's profile details based on Bearer token.
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

// Handle CORS
ResponseHelper::sendCorsHeaders();

// Allow GET and OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::send(401, false, 'Unauthorized or session expired');
    }

    ResponseHelper::send(200, true, 'User profile retrieved successfully', [
        'user' => $user
    ]);

} catch (PDOException $e) {
    ResponseHelper::error('A database error occurred while fetching user profile.', 500);
} catch (Throwable $e) {
    ResponseHelper::error('An unexpected error occurred while fetching user profile.', 500);
}
