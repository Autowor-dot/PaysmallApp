<?php
/**
 * GET api/health.php
 * Connection readiness check used by api-status.html. Public; reveals no secrets.
 */

require_once __DIR__ . '/config.php';

require_method('GET');

$required = ['users', 'sellers', 'products', 'agreements', 'installment_plans', 'installment_payments', 'transactions', 'notifications', 'password_resets'];

$report = [
    'success'     => true,
    'app'         => cfg('app_name'),
    'environment' => cfg('app_env'),
    'php_version' => PHP_VERSION,
    'server_time' => date('c'),
    'base_url'    => app_base_url(),
    'database'    => ['connected' => false, 'error' => null, 'server' => null],
    'tables'      => [],
    'missing_tables' => [],
    'session'     => ['active' => false, 'user_id' => null],
    'config'      => [
        'webhook_secret_configured' => cfg('webhook_secret') !== '' && cfg('webhook_secret') !== 'change-me-to-a-long-random-string',
        'install_key_configured'    => cfg('install_key') !== '' && cfg('install_key') !== 'change-me-before-running-install',
        'mail_enabled'              => (bool) cfg('mail_enabled', true),
        'mail_function_available'   => function_exists('mail'),
        'local_config_present'      => is_file(__DIR__ . '/config.local.php'),
    ],
    'extensions'  => [
        'mysqli'   => extension_loaded('mysqli'),
        'mbstring' => extension_loaded('mbstring'),
        'json'     => function_exists('json_encode'),
    ],
];

try {
    $conn = db();
    $report['database']['connected'] = true;
    $report['database']['server']    = $conn->server_info;

    foreach ($required as $t) {
        $exists = table_exists($t);
        $report['tables'][$t] = $exists;
        if (!$exists) {
            $report['missing_tables'][] = $t;
        }
    }
    if (table_exists('users')) {
        $report['database']['user_count'] = (int) (db_select('SELECT COUNT(*) AS c FROM users')[0]['c'] ?? 0);
        $report['database']['users_settings_column'] = column_exists('users', 'settings_json');
    }
} catch (Throwable $e) {
    $report['database']['connected'] = false;
    $report['database']['error']     = is_development() ? $e->getMessage() : 'Could not connect to the database.';
    error_log('LipaLink health check DB error: ' . $e->getMessage());
}

$uid = current_user_id();
$report['session']['active']  = $uid !== null;
$report['session']['user_id'] = $uid;

$report['ready'] = $report['database']['connected'] && empty($report['missing_tables']);
$report['message'] = $report['ready']
    ? 'Backend is ready.'
    : (!$report['database']['connected'] ? 'Database connection failed.' : 'Database connected but some tables are missing. Run api/install.php or api/schema.sql.');

json_response(200, $report);
