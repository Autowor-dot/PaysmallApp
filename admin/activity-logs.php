<?php
/**
 * Read-only audit trail. There is deliberately no edit or delete path for
 * log entries anywhere in the admin panel.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_audit');

$tab = get_str('tab', 10) === 'logins' && $admin['role'] === 'super_admin' ? 'logins' : 'audit';
$q = get_str('q');
$adminId = get_int('admin_id');
$action = get_str('action', 60);
$targetType = get_str('target', 40);
$from = get_date('from');
$to = get_date('to');

if ($tab === 'logins') {
    $fromSql = ' FROM admin_login_attempts l ';
    $w = new Where();
    if ($q !== '') $w->add('(l.email LIKE ? OR l.ip_address LIKE ?)', 'ss', [like($q), like($q)]);
    if (get_str('result', 10) === 'failed') $w->add('l.success = 0');
    if ($from !== '') $w->add('l.attempted_at >= ?', 's', [$from . ' 00:00:00']);
    if ($to !== '') $w->add('l.attempted_at <= ?', 's', [$to . ' 23:59:59']);
    $pg = paginate(count_rows($fromSql, $w), 50);
    $rows = db_select('SELECT l.*' . $fromSql . $w->sql() . ' ORDER BY l.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
} else {
    $fromSql = ' FROM admin_audit_log l ';
    $w = new Where();
    // Ordinary admins cannot see Super Admin security events (password/role changes of super admins)
    if ($q !== '') $w->add('(l.target_label LIKE ? OR l.details LIKE ? OR l.admin_email LIKE ? OR l.target_id = ?)', 'ssss', [like($q), like($q), like($q), $q]);
    if ($adminId) $w->add('l.admin_id = ?', 'i', [$adminId]);
    if ($action !== '') $w->add('l.action = ?', 's', [$action]);
    if ($targetType !== '') $w->add('l.target_type = ?', 's', [$targetType]);
    if ($from !== '') $w->add('l.created_at >= ?', 's', [$from . ' 00:00:00']);
    if ($to !== '') $w->add('l.created_at <= ?', 's', [$to . ' 23:59:59']);

    if (get_str('export') === 'csv' && $admin['role'] === 'super_admin') {
        $all = db_select('SELECT l.*' . $fromSql . $w->sql() . ' ORDER BY l.id DESC LIMIT 50000', $w->types, $w->params);
        audit('export_audit_log_csv', 'report', null, 'audit-log.csv', ['filters' => $_GET, 'rows' => count($all)]);
        send_csv('audit-log-' . date('Ymd') . '.csv', ['ID', 'Time', 'Admin', 'Admin email', 'Role', 'Action', 'Target type', 'Target ID', 'Target', 'Details', 'IP'],
            array_map(static fn($r) => [$r['id'], $r['created_at'], $r['admin_name'], $r['admin_email'], $r['admin_role'], $r['action'], $r['target_type'], $r['target_id'], $r['target_label'], $r['details'], $r['ip_address']], $all));
    }
    $pg = paginate(count_rows($fromSql, $w), 50);
    $rows = db_select('SELECT l.*' . $fromSql . $w->sql() . ' ORDER BY l.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
    $actions = db_select('SELECT DISTINCT action FROM admin_audit_log ORDER BY action');
    $targets = db_select('SELECT DISTINCT target_type FROM admin_audit_log WHERE target_type IS NOT NULL ORDER BY target_type');
    $admins = db_select('SELECT id, full_name FROM admin_users ORDER BY full_name');
}

$targetLink = static function (array $r): string {
    $id = (int) $r['target_id'];
    $label = e($r['target_label'] ?: ($r['target_type'] . ' #' . $r['target_id']));
    return match ($r['target_type']) {
        'user' => '<a href="user.php?id=' . $id . '">' . $label . '</a>',
        'seller' => '<a href="seller.php?id=' . $id . '">' . $label . '</a>',
        'agreement' => '<a href="agreement.php?id=' . $id . '">' . $label . '</a>',
        'transaction' => '<a href="payment.php?id=' . $id . '">' . $label . '</a>',
        'case' => '<a href="case.php?id=' . $id . '">' . $label . '</a>',
        default => $r['target_type'] ? $label : '—',
    };
};

$pageTitle = 'Activity logs';
$pageSubtitle = 'Tamper-evident record of administrator actions. Entries cannot be edited or deleted from the panel.';
$activeNav = 'logs';
$pageActions = $admin['role'] === 'super_admin' && $tab === 'audit' ? '<a class="btn" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<?php if ($admin['role'] === 'super_admin'): ?>
<nav class="tabs">
    <a class="tab<?= $tab === 'audit' ? ' active' : '' ?>" href="activity-logs.php">Admin actions</a>
    <a class="tab<?= $tab === 'logins' ? ' active' : '' ?>" href="activity-logs.php?tab=logins">Sign-in attempts</a>
</nav>
<?php endif; ?>

<form class="card filters" method="get">
    <?php if ($tab === 'logins'): ?>
        <input type="hidden" name="tab" value="logins">
        <label class="field grow">Email or IP<input type="search" name="q" value="<?= e($q) ?>"></label>
        <label class="field">Result<select name="result"><option value="">All</option><option value="failed"<?= get_str('result') === 'failed' ? ' selected' : '' ?>>Failed only</option></select></label>
    <?php else: ?>
        <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Target, details, admin email, record ID"></label>
        <label class="field">Administrator<select name="admin_id"><option value="">All</option><?php foreach ($admins as $ad): ?><option value="<?= (int) $ad['id'] ?>"<?= $adminId === (int) $ad['id'] ? ' selected' : '' ?>><?= e($ad['full_name']) ?></option><?php endforeach; ?></select></label>
        <label class="field">Action<select name="action"><option value="">All</option><?php foreach ($actions as $ac): ?><option value="<?= e($ac['action']) ?>"<?= $action === $ac['action'] ? ' selected' : '' ?>><?= e(humanize($ac['action'])) ?></option><?php endforeach; ?></select></label>
        <label class="field">Record type<select name="target"><option value="">All</option><?php foreach ($targets as $tg): ?><option value="<?= e($tg['target_type']) ?>"<?= $targetType === $tg['target_type'] ? ' selected' : '' ?>><?= e(humanize($tg['target_type'])) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="field">From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-ghost" href="activity-logs.php<?= $tab === 'logins' ? '?tab=logins' : '' ?>">Reset</a></div>
</form>

<div class="card table-card">
    <div class="table-wrap">
    <?php if ($tab === 'logins'): ?>
        <table class="table">
            <thead><tr><th>Time</th><th>Email</th><th>IP address</th><th>Result</th></tr></thead>
            <tbody><?php foreach ($rows as $r): ?>
                <tr><td class="nowrap"><?= fmt_date($r['attempted_at'], true) ?></td><td><?= e($r['email']) ?></td><td class="mono"><?= e($r['ip_address']) ?></td><td><?= $r['success'] ? badge('success', 'Success') : badge('failed') ?></td></tr>
            <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="4" class="empty">No attempts.</td></tr><?php endif; ?></tbody>
        </table>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Affected record</th><th>Details</th><th>IP</th></tr></thead>
            <tbody><?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap small"><?= fmt_date($r['created_at'], true) ?></td>
                    <td><?= e($r['admin_name'] ?: 'System') ?><div class="muted small"><?= e(role_label((string) $r['admin_role'])) ?></div></td>
                    <td><span class="tag"><?= e(humanize($r['action'])) ?></span></td>
                    <td><?= $targetLink($r) ?></td>
                    <td class="details-cell"><?php if ($r['details']): $pretty = json_encode(json_decode($r['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                        <details><summary class="small"><?= e(mb_strimwidth((string) $r['details'], 0, 70, '…')) ?></summary><pre><?= e($pretty ?: $r['details']) ?></pre></details>
                    <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td class="mono small"><?= e($r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="empty">No log entries.</td></tr><?php endif; ?></tbody>
        </table>
    <?php endif; ?>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
