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

    // Link to the signed-in account when there is one; otherwise create the seller's login now.
    $userId      = current_user_id();
    $createdUser = null;
    if ($userId === null) {
        $password = (string) ($input['password'] ?? '');
        $confirm  = (string) ($input['confirm_password'] ?? '');
        if ($password === '' || $confirm === '') {
            json_error(400, 'Please choose a password for your seller login (and confirm it).');
        }
        if (strlen($password) < 8) {
            json_error(400, 'Your password must be at least 8 characters long.');
        }
        if ($password !== $confirm) {
            json_error(400, 'The passwords do not match.');
        }
        if (!site_flag('allow_user_registration', true)) {
            json_error(403, 'New registrations are temporarily closed. Please try again later.');
        }
        $clash = db_select('SELECT id, email, phone FROM users WHERE email = ? OR phone = ? LIMIT 1', 'ss', [$email, $phone]);
        if (!empty($clash)) {
            $which = strtolower((string) $clash[0]['email']) === $email ? 'email address' : 'phone number';
            json_error(409, 'A LipaLink account already uses this ' . $which . '. Log in with that account first, then register the store from your Seller Hub.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_execute('INSERT INTO users (full_name, phone, email, password_hash) VALUES (?, ?, ?, ?)', 'ssss', [$fullName, $phone, $email, $hash]);
        $userId      = (int) db()->insert_id;
        $createdUser = db_select('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$userId])[0] ?? null;
    }

    db_execute(
        'INSERT INTO sellers (user_id, business_name, full_name, phone, email, location, category, payout_method, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'issssssss',
        [$userId, $businessName, $fullName, $phone, $email, $location, $category, $payout, 'pending']
    );

    $sellerId = (int) db()->insert_id;
    if (site_flag('notify_email_registration', true)) {
        send_mail(
            $email,
            cfg('app_name') . ': store "' . $businessName . '" registered',
            sprintf("Hello %s,\n\nYour store \"%s\" has been registered on %s and is pending verification by our team.\n\nLog in with this email (or your phone number) and your password, choose Seller, and open your Seller Hub to add products and set your installment defaults:\n%s/seller.html\n\nYou will be notified once verification is complete.\n\n— %s",
                $fullName, $businessName, cfg('app_name'), app_base_url(), cfg('app_name')),
            'store_registered', 'seller', (string) $sellerId
        );
    }

    json_response(201, [
    if ($createdUser !== null) {
        sign_in_user($userId);
        if (column_exists('users', 'last_login_at')) {
            db_execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', 'i', [$userId]);
        }
        json_response(201, [
            'success'   => true,
            'message'   => 'Your store has been registered and your seller login is ready. Welcome to your Seller Hub!',
            'seller_id' => $sellerId,
            'signed_in' => true,
            'user'      => public_user($createdUser),
        ]);
    }

        'success'   => true,
        'message'   => 'Your store has been registered and is pending verification.',
        'seller_id' => $sellerId,
    ]);
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'A store with this email address or phone number is already registered.');
    }
    server_error($e, 'store registration');
}
