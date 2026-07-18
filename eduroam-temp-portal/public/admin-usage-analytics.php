<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

$sources = [
    'all' => '全部來源',
    'local' => '本校 / 本機',
    'tanrc' => 'TANRC 外校',
    'no_realm' => '未帶 realm',
];
$orders = [
    'traffic' => '流量最多',
    'time' => '連線時間最長',
    'sessions' => '連線次數最多',
    'last_seen' => '最近使用',
];
$dayOptions = [1, 7, 30, 90, 180, 365];

$source = (string) ($_GET['source'] ?? 'all');
if (!array_key_exists($source, $sources)) {
    $source = 'all';
}
$order = (string) ($_GET['order'] ?? 'traffic');
if (!array_key_exists($order, $orders)) {
    $order = 'traffic';
}
$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, $dayOptions, true)) {
    $days = 30;
}
$limit = (int) ($_GET['limit'] ?? 50);
$limit = max(25, min($limit, 300));
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 120) {
    $search = mb_substr($search, 0, 120);
}

$summary = radius_usage_summary($pdo, $source, $days);
$users = radius_usage_top_users($pdo, $source, $days, $order, $search, $limit);
$realms = radius_usage_by_realm($pdo, $source, $days, 30);
$maxOctets = 0;
foreach ($users as $row) {
    $maxOctets = max($maxOctets, (int) ($row['total_octets'] ?? 0));
}

render_header('使用者用量分析 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>使用者用量分析</h1>
        <p>依 RADIUS accounting 彙整帳號使用狀況，協助辨識異常流量、過多裝置、跨 NAS/AP 使用等行為。</p>
    </div>
    <div class="stats">
        <span><strong><?= $summary['user_count'] ?></strong> 使用者</span>
        <span><strong><?= $summary['session_count'] ?></strong> 連線 session</span>
        <span><strong><?= e(human_bytes($summary['total_octets'])) ?></strong> 總流量</span>
        <span><strong><?= e(human_duration($summary['total_seconds'])) ?></strong> 連線時間</span>
    </div>
</section>

<nav class="tabbar auth-tabbar" aria-label="認證與安全功能">
    <a href="/admin-auth-logs.php?type=local">認證紀錄</a>
    <a href="/admin-online-users.php">線上帳號</a>
    <a class="active" href="/admin-usage-analytics.php">用量分析</a>
    <a href="/admin-roaming-blocklist.php">外校封鎖管理</a>
</nav>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>分析條件</h2>
            <p class="muted small">統計資料來自 radacct；若某台控制器未送 accounting，該設備流量不會出現在這裡。</p>
        </div>
        <form method="get" class="inline-filter">
            <label>
                <span>期間</span>
                <select name="days">
                    <?php foreach ($dayOptions as $option): ?>
                        <option value="<?= $option ?>" <?= $days === $option ? 'selected' : '' ?>><?= $option ?> 天</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>來源</span>
                <select name="source">
                    <?php foreach ($sources as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $source === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>排序</span>
                <select name="order">
                    <?php foreach ($orders as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $order === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>搜尋</span>
                <input name="q" value="<?= e($search) ?>" placeholder="帳號、MAC、NAS、AP、IP">
            </label>
            <label>
                <span>顯示筆數</span>
                <select name="limit">
                    <?php foreach ([50, 100, 200, 300] as $option): ?>
                        <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">查詢</button>
        </form>
    </div>
</section>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>帳號用量排行</h2>
            <p class="muted small">可用 MAC/NAS/AP 數量搭配認證詳細資料，判斷是否有帳密共用或異常漫遊。</p>
        </div>
    </div>

    <?php if (!$users): ?>
        <p class="muted">目前沒有符合條件的 accounting 用量資料。</p>
    <?php else: ?>
        <div class="table-wrap auth-log-table usage-analysis-table">
            <table>
                <thead>
                <tr>
                    <th>帳號</th>
                    <th>來源</th>
                    <th>狀態</th>
                    <th>連線數</th>
                    <th>流量</th>
                    <th>連線時間</th>
                    <th>MAC / NAS / AP / IP</th>
                    <th>首次出現</th>
                    <th>最後出現</th>
                    <th>詳細</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                    $octets = (int) ($row['total_octets'] ?? 0);
                    $percent = $maxOctets > 0 ? max(3, (int) round($octets * 100 / $maxOctets)) : 0;
                    $authId = (int) ($row['latest_auth_id'] ?? 0);
                    ?>
                    <tr>
                        <td><code><?= e($row['username'] ?? '') ?></code></td>
                        <td><?= e($row['source_label'] ?? '') ?></td>
                        <td><?= ((int) ($row['online_now'] ?? 0) === 1) ? '<span class="badge approved">線上</span>' : '<span class="badge info">離線</span>' ?></td>
                        <td><?= (int) ($row['session_count'] ?? 0) ?></td>
                        <td>
                            <strong><?= e(human_bytes($octets)) ?></strong>
                            <div class="usage-meter" aria-hidden="true"><span style="width: <?= $percent ?>%"></span></div>
                        </td>
                        <td><?= e(human_duration((int) ($row['total_seconds'] ?? 0))) ?></td>
                        <td>
                            <span class="nowrap"><?= (int) ($row['mac_count'] ?? 0) ?> / <?= (int) ($row['nas_count'] ?? 0) ?> / <?= (int) ($row['ap_count'] ?? 0) ?> / <?= (int) ($row['framed_ip_count'] ?? 0) ?></span>
                        </td>
                        <td><?= e($row['first_seen'] ?? '') ?></td>
                        <td><?= e($row['last_seen'] ?? '') ?></td>
                        <td>
                            <?php if ($authId > 0): ?>
                                <a class="button-link secondary" href="<?= e('/admin-auth-log-detail.php?' . http_build_query(['id' => $authId])) ?>">查看</a>
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

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>Realm / 網域彙總</h2>
            <p class="muted small">快速比較本校、TANRC 外校與未帶 realm 帳號在指定期間的使用量。</p>
        </div>
    </div>

    <?php if (!$realms): ?>
        <p class="muted">目前沒有 realm 彙總資料。</p>
    <?php else: ?>
        <div class="table-wrap mini-table">
            <table>
                <thead>
                <tr>
                    <th>Realm</th>
                    <th>使用者</th>
                    <th>連線數</th>
                    <th>流量</th>
                    <th>連線時間</th>
                    <th>最後出現</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($realms as $realm): ?>
                    <tr>
                        <td><code><?= e($realm['realm'] ?? '') ?></code></td>
                        <td><?= (int) ($realm['user_count'] ?? 0) ?></td>
                        <td><?= (int) ($realm['session_count'] ?? 0) ?></td>
                        <td><?= e(human_bytes((int) ($realm['total_octets'] ?? 0))) ?></td>
                        <td><?= e(human_duration((int) ($realm['total_seconds'] ?? 0))) ?></td>
                        <td><?= e($realm['last_seen'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
