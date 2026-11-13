<?php
/**
 * GET api/dashboard.php
 * Returns everything dashboard.html, agreement.html and pay.html need:
 * customer, summary, active_installments, upcoming_payments, recent_transactions, notifications.
 *
 * Optional tables (installment_plans, installment_payments, transactions, notifications)
 * are skipped gracefully when they have not been created yet — see api/schema.sql.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tasks-lib.php';

require_method('GET');

$userId = require_auth();

// No cron on shared hosting: roll installment statuses and send reminders at most once an hour
try {
    run_scheduled_tasks(3600);
} catch (Throwable $taskError) {
    error_log('LipaLink tasks error: ' . $taskError->getMessage());
}

try {
    $userRow = fetch_user($userId);
    if ($userRow === null) {
        sign_out_user();
        json_error(401, 'Your account could not be found. Please log in again.');
    }

    $today = date('Y-m-d');

    // ---------------- Active installment plans ----------------
    $installments = [];
    if (table_exists('installment_plans')) {
        $rows = db_select(
            "SELECT * FROM installment_plans
             WHERE customer_id = ? AND status NOT IN ('completed', 'cancelled')
             ORDER BY next_payment_due_date IS NULL, next_payment_due_date ASC, id DESC",
            'i',
            [$userId]
        );
        foreach ($rows as $r) {
            $total = (float) ($r['total_price'] ?? 0);
            $paid  = (float) ($r['amount_paid'] ?? 0);
            $remaining = max(0.0, $total - $paid);
            $installments[] = [
                'id'                    => (int) $r['id'],
                'agreement_id'          => $r['agreement_id'] ?? null,
                'product_name'          => $r['product_name'] ?? 'Installment Plan',
                'seller_name'           => $r['seller_name'] ?? 'Verified Seller',
                'product_image'         => $r['product_image'] ?? null,
                'total_price'           => $total,
                'amount_paid'           => $paid,
                'remaining_balance'     => $remaining,
                'completion_percentage' => $total > 0 ? (int) round($paid / $total * 100) : 0,
                'next_payment_amount'   => (float) ($r['next_payment_amount'] ?? 0),
                'next_payment_due_date' => $r['next_payment_due_date'] ?? null,
                'status'                => ucfirst((string) ($r['status'] ?? 'active')),
            ];
        }
    }

    // Full installment schedule for each active plan
    if ( !== [] && table_exists('installment_payments')) {
         = array_column(, 'id');
          = implode(',', array_fill(0, count(), '?'));
         = [];
        foreach (db_select("SELECT id, plan_id, installment_number, amount_due, due_date, status, paid_at FROM installment_payments WHERE plan_id IN ({}) ORDER BY plan_id, installment_number", str_repeat('i', count()), ) as ) {
            [(int) ['plan_id']][] = [
                'id' => (int) ['id'], 'installment_number' => (int) ['installment_number'], 'amount_due' => (float) ['amount_due'],
                'due_date' => ['due_date'], 'status' => ['status'], 'paid_at' => ['paid_at'],
            ];
        }
        foreach ( as &) {
            ['schedule'] = [['id']] ?? [];
        }
        unset();
    }

    // ---------------- Upcoming payments ----------------
    $upcoming = [];
    if (table_exists('installment_payments') && table_exists('installment_plans')) {
        $rows = db_select(
            "SELECT p.*, pl.product_name, pl.seller_name
             FROM installment_payments p
             JOIN installment_plans pl ON pl.id = p.plan_id
             WHERE p.customer_id = ? AND p.status <> 'paid'
             ORDER BY p.due_date ASC, p.installment_number ASC
             LIMIT 20",
            'i',
            [$userId]
        );
        foreach ($rows as $r) {
            $due = (string) ($r['due_date'] ?? '');
            if ($due !== '' && $due < $today) {
                $label = 'Overdue';
            } elseif ($due === $today) {
                $label = 'Due Today';
            } else {
                $label = 'Upcoming';
            }
            $upcoming[] = [
                'id'                 => (int) $r['id'],
                'plan_id'            => (int) $r['plan_id'],
                'installment_number' => (int) ($r['installment_number'] ?? 0),
                'product_name'       => $r['product_name'] ?? 'Installment Payment',
                'seller_name'        => $r['seller_name'] ?? 'Seller',
                'amount_due'         => (float) ($r['amount_due'] ?? 0),
                'due_date'           => $due,
                'status'             => $label,
            ];
        }
    }

    // ---------------- Recent transactions ----------------
    $transactions = [];
    $totalPaid = 0.0;
    if (table_exists('transactions')) {
        $rows = db_select(
            'SELECT * FROM transactions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 10',
            'i',
            [$userId]
        );
        foreach ($rows as $r) {
            $transactions[] = [
                'id'               => (int) $r['id'],
                'transaction_id'   => $r['transaction_code'] ?? ('#TX-' . $r['id']),
                'product_name'     => $r['product_name'] ?? 'Installment Payment',
                'seller_name'      => $r['seller_name'] ?? null,
                'amount_paid'      => (float) ($r['amount'] ?? 0),
                'transaction_date' => $r['created_at'] ?? null,
                'payment_method'   => $r['payment_method'] ?? 'Mobile Money',
                'payer_phone'      => $r['payer_phone'] ?? null,
                'status'           => ucfirst((string) ($r['status'] ?? 'completed')),
            ];
        }
        $sum = db_select(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE customer_id = ? AND status = 'completed'",
            'i',
            [$userId]
        );
        $totalPaid = (float) ($sum[0]['total'] ?? 0);
    }

    // ---------------- Notifications ----------------
    $notifications = [];
    if (table_exists('notifications')) {
        $rows = db_select(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10',
            'i',
            [$userId]
        );
        foreach ($rows as $r) {
            $notifications[] = [
                'id'         => (int) $r['id'],
                'type'       => $r['type'] ?? 'general',
                'title'      => $r['title'] ?? 'Account Update',
                'message'    => $r['message'] ?? '',
                'is_read'    => (bool) ($r['is_read'] ?? 0),
                'created_at' => $r['created_at'] ?? null,
            ];
        }
    }

    // ---------------- Summary ----------------
    $remainingBalance = 0.0;
    foreach ($installments as $plan) {
        $remainingBalance += $plan['remaining_balance'];
    }
    $upcomingAmount = 0.0;
    foreach ($upcoming as $p) {
        $upcomingAmount += $p['amount_due'];
    }

    json_response(200, [
        'success' => true,
        'data' => [
            'customer' => public_user($userRow),
            'summary'  => [
                'total_remaining_balance'  => $remainingBalance,
                'active_installment_plans' => count($installments),
                'upcoming_payments_amount' => $upcomingAmount,
                'upcoming_payments_count'  => count($upcoming),
                'total_amount_paid'        => $totalPaid,
            ],
            'active_installments' => $installments,
            'upcoming_payments'   => $upcoming,
            'recent_transactions' => $transactions,
            'notifications'       => $notifications,
        ],
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'dashboard');
}
