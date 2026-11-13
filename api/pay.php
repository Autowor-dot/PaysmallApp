<?php
/**
 * POST api/pay.php   JSON { payment_id?, plan_id?, product_name?, amount, method, phone? }
 *
 * Records a payment request as a PENDING transaction and returns a reference.
 * The actual Mobile Money / bank confirmation is handled by the payment provider
 * webhook (not part of this frontend project); once confirmed, the transaction
 * status should be set to 'completed' and the installment marked 'paid'.
 */

require_once __DIR__ . '/config.php';

require_method('POST');

$userId = require_auth();

const PAYMENT_METHODS = ['M-Pesa', 'Tigo Pesa', 'Airtel Money', 'HaloPesa', 'Bank Transfer'];

try {
    if (!table_exists('transactions')) {
        json_error(503, 'The payment service is not set up yet. Please run api/schema.sql on the database.');
    }

    $input     = request_input();
    $paymentId = isset($input['payment_id']) && $input['payment_id'] !== null && $input['payment_id'] !== '' ? (int) $input['payment_id'] : null;
    $planId    = isset($input['plan_id']) && $input['plan_id'] !== null && $input['plan_id'] !== '' ? (int) $input['plan_id'] : null;
    $amount    = (float) ($input['amount'] ?? 0);
    $method    = input_string($input, 'method', 40);
    $phone     = normalize_phone(input_string($input, 'phone', 30));
    $product   = input_string($input, 'product_name', 200);
    $seller    = null;

    if ($amount < 500) {
        json_error(400, 'The minimum payment amount is TZS 500.');
    }
    if (!in_array($method, PAYMENT_METHODS, true)) {
        json_error(400, 'Please choose a supported payment method.');
    }
    $isBank = $method === 'Bank Transfer';
    if (!$isBank && !is_valid_tz_phone($phone)) {
        json_error(400, 'Please enter a valid Mobile Money number.');
    }

    // Resolve the scheduled payment / plan and make sure it belongs to this customer
    if ($paymentId !== null && table_exists('installment_payments') && table_exists('installment_plans')) {
        $rows = db_select(
            'SELECT p.*, pl.product_name, pl.seller_name
             FROM installment_payments p JOIN installment_plans pl ON pl.id = p.plan_id
             WHERE p.id = ? AND p.customer_id = ? LIMIT 1',
            'ii',
            [$paymentId, $userId]
        );
        if (empty($rows)) {
            json_error(404, 'That scheduled payment could not be found on your account.');
        }
        if (($rows[0]['status'] ?? '') === 'paid') {
            json_error(409, 'This installment has already been paid.');
        }
        $planId  = (int) $rows[0]['plan_id'];
        $product = (string) $rows[0]['product_name'];
        $seller  = $rows[0]['seller_name'] ?? null;
    } elseif ($planId !== null && table_exists('installment_plans')) {
        $rows = db_select('SELECT * FROM installment_plans WHERE id = ? AND customer_id = ? LIMIT 1', 'ii', [$planId, $userId]);
        if (empty($rows)) {
            json_error(404, 'That installment plan could not be found on your account.');
        }
        $product = (string) $rows[0]['product_name'];
        $seller  = $rows[0]['seller_name'] ?? null;
    }

    if ($product === '') {
        $product = 'Installment Payment';
    }

    $code = generate_code('TX');

    db_execute(
        'INSERT INTO transactions
            (customer_id, plan_id, payment_id, transaction_code, product_name, seller_name, amount, payment_method, payer_phone, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iiisssdsss',
        [$userId, $planId, $paymentId, $code, $product, $seller, $amount, $method, $isBank ? null : $phone, 'pending']
    );

    add_notification($userId, 'payment', 'Payment initiated', $isBank
        ? sprintf('Bank transfer reference %s created for %s. Complete the transfer to confirm your payment.', $code, $product)
        : sprintf('Payment request %s of TZS %s sent to %s. Approve the prompt on your phone to complete it.', $code, number_format($amount), $method));

    json_response(201, [
        'success'            => true,
        'message'            => $isBank
            ? 'Your bank transfer reference has been created. Use it when making the transfer; your receipt will be issued once the payment is verified.'
            : 'Your payment request has been sent. Approve the prompt on your phone to complete the payment.',
        'transaction_id'     => $code,
        'status'             => 'pending',
        'amount'             => $amount,
        'method'             => $method,
        // pay.html polls api/payments.php?code=... until the provider webhook settles the payment.
        'status_url'         => 'api/payments.php?code=' . rawurlencode($code),
        // In development the page shows a "Simulate confirmation" button that calls api/payments.php.
        'simulate_available' => is_development(),
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'payment');
}
