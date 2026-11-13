<?php
/**
 * POST api/logout.php
 * Ends the PHP session.
 */

require_once __DIR__ . '/config.php';

require_method('POST');

sign_out_user();

json_response(200, ['success' => true, 'message' => 'You have been logged out.']);
