<?php
/**
 * Page chrome: <head>, sidebar, top bar, flash messages.
 * Expects $admin (array), $pageTitle (string), $activeNav (string).
 * Optional $pageSubtitle, $pageActions (trusted HTML), $useCharts (bool).
 */

declare(strict_types=1);

$siteName = site_setting('site_name', (string) cfg('app_name', 'LipaLink'));

$nav = [
    ['overview',      'dashboard.php',     'fa-chart-pie',            'Overview',          'view_dashboard'],
    ['users',         'users.php',         'fa-users',                'Users',             'view_users'],
    ['sellers',       'sellers.php',       'fa-store',                'Sellers',           'view_sellers'],
    ['agreements',    'agreements.php',    'fa-file-signature',       'Agreements',        'view_agreements'],
    ['payments',      'payments.php',      'fa-money-bill-transfer',  'Payments',          'view_payments'],
    ['reports',       'reports.php',       'fa-chart-line',           'Financial Reports', 'view_reports'],
    ['cases',         'cases.php',         'fa-scale-balanced',       'Cases & Disputes',  'view_cases'],
    ['notifications', 'notifications.php', 'fa-bell',                 'Notifications',     'view_notifications'],
    ['messages',      'messages.php',      'fa-envelope-open-text',   'Messages',          'view_cases'],
    ['logs',          'activity-logs.php', 'fa-clock-rotate-left',    'Activity Logs',     'view_audit'],
    ['admins',        'administrators.php','fa-user-shield',          'Administrators',    'view_admins'],
    ['settings',      'settings.php',      'fa-gear',                 'Website Settings',  'view_settings'],
    ['backup',        'backup.php',        'fa-database',             'Backups',           'manage_settings'],
];

// Bell indicator: open cases + failed emails in the last 7 days
$alertCount = 0;
try {
    $alertCount = (int) scalar("SELECT COUNT(*) FROM admin_cases WHERE status IN ('open','investigating')")
        + (int) scalar("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 7 DAY)");
} catch (Throwable $ignored) {
}
$maintenanceOn = site_flag('maintenance_mode', false);
$initials = strtoupper(implode('', array_map(static fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($admin['full_name'])) ?: ['A'], 0, 2))));
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? 'Admin') ?> · <?= e($siteName) ?> Admin</title>
    <script src="assets/theme-init.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css?v=1">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark"><i class="fa-solid fa-link"></i></span>
            <span class="brand-text"><?= e($siteName) ?><small>Admin Console</small></span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as [$key, $href, $icon, $label, $perm]): if (!admin_can($perm)) continue; ?>
                <a class="nav-link<?= ($activeNav ?? '') === $key ? ' active' : '' ?>" href="<?= e($href) ?>">
                    <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="logout.php" class="nav-logout">
            <?= csrf_field() ?>
            <button type="submit" class="nav-link"><i class="fa-solid fa-arrow-right-from-bracket"></i><span>Logout</span></button>
        </form>
    </aside>
    <div class="sidebar-backdrop" data-close-sidebar></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" type="button" data-toggle-sidebar aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
            <form class="topbar-search" action="search.php" method="get" role="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" placeholder="Search users, sellers, agreements, payments…" value="<?= e(basename((string) $_SERVER['SCRIPT_NAME']) === 'search.php' ? get_str('q') : '') ?>" aria-label="Search">
            </form>
            <div class="topbar-right">
                <button class="icon-btn" type="button" data-toggle-theme aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
                <a class="icon-btn" href="cases.php" title="Open cases and failed emails" aria-label="Alerts">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($alertCount > 0): ?><span class="dot-count"><?= $alertCount > 99 ? '99+' : (int) $alertCount ?></span><?php endif; ?>
                </a>
                <div class="profile" data-dropdown>
                    <button type="button" class="profile-btn" data-dropdown-toggle aria-haspopup="true">
                        <span class="avatar"><?= e($initials) ?></span>
                        <span class="profile-meta"><strong><?= e($admin['full_name']) ?></strong><small><?= e(role_label($admin['role'])) ?></small></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown">
                        <div class="dropdown-head"><strong><?= e($admin['full_name']) ?></strong><small><?= e($admin['email']) ?></small></div>
                        <a href="account.php"><i class="fa-solid fa-key"></i> My account &amp; password</a>
                        <a href="../index.html" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> View website</a>
                        <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</button></form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <?php if ($maintenanceOn): ?>
                <div class="alert alert-warning"><i class="fa-solid fa-person-digging"></i> Maintenance mode is <strong>ON</strong> — the public website and customer API are offline. <?php if (admin_can('manage_settings')): ?><a href="settings.php">Change in settings</a><?php endif; ?></div>
            <?php endif; ?>
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : ($f['type'] === 'warning' ? 'warning' : 'success') ?>" role="status">
                    <i class="fa-solid <?= $f['type'] === 'error' ? 'fa-circle-exclamation' : ($f['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check') ?>"></i>
                    <?= e($f['message']) ?>
                </div>
            <?php endforeach; ?>
            <div class="page-head">
                <div>
                    <h1><?= e($pageTitle ?? '') ?></h1>
                    <?php if (!empty($pageSubtitle)): ?><p class="muted"><?= e($pageSubtitle) ?></p><?php endif; ?>
                </div>
                <?php if (!empty($pageActions)): ?><div class="page-actions"><?= $pageActions ?></div><?php endif; ?>
            </div>
