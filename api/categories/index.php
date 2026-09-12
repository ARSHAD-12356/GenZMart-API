<?php
/**
 * GenZMart API - Categories Listing Endpoint
 * GET /api/categories/index.php
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
        SELECT c.*, 
            COUNT(DISTINCT p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
        WHERE c.status = 1
        GROUP BY c.id
        ORDER BY c.is_featured DESC, c.name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $subStmt = $pdo->prepare("SELECT id, category_id, name, slug FROM subcategories WHERE status = 1 ORDER BY name ASC");
    $subStmt->execute();
    $subcategories = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    $subMap = [];
    foreach ($subcategories as $sub) {
        $subMap[$sub['category_id']][] = [
            'id' => (int)$sub['id'],
            'name' => $sub['name'],
            'slug' => $sub['slug']
        ];
    }

    $formatted = array_map(function($cat) use ($subMap) {
        return [
            'id' => (int)$cat['id'],
            'name' => $cat['name'],
            'slug' => $cat['slug'],
            'description' => $cat['description'],
            'image' => $cat['image'],
            'is_featured' => (bool)$cat['is_featured'],
            'product_count' => (int)$cat['product_count'],
            'subcategories' => $subMap[$cat['id']] ?? []
        ];
    }, $categories);

    ResponseHelper::success('Categories retrieved successfully', ['categories' => $formatted]);

} catch (Throwable $e) {
    ResponseHelper::error('Failed to fetch categories: ' . $e->getMessage(), 500);
}
