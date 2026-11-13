<?php
/**
 * Admin panel bootstrap. Every admin page starts with:
 *     require __DIR__ . '/includes/bootstrap.php';
 *
 * Reuses the site's database layer (api/config.php: db(), db_select(), db_execute(),
 * cfg(), site_setting()) but runs its OWN session (cookie LIPALINKADMIN, scoped to
 * /admin) so customer/seller sessions can never grant admin access and vice versa.
 */

declare(strict_types=1);

define('LIPALINK_ADMIN', true);

// Start the admin session BEFORE api/config.php so its helpers reuse this session
// (the customer session functions there return early when a session is active).
(static function (): void {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/') . '/';
    session_name('LIPALINKADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
})();

require_once __DIR__ . '/../../api/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/metrics.php';

// api/config.php sends JSON headers; the admin panel serves HTML
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
    . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; "
    . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

// Convert uncaught database errors into a friendly page instead of a blank screen
set_exception_handler(static function (Throwable $e): void {
    error_log('LipaLink admin error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $detail = is_development() ? '<pre style="white-space:pre-wrap">' . e($e->getMessage()) . '</pre>' : '';
    echo '<!doctype html><meta charset="utf-8"><title>Admin error</title>'
        . '<div style="font-family:system-ui;max-width:640px;margin:60px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px">'
        . '<h2 style="margin-top:0">Something went wrong</h2><p>The action could not be completed. The error has been logged.</p>'
        . $detail . '<p><a href="dashboard.php">Back to the dashboard</a></p></div>';
});
