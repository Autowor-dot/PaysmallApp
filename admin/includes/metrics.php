<?php
/**
 * Shared definitions so every admin page computes statuses and money the same way.
 *
 * Agreement lifecycle (derived from agreements.status + installment_plans.status):
 *   pending   = awaiting seller approval
 *   active    = accepted, plan not finished
 *   completed = accepted, plan fully paid
 *   declined  = seller declined
 *   cancelled = cancelled by customer or administrator
 *   overdue   = active with at least one unpaid installment past its due date
 *
 * Money:
 *   Expected  = total agreed value of accepted agreements (what customers committed to pay)
 *   Recorded  = transactions with status 'completed' (confirmed in our system by any route)
 *   Verified  = recorded AND confirmed by a payment provider webhook (verified_via = 'provider')
 *   Simulated = recorded by the development simulator — no real money moved
 */

declare(strict_types=1);

const SUSPICIOUS_AMOUNT_TZS = 10000000;

/** FROM clause shared by agreement queries (aliases: a, u, s, pl, od). */
function agreement_from_sql(): string
{
    return " FROM agreements a
        JOIN users u ON u.id = a.customer_id
        LEFT JOIN sellers s ON s.id = a.seller_id
        LEFT JOIN installment_plans pl ON pl.agreement_id = a.id
        LEFT JOIN (
            SELECT plan_id, COUNT(*) AS overdue_count, SUM(amount_due) AS overdue_amount, MIN(due_date) AS oldest_overdue
            FROM installment_payments
            WHERE status <> 'paid' AND due_date < CURDATE()
            GROUP BY plan_id
        ) od ON od.plan_id = pl.id ";
}

/** CASE expression → pending|active|completed|declined|cancelled */
function agreement_state_sql(): string
{
    return "(CASE
        WHEN a.status = 'pending_seller_approval' THEN 'pending'
        WHEN a.status = 'declined' THEN 'declined'
        WHEN a.status = 'cancelled' THEN 'cancelled'
        WHEN a.status = 'accepted' AND pl.status = 'completed' THEN 'completed'
        WHEN a.status = 'accepted' AND pl.status = 'cancelled' THEN 'cancelled'
        WHEN a.status = 'accepted' THEN 'active'
        ELSE a.status END)";
}

function agreement_overdue_sql(): string
{
    return "(a.status = 'accepted' AND COALESCE(pl.status, '') NOT IN ('completed','cancelled') AND COALESCE(od.overdue_count, 0) > 0)";
}

/** Rule-based red flags. Each entry: [sql condition, human reason]. */
function suspicious_rules(): array
{
    return [
        ['a.total_price >= ' . SUSPICIOUS_AMOUNT_TZS, 'Unusually large amount (≥ ' . tzs(SUSPICIOUS_AMOUNT_TZS) . ')'],
        ["(a.seller_email <> '' AND LOWER(a.seller_email) = LOWER(u.email))", 'Seller email is the customer\'s own email'],
        ["(a.seller_phone <> '' AND a.seller_phone = u.phone)", 'Seller phone is the customer\'s own phone'],
        ["s.status = 'suspended'", 'Seller account is suspended'],
        ['a.number_of_installments > 60', 'More than 60 installments'],
        ['a.initial_deposit > a.total_price', 'Deposit exceeds total price'],
        ['(SELECT COUNT(*) FROM agreements a2 WHERE a2.customer_id = a.customer_id AND a2.created_at BETWEEN (a.created_at - INTERVAL 1 DAY) AND a.created_at) >= 5', '5+ agreements by this customer within 24 hours'],
    ];
}

function suspicious_sql(): string
{
    return '(' . implode(' OR ', array_map(static fn($r) => '(' . $r[0] . ')', suspicious_rules())) . ')';
}

/** Reasons that apply to one agreement id. */
function suspicious_reasons(int $agreementId): array
{
    $selects = [];
    foreach (suspicious_rules() as $i => $rule) {
        $selects[] = '(' . $rule[0] . ') AS r' . $i;
    }
    $row = db_select('SELECT ' . implode(', ', $selects) . agreement_from_sql() . ' WHERE a.id = ? LIMIT 1', 'i', [$agreementId])[0] ?? [];
    $reasons = [];
    foreach (suspicious_rules() as $i => $rule) {
        if (!empty($row['r' . $i])) {
            $reasons[] = $rule[1];
        }
    }
    return $reasons;
}

/** Headline numbers for the overview and reports. */
function platform_totals(): array
{
    $t = [];
    $t['users_total']      = (int) scalar('SELECT COUNT(*) FROM users');
    $t['users_active']     = (int) scalar("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $t['users_suspended']  = (int) scalar("SELECT COUNT(*) FROM users WHERE status = 'suspended'");
    $t['users_new_30d']    = (int) scalar('SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 30 DAY)');
    $t['users_new_7d']     = (int) scalar('SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
    // Customers = accounts that have created at least one agreement
    $t['customers']        = (int) scalar('SELECT COUNT(DISTINCT customer_id) FROM agreements');
    $t['sellers_total']    = (int) scalar('SELECT COUNT(*) FROM sellers');
    $t['sellers_verified'] = (int) scalar("SELECT COUNT(*) FROM sellers WHERE status = 'verified'");
    $t['sellers_pending']  = (int) scalar("SELECT COUNT(*) FROM sellers WHERE status = 'pending'");
    $t['sellers_new_30d']  = (int) scalar('SELECT COUNT(*) FROM sellers WHERE created_at >= (NOW() - INTERVAL 30 DAY)');

    $state = agreement_state_sql();
    $counts = ['pending' => 0, 'active' => 0, 'completed' => 0, 'declined' => 0, 'cancelled' => 0];
    foreach (db_select("SELECT {$state} AS st, COUNT(*) AS c " . agreement_from_sql() . ' GROUP BY st') as $r) {
        $counts[$r['st']] = (int) $r['c'];
    }
    $t['agreements']           = $counts;
    $t['agreements_total']     = array_sum($counts);
    $t['agreements_overdue']   = (int) scalar('SELECT COUNT(*) ' . agreement_from_sql() . ' WHERE ' . agreement_overdue_sql());
    $t['agreements_suspicious'] = (int) scalar('SELECT COUNT(*) ' . agreement_from_sql() . ' WHERE ' . suspicious_sql());

    // Expected: value committed under accepted agreements
    $t['expected_total'] = scalar("SELECT COALESCE(SUM(a.total_price),0) FROM agreements a WHERE a.status = 'accepted'");
    $t['agreement_value_all'] = scalar("SELECT COALESCE(SUM(total_price),0) FROM agreements WHERE status IN ('accepted','pending_seller_approval')");
    $t['completed_value'] = scalar("SELECT COALESCE(SUM(a.total_price),0) FROM agreements a JOIN installment_plans pl ON pl.agreement_id = a.id WHERE a.status = 'accepted' AND pl.status = 'completed'");

    // Scheduled installments still to come on live plans
    $t['scheduled_unpaid'] = scalar("SELECT COALESCE(SUM(ip.amount_due),0) FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id WHERE ip.status <> 'paid' AND pl.status IN ('active','overdue')");
    $t['outstanding'] = scalar("SELECT COALESCE(SUM(GREATEST(pl.total_price - pl.amount_paid, 0)),0) FROM installment_plans pl WHERE pl.status IN ('active','overdue')");
    $t['overdue_amount'] = scalar("SELECT COALESCE(SUM(ip.amount_due),0) FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id WHERE ip.status <> 'paid' AND ip.due_date < CURDATE() AND pl.status IN ('active','overdue')");
    $t['overdue_installments'] = (int) scalar("SELECT COUNT(*) FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id WHERE ip.status <> 'paid' AND ip.due_date < CURDATE() AND pl.status IN ('active','overdue')");

    $v = tx_verification_sql();
    $row = db_select("SELECT
            COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.amount END),0) AS recorded,
            COALESCE(SUM(CASE WHEN {$v} = 'provider' THEN t.amount END),0) AS verified,
            COALESCE(SUM(CASE WHEN {$v} = 'simulator' THEN t.amount END),0) AS simulated,
            COALESCE(SUM(CASE WHEN {$v} = 'unverified' THEN t.amount END),0) AS unverified,
            COALESCE(SUM(CASE WHEN t.status = 'pending' THEN t.amount END),0) AS pending_amount,
            SUM(t.status = 'completed') AS n_completed, SUM(t.status = 'pending') AS n_pending, SUM(t.status = 'failed') AS n_failed,
            COUNT(*) AS n_all
        FROM transactions t")[0];
    $t['payments'] = array_map(static fn($x) => (float) $x, $row);
    return $t;
}

/** Last N month keys 'YYYY-MM' (oldest first). */
function month_keys(int $n = 12): array
{
    $keys = [];
    $base = new DateTimeImmutable('first day of this month');
    for ($i = $n - 1; $i >= 0; $i--) {
        $keys[] = $base->modify("-{$i} month")->format('Y-m');
    }
    return $keys;
}

/** Fill a month series from rows of [ym, value]. */
function month_series(array $rows, array $keys, string $col = 'v'): array
{
    $map = [];
    foreach ($rows as $r) {
        $map[$r['ym']] = (float) $r[$col];
    }
    return array_map(static fn($k) => $map[$k] ?? 0, $keys);
}

function month_labels(array $keys): array
{
    return array_map(static fn($k) => date('M y', (int) strtotime($k . '-01')), $keys);
}
