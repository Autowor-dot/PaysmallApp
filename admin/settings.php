<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin('view_settings');

// key => [label, type, section, extra]
$fields = [
    'site_name'                       => ['Website name', 'text', 'branding', 80],
    'site_tagline'                    => ['Tagline', 'text', 'branding', 160],
    'contact_email'                   => ['Contact email', 'email', 'contact', 190],
    'support_email'                   => ['Support email', 'email', 'contact', 190],
    'contact_phone'                   => ['Contact phone', 'text', 'contact', 30],
    'contact_address'                 => ['Address', 'text', 'contact', 200],
    'maintenance_mode'                => ['Maintenance mode', 'bool', 'access', null],
    'maintenance_message'             => ['Maintenance message', 'textarea', 'access', 500],
    'allow_user_registration'         => ['Allow new customer registrations', 'bool', 'access', null],
    'allow_seller_registration'       => ['Allow new seller/store registrations', 'bool', 'access', null],
    'notify_email_agreement_request'  => ['Email the seller when a customer proposes an agreement', 'bool', 'notifications', null],
    'notify_email_agreement_accepted' => ['Email the finalised agreement to seller and customer on acceptance', 'bool', 'notifications', null],
    'notify_email_payment_receipt'    => ['Email the seller a receipt when an installment payment is confirmed', 'bool', 'notifications', null],
    'notify_email_registration'       => ['Email a welcome / confirmation message on customer and store registration', 'bool', 'notifications', null],
    'notify_email_payment_receipt_customer' => ['Email the customer a receipt when an installment payment is confirmed', 'bool', 'notifications', null],
    'notify_email_security'           => ['Email account-security notices (password changed, Google sign-in linked)', 'bool', 'notifications', null],
    'notify_email_reminders'          => ['Email customers upcoming, due-today and overdue installment reminders', 'bool', 'notifications', null],
    'admin_session_timeout_minutes'   => ['Admin idle timeout (minutes)', 'int', 'security', [5, 480]],
    'admin_max_login_attempts'        => ['Failed sign-ins before lockout', 'int', 'security', [3, 20]],
    'admin_lockout_minutes'           => ['Lockout duration (minutes)', 'int', 'security', [1, 1440]],
];
$sections = [
    'branding'      => ['Branding', 'fa-palette', 'Used in emails and the admin console.'],
    'contact'       => ['Contact information', 'fa-address-card', 'Public contact details returned by api/site-status.php.'],
    'access'        => ['Website access', 'fa-door-open', 'Maintenance mode takes the public website and customer API offline. The admin panel and payment-provider webhooks keep working.'],
    'notifications' => ['Notification settings', 'fa-envelope', 'In-app notifications are always created; these control outgoing emails.'],
    'security'      => ['Security settings', 'fa-shield-halved', 'Applies to administrator accounts.'],
];

$flagFile = dirname(__DIR__) . '/maintenance.flag';

if (is_post()) {
    require_csrf();
    if (!admin_can('manage_settings')) {
        flash('error', 'Only a Super Admin can change website settings.');
        redirect('settings.php');
    }
    $section = post_str('section', 20);
    if (!isset($sections[$section])) {
        flash('error', 'Unknown settings section.');
        redirect('settings.php');
    }
    $changes = [];
    $errors = [];
    foreach ($fields as $key => [$label, $type, $sec, $extra]) {
        if ($sec !== $section) continue;
        $old = setting_now($key, '');
        if ($type === 'bool') {
            $val = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';
        } elseif ($type === 'int') {
            $raw = post_str($key, 6);
            if (!ctype_digit($raw) || (int) $raw < $extra[0] || (int) $raw > $extra[1]) {
                $errors[] = $label . ' must be between ' . $extra[0] . ' and ' . $extra[1] . '.';
                continue;
            }
            $val = (string) (int) $raw;
        } else {
            $val = post_str($key, (int) $extra);
            if ($type === 'email' && $val !== '' && !is_valid_email($val)) {
                $errors[] = $label . ' is not a valid email.';
                continue;
            }
            if ($key === 'site_name' && mb_strlen($val) < 2) {
                $errors[] = 'Website name is required.';
                continue;
            }
        }
        if ($val !== $old) {
            $changes[$key] = ['from' => $old, 'to' => $val];
        }
    }
    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect('settings.php#' . $section);
    }
    if (!$changes) {
        flash('warning', 'No changes to save.');
        redirect('settings.php#' . $section);
    }
    foreach ($changes as $key => $c) {
        save_setting($key, $c['to'], (int) $admin['id']);
    }
    if (isset($changes['maintenance_mode'])) {
        // Flag file lets .htaccess redirect static pages without touching PHP or the DB
        if ($changes['maintenance_mode']['to'] === '1') {
            @file_put_contents($flagFile, date('c') . ' by ' . $admin['email']);
        } elseif (is_file($flagFile)) {
            @unlink($flagFile);
        }
        audit($changes['maintenance_mode']['to'] === '1' ? 'maintenance_enabled' : 'maintenance_disabled', 'settings', 'maintenance_mode', 'Maintenance mode');
    }
    audit('settings_updated', 'settings', $section, $sections[$section][0], ['changes' => $changes]);
    flash('success', $sections[$section][0] . ' saved.');
    redirect('settings.php#' . $section);
}

$values = [];
foreach ($fields as $key => $_) {
    $values[$key] = setting_now($key, '');
}
$canEdit = admin_can('manage_settings');
$flagPresent = is_file($flagFile);
$history = db_select("SELECT * FROM admin_audit_log WHERE target_type = 'settings' ORDER BY id DESC LIMIT 10");

$pageTitle = 'Website settings';
$pageSubtitle = $canEdit ? 'Changes take effect immediately and are recorded in the activity log.' : 'Read-only: only a Super Admin can change settings.';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>
<?php if ($values['maintenance_mode'] === '1' && !$flagPresent): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> Maintenance is on, but the server could not write <code>maintenance.flag</code>, so static pages stay reachable (the customer API is still blocked). Save the access settings again or check folder permissions.</div>
<?php endif; ?>

<div class="settings-layout">
    <nav class="settings-nav card">
        <?php foreach ($sections as $k => [$title, $icon]): ?><a href="#<?= $k ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($title) ?></a><?php endforeach; ?>
        <a href="#system"><i class="fa-solid fa-server"></i> System status</a>
        <?php if (admin_can('view_admins')): ?><a href="administrators.php"><i class="fa-solid fa-user-shield"></i> Admin accounts</a><?php endif; ?>
    </nav>
    <div class="stack">
        <?php foreach ($sections as $sec => [$title, $icon, $help]): ?>
        <form method="post" class="card settings-section" id="<?= $sec ?>">
            <?= csrf_field() ?><input type="hidden" name="section" value="<?= $sec ?>">
            <div class="card-head"><h3><i class="fa-solid <?= $icon ?>"></i> <?= e($title) ?></h3></div>
            <p class="small muted"><?= e($help) ?></p>
            <fieldset<?= $canEdit ? '' : ' disabled' ?> class="form-grid">
            <?php foreach ($fields as $key => [$label, $type, $s, $extra]): if ($s !== $sec) continue; ?>
                <?php if ($type === 'bool'): ?>
                    <label class="switch full"><input type="checkbox" name="<?= $key ?>" value="1"<?= $values[$key] === '1' ? ' checked' : '' ?>><span class="switch-ui"></span><span><?= e($label) ?></span></label>
                <?php elseif ($type === 'textarea'): ?>
                    <label class="field full"><?= e($label) ?><textarea name="<?= $key ?>" rows="2" maxlength="<?= (int) $extra ?>"><?= e($values[$key]) ?></textarea></label>
                <?php elseif ($type === 'int'): ?>
                    <label class="field"><?= e($label) ?><input type="number" name="<?= $key ?>" min="<?= $extra[0] ?>" max="<?= $extra[1] ?>" value="<?= e($values[$key]) ?>" required></label>
                <?php else: ?>
                    <label class="field"><?= e($label) ?><input type="<?= $type ?>" name="<?= $key ?>" maxlength="<?= (int) $extra ?>" value="<?= e($values[$key]) ?>"></label>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
                <div class="full"><button class="btn btn-primary" type="submit"<?= $sec === 'access' ? ' data-confirm="Save website access settings? Turning on maintenance mode takes the public site offline immediately."' : '' ?>>Save <?= e(strtolower($title)) ?></button></div>
            <?php endif; ?>
            </fieldset>
        </form>
        <?php endforeach; ?>

        <div class="card" id="system">
            <div class="card-head"><h3><i class="fa-solid fa-server"></i> System status</h3><span class="muted small">secrets are never shown</span></div>
            <dl class="dl">
                <dt>Environment</dt><dd><?= is_development() ? badge('pending', 'development (payment simulator ON)') : badge('active', 'production') ?></dd>
                <dt>PHP version</dt><dd><?= e(PHP_VERSION) ?></dd>
                <dt>Database server</dt><dd><?= e(db()->server_info) ?></dd>
                <dt>Server email</dt><dd><?= cfg('mail_enabled', true) ? badge('active', 'enabled') : badge('disabled', 'disabled') ?> <?= function_exists('mail') ? '' : badge('failed', 'mail() unavailable') ?></dd>
                <dt>Payment webhook secret</dt><dd><?= (cfg('webhook_secret') !== '' && cfg('webhook_secret') !== 'change-me-to-a-long-random-string') ? badge('active', 'configured') : badge('failed', 'not configured') ?></dd>
                <dt>Payment gateway</dt><dd><?= (int) scalar("SELECT COUNT(*) FROM transactions WHERE verified_via = 'provider'") > 0 ? badge('active', 'receiving provider confirmations') : badge('pending', 'no provider confirmations received yet') ?></dd>
                <dt>Maintenance flag file</dt><dd><?= $flagPresent ? badge('failed', 'present — static pages redirect') : badge('active', 'absent') ?></dd>
            </dl>
            <p class="small muted">Database credentials, keys and secrets live in <code>api/config.local.php</code> on the server and are only editable there.</p>
        </div>

        <div class="card">
            <div class="card-head"><h3>Recent settings changes</h3><a class="small" href="activity-logs.php?target=settings">All</a></div>
            <ul class="list"><?php foreach ($history as $h): ?>
                <li><div><strong><?= e(humanize($h['action'])) ?></strong> · <?= e($h['target_label']) ?><div class="muted small"><?= e($h['admin_name']) ?></div></div><div class="muted small"><?= fmt_date($h['created_at'], true) ?></div></li>
            <?php endforeach; ?><?php if (!$history): ?><li class="muted">No changes yet.</li><?php endif; ?></ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
