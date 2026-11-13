<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_agreements');

$id = get_int('id');
$state = agreement_state_sql();
$a = db_select("SELECT a.*, u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone, u.status AS customer_status,
        s.business_name, s.status AS seller_status, s.user_id AS seller_user_id,
        pl.id AS plan_id, pl.status AS plan_status, pl.amount_paid, pl.total_price AS plan_total, pl.next_payment_amount, pl.next_payment_due_date,
        {$state} AS st, COALESCE(od.overdue_count,0) AS overdue_count, COALESCE(od.overdue_amount,0) AS overdue_amount"
    . agreement_from_sql() . ' WHERE a.id = ? LIMIT 1', 'i', [$id])[0] ?? null;
if ($a === null) {
    flash('error', 'Agreement not found.');
    redirect('agreements.php');
}
$self = 'agreement.php?id=' . $id;

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_agreements')) {
        flash('error', 'Your role cannot modify agreements.');
        redirect($self);
    }
    $action = post_str('action', 30);
    $reason = post_str('reason', 500);
    if (mb_strlen($reason) < 10) {
        flash('error', 'A reason of at least 10 characters is required for any change to an agreement. It is stored in the audit log.');
        redirect($self);
    }

    if ($action === 'cancel') {
        if (!in_array($a['st'], ['pending', 'active'], true)) {
            flash('error', 'Only pending or active agreements can be cancelled.');
            redirect($self);
        }
        $conn = db();
        $conn->begin_transaction();
        try {
            // Keep the seller's original response note; the admin reason lives in the audit log and customer notification
            db_execute("UPDATE agreements SET status = 'cancelled', response_note = COALESCE(response_note, ?), responded_at = COALESCE(responded_at, NOW()) WHERE id = ?", 'si',
                [mb_substr('Cancelled by administrator: ' . $reason, 0, 500), $id]);
            db_execute("UPDATE installment_plans SET status = 'cancelled' WHERE agreement_id = ?", 'i', [$id]);
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            throw $ex;
        }
        audit('agreement_cancelled', 'agreement', $id, $a['agreement_code'], [
            'from' => ['agreement_status' => $a['status'], 'plan_status' => $a['plan_status'], 'response_note' => $a['response_note']],
            'to' => ['agreement_status' => 'cancelled', 'plan_status' => 'cancelled'],
            'reason' => $reason, 'recorded_paid_at_cancellation' => (float) $a['amount_paid'],
        ]);
        $msg = sprintf('Agreement %s for "%s" was cancelled by %s support. Reason: %s', $a['agreement_code'], $a['product_name'], cfg('app_name'), $reason);
        add_notification((int) $a['customer_id'], 'agreement', 'Agreement cancelled', $msg);
        if (!empty($a['seller_user_id'])) {
            add_notification((int) $a['seller_user_id'], 'agreement', 'Agreement cancelled', $msg);
        }
        flash('success', 'Agreement cancelled. Recorded payments were not reversed — handle any refunds through a case.');
        redirect($self);
    }

    if ($action === 'correct') {
        // Only identification/contact details can be corrected. Financial terms are never edited.
        $fields = [
            'product_name' => post_str('product_name', 200),
            'seller_name'  => post_str('seller_name', 160),
            'seller_email' => strtolower(post_str('seller_email', 190)),
            'seller_phone' => normalize_phone(post_str('seller_phone', 30)),
        ];
        if ($fields['product_name'] === '') {
            flash('error', 'Product name cannot be empty.');
            redirect($self);
        }
        if ($fields['seller_email'] !== '' && !is_valid_email($fields['seller_email'])) {
            flash('error', 'Seller email is not valid.');
            redirect($self);
        }
        if ($fields['seller_phone'] !== '' && !is_valid_tz_phone($fields['seller_phone'])) {
            flash('error', 'Seller phone must be a valid Tanzanian number.');
            redirect($self);
        }
        $changes = [];
        foreach ($fields as $k => $v) {
            if ((string) ($a[$k] ?? '') !== $v) {
                $changes[$k] = ['from' => $a[$k], 'to' => $v];
            }
        }
        if (!$changes) {
            flash('warning', 'Nothing changed.');
            redirect($self);
        }
        db_execute('UPDATE agreements SET product_name = ?, seller_name = ?, seller_email = ?, seller_phone = ? WHERE id = ?', 'ssssi',
            [$fields['product_name'], $fields['seller_name'], $fields['seller_email'], $fields['seller_phone'], $id]);
        if (isset($changes['product_name']) || isset($changes['seller_name'])) {
            db_execute('UPDATE installment_plans SET product_name = ?, seller_name = ? WHERE agreement_id = ?', 'ssi',
                [$fields['product_name'], $fields['seller_name'] !== '' ? $fields['seller_name'] : 'Seller', $id]);
        }
        audit('agreement_details_corrected', 'agreement', $id, $a['agreement_code'], ['changes' => $changes, 'reason' => $reason, 'agreement_status' => $a['status']]);
        add_notification((int) $a['customer_id'], 'agreement', 'Agreement details corrected',
            sprintf('Support corrected details on agreement %s (%s). Reason: %s', $a['agreement_code'], implode(', ', array_map('humanize', array_keys($changes))), $reason));
        flash('success', 'Details corrected and recorded in the audit log.');
        redirect($self);
    }
    flash('error', 'Unknown action.');
    redirect($self);
}

$payload = json_decode((string) ($a['payload_json'] ?? ''), true) ?: [];
$pay = $payload['payment'] ?? [];
$product = $payload['product'] ?? [];
$delivery = $payload['delivery'] ?? [];
$terms = $payload['terms'] ?? [];

$schedule = $a['plan_id'] ? db_select('SELECT * FROM installment_payments WHERE plan_id = ? ORDER BY installment_number, due_date', 'i', [(int) $a['plan_id']]) : [];
$v = tx_verification_sql();
$txs = $a['plan_id'] ? db_select("SELECT t.*, {$v} AS verification FROM transactions t WHERE t.plan_id = ? ORDER BY t.created_at DESC", 'i', [(int) $a['plan_id']]) : [];
$recorded = 0.0; $verified = 0.0;
foreach ($txs as $t) {
    if ($t['status'] === 'completed') $recorded += (float) $t['amount'];
    if ($t['verification'] === 'provider') $verified += (float) $t['amount'];
}
$emails = db_select("SELECT * FROM email_log WHERE related_type = 'agreement' AND related_id = ? ORDER BY id DESC", 's', [(string) $id]);
$cases = db_select('SELECT * FROM admin_cases WHERE agreement_id = ? ORDER BY created_at DESC', 'i', [$id]);
$history = db_select("SELECT * FROM admin_audit_log WHERE target_type = 'agreement' AND target_id = ? ORDER BY id DESC", 's', [(string) $id]);
$flags = suspicious_reasons($id);
$today = date('Y-m-d');
$expected = (float) $a['total_price'];
$balance = max(0, (float) ($a['plan_total'] ?? $a['total_price']) - (float) ($a['amount_paid'] ?? 0));

$pageTitle = 'Agreement ' . $a['agreement_code'];
$pageSubtitle = $a['product_name'] . ' · created ' . fmt_date($a['created_at'], true);
$activeNav = 'agreements';
$pageActions = '<a class="btn" href="agreements.php"><i class="fa-solid fa-arrow-left"></i> All agreements</a>'
    . (admin_can('manage_cases') ? ' <a class="btn" href="cases.php?new=1&amp;agreement_id=' . $id . '&amp;type=' . ($flags ? 'suspicious_agreement' : 'complaint') . '"><i class="fa-solid fa-flag"></i> Open case</a>' : '');
require __DIR__ . '/includes/header.php';
?>
<?php if ($flags): ?>
<div class="alert alert-warning"><i class="fa-solid fa-user-secret"></i> <div><strong>Red flags:</strong> <?= e(implode(' · ', $flags)) ?>. Review the parties and open a case if needed.</div></div>
<?php endif; ?>

<section class="kpi-grid kpi-grid-5">
    <div class="kpi card"><div><div class="kpi-label">Status</div><div class="kpi-value sm"><?= badge($a['st']) ?><?= $a['overdue_count'] > 0 && $a['st'] === 'active' ? ' ' . badge('overdue') : '' ?></div><div class="kpi-sub">record: <?= e($a['status']) ?><?= $a['plan_status'] ? ' · plan: ' . e($a['plan_status']) : '' ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Expected (agreed total)</div><div class="kpi-value sm"><?= tzs($expected) ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Recorded payments</div><div class="kpi-value sm"><?= tzs($recorded) ?></div><div class="kpi-sub"><?= tzs($verified) ?> provider-verified</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Outstanding</div><div class="kpi-value sm"><?= in_array($a['st'], ['active', 'completed'], true) ? tzs($balance) : '—' ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Overdue</div><div class="kpi-value sm <?= $a['overdue_amount'] > 0 ? 'text-red' : '' ?>"><?= tzs($a['overdue_amount']) ?></div><div class="kpi-sub"><?= (int) $a['overdue_count'] ?> installment(s)</div></div></div>
</section>

<section class="grid-3">
    <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-user"></i> Customer</h3></div>
        <dl class="dl">
            <dt>Name</dt><dd><a href="user.php?id=<?= (int) $a['customer_id'] ?>"><?= e($a['customer_name']) ?></a> <?= $a['customer_status'] !== 'active' ? badge($a['customer_status']) : '' ?></dd>
            <dt>Email</dt><dd><?= e($a['customer_email']) ?></dd>
            <dt>Phone</dt><dd><?= e($a['customer_phone']) ?></dd>
            <dt>Confirmed terms</dt><dd><?= !empty($payload['customer_confirmed']) ? 'Yes, at submission' : 'Not recorded' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-store"></i> Seller</h3></div>
        <dl class="dl">
            <dt>Name</dt><dd><?= $a['seller_id'] ? '<a href="seller.php?id=' . (int) $a['seller_id'] . '">' . e($a['seller_name']) . '</a> ' . badge($a['seller_status']) : e($a['seller_name'] ?: '—') ?></dd>
            <dt>Type</dt><dd><?= $a['seller_type'] === 'registered' ? 'Registered seller' : 'Unregistered (email invitation)' ?></dd>
            <dt>Email</dt><dd><?= e($a['seller_email'] ?: '—') ?></dd>
            <dt>Phone</dt><dd><?= e($a['seller_phone'] ?: '—') ?></dd>
            <dt>Location</dt><dd><?= e($payload['seller']['location'] ?? '—') ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-handshake"></i> Acceptance</h3></div>
        <dl class="dl">
            <dt>Seller decision</dt><dd><?= $a['status'] === 'pending_seller_approval' ? badge('pending', 'Awaiting seller') : badge($a['status']) ?></dd>
            <dt>Responded</dt><dd><?= fmt_date($a['responded_at'], true) ?></dd>
            <dt>Note</dt><dd><?= e($a['response_note'] ?: '—') ?></dd>
            <dt>Emails</dt><dd><?= count($emails) ?> logged (<?= count(array_filter($emails, static fn($m) => $m['status'] === 'sent')) ?> sent)</dd>
        </dl>
    </div>
</section>

<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Terms</h3><span class="muted small">financial terms are read-only</span></div>
        <dl class="dl">
            <dt>Product / service</dt><dd><?= e($a['product_name']) ?> <span class="muted small">(<?= e(humanize($product['type'] ?? 'product')) ?><?= !empty($product['category']) ? ' · ' . e($product['category']) : '' ?>)</span></dd>
            <?php if (!empty($product['description'])): ?><dt>Description</dt><dd><?= e($product['description']) ?></dd><?php endif; ?>
            <dt>Quantity / condition</dt><dd><?= e($product['quantity'] ?? 1) ?> · <?= e($product['condition'] ?? '—') ?></dd>
            <dt>Total agreed amount</dt><dd><strong><?= tzs($a['total_price']) ?></strong></dd>
            <dt>Initial deposit</dt><dd><?= tzs($a['initial_deposit']) ?></dd>
            <dt>Installments</dt><dd><?= (int) $a['number_of_installments'] ?> × <?= isset($pay['installment_amount']) ? tzs($pay['installment_amount']) : '—' ?> (<?= e($a['payment_frequency']) ?>)</dd>
            <dt>Total payable</dt><dd><?= isset($pay['total_amount_payable']) ? tzs($pay['total_amount_payable']) : '—' ?></dd>
            <dt>Payment method</dt><dd><?= e($pay['preferred_payment_method'] ?? '—') ?></dd>
            <dt>Start (first payment)</dt><dd><?= fmt_date($pay['first_payment_date'] ?? null) ?></dd>
            <dt>Expected completion</dt><dd><?= fmt_date($pay['expected_final_payment_date'] ?? null) ?></dd>
            <?php if (!empty($pay['payment_instructions'])): ?><dt>Instructions</dt><dd><?= e($pay['payment_instructions']) ?></dd><?php endif; ?>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3>Delivery &amp; conditions</h3></div>
        <dl class="dl">
            <dt>Release condition</dt><dd><?= e(humanize($delivery['release_condition'] ?? '—')) ?><?= !empty($delivery['required_threshold']) ? ' (' . e($delivery['required_threshold']) . ')' : '' ?></dd>
            <dt>Delivery / service date</dt><dd><?= fmt_date($delivery['expected_delivery_date'] ?? $delivery['service_commencement_date'] ?? null) ?></dd>
            <dt>Location</dt><dd><?= e($delivery['location'] ?? '—') ?></dd>
            <dt>Charges</dt><dd><?= isset($delivery['charges']) ? tzs($delivery['charges']) . ' (' . e($delivery['charges_paid_by'] ?? '') . ')' : '—' ?></dd>
            <dt>Grace period</dt><dd><?= e($terms['grace_period_days'] ?? 0) ?> day(s)</dd>
            <?php foreach (['late_payment_policy' => 'Late payment', 'cancellation_policy' => 'Cancellation', 'refund_policy' => 'Refunds', 'dispute_resolution_conditions' => 'Disputes', 'additional_charges_or_interest' => 'Extra charges'] as $k => $l): if (!empty($terms[$k])): ?>
                <dt><?= e($l) ?></dt><dd><?= e($terms[$k]) ?></dd>
            <?php endif; endforeach; ?>
        </dl>
    </div>
</section>

<div class="card table-card">
    <div class="card-head"><h3>Installment schedule</h3><span class="muted small"><?= count($schedule) ?> installments</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>#</th><th>Due date</th><th class="num">Amount due</th><th>Status</th><th>Paid at</th></tr></thead>
        <tbody><?php foreach ($schedule as $s):
            $late = $s['status'] !== 'paid' && $s['due_date'] < $today && in_array($a['st'], ['active'], true); ?>
            <tr class="<?= $late ? 'row-red' : '' ?>"><td><?= (int) $s['installment_number'] ?></td><td><?= fmt_date($s['due_date']) ?></td><td class="num"><?= tzs($s['amount_due']) ?></td>
                <td><?= $late ? badge('overdue', 'Overdue · ' . (int) ((time() - strtotime($s['due_date'])) / 86400) . 'd') : badge($s['status']) ?></td><td><?= fmt_date($s['paid_at'], true) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$schedule): ?><tr><td colspan="5" class="empty">No schedule stored for this agreement.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<div class="card table-card">
    <div class="card-head"><h3>Payments on this agreement</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th class="num">Amount</th><th>Method</th><th>Provider ref</th><th>Status</th><th>Verification</th><th>Date</th></tr></thead>
        <tbody><?php foreach ($txs as $t): ?>
            <tr><td><a class="strong mono" href="payment.php?id=<?= (int) $t['id'] ?>"><?= e($t['transaction_code']) ?></a></td><td class="num"><?= tzs($t['amount']) ?></td><td><?= e($t['payment_method']) ?></td><td class="mono small"><?= e($t['provider_reference'] ?: '—') ?></td><td><?= badge($t['status']) ?></td><td><?= $t['verification'] ? badge($t['verification']) : '—' ?></td><td><?= fmt_date($t['created_at'], true) ?></td></tr>
        <?php endforeach; ?><?php if (!$txs): ?><tr><td colspan="7" class="empty">No payments recorded.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<?php if (admin_can('manage_agreements')): ?>
<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Correct details</h3></div>
        <p class="small muted">Only names and contact details can be corrected (for example a mistyped seller email). Amounts, deposit, installments and dates are never edited after submission. Every correction is recorded with before/after values and your reason, and the customer is notified.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="correct">
            <label class="field full">Product / service name<input name="product_name" maxlength="200" required value="<?= e($a['product_name']) ?>"></label>
            <label class="field">Seller name<input name="seller_name" maxlength="160" value="<?= e($a['seller_name']) ?>"></label>
            <label class="field">Seller email<input type="email" name="seller_email" maxlength="190" value="<?= e($a['seller_email']) ?>"></label>
            <label class="field">Seller phone<input name="seller_phone" maxlength="30" value="<?= e($a['seller_phone']) ?>"></label>
            <label class="field full">Reason for correction <small>(required)</small><textarea name="reason" rows="2" minlength="10" maxlength="500" required></textarea></label>
            <button class="btn btn-primary" type="submit" data-confirm="Apply this correction? It will be recorded in the audit log and the customer will be notified.">Save correction</button>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><h3>Cancel agreement</h3></div>
        <?php if (in_array($a['st'], ['pending', 'active'], true)): ?>
            <p class="small muted">Cancelling stops the installment plan. Payments already recorded (<?= tzs($recorded) ?>) are <strong>not</strong> reversed; arrange refunds separately and track them in a case.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <label class="field full">Reason <small>(required, shared with the customer)</small><textarea name="reason" rows="3" minlength="10" maxlength="500" required></textarea></label>
                <button class="btn btn-danger" type="submit" data-confirm="Cancel agreement <?= e($a['agreement_code']) ?>? This cannot be undone from the admin panel."><i class="fa-solid fa-ban"></i> Cancel agreement</button>
            </form>
        <?php else: ?>
            <p class="muted">This agreement is <?= e($a['st']) ?> and cannot be cancelled.</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="grid-3">
    <div class="card">
        <div class="card-head"><h3>Cases</h3></div>
        <ul class="list"><?php foreach ($cases as $c): ?>
            <li><div><a class="strong" href="case.php?id=<?= (int) $c['id'] ?>"><?= e($c['case_code']) ?></a><div class="small"><?= e($c['subject']) ?></div></div><div><?= badge($c['status']) ?></div></li>
        <?php endforeach; ?><?php if (!$cases): ?><li class="muted">No cases.</li><?php endif; ?></ul>
    </div>
    <div class="card">
        <div class="card-head"><h3>Emails</h3></div>
        <ul class="list"><?php foreach ($emails as $m): ?>
            <li><div><strong><?= e(humanize($m['context'])) ?></strong><div class="muted small"><?= e($m['recipient']) ?></div></div><div class="right"><?= badge($m['status']) ?><div class="muted small"><?= fmt_date($m['created_at'], true) ?></div></div></li>
        <?php endforeach; ?><?php if (!$emails): ?><li class="muted">No emails logged (logging starts after the admin migration).</li><?php endif; ?></ul>
    </div>
    <div class="card">
        <div class="card-head"><h3>Audit trail</h3></div>
        <ul class="list"><?php foreach ($history as $h): $d = json_decode((string) $h['details'], true) ?: []; ?>
            <li><div><strong><?= e(humanize($h['action'])) ?></strong><div class="muted small"><?= e($h['admin_name']) ?><?= !empty($d['reason']) ? ' — “' . e($d['reason']) . '”' : '' ?></div></div><div class="muted small"><?= fmt_date($h['created_at'], true) ?></div></li>
        <?php endforeach; ?><?php if (!$history): ?><li class="muted">No admin changes. Terms are as submitted.</li><?php endif; ?></ul>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
