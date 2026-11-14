<?php
/**
 * POST api/login.php
 * Fields: login (email or phone), password
 * Starts a PHP session on success and returns the public user record.
 */

require_once __DIR__ . '/config.php';

require_method('POST');

try {
    $input    = request_input();
    $login    = input_string($input, 'login', 190);
    $password = (string) ($input['password'] ?? '');

    if ($login === '' || $password === '') {
        json_error(400, 'Please enter your email/phone and password.');
    }

    // Look up by email, by the phone exactly as typed, or by its normalised form
    $normalizedPhone = normalize_phone($login);
    $rows = db_select(
        'SELECT * FROM users WHERE email = ? OR phone = ? OR phone = ? LIMIT 1',
        'sss',
        [$login, $login, $normalizedPhone]
    );

    $user = $rows[0] ?? null;

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        json_error(401, 'Invalid email/phone or password.');
    }

    // Accounts suspended or deactivated by an administrator cannot sign in
    $blocked = account_block_message((int) $user['id']);
    if ($blocked !== null) {
        json_error(403, $blocked);
    }

    if (column_exists('users', 'last_login_at')) {
        db_execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', 'i', [(int) $user['id']]);
    }

    // Upgrade the hash transparently if PHP's default algorithm has changed
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db_execute('UPDATE users SET password_hash = ? WHERE id = ?', 'si', [$newHash, (int) $user['id']]);
    }

    // Link stores registered with this email before the account existed
    if (table_exists('sellers')) {
        link_seller_stores((int) $user['id'], (string) $user['email'], (string) $user['phone']);
    }

    sign_in_user((int) $user['id']);

    json_response(200, [
        'success' => true,
        'message' => 'Login successful!',
        'user'    => public_user($user),
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'login');
}
