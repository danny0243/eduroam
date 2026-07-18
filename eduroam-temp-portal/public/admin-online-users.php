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

$source = (string) ($_GET['source'] ?? 'all');
if (!array_key_exists($source, $sources)) {
    $source = 'all';
}

$limit = (int) ($_GET['limit'] ?? 100);
$limit = max(25, min($limit, 300));
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 120) {
    $search = mb_substr($search, 0, 120);
}

$summary = online_radius_summary($pdo);
$sessions = online_radius_sessions($pdo, $source, $search, $limit);

render_header('線上帳號 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>線上即時帳號資訊</h1>
        <p>依 FreeRADIUS accounting 紀錄顯示目前尚未收到 Stop 的連線，用來檢查帳號、MAC、NAS/AP 與用量狀態。</p>
    </div>
    <div class="stats">
        <span><strong><?= $summary['total_count'] ?></strong> 線上 session</span>
        <span><strong><?= $summary['local_count'] ?></strong> 本校 / 本機</span>
        <span><strong><?= $summary['tanrc_count'] ?></strong> TANRC 外校</span>
        <span><strong><?= $summary['stale_count'] ?></strong> 超過 30 分未更新</span>
    </div>
</section>

<nav class="tabbar auth-tabbar" aria-label="認證與安全功能">
    <a href="/admin-auth-logs.php?type=local">認證紀錄</a>
    <a class="active" href="/admin-online-users.php">線上帳號</a>
    <a href="/admin-usage-analytics.php">用量分析</a>
    <a href="/admin-roaming-blocklist.php">外校封鎖管理</a>
</nav>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>目前線上連線</h2>
            <p class="muted small">若 NAS 沒有送出 Accounting-Stop，紀錄可能會停留在線上；超過 30 分鐘未更新會標示為待確認。</p>
        </div>
        <form method="get" class="inline-filter">
            <label>
                <span>來源</span>
                <select name="source">
                    <?php foreach ($sources as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $source === $key ? 'selected' : '' ?>><?= e($label) ?></option>
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

    <?php if (!$sessions): ?>
        <p class="muted">目前沒有符合條件的線上 accounting session。</p>
    <?php else: ?>
        <div class="table-wrap auth-log-table online-session-table">
            <table>
                <thead>
                <tr>
                    <th>狀態</th>
                    <th>帳號</th>
                    <th>來源</th>
                    <th>最近認證</th>
                    <th>使用者 MAC</th>
                    <th>NAS IP</th>
                    <th>AP / Called-Station</th>
                    <th>取得 IP</th>
                    <th>開始時間</th>
                    <th>最近更新</th>
                    <th>連線時間</th>
                    <th>流量</th>
                    <th>詳細</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                    <?php
                    $idleMinutes = (int) ($session['idle_minutes'] ?? 0);
                    $isStale = $idleMinutes > 30;
                    $authId = (int) ($session['latest_auth_id'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <span class="badge <?= $isStale ? 'expired' : 'approved' ?>"><?= $isStale ? '待確認' : '線上' ?></span>
                            <small class="muted nowrap"><?= $idleMinutes ?> 分未更新</small>
                        </td>
                        <td><code><?= e($session['username'] ?? '') ?></code></td>
                        <td><?= e($session['source_label'] ?? '') ?></td>
                        <td><?= $session['latest_reply'] ? '<span class="badge ' . ($session['latest_reply'] === 'Access-Accept' ? 'approved' : 'rejected') . '">' . e($session['latest_reply']) . '</span>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $session['callingstationid'] ? '<code>' . e(normalize_calling_station_id((string) $session['callingstationid'])) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $session['nasipaddress'] ? '<code>' . e($session['nasipaddress']) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $session['calledstationid'] ? '<code>' . e($session['calledstationid']) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $session['framedipaddress'] ? '<code>' . e($session['framedipaddress']) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= e($session['acctstarttime'] ?? '') ?></td>
                        <td><?= $session['acctupdatetime'] ? e($session['acctupdatetime']) : '<span class="muted">-</span>' ?></td>
                        <td><?= e(human_duration((int) ($session['live_seconds'] ?? 0))) ?></td>
                        <td><?= e(human_bytes((int) ($session['total_octets'] ?? 0))) ?></td>
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
<?php render_footer(); ?>
