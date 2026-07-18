<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

function auth_detail_sync_roaming_blocklist_to_radius(): void
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
        $output = trim(($stderr ?: '') . "\n" . ($stdout ?: ''));
        $firstLine = strtok($output, "\r\n");
        throw new RuntimeException('外校封鎖同步失敗：' . mb_substr((string) $firstLine, 0, 300));
    }
}

function auth_detail_block_until(string $duration): ?string
{
    if ($duration === 'permanent') {
        return null;
    }
    $hours = match ($duration) {
        '24h' => 24,
        '7d' => 168,
        default => throw new RuntimeException('封鎖期限不正確。'),
    };
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))
        ->modify('+' . $hours . ' hours')
        ->format('Y-m-d H:i:s');
}

function auth_detail_add_roaming_block(PDO $pdo, array $admin): void
{
    $labels = roaming_block_type_labels();
    $type = (string) ($_POST['block_type'] ?? '');
    if (!array_key_exists($type, $labels)) {
        throw new RuntimeException('未知的封鎖類型。');
    }

    $value = normalize_roaming_block_value($type, (string) ($_POST['block_value'] ?? ''));
    validate_roaming_block_value($type, $value);
    $duration = (string) ($_POST['duration'] ?? '24h');
    $blockedUntil = auth_detail_block_until($duration);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = '由認證紀錄詳情頁快速封鎖';
    }

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
    audit($pdo, (int) $admin['id'], 'auth_detail_roaming_block_add', null, $labels[$type] . ' ' . $value);
}

function auth_detail_seconds(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . '天 ' . $hours . '小時';
    }
    if ($hours > 0) {
        return $hours . '小時 ' . $minutes . '分';
    }
    return $minutes . '分';
}

function auth_detail_value(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '<span class="muted">-</span>';
    }
    return '<code>' . e($value) . '</code>';
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? 'local');
if (!in_array($type, ['local', 'tanrc', 'no_realm'], true)) {
    $type = 'local';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if ((string) ($_POST['action'] ?? '') !== 'add_roaming_block') {
            throw new RuntimeException('未知的操作。');
        }
        auth_detail_add_roaming_block($pdo, $admin);
        auth_detail_sync_roaming_blocklist_to_radius();
        flash('success', '封鎖項目已加入並套用到 FreeRADIUS。');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin-auth-log-detail.php?' . http_build_query(['id' => $id, 'type' => $type]));
}

$attempt = $id > 0 ? auth_attempt_by_id($pdo, $id) : null;
if (!$attempt) {
    flash('error', '找不到指定的認證紀錄。');
    redirect('/admin-auth-logs.php?' . http_build_query(['type' => $type]));
}

$username = (string) $attempt['username'];
$realm = username_realm($username);
$callingStationId = normalize_calling_station_id((string) ($attempt['callingstationid'] ?? ''));
$stats24 = auth_attempt_stats($pdo, $username, 24);
$stats7 = auth_attempt_stats($pdo, $username, 168);
$acctSummary = auth_accounting_summary($pdo, $username, 30);
$recentAttempts = auth_attempts_for_username($pdo, $username, 25);
$recentSessions = auth_accounting_sessions($pdo, $username, 25);
$macValues = auth_distinct_accounting_values($pdo, $username, 'callingstationid', 30, 10);
$nasValues = auth_distinct_accounting_values($pdo, $username, 'nasipaddress', 30, 10);
$calledValues = auth_distinct_accounting_values($pdo, $username, 'calledstationid', 30, 10);
$ipValues = auth_distinct_accounting_values($pdo, $username, 'framedipaddress', 30, 10);
$activeBlocks = roaming_active_blocks_for_identity($pdo, $username, $callingStationId);
$signals = auth_risk_signals($attempt, $stats24, $stats7, $acctSummary, $activeBlocks);
$isRoamingUser = $realm !== '' && !is_ncut_username($username);
$backUrl = '/admin-auth-logs.php?' . http_build_query(['type' => $type]);

render_header('認證紀錄詳情 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>認證紀錄詳情</h1>
        <p>檢視單一帳號的 IP、MAC、NAS/AP、成功失敗趨勢與封鎖狀態，協助判斷是否異常。</p>
    </div>
    <div class="stats">
        <span><strong><?= (int) $stats24['reject_count'] ?></strong> Reject 24h</span>
        <span><strong><?= (int) $acctSummary['mac_count'] ?></strong> MAC 30d</span>
        <span><strong><?= count($activeBlocks) ?></strong> 啟用封鎖</span>
    </div>
</section>

<nav class="tabbar auth-tabbar" aria-label="認證紀錄導覽">
    <a href="<?= e($backUrl) ?>">返回列表</a>
    <a href="/admin-roaming-blocklist.php">外校封鎖管理</a>
</nav>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2><code><?= e($username) ?></code></h2>
            <p class="muted small"><?= e($attempt['source_label']) ?><?= $realm !== '' ? '，realm：' . e($realm) : '' ?></p>
        </div>
        <span class="badge <?= $attempt['reply'] === 'Access-Accept' ? 'approved' : 'rejected' ?>"><?= e($attempt['reply']) ?></span>
    </div>

    <div class="detail-grid">
        <div>
            <h3 class="subsection-title">此筆認證</h3>
            <dl class="kv-grid">
                <dt>時間</dt><dd><?= e($attempt['authdate']) ?></dd>
                <dt>NAS IP</dt><dd><?= auth_detail_value($attempt['nasipaddress'] ?? '') ?></dd>
                <dt>NAS Identifier</dt><dd><?= auth_detail_value($attempt['nasidentifier'] ?? '') ?></dd>
                <dt>NAS Port ID</dt><dd><?= auth_detail_value($attempt['nasportid'] ?? '') ?></dd>
                <dt>Called-Station-Id / AP</dt><dd><?= auth_detail_value($attempt['calledstationid'] ?? '') ?></dd>
                <dt>Calling-Station-Id / MAC</dt><dd><?= auth_detail_value($callingStationId) ?></dd>
                <dt>Packet Source IP</dt><dd><?= auth_detail_value($attempt['packet_src_ipaddress'] ?? '') ?></dd>
                <dt>Class</dt><dd><?= auth_detail_value($attempt['class'] ?? '') ?></dd>
            </dl>
        </div>
        <div>
            <h3 class="subsection-title">異常提示</h3>
            <ul class="signal-list">
                <?php foreach ($signals as $signal): ?>
                    <li class="signal <?= e($signal['level']) ?>"><?= e($signal['text']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($activeBlocks): ?>
                <div class="mini-table">
                    <table>
                        <thead><tr><th>封鎖類型</th><th>封鎖值</th><th>到期</th></tr></thead>
                        <tbody>
                        <?php foreach ($activeBlocks as $block): ?>
                            <tr>
                                <td><?= e($block['block_type']) ?></td>
                                <td><code><?= e($block['block_value']) ?></code></td>
                                <td><?= $block['blocked_until'] ? e($block['blocked_until']) : '永久' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($isRoamingUser): ?>
<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>快速封鎖</h2>
            <p class="muted small">建議優先封鎖完整帳號；封鎖 realm 會影響該學校所有使用者，請只在明確異常時使用。</p>
        </div>
    </div>
    <div class="quick-block-grid">
        <?php
        $blockForms = [
            ['type' => 'username', 'value' => $username, 'label' => '封鎖完整帳號', 'danger' => false],
            ['type' => 'realm', 'value' => $realm, 'label' => '封鎖整個 realm', 'danger' => true],
        ];
        if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $callingStationId)) {
            $blockForms[] = ['type' => 'calling_station_id', 'value' => $callingStationId, 'label' => '封鎖此 MAC', 'danger' => false];
        }
        ?>
        <?php foreach ($blockForms as $form): ?>
            <form method="post" class="block-card" onsubmit="return confirm('確定新增此封鎖項目並套用到 FreeRADIUS？');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_roaming_block">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="block_type" value="<?= e($form['type']) ?>">
                <input type="hidden" name="block_value" value="<?= e($form['value']) ?>">
                <strong><?= e($form['label']) ?></strong>
                <code><?= e($form['value']) ?></code>
                <label>
                    <span>期限</span>
                    <select name="duration">
                        <option value="24h">24 小時</option>
                        <option value="7d">7 天</option>
                        <option value="permanent">永久</option>
                    </select>
                </label>
                <label>
                    <span>原因</span>
                    <input name="reason" maxlength="500" value="由認證紀錄詳情判定異常後封鎖">
                </label>
                <button type="submit" class="<?= $form['danger'] ? 'danger' : 'secondary' ?>"><?= e($form['label']) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>帳號活動摘要</h2>
            <p class="muted small">舊紀錄若沒有 post-auth IP/MAC，會搭配同帳號最近 accounting 資料輔助判斷。</p>
        </div>
    </div>
    <div class="stats detail-stats">
        <span><strong><?= (int) $stats24['total_count'] ?></strong> 認證 24h</span>
        <span><strong><?= (int) $stats24['accept_count'] ?></strong> Accept 24h</span>
        <span><strong><?= (int) $stats24['reject_count'] ?></strong> Reject 24h</span>
        <span><strong><?= (int) $stats7['reject_count'] ?></strong> Reject 7d</span>
        <span><strong><?= (int) $acctSummary['session_count'] ?></strong> Sessions 30d</span>
        <span><strong><?= auth_detail_seconds((int) $acctSummary['total_session_seconds']) ?></strong> 使用時間 30d</span>
    </div>
    <div class="detail-grid">
        <div>
            <h3 class="subsection-title">最近使用者 MAC</h3>
            <?php if (!$macValues): ?><p class="muted">無 accounting MAC 資料。</p><?php else: ?>
                <ul class="compact-list">
                    <?php foreach ($macValues as $row): ?><li><code><?= e(normalize_calling_station_id((string) $row['value'])) ?></code><span><?= (int) $row['seen_count'] ?> 次，<?= e($row['last_seen']) ?></span></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="subsection-title">最近 NAS IP</h3>
            <?php if (!$nasValues): ?><p class="muted">無 NAS IP 資料。</p><?php else: ?>
                <ul class="compact-list">
                    <?php foreach ($nasValues as $row): ?><li><code><?= e($row['value']) ?></code><span><?= (int) $row['seen_count'] ?> 次，<?= e($row['last_seen']) ?></span></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="subsection-title">最近 Called-Station / AP</h3>
            <?php if (!$calledValues): ?><p class="muted">無 AP 資料。</p><?php else: ?>
                <ul class="compact-list">
                    <?php foreach ($calledValues as $row): ?><li><code><?= e($row['value']) ?></code><span><?= (int) $row['seen_count'] ?> 次，<?= e($row['last_seen']) ?></span></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="subsection-title">最近取得 IP</h3>
            <?php if (!$ipValues): ?><p class="muted">無 Framed IP 資料。</p><?php else: ?>
                <ul class="compact-list">
                    <?php foreach ($ipValues as $row): ?><li><code><?= e($row['value']) ?></code><span><?= (int) $row['seen_count'] ?> 次，<?= e($row['last_seen']) ?></span></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="panel">
    <h2>最近認證紀錄</h2>
    <div class="table-wrap auth-log-table">
        <table>
            <thead>
            <tr>
                <th>時間</th>
                <th>結果</th>
                <th>NAS IP</th>
                <th>使用者 MAC</th>
                <th>AP / Called-Station</th>
                <th>Packet Source</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentAttempts as $row): ?>
                <tr>
                    <td><?= e($row['authdate']) ?></td>
                    <td><span class="badge <?= $row['reply'] === 'Access-Accept' ? 'approved' : 'rejected' ?>"><?= e($row['reply']) ?></span></td>
                    <td><?= auth_detail_value($row['nasipaddress'] ?? '') ?></td>
                    <td><?= auth_detail_value(normalize_calling_station_id((string) ($row['callingstationid'] ?? ''))) ?></td>
                    <td><?= auth_detail_value($row['calledstationid'] ?? '') ?></td>
                    <td><?= auth_detail_value($row['packet_src_ipaddress'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>最近 Accounting Sessions</h2>
    <?php if (!$recentSessions): ?>
        <p class="muted">目前沒有此帳號的 accounting session。</p>
    <?php else: ?>
        <div class="table-wrap auth-log-table">
            <table>
                <thead>
                <tr>
                    <th>開始</th>
                    <th>最近更新 / 結束</th>
                    <th>NAS IP</th>
                    <th>使用者 MAC</th>
                    <th>AP / Called-Station</th>
                    <th>取得 IP</th>
                    <th>連線時間</th>
                    <th>結束原因</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSessions as $row): ?>
                    <tr>
                        <td><?= e($row['acctstarttime'] ?? '-') ?></td>
                        <td><?= e($row['acctstoptime'] ?: ($row['acctupdatetime'] ?? '-')) ?></td>
                        <td><?= auth_detail_value($row['nasipaddress'] ?? '') ?></td>
                        <td><?= auth_detail_value(normalize_calling_station_id((string) ($row['callingstationid'] ?? ''))) ?></td>
                        <td><?= auth_detail_value($row['calledstationid'] ?? '') ?></td>
                        <td><?= auth_detail_value($row['framedipaddress'] ?: ($row['framedipv6address'] ?? '')) ?></td>
                        <td><?= auth_detail_seconds((int) ($row['acctsessiontime'] ?? 0)) ?></td>
                        <td><?= e($row['acctterminatecause'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
