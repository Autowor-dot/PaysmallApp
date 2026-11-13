<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!admin_schema_ready()) {
    redirect('setup.php');
}
redirect(current_admin() !== null ? 'dashboard.php' : 'login.php');
