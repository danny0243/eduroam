<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

const SSL_CERT_PATH = '/etc/pki/tls/certs/eduroam.ncut.edu.tw-fullchain.pem';
const SSL_KEY_PATH = '/etc/pki/tls/private/eduroam.ncut.edu.tw.key';
const SSL_DOMAIN = 'eduroam.ncut.edu.tw';
const SSL_UPLOAD_MAX_BYTES = 1048576;

function ssl_current_certificate_summary(): array
{
    if (!is_readable(SSL_CERT_PATH)) {
        return ['readable' => false];
    }
    $content = (string) file_get_contents(SSL_CERT_PATH);
    if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $content, $matches)) {
        return ['readable' => true, 'valid' => false];
    }
    $cert = @openssl_x509_read($matches[0]);
    if ($cert === false) {
        return ['readable' => true, 'valid' => false];
    }
    $parsed = openssl_x509_parse($cert);
    if (!is_array($parsed)) {
        return ['readable' => true, 'valid' => false];
    }
    $subject = $parsed['subject'] ?? [];
    $issuer = $parsed['issuer'] ?? [];
    $now = time();
    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
    $daysLeft = $validTo > 0 ? (int) floor(($validTo - $now) / 86400) : null;

    return [
        'readable' => true,
        'valid' => true,
        'subject' => is_array($subject) ? (string) ($subject['CN'] ?? '') : '',
        'issuer' => is_array($issuer) ? (string) ($issuer['CN'] ?? '') : '',
        'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
        'not_before' => isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']) : '',
        'not_after' => $validTo > 0 ? date('Y-m-d H:i:s', $validTo) : '',
        'days_left' => $daysLeft,
        'fingerprint_sha256' => openssl_x509_fingerprint($cert, 'sha256') ?: '',
        'chain_count' => preg_match_all('/-----BEGIN CERTIFICATE-----/s', $content),
    ];
}

function ssl_badge_class(?int $daysLeft): string
{
    if ($daysLeft === null) {
        return 'info';
    }
    if ($daysLeft < 0 || $daysLeft <= 14) {
        return 'rejected';
    }
    if ($daysLeft <= 45) {
        return 'expired';
    }
    return 'approved';
}

function ssl_upload_contents(string $field, bool $required): string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('請選擇必要的憑證檔案。');
        }
        return '';
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('檔案上傳失敗，錯誤碼：' . $error);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > SSL_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('上傳檔案大小不正確，單一檔案上限為 1 MB。');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('上傳暫存檔無效。');
    }
    $content = file_get_contents($tmp);
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('上傳檔案內容為空。');
    }
    return $content;
}

function ssl_certificate_upload_to_pem(string $content): string
{
    if ($content === '') {
        return '';
    }
    if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $content)) {
        return $content;
    }
    return "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($content), 64, "\n")
        . "-----END CERTIFICATE-----\n";
}

function ssl_install_error_message(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return 'SSL 憑證匯入失敗：helper 沒有回傳錯誤內容，請檢查 Web Server 與 sudoers 設定。';
    }
    if (stripos($output, 'sudo') !== false && preg_match('/password|required|not allowed|no tty/i', $output)) {
        return 'SSL 憑證匯入失敗：sudo 權限尚未設定完成，請確認 /etc/sudoers.d/ncut-eduroam-ssl-certificate。';
    }
    $safe = preg_replace('/-----BEGIN .*?-----.*?-----END .*?-----/s', '[PEM REDACTED]', $output) ?? $output;
    $firstLine = strtok($safe, "\r\n");
    return 'SSL 憑證匯入失敗：' . mb_substr((string) $firstLine, 0, 500);
}

function run_ssl_install_helper(array $payload): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('SSL 憑證匯入失敗：PHP proc_open 未啟用。');
    }
    $helper = '/var/www/eduroam-portal/bin/install-ssl-certificate.php';
    if (!is_readable($helper)) {
        throw new RuntimeException('SSL 憑證匯入失敗：找不到 install-ssl-certificate.php。');
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        throw new RuntimeException('SSL 憑證匯入失敗：無法建立 helper payload。');
    }

    $cmd = '/usr/bin/sudo -n /usr/bin/php ' . escapeshellarg($helper);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('SSL 憑證匯入失敗：無法啟動 sudo helper。');
    }
    fwrite($pipes[0], $payloadJson);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(ssl_install_error_message(trim(($stderr ?: '') . "\n" . ($stdout ?: ''))));
    }
    $result = json_decode((string) $stdout, true);
    if (!is_array($result) || ($result['status'] ?? '') !== 'ok') {
        throw new RuntimeException('SSL 憑證匯入失敗：helper 回傳格式不正確。');
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'install_ssl_certificate') {
            throw new RuntimeException('未知的操作。');
        }

        $certificate = ssl_certificate_upload_to_pem(ssl_upload_contents('certificate_file', true));
        $chain = ssl_certificate_upload_to_pem(ssl_upload_contents('chain_file', false));
        $result = run_ssl_install_helper([
            'certificate' => $certificate,
            'chain' => $chain,
            'private_key' => ssl_upload_contents('private_key_file', true),
            'private_key_passphrase' => (string) ($_POST['private_key_passphrase'] ?? ''),
            'reload_httpd' => !empty($_POST['reload_httpd']),
        ]);
        audit(
            $pdo,
            (int) $admin['id'],
            'ssl_certificate_install',
            null,
            'installed SSL certificate for ' . SSL_DOMAIN . ' fingerprint ' . ($result['fingerprint_sha256'] ?? '')
        );
        flash('success', 'SSL 憑證已匯入並套用，效期至 ' . ($result['not_after'] ?? '-') . '。');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin-ssl-certificate.php');
}

$summary = ssl_current_certificate_summary();
$daysLeft = isset($summary['days_left']) ? (int) $summary['days_left'] : null;

render_header('SSL 憑證管理 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>SSL 憑證管理</h1>
        <p>匯入並套用 TWCA 核發給 <code><?= e(SSL_DOMAIN) ?></code> 的 Apache HTTPS 憑證。</p>
    </div>
    <div class="stats">
        <span><strong><?= e(SSL_DOMAIN) ?></strong> 網域</span>
        <span><strong><?= e(SSL_CERT_PATH) ?></strong> fullchain</span>
    </div>
</section>

<nav class="tabbar" aria-label="系統管理">
    <a href="/admin-radius-proxy.php">RADIUS Proxy</a>
    <a href="/admin-settings.php">系統設定</a>
    <a class="active" href="/admin-ssl-certificate.php">SSL 憑證</a>
</nav>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>目前憑證</h2>
            <p class="muted small">正式 Apache 443 與 daloRADIUS 8443 目前共用這組憑證檔案。</p>
        </div>
        <?php if (($summary['valid'] ?? false) === true): ?>
            <span class="badge <?= e(ssl_badge_class($daysLeft)) ?>">
                <?= $daysLeft !== null ? e($daysLeft . ' 天後到期') : '效期未知' ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (($summary['valid'] ?? false) !== true): ?>
        <p class="muted">目前無法讀取或解析正式憑證檔。</p>
    <?php else: ?>
        <div class="ssl-summary-grid">
            <div><span>Subject CN</span><strong><?= e($summary['subject']) ?></strong></div>
            <div><span>Issuer</span><strong><?= e($summary['issuer']) ?></strong></div>
            <div><span>Not Before</span><strong><?= e($summary['not_before']) ?></strong></div>
            <div><span>Not After</span><strong><?= e($summary['not_after']) ?></strong></div>
            <div><span>Chain 憑證數</span><strong><?= (int) ($summary['chain_count'] ?? 0) ?></strong></div>
            <div><span>私鑰路徑</span><strong><?= e(SSL_KEY_PATH) ?></strong></div>
        </div>
        <p class="ssl-fingerprint"><span>SHA256 Fingerprint</span><code><?= e((string) $summary['fingerprint_sha256']) ?></code></p>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>匯入 TWCA 憑證</h2>
            <p class="muted small">Server Certificate 與 Private Key 必填；Intermediate CA / Chain 建議上傳 TWCA 提供的 <code>uca.cer</code>。</p>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="form-grid ssl-upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="install_ssl_certificate">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= SSL_UPLOAD_MAX_BYTES ?>">

        <label>
            <span>Server Certificate</span>
            <input type="file" name="certificate_file" accept=".cer,.crt,.pem,application/x-x509-ca-cert,text/plain" required>
            <small class="muted">例如 TWCA 的 <code>server.cer</code>，可為 PEM 或單張 DER 憑證。</small>
        </label>
        <label>
            <span>Private Key</span>
            <input type="file" name="private_key_file" accept=".key,.pem,text/plain" required>
            <small class="muted">例如 <code>ncutserver.key</code>。內容不會寫入資料庫或顯示在頁面。</small>
        </label>
        <label>
            <span>Intermediate CA / Chain</span>
            <input type="file" name="chain_file" accept=".cer,.crt,.pem,application/x-x509-ca-cert,text/plain">
            <small class="muted">例如 TWCA 的 <code>uca.cer</code>；若 Server Certificate 已含 fullchain 可不選。</small>
        </label>
        <label>
            <span>私鑰密碼</span>
            <input type="password" name="private_key_passphrase" autocomplete="off" placeholder="若私鑰未加密可留空">
            <small class="muted">若有填寫，只用於本次匯入解密，不會保存。</small>
        </label>
        <label class="inline-check wide">
            <input type="checkbox" name="reload_httpd" value="1" checked>
            <span>驗證通過後重新載入 Apache，讓 443 與 8443 立即使用新憑證</span>
        </label>
        <div class="notice wide ssl-warning">
            <strong>安全檢查</strong>
            <p>系統會檢查憑證效期、CN/SAN 是否符合 <code><?= e(SSL_DOMAIN) ?></code>、憑證與私鑰是否相符，並在安裝後執行 Apache configtest。若驗證失敗，會回復原憑證。</p>
        </div>
        <div class="actions wide">
            <button type="submit" class="primary">匯入並套用 SSL 憑證</button>
        </div>
    </form>
</section>
<?php render_footer(); ?>
