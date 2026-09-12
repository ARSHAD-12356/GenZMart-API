<?php
/**
 * GenZMart API - Cart Endpoint
 * GET / POST / PUT / DELETE /api/cart/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized. Please log in to access cart.', 401);
    }

    $userId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure user has a cart
    $cartStmt = $pdo->prepare("SELECT id FROM carts WHERE user_id = ? LIMIT 1");
    $cartStmt->execute([$userId]);
    $cartId = $cartStmt->fetchColumn();

    if (!$cartId) {
        $createCart = $pdo->prepare("INSERT INTO carts (user_id) VALUES (?)");
        $createCart->execute([$userId]);
        $cartId = $pdo->lastInsertId();
    }

    if ($method === 'GET') {
        // Fetch cart items
        $stmt = $pdo->prepare("
            SELECT 
                ci.id AS item_id,
                ci.quantity,
                p.id AS product_id,
                p.name AS product_name,
                p.slug AS product_slug,
                p.price,
                p.sale_price,
                p.stock_quantity,
                pv.id AS variant_id,
                pv.variant_name,
                pv.price AS variant_price,
                pv.sale_price AS variant_sale_price,
                (
                    SELECT image_path 
                    FROM product_images 
                    WHERE product_id = p.id 
                    ORDER BY is_primary DESC, sort_order ASC, id ASC 
                    LIMIT 1
                ) AS image
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            LEFT JOIN product_variants pv ON ci.variant_id = pv.id
            WHERE ci.cart_id = ?
            ORDER BY ci.created_at DESC
        ");
        $stmt->execute([$cartId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSubtotal = 0;
        $formattedItems = array_map(function($i) use (&$totalSubtotal) {
            $unitPrice = $i['variant_sale_price'] ?? $i['variant_price'] ?? $i['sale_price'] ?? $i['price'];
            $unitPrice = (float)$unitPrice;
            $qty = (int)$i['quantity'];
            $itemTotal = $unitPrice * $qty;
            $totalSubtotal += $itemTotal;

            return [
                'item_id' => (int)$i['item_id'],
                'product_id' => (int)$i['product_id'],
                'variant_id' => $i['variant_id'] ? (int)$i['variant_id'] : null,
                'name' => $i['product_name'],
                'slug' => $i['product_slug'],
                'variant_name' => $i['variant_name'],
                'unit_price' => $unitPrice,
                'original_price' => (float)$i['price'],
                'quantity' => $qty,
                'item_total' => $itemTotal,
                'stock' => (int)$i['stock_quantity'],
                'image' => $i['image'] ?: 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp'
            ];
        }, $items);

        ResponseHelper::success('Cart retrieved successfully', [
            'cart_id' => (int)$cartId,
            'items' => $formattedItems,
            'total_items' => count($formattedItems),
            'subtotal' => $totalSubtotal
        ]);

    } elseif ($method === 'POST') {
        $data = AuthHelper::getRequestData();
        $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
        $variantId = isset($data['variant_id']) ? (int)$data['variant_id'] : null;
        $quantity = isset($data['quantity']) ? max(1, (int)$data['quantity']) : 1;

        if ($productId <= 0) {
            ResponseHelper::error('Invalid product_id', 400);
        }

        // Verify product exists and has stock
        $pStmt = $pdo->prepare("SELECT id, stock_quantity FROM products WHERE id = ? AND status = 'active'");
        $pStmt->execute([$productId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            ResponseHelper::error('Product not found or inactive', 404);
        }

        // Check existing item
        if ($variantId) {
            $checkStmt = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id = ?");
            $checkStmt->execute([$cartId, $productId, $variantId]);
        } else {
            $checkStmt = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id IS NULL");
            $checkStmt->execute([$cartId, $productId]);
        }
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newQty = $existing['quantity'] + $quantity;
            $updateStmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $updateStmt->execute([$newQty, $existing['id']]);
        } else {
            $insStmt = $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)");
            $insStmt->execute([$cartId, $productId, $variantId, $quantity]);
        }

        ResponseHelper::success('Item added to cart successfully');

    } elseif ($method === 'PUT') {
        $data = AuthHelper::getRequestData();
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : null;
        $productId = isset($data['product_id']) ? (int)$data['product_id'] : null;
        $quantity = isset($data['quantity']) ? (int)$data['quantity'] : 1;

        if ($quantity <= 0) {
            // Remove item
            if ($itemId) {
                $del = $pdo->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
                $del->execute([$itemId, $cartId]);
            } elseif ($productId) {
                $del = $pdo->prepare("DELETE FROM cart_items WHERE product_id = ? AND cart_id = ?");
                $del->execute([$productId, $cartId]);
            }
            ResponseHelper::success('Item removed from cart');
        }

        if ($itemId) {
            $upd = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?");
            $upd->execute([$quantity, $itemId, $cartId]);
        } elseif ($productId) {
            $upd = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE product_id = ? AND cart_id = ?");
            $upd->execute([$quantity, $productId, $cartId]);
        } else {
            ResponseHelper::error('Missing item_id or product_id', 400);
        }

        ResponseHelper::success('Cart quantity updated successfully');

    } elseif ($method === 'DELETE') {
        $data = AuthHelper::getRequestData();
        $clearAll = isset($_GET['clear']) || isset($data['clear']);
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : (isset($data['item_id']) ? (int)$data['item_id'] : null);
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : (isset($data['product_id']) ? (int)$data['product_id'] : null);

        if ($clearAll) {
            $del = $pdo->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            $del->execute([$cartId]);
            ResponseHelper::success('Cart cleared successfully');
        } elseif ($itemId) {
            $del = $pdo->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
            $del->execute([$itemId, $cartId]);
            ResponseHelper::success('Item removed from cart');
        } elseif ($productId) {
            $del = $pdo->prepare("DELETE FROM cart_items WHERE product_id = ? AND cart_id = ?");
            $del->execute([$productId, $cartId]);
            ResponseHelper::success('Item removed from cart');
        } else {
            ResponseHelper::error('Provide item_id, product_id, or clear=1', 400);
        }
    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Cart operation failed: ' . $e->getMessage(), 500);
}
