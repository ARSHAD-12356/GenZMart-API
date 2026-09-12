<?php
/**
 * GenZMart API - Coupon Validation Endpoint
 * POST /api/coupons/validate.php
 */

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';
require_once __DIR__ . '/../../helpers/AuthHelper.php';

ResponseHelper::sendCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('Method Not Allowed', 405);
}

try {
    $pdo = Database::getConnection();
    $data = AuthHelper::getRequestData();

    $code = isset($data['code']) ? trim($data['code']) : '';
    $subtotal = isset($data['subtotal']) ? (float)$data['subtotal'] : 0.0;

    if (empty($code)) {
        ResponseHelper::error('Coupon code is required', 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 1 LIMIT 1");
    $stmt->execute([$code]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        ResponseHelper::error('Invalid or expired coupon code', 404);
    }

    $minOrder = (float)$c['min_order_amount'];
    if ($subtotal < $minOrder) {
        ResponseHelper::error("Minimum order of \${$minOrder} required for coupon {$code}", 400);
    }

    $discountType = $c['discount_type'];
    $discountVal = (float)$c['discount_value'];
    $maxDiscount = $c['max_discount_amount'] !== null ? (float)$c['max_discount_amount'] : null;

    $discount = 0.0;
    if ($discountType === 'percentage') {
        $discount = ($subtotal * $discountVal) / 100.0;
        if ($maxDiscount !== null && $discount > $maxDiscount) {
            $discount = $maxDiscount;
        }
    } else {
        $discount = $discountVal;
    }

    $discount = min($discount, $subtotal);
    $finalTotal = max(0, $subtotal - $discount);

    ResponseHelper::success('Coupon code applied successfully', [
        'coupon_id' => (int)$c['id'],
        'code' => $c['code'],
        'discount_type' => $c['discount_type'],
        'discount_value' => $discountVal,
        'discount_amount' => round($discount, 2),
        'subtotal' => $subtotal,
        'total' => round($finalTotal, 2)
    ]);

} catch (Throwable $e) {
    ResponseHelper::error('Coupon validation failed: ' . $e->getMessage(), 500);
}
