<?php
/**
 * POST api/register.php
 * Fields: full_name, phone, email, password, confirm_password
 * Creates a customer account. Phone numbers are stored normalised (2557XXXXXXXX).
 */

require_once __DIR__ . '/config.php';

require_method('POST');

try {
    if (!site_flag('allow_user_registration', true)) {
        json_error(403, 'New account registration is temporarily closed. Please try again later.');
    }

    $input            = request_input();
    $fullName         = input_string($input, 'full_name', 120);
    $phone            = normalize_phone(input_string($input, 'phone', 30));
    $email            = strtolower(input_string($input, 'email', 190));
    $password         = (string) ($input['password'] ?? '');
    $confirmPassword  = (string) ($input['confirm_password'] ?? '');

    if ($fullName === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '') {
        json_error(400, 'Please fill in all required fields.');
    }

    if (mb_strlen($fullName) < 2) {
        json_error(400, 'Please enter your full name.');
    }

    if (!is_valid_tz_phone($phone)) {
        json_error(400, 'Please enter a valid Tanzanian phone number (e.g. 0712 345 678).');
    }

    if (!is_valid_email($email)) {
        json_error(400, 'Please enter a valid email address.');
    }

    if (strlen($password) < 8) {
        json_error(400, 'Password must contain at least 8 characters.');
    }

    if ($password !== $confirmPassword) {
        json_error(400, 'Passwords do not match.');
    }

    $existing = db_select('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1', 'ss', [$email, $phone]);
    if (!empty($existing)) {
        json_error(409, 'This email address or phone number is already registered.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    db_execute(
        'INSERT INTO users (full_name, phone, email, password_hash) VALUES (?, ?, ?, ?)',
        'ssss',
        [$fullName, $phone, $email, $hash]
    );

    $userId = (int) db()->insert_id;
    link_seller_stores($userId, $email, $phone);

    if (site_flag('notify_email_registration', true)) {
        send_mail(
            $email,
            cfg('app_name') . ': welcome, your account is ready',
            sprintf("Hello %s,\n\nYour %s account has been created successfully.\n\nPhone: %s\nEmail: %s\n\nYou can now log in, browse verified sellers and set up pay-small installment plans:\n%s/login.html\n\nIf you did not create this account, please contact support.\n\n— %s",
                $fullName, cfg('app_name'), $phone, $email, app_base_url(), cfg('app_name')),
            'registration_welcome', 'user', (string) $userId
        );
    }

    json_response(201, [
        'success' => true,
        'message' => 'Your LipaLink account has been created successfully!',
        'user_id' => $userId,
    ]);
} catch (mysqli_sql_exception $e) {
    // Duplicate key race (two requests at once) still returns a friendly message
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'This email address or phone number is already registered.');
    }
    server_error($e, 'registration');
}
