<?php
/**
 * GenZMart API - Product Reviews Endpoint
 * GET / POST /api/reviews/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        if ($productId <= 0) {
            ResponseHelper::error('Product ID parameter is required', 400);
        }

        $stmt = $pdo->prepare("
            SELECT r.id, r.rating, r.title, r.comment, r.created_at, CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.avatar
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.product_id = ? AND r.is_approved = 1
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$productId]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ResponseHelper::success('Reviews retrieved successfully', ['reviews' => $reviews]);

    } elseif ($method === 'POST') {
        $user = AuthHelper::getAuthenticatedUser($pdo);
        if (!$user) {
            ResponseHelper::error('Unauthorized. Please log in to write a review.', 401);
        }

        $data = AuthHelper::getRequestData();
        $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
        $rating = isset($data['rating']) ? (int)$data['rating'] : 0;
        $title = isset($data['title']) ? trim($data['title']) : '';
        $comment = isset($data['comment']) ? trim($data['comment']) : '';

        if ($productId <= 0 || $rating < 1 || $rating > 5) {
            ResponseHelper::error('Valid product_id and rating (1-5) are required', 400);
        }

        // Insert review
        $ins = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, title, comment, is_approved) VALUES (?, ?, ?, ?, ?, 1)");
        $ins->execute([$productId, $user['id'], $rating, $title, $comment]);

        // Recalculate product rating_avg and rating_count
        $calcStmt = $pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_count FROM reviews WHERE product_id = ? AND is_approved = 1");
        $calcStmt->execute([$productId]);
        $stats = $calcStmt->fetch(PDO::FETCH_ASSOC);

        $newAvg = round((float)($stats['avg_rating'] ?? $rating), 2);
        $newCount = (int)($stats['total_count'] ?? 1);

        $updProduct = $pdo->prepare("UPDATE products SET rating_avg = ?, rating_count = ? WHERE id = ?");
        $updProduct->execute([$newAvg, $newCount, $productId]);

        ResponseHelper::success('Review submitted successfully', [
            'rating_avg' => $newAvg,
            'rating_count' => $newCount
        ]);

    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Reviews operation failed: ' . $e->getMessage(), 500);
}
