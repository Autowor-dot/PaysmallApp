<?php
/** POST-only logout (CSRF-protected so third-party pages cannot sign admins out). */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('dashboard.php');
}
require_csrf();

$admin = current_admin();
if ($admin !== null) {
    audit('admin_logout', 'admin', $admin['id'], $admin['email'], [], $admin);
}
admin_destroy_session();
session_start();
flash('success', 'You have been signed out.');
redirect('login.php');
