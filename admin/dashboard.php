<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_dashboard');

$t = platform_totals();
$keys = month_keys(12);
$labels = month_labels($keys);
$from = $keys[0] . '-01';

$usersSeries = month_series(db_select("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM users WHERE created_at >= ? GROUP BY ym", 's', [$from]), $keys);
$sellerSeries = month_series(db_select("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM sellers WHERE created_at >= ? GROUP BY ym", 's', [$from]), $keys);
$agrSeries = month_series(db_select("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM agreements WHERE created_at >= ? GROUP BY ym", 's', [$from]), $keys);
$agrAccepted = month_series(db_select("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM agreements WHERE status = 'accepted' AND created_at >= ? GROUP BY ym", 's', [$from]), $keys);
$v = tx_verification_sql();
$payRows = db_select("SELECT DATE_FORMAT(COALESCE(t.confirmed_at, t.created_at),'%Y-%m') ym,
        COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount END),0) recorded,
        COALESCE(SUM(CASE WHEN {$v}='provider' THEN t.amount END),0) verified,
        SUM(t.status='failed') failed, SUM(t.status='completed') ok
    FROM transactions t WHERE COALESCE(t.confirmed_at, t.created_at) >= ? GROUP BY ym", 's', [$from]);
$dueRows = db_select("SELECT DATE_FORMAT(ip.due_date,'%Y-%m') ym, COALESCE(SUM(ip.amount_due),0) v
    FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id
    WHERE pl.status IN ('active','overdue','completed') AND ip.due_date >= ? AND ip.due_date < (LAST_DAY(CURDATE()) + INTERVAL 1 DAY) GROUP BY ym", 's', [$from]);

$charts = [
    'growth' => [
        'type' => 'line',
        'labels' => $labels,
        'datasets' => [
            ['label' => 'New users', 'data' => $usersSeries, 'color' => 0],
            ['label' => 'New sellers', 'data' => $sellerSeries, 'color' => 1],
        ],
    ],
    'agreements' => [
        'type' => 'bar',
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Created', 'data' => $agrSeries, 'color' => 0],
            ['label' => 'Accepted', 'data' => $agrAccepted, 'color' => 2],
        ],
    ],
    'payments' => [
        'type' => 'bar',
        'money' => true,
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Scheduled (expected)', 'data' => month_series($dueRows, $keys), 'color' => 4],
            ['label' => 'Recorded', 'data' => month_series($payRows, $keys, 'recorded'), 'color' => 0],
            ['label' => 'Provider-verified', 'data' => month_series($payRows, $keys, 'verified'), 'color' => 2],
        ],
    ],
    'status' => [
        'type' => 'doughnut',
        'labels' => ['Active', 'Completed', 'Pending', 'Declined', 'Cancelled'],
        'datasets' => [[
            'label' => 'Agreements',
            'data' => [$t['agreements']['active'], $t['agreements']['completed'], $t['agreements']['pending'], $t['agreements']['declined'], $t['agreements']['cancelled']],
            'colors' => [0, 2, 3, 5, 6],
        ]],
    ],
    'performance' => [
        'type' => 'line',
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Successful payments', 'data' => month_series($payRows, $keys, 'ok'), 'color' => 2],
            ['label' => 'Failed payments', 'data' => month_series($payRows, $keys, 'failed'), 'color' => 5],
        ],
    ],
];

// Recent platform activity (merged feed)
$feed = [];
foreach (db_select('SELECT id, full_name, created_at FROM users ORDER BY created_at DESC LIMIT 6') as $r) {
    $feed[] = ['t' => $r['created_at'], 'icon' => 'fa-user-plus', 'tone' => 'blue', 'text' => '<a href="user.php?id=' . (int) $r['id'] . '">' . e($r['full_name']) . '</a> registered'];
}
foreach (db_select('SELECT id, business_name, created_at FROM sellers ORDER BY created_at DESC LIMIT 6') as $r) {
    $feed[] = ['t' => $r['created_at'], 'icon' => 'fa-store', 'tone' => 'violet', 'text' => 'Store <a href="seller.php?id=' . (int) $r['id'] . '">' . e($r['business_name']) . '</a> registered'];
}
foreach (db_select('SELECT a.id, a.agreement_code, a.status, a.created_at, a.responded_at, a.total_price, u.full_name FROM agreements a JOIN users u ON u.id = a.customer_id ORDER BY GREATEST(a.created_at, COALESCE(a.responded_at, a.created_at)) DESC LIMIT 8') as $r) {
    $link = '<a href="agreement.php?id=' . (int) $r['id'] . '">' . e($r['agreement_code']) . '</a>';
    $feed[] = ['t' => $r['created_at'], 'icon' => 'fa-file-signature', 'tone' => 'amber', 'text' => e($r['full_name']) . ' proposed ' . $link . ' (' . tzs($r['total_price']) . ')'];
    if ($r['responded_at'] && $r['status'] !== 'pending_seller_approval') {
        $feed[] = ['t' => $r['responded_at'], 'icon' => 'fa-handshake', 'tone' => $r['status'] === 'accepted' ? 'green' : 'red', 'text' => $link . ' was ' . e(humanize($r['status']))];
    }
}
foreach (db_select('SELECT id, transaction_code, amount, status, created_at FROM transactions ORDER BY created_at DESC LIMIT 8') as $r) {
    $feed[] = ['t' => $r['created_at'], 'icon' => 'fa-money-bill-wave', 'tone' => $r['status'] === 'completed' ? 'green' : ($r['status'] === 'failed' ? 'red' : 'amber'),
        'text' => 'Payment <a href="payment.php?id=' . (int) $r['id'] . '">' . e($r['transaction_code']) . '</a> ' . tzs($r['amount']) . ' — ' . e($r['status'])];
}
usort($feed, static fn($a, $b) => strcmp((string) $b['t'], (string) $a['t']));
$feed = array_slice($feed, 0, 14);

$overdueList = db_select("SELECT ip.id, ip.installment_number, ip.amount_due, ip.due_date, a.id AS agreement_id, a.agreement_code, u.full_name, u.phone
    FROM installment_payments ip
    JOIN installment_plans pl ON pl.id = ip.plan_id
    JOIN users u ON u.id = ip.customer_id
    LEFT JOIN agreements a ON a.id = pl.agreement_id
    WHERE ip.status <> 'paid' AND ip.due_date < CURDATE() AND pl.status IN ('active','overdue')
    ORDER BY ip.due_date ASC LIMIT 6");

$p = $t['payments'];
$pageTitle = 'Overview';
$pageSubtitle = 'Live platform figures from the database · ' . date('l j F Y');
$activeNav = 'overview';
$useCharts = true;
$pageActions = admin_can('view_reports') ? '<a class="btn" href="reports.php"><i class="fa-solid fa-file-export"></i> Reports</a>' : '';
require __DIR__ . '/includes/header.php';

$kpi = static function (string $label, string $value, string $icon, string $tone, string $sub = '', string $href = ''): string {
    $inner = '<div class="kpi-icon tone-' . $tone . '"><i class="fa-solid ' . $icon . '"></i></div>'
        . '<div><div class="kpi-label">' . e($label) . '</div><div class="kpi-value">' . $value . '</div>'
        . ($sub !== '' ? '<div class="kpi-sub">' . $sub . '</div>' : '') . '</div>';
    return $href !== '' ? '<a class="kpi card" href="' . e($href) . '">' . $inner . '</a>' : '<div class="kpi card">' . $inner . '</div>';
};
?>
<section class="kpi-grid">
    <?= $kpi('Registered users', number_format($t['users_total']), 'fa-users', 'blue', '+' . number_format($t['users_new_7d']) . ' this week · +' . number_format($t['users_new_30d']) . ' in 30 days', 'users.php') ?>
    <?= $kpi('Customers', number_format($t['customers']), 'fa-user-check', 'teal', 'users who have created an agreement', 'users.php?role=customer') ?>
    <?= $kpi('Registered sellers', number_format($t['sellers_total']), 'fa-store', 'violet', number_format($t['sellers_verified']) . ' verified · ' . number_format($t['sellers_pending']) . ' pending', 'sellers.php') ?>
    <?= $kpi('Agreements', number_format($t['agreements_total']), 'fa-file-signature', 'amber', number_format($t['agreements']['active']) . ' active · ' . number_format($t['agreements']['completed']) . ' completed', 'agreements.php') ?>
</section>

<section class="kpi-grid kpi-grid-5">
    <?= $kpi('Active', number_format($t['agreements']['active']), 'fa-circle-play', 'blue', '', 'agreements.php?state=active') ?>
    <?= $kpi('Pending approval', number_format($t['agreements']['pending']), 'fa-hourglass-half', 'amber', '', 'agreements.php?state=pending') ?>
    <?= $kpi('Completed', number_format($t['agreements']['completed']), 'fa-circle-check', 'green', '', 'agreements.php?state=completed') ?>
    <?= $kpi('Cancelled / declined', number_format($t['agreements']['cancelled'] + $t['agreements']['declined']), 'fa-ban', 'grey', '', 'agreements.php?state=cancelled') ?>
    <?= $kpi('Overdue', number_format($t['agreements_overdue']), 'fa-triangle-exclamation', 'red', number_format($t['overdue_installments']) . ' late installments', 'agreements.php?state=overdue') ?>
</section>

<section class="money-strip card">
    <div class="money-item">
        <span class="money-label">Expected <i class="fa-solid fa-circle-info" title="Total agreed value of accepted agreements"></i></span>
        <strong><?= tzs($t['expected_total']) ?></strong>
        <small>accepted agreement value</small>
    </div>
    <div class="money-item">
        <span class="money-label">Recorded payments <i class="fa-solid fa-circle-info" title="Transactions marked completed in our system by any route"></i></span>
        <strong><?= tzs($p['recorded']) ?></strong>
        <small><?= number_format((int) $p['n_completed']) ?> completed transactions</small>
    </div>
    <div class="money-item verified">
        <span class="money-label">Provider-verified <i class="fa-solid fa-shield-halved" title="Confirmed by a payment provider webhook"></i></span>
        <strong><?= tzs($p['verified']) ?></strong>
        <small><?= $p['simulated'] > 0 ? tzs($p['simulated']) . ' simulated (test)' : 'independently confirmed' ?></small>
    </div>
    <div class="money-item">
        <span class="money-label">Outstanding balance</span>
        <strong><?= tzs($t['outstanding']) ?></strong>
        <small>on live plans</small>
    </div>
    <div class="money-item danger">
        <span class="money-label">Overdue</span>
        <strong><?= tzs($t['overdue_amount']) ?></strong>
        <small><?= number_format($t['overdue_installments']) ?> installments past due</small>
    </div>
</section>
<?php if ($p['verified'] == 0 && $p['recorded'] > 0): ?>
    <p class="note"><i class="fa-solid fa-circle-info"></i> No payment gateway is connected yet, so no payment has been independently verified. Recorded amounts include <?= tzs($p['simulated']) ?> confirmed by the development simulator.</p>
<?php endif; ?>

<section class="grid-2">
    <div class="card chart-card">
        <div class="card-head"><h3>Customer &amp; seller growth</h3><span class="muted small">new registrations / month</span></div>
        <div class="chart-box"><canvas data-chart="growth"></canvas></div>
    </div>
    <div class="card chart-card">
        <div class="card-head"><h3>Agreement creation</h3><span class="muted small">created vs accepted / month</span></div>
        <div class="chart-box"><canvas data-chart="agreements"></canvas></div>
    </div>
    <div class="card chart-card">
        <div class="card-head"><h3>Monthly installment payments</h3><span class="muted small">TZS · expected vs recorded vs verified</span></div>
        <div class="chart-box"><canvas data-chart="payments"></canvas></div>
    </div>
    <div class="card chart-card">
        <div class="card-head"><h3>Agreements by status</h3><span class="muted small">all time</span></div>
        <div class="chart-box"><canvas data-chart="status"></canvas></div>
    </div>
    <div class="card chart-card">
        <div class="card-head"><h3>Payment performance</h3><span class="muted small">successful vs failed transactions</span></div>
        <div class="chart-box"><canvas data-chart="performance"></canvas></div>
    </div>
    <div class="card">
        <div class="card-head"><h3>Overdue installments</h3><a class="small" href="agreements.php?state=overdue">View all</a></div>
        <?php if (empty($overdueList)): ?>
            <div class="empty-mini"><i class="fa-solid fa-circle-check"></i> Nothing overdue.</div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($overdueList as $o): ?>
                    <li>
                        <div><strong><?= e($o['full_name']) ?></strong> <span class="muted small"><?= e($o['phone']) ?></span><br>
                            <span class="small"><?= $o['agreement_id'] ? '<a href="agreement.php?id=' . (int) $o['agreement_id'] . '">' . e($o['agreement_code']) . '</a> · ' : '' ?>#<?= (int) $o['installment_number'] ?> due <?= fmt_date($o['due_date']) ?></span></div>
                        <div class="right"><strong><?= tzs($o['amount_due']) ?></strong><br><span class="small text-red"><?= (int) ((time() - strtotime($o['due_date'])) / 86400) ?> days late</span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Recent platform activity</h3></div>
        <ul class="feed">
            <?php foreach ($feed as $f): ?>
                <li><span class="feed-icon tone-<?= e($f['tone']) ?>"><i class="fa-solid <?= e($f['icon']) ?>"></i></span><span class="feed-text"><?= $f['text'] ?></span><span class="muted small"><?= e(time_ago($f['t'])) ?></span></li>
            <?php endforeach; ?>
            <?php if (empty($feed)): ?><li class="muted">No activity yet.</li><?php endif; ?>
        </ul>
    </div>
    <div class="card">
        <div class="card-head"><h3>Needs attention</h3></div>
        <ul class="attention">
            <li><a href="sellers.php?status=pending"><i class="fa-solid fa-store"></i> Sellers awaiting verification <span class="pill"><?= number_format($t['sellers_pending']) ?></span></a></li>
            <li><a href="agreements.php?state=pending"><i class="fa-solid fa-hourglass-half"></i> Agreements awaiting seller approval <span class="pill"><?= number_format($t['agreements']['pending']) ?></span></a></li>
            <li><a href="agreements.php?flag=suspicious"><i class="fa-solid fa-user-secret"></i> Agreements matching red-flag rules <span class="pill pill-red"><?= number_format($t['agreements_suspicious']) ?></span></a></li>
            <li><a href="payments.php?status=pending"><i class="fa-solid fa-clock"></i> Payments pending confirmation <span class="pill"><?= number_format((int) $p['n_pending']) ?></span></a></li>
            <li><a href="payments.php?status=failed"><i class="fa-solid fa-circle-xmark"></i> Failed payments <span class="pill pill-red"><?= number_format((int) $p['n_failed']) ?></span></a></li>
            <li><a href="cases.php"><i class="fa-solid fa-scale-balanced"></i> Open cases &amp; disputes <span class="pill"><?= number_format((int) scalar("SELECT COUNT(*) FROM admin_cases WHERE status IN ('open','investigating')")) ?></span></a></li>
            <li><a href="notifications.php?tab=email&amp;status=failed"><i class="fa-solid fa-envelope-circle-check"></i> Failed emails (7 days) <span class="pill pill-red"><?= number_format((int) scalar("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 7 DAY)")) ?></span></a></li>
            <li><a href="users.php?status=suspended"><i class="fa-solid fa-user-lock"></i> Suspended users <span class="pill"><?= number_format($t['users_suspended']) ?></span></a></li>
        </ul>
    </div>
</section>

<script type="application/json" id="chart-data"><?= json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
