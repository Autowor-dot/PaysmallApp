<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_payments');

$q = get_str('q');
$status = get_str('status', 20);
$verification = get_str('verification', 20);
$method = get_str('method', 40);
$from = get_date('from');
$to = get_date('to');
$v = tx_verification_sql();

$tabs = ['' => 'All transactions', 'completed' => 'Successful', 'pending' => 'Pending', 'failed' => 'Failed', 'overdue' => 'Overdue installments'];
$csv = get_str('export') === 'csv' && admin_can('view_reports');

if ($status === 'overdue') {
    // Scheduled installments past due and unpaid (no transaction needed to be overdue)
    $fromSql = " FROM installment_payments ip
        JOIN installment_plans pl ON pl.id = ip.plan_id
        JOIN users u ON u.id = ip.customer_id
        LEFT JOIN agreements a ON a.id = pl.agreement_id ";
    $w = new Where();
    $w->add("ip.status <> 'paid' AND ip.due_date < CURDATE() AND pl.status IN ('active','overdue')");
    if ($q !== '') {
        $w->add('(a.agreement_code LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR a.seller_name LIKE ? OR pl.product_name LIKE ?)', 'sssss', array_fill(0, 5, like($q)));
    }
    if (get_int('customer_id') > 0) $w->add('ip.customer_id = ?', 'i', [get_int('customer_id')]);
    if ($from !== '') $w->add('ip.due_date >= ?', 's', [$from]);
    if ($to !== '') $w->add('ip.due_date <= ?', 's', [$to]);
    $select = 'SELECT ip.*, DATEDIFF(CURDATE(), ip.due_date) AS days_late, pl.product_name, a.id AS agreement_id, a.agreement_code, a.seller_name, a.seller_phone, u.id AS uid, u.full_name, u.phone';
    if ($csv) {
        $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY ip.due_date ASC', $w->types, $w->params);
        audit('export_overdue_csv', 'report', null, 'overdue.csv', ['filters' => $_GET, 'rows' => count($rows)]);
        send_csv('overdue-installments-' . date('Ymd') . '.csv', ['Agreement', 'Customer', 'Customer phone', 'Seller', 'Product', 'Installment #', 'Due date', 'Days late', 'Amount due (TZS)'],
            array_map(static fn($r) => [$r['agreement_code'], $r['full_name'], $r['phone'], $r['seller_name'], $r['product_name'], $r['installment_number'], $r['due_date'], $r['days_late'], $r['amount_due']], $rows));
    }
    $pg = paginate(count_rows($fromSql, $w));
    $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY ip.due_date ASC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
    $sum = scalar('SELECT COALESCE(SUM(ip.amount_due),0) ' . $fromSql . $w->sql(), $w->types, $w->params);
} else {
    $fromSql = " FROM transactions t
        JOIN users u ON u.id = t.customer_id
        LEFT JOIN installment_plans pl ON pl.id = t.plan_id
        LEFT JOIN agreements a ON a.id = pl.agreement_id ";
    $w = new Where();
    if (in_array($status, ['completed', 'pending', 'failed'], true)) {
        $w->add('t.status = ?', 's', [$status]);
    }
    if (in_array($verification, ['provider', 'simulator', 'unverified'], true)) {
        $w->add("{$v} = ?", 's', [$verification]);
    }
    if ($method !== '') $w->add('t.payment_method = ?', 's', [$method]);
    if (get_int('customer_id') > 0) $w->add('t.customer_id = ?', 'i', [get_int('customer_id')]);
    if ($q !== '') {
        $w->add('(t.transaction_code LIKE ? OR t.provider_reference LIKE ? OR a.agreement_code LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR t.payer_phone LIKE ? OR t.seller_name LIKE ?)', 'sssssss', array_fill(0, 7, like($q)));
    }
    if ($from !== '') $w->add('t.created_at >= ?', 's', [$from . ' 00:00:00']);
    if ($to !== '') $w->add('t.created_at <= ?', 's', [$to . ' 23:59:59']);
    $select = "SELECT t.*, {$v} AS verification, u.full_name, u.phone, a.id AS agreement_id, a.agreement_code, a.seller_name AS a_seller,
        (SELECT COUNT(*) FROM admin_cases c WHERE c.transaction_id = t.id AND c.status IN ('open','investigating')) AS open_cases";
    if ($csv) {
        $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY t.created_at DESC', $w->types, $w->params);
        audit('export_payments_csv', 'report', null, 'payments.csv', ['filters' => $_GET, 'rows' => count($rows)]);
        send_csv('payments-' . date('Ymd') . '.csv', ['Payment ref', 'Agreement', 'Customer', 'Customer phone', 'Seller', 'Product', 'Amount (TZS)', 'Method', 'Payer phone', 'Provider ref', 'Status', 'Verification', 'Created', 'Confirmed'],
            array_map(static fn($r) => [$r['transaction_code'], $r['agreement_code'], $r['full_name'], $r['phone'], $r['a_seller'] ?: $r['seller_name'], $r['product_name'], $r['amount'], $r['payment_method'], $r['payer_phone'], $r['provider_reference'], $r['status'], $r['verification'], $r['created_at'], $r['confirmed_at']], $rows));
    }
    $pg = paginate(count_rows($fromSql, $w));
    $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
    $sum = scalar('SELECT COALESCE(SUM(t.amount),0) ' . $fromSql . $w->sql(), $w->types, $w->params);
    $methods = db_select('SELECT DISTINCT payment_method FROM transactions ORDER BY payment_method');
}

$pageTitle = 'Payments';
$pageSubtitle = 'All installment payment activity. Recorded ≠ verified: only provider-confirmed transactions are independently verified.';
$activeNav = 'payments';
$pageActions = admin_can('view_reports') ? '<a class="btn" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<nav class="tabs">
    <?php foreach ($tabs as $k => $l): ?><a class="tab<?= $status === $k ? ' active' : '' ?><?= $k === 'overdue' ? ' tab-red' : '' ?>" href="payments.php<?= $k !== '' ? '?status=' . $k : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
</nav>

<form class="card filters" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Payment / agreement / provider ref, customer, phone"></label>
    <?php if ($status !== 'overdue'): ?>
    <label class="field">Verification
        <select name="verification"><option value="">Any</option>
            <option value="provider"<?= $verification === 'provider' ? ' selected' : '' ?>>Provider-verified</option>
            <option value="simulator"<?= $verification === 'simulator' ? ' selected' : '' ?>>Simulated (test)</option>
            <option value="unverified"<?= $verification === 'unverified' ? ' selected' : '' ?>>Recorded, unverified</option>
        </select>
    </label>
    <label class="field">Method
        <select name="method"><option value="">Any</option>
            <?php foreach ($methods as $m): ?><option value="<?= e($m['payment_method']) ?>"<?= $method === $m['payment_method'] ? ' selected' : '' ?>><?= e($m['payment_method']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>
    <label class="field"><?= $status === 'overdue' ? 'Due from' : 'From' ?><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button><a class="btn btn-ghost" href="payments.php<?= $status !== '' ? '?status=' . e($status) : '' ?>">Reset</a></div>
</form>

<p class="summary-line"><strong><?= number_format($pg['total']) ?></strong> <?= $status === 'overdue' ? 'overdue installments' : 'transactions' ?> · total <strong><?= tzs($sum) ?></strong></p>

<div class="card table-card">
    <div class="table-wrap">
        <?php if ($status === 'overdue'): ?>
        <table class="table">
            <thead><tr><th>Agreement</th><th>Customer</th><th>Seller</th><th>Product</th><th>#</th><th>Due date</th><th class="num">Days late</th><th class="num">Amount due</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['agreement_id'] ? '<a class="strong mono" href="agreement.php?id=' . (int) $r['agreement_id'] . '">' . e($r['agreement_code']) . '</a>' : '—' ?></td>
                    <td><a href="user.php?id=<?= (int) $r['uid'] ?>"><?= e($r['full_name']) ?></a><div class="muted small"><?= e($r['phone']) ?></div></td>
                    <td><?= e($r['seller_name'] ?: '—') ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= (int) $r['installment_number'] ?></td>
                    <td><?= fmt_date($r['due_date']) ?></td>
                    <td class="num text-red"><?= (int) $r['days_late'] ?></td>
                    <td class="num strong"><?= tzs($r['amount_due']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="empty">No overdue installments. 🎉</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Payment ref</th><th>Agreement</th><th>Customer</th><th>Seller</th><th class="num">Amount</th><th>Method</th><th>Provider ref</th><th>Status</th><th>Verification</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="strong mono" href="payment.php?id=<?= (int) $r['id'] ?>"><?= e($r['transaction_code']) ?></a><?= $r['open_cases'] > 0 ? ' <i class="fa-solid fa-scale-balanced text-amber" title="Open dispute"></i>' : '' ?></td>
                    <td><?= $r['agreement_id'] ? '<a class="mono small" href="agreement.php?id=' . (int) $r['agreement_id'] . '">' . e($r['agreement_code']) . '</a>' : '<span class="muted">—</span>' ?></td>
                    <td><a href="user.php?id=<?= (int) $r['customer_id'] ?>"><?= e($r['full_name']) ?></a><div class="muted small"><?= e($r['payer_phone'] ?: $r['phone']) ?></div></td>
                    <td><?= e($r['a_seller'] ?: ($r['seller_name'] ?: '—')) ?></td>
                    <td class="num strong"><?= tzs($r['amount']) ?></td>
                    <td><?= e($r['payment_method']) ?></td>
                    <td class="mono small"><?= e($r['provider_reference'] ?: '—') ?></td>
                    <td><?= badge($r['status'], $r['status'] === 'completed' ? 'Successful' : null) ?></td>
                    <td><?= $r['verification'] ? badge($r['verification']) : '<span class="muted">—</span>' ?></td>
                    <td class="small"><?= fmt_date($r['created_at'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="empty">No transactions match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
