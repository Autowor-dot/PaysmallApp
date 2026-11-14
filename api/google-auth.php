<?php
/**
 * POST api/google-auth.php
 * Body: { access_token, phone? }
 *
 * "Continue with Google" for login and registration. The browser obtains an OAuth access token
 * from Google (assets/js/google-auth.js, Google Identity Services token flow); this endpoint
 * verifies it with Google, then signs the matching account in or creates one.
 *
 *  - Existing account with the same (verified) Google email -> signed in.
 *  - No account yet -> a Tanzanian phone number is required (users.phone is mandatory and
 *    unique); the first call answers { needs_phone: true } and the browser asks for it, then
 *    calls again with { access_token, phone } to create the account.
 *
 * Needs 'google_client_id' in api/config.local.php (the OAuth client ID is public, not a secret).
 */

require_once __DIR__ . '/config.php';

require_method('POST');

try {
    $clientId = trim((string) cfg('google_client_id', ''));
    if ($clientId === '') {
        json_error(503, 'Google sign-in is not configured on this site yet. Please use your email or phone number.');
    }
    if (!function_exists('curl_init')) {
        json_error(503, 'Google sign-in is unavailable on this server (cURL missing).');
    }

    $input = request_input();
    $token = trim((string) ($input['access_token'] ?? ''));
    $phone = normalize_phone(input_string($input, 'phone', 30));
    if ($token === '' || strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9._\-]+$/', $token)) {
        json_error(400, 'Missing Google sign-in token.');
    }

    // 1) Token must have been issued to THIS site's OAuth client and still be valid
    $info = google_http_json('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($token));
    if ($info === null || empty($info['aud']) || (string) $info['aud'] !== $clientId || (int) ($info['expires_in'] ?? 0) <= 0) {
        json_error(401, 'Google could not verify this sign-in. Please try again.');
    }
    if (empty($info['email']) || (string) ($info['email_verified'] ?? 'false') !== 'true') {
        json_error(401, 'Your Google account email address is not verified. Please use another sign-in method.');
    }
    $email = strtolower(trim((string) $info['email']));
    if (!is_valid_email($email)) {
        json_error(400, 'Google returned an invalid email address.');
    }

    // 2) Profile (name) for new accounts
    $profile = google_http_json('https://www.googleapis.com/oauth2/v3/userinfo', $token) ?? [];
    $fullName = trim((string) ($profile['name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string) (($profile['given_name'] ?? '') . ' ' . ($profile['family_name'] ?? '')));
    }
    if ($fullName === '') {
        $fullName = ucfirst(preg_replace('/[^a-z0-9]+/i', ' ', explode('@', $email)[0]) ?? 'Customer');
    }
    $fullName = mb_substr($fullName, 0, 120);

    // 3) Existing account -> sign in
    $rows = db_select('SELECT * FROM users WHERE email = ? LIMIT 1', 's', [$email]);
    $user = $rows[0] ?? null;

    if ($user !== null) {
        $blocked = account_block_message((int) $user['id']);
        if ($blocked !== null) {
            json_error(403, $blocked);
        }
        if (column_exists('users', 'last_login_at')) {
            db_execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', 'i', [(int) $user['id']]);
        }
        if (table_exists('sellers')) {
            link_seller_stores((int) $user['id'], $email, (string) $user['phone']);
        }
        sign_in_user((int) $user['id']);
        json_response(200, [
            'success' => true,
            'created' => false,
            'message' => 'Welcome back, ' . $user['full_name'] . '!',
            'user'    => public_user($user),
        ]);
    }

    // 4) New account. A store registered with this Google email (and no login yet) becomes the account.
    if (!site_flag('allow_user_registration', true)) {
        json_error(403, 'New registrations are currently closed. Please try again later.');
    }
    $seller = orphan_seller_by_contact($email, $phone);
    $notice = '';
    if ($seller === null) {
        $linked = seller_by_contact($email, '');
        if ($linked !== null && !empty($linked['user_id'])) {
            $owner = db_select('SELECT email FROM users WHERE id = ? LIMIT 1', 'i', [(int) $linked['user_id']])[0] ?? null;
            $notice = sprintf('This email is the contact address of the store "%s", which is managed by the LipaLink account %s. To open that Seller Hub, log in with that account instead. Continuing here creates a separate customer account.',
                $linked['business_name'], $owner ? mask_email((string) $owner['email']) : 'of its owner');
        }
    }
    if ($seller !== null && $phone === '') {
        $sellerPhone = normalize_phone((string) $seller['phone']);
        $owner = $sellerPhone !== '' ? (db_select('SELECT id, email FROM users WHERE phone = ? LIMIT 1', 's', [$sellerPhone])[0] ?? null) : null;
        if ($owner !== null) {
            json_error(409, sprintf('The store "%s" is registered with this Google email, but its phone number already belongs to the LipaLink account %s. Log in with that account (email/phone and password, or "Forgot password") and the store will appear in its Seller Hub.',
                $seller['business_name'], mask_email((string) $owner['email'])));
        }
        $created = create_user_from_seller($seller);
        if ($created !== null) {
            if (column_exists('users', 'last_login_at')) {
                db_execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', 'i', [(int) $created['id']]);
            }
            sign_in_user((int) $created['id']);
            json_response(201, [
                'success'    => true,
                'created'    => true,
                'seller_hub' => true,
                'message'    => 'Welcome, ' . $created['full_name'] . '! Your store "' . $seller['business_name'] . '" is now linked to your Google sign-in.',
                'user'       => public_user($created),
            ]);
        }
    }
    if ($phone === '') {
        json_response(200, [
            'success'     => true,
            'needs_phone' => true,
            'message'     => 'Almost done - add the phone number you use for Mobile Money.',
            'notice'      => $notice,
            'name'        => $fullName,
            'email'       => $email,
        ]);
    }
    if (!is_valid_tz_phone($phone)) {
        json_error(400, 'Please enter a valid Tanzanian phone number (e.g. 0712 345 678).');
    }
    $taken = db_select('SELECT id FROM users WHERE phone = ? LIMIT 1', 's', [$phone]);
    if (!empty($taken)) {
        json_error(409, 'This phone number is already registered to another account. Log in with it instead.');
    }

    // Google accounts have no site password; store an unguessable hash so password login is impossible
    // until the user sets one via "Forgot password".
    $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    db_execute(
        'INSERT INTO users (full_name, phone, email, password_hash) VALUES (?, ?, ?, ?)',
        'ssss',
        [$fullName, $phone, $email, $hash]
    );
    $userId = (int) db()->insert_id;

    if (table_exists('sellers')) {
        link_seller_stores($userId, $email, $phone);
    }
    if (site_flag('notify_email_registration', true)) {
        send_mail(
            $email,
            cfg('app_name') . ': welcome, your account is ready',
            sprintf("Hello %s,\n\nYour %s account has been created with your Google sign-in.\n\nPhone: %s\nEmail: %s\n\nYou can log in any time with \"Continue with Google\". To also log in with a password, use \"Forgot password\" once to set one.\n\nStart here: %s/dashboard.html\n\nIf you did not create this account, please contact support.\n\n— %s",
                $fullName, cfg('app_name'), $phone, $email, app_base_url(), cfg('app_name')),
            'registration_welcome', 'user', (string) $userId
        );
    }

    sign_in_user($userId);
    $created = db_select('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$userId])[0] ?? ['id' => $userId, 'full_name' => $fullName, 'email' => $email, 'phone' => $phone];
    json_response(201, [
        'success' => true,
        'created' => true,
        'message' => 'Your account has been created with Google. Welcome, ' . $fullName . '!',
        'user'    => public_user($created),
    ]);
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'This email address or phone number is already registered.');
    }
    server_error($e, 'google sign-in');
}

/** GET a Google endpoint and decode its JSON body; null on any failure. */
function google_http_json(string $url, ?string $bearer = null): ?array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer !== null) {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        return null;
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}
