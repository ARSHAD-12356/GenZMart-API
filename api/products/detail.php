<?php
/**
 * GenZMart API - Single Product Details Endpoint
 * GET /api/products/detail.php?id=1 or ?slug=aurora-wireless-headphones
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;

    if (!$id && !$slug) {
        ResponseHelper::error('Product ID or slug parameter is required', 400);
    }

    $where = $id ? "p.id = :id" : "p.slug = :slug";
    $param = $id ? ['id' => $id] : ['slug' => $slug];

    $sql = "
        SELECT 
            p.*,
            c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
            sc.id AS subcategory_id, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
            b.id AS brand_id, b.name AS brand_name, b.slug AS brand_slug,
            sp.store_name, sp.store_slug
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
        WHERE {$where} AND p.status = 'active'
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($param);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        ResponseHelper::error('Product not found', 404);
    }

    $productId = (int)$p['id'];

    // Images
    $imgStmt = $pdo->prepare("SELECT id, image_path, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC");
    $imgStmt->execute([$productId]);
    $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
    $imageUrls = array_column($images, 'image_path');

    // Variants
    $varStmt = $pdo->prepare("SELECT id, sku, variant_name, price, sale_price, stock_quantity, attributes FROM product_variants WHERE product_id = ?");
    $varStmt->execute([$productId]);
    $variants = $varStmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedVariants = array_map(function($v) {
        return [
            'id' => (int)$v['id'],
            'sku' => $v['sku'],
            'variant_name' => $v['variant_name'],
            'price' => $v['price'] !== null ? (float)$v['price'] : null,
            'sale_price' => $v['sale_price'] !== null ? (float)$v['sale_price'] : null,
            'stock_quantity' => (int)$v['stock_quantity'],
            'attributes' => $v['attributes'] ? json_decode($v['attributes'], true) : null
        ];
    }, $variants);

    // Reviews summary
    $revStmt = $pdo->prepare("
        SELECT r.id, r.rating, r.title, r.comment, r.created_at, CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.avatar
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.product_id = ? AND r.is_approved = 1
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $revStmt->execute([$productId]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'id' => (int)$p['id'],
        'seller_id' => (int)$p['seller_id'],
        'name' => $p['name'],
        'slug' => $p['slug'],
        'description' => $p['description'],
        'short_description' => $p['short_description'],
        'price' => (float)$p['price'],
        'sale_price' => $p['sale_price'] !== null ? (float)$p['sale_price'] : null,
        'sku' => $p['sku'],
        'stock_quantity' => (int)$p['stock_quantity'],
        'is_featured' => (bool)$p['is_featured'],
        'is_new_arrival' => (bool)$p['is_new_arrival'],
        'is_best_seller' => (bool)$p['is_best_seller'],
        'rating_avg' => (float)$p['rating_avg'],
        'rating_count' => (int)$p['rating_count'],
        'created_at' => $p['created_at'],
        'category' => [
            'id' => (int)$p['category_id'],
            'name' => $p['category_name'],
            'slug' => $p['category_slug']
        ],
        'subcategory' => $p['subcategory_id'] ? [
            'id' => (int)$p['subcategory_id'],
            'name' => $p['subcategory_name'],
            'slug' => $p['subcategory_slug']
        ] : null,
        'brand' => $p['brand_id'] ? [
            'id' => (int)$p['brand_id'],
            'name' => $p['brand_name'],
            'slug' => $p['brand_slug']
        ] : null,
        'seller' => [
            'store_name' => $p['store_name'] ?: 'GenZMart Official',
            'store_slug' => $p['store_slug'] ?: 'genzmart'
        ],
        'images' => !empty($imageUrls) ? $imageUrls : ['https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp'],
        'variants' => $formattedVariants,
        'recent_reviews' => $reviews
    ];

    ResponseHelper::success('Product details retrieved successfully', ['product' => $result]);

} catch (Throwable $e) {
    ResponseHelper::error('Failed to fetch product details: ' . $e->getMessage(), 500);
}
