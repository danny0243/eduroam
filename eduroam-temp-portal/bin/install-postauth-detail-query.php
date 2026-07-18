<?php
declare(strict_types=1);

const QUERIES_CONF = '/etc/raddb/mods-config/sql/main/mysql/queries.conf';

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
    $backup = $target . '.pre-auth-log-detail';
    if (!file_exists($backup) && !copy($target, $backup)) {
        throw new RuntimeException('Unable to create backup: ' . $backup);
    }
}

function detailed_postauth_query(): string
{
    return <<<'CONF'
query =	"\
		INSERT INTO ${..postauth_table} \
			(username, pass, reply, authdate, nasipaddress, nasidentifier, nasportid, calledstationid, callingstationid, packet_src_ipaddress ${..class.column_name}) \
		VALUES ( \
			'%{SQL-User-Name}', \
			'%{%{User-Password}:-%{Chap-Password}}', \
			'%{reply:Packet-Type}', \
			'%S.%M', \
			'%{%{NAS-IP-Address}:-}', \
			'%{%{NAS-Identifier}:-}', \
			'%{%{NAS-Port-Id}:-}', \
			'%{%{Called-Station-Id}:-}', \
			'%{%{Calling-Station-Id}:-}', \
			'%{%{Packet-Src-IP-Address}:-}' \
			${..class.reply_xlat})"
CONF;
}

function install_query(string $content): string
{
    $pattern = '/(^post-auth\s*\{\s*.*?)(^\s*query\s*=\s*"(?:\\\\.|[^"])*")/ms';
    $count = 0;
    $newContent = preg_replace_callback(
        $pattern,
        static function (array $matches) use (&$count): string {
            $count++;
            return $matches[1] . detailed_postauth_query();
        },
        $content,
        1
    );
    if ($newContent === null || $count !== 1) {
        throw new RuntimeException('Unable to locate post-auth query in ' . QUERIES_CONF);
    }
    return $newContent;
}

require_root();

if (!is_readable(QUERIES_CONF)) {
    throw new RuntimeException('Unable to read ' . QUERIES_CONF);
}

$old = (string) file_get_contents(QUERIES_CONF);
$new = install_query($old);
if ($new === $old) {
    echo "postauth_detail_query=already_ok\n";
    exit(0);
}

$stat = stat(QUERIES_CONF);
$mode = $stat ? ($stat['mode'] & 0777) : 0640;
backup_once(QUERIES_CONF);

try {
    atomic_write(QUERIES_CONF, $new, $mode);
    @chgrp(QUERIES_CONF, 'radiusd');
    $radiusd = command_path(['/usr/sbin/radiusd', '/usr/sbin/freeradius']);
    run_command('FreeRADIUS config check', [$radiusd, '-XC'], true, 45);
    $systemctl = command_path(['/usr/bin/systemctl', '/bin/systemctl']);
    run_command('Restart FreeRADIUS', [$systemctl, 'restart', 'radiusd'], true, 60);
} catch (Throwable $e) {
    atomic_write(QUERIES_CONF, $old, $mode);
    @chgrp(QUERIES_CONF, 'radiusd');
    throw $e;
}

echo "postauth_detail_query=updated\n";
echo "radiusd_restarted=1\n";
