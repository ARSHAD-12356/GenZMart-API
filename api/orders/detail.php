<?php
/**
 * GenZMart API - Single Order Detail Endpoint
 * GET /api/orders/detail.php?id=1 or ?order_number=GZM-...
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized', 401);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $number = isset($_GET['order_number']) ? trim($_GET['order_number']) : null;

    if (!$id && !$number) {
        ResponseHelper::error('Order ID or order_number parameter required', 400);
    }

    $where = $id ? "o.id = :id" : "o.order_number = :num";
    $params = $id ? ['id' => $id] : ['num' => $number];

    // If customer, restrict to their own order
    if ($user['role'] === 'customer') {
        $where .= " AND o.user_id = :uid";
        $params['uid'] = $user['id'];
    }

    $stmt = $pdo->prepare("SELECT o.* FROM orders o WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHelper::error('Order not found', 404);
    }

    $orderId = (int)$order['id'];

    // Items
    $iStmt = $pdo->prepare("
        SELECT oi.*, (SELECT image_path FROM product_images WHERE product_id = oi.product_id LIMIT 1) AS image
        FROM order_items oi
        WHERE oi.order_id = ?
    ");
    $iStmt->execute([$orderId]);
    $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

    // Address
    $aStmt = $pdo->prepare("SELECT * FROM order_addresses WHERE order_id = ? LIMIT 1");
    $aStmt->execute([$orderId]);
    $address = $aStmt->fetch(PDO::FETCH_ASSOC);

    // History log
    $hStmt = $pdo->prepare("SELECT id, status, notes, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
    $hStmt->execute([$orderId]);
    $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

    ResponseHelper::success('Order details retrieved successfully', [
        'order' => [
            'id' => $orderId,
            'order_number' => $order['order_number'],
            'order_status' => $order['order_status'],
            'payment_status' => $order['payment_status'],
            'payment_method' => $order['payment_method'],
            'subtotal' => (float)$order['subtotal'],
            'discount_amount' => (float)$order['discount_amount'],
            'shipping_fee' => (float)$order['shipping_fee'],
            'tax_amount' => (float)$order['tax_amount'],
            'total_amount' => (float)$order['total_amount'],
            'notes' => $order['notes'],
            'created_at' => $order['created_at'],
            'items' => array_map(function($i) {
                return [
                    'id' => (int)$i['id'],
                    'product_id' => (int)$i['product_id'],
                    'seller_id' => (int)$i['seller_id'],
                    'product_name' => $i['product_name'],
                    'variant_name' => $i['variant_name'],
                    'price' => (float)$i['price'],
                    'quantity' => (int)$i['quantity'],
                    'total_price' => (float)$i['total_price'],
                    'image' => $i['image'] ?: 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp'
                ];
            }, $items),
            'shipping_address' => $address ?: null,
            'status_history' => $history
        ]
    ]);

} catch (Throwable $e) {
    ResponseHelper::error('Failed to fetch order detail: ' . $e->getMessage(), 500);
}
