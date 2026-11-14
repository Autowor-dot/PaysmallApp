<?php
/**
 * api/profile.php
 *
 * GET  -> current user (used by the app shell as the session check)
 * POST -> JSON { action: "update_profile" | "change_password" | "update_settings" | "delete_account", ... }
 */

require_once __DIR__ . '/config.php';

require_method('GET', 'POST');

$userId = require_auth();

try {
    $userRow = fetch_user($userId);
    if ($userRow === null) {
        sign_out_user();
        json_error(401, 'Your account could not be found. Please log in again.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(200, ['success' => true, 'data' => ['user' => public_user($userRow)]]);
    }

    $input  = request_input();
    $action = input_string($input, 'action', 40);

    switch ($action) {
        // ------------------------------------------------------------
        case 'update_profile':
            $fullName = input_string($input, 'full_name', 120);
            $email    = strtolower(input_string($input, 'email', 190));
            $phone    = normalize_phone(input_string($input, 'phone', 30));

            if (mb_strlen($fullName) < 2) {
                json_error(400, 'Please enter your full name.');
            }
            if (!is_valid_email($email)) {
                json_error(400, 'Please enter a valid email address.');
            }
            if (!is_valid_tz_phone($phone)) {
                json_error(400, 'Please enter a valid Tanzanian phone number.');
            }

            $clash = db_select(
                'SELECT id FROM users WHERE (email = ? OR phone = ?) AND id <> ? LIMIT 1',
                'ssi',
                [$email, $phone, $userId]
            );
            if (!empty($clash)) {
                json_error(409, 'That email address or phone number is already used by another account.');
            }

            db_execute(
                'UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?',
                'sssi',
                [$fullName, $email, $phone, $userId]
            );

            $updated = fetch_user($userId) ?? $userRow;
            json_response(200, [
                'success' => true,
                'message' => 'Your profile has been updated.',
                'data'    => ['user' => public_user($updated)],
            ]);
            break;

        // ------------------------------------------------------------
        case 'change_password':
            $current = (string) ($input['current_password'] ?? '');
            $new     = (string) ($input['new_password'] ?? '');
            $confirm = (string) ($input['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                json_error(400, 'Please complete all password fields.');
            }
            if (!password_verify($current, (string) $userRow['password_hash'])) {
                json_error(401, 'Your current password is incorrect.');
            }
            if (strlen($new) < 8) {
                json_error(400, 'Your new password must be at least 8 characters long.');
            }
            if ($new !== $confirm) {
                json_error(400, 'The new passwords do not match.');
            }
            if ($current === $new) {
                json_error(400, 'Your new password must be different from your current password.');
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            db_execute('UPDATE users SET password_hash = ? WHERE id = ?', 'si', [$hash, $userId]);
            add_notification($userId, 'security', 'Password changed', 'Your password was changed from your account settings. If this was not you, contact support immediately.');
            send_security_email($userRow, 'your password was changed', sprintf('The password of your %s account was changed from the account settings on %s.', cfg('app_name'), date('j M Y H:i')));

            json_response(200, ['success' => true, 'message' => 'Your password has been changed.']);
            break;

        // ------------------------------------------------------------
        case 'update_settings':
            $settings = $input['settings'] ?? null;
            if (!is_array($settings)) {
                json_error(400, 'No settings were provided.');
            }

            // Whitelist the keys we store
            $allowed = ['sms_reminders', 'email_receipts', 'whatsapp_updates', 'seller_updates', 'marketing', 'language'];
            $clean = [];
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $settings)) {
                    continue;
                }
                $clean[$key] = $key === 'language'
                    ? (in_array($settings[$key], ['en', 'sw'], true) ? $settings[$key] : 'en')
                    : (bool) $settings[$key];
            }

            if (!column_exists('users', 'settings_json')) {
                // Column not migrated yet (see api/schema.sql); preferences stay on the device.
                json_response(200, [
                    'success'  => true,
                    'message'  => 'Preferences saved on this device. Server-side storage is not enabled yet.',
                    'persisted' => false,
                ]);
            }

            $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
            db_execute('UPDATE users SET settings_json = ? WHERE id = ?', 'si', [$json, $userId]);

            json_response(200, ['success' => true, 'message' => 'Preferences saved.', 'persisted' => true, 'settings' => $clean]);
            break;

        // ------------------------------------------------------------
        case 'delete_account':
            $password = (string) ($input['password'] ?? '');
            if ($password === '' || !password_verify($password, (string) $userRow['password_hash'])) {
                json_error(401, 'The password you entered is incorrect.');
            }

            if (table_exists('installment_plans')) {
                $active = db_select(
                    "SELECT COUNT(*) AS c FROM installment_plans WHERE customer_id = ? AND status NOT IN ('completed', 'cancelled')",
                    'i',
                    [$userId]
                );
                if ((int) ($active[0]['c'] ?? 0) > 0) {
                    json_error(409, 'You still have active installment plans. Please settle them before closing your account.');
                }
            }

            // Related rows are removed by ON DELETE CASCADE / SET NULL (see api/schema.sql)
            db_execute('DELETE FROM users WHERE id = ?', 'i', [$userId]);
            sign_out_user();

            json_response(200, ['success' => true, 'message' => 'Your account has been closed.']);
            break;

        default:
            json_error(400, 'Unknown action.');
    }
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'That email address or phone number is already used by another account.');
    }
    server_error($e, 'profile');
}
