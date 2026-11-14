<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_notifications');

$tab = get_str('tab', 10) === 'email' ? 'email' : 'inapp';
$q = get_str('q');
$status = get_str('status', 20);
$kind = get_str('kind', 60);
$from = get_date('from');
$to = get_date('to');

// Category counters (all time / last 30 days)
$cat = static function (string $where): array {
    return [
        (int) scalar("SELECT COUNT(*) FROM notifications WHERE {$where}"),
        (int) scalar("SELECT COUNT(*) FROM notifications WHERE {$where} AND created_at > (NOW() - INTERVAL 30 DAY)"),
    ];
};
$cards = [
    ['Agreement notifications', 'fa-file-signature', $cat("type = 'agreement'"), 'kind=agreement'],
    ['Acceptance notifications', 'fa-handshake', $cat("type = 'agreement' AND title IN ('Agreement accepted','Agreement declined')"), 'kind=acceptance'],
    ['Payment confirmations', 'fa-circle-check', $cat("type = 'payment' AND title = 'Payment confirmed'"), 'kind=payment_confirmed'],
    ['Seller payment alerts', 'fa-store', $cat("type = 'payment' AND title = 'Installment payment received'"), 'kind=seller_payment'],
    ['Installment reminders', 'fa-bell', $cat("type = 'reminder'"), 'kind=reminder'],
];
$emailStats = [];
foreach (db_select('SELECT context, status, COUNT(*) n FROM email_log GROUP BY context, status') as $r) {
    $emailStats[$r['context']][$r['status']] = (int) $r['n'];
}

if ($tab === 'inapp') {
    $fromSql = ' FROM notifications n JOIN users u ON u.id = n.user_id ';
    $w = new Where();
    $kinds = [
        'agreement' => "n.type = 'agreement'",
        'acceptance' => "n.type = 'agreement' AND n.title IN ('Agreement accepted','Agreement declined')",
        'payment_confirmed' => "n.type = 'payment' AND n.title = 'Payment confirmed'",
        'seller_payment' => "n.type = 'payment' AND n.title = 'Installment payment received'",
        'payment' => "n.type = 'payment'",
        'reminder' => "n.type = 'reminder'",
    ];
    if (isset($kinds[$kind])) $w->add($kinds[$kind]);
    if ($status === 'unread') $w->add('n.is_read = 0');
    if ($status === 'read') $w->add('n.is_read = 1');
    if ($q !== '') $w->add('(n.title LIKE ? OR n.message LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)', 'ssss', array_fill(0, 4, like($q)));
    if ($from !== '') $w->add('n.created_at >= ?', 's', [$from . ' 00:00:00']);
    if ($to !== '') $w->add('n.created_at <= ?', 's', [$to . ' 23:59:59']);
    $pg = paginate(count_rows($fromSql, $w), 30);
    $rows = db_select('SELECT n.*, u.full_name, u.email' . $fromSql . $w->sql() . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
} else {
    $fromSql = ' FROM email_log m ';
    $w = new Where();
    if (in_array($status, ['sent', 'failed', 'disabled'], true)) $w->add('m.status = ?', 's', [$status]);
    if ($kind !== '') $w->add('m.context = ?', 's', [$kind]);
    if ($q !== '') $w->add('(m.recipient LIKE ? OR m.subject LIKE ?)', 'ss', [like($q), like($q)]);
    if ($from !== '') $w->add('m.created_at >= ?', 's', [$from . ' 00:00:00']);
    if ($to !== '') $w->add('m.created_at <= ?', 's', [$to . ' 23:59:59']);
    $pg = paginate(count_rows($fromSql, $w), 30);
    $rows = db_select('SELECT m.*' . $fromSql . $w->sql() . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
    $contexts = db_select('SELECT DISTINCT context FROM email_log ORDER BY context');
}

$settingsRows = [
    ['notify_email_agreement_request', 'Agreement request email to seller'],
    ['notify_email_agreement_accepted', 'Accepted agreement emailed to seller & customer'],
    ['notify_email_payment_receipt', 'Payment receipt email to seller'],
    ['notify_email_payment_receipt_customer', 'Payment receipt email to customer'],
    ['notify_email_registration', 'Welcome email on customer / store registration'],
    ['notify_email_security', 'Account-security notices'],
];

$pageTitle = 'Notifications';
$pageSubtitle = 'In-app notifications and outgoing email delivery across the platform.';
$activeNav = 'notifications';
require __DIR__ . '/includes/header.php';
?>
<section class="kpi-grid kpi-grid-5">
    <?php foreach ($cards as [$label, $icon, $nums, $link]): ?>
        <a class="kpi card" href="notifications.php?tab=inapp&amp;<?= e($link) ?>"><div class="kpi-icon tone-blue"><i class="fa-solid <?= e($icon) ?>"></i></div>
            <div><div class="kpi-label"><?= e($label) ?></div><div class="kpi-value"><?= number_format($nums[0]) ?></div><div class="kpi-sub"><?= number_format($nums[1]) ?> in last 30 days</div></div></a>
    <?php endforeach; ?>
</section>

<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Email delivery by message type</h3><span class="muted small">as reported by the server mail function</span></div>
        <div class="table-wrap"><table class="table compact">
            <thead><tr><th>Message</th><th class="num">Sent</th><th class="num">Failed</th><th class="num">Disabled</th></tr></thead>
            <tbody><?php foreach ($emailStats as $ctx => $s): ?>
                <tr><td><a href="notifications.php?tab=email&amp;kind=<?= e(rawurlencode($ctx)) ?>"><?= e(humanize($ctx)) ?></a></td><td class="num"><?= $s['sent'] ?? 0 ?></td><td class="num <?= !empty($s['failed']) ? 'text-red' : '' ?>"><?= $s['failed'] ?? 0 ?></td><td class="num"><?= $s['disabled'] ?? 0 ?></td></tr>
            <?php endforeach; ?><?php if (!$emailStats): ?><tr><td colspan="4" class="empty">No emails logged yet. Logging starts once the admin migration is applied.</td></tr><?php endif; ?></tbody>
        </table></div>
        <p class="small muted">“Sent” means the hosting mail server accepted the message; it does not guarantee inbox delivery. Free hosting often restricts PHP mail — if many fail, configure an SMTP provider.</p>
    </div>
    <div class="card">
        <div class="card-head"><h3>Notification settings</h3><?php if (admin_can('manage_settings')): ?><a class="small" href="settings.php#notifications">Change</a><?php endif; ?></div>
        <ul class="list">
            <li><div>Server email sending (<code>mail_enabled</code>)</div><div><?= cfg('mail_enabled', true) ? badge('active', 'On') : badge('disabled', 'Off') ?></div></li>
            <?php foreach ($settingsRows as [$key, $label]): ?>
                <li><div><?= e($label) ?></div><div><?= site_flag($key, true) ? badge('active', 'On') : badge('disabled', 'Off') ?></div></li>
            <?php endforeach; ?>
            <li><div>Automatic installment reminders</div><div><?= badge('pending', 'Not scheduled yet') ?></div></li>
        </ul>
        <p class="small muted">In-app notifications are always created. Installment reminders need a scheduled job (cron), which free hosting does not provide; the “reminder” notification type is reserved for it.</p>
    </div>
</section>

<nav class="tabs">
    <a class="tab<?= $tab === 'inapp' ? ' active' : '' ?>" href="notifications.php?tab=inapp">In-app notifications</a>
    <a class="tab<?= $tab === 'email' ? ' active' : '' ?>" href="notifications.php?tab=email">Email log</a>
    <a class="tab tab-red<?= $tab === 'email' && $status === 'failed' ? ' active' : '' ?>" href="notifications.php?tab=email&amp;status=failed">Failed emails</a>
</nav>

<form class="card filters" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $tab === 'email' ? 'Recipient or subject' : 'Title, message or user' ?>"></label>
    <?php if ($tab === 'inapp'): ?>
        <label class="field">Category<select name="kind"><option value="">All</option>
            <?php foreach (['agreement' => 'Agreement', 'acceptance' => 'Acceptance', 'payment' => 'All payment', 'payment_confirmed' => 'Payment confirmed', 'seller_payment' => 'Seller payment alerts', 'reminder' => 'Reminders'] as $k => $l): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></label>
        <label class="field">Read<select name="status"><option value="">All</option><option value="unread"<?= $status === 'unread' ? ' selected' : '' ?>>Unread</option><option value="read"<?= $status === 'read' ? ' selected' : '' ?>>Read</option></select></label>
    <?php else: ?>
        <label class="field">Message<select name="kind"><option value="">All</option><?php foreach ($contexts as $c): ?><option value="<?= e($c['context']) ?>"<?= $kind === $c['context'] ? ' selected' : '' ?>><?= e(humanize($c['context'])) ?></option><?php endforeach; ?></select></label>
        <label class="field">Status<select name="status"><option value="">All</option><?php foreach (['sent', 'failed', 'disabled'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= humanize($s) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="field">From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button></div>
</form>

<div class="card table-card">
    <div class="table-wrap">
    <?php if ($tab === 'inapp'): ?>
        <table class="table">
            <thead><tr><th>When</th><th>User</th><th>Type</th><th>Title</th><th>Message</th><th>Read</th></tr></thead>
            <tbody><?php foreach ($rows as $n): ?>
                <tr><td class="small nowrap"><?= fmt_date($n['created_at'], true) ?></td><td><a href="user.php?id=<?= (int) $n['user_id'] ?>"><?= e($n['full_name']) ?></a></td><td><span class="tag"><?= e($n['type']) ?></span></td><td class="strong"><?= e($n['title']) ?></td><td class="small"><?= e(mb_strimwidth((string) $n['message'], 0, 140, '…')) ?></td><td><?= $n['is_read'] ? '<i class="fa-solid fa-check text-green"></i>' : '<span class="muted small">unread</span>' ?></td></tr>
            <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="empty">No notifications.</td></tr><?php endif; ?></tbody>
        </table>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>When</th><th>Recipient</th><th>Message</th><th>Subject</th><th>Related</th><th>Status</th><th>Error</th></tr></thead>
            <tbody><?php foreach ($rows as $m):
                $rel = match ($m['related_type']) {
                    'agreement' => '<a href="agreement.php?id=' . (int) $m['related_id'] . '">agreement #' . (int) $m['related_id'] . '</a>',
                    'transaction' => '<a href="payment.php?id=' . (int) $m['related_id'] . '">payment #' . (int) $m['related_id'] . '</a>',
                    'user' => '<a href="user.php?id=' . (int) $m['related_id'] . '">user #' . (int) $m['related_id'] . '</a>',
                    default => '—',
                }; ?>
                <tr><td class="small nowrap"><?= fmt_date($m['created_at'], true) ?></td><td><?= e($m['recipient']) ?></td><td><?= e(humanize($m['context'])) ?></td><td class="small"><?= e($m['subject']) ?></td><td class="small"><?= $rel ?></td><td><?= badge($m['status']) ?></td><td class="small text-red"><?= e($m['error'] ?? '') ?></td></tr>
            <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7" class="empty">No emails match.</td></tr><?php endif; ?></tbody>
        </table>
    <?php endif; ?>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
