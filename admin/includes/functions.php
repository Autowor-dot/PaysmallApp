<?php
/**
 * Admin helpers: escaping, formatting, CSRF, flash messages, permissions,
 * audit logging, filtering/pagination and CSV output.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Roles & permissions
// ---------------------------------------------------------------------
const ADMIN_ROLES = [
    'super_admin' => 'Super Admin',
    'admin'       => 'Admin',
    'support'     => 'Support Admin',
];

/** permission => roles allowed */
const ADMIN_PERMISSIONS = [
    'view_dashboard'     => ['super_admin', 'admin', 'support'],
    'view_users'         => ['super_admin', 'admin', 'support'],
    'manage_users'       => ['super_admin', 'admin'],
    'view_sellers'       => ['super_admin', 'admin', 'support'],
    'manage_sellers'     => ['super_admin', 'admin'],
    'view_agreements'    => ['super_admin', 'admin', 'support'],
    'manage_agreements'  => ['super_admin', 'admin'],
    'view_payments'      => ['super_admin', 'admin', 'support'],
    'manage_payments'    => ['super_admin', 'admin'],
    'view_reports'       => ['super_admin', 'admin'],
    'view_notifications' => ['super_admin', 'admin', 'support'],
    'view_cases'         => ['super_admin', 'admin', 'support'],
    'manage_cases'       => ['super_admin', 'admin', 'support'],
    'view_audit'         => ['super_admin', 'admin'],
    'view_admins'        => ['super_admin', 'admin'],
    'manage_admins'      => ['super_admin'],
    'view_settings'      => ['super_admin', 'admin'],
    'manage_settings'    => ['super_admin'],
];

function role_label(string $role): string
{
    return ADMIN_ROLES[$role] ?? ucfirst($role);
}

function admin_can(string $permission): bool
{
    $admin = current_admin();
    return $admin !== null && in_array($admin['role'], ADMIN_PERMISSIONS[$permission] ?? [], true);
}

// ---------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tzs($amount, bool $withCurrency = true): string
{
    $n = number_format((float) $amount, 0);
    return $withCurrency ? 'TZS ' . $n : $n;
}

function fmt_date(?string $value, bool $withTime = false): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? e($value) : date($withTime ? 'j M Y, H:i' : 'j M Y', $ts);
}

function time_ago(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $diff = time() - (int) strtotime($value);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . 'd ago';
    return fmt_date($value);
}

function humanize(?string $value): string
{
    return ucfirst(str_replace('_', ' ', (string) $value));
}

/** Coloured status pill. */
function badge(?string $status, ?string $label = null): string
{
    $s = strtolower((string) $status);
    $tone = match (true) {
        in_array($s, ['active', 'verified', 'accepted', 'completed', 'paid', 'sent', 'resolved', 'provider', 'success'], true) => 'green',
        in_array($s, ['pending', 'pending_seller_approval', 'upcoming', 'investigating', 'due', 'open', 'normal', 'simulator', 'unverified'], true) => 'amber',
        in_array($s, ['suspended', 'failed', 'declined', 'overdue', 'urgent', 'high', 'deactivated', 'inactive', 'dismissed'], true) => 'red',
        in_array($s, ['cancelled', 'disabled', 'low'], true) => 'grey',
        default => 'blue',
    };
    $text = $label ?? match ($s) {
        'pending_seller_approval' => 'Pending approval',
        'provider'   => 'Provider verified',
        'simulator'  => 'Simulated (test)',
        'unverified' => 'Recorded, unverified',
        default      => humanize($s),
    };
    return '<span class="badge badge-' . $tone . '">' . e($text) . '</span>';
}

// ---------------------------------------------------------------------
// Requests, redirects, flash
// ---------------------------------------------------------------------
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

function get_str(string $key, int $max = 120): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

function get_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_scalar($v) && ctype_digit((string) $v) ? (int) $v : $default;
}

function post_int(string $key): int
{
    $v = $_POST[$key] ?? null;
    return is_scalar($v) && ctype_digit((string) $v) ? (int) $v : 0;
}

/** Y-m-d or '' */
function get_date(string $key): string
{
    $v = get_str($key, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : '';
}

/** Current URL with some query parameters replaced (null removes). */
function url_with(array $changes): string
{
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        }
    }
    $self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    return $self . (empty($q) ? '' : '?' . http_build_query($q));
}

function client_ip(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Stops the request unless the POST carries this session's CSRF token. */
function require_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        flash('error', 'Your session form token expired. Please try again.');
        redirect(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
    }
}

// ---------------------------------------------------------------------
// Audit log (append-only)
// ---------------------------------------------------------------------
function audit(string $action, ?string $targetType = null, $targetId = null, ?string $targetLabel = null, array $details = [], ?array $actor = null): void
{
    try {
        if (!table_exists('admin_audit_log')) {
            return;
        }
        $a = $actor ?? current_admin();
        db_execute(
            'INSERT INTO admin_audit_log (admin_id, admin_name, admin_email, admin_role, action, target_type, target_id, target_label, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'issssssssss',
            [
                $a['id'] ?? null, $a['full_name'] ?? null, $a['email'] ?? null, $a['role'] ?? null,
                $action, $targetType, $targetId !== null ? (string) $targetId : null,
                $targetLabel !== null ? mb_substr($targetLabel, 0, 200) : null,
                empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('LipaLink audit error: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Query building
// ---------------------------------------------------------------------
/** Collects WHERE clauses with bind types/params. */
final class Where
{
    public array $clauses = [];
    public string $types = '';
    public array $params = [];

    public function add(string $clause, string $types = '', array $params = []): self
    {
        $this->clauses[] = $clause;
        $this->types .= $types;
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    public function sql(): string
    {
        return empty($this->clauses) ? '' : ' WHERE ' . implode(' AND ', $this->clauses);
    }
}

function count_rows(string $fromSql, Where $w): int
{
    return (int) (db_select('SELECT COUNT(*) AS c ' . $fromSql . $w->sql(), $w->types, $w->params)[0]['c'] ?? 0);
}

function scalar(string $sql, string $types = '', array $params = []): float
{
    $row = db_select($sql, $types, $params)[0] ?? [];
    return (float) (array_values($row)[0] ?? 0);
}

/** ['page','per','offset','pages','total'] */
function paginate(int $total, int $per = 25): array
{
    $pages = max(1, (int) ceil($total / $per));
    $page = min(max(1, get_int('page', 1)), $pages);
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per, 'pages' => $pages, 'total' => $total];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="pager"><span class="muted">' . number_format($p['total']) . ' record(s)</span></div>';
    }
    $html = '<div class="pager"><span class="muted">' . number_format($p['total']) . ' records · page ' . $p['page'] . ' of ' . $p['pages'] . '</span><div class="pager-links">';
    if ($p['page'] > 1) {
        $html .= '<a class="btn btn-sm" href="' . e(url_with(['page' => $p['page'] - 1])) . '">&larr; Prev</a>';
    }
    if ($p['page'] < $p['pages']) {
        $html .= '<a class="btn btn-sm" href="' . e(url_with(['page' => $p['page'] + 1])) . '">Next &rarr;</a>';
    }
    return $html . '</div></div>';
}

function like(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

// ---------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------
function send_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // Neutralise spreadsheet formula injection
        $r = array_map(static function ($v) {
            $v = (string) ($v ?? '');
            return $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v;
        }, $r);
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------
function save_setting(string $key, string $value, int $adminId): void
{
    db_execute(
        'INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        'ssi', [$key, $value, $adminId]
    );
}

/** Read setting fresh from the DB (site_setting() caches per request). */
function setting_now(string $key, string $default = ''): string
{
    if (!table_exists('site_settings')) {
        return $default;
    }
    $rows = db_select('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1', 's', [$key]);
    return isset($rows[0]) && $rows[0]['setting_value'] !== null ? (string) $rows[0]['setting_value'] : $default;
}

/** True when every admin table exists (migration applied). */
function admin_schema_ready(): bool
{
    foreach (['admin_users', 'admin_audit_log', 'site_settings', 'email_log', 'admin_cases', 'admin_case_notes', 'admin_login_attempts'] as $t) {
        if (!table_exists($t)) {
            return false;
        }
    }
    return column_exists('users', 'status') && column_exists('transactions', 'verified_via');
}

/** SQL expression classifying how a transaction was confirmed. Alias t = transactions. */
function tx_verification_sql(): string
{
    $col = column_exists('transactions', 'verified_via') ? 't.verified_via' : 'NULL';
    return "CASE WHEN t.status <> 'completed' THEN NULL
                 WHEN {$col} IS NOT NULL THEN {$col}
                 WHEN t.provider_reference LIKE 'SIMULATED-%' THEN 'simulator'
                 ELSE 'unverified' END";
}

/**
 * Apply a .sql migration file statement by statement. "Already applied"
 * errors are skipped so the file can be re-run safely.
 * Returns ['executed' => n, 'skipped' => n, 'errors' => [...]].
 */
function run_sql_file(string $path): array
{
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $s) => $s !== ''));
    $result = ['executed' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($statements as $statement) {
        try {
            db()->query($statement);
            $result['executed']++;
        } catch (mysqli_sql_exception $e) {
            if (in_array((int) $e->getCode(), [1060, 1061, 1050, 1826, 1022, 1091], true)) {
                $result['skipped']++;
                continue;
            }
            $result['errors'][] = '#' . $e->getCode() . ' ' . $e->getMessage() . ' — ' . mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 120);
        }
    }
    return $result;
}
