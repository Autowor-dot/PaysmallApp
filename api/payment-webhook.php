<?php
/**
 * POST api/payment-webhook.php
 *
 * Callback endpoint for the Mobile Money / bank payment provider.
 * Body (JSON or form): transaction_code, status ("completed" | "failed"), provider_reference?
 * Authentication: header  X-LipaLink-Signature: <webhook_secret>
 *                 or field secret=<webhook_secret>
 *
 * Idempotent: settling an already-settled transaction returns success without changes.
 */

require_once __DIR__ . '/payments-lib.php';

require_method('POST');

try {
    $expected = (string) cfg('webhook_secret', '');
    $provided = (string) ($_SERVER['HTTP_X_LIPALINK_SIGNATURE'] ?? '');
    $input    = request_input();
    if ($provided === '') {
        $provided = input_string($input, 'secret', 255);
    }

    if ($expected === '' || $expected === 'change-me-to-a-long-random-string') {
        error_log('LipaLink webhook rejected: webhook_secret is not configured.');
        json_error(503, 'Webhook secret is not configured on the server.');
    }
    if (!hash_equals($expected, $provided)) {
        json_error(401, 'Invalid webhook signature.');
    }

    if (!table_exists('transactions')) {
        json_error(503, 'Transactions table is missing. Run api/schema.sql.');
    }

    $code   = input_string($input, 'transaction_code', 40);
    $status = strtolower(input_string($input, 'status', 20));
    $ref    = input_string($input, 'provider_reference', 100);

    if ($code === '' || !in_array($status, ['completed', 'failed'], true)) {
        json_error(400, 'transaction_code and a status of "completed" or "failed" are required.');
    }

    $tx = fetch_transaction_by_code($code);
    if ($tx === null) {
        json_error(404, 'Unknown transaction code.');
    }

    $updated = apply_payment_result($tx, $status, $ref !== '' ? $ref : null, 'provider');

    json_response(200, [
        'success'     => true,
        'message'     => 'Transaction ' . $code . ' is now ' . $updated['status'] . '.',
        'transaction' => public_transaction($updated),
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'payment webhook');
}
