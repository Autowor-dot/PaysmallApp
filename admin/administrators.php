<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_admins');

$activeSupers = static fn(): int => (int) scalar("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin' AND status = 'active'");

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_admins')) {
        flash('error', 'Only a Super Admin can manage administrator accounts.');
        redirect('administrators.php');
    }
    $action = post_str('action', 20);

    if ($action === 'create') {
        $name = post_str('full_name', 120);
        $email = strtolower(post_str('email', 190));
        $role = post_str('role', 20);
        $pass = (string) ($_POST['password'] ?? '');
        $err = null;
        if (mb_strlen($name) < 2) $err = 'Enter the administrator\'s full name.';
        elseif (!is_valid_email($email)) $err = 'Enter a valid email.';
        elseif (!isset(ADMIN_ROLES[$role])) $err = 'Choose a role.';
        elseif (($p = admin_password_problem($pass)) !== null) $err = $p;
        elseif (!empty(db_select('SELECT id FROM admin_users WHERE email = ?', 's', [$email]))) $err = 'An administrator with this email already exists.';
        if ($err) {
            flash('error', $err);
            redirect('administrators.php?new=1');
        }
        db_execute('INSERT INTO admin_users (full_name, email, password_hash, role, status, created_by) VALUES (?, ?, ?, ?, \'active\', ?)', 'ssssi',
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, (int) $admin['id']]);
        $newId = (int) db()->insert_id;
        audit('admin_created', 'admin', $newId, $email, ['role' => $role, 'full_name' => $name]);
        flash('success', role_label($role) . ' account created for ' . $email . '. Share the temporary password privately and ask them to change it after signing in.');
        redirect('administrators.php');
    }

    $targetId = post_int('admin_id');
    $target = db_select('SELECT * FROM admin_users WHERE id = ? LIMIT 1', 'i', [$targetId])[0] ?? null;
    if ($target === null) {
        flash('error', 'Administrator not found.');
        redirect('administrators.php');
    }
    $isSelf = (int) $target['id'] === (int) $admin['id'];

    if ($action === 'update') {
        $role = post_str('role', 20);
        $status = post_str('status', 10);
        $name = post_str('full_name', 120);
        if (!isset(ADMIN_ROLES[$role]) || !in_array($status, ['active', 'inactive'], true) || mb_strlen($name) < 2) {
            flash('error', 'Invalid values.');
            redirect('administrators.php');
        }
        if ($isSelf && ($role !== $target['role'] || $status !== $target['status'])) {
            flash('error', 'You cannot change your own role or deactivate yourself. Ask another Super Admin.');
            redirect('administrators.php');
        }
        $losingSuper = $target['role'] === 'super_admin' && $target['status'] === 'active' && ($role !== 'super_admin' || $status !== 'active');
        if ($losingSuper && $activeSupers() <= 1) {
            flash('error', 'There must always be at least one active Super Admin.');
            redirect('administrators.php');
        }
        db_execute('UPDATE admin_users SET full_name = ?, role = ?, status = ? WHERE id = ?', 'sssi', [$name, $role, $status, $targetId]);
        audit($status !== $target['status'] ? ($status === 'active' ? 'admin_reactivated' : 'admin_deactivated') : 'admin_updated', 'admin', $targetId, $target['email'], [
            'from' => ['full_name' => $target['full_name'], 'role' => $target['role'], 'status' => $target['status']],
            'to' => ['full_name' => $name, 'role' => $role, 'status' => $status]]);
        flash('success', 'Administrator updated.');
        redirect('administrators.php');
    }

    if ($action === 'reset_password') {
        $pass = (string) ($_POST['password'] ?? '');
        if (($p = admin_password_problem($pass)) !== null) {
            flash('error', $p);
            redirect('administrators.php');
        }
        db_execute('UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?', 'si', [password_hash($pass, PASSWORD_DEFAULT), $targetId]);
        audit('admin_password_reset', 'admin', $targetId, $target['email']);
        flash('success', 'Password reset for ' . $target['email'] . '. Any lock on the account was cleared.');
        redirect('administrators.php');
    }

    if ($action === 'unlock') {
        db_execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', 'i', [$targetId]);
        audit('admin_unlocked', 'admin', $targetId, $target['email']);
        flash('success', 'Account unlocked.');
        redirect('administrators.php');
    }
    flash('error', 'Unknown action.');
    redirect('administrators.php');
}

$admins = db_select('SELECT a.*, c.full_name AS creator FROM admin_users a LEFT JOIN admin_users c ON c.id = a.created_by ORDER BY FIELD(a.role,\'super_admin\',\'admin\',\'support\'), a.full_name');
$canManage = admin_can('manage_admins');

$pageTitle = 'Administrators';
$pageSubtitle = 'Admin accounts are separate from customer and seller accounts.';
$activeNav = 'admins';
$pageActions = $canManage ? '<a class="btn btn-primary" href="administrators.php?new=1"><i class="fa-solid fa-user-plus"></i> Add administrator</a>' : '';
require __DIR__ . '/includes/header.php';
?>
<section class="grid-3 roles">
    <div class="card"><h3><?= badge('active', 'Super Admin') ?></h3><p class="small">Full control: users, sellers, agreements, payments, reports, all audit logs, administrators and website/security settings.</p></div>
    <div class="card"><h3><?= badge('blue', 'Admin') ?></h3><p class="small">Manages users, sellers, agreements, payment investigations and reports; views logs. Cannot manage administrators or change settings.</p></div>
    <div class="card"><h3><?= badge('pending', 'Support Admin') ?></h3><p class="small">Views customers, sellers, agreements and payments; handles complaints and dispute cases. No account changes, reports or settings.</p></div>
</section>

<?php if ($canManage && get_int('new') === 1): ?>
<div class="card">
    <div class="card-head"><h3>Add administrator</h3></div>
    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <label class="field">Full name<input name="full_name" required maxlength="120"></label>
        <label class="field">Email<input type="email" name="email" required maxlength="190"></label>
        <label class="field">Role<select name="role"><option value="support">Support Admin</option><option value="admin">Admin</option><option value="super_admin">Super Admin</option></select></label>
        <label class="field">Temporary password <small>(12+ chars, letters &amp; numbers)</small>
            <span class="input-row"><input type="text" name="password" required minlength="12" data-password-target autocomplete="new-password"><button class="btn btn-sm" type="button" data-generate-password>Generate</button></span>
        </label>
        <div class="btn-row full"><button class="btn btn-primary" type="submit" data-confirm="Create this administrator account?">Create account</button><a class="btn btn-ghost" href="administrators.php">Cancel</a></div>
    </form>
</div>
<?php endif; ?>

<div class="card table-card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Administrator</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Created</th><?php if ($canManage): ?><th>Manage</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($admins as $a):
            $locked = $a['locked_until'] && strtotime($a['locked_until']) > time();
            $self = (int) $a['id'] === (int) $admin['id']; ?>
            <tr>
                <td><strong><?= e($a['full_name']) ?></strong><?= $self ? ' <span class="tag">you</span>' : '' ?><div class="muted small"><?= e($a['email']) ?></div></td>
                <td><?= e(role_label($a['role'])) ?></td>
                <td><?= badge($a['status']) ?><?= $locked ? ' ' . badge('failed', 'Locked') : '' ?></td>
                <td class="small"><?= fmt_date($a['last_login_at'], true) ?><div class="muted mono"><?= e($a['last_login_ip'] ?? '') ?></div></td>
                <td class="small"><?= fmt_date($a['created_at']) ?><div class="muted"><?= e($a['creator'] ?: 'setup') ?></div></td>
                <?php if ($canManage): ?>
                <td>
                    <details class="manage">
                        <summary class="btn btn-sm">Edit</summary>
                        <div class="manage-body">
                            <form method="post" class="form-grid">
                                <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                <label class="field full">Name<input name="full_name" value="<?= e($a['full_name']) ?>" required maxlength="120"></label>
                                <label class="field">Role<select name="role"<?= $self ? ' disabled' : '' ?>><?php foreach (ADMIN_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $a['role'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
                                <label class="field">Status<select name="status"<?= $self ? ' disabled' : '' ?>><option value="active"<?= $a['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $a['status'] === 'inactive' ? ' selected' : '' ?>>Deactivated</option></select></label>
                                <?php if ($self): ?><input type="hidden" name="role" value="<?= e($a['role']) ?>"><input type="hidden" name="status" value="<?= e($a['status']) ?>"><?php endif; ?>
                                <button class="btn btn-primary btn-sm" type="submit" data-confirm="Save changes to <?= e($a['email']) ?>?">Save</button>
                            </form>
                            <?php if (!$self): ?>
                            <form method="post" class="form-grid">
                                <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                <label class="field full">New temporary password<span class="input-row"><input type="text" name="password" minlength="12" required data-password-target autocomplete="new-password"><button class="btn btn-sm" type="button" data-generate-password>Generate</button></span></label>
                                <button class="btn btn-warning btn-sm" type="submit" data-confirm="Reset the password for <?= e($a['email']) ?>?">Reset password</button>
                            </form>
                            <?php if ($locked): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-sm" type="submit">Unlock now</button></form>
                            <?php endif; endif; ?>
                        </div>
                    </details>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
