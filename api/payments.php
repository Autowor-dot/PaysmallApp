<?php
/**
 * api/payments.php
 *
 * GET  ?code=TX-...                 -> status of one of the customer's transactions (pay.html polls this)
 * POST { action: "simulate", code } -> DEVELOPMENT ONLY: marks a pending transaction completed,
 *                                      exactly as the provider webhook would.
 */

require_once __DIR__ . '/payments-lib.php';

require_method('GET', 'POST');

$userId = require_auth();

try {
    if (!table_exists('transactions')) {
        json_error(503, 'The payment service is not set up yet. Please run api/schema.sql on the database.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            json_error(400, 'A transaction code is required.');
        }
        $tx = fetch_transaction_by_code($code);
        if ($tx === null || (int) $tx['customer_id'] !== $userId) {
            json_error(404, 'Transaction not found.');
        }
        json_response(200, ['success' => true, 'data' => ['transaction' => public_transaction($tx)]]);
    }

    $input  = request_input();
    $action = input_string($input, 'action', 40);

    if ($action !== 'simulate') {
        json_error(400, 'Unknown action.');
    }
    if (!is_development()) {
        json_error(403, 'The payment simulator is only available when app_env is "development".');
    }

    $code = input_string($input, 'code', 40);
    $tx   = fetch_transaction_by_code($code);
    if ($tx === null || (int) $tx['customer_id'] !== $userId) {
        json_error(404, 'Transaction not found.');
    }

    $result = input_string($input, 'result', 20) === 'failed' ? 'failed' : 'completed';
    $updated = apply_payment_result($tx, $result, 'SIMULATED-' . strtoupper(bin2hex(random_bytes(3))));

    json_response(200, [
        'success' => true,
        'message' => $result === 'completed' ? 'Payment simulated as confirmed.' : 'Payment simulated as failed.',
        'data'    => ['transaction' => public_transaction($updated)],
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'payments');
}
