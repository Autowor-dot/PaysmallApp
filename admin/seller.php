<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_sellers');

$id = get_int('id');
$seller = db_select('SELECT s.*, u.full_name AS owner_name, u.email AS owner_email, u.status AS owner_status FROM sellers s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1', 'i', [$id])[0] ?? null;
if ($seller === null) {
    flash('error', 'Seller not found.');
    redirect('sellers.php');
}
$self = 'seller.php?id=' . $id;

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_sellers')) {
        flash('error', 'Your role cannot modify sellers.');
        redirect($self);
    }
    $new = post_str('status', 20);
    $reason = post_str('reason', 255);
    if (post_str('action', 20) !== 'set_status' || !in_array($new, ['pending', 'verified', 'suspended'], true) || $new === $seller['status']) {
        flash('error', 'Invalid status change.');
        redirect($self);
    }
    if ($new === 'suspended' && mb_strlen($reason) < 5) {
        flash('error', 'Please give a reason (at least 5 characters) for suspending a seller.');
        redirect($self);
    }
    db_execute('UPDATE sellers SET status = ?, status_reason = ? WHERE id = ?', 'ssi', [$new, $reason !== '' ? $reason : null, $id]);
    $verb = ['verified' => 'verified', 'suspended' => 'suspended', 'pending' => 'reactivated (pending)'][$new];
    if ($seller['status'] === 'suspended' && $new === 'verified') {
        $verb = 'reactivated';
    }
    audit('seller_' . str_replace([' ', '(', ')'], ['_', '', ''], $verb), 'seller', $id, $seller['business_name'], ['from' => $seller['status'], 'to' => $new, 'reason' => $reason]);
    if (!empty($seller['user_id'])) {
        $msg = match ($new) {
            'verified'  => 'Your store "' . $seller['business_name'] . '" has been verified.',
            'suspended' => 'Your store "' . $seller['business_name'] . '" has been suspended. Customers cannot select it for new agreements. Contact support for help.',
            default     => 'Your store "' . $seller['business_name'] . '" is pending verification again.',
        };
        add_notification((int) $seller['user_id'], 'general', 'Store status updated', $msg);
    }
    flash('success', 'Seller ' . $verb . '.');
    redirect($self);
}

$match = '(a.seller_id = ? OR (a.seller_id IS NULL AND LOWER(a.seller_email) = LOWER(?)))';
$state = agreement_state_sql();
$agreements = db_select("SELECT a.*, u.full_name AS customer_name, {$state} AS st, COALESCE(pl.amount_paid,0) paid, COALESCE(od.overdue_count,0) overdue_count, COALESCE(od.overdue_amount,0) overdue_amount"
    . agreement_from_sql() . " WHERE {$match} ORDER BY a.created_at DESC LIMIT 200", 'is', [$id, $seller['email']]);
$stats = ['total' => count($agreements), 'active' => 0, 'completed' => 0, 'pending' => 0, 'value' => 0.0, 'paid' => 0.0, 'overdue' => 0.0];
foreach ($agreements as $a) {
    if (isset($stats[$a['st']])) $stats[$a['st']]++;
    if ($a['status'] === 'accepted') {
        $stats['value'] += (float) $a['total_price'];
        $stats['overdue'] += (float) $a['overdue_amount'];
    }
}
$v = tx_verification_sql();
$payments = db_select("SELECT t.*, {$v} AS verification, a.agreement_code, a.id AS agreement_id, u.full_name AS customer_name
    FROM transactions t JOIN installment_plans pl ON pl.id = t.plan_id JOIN agreements a ON a.id = pl.agreement_id JOIN users u ON u.id = t.customer_id
    WHERE {$match} ORDER BY t.created_at DESC LIMIT 100", 'is', [$id, $seller['email']]);
foreach ($payments as $p) {
    if ($p['status'] === 'completed') $stats['paid'] += (float) $p['amount'];
}
$products = db_select('SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC LIMIT 50', 'i', [$id]);
$history = db_select("SELECT * FROM admin_audit_log WHERE target_type = 'seller' AND target_id = ? ORDER BY id DESC LIMIT 15", 's', [(string) $id]);

$pageTitle = $seller['business_name'];
$pageSubtitle = 'Seller #' . $id . ' · registered ' . fmt_date($seller['created_at'], true);
$activeNav = 'sellers';
$pageActions = '<a class="btn" href="sellers.php"><i class="fa-solid fa-arrow-left"></i> All sellers</a>'
    . (admin_can('manage_cases') ? ' <a class="btn" href="cases.php?new=1&amp;seller_id=' . $id . '"><i class="fa-solid fa-flag"></i> Open case</a>' : '');
require __DIR__ . '/includes/header.php';
?>
<section class="kpi-grid kpi-grid-5">
    <div class="kpi card"><div><div class="kpi-label">Agreements</div><div class="kpi-value"><?= number_format($stats['total']) ?></div><div class="kpi-sub"><?= $stats['pending'] ?> pending</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Active / completed</div><div class="kpi-value"><?= $stats['active'] ?> / <?= $stats['completed'] ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Accepted value (expected)</div><div class="kpi-value sm"><?= tzs($stats['value']) ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Recorded payments</div><div class="kpi-value sm"><?= tzs($stats['paid']) ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Overdue</div><div class="kpi-value sm text-red"><?= tzs($stats['overdue']) ?></div></div></div>
</section>

<section class="grid-profile">
    <div class="card">
        <div class="profile-head">
            <span class="avatar avatar-lg tone-violet"><i class="fa-solid fa-store"></i></span>
            <div><h2><?= e($seller['business_name']) ?></h2><div><?= badge($seller['status']) ?></div></div>
        </div>
        <dl class="dl">
            <dt>Owner</dt><dd><?= e($seller['full_name']) ?></dd>
            <dt>Business email</dt><dd><?= e($seller['email']) ?></dd>
            <dt>Business phone</dt><dd><?= e($seller['phone']) ?></dd>
            <dt>Location</dt><dd><?= e($seller['location'] ?: '—') ?></dd>
            <dt>Category</dt><dd><?= e($seller['category'] ?: '—') ?></dd>
            <dt>Payout method</dt><dd><?= e(humanize($seller['payout_method'])) ?></dd>
            <dt>Linked user account</dt><dd><?= $seller['user_id'] ? '<a href="user.php?id=' . (int) $seller['user_id'] . '">' . e($seller['owner_name']) . '</a> ' . badge($seller['owner_status'] ?? 'active') : '<span class="muted">Not linked</span>' ?></dd>
            <?php if (!empty($seller['status_reason'])): ?><dt>Status reason</dt><dd><?= e($seller['status_reason']) ?></dd><?php endif; ?>
        </dl>
    </div>
    <?php if (admin_can('manage_sellers')): ?>
    <div class="card">
        <div class="card-head"><h3>Seller status</h3></div>
        <p class="small muted">Suspended sellers are hidden from the customer seller search, so no new agreements can be addressed to them. Existing agreements are not changed.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_status">
            <label class="field full">Reason <small>(required to suspend)</small><input name="reason" maxlength="255"></label>
            <div class="btn-row full">
                <?php if ($seller['status'] !== 'verified'): ?><button class="btn btn-success" name="status" value="verified" data-confirm="Mark this seller as verified?"><i class="fa-solid fa-circle-check"></i> <?= $seller['status'] === 'suspended' ? 'Reactivate & verify' : 'Verify seller' ?></button><?php endif; ?>
                <?php if ($seller['status'] === 'suspended'): ?><button class="btn" name="status" value="pending" data-confirm="Reactivate as pending verification?">Reactivate as pending</button><?php endif; ?>
                <?php if ($seller['status'] !== 'suspended'): ?><button class="btn btn-danger" name="status" value="suspended" data-confirm="Suspend this seller?"><i class="fa-solid fa-ban"></i> Suspend</button><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>
</section>

<div class="card table-card">
    <div class="card-head"><h3>Agreements</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th>Customer</th><th>Product</th><th class="num">Total</th><th class="num">Paid</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($agreements as $a): ?>
            <tr><td><a class="strong" href="agreement.php?id=<?= (int) $a['id'] ?>"><?= e($a['agreement_code']) ?></a></td><td><a href="user.php?id=<?= (int) $a['customer_id'] ?>"><?= e($a['customer_name']) ?></a></td><td><?= e($a['product_name']) ?></td><td class="num"><?= tzs($a['total_price']) ?></td><td class="num"><?= tzs($a['paid']) ?></td>
                <td><?= badge($a['st']) ?><?= $a['overdue_count'] > 0 && $a['st'] === 'active' ? ' ' . badge('overdue') : '' ?></td><td><?= fmt_date($a['created_at']) ?></td></tr>
        <?php endforeach; ?><?php if (!$agreements): ?><tr><td colspan="7" class="empty">No agreements.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<div class="card table-card">
    <div class="card-head"><h3>Payments received</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th>Agreement</th><th>Customer</th><th class="num">Amount</th><th>Status</th><th>Verification</th><th>Date</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
            <tr><td><a class="strong" href="payment.php?id=<?= (int) $p['id'] ?>"><?= e($p['transaction_code']) ?></a></td><td><a href="agreement.php?id=<?= (int) $p['agreement_id'] ?>"><?= e($p['agreement_code']) ?></a></td><td><?= e($p['customer_name']) ?></td><td class="num"><?= tzs($p['amount']) ?></td><td><?= badge($p['status']) ?></td><td><?= $p['verification'] ? badge($p['verification']) : '—' ?></td><td><?= fmt_date($p['created_at'], true) ?></td></tr>
        <?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="7" class="empty">No payments.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<section class="grid-2">
    <div class="card table-card">
        <div class="card-head"><h3>Products listed</h3></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Product</th><th class="num">Price</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($products as $pr): ?>
                <tr><td><?= e($pr['name']) ?><div class="muted small"><?= e($pr['category']) ?></div></td><td class="num"><?= tzs($pr['price']) ?></td><td><?= badge($pr['status']) ?></td></tr>
            <?php endforeach; ?><?php if (!$products): ?><tr><td colspan="3" class="empty">No marketplace products linked to this seller.</td></tr><?php endif; ?></tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="card-head"><h3>Admin actions on this seller</h3></div>
        <ul class="list"><?php foreach ($history as $h): ?>
            <li><div><strong><?= e(humanize($h['action'])) ?></strong><div class="muted small"><?= e($h['admin_name']) ?></div></div><div class="muted small"><?= fmt_date($h['created_at'], true) ?></div></li>
        <?php endforeach; ?><?php if (!$history): ?><li class="muted">No admin actions yet.</li><?php endif; ?></ul>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
