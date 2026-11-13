<?php
/**
 * One-time setup:
 *   1. applies admin/migrations/001_admin_panel.sql (additive, idempotent)
 *   2. creates the FIRST Super Admin — only while no admin account exists.
 *
 * Protected by the server's install_key (api/config.local.php, never in the
 * repository). No default credentials exist anywhere.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$installKey = (string) cfg('install_key', '');
$keyConfigured = $installKey !== '' && $installKey !== 'change-me-before-running-install';

$adminCount = null;
if (table_exists('admin_users')) {
    $adminCount = (int) scalar('SELECT COUNT(*) FROM admin_users');
}
$schemaReady = admin_schema_ready();
$errors = [];
$migration = null;

if (is_post()) {
    require_csrf();

    // Throttle key guessing per session
    $_SESSION['setup_tries'] = (int) ($_SESSION['setup_tries'] ?? 0) + 1;
    if ($_SESSION['setup_tries'] > 10) {
        $errors[] = 'Too many attempts. Close the browser and try again later.';
    } elseif (!$keyConfigured) {
        $errors[] = 'Set a unique install_key in api/config.local.php first.';
    } elseif (!hash_equals($installKey, (string) ($_POST['install_key'] ?? ''))) {
        $errors[] = 'The install key is incorrect.';
    } else {
        $migration = run_sql_file(__DIR__ . '/migrations/001_admin_panel.sql');
        $adminCount = (int) scalar('SELECT COUNT(*) FROM admin_users');

        if ($adminCount === 0) {
            $name  = post_str('full_name', 120);
            $email = strtolower(post_str('email', 190));
            $pass  = (string) ($_POST['password'] ?? '');
            $conf  = (string) ($_POST['password_confirm'] ?? '');

            if (mb_strlen($name) < 2) {
                $errors[] = 'Enter your full name.';
            }
            if (!is_valid_email($email)) {
                $errors[] = 'Enter a valid email address.';
            }
            if (($p = admin_password_problem($pass)) !== null) {
                $errors[] = $p;
            }
            if ($pass !== $conf) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                db_execute(
                    "INSERT INTO admin_users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'super_admin', 'active')",
                    'sss', [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]
                );
                $newId = (int) db()->insert_id;
                $actor = ['id' => $newId, 'full_name' => $name, 'email' => $email, 'role' => 'super_admin'];
                audit('admin_setup_super_admin_created', 'admin', $newId, $email, ['migration' => $migration], $actor);
                unset($_SESSION['setup_tries']);
                flash('success', 'Super Admin account created. Sign in to continue.');
                redirect('login.php');
            }
        } else {
            unset($_SESSION['setup_tries']);
            if (empty($migration['errors'])) {
                flash('success', 'Database migration applied (' . $migration['executed'] . ' statements, ' . $migration['skipped'] . ' already present).');
                redirect('login.php');
            }
        }
    }
}

$needsAdmin = $adminCount === null || $adminCount === 0;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin setup</title>
    <script src="assets/theme-init.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css?v=1">
</head>
<body class="auth-page">
<div class="auth-card wide">
    <div class="auth-brand"><span class="brand-mark"><i class="fa-solid fa-link"></i></span> Admin setup</div>

    <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($err) ?></div>
    <?php endforeach; ?>
    <?php if ($migration && !empty($migration['errors'])): ?>
        <div class="alert alert-error"><strong>Migration errors:</strong><ul><?php foreach ($migration['errors'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!$keyConfigured): ?>
        <h1>Install key required</h1>
        <p class="muted">Add a long random <code>install_key</code> to <code>api/config.local.php</code> on the server, then reload this page.</p>
    <?php elseif ($needsAdmin): ?>
        <h1>Create the Super Admin</h1>
        <p class="muted">This applies the admin database migration (new tables and columns only — no existing data is changed or deleted) and creates the first Super Admin. This page stops accepting new accounts once an administrator exists.</p>
        <form method="post" autocomplete="off" class="form-grid">
            <?= csrf_field() ?>
            <label class="field full">Install key <small>(from api/config.local.php)</small>
                <input type="password" name="install_key" required autocomplete="off">
            </label>
            <label class="field full">Full name<input type="text" name="full_name" required maxlength="120" value="<?= e(post_str('full_name')) ?>"></label>
            <label class="field full">Email<input type="email" name="email" required maxlength="190" value="<?= e(post_str('email')) ?>"></label>
            <label class="field">Password <small>(min 12 chars, letters + numbers)</small><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
            <label class="field">Confirm password<input type="password" name="password_confirm" required minlength="12" autocomplete="new-password"></label>
            <button class="btn btn-primary full" type="submit"><i class="fa-solid fa-user-shield"></i> Apply migration &amp; create Super Admin</button>
        </form>
    <?php else: ?>
        <h1><?= $schemaReady ? 'Setup complete' : 'Apply database update' ?></h1>
        <p class="muted"><?= $schemaReady
            ? 'An administrator already exists. You can re-apply the migration safely after an update.'
            : 'The admin tables are incomplete. Enter the install key to apply the migration.' ?></p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <label class="field full">Install key<input type="password" name="install_key" required autocomplete="off"></label>
            <button class="btn btn-primary full" type="submit"><i class="fa-solid fa-database"></i> Apply migration</button>
        </form>
        <p class="center"><a href="login.php">Go to admin sign-in &rarr;</a></p>
    <?php endif; ?>
</div>
</body>
</html>
