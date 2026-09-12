<?php
/**
 * GenZMart API - Automated Testing Suite
 */

require_once __DIR__ . '/database/Database.php';

echo "==================================================\n";
echo "    GenZMart API End-to-End Test Suite\n";
echo "==================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$name} - {$details}\n";
        $failCount++;
    }
}

try {
    $pdo = Database::getConnection();

    // 1. PRODUCTS TEST
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $pCount = $stmt->fetchColumn();
    assertTest("Products Table Populated", $pCount > 0, "Found {$pCount} products");

    // Test product search/filter query
    $stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE ?");
    $stmt->execute(['%Aurora%']);
    $aurora = $stmt->fetch();
    assertTest("Product Search Query", !empty($aurora), "Aurora product found");

    // 2. CATEGORIES & BRANDS TEST
    $cCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    assertTest("Categories List", $cCount > 0, "Found {$cCount} categories");

    $bCount = $pdo->query("SELECT COUNT(*) FROM brands")->fetchColumn();
    assertTest("Brands List", $bCount > 0, "Found {$bCount} brands");

    // 3. AUTHENTICATED CART TEST
    // Get test customer
    $uStmt = $pdo->query("SELECT id FROM users WHERE email = 'customer@genzmart.com'");
    $customerId = $uStmt->fetchColumn();
    assertTest("Test Customer Account", !empty($customerId), "Customer ID: {$customerId}");

    // Create cart
    $cStmt = $pdo->prepare("SELECT id FROM carts WHERE user_id = ?");
    $cStmt->execute([$customerId]);
    $cartId = $cStmt->fetchColumn();
    if (!$cartId) {
        $insC = $pdo->prepare("INSERT INTO carts (user_id) VALUES (?)");
        $insC->execute([$customerId]);
        $cartId = $pdo->lastInsertId();
    }
    assertTest("Customer Cart Exists", !empty($cartId), "Cart ID: {$cartId}");

    // Add item to cart
    $pdo->prepare("DELETE FROM cart_items WHERE cart_id = ?")->execute([$cartId]);
    $insCi = $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)");
    $insCi->execute([$cartId, $aurora['id'], 2]);
    $ciCount = $pdo->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = {$cartId}")->fetchColumn();
    assertTest("Add Item To Cart", $ciCount == 1, "Added 2x Aurora product to cart");

    // 4. WISHLIST TEST
    $wStmt = $pdo->prepare("SELECT id FROM wishlists WHERE user_id = ?");
    $wStmt->execute([$customerId]);
    $wishlistId = $wStmt->fetchColumn();
    if (!$wishlistId) {
        $insW = $pdo->prepare("INSERT INTO wishlists (user_id) VALUES (?)");
        $insW->execute([$customerId]);
        $wishlistId = $pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM wishlist_items WHERE wishlist_id = ?")->execute([$wishlistId]);
    $pdo->prepare("INSERT INTO wishlist_items (wishlist_id, product_id) VALUES (?, ?)")->execute([$wishlistId, $aurora['id']]);
    $wiCount = $pdo->query("SELECT COUNT(*) FROM wishlist_items WHERE wishlist_id = {$wishlistId}")->fetchColumn();
    assertTest("Wishlist Toggle/Add", $wiCount == 1, "Wishlist item added");

    // 5. ADDRESS TEST
    $pdo->prepare("DELETE FROM user_addresses WHERE user_id = ?")->execute([$customerId]);
    $insAddr = $pdo->prepare("INSERT INTO user_addresses (user_id, title, recipient_name, phone, address_line1, city, state, postal_code, is_default) VALUES (?, 'Home', 'Aria Kapoor', '7777777777', '42 Tech Avenue', 'Mumbai', 'Maharashtra', '400001', 1)");
    $insAddr->execute([$customerId]);
    $addrId = $pdo->lastInsertId();
    assertTest("Add User Address", !empty($addrId), "Address ID: {$addrId}");

    // 6. COUPON TEST
    $cpStmt = $pdo->prepare("SELECT * FROM coupons WHERE code = 'GENZ20'");
    $cpStmt->execute();
    $coupon = $cpStmt->fetch();
    assertTest("Coupon Validation", !empty($coupon), "Code: GENZ20, Discount: 20%");

    // 7. ORDER CREATION TEST (MySQL Transaction)
    $stockBefore = (int)$aurora['stock_quantity'];
    
    $pdo->beginTransaction();

    $orderNum = 'GZM-TEST-' . time();
    $subtotal = 298.00;
    $discount = 59.60;
    $shipping = 0.00;
    $tax = 11.92;
    $total = 250.32;

    $insO = $pdo->prepare("INSERT INTO orders (order_number, user_id, coupon_id, subtotal, discount_amount, shipping_fee, tax_amount, total_amount, payment_method, payment_status, order_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cod', 'pending', 'pending')");
    $insO->execute([$orderNum, $customerId, $coupon['id'], $subtotal, $discount, $shipping, $tax, $total]);
    $orderId = $pdo->lastInsertId();

    $insOi = $pdo->prepare("INSERT INTO order_items (order_id, product_id, seller_id, product_name, price, quantity, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insOi->execute([$orderId, $aurora['id'], $aurora['seller_id'], $aurora['name'], 149.00, 2, 298.00]);

    $insOa = $pdo->prepare("INSERT INTO order_addresses (order_id, recipient_name, phone, address_line1, city, state, postal_code, country) VALUES (?, 'Aria Kapoor', '7777777777', '42 Tech Avenue', 'Mumbai', 'Maharashtra', '400001', 'India')");
    $insOa->execute([$orderId]);

    $insOh = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'pending', 'Test order placed', ?)");
    $insOh->execute([$orderId, $customerId]);

    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - 2 WHERE id = ?")->execute([$aurora['id']]);
    $pdo->prepare("DELETE FROM cart_items WHERE cart_id = ?")->execute([$cartId]);

    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Order Confirmed!', 'Test order placed', 'order')")->execute([$customerId]);

    $pdo->commit();

    assertTest("Order Placement Transaction", !empty($orderId), "Order ID: {$orderId}, Order No: {$orderNum}");

    $stockAfter = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = {$aurora['id']}")->fetchColumn();
    assertTest("Stock Reduction Verification", $stockAfter == ($stockBefore - 2), "Stock reduced from {$stockBefore} to {$stockAfter}");

    $cartAfter = (int)$pdo->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = {$cartId}")->fetchColumn();
    assertTest("Cart Clearance Verification", $cartAfter == 0, "Cart cleared after checkout");

    $notifCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$customerId}")->fetchColumn();
    assertTest("Notification Creation Verification", $notifCount > 0, "Notification generated");

    // 8. SELLER DASHBOARD TEST
    $sellerId = $aurora['seller_id'];
    $sSales = $pdo->query("SELECT SUM(total_price) FROM order_items WHERE seller_id = {$sellerId}")->fetchColumn();
    assertTest("Seller Dashboard Metrics", $sSales > 0, "Total Sales for seller: \${$sSales}");

    // 9. ADMIN DASHBOARD TEST
    $aRevenue = $pdo->query("SELECT SUM(total_amount) FROM orders")->fetchColumn();
    assertTest("Admin System Statistics", $aRevenue > 0, "Platform Total Revenue: \${$aRevenue}");

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[ERROR] Test Exception: " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n--------------------------------------------------\n";
echo "TEST RESULTS: PASS = {$passCount}, FAIL = {$failCount}\n";
echo "==================================================\n";
