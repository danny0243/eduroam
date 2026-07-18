<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

$requestSummary = dashboard_request_summary($pdo);
$authSummary = dashboard_auth_outcome_summary($pdo, 24);
$authSourceCounts = [
    'local' => auth_attempt_count($pdo, 'local', 24),
    'tanrc' => auth_attempt_count($pdo, 'tanrc', 24),
    'no_realm' => auth_attempt_count($pdo, 'no_realm', 24),
];
$onlineSummary = online_radius_summary($pdo);
$usage24 = radius_usage_summary($pdo, 'all', 1);
$usage30 = radius_usage_summary($pdo, 'all', 30);
$activeBlocks = roaming_active_block_count($pdo);
$memory = dashboard_memory_usage();
$cpu = dashboard_cpu_load();
$disks = dashboard_disk_usage();
$services = dashboard_service_status();
$recentRequests = dashboard_recent_requests($pdo, 8);
$onlineSessions = online_radius_sessions($pdo, 'all', '', 10);
$updatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d H:i:s');

$maxDisk = null;
foreach ($disks as $disk) {
    $maxDisk = max((float) ($maxDisk ?? 0), (float) $disk['used_percent']);
}
$diskLevel = dashboard_level($maxDisk);
$overallLevel = dashboard_level(max(
    (float) ($cpu['load_percent'] ?? 0),
    (float) ($memory['used_percent'] ?? 0),
    (float) ($maxDisk ?? 0)
));
$acceptRate = $authSummary['total_count'] > 0
    ? dashboard_percent($authSummary['accept_count'] * 100 / $authSummary['total_count'])
    : null;

function dashboard_level_badge(string $level): string
{
    return match ($level) {
        'ok' => 'approved',
        'warning' => 'expired',
        'critical' => 'rejected',
        default => 'info',
    };
}

function dashboard_level_text(string $level): string
{
    return match ($level) {
        'ok' => '正常',
        'warning' => '注意',
        'critical' => '警示',
        default => '未知',
    };
}

function dashboard_tone(string $level): string
{
    return match ($level) {
        'ok' => 'tone-success',
        'warning' => 'tone-warning',
        'critical' => 'tone-danger',
        default => 'tone-info',
    };
}

function dashboard_service_text(string $status): string
{
    return match ($status) {
        'active' => 'running',
        'inactive' => 'stopped',
        'failed' => 'failed',
        'activating' => 'starting',
        'deactivating' => 'stopping',
        default => $status,
    };
}

function dashboard_request_status_text(string $status): string
{
    return match ($status) {
        'pending' => '待審',
        'approved' => '已開通',
        'disabled' => '停用',
        'rejected' => '已退回',
        'deleted' => '已刪除',
        default => $status,
    };
}

function dashboard_percent_text(?float $percent): string
{
    return $percent === null ? '-' : number_format($percent, 1) . '%';
}

function dashboard_percent_width(?float $percent): string
{
    return (string) max(0, min(100, (float) ($percent ?? 0)));
}

render_header('Dashboard - ' . APP_NAME, true);
?>
<div class="mui-dashboard">
    <section class="mui-hero">
        <div>
            <div class="mui-eyebrow">
                <span class="mui-symbol" aria-hidden="true">dashboard</span>
                Material UI Dashboard
            </div>
            <h1>NCUT eduroam 控制台</h1>
            <p>集中查看 RADIUS 認證、臨時帳號、外校封鎖、線上 Session 與伺服器硬體資源狀態。</p>
        </div>
        <div class="mui-hero-meta">
            <span class="mui-chip <?= e(dashboard_tone($overallLevel)) ?>"><?= e(dashboard_level_text($overallLevel)) ?></span>
            <span>更新 <?= e($updatedAt) ?></span>
            <span><?= e($admin['display_name']) ?></span>
        </div>
    </section>

    <section class="mui-kpi-grid" aria-label="系統摘要">
        <a class="mui-kpi-card tone-primary" href="/admin.php?view=queue">
            <span class="mui-kpi-icon mui-symbol" aria-hidden="true">pending_actions</span>
            <span class="mui-kpi-label">待審申請</span>
            <strong><?= $requestSummary['pending_requests'] ?></strong>
            <small>展延待審 <?= $requestSummary['pending_extensions'] ?> 筆</small>
        </a>
        <a class="mui-kpi-card tone-info" href="/admin-online-users.php">
            <span class="mui-kpi-icon mui-symbol" aria-hidden="true">wifi_tethering</span>
            <span class="mui-kpi-label">線上 Session</span>
            <strong><?= $onlineSummary['total_count'] ?></strong>
            <small>本校 <?= $onlineSummary['local_count'] ?>，TANRC <?= $onlineSummary['tanrc_count'] ?></small>
        </a>
        <a class="mui-kpi-card <?= e($authSummary['reject_count'] > $authSummary['accept_count'] ? 'tone-warning' : 'tone-success') ?>" href="/admin-auth-logs.php">
            <span class="mui-kpi-icon mui-symbol" aria-hidden="true">verified_user</span>
            <span class="mui-kpi-label">24h 認證</span>
            <strong><?= $authSummary['total_count'] ?></strong>
            <small>Accept <?= $authSummary['accept_count'] ?>，Reject <?= $authSummary['reject_count'] ?></small>
        </a>
        <a class="mui-kpi-card tone-secondary" href="/admin-usage-analytics.php">
            <span class="mui-kpi-icon mui-symbol" aria-hidden="true">data_usage</span>
            <span class="mui-kpi-label">30 天用量</span>
            <strong><?= e(human_bytes($usage30['total_octets'])) ?></strong>
            <small><?= $usage30['user_count'] ?> 位使用者，<?= $usage30['session_count'] ?> 次 Session</small>
        </a>
    </section>

    <section class="mui-paper">
        <div class="mui-section-head">
            <div class="mui-section-title">
                <span class="mui-section-icon mui-symbol" aria-hidden="true">memory</span>
                <div>
                    <h2>硬體資源監測</h2>
                    <p>CPU、記憶體與重要目錄所在磁碟使用率。80% 以上列為注意，90% 以上列為警示。</p>
                </div>
            </div>
        </div>

        <div class="mui-resource-grid">
            <article class="mui-resource-card <?= e(dashboard_tone((string) $cpu['level'])) ?>">
                <div class="mui-card-topline">
                    <span>CPU Load</span>
                    <span class="mui-chip <?= e(dashboard_tone((string) $cpu['level'])) ?>"><?= e(dashboard_level_text((string) $cpu['level'])) ?></span>
                </div>
                <div class="mui-resource-value">
                    <?= e((string) $cpu['load1']) ?>
                    <small>/ <?= (int) $cpu['cpu_count'] ?> cores</small>
                </div>
                <div class="mui-linear-progress <?= e(dashboard_tone((string) $cpu['level'])) ?>" aria-hidden="true">
                    <span style="width: <?= e(dashboard_percent_width((float) $cpu['load_percent'])) ?>%"></span>
                </div>
                <dl class="mui-kv">
                    <dt>1 分鐘</dt><dd><?= e((string) $cpu['load1']) ?></dd>
                    <dt>5 分鐘</dt><dd><?= e((string) $cpu['load5']) ?></dd>
                    <dt>15 分鐘</dt><dd><?= e((string) $cpu['load15']) ?></dd>
                </dl>
            </article>

            <article class="mui-resource-card <?= e(dashboard_tone((string) $memory['level'])) ?>">
                <div class="mui-card-topline">
                    <span>Memory</span>
                    <span class="mui-chip <?= e(dashboard_tone((string) $memory['level'])) ?>"><?= e(dashboard_level_text((string) $memory['level'])) ?></span>
                </div>
                <div class="mui-resource-value">
                    <?= e(dashboard_percent_text($memory['used_percent'])) ?>
                    <small>used</small>
                </div>
                <div class="mui-linear-progress <?= e(dashboard_tone((string) $memory['level'])) ?>" aria-hidden="true">
                    <span style="width: <?= e(dashboard_percent_width($memory['used_percent'])) ?>%"></span>
                </div>
                <dl class="mui-kv">
                    <dt>已用</dt><dd><?= e(human_bytes((int) $memory['used_bytes'])) ?></dd>
                    <dt>可用</dt><dd><?= e(human_bytes((int) $memory['available_bytes'])) ?></dd>
                    <dt>總量</dt><dd><?= e(human_bytes((int) $memory['total_bytes'])) ?></dd>
                </dl>
            </article>

            <article class="mui-resource-card <?= e(dashboard_tone($diskLevel)) ?>">
                <div class="mui-card-topline">
                    <span>Disk</span>
                    <span class="mui-chip <?= e(dashboard_tone($diskLevel)) ?>"><?= e(dashboard_level_text($diskLevel)) ?></span>
                </div>
                <div class="mui-resource-value">
                    <?= e(dashboard_percent_text($maxDisk)) ?>
                    <small>max used</small>
                </div>
                <div class="mui-linear-progress <?= e(dashboard_tone($diskLevel)) ?>" aria-hidden="true">
                    <span style="width: <?= e(dashboard_percent_width($maxDisk)) ?>%"></span>
                </div>
                <dl class="mui-kv">
                    <dt>監測路徑</dt><dd><?= count($disks) ?> 個</dd>
                    <dt>最高使用率</dt><dd><?= e(dashboard_percent_text($maxDisk)) ?></dd>
                    <dt>狀態</dt><dd><?= e(dashboard_level_text($diskLevel)) ?></dd>
                </dl>
            </article>
        </div>

        <?php if (!$disks): ?>
            <p class="muted">目前無法讀取硬碟用量資料。</p>
        <?php else: ?>
            <div class="mui-table-paper">
                <table>
                    <thead>
                    <tr>
                        <th>路徑</th>
                        <th>狀態</th>
                        <th>使用率</th>
                        <th>已用</th>
                        <th>可用</th>
                        <th>總量</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($disks as $disk): ?>
                        <tr>
                            <td><code><?= e($disk['path']) ?></code></td>
                            <td><span class="mui-chip <?= e(dashboard_tone((string) $disk['level'])) ?>"><?= e(dashboard_level_text((string) $disk['level'])) ?></span></td>
                            <td>
                                <strong><?= e(dashboard_percent_text((float) $disk['used_percent'])) ?></strong>
                                <div class="mui-linear-progress slim <?= e(dashboard_tone((string) $disk['level'])) ?>" aria-hidden="true">
                                    <span style="width: <?= e(dashboard_percent_width((float) $disk['used_percent'])) ?>%"></span>
                                </div>
                            </td>
                            <td><?= e(human_bytes((int) $disk['used_bytes'])) ?></td>
                            <td><?= e(human_bytes((int) $disk['free_bytes'])) ?></td>
                            <td><?= e(human_bytes((int) $disk['total_bytes'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="mui-grid-2">
        <section class="mui-paper">
            <div class="mui-section-head">
                <div class="mui-section-title">
                    <span class="mui-section-icon mui-symbol" aria-hidden="true">dns</span>
                    <div>
                        <h2>服務狀態</h2>
                        <p>Web、PHP、RADIUS 與 MariaDB 目前執行狀態。</p>
                    </div>
                </div>
            </div>
            <div class="mui-service-grid">
                <?php foreach ($services as $service): ?>
                    <div class="mui-service-card <?= e(dashboard_tone((string) $service['level'])) ?>">
                        <strong><?= e($service['name']) ?></strong>
                        <span><?= e(dashboard_service_text((string) $service['status'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mui-paper">
            <div class="mui-section-head">
                <div class="mui-section-title">
                    <span class="mui-section-icon mui-symbol" aria-hidden="true">security</span>
                    <div>
                        <h2>認證與安全</h2>
                        <p>24 小時內認證來源統計與目前外校封鎖數。</p>
                    </div>
                </div>
            </div>
            <div class="mui-mini-grid">
                <a href="/admin-auth-logs.php?type=local"><strong><?= $authSourceCounts['local'] ?></strong><span>本機 / 本校</span></a>
                <a href="/admin-auth-logs.php?type=tanrc"><strong><?= $authSourceCounts['tanrc'] ?></strong><span>TANRC 外校</span></a>
                <a href="/admin-auth-logs.php?type=no_realm"><strong><?= $authSourceCounts['no_realm'] ?></strong><span>無 realm</span></a>
                <a href="/admin-roaming-blocklist.php"><strong><?= $activeBlocks ?></strong><span>外校封鎖</span></a>
            </div>
            <div class="mui-accept-rate">
                <span>24h Access-Accept 比例</span>
                <strong><?= e(dashboard_percent_text($acceptRate)) ?></strong>
                <div class="mui-linear-progress tone-success" aria-hidden="true">
                    <span style="width: <?= e(dashboard_percent_width($acceptRate)) ?>%"></span>
                </div>
            </div>
        </section>
    </section>

    <section class="mui-grid-2">
        <section class="mui-paper">
            <div class="mui-section-head">
                <div class="mui-section-title">
                    <span class="mui-section-icon mui-symbol" aria-hidden="true">groups</span>
                    <div>
                        <h2>臨時帳號狀態</h2>
                        <p>帳號申請、展延、停用與快到期數量。</p>
                    </div>
                </div>
            </div>
            <div class="mui-mini-grid">
                <a href="/admin.php?view=accounts"><strong><?= $requestSummary['approved_accounts'] ?></strong><span>已開通</span></a>
                <a href="/admin.php?view=accounts"><strong><?= $requestSummary['disabled_accounts'] ?></strong><span>停用</span></a>
                <a href="/admin.php?view=accounts"><strong><?= $requestSummary['expiring_soon'] ?></strong><span>7 天內到期</span></a>
                <a href="/admin.php?view=accounts"><strong><?= $requestSummary['expired_accounts'] ?></strong><span>已逾期</span></a>
            </div>
        </section>

        <section class="mui-paper">
            <div class="mui-section-head">
                <div class="mui-section-title">
                    <span class="mui-section-icon mui-symbol" aria-hidden="true">query_stats</span>
                    <div>
                        <h2>用量概況</h2>
                        <p>Accounting 資料彙整，方便快速判斷近期使用量。</p>
                    </div>
                </div>
            </div>
            <div class="mui-mini-grid">
                <a href="/admin-usage-analytics.php?days=1"><strong><?= e(human_bytes($usage24['total_octets'])) ?></strong><span>24h 流量</span></a>
                <a href="/admin-usage-analytics.php?days=1"><strong><?= $usage24['user_count'] ?></strong><span>24h 使用者</span></a>
                <a href="/admin-usage-analytics.php?days=30"><strong><?= e(human_duration($usage30['total_seconds'])) ?></strong><span>30 天連線時間</span></a>
                <a href="/admin-online-users.php"><strong><?= $onlineSummary['stale_count'] ?></strong><span>逾 30 分未更新</span></a>
            </div>
        </section>
    </section>

    <section class="mui-paper">
        <div class="mui-section-head">
            <div class="mui-section-title">
                <span class="mui-section-icon mui-symbol" aria-hidden="true">manage_accounts</span>
                <div>
                    <h2>最近帳號動態</h2>
                    <p>顯示最近更新的臨時帳號申請，不包含密碼。</p>
                </div>
            </div>
            <a class="mui-button" href="/admin.php?view=accounts">帳號管理</a>
        </div>
        <?php if (!$recentRequests): ?>
            <p class="muted">目前沒有帳號申請資料。</p>
        <?php else: ?>
            <div class="mui-table-paper">
                <table>
                    <thead>
                    <tr>
                        <th>申請編號</th>
                        <th>申請人</th>
                        <th>Email</th>
                        <th>帳號</th>
                        <th>狀態</th>
                        <th>期限</th>
                        <th>更新時間</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentRequests as $item): ?>
                        <?php
                        $account = (string) ($item['radius_username'] ?: $item['requested_username'] ?: '-');
                        $start = (string) ($item['starts_at'] ?: $item['desired_start'] ?: '-');
                        $end = (string) ($item['expires_at'] ?: $item['desired_end'] ?: '永久有效');
                        ?>
                        <tr>
                            <td><code><?= e($item['request_code']) ?></code></td>
                            <td><?= e($item['applicant_name']) ?></td>
                            <td><?= e($item['applicant_email']) ?></td>
                            <td><code><?= e($account) ?></code></td>
                            <td><span class="badge <?= e((string) $item['status']) ?>"><?= e(dashboard_request_status_text((string) $item['status'])) ?></span></td>
                            <td><?= e($start . ' 到 ' . $end) ?></td>
                            <td><?= e($item['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="mui-paper">
        <div class="mui-section-head">
            <div class="mui-section-title">
                <span class="mui-section-icon mui-symbol" aria-hidden="true">router</span>
                <div>
                    <h2>線上帳號快照</h2>
                    <p>最近更新的線上 accounting session，可進一步查看認證明細。</p>
                </div>
            </div>
            <a class="mui-button" href="/admin-online-users.php">線上帳號</a>
        </div>
        <?php if (!$onlineSessions): ?>
            <p class="muted">目前沒有線上 Session。</p>
        <?php else: ?>
            <div class="mui-table-paper">
                <table>
                    <thead>
                    <tr>
                        <th>帳號</th>
                        <th>來源</th>
                        <th>MAC</th>
                        <th>NAS IP</th>
                        <th>Client IP</th>
                        <th>使用時間</th>
                        <th>流量</th>
                        <th>明細</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($onlineSessions, 0, 8) as $session): ?>
                        <?php $authId = (int) ($session['latest_auth_id'] ?? 0); ?>
                        <tr>
                            <td><code><?= e($session['username'] ?? '') ?></code></td>
                            <td><?= e($session['source_label'] ?? '') ?></td>
                            <td><?= $session['callingstationid'] ? '<code>' . e(normalize_calling_station_id((string) $session['callingstationid'])) . '</code>' : '<span class="muted">-</span>' ?></td>
                            <td><?= $session['nasipaddress'] ? '<code>' . e($session['nasipaddress']) . '</code>' : '<span class="muted">-</span>' ?></td>
                            <td><?= $session['framedipaddress'] ? '<code>' . e($session['framedipaddress']) . '</code>' : '<span class="muted">-</span>' ?></td>
                            <td><?= e(human_duration((int) ($session['live_seconds'] ?? 0))) ?></td>
                            <td><?= e(human_bytes((int) ($session['total_octets'] ?? 0))) ?></td>
                            <td>
                                <?php if ($authId > 0): ?>
                                    <a class="mui-button small" href="<?= e('/admin-auth-log-detail.php?' . http_build_query(['id' => $authId])) ?>">查看</a>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
