<?php
/**
 * GenZMart API - Wishlist Endpoint
 * GET / POST / DELETE /api/wishlist/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized. Please log in to access wishlist.', 401);
    }

    $userId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure wishlist row exists
    $wStmt = $pdo->prepare("SELECT id FROM wishlists WHERE user_id = ? LIMIT 1");
    $wStmt->execute([$userId]);
    $wishlistId = $wStmt->fetchColumn();

    if (!$wishlistId) {
        $insW = $pdo->prepare("INSERT INTO wishlists (user_id) VALUES (?)");
        $insW->execute([$userId]);
        $wishlistId = $pdo->lastInsertId();
    }

    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT 
                wi.id AS wishlist_item_id,
                wi.created_at AS added_at,
                p.id AS product_id,
                p.name,
                p.slug,
                p.price,
                p.sale_price,
                p.stock_quantity,
                p.rating_avg,
                (
                    SELECT image_path 
                    FROM product_images 
                    WHERE product_id = p.id 
                    ORDER BY is_primary DESC, sort_order ASC, id ASC 
                    LIMIT 1
                ) AS image
            FROM wishlist_items wi
            JOIN products p ON wi.product_id = p.id
            WHERE wi.wishlist_id = ?
            ORDER BY wi.created_at DESC
        ");
        $stmt->execute([$wishlistId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($i) {
            return [
                'wishlist_item_id' => (int)$i['wishlist_item_id'],
                'product_id' => (int)$i['product_id'],
                'name' => $i['name'],
                'slug' => $i['slug'],
                'price' => (float)$i['price'],
                'sale_price' => $i['sale_price'] !== null ? (float)$i['sale_price'] : null,
                'stock' => (int)$i['stock_quantity'],
                'rating' => (float)$i['rating_avg'],
                'image' => $i['image'] ?: 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp',
                'added_at' => $i['added_at']
            ];
        }, $items);

        ResponseHelper::success('Wishlist retrieved successfully', [
            'wishlist' => $formatted,
            'total_items' => count($formatted)
        ]);

    } elseif ($method === 'POST') {
        $data = AuthHelper::getRequestData();
        $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;

        if ($productId <= 0) {
            ResponseHelper::error('Invalid product_id', 400);
        }

        // Check if already in wishlist
        $check = $pdo->prepare("SELECT id FROM wishlist_items WHERE wishlist_id = ? AND product_id = ?");
        $check->execute([$wishlistId, $productId]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            // Toggle off (remove)
            $del = $pdo->prepare("DELETE FROM wishlist_items WHERE id = ?");
            $del->execute([$existingId]);
            ResponseHelper::success('Removed from wishlist', ['wishlisted' => false]);
        } else {
            // Add
            $ins = $pdo->prepare("INSERT INTO wishlist_items (wishlist_id, product_id) VALUES (?, ?)");
            $ins->execute([$wishlistId, $productId]);
            ResponseHelper::success('Added to wishlist', ['wishlisted' => true]);
        }

    } elseif ($method === 'DELETE') {
        $data = AuthHelper::getRequestData();
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : (isset($data['product_id']) ? (int)$data['product_id'] : null);

        if (!$productId) {
            ResponseHelper::error('Missing product_id', 400);
        }

        $del = $pdo->prepare("DELETE FROM wishlist_items WHERE wishlist_id = ? AND product_id = ?");
        $del->execute([$wishlistId, $productId]);

        ResponseHelper::success('Removed from wishlist', ['wishlisted' => false]);
    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Wishlist operation failed: ' . $e->getMessage(), 500);
}
