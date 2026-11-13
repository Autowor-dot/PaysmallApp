<?php
/**
 * GET api/products.php            -> active marketplace products (public)
 *     ?q=search&category=Name     -> filtered
 *     ?id=12                      -> one product
 */

require_once __DIR__ . '/config.php';

require_method('GET');

function public_product(array $r): array
{
    $price = (float) ($r['price'] ?? 0);
    $count = max(1, (int) ($r['installments_count'] ?? 1));
    $installment = (float) ($r['installment_amount'] ?? 0);
    if ($installment <= 0 && $price > 0) {
        $installment = round($price / $count, 0);
    }
    return [
        'id'                    => (int) $r['id'],
        'slug'                  => $r['slug'] ?? null,
        'name'                  => $r['name'] ?? '',
        'description'           => $r['description'] ?? '',
        'category'              => $r['category'] ?? '',
        'price'                 => $price,
        'installment_amount'    => $installment,
        'installment_frequency' => $r['installment_frequency'] ?? 'weekly',
        'installments_count'    => $count,
        'image_url'             => $r['image_url'] ?? null,
        'icon'                  => $r['icon'] ?? 'fa-solid fa-box',
        'seller_id'             => isset($r['seller_id']) ? (int) $r['seller_id'] : null,
        'seller_name'           => $r['seller_name'] ?? ($r['business_name'] ?? 'Verified Seller'),
        'seller_location'       => $r['location'] ?? null,
        'seller_email'          => $r['seller_email'] ?? null,
        'seller_phone'          => $r['seller_phone'] ?? null,
        'stock'                 => isset($r['stock']) ? (int) $r['stock'] : null,
    ];
}

try {
    if (!table_exists('products')) {
        json_response(200, ['success' => true, 'data' => ['products' => [], 'categories' => []], 'message' => 'Products table not created yet.']);
    }

    $hasSellers = table_exists('sellers');
    $select = 'SELECT p.*' . ($hasSellers ? ', s.business_name, s.location, s.email AS seller_email, s.phone AS seller_phone' : '') . ' FROM products p'
        . ($hasSellers ? ' LEFT JOIN sellers s ON s.id = p.seller_id' : '');

    $id = isset($_GET['id']) && is_numeric((string) $_GET['id']) ? (int) $_GET['id'] : null;
    if ($id !== null) {
        $rows = db_select($select . ' WHERE p.id = ? LIMIT 1', 'i', [$id]);
        if (empty($rows)) {
            json_error(404, 'Product not found.');
        }
        json_response(200, ['success' => true, 'data' => ['product' => public_product($rows[0])]]);
    }

    $q        = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));

    $where  = ["p.status = 'active'"];
    $types  = '';
    $params = [];
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ?' . ($hasSellers ? ' OR s.business_name LIKE ?' : '') . ')';
        $like = '%' . $q . '%';
        $types .= $hasSellers ? 'ssss' : 'sss';
        $params = array_merge($params, $hasSellers ? [$like, $like, $like, $like] : [$like, $like, $like]);
    }
    if ($category !== '') {
        $where[] = 'p.category = ?';
        $types  .= 's';
        $params[] = $category;
    }

    $rows = db_select($select . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.featured DESC, p.created_at DESC LIMIT 100', $types, $params);
    $cats = db_select("SELECT DISTINCT category FROM products WHERE status = 'active' AND category <> '' ORDER BY category");

    json_response(200, [
        'success' => true,
        'data'    => [
            'products'   => array_map('public_product', $rows),
            'categories' => array_values(array_map(static fn(array $c) => $c['category'], $cats)),
        ],
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'products');
}
