<?php
/**
 * Payment settlement logic shared by payments.php (status / simulator)
 * and payment-webhook.php (provider callbacks).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function fetch_transaction_by_code(string $code): ?array
{
    $rows = db_select('SELECT * FROM transactions WHERE transaction_code = ? LIMIT 1', 's', [$code]);
    return $rows[0] ?? null;
}

function public_transaction(array $r): array
{
    return [
        'id'                 => (int) $r['id'],
        'transaction_id'     => $r['transaction_code'] ?? ('#TX-' . $r['id']),
        'plan_id'            => isset($r['plan_id']) ? (int) $r['plan_id'] : null,
        'payment_id'         => isset($r['payment_id']) ? (int) $r['payment_id'] : null,
        'product_name'       => $r['product_name'] ?? 'Installment Payment',
        'seller_name'        => $r['seller_name'] ?? null,
        'amount_paid'        => (float) ($r['amount'] ?? 0),
        'transaction_date'   => $r['created_at'] ?? null,
        'confirmed_at'       => $r['confirmed_at'] ?? null,
        'payment_method'     => $r['payment_method'] ?? 'Mobile Money',
        'payer_phone'        => $r['payer_phone'] ?? null,
        'provider_reference' => $r['provider_reference'] ?? null,
        'status'             => ucfirst((string) ($r['status'] ?? 'pending')),
    ];
}

/**
 * Settle a pending transaction.
 *
 * @param array   $tx          transactions row
 * @param string  $newStatus   'completed' | 'failed'
 * @param ?string $verifiedVia how the result was confirmed: 'provider' (gateway webhook) or 'simulator'
 * @return array               updated transactions row
 */
function apply_payment_result(array $tx, string $newStatus, ?string $providerReference = null, ?string $verifiedVia = null): array
{
    if (!in_array($newStatus, ['completed', 'failed'], true)) {
        throw new InvalidArgumentException('Unsupported payment status.');
    }
    if (($tx['status'] ?? '') !== 'pending') {
        return $tx; // already settled; idempotent
    }

    $conn = db();
    $conn->begin_transaction();

    try {
        $txId   = (int) $tx['id'];
        $amount = (float) $tx['amount'];
        $userId = (int) $tx['customer_id'];
        $hasRef = column_exists('transactions', 'provider_reference');

        if ($newStatus === 'failed') {
            db_execute("UPDATE transactions SET status = 'failed' WHERE id = ?", 'i', [$txId]);
            $conn->commit();
            add_notification($userId, 'payment', 'Payment failed',
                sprintf('Payment %s of TZS %s for %s was not completed. You can try again from your dashboard.',
                    $tx['transaction_code'], number_format($amount), $tx['product_name']));
            return fetch_transaction_by_code((string) $tx['transaction_code']) ?? $tx;
        }

        if ($hasRef) {
            db_execute("UPDATE transactions SET status = 'completed', confirmed_at = NOW(), provider_reference = ? WHERE id = ?",
                'si', [$providerReference, $txId]);
        } else {
            db_execute("UPDATE transactions SET status = 'completed', confirmed_at = NOW() WHERE id = ?", 'i', [$txId]);
        }
        if ($verifiedVia !== null && column_exists('transactions', 'verified_via')) {
            db_execute('UPDATE transactions SET verified_via = ? WHERE id = ?', 'si', [$verifiedVia, $txId]);
        }

        // Mark the scheduled installment paid (or reduce it for a partial payment)
        $paymentId = isset($tx['payment_id']) ? (int) $tx['payment_id'] : 0;
        if ($paymentId > 0 && table_exists('installment_payments')) {
            $rows = db_select('SELECT * FROM installment_payments WHERE id = ? LIMIT 1', 'i', [$paymentId]);
            if (!empty($rows)) {
                $due = (float) $rows[0]['amount_due'];
                if ($amount + 0.005 >= $due) {
                    db_execute("UPDATE installment_payments SET status = 'paid', paid_at = NOW() WHERE id = ?", 'i', [$paymentId]);
                } else {
                    $remaining = round($due - $amount, 2);
                    db_execute('UPDATE installment_payments SET amount_due = ? WHERE id = ?', 'di', [$remaining, $paymentId]);
                }
            }
        }

        // Update the plan totals and roll forward the next due installment
        $planId = isset($tx['plan_id']) ? (int) $tx['plan_id'] : 0;
        if ($planId > 0 && table_exists('installment_plans')) {
            db_execute('UPDATE installment_plans SET amount_paid = amount_paid + ? WHERE id = ?', 'di', [$amount, $planId]);
            $plan = db_select('SELECT * FROM installment_plans WHERE id = ? LIMIT 1', 'i', [$planId])[0] ?? null;

            if ($plan !== null) {
                $paid  = (float) $plan['amount_paid'];
                $total = (float) $plan['total_price'];

                $nextAmount = 0.0;
                $nextDue    = null;
                if (table_exists('installment_payments')) {
                    $next = db_select(
                        "SELECT amount_due, due_date FROM installment_payments
                         WHERE plan_id = ? AND status <> 'paid' ORDER BY due_date ASC, installment_number ASC LIMIT 1",
                        'i', [$planId]
                    );
                    if (!empty($next)) {
                        $nextAmount = (float) $next[0]['amount_due'];
                        $nextDue    = $next[0]['due_date'];
                    }
                }

                $status = $paid + 0.005 >= $total ? 'completed' : 'active';
                db_execute(
                    'UPDATE installment_plans SET next_payment_amount = ?, next_payment_due_date = ?, status = ? WHERE id = ?',
                    'dssi',
                    [$nextAmount, $nextDue, $status, $planId]
                );

                if ($status === 'completed') {
                    add_notification($userId, 'success', 'Plan fully paid!',
                        sprintf('Congratulations! You have completed all payments for %s.', $plan['product_name']));
                }
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    add_notification($userId, 'payment', 'Payment confirmed',
        sprintf('Your payment %s of TZS %s for %s has been confirmed. A digital receipt is now available.',
            $tx['transaction_code'], number_format($amount), $tx['product_name']));

    notify_seller_of_payment($tx, $verifiedVia);

    return fetch_transaction_by_code((string) $tx['transaction_code']) ?? $tx;
}

/** Payment receipt to the seller of the agreement behind a confirmed transaction. Never throws. */
function notify_seller_of_payment(array $tx, ?string $verifiedVia): void
{
    try {
        $planId = isset($tx['plan_id']) ? (int) $tx['plan_id'] : 0;
        if ($planId <= 0 || !table_exists('installment_plans') || !table_exists('agreements')) {
            return;
        }
        $rows = db_select(
            'SELECT a.id, a.agreement_code, a.seller_name, a.seller_email, a.seller_id, u.full_name AS customer_name, s.user_id AS seller_user_id
             FROM installment_plans pl
             JOIN agreements a ON a.id = pl.agreement_id
             JOIN users u ON u.id = a.customer_id
             LEFT JOIN sellers s ON s.id = a.seller_id
             WHERE pl.id = ? LIMIT 1',
            'i', [$planId]
        );
        if (empty($rows)) {
            return;
        }
        $a = $rows[0];
        $amount = number_format((float) $tx['amount']);
        $note = $verifiedVia === 'provider'
            ? 'This payment was confirmed by the payment provider.'
            : ($verifiedVia === 'simulator'
                ? 'NOTE: this is a TEST payment confirmed by the development simulator; no money moved.'
                : 'This payment has been recorded but not independently verified by a payment provider.');

        if (!empty($a['seller_user_id'])) {
            add_notification((int) $a['seller_user_id'], 'payment', 'Installment payment received',
                sprintf('%s paid TZS %s on agreement %s (ref %s).', $a['customer_name'], $amount, $a['agreement_code'], $tx['transaction_code']));
        }
        if (!empty($a['seller_email']) && site_flag('notify_email_payment_receipt', true)) {
            send_mail(
                (string) $a['seller_email'],
                cfg('app_name') . ': payment receipt ' . $tx['transaction_code'],
                sprintf("Hello %s,\n\nAn installment payment has been recorded on agreement %s.\n\nCustomer: %s\nProduct/service: %s\nAmount: TZS %s\nMethod: %s\nReference: %s\nProvider reference: %s\nDate: %s\n\n%s\n\n— %s",
                    $a['seller_name'] ?: 'there', $a['agreement_code'], $a['customer_name'], $tx['product_name'], $amount,
                    $tx['payment_method'], $tx['transaction_code'], $tx['provider_reference'] ?? '-', date('j M Y H:i'), $note, cfg('app_name')),
                'payment_receipt_seller', 'transaction', (string) $tx['id']
            );
        }
    } catch (Throwable $e) {
        error_log('LipaLink seller receipt error: ' . $e->getMessage());
    }
}
