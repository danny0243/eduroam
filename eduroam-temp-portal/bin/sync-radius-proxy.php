<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

const RADIUS_PROXY_CONF = '/etc/raddb/proxy.conf';
const MANAGED_PROXY_FILE = '/etc/raddb/ncut-proxy-managed.conf';
const MANAGED_PROXY_INCLUDE = '$INCLUDE ' . MANAGED_PROXY_FILE;
const LEGACY_TANRC_BLOCK_PATTERN = '/\n?# BEGIN NCUT TANRC PROXY\b.*?# END NCUT TANRC PROXY\n?/s';
const TANRC_POOL_NAME = 'TANRC_POOL';

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

function atomic_write(string $target, string $content, int $mode): void
{
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory: ' . $dir);
    }
    $tmp = $target . '.ncut.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write ' . $tmp);
    }
    chmod($tmp, $mode);
    if (!rename($tmp, $target)) {
        throw new RuntimeException('Unable to replace ' . $target);
    }
}

function backup_once(string $target): void
{
    if (!is_file($target)) {
        return;
    }
    $backup = $target . '.pre-ncut-proxy';
    if (!file_exists($backup) && !copy($target, $backup)) {
        throw new RuntimeException('Unable to create backup: ' . $backup);
    }
}

function radius_value(string $value): string
{
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

function radius_comment(string $value): string
{
    return str_replace(["\r", "\n"], ' ', mb_substr($value, 0, 160));
}

function radius_identifier(string $value, string $fallback): string
{
    $value = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    if ($value !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $value)) {
        return $value;
    }
    return $fallback;
}

function home_server_name(int $groupId, int $serverId, string $type): string
{
    return 'ncut_proxy_g' . $groupId . '_s' . $serverId . '_' . $type;
}

function pool_name(int $groupId, string $type): string
{
    return 'ncut_proxy_pool_g' . $groupId . '_' . $type;
}

function tanrc_home_server_name(array $server): string
{
    return radius_identifier(
        (string) $server['name'],
        'TANRC_MainAuth_SRV' . (int) $server['id']
    );
}

function append_ncut_local_realms(array &$lines): void
{
    $lines[] = '# NCUT local realms: handle campus eduroam users on this server.';
    foreach (['ncut.edu.tw', 'eduroam.ncut.edu.tw'] as $realm) {
        $lines[] = 'realm ' . $realm . ' {';
        $lines[] = '        nostrip';
        $lines[] = '}';
        $lines[] = '';
    }
}

function append_tanrc_default_proxy(array &$lines, array $group): void
{
    $lines[] = '# Managed TANRC DEFAULT proxy. pool=' . TANRC_POOL_NAME;
    foreach ($group['servers'] as $server) {
        $serverName = tanrc_home_server_name($server);
        $lines[] = '# server_id=' . (int) $server['id'] . ' name=' . radius_comment((string) $server['name']);
        $lines[] = 'home_server ' . $serverName . ' {';
        $lines[] = '        type = auth+acct';
        $lines[] = '        ipaddr = ' . (string) $server['server_host'];
        $lines[] = '        port = ' . (int) $server['auth_port'];
        $lines[] = '        secret = ' . radius_value((string) $server['shared_secret_plain']);
        $lines[] = '        response_window = ' . (int) $server['response_window'];
        $lines[] = '        zombie_period = ' . (int) $server['zombie_period'];
        $lines[] = '        revive_interval = ' . (int) $server['revive_interval'];
        $lines[] = '        status_check = ' . (string) $server['status_check'];
        $lines[] = '}';
        $lines[] = '';
    }

    $lines[] = 'home_server_pool ' . TANRC_POOL_NAME . ' {';
    $lines[] = '        type = ' . (string) $group['pool_type'];
    foreach ($group['servers'] as $server) {
        $lines[] = '        home_server = ' . tanrc_home_server_name($server);
    }
    $lines[] = '}';
    $lines[] = '';
    $lines[] = 'realm DEFAULT {';
    $lines[] = '        pool = ' . TANRC_POOL_NAME;
    if ((int) $group['nostrip'] === 1) {
        $lines[] = '        nostrip';
    }
    $lines[] = '}';
    $lines[] = '';
}

function proxy_content(array $groups, bool $defaultRealmManaged): string
{
    $lines = [
        '# Generated by NCUT eduroam portal. Do not edit by hand.',
        '# This file is included by ' . RADIUS_PROXY_CONF,
        '# DEFAULT may be managed here as TANRC_POOL.',
        '',
    ];

    if ($defaultRealmManaged) {
        append_ncut_local_realms($lines);
    }

    foreach ($groups as $group) {
        $groupId = (int) $group['id'];
        $realm = (string) $group['realm'];
        if (radius_proxy_is_default_realm($realm)) {
            append_tanrc_default_proxy($lines, $group);
            continue;
        }
        $authPool = pool_name($groupId, 'auth');
        $acctPool = pool_name($groupId, 'acct');
        $lines[] = '# group_id=' . $groupId . ' name=' . radius_comment((string) $group['name']) . ' realm=' . $realm;
        foreach ($group['servers'] as $server) {
            $serverId = (int) $server['id'];
            $baseComment = '# server_id=' . $serverId . ' name=' . radius_comment((string) $server['name']);
            foreach (['auth' => (int) $server['auth_port'], 'acct' => (int) $server['acct_port']] as $type => $port) {
                $lines[] = $baseComment . ' type=' . $type;
                $lines[] = 'home_server ' . home_server_name($groupId, $serverId, $type) . ' {';
                $lines[] = '        type = ' . $type;
                $lines[] = '        ipaddr = ' . (string) $server['server_host'];
                $lines[] = '        port = ' . $port;
                $lines[] = '        secret = ' . radius_value((string) $server['shared_secret_plain']);
                $lines[] = '        response_window = ' . (int) $server['response_window'];
                $lines[] = '        zombie_period = ' . (int) $server['zombie_period'];
                $lines[] = '        revive_interval = ' . (int) $server['revive_interval'];
                $lines[] = '        status_check = ' . (string) $server['status_check'];
                $lines[] = '}';
                $lines[] = '';
            }
        }

        $lines[] = 'home_server_pool ' . $authPool . ' {';
        $lines[] = '        type = ' . (string) $group['pool_type'];
        foreach ($group['servers'] as $server) {
            $serverId = (int) $server['id'];
            $lines[] = '        home_server = ' . home_server_name($groupId, $serverId, 'auth');
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'home_server_pool ' . $acctPool . ' {';
        $lines[] = '        type = ' . (string) $group['pool_type'];
        foreach ($group['servers'] as $server) {
            $serverId = (int) $server['id'];
            $lines[] = '        home_server = ' . home_server_name($groupId, $serverId, 'acct');
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'realm ' . $realm . ' {';
        $lines[] = '        auth_pool = ' . $authPool;
        $lines[] = '        acct_pool = ' . $acctPool;
        if ((int) $group['nostrip'] === 1) {
            $lines[] = '        nostrip';
        }
        $lines[] = '}';
        $lines[] = '';
    }

    if (!$groups) {
        $lines[] = '# No active managed proxy realms.';
    }

    return implode("\n", $lines) . "\n";
}

function ensure_proxy_include(string $proxyConf): string
{
    if (str_contains($proxyConf, MANAGED_PROXY_INCLUDE)) {
        return $proxyConf;
    }
    return "# NCUT managed proxy realms\n" . MANAGED_PROXY_INCLUDE . "\n\n" . $proxyConf;
}

function remove_legacy_tanrc_block(string $proxyConf): string
{
    $cleaned = preg_replace(LEGACY_TANRC_BLOCK_PATTERN, "\n", $proxyConf, 1);
    return $cleaned ?? $proxyConf;
}

require_root();
$pdo = db();
$groups = radius_proxy_active_config($pdo);
$defaultRealmManaged = radius_proxy_default_group_exists($pdo);
$content = proxy_content($groups, $defaultRealmManaged);

if (!is_readable(RADIUS_PROXY_CONF)) {
    throw new RuntimeException('Unable to read ' . RADIUS_PROXY_CONF);
}
$oldProxyConf = (string) file_get_contents(RADIUS_PROXY_CONF);
$newProxyConf = ensure_proxy_include($oldProxyConf);
if ($defaultRealmManaged) {
    $newProxyConf = remove_legacy_tanrc_block($newProxyConf);
}
$oldManaged = is_file(MANAGED_PROXY_FILE) ? (string) file_get_contents(MANAGED_PROXY_FILE) : null;

backup_once(RADIUS_PROXY_CONF);
backup_once(MANAGED_PROXY_FILE);

try {
    atomic_write(MANAGED_PROXY_FILE, $content, 0640);
    @chgrp(MANAGED_PROXY_FILE, 'radiusd');
    if ($newProxyConf !== $oldProxyConf) {
        atomic_write(RADIUS_PROXY_CONF, $newProxyConf, 0640);
        @chgrp(RADIUS_PROXY_CONF, 'radiusd');
    }

    $radiusd = command_path(['/usr/sbin/radiusd', '/usr/sbin/freeradius']);
    run_command('FreeRADIUS config check', [$radiusd, '-XC'], true, 45);
    $systemctl = command_path(['/usr/bin/systemctl', '/bin/systemctl']);
    run_command('Restart FreeRADIUS', [$systemctl, 'restart', 'radiusd'], true, 60);
    $pdo->exec('UPDATE radius_proxy_groups SET last_synced_at = NOW() WHERE enabled = 1');
} catch (Throwable $e) {
    atomic_write(RADIUS_PROXY_CONF, $oldProxyConf, 0640);
    if ($oldManaged !== null) {
        atomic_write(MANAGED_PROXY_FILE, $oldManaged, 0640);
    } else {
        atomic_write(MANAGED_PROXY_FILE, "# Generated by NCUT eduroam portal.\n# Sync failed and no previous managed proxy file existed.\n", 0640);
    }
    @chgrp(RADIUS_PROXY_CONF, 'radiusd');
    @chgrp(MANAGED_PROXY_FILE, 'radiusd');
    throw $e;
}

echo 'active_proxy_groups=' . count($groups) . PHP_EOL;
echo 'radiusd_restarted=1' . PHP_EOL;
