<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_users');

$q = get_str('q');
$status = get_str('status', 20);
$role = get_str('role', 20);
$from = get_date('from');
$to = get_date('to');

$fromSql = ' FROM users u
    LEFT JOIN (SELECT customer_id, COUNT(*) n, SUM(total_price) v FROM agreements GROUP BY customer_id) ag ON ag.customer_id = u.id
    LEFT JOIN (SELECT user_id, COUNT(*) n FROM sellers WHERE user_id IS NOT NULL GROUP BY user_id) st ON st.user_id = u.id ';
$w = new Where();
if ($q !== '') {
    $digits = normalize_phone($q);
    $w->add('(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)', 'sss', [like($q), like($q), like($digits !== '' ? $digits : $q)]);
}
if (in_array($status, ['active', 'suspended', 'deactivated'], true)) {
    $w->add('u.status = ?', 's', [$status]);
}
if ($role === 'customer') {
    $w->add('COALESCE(ag.n,0) > 0');
} elseif ($role === 'seller') {
    $w->add('COALESCE(st.n,0) > 0');
} elseif ($role === 'both') {
    $w->add('COALESCE(ag.n,0) > 0 AND COALESCE(st.n,0) > 0');
} elseif ($role === 'none') {
    $w->add('COALESCE(ag.n,0) = 0 AND COALESCE(st.n,0) = 0');
}
if ($from !== '') {
    $w->add('u.created_at >= ?', 's', [$from . ' 00:00:00']);
}
if ($to !== '') {
    $w->add('u.created_at <= ?', 's', [$to . ' 23:59:59']);
}

$select = 'SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at, u.last_login_at, COALESCE(ag.n,0) agreements, COALESCE(ag.v,0) agreement_value, COALESCE(st.n,0) stores';

if (get_str('export') === 'csv' && admin_can('view_reports')) {
    $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY u.created_at DESC', $w->types, $w->params);
    audit('export_users_csv', 'report', null, 'users.csv', ['filters' => $_GET, 'rows' => count($rows)]);
    send_csv('users-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Phone', 'Status', 'Agreements', 'Agreement value (TZS)', 'Stores', 'Registered', 'Last login'],
        array_map(static fn($r) => [$r['id'], $r['full_name'], $r['email'], $r['phone'], $r['status'], $r['agreements'], $r['agreement_value'], $r['stores'], $r['created_at'], $r['last_login_at']], $rows));
}

$pg = paginate(count_rows($fromSql, $w));
$rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY u.created_at DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);

$pageTitle = 'Users';
$pageSubtitle = 'Every registered account. A user is a customer when they have created an agreement and a seller when they own a store.';
$activeNav = 'users';
$pageActions = admin_can('view_reports') ? '<a class="btn" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<form class="card filters" method="get">
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone"></label>
    <label class="field">Status
        <select name="status"><option value="">All</option>
            <?php foreach (['active', 'suspended', 'deactivated'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= humanize($s) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Type
        <select name="role"><option value="">All</option>
            <?php foreach (['customer' => 'Customers', 'seller' => 'Sellers', 'both' => 'Customer & seller', 'none' => 'No activity yet'] as $k => $l): ?><option value="<?= $k ?>"<?= $role === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Registered from<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">to<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button><a class="btn btn-ghost" href="users.php">Reset</a></div>
</form>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>User</th><th>Phone</th><th>Type</th><th class="num">Agreements</th><th>Status</th><th>Registered</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="strong" href="user.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><div class="muted small"><?= e($r['email']) ?></div></td>
                    <td><?= e($r['phone']) ?></td>
                    <td>
                        <?php if ($r['agreements'] > 0): ?><span class="tag">Customer</span><?php endif; ?>
                        <?php if ($r['stores'] > 0): ?><span class="tag tag-violet">Seller</span><?php endif; ?>
                        <?php if ($r['agreements'] == 0 && $r['stores'] == 0): ?><span class="muted small">—</span><?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $r['agreements']) ?></td>
                    <td><?= badge($r['status']) ?></td>
                    <td><?= fmt_date($r['created_at']) ?></td>
                    <td class="muted small"><?= e(time_ago($r['last_login_at'])) ?></td>
                    <td class="right"><a class="btn btn-sm" href="user.php?id=<?= (int) $r['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="empty">No users match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
