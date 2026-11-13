<?php
/**
 * Admin sign-in (separate from customer/seller login).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!admin_schema_ready()) {
    redirect('setup.php');
}
if (current_admin() !== null) {
    redirect('dashboard.php');
}

$error = null;
$email = '';
$next = get_str('next', 300);
// Only allow redirects to admin pages (no open redirect)
if (!preg_match('/^[a-z-]+\.php(\?[^\s<>"]*)?$/', $next)) {
    $next = 'dashboard.php';
}

if (is_post()) {
    require_csrf();
    $email = strtolower(post_str('email', 190));
    $password = (string) ($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        [$ok, $message] = admin_attempt_login($email, $password);
        if ($ok) {
            flash('success', $message);
            redirect($next);
        }
        $error = $message;
    }
}

$siteName = site_setting('site_name', (string) cfg('app_name', 'LipaLink'));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin sign in · <?= e($siteName) ?></title>
    <script src="assets/theme-init.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css?v=1">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-brand"><span class="brand-mark"><i class="fa-solid fa-link"></i></span> <?= e($siteName) ?> <span class="muted">Admin</span></div>
    <h1>Sign in to the console</h1>
    <p class="muted">Restricted area. All sign-ins are logged.</p>

    <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <label class="field full">Email<input type="email" name="email" required autofocus autocomplete="username" value="<?= e($email) ?>"></label>
        <label class="field full">Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary full" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Sign in</button>
    </form>
    <p class="center small muted"><a href="../index.html">&larr; Back to website</a></p>
</div>
</body>
</html>
