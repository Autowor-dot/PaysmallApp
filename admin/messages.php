<?php
/**
 * Contact-form messages (contact_messages): list, search, mark read/replied/archived.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_cases');

if (!table_exists('contact_messages')) {
    $pageTitle = 'Messages';
    $activeNav = 'messages';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card empty-state"><i class="fa-solid fa-envelope"></i><h2>Contact messages are not set up yet</h2><p>Run <code>api/install.php</code> (schema migration 002) to create the <code>contact_messages</code> table.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_cases')) {
        flash('error', 'Your role cannot update messages.');
        redirect('messages.php');
    }
    $id = post_int('id');
    $status = post_str('status', 20);
    if ($id > 0 && in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
        $row = db_select('SELECT * FROM contact_messages WHERE id = ? LIMIT 1', 'i', [$id])[0] ?? null;
        if ($row !== null) {
            db_execute('UPDATE contact_messages SET status = ? WHERE id = ?', 'si', [$status, $id]);
            audit('contact_message_' . $status, 'contact_message', $id, $row['subject'], ['from' => $row['status'], 'to' => $status]);
            flash('success', 'Message marked as ' . $status . '.');
        }
    }
    redirect(url_with([]));
}

$q = get_str('q');
$status = get_str('status', 20);
$w = new Where();
if (in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
    $w->add('m.status = ?', 's', [$status]);
} elseif ($status === '') {
    $w->add("m.status <> 'archived'");
}
if ($q !== '') {
    $w->add('(m.name LIKE ? OR m.email LIKE ? OR m.subject LIKE ? OR m.message LIKE ?)', 'ssss', array_fill(0, 4, like($q)));
}
$fromSql = ' FROM contact_messages m ';
$pg = paginate(count_rows($fromSql, $w), 25);
$rows = db_select('SELECT m.*' . $fromSql . $w->sql() . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $w->types, $w->params);
$counts = [];
foreach (db_select('SELECT status, COUNT(*) n FROM contact_messages GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['n'];
}
$canManage = admin_can('manage_cases');

$pageTitle = 'Messages';
$pageSubtitle = 'Messages sent through the website contact form.';
$activeNav = 'messages';
require __DIR__ . '/includes/header.php';
?>
<div class="kpi-grid" style="margin-bottom: 18px;">
    <a class="kpi card" href="messages.php?status=new"><div><div class="kpi-label">New</div><div class="kpi-value"><?= $counts['new'] ?? 0 ?></div></div></a>
    <a class="kpi card" href="messages.php?status=read"><div><div class="kpi-label">Read</div><div class="kpi-value"><?= $counts['read'] ?? 0 ?></div></div></a>
    <a class="kpi card" href="messages.php?status=replied"><div><div class="kpi-label">Replied</div><div class="kpi-value"><?= $counts['replied'] ?? 0 ?></div></div></a>
    <a class="kpi card" href="messages.php?status=archived"><div><div class="kpi-label">Archived</div><div class="kpi-value"><?= $counts['archived'] ?? 0 ?></div></div></a>
</div>

<div class="card">
    <form method="get" class="filters">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, subject or text">
        <select name="status">
            <option value="">Inbox (not archived)</option>
            <?php foreach (['new', 'read', 'replied', 'archived'] as $s): ?>
                <option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <a class="btn btn-ghost" href="messages.php">Reset</a>
    </form>

    <?php if (empty($rows)): ?>
        <div class="empty-state"><i class="fa-solid fa-envelope-open"></i><h2>No messages</h2><p>Nothing matches these filters.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Received</th><th>From</th><th>Subject &amp; message</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $m): ?>
                <tr>
                    <td class="nowrap"><?= fmt_date($m['created_at'], true) ?></td>
                    <td>
                        <strong><?= e($m['name']) ?></strong><br>
                        <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>"><?= e($m['email']) ?></a>
                        <?php if (!empty($m['phone'])): ?><br><span class="muted"><?= e($m['phone']) ?></span><?php endif; ?>
                        <?php if (!empty($m['user_id'])): ?><br><a class="small" href="user.php?id=<?= (int) $m['user_id'] ?>">Registered user #<?= (int) $m['user_id'] ?></a><?php endif; ?>
                    </td>
                    <td style="max-width: 520px;">
                        <strong><?= e($m['subject']) ?></strong>
                        <p class="muted" style="white-space: pre-wrap; margin: 4px 0 0;"><?= e($m['message']) ?></p>
                    </td>
                    <td><?= badge($m['status'] === 'new' ? 'pending' : $m['status'], ucfirst($m['status'])) ?></td>
                    <td class="nowrap">
                        <?php if ($canManage): ?>
                            <form method="post" style="display: inline-block; margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <select name="status" onchange="this.form.submit()" aria-label="Change status">
                                    <?php foreach (['new', 'read', 'replied', 'archived'] as $s): ?>
                                        <option value="<?= $s ?>"<?= $m['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
