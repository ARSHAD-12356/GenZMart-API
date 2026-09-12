<?php
/**
 * GenZMart API - Orders Endpoint
 * GET / POST /api/orders/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized. Please log in to access orders.', 401);
    }

    $userId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Fetch user's orders
        $stmt = $pdo->prepare("
            SELECT o.*, oa.recipient_name, oa.city, oa.state, oa.postal_code, oa.phone
            FROM orders o
            LEFT JOIN order_addresses oa ON o.id = oa.order_id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $itemStmt = $pdo->prepare("
            SELECT oi.*, (SELECT image_path FROM product_images WHERE product_id = oi.product_id LIMIT 1) AS image
            FROM order_items oi
            WHERE oi.order_id = ?
        ");

        $formattedOrders = [];
        foreach ($orders as $o) {
            $orderId = (int)$o['id'];
            $itemStmt->execute([$orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedItems = array_map(function($i) {
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
            }, $items);

            $formattedOrders[] = [
                'id' => $orderId,
                'order_number' => $o['order_number'],
                'order_status' => $o['order_status'],
                'payment_status' => $o['payment_status'],
                'payment_method' => $o['payment_method'],
                'subtotal' => (float)$o['subtotal'],
                'discount_amount' => (float)$o['discount_amount'],
                'shipping_fee' => (float)$o['shipping_fee'],
                'tax_amount' => (float)$o['tax_amount'],
                'total_amount' => (float)$o['total_amount'],
                'created_at' => $o['created_at'],
                'items' => $formattedItems,
                'recipient' => $o['recipient_name'] ?: ($user['first_name'] . ' ' . $user['last_name']),
                'address_summary' => ($o['city'] && $o['state']) ? "{$o['city']}, {$o['state']} - {$o['postal_code']}" : 'Shipping Address'
            ];
        }

        ResponseHelper::success('Orders retrieved successfully', ['orders' => $formattedOrders]);

    } elseif ($method === 'POST') {
        $data = AuthHelper::getRequestData();

        $addressId = isset($data['address_id']) ? (int)$data['address_id'] : null;
        $couponCode = isset($data['coupon_code']) ? trim($data['coupon_code']) : null;
        $paymentMethod = isset($data['payment_method']) ? trim($data['payment_method']) : 'cod';
        $notes = isset($data['notes']) ? trim($data['notes']) : null;

        // Get user cart items
        $cartStmt = $pdo->prepare("
            SELECT ci.*, p.name AS product_name, p.price, p.sale_price, p.seller_id, p.stock_quantity, pv.variant_name, pv.price AS var_price, pv.sale_price AS var_sale_price
            FROM carts c
            JOIN cart_items ci ON c.id = ci.cart_id
            JOIN products p ON ci.product_id = p.id
            LEFT JOIN product_variants pv ON ci.variant_id = pv.id
            WHERE c.user_id = ?
        ");
        $cartStmt->execute([$userId]);
        $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        $subtotal = 0.0;
        $orderItemsPayload = [];

        if (!empty($cartItems)) {
            foreach ($cartItems as $ci) {
                $unitPrice = $ci['var_sale_price'] ?? $ci['var_price'] ?? $ci['sale_price'] ?? $ci['price'];
                $unitPrice = (float)$unitPrice;
                $qty = (int)$ci['quantity'];
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $orderItemsPayload[] = [
                    'product_id' => (int)$ci['product_id'],
                    'seller_id' => (int)$ci['seller_id'],
                    'variant_id' => $ci['variant_id'] ? (int)$ci['variant_id'] : null,
                    'product_name' => $ci['product_name'],
                    'variant_name' => $ci['variant_name'],
                    'price' => $unitPrice,
                    'quantity' => $qty,
                    'total_price' => $lineTotal
                ];
            }
        } elseif (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $pid = (int)($item['product_id'] ?? $item['productId'] ?? 0);
                $pName = $item['product_name'] ?? $item['name'] ?? 'Product';
                $varName = $item['variant_name'] ?? $item['variant'] ?? null;
                $price = (float)($item['price'] ?? 0);
                $qty = max(1, (int)($item['quantity'] ?? 1));
                $sellerId = (int)($item['seller_id'] ?? $item['sellerId'] ?? 1);

                if ($pid > 0) {
                    $pStmt = $pdo->prepare("SELECT name, price, sale_price, seller_id FROM products WHERE id = ?");
                    $pStmt->execute([$pid]);
                    $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($prod) {
                        if (empty($pName)) $pName = $prod['name'];
                        if ($price <= 0) $price = (float)($prod['sale_price'] ?? $prod['price']);
                        if ($sellerId <= 0) $sellerId = (int)$prod['seller_id'];
                    }
                }
                if ($sellerId <= 0) $sellerId = 1;
                $lineTotal = $price * $qty;
                $subtotal += $lineTotal;

                $orderItemsPayload[] = [
                    'product_id' => $pid > 0 ? $pid : 1,
                    'seller_id' => $sellerId,
                    'variant_id' => null,
                    'product_name' => $pName,
                    'variant_name' => $varName,
                    'price' => $price,
                    'quantity' => $qty,
                    'total_price' => $lineTotal
                ];
            }
        } else {
            ResponseHelper::error('Cart is empty. Cannot create order.', 400);
        }

        // Fetch Address
        $addrRow = null;
        if ($addressId) {
            $aStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
            $aStmt->execute([$addressId, $userId]);
            $addrRow = $aStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$addrRow) {
            $aStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
            $aStmt->execute([$userId]);
            $addrRow = $aStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$addrRow && isset($data['shipping_address'])) {
            $sa = $data['shipping_address'];
            if (is_array($sa)) {
                $addrRow = [
                    'recipient_name' => $sa['recipient_name'] ?? $sa['name'] ?? ($user['first_name'] . ' ' . $user['last_name']),
                    'phone' => $sa['phone'] ?? ($user['phone'] ?: '9999999999'),
                    'address_line1' => $sa['address_line1'] ?? $sa['line1'] ?? '123 Main St',
                    'address_line2' => $sa['address_line2'] ?? $sa['line2'] ?? null,
                    'city' => $sa['city'] ?? 'Mumbai',
                    'state' => $sa['state'] ?? 'Maharashtra',
                    'postal_code' => $sa['postal_code'] ?? $sa['zip'] ?? '400001',
                    'country' => $sa['country'] ?? 'India',
                ];
            }
        }

        if (!$addrRow) {
            $addrRow = [
                'recipient_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['name'] ?? 'Customer'),
                'phone' => $user['phone'] ?? '9999999999',
                'address_line1' => 'Default Shipping Address',
                'address_line2' => null,
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'postal_code' => '400001',
                'country' => 'India',
            ];
        }

        // Coupon calculation
        $discountAmount = isset($data['discount']) ? (float)$data['discount'] : 0.0;
        $couponId = null;
        if (!empty($couponCode)) {
            $cpStmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 1 LIMIT 1");
            $cpStmt->execute([$couponCode]);
            $cp = $cpStmt->fetch(PDO::FETCH_ASSOC);
            if ($cp && $subtotal >= (float)$cp['min_order_amount']) {
                $couponId = (int)$cp['id'];
                if ($cp['discount_type'] === 'percentage') {
                    $discountAmount = ($subtotal * (float)$cp['discount_value']) / 100.0;
                    if ($cp['max_discount_amount'] !== null) {
                        $discountAmount = min($discountAmount, (float)$cp['max_discount_amount']);
                    }
                } else {
                    $discountAmount = (float)$cp['discount_value'];
                }
                $discountAmount = min($discountAmount, $subtotal);
            }
        }

        $shippingFee = isset($data['shipping']) ? (float)$data['shipping'] : ($subtotal > 100.0 ? 0.0 : 10.0);
        $taxAmount = isset($data['tax']) ? (float)$data['tax'] : round(($subtotal - $discountAmount) * 0.05, 2);
        $totalAmount = isset($data['total']) ? (float)$data['total'] : max(0, round($subtotal - $discountAmount + $shippingFee + $taxAmount, 2));

        $orderNumber = !empty($data['order_number']) ? trim($data['order_number']) : (!empty($data['orderNumber']) ? trim($data['orderNumber']) : ('GZ-' . date('Y') . '-' . rand(1000, 9999)));

        // BEGIN MYSQL TRANSACTION
        $pdo->beginTransaction();

        $insOrder = $pdo->prepare("
            INSERT INTO orders (order_number, user_id, coupon_id, subtotal, discount_amount, shipping_fee, tax_amount, total_amount, payment_method, payment_status, order_status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?)
        ");
        $insOrder->execute([$orderNumber, $userId, $couponId, $subtotal, $discountAmount, $shippingFee, $taxAmount, $totalAmount, $paymentMethod, $notes]);
        $orderId = $pdo->lastInsertId();

        // Insert order items & reduce stock
        $insItem = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, seller_id, variant_id, product_name, variant_name, price, quantity, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $decStock = $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");

        foreach ($orderItemsPayload as $oi) {
            $insItem->execute([$orderId, $oi['product_id'], $oi['seller_id'], $oi['variant_id'], $oi['product_name'], $oi['variant_name'], $oi['price'], $oi['quantity'], $oi['total_price']]);
            $decStock->execute([$oi['quantity'], $oi['product_id']]);
        }

        // Insert immutable order address snapshot
        $insAddr = $pdo->prepare("
            INSERT INTO order_addresses (order_id, recipient_name, phone, address_line1, address_line2, city, state, postal_code, country)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insAddr->execute([$orderId, $addrRow['recipient_name'], $addrRow['phone'], $addrRow['address_line1'], $addrRow['address_line2'], $addrRow['city'], $addrRow['state'], $addrRow['postal_code'], $addrRow['country']]);

        // Insert status history
        $insHist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'pending', 'Order placed successfully', ?)");
        $insHist->execute([$orderId, $userId]);

        // Clear Cart
        $clearCart = $pdo->prepare("DELETE ci FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = ?");
        $clearCart->execute([$userId]);

        // Notification
        $insNotif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')");
        $insNotif->execute([$userId, 'Order Confirmed!', "Order #{$orderNumber} placed successfully. We are processing it now!"]);

        $pdo->commit();

        ResponseHelper::success('Order placed successfully', [
            'order_id' => (int)$orderId,
            'order_number' => $orderNumber,
            'total_amount' => $totalAmount,
            'order_status' => 'pending'
        ], 201);

    } elseif ($method === 'PUT') {
        $data = AuthHelper::getRequestData();
        $orderId = isset($data['order_id']) ? (int)$data['order_id'] : (isset($data['id']) ? (int)$data['id'] : null);
        $orderNumber = isset($data['order_number']) ? trim($data['order_number']) : (isset($data['orderNumber']) ? trim($data['orderNumber']) : null);
        $newStatus = isset($data['status']) ? trim(strtolower($data['status'])) : (isset($data['orderStatus']) ? trim(strtolower($data['orderStatus'])) : 'cancelled');

        if (!$orderId && !$orderNumber) {
            ResponseHelper::error('Missing order_id or order_number', 400);
        }

        $where = $orderId ? "id = ?" : "order_number = ?";
        $params = $orderId ? [$orderId] : [$orderNumber];

        if ($user['role'] === 'customer') {
            $where .= " AND user_id = ?";
            $params[] = $user['id'];
        }

        $stmt = $pdo->prepare("SELECT id, order_status FROM orders WHERE {$where} LIMIT 1");
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ResponseHelper::error('Order not found or unauthorized', 404);
        }

        $targetOrderId = (int)$order['id'];

        $statusMap = [
            'placed' => 'pending',
            'confirmed' => 'processing',
            'packed' => 'processing',
            'shipped' => 'shipped',
            'out for delivery' => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled'
        ];
        $dbStatus = $statusMap[$newStatus] ?? $newStatus;

        $pdo->beginTransaction();

        $upd = $pdo->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$dbStatus, $targetOrderId]);

        if ($dbStatus === 'cancelled') {
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$targetOrderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $incStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
            foreach ($items as $item) {
                $incStock->execute([(int)$item['quantity'], (int)$item['product_id']]);
            }
        }

        $insHist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, ?, ?, ?)");
        $insHist->execute([$targetOrderId, $dbStatus, "Order status updated to {$dbStatus}", $user['id']]);

        $pdo->commit();

        ResponseHelper::success('Order status updated successfully', [
            'order_id' => $targetOrderId,
            'order_status' => $dbStatus
        ]);

    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ResponseHelper::error('Order creation failed: ' . $e->getMessage(), 500);
}
