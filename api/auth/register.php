<?php
/**
 * GenZMart API - User Registration Endpoint
 * 
 * Endpoint: POST /api/auth/register.php
 * Handles user account creation, input validation, secure password hashing,
 * role assignment, and initial session creation.
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

    // Extract & sanitize inputs
    $name = trim($data['name'] ?? '');
    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';
    $roleName = strtolower(trim($data['role'] ?? 'customer'));

    // Handle single 'name' field if provided
    if (empty($firstName) && !empty($name)) {
        $parts = explode(' ', $name, 2);
        $firstName = $parts[0];
        $lastName = $parts[1] ?? '';
    }

    // Input Validation
    if (empty($firstName)) {
        $errors['name'] = 'Name is required.';
    }

    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters long.';
    }

    if (empty($confirmPassword)) {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!empty($errors)) {
        ResponseHelper::send(400, false, 'Validation failed', null, $errors);
    }

    $pdo = Database::getConnection();

    // Check if email already exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        ResponseHelper::send(400, false, 'An account with this email address already exists.', null, [
            'email' => 'Email is already registered.'
        ]);
    }

    // Resolve Role ID from database with dynamic fallback and auto-seeding if missing
    $targetRole = ($roleName === 'seller') ? 'Seller' : 'Customer';
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $roleStmt->execute(['name' => $targetRole]);
    $roleRow = $roleStmt->fetch();

    if ($roleRow) {
        $roleId = (int) $roleRow['id'];
    } else {
        // Search for any existing role ID in roles table
        $anyRoleStmt = $pdo->query("SELECT id FROM roles ORDER BY id ASC LIMIT 1");
        $anyRoleRow = $anyRoleStmt ? $anyRoleStmt->fetch() : false;

        if ($anyRoleRow) {
            $roleId = (int) $anyRoleRow['id'];
        } else {
            // Seed default roles if roles table is unseeded
            $pdo->exec("
                INSERT INTO roles (id, name, description) VALUES
                (1, 'Admin', 'System Administrator'),
                (2, 'Seller', 'Vendor/Seller'),
                (3, 'Customer', 'Shopper account')
                ON DUPLICATE KEY UPDATE name=VALUES(name)
            ");
            $roleId = ($targetRole === 'Seller') ? 2 : 3;
        }
    }

    // Securely hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Insert user into users table
    $insertStmt = $pdo->prepare("
        INSERT INTO users (role_id, first_name, last_name, email, password, status)
        VALUES (:role_id, :first_name, :last_name, :email, :password, 'active')
    ");

    $insertStmt->execute([
        'role_id' => $roleId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password' => $passwordHash
    ]);

    $userId = $pdo->lastInsertId();

    // If registering as a Seller, create initial seller profile
    if ($targetRole === 'Seller') {
        $storeName = !empty($name) ? $name . " Store" : $firstName . " Store";
        $storeSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $storeName)) . '-' . $userId;

        $sellerStmt = $pdo->prepare("
            INSERT INTO seller_profiles (user_id, store_name, store_slug, status)
            VALUES (:user_id, :store_name, :store_slug, 'approved')
        ");
        $sellerStmt->execute([
            'user_id' => $userId,
            'store_name' => $storeName,
            'store_slug' => $storeSlug
        ]);
    }

    // Create session token for auto-login after signup
    $token = AuthHelper::createSession($pdo, $userId);

    // Prepare response data (Strictly exclude password / hash)
    $userPayload = [
        'id' => (int) $userId,
        'name' => trim($firstName . ' ' . $lastName),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => strtolower($targetRole)
    ];

    ResponseHelper::send(201, true, 'User account registered successfully', [
        'user' => $userPayload,
        'token' => $token
    ]);

} catch (PDOException $e) {
    $config = require __DIR__ . '/../../config/config.php';
    $dbPass = $config['db']['password'] ?? '';
    $errorMessage = $e->getMessage();
    if (!empty($dbPass)) {
        $errorMessage = str_replace($dbPass, '********', $errorMessage);
    }
    ResponseHelper::send(500, false, 'A database error occurred during registration.', null, [
        'error_details' => $errorMessage,
        'sql_state' => $e->getCode()
    ]);
} catch (Throwable $e) {
    $config = require __DIR__ . '/../../config/config.php';
    $dbPass = $config['db']['password'] ?? '';
    $errorMessage = $e->getMessage();
    if (!empty($dbPass)) {
        $errorMessage = str_replace($dbPass, '********', $errorMessage);
    }
    ResponseHelper::send(500, false, 'An unexpected error occurred during registration.', null, [
        'error_details' => $errorMessage
    ]);
}
