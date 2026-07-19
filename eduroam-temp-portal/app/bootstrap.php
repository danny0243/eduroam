<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Taipei');

const SESSION_LIFETIME_SECONDS = 604800;
const ADMIN_IDLE_TIMEOUT_SECONDS = 28800;
const AUTH_FAILURE_WINDOW_MINUTES = 15;
const AUTH_FAILURE_LIMIT = 10;
const PORTAL_SECRET_KEY_PATH = '/var/lib/eduroam-portal/secret.key';
const PASSWORD_LOGIN_ENABLED = false;

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME_SECONDS);
    ini_set('session.cookie_lifetime', (string) SESSION_LIFETIME_SECONDS);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const APP_NAME = 'NCUT eduroam 臨時帳號申請系統';
const ADMIN_HOME_PATH = '/admin-dashboard.php';
const DALO_CONFIG_PATH = '/var/www/daloradius/app/common/includes/daloradius.conf.php';
const FIREBASE_PROJECT_ID = 'ncutcc-cd082';
const FIREBASE_CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
const PASSWORD_LOGIN_ALLOWED_IPS = ['120.108.9.6', '127.0.0.1', '::1'];

function app_config(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $configValues = [];
    if (!is_readable(DALO_CONFIG_PATH)) {
        throw new RuntimeException('無法讀取 daloRADIUS 資料庫設定。');
    }
    require DALO_CONFIG_PATH;

    $values = $configValues;
    return $values;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config();
    $host = $cfg['CONFIG_DB_HOST'] ?? 'localhost';
    $port = $cfg['CONFIG_DB_PORT'] ?? '3306';
    $name = $cfg['CONFIG_DB_NAME'] ?? 'raddb';
    $user = $cfg['CONFIG_DB_USER'] ?? '';
    $pass = $cfg['CONFIG_DB_PASS'] ?? '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_secret_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }

    if (is_readable(PORTAL_SECRET_KEY_PATH)) {
        $raw = trim((string) file_get_contents(PORTAL_SECRET_KEY_PATH));
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            $key = $decoded;
            return $key;
        }
        throw new RuntimeException('Portal Secret Key 格式不正確。');
    }

    $dir = dirname(PORTAL_SECRET_KEY_PATH);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('無法建立 Portal Secret Key 目錄。');
    }
    $newKey = random_bytes(32);
    if (file_put_contents(PORTAL_SECRET_KEY_PATH, base64_encode($newKey), LOCK_EX) === false) {
        throw new RuntimeException('無法建立 Portal Secret Key。');
    }
    @chmod(PORTAL_SECRET_KEY_PATH, 0640);
    $key = $newKey;
    return $key;
}

function encrypt_secret(string $value): string
{
    if ($value === '' || str_starts_with($value, 'enc:v1:')) {
        return $value;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', portal_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || strlen($tag) !== 16) {
        throw new RuntimeException('敏感資料加密失敗。');
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(?string $value): string
{
    $value = (string) $value;
    if ($value === '' || !str_starts_with($value, 'enc:v1:')) {
        return $value;
    }
    $raw = base64_decode(substr($value, 7), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('敏感資料格式不正確。');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', portal_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('敏感資料解密失敗。');
    }
    return $plain;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($known) || !hash_equals($known, $sent)) {
        throw new RuntimeException('表單驗證逾時，請重新整理頁面後再試一次。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function local_redirect_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, "\r") || str_contains($path, "\n")) {
        return ADMIN_HOME_PATH;
    }
    return $path;
}

function remember_admin_return_to(string $path): void
{
    $_SESSION['admin_after_login'] = local_redirect_path($path);
}

function consume_admin_return_to(): string
{
    $path = local_redirect_path((string) ($_SESSION['admin_after_login'] ?? ADMIN_HOME_PATH));
    unset($_SESSION['admin_after_login']);
    return $path;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64);
}

function user_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

function password_login_allowed(): bool
{
    return PASSWORD_LOGIN_ENABLED && in_array(client_ip(), PASSWORD_LOGIN_ALLOWED_IPS, true);
}

function firebase_web_config(): array
{
    return [
        'apiKey' => 'AIzaSyBQHx2VC8SvwjOOTunbq6PiIS5HxB79u40',
        'authDomain' => 'ncutcc-cd082.firebaseapp.com',
        'projectId' => FIREBASE_PROJECT_ID,
        'storageBucket' => 'ncutcc-cd082.firebasestorage.app',
        'messagingSenderId' => '755991311078',
        'appId' => '1:755991311078:web:6db31913e05e97ee460127',
        'measurementId' => 'G-F018PJXRM4',
    ];
}

function base64url_decode_strict(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new RuntimeException('Google Token 格式不正確。');
    }
    return $decoded;
}

function decode_jwt_json(string $value): array
{
    $json = json_decode(base64url_decode_strict($value), true);
    if (!is_array($json)) {
        throw new RuntimeException('Google Token 內容無法解析。');
    }
    return $json;
}

function fetch_firebase_certs(bool $forceRefresh = false): array
{
    $cacheFile = sys_get_temp_dir() . '/ncut-firebase-certs.json';
    if (!$forceRefresh && is_readable($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() && is_array($cached['certs'] ?? null)) {
            return $cached['certs'];
        }
    }

    $headers = [];
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents(FIREBASE_CERTS_URL, false, $context);
    if ($body === false) {
        throw new RuntimeException('目前無法取得 Google 驗證憑證，請稍後再試。');
    }
    if (isset($http_response_header) && is_array($http_response_header)) {
        $headers = $http_response_header;
    }
    $certs = json_decode($body, true);
    if (!is_array($certs)) {
        throw new RuntimeException('Google 驗證憑證格式不正確。');
    }

    $maxAge = 3600;
    foreach ($headers as $header) {
        if (preg_match('/^Cache-Control:\s*.*max-age=(\d+)/i', $header, $m)) {
            $maxAge = max(300, (int) $m[1]);
            break;
        }
    }
    @file_put_contents($cacheFile, json_encode([
        'expires_at' => time() + $maxAge - 60,
        'certs' => $certs,
    ]), LOCK_EX);

    return $certs;
}

function verify_firebase_id_token(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new RuntimeException('Google Token 格式不正確。');
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = decode_jwt_json($encodedHeader);
    $payload = decode_jwt_json($encodedPayload);
    $signed = $encodedHeader . '.' . $encodedPayload;
    $signature = base64url_decode_strict($encodedSignature);

    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        throw new RuntimeException('Google Token 簽章格式不正確。');
    }

    $certs = fetch_firebase_certs();
    if (empty($certs[$header['kid']])) {
        $certs = fetch_firebase_certs(true);
    }
    if (empty($certs[$header['kid']])) {
        throw new RuntimeException('找不到對應的 Google 驗證憑證。');
    }

    $verified = openssl_verify($signed, $signature, $certs[$header['kid']], OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new RuntimeException('Google Token 簽章驗證失敗。');
    }

    $now = time();
    $issuer = 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID;
    if (($payload['iss'] ?? '') !== $issuer || ($payload['aud'] ?? '') !== FIREBASE_PROJECT_ID) {
        throw new RuntimeException('Google Token 專案來源不正確。');
    }
    if (empty($payload['sub']) || !is_string($payload['sub'])) {
        throw new RuntimeException('Google Token 缺少使用者識別。');
    }
    if (($payload['exp'] ?? 0) < $now || ($payload['iat'] ?? 0) > $now + 300) {
        throw new RuntimeException('Google Token 已過期或時間不正確。');
    }

    $email = strtolower((string) ($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Google 帳號未提供有效 Email。');
    }
    if (($payload['email_verified'] ?? false) !== true) {
        throw new RuntimeException('Google 帳號 Email 尚未驗證。');
    }
    return [
        'email' => $email,
        'name' => (string) ($payload['name'] ?? $email),
        'picture' => (string) ($payload['picture'] ?? ''),
        'sub' => (string) $payload['sub'],
    ];
}

function google_admin_id(PDO $pdo, string $email): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM guest_account_admins WHERE username = ? AND enabled = 1');
    $stmt->execute([strtolower($email)]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function upsert_google_admin(PDO $pdo, string $email, string $displayName = ''): int
{
    $username = strtolower(trim($email));
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('請輸入有效的 Google Email。');
    }
    $displayName = $displayName !== '' ? $displayName : $username;
    $stmt = $pdo->prepare(
        'INSERT INTO guest_account_admins (username, password_hash, display_name, enabled, created_at, updated_at)
         VALUES (?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), enabled = 1, updated_at = NOW()'
    );
    $stmt->execute([$username, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $displayName]);

    $stmt = $pdo->prepare('SELECT id FROM guest_account_admins WHERE username = ? AND enabled = 1');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        throw new RuntimeException('無法建立 Google 管理者。');
    }
    return (int) $id;
}

function current_user(): ?array
{
    $u = $_SESSION['auth'] ?? null;
    if (!is_array($u) || empty($u['email'])) {
        return null;
    }
    return $u;
}

function google_applicant(): ?array
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    return [
        'email' => (string) $u['email'],
        'name'  => (string) ($u['name'] ?? ''),
        'picture' => (string) ($u['picture'] ?? ''),
        'sub'   => (string) ($u['sub'] ?? ''),
    ];
}

function email_domain(string $email): string
{
    $parts = explode('@', strtolower(trim($email)));
    return count($parts) === 2 ? $parts[1] : '';
}

function normalize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/^@+/', '', $domain);
    return $domain ?? '';
}

function validate_domain(string $domain): bool
{
    return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain);
}

function allowed_domains(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, domain, enabled, created_by, created_at, max_months FROM guest_allowed_domains ORDER BY domain ASC');
    return $stmt->fetchAll();
}

function email_domain_allowed(PDO $pdo, string $email): bool
{
    $domain = email_domain($email);
    if ($domain === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM guest_allowed_domains WHERE enabled = 1 AND domain = ?');
    $stmt->execute([$domain]);
    return (int) $stmt->fetchColumn() > 0;
}

function setting_get(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM guest_mail_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : decrypt_secret((string) $value);
}

function setting_set(PDO $pdo, string $key, string $value, bool $isSecret = false): void
{
    if ($isSecret) {
        $value = encrypt_secret($value);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO guest_mail_settings (setting_key, setting_value, is_secret, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_at = NOW()'
    );
    $stmt->execute([$key, $value, $isSecret ? 1 : 0]);
}

function mail_settings(PDO $pdo): array
{
    return [
        'enabled' => setting_get($pdo, 'smtp_enabled', '0') === '1',
        'host' => setting_get($pdo, 'smtp_host', 'smtp.gmail.com'),
        'port' => setting_get($pdo, 'smtp_port', '587'),
        'secure' => setting_get($pdo, 'smtp_secure', '0') === '1',
        'user' => setting_get($pdo, 'smtp_user', ''),
        'pass' => setting_get($pdo, 'smtp_pass', ''),
        'from_email' => setting_get($pdo, 'smtp_from_email', ''),
        'from_name' => setting_get($pdo, 'smtp_from_name', 'NCUT eduroam'),
        'admin_recipients' => setting_get($pdo, 'notify_admins', ''),
    ];
}

function ad_settings(PDO $pdo): array
{
    return [
        'enabled' => setting_get($pdo, 'ad_enabled', '0') === '1',
        'mode' => setting_get($pdo, 'ad_mode', 'winbind'),
        'domain' => setting_get($pdo, 'ad_domain', ''),
        'netbios_domain' => setting_get($pdo, 'ad_netbios_domain', ''),
        'hosts' => setting_get($pdo, 'ad_hosts', ''),
        'port' => setting_get($pdo, 'ad_port', '389'),
        'use_ssl' => setting_get($pdo, 'ad_use_ssl', '0') === '1',
        'start_tls' => setting_get($pdo, 'ad_start_tls', '0') === '1',
        'verify_cert' => setting_get($pdo, 'ad_verify_cert', '1') === '1',
        'base_dn' => setting_get($pdo, 'ad_base_dn', ''),
        'bind_dn' => setting_get($pdo, 'ad_bind_dn', ''),
        'bind_password' => setting_get($pdo, 'ad_bind_password', ''),
        'user_attribute' => setting_get($pdo, 'ad_user_attribute', 'sAMAccountName'),
        'upn_suffix' => setting_get($pdo, 'ad_upn_suffix', ''),
        'ntlm_auth_path' => setting_get($pdo, 'ad_ntlm_auth_path', '/usr/bin/ntlm_auth'),
    ];
}

function sql_view_field_settings(): array
{
    return [
        'applicant_name' => ['setting' => 'sql_view_col_name', 'default' => 'name', 'required' => true],
        'applicant_email' => ['setting' => 'sql_view_col_email', 'default' => 'email', 'required' => true],
        'radius_username' => ['setting' => 'sql_view_col_username', 'default' => 'username', 'required' => true],
        'radius_password' => ['setting' => 'sql_view_col_password', 'default' => 'password', 'required' => false],
        'organization' => ['setting' => 'sql_view_col_organization', 'default' => 'organization', 'required' => false],
        'applicant_phone' => ['setting' => 'sql_view_col_phone', 'default' => 'phone', 'required' => false],
        'starts_at' => ['setting' => 'sql_view_col_starts_at', 'default' => 'starts_at', 'required' => false],
        'expires_at' => ['setting' => 'sql_view_col_expires_at', 'default' => 'expires_at', 'required' => false],
        'permanent' => ['setting' => 'sql_view_col_permanent', 'default' => 'permanent', 'required' => false],
        'reason' => ['setting' => 'sql_view_col_reason', 'default' => 'reason', 'required' => false],
    ];
}

function sql_view_list(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM guest_sql_views ORDER BY id ASC');
    return $stmt->fetchAll();
}

function sql_view_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM guest_sql_views WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function sql_view_row_to_settings(array $row): array
{
    $settings = [
        'enabled'             => (bool) $row['enabled'],
        'host'                => (string) $row['host'],
        'port'                => (string) $row['port'],
        'database'            => (string) $row['dbname'],
        'charset'             => (string) $row['charset'],
        'username'            => (string) $row['dbuser'],
        'password'            => decrypt_secret((string) $row['dbpass']),
        'view_name'           => (string) $row['view_name'],
        'status_column'       => (string) $row['status_column'],
        'status_active_value' => (string) $row['status_active_value'],
        'columns'             => [],
    ];
    foreach (sql_view_field_settings() as $key => $meta) {
        $settings['columns'][$key] = (string) ($row['col_' . $key] ?? '');
    }
    return $settings;
}

function sql_view_validate_identifier(string $identifier, string $label, bool $qualified = false): void
{
    $pattern = $qualified
        ? '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/'
        : '/^[A-Za-z_][A-Za-z0-9_]*$/';
    if (!preg_match($pattern, $identifier)) {
        throw new RuntimeException($label . ' 只能使用英文字母、數字與底線，且不可用數字開頭。');
    }
}

function sql_view_quote_identifier(string $identifier, bool $qualified = false): string
{
    sql_view_validate_identifier($identifier, 'SQL 欄位或 View 名稱', $qualified);
    $parts = explode('.', $identifier);
    return implode('.', array_map(static fn($part) => '`' . $part . '`', $parts));
}

function sql_view_validate_settings(array $settings, bool $requireConnection): void
{
    if (!$requireConnection && !$settings['enabled']) {
        return;
    }
    foreach (['host' => 'SQL 主機', 'database' => '資料庫名稱', 'username' => '資料庫帳號', 'view_name' => 'View 名稱'] as $key => $label) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            throw new RuntimeException($label . '不可空白。');
        }
    }
    $port = (string) ($settings['port'] ?? '');
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('SQL Port 必須介於 1 到 65535。');
    }
    sql_view_validate_identifier((string) $settings['view_name'], 'View 名稱', true);
    if ((string) ($settings['status_column'] ?? '') !== '') {
        sql_view_validate_identifier((string) $settings['status_column'], '狀態欄位');
    }
    foreach (sql_view_field_settings() as $key => $meta) {
        $column = trim((string) ($settings['columns'][$key] ?? ''));
        if ($column === '') {
            if ($meta['required']) {
                throw new RuntimeException('必要對應欄位不可空白：' . $key);
            }
            continue;
        }
        sql_view_validate_identifier($column, '對應欄位 ' . $key);
    }
}

function sql_view_external_pdo(array $settings): PDO
{
    sql_view_validate_settings($settings, true);
    $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) $settings['charset']) ? (string) $settings['charset'] : 'utf8mb4';
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $settings['host'],
        (int) $settings['port'],
        (string) $settings['database'],
        $charset
    );
    return new PDO($dsn, (string) $settings['username'], (string) $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function sql_view_get_columns(array $settings): array
{
    foreach (['host' => 'SQL 主機', 'database' => '資料庫名稱', 'username' => '資料庫帳號', 'view_name' => 'View 名稱'] as $key => $label) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            throw new RuntimeException($label . '不可空白。');
        }
    }
    $port = (string) ($settings['port'] ?? '');
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('SQL Port 必須介於 1 到 65535。');
    }
    $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) $settings['charset']) ? (string) $settings['charset'] : 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $settings['host'], (int) $settings['port'],
        (string) $settings['database'], $charset
    );
    $ext = new PDO($dsn, (string) $settings['username'], (string) $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $stmt = $ext->query('SELECT * FROM ' . sql_view_quote_identifier((string) $settings['view_name'], true) . ' LIMIT 0');
    $columns = [];
    for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
        $meta = $stmt->getColumnMeta($i);
        if ($meta !== false && isset($meta['name'])) {
            $columns[] = (string) $meta['name'];
        }
    }
    return $columns;
}

function sql_view_fetch_rows(array $settings, int $limit = 0): array
{
    $external = sql_view_external_pdo($settings);
    $limit = $limit > 0 ? $limit : 0;
    $select = [];
    foreach (sql_view_field_settings() as $key => $meta) {
        $column = trim((string) ($settings['columns'][$key] ?? ''));
        $alias = sql_view_quote_identifier($key);
        $select[] = $column !== ''
            ? sql_view_quote_identifier($column) . ' AS ' . $alias
            : "'' AS " . $alias;
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . sql_view_quote_identifier((string) $settings['view_name'], true);
    $params = [];
    $statusColumn = trim((string) ($settings['status_column'] ?? ''));
    $statusValue = (string) ($settings['status_active_value'] ?? '');
    if ($statusColumn !== '' && $statusValue !== '') {
        $sql .= ' WHERE ' . sql_view_quote_identifier($statusColumn) . ' = :status_value';
        $params[':status_value'] = $statusValue;
    }
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }

    $stmt = $external->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function radius_proxy_pool_types(): array
{
    return [
        'fail-over' => 'Fail-over',
        'load-balance' => 'Load-balance',
    ];
}

function radius_proxy_status_checks(): array
{
    return [
        'status-server' => 'Status-Server',
        'none' => 'None',
    ];
}

function normalize_proxy_realm(string $realm): string
{
    $realm = trim($realm);
    $realm = preg_replace('/^@+/', '', $realm) ?? $realm;
    if (strcasecmp($realm, 'DEFAULT') === 0) {
        return 'DEFAULT';
    }
    return normalize_domain($realm);
}

function radius_proxy_is_default_realm(string $realm): bool
{
    return strcasecmp($realm, 'DEFAULT') === 0;
}

function validate_proxy_realm(string $realm): void
{
    if ($realm === '') {
        throw new RuntimeException('Proxy realm 不可空白。');
    }
    if (radius_proxy_is_default_realm($realm)) {
        return;
    }
    if (in_array(strtolower($realm), ['local', 'null'], true)) {
        throw new RuntimeException('LOCAL / NULL 為系統保留 realm，不可由此頁管理。');
    }
    if (!validate_domain($realm)) {
        throw new RuntimeException('Proxy realm 必須是有效網域，例如 example.edu.tw。');
    }
    if (is_ncut_realm($realm)) {
        throw new RuntimeException('本校 realm 由本機認證處理，不可設定為外部 proxy。');
    }
}

function validate_proxy_name(string $name, string $label): void
{
    if ($name === '' || mb_strlen($name) > 80) {
        throw new RuntimeException($label . '需介於 1 到 80 字元。');
    }
}

function validate_proxy_host(string $host): void
{
    if ($host === '' || strlen($host) > 190 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $host)) {
        throw new RuntimeException('Home Server 主機只能使用 IP、FQDN、冒號、底線、連字號與句點。');
    }
}

function validate_proxy_port(string $port, string $label): int
{
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException($label . ' 必須介於 1 到 65535。');
    }
    return (int) $port;
}

function validate_proxy_timing(string $value, string $label, int $min, int $max): int
{
    if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
        throw new RuntimeException($label . " 必須介於 {$min} 到 {$max} 秒。");
    }
    return (int) $value;
}

function radius_proxy_groups(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT g.*,
            (SELECT COUNT(*) FROM radius_proxy_servers s WHERE s.group_id = g.id) AS server_count,
            (SELECT COUNT(*) FROM radius_proxy_servers s WHERE s.group_id = g.id AND s.enabled = 1) AS enabled_server_count
         FROM radius_proxy_groups g
         ORDER BY g.enabled DESC, g.realm ASC, g.id ASC'
    );
    return $stmt->fetchAll();
}

function radius_proxy_group_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM radius_proxy_groups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function radius_proxy_default_group_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM radius_proxy_groups WHERE UPPER(realm) = 'DEFAULT'");
    return (int) $stmt->fetchColumn() > 0;
}

function radius_proxy_servers(PDO $pdo, int $groupId, bool $decryptSecrets = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM radius_proxy_servers WHERE group_id = ? ORDER BY enabled DESC, id ASC');
    $stmt->execute([$groupId]);
    $rows = $stmt->fetchAll();
    if ($decryptSecrets) {
        foreach ($rows as &$row) {
            $row['shared_secret_plain'] = decrypt_secret((string) $row['shared_secret']);
        }
        unset($row);
    }
    return $rows;
}

function radius_proxy_server_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM radius_proxy_servers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function radius_proxy_active_config(PDO $pdo): array
{
    $groups = [];
    foreach (radius_proxy_groups($pdo) as $group) {
        if ((int) $group['enabled'] !== 1) {
            continue;
        }
        $servers = array_values(array_filter(
            radius_proxy_servers($pdo, (int) $group['id'], true),
            static fn($server) => (int) $server['enabled'] === 1
        ));
        if (!$servers) {
            continue;
        }
        $group['servers'] = $servers;
        $groups[] = $group;
    }
    return $groups;
}

function parse_recipients(string $value): array
{
    $items = preg_split('/[,;\s]+/', $value) ?: [];
    $valid = [];
    foreach ($items as $item) {
        $email = strtolower(trim($item));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[] = $email;
        }
    }
    return array_values(array_unique($valid));
}

function mail_node_binary(): string
{
    foreach (['/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'node';
}

function mailer_failure_message(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return 'Email 未送出：Nodemailer 未回傳錯誤內容，請檢查 Web Server 錯誤紀錄。';
    }
    if (stripos($output, "Cannot find module 'nodemailer'") !== false || stripos($output, 'Cannot find module "nodemailer"') !== false) {
        return 'Email 未送出：Nodemailer 套件尚未安裝，請在 mailer 目錄執行 npm install --omit=dev。';
    }
    if (preg_match('/\b(535|534)\b|Invalid login|Username and Password not accepted/i', $output)) {
        return 'Email 未送出：Gmail 拒絕登入，請確認 Gmail 帳號、App Password 與兩步驟驗證設定。';
    }
    if (preg_match('/ECONNREFUSED|ETIMEDOUT|ENOTFOUND|ENETUNREACH|Connection timeout/i', $output)) {
        return 'Email 未送出：無法連線到 Gmail SMTP，請確認主機 DNS、網路與對外 587/tcp 是否可用。';
    }

    $firstLine = strtok($output, "\r\n");
    return 'Email 未送出：' . mb_substr((string) $firstLine, 0, 240);
}

function mail_runtime_warnings(): array
{
    $warnings = [];
    $mailerDir = dirname(__DIR__) . '/mailer';
    if (!is_readable($mailerDir . '/send-mail.js')) {
        $warnings[] = '找不到 mailer/send-mail.js，無法執行寄信程式。';
    }
    if (!is_readable($mailerDir . '/node_modules/nodemailer/package.json')) {
        $warnings[] = 'Nodemailer 尚未安裝，請在 /var/www/eduroam-portal/mailer 執行 npm install --omit=dev。';
    }
    if (!is_executable('/usr/bin/node') && !is_executable('/usr/local/bin/node')) {
        $warnings[] = '找不到 /usr/bin/node 或 /usr/local/bin/node，系統會嘗試使用 PATH 中的 node。';
    }
    return $warnings;
}

function mailer_failure_message_safe(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return 'Email not sent: Nodemailer did not return an error message.';
    }
    if (stripos($output, "Cannot find module 'nodemailer'") !== false || stripos($output, 'Cannot find module "nodemailer"') !== false) {
        return 'Email not sent: Nodemailer is not installed.';
    }
    if (preg_match('/\b(535|534)\b|Invalid login|Username and Password not accepted/i', $output)) {
        return 'Email not sent: Gmail rejected the login. Please check Gmail account, App Password, and 2-Step Verification.';
    }
    if (preg_match('/ECONNREFUSED|ETIMEDOUT|ENOTFOUND|ENETUNREACH|Connection timeout/i', $output)) {
        return 'Email not sent: cannot connect to Gmail SMTP. Please check DNS/network/587-tcp.';
    }
    if (preg_match('/Fatal error in , line 0|V8_Fatal|reservation_\\.SetPermissions|SetPermissions\\(/i', $output)) {
        return 'Email not sent: Node.js was blocked in the web server context. Please verify SELinux httpd_execmem is enabled.';
    }

    $safe = preg_replace('/AUTH (PLAIN|LOGIN)\s+[A-Za-z0-9+\/=_-]+/i', 'AUTH $1 [redacted]', $output) ?? $output;
    $safe = preg_replace('/(pass(word)?|smtp_pass|auth)[=: ]+[^\\s]+/i', '$1=[redacted]', $safe) ?? $safe;
    $lines = preg_split('/\R+/', $safe) ?: [$safe];
    $firstUseful = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && $line !== '#') {
            $firstUseful = $line;
            break;
        }
    }
    if ($firstUseful === '') {
        if (trim($safe, "# \t\r\n") === '') {
            return 'Email not sent: Nodemailer did not return a usable error message.';
        }
        $firstUseful = mb_substr($safe, 0, 500);
    }
    return 'Email not sent: ' . mb_substr($firstUseful, 0, 500);
}

function html_mail(string $title, array $rows, string $body = '', array $actions = []): string
{
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.55;color:#1d2635;max-width:760px;margin:0 auto;padding:20px">';
    $html .= '<h2 style="margin:0 0 16px">' . e($title) . '</h2>';
    if ($body !== '') {
        $html .= '<p style="margin:0 0 16px">' . nl2br(e($body)) . '</p>';
    }
    $html .= '<table style="border-collapse:collapse;width:100%;max-width:720px">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px;width:150px;font-weight:600">' . e((string) $label) . '</th>';
        $html .= '<td style="border:1px solid #dbe2ea;padding:8px">' . nl2br(e((string) $value)) . '</td></tr>';
    }
    $html .= '</table>';
    if ($actions) {
        $html .= '<div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">';
        foreach ($actions as $ac) {
            $bg = ($ac['danger'] ?? false) ? '#c62828' : '#1667c7';
            $html .= '<a href="' . e($ac['url']) . '" style="display:inline-block;padding:12px 28px;background:' . $bg . ';color:#ffffff;text-decoration:none;border-radius:6px;font-weight:700;font-size:15px;">' . e($ac['label']) . '</a>';
        }
        $html .= '</div>';
        $html .= '<p style="color:#6b7787;font-size:12px;margin-top:8px">此連結 72 小時內有效，且只能使用一次。請勿轉發此郵件給非管理者人員。</p>';
    }
    $html .= '<p style="color:#6b7787;font-size:13px;margin-top:18px">NCUT eduroam 臨時帳號申請系統</p>';
    $html .= '</body></html>';
    return $html;
}

function text_mail(string $title, array $rows, string $body = '', array $actions = []): string
{
    $lines = [$title, str_repeat('=', mb_strlen($title))];
    if ($body !== '') {
        $lines[] = '';
        $lines[] = $body;
    }
    foreach ($rows as $label => $value) {
        $lines[] = "{$label}: {$value}";
    }
    if ($actions) {
        $lines[] = '';
        $lines[] = str_repeat('-', 40);
        foreach ($actions as $ac) {
            $lines[] = $ac['label'] . ': ' . $ac['url'];
        }
        $lines[] = '（連結 72 小時內有效，且只能使用一次）';
    }
    $lines[] = '';
    $lines[] = 'NCUT eduroam 臨時帳號申請系統';
    return implode("\n", $lines);
}

function send_portal_mail(PDO $pdo, array $mail): bool
{
    $settings = mail_settings($pdo);
    $to = is_array($mail['to'] ?? null) ? $mail['to'] : parse_recipients((string) ($mail['to'] ?? ''));
    $errors = [];
    if (!$settings['enabled']) {
        $errors[] = 'Email 通知尚未啟用';
    }
    if ($settings['host'] === '') {
        $errors[] = 'SMTP 主機未設定';
    }
    if ($settings['user'] === '') {
        $errors[] = 'Gmail 帳號未設定';
    } elseif (!filter_var($settings['user'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Gmail 帳號格式不正確';
    }
    if ($settings['pass'] === '') {
        $errors[] = 'Gmail App Password 未設定';
    }
    if (!$to) {
        $errors[] = '收件人 Email 未設定';
    }
    if ($settings['from_email'] !== '' && !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '寄件者 Email 格式不正確';
    }
    if ($errors) {
        throw new RuntimeException('Email 未送出：' . implode('、', $errors) . '。');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Email 未送出：PHP proc_open 未啟用，無法執行 Nodemailer。');
    }

    $fromEmail = $settings['from_email'] !== '' ? $settings['from_email'] : $settings['user'];
    $fromName = $settings['from_name'] !== '' ? $settings['from_name'] : 'NCUT eduroam';
    $payload = [
        'smtp' => [
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            'secure' => $settings['secure'],
            'user' => $settings['user'],
            'pass' => $settings['pass'],
        ],
        'from' => sprintf('%s <%s>', $fromName, $fromEmail),
        'to' => $to,
        'subject' => (string) ($mail['subject'] ?? ''),
        'text' => (string) ($mail['text'] ?? ''),
        'html' => (string) ($mail['html'] ?? ''),
        'replyTo' => (string) ($mail['replyTo'] ?? ''),
    ];

    $mailerScript = dirname(__DIR__) . '/mailer/send-mail.js';
    $mailerDir = dirname($mailerScript);
    if (!is_readable($mailerScript)) {
        throw new RuntimeException('Email 未送出：找不到 mailer/send-mail.js。');
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new RuntimeException('Email 未送出：信件內容無法轉成 JSON。');
    }

    $cmd = escapeshellarg(mail_node_binary()) . ' ' . escapeshellarg($mailerScript);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $mailerDir);
    if (!is_resource($process)) {
        error_log('eduroam portal mail: unable to start nodemailer');
        throw new RuntimeException('Email 未送出：無法啟動 Node/Nodemailer。');
    }
    fwrite($pipes[0], $payloadJson);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $output = trim($stderr ?: $stdout);
        error_log('eduroam portal mail failed: ' . $output);
        throw new RuntimeException(mailer_failure_message_safe($output));
    }
    return true;
}

function notify_admins(PDO $pdo, string $subject, string $title, array $rows, string $body = '', array $actions = []): void
{
    $settings = mail_settings($pdo);
    if (!$settings['enabled']) {
        return;
    }
    $recipients = parse_recipients($settings['admin_recipients']);
    if (!$recipients) {
        return;
    }
    try {
        send_portal_mail($pdo, [
            'to' => $recipients,
            'subject' => $subject,
            'text' => text_mail($title, $rows, $body, $actions),
            'html' => html_mail($title, $rows, $body, $actions),
        ]);
    } catch (Throwable $e) {
        error_log('eduroam portal admin notification failed: ' . $e->getMessage());
    }
}

function notify_applicant(PDO $pdo, string $to, string $subject, string $title, array $rows, string $body = ''): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    if (!mail_settings($pdo)['enabled']) {
        return;
    }
    try {
        send_portal_mail($pdo, [
            'to' => [$to],
            'subject' => $subject,
            'text' => text_mail($title, $rows, $body),
            'html' => html_mail($title, $rows, $body),
        ]);
    } catch (Throwable $e) {
        error_log('eduroam portal applicant notification failed: ' . $e->getMessage());
    }
}

function normalize_radius_username(string $username): string
{
    return strtolower(trim($username));
}

function username_realm(string $username): string
{
    $username = normalize_radius_username($username);
    $at = strrpos($username, '@');
    return $at === false ? '' : substr($username, $at + 1);
}

function is_ncut_realm(string $realm): bool
{
    $realm = normalize_domain($realm);
    return $realm === 'ncut.edu.tw' || str_ends_with($realm, '.ncut.edu.tw');
}

function is_ncut_username(string $username): bool
{
    return is_ncut_realm(username_realm($username));
}

function validate_radius_username(string $username): bool
{
    return strlen($username) <= 64 && (bool) preg_match('/^[a-z0-9._%+\-]+@ncut\.edu\.tw$/i', $username);
}

function generate_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function radius_expiration_value(string $expiresAt): string
{
    $dt = new DateTimeImmutable($expiresAt, new DateTimeZone('Asia/Taipei'));
    return $dt->format('M d Y H:i:s');
}

function parse_datetime_local(string $value): DateTimeImmutable
{
    $value = trim($value);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('Asia/Taipei'));
    if (!$dt) {
        throw new RuntimeException('到期時間格式不正確。');
    }
    return $dt;
}

function parse_date_input(string $value, string $label): DateTimeImmutable
{
    $value = trim($value);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . '日期格式不正確。');
    }
    return $dt;
}

function request_email_domain(string $email): string
{
    $at = strrpos($email, '@');
    return $at === false ? '' : strtolower(substr($email, $at + 1));
}

function request_max_months_for_email(PDO $pdo, string $email): int
{
    $domain = request_email_domain($email);
    if ($domain === '') {
        return 12;
    }
    $stmt = $pdo->prepare('SELECT max_months FROM guest_allowed_domains WHERE domain = ?');
    $stmt->execute([$domain]);
    $val = $stmt->fetchColumn();
    return ($val !== false && (int) $val > 0) ? (int) $val : 12;
}

function request_max_end_date(PDO $pdo, string $email, DateTimeImmutable $startDate): DateTimeImmutable
{
    return $startDate->modify('+' . request_max_months_for_email($pdo, $email) . ' months');
}

function validate_requested_period(PDO $pdo, string $email, DateTimeImmutable $startDate, DateTimeImmutable $endDate): void
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei'));
    if ($startDate < $today) {
        throw new RuntimeException('使用起日不可早於今天。');
    }
    if ($endDate < $startDate) {
        throw new RuntimeException('使用迄日不可早於使用起日。');
    }

    $maxMonths = request_max_months_for_email($pdo, $email);
    $maxEnd = request_max_end_date($pdo, $email, $startDate);
    if ($endDate > $maxEnd) {
        throw new RuntimeException('使用期限超過限制：此網域帳號最多可申請 ' . $maxMonths . ' 個月。');
    }
}

function validate_extension_period(PDO $pdo, string $email, DateTimeImmutable $startDate, DateTimeImmutable $currentEndDate, DateTimeImmutable $requestedEndDate): void
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei'));
    if ($requestedEndDate < $today) {
        throw new RuntimeException('展延迄日不可早於今天。');
    }
    if ($requestedEndDate <= $currentEndDate) {
        throw new RuntimeException('展延迄日必須晚於目前到期日。');
    }

    $maxMonths = request_max_months_for_email($pdo, $email);
    $maxEnd = request_max_end_date($pdo, $email, $startDate);
    if ($requestedEndDate > $maxEnd) {
        throw new RuntimeException('展延期限超過限制：此網域帳號最多可申請 ' . $maxMonths . ' 個月。');
    }
}

function date_to_start_at(DateTimeImmutable $date): string
{
    return $date->setTime(0, 0, 0)->format('Y-m-d H:i:s');
}

function date_to_expires_at(DateTimeImmutable $date): string
{
    return $date->setTime(23, 59, 59)->format('Y-m-d H:i:s');
}

function request_code(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function admin_user(): ?array
{
    $u = current_user();
    if (!$u || empty($u['is_admin']) || empty($u['admin_id'])) {
        return null;
    }
    $now = time();
    $authenticatedAt = (int) ($u['authenticated_at'] ?? $now);
    $lastActivity = (int) ($u['last_activity'] ?? $now);
    if (($now - $authenticatedAt) > SESSION_LIFETIME_SECONDS || ($now - $lastActivity) > ADMIN_IDLE_TIMEOUT_SECONDS) {
        unset($_SESSION['auth']);
        return null;
    }

    $stmt = db()->prepare('SELECT id, username, display_name, enabled FROM guest_account_admins WHERE id = ? AND username = ? AND enabled = 1');
    $stmt->execute([(int) $u['admin_id'], strtolower((string) $u['email'])]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['auth']);
        return null;
    }
    $_SESSION['auth']['authenticated_at'] = $authenticatedAt;
    $_SESSION['auth']['last_activity'] = $now;

    return [
        'id'           => (int) $row['id'],
        'username'     => (string) $row['username'],
        'display_name' => (string) ($row['display_name'] ?: $row['username']),
        'enabled'      => 1,
    ];
}

function require_admin(): array
{
    $admin = admin_user();
    if (!$admin) {
        redirect('/admin.php');
    }
    return $admin;
}

function audit(PDO $pdo, ?int $adminId, string $action, ?int $requestId, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO guest_account_audit (admin_id, action, request_id, message, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$adminId, $action, $requestId, $message, client_ip()]);
}

function security_audit(PDO $pdo, string $action, string $message): void
{
    try {
        audit($pdo, null, $action, null, mb_substr($message, 0, 500));
    } catch (Throwable $e) {
        error_log('eduroam portal security audit failed: ' . $e->getMessage());
    }
}

function assert_auth_rate_limit(PDO $pdo): void
{
    $minutes = AUTH_FAILURE_WINDOW_MINUTES;
    $actions = ['admin_auth_failed', 'admin_google_denied', 'admin_token_failed', 'admin_password_login_blocked'];
    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM guest_account_audit
         WHERE action IN ({$placeholders})
           AND ip_address = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
    );
    $stmt->execute([...$actions, client_ip()]);
    if ((int) $stmt->fetchColumn() >= AUTH_FAILURE_LIMIT) {
        security_audit($pdo, 'admin_auth_rate_limited', 'too many admin authentication failures');
        throw new RuntimeException('登入嘗試次數過多，請稍後再試。');
    }
}

function generate_email_action_token(PDO $pdo, string $action, int $requestId, int $ttlHours = 72): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))
        ->modify("+{$ttlHours} hours")
        ->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO guest_email_action_tokens (token, action, request_id, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())'
    )->execute([$tokenHash, $action, $requestId, $expiresAt]);
    return $token;
}

function validate_email_action_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM guest_email_action_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function consume_email_action_token(PDO $pdo, int $tokenId): void
{
    $stmt = $pdo->prepare('UPDATE guest_email_action_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $stmt->execute([$tokenId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('此審核連結已被使用，請重新整理確認最新狀態。');
    }
}

function radius_user_exists(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn() > 0;
}

function radius_sql_alias(string $alias): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
        throw new RuntimeException('Unsupported SQL alias.');
    }
    return $alias;
}

function radius_identity_source_condition(string $type, string $alias, bool $allowAll = false): string
{
    $alias = radius_sql_alias($alias);
    if ($allowAll && $type === 'all') {
        return '1=1';
    }
    $usernameSql = "CONVERT({$alias}.username USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $radcheckUsernameSql = 'CONVERT(rc.username USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    $local = "(EXISTS (SELECT 1 FROM radcheck rc WHERE {$radcheckUsernameSql} = {$usernameSql}) OR LOWER({$alias}.username) LIKE '%@ncut.edu.tw' OR LOWER({$alias}.username) LIKE '%@%.ncut.edu.tw')";
    return match ($type) {
        'local' => $local,
        'tanrc' => "{$alias}.username LIKE '%@%' AND NOT {$local}",
        'no_realm' => "{$alias}.username NOT LIKE '%@%' AND NOT EXISTS (SELECT 1 FROM radcheck rc WHERE {$radcheckUsernameSql} = {$usernameSql})",
        default => throw new RuntimeException('未知的認證紀錄分類。'),
    };
}

function radius_identity_source_label_sql(string $alias): string
{
    $alias = radius_sql_alias($alias);
    $usernameSql = "CONVERT({$alias}.username USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $radcheckUsernameSql = 'CONVERT(rc.username USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    return "CASE
            WHEN EXISTS (SELECT 1 FROM radcheck rc WHERE {$radcheckUsernameSql} = {$usernameSql}) THEN '本機 SQL'
            WHEN LOWER({$alias}.username) LIKE '%@ncut.edu.tw' OR LOWER({$alias}.username) LIKE '%@%.ncut.edu.tw' THEN '本校 realm'
            WHEN {$alias}.username LIKE '%@%' THEN 'TANRC 外校'
            ELSE '未帶 realm'
        END";
}

function radius_acct_session_seconds_sql(string $alias): string
{
    $alias = radius_sql_alias($alias);
    return "CASE
            WHEN COALESCE({$alias}.acctsessiontime, 0) > 0 THEN COALESCE({$alias}.acctsessiontime, 0)
            ELSE COALESCE(GREATEST(TIMESTAMPDIFF(SECOND, {$alias}.acctstarttime, COALESCE({$alias}.acctstoptime, {$alias}.acctupdatetime, NOW())), 0), 0)
        END";
}

function radius_acct_octets_sql(string $alias): string
{
    $alias = radius_sql_alias($alias);
    return "(COALESCE({$alias}.acctinputoctets, 0) + COALESCE({$alias}.acctoutputoctets, 0))";
}

function human_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}

function human_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . '天 ' . $hours . '小時';
    }
    if ($hours > 0) {
        return $hours . '小時 ' . $minutes . '分';
    }
    if ($minutes > 0) {
        return $minutes . '分';
    }
    return $seconds . '秒';
}

function dashboard_percent(float $value): float
{
    return round(max(0.0, min(100.0, $value)), 1);
}

function dashboard_level(?float $percent): string
{
    if ($percent === null) {
        return 'unknown';
    }
    if ($percent >= 90.0) {
        return 'critical';
    }
    if ($percent >= 80.0) {
        return 'warning';
    }
    return 'ok';
}

function dashboard_request_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM guest_account_requests WHERE status = "pending") AS pending_requests,
            (SELECT COUNT(*) FROM guest_account_extension_requests WHERE status = "pending") AS pending_extensions,
            (SELECT COUNT(*) FROM guest_account_requests WHERE status = "approved") AS approved_accounts,
            (SELECT COUNT(*) FROM guest_account_requests WHERE status = "disabled") AS disabled_accounts,
            (SELECT COUNT(*) FROM guest_account_requests
             WHERE status = "approved"
               AND expires_at IS NOT NULL
               AND expires_at >= NOW()
               AND expires_at < DATE_ADD(NOW(), INTERVAL 7 DAY)) AS expiring_soon,
            (SELECT COUNT(*) FROM guest_account_requests
             WHERE status = "approved"
               AND expires_at IS NOT NULL
               AND expires_at < NOW()) AS expired_accounts'
    );
    $row = $stmt->fetch() ?: [];
    return [
        'pending_requests' => (int) ($row['pending_requests'] ?? 0),
        'pending_extensions' => (int) ($row['pending_extensions'] ?? 0),
        'approved_accounts' => (int) ($row['approved_accounts'] ?? 0),
        'disabled_accounts' => (int) ($row['disabled_accounts'] ?? 0),
        'expiring_soon' => (int) ($row['expiring_soon'] ?? 0),
        'expired_accounts' => (int) ($row['expired_accounts'] ?? 0),
    ];
}

function dashboard_recent_requests(PDO $pdo, int $limit = 8): array
{
    $limit = max(3, min($limit, 20));
    return $pdo->query(
        "SELECT id, request_code, applicant_name, applicant_email, requested_username, radius_username,
                status, desired_start, desired_end, starts_at, expires_at, created_at, updated_at
         FROM guest_account_requests
         ORDER BY updated_at DESC, id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function dashboard_auth_outcome_summary(PDO $pdo, int $hours = 24): array
{
    $hours = max(1, min($hours, 720));
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN reply = 'Access-Accept' THEN 1 ELSE 0 END) AS accept_count,
            SUM(CASE WHEN reply <> 'Access-Accept' THEN 1 ELSE 0 END) AS reject_count,
            MAX(authdate) AS last_authdate
         FROM radpostauth
         WHERE authdate >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)"
    );
    $row = $stmt->fetch() ?: [];
    return [
        'total_count' => (int) ($row['total_count'] ?? 0),
        'accept_count' => (int) ($row['accept_count'] ?? 0),
        'reject_count' => (int) ($row['reject_count'] ?? 0),
        'last_authdate' => $row['last_authdate'] ?? null,
    ];
}

function dashboard_memory_usage(): array
{
    $values = [];
    if (is_readable('/proc/meminfo')) {
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2] * 1024;
            }
        }
    }

    $total = (int) ($values['MemTotal'] ?? 0);
    $available = (int) ($values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0)));
    $used = max(0, $total - $available);
    $percent = $total > 0 ? dashboard_percent($used * 100 / $total) : null;

    return [
        'total_bytes' => $total,
        'available_bytes' => $available,
        'used_bytes' => $used,
        'used_percent' => $percent,
        'level' => dashboard_level($percent),
    ];
}

function dashboard_cpu_load(): array
{
    $loads = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if (!is_array($loads)) {
        $loads = [0.0, 0.0, 0.0];
    }

    $cpuCount = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cpuInfo = (string) file_get_contents('/proc/cpuinfo');
        $cpuCount = preg_match_all('/^processor\s*:/m', $cpuInfo) ?: 0;
    }
    $cpuCount = max(1, (int) $cpuCount);
    $load1 = (float) ($loads[0] ?? 0.0);
    $percent = dashboard_percent($load1 * 100 / $cpuCount);

    return [
        'load1' => round($load1, 2),
        'load5' => round((float) ($loads[1] ?? 0.0), 2),
        'load15' => round((float) ($loads[2] ?? 0.0), 2),
        'cpu_count' => $cpuCount,
        'load_percent' => $percent,
        'level' => dashboard_level($percent),
    ];
}

function dashboard_disk_usage(array $paths = []): array
{
    $paths = $paths ?: ['/', '/var', '/var/log', '/var/www', '/etc/raddb'];
    $items = [];
    foreach ($paths as $path) {
        $path = (string) $path;
        if ($path === '' || !is_dir($path)) {
            continue;
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!is_numeric($total) || !is_numeric($free) || (float) $total <= 0) {
            continue;
        }
        $totalBytes = (int) $total;
        $freeBytes = (int) $free;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $percent = dashboard_percent($usedBytes * 100 / $totalBytes);
        $items[] = [
            'path' => $path,
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'used_bytes' => $usedBytes,
            'used_percent' => $percent,
            'level' => dashboard_level($percent),
        ];
    }
    return $items;
}

function dashboard_service_status(array $services = []): array
{
    $services = $services ?: ['httpd', 'php-fpm', 'radiusd', 'mariadb'];
    $systemctl = is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl' : '/bin/systemctl';
    $items = [];
    foreach ($services as $service) {
        $service = (string) $service;
        if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $service) || !is_executable($systemctl) || !function_exists('proc_open')) {
            $items[] = ['name' => $service, 'status' => 'unknown', 'level' => 'unknown'];
            continue;
        }

        $cmd = escapeshellarg($systemctl) . ' is-active ' . escapeshellarg($service);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $items[] = ['name' => $service, 'status' => 'unknown', 'level' => 'unknown'];
            continue;
        }
        $stdout = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $status = $stdout !== '' ? $stdout : ($stderr !== '' ? $stderr : 'unknown');
        if ($exitCode !== 0 && $status === 'unknown') {
            $status = 'inactive';
        }
        $level = match ($status) {
            'active' => 'ok',
            'activating', 'deactivating' => 'warning',
            'inactive', 'failed' => 'critical',
            default => 'unknown',
        };
        $items[] = ['name' => $service, 'status' => $status, 'level' => $level];
    }
    return $items;
}

function auth_attempt_condition(string $type): string
{
    return radius_identity_source_condition($type, 'rp');
}

function table_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int) $stmt->fetchColumn() > 0;
    return $cache[$key];
}

function auth_nullable_column(PDO $pdo, string $table, string $alias, string $column): string
{
    return table_column_exists($pdo, $table, $column) ? $alias . '.' . $column : 'NULL';
}

function auth_latest_acct_value(string $column): string
{
    $allowed = [
        'nasipaddress',
        'nasportid',
        'calledstationid',
        'callingstationid',
        'framedipaddress',
        'framedipv6address',
    ];
    if (!in_array($column, $allowed, true)) {
        throw new RuntimeException('Unsupported accounting column.');
    }
    return "(SELECT NULLIF(ra.{$column}, '')
              FROM radacct ra
              WHERE ra.username = rp.username
                AND ra.{$column} IS NOT NULL
                AND ra.{$column} <> ''
              ORDER BY COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime) DESC
              LIMIT 1)";
}

function auth_attempt_select_sql(PDO $pdo): string
{
    $nasIp = auth_nullable_column($pdo, 'radpostauth', 'rp', 'nasipaddress');
    $nasIdentifier = auth_nullable_column($pdo, 'radpostauth', 'rp', 'nasidentifier');
    $nasPortId = auth_nullable_column($pdo, 'radpostauth', 'rp', 'nasportid');
    $calledStation = auth_nullable_column($pdo, 'radpostauth', 'rp', 'calledstationid');
    $callingStation = auth_nullable_column($pdo, 'radpostauth', 'rp', 'callingstationid');
    $packetSource = auth_nullable_column($pdo, 'radpostauth', 'rp', 'packet_src_ipaddress');
    $class = auth_nullable_column($pdo, 'radpostauth', 'rp', 'class');

    return "
        rp.id,
        rp.username,
        rp.reply,
        rp.authdate,
        {$class} AS class,
        COALESCE(NULLIF({$nasIp}, ''), " . auth_latest_acct_value('nasipaddress') . ") AS nasipaddress,
        NULLIF({$nasIdentifier}, '') AS nasidentifier,
        COALESCE(NULLIF({$nasPortId}, ''), " . auth_latest_acct_value('nasportid') . ") AS nasportid,
        COALESCE(NULLIF({$calledStation}, ''), " . auth_latest_acct_value('calledstationid') . ") AS calledstationid,
        COALESCE(NULLIF({$callingStation}, ''), " . auth_latest_acct_value('callingstationid') . ") AS callingstationid,
        NULLIF({$packetSource}, '') AS packet_src_ipaddress,
        " . radius_identity_source_label_sql('rp') . " AS source_label
    ";
}

function online_radius_summary(PDO $pdo): array
{
    $local = radius_identity_source_condition('local', 'ra');
    $tanrc = radius_identity_source_condition('tanrc', 'ra');
    $noRealm = radius_identity_source_condition('no_realm', 'ra');
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN {$local} THEN 1 ELSE 0 END) AS local_count,
            SUM(CASE WHEN {$tanrc} THEN 1 ELSE 0 END) AS tanrc_count,
            SUM(CASE WHEN {$noRealm} THEN 1 ELSE 0 END) AS no_realm_count,
            SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, COALESCE(ra.acctupdatetime, ra.acctstarttime), NOW()) > 30 THEN 1 ELSE 0 END) AS stale_count
        FROM radacct ra
        WHERE ra.acctstoptime IS NULL
    ");
    $row = $stmt->fetch() ?: [];
    return [
        'total_count' => (int) ($row['total_count'] ?? 0),
        'local_count' => (int) ($row['local_count'] ?? 0),
        'tanrc_count' => (int) ($row['tanrc_count'] ?? 0),
        'no_realm_count' => (int) ($row['no_realm_count'] ?? 0),
        'stale_count' => (int) ($row['stale_count'] ?? 0),
    ];
}

function online_radius_sessions(PDO $pdo, string $source = 'all', string $search = '', int $limit = 100): array
{
    $limit = max(10, min($limit, 300));
    $where = [
        'ra.acctstoptime IS NULL',
        radius_identity_source_condition($source, 'ra', true),
    ];
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $where[] = '(ra.username LIKE ? OR ra.callingstationid LIKE ? OR ra.calledstationid LIKE ? OR ra.nasipaddress LIKE ? OR ra.framedipaddress LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }

    $secondsSql = radius_acct_session_seconds_sql('ra');
    $octetsSql = radius_acct_octets_sql('ra');
    $labelSql = radius_identity_source_label_sql('ra');
    $stmt = $pdo->prepare("
        SELECT
            ra.radacctid,
            ra.acctsessionid,
            ra.username,
            ra.realm,
            ra.nasipaddress,
            ra.nasportid,
            ra.nasporttype,
            ra.acctstarttime,
            ra.acctupdatetime,
            ra.calledstationid,
            ra.callingstationid,
            ra.framedipaddress,
            ra.framedipv6address,
            COALESCE(ra.acctinputoctets, 0) AS input_octets,
            COALESCE(ra.acctoutputoctets, 0) AS output_octets,
            {$octetsSql} AS total_octets,
            {$secondsSql} AS live_seconds,
            COALESCE(TIMESTAMPDIFF(MINUTE, COALESCE(ra.acctupdatetime, ra.acctstarttime), NOW()), 0) AS idle_minutes,
            {$labelSql} AS source_label,
            (SELECT rp.id FROM radpostauth rp WHERE rp.username = ra.username ORDER BY rp.authdate DESC LIMIT 1) AS latest_auth_id,
            (SELECT rp.reply FROM radpostauth rp WHERE rp.username = ra.username ORDER BY rp.authdate DESC LIMIT 1) AS latest_reply
        FROM radacct ra
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(ra.acctupdatetime, ra.acctstarttime) DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function radius_usage_summary(PDO $pdo, string $source = 'all', int $days = 30): array
{
    $days = max(1, min($days, 365));
    $secondsSql = radius_acct_session_seconds_sql('ra');
    $octetsSql = radius_acct_octets_sql('ra');
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS session_count,
            COUNT(DISTINCT ra.username) AS user_count,
            COUNT(DISTINCT NULLIF(ra.callingstationid, '')) AS mac_count,
            COUNT(DISTINCT NULLIF(ra.nasipaddress, '')) AS nas_count,
            SUM({$secondsSql}) AS total_seconds,
            SUM({$octetsSql}) AS total_octets,
            SUM(CASE WHEN ra.acctstoptime IS NULL THEN 1 ELSE 0 END) AS online_count
        FROM radacct ra
        WHERE " . radius_identity_source_condition($source, 'ra', true) . "
          AND COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ");
    $row = $stmt->fetch() ?: [];
    return [
        'session_count' => (int) ($row['session_count'] ?? 0),
        'user_count' => (int) ($row['user_count'] ?? 0),
        'mac_count' => (int) ($row['mac_count'] ?? 0),
        'nas_count' => (int) ($row['nas_count'] ?? 0),
        'total_seconds' => (int) ($row['total_seconds'] ?? 0),
        'total_octets' => (int) ($row['total_octets'] ?? 0),
        'online_count' => (int) ($row['online_count'] ?? 0),
    ];
}

function radius_usage_top_users(PDO $pdo, string $source = 'all', int $days = 30, string $order = 'traffic', string $search = '', int $limit = 50): array
{
    $days = max(1, min($days, 365));
    $limit = max(10, min($limit, 300));
    $orderSql = match ($order) {
        'sessions' => 'session_count DESC, last_seen DESC',
        'time' => 'total_seconds DESC, last_seen DESC',
        'last_seen' => 'last_seen DESC',
        default => 'total_octets DESC, last_seen DESC',
    };
    $where = [
        radius_identity_source_condition($source, 'ra', true),
        "COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)",
    ];
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $where[] = '(ra.username LIKE ? OR ra.callingstationid LIKE ? OR ra.calledstationid LIKE ? OR ra.nasipaddress LIKE ? OR ra.framedipaddress LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }

    $secondsSql = radius_acct_session_seconds_sql('ra');
    $octetsSql = radius_acct_octets_sql('ra');
    $labelSql = radius_identity_source_label_sql('ra');
    $stmt = $pdo->prepare("
        SELECT
            ra.username,
            MAX({$labelSql}) AS source_label,
            COUNT(*) AS session_count,
            COUNT(DISTINCT NULLIF(ra.callingstationid, '')) AS mac_count,
            COUNT(DISTINCT NULLIF(ra.nasipaddress, '')) AS nas_count,
            COUNT(DISTINCT NULLIF(ra.calledstationid, '')) AS ap_count,
            COUNT(DISTINCT NULLIF(ra.framedipaddress, '')) AS framed_ip_count,
            SUM({$secondsSql}) AS total_seconds,
            SUM({$octetsSql}) AS total_octets,
            MIN(ra.acctstarttime) AS first_seen,
            MAX(COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime)) AS last_seen,
            MAX(CASE WHEN ra.acctstoptime IS NULL THEN 1 ELSE 0 END) AS online_now,
            (SELECT rp.id FROM radpostauth rp WHERE rp.username = ra.username ORDER BY rp.authdate DESC LIMIT 1) AS latest_auth_id
        FROM radacct ra
        WHERE " . implode(' AND ', $where) . "
        GROUP BY ra.username
        ORDER BY {$orderSql}
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function radius_usage_by_realm(PDO $pdo, string $source = 'all', int $days = 30, int $limit = 30): array
{
    $days = max(1, min($days, 365));
    $limit = max(5, min($limit, 100));
    $secondsSql = radius_acct_session_seconds_sql('ra');
    $octetsSql = radius_acct_octets_sql('ra');
    $stmt = $pdo->query("
        SELECT
            CASE
                WHEN ra.username LIKE '%@%' THEN SUBSTRING_INDEX(LOWER(ra.username), '@', -1)
                ELSE '(未帶 realm)'
            END AS realm,
            COUNT(DISTINCT ra.username) AS user_count,
            COUNT(*) AS session_count,
            SUM({$secondsSql}) AS total_seconds,
            SUM({$octetsSql}) AS total_octets,
            MAX(COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime)) AS last_seen
        FROM radacct ra
        WHERE " . radius_identity_source_condition($source, 'ra', true) . "
          AND COALESCE(ra.acctupdatetime, ra.acctstoptime, ra.acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY realm
        ORDER BY total_octets DESC, session_count DESC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function auth_attempts(PDO $pdo, string $type, int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    $where = auth_attempt_condition($type);
    $select = auth_attempt_select_sql($pdo);
    $sql = "
        SELECT {$select}
        FROM radpostauth rp
        WHERE {$where}
        ORDER BY rp.authdate DESC
        LIMIT {$limit}
    ";
    return $pdo->query($sql)->fetchAll();
}

function auth_attempt_by_id(PDO $pdo, int $id): ?array
{
    $select = auth_attempt_select_sql($pdo);
    $stmt = $pdo->prepare("
        SELECT {$select}
        FROM radpostauth rp
        WHERE rp.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function auth_attempts_for_username(PDO $pdo, string $username, int $limit = 25): array
{
    $limit = max(1, min($limit, 100));
    $select = auth_attempt_select_sql($pdo);
    $stmt = $pdo->prepare("
        SELECT {$select}
        FROM radpostauth rp
        WHERE rp.username = ?
        ORDER BY rp.authdate DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

function auth_attempt_stats(PDO $pdo, string $username, int $hours = 24): array
{
    $hours = max(1, min($hours, 720));
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN reply = 'Access-Accept' THEN 1 ELSE 0 END) AS accept_count,
            SUM(CASE WHEN reply <> 'Access-Accept' THEN 1 ELSE 0 END) AS reject_count,
            MIN(authdate) AS first_authdate,
            MAX(authdate) AS last_authdate
        FROM radpostauth
        WHERE username = ?
          AND authdate >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)
    ");
    $stmt->execute([$username]);
    $row = $stmt->fetch() ?: [];
    return [
        'total_count' => (int) ($row['total_count'] ?? 0),
        'accept_count' => (int) ($row['accept_count'] ?? 0),
        'reject_count' => (int) ($row['reject_count'] ?? 0),
        'first_authdate' => $row['first_authdate'] ?? null,
        'last_authdate' => $row['last_authdate'] ?? null,
    ];
}

function auth_accounting_summary(PDO $pdo, string $username, int $days = 30): array
{
    $days = max(1, min($days, 365));
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS session_count,
            COUNT(DISTINCT NULLIF(callingstationid, '')) AS mac_count,
            COUNT(DISTINCT NULLIF(nasipaddress, '')) AS nas_count,
            COUNT(DISTINCT NULLIF(calledstationid, '')) AS called_count,
            COUNT(DISTINCT NULLIF(framedipaddress, '')) AS framed_ip_count,
            MIN(acctstarttime) AS first_start,
            MAX(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) AS last_seen,
            SUM(COALESCE(acctsessiontime, 0)) AS total_session_seconds
        FROM radacct
        WHERE username = ?
          AND COALESCE(acctupdatetime, acctstoptime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ");
    $stmt->execute([$username]);
    $row = $stmt->fetch() ?: [];
    return [
        'session_count' => (int) ($row['session_count'] ?? 0),
        'mac_count' => (int) ($row['mac_count'] ?? 0),
        'nas_count' => (int) ($row['nas_count'] ?? 0),
        'called_count' => (int) ($row['called_count'] ?? 0),
        'framed_ip_count' => (int) ($row['framed_ip_count'] ?? 0),
        'first_start' => $row['first_start'] ?? null,
        'last_seen' => $row['last_seen'] ?? null,
        'total_session_seconds' => (int) ($row['total_session_seconds'] ?? 0),
    ];
}

function auth_accounting_sessions(PDO $pdo, string $username, int $limit = 25): array
{
    $limit = max(1, min($limit, 100));
    $stmt = $pdo->prepare("
        SELECT
            radacctid,
            acctsessionid,
            nasipaddress,
            nasportid,
            nasporttype,
            acctstarttime,
            acctupdatetime,
            acctstoptime,
            acctsessiontime,
            calledstationid,
            callingstationid,
            framedipaddress,
            framedipv6address,
            acctterminatecause,
            connectinfo_start,
            connectinfo_stop
        FROM radacct
        WHERE username = ?
        ORDER BY COALESCE(acctupdatetime, acctstoptime, acctstarttime) DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

function auth_distinct_accounting_values(PDO $pdo, string $username, string $column, int $days = 30, int $limit = 10): array
{
    $allowed = ['callingstationid', 'nasipaddress', 'calledstationid', 'framedipaddress'];
    if (!in_array($column, $allowed, true)) {
        throw new RuntimeException('Unsupported accounting column.');
    }
    $days = max(1, min($days, 365));
    $limit = max(1, min($limit, 50));
    $stmt = $pdo->prepare("
        SELECT
            {$column} AS value,
            COUNT(*) AS seen_count,
            MAX(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) AS last_seen
        FROM radacct
        WHERE username = ?
          AND {$column} IS NOT NULL
          AND {$column} <> ''
          AND COALESCE(acctupdatetime, acctstoptime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY {$column}
        ORDER BY last_seen DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

function auth_attempt_count(PDO $pdo, string $type, int $hours = 24): int
{
    $hours = max(1, min($hours, 720));
    $where = auth_attempt_condition($type);
    $sql = "
        SELECT COUNT(*)
        FROM radpostauth rp
        WHERE {$where}
          AND rp.authdate >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)
    ";
    return (int) $pdo->query($sql)->fetchColumn();
}

function roaming_active_blocks_for_identity(PDO $pdo, string $username, string $callingStationId = ''): array
{
    $realm = username_realm($username);
    $mac = normalize_calling_station_id($callingStationId);
    $parts = ['(block_type = ? AND block_value = ?)'];
    $params = ['username', strtolower($username)];
    if ($realm !== '') {
        $parts[] = '(block_type = ? AND block_value = ?)';
        $params[] = 'realm';
        $params[] = normalize_domain($realm);
    }
    if ($mac !== '' && preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac)) {
        $parts[] = '(block_type = ? AND block_value = ?)';
        $params[] = 'calling_station_id';
        $params[] = $mac;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM radius_roaming_blocklist
         WHERE enabled = 1
           AND (blocked_until IS NULL OR blocked_until > NOW())
           AND (' . implode(' OR ', $parts) . ')
         ORDER BY created_at DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function auth_risk_signals(array $attempt, array $stats24, array $stats7, array $acctSummary, array $activeBlocks): array
{
    $signals = [];
    if (($attempt['reply'] ?? '') !== 'Access-Accept') {
        $signals[] = ['level' => 'high', 'text' => '此筆認證為拒絕，請確認密碼錯誤、帳號停用或來源異常。'];
    }
    if ((int) $stats24['reject_count'] >= 10) {
        $signals[] = ['level' => 'high', 'text' => '24 小時內 Access-Reject 次數偏高，可能是密碼猜測或裝置重試。'];
    } elseif ((int) $stats24['reject_count'] >= 5) {
        $signals[] = ['level' => 'medium', 'text' => '24 小時內有多次 Access-Reject，建議確認使用者裝置設定。'];
    }
    if ((int) $stats7['reject_count'] >= 20) {
        $signals[] = ['level' => 'medium', 'text' => '近 7 天拒絕次數偏高，適合追蹤是否持續異常。'];
    }
    if ((int) $acctSummary['mac_count'] >= 4) {
        $signals[] = ['level' => 'medium', 'text' => '近 30 天出現多個使用者 MAC，可能是多裝置或帳密共用。'];
    }
    if ((int) $acctSummary['nas_count'] >= 4) {
        $signals[] = ['level' => 'low', 'text' => '近 30 天出現在多個 NAS/AP，若時間集中需進一步確認位置。'];
    }
    if ($activeBlocks) {
        $signals[] = ['level' => 'high', 'text' => '此帳號、realm 或 MAC 已命中啟用中的封鎖清單。'];
    }
    if (!$signals) {
        $signals[] = ['level' => 'ok', 'text' => '目前統計未達自動標記門檻，仍建議依 IP、MAC、AP 與時間比對判斷。'];
    }
    return $signals;
}

function roaming_block_type_labels(): array
{
    return [
        'username' => '完整外校帳號',
        'realm' => '外校 realm',
        'calling_station_id' => 'Calling-Station-Id / MAC',
    ];
}

function normalize_calling_station_id(string $value): string
{
    $value = strtolower(trim($value));
    $hex = preg_replace('/[^0-9a-f]/', '', $value) ?? '';
    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $value;
}

function normalize_roaming_block_value(string $type, string $value): string
{
    $value = trim($value);
    return match ($type) {
        'username' => normalize_radius_username($value),
        'realm' => normalize_domain($value),
        'calling_station_id' => normalize_calling_station_id($value),
        default => throw new RuntimeException('未知的封鎖類型。'),
    };
}

function validate_roaming_block_value(string $type, string $value): void
{
    if ($value === '') {
        throw new RuntimeException('封鎖值不可空白。');
    }
    if (strlen($value) > 190) {
        throw new RuntimeException('封鎖值過長。');
    }

    if ($type === 'username') {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('完整外校帳號必須是 username@realm 格式。');
        }
        if (is_ncut_username($value)) {
            throw new RuntimeException('本校帳號請在臨時帳號管理或 AD 管理處理，不應放入外校封鎖清單。');
        }
        return;
    }

    if ($type === 'realm') {
        if (!validate_domain($value)) {
            throw new RuntimeException('realm 必須是有效網域，例如 example.edu.tw。');
        }
        if (is_ncut_realm($value)) {
            throw new RuntimeException('本校 realm 不可放入外校封鎖清單。');
        }
        return;
    }

    if ($type === 'calling_station_id') {
        if (!preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $value)) {
            throw new RuntimeException('Calling-Station-Id 請輸入 12 碼 MAC，例如 aa:bb:cc:dd:ee:ff。');
        }
        return;
    }

    throw new RuntimeException('未知的封鎖類型。');
}

function roaming_blocklist(PDO $pdo, bool $includeDisabled = true): array
{
    $where = $includeDisabled ? '1=1' : 'enabled = 1 AND (blocked_until IS NULL OR blocked_until > NOW())';
    $stmt = $pdo->query(
        "SELECT *,
            CASE
                WHEN enabled = 0 THEN 'disabled'
                WHEN blocked_until IS NOT NULL AND blocked_until <= NOW() THEN 'expired'
                ELSE 'active'
            END AS runtime_status
         FROM radius_roaming_blocklist
         WHERE {$where}
         ORDER BY enabled DESC, runtime_status ASC, created_at DESC
         LIMIT 200"
    );
    return $stmt->fetchAll();
}

function roaming_active_block_count(PDO $pdo): int
{
    $stmt = $pdo->query(
        'SELECT COUNT(*)
         FROM radius_roaming_blocklist
         WHERE enabled = 1
           AND (blocked_until IS NULL OR blocked_until > NOW())'
    );
    return (int) $stmt->fetchColumn();
}

function roaming_recent_summary(PDO $pdo, int $hours = 24, int $limit = 50): array
{
    $hours = max(1, min($hours, 720));
    $limit = max(1, min($limit, 100));
    $where = auth_attempt_condition('tanrc');
    $sql = "
        SELECT
            rp.username,
            SUBSTRING_INDEX(LOWER(rp.username), '@', -1) AS realm,
            SUM(CASE WHEN rp.reply = 'Access-Accept' THEN 1 ELSE 0 END) AS accept_count,
            SUM(CASE WHEN rp.reply <> 'Access-Accept' THEN 1 ELSE 0 END) AS reject_count,
            COUNT(*) AS total_count,
            MAX(rp.authdate) AS last_authdate,
            (
                SELECT COUNT(*)
                FROM radius_roaming_blocklist rb
                WHERE rb.enabled = 1
                  AND (rb.blocked_until IS NULL OR rb.blocked_until > NOW())
                  AND (
                    (rb.block_type = 'username' AND rb.block_value = LOWER(rp.username))
                    OR (rb.block_type = 'realm' AND rb.block_value = SUBSTRING_INDEX(LOWER(rp.username), '@', -1))
                  )
            ) AS active_block_count
        FROM radpostauth rp
        WHERE {$where}
          AND rp.authdate >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)
        GROUP BY rp.username
        ORDER BY last_authdate DESC
        LIMIT {$limit}
    ";
    return $pdo->query($sql)->fetchAll();
}

function roaming_block_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM radius_roaming_blocklist WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function requested_radius_username_exists(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM guest_account_requests
         WHERE status IN ("pending", "approved", "disabled")
           AND (requested_username = ? OR radius_username = ?)'
    );
    $stmt->execute([$username, $username]);
    return (int) $stmt->fetchColumn() > 0;
}

function generate_guest_username(PDO $pdo): string
{
    for ($i = 0; $i < 50; $i++) {
        $candidate = 'guest' . date('ymd') . strtolower(bin2hex(random_bytes(2))) . '@ncut.edu.tw';
        $stmt = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM radcheck WHERE username = ?) +
                (SELECT COUNT(*) FROM guest_account_requests WHERE radius_username = ?)'
        );
        $stmt->execute([$candidate, $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }
    throw new RuntimeException('無法產生可用的臨時帳號，請手動指定帳號。');
}

function nav_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

function nav_active(array $paths): bool
{
    $current = nav_path();
    return in_array($current, $paths, true);
}

function nav_link(string $href, string $label, array $activePaths = []): void
{
    $paths = $activePaths ?: [$href];
    $active = nav_active($paths) ? ' class="active"' : '';
    echo '<a' . $active . ' href="' . e($href) . '">' . e($label) . '</a>';
}

function render_header(string $title, bool $isAdmin = false): void
{
    $admin = admin_user();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,500,0,0&display=swap">
    <link rel="stylesheet" href="/assets/styles.css?v=20260719-ssl-manager-v2">
</head>
<body>
<header class="topbar">
    <div>
        <div class="brand">NCUT eduroam</div>
        <div class="subtitle"><?= e($isAdmin ? '管理者後台' : '臨時帳號申請') ?></div>
    </div>
    <nav>
        <?php if (google_applicant()): ?>
            <?php nav_link('/', '申請帳號'); ?>
        <?php endif; ?>
        <?php if ($admin): ?>
            <?php nav_link('/admin-dashboard.php', 'Dashboard'); ?>
            <?php nav_link('/admin.php', '帳號管理'); ?>
            <details class="nav-menu <?= nav_active(['/admin-auth-logs.php', '/admin-auth-log-detail.php', '/admin-online-users.php', '/admin-usage-analytics.php', '/admin-roaming-blocklist.php']) ? 'active' : '' ?>">
                <summary>認證與安全</summary>
                <div class="nav-menu-panel">
                    <?php nav_link('/admin-auth-logs.php', '認證紀錄', ['/admin-auth-logs.php', '/admin-auth-log-detail.php']); ?>
                    <?php nav_link('/admin-online-users.php', '線上帳號'); ?>
                    <?php nav_link('/admin-usage-analytics.php', '用量分析'); ?>
                    <?php nav_link('/admin-roaming-blocklist.php', '外校封鎖管理'); ?>
                </div>
            </details>
            <details class="nav-menu <?= nav_active(['/admin-radius-proxy.php', '/admin-settings.php', '/admin-ssl-certificate.php']) ? 'active' : '' ?>">
                <summary>系統管理</summary>
                <div class="nav-menu-panel">
                    <?php nav_link('/admin-radius-proxy.php', 'RADIUS Proxy'); ?>
                    <?php nav_link('/admin-settings.php', '系統設定'); ?>
                    <?php nav_link('/admin-ssl-certificate.php', 'SSL 憑證'); ?>
                </div>
            </details>
            <form method="post" action="/admin.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="logout">
                <button class="link-button" type="submit">登出 <?= e($admin['username']) ?></button>
            </form>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php foreach (take_flashes() as $item): ?>
    <div class="flash <?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="footer">
    國立勤益科技大學 eduroam 臨時帳號服務
</footer>
</body>
</html>
    <?php
}
