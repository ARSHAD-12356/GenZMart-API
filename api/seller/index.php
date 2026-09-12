<?php
/**
 * GenZMart API - Seller Dashboard & Product/Order Management Endpoint
 * GET / POST / PUT / DELETE /api/seller/index.php
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

    if (!in_array($user['role'], ['seller', 'admin'])) {
        ResponseHelper::error('Forbidden. Seller role required.', 403);
    }

    $sellerId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';

    // Parse JSON payload if present
    $inputData = AuthHelper::getRequestData();
    if (isset($inputData['action'])) {
        $action = $inputData['action'];
    }

    if ($method === 'GET' && $action === 'dashboard') {
        // Seller metrics
        $salesStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) AS total_sales, COUNT(DISTINCT order_id) AS total_orders 
            FROM order_items 
            WHERE seller_id = ?
        ");
        $salesStmt->execute([$sellerId]);
        $salesData = $salesStmt->fetch(PDO::FETCH_ASSOC);

        $pCountStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status != 'inactive'");
        $pCountStmt->execute([$sellerId]);
        $prodCount = $pCountStmt->fetchColumn();

        // Seller profile
        $spStmt = $pdo->prepare("SELECT * FROM seller_profiles WHERE user_id = ? LIMIT 1");
        $spStmt->execute([$sellerId]);
        $profile = $spStmt->fetch(PDO::FETCH_ASSOC);

        // Recent seller orders
        $roStmt = $pdo->prepare("
            SELECT DISTINCT o.id, o.order_number, o.order_status, o.total_amount, o.created_at, oa.recipient_name
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN order_addresses oa ON o.id = oa.order_id
            WHERE oi.seller_id = ?
            ORDER BY o.created_at DESC
            LIMIT 5
        ");
        $roStmt->execute([$sellerId]);
        $recentOrders = $roStmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Seller dashboard summary retrieved successfully', [
            'profile' => $profile,
            'metrics' => [
                'total_sales' => (float)$salesData['total_sales'],
                'total_orders' => (int)$salesData['total_orders'],
                'total_products' => (int)$prodCount,
                'rating_avg' => $profile ? (float)$profile['rating_avg'] : 4.8
            ],
            'recent_orders' => $recentOrders
        ]);

    } elseif ($method === 'GET' && $action === 'products') {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name, b.name AS brand_name,
            (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) AS image
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE p.seller_id = ? AND p.status != 'inactive'
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$sellerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Seller products retrieved successfully', ['products' => $products]);

    } elseif (($method === 'POST' || $method === 'PUT') && ($action === 'add_product' || $action === 'update_product')) {
        $name = isset($inputData['name']) ? trim($inputData['name']) : '';
        $categoryId = isset($inputData['category_id']) ? (int)$inputData['category_id'] : 1;
        $price = isset($inputData['price']) ? (float)$inputData['price'] : 0.0;
        $salePrice = isset($inputData['sale_price']) && $inputData['sale_price'] !== '' ? (float)$inputData['sale_price'] : null;
        $stock = isset($inputData['stock_quantity']) ? (int)$inputData['stock_quantity'] : 10;
        $desc = isset($inputData['description']) ? trim($inputData['description']) : '';
        $shortDesc = isset($inputData['short_description']) ? trim($inputData['short_description']) : '';

        if (empty($name) || $price <= 0) {
            ResponseHelper::error('Product name and valid price are required', 400);
        }

        if ($action === 'add_product' || $method === 'POST') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . time();
            $sku = 'SKU-' . strtoupper(substr(md5(uniqid()), 0, 8));

            $ins = $pdo->prepare("INSERT INTO products (seller_id, category_id, name, slug, description, short_description, price, sale_price, sku, stock_quantity, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $ins->execute([$sellerId, $categoryId, $name, $slug, $desc, $shortDesc, $price, $salePrice, $sku, $stock]);
            $pId = $pdo->lastInsertId();

            $image = !empty($inputData['image']) ? trim($inputData['image']) : 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp';
            $insImg = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 1)");
            $insImg->execute([$pId, $image]);

            ResponseHelper::success('Product added successfully', ['product_id' => (int)$pId]);
        } else {
            $productId = isset($inputData['id']) ? (int)$inputData['id'] : 0;
            if ($productId <= 0) {
                ResponseHelper::error('Product ID required for update', 400);
            }

            $upd = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, price = ?, sale_price = ?, stock_quantity = ?, description = ?, short_description = ? WHERE id = ? AND seller_id = ?");
            $upd->execute([$name, $categoryId, $price, $salePrice, $stock, $desc, $shortDesc, $productId, $sellerId]);

            ResponseHelper::success('Product updated successfully');
        }

    } elseif (($method === 'DELETE' || $action === 'delete_product')) {
        $productId = isset($inputData['id']) ? (int)$inputData['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($productId <= 0) {
            ResponseHelper::error('Product ID required', 400);
        }

        $del = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ? AND seller_id = ?");
        $del->execute([$productId, $sellerId]);

        ResponseHelper::success('Product deactivated successfully');

    } elseif ($method === 'GET' && $action === 'orders') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT o.*, oa.recipient_name, oa.phone, oa.city, oa.state
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN order_addresses oa ON o.id = oa.order_id
            WHERE oi.seller_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$sellerId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Seller orders retrieved successfully', ['orders' => $orders]);

    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'update_order_status') {
        $orderId = isset($inputData['order_id']) ? (int)$inputData['order_id'] : 0;
        $status = isset($inputData['status']) ? trim($inputData['status']) : '';

        $allowed = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        if ($orderId <= 0 || !in_array($status, $allowed)) {
            ResponseHelper::error('Valid order_id and status required', 400);
        }

        // Fetch order buyer
        $oStmt = $pdo->prepare("SELECT user_id, order_number FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $ord = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ord) {
            ResponseHelper::error('Order not found', 404);
        }

        $upd = $pdo->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $upd->execute([$status, $orderId]);

        // Insert history
        $insH = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, ?, ?, ?)");
        $insH->execute([$orderId, $status, "Status updated to {$status} by seller", $sellerId]);

        // Notify Buyer
        $insN = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')");
        $insN->execute([$ord['user_id'], "Order #{$ord['order_number']} Updated", "Your order status has changed to {$status}.", 'order']);

        ResponseHelper::success('Order status updated successfully');
    } else {
        ResponseHelper::error('Invalid seller action or method', 400);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Seller operation failed: ' . $e->getMessage(), 500);
}
