<?php
/**
 * api/install.php?key=<install_key>
 *
 * Applies api/schema.sql to the configured database so you do not need phpMyAdmin.
 * Protected by the install_key in config.php / config.local.php. Safe to re-run.
 */

require_once __DIR__ . '/config.php';

require_method('GET', 'POST');

$expected = (string) cfg('install_key', '');
$provided = (string) ($_GET['key'] ?? $_POST['key'] ?? '');

if ($expected === '' || $expected === 'change-me-before-running-install') {
    json_error(403, 'Set a unique install_key in api/config.php (or api/config.local.php) before running the installer.');
}
if (!hash_equals($expected, $provided)) {
    json_error(401, 'Invalid install key.');
}

$schemaFile = __DIR__ . '/schema.sql';
if (!is_file($schemaFile)) {
    json_error(500, 'schema.sql was not found next to install.php.');
}

$sql = (string) file_get_contents($schemaFile);

// Strip comments, then split on semicolons (schema.sql contains no procedures/triggers)
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $s) => $s !== ''));

$results = ['executed' => 0, 'skipped' => 0, 'errors' => []];

try {
    $conn = db();
    foreach ($statements as $statement) {
        try {
            $conn->query($statement);
            $results['executed']++;
        } catch (mysqli_sql_exception $e) {
            $code = (int) $e->getCode();
            // 1060 duplicate column, 1061 duplicate key, 1050 table exists, 1826/1022 duplicate constraint: already applied
            if (in_array($code, [1060, 1061, 1050, 1826, 1022, 1091], true)) {
                $results['skipped']++;
                continue;
            }
            $results['errors'][] = [
                'code'      => $code,
                'message'   => $e->getMessage(),
                'statement' => mb_substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 160),
            ];
        }
    }
} catch (Throwable $e) {
    server_error($e, 'install');
}

$ok = empty($results['errors']);
json_response($ok ? 200 : 207, [
    'success'  => $ok,
    'message'  => $ok ? 'Schema applied. The backend is ready.' : 'Schema applied with errors; see the errors list.',
    'results'  => $results,
    'next'     => 'Open api/health.php to confirm every table exists.',
]);
