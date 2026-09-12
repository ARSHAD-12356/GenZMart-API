<?php
require_once __DIR__ . '/Database.php';

try {
    $pdo = Database::getConnection();
    echo "Starting Database Seeding...\n";

    // 1. Ensure Roles
    $pdo->exec("INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
        (1, 'Admin', 'System Administrator'),
        (2, 'Seller', 'Vendor/Seller Account'),
        (3, 'Customer', 'Shopper Account')
    ");

    // 2. Users (Admin, Seller, Customer)
    $passwordHash = password_hash('password123', PASSWORD_BCRYPT);
    
    // Check/Insert Admin
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@genzmart.com'");
    $stmt->execute();
    $adminId = $stmt->fetchColumn();
    if (!$adminId) {
        $stmt = $pdo->prepare("INSERT INTO users (role_id, first_name, last_name, email, phone, password, status) VALUES (1, 'System', 'Admin', 'admin@genzmart.com', '9999999999', ?, 'active')");
        $stmt->execute([$passwordHash]);
        $adminId = $pdo->lastInsertId();
    }

    // Check/Insert Seller User
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'seller@sonicwave.com'");
    $stmt->execute();
    $sellerUserId = $stmt->fetchColumn();
    if (!$sellerUserId) {
        $stmt = $pdo->prepare("INSERT INTO users (role_id, first_name, last_name, email, phone, password, status) VALUES (2, 'Sonicwave', 'Official', 'seller@sonicwave.com', '8888888888', ?, 'active')");
        $stmt->execute([$passwordHash]);
        $sellerUserId = $pdo->lastInsertId();
    }

    // Seller Profile
    $stmt = $pdo->prepare("SELECT id FROM seller_profiles WHERE user_id = ?");
    $stmt->execute([$sellerUserId]);
    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("INSERT INTO seller_profiles (user_id, store_name, store_slug, description, status, rating_avg) VALUES (?, 'Sonicwave Store', 'sonicwave', 'Premium audio & wearable gadgets', 'approved', 4.8)");
        $stmt->execute([$sellerUserId]);
    }

    // Check/Insert Demo Customer
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'customer@genzmart.com'");
    $stmt->execute();
    $customerId = $stmt->fetchColumn();
    if (!$customerId) {
        $stmt = $pdo->prepare("INSERT INTO users (role_id, first_name, last_name, email, phone, password, status) VALUES (3, 'Aria', 'Kapoor', 'customer@genzmart.com', '7777777777', ?, 'active')");
        $stmt->execute([$passwordHash]);
        $customerId = $pdo->lastInsertId();
    }

    // 3. Categories & Subcategories
    $categories = [
        [
            'name' => 'Audio', 'slug' => 'audio', 'desc' => 'Headphones, earbuds and speakers tuned for the feed generation.',
            'image' => 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp', 'is_featured' => 1,
            'subs' => ['Headphones', 'Earbuds', 'Speakers', 'Microphones', 'Audio Accessories']
        ],
        [
            'name' => 'Wearables', 'slug' => 'wearables', 'desc' => 'Smartwatches and trackers that keep up with your day.',
            'image' => 'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-watch-series-4-gold/1.webp', 'is_featured' => 1,
            'subs' => ['Smartwatches', 'Fitness Bands', 'Smart Rings', 'Wearable Accessories']
        ],
        [
            'name' => 'Gaming', 'slug' => 'gaming', 'desc' => 'Controllers, keyboards and gear built for the grind.',
            'image' => 'https://cdn.dummyjson.com/product-images/laptops/apple-macbook-pro-14-inch-space-grey/1.webp', 'is_featured' => 1,
            'subs' => ['Gaming Headsets', 'Controllers', 'Gaming Keyboards', 'Gaming Mice']
        ],
        [
            'name' => 'Mobile', 'slug' => 'mobile', 'desc' => 'Phones and accessories that flex.',
            'image' => 'https://cdn.dummyjson.com/product-images/smartphones/iphone-13-pro/1.webp', 'is_featured' => 1,
            'subs' => ['Smartphones', 'Tablets', 'Power Banks', 'Chargers', 'Phone Cases']
        ],
        [
            'name' => 'Computing', 'slug' => 'computing', 'desc' => 'Laptops, displays and workstation essentials.',
            'image' => 'https://cdn.dummyjson.com/product-images/laptops/apple-macbook-pro-14-inch-space-grey/1.webp', 'is_featured' => 1,
            'subs' => ['Laptops', 'Monitors', 'Keyboards', 'Mice', 'Webcams', 'Storage']
        ],
        [
            'name' => 'Lifestyle', 'slug' => 'lifestyle', 'desc' => 'Everyday carry and desk setups that hit different.',
            'image' => 'https://cdn.dummyjson.com/product-images/sunglasses/classic-sunglasses/1.webp', 'is_featured' => 0,
            'subs' => ['Desk Setup', 'Smart Accessories', 'Travel Tech']
        ]
    ];

    $catMap = [];
    $subMap = [];

    foreach ($categories as $cat) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = ?");
        $stmt->execute([$cat['slug']]);
        $cId = $stmt->fetchColumn();
        if (!$cId) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, image, is_featured, status) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$cat['name'], $cat['slug'], $cat['desc'], $cat['image'], $cat['is_featured']]);
            $cId = $pdo->lastInsertId();
        }
        $catMap[$cat['slug']] = $cId;

        foreach ($cat['subs'] as $subName) {
            $subSlug = strtolower(str_replace(' ', '-', $cat['slug'] . '-' . $subName));
            $stmt = $pdo->prepare("SELECT id FROM subcategories WHERE slug = ?");
            $stmt->execute([$subSlug]);
            $sId = $stmt->fetchColumn();
            if (!$sId) {
                $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, slug, status) VALUES (?, ?, ?, 1)");
                $stmt->execute([$cId, $subName, $subSlug]);
                $sId = $pdo->lastInsertId();
            }
            $subMap[$cat['slug'] . ':' . $subName] = $sId;
        }
    }

    // 4. Brands
    $brands = [
        ['name' => 'Sonicwave', 'slug' => 'sonicwave', 'is_featured' => 1],
        ['name' => 'Pulse', 'slug' => 'pulse', 'is_featured' => 1],
        ['name' => 'Nova', 'slug' => 'nova', 'is_featured' => 1],
        ['name' => 'Vertex', 'slug' => 'vertex', 'is_featured' => 1],
        ['name' => 'Kite', 'slug' => 'kite', 'is_featured' => 0],
    ];
    $brandMap = [];
    foreach ($brands as $b) {
        $stmt = $pdo->prepare("SELECT id FROM brands WHERE slug = ?");
        $stmt->execute([$b['slug']]);
        $bId = $stmt->fetchColumn();
        if (!$bId) {
            $stmt = $pdo->prepare("INSERT INTO brands (name, slug, is_featured, status) VALUES (?, ?, ?, 1)");
            $stmt->execute([$b['name'], $b['slug'], $b['is_featured']]);
            $bId = $pdo->lastInsertId();
        }
        $brandMap[$b['slug']] = $bId;
    }

    // 5. Products
    $products = [
        [
            'name' => 'Aurora Wireless Headphones',
            'slug' => 'aurora-wireless-headphones',
            'cat' => 'audio', 'sub' => 'Headphones', 'brand' => 'sonicwave',
            'price' => 229.00, 'sale_price' => 149.00, 'sku' => 'SKU-AURORA-001',
            'stock' => 42, 'is_featured' => 1, 'is_new' => 1, 'is_best' => 1,
            'short' => 'Adaptive noise cancelling over-ear headphones with 38h battery life.',
            'desc' => 'Experience high-fidelity sound with deep bass and ambient sound transparency.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods-max-silver/1.webp',
                'https://cdn.dummyjson.com/product-images/mobile-accessories/beats-flex-wireless-earphones/1.webp'
            ],
            'rating_avg' => 4.80, 'rating_count' => 124
        ],
        [
            'name' => 'Echo Buds Pro',
            'slug' => 'echo-buds-pro',
            'cat' => 'audio', 'sub' => 'Earbuds', 'brand' => 'pulse',
            'price' => 129.00, 'sale_price' => 89.00, 'sku' => 'SKU-ECHO-002',
            'stock' => 120, 'is_featured' => 1, 'is_new' => 0, 'is_best' => 1,
            'short' => 'True wireless earbuds with spatial audio and active noise cancellation.',
            'desc' => 'Crystal clear calls, instant bluetooth pairing, IPX4 sweat resistance.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods/1.webp',
                'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-airpods/2.webp'
            ],
            'rating_avg' => 4.60, 'rating_count' => 87
        ],
        [
            'name' => 'Pulse Fit Smartwatch 2',
            'slug' => 'pulse-fit-smartwatch-2',
            'cat' => 'wearables', 'sub' => 'Smartwatches', 'brand' => 'pulse',
            'price' => 199.00, 'sale_price' => 159.00, 'sku' => 'SKU-PULSE-FIT2',
            'stock' => 50, 'is_featured' => 1, 'is_new' => 1, 'is_best' => 1,
            'short' => 'Always-on AMOLED display with heart rate and SpO2 tracking.',
            'desc' => 'Track 50+ workout modes, sleep metrics, and receive phone notifications.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/mobile-accessories/apple-watch-series-4-gold/1.webp'
            ],
            'rating_avg' => 4.70, 'rating_count' => 54
        ],
        [
            'name' => 'Vertex Mechanical Gaming Keyboard',
            'slug' => 'vertex-mechanical-gaming-keyboard',
            'cat' => 'gaming', 'sub' => 'Gaming Keyboards', 'brand' => 'vertex',
            'price' => 149.00, 'sale_price' => 119.00, 'sku' => 'SKU-VERTEX-KB1',
            'stock' => 35, 'is_featured' => 1, 'is_new' => 0, 'is_best' => 1,
            'short' => 'RGB backlit mechanical keyboard with hot-swappable tactile switches.',
            'desc' => 'Low latency 2.4GHz wireless and Bluetooth 5.1 connection options.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/laptops/apple-macbook-pro-14-inch-space-grey/1.webp'
            ],
            'rating_avg' => 4.90, 'rating_count' => 210
        ],
        [
            'name' => 'Nova UltraBook Pro 15',
            'slug' => 'nova-ultrabook-pro-15',
            'cat' => 'computing', 'sub' => 'Laptops', 'brand' => 'nova',
            'price' => 1299.00, 'sale_price' => 1099.00, 'sku' => 'SKU-NOVA-LAPTOP15',
            'stock' => 15, 'is_featured' => 1, 'is_new' => 1, 'is_best' => 0,
            'short' => 'Ultra-thin laptop with 16GB RAM, 1TB SSD, and 4K OLED screen.',
            'desc' => 'Powered by latest generation processor for creator and professional workflows.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/laptops/apple-macbook-pro-14-inch-space-grey/1.webp'
            ],
            'rating_avg' => 4.85, 'rating_count' => 42
        ],
        [
            'name' => 'Kite MagSafe 10000mAh Power Bank',
            'slug' => 'kite-magsafe-power-bank',
            'cat' => 'mobile', 'sub' => 'Power Banks', 'brand' => 'kite',
            'price' => 49.00, 'sale_price' => 39.00, 'sku' => 'SKU-KITE-PB10',
            'stock' => 80, 'is_featured' => 0, 'is_new' => 1, 'is_best' => 0,
            'short' => 'Fast magnetic wireless charging power bank with pass-through support.',
            'desc' => 'Compact power bank fitting comfortably in your hand or pocket.',
            'images' => [
                'https://cdn.dummyjson.com/product-images/smartphones/iphone-13-pro/1.webp'
            ],
            'rating_avg' => 4.50, 'rating_count' => 30
        ]
    ];

    foreach ($products as $p) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE slug = ?");
        $stmt->execute([$p['slug']]);
        $pId = $stmt->fetchColumn();
        if (!$pId) {
            $catId = $catMap[$p['cat']] ?? null;
            $subId = $subMap[$p['cat'] . ':' . $p['sub']] ?? null;
            $brandId = $brandMap[$p['brand']] ?? null;

            $stmt = $pdo->prepare("INSERT INTO products (seller_id, brand_id, category_id, subcategory_id, name, slug, description, short_description, price, sale_price, sku, stock_quantity, is_featured, is_new_arrival, is_best_seller, status, rating_avg, rating_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
            $stmt->execute([
                $sellerUserId, $brandId, $catId, $subId,
                $p['name'], $p['slug'], $p['desc'], $p['short'],
                $p['price'], $p['sale_price'], $p['sku'],
                $p['stock'], $p['is_featured'], $p['is_new'], $p['is_best'],
                $p['rating_avg'], $p['rating_count']
            ]);
            $pId = $pdo->lastInsertId();

            // Images
            foreach ($p['images'] as $idx => $imgUrl) {
                $isPrimary = ($idx === 0) ? 1 : 0;
                $stmtImg = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_primary, sort_order) VALUES (?, ?, ?, ?)");
                $stmtImg->execute([$pId, $imgUrl, $isPrimary, $idx]);
            }

            // Default variant
            $stmtVar = $pdo->prepare("INSERT INTO product_variants (product_id, sku, variant_name, price, sale_price, stock_quantity) VALUES (?, ?, 'Default', ?, ?, ?)");
            $stmtVar->execute([$pId, $p['sku'] . '-VAR1', $p['price'], $p['sale_price'], $p['stock']]);
        }
    }

    // 6. Coupons
    $coupons = [
        ['code' => 'GENZ20', 'desc' => '20% Off on orders above $50', 'type' => 'percentage', 'val' => 20.00, 'min' => 50.00, 'max' => 50.00],
        ['code' => 'WELCOME10', 'desc' => '$10 Flat discount for new shoppers', 'type' => 'fixed', 'val' => 10.00, 'min' => 30.00, 'max' => 10.00],
        ['code' => 'FESTIVE500', 'desc' => '50% Off Mega Deal', 'type' => 'percentage', 'val' => 50.00, 'min' => 100.00, 'max' => 150.00],
    ];

    foreach ($coupons as $cp) {
        $stmt = $pdo->prepare("SELECT id FROM coupons WHERE code = ?");
        $stmt->execute([$cp['code']]);
        if (!$stmt->fetchColumn()) {
            $stmt = $pdo->prepare("INSERT INTO coupons (code, description, discount_type, discount_value, min_order_amount, max_discount_amount, status) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$cp['code'], $cp['desc'], $cp['type'], $cp['val'], $cp['min'], $cp['max']]);
        }
    }

    echo "Database Seeding Complete Successfully!\n";

} catch (Exception $e) {
    echo "Seeding Failed: " . $e->getMessage() . "\n";
}
