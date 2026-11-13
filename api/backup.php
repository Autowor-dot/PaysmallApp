<?php
/**
 * api/backup.php?key=<install_key>            -> creates a full database backup (JSON summary)
 * api/backup.php?key=<install_key>&list=1     -> lists existing backups
 *
 * Backups are written to /backups (not web-accessible). Administrators can
 * also create and download backups from the admin console (admin/backup.php).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup-lib.php';

require_method('GET', 'POST');

$expected = (string) cfg('install_key', '');
$provided = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if ($expected === '' || $expected === 'change-me-before-running-install' || !hash_equals($expected, $provided)) {
    json_error(401, 'Invalid key.');
}

try {
    if (isset($_GET['list'])) {
        json_response(200, ['success' => true, 'backups' => list_database_backups()]);
    }
    $label = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($_GET['label'] ?? 'manual')) ?: 'manual';
    $info  = create_database_backup($label);
    unset($info['path']);
    json_response(200, ['success' => true, 'message' => 'Backup created.', 'backup' => $info]);
} catch (Throwable $e) {
    server_error($e, 'backup');
}
