<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_users');

$id = get_int('id');
$user = db_select('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$id])[0] ?? null;
if ($user === null) {
    flash('error', 'User not found.');
    redirect('users.php');
}
$self = 'user.php?id=' . $id;

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_users')) {
        flash('error', 'Your role cannot modify user accounts.');
        redirect($self);
    }
    $action = post_str('action', 30);

    if ($action === 'update_profile') {
        $name = post_str('full_name', 120);
        $email = strtolower(post_str('email', 190));
        $phone = normalize_phone(post_str('phone', 30));
        $errors = [];
        if (mb_strlen($name) < 2) $errors[] = 'Name is too short.';
        if (!is_valid_email($email)) $errors[] = 'Email is not valid.';
        if (!is_valid_tz_phone($phone)) $errors[] = 'Phone must be a valid Tanzanian number.';
        if (empty($errors) && !empty(db_select('SELECT id FROM users WHERE (email = ? OR phone = ?) AND id <> ? LIMIT 1', 'ssi', [$email, $phone, $id]))) {
            $errors[] = 'Another account already uses that email or phone.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect($self);
        }
        $changes = [];
        foreach (['full_name' => $name, 'email' => $email, 'phone' => $phone] as $k => $v) {
            if ((string) $user[$k] !== $v) {
                $changes[$k] = ['from' => $user[$k], 'to' => $v];
            }
        }
        if (empty($changes)) {
            flash('warning', 'Nothing changed.');
            redirect($self);
        }
        db_execute('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?', 'sssi', [$name, $email, $phone, $id]);
        audit('user_profile_updated', 'user', $id, $name, ['changes' => $changes]);
        flash('success', 'User details updated.');
        redirect($self);
    }

    if ($action === 'set_status') {
        $new = post_str('status', 20);
        $reason = post_str('reason', 255);
        if (!in_array($new, ['active', 'suspended', 'deactivated'], true) || $new === $user['status']) {
            flash('error', 'Invalid status change.');
            redirect($self);
        }
        if ($new !== 'active' && mb_strlen($reason) < 5) {
            flash('error', 'Please give a reason (at least 5 characters) for suspending or deactivating an account.');
            redirect($self);
        }
        db_execute('UPDATE users SET status = ?, status_reason = ?, status_changed_at = NOW() WHERE id = ?', 'ssi', [$new, $reason !== '' ? $reason : null, $id]);
        $verb = ['active' => 'reactivated', 'suspended' => 'suspended', 'deactivated' => 'deactivated'][$new];
        audit('user_' . $verb, 'user', $id, $user['full_name'], ['from' => $user['status'], 'to' => $new, 'reason' => $reason]);
        if ($new === 'active') {
            add_notification($id, 'general', 'Account reactivated', 'Your account has been reactivated. Welcome back!');
        }
        flash('success', 'Account ' . $verb . '.' . ($new !== 'active' ? ' The user is signed out on their next request.' : ''));
        redirect($self);
    }
    flash('error', 'Unknown action.');
    redirect($self);
}

$stores = db_select('SELECT * FROM sellers WHERE user_id = ? ORDER BY created_at DESC', 'i', [$id]);
$state = agreement_state_sql();
$asCustomer = db_select("SELECT a.*, {$state} AS st, COALESCE(pl.amount_paid,0) paid, COALESCE(od.overdue_count,0) overdue_count" . agreement_from_sql() . ' WHERE a.customer_id = ? ORDER BY a.created_at DESC LIMIT 100', 'i', [$id]);
$sellerIds = array_map(static fn($s) => (int) $s['id'], $stores);
$sellerCond = 'LOWER(a.seller_email) = ?';
$types = 's';
$params = [strtolower((string) $user['email'])];
if ($sellerIds) {
    $sellerCond = '(' . $sellerCond . ' OR a.seller_id IN (' . implode(',', $sellerIds) . '))';
}
$asSeller = db_select("SELECT a.*, u.full_name AS customer_name, {$state} AS st" . agreement_from_sql() . " WHERE a.customer_id <> ? AND {$sellerCond} ORDER BY a.created_at DESC LIMIT 100", 'i' . $types, array_merge([$id], $params));
$v = tx_verification_sql();
$payments = db_select("SELECT t.*, {$v} AS verification FROM transactions t WHERE t.customer_id = ? ORDER BY t.created_at DESC LIMIT 100", 'i', [$id]);
$paidTotal = array_sum(array_map(static fn($p) => $p['status'] === 'completed' ? (float) $p['amount'] : 0, $payments));
$cases = db_select('SELECT * FROM admin_cases WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', 'i', [$id]);
$notifCount = (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', 'i', [$id]);
$history = db_select("SELECT * FROM admin_audit_log WHERE target_type = 'user' AND target_id = ? ORDER BY id DESC LIMIT 15", 's', [(string) $id]);

$pageTitle = $user['full_name'];
$pageSubtitle = 'User #' . $id . ' · registered ' . fmt_date($user['created_at'], true);
$activeNav = 'users';
$pageActions = '<a class="btn" href="users.php"><i class="fa-solid fa-arrow-left"></i> All users</a>'
    . (admin_can('manage_cases') ? ' <a class="btn" href="cases.php?new=1&amp;user_id=' . $id . '"><i class="fa-solid fa-flag"></i> Open case</a>' : '');
require __DIR__ . '/includes/header.php';
?>
<section class="grid-profile">
    <div class="card">
        <div class="profile-head">
            <span class="avatar avatar-lg"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
            <div>
                <h2><?= e($user['full_name']) ?></h2>
                <div><?= badge($user['status']) ?>
                    <?php if ($asCustomer): ?><span class="tag">Customer</span><?php endif; ?>
                    <?php if ($stores): ?><span class="tag tag-violet">Seller</span><?php endif; ?>
                </div>
            </div>
        </div>
        <dl class="dl">
            <dt>Email</dt><dd><?= e($user['email']) ?></dd>
            <dt>Phone</dt><dd><?= e($user['phone']) ?></dd>
            <dt>Registered</dt><dd><?= fmt_date($user['created_at'], true) ?></dd>
            <dt>Last login</dt><dd><?= fmt_date($user['last_login_at'] ?? null, true) ?></dd>
            <dt>Agreements</dt><dd><?= count($asCustomer) ?> as customer · <?= count($asSeller) ?> as seller</dd>
            <dt>Recorded payments</dt><dd><?= tzs($paidTotal) ?></dd>
            <dt>In-app notifications</dt><dd><?= number_format($notifCount) ?></dd>
            <?php if (!empty($user['status_reason'])): ?><dt>Status reason</dt><dd><?= e($user['status_reason']) ?> <span class="muted small">(<?= fmt_date($user['status_changed_at'] ?? null, true) ?>)</span></dd><?php endif; ?>
        </dl>
    </div>

    <?php if (admin_can('manage_users')): ?>
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3>Edit details</h3></div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="update_profile">
                <label class="field full">Full name<input name="full_name" required maxlength="120" value="<?= e($user['full_name']) ?>"></label>
                <label class="field">Email<input type="email" name="email" required maxlength="190" value="<?= e($user['email']) ?>"></label>
                <label class="field">Phone<input name="phone" required maxlength="30" value="<?= e($user['phone']) ?>"></label>
                <p class="small muted full">Passwords are never visible or editable here. Users reset their own password from the login page.</p>
                <button class="btn btn-primary" type="submit" data-confirm="Save changes to this user's details?">Save changes</button>
            </form>
        </div>
        <div class="card">
            <div class="card-head"><h3>Account status</h3></div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="set_status">
                <label class="field full">Reason <small>(required to suspend or deactivate; recorded in the audit log)</small><input name="reason" maxlength="255" placeholder="e.g. Reported fraudulent agreements"></label>
                <div class="btn-row full">
                    <?php if ($user['status'] !== 'active'): ?>
                        <button class="btn btn-success" name="status" value="active" data-confirm="Reactivate this account?"><i class="fa-solid fa-user-check"></i> Reactivate</button>
                    <?php endif; ?>
                    <?php if ($user['status'] !== 'suspended'): ?>
                        <button class="btn btn-warning" name="status" value="suspended" data-confirm="Suspend this account? The user will be signed out and blocked from logging in."><i class="fa-solid fa-user-lock"></i> Suspend</button>
                    <?php endif; ?>
                    <?php if ($user['status'] !== 'deactivated'): ?>
                        <button class="btn btn-danger" name="status" value="deactivated" data-confirm="Deactivate this account? Data is kept, but the user can no longer sign in."><i class="fa-solid fa-user-slash"></i> Deactivate</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if ($stores): ?>
<div class="card table-card">
    <div class="card-head"><h3>Stores owned</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Business</th><th>Contact</th><th>Category</th><th>Status</th><th>Registered</th></tr></thead>
        <tbody><?php foreach ($stores as $s): ?>
            <tr><td><a class="strong" href="seller.php?id=<?= (int) $s['id'] ?>"><?= e($s['business_name']) ?></a></td><td><?= e($s['email']) ?><div class="muted small"><?= e($s['phone']) ?></div></td><td><?= e($s['category'] ?: '—') ?></td><td><?= badge($s['status']) ?></td><td><?= fmt_date($s['created_at']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card table-card">
    <div class="card-head"><h3>Agreements as customer</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th>Product</th><th>Seller</th><th class="num">Total</th><th class="num">Paid</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($asCustomer as $a): ?>
            <tr><td><a class="strong" href="agreement.php?id=<?= (int) $a['id'] ?>"><?= e($a['agreement_code']) ?></a></td><td><?= e($a['product_name']) ?></td><td><?= e($a['seller_name'] ?: '—') ?></td>
                <td class="num"><?= tzs($a['total_price']) ?></td><td class="num"><?= tzs($a['paid']) ?></td>
                <td><?= badge($a['st']) ?><?= $a['overdue_count'] > 0 && $a['st'] === 'active' ? ' ' . badge('overdue') : '' ?></td><td><?= fmt_date($a['created_at']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$asCustomer): ?><tr><td colspan="7" class="empty">No agreements.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<?php if ($asSeller): ?>
<div class="card table-card">
    <div class="card-head"><h3>Agreements received as seller</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th>Product</th><th>Customer</th><th class="num">Total</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($asSeller as $a): ?>
            <tr><td><a class="strong" href="agreement.php?id=<?= (int) $a['id'] ?>"><?= e($a['agreement_code']) ?></a></td><td><?= e($a['product_name']) ?></td><td><?= e($a['customer_name']) ?></td><td class="num"><?= tzs($a['total_price']) ?></td><td><?= badge($a['st']) ?></td><td><?= fmt_date($a['created_at']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card table-card">
    <div class="card-head"><h3>Payment history</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Reference</th><th>Product</th><th class="num">Amount</th><th>Method</th><th>Status</th><th>Verification</th><th>Date</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
            <tr><td><a class="strong" href="payment.php?id=<?= (int) $p['id'] ?>"><?= e($p['transaction_code']) ?></a></td><td><?= e($p['product_name']) ?></td><td class="num"><?= tzs($p['amount']) ?></td><td><?= e($p['payment_method']) ?></td><td><?= badge($p['status']) ?></td><td><?= $p['verification'] ? badge($p['verification']) : '<span class="muted">—</span>' ?></td><td><?= fmt_date($p['created_at'], true) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="empty">No payments.</td></tr><?php endif; ?></tbody>
    </table></div>
</div>

<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Cases</h3></div>
        <ul class="list"><?php foreach ($cases as $c): ?>
            <li><div><a class="strong" href="case.php?id=<?= (int) $c['id'] ?>"><?= e($c['case_code']) ?></a> <?= e($c['subject']) ?></div><div><?= badge($c['status']) ?></div></li>
        <?php endforeach; ?><?php if (!$cases): ?><li class="muted">No cases.</li><?php endif; ?></ul>
    </div>
    <div class="card">
        <div class="card-head"><h3>Admin actions on this account</h3></div>
        <ul class="list"><?php foreach ($history as $h): ?>
            <li><div><strong><?= e(humanize($h['action'])) ?></strong><div class="muted small"><?= e($h['admin_name']) ?></div></div><div class="muted small"><?= fmt_date($h['created_at'], true) ?></div></li>
        <?php endforeach; ?><?php if (!$history): ?><li class="muted">No admin actions yet.</li><?php endif; ?></ul>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
