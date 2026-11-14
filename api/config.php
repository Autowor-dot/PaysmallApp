<?php
/**
 * LipaLink API bootstrap.
 *
 * Shared by every endpoint in /api: configuration, database connection,
 * PHP session, JSON helpers, input parsing, phone normalisation, auth guards,
 * notifications and mail.
 *
 * Every endpoint starts with:  require_once __DIR__ . '/config.php';
 *
 * To override any setting without editing this file, create api/config.local.php:
 *     <?php return ['db_pass' => '...', 'app_env' => 'development'];
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------
$LIPALINK_CONFIG = [
    // Database — real values live in api/config.local.php (git-ignored); see config.local.example.php
    'db_host' => 'localhost',
    'db_user' => 'CHANGE_ME',
    'db_pass' => 'CHANGE_ME',
    'db_name' => 'paysmallapp',

    // 'production' or 'development'. Development enables the payment simulator
    // and returns password-reset links in API responses (for testing without email).
    'app_env' => 'production',

    // Shown in emails and used to build absolute links. Leave empty to auto-detect.
    'app_name' => 'LipaLink',
    'app_url'  => '',

    // Shared secret the payment provider sends with webhook callbacks (api/payment-webhook.php).
    // Generate a long random string and configure the same value at the provider.
    'webhook_secret' => 'change-me-to-a-long-random-string',

    // Key required to run api/install.php (creates the database tables). Empty disables the installer.
    'install_key' => 'change-me-before-running-install',

    // Outgoing email. InfinityFree's free plan cannot deliver PHP mail(), so set
    // the default transport is Brevo's HTTPS API: put your Brevo API key (xkeysib-…) in config.local.php as mail_api_key
    // and use a sender address verified in Brevo as mail_from. Other transports: 'mail', 'resend', 'mailersend' (see api/mail-lib.php).
    'mail_from'      => 'no-reply@lipalink.co.tz',
    'mail_from_name' => 'LipaLink',
    'mail_enabled'   => true,
    'mail_transport' => 'brevo',
    'mail_api_key'   => '',

    // Key for api/cron.php (scheduled reminders). Empty = falls back to install_key.
    'cron_key' => '',
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $override = include $localConfig;
    if (is_array($override)) {
        $LIPALINK_CONFIG = array_merge($LIPALINK_CONFIG, $override);
    }
}

function cfg(string $key, $default = null)
{
    global $LIPALINK_CONFIG;
    return $LIPALINK_CONFIG[$key] ?? $default;
}

function is_development(): bool
{
    return cfg('app_env') === 'development';
}

// Never leak PHP warnings into JSON responses
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Africa/Dar_es_Salaam');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message): void
{
    json_response($status, ['success' => false, 'message' => $message]);
}

/** Log the real error, return a generic message to the client. */
function server_error(Throwable $e, string $context): void
{
    error_log('LipaLink ' . $context . ' error: ' . $e->getMessage());
    $message = is_development()
        ? 'Server error (' . $context . '): ' . $e->getMessage()
        : 'A server error occurred. Please try again shortly.';
    json_error(500, $message);
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_error(405, 'Invalid request method.');
    }
}

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function lipalink_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('LIPALINKSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user_id(): ?int
{
    lipalink_session_start();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** Returns the signed-in user's id or sends a 401 JSON response. */
function require_auth(): int
{
    $id = current_user_id();
    if ($id === null) {
        json_error(401, 'Please log in to continue.');
    }
    // Accounts suspended/deactivated by an administrator lose access immediately
    $blocked = account_block_message($id);
    if ($blocked !== null) {
        sign_out_user();
        json_error(403, $blocked);
    }
    return $id;
}

/** Null when the account may use the site, otherwise the message to show. */
function account_block_message(int $userId): ?string
{
    try {
        if (!column_exists('users', 'status')) {
            return null;
        }
        $rows = db_select('SELECT status FROM users WHERE id = ? LIMIT 1', 'i', [$userId]);
        $status = (string) ($rows[0]['status'] ?? 'active');
    } catch (Throwable $e) {
        error_log('LipaLink account status error: ' . $e->getMessage());
        return null;
    }
    if ($status === 'suspended') {
        return 'Your account has been suspended. Please contact support for assistance.';
    }
    if ($status === 'deactivated') {
        return 'This account has been deactivated. Please contact support if you believe this is a mistake.';
    }
    return null;
}

function sign_in_user(int $userId): void
{
    lipalink_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['signed_in_at'] = time();
}

function sign_out_user(): void
{
    lipalink_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(cfg('db_host'), cfg('db_user'), cfg('db_pass'), cfg('db_name'));
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** True when a table exists in the current schema (cached per request). */
function table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$table] = $exists;
}

function column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$key] = $exists;
}

/** Run a prepared SELECT and return all rows. $types is the bind_param type string. */
function db_select(string $sql, string $types = '', array $params = []): array
{
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Run a prepared INSERT/UPDATE/DELETE and return affected rows. */
function db_execute(string $sql, string $types = '', array $params = []): int
{
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

// ---------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------
function fetch_user(int $id): ?array
{
    $rows = db_select('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$id]);
    return $rows[0] ?? null;
}

/** The subset of a users row that is safe to send to the browser. */
function public_user(array $row): array
{
    $settings = null;
    if (!empty($row['settings_json'])) {
        $decoded = json_decode((string) $row['settings_json'], true);
        $settings = is_array($decoded) ? $decoded : null;
    }

    $isSeller = false;
    if (table_exists('sellers')) {
        $s = db_select('SELECT id FROM sellers WHERE user_id = ? LIMIT 1', 'i', [(int) $row['id']]);
        $isSeller = !empty($s);
    }

    return [
        'id'         => (int) $row['id'],
        'full_name'  => $row['full_name'] ?? '',
        'phone'      => $row['phone'] ?? '',
        'email'      => $row['email'] ?? '',
        'created_at' => $row['created_at'] ?? null,
        'settings'   => $settings,
        'is_seller'  => $isSeller,
    ];
}

// ---------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------
function add_notification(?int $userId, string $type, string $title, string $message): void
{
    if ($userId === null || !table_exists('notifications')) {
        return;
    }
    db_execute(
        'INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)',
        'isss',
        [$userId, $type, mb_substr($title, 0, 160), $message]
    );
}

// ---------------------------------------------------------------------
// Mail
// ---------------------------------------------------------------------
function app_base_url(): string
{
    $configured = rtrim((string) cfg('app_url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }
    $scheme = is_https_request() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // /some/dir/api/endpoint.php -> /some/dir
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/\\');
    return $scheme . '://' . $host . $dir;
}

/**
 * Best-effort plain-text email. Returns true when PHP accepted the message.
 * Every attempt is recorded in email_log (when the admin migration has run)
 * so the admin panel can show delivery status. $context names the message
 * kind, e.g. 'agreement_request'; $relatedType/$relatedId point at the record.
 */
function send_mail(string $to, string $subject, string $body, string $context = 'general', ?string $relatedType = null, ?string $relatedId = null): bool
{
    if (!is_valid_email($to)) {
        return false;
    }
    if (!cfg('mail_enabled', true)) {
        log_email($to, $subject, $context, $relatedType, $relatedId, 'disabled', 'mail_enabled is off');
        return false;
    }
    $from     = (string) cfg('mail_from');
    $siteName = (string) cfg('mail_from_name', site_setting('site_name', (string) cfg('app_name')));
    // Shared-sender hint: Brevo free plan + freemail sender often lands in spam until marked "Not spam".
    $body .= "\n\n--\nIf this message arrived in your spam or junk folder, please mark it as \"Not spam\" so future receipts and reminders from " . $siteName . " reach your inbox.";
    require_once __DIR__ . '/mail-lib.php';
    $result = deliver_email($to, $subject, $body, $from, $siteName);
    if (!$result['ok']) {
        error_log('LipaLink mail error (' . $result['transport'] . '): ' . ($result['error'] ?? 'unknown'));
    }
    log_email($to, $subject, $context, $relatedType, $relatedId, $result['ok'] ? 'sent' : 'failed', $result['error']);
    return $result['ok'];
}

/** Record an email attempt; never throws. */
function log_email(string $to, string $subject, string $context, ?string $relatedType, ?string $relatedId, string $status, ?string $error): void
{
    try {
        if (!table_exists('email_log')) {
            return;
        }
        db_execute(
            'INSERT INTO email_log (recipient, subject, context, related_type, related_id, status, error) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sssssss',
            [mb_substr($to, 0, 190), mb_substr($subject, 0, 255), mb_substr($context, 0, 60), $relatedType, $relatedId, $status,
             $error !== null ? mb_substr($error, 0, 255) : null]
        );
    } catch (Throwable $e) {
        error_log('LipaLink email_log error: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Website settings (managed in the admin panel; table site_settings)
// ---------------------------------------------------------------------
/** Read a website setting; returns $default when unset or before the admin migration. */
function site_setting(string $key, ?string $default = null): ?string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            if (table_exists('site_settings')) {
                foreach (db_select('SELECT setting_key, setting_value FROM site_settings') as $row) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (Throwable $e) {
            error_log('LipaLink settings error: ' . $e->getMessage());
        }
    }
    return array_key_exists($key, $settings) && $settings[$key] !== null ? (string) $settings[$key] : $default;
}

function site_flag(string $key, bool $default): bool
{
    return site_setting($key, $default ? '1' : '0') === '1';
}

// ---------------------------------------------------------------------
// Input helpers
// ---------------------------------------------------------------------
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Merged request input: JSON body takes precedence over form fields. */
function request_input(): array
{
    return array_merge($_POST, read_json_body());
}

function input_string(array $input, string $key, int $maxLength = 255): string
{
    $value = $input[$key] ?? '';
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    return mb_substr($value, 0, $maxLength);
}

function input_int(array $input, string $key): ?int
{
    $value = $input[$key] ?? null;
    if ($value === null || $value === '' || !is_scalar($value) || !is_numeric((string) $value)) {
        return null;
    }
    return (int) $value;
}

/**
 * Normalise Tanzanian phone numbers to 2557XXXXXXXX / 2556XXXXXXXX.
 * Accepts 07XX..., 7XX..., +255 7XX..., 255 7XX... with spaces or dashes.
 */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 9 && ($digits[0] === '7' || $digits[0] === '6')) {
        $digits = '255' . $digits;
    } elseif (strlen($digits) === 10 && $digits[0] === '0') {
        $digits = '255' . substr($digits, 1);
    }
    return $digits;
}

function is_valid_tz_phone(string $digits): bool
{
    return (bool) preg_match('/^255[67]\d{8}$/', $digits);
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Human-friendly unique reference, e.g. TX-20261113-7F3A9C */
function generate_code(string $prefix): string
{
    return sprintf('%s-%s-%s', $prefix, date('Ymd'), strtoupper(bin2hex(random_bytes(3))));
}

// ---------------------------------------------------------------------
// Maintenance mode (switched on in admin/settings.php)
// The admin panel defines LIPALINK_ADMIN and is never blocked. Health,
// installer, public site status and payment-provider webhooks keep working
// so payments in flight are still settled.
// ---------------------------------------------------------------------
if (!defined('LIPALINK_ADMIN')) {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['health.php', 'install.php', 'site-status.php', 'payment-webhook.php', 'cron.php', 'backup.php'], true)
        && site_flag('maintenance_mode', false)) {
        header('Retry-After: 1800');
        json_response(503, [
            'success'     => false,
            'maintenance' => true,
            'message'     => (string) site_setting('maintenance_message', 'The site is undergoing maintenance. Please try again shortly.'),
        ]);
    }
    unset($script);
}
