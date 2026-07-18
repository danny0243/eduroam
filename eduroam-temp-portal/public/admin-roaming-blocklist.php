<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

function parse_block_until(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Asia/Taipei'));
        if (!$dt instanceof DateTimeImmutable) {
            continue;
        }
        if ($format === 'Y-m-d') {
            $dt = $dt->setTime(23, 59, 59);
        }
        if ($dt <= new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'))) {
            throw new RuntimeException('封鎖到期時間必須晚於現在。');
        }
        return $dt->format('Y-m-d H:i:s');
    }
    throw new RuntimeException('封鎖到期時間格式不正確。');
}

function roaming_sync_error_message(string $output): string
{
    $output = trim($output);
    if ($output === '') {
        return '外校封鎖同步失敗：helper 沒有回傳錯誤內容，請檢查 Web Server 與 sudoers 設定。';
    }
    if (stripos($output, 'sudo') !== false && preg_match('/password|required|not allowed|no tty/i', $output)) {
        return '外校封鎖同步失敗：sudo 權限尚未設定完成，請確認 /etc/sudoers.d/ncut-eduroam-roaming-blocklist。';
    }
    $firstLine = strtok($output, "\r\n");
    return '外校封鎖同步失敗：' . mb_substr((string) $firstLine, 0, 300);
}

function sync_roaming_blocklist_to_radius(): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('外校封鎖同步失敗：PHP proc_open 未啟用。');
    }
    $helper = '/var/www/eduroam-portal/bin/sync-radius-roaming-blocklist.php';
    if (!is_readable($helper)) {
        throw new RuntimeException('外校封鎖同步失敗：找不到 sync-radius-roaming-blocklist.php。');
    }
    $cmd = '/usr/bin/sudo -n /usr/bin/php ' . escapeshellarg($helper);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('外校封鎖同步失敗：無法啟動 sudo helper。');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(roaming_sync_error_message(trim(($stderr ?: '') . "\n" . ($stdout ?: ''))));
    }
}

function add_roaming_block(PDO $pdo, array $admin): void
{
    $labels = roaming_block_type_labels();
    $type = (string) ($_POST['block_type'] ?? '');
    if (!array_key_exists($type, $labels)) {
        throw new RuntimeException('未知的封鎖類型。');
    }
    $value = normalize_roaming_block_value($type, (string) ($_POST['block_value'] ?? ''));
    validate_roaming_block_value($type, $value);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        throw new RuntimeException('請輸入封鎖原因。');
    }
    $blockedUntil = parse_block_until((string) ($_POST['blocked_until'] ?? ''));

    $stmt = $pdo->prepare(
        'INSERT INTO radius_roaming_blocklist
            (block_type, block_value, reason, enabled, blocked_until, created_by, disabled_by, disabled_at, created_at, updated_at)
         VALUES (?, ?, ?, 1, ?, ?, NULL, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            reason = VALUES(reason),
            enabled = 1,
            blocked_until = VALUES(blocked_until),
            created_by = VALUES(created_by),
            disabled_by = NULL,
            disabled_at = NULL,
            updated_at = NOW()'
    );
    $stmt->execute([$type, $value, mb_substr($reason, 0, 500), $blockedUntil, $admin['username']]);
    audit($pdo, (int) $admin['id'], 'roaming_block_add', null, $labels[$type] . ' ' . $value);
}

function update_roaming_block_enabled(PDO $pdo, array $admin, bool $enabled): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = roaming_block_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到封鎖項目。');
    }
    if ($enabled) {
        $stmt = $pdo->prepare('UPDATE radius_roaming_blocklist SET enabled = 1, disabled_by = NULL, disabled_at = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        audit($pdo, (int) $admin['id'], 'roaming_block_enable', null, $row['block_type'] . ' ' . $row['block_value']);
    } else {
        $stmt = $pdo->prepare('UPDATE radius_roaming_blocklist SET enabled = 0, disabled_by = ?, disabled_at = NOW(), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$admin['username'], $id]);
        audit($pdo, (int) $admin['id'], 'roaming_block_disable', null, $row['block_type'] . ' ' . $row['block_value']);
    }
}

function delete_roaming_block(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = roaming_block_by_id($pdo, $id);
    if (!$row) {
        throw new RuntimeException('找不到封鎖項目。');
    }
    $stmt = $pdo->prepare('DELETE FROM radius_roaming_blocklist WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'roaming_block_delete', null, $row['block_type'] . ' ' . $row['block_value']);
}

function quick_until(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))
        ->modify('+24 hours')
        ->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'add_block' => add_roaming_block($pdo, $admin),
            'enable_block' => update_roaming_block_enabled($pdo, $admin, true),
            'disable_block' => update_roaming_block_enabled($pdo, $admin, false),
            'delete_block' => delete_roaming_block($pdo, $admin),
            'sync_blocks' => null,
            default => throw new RuntimeException('未知的操作。'),
        };

        sync_roaming_blocklist_to_radius();
        flash('success', '外校封鎖清單已更新並套用到 FreeRADIUS。');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin-roaming-blocklist.php');
}

$blocks = roaming_blocklist($pdo, true);
$summaryHours = 24;
$summary = roaming_recent_summary($pdo, $summaryHours, 50);
$activeBlockCount = roaming_active_block_count($pdo);
$tanrc24h = auth_attempt_count($pdo, 'tanrc', 24);
$quickUntil = quick_until();
$typeLabels = roaming_block_type_labels();

render_header('外校封鎖管理 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>外校 / TANRC 異常帳號管理</h1>
        <p>管理 NCUT 本地端封鎖清單。外校帳號密碼仍由原學校管理，本系統只控制該帳號是否可在本校 eduroam 使用。</p>
    </div>
    <div class="stats">
        <span><strong><?= $activeBlockCount ?></strong> 啟用封鎖</span>
        <span><strong><?= $tanrc24h ?></strong> 外校認證 24h</span>
    </div>
</section>

<nav class="tabbar auth-tabbar" aria-label="認證與封鎖功能">
    <a href="/admin-auth-logs.php?type=local">認證紀錄</a>
    <a href="/admin-online-users.php">線上帳號</a>
    <a href="/admin-usage-analytics.php">用量分析</a>
    <a class="active" href="/admin-roaming-blocklist.php">外校封鎖管理</a>
</nav>

<section class="notice">
    <div>
        <strong>管理邊界</strong>
        <p class="muted small">這裡只會阻擋外校帳號在 NCUT eduroam 的連線，不會也不能修改外校使用者的帳號密碼。若疑似帳密外洩，請保留認證紀錄後通報 TANRC 或該帳號所屬學校。</p>
    </div>
</section>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>新增封鎖項目</h2>
            <p class="muted small">封鎖完整帳號影響最小；封鎖 realm 會影響該學校所有使用者，請只在明確異常時使用。</p>
        </div>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_blocks">
            <button type="submit" class="secondary">重新套用 FreeRADIUS</button>
        </form>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_block">
        <label>
            <span>封鎖類型</span>
            <select name="block_type" required>
                <option value="username">完整外校帳號</option>
                <option value="realm">外校 realm</option>
                <option value="calling_station_id">Calling-Station-Id / MAC</option>
            </select>
        </label>
        <label>
            <span>封鎖值</span>
            <input name="block_value" required maxlength="190" placeholder="user@example.edu.tw 或 example.edu.tw">
        </label>
        <label>
            <span>封鎖到期時間</span>
            <input type="datetime-local" name="blocked_until">
            <small class="muted">空白代表永久封鎖；建議先設定期限。</small>
        </label>
        <label class="wide">
            <span>原因</span>
            <textarea name="reason" required rows="3" maxlength="500" placeholder="例如：短時間大量 Access-Reject、疑似帳密外洩、TANRC 通報事件編號"></textarea>
        </label>
        <div class="actions wide">
            <button type="submit" class="primary">新增並套用</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>最近 <?= $summaryHours ?> 小時外校認證摘要</h2>
            <p class="muted small">可從這裡快速封鎖單一外校帳號 24 小時。若要封鎖整個 realm，請先確認不會誤傷正常使用者。</p>
        </div>
    </div>
    <?php if (!$summary): ?>
        <p class="muted">目前沒有 TANRC 外校認證紀錄。</p>
    <?php else: ?>
        <div class="table-wrap auth-log-table">
            <table>
                <thead>
                <tr>
                    <th>帳號</th>
                    <th>realm</th>
                    <th>Accept</th>
                    <th>Reject</th>
                    <th>最近時間</th>
                    <th>狀態</th>
                    <th>處置</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($summary as $item): ?>
                    <?php $blocked = (int) $item['active_block_count'] > 0; ?>
                    <tr>
                        <td><code><?= e($item['username']) ?></code></td>
                        <td><code><?= e($item['realm']) ?></code></td>
                        <td><?= (int) $item['accept_count'] ?></td>
                        <td><?= (int) $item['reject_count'] ?></td>
                        <td><?= e($item['last_authdate']) ?></td>
                        <td>
                            <?php if ($blocked): ?>
                                <span class="badge rejected">已封鎖</span>
                            <?php else: ?>
                                <span class="badge approved">未封鎖</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$blocked): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('確定封鎖此完整外校帳號 24 小時？');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_block">
                                    <input type="hidden" name="block_type" value="username">
                                    <input type="hidden" name="block_value" value="<?= e($item['username']) ?>">
                                    <input type="hidden" name="blocked_until" value="<?= e($quickUntil) ?>">
                                    <input type="hidden" name="reason" value="由最近外校認證摘要快速封鎖 24 小時">
                                    <button type="submit" class="secondary">封鎖 24h</button>
                                </form>
                            <?php else: ?>
                                <span class="muted small">已在清單</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>封鎖清單</h2>
            <p class="muted small">停用或刪除後會重新產生 FreeRADIUS 封鎖檔並重啟 radiusd。</p>
        </div>
    </div>
    <?php if (!$blocks): ?>
        <p class="muted">目前沒有封鎖項目。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>類型</th>
                    <th>封鎖值</th>
                    <th>狀態</th>
                    <th>到期</th>
                    <th>原因</th>
                    <th>建立者</th>
                    <th>最近同步</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td><?= e($typeLabels[$block['block_type']] ?? $block['block_type']) ?></td>
                        <td><code><?= e($block['block_value']) ?></code></td>
                        <td>
                            <?php if ($block['runtime_status'] === 'active'): ?>
                                <span class="badge rejected">啟用</span>
                            <?php elseif ($block['runtime_status'] === 'expired'): ?>
                                <span class="badge expired">已到期</span>
                            <?php else: ?>
                                <span class="badge disabled">已停用</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $block['blocked_until'] ? e($block['blocked_until']) : '永久' ?></td>
                        <td><?= e($block['reason']) ?></td>
                        <td><?= e($block['created_by']) ?></td>
                        <td><?= $block['last_synced_at'] ? e($block['last_synced_at']) : '-' ?></td>
                        <td class="manage-cell">
                            <div class="inline-actions">
                                <?php if ((int) $block['enabled'] === 1): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="disable_block">
                                        <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                        <button type="submit" class="secondary">停用</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="enable_block">
                                        <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                        <button type="submit" class="secondary">啟用</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('確定刪除此封鎖項目？');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_block">
                                    <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                    <button type="submit" class="danger">刪除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
