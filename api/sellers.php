<?php
/**
 * GET api/sellers.php?q=<search>
 * Searches registered sellers by business name, owner name, phone or email.
 * Used by agreement.html (Step 1: registered seller lookup).
 */

require_once __DIR__ . '/config.php';

require_method('GET');

require_auth();

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($query) < 2) {
        json_response(200, ['success' => true, 'sellers' => []]);
    }

    if (!table_exists('sellers')) {
        // Registry not created yet (see api/schema.sql)
        json_response(200, ['success' => true, 'sellers' => []]);
    }

    $like  = '%' . $query . '%';
    $phone = normalize_phone($query);
    $phoneLike = $phone !== '' ? '%' . $phone . '%' : $like;

    $rows = db_select(
        "SELECT id, business_name, full_name, phone, email, location, category, status
         FROM sellers
         WHERE status <> 'suspended'
           AND (business_name LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ?)
         ORDER BY (status = 'verified') DESC, business_name ASC
         LIMIT 10",
        'ssss',
        [$like, $like, $like, $phoneLike]
    );

    $sellers = array_map(static function (array $r): array {
        return [
            'id'            => (int) $r['id'],
            'business_name' => $r['business_name'],
            'full_name'     => $r['full_name'],
            'phone'         => $r['phone'],
            'email'         => $r['email'],
            'location'      => $r['location'],
            'category'      => $r['category'],
            'verified'      => ($r['status'] ?? '') === 'verified',
        ];
    }, $rows);

    json_response(200, ['success' => true, 'sellers' => $sellers]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'seller search');
}
