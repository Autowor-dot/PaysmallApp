<?php
/**
 * Payment detail & investigation.
 * Administrators can NEVER mark a payment successful here: success only comes
 * from the payment provider webhook (or the development simulator, labelled as such).
 * Allowed actions: mark a stale PENDING request as failed/expired, add investigation notes via cases.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../api/payments-lib.php';
$admin = require_admin('view_payments');

$id = get_int('id');
$v = tx_verification_sql();
$t = db_select("SELECT t.*, {$v} AS verification, u.full_name, u.email, u.phone AS customer_phone,
        a.id AS agreement_id, a.agreement_code, a.seller_name AS a_seller, a.seller_email, a.seller_id,
        ip.installment_number, ip.due_date, ip.amount_due, ip.status AS installment_status
    FROM transactions t JOIN users u ON u.id = t.customer_id
    LEFT JOIN installment_plans pl ON pl.id = t.plan_id
    LEFT JOIN agreements a ON a.id = pl.agreement_id
    LEFT JOIN installment_payments ip ON ip.id = t.payment_id
    WHERE t.id = ? LIMIT 1", 'i', [$id])[0] ?? null;
if ($t === null) {
    flash('error', 'Payment not found.');
    redirect('payments.php');
}
$self = 'payment.php?id=' . $id;

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_payments')) {
        flash('error', 'Your role cannot change payments.');
        redirect($self);
    }
    $action = post_str('action', 30);
    $reason = post_str('reason', 500);
    if ($action === 'expire') {
        if ($t['status'] !== 'pending') {
            flash('error', 'Only pending payment requests can be marked as failed.');
            redirect($self);
        }
        if (mb_strlen($reason) < 10) {
            flash('error', 'Please give a reason (at least 10 characters).');
            redirect($self);
        }
        $raw = db_select('SELECT * FROM transactions WHERE id = ? LIMIT 1', 'i', [$id])[0];
        apply_payment_result($raw, 'failed');
        audit('payment_marked_failed', 'transaction', $id, $t['transaction_code'], ['from' => 'pending', 'to' => 'failed', 'amount' => (float) $t['amount'], 'reason' => $reason]);
        flash('success', 'Payment request marked as failed. The customer has been notified and can pay again.');
        redirect($self);
    }
    flash('error', 'Unknown action.');
    redirect($self);
}

$cases = db_select('SELECT * FROM admin_cases WHERE transaction_id = ? ORDER BY created_at DESC', 'i', [$id]);
$emails = db_select("SELECT * FROM email_log WHERE related_type = 'transaction' AND related_id = ? ORDER BY id DESC", 's', [(string) $id]);
$history = db_select("SELECT * FROM admin_audit_log WHERE target_type = 'transaction' AND target_id = ? ORDER BY id DESC", 's', [(string) $id]);
$ageHours = (time() - strtotime((string) $t['created_at'])) / 3600;

$verText = [
    'provider'   => ['Provider-verified', 'Confirmed by the payment provider through the signed webhook. This is an independently verified transaction.', 'green'],
    'simulator'  => ['Simulated (test only)', 'Confirmed with the development simulator. No real money moved — do not treat as revenue.', 'amber'],
    'unverified' => ['Recorded, not verified', 'Marked completed in the database without a provider confirmation. Treat as unverified until reconciled with the provider statement.', 'amber'],
][$t['verification'] ?? ''] ?? ['Not completed', 'This transaction has not been confirmed.', 'grey'];

$pageTitle = 'Payment ' . $t['transaction_code'];
$pageSubtitle = tzs($t['amount']) . ' · ' . $t['payment_method'] . ' · ' . fmt_date($t['created_at'], true);
$activeNav = 'payments';
$pageActions = '<a class="btn" href="payments.php"><i class="fa-solid fa-arrow-left"></i> All payments</a>'
    . (admin_can('manage_cases') ? ' <a class="btn btn-warning" href="cases.php?new=1&amp;type=payment_dispute&amp;transaction_id=' . $id . '"><i class="fa-solid fa-scale-balanced"></i> Open dispute</a>' : '');
require __DIR__ . '/includes/header.php';
?>
<div class="verify-banner tone-<?= $verText[2] ?>">
    <i class="fa-solid <?= $t['verification'] === 'provider' ? 'fa-shield-halved' : 'fa-circle-info' ?>"></i>
    <div><strong><?= e($verText[0]) ?></strong><p><?= e($verText[1]) ?></p></div>
</div>

<section class="grid-3">
    <div class="card">
        <div class="card-head"><h3>Transaction</h3></div>
        <dl class="dl">
            <dt>Payment reference</dt><dd class="mono"><?= e($t['transaction_code']) ?></dd>
            <dt>Amount</dt><dd><strong><?= tzs($t['amount']) ?></strong></dd>
            <dt>Status</dt><dd><?= badge($t['status'], $t['status'] === 'completed' ? 'Successful' : null) ?></dd>
            <dt>Method</dt><dd><?= e($t['payment_method']) ?></dd>
            <dt>Payer phone</dt><dd><?= e($t['payer_phone'] ?: '—') ?></dd>
            <dt>Provider reference</dt><dd class="mono"><?= e($t['provider_reference'] ?: '—') ?></dd>
            <dt>Initiated</dt><dd><?= fmt_date($t['created_at'], true) ?></dd>
            <dt>Confirmed</dt><dd><?= fmt_date($t['confirmed_at'], true) ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3>Agreement &amp; installment</h3></div>
        <dl class="dl">
            <dt>Agreement</dt><dd><?= $t['agreement_id'] ? '<a class="mono" href="agreement.php?id=' . (int) $t['agreement_id'] . '">' . e($t['agreement_code']) . '</a>' : '—' ?></dd>
            <dt>Product</dt><dd><?= e($t['product_name']) ?></dd>
            <dt>Installment</dt><dd><?= $t['installment_number'] ? '#' . (int) $t['installment_number'] . ' due ' . fmt_date($t['due_date']) : '—' ?></dd>
            <dt>Installment status</dt><dd><?= $t['installment_status'] ? badge($t['installment_status']) : '—' ?></dd>
            <dt>Remaining on installment</dt><dd><?= $t['amount_due'] !== null ? tzs($t['amount_due']) : '—' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3>Parties</h3></div>
        <dl class="dl">
            <dt>Customer</dt><dd><a href="user.php?id=<?= (int) $t['customer_id'] ?>"><?= e($t['full_name']) ?></a></dd>
            <dt>Customer contact</dt><dd><?= e($t['email']) ?><br><?= e($t['customer_phone']) ?></dd>
            <dt>Seller</dt><dd><?= $t['seller_id'] ? '<a href="seller.php?id=' . (int) $t['seller_id'] . '">' . e($t['a_seller']) . '</a>' : e($t['a_seller'] ?: ($t['seller_name'] ?: '—')) ?></dd>
            <dt>Seller email</dt><dd><?= e($t['seller_email'] ?: '—') ?></dd>
        </dl>
    </div>
</section>

<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Investigate</h3></div>
        <p class="small muted">Payments can only be marked successful by the payment provider. Administrators cannot create or confirm payments. For disputes (e.g. “I paid but it shows pending”), open a dispute case, compare the provider statement and record findings there.</p>
        <?php if ($t['status'] === 'pending' && admin_can('manage_payments')): ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="expire">
                <p class="full small">This request has been pending for <strong><?= $ageHours < 48 ? round($ageHours, 1) . ' hours' : round($ageHours / 24) . ' days' ?></strong>. If the provider confirms no money was received, mark it failed so the customer can retry.</p>
                <label class="field full">Reason <small>(required)</small><input name="reason" minlength="10" maxlength="500" required placeholder="e.g. No matching M-Pesa record after 48h"></label>
                <button class="btn btn-danger" type="submit" data-confirm="Mark this pending payment as FAILED? Only do this if the provider confirms no money was received."><i class="fa-solid fa-circle-xmark"></i> Mark as failed / expired</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Dispute cases</h3></div>
        <ul class="list"><?php foreach ($cases as $c): ?>
            <li><div><a class="strong" href="case.php?id=<?= (int) $c['id'] ?>"><?= e($c['case_code']) ?></a><div class="small"><?= e($c['subject']) ?></div></div><div><?= badge($c['status']) ?></div></li>
        <?php endforeach; ?><?php if (!$cases): ?><li class="muted">No disputes on this payment.</li><?php endif; ?></ul>
        <div class="card-head"><h3>Receipts &amp; audit</h3></div>
        <ul class="list">
            <?php foreach ($emails as $m): ?><li><div><?= e(humanize($m['context'])) ?><div class="muted small"><?= e($m['recipient']) ?></div></div><div><?= badge($m['status']) ?></div></li><?php endforeach; ?>
            <?php foreach ($history as $h): ?><li><div><strong><?= e(humanize($h['action'])) ?></strong><div class="muted small"><?= e($h['admin_name']) ?></div></div><div class="muted small"><?= fmt_date($h['created_at'], true) ?></div></li><?php endforeach; ?>
            <?php if (!$emails && !$history): ?><li class="muted">Nothing logged yet.</li><?php endif; ?>
        </ul>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
