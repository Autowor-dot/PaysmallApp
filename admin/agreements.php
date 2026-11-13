<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_agreements');

$q = get_str('q');
$stateFilter = get_str('state', 20);
$flag = get_str('flag', 20);
$freq = get_str('frequency', 30);
$from = get_date('from');
$to = get_date('to');
$sellerId = get_int('seller_id');
$customerId = get_int('customer_id');

$state = agreement_state_sql();
$fromSql = agreement_from_sql();
$w = new Where();
if ($q !== '') {
    $w->add('(a.agreement_code LIKE ? OR a.product_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR a.seller_name LIKE ? OR a.seller_email LIKE ?)', 'ssssss',
        array_fill(0, 6, like($q)));
}
if ($stateFilter === 'overdue') {
    $w->add(agreement_overdue_sql());
} elseif ($stateFilter === 'cancelled') {
    $w->add("{$state} IN ('cancelled','declined')");
} elseif (in_array($stateFilter, ['pending', 'active', 'completed', 'declined'], true)) {
    $w->add("{$state} = ?", 's', [$stateFilter]);
}
if ($flag === 'suspicious') {
    $w->add(suspicious_sql());
}
if ($freq !== '') $w->add('a.payment_frequency = ?', 's', [$freq]);
if ($from !== '') $w->add('a.created_at >= ?', 's', [$from . ' 00:00:00']);
if ($to !== '') $w->add('a.created_at <= ?', 's', [$to . ' 23:59:59']);
if ($sellerId > 0) $w->add('a.seller_id = ?', 'i', [$sellerId]);
if ($customerId > 0) $w->add('a.customer_id = ?', 'i', [$customerId]);

$select = "SELECT a.id, a.agreement_code, a.customer_id, a.seller_id, a.seller_type, a.seller_name, a.seller_email, a.seller_phone, a.product_name,
    a.total_price, a.initial_deposit, a.number_of_installments, a.payment_frequency, a.status, a.created_at, a.responded_at,
    u.full_name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
    {$state} AS st, COALESCE(pl.amount_paid,0) AS paid, GREATEST(COALESCE(pl.total_price, a.total_price) - COALESCE(pl.amount_paid,0), 0) AS balance,
    COALESCE(od.overdue_count,0) AS overdue_count, COALESCE(od.overdue_amount,0) AS overdue_amount,
    JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.payment.installment_amount')) AS installment_amount,
    JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.payment.preferred_payment_method')) AS pay_method,
    JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.payment.expected_final_payment_date')) AS end_date,
    " . suspicious_sql() . ' AS suspicious';

if (get_str('export') === 'csv' && admin_can('view_reports')) {
    $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY a.created_at DESC', $w->types, $w->params);
    audit('export_agreements_csv', 'report', null, 'agreements.csv', ['filters' => $_GET, 'rows' => count($rows)]);
    send_csv('agreements-' . date('Ymd') . '.csv',
        ['Reference', 'Customer', 'Customer phone', 'Customer email', 'Seller', 'Seller email', 'Seller phone', 'Seller type', 'Product/service', 'Total (TZS)', 'Deposit (TZS)', 'Installment (TZS)', 'Installments', 'Frequency', 'Payment method', 'Recorded paid (TZS)', 'Outstanding (TZS)', 'Overdue (TZS)', 'Status', 'Seller response', 'Created', 'Expected end', 'Red flag'],
        array_map(static fn($r) => [$r['agreement_code'], $r['customer_name'], $r['customer_phone'], $r['customer_email'], $r['seller_name'], $r['seller_email'], $r['seller_phone'], $r['seller_type'], $r['product_name'],
            $r['total_price'], $r['initial_deposit'], $r['installment_amount'], $r['number_of_installments'], $r['payment_frequency'], $r['pay_method'], $r['paid'], $r['balance'], $r['overdue_amount'],
            $r['st'] . ($r['overdue_count'] > 0 && $r['st'] === 'active' ? ' (overdue)' : ''), $r['responded_at'], $r['created_at'], $r['end_date'], $r['suspicious'] ? 'yes' : ''], $rows));
}

$pg = paginate(count_rows($fromSql, $w));
$rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
$freqs = db_select('SELECT DISTINCT payment_frequency FROM agreements ORDER BY payment_frequency');

$tabs = ['' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'overdue' => 'Overdue', 'completed' => 'Completed', 'cancelled' => 'Cancelled / declined'];

$pageTitle = 'Installment agreements';
$pageSubtitle = 'Every agreement created on the platform, with live repayment status.';
$activeNav = 'agreements';
$pageActions = admin_can('view_reports') ? '<a class="btn" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<nav class="tabs">
    <?php foreach ($tabs as $k => $l): ?><a class="tab<?= $stateFilter === $k && $flag === '' ? ' active' : '' ?>" href="agreements.php<?= $k !== '' ? '?state=' . $k : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
    <a class="tab tab-red<?= $flag === 'suspicious' ? ' active' : '' ?>" href="agreements.php?flag=suspicious"><i class="fa-solid fa-user-secret"></i> Red flags</a>
</nav>

<form class="card filters" method="get">
    <?php if ($stateFilter !== ''): ?><input type="hidden" name="state" value="<?= e($stateFilter) ?>"><?php endif; ?>
    <?php if ($flag !== ''): ?><input type="hidden" name="flag" value="<?= e($flag) ?>"><?php endif; ?>
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Reference, product, customer, seller"></label>
    <label class="field">Frequency
        <select name="frequency"><option value="">All</option>
            <?php foreach ($freqs as $f): ?><option value="<?= e($f['payment_frequency']) ?>"<?= $freq === $f['payment_frequency'] ? ' selected' : '' ?>><?= e(humanize($f['payment_frequency'])) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Created from<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">to<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button><a class="btn btn-ghost" href="agreements.php">Reset</a></div>
</form>
<?php if ($flag === 'suspicious'): ?>
    <p class="note"><i class="fa-solid fa-circle-info"></i> Red flags are rule-based hints, not proof: large amounts (≥ <?= tzs(SUSPICIOUS_AMOUNT_TZS) ?>), a customer acting as their own seller, suspended sellers, more than 60 installments, deposits above the price, or 5+ agreements by one customer within 24 hours.</p>
<?php endif; ?>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Reference</th><th>Customer</th><th>Seller</th><th>Product / service</th><th class="num">Total</th><th class="num">Deposit</th><th class="num">Installment</th><th class="num">Outstanding</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="strong mono" href="agreement.php?id=<?= (int) $r['id'] ?>"><?= e($r['agreement_code']) ?></a><?= $r['suspicious'] ? ' <i class="fa-solid fa-flag text-red" title="Matches a red-flag rule"></i>' : '' ?></td>
                    <td><a href="user.php?id=<?= (int) $r['customer_id'] ?>"><?= e($r['customer_name']) ?></a><div class="muted small"><?= e($r['customer_phone']) ?></div></td>
                    <td><?= $r['seller_id'] ? '<a href="seller.php?id=' . (int) $r['seller_id'] . '">' . e($r['seller_name']) . '</a>' : e($r['seller_name'] ?: '—') ?><div class="muted small"><?= e($r['seller_email']) ?></div></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td class="num"><?= tzs($r['total_price']) ?></td>
                    <td class="num"><?= tzs($r['initial_deposit'], false) ?></td>
                    <td class="num"><?= $r['installment_amount'] !== null ? tzs($r['installment_amount'], false) : '—' ?><div class="muted small"><?= (int) $r['number_of_installments'] ?> × <?= e($r['payment_frequency']) ?></div></td>
                    <td class="num"><?= in_array($r['st'], ['active', 'completed'], true) ? tzs($r['balance'], false) : '<span class="muted">—</span>' ?></td>
                    <td><?= badge($r['st']) ?><?= $r['overdue_count'] > 0 && $r['st'] === 'active' ? ' ' . badge('overdue') : '' ?></td>
                    <td class="small"><?= fmt_date($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="10" class="empty">No agreements match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
