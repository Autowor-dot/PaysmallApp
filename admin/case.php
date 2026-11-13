<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_cases');

$id = get_int('id');
$c = db_select('SELECT c.*, u.full_name, u.email, u.phone, s.business_name, a.agreement_code, t.transaction_code, t.amount AS tx_amount, t.status AS tx_status,
        ad.full_name AS assignee, cr.full_name AS creator
    FROM admin_cases c
    LEFT JOIN users u ON u.id = c.user_id LEFT JOIN sellers s ON s.id = c.seller_id
    LEFT JOIN agreements a ON a.id = c.agreement_id LEFT JOIN transactions t ON t.id = c.transaction_id
    LEFT JOIN admin_users ad ON ad.id = c.assigned_to LEFT JOIN admin_users cr ON cr.id = c.created_by
    WHERE c.id = ? LIMIT 1', 'i', [$id])[0] ?? null;
if ($c === null) {
    flash('error', 'Case not found.');
    redirect('cases.php');
}
$self = 'case.php?id=' . $id;

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_cases')) {
        flash('error', 'Your role cannot update cases.');
        redirect($self);
    }
    $action = post_str('action', 20);
    if ($action === 'note') {
        $note = post_str('note', 5000);
        if (mb_strlen($note) < 2) {
            flash('error', 'The note is empty.');
            redirect($self);
        }
        db_execute('INSERT INTO admin_case_notes (case_id, admin_id, admin_name, note) VALUES (?, ?, ?, ?)', 'iiss', [$id, (int) $admin['id'], $admin['full_name'], $note]);
        if ($c['status'] === 'open') {
            db_execute("UPDATE admin_cases SET status = 'investigating' WHERE id = ?", 'i', [$id]);
        }
        audit('case_note_added', 'case', $id, $c['case_code'], ['note' => mb_substr($note, 0, 300)]);
        flash('success', 'Note added.');
        redirect($self);
    }
    if ($action === 'update') {
        $status = post_str('status', 20);
        $priority = post_str('priority', 10);
        $assignee = post_int('assigned_to');
        $resolution = post_str('resolution', 5000);
        if (!in_array($status, ['open', 'investigating', 'resolved', 'dismissed'], true) || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            flash('error', 'Invalid status or priority.');
            redirect($self);
        }
        if (in_array($status, ['resolved', 'dismissed'], true) && mb_strlen($resolution) < 5) {
            flash('error', 'Describe the resolution (at least 5 characters) before closing a case.');
            redirect($self);
        }
        if ($assignee && empty(db_select("SELECT id FROM admin_users WHERE id = ? AND status = 'active'", 'i', [$assignee]))) {
            $assignee = 0;
        }
        $closing = in_array($status, ['resolved', 'dismissed'], true);
        db_execute('UPDATE admin_cases SET status = ?, priority = ?, assigned_to = ?, resolution = ?, resolved_at = ' . ($closing ? 'COALESCE(resolved_at, NOW())' : 'NULL') . ' WHERE id = ?', 'ssisi',
            [$status, $priority, $assignee ?: null, $resolution !== '' ? $resolution : null, $id]);
        audit('case_updated', 'case', $id, $c['case_code'], [
            'from' => ['status' => $c['status'], 'priority' => $c['priority'], 'assigned_to' => $c['assigned_to']],
            'to' => ['status' => $status, 'priority' => $priority, 'assigned_to' => $assignee ?: null], 'resolution' => $resolution]);
        flash('success', 'Case updated.');
        redirect($self);
    }
    flash('error', 'Unknown action.');
    redirect($self);
}

$notes = db_select('SELECT * FROM admin_case_notes WHERE case_id = ? ORDER BY created_at ASC, id ASC', 'i', [$id]);
$admins = db_select("SELECT id, full_name, role FROM admin_users WHERE status = 'active' ORDER BY full_name");

$pageTitle = 'Case ' . $c['case_code'];
$pageSubtitle = $c['subject'];
$activeNav = 'cases';
$pageActions = '<a class="btn" href="cases.php"><i class="fa-solid fa-arrow-left"></i> All cases</a>';
require __DIR__ . '/includes/header.php';
?>
<section class="grid-profile">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><?= e(humanize($c['type'])) ?></h3><div><?= badge($c['priority']) ?> <?= badge($c['status']) ?></div></div>
            <p class="pre"><?= e($c['description'] ?: 'No description.') ?></p>
            <dl class="dl">
                <dt>Customer</dt><dd><?= $c['user_id'] ? '<a href="user.php?id=' . (int) $c['user_id'] . '">' . e($c['full_name']) . '</a> · ' . e($c['phone']) : '—' ?></dd>
                <dt>Seller</dt><dd><?= $c['seller_id'] ? '<a href="seller.php?id=' . (int) $c['seller_id'] . '">' . e($c['business_name']) . '</a>' : '—' ?></dd>
                <dt>Agreement</dt><dd><?= $c['agreement_id'] ? '<a class="mono" href="agreement.php?id=' . (int) $c['agreement_id'] . '">' . e($c['agreement_code']) . '</a>' : '—' ?></dd>
                <dt>Payment</dt><dd><?= $c['transaction_id'] ? '<a class="mono" href="payment.php?id=' . (int) $c['transaction_id'] . '">' . e($c['transaction_code']) . '</a> ' . tzs($c['tx_amount']) . ' ' . badge($c['tx_status']) : '—' ?></dd>
                <dt>Opened</dt><dd><?= fmt_date($c['created_at'], true) ?> by <?= e($c['creator'] ?: '—') ?></dd>
                <dt>Assigned to</dt><dd><?= e($c['assignee'] ?: 'Unassigned') ?></dd>
                <?php if ($c['resolution']): ?><dt>Resolution</dt><dd><?= e($c['resolution']) ?> <span class="muted small"><?= fmt_date($c['resolved_at'], true) ?></span></dd><?php endif; ?>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><h3>Investigation notes</h3></div>
            <ul class="timeline">
                <?php foreach ($notes as $n): ?>
                    <li><div class="timeline-meta"><strong><?= e($n['admin_name']) ?></strong> · <?= fmt_date($n['created_at'], true) ?></div><div class="pre"><?= e($n['note']) ?></div></li>
                <?php endforeach; ?>
                <?php if (!$notes): ?><li class="muted">No notes yet.</li><?php endif; ?>
            </ul>
            <?php if (admin_can('manage_cases')): ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="note">
                <label class="field full">Add note<textarea name="note" rows="3" maxlength="5000" required placeholder="Findings, contact with customer/seller, provider statement checks…"></textarea></label>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-comment-medical"></i> Add note</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if (admin_can('manage_cases')): ?>
    <div class="card">
        <div class="card-head"><h3>Update case</h3></div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="update">
            <label class="field">Status<select name="status"><?php foreach (['open', 'investigating', 'resolved', 'dismissed'] as $s): ?><option value="<?= $s ?>"<?= $c['status'] === $s ? ' selected' : '' ?>><?= humanize($s) ?></option><?php endforeach; ?></select></label>
            <label class="field">Priority<select name="priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?><option value="<?= $p ?>"<?= $c['priority'] === $p ? ' selected' : '' ?>><?= humanize($p) ?></option><?php endforeach; ?></select></label>
            <label class="field full">Assign to<select name="assigned_to"><option value="0">Unassigned</option><?php foreach ($admins as $ad): ?><option value="<?= (int) $ad['id'] ?>"<?= (int) $c['assigned_to'] === (int) $ad['id'] ? ' selected' : '' ?>><?= e($ad['full_name']) ?> (<?= e(role_label($ad['role'])) ?>)</option><?php endforeach; ?></select></label>
            <label class="field full">Resolution <small>(required to resolve or dismiss)</small><textarea name="resolution" rows="4" maxlength="5000"><?= e($c['resolution']) ?></textarea></label>
            <button class="btn btn-primary" type="submit" data-confirm="Save changes to this case?">Save</button>
        </form>
        <p class="small muted">Resolving a payment dispute does not change the payment. Payments are only confirmed by the payment provider.</p>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
