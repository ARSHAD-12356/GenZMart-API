<?php
/**
 * GenZMart API - Admin Dashboard & Platform Management Endpoint
 * GET / POST / PUT /api/admin/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized', 401);
    }

    if ($user['role'] !== 'admin') {
        ResponseHelper::error('Forbidden. Admin authorization required.', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $inputData = AuthHelper::getRequestData();
    $action = $_GET['action'] ?? $inputData['action'] ?? 'stats';

    if ($method === 'GET' && $action === 'stats') {
        $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid' OR order_status != 'cancelled'")->fetchColumn();
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalSellers = (int)$pdo->query("SELECT COUNT(*) FROM seller_profiles")->fetchColumn();
        $totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

        ResponseHelper::success('Admin statistics retrieved successfully', [
            'stats' => [
                'total_revenue' => $totalRevenue,
                'total_users' => $totalUsers,
                'total_sellers' => $totalSellers,
                'total_orders' => $totalOrders,
                'total_products' => $totalProducts
            ]
        ]);

    } elseif ($method === 'GET' && $action === 'users') {
        $stmt = $pdo->query("
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.created_at, r.name AS role,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS order_count
            FROM users u
            JOIN roles r ON u.role_id = r.id
            ORDER BY u.created_at DESC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Users list retrieved successfully', ['users' => $users]);

    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'update_user_status') {
        $targetUserId = isset($inputData['user_id']) ? (int)$inputData['user_id'] : 0;
        $status = isset($inputData['status']) ? trim($inputData['status']) : '';

        if ($targetUserId <= 0 || !in_array($status, ['active', 'inactive', 'suspended'])) {
            ResponseHelper::error('Valid user_id and status required', 400);
        }

        $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $upd->execute([$status, $targetUserId]);

        ResponseHelper::success("User status updated to {$status}");

    } elseif ($method === 'GET' && $action === 'sellers') {
        $stmt = $pdo->query("
            SELECT sp.*, u.first_name, u.last_name, u.email, u.phone
            FROM seller_profiles sp
            JOIN users u ON sp.user_id = u.id
            ORDER BY sp.created_at DESC
        ");
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Sellers list retrieved successfully', ['sellers' => $sellers]);

    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'approve_seller') {
        $sellerProfileId = isset($inputData['seller_id']) ? (int)$inputData['seller_id'] : 0;
        $status = isset($inputData['status']) ? trim($inputData['status']) : 'approved';

        if ($sellerProfileId <= 0 || !in_array($status, ['pending', 'approved', 'rejected', 'suspended'])) {
            ResponseHelper::error('Valid seller_id and status required', 400);
        }

        $upd = $pdo->prepare("UPDATE seller_profiles SET status = ? WHERE id = ? OR user_id = ?");
        $upd->execute([$status, $sellerProfileId, $sellerProfileId]);

        ResponseHelper::success("Seller profile status updated to {$status}");

    } elseif ($method === 'GET' && $action === 'products') {
        $stmt = $pdo->query("
            SELECT p.*, c.name AS category_name, b.name AS brand_name, sp.store_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
            ORDER BY p.created_at DESC
        ");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('All products retrieved successfully', ['products' => $products]);

    } elseif ($method === 'GET' && $action === 'orders') {
        $stmt = $pdo->query("
            SELECT o.*, CONCAT(u.first_name, ' ', u.last_name) AS customer_name, u.email AS customer_email,
            oa.recipient_name, oa.city, oa.state
            FROM orders o
            JOIN users u ON o.user_id = u.id
            LEFT JOIN order_addresses oa ON o.id = oa.order_id
            ORDER BY o.created_at DESC
        ");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('All orders retrieved successfully', ['orders' => $orders]);

    } else {
        ResponseHelper::error('Invalid admin action or method', 400);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Admin operation failed: ' . $e->getMessage(), 500);
}
