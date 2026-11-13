<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_cases');

const CASE_TYPES = [
    'payment_dispute'      => 'Payment dispute',
    'suspicious_agreement' => 'Suspicious agreement',
    'complaint'            => 'Customer complaint',
    'account_issue'        => 'Account issue',
    'other'                => 'Other',
];

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_cases')) {
        flash('error', 'Your role cannot create cases.');
        redirect('cases.php');
    }
    $type = post_str('type', 30);
    $subject = post_str('subject', 200);
    $desc = post_str('description', 5000);
    $priority = post_str('priority', 10);
    $links = ['user_id' => post_int('user_id'), 'seller_id' => post_int('seller_id'), 'agreement_id' => post_int('agreement_id'), 'transaction_id' => post_int('transaction_id')];
    if (!isset(CASE_TYPES[$type]) || mb_strlen($subject) < 4 || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        flash('error', 'Choose a type and priority and enter a subject of at least 4 characters.');
        redirect('cases.php?new=1');
    }
    // Derive the customer from the linked agreement/payment when not given
    if (!$links['user_id'] && $links['agreement_id']) {
        $links['user_id'] = (int) (db_select('SELECT customer_id FROM agreements WHERE id = ?', 'i', [$links['agreement_id']])[0]['customer_id'] ?? 0);
    }
    if (!$links['user_id'] && $links['transaction_id']) {
        $links['user_id'] = (int) (db_select('SELECT customer_id FROM transactions WHERE id = ?', 'i', [$links['transaction_id']])[0]['customer_id'] ?? 0);
    }
    $code = generate_code('CASE');
    db_execute('INSERT INTO admin_cases (case_code, type, subject, description, priority, user_id, seller_id, agreement_id, transaction_id, assigned_to, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'sssssiiiiii',
        [$code, $type, $subject, $desc, $priority, $links['user_id'] ?: null, $links['seller_id'] ?: null, $links['agreement_id'] ?: null, $links['transaction_id'] ?: null, (int) $admin['id'], (int) $admin['id']]);
    $caseId = (int) db()->insert_id;
    audit('case_opened', 'case', $caseId, $code, ['type' => $type, 'subject' => $subject, 'priority' => $priority, 'links' => array_filter($links)]);
    flash('success', 'Case ' . $code . ' opened.');
    redirect('case.php?id=' . $caseId);
}

$status = get_str('status', 20);
$type = get_str('type', 30);
$q = get_str('q');
$w = new Where();
if ($status === '' ) {
    $w->add("c.status IN ('open','investigating')");
} elseif (in_array($status, ['open', 'investigating', 'resolved', 'dismissed'], true)) {
    $w->add('c.status = ?', 's', [$status]);
}
if (isset(CASE_TYPES[$type]) && !get_int('new')) $w->add('c.type = ?', 's', [$type]);
if ($q !== '') $w->add('(c.case_code LIKE ? OR c.subject LIKE ? OR u.full_name LIKE ?)', 'sss', [like($q), like($q), like($q)]);
$fromSql = ' FROM admin_cases c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN admin_users ad ON ad.id = c.assigned_to LEFT JOIN agreements a ON a.id = c.agreement_id LEFT JOIN transactions t ON t.id = c.transaction_id ';
$pg = paginate(count_rows($fromSql, $w));
$rows = db_select('SELECT c.*, u.full_name, ad.full_name AS assignee, a.agreement_code, t.transaction_code' . $fromSql . $w->sql()
    . " ORDER BY FIELD(c.priority,'urgent','high','normal','low'), c.created_at DESC LIMIT " . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);

$failedEmails = (int) scalar("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 7 DAY)");

// Prefill for "Open case" links from other pages
$new = get_int('new') === 1 && admin_can('manage_cases');
$pre = ['user_id' => get_int('user_id'), 'seller_id' => get_int('seller_id'), 'agreement_id' => get_int('agreement_id'), 'transaction_id' => get_int('transaction_id'), 'type' => isset(CASE_TYPES[$type]) ? $type : 'complaint'];
$preLabel = [];
if ($pre['agreement_id']) $preLabel[] = 'Agreement ' . (db_select('SELECT agreement_code FROM agreements WHERE id = ?', 'i', [$pre['agreement_id']])[0]['agreement_code'] ?? '#' . $pre['agreement_id']);
if ($pre['transaction_id']) $preLabel[] = 'Payment ' . (db_select('SELECT transaction_code FROM transactions WHERE id = ?', 'i', [$pre['transaction_id']])[0]['transaction_code'] ?? '#' . $pre['transaction_id']);
if ($pre['user_id']) $preLabel[] = 'User ' . (db_select('SELECT full_name FROM users WHERE id = ?', 'i', [$pre['user_id']])[0]['full_name'] ?? '#' . $pre['user_id']);
if ($pre['seller_id']) $preLabel[] = 'Seller ' . (db_select('SELECT business_name FROM sellers WHERE id = ?', 'i', [$pre['seller_id']])[0]['business_name'] ?? '#' . $pre['seller_id']);

$pageTitle = 'Cases & disputes';
$pageSubtitle = 'Payment disputes, suspicious agreements and customer complaints, with an investigation trail.';
$activeNav = 'cases';
$pageActions = admin_can('manage_cases') ? '<a class="btn btn-primary" href="cases.php?new=1"><i class="fa-solid fa-plus"></i> New case</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<?php if ($failedEmails > 0): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-envelope"></i> <?= $failedEmails ?> email(s) failed to send in the last 7 days. <a href="notifications.php?tab=email&amp;status=failed">Review email delivery</a></div>
<?php endif; ?>

<?php if ($new): ?>
<div class="card">
    <div class="card-head"><h3>Open a new case</h3><?php if ($preLabel): ?><span class="muted small">Linked to: <?= e(implode(' · ', $preLabel)) ?></span><?php endif; ?></div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <?php foreach (['user_id', 'seller_id', 'agreement_id', 'transaction_id'] as $k): if ($pre[$k]): ?><input type="hidden" name="<?= $k ?>" value="<?= (int) $pre[$k] ?>"><?php endif; endforeach; ?>
        <label class="field">Type
            <select name="type"><?php foreach (CASE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $pre['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        </label>
        <label class="field">Priority
            <select name="priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?><option value="<?= $p ?>"<?= $p === 'normal' ? ' selected' : '' ?>><?= humanize($p) ?></option><?php endforeach; ?></select>
        </label>
        <label class="field full">Subject<input name="subject" required minlength="4" maxlength="200" placeholder="e.g. Customer says M-Pesa payment was deducted but shows pending"></label>
        <label class="field full">Details<textarea name="description" rows="4" maxlength="5000" placeholder="What was reported, by whom, and what has been checked so far"></textarea></label>
        <div class="btn-row full"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-folder-plus"></i> Open case</button><a class="btn btn-ghost" href="cases.php">Cancel</a></div>
    </form>
</div>
<?php endif; ?>

<nav class="tabs">
    <?php foreach (['' => 'Open & investigating', 'open' => 'Open', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l): ?>
        <a class="tab<?= $status === $k ? ' active' : '' ?>" href="cases.php<?= $k !== '' ? '?status=' . $k : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</nav>
<form class="card filters" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Case code, subject, customer"></label>
    <label class="field">Type<select name="type"><option value="">All</option><?php foreach (CASE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button></div>
</form>

<div class="card table-card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Case</th><th>Type</th><th>Subject</th><th>Customer</th><th>Linked</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Opened</th></tr></thead>
        <tbody><?php foreach ($rows as $c): ?>
            <tr>
                <td><a class="strong mono" href="case.php?id=<?= (int) $c['id'] ?>"><?= e($c['case_code']) ?></a></td>
                <td><?= e(CASE_TYPES[$c['type']] ?? $c['type']) ?></td>
                <td><?= e($c['subject']) ?></td>
                <td><?= $c['user_id'] ? '<a href="user.php?id=' . (int) $c['user_id'] . '">' . e($c['full_name']) . '</a>' : '—' ?></td>
                <td class="small mono"><?= e(trim(($c['agreement_code'] ?? '') . ' ' . ($c['transaction_code'] ?? '')) ?: '—') ?></td>
                <td><?= badge($c['priority']) ?></td>
                <td><?= badge($c['status']) ?></td>
                <td class="small"><?= e($c['assignee'] ?: '—') ?></td>
                <td class="small"><?= fmt_date($c['created_at']) ?></td>
            </tr>
        <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="9" class="empty">No cases here.</td></tr><?php endif; ?></tbody>
    </table></div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
