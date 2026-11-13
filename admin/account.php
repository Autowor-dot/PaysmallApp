<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    require_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash = (string) (db_select('SELECT password_hash FROM admin_users WHERE id = ?', 'i', [(int) $admin['id']])[0]['password_hash'] ?? '');
    if (!password_verify($current, $hash)) {
        audit('admin_password_change_failed', 'admin', $admin['id'], $admin['email']);
        flash('error', 'Your current password is incorrect.');
    } elseif (($p = admin_password_problem($new)) !== null) {
        flash('error', $p);
    } elseif ($new !== $confirm) {
        flash('error', 'The new passwords do not match.');
    } elseif (hash_equals($current, $new)) {
        flash('error', 'Choose a password different from the current one.');
    } else {
        db_execute('UPDATE admin_users SET password_hash = ? WHERE id = ?', 'si', [password_hash($new, PASSWORD_DEFAULT), (int) $admin['id']]);
        session_regenerate_id(true);
        audit('admin_password_changed', 'admin', $admin['id'], $admin['email']);
        flash('success', 'Password changed.');
    }
    redirect('account.php');
}

$recent = db_select("SELECT * FROM admin_audit_log WHERE admin_id = ? ORDER BY id DESC LIMIT 10", 'i', [(int) $admin['id']]);

$pageTitle = 'My account';
$pageSubtitle = $admin['email'] . ' · ' . role_label($admin['role']);
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<section class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Change password</h3></div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <label class="field full">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label class="field">New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
            <label class="field">Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
            <p class="small muted full">At least 12 characters with letters and numbers.</p>
            <button class="btn btn-primary" type="submit">Update password</button>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><h3>Session</h3></div>
        <dl class="dl">
            <dt>Previous sign-in</dt><dd><?= fmt_date($_SESSION['admin_prev_login'] ?? null, true) ?></dd>
            <dt>Idle timeout</dt><dd><?= (int) (admin_session_timeout() / 60) ?> minutes</dd>
            <dt>Your IP</dt><dd class="mono"><?= e(client_ip()) ?></dd>
        </dl>
        <div class="card-head"><h3>Your recent actions</h3></div>
        <ul class="list"><?php foreach ($recent as $r): ?>
            <li><div><?= e(humanize($r['action'])) ?><div class="muted small"><?= e($r['target_label'] ?? '') ?></div></div><div class="muted small"><?= fmt_date($r['created_at'], true) ?></div></li>
        <?php endforeach; ?></ul>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
