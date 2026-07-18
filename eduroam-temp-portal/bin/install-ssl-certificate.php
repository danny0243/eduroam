<?php
declare(strict_types=1);

const SSL_CERT_PATH = '/etc/pki/tls/certs/eduroam.ncut.edu.tw-fullchain.pem';
const SSL_KEY_PATH = '/etc/pki/tls/private/eduroam.ncut.edu.tw.key';
const SSL_DOMAIN = 'eduroam.ncut.edu.tw';
const MAX_PEM_BYTES = 262144;

function require_root(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new RuntimeException('This helper must run as root.');
    }
}

function shell_join(array $args): string
{
    return implode(' ', array_map('escapeshellarg', $args));
}

function command_path(array $candidates): string
{
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Required command not found: ' . implode(', ', $candidates));
}

function run_command(string $label, array $args, bool $required = true, int $timeout = 60): array
{
    $timeoutBin = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout' : '';
    $cmd = $timeoutBin !== ''
        ? escapeshellarg($timeoutBin) . ' ' . escapeshellarg((string) $timeout) . ' ' . shell_join($args)
        : shell_join($args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, '/');
    if (!is_resource($process)) {
        throw new RuntimeException($label . ' could not start.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $output = trim(($stdout ?: '') . "\n" . ($stderr ?: ''));
    if ($required && $exitCode !== 0) {
        throw new RuntimeException($label . " failed.\n" . $output);
    }
    return [
        'label' => $label,
        'exit' => $exitCode,
        'output' => $output,
    ];
}

function pem_blocks(string $content, string $type): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $pattern = '/-----BEGIN ' . preg_quote($type, '/') . '-----.*?-----END ' . preg_quote($type, '/') . '-----/s';
    if (preg_match_all($pattern, $content, $matches)) {
        return array_values(array_map(
            static fn(string $block): string => trim($block) . "\n",
            $matches[0]
        ));
    }

    if ($type === 'CERTIFICATE') {
        $candidate = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($content), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        if (@openssl_x509_read($candidate) !== false) {
            return [$candidate];
        }
    }

    return [];
}

function unique_cert_blocks(array $blocks): array
{
    $seen = [];
    $unique = [];
    foreach ($blocks as $block) {
        $cert = @openssl_x509_read($block);
        if ($cert === false) {
            throw new RuntimeException('Invalid certificate PEM block.');
        }
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256') ?: hash('sha256', $block);
        if (!isset($seen[$fingerprint])) {
            $seen[$fingerprint] = true;
            $unique[] = $block;
        }
    }
    return $unique;
}

function cert_names(array $parsed): array
{
    $names = [];
    $subject = $parsed['subject'] ?? [];
    if (is_array($subject) && !empty($subject['CN'])) {
        $names[] = strtolower((string) $subject['CN']);
    }
    $san = (string) (($parsed['extensions'] ?? [])['subjectAltName'] ?? '');
    foreach (explode(',', $san) as $part) {
        $part = trim($part);
        if (stripos($part, 'DNS:') === 0) {
            $names[] = strtolower(trim(substr($part, 4)));
        }
    }
    return array_values(array_unique($names));
}

function cert_matches_domain(array $names, string $domain): bool
{
    $domain = strtolower($domain);
    foreach ($names as $name) {
        $name = strtolower($name);
        if ($name === $domain) {
            return true;
        }
        if (str_starts_with($name, '*.')) {
            $suffix = substr($name, 1);
            if (str_ends_with($domain, $suffix) && substr_count($domain, '.') === substr_count($name, '.')) {
                return true;
            }
        }
    }
    return false;
}

function cert_summary(string $certPem): array
{
    $cert = @openssl_x509_read($certPem);
    if ($cert === false) {
        throw new RuntimeException('Unable to parse certificate.');
    }
    $parsed = openssl_x509_parse($cert);
    if (!is_array($parsed)) {
        throw new RuntimeException('Unable to parse certificate metadata.');
    }

    $subject = $parsed['subject'] ?? [];
    $issuer = $parsed['issuer'] ?? [];
    return [
        'subject' => is_array($subject) ? (string) ($subject['CN'] ?? '') : '',
        'issuer' => is_array($issuer) ? (string) ($issuer['CN'] ?? '') : '',
        'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
        'not_before' => isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']) : '',
        'not_after' => isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : '',
        'fingerprint_sha256' => openssl_x509_fingerprint($cert, 'sha256') ?: '',
        'names' => cert_names($parsed),
        'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
        'valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
    ];
}

function normalize_private_key(string $privateKey, string $passphrase): string
{
    $key = @openssl_pkey_get_private($privateKey, $passphrase !== '' ? $passphrase : null);
    if ($key === false) {
        throw new RuntimeException('Private key cannot be read. Check the file and passphrase.');
    }
    $exported = '';
    if (!openssl_pkey_export($key, $exported)) {
        throw new RuntimeException('Private key export failed.');
    }
    return trim($exported) . "\n";
}

function atomic_write(string $target, string $content, int $mode, string $group): void
{
    $dir = dirname($target);
    if (!is_dir($dir)) {
        throw new RuntimeException('Directory not found: ' . $dir);
    }
    $tmp = $target . '.ncut-' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file for ' . $target);
    }
    chmod($tmp, $mode);
    @chown($tmp, 'root');
    @chgrp($tmp, $group);
    if (!rename($tmp, $target)) {
        throw new RuntimeException('Unable to replace ' . $target);
    }
    chmod($target, $mode);
    @chown($target, 'root');
    @chgrp($target, $group);
}

function backup_existing(string $target, int $mode, string $group, string $stamp): ?string
{
    if (!is_file($target)) {
        return null;
    }
    $backup = $target . '.' . $stamp . '.bak';
    if (!copy($target, $backup)) {
        throw new RuntimeException('Unable to create backup for ' . $target);
    }
    chmod($backup, $mode);
    @chown($backup, 'root');
    @chgrp($backup, $group);
    return $backup;
}

function restore_backup(?string $backup, string $target, int $mode, string $group): void
{
    if ($backup === null || !is_file($backup)) {
        return;
    }
    if (!copy($backup, $target)) {
        throw new RuntimeException('Rollback failed for ' . $target);
    }
    chmod($target, $mode);
    @chown($target, 'root');
    @chgrp($target, $group);
}

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($exitCode);
}

try {
    require_root();
    $raw = stream_get_contents(STDIN);
    if (!is_string($raw) || trim($raw) === '' || strlen($raw) > MAX_PEM_BYTES * 3) {
        throw new RuntimeException('Invalid input payload.');
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $certificateInput = (string) ($payload['certificate'] ?? '');
    $chainInput = (string) ($payload['chain'] ?? '');
    $privateKeyInput = (string) ($payload['private_key'] ?? '');
    $passphrase = (string) ($payload['private_key_passphrase'] ?? '');
    $reloadHttpd = !empty($payload['reload_httpd']);

    foreach ([$certificateInput, $chainInput, $privateKeyInput] as $part) {
        if (strlen($part) > MAX_PEM_BYTES) {
            throw new RuntimeException('Uploaded certificate material is too large.');
        }
    }

    $certificateBlocks = pem_blocks($certificateInput, 'CERTIFICATE');
    if (!$certificateBlocks) {
        throw new RuntimeException('Server certificate file does not contain a certificate.');
    }
    $chainBlocks = pem_blocks($chainInput, 'CERTIFICATE');
    $allBlocks = unique_cert_blocks(array_merge($certificateBlocks, $chainBlocks));
    $leafCert = $certificateBlocks[0];
    $summary = cert_summary($leafCert);

    $now = time();
    if ((int) $summary['valid_from'] > $now) {
        throw new RuntimeException('Certificate is not valid yet.');
    }
    if ((int) $summary['valid_to'] <= $now) {
        throw new RuntimeException('Certificate is expired.');
    }
    if (!cert_matches_domain($summary['names'], SSL_DOMAIN)) {
        throw new RuntimeException('Certificate SAN/CN does not match ' . SSL_DOMAIN . '.');
    }

    $privateKey = normalize_private_key($privateKeyInput, $passphrase);
    $certResource = @openssl_x509_read($leafCert);
    $keyResource = @openssl_pkey_get_private($privateKey);
    if ($certResource === false || $keyResource === false || !openssl_x509_check_private_key($certResource, $keyResource)) {
        throw new RuntimeException('Certificate and private key do not match.');
    }

    $fullchain = implode("\n", $allBlocks);
    $stamp = date('Ymd-His');
    $certBackup = backup_existing(SSL_CERT_PATH, 0644, 'root', $stamp);
    $keyBackup = backup_existing(SSL_KEY_PATH, 0640, 'apache', $stamp);

    try {
        atomic_write(SSL_CERT_PATH, $fullchain, 0644, 'root');
        atomic_write(SSL_KEY_PATH, $privateKey, 0640, 'apache');
        if (is_executable('/usr/sbin/restorecon')) {
            run_command('Restore SELinux context', ['/usr/sbin/restorecon', SSL_CERT_PATH, SSL_KEY_PATH], false, 20);
        }
        $apachectl = command_path(['/usr/sbin/apachectl', '/usr/sbin/httpd']);
        $configCheckArgs = str_ends_with($apachectl, '/httpd') ? [$apachectl, '-t'] : [$apachectl, 'configtest'];
        run_command('Apache configtest', $configCheckArgs, true, 30);
    } catch (Throwable $e) {
        restore_backup($certBackup, SSL_CERT_PATH, 0644, 'root');
        restore_backup($keyBackup, SSL_KEY_PATH, 0640, 'apache');
        throw $e;
    }

    $reloadOutput = 'skipped';
    if ($reloadHttpd) {
        $systemctl = command_path(['/usr/bin/systemctl', '/bin/systemctl']);
        $reload = run_command('Reload Apache', [$systemctl, 'reload', 'httpd'], true, 60);
        $reloadOutput = $reload['output'] !== '' ? $reload['output'] : 'ok';
    }

    echo json_encode([
        'status' => 'ok',
        'domain' => SSL_DOMAIN,
        'subject' => $summary['subject'],
        'issuer' => $summary['issuer'],
        'serial' => $summary['serial'],
        'not_before' => $summary['not_before'],
        'not_after' => $summary['not_after'],
        'fingerprint_sha256' => $summary['fingerprint_sha256'],
        'certificate_path' => SSL_CERT_PATH,
        'private_key_path' => SSL_KEY_PATH,
        'backup_stamp' => $stamp,
        'chain_count' => max(0, count($allBlocks) - 1),
        'apache_reload' => $reloadOutput,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fail($e->getMessage());
}
