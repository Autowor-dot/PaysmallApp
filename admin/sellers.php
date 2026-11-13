<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_sellers');

$q = get_str('q');
$status = get_str('status', 20);
$category = get_str('category', 80);
$from = get_date('from');
$to = get_date('to');

// Agreements belong to a seller by seller_id or, for email invitations, by seller email
$fromSql = " FROM sellers s
    LEFT JOIN users ou ON ou.id = s.user_id
    LEFT JOIN (
        SELECT s2.id AS sid, COUNT(a.id) n,
               SUM(a.status = 'accepted') accepted,
               COALESCE(SUM(CASE WHEN a.status = 'accepted' THEN a.total_price END),0) value
        FROM sellers s2 JOIN agreements a ON (a.seller_id = s2.id OR (a.seller_id IS NULL AND LOWER(a.seller_email) = LOWER(s2.email)))
        GROUP BY s2.id
    ) ag ON ag.sid = s.id
    LEFT JOIN (SELECT seller_id, COUNT(*) n FROM products WHERE seller_id IS NOT NULL GROUP BY seller_id) pr ON pr.seller_id = s.id ";
$w = new Where();
if ($q !== '') {
    $digits = normalize_phone($q);
    $w->add('(s.business_name LIKE ? OR s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.location LIKE ?)', 'sssss',
        [like($q), like($q), like($q), like($digits !== '' ? $digits : $q), like($q)]);
}
if (in_array($status, ['pending', 'verified', 'suspended'], true)) {
    $w->add('s.status = ?', 's', [$status]);
}
if ($category !== '') {
    $w->add('s.category = ?', 's', [$category]);
}
if ($from !== '') $w->add('s.created_at >= ?', 's', [$from . ' 00:00:00']);
if ($to !== '') $w->add('s.created_at <= ?', 's', [$to . ' 23:59:59']);

$select = 'SELECT s.*, ou.full_name AS owner_account, COALESCE(ag.n,0) agreements, COALESCE(ag.accepted,0) accepted, COALESCE(ag.value,0) value, COALESCE(pr.n,0) products';

if (get_str('export') === 'csv' && admin_can('view_reports')) {
    $rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY s.created_at DESC', $w->types, $w->params);
    audit('export_sellers_csv', 'report', null, 'sellers.csv', ['filters' => $_GET, 'rows' => count($rows)]);
    send_csv('sellers-' . date('Ymd') . '.csv', ['ID', 'Business', 'Owner', 'Email', 'Phone', 'Location', 'Category', 'Payout', 'Status', 'Agreements', 'Accepted', 'Accepted value (TZS)', 'Products', 'Registered'],
        array_map(static fn($r) => [$r['id'], $r['business_name'], $r['full_name'], $r['email'], $r['phone'], $r['location'], $r['category'], $r['payout_method'], $r['status'], $r['agreements'], $r['accepted'], $r['value'], $r['products'], $r['created_at']], $rows));
}

$pg = paginate(count_rows($fromSql, $w));
$rows = db_select($select . $fromSql . $w->sql() . ' ORDER BY s.created_at DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
$categories = db_select("SELECT DISTINCT category FROM sellers WHERE category IS NOT NULL AND category <> '' ORDER BY category");

$pageTitle = 'Sellers';
$pageSubtitle = 'Registered stores and merchants, with their agreement activity.';
$activeNav = 'sellers';
$pageActions = admin_can('view_reports') ? '<a class="btn" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<form class="card filters" method="get">
    <label class="field grow">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Business, owner, email, phone, location"></label>
    <label class="field">Status
        <select name="status"><option value="">All</option>
            <?php foreach (['pending', 'verified', 'suspended'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= humanize($s) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">Category
        <select name="category"><option value="">All</option>
            <?php foreach ($categories as $c): ?><option value="<?= e($c['category']) ?>"<?= $category === $c['category'] ? ' selected' : '' ?>><?= e($c['category']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field">To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button><a class="btn btn-ghost" href="sellers.php">Reset</a></div>
</form>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Business</th><th>Contact</th><th>Category</th><th class="num">Agreements</th><th class="num">Accepted value</th><th class="num">Products</th><th>Status</th><th>Registered</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="strong" href="seller.php?id=<?= (int) $r['id'] ?>"><?= e($r['business_name']) ?></a><div class="muted small"><?= e($r['full_name']) ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?></div></td>
                    <td><?= e($r['email']) ?><div class="muted small"><?= e($r['phone']) ?></div></td>
                    <td><?= e($r['category'] ?: '—') ?></td>
                    <td class="num"><?= number_format((int) $r['agreements']) ?> <span class="muted small">(<?= (int) $r['accepted'] ?> accepted)</span></td>
                    <td class="num"><?= tzs($r['value']) ?></td>
                    <td class="num"><?= number_format((int) $r['products']) ?></td>
                    <td><?= badge($r['status']) ?></td>
                    <td><?= fmt_date($r['created_at']) ?></td>
                    <td class="right"><a class="btn btn-sm" href="seller.php?id=<?= (int) $r['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="9" class="empty">No sellers match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
