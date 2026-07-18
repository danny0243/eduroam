<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

function create_admin(PDO $pdo, array $admin): void
{
    $email = strtolower(trim((string) ($_POST['new_admin_email'] ?? '')));
    upsert_google_admin($pdo, $email, $email);
    audit($pdo, (int) $admin['id'], 'create_admin', null, 'granted google admin ' . $email);
    flash('success', "已新增 Google 管理員 {$email}。");
}

function add_allowed_domain(PDO $pdo, array $admin): void
{
    $domain = normalize_domain((string) ($_POST['domain'] ?? ''));
    if (!validate_domain($domain)) {
        throw new RuntimeException('請輸入有效網域，例如 gmail.com。');
    }
    $maxMonths = max(1, min(120, (int) ($_POST['max_months'] ?? 12)));
    $stmt = $pdo->prepare(
        'INSERT INTO guest_allowed_domains (domain, enabled, created_by, created_at, updated_at, max_months)
         VALUES (?, 1, ?, NOW(), NOW(), ?)
         ON DUPLICATE KEY UPDATE enabled = 1, updated_at = NOW(), max_months = VALUES(max_months)'
    );
    $stmt->execute([$domain, $admin['username'], $maxMonths]);
    audit($pdo, (int) $admin['id'], 'add_allowed_domain', null, 'allowed domain ' . $domain);
    flash('success', "已加入允許申請網域 {$domain}（最長 {$maxMonths} 個月）。");
}

function update_domain_max_months(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $maxMonths = max(1, min(120, (int) ($_POST['max_months'] ?? 12)));
    $stmt = $pdo->prepare('SELECT domain FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetchColumn();
    if (!$domain) {
        throw new RuntimeException('找不到指定網域。');
    }
    $stmt = $pdo->prepare('UPDATE guest_allowed_domains SET max_months = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$maxMonths, $id]);
    audit($pdo, (int) $admin['id'], 'update_domain_max_months', null, "set max_months={$maxMonths} for domain {$domain}");
    flash('success', "已更新 {$domain} 最長申請期限為 {$maxMonths} 個月。");
}

function remove_allowed_domain(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT domain FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetchColumn();
    if (!$domain) {
        throw new RuntimeException('找不到指定網域。');
    }
    $stmt = $pdo->prepare('UPDATE guest_allowed_domains SET enabled = 0, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'remove_allowed_domain', null, 'removed allowed domain ' . $domain);
    flash('success', "已停用允許申請網域 {$domain}。");
}

function enable_allowed_domain(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT domain FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetchColumn();
    if (!$domain) {
        throw new RuntimeException('找不到指定網域。');
    }
    $stmt = $pdo->prepare('UPDATE guest_allowed_domains SET enabled = 1, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'enable_allowed_domain', null, 'enabled allowed domain ' . $domain);
    flash('success', "已啟用允許申請網域 {$domain}。");
}

function delete_allowed_domain(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT domain FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetchColumn();
    if (!$domain) {
        throw new RuntimeException('找不到指定網域。');
    }
    $stmt = $pdo->prepare('DELETE FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'delete_allowed_domain', null, 'deleted allowed domain ' . $domain);
    flash('success', "已刪除允許申請網域 {$domain}。");
}

function ad_settings_from_post(PDO $pdo): array
{
    $current = ad_settings($pdo);
    $mode = (string) ($_POST['ad_mode'] ?? 'winbind');
    if (!in_array($mode, ['ldap', 'winbind'], true)) {
        throw new RuntimeException('AD 驗證模式不正確。');
    }
    $port = trim((string) ($_POST['ad_port'] ?? '389'));
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('AD Server Port 必須介於 1 到 65535。');
    }

    $settings = [
        'enabled' => !empty($_POST['ad_enabled']),
        'mode' => $mode,
        'domain' => strtolower(trim((string) ($_POST['ad_domain'] ?? ''))),
        'netbios_domain' => strtoupper(trim((string) ($_POST['ad_netbios_domain'] ?? ''))),
        'hosts' => trim((string) ($_POST['ad_hosts'] ?? '')),
        'port' => $port,
        'use_ssl' => !empty($_POST['ad_use_ssl']),
        'start_tls' => !empty($_POST['ad_start_tls']),
        'verify_cert' => !empty($_POST['ad_verify_cert']),
        'base_dn' => trim((string) ($_POST['ad_base_dn'] ?? '')),
        'bind_dn' => trim((string) ($_POST['ad_bind_dn'] ?? '')),
        'bind_password' => (string) ($_POST['ad_bind_password'] ?? ''),
        'user_attribute' => trim((string) ($_POST['ad_user_attribute'] ?? 'sAMAccountName')),
        'upn_suffix' => strtolower(trim((string) ($_POST['ad_upn_suffix'] ?? ''))),
        'ntlm_auth_path' => trim((string) ($_POST['ad_ntlm_auth_path'] ?? '/usr/bin/ntlm_auth')),
    ];
    if ($settings['bind_password'] === '') {
        $settings['bind_password'] = $current['bind_password'];
    }
    if ($settings['enabled'] && ($settings['domain'] === '' || $settings['hosts'] === '' || $settings['base_dn'] === '')) {
        throw new RuntimeException('啟用 AD 串接時，請填寫 AD Domain、Domain Controller 與 Base DN。');
    }
    if ($settings['enabled'] && $settings['mode'] === 'winbind' && $settings['netbios_domain'] === '') {
        throw new RuntimeException('啟用 Winbind / ntlm_auth 時，請填寫 NetBIOS Domain。');
    }
    if ($settings['use_ssl'] && $settings['start_tls']) {
        throw new RuntimeException('LDAPS 與 StartTLS 擇一啟用即可。');
    }
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $settings['user_attribute'])) {
        throw new RuntimeException('AD 帳號屬性格式不正確。');
    }
    return $settings;
}

function save_ad_settings(PDO $pdo, array $admin): void
{
    $settings = ad_settings_from_post($pdo);
    persist_ad_settings($pdo, $settings);
    audit($pdo, (int) $admin['id'], 'save_ad_settings', null, 'updated AD integration settings');
    flash('success', 'AD 串接設定已儲存。');
}

function persist_ad_settings(PDO $pdo, array $settings): void
{
    setting_set($pdo, 'ad_enabled', $settings['enabled'] ? '1' : '0');
    setting_set($pdo, 'ad_mode', $settings['mode']);
    setting_set($pdo, 'ad_domain', $settings['domain']);
    setting_set($pdo, 'ad_netbios_domain', $settings['netbios_domain']);
    setting_set($pdo, 'ad_hosts', $settings['hosts']);
    setting_set($pdo, 'ad_port', $settings['port']);
    setting_set($pdo, 'ad_use_ssl', $settings['use_ssl'] ? '1' : '0');
    setting_set($pdo, 'ad_start_tls', $settings['start_tls'] ? '1' : '0');
    setting_set($pdo, 'ad_verify_cert', $settings['verify_cert'] ? '1' : '0');
    setting_set($pdo, 'ad_base_dn', $settings['base_dn']);
    setting_set($pdo, 'ad_bind_dn', $settings['bind_dn']);
    if ((string) ($_POST['ad_bind_password'] ?? '') !== '') {
        setting_set($pdo, 'ad_bind_password', $settings['bind_password'], true);
    }
    setting_set($pdo, 'ad_user_attribute', $settings['user_attribute']);
    setting_set($pdo, 'ad_upn_suffix', $settings['upn_suffix']);
    setting_set($pdo, 'ad_ntlm_auth_path', $settings['ntlm_auth_path']);
}

function ldap_hosts(string $hosts): array
{
    $items = preg_split('/[,;\s]+/', $hosts) ?: [];
    return array_values(array_filter(array_map('trim', $items), static fn($item) => $item !== ''));
}

function test_ad_connection(PDO $pdo, array $admin): void
{
    $settings = ad_settings_from_post($pdo);
    $testUsername = trim((string) ($_POST['ad_test_username'] ?? ''));
    if (!extension_loaded('ldap')) {
        throw new RuntimeException('伺服器尚未安裝 PHP LDAP extension，無法執行 AD 連線測試。');
    }
    if ($settings['hosts'] === '' || $settings['base_dn'] === '') {
        throw new RuntimeException('請先填寫 Domain Controller 與 Base DN。');
    }
    if ($settings['use_ssl'] && $settings['start_tls']) {
        throw new RuntimeException('LDAPS 與 StartTLS 擇一啟用即可。');
    }
    if (!$settings['verify_cert'] && defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    }

    $lastError = '';
    foreach (ldap_hosts($settings['hosts']) as $host) {
        $scheme = $settings['use_ssl'] ? 'ldaps' : 'ldap';
        $uri = $scheme . '://' . $host . ':' . $settings['port'];
        $conn = @ldap_connect($uri);
        if (!$conn) {
            $lastError = '無法建立 LDAP 連線：' . $uri;
            continue;
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 6);
        }
        if ($settings['start_tls'] && !@ldap_start_tls($conn)) {
            $lastError = $uri . ' StartTLS 失敗：' . ldap_error($conn);
            @ldap_unbind($conn);
            continue;
        }
        $bindOk = $settings['bind_dn'] !== ''
            ? @ldap_bind($conn, $settings['bind_dn'], $settings['bind_password'])
            : @ldap_bind($conn);
        if (!$bindOk) {
            $lastError = $uri . ' Bind 失敗：' . ldap_error($conn);
            @ldap_unbind($conn);
            continue;
        }

        if ($testUsername !== '') {
            $escapedUser = ldap_escape($testUsername, '', LDAP_ESCAPE_FILTER);
            $filters = ['(' . $settings['user_attribute'] . '=' . $escapedUser . ')'];
            if (str_contains($testUsername, '@')) {
                $filters[] = '(userPrincipalName=' . $escapedUser . ')';
            } elseif ($settings['upn_suffix'] !== '') {
                $filters[] = '(userPrincipalName=' . $escapedUser . '@' . ldap_escape($settings['upn_suffix'], '', LDAP_ESCAPE_FILTER) . ')';
            }
            $filter = count($filters) > 1 ? '(|' . implode('', $filters) . ')' : $filters[0];
            $search = @ldap_search($conn, $settings['base_dn'], $filter, ['dn'], 0, 1);
            if (!$search || ldap_count_entries($conn, $search) < 1) {
                $lastError = $uri . ' 連線成功，但找不到測試帳號。';
                @ldap_unbind($conn);
                continue;
            }
        } else {
            $search = @ldap_read($conn, $settings['base_dn'], '(objectClass=*)', ['dn'], 0, 1);
            if (!$search) {
                $lastError = $uri . ' Bind 成功，但 Base DN 不可讀取：' . ldap_error($conn);
                @ldap_unbind($conn);
                continue;
            }
        }

        @ldap_unbind($conn);
        audit($pdo, (int) $admin['id'], 'test_ad_connection', null, 'tested AD connection to ' . $host);
        flash('success', 'AD 連線測試成功：' . $host . ($testUsername !== '' ? '，已找到測試帳號。' : '，Base DN 可讀取。'));
        return;
    }

    throw new RuntimeException($lastError !== '' ? $lastError : 'AD 連線測試失敗。');
}

function ad_apply_summary(string $output): string
{
    $lines = preg_split('/\R+/', trim($output)) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
    if (!$lines) {
        return '系統層套用完成。';
    }
    return implode(' / ', array_slice($lines, 0, 8));
}

function ad_apply_failure_message(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return 'AD 系統層套用失敗：helper 沒有回傳錯誤訊息。';
    }
    if (stripos($output, 'sudo') !== false && preg_match('/password|required|not allowed|no tty/i', $output)) {
        return 'AD 系統層套用失敗：sudo 權限尚未設定完成，請確認 /etc/sudoers.d/ncut-eduroam-ad。';
    }
    $safe = preg_replace('/(password|passwd|secret)[=: ]+\S+/i', '$1=[redacted]', $output) ?? $output;
    return 'AD 系統層套用失敗：' . mb_substr($safe, 0, 1000);
}

function run_ad_apply_helper(array $payload): string
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('AD 系統層套用失敗：PHP proc_open 未啟用。');
    }
    $helper = '/var/www/eduroam-portal/bin/apply-ad-domain-join.php';
    if (!is_readable($helper)) {
        throw new RuntimeException('AD 系統層套用失敗：找不到 apply-ad-domain-join.php。');
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new RuntimeException('AD 系統層套用失敗：套用資料無法轉成 JSON。');
    }

    $cmd = '/usr/bin/sudo -n /usr/bin/php ' . escapeshellarg($helper);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('AD 系統層套用失敗：無法啟動 helper。');
    }
    fwrite($pipes[0], $payloadJson);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $output = trim(($stdout ?: '') . "\n" . ($stderr ?: ''));
    if ($exitCode !== 0) {
        throw new RuntimeException(ad_apply_failure_message($output));
    }
    return $output;
}

function apply_ad_settings(PDO $pdo, array $admin): void
{
    $settings = ad_settings_from_post($pdo);
    if (!$settings['enabled']) {
        throw new RuntimeException('請先勾選啟用 AD 串接設定，再執行套用。');
    }
    if ($settings['mode'] !== 'winbind') {
        throw new RuntimeException('eduroam PEAP/MSCHAPV2 要直接驗證 AD 密碼時，請選擇 Winbind / ntlm_auth 模式。');
    }

    persist_ad_settings($pdo, $settings);
    $output = run_ad_apply_helper([
        'join_username' => trim((string) ($_POST['ad_join_username'] ?? '')),
        'join_password' => (string) ($_POST['ad_join_password'] ?? ''),
        'test_username' => trim((string) ($_POST['ad_test_username'] ?? '')),
        'test_password' => (string) ($_POST['ad_test_password'] ?? ''),
    ]);
    audit($pdo, (int) $admin['id'], 'apply_ad_settings', null, 'applied AD system integration');
    flash('success', 'AD 設定已儲存並套用。' . ad_apply_summary($output));
}

function save_mail_settings(PDO $pdo, array $admin): void
{
    $current = mail_settings($pdo);
    $smtpEnabled = !empty($_POST['smtp_enabled']);
    $smtpUser = strtolower(trim((string) ($_POST['smtp_user'] ?? '')));
    $smtpPass = preg_replace('/\s+/', '', (string) ($_POST['smtp_pass'] ?? '')) ?? '';
    $fromEmail = strtolower(trim((string) ($_POST['smtp_from_email'] ?? '')));

    if ($smtpEnabled && $smtpUser === '') {
        throw new RuntimeException('啟用 Email 通知時，請填寫 Gmail 帳號。');
    }
    if ($smtpUser !== '' && !filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('請輸入有效的 Gmail 帳號。');
    }
    if ($smtpEnabled && $smtpPass === '' && $current['pass'] === '') {
        throw new RuntimeException('啟用 Email 通知時，請填寫 Gmail App Password。');
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('請輸入有效的寄件者 Email。');
    }

    setting_set($pdo, 'smtp_enabled', $smtpEnabled ? '1' : '0');
    setting_set($pdo, 'smtp_host', 'smtp.gmail.com');
    setting_set($pdo, 'smtp_port', '587');
    setting_set($pdo, 'smtp_secure', '0');
    setting_set($pdo, 'smtp_user', $smtpUser);
    if ($smtpPass !== '') {
        setting_set($pdo, 'smtp_pass', $smtpPass, true);
    }
    setting_set($pdo, 'smtp_from_email', $fromEmail);
    setting_set($pdo, 'smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? 'NCUT eduroam')));
    setting_set($pdo, 'notify_admins', trim((string) ($_POST['notify_admins'] ?? '')));
    audit($pdo, (int) $admin['id'], 'save_mail_settings', null, 'updated gmail smtp notification settings');
    flash('success', '通知設定已儲存。');
}

function send_test_mail(PDO $pdo, array $admin): void
{
    $to = strtolower(trim((string) ($_POST['test_email'] ?? '')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('請輸入有效的測試收件人 Email。');
    }
    send_portal_mail($pdo, [
        'to' => [$to],
        'subject' => '[NCUT eduroam] 通知測試',
        'text' => text_mail('通知測試', [
            '測試時間' => date('Y-m-d H:i:s'),
            '測試者' => $admin['username'],
        ], '這是一封 Nodemailer + Gmail SMTP 測試信。'),
        'html' => html_mail('通知測試', [
            '測試時間' => date('Y-m-d H:i:s'),
            '測試者' => $admin['username'],
        ], '這是一封 Nodemailer + Gmail SMTP 測試信。'),
    ]);
    audit($pdo, (int) $admin['id'], 'send_test_mail', null, 'sent notification test to ' . $to);
    flash('success', "測試信已送出到 {$to}。");
}

function sv_settings_from_post(?array $existingRow, bool $forceValidate = false): array
{
    $settings = [
        'enabled'             => !empty($_POST['sv_enabled']),
        'host'                => trim((string) ($_POST['sv_host']                ?? '')),
        'port'                => trim((string) ($_POST['sv_port']                ?? '3306')),
        'database'            => trim((string) ($_POST['sv_database']            ?? '')),
        'charset'             => trim((string) ($_POST['sv_charset']             ?? 'utf8mb4')),
        'username'            => trim((string) ($_POST['sv_username']            ?? '')),
        'password'            => (string)      ($_POST['sv_password']            ?? ''),
        'view_name'           => trim((string) ($_POST['sv_view_name']           ?? '')),
        'status_column'       => trim((string) ($_POST['sv_status_column']       ?? '')),
        'status_active_value' => trim((string) ($_POST['sv_status_active_value'] ?? '')),
        'columns'             => [],
    ];
    if ($settings['password'] === '' && $existingRow !== null) {
        $settings['password'] = decrypt_secret((string) $existingRow['dbpass']);
    }
    foreach (sql_view_field_settings() as $key => $meta) {
        $settings['columns'][$key] = trim((string) ($_POST['sv_col_' . $key] ?? $meta['default']));
    }
    sql_view_validate_settings($settings, $forceValidate || $settings['enabled']);
    return $settings;
}

function save_sql_view(PDO $pdo, array $admin): void
{
    $id   = (int) ($_POST['sv_id']   ?? 0);
    $name = trim((string) ($_POST['sv_name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('請填寫 SQL View 來源名稱。');
    }
    $existingRow = $id > 0 ? sql_view_by_id($pdo, $id) : null;
    if ($id > 0 && $existingRow === null) {
        throw new RuntimeException('找不到指定的 SQL View 設定。');
    }
    $s = sv_settings_from_post($existingRow);
    $cols = [
        $name, $s['enabled'] ? 1 : 0, $s['host'], (int) $s['port'],
        $s['database'], $s['charset'], $s['username'], encrypt_secret($s['password']),
        $s['view_name'], $s['status_column'], $s['status_active_value'],
        $s['columns']['applicant_name'], $s['columns']['applicant_email'],
        $s['columns']['radius_username'], $s['columns']['radius_password'],
        $s['columns']['organization'],    $s['columns']['applicant_phone'],
        $s['columns']['starts_at'],       $s['columns']['expires_at'],
        $s['columns']['permanent'],       $s['columns']['reason'],
    ];
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE guest_sql_views SET name=?,enabled=?,host=?,port=?,dbname=?,charset=?,dbuser=?,dbpass=?,
             view_name=?,status_column=?,status_active_value=?,
             col_applicant_name=?,col_applicant_email=?,col_radius_username=?,col_radius_password=?,
             col_organization=?,col_applicant_phone=?,col_starts_at=?,col_expires_at=?,col_permanent=?,col_reason=?,
             updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([...$cols, $id]);
        audit($pdo, (int) $admin['id'], 'save_sql_view', null, "updated SQL View #{$id} ({$name})");
        flash('success', "SQL View「{$name}」設定已儲存。");
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO guest_sql_views
             (name,enabled,host,port,dbname,charset,dbuser,dbpass,
              view_name,status_column,status_active_value,
              col_applicant_name,col_applicant_email,col_radius_username,col_radius_password,
              col_organization,col_applicant_phone,col_starts_at,col_expires_at,col_permanent,col_reason,
              created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute($cols);
        audit($pdo, (int) $admin['id'], 'add_sql_view', null, "added SQL View ({$name})");
        flash('success', "已新增 SQL View「{$name}」。");
    }
}

function test_sv_connection(PDO $pdo, array $admin): void
{
    $id          = (int) ($_POST['sv_id'] ?? 0);
    $existingRow = $id > 0 ? sql_view_by_id($pdo, $id) : null;
    $s           = sv_settings_from_post($existingRow, true);
    $rows        = sql_view_fetch_rows($s, 5);
    $summary     = '連線測試成功，讀取 ' . count($rows) . ' 筆範例資料。';
    if ($rows) {
        $first    = $rows[0];
        $summary .= ' 第一筆：' . ((string) ($first['radius_username'] ?? '-'))
                  . '，Email：' . ((string) ($first['applicant_email'] ?? '-')) . '。';
    }
    audit($pdo, (int) $admin['id'], 'test_sv_connection', null, 'tested SQL View connection');
    flash('success', $summary);
}

function fetch_sv_columns(PDO $pdo, array $admin): void
{
    $id          = (int) ($_POST['sv_id'] ?? 0);
    $existingRow = $id > 0 ? sql_view_by_id($pdo, $id) : null;
    $password    = (string) ($_POST['sv_password'] ?? '');
    if ($password === '' && $existingRow !== null) {
        $password = decrypt_secret((string) $existingRow['dbpass']);
    }
    $settings = [
        'host'      => trim((string) ($_POST['sv_host']      ?? '')),
        'port'      => trim((string) ($_POST['sv_port']      ?? '3306')),
        'database'  => trim((string) ($_POST['sv_database']  ?? '')),
        'charset'   => trim((string) ($_POST['sv_charset']   ?? 'utf8mb4')),
        'username'  => trim((string) ($_POST['sv_username']  ?? '')),
        'password'  => $password,
        'view_name' => trim((string) ($_POST['sv_view_name'] ?? '')),
    ];
    $columns = sql_view_get_columns($settings);
    audit($pdo, (int) $admin['id'], 'fetch_sv_columns', null, 'fetched SQL View column list');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'columns' => $columns]);
    exit;
}

function toggle_sql_view(PDO $pdo, array $admin): void
{
    $id  = (int) ($_POST['sv_id'] ?? 0);
    $row = sql_view_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到指定的 SQL View 設定。');
    }
    $newEnabled = ((int) $row['enabled'] === 1) ? 0 : 1;
    $pdo->prepare('UPDATE guest_sql_views SET enabled=?,updated_at=NOW() WHERE id=?')->execute([$newEnabled, $id]);
    audit($pdo, (int) $admin['id'], 'toggle_sql_view', null, ($newEnabled ? 'enabled' : 'disabled') . " SQL View #{$id}");
    flash('success', 'SQL View「' . $row['name'] . '」已' . ($newEnabled ? '啟用' : '停用') . '。');
}

function delete_sql_view(PDO $pdo, array $admin): void
{
    $id  = (int) ($_POST['sv_id'] ?? 0);
    $row = sql_view_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到指定的 SQL View 設定。');
    }
    $pdo->prepare('DELETE FROM guest_sql_views WHERE id=?')->execute([$id]);
    audit($pdo, (int) $admin['id'], 'delete_sql_view', null, "deleted SQL View #{$id} ({$row['name']})");
    flash('success', 'SQL View「' . $row['name'] . '」已刪除。');
}

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'create_admin' => create_admin($pdo, $admin),
            'add_allowed_domain' => add_allowed_domain($pdo, $admin),
            'remove_allowed_domain' => remove_allowed_domain($pdo, $admin),
            'enable_allowed_domain' => enable_allowed_domain($pdo, $admin),
            'delete_allowed_domain' => delete_allowed_domain($pdo, $admin),
            'update_domain_max_months' => update_domain_max_months($pdo, $admin),
            'save_ad_settings' => save_ad_settings($pdo, $admin),
            'apply_ad_settings' => apply_ad_settings($pdo, $admin),
            'test_ad_connection' => test_ad_connection($pdo, $admin),
            'save_mail_settings' => save_mail_settings($pdo, $admin),
            'send_test_mail' => send_test_mail($pdo, $admin),
            'save_sql_view' => save_sql_view($pdo, $admin),
            'test_sv_connection' => test_sv_connection($pdo, $admin),
            'fetch_sv_columns' => fetch_sv_columns($pdo, $admin),
            'toggle_sql_view' => toggle_sql_view($pdo, $admin),
            'delete_sql_view' => delete_sql_view($pdo, $admin),
            default => throw new RuntimeException('未知的管理動作。'),
        };
        redirect('/admin-settings.php');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin-settings.php');
    }
}

$admins = $pdo->query('SELECT username, display_name, enabled, last_login_at, created_at FROM guest_account_admins WHERE enabled = 1 ORDER BY username ASC')->fetchAll();
$domains = allowed_domains($pdo);
$mail = mail_settings($pdo);
$ad = ad_settings($pdo);
$sqlViews = sql_view_list($pdo);
$editingSvId = isset($_GET['sv']) ? (int) $_GET['sv'] : 0;
$editingSv = null;
if ($editingSvId > 0) {
    foreach ($sqlViews as $sv) {
        if ((int) $sv['id'] === $editingSvId) {
            $editingSv = $sv;
            break;
        }
    }
}
$svColLabels = [
    'applicant_name'  => '姓名欄位',
    'applicant_email' => 'Email 欄位',
    'radius_username' => 'RADIUS 帳號欄位',
    'radius_password' => '密碼欄位',
    'organization'    => '單位欄位',
    'applicant_phone' => '電話欄位',
    'starts_at'       => '啟用時間欄位',
    'expires_at'      => '使用迄止欄位',
    'permanent'       => '永久有效欄位',
    'reason'          => '用途 / 備註欄位',
];
$mailRuntimeWarnings = mail_runtime_warnings();

render_header('系統設定 - ' . APP_NAME, true);
?>
<style>
/* ── Panel icon ──────────────────────────────────────────── */
.ps-icon {
    display: inline-block;
    vertical-align: -3px;
    margin-right: 8px;
    flex-shrink: 0;
    color: #526173;
}

/* ── Form section separator ──────────────────────────────── */
.fsep {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 4px;
    padding-top: 16px;
    border-top: 1px solid #e5ebf2;
}
.fsep:first-child { border-top: none; padding-top: 0; }
.fsep-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #8896a9;
    white-space: nowrap;
}
.fsep-note {
    font-size: 12px;
    color: #8896a9;
}
.fsep::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #e5ebf2;
}

/* ── AD apply zone ───────────────────────────────────────── */
.apply-zone {
    grid-column: 1 / -1;
    background: #fffbf0;
    border: 1px solid #f1d59a;
    border-radius: 8px;
    padding: 16px 18px 18px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.apply-zone-head {
    grid-column: 1 / -1;
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 2px;
}
.apply-zone-title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: #92400e;
}
.apply-zone-note {
    font-size: 12px;
    color: #b45309;
}
.apply-zone .actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
}
.btn-apply {
    background: #b45309;
    color: #fff;
}
.btn-apply:hover:not(:disabled) {
    background: #92400e;
}

/* ── SQL View: connection → fetch → mapping flow ─────────── */
.sv-fetch-zone {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: #edf4ff;
    border: 1px solid #bdd6f7;
    border-radius: 8px;
    flex-wrap: wrap;
}
.sv-fetch-zone button { flex-shrink: 0; }
#sv-fetch-status {
    font-size: 13px;
    font-weight: 600;
}
#sv-fetch-status.ok  { color: #0b6b38; }
#sv-fetch-status.err { color: #b91c1c; }
.sv-mapping-grid {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
@media (max-width: 760px) {
    .sv-mapping-grid { grid-template-columns: 1fr; }
    .apply-zone { grid-template-columns: 1fr; }
}

/* ── Domain status badges ────────────────────────────────── */
.badge-active {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 12px;
    font-weight: 700;
    color: #0b6b38;
    background: #e7f7ee;
}
.badge-inactive {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 12px;
    font-weight: 700;
    color: #7c3d00;
    background: #fff1df;
}

/* ── Settings overview ───────────────────────────────────── */
.settings-hub {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-top: 22px;
}
.settings-hub-card {
    background: #ffffff;
    border: 1px solid #dbe2ea;
    border-radius: 8px;
    padding: 16px;
}
.settings-hub-card h2 {
    margin: 0 0 6px;
    font-size: 17px;
}
.settings-hub-card p {
    margin: 0 0 12px;
    color: #6b7787;
    font-size: 14px;
}
.settings-hub-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.settings-hub-links a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #c9d3df;
    border-radius: 6px;
    color: #1d2635;
    font-size: 14px;
    font-weight: 700;
    padding: 7px 10px;
}
.settings-hub-links a:hover {
    background: #edf4fb;
    border-color: #9fc4ed;
    color: #075aa5;
}
.settings-count {
    align-items: center;
    background: #e8edf3;
    border-radius: 999px;
    display: inline-flex;
    font-size: 12px;
    justify-content: center;
    min-width: 24px;
    padding: 1px 7px;
}
.settings-section-label {
    align-items: baseline;
    display: flex;
    gap: 10px;
    margin: 28px 0 -8px;
}
.settings-section-label strong {
    color: #1d2635;
    font-size: 15px;
}
.settings-section-label span {
    color: #6b7787;
    font-size: 13px;
}
@media (max-width: 900px) {
    .settings-hub { grid-template-columns: 1fr; }
}
</style>

<section class="dashboard-head">
    <div>
        <h1>系統設定</h1>
        <p>依用途分成權限、通知、認證資料來源三類；先選分類，再展開需要調整的設定。</p>
    </div>
</section>

<?php
$ic_admin = '<svg class="ps-icon" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6.5" r="2.75"/><path d="M3 16c0-3 2.7-5.5 6-5.5s6 2.5 6 5.5"/></svg>';
$ic_mail  = '<svg class="ps-icon" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4.5" width="14" height="9" rx="1.5"/><path d="M2 5.5l7 5 7-5"/></svg>';
$ic_ad    = '<svg class="ps-icon" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="14" height="5" rx="1.5"/><rect x="2" y="10" width="14" height="5" rx="1.5"/><circle cx="13.5" cy="5.5" r="0.6" fill="currentColor" stroke="none"/><circle cx="13.5" cy="12.5" r="0.6" fill="currentColor" stroke="none"/></svg>';
$ic_sql   = '<svg class="ps-icon" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="14" height="12" rx="1.5"/><path d="M2 8h14M7 8v7"/></svg>';
$ic_globe = '<svg class="ps-icon" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7"/><path d="M9 2Q6 9 9 16M9 2Q12 9 9 16M2 9h14"/></svg>';
$enabledDomainCount = count(array_filter($domains, static fn($item) => (int) ($item['enabled'] ?? 0) === 1));
$enabledSvCount = count(array_filter($sqlViews, static fn($item) => (int) ($item['enabled'] ?? 0) === 1));
?>

<section class="settings-hub" aria-label="系統設定分類">
    <div class="settings-hub-card">
        <h2>權限與申請</h2>
        <p>管理後台管理員與可申請臨時帳號的 Google Email 網域。</p>
        <div class="settings-hub-links">
            <a href="#settings-admin" data-settings-target="#settings-admin">管理員 <span class="settings-count"><?= count($admins) ?></span></a>
            <a href="#settings-domains" data-settings-target="#settings-domains">允許網域 <span class="settings-count"><?= $enabledDomainCount ?></span></a>
        </div>
    </div>
    <div class="settings-hub-card">
        <h2>通知</h2>
        <p>設定 Gmail SMTP、通知收件人與測試信寄送。</p>
        <div class="settings-hub-links">
            <a href="#settings-mail" data-settings-target="#settings-mail">Email 通知 <span class="settings-count"><?= $mail['enabled'] ? 'ON' : 'OFF' ?></span></a>
        </div>
    </div>
    <div class="settings-hub-card">
        <h2>認證資料來源</h2>
        <p>串接 AD Server 與外部 SQL View 帳號匯入來源。</p>
        <div class="settings-hub-links">
            <a href="#settings-ad" data-settings-target="#settings-ad">AD Server <span class="settings-count"><?= $ad['enabled'] ? 'ON' : 'OFF' ?></span></a>
            <a href="#sv-panel" data-settings-target="#sv-panel">SQL View <span class="settings-count"><?= $enabledSvCount ?></span></a>
        </div>
    </div>
</section>

<div class="settings-section-label">
    <strong>權限與申請</strong>
    <span>後台登入權限、申請帳號允許網域</span>
</div>

<details class="panel collapsible-panel" id="settings-admin">
    <summary class="panel-summary">
        <span><?= $ic_admin ?>系統管理員權限</span>
        <small>新增管理員、查看管理員清單</small>
    </summary>
    <div class="panel-body">
    <h3>新增管理員</h3>
    <p class="warning-text">警告：新增管理員將賦予此帳號最高權限。</p>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_admin">
        <label>
            <span>輸入 Google Email</span>
            <input type="email" name="new_admin_email" required maxlength="190" autocomplete="email">
        </label>
        <button type="submit" class="primary">新增管理員</button>
    </form>

    <h3 class="subsection-title">管理員清單</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Google Email</th><th>名稱</th><th>啟用</th><th>最後登入</th><th>建立時間</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $item): ?>
                <tr>
                    <td><?= e($item['username']) ?></td>
                    <td><?= e($item['display_name']) ?></td>
                    <td><?= (int) $item['enabled'] === 1 ? '是' : '否' ?></td>
                    <td><?= e($item['last_login_at']) ?></td>
                    <td><?= e($item['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</details>

<details class="panel collapsible-panel" id="settings-domains">
    <summary class="panel-summary">
        <span><?= $ic_globe ?>申請帳號開放允許的網域清單</span>
        <small><?= $enabledDomainCount ?> 個網域已允許</small>
    </summary>
    <div class="panel-body">
    <form method="post" style="display:grid;grid-template-columns:minmax(200px,1fr) 120px auto;gap:12px;align-items:end;margin-bottom:18px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_allowed_domain">
        <label>
            <span>允許 Google Email 網域</span>
            <input name="domain" required maxlength="190" placeholder="例如 gmail.com">
        </label>
        <label>
            <span>最長期限（月）</span>
            <input type="number" name="max_months" value="12" min="1" max="120" required>
        </label>
        <button type="submit" class="primary">加入網域</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>網域</th><th>狀態</th><th>最長申請期限</th><th>建立者</th><th>建立時間</th><th>管理</th></tr></thead>
            <tbody>
            <?php foreach ($domains as $item): ?>
                <tr>
                    <td><code><?= e($item['domain']) ?></code></td>
                    <td><?= (int) $item['enabled'] === 1
                        ? '<span class="badge-active">允許</span>'
                        : '<span class="badge-inactive">停用</span>' ?></td>
                    <td>
                        <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_domain_max_months">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="number" name="max_months" value="<?= (int) ($item['max_months'] ?? 12) ?>" min="1" max="120" style="width:72px;padding:6px 8px;">
                            <span style="color:#5b6778;white-space:nowrap;">個月</span>
                            <button type="submit" class="secondary" style="padding:6px 10px;font-size:13px;white-space:nowrap;">更新</button>
                        </form>
                    </td>
                    <td><?= e($item['created_by']) ?></td>
                    <td><?= e($item['created_at']) ?></td>
                    <td>
                        <div class="inline-actions">
                        <?php if ((int) $item['enabled'] === 1): ?>
                            <form method="post" onsubmit="return confirm('確定要停用此允許網域？停用後此網域無法提出新申請。');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_allowed_domain">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button class="secondary" type="submit">停用</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="enable_allowed_domain">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button class="secondary" type="submit">啟用</button>
                            </form>
                        <?php endif; ?>
                            <form method="post" onsubmit="return confirm('確定要刪除此允許網域？刪除後若需要使用必須重新加入。');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_allowed_domain">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button class="danger" type="submit">刪除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</details>

<div class="settings-section-label">
    <strong>通知</strong>
    <span>Gmail SMTP、管理者與申請者 Email 通知</span>
</div>

<details class="panel collapsible-panel" id="settings-mail">
    <summary class="panel-summary">
        <span><?= $ic_mail ?>Nodemailer + Gmail SMTP 通知設定</span>
        <small>設定通知寄件帳號與測試信</small>
    </summary>
    <div class="panel-body">
    <div class="notice mail-help">
        <strong>Email 通知服務 (SMTP)</strong>
        <div>
            <p>若使用 Gmail，請勿填寫您原本的登入密碼，容易遭到帳號阻擋。</p>
            <p><a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">點此直接前往 Google 申請「應用程式密碼」</a></p>
            <p>步驟說明：</p>
            <ol>
                <li>您必須先在 Google 帳號安全性設定中開啟「兩步驟驗證」。</li>
                <li>點擊上述連結，在您的 Google 帳戶後台建立一組密碼，名稱可自訂如：NCUT eduroam申請系統。</li>
                <li>此時會產生一串 16 字元長度英文字母，請直接複製並貼到下方作為密碼。</li>
            </ol>
        </div>
    </div>
    <?php if ($mailRuntimeWarnings): ?>
        <div class="notice warning-text">
            <strong>寄信環境檢查</strong>
            <ul>
                <?php foreach ($mailRuntimeWarnings as $warning): ?>
                    <li><?= e($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_mail_settings">
        <label class="inline-check wide">
            <input type="checkbox" name="smtp_enabled" value="1" <?= $mail['enabled'] ? 'checked' : '' ?>>
            <span>啟用 Email 通知</span>
        </label>
        <label>
            <span>Gmail 帳號</span>
            <input type="email" name="smtp_user" value="<?= e($mail['user']) ?>" autocomplete="username">
        </label>
        <label>
            <span>Gmail App Password</span>
            <input type="password" name="smtp_pass" placeholder="<?= $mail['pass'] !== '' ? '已設定，留空不變更' : '請輸入 Gmail App Password' ?>" autocomplete="new-password">
        </label>
        <label>
            <span>寄件者 Email</span>
            <input type="email" name="smtp_from_email" value="<?= e($mail['from_email']) ?>" placeholder="留空則使用 Gmail 帳號">
        </label>
        <label>
            <span>寄件者名稱</span>
            <input name="smtp_from_name" value="<?= e($mail['from_name']) ?>">
        </label>
        <label class="wide">
            <span>通知管理者收件人</span>
            <input name="notify_admins" value="<?= e($mail['admin_recipients']) ?>" placeholder="可用逗號分隔多個 Email">
        </label>
        <div class="actions wide">
            <button type="submit" class="primary">儲存通知設定</button>
        </div>
    </form>
    <form method="post" class="domain-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_test_mail">
        <label>
            <span>測試收件人</span>
            <input type="email" name="test_email" required placeholder="name@example.com">
        </label>
        <button type="submit" class="secondary">寄送測試信</button>
    </form>
    <p class="muted small">系統固定使用 Gmail SMTP：smtp.gmail.com:587，並由 Nodemailer 使用 STARTTLS。管理者只需要填 Gmail 帳號與 App Password。</p>
    </div>
</details>

<div class="settings-section-label">
    <strong>認證資料來源</strong>
    <span>AD Server 串接、SQL View 帳號匯入</span>
</div>

<details class="panel collapsible-panel" id="settings-ad">
    <summary class="panel-summary">
        <span><?= $ic_ad ?>AD Server 串接設定</span>
        <small>LDAP 測試、Winbind 加入網域、FreeRADIUS 串接</small>
    </summary>
    <div class="panel-body">
    <div class="notice mail-help">
        <strong>FreeRADIUS / AD</strong>
        <div>
            <p>此區可儲存 AD 連線參數並測試 LDAP/LDAPS 連線。若 eduroam 使用 PEAP / MSCHAPV2 直接驗證 AD 密碼，請使用「儲存並套用 / 加入網域」自動完成 Samba / Winbind / ntlm_auth 設定。</p>
            <p class="muted small">套用時會產生 Kerberos 與 Samba 設定、加入 AD 網域、啟動 winbind、同步 FreeRADIUS 並重啟 radiusd；加入網域管理者密碼只會當次使用，不會保存。</p>
            <p class="muted small">完成後本機帳號會優先認證，找不到本機帳號時才會交給 AD；外校帳號仍依 TANRC realm 轉送。</p>
            <?php if (!extension_loaded('ldap')): ?>
                <p class="warning-text">目前 PHP LDAP extension 尚未啟用，AD 連線測試按鈕會無法執行。</p>
            <?php endif; ?>
        </div>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>

        <div class="fsep wide"><span class="fsep-label">連線設定</span></div>
        <label class="inline-check wide">
            <input type="checkbox" name="ad_enabled" value="1" <?= $ad['enabled'] ? 'checked' : '' ?>>
            <span>啟用 AD 串接設定</span>
        </label>
        <label>
            <span>驗證模式</span>
            <select name="ad_mode">
                <option value="winbind" <?= $ad['mode'] === 'winbind' ? 'selected' : '' ?>>Winbind / ntlm_auth（PEAP/MSCHAPV2 建議）</option>
                <option value="ldap" <?= $ad['mode'] === 'ldap' ? 'selected' : '' ?>>LDAP 查詢 / Bind 測試</option>
            </select>
        </label>
        <label>
            <span>AD Domain</span>
            <input name="ad_domain" value="<?= e($ad['domain']) ?>" placeholder="例如 ncut.edu.tw">
        </label>
        <label>
            <span>NetBIOS Domain</span>
            <input name="ad_netbios_domain" value="<?= e($ad['netbios_domain']) ?>" placeholder="例如 NCUT">
        </label>
        <label class="wide">
            <span>Domain Controller</span>
            <input name="ad_hosts" value="<?= e($ad['hosts']) ?>" placeholder="例如 dc1.ncut.edu.tw, dc2.ncut.edu.tw">
        </label>
        <label>
            <span>LDAP Port</span>
            <input name="ad_port" value="<?= e($ad['port']) ?>" inputmode="numeric" placeholder="389 或 636">
        </label>
        <label>
            <span>Base DN</span>
            <input name="ad_base_dn" value="<?= e($ad['base_dn']) ?>" placeholder="DC=ncut,DC=edu,DC=tw">
        </label>
        <label>
            <span>Bind DN / UPN</span>
            <input name="ad_bind_dn" value="<?= e($ad['bind_dn']) ?>" placeholder="radius-bind@ncut.edu.tw">
        </label>
        <label>
            <span>Bind Password</span>
            <input type="password" name="ad_bind_password" placeholder="<?= $ad['bind_password'] !== '' ? '已設定，留空不變更' : '請輸入 Bind 密碼' ?>" autocomplete="new-password">
        </label>
        <label>
            <span>帳號屬性</span>
            <input name="ad_user_attribute" value="<?= e($ad['user_attribute']) ?>" placeholder="sAMAccountName">
        </label>
        <label>
            <span>UPN Suffix</span>
            <input name="ad_upn_suffix" value="<?= e($ad['upn_suffix']) ?>" placeholder="ncut.edu.tw">
        </label>
        <label class="wide">
            <span>ntlm_auth 路徑</span>
            <input name="ad_ntlm_auth_path" value="<?= e($ad['ntlm_auth_path']) ?>" placeholder="/usr/bin/ntlm_auth">
        </label>

        <div class="fsep wide"><span class="fsep-label">TLS / 加密</span></div>
        <label class="inline-check">
            <input type="checkbox" name="ad_use_ssl" value="1" <?= $ad['use_ssl'] ? 'checked' : '' ?>>
            <span>使用 LDAPS</span>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="ad_start_tls" value="1" <?= $ad['start_tls'] ? 'checked' : '' ?>>
            <span>使用 StartTLS</span>
        </label>
        <label class="inline-check wide">
            <input type="checkbox" name="ad_verify_cert" value="1" <?= $ad['verify_cert'] ? 'checked' : '' ?>>
            <span>驗證伺服器憑證</span>
        </label>

        <div class="fsep wide"><span class="fsep-label">連線測試（選填，不保存）</span></div>
        <label>
            <span>測試帳號</span>
            <input name="ad_test_username" placeholder="例如 testuser 或 testuser@ncut.edu.tw">
        </label>
        <label>
            <span>測試帳號密碼</span>
            <input type="password" name="ad_test_password" autocomplete="new-password" placeholder="套用後用 ntlm_auth 測試 AD 密碼">
        </label>
        <div class="actions wide">
            <button type="submit" name="action" value="save_ad_settings" class="primary">儲存 AD 設定</button>
            <button type="submit" name="action" value="test_ad_connection" class="secondary">測試 AD 連線</button>
        </div>

        <div class="fsep wide">
            <span class="fsep-label">套用 / 加入網域</span>
            <span class="fsep-note">會重啟 winbind 與 radiusd，管理帳號僅當次使用不保存</span>
        </div>
        <div class="apply-zone">
            <div class="apply-zone-head">
                <span class="apply-zone-title">系統層套用</span>
                <span class="apply-zone-note">執行後將寫入 Samba / Kerberos 設定並重啟服務</span>
            </div>
            <label>
                <span>加入網域管理帳號</span>
                <input name="ad_join_username" autocomplete="off" placeholder="例如 administrator 或 admin@ncut.edu.tw">
            </label>
            <label>
                <span>加入網域管理密碼（不保存）</span>
                <input type="password" name="ad_join_password" autocomplete="new-password" placeholder="伺服器尚未加入網域時必填">
            </label>
            <div class="actions">
                <button type="submit" name="action" value="apply_ad_settings" class="btn-apply" onclick="return confirm('將儲存設定並套用到系統層，可能會重啟 winbind 與 FreeRADIUS。確認繼續？');">儲存並套用 / 加入網域</button>
            </div>
        </div>

    </form>
    </div>
</details>

<?php
function sv_render_form(array $sv = [], array $svColLabels = []): void
{
    $isNew     = empty($sv['id']);
    $id        = (int) ($sv['id'] ?? 0);
    $name      = (string) ($sv['name'] ?? '');
    $enabled   = !empty($sv['enabled']);
    $host      = (string) ($sv['host'] ?? '');
    $port      = (string) ($sv['port'] ?? '3306');
    $dbname    = (string) ($sv['dbname'] ?? '');
    $charset   = (string) ($sv['charset'] ?? 'utf8mb4');
    $dbuser    = (string) ($sv['dbuser'] ?? '');
    $dbpass    = (string) ($sv['dbpass'] ?? '');
    $viewName  = (string) ($sv['view_name'] ?? '');
    $statusCol = (string) ($sv['status_column'] ?? '');
    $statusVal = (string) ($sv['status_active_value'] ?? '');
?>
<form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="sv_id" value="<?= $id ?>">

    <div class="fsep wide"><span class="fsep-label">來源名稱</span></div>
    <label class="wide">
        <span>來源名稱 <span style="color:#b91c1c">*</span></span>
        <input name="sv_name" value="<?= e($name) ?>" required placeholder="例如 學籍系統 View、Campus HR">
    </label>
    <label class="inline-check wide">
        <input type="checkbox" name="sv_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span>啟用此 SQL View 匯入來源</span>
    </label>

    <div class="fsep wide"><span class="fsep-label">連線資訊</span></div>
    <label>
        <span>SQL 主機</span>
        <input name="sv_host" value="<?= e($host) ?>" placeholder="127.0.0.1 或 db.example.edu.tw">
    </label>
    <label>
        <span>SQL Port</span>
        <input name="sv_port" value="<?= e($port) ?>" inputmode="numeric" placeholder="3306">
    </label>
    <label>
        <span>資料庫名稱</span>
        <input name="sv_database" value="<?= e($dbname) ?>" placeholder="例如 campus_account">
    </label>
    <label>
        <span>字元集</span>
        <input name="sv_charset" value="<?= e($charset) ?>" placeholder="utf8mb4">
    </label>
    <label>
        <span>資料庫帳號</span>
        <input name="sv_username" value="<?= e($dbuser) ?>" autocomplete="off">
    </label>
    <label>
        <span>資料庫密碼</span>
        <input type="password" name="sv_password"
               placeholder="<?= $dbpass !== '' ? '已設定，留空不變更' : '請輸入資料庫密碼' ?>"
               autocomplete="new-password">
    </label>
    <label>
        <span>View 名稱</span>
        <input name="sv_view_name" value="<?= e($viewName) ?>" placeholder="例如 vw_eduroam_accounts">
    </label>

    <div class="fsep wide"><span class="fsep-label">篩選條件（選填）</span></div>
    <label>
        <span>狀態欄位</span>
        <select name="sv_status_column" class="sv-col-sel"
                data-saved="<?= e($statusCol) ?>" data-blank="（不篩選）">
            <option value="">（不篩選）</option>
            <?php if ($statusCol !== ''): ?>
            <option value="<?= e($statusCol) ?>" selected><?= e($statusCol) ?></option>
            <?php endif; ?>
        </select>
    </label>
    <label>
        <span>啟用狀態值</span>
        <input name="sv_status_active_value" value="<?= e($statusVal) ?>" placeholder="例如 active">
    </label>

    <div class="fsep wide"><span class="fsep-label">欄位對應</span></div>
    <div class="sv-fetch-zone wide">
        <button type="button" class="sv-fetch-btn primary">連線並取得欄位清單</button>
        <span class="sv-fetch-status"></span>
        <span class="muted small" style="margin-left:auto;">填好連線資訊後點此，再從下拉選單選取對應欄位</span>
    </div>
    <div class="sv-mapping-grid wide">
    <?php foreach (sql_view_field_settings() as $key => $meta):
        $colField = 'col_' . $key;
        $savedCol = (string) ($sv[$colField] ?? $meta['default']);
    ?>
        <label>
            <span><?= e($svColLabels[$key] ?? $key) ?><?= $meta['required']
                ? ' <span style="color:#b91c1c">*</span>'
                : '<span class="muted" style="font-weight:400">（選填）</span>' ?></span>
            <select name="sv_col_<?= e($key) ?>" class="sv-col-sel"
                    data-saved="<?= e($savedCol) ?>"
                    data-blank="<?= $meta['required'] ? '— 請選擇欄位 —' : '（略過）' ?>">
                <option value=""><?= $meta['required'] ? '— 請取得欄位清單 —' : '（略過）' ?></option>
                <?php if ($savedCol !== ''): ?>
                <option value="<?= e($savedCol) ?>" selected><?= e($savedCol) ?></option>
                <?php endif; ?>
            </select>
        </label>
    <?php endforeach; ?>
    </div>

    <div class="actions wide">
        <button type="submit" name="action" value="save_sql_view" class="primary"><?= $isNew ? '新增 SQL View' : '儲存設定' ?></button>
        <button type="submit" name="action" value="test_sv_connection" class="secondary">測試連線</button>
    </div>
</form>
<?php } ?>

<details class="panel collapsible-panel" id="sv-panel" <?= $editingSv ? 'open' : '' ?>>
    <summary class="panel-summary">
        <span><?= $ic_sql ?>SQL View 串接設定</span>
        <small><?= count($sqlViews) ?> 個來源，<?= $enabledSvCount ?> 個已啟用</small>
    </summary>
    <div class="panel-body">

    <div class="notice mail-help">
        <strong>SQL View 匯入來源</strong>
        <div>
            <p>可設定多個外部 MySQL/MariaDB SQL View 來源；匯入時將從所有已啟用的來源依序讀取並開通帳號。</p>
            <p class="muted small">View 名稱可使用 <code>view_name</code> 或 <code>schema.view_name</code>；欄位名稱限英文字母、數字與底線。</p>
        </div>
    </div>

    <?php if ($sqlViews): ?>
    <div class="table-wrap" style="margin-bottom:20px;">
        <table style="min-width:600px;">
            <thead>
                <tr><th>名稱</th><th>主機</th><th>View 名稱</th><th>狀態</th><th>管理</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sqlViews as $sv): ?>
                <tr <?= (int) $sv['id'] === $editingSvId ? 'style="background:#edf4ff"' : '' ?>>
                    <td><strong><?= e($sv['name']) ?></strong></td>
                    <td class="muted small"><?= e($sv['host']) ?></td>
                    <td><code><?= e($sv['view_name']) ?></code></td>
                    <td><?= (int) $sv['enabled'] === 1
                        ? '<span class="badge-active">啟用</span>'
                        : '<span class="badge-inactive">停用</span>' ?></td>
                    <td>
                        <div class="inline-actions">
                            <a href="/admin-settings.php?sv=<?= (int) $sv['id'] ?>#sv-panel"
                               class="secondary button-link" style="font-size:13px;padding:7px 11px;">設定</a>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_sql_view">
                                <input type="hidden" name="sv_id" value="<?= (int) $sv['id'] ?>">
                                <button class="secondary" type="submit"><?= (int) $sv['enabled'] === 1 ? '停用' : '啟用' ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('確定要刪除此 SQL View 設定？');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_sql_view">
                                <input type="hidden" name="sv_id" value="<?= (int) $sv['id'] ?>">
                                <button class="danger" type="submit">刪除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($editingSv): ?>
    <div style="background:#f0f7ff;border:1px solid #c0d8f8;border-radius:8px;padding:20px 20px 6px;margin-bottom:20px;">
        <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:14px;">
            <strong>編輯：<?= e($editingSv['name']) ?></strong>
            <a href="/admin-settings.php#sv-panel" style="font-size:13px;">← 返回清單</a>
        </div>
        <?= sv_render_form($editingSv, $svColLabels) ?>
    </div>
    <?php endif; ?>

    <details <?= $editingSv ? '' : (empty($sqlViews) ? 'open' : '') ?>>
        <summary style="cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:8px;padding:9px 14px;background:#e8edf3;border-radius:6px;font-weight:700;margin-bottom:16px;">
            + 新增 SQL View 來源
        </summary>
        <div style="border:1px solid #dbe2ea;border-radius:8px;padding:20px 20px 6px;margin-top:12px;">
            <?= sv_render_form([], $svColLabels) ?>
        </div>
    </details>

    </div>
</details>

<script>
(function () {
    function openSettingsTarget(hash) {
        if (!hash || hash.length < 2) return;
        var target = document.querySelector(hash);
        if (target && target.tagName === 'DETAILS') {
            target.open = true;
        }
    }

    document.querySelectorAll('[data-settings-target]').forEach(function (link) {
        link.addEventListener('click', function () {
            openSettingsTarget(link.getAttribute('data-settings-target'));
        });
    });
    openSettingsTarget(window.location.hash);
    window.addEventListener('hashchange', function () {
        openSettingsTarget(window.location.hash);
    });

    function populateSelects(form, cols) {
        form.querySelectorAll('.sv-col-sel').forEach(function (sel) {
            var saved   = sel.dataset.saved || '';
            var current = sel.value         || '';
            var pick    = current || saved;
            var blank   = sel.dataset.blank || '（略過）';
            sel.innerHTML = '';
            sel.appendChild(new Option(blank, ''));
            cols.forEach(function (col) {
                var opt = new Option(col, col);
                if (col === pick) opt.selected = true;
                sel.appendChild(opt);
            });
        });
    }

    document.querySelectorAll('.sv-fetch-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form   = btn.closest('form');
            var status = form.querySelector('.sv-fetch-status');
            btn.disabled = true;
            status.textContent = '連線中…';
            status.className = 'sv-fetch-status';

            var data = new FormData(form);
            data.set('action', 'fetch_sv_columns');

            fetch('', { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.ok) {
                        populateSelects(form, json.columns);
                        status.textContent = '✓ 已載入 ' + json.columns.length + ' 個欄位';
                    } else {
                        status.textContent = '錯誤：' + (json.error || '未知錯誤');
                    }
                })
                .catch(function (e) {
                    status.textContent = '連線失敗：' + e.message;
                })
                .finally(function () { btn.disabled = false; });
        });
    });
})();
</script>
<?php
render_footer();
