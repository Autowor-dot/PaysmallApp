<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_reports');

$from = get_date('from') ?: date('Y-m-01', strtotime('-11 months'));
$to = get_date('to') ?: date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$sellerId = get_int('seller_id');
$customerQ = get_str('customer', 190);
$stateFilter = get_str('state', 20);
$payStatus = get_str('pay_status', 20);

$customerId = 0;
$customerLabel = '';
if ($customerQ !== '') {
    $c = db_select('SELECT id, full_name FROM users WHERE email = ? OR phone = ? OR id = ? LIMIT 1', 'ssi',
        [strtolower($customerQ), normalize_phone($customerQ), ctype_digit($customerQ) ? (int) $customerQ : 0])[0] ?? null;
    if ($c) {
        $customerId = (int) $c['id'];
        $customerLabel = $c['full_name'];
    } else {
        flash('warning', 'No customer matches “' . $customerQ . '”. Showing all customers.');
    }
}
$seller = $sellerId ? (db_select('SELECT id, business_name, email FROM sellers WHERE id = ?', 'i', [$sellerId])[0] ?? null) : null;

$state = agreement_state_sql();
$v = tx_verification_sql();
$fromTs = $from . ' 00:00:00';
$toTs = $to . ' 23:59:59';

// --- Agreement filter (by creation date) ---
$wa = new Where();
$wa->add('a.created_at BETWEEN ? AND ?', 'ss', [$fromTs, $toTs]);
if ($customerId) $wa->add('a.customer_id = ?', 'i', [$customerId]);
if ($seller) $wa->add('(a.seller_id = ? OR (a.seller_id IS NULL AND LOWER(a.seller_email) = LOWER(?)))', 'is', [$seller['id'], $seller['email']]);
if ($stateFilter === 'overdue') {
    $wa->add(agreement_overdue_sql());
} elseif (in_array($stateFilter, ['pending', 'active', 'completed', 'declined', 'cancelled'], true)) {
    $wa->add("{$state} = ?", 's', [$stateFilter]);
}
$agg = db_select("SELECT COUNT(*) n,
        COALESCE(SUM(a.total_price),0) value_all,
        COALESCE(SUM(CASE WHEN a.status='accepted' THEN a.total_price END),0) expected,
        COALESCE(SUM(CASE WHEN {$state}='completed' THEN a.total_price END),0) completed_value,
        SUM({$state}='completed') n_completed, SUM({$state}='active') n_active, SUM({$state}='pending') n_pending,
        COALESCE(SUM(CASE WHEN {$state}='active' THEN GREATEST(pl.total_price - pl.amount_paid, 0) END),0) outstanding,
        COALESCE(SUM(CASE WHEN {$state}='active' THEN od.overdue_amount END),0) overdue,
        COALESCE(SUM(a.initial_deposit),0) deposits"
    . agreement_from_sql() . $wa->sql(), $wa->types, $wa->params)[0];

// --- Payment filter (by transaction date) ---
$wt = new Where();
$wt->add('t.created_at BETWEEN ? AND ?', 'ss', [$fromTs, $toTs]);
if ($customerId) $wt->add('t.customer_id = ?', 'i', [$customerId]);
if ($seller) $wt->add('(a.seller_id = ? OR (a.seller_id IS NULL AND LOWER(a.seller_email) = LOWER(?)))', 'is', [$seller['id'], $seller['email']]);
if (in_array($payStatus, ['completed', 'pending', 'failed'], true)) $wt->add('t.status = ?', 's', [$payStatus]);
$txFrom = ' FROM transactions t LEFT JOIN installment_plans pl ON pl.id = t.plan_id LEFT JOIN agreements a ON a.id = pl.agreement_id ';
$pay = db_select("SELECT COUNT(*) n,
        COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount END),0) recorded,
        COALESCE(SUM(CASE WHEN {$v}='provider' THEN t.amount END),0) verified,
        COALESCE(SUM(CASE WHEN {$v}='simulator' THEN t.amount END),0) simulated,
        COALESCE(SUM(CASE WHEN {$v}='unverified' THEN t.amount END),0) unverified,
        COALESCE(SUM(CASE WHEN t.status='pending' THEN t.amount END),0) pending,
        COALESCE(SUM(CASE WHEN t.status='failed' THEN t.amount END),0) failed,
        SUM(t.status='completed') n_ok, SUM(t.status='failed') n_failed, SUM(t.status='pending') n_pending"
    . $txFrom . $wt->sql(), $wt->types, $wt->params)[0];

// --- Monthly statistics ---
$monthly = [];
$cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01');
$end = new DateTimeImmutable(substr($to, 0, 7) . '-01');
while ($cursor <= $end && count($monthly) < 60) {
    $monthly[$cursor->format('Y-m')] = ['agreements' => 0, 'agreed' => 0.0, 'scheduled' => 0.0, 'recorded' => 0.0, 'verified' => 0.0, 'failed' => 0, 'payments' => 0];
    $cursor = $cursor->modify('+1 month');
}
foreach (db_select("SELECT DATE_FORMAT(a.created_at,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(a.total_price),0) v" . agreement_from_sql() . $wa->sql() . ' GROUP BY ym', $wa->types, $wa->params) as $r) {
    if (isset($monthly[$r['ym']])) { $monthly[$r['ym']]['agreements'] = (int) $r['n']; $monthly[$r['ym']]['agreed'] = (float) $r['v']; }
}
foreach (db_select("SELECT DATE_FORMAT(t.created_at,'%Y-%m') ym, SUM(t.status='completed') ok, SUM(t.status='failed') bad,
        COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount END),0) rec, COALESCE(SUM(CASE WHEN {$v}='provider' THEN t.amount END),0) ver" . $txFrom . $wt->sql() . ' GROUP BY ym', $wt->types, $wt->params) as $r) {
    if (isset($monthly[$r['ym']])) { $monthly[$r['ym']]['payments'] = (int) $r['ok']; $monthly[$r['ym']]['failed'] = (int) $r['bad']; $monthly[$r['ym']]['recorded'] = (float) $r['rec']; $monthly[$r['ym']]['verified'] = (float) $r['ver']; }
}
// Scheduled (expected) installments by due month, on accepted agreements
$ws = new Where();
$ws->add('ip.due_date BETWEEN ? AND ?', 'ss', [$from, $to]);
$ws->add("a.status = 'accepted'");
if ($customerId) $ws->add('a.customer_id = ?', 'i', [$customerId]);
if ($seller) $ws->add('(a.seller_id = ? OR (a.seller_id IS NULL AND LOWER(a.seller_email) = LOWER(?)))', 'is', [$seller['id'], $seller['email']]);
foreach (db_select("SELECT DATE_FORMAT(ip.due_date,'%Y-%m') ym, COALESCE(SUM(ip.amount_due),0) v FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id JOIN agreements a ON a.id = pl.agreement_id" . $ws->sql() . ' GROUP BY ym', $ws->types, $ws->params) as $r) {
    if (isset($monthly[$r['ym']])) $monthly[$r['ym']]['scheduled'] = (float) $r['v'];
}

if (get_str('export') === 'monthly') {
    audit('export_monthly_report_csv', 'report', null, 'monthly.csv', ['filters' => $_GET]);
    $out = [];
    foreach ($monthly as $ym => $m) {
        $out[] = [$ym, $m['agreements'], $m['agreed'], $m['scheduled'], $m['recorded'], $m['verified'], $m['payments'], $m['failed']];
    }
    send_csv('monthly-report-' . $from . '-to-' . $to . '.csv',
        ['Month', 'Agreements created', 'Agreed value (TZS)', 'Scheduled installments - expected (TZS)', 'Recorded payments (TZS)', 'Provider-verified payments (TZS)', 'Successful payments (count)', 'Failed payments (count)'], $out);
}

// Top sellers by accepted value in the period
$topSellers = db_select("SELECT COALESCE(s.business_name, a.seller_name, a.seller_email) name, a.seller_id, COUNT(*) n,
        COALESCE(SUM(CASE WHEN a.status='accepted' THEN a.total_price END),0) v, COALESCE(SUM(pl.amount_paid),0) paid"
    . agreement_from_sql() . $wa->sql() . ' GROUP BY name, a.seller_id ORDER BY v DESC LIMIT 10', $wa->types, $wa->params);

$sellers = db_select('SELECT id, business_name FROM sellers ORDER BY business_name LIMIT 500');
$keys = array_keys($monthly);
$charts = ['monthly' => [
    'type' => 'bar', 'money' => true, 'labels' => month_labels($keys),
    'datasets' => [
        ['label' => 'Expected (scheduled)', 'data' => array_column($monthly, 'scheduled'), 'color' => 4],
        ['label' => 'Recorded', 'data' => array_column($monthly, 'recorded'), 'color' => 0],
        ['label' => 'Provider-verified', 'data' => array_column($monthly, 'verified'), 'color' => 2],
    ],
]];

$qs = static fn(array $extra) => e(http_build_query(array_filter(array_merge([
    'from' => $from, 'to' => $to, 'seller_id' => $sellerId ?: null, 'customer_id' => $customerId ?: null,
], $extra), static fn($x) => $x !== null && $x !== '')));

$pageTitle = 'Financial reports';
$pageSubtitle = fmt_date($from) . ' – ' . fmt_date($to) . ($customerLabel ? ' · customer: ' . $customerLabel : '') . ($seller ? ' · seller: ' . $seller['business_name'] : '');
$activeNav = 'reports';
$useCharts = true;
require __DIR__ . '/includes/header.php';
?>
<form class="card filters" method="get">
    <label class="field">From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="field">Customer<input name="customer" value="<?= e($customerQ) ?>" placeholder="Email, phone or user ID"></label>
    <label class="field">Seller
        <select name="seller_id"><option value="">All sellers</option>
            <?php foreach ($sellers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $sellerId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['business_name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Agreement status
        <select name="state"><option value="">All</option>
            <?php foreach (['pending', 'active', 'overdue', 'completed', 'declined', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= $stateFilter === $s ? ' selected' : '' ?>><?= humanize($s) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Payment status
        <select name="pay_status"><option value="">All</option>
            <?php foreach (['completed' => 'Successful', 'pending' => 'Pending', 'failed' => 'Failed'] as $k => $l): ?><option value="<?= $k ?>"<?= $payStatus === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Run report</button><a class="btn btn-ghost" href="reports.php">Reset</a></div>
</form>

<div class="legend-box">
    <span><span class="swatch sw-4"></span><strong>Expected</strong> — amounts customers committed to under accepted agreements / scheduled installments.</span>
    <span><span class="swatch sw-0"></span><strong>Recorded</strong> — payments marked completed in our system.</span>
    <span><span class="swatch sw-2"></span><strong>Verified</strong> — recorded payments confirmed by a payment provider webhook.</span>
</div>

<section class="kpi-grid">
    <div class="kpi card"><div><div class="kpi-label">Agreements created</div><div class="kpi-value"><?= number_format((int) $agg['n']) ?></div><div class="kpi-sub"><?= (int) $agg['n_active'] ?> active · <?= (int) $agg['n_completed'] ?> completed · <?= (int) $agg['n_pending'] ?> pending</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Total agreement value</div><div class="kpi-value sm"><?= tzs($agg['value_all']) ?></div><div class="kpi-sub">all statuses, incl. pending &amp; declined</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Expected (accepted)</div><div class="kpi-value sm"><?= tzs($agg['expected']) ?></div><div class="kpi-sub">deposits agreed: <?= tzs($agg['deposits']) ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Completed agreements' value</div><div class="kpi-value sm"><?= tzs($agg['completed_value']) ?></div></div></div>
</section>
<section class="kpi-grid">
    <div class="kpi card"><div><div class="kpi-label">Recorded payments</div><div class="kpi-value sm"><?= tzs($pay['recorded']) ?></div><div class="kpi-sub"><?= number_format((int) $pay['n_ok']) ?> successful · <?= number_format((int) $pay['n_failed']) ?> failed · <?= number_format((int) $pay['n_pending']) ?> pending</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Provider-verified</div><div class="kpi-value sm text-green"><?= tzs($pay['verified']) ?></div><div class="kpi-sub">simulated: <?= tzs($pay['simulated']) ?> · unverified: <?= tzs($pay['unverified']) ?></div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Outstanding balances</div><div class="kpi-value sm"><?= tzs($agg['outstanding']) ?></div><div class="kpi-sub">active agreements in selection</div></div></div>
    <div class="kpi card"><div><div class="kpi-label">Overdue amount</div><div class="kpi-value sm text-red"><?= tzs($agg['overdue']) ?></div><div class="kpi-sub">pending requests: <?= tzs($pay['pending']) ?></div></div></div>
</section>

<div class="card chart-card">
    <div class="card-head"><h3>Payment trend</h3><span class="muted small">TZS by month</span></div>
    <div class="chart-box tall"><canvas data-chart="monthly"></canvas></div>
</div>

<div class="card table-card">
    <div class="card-head"><h3>Monthly statistics</h3><a class="btn btn-sm" href="reports.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'monthly']))) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Month</th><th class="num">Agreements</th><th class="num">Agreed value</th><th class="num">Expected (scheduled)</th><th class="num">Recorded</th><th class="num">Verified</th><th class="num">Successful</th><th class="num">Failed</th></tr></thead>
        <tbody><?php foreach (array_reverse($monthly, true) as $ym => $m): ?>
            <tr><td><?= e(date('F Y', strtotime($ym . '-01'))) ?></td><td class="num"><?= $m['agreements'] ?></td><td class="num"><?= tzs($m['agreed'], false) ?></td><td class="num"><?= tzs($m['scheduled'], false) ?></td><td class="num"><?= tzs($m['recorded'], false) ?></td><td class="num"><?= tzs($m['verified'], false) ?></td><td class="num"><?= $m['payments'] ?></td><td class="num"><?= $m['failed'] ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th>Total</th><th class="num"><?= array_sum(array_column($monthly, 'agreements')) ?></th><th class="num"><?= tzs(array_sum(array_column($monthly, 'agreed')), false) ?></th><th class="num"><?= tzs(array_sum(array_column($monthly, 'scheduled')), false) ?></th><th class="num"><?= tzs(array_sum(array_column($monthly, 'recorded')), false) ?></th><th class="num"><?= tzs(array_sum(array_column($monthly, 'verified')), false) ?></th><th class="num"><?= array_sum(array_column($monthly, 'payments')) ?></th><th class="num"><?= array_sum(array_column($monthly, 'failed')) ?></th></tr></tfoot>
    </table></div>
</div>

<section class="grid-2">
    <div class="card table-card">
        <div class="card-head"><h3>Top sellers by accepted value</h3></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Seller</th><th class="num">Agreements</th><th class="num">Accepted value</th><th class="num">Recorded paid</th></tr></thead>
            <tbody><?php foreach ($topSellers as $s): ?>
                <tr><td><?= $s['seller_id'] ? '<a href="seller.php?id=' . (int) $s['seller_id'] . '">' . e($s['name']) . '</a>' : e($s['name'] ?: '—') ?></td><td class="num"><?= (int) $s['n'] ?></td><td class="num"><?= tzs($s['v'], false) ?></td><td class="num"><?= tzs($s['paid'], false) ?></td></tr>
            <?php endforeach; ?><?php if (!$topSellers): ?><tr><td colspan="4" class="empty">No agreements in this period.</td></tr><?php endif; ?></tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="card-head"><h3>Export detailed CSV</h3></div>
        <p class="small muted">Exports use the same period, customer and seller filters. Each export is recorded in the activity log.</p>
        <div class="export-list">
            <a class="btn" href="agreements.php?export=csv&amp;<?= $qs(['state' => $stateFilter ?: null, 'from' => $from, 'to' => $to]) ?>"><i class="fa-solid fa-file-signature"></i> Agreements</a>
            <a class="btn" href="payments.php?export=csv&amp;<?= $qs(['status' => $payStatus ?: null]) ?>"><i class="fa-solid fa-money-bill-transfer"></i> Payments</a>
            <a class="btn" href="payments.php?export=csv&amp;status=overdue&amp;<?= $qs([]) ?>"><i class="fa-solid fa-triangle-exclamation"></i> Overdue installments</a>
            <a class="btn" href="reports.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'monthly']))) ?>"><i class="fa-solid fa-calendar"></i> Monthly statistics</a>
            <a class="btn" href="users.php?export=csv&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>"><i class="fa-solid fa-users"></i> Users registered</a>
            <a class="btn" href="sellers.php?export=csv&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>"><i class="fa-solid fa-store"></i> Sellers registered</a>
        </div>
        <?php if ($seller || $customerId): ?><p class="small muted">Agreement and payment exports include the customer filter; the seller filter applies to agreements with a registered seller.</p><?php endif; ?>
    </div>
</section>

<script type="application/json" id="chart-data"><?= json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
