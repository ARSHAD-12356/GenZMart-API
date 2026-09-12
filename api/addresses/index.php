<?php
/**
 * GenZMart API - User Addresses Endpoint
 * GET / POST / PUT / DELETE /api/addresses/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized. Please log in to manage addresses.', 401);
    }

    $userId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
        $stmt->execute([$userId]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'title' => $a['title'],
                'recipient_name' => $a['recipient_name'],
                'phone' => $a['phone'],
                'address_line1' => $a['address_line1'],
                'address_line2' => $a['address_line2'],
                'city' => $a['city'],
                'state' => $a['state'],
                'postal_code' => $a['postal_code'],
                'country' => $a['country'],
                'is_default' => (bool)$a['is_default']
            ];
        }, $addresses);

        ResponseHelper::success('Addresses retrieved successfully', ['addresses' => $formatted]);

    } elseif ($method === 'POST') {
        $data = AuthHelper::getRequestData();

        $title = !empty($data['title']) ? trim($data['title']) : 'Home';
        $recipientName = !empty($data['recipient_name']) ? trim($data['recipient_name']) : ($user['first_name'] . ' ' . $user['last_name']);
        $phone = !empty($data['phone']) ? trim($data['phone']) : ($user['phone'] ?: '9999999999');
        $line1 = !empty($data['address_line1']) ? trim($data['address_line1']) : '';
        $line2 = !empty($data['address_line2']) ? trim($data['address_line2']) : null;
        $city = !empty($data['city']) ? trim($data['city']) : 'Mumbai';
        $state = !empty($data['state']) ? trim($data['state']) : 'Maharashtra';
        $postalCode = !empty($data['postal_code']) ? trim($data['postal_code']) : '400001';
        $country = !empty($data['country']) ? trim($data['country']) : 'India';
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        if (empty($line1)) {
            ResponseHelper::error('Address Line 1 is required', 400);
        }

        if ($isDefault === 1) {
            $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        } else {
            // Check if this is first address
            $count = $pdo->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?");
            $count->execute([$userId]);
            if ((int)$count->fetchColumn() === 0) {
                $isDefault = 1;
            }
        }

        $ins = $pdo->prepare("INSERT INTO user_addresses (user_id, title, recipient_name, phone, address_line1, address_line2, city, state, postal_code, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$userId, $title, $recipientName, $phone, $line1, $line2, $city, $state, $postalCode, $country, $isDefault]);
        $addressId = $pdo->lastInsertId();

        ResponseHelper::success('Address added successfully', ['address_id' => (int)$addressId]);

    } elseif ($method === 'PUT') {
        $data = AuthHelper::getRequestData();
        $addressId = isset($data['id']) ? (int)$data['id'] : 0;
        $setDefaultOnly = isset($data['set_default']) && $data['set_default'];

        if ($addressId <= 0) {
            ResponseHelper::error('Invalid address id', 400);
        }

        if ($setDefaultOnly) {
            $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$addressId, $userId]);
            ResponseHelper::success('Default address updated');
        }

        $title = !empty($data['title']) ? trim($data['title']) : 'Home';
        $recipientName = !empty($data['recipient_name']) ? trim($data['recipient_name']) : '';
        $phone = !empty($data['phone']) ? trim($data['phone']) : '';
        $line1 = !empty($data['address_line1']) ? trim($data['address_line1']) : '';
        $line2 = !empty($data['address_line2']) ? trim($data['address_line2']) : null;
        $city = !empty($data['city']) ? trim($data['city']) : '';
        $state = !empty($data['state']) ? trim($data['state']) : '';
        $postalCode = !empty($data['postal_code']) ? trim($data['postal_code']) : '';
        $country = !empty($data['country']) ? trim($data['country']) : 'India';
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        if ($isDefault === 1) {
            $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        }

        $upd = $pdo->prepare("UPDATE user_addresses SET title = ?, recipient_name = ?, phone = ?, address_line1 = ?, address_line2 = ?, city = ?, state = ?, postal_code = ?, country = ?, is_default = ? WHERE id = ? AND user_id = ?");
        $upd->execute([$title, $recipientName, $phone, $line1, $line2, $city, $state, $postalCode, $country, $isDefault, $addressId, $userId]);

        ResponseHelper::success('Address updated successfully');

    } elseif ($method === 'DELETE') {
        $data = AuthHelper::getRequestData();
        $addressId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($data['id']) ? (int)$data['id'] : 0);

        if ($addressId <= 0) {
            ResponseHelper::error('Invalid address id', 400);
        }

        $del = $pdo->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $del->execute([$addressId, $userId]);

        ResponseHelper::success('Address deleted successfully');
    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Address operation failed: ' . $e->getMessage(), 500);
}
