<?php
/**
 * GenZMart API - Brands Listing Endpoint
 * GET /api/brands/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare("
        SELECT b.*, COUNT(DISTINCT p.id) AS product_count
        FROM brands b
        LEFT JOIN products p ON b.id = p.brand_id AND p.status = 'active'
        WHERE b.status = 1
        GROUP BY b.id
        ORDER BY b.is_featured DESC, b.name ASC
    ");
    $stmt->execute();
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($b) {
        return [
            'id' => (int)$b['id'],
            'name' => $b['name'],
            'slug' => $b['slug'],
            'logo' => $b['logo'],
            'description' => $b['description'],
            'is_featured' => (bool)$b['is_featured'],
            'product_count' => (int)$b['product_count']
        ];
    }, $brands);

    ResponseHelper::success('Brands retrieved successfully', ['brands' => $formatted]);

} catch (Throwable $e) {
    ResponseHelper::error('Failed to fetch brands: ' . $e->getMessage(), 500);
}
