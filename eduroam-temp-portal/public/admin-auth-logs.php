<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$admin = require_admin();

$types = [
    'local' => [
        'label' => '本校 / 本機',
        'title' => '本校 / 本機認證紀錄',
        'description' => '包含本機 SQL 帳號，以及使用 @ncut.edu.tw 或子網域 realm 的認證紀錄。',
        'empty' => '目前沒有本校或本機認證紀錄。',
    ],
    'tanrc' => [
        'label' => 'TANRC 外校',
        'title' => 'TANRC 外校認證紀錄',
        'description' => '外校帳號只保留唯讀認證紀錄，帳號密碼由所屬學校管理，本系統不提供修改。',
        'empty' => '目前沒有 TANRC 外校認證紀錄。',
    ],
    'no_realm' => [
        'label' => '未帶 realm',
        'title' => '未帶 realm 認證紀錄',
        'description' => '用來追蹤沒有輸入完整 username@realm 的連線嘗試，方便提醒使用者補上完整帳號。',
        'empty' => '目前沒有未帶 realm 的認證紀錄。',
    ],
];

$type = (string) ($_GET['type'] ?? 'local');
if (!array_key_exists($type, $types)) {
    $type = 'local';
}

$limit = (int) ($_GET['limit'] ?? 50);
$limit = max(10, min($limit, 100));

$counts = [
    'local' => auth_attempt_count($pdo, 'local', 24),
    'tanrc' => auth_attempt_count($pdo, 'tanrc', 24),
    'no_realm' => auth_attempt_count($pdo, 'no_realm', 24),
];
$attempts = auth_attempts($pdo, $type, $limit);
$active = $types[$type];

render_header('認證紀錄 - ' . APP_NAME, true);
?>
<section class="dashboard-head">
    <div>
        <h1>認證紀錄</h1>
        <p>將本校帳號、TANRC 外校帳號與未帶 realm 的連線嘗試分開檢視，避免和臨時帳號管理混在一起。</p>
    </div>
    <div class="stats">
        <span><strong><?= $counts['local'] ?></strong> 本校/本機 24h</span>
        <span><strong><?= $counts['tanrc'] ?></strong> TANRC 外校 24h</span>
        <span><strong><?= $counts['no_realm'] ?></strong> 未帶 realm 24h</span>
    </div>
</section>

<nav class="tabbar auth-tabbar" aria-label="認證紀錄分類">
    <a class="active" href="<?= e('/admin-auth-logs.php?' . http_build_query(['type' => $type, 'limit' => $limit])) ?>">認證紀錄</a>
    <a href="/admin-online-users.php">線上帳號</a>
    <a href="/admin-usage-analytics.php">用量分析</a>
    <a href="/admin-roaming-blocklist.php">外校封鎖管理</a>
</nav>

<section class="panel">
    <div class="section-title-row">
        <div>
            <h2><?= e($active['title']) ?></h2>
            <p class="muted small"><?= e($active['description']) ?></p>
        </div>
        <form method="get" class="inline-filter">
            <label>
                <span>分類</span>
                <select name="type" onchange="this.form.submit()">
                    <?php foreach ($types as $key => $item): ?>
                        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($item['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>顯示筆數</span>
                <select name="limit" onchange="this.form.submit()">
                    <?php foreach ([25, 50, 100] as $option): ?>
                        <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">更新</button>
        </form>
    </div>

    <?php if (!$attempts): ?>
        <p class="muted"><?= e($active['empty']) ?></p>
    <?php else: ?>
        <div class="table-wrap auth-log-table">
            <table>
                <thead>
                <tr>
                    <th>時間</th>
                    <th>帳號</th>
                    <th>結果</th>
                    <th>分類</th>
                    <th>NAS IP</th>
                    <th>使用者 MAC</th>
                    <th>AP / Called-Station</th>
                    <th>詳細</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($attempts as $item): ?>
                    <tr>
                        <td><?= e($item['authdate']) ?></td>
                        <td><code><?= e($item['username']) ?></code></td>
                        <td><span class="badge <?= $item['reply'] === 'Access-Accept' ? 'approved' : 'rejected' ?>"><?= e($item['reply']) ?></span></td>
                        <td><?= e($item['source_label']) ?></td>
                        <td><?= $item['nasipaddress'] ? '<code>' . e($item['nasipaddress']) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $item['callingstationid'] ? '<code>' . e(normalize_calling_station_id((string) $item['callingstationid'])) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $item['calledstationid'] ? '<code>' . e($item['calledstationid']) . '</code>' : '<span class="muted">-</span>' ?></td>
                        <td>
                            <a class="button-link secondary" href="<?= e('/admin-auth-log-detail.php?' . http_build_query(['id' => (int) $item['id'], 'type' => $type])) ?>">查看</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
