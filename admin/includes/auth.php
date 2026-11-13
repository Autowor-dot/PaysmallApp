<?php
/**
 * Admin authentication: login with lockout/rate-limit, idle timeout,
 * session fingerprint, page guards.
 */

declare(strict_types=1);

/** The signed-in admin row (fresh from DB once per request) or null. */
function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $admin = null;
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0 || !table_exists('admin_users')) {
        return null;
    }
    $rows = db_select('SELECT id, full_name, email, role, status, last_login_at FROM admin_users WHERE id = ? LIMIT 1', 'i', [$id]);
    if (!empty($rows) && $rows[0]['status'] === 'active') {
        $admin = $rows[0];
    }
    return $admin;
}

function admin_session_timeout(): int
{
    return max(5, min(480, (int) site_setting('admin_session_timeout_minutes', '30'))) * 60;
}

/**
 * Guard for every admin page. Redirects to login when not signed in,
 * when the session went idle, or when the account was deactivated.
 * Optionally requires a permission.
 */
function require_admin(?string $permission = null): array
{
    if (!admin_schema_ready()) {
        redirect('setup.php');
    }
    $admin = current_admin();
    $fingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($admin === null || ($_SESSION['admin_fp'] ?? '') !== $fingerprint) {
        $wasSignedIn = isset($_SESSION['admin_id']);
        admin_destroy_session();
        session_start();
        flash('error', $wasSignedIn ?'Your account is no longer active.' : 'Please sign in to continue.');
        redirect('login.php?next=' . rawurlencode(basename((string) $_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING'])));
    }

    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && time() - $last > admin_session_timeout()) {
        audit('admin_session_expired', 'admin', $admin['id'], $admin['email'], [], $admin);
        admin_destroy_session();
        session_start();
        flash('error', 'You were signed out after a period of inactivity.');
        redirect('login.php');
    }
    $_SESSION['admin_last_seen'] = time();

    // Rotate the session id periodically
    if (time() - (int) ($_SESSION['admin_rotated_at'] ?? 0) > 900) {
        session_regenerate_id(true);
        $_SESSION['admin_rotated_at'] = time();
    }

    if ($permission !== null && !admin_can($permission)) {
        http_response_code(403);
        render_forbidden($admin);
        exit;
    }
    return $admin;
}

function render_forbidden(array $admin): void
{
    $pageTitle = 'Access denied';
    $activeNav = '';
    require __DIR__ . '/header.php';
    echo '<div class="card empty-state"><i class="fa-solid fa-lock"></i><h2>Access denied</h2>'
        . '<p>Your role (' . e(role_label($admin['role'])) . ') does not have permission to open this page.</p>'
        . '<a class="btn btn-primary" href="dashboard.php">Back to overview</a></div>';
    require __DIR__ . '/footer.php';
}

/**
 * Attempt a login. Returns [ok(bool), message(string)].
 * Generic failure messages avoid revealing which emails exist.
 */
function admin_attempt_login(string $email, string $password): array
{
    $ip = client_ip();
    $generic = 'Invalid email or password.';

    // IP rate limit: 20 failures / 15 minutes
    $ipFailures = (int) scalar(
        'SELECT COUNT(*) FROM admin_login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)',
        's', [$ip]
    );
    if ($ipFailures >= 20) {
        return [false, 'Too many failed attempts from your network. Please wait 15 minutes and try again.'];
    }

    $rows = db_select('SELECT * FROM admin_users WHERE email = ? LIMIT 1', 's', [$email]);
    $admin = $rows[0] ?? null;

    $record = static function (bool $ok) use ($email, $ip): void {
        db_execute('INSERT INTO admin_login_attempts (email, ip_address, success) VALUES (?, ?, ?)', 'ssi', [$email, $ip, $ok ? 1 : 0]);
    };

    if ($admin !== null && $admin['locked_until'] !== null && strtotime((string) $admin['locked_until']) > time()) {
        $record(false);
        return [false, 'This account is temporarily locked after repeated failed sign-ins. Try again later.'];
    }

    // Constant-ish time even for unknown emails
    $hash = $admin['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
    $valid = password_verify($password, (string) $hash);

    if ($admin === null || !$valid) {
        $record(false);
        if ($admin !== null) {
            $max = max(3, (int) site_setting('admin_max_login_attempts', '5'));
            $lockMinutes = max(1, (int) site_setting('admin_lockout_minutes', '15'));
            $fails = (int) $admin['failed_attempts'] + 1;
            if ($fails >= $max) {
                db_execute('UPDATE admin_users SET failed_attempts = 0, locked_until = (NOW() + INTERVAL ? MINUTE) WHERE id = ?', 'ii', [$lockMinutes, (int) $admin['id']]);
                audit('admin_account_locked', 'admin', $admin['id'], $admin['email'], ['failed_attempts' => $fails, 'ip' => $ip], $admin);
            } else {
                db_execute('UPDATE admin_users SET failed_attempts = ? WHERE id = ?', 'ii', [$fails, (int) $admin['id']]);
            }
            audit('admin_login_failed', 'admin', $admin['id'], $admin['email'], [], $admin);
        }
        return [false, $generic];
    }

    if ($admin['status'] !== 'active') {
        $record(false);
        audit('admin_login_blocked_inactive', 'admin', $admin['id'], $admin['email'], [], $admin);
        return [false, 'This administrator account has been deactivated.'];
    }

    if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
        db_execute('UPDATE admin_users SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
    }
    db_execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?', 'si', [$ip, (int) $admin['id']]);
    $record(true);

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_fp'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['admin_last_seen'] = time();
    $_SESSION['admin_rotated_at'] = time();
    $_SESSION['admin_prev_login'] = $admin['last_login_at'];

    audit('admin_login', 'admin', $admin['id'], $admin['email'], [], $admin);
    return [true, 'Welcome back, ' . $admin['full_name'] . '.'];
}

function admin_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Password policy for admin accounts. Returns an error message or null. */
function admin_password_problem(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Admin passwords must be at least 12 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Admin passwords must contain both letters and numbers.';
    }
    return null;
}
