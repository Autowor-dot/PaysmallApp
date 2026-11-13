<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_dashboard');

$q = get_str('q', 120);
$results = ['users' => [], 'sellers' => [], 'agreements' => [], 'payments' => []];
if (mb_strlen($q) >= 2) {
    $l = like($q);
    $phone = normalize_phone($q);
    $pl = like($phone !== '' ? $phone : $q);
    if (admin_can('view_users')) {
        $results['users'] = db_select('SELECT id, full_name, email, phone, status FROM users WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY created_at DESC LIMIT 10', 'sss', [$l, $l, $pl]);
    }
    if (admin_can('view_sellers')) {
        $results['sellers'] = db_select('SELECT id, business_name, full_name, email, status FROM sellers WHERE business_name LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY created_at DESC LIMIT 10', 'ssss', [$l, $l, $l, $pl]);
    }
    if (admin_can('view_agreements')) {
        $results['agreements'] = db_select('SELECT a.id, a.agreement_code, a.product_name, a.total_price, a.status, u.full_name FROM agreements a JOIN users u ON u.id = a.customer_id
            WHERE a.agreement_code LIKE ? OR a.product_name LIKE ? OR a.seller_name LIKE ? OR a.seller_email LIKE ? OR u.full_name LIKE ? ORDER BY a.created_at DESC LIMIT 10', 'sssss', [$l, $l, $l, $l, $l]);
    }
    if (admin_can('view_payments')) {
        $results['payments'] = db_select('SELECT t.id, t.transaction_code, t.amount, t.status, t.provider_reference, u.full_name FROM transactions t JOIN users u ON u.id = t.customer_id
            WHERE t.transaction_code LIKE ? OR t.provider_reference LIKE ? OR t.payer_phone LIKE ? ORDER BY t.created_at DESC LIMIT 10', 'sss', [$l, $l, $pl]);
    }
}
$total = array_sum(array_map('count', $results));

$pageTitle = 'Search';
$pageSubtitle = $q === '' ? 'Type at least 2 characters in the search bar.' : $total . ' result(s) for “' . $q . '”';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<section class="grid-2">
    <div class="card"><div class="card-head"><h3><i class="fa-solid fa-users"></i> Users</h3></div><ul class="list">
        <?php foreach ($results['users'] as $r): ?><li><div><a class="strong" href="user.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><div class="muted small"><?= e($r['email']) ?> · <?= e($r['phone']) ?></div></div><div><?= badge($r['status']) ?></div></li><?php endforeach; ?>
        <?php if (!$results['users']): ?><li class="muted">No users.</li><?php endif; ?></ul></div>
    <div class="card"><div class="card-head"><h3><i class="fa-solid fa-store"></i> Sellers</h3></div><ul class="list">
        <?php foreach ($results['sellers'] as $r): ?><li><div><a class="strong" href="seller.php?id=<?= (int) $r['id'] ?>"><?= e($r['business_name']) ?></a><div class="muted small"><?= e($r['full_name']) ?> · <?= e($r['email']) ?></div></div><div><?= badge($r['status']) ?></div></li><?php endforeach; ?>
        <?php if (!$results['sellers']): ?><li class="muted">No sellers.</li><?php endif; ?></ul></div>
    <div class="card"><div class="card-head"><h3><i class="fa-solid fa-file-signature"></i> Agreements</h3></div><ul class="list">
        <?php foreach ($results['agreements'] as $r): ?><li><div><a class="strong mono" href="agreement.php?id=<?= (int) $r['id'] ?>"><?= e($r['agreement_code']) ?></a><div class="muted small"><?= e($r['product_name']) ?> · <?= e($r['full_name']) ?></div></div><div class="right"><?= tzs($r['total_price']) ?><br><?= badge($r['status']) ?></div></li><?php endforeach; ?>
        <?php if (!$results['agreements']): ?><li class="muted">No agreements.</li><?php endif; ?></ul></div>
    <div class="card"><div class="card-head"><h3><i class="fa-solid fa-money-bill-transfer"></i> Payments</h3></div><ul class="list">
        <?php foreach ($results['payments'] as $r): ?><li><div><a class="strong mono" href="payment.php?id=<?= (int) $r['id'] ?>"><?= e($r['transaction_code']) ?></a><div class="muted small"><?= e($r['full_name']) ?><?= $r['provider_reference'] ? ' · ' . e($r['provider_reference']) : '' ?></div></div><div class="right"><?= tzs($r['amount']) ?><br><?= badge($r['status']) ?></div></li><?php endforeach; ?>
        <?php if (!$results['payments']): ?><li class="muted">No payments.</li><?php endif; ?></ul></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
