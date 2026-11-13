<?php
/**
 * GET api/transactions.php
 * Full payment history for the signed-in customer (newest first).
 */

require_once __DIR__ . '/config.php';

require_method('GET');

$userId = require_auth();

try {
    $transactions = [];

    if (table_exists('transactions')) {
        $rows = db_select(
            'SELECT * FROM transactions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 500',
            'i',
            [$userId]
        );
        foreach ($rows as $r) {
            $transactions[] = [
                'id'               => (int) $r['id'],
                'transaction_id'   => $r['transaction_code'] ?? ('#TX-' . $r['id']),
                'plan_id'          => isset($r['plan_id']) ? (int) $r['plan_id'] : null,
                'product_name'     => $r['product_name'] ?? 'Installment Payment',
                'seller_name'      => $r['seller_name'] ?? null,
                'amount_paid'      => (float) ($r['amount'] ?? 0),
                'transaction_date' => $r['created_at'] ?? null,
                'payment_method'   => $r['payment_method'] ?? 'Mobile Money',
                'payer_phone'      => $r['payer_phone'] ?? null,
                'status'           => ucfirst((string) ($r['status'] ?? 'completed')),
            ];
        }
    }

    json_response(200, [
        'success' => true,
        'data'    => ['transactions' => $transactions, 'count' => count($transactions)],
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'transactions');
}
