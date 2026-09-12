<?php
/**
 * GenZMart API - User Login Endpoint
 * 
 * Endpoint: POST /api/auth/login.php
 * Verifies email & password credentials, validates active account status,
 * and returns authentication session token with user profile.
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

// Handle CORS
ResponseHelper::sendCorsHeaders();

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $data = AuthHelper::getRequestData();
    $errors = [];

    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    }
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    }

    if (!empty($errors)) {
        ResponseHelper::send(400, false, 'Validation failed', null, $errors);
    }

    $pdo = Database::getConnection();

    // Query user by email
    $stmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.password, 
            u.status,
            r.name AS role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.email = :email
        LIMIT 1
    ");

    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        ResponseHelper::send(401, false, 'Invalid email address or password.', null, [
            'credentials' => 'Invalid email address or password.'
        ]);
    }

    // Check account status
    if ($user['status'] !== 'active') {
        ResponseHelper::send(403, false, 'Your account is currently ' . $user['status'] . '. Please contact support.');
    }

    // Create session token
    $token = AuthHelper::createSession($pdo, $user['id']);

    // Prepare response user object (Never expose password / password hash)
    $userPayload = [
        'id' => (int) $user['id'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'role' => strtolower($user['role_name'])
    ];

    ResponseHelper::send(200, true, 'Login successful', [
        'user' => $userPayload,
        'token' => $token
    ]);

} catch (PDOException $e) {
    ResponseHelper::error('A database error occurred during login.', 500);
} catch (Throwable $e) {
    ResponseHelper::error('An unexpected error occurred during login.', 500);
}
