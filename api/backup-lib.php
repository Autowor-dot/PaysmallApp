<?php
/**
 * Database backups: full SQL dump of every table in the configured database,
 * written to <web root>/backups/ (blocked from the web by backups/.htaccess).
 */

declare(strict_types=1);

function backups_dir(): string
{
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Create a backup file and return ['file' => name, 'path' => abs, 'tables' => n, 'rows' => n, 'bytes' => n]. */
function create_database_backup(string $label = 'manual'): array
{
    $conn = db();
    $dir  = backups_dir();
    $name = 'backup-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) . '.sql';
    $path = $dir . '/' . $name;

    $fh = fopen($path, 'w');
    if ($fh === false) {
        throw new RuntimeException('Cannot write to the backups folder.');
    }
    fwrite($fh, "-- LipaLink database backup\n-- Database: " . cfg('db_name') . "\n-- Created: " . date('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = [];
    $res = $conn->query('SHOW TABLES');
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $tables[] = (string) $row[0];
    }

    $totalRows = 0;
    foreach ($tables as $table) {
        $create = $conn->query('SHOW CREATE TABLE `' . $conn->real_escape_string($table) . '`')->fetch_array(MYSQLI_NUM);
        fwrite($fh, "-- ----------------------------\n-- Table `{$table}`\n-- ----------------------------\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");

        $rows = $conn->query('SELECT * FROM `' . $conn->real_escape_string($table) . '`');
        $batch = [];
        while ($r = $rows->fetch_assoc()) {
            $values = [];
            foreach ($r as $v) {
                $values[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string((string) $v) . "'";
            }
            $batch[] = '(' . implode(',', $values) . ')';
            $totalRows++;
            if (count($batch) >= 200) {
                fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);

    return ['file' => $name, 'path' => $path, 'tables' => count($tables), 'rows' => $totalRows, 'bytes' => (int) filesize($path)];
}

/** Existing backup files, newest first. */
function list_database_backups(): array
{
    $dir = backups_dir();
    $out = [];
    foreach (glob($dir . '/backup-*.sql') ?: [] as $file) {
        $out[] = ['file' => basename($file), 'bytes' => (int) filesize($file), 'created_at' => date('Y-m-d H:i:s', (int) filemtime($file))];
    }
    usort($out, static fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}
