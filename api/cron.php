<?php
/**
 * GET api/cron.php?key=<cron_key>
 *
 * Runs the scheduled tasks (installment status roll-over, upcoming / due-today /
 * overdue reminders). Point an external scheduler such as cron-job.org at this
 * URL once a day; the dashboard also triggers the same tasks opportunistically.
 * The key is cron_key from api/config.local.php (falls back to install_key).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tasks-lib.php';

require_method('GET', 'POST');

$expected = (string) cfg('cron_key', '');
if ($expected === '') {
    $expected = (string) cfg('install_key', '');
}
$provided = (string) ($_GET['key'] ?? $_POST['key'] ?? '');

if ($expected === '' || $expected === 'change-me-before-running-install') {
    json_error(503, 'Set cron_key (or install_key) in api/config.local.php to enable scheduled tasks.');
}
if (!hash_equals($expected, $provided)) {
    json_error(401, 'Invalid key.');
}

try {
    $summary = run_scheduled_tasks(0, true);
    json_response(200, ['success' => true, 'summary' => $summary, 'time' => date('c')]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'cron');
}
