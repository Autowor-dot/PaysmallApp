<?php
/**
 * Database backups (Super Admin): create a full SQL dump, list and download previous dumps.
 * Dumps live in /backups, which is blocked from the web; downloads go through this page only.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../api/backup-lib.php';
$admin = require_admin('manage_settings');

// Download
$file = get_str('download', 120);
if ($file !== '') {
    if (!preg_match('/^backup-[0-9]{8}-[0-9]{6}-[a-z0-9-]+\.sql$/', $file) || !is_file(backups_dir() . '/' . $file)) {
        flash('error', 'Backup file not found.');
        redirect('backup.php');
    }
    audit('backup_downloaded', 'backup', $file, $file);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize(backups_dir() . '/' . $file));
    readfile(backups_dir() . '/' . $file);
    exit;
}

if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);
    if ($action === 'create') {
        try {
            $info = create_database_backup('admin');
            audit('backup_created', 'backup', $info['file'], $info['file'], ['tables' => $info['tables'], 'rows' => $info['rows'], 'bytes' => $info['bytes']]);
            flash('success', 'Backup created: ' . $info['file'] . ' (' . $info['tables'] . ' tables, ' . number_format($info['rows']) . ' rows).');
        } catch (Throwable $e) {
            flash('error', 'Backup failed: ' . $e->getMessage());
        }
    } elseif ($action === 'delete') {
        $target = post_str('file', 120);
        if (preg_match('/^backup-[0-9]{8}-[0-9]{6}-[a-z0-9-]+\.sql$/', $target) && is_file(backups_dir() . '/' . $target)) {
            unlink(backups_dir() . '/' . $target);
            audit('backup_deleted', 'backup', $target, $target);
            flash('success', 'Backup deleted.');
        }
    }
    redirect('backup.php');
}

$backups = list_database_backups();
$pageTitle = 'Database backups';
$pageSubtitle = 'Full SQL dumps of the live database. Download and keep copies off the server.';
$activeNav = 'backup';
$pageActions = '<form method="post" style="display: inline-block; margin: 0;">' . csrf_field() . '<input type="hidden" name="action" value="create"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-database"></i> Create backup now</button></form>';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <?php if (empty($backups)): ?>
        <div class="empty-state"><i class="fa-solid fa-database"></i><h2>No backups yet</h2><p>Create one before applying database changes or at least once a week.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>File</th><th>Created</th><th>Size</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td><code><?= e($b['file']) ?></code></td>
                    <td class="nowrap"><?= fmt_date($b['created_at'], true) ?></td>
                    <td class="nowrap"><?= number_format($b['bytes'] / 1024, 1) ?> KB</td>
                    <td class="nowrap">
                        <a class="btn btn-sm" href="backup.php?download=<?= rawurlencode($b['file']) ?>"><i class="fa-solid fa-download"></i> Download</a>
                        <form method="post" style="display: inline-block; margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($b['file']) ?>">
                            <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete this backup file from the server?"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <p class="muted small" style="margin-top: 12px;">Backups are stored in <code>/backups</code> on the hosting account (not web-accessible). Restore by importing the file in phpMyAdmin. The <code>api/backup.php?key=…</code> endpoint creates the same dump for scripted use.</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
