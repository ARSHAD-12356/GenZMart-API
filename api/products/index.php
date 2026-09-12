<?php
/**
 * GenZMart API - Products Listing Endpoint
 * GET /api/products/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $subcategory = isset($_GET['subcategory']) ? trim($_GET['subcategory']) : '';
    $brand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
    $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
    $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
    $sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
    $featured = isset($_GET['featured']) ? (int)$_GET['featured'] : null;
    $newArrival = isset($_GET['new_arrival']) ? (int)$_GET['new_arrival'] : null;
    $bestSeller = isset($_GET['best_seller']) ? (int)$_GET['best_seller'] : null;
    $onSale = isset($_GET['on_sale']) ? (int)$_GET['on_sale'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $where = ["p.status = 'active'"];
    $params = [];

    if ($q !== '') {
        $where[] = "(p.name LIKE :q OR p.description LIKE :q OR p.short_description LIKE :q OR b.name LIKE :q)";
        $params['q'] = '%' . $q . '%';
    }

    if ($category !== '') {
        $where[] = "(c.slug = :cat OR c.id = :cat_id)";
        $params['cat'] = $category;
        $params['cat_id'] = is_numeric($category) ? (int)$category : 0;
    }

    if ($subcategory !== '') {
        $where[] = "(sc.slug = :subcat OR sc.id = :subcat_id)";
        $params['subcat'] = $subcategory;
        $params['subcat_id'] = is_numeric($subcategory) ? (int)$subcategory : 0;
    }

    if ($brand !== '') {
        $where[] = "(b.slug = :brand OR b.id = :brand_id)";
        $params['brand'] = $brand;
        $params['brand_id'] = is_numeric($brand) ? (int)$brand : 0;
    }

    if ($minPrice !== null && $minPrice > 0) {
        $where[] = "COALESCE(p.sale_price, p.price) >= :min_price";
        $params['min_price'] = $minPrice;
    }

    if ($maxPrice !== null && $maxPrice > 0) {
        $where[] = "COALESCE(p.sale_price, p.price) <= :max_price";
        $params['max_price'] = $maxPrice;
    }

    if ($featured !== null) {
        $where[] = "p.is_featured = :featured";
        $params['featured'] = $featured;
    }

    if ($newArrival !== null) {
        $where[] = "p.is_new_arrival = :new_arrival";
        $params['new_arrival'] = $newArrival;
    }

    if ($bestSeller !== null) {
        $where[] = "p.is_best_seller = :best_seller";
        $params['best_seller'] = $bestSeller;
    }

    if ($onSale !== null && $onSale === 1) {
        $where[] = "p.sale_price IS NOT NULL AND p.sale_price < p.price";
    }

    $whereSql = implode(' AND ', $where);

    // Count total matching
    $countSql = "
        SELECT COUNT(DISTINCT p.id) 
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE {$whereSql}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Order By
    $orderBy = "p.created_at DESC";
    switch ($sort) {
        case 'price_asc':
            $orderBy = "COALESCE(p.sale_price, p.price) ASC";
            break;
        case 'price_desc':
            $orderBy = "COALESCE(p.sale_price, p.price) DESC";
            break;
        case 'rating':
            $orderBy = "p.rating_avg DESC";
            break;
        case 'popular':
        case 'best_seller':
            $orderBy = "p.is_best_seller DESC, p.rating_count DESC";
            break;
        case 'newest':
        default:
            $orderBy = "p.created_at DESC";
            break;
    }

    $sql = "
        SELECT 
            p.id,
            p.name,
            p.slug,
            p.short_description,
            p.price,
            p.sale_price,
            p.sku,
            p.stock_quantity,
            p.is_featured,
            p.is_new_arrival,
            p.is_best_seller,
            p.rating_avg,
            p.rating_count,
            p.created_at,
            c.id AS category_id,
            c.name AS category_name,
            c.slug AS category_slug,
            sc.id AS subcategory_id,
            sc.name AS subcategory_name,
            sc.slug AS subcategory_slug,
            b.id AS brand_id,
            b.name AS brand_name,
            b.slug AS brand_slug,
            (
                SELECT image_path 
                FROM product_images 
                WHERE product_id = p.id 
                ORDER BY is_primary DESC, sort_order ASC, id ASC 
                LIMIT 1
            ) AS primary_image
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format types
    $products = array_map(function($p) {
        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'slug' => $p['slug'],
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
            'image' => $p['primary_image'] ?: 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp'
        ];
    }, $products);

    ResponseHelper::success('Products retrieved successfully', [
        'products' => $products,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);

} catch (Throwable $e) {
    ResponseHelper::error('Failed to fetch products: ' . $e->getMessage(), 500);
}
