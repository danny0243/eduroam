<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

function radius_proxy_sync_failure(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return 'RADIUS Proxy 套用失敗：helper 沒有回傳錯誤內容，請檢查 Web Server 與 sudoers 設定。';
    }
    if (stripos($output, 'sudo') !== false && preg_match('/password|required|not allowed|no tty/i', $output)) {
        return 'RADIUS Proxy 套用失敗：sudo 權限尚未設定完成，請確認 /etc/sudoers.d/ncut-eduroam-radius-proxy。';
    }
    $safe = preg_replace('/(secret|password|passwd)[=: ]+\S+/i', '$1=[redacted]', $output) ?? $output;
    $firstLine = strtok($safe, "\r\n");
    return 'RADIUS Proxy 套用失敗：' . mb_substr((string) $firstLine, 0, 500);
}

function sync_radius_proxy_to_radius(): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('RADIUS Proxy 套用失敗：PHP proc_open 未啟用。');
    }
    $helper = '/var/www/eduroam-portal/bin/sync-radius-proxy.php';
    if (!is_readable($helper)) {
        throw new RuntimeException('RADIUS Proxy 套用失敗：找不到 sync-radius-proxy.php。');
    }
    $cmd = '/usr/bin/sudo -n /usr/bin/php ' . escapeshellarg($helper);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('RADIUS Proxy 套用失敗：無法啟動 sudo helper。');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(radius_proxy_sync_failure(trim(($stderr ?: '') . "\n" . ($stdout ?: ''))));
    }
}

function save_proxy_group(PDO $pdo, array $admin): int
{
    $id = (int) ($_POST['id'] ?? 0);
    $poolTypes = radius_proxy_pool_types();
    $name = trim((string) ($_POST['name'] ?? ''));
    $realm = normalize_proxy_realm((string) ($_POST['realm'] ?? ''));
    $poolType = (string) ($_POST['pool_type'] ?? 'fail-over');
    $enabled = !empty($_POST['enabled']);
    $nostrip = !empty($_POST['nostrip']);
    $note = trim((string) ($_POST['note'] ?? ''));
    $existing = $id > 0 ? radius_proxy_group_by_id($pdo, $id) : null;

    if (radius_proxy_is_default_realm($realm)) {
        $name = 'TANRC_POOL';
        $nostrip = true;
    }
    validate_proxy_name($name, 'Proxy 名稱');
    validate_proxy_realm($realm);
    if (!array_key_exists($poolType, $poolTypes)) {
        throw new RuntimeException('Proxy Pool 類型不正確。');
    }
    if ($existing && radius_proxy_is_default_realm((string) $existing['realm']) && !radius_proxy_is_default_realm($realm)) {
        throw new RuntimeException('TANRC_POOL 必須維持 realm DEFAULT，不能改成其他 realm。');
    }

    if ($id > 0) {
        if (!$existing) {
            throw new RuntimeException('找不到 Proxy 群組。');
        }
        $stmt = $pdo->prepare(
            'UPDATE radius_proxy_groups
             SET name = ?, realm = ?, enabled = ?, pool_type = ?, nostrip = ?, note = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$name, $realm, $enabled ? 1 : 0, $poolType, $nostrip ? 1 : 0, mb_substr($note, 0, 500), $id]);
        audit($pdo, (int) $admin['id'], 'radius_proxy_group_update', null, 'updated proxy realm ' . $realm);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO radius_proxy_groups
            (name, realm, enabled, pool_type, nostrip, note, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([$name, $realm, $enabled ? 1 : 0, $poolType, $nostrip ? 1 : 0, mb_substr($note, 0, 500), $admin['username']]);
    $newId = (int) $pdo->lastInsertId();
    audit($pdo, (int) $admin['id'], 'radius_proxy_group_add', null, 'added proxy realm ' . $realm);
    return $newId;
}

function toggle_proxy_group(PDO $pdo, array $admin): int
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = radius_proxy_group_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到 Proxy 群組。');
    }
    $newEnabled = (int) $row['enabled'] === 1 ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE radius_proxy_groups SET enabled = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newEnabled, $id]);
    audit($pdo, (int) $admin['id'], 'radius_proxy_group_toggle', null, ($newEnabled ? 'enabled ' : 'disabled ') . $row['realm']);
    return $id;
}

function delete_proxy_group(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = radius_proxy_group_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到 Proxy 群組。');
    }
    if (radius_proxy_is_default_realm((string) $row['realm'])) {
        throw new RuntimeException('TANRC_POOL 是外校 eduroam 全域轉送設定，請改用停用，不直接刪除。');
    }
    $stmt = $pdo->prepare('DELETE FROM radius_proxy_groups WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'radius_proxy_group_delete', null, 'deleted proxy realm ' . $row['realm']);
}

function save_proxy_server(PDO $pdo, array $admin): int
{
    $id = (int) ($_POST['id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $group = radius_proxy_group_by_id($pdo, $groupId);
    if (!$group) {
        throw new RuntimeException('找不到 Proxy 群組。');
    }
    $statusChecks = radius_proxy_status_checks();
    $name = trim((string) ($_POST['name'] ?? ''));
    $host = strtolower(trim((string) ($_POST['server_host'] ?? '')));
    $authPort = validate_proxy_port(trim((string) ($_POST['auth_port'] ?? '1812')), 'Authentication Port');
    $acctPort = validate_proxy_port(trim((string) ($_POST['acct_port'] ?? '1813')), 'Accounting Port');
    $secret = (string) ($_POST['shared_secret'] ?? '');
    $enabled = !empty($_POST['enabled']);
    $responseWindow = validate_proxy_timing(trim((string) ($_POST['response_window'] ?? '20')), 'Response Window', 5, 120);
    $zombiePeriod = validate_proxy_timing(trim((string) ($_POST['zombie_period'] ?? '40')), 'Zombie Period', 5, 600);
    $reviveInterval = validate_proxy_timing(trim((string) ($_POST['revive_interval'] ?? '120')), 'Revive Interval', 10, 3600);
    $statusCheck = (string) ($_POST['status_check'] ?? 'status-server');

    validate_proxy_name($name, 'Home Server 名稱');
    validate_proxy_host($host);
    if (radius_proxy_is_default_realm((string) $group['realm']) && $acctPort !== $authPort + 1) {
        throw new RuntimeException('TANRC_POOL 使用 auth+acct home_server，Accounting Port 必須等於 Authentication Port + 1。');
    }
    if (!array_key_exists($statusCheck, $statusChecks)) {
        throw new RuntimeException('Status Check 設定不正確。');
    }

    $existing = $id > 0 ? radius_proxy_server_by_id($pdo, $id) : null;
    if ($id > 0 && (!$existing || (int) $existing['group_id'] !== $groupId)) {
        throw new RuntimeException('找不到 Home Server。');
    }
    if ($secret === '' && !$existing) {
        throw new RuntimeException('新增 Home Server 時必須輸入 Shared Secret。');
    }
    $storedSecret = $existing ? (string) $existing['shared_secret'] : '';
    if ($secret !== '') {
        if (strlen($secret) < 4 || strlen($secret) > 255) {
            throw new RuntimeException('Shared Secret 長度需介於 4 到 255 字元。');
        }
        $storedSecret = encrypt_secret($secret);
    }

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE radius_proxy_servers
             SET name = ?, server_host = ?, auth_port = ?, acct_port = ?, shared_secret = ?, enabled = ?,
                 response_window = ?, zombie_period = ?, revive_interval = ?, status_check = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $name, $host, $authPort, $acctPort, $storedSecret, $enabled ? 1 : 0,
            $responseWindow, $zombiePeriod, $reviveInterval, $statusCheck, $id,
        ]);
        audit($pdo, (int) $admin['id'], 'radius_proxy_server_update', null, 'updated proxy server ' . $name . ' for ' . $group['realm']);
        return $groupId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO radius_proxy_servers
            (group_id, name, server_host, auth_port, acct_port, shared_secret, enabled,
             response_window, zombie_period, revive_interval, status_check, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $groupId, $name, $host, $authPort, $acctPort, $storedSecret, $enabled ? 1 : 0,
        $responseWindow, $zombiePeriod, $reviveInterval, $statusCheck,
    ]);
    audit($pdo, (int) $admin['id'], 'radius_proxy_server_add', null, 'added proxy server ' . $name . ' for ' . $group['realm']);
    return $groupId;
}

function toggle_proxy_server(PDO $pdo, array $admin): int
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = radius_proxy_server_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到 Home Server。');
    }
    $newEnabled = (int) $row['enabled'] === 1 ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE radius_proxy_servers SET enabled = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newEnabled, $id]);
    audit($pdo, (int) $admin['id'], 'radius_proxy_server_toggle', null, ($newEnabled ? 'enabled ' : 'disabled ') . $row['name']);
    return (int) $row['group_id'];
}

function delete_proxy_server(PDO $pdo, array $admin): int
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = radius_proxy_server_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到 Home Server。');
    }
    $stmt = $pdo->prepare('DELETE FROM radius_proxy_servers WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'radius_proxy_server_delete', null, 'deleted proxy server ' . $row['name']);
    return (int) $row['group_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectGroup = 0;
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $redirectGroup = match ($action) {
            'save_group' => save_proxy_group($pdo, $admin),
            'toggle_group' => toggle_proxy_group($pdo, $admin),
            'delete_group' => (delete_proxy_group($pdo, $admin) || true) ? 0 : 0,
            'save_server' => save_proxy_server($pdo, $admin),
            'toggle_server' => toggle_proxy_server($pdo, $admin),
            'delete_server' => delete_proxy_server($pdo, $admin),
            'sync_proxy' => (int) ($_POST['group_id'] ?? 0),
            default => throw new RuntimeException('未知的操作。'),
        };

        sync_radius_proxy_to_radius();
        flash('success', 'RADIUS Proxy 設定已儲存並套用到 FreeRADIUS。');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    $target = '/admin-radius-proxy.php';
    if ($redirectGroup > 0) {
        $target .= '#group-' . $redirectGroup;
    }
    redirect($target);
}

$groups = radius_proxy_groups($pdo);
$editGroupId = (int) ($_GET['edit'] ?? 0);
$editGroup = $editGroupId > 0 ? radius_proxy_group_by_id($pdo, $editGroupId) : null;
$poolTypes = radius_proxy_pool_types();
$statusChecks = radius_proxy_status_checks();

render_header('RADIUS Proxy 設定 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>FreeRADIUS Proxy 設定</h1>
        <p>管理 TANRC_POOL 與額外 realm 要轉送到哪些 FreeRADIUS Home Server。本校 realm 仍由本機認證處理。</p>
    </div>
    <div class="stats">
        <span><strong><?= count($groups) ?></strong> Proxy Realm</span>
        <span><strong><?= count(radius_proxy_active_config($pdo)) ?></strong> 已啟用且有伺服器</span>
    </div>
</section>

<section class="notice">
    <div>
        <strong>安全提醒</strong>
        <p class="muted small">Shared Secret 會加密存入資料庫；WebUI 不會回顯既有 secret。`DEFAULT` 代表 TANRC_POOL 全域外校 eduroam 轉送；`LOCAL` 與本校 `ncut.edu.tw` realm 不可在此頁改成 proxy。</p>
    </div>
</section>

<section class="panel" id="group-form">
    <div class="section-title-row">
        <div>
            <h2><?= $editGroup ? '編輯 Proxy Realm' : '新增 Proxy Realm' ?></h2>
            <p class="muted small">一般 realm 會產生獨立 pool；realm 填 `DEFAULT` 會管理固定的 TANRC_POOL。</p>
        </div>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_proxy">
            <button type="submit" class="secondary">重新套用 FreeRADIUS</button>
        </form>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_group">
        <input type="hidden" name="id" value="<?= (int) ($editGroup['id'] ?? 0) ?>">
        <label>
            <span>名稱</span>
            <input name="name" maxlength="80" required value="<?= e($editGroup['name'] ?? '') ?>" placeholder="例如：TANRC_POOL 或合作學校 Proxy">
        </label>
        <label>
            <span>Realm</span>
            <input name="realm" maxlength="190" required value="<?= e($editGroup['realm'] ?? '') ?>" placeholder="DEFAULT 或 example.edu.tw">
        </label>
        <label>
            <span>Pool 類型</span>
            <select name="pool_type">
                <?php foreach ($poolTypes as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= (($editGroup['pool_type'] ?? 'fail-over') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="enabled" value="1" <?= (!$editGroup || (int) $editGroup['enabled'] === 1) ? 'checked' : '' ?>>
            啟用此 Proxy Realm
        </label>
        <label class="inline-check">
            <input type="checkbox" name="nostrip" value="1" <?= (!$editGroup || (int) $editGroup['nostrip'] === 1) ? 'checked' : '' ?>>
            保留完整 username@realm
        </label>
        <label class="wide">
            <span>備註</span>
            <textarea name="note" rows="2" maxlength="500" placeholder="用途、聯絡窗口、異動單號"><?= e($editGroup['note'] ?? '') ?></textarea>
        </label>
        <div class="actions wide">
            <?php if ($editGroup): ?>
                <a class="button-link secondary" href="/admin-radius-proxy.php">取消編輯</a>
            <?php endif; ?>
            <button type="submit" class="primary"><?= $editGroup ? '儲存 Proxy Realm' : '新增 Proxy Realm' ?></button>
        </div>
    </form>
</section>

<?php if (!$groups): ?>
    <section class="panel">
        <p class="muted">目前尚未建立額外 Proxy Realm。</p>
    </section>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
    <?php $servers = radius_proxy_servers($pdo, (int) $group['id']); ?>
    <?php $isDefaultProxy = radius_proxy_is_default_realm((string) $group['realm']); ?>
    <section class="panel" id="group-<?= (int) $group['id'] ?>">
        <div class="section-title-row">
            <div>
                <h2>
                    <?= e($group['name']) ?> <code><?= e($group['realm']) ?></code>
                    <?php if ($isDefaultProxy): ?><span class="badge info">TANRC_POOL</span><?php endif; ?>
                </h2>
                <p class="muted small">
                    <?= e($poolTypes[$group['pool_type']] ?? $group['pool_type']) ?>，
                    <?= $isDefaultProxy ? '外校預設轉送' : ((int) $group['nostrip'] === 1 ? '保留完整帳號' : '送出時移除 realm') ?>，
                    <?= (int) $group['enabled'] === 1 ? '已啟用' : '已停用' ?>。
                    <?= $group['last_synced_at'] ? '最近同步：' . e($group['last_synced_at']) : '尚未同步。' ?>
                </p>
            </div>
            <div class="inline-actions">
                <a class="button-link secondary" href="/admin-radius-proxy.php?edit=<?= (int) $group['id'] ?>#group-form">編輯 Realm</a>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_group">
                    <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                    <button type="submit" class="secondary"><?= (int) $group['enabled'] === 1 ? '停用' : '啟用' ?></button>
                </form>
                <?php if (!$isDefaultProxy): ?>
                    <form method="post" onsubmit="return confirm('確定刪除此 Proxy Realm 與其所有 Home Server？');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                        <button type="submit" class="danger">刪除</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($group['note'] !== ''): ?>
            <p class="muted small"><?= e($group['note']) ?></p>
        <?php endif; ?>
        <?php if ($isDefaultProxy): ?>
            <p class="muted small">此群組會產生 <code>home_server_pool TANRC_POOL</code> 與 <code>realm DEFAULT</code>。新增或修改 TANRC home server 後會立即重新檢查 FreeRADIUS 設定並重啟服務。</p>
        <?php endif; ?>

        <h3 class="subsection-title">Home Server</h3>
        <?php if (!$servers): ?>
            <p class="muted">此 realm 尚未新增 Home Server，啟用後仍不會產生 proxy 設定。</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>名稱</th>
                        <th>Host</th>
                        <th>Auth / Acct Port</th>
                        <th>Status Check</th>
                        <th>時間參數</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><?= e($server['name']) ?></td>
                            <td><code><?= e($server['server_host']) ?></code></td>
                            <td><?= (int) $server['auth_port'] ?> / <?= (int) $server['acct_port'] ?></td>
                            <td><?= e($statusChecks[$server['status_check']] ?? $server['status_check']) ?></td>
                            <td>
                                response <?= (int) $server['response_window'] ?>s<br>
                                zombie <?= (int) $server['zombie_period'] ?>s<br>
                                revive <?= (int) $server['revive_interval'] ?>s
                            </td>
                            <td>
                                <span class="badge <?= (int) $server['enabled'] === 1 ? 'approved' : 'disabled' ?>">
                                    <?= (int) $server['enabled'] === 1 ? '啟用' : '停用' ?>
                                </span>
                            </td>
                            <td class="manage-cell">
                                <details class="manage-menu">
                                    <summary>管理</summary>
                                    <div class="manage-actions">
                                        <form method="post" class="action-group">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="save_server">
                                            <input type="hidden" name="id" value="<?= (int) $server['id'] ?>">
                                            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                            <label><span>名稱</span><input name="name" value="<?= e($server['name']) ?>" required maxlength="80"></label>
                                            <label><span>Host</span><input name="server_host" value="<?= e($server['server_host']) ?>" required maxlength="190"></label>
                                            <label><span>Auth Port</span><input name="auth_port" value="<?= (int) $server['auth_port'] ?>" required></label>
                                            <label><span>Acct Port</span><input name="acct_port" value="<?= (int) $server['acct_port'] ?>" required></label>
                                            <label><span>Shared Secret</span><input type="password" name="shared_secret" placeholder="空白則保留原 secret" autocomplete="new-password"></label>
                                            <label><span>Response Window</span><input name="response_window" value="<?= (int) $server['response_window'] ?>" required></label>
                                            <label><span>Zombie Period</span><input name="zombie_period" value="<?= (int) $server['zombie_period'] ?>" required></label>
                                            <label><span>Revive Interval</span><input name="revive_interval" value="<?= (int) $server['revive_interval'] ?>" required></label>
                                            <label><span>Status Check</span>
                                                <select name="status_check">
                                                    <?php foreach ($statusChecks as $value => $label): ?>
                                                        <option value="<?= e($value) ?>" <?= $server['status_check'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="inline-check"><input type="checkbox" name="enabled" value="1" <?= (int) $server['enabled'] === 1 ? 'checked' : '' ?>> 啟用</label>
                                            <button type="submit" class="primary">儲存 Server</button>
                                        </form>
                                        <div class="inline-actions danger-zone">
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_server">
                                                <input type="hidden" name="id" value="<?= (int) $server['id'] ?>">
                                                <button type="submit" class="secondary"><?= (int) $server['enabled'] === 1 ? '停用' : '啟用' ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('確定刪除此 Home Server？');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_server">
                                                <input type="hidden" name="id" value="<?= (int) $server['id'] ?>">
                                                <button type="submit" class="danger">刪除</button>
                                            </form>
                                        </div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <details class="collapsible-panel proxy-server-form">
            <summary class="panel-summary">
                <span>新增 Home Server</span>
                <small>為 <?= $isDefaultProxy ? 'TANRC_POOL' : e($group['realm']) ?> 增加一台 FreeRADIUS proxy 目標</small>
            </summary>
            <div class="panel-body">
                <form method="post" class="form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_server">
                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                    <label><span>名稱</span><input name="name" required maxlength="80" placeholder="Primary / Secondary"></label>
                    <label><span>Host / IP</span><input name="server_host" required maxlength="190" placeholder="140.128.x.x 或 radius.example.edu.tw"></label>
                    <label><span>Authentication Port</span><input name="auth_port" value="1812" required></label>
                    <label><span>Accounting Port</span><input name="acct_port" value="1813" required></label>
                    <label class="wide"><span>Shared Secret</span><input type="password" name="shared_secret" required autocomplete="new-password"></label>
                    <label><span>Response Window</span><input name="response_window" value="20" required></label>
                    <label><span>Zombie Period</span><input name="zombie_period" value="40" required></label>
                    <label><span>Revive Interval</span><input name="revive_interval" value="120" required></label>
                    <label><span>Status Check</span>
                        <select name="status_check">
                            <?php foreach ($statusChecks as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="inline-check"><input type="checkbox" name="enabled" value="1" checked> 啟用此 Home Server</label>
                    <div class="actions wide">
                        <button type="submit" class="primary">新增並套用</button>
                    </div>
                </form>
            </div>
        </details>
    </section>
<?php endforeach; ?>

<?php render_footer(); ?>
