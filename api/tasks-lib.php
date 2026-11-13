<?php
/**
 * Scheduled tasks: installment status roll-over, upcoming/overdue reminders.
 *
 * Free hosting has no cron, so run_scheduled_tasks() is called
 *   - opportunistically from api/dashboard.php (at most once per hour), and
 *   - by api/cron.php?key=… from an external scheduler (e.g. cron-job.org) if configured.
 */

declare(strict_types=1);

const REMINDER_DAYS_AHEAD = 2;

/** Run all tasks unless they ran within $minIntervalSeconds. Returns a summary. */
function run_scheduled_tasks(int $minIntervalSeconds = 3600, bool $force = false): array
{
    if (!table_exists('installment_payments') || !table_exists('scheduled_runs')) {
        return ['ran' => false, 'reason' => 'schema not ready'];
    }
    $last = db_select("SELECT last_run FROM scheduled_runs WHERE task = 'reminders' LIMIT 1")[0]['last_run'] ?? null;
    if (!$force && $last !== null && (time() - (int) strtotime((string) $last)) < $minIntervalSeconds) {
        return ['ran' => false, 'reason' => 'ran recently', 'last_run' => $last];
    }
    // Claim the run first so concurrent requests do not double-send
    db_execute("INSERT INTO scheduled_runs (task, last_run) VALUES ('reminders', NOW()) ON DUPLICATE KEY UPDATE last_run = NOW()");

    $summary = ['ran' => true, 'statuses_updated' => 0, 'upcoming' => 0, 'due_today' => 0, 'overdue' => 0];
    try {
        $summary['statuses_updated'] = refresh_installment_statuses();
        $summary['upcoming']  = send_installment_reminders('upcoming');
        $summary['due_today'] = send_installment_reminders('due_today');
        $summary['overdue']   = send_installment_reminders('overdue');
        db_execute("UPDATE scheduled_runs SET last_result = ? WHERE task = 'reminders'", 's', [json_encode($summary)]);
    } catch (Throwable $e) {
        error_log('LipaLink scheduled tasks error: ' . $e->getMessage());
        db_execute("UPDATE scheduled_runs SET last_result = ? WHERE task = 'reminders'", 's', ['error: ' . mb_substr($e->getMessage(), 0, 200)]);
        $summary['error'] = $e->getMessage();
    }
    return $summary;
}

/** upcoming → due (today) → overdue (past), and plans with overdue items become 'overdue'. */
function refresh_installment_statuses(): int
{
    $n  = db_execute("UPDATE installment_payments SET status = 'overdue' WHERE status IN ('upcoming','due') AND due_date < CURDATE()");
    $n += db_execute("UPDATE installment_payments SET status = 'due' WHERE status = 'upcoming' AND due_date = CURDATE()");
    if (table_exists('installment_plans')) {
        $n += db_execute("UPDATE installment_plans pl SET pl.status = 'overdue'
            WHERE pl.status = 'active' AND EXISTS (SELECT 1 FROM installment_payments p WHERE p.plan_id = pl.id AND p.status = 'overdue')");
        $n += db_execute("UPDATE installment_plans pl SET pl.status = 'active'
            WHERE pl.status = 'overdue' AND NOT EXISTS (SELECT 1 FROM installment_payments p WHERE p.plan_id = pl.id AND p.status = 'overdue')");
    }
    return $n;
}

/**
 * Send one reminder per installment per kind (in-app notification + optional email).
 * kind: upcoming (due within REMINDER_DAYS_AHEAD days), due_today, overdue.
 */
function send_installment_reminders(string $kind): int
{
    $where = match ($kind) {
        'upcoming'  => "p.status IN ('upcoming','due') AND p.due_date > CURDATE() AND p.due_date <= DATE_ADD(CURDATE(), INTERVAL " . REMINDER_DAYS_AHEAD . " DAY)",
        'due_today' => "p.status IN ('upcoming','due') AND p.due_date = CURDATE()",
        'overdue'   => "p.status = 'overdue' AND p.due_date < CURDATE()",
        default     => null,
    };
    if ($where === null) {
        return 0;
    }
    $rows = db_select(
        "SELECT p.id, p.amount_due, p.due_date, p.installment_number, pl.product_name, pl.seller_name, pl.customer_id, pl.agreement_id,
                u.full_name, u.email
         FROM installment_payments p
         JOIN installment_plans pl ON pl.id = p.plan_id
         JOIN users u ON u.id = pl.customer_id
         WHERE {$where}
           AND pl.status IN ('active','overdue')
           AND NOT EXISTS (SELECT 1 FROM installment_reminders r WHERE r.payment_id = p.id AND r.kind = ?)
         LIMIT 200",
        's', [$kind]
    );

    $emailOn = site_flag('notify_email_reminders', true);
    $sent = 0;
    foreach ($rows as $r) {
        $amount = number_format((float) $r['amount_due']);
        $due    = date('j M Y', strtotime((string) $r['due_date']));
        switch ($kind) {
            case 'upcoming':
                $title = 'Installment due soon';
                $msg   = sprintf('Installment #%d of TZS %s for %s is due on %s.', $r['installment_number'], $amount, $r['product_name'], $due);
                break;
            case 'due_today':
                $title = 'Installment due today';
                $msg   = sprintf('Installment #%d of TZS %s for %s is due today. Pay now to keep your plan on track.', $r['installment_number'], $amount, $r['product_name']);
                break;
            default:
                $title = 'Installment overdue';
                $msg   = sprintf('Installment #%d of TZS %s for %s was due on %s and is now overdue. Please pay as soon as possible.', $r['installment_number'], $amount, $r['product_name'], $due);
        }
        add_notification((int) $r['customer_id'], 'reminder', $title, $msg);

        // Respect the customer's own preference (settings page) for email reminders
        $prefs = user_settings((int) $r['customer_id']);
        if ($emailOn && ($prefs['email_receipts'] ?? true) && !empty($r['email'])) {
            send_mail(
                (string) $r['email'],
                cfg('app_name') . ': ' . $title,
                sprintf("Hello %s,\n\n%s\n\nSeller: %s\nPay from your dashboard: %s/dashboard.html\n\n— %s",
                    $r['full_name'], $msg, $r['seller_name'] ?: 'Seller', app_base_url(), cfg('app_name')),
                'reminder_' . $kind, 'installment_payment', (string) $r['id']
            );
        }
        db_execute('INSERT IGNORE INTO installment_reminders (payment_id, kind) VALUES (?, ?)', 'is', [(int) $r['id'], $kind]);
        $sent++;
    }
    return $sent;
}

/** Decoded users.settings_json for a user (empty array when unset). */
function user_settings(int $userId): array
{
    if (!column_exists('users', 'settings_json')) {
        return [];
    }
    $row = db_select('SELECT settings_json FROM users WHERE id = ? LIMIT 1', 'i', [$userId])[0] ?? null;
    $decoded = $row && !empty($row['settings_json']) ? json_decode((string) $row['settings_json'], true) : null;
    return is_array($decoded) ? $decoded : [];
}
