<?php
/**
 * POST api/contact.php  { name, email, phone?, subject, message }
 * Stores the message (contact_messages, visible in the admin console) and
 * emails it to the support address configured in Website Settings.
 */

require_once __DIR__ . '/config.php';

require_method('POST');

try {
    if (!table_exists('contact_messages')) {
        json_error(503, 'The contact form is not set up yet. Please run api/install.php.');
    }

    $input   = request_input();
    $name    = input_string($input, 'name', 120);
    $email   = strtolower(input_string($input, 'email', 190));
    $phone   = normalize_phone(input_string($input, 'phone', 30));
    $subject = input_string($input, 'subject', 160);
    $message = mb_substr(trim((string) ($input['message'] ?? '')), 0, 4000);

    if (mb_strlen($name) < 2) json_error(400, 'Please enter your name.');
    if (!is_valid_email($email)) json_error(400, 'Please enter a valid email address.');
    if ($phone !== '' && !is_valid_tz_phone($phone)) json_error(400, 'Please enter a valid Tanzanian phone number or leave it blank.');
    if (mb_strlen($subject) < 3) json_error(400, 'Please enter a subject.');
    if (mb_strlen($message) < 10) json_error(400, 'Please write a message of at least 10 characters.');
    // Honeypot field for bots
    if (!empty($input['website'])) json_response(200, ['success' => true, 'message' => 'Thank you, your message has been received.']);

    $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $recent = db_select('SELECT COUNT(*) AS c FROM contact_messages WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', 's', [$ip]);
    if ((int) ($recent[0]['c'] ?? 0) >= 5) {
        json_error(429, 'You have sent several messages recently. Please try again in an hour.');
    }

    $userId = current_user_id();
    db_execute(
        'INSERT INTO contact_messages (user_id, name, email, phone, subject, message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'issssss',
        [$userId, $name, $email, $phone !== '' ? $phone : null, $subject, $message, $ip]
    );
    $id = (int) db()->insert_id;

    $to = (string) site_setting('support_email', '') ?: (string) site_setting('contact_email', '');
    if ($to !== '') {
        send_mail(
            $to,
            cfg('app_name') . ' contact form: ' . $subject,
            sprintf("New message from the website contact form.\n\nName: %s\nEmail: %s\nPhone: %s\n\n%s\n\nReply to the sender directly. Message #%d is also in the admin console.",
                $name, $email, $phone !== '' ? $phone : '-', $message, $id),
            'contact_message', 'contact_message', (string) $id
        );
    }

    json_response(201, ['success' => true, 'message' => 'Thank you, your message has been received. We will get back to you by email.', 'id' => $id]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'contact');
}
