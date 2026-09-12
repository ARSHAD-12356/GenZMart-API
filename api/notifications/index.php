<?php
/**
 * GenZMart API - Notifications Endpoint
 * GET / PUT /api/notifications/index.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

try {
    $pdo = Database::getConnection();
    $user = AuthHelper::getAuthenticatedUser($pdo);

    if (!$user) {
        ResponseHelper::error('Unauthorized. Please log in.', 401);
    }

    $userId = $user['id'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT id, title, message AS body, type, is_read, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'body' => $r['body'],
                'type' => $r['type'],
                'read' => (bool)$r['is_read'],
                'read_at' => $r['read_at'],
                'date' => $r['created_at']
            ];
        }, $rows);

        ResponseHelper::success('Notifications retrieved successfully', [
            'notifications' => $formatted,
            'unread_count' => count(array_filter($formatted, fn($n) => !$n['read']))
        ]);

    } elseif ($method === 'PUT') {
        $data = AuthHelper::getRequestData();
        $markAll = isset($data['mark_all']) && $data['mark_all'];
        $notifId = isset($data['id']) ? (int)$data['id'] : null;

        if ($markAll) {
            $upd = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
            $upd->execute([$userId]);
            ResponseHelper::success('All notifications marked as read');
        } elseif ($notifId) {
            $upd = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            $upd->execute([$notifId, $userId]);
            ResponseHelper::success('Notification marked as read');
        } else {
            ResponseHelper::error('Provide notification id or mark_all=true', 400);
        }

    } else {
        ResponseHelper::error('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    ResponseHelper::error('Notifications operation failed: ' . $e->getMessage(), 500);
}
