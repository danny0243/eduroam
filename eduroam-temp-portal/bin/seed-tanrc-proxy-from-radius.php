<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

const RADIUS_PROXY_CONF = '/etc/raddb/proxy.conf';
const TANRC_POOL_NAME = 'TANRC_POOL';
const TANRC_REALM = 'DEFAULT';

function require_root(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new RuntimeException('This helper must run as root.');
    }
}

function extract_tanrc_block(string $proxyConf): string
{
    if (!preg_match('/# BEGIN NCUT TANRC PROXY\b(.*?)# END NCUT TANRC PROXY/s', $proxyConf, $matches)) {
        throw new RuntimeException('TANRC legacy block not found in ' . RADIUS_PROXY_CONF);
    }
    return (string) $matches[1];
}

function radius_directive(string $block, string $key): string
{
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/mi';
    if (!preg_match($pattern, $block, $matches)) {
        return '';
    }
    $value = trim((string) $matches[1]);
    if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
        || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
        $value = substr($value, 1, -1);
    }
    return $value;
}

function parse_home_servers(string $block): array
{
    preg_match_all('/home_server\s+([A-Za-z][A-Za-z0-9_.:-]*)\s*\{(.*?)\}/s', $block, $matches, PREG_SET_ORDER);
    $servers = [];
    foreach ($matches as $match) {
        $name = (string) $match[1];
        $body = (string) $match[2];
        $port = (int) (radius_directive($body, 'port') ?: '1812');
        $secret = radius_directive($body, 'secret');
        if ($secret === '') {
            throw new RuntimeException('Missing shared secret for ' . $name);
        }
        $servers[$name] = [
            'name' => $name,
            'host' => radius_directive($body, 'ipaddr') ?: radius_directive($body, 'ipv6addr'),
            'auth_port' => $port,
            'acct_port' => $port + 1,
            'secret' => $secret,
            'response_window' => (int) (radius_directive($body, 'response_window') ?: '20'),
            'zombie_period' => (int) (radius_directive($body, 'zombie_period') ?: '40'),
            'revive_interval' => (int) (radius_directive($body, 'revive_interval') ?: '120'),
            'status_check' => radius_directive($body, 'status_check') ?: 'status-server',
        ];
    }
    return $servers;
}

function tanrc_pool_members(string $block): array
{
    if (!preg_match('/home_server_pool\s+' . TANRC_POOL_NAME . '\s*\{(.*?)\}/s', $block, $matches)) {
        throw new RuntimeException(TANRC_POOL_NAME . ' not found in legacy block.');
    }
    preg_match_all('/^\s*home_server\s*=\s*([A-Za-z][A-Za-z0-9_.:-]*)\s*$/mi', (string) $matches[1], $servers);
    return array_values(array_unique($servers[1] ?? []));
}

function upsert_tanrc_group(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM radius_proxy_groups WHERE UPPER(realm) = 'DEFAULT' LIMIT 1");
    $stmt->execute();
    $id = (int) ($stmt->fetchColumn() ?: 0);
    $note = 'TANRC / eduroam 外校預設轉送 pool；管理 realm DEFAULT。';
    if ($id > 0) {
        $update = $pdo->prepare(
            'UPDATE radius_proxy_groups
             SET name = ?, realm = ?, enabled = 1, pool_type = ?, nostrip = 1, note = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([TANRC_POOL_NAME, TANRC_REALM, 'fail-over', $note, $id]);
        return $id;
    }

    $insert = $pdo->prepare(
        'INSERT INTO radius_proxy_groups
            (name, realm, enabled, pool_type, nostrip, note, created_by, created_at, updated_at)
         VALUES (?, ?, 1, ?, 1, ?, ?, NOW(), NOW())'
    );
    $insert->execute([TANRC_POOL_NAME, TANRC_REALM, 'fail-over', $note, 'system']);
    return (int) $pdo->lastInsertId();
}

function upsert_tanrc_server(PDO $pdo, int $groupId, array $server): void
{
    if ((string) $server['host'] === '') {
        throw new RuntimeException('Missing host for ' . (string) $server['name']);
    }
    $stmt = $pdo->prepare('SELECT id FROM radius_proxy_servers WHERE group_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$groupId, $server['name']]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    $payload = [
        $server['name'],
        $server['host'],
        $server['auth_port'],
        $server['acct_port'],
        encrypt_secret((string) $server['secret']),
        1,
        $server['response_window'],
        $server['zombie_period'],
        $server['revive_interval'],
        in_array($server['status_check'], ['none', 'status-server'], true) ? $server['status_check'] : 'status-server',
    ];
    if ($id > 0) {
        $update = $pdo->prepare(
            'UPDATE radius_proxy_servers
             SET name = ?, server_host = ?, auth_port = ?, acct_port = ?, shared_secret = ?, enabled = ?,
                 response_window = ?, zombie_period = ?, revive_interval = ?, status_check = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([...$payload, $id]);
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO radius_proxy_servers
            (group_id, name, server_host, auth_port, acct_port, shared_secret, enabled,
             response_window, zombie_period, revive_interval, status_check, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $insert->execute([$groupId, ...$payload]);
}

require_root();

if (!is_readable(RADIUS_PROXY_CONF)) {
    throw new RuntimeException('Unable to read ' . RADIUS_PROXY_CONF);
}

$pdo = db();
$proxyConf = (string) file_get_contents(RADIUS_PROXY_CONF);
try {
    $block = extract_tanrc_block($proxyConf);
} catch (RuntimeException $e) {
    if (radius_proxy_default_group_exists($pdo)) {
        echo 'tanrc_proxy_already_managed=1' . PHP_EOL;
        exit(0);
    }
    throw $e;
}
$servers = parse_home_servers($block);
$members = tanrc_pool_members($block);
if (!$members) {
    throw new RuntimeException(TANRC_POOL_NAME . ' has no home_server members.');
}

$pdo->beginTransaction();
try {
    $groupId = upsert_tanrc_group($pdo);
    $count = 0;
    foreach ($members as $member) {
        if (!isset($servers[$member])) {
            throw new RuntimeException('Pool member has no home_server block: ' . $member);
        }
        upsert_tanrc_server($pdo, $groupId, $servers[$member]);
        $count++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo 'tanrc_proxy_group_id=' . $groupId . PHP_EOL;
echo 'tanrc_proxy_servers=' . $count . PHP_EOL;
