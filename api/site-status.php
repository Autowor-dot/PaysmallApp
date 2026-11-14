<?php
/**
 * GET api/site-status.php
 * Public website settings: name, contact details, maintenance state and
 * whether registration is open. Exempt from the maintenance gate. No secrets.
 */

require_once __DIR__ . '/config.php';

require_method('GET');

json_response(200, [
    'success'     => true,
    'site_name'   => site_setting('site_name', (string) cfg('app_name')),
    'tagline'     => site_setting('site_tagline', ''),
    'contact'     => [
        'email'         => site_setting('contact_email', ''),
        'support_email' => site_setting('support_email', ''),
        'phone'         => site_setting('contact_phone', ''),
        'address'       => site_setting('contact_address', ''),
    ],
    'maintenance' => [
        'enabled' => site_flag('maintenance_mode', false),
        'message' => site_setting('maintenance_message', ''),
    ],
    'google_client_id' => (string) cfg('google_client_id', ''),
    'registration' => [
        'users'   => site_flag('allow_user_registration', true),
        'sellers' => site_flag('allow_seller_registration', true),
    ],
]);
