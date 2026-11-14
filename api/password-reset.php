<?php
/**
 * POST api/password-reset.php
 *   { action: "request",  login }                                  -> emails a reset link (always responds success)
 *   { action: "validate", token }                                  -> { valid: true|false }
 *   { action: "reset",    token, password, confirm_password }      -> sets the new password
 *
 * In development mode the "request" response also includes reset_url for testing without email.
 */

require_once __DIR__ . '/config.php';

require_method('POST');

const RESET_TOKEN_TTL_MINUTES = 60;

try {
    if (!table_exists('password_resets')) {
        json_error(503, 'Password reset is not set up yet. Please run api/schema.sql on the database.');
    }

    $input  = request_input();
    $action = input_string($input, 'action', 20);

    // Opportunistic cleanup of expired tokens
    db_execute('DELETE FROM password_resets WHERE expires_at < NOW()');

    switch ($action) {
        // ------------------------------------------------------------
        case 'request':
            $login = input_string($input, 'login', 190);
            if ($login === '') {
                json_error(400, 'Please enter your email address or phone number.');
            }

            $phone = normalize_phone($login);
            $rows  = db_select('SELECT * FROM users WHERE email = ? OR phone = ? OR phone = ? LIMIT 1', 'sss', [strtolower($login), $login, $phone]);
            $user  = $rows[0] ?? null;

            $generic = 'If an account matches those details, a password reset link has been sent to its email address.';
            $note    = '';
            if ($user === null) {
                // Store registered without a login account (register-store.html while signed out)?
                $seller = orphan_seller_by_contact(strtolower($login), $phone);
                if ($seller !== null) {
                    $sellerPhone = normalize_phone((string) $seller['phone']);
                    $owner = $sellerPhone !== '' ? (db_select('SELECT * FROM users WHERE phone = ? LIMIT 1', 's', [$sellerPhone])[0] ?? null) : null;
                    if ($owner !== null) {
                        // The store's phone already belongs to an account: that account owns the store.
                        db_execute('UPDATE sellers SET user_id = ? WHERE id = ?', 'ii', [(int) $owner['id'], (int) $seller['id']]);
                        $user = $owner;
                        $note = sprintf('The store "%s" is registered with the phone number of the account %s, so the reset link was sent to that account\'s email address. Log in with that account to manage the store.',
                            $seller['business_name'], mask_email((string) $owner['email']));
                    } else {
                        $user = create_user_from_seller($seller);
                        if ($user !== null) {
                            $note = sprintf('We created the login account for your store "%s". Open the link in the email to choose your password.', $seller['business_name']);
                        }
                    }
                }
            }
            if ($user === null) {
                json_response(200, ['success' => true, 'message' => $generic]);
            }

            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires   = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL_MINUTES * 60);

            db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [(int) $user['id']]);
            db_execute(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
                'iss',
                [(int) $user['id'], $tokenHash, $expires]
            );

            $resetUrl = app_base_url() . '/reset-password.html?token=' . $token;
            $sent = send_mail(
                (string) $user['email'],
                cfg('app_name') . ': reset your password',
                sprintf("Hello %s,\n\nWe received a request to reset your %s password. Open the link below within %d minutes to choose a new one:\n\n%s\n\nIf you did not request this, you can ignore this email.\n\n— %s",
                    $user['full_name'], cfg('app_name'), RESET_TOKEN_TTL_MINUTES, $resetUrl, cfg('app_name')),
                'password_reset', 'user', (string) $user['id']
            );

            $payload = ['success' => true, 'message' => $note !== '' ? $note : $generic, 'email_sent' => $sent];
            if (is_development()) {
                $payload['reset_url'] = $resetUrl; // testing aid only
            }
            json_response(200, $payload);
            break;

        // ------------------------------------------------------------
        case 'validate':
            $token = input_string($input, 'token', 128);
            $rows  = $token !== ''
                ? db_select('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1', 's', [hash('sha256', $token)])
                : [];
            json_response(200, ['success' => true, 'valid' => !empty($rows)]);
            break;

        // ------------------------------------------------------------
        case 'reset':
            $token    = input_string($input, 'token', 128);
            $password = (string) ($input['password'] ?? '');
            $confirm  = (string) ($input['confirm_password'] ?? '');

            if ($token === '') {
                json_error(400, 'The reset link is missing its token.');
            }
            if (strlen($password) < 8) {
                json_error(400, 'Your new password must be at least 8 characters long.');
            }
            if ($password !== $confirm) {
                json_error(400, 'The passwords do not match.');
            }

            $rows = db_select('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1', 's', [hash('sha256', $token)]);
            $reset = $rows[0] ?? null;
            if ($reset === null) {
                json_error(400, 'This reset link is invalid or has expired. Please request a new one.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            db_execute('UPDATE users SET password_hash = ? WHERE id = ?', 'si', [$hash, (int) $reset['user_id']]);
            db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [(int) $reset['user_id']]);

            add_notification((int) $reset['user_id'], 'security', 'Password changed',
                'Your password was reset successfully. If this was not you, contact support immediately.');

            json_response(200, ['success' => true, 'message' => 'Your password has been reset. You can now log in.']);
            break;

        default:
            json_error(400, 'Unknown action.');
    }
} catch (mysqli_sql_exception $e) {
    server_error($e, 'password reset');
}
