<?php
/**
 * POST api/register-store.php
 * Fields: business_name, full_name, phone, email, location?, category?, payout_method?
 * Registers a seller / merchant store (status: pending verification).
 */

require_once __DIR__ . '/config.php';

require_method('POST');

try {
    if (!table_exists('sellers')) {
        json_error(503, 'Store registration is not set up yet. Please run api/schema.sql on the database.');
    }

    if (!site_flag('allow_seller_registration', true)) {
        json_error(403, 'New store registration is temporarily closed. Please try again later.');
    }

    $input        = request_input();
    $businessName = input_string($input, 'business_name', 160);
    $fullName     = input_string($input, 'full_name', 120);
    $phone        = normalize_phone(input_string($input, 'phone', 30));
    $email        = strtolower(input_string($input, 'email', 190));
    $location     = input_string($input, 'location', 160);
    $category     = input_string($input, 'category', 80);
    $payout       = input_string($input, 'payout_method', 30);
    $payout       = in_array($payout, ['mobile_money', 'bank'], true) ? $payout : 'mobile_money';

    if (mb_strlen($businessName) < 2) {
        json_error(400, 'Please enter your business or store name.');
    }
    if (mb_strlen($fullName) < 2) {
        json_error(400, 'Please enter the owner\'s full name.');
    }
    if (!is_valid_tz_phone($phone)) {
        json_error(400, 'Please enter a valid Tanzanian phone number.');
    }
    if (!is_valid_email($email)) {
        json_error(400, 'Please enter a valid business email address.');
    }

    $existing = db_select('SELECT id FROM sellers WHERE email = ? OR phone = ? LIMIT 1', 'ss', [$email, $phone]);
    if (!empty($existing)) {
        json_error(409, 'A store with this email address or phone number is already registered.');
    }

    // Link to the signed-in customer account when there is one
    $userId = current_user_id();

    db_execute(
        'INSERT INTO sellers (user_id, business_name, full_name, phone, email, location, category, payout_method, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'issssssss',
        [$userId, $businessName, $fullName, $phone, $email, $location, $category, $payout, 'pending']
    );

    json_response(201, [
        'success'   => true,
        'message'   => 'Your store has been registered and is pending verification.',
        'seller_id' => (int) db()->insert_id,
    ]);
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'A store with this email address or phone number is already registered.');
    }
    server_error($e, 'store registration');
}
