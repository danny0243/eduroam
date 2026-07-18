<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// ── helpers ──────────────────────────────────────────────────────────────────

function action_page_header(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="zh-Hant"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' - NCUT eduroam</title>';
    echo '<link rel="stylesheet" href="/assets/styles.css?v=20260628-sql-view">';
    echo '</head><body>';
    echo '<header class="topbar"><div><div class="brand">NCUT eduroam</div><div class="subtitle">申請審核</div></div></header>';
    echo '<div class="container">';
}

function action_page_footer(): void
{
    echo '</div></body></html>';
}

function action_error(string $msg): void
{
    action_page_header('操作失敗');
    echo '<div class="panel"><h2 style="margin:0 0 12px">操作失敗</h2>';
    echo '<p>' . e($msg) . '</p>';
    echo '<p><a href="/admin.php" class="button-link primary" style="display:inline-block;padding:10px 20px;background:#1667c7;color:#fff;border-radius:6px;text-decoration:none;font-weight:700;">前往管理後台</a></p>';
    echo '</div>';
    action_page_footer();
    exit;
}

// ── perform approve (account request) ────────────────────────────────────────

function do_approve(PDO $pdo, array $tokenRow, string $note, array $admin): void
{
    $requestId = (int) $tokenRow['request_id'];

    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? AND status = "pending"');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new RuntimeException('找不到待審核申請，或該申請已處理。');
    }

    $username = normalize_radius_username((string) $request['requested_username']);
    if (!validate_radius_username($username)) {
        throw new RuntimeException('希望帳號格式不正確（' . $username . '），請至管理後台手動審核。');
    }
    $password = decrypt_secret((string) $request['requested_password']);
    if ($password === '') {
        $password = generate_password();
    }

    $tz = new DateTimeZone('Asia/Taipei');
    $startsAt  = (new DateTimeImmutable((string) $request['desired_start'], $tz))->setTime(0, 0, 0);
    $expiresAt = (new DateTimeImmutable((string) $request['desired_end'],   $tz))->setTime(23, 59, 59);
    $now = new DateTimeImmutable('now', $tz);
    if ($expiresAt <= $now) {
        throw new RuntimeException('申請的使用迄日已過期，請至管理後台手動審核。');
    }

    validate_requested_period(
        $pdo,
        (string) $request['applicant_email'],
        $startsAt->setTime(0, 0, 0),
        $expiresAt->setTime(0, 0, 0)
    );

    if (radius_user_exists($pdo, $username)) {
        throw new RuntimeException('RADIUS 帳號已存在（' . $username . '），請至管理後台手動審核。');
    }

    $pdo->beginTransaction();
    try {
        consume_email_action_token($pdo, (int) $tokenRow['id']);

        $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ":=", ?)');
        $stmt->execute([$username, 'Cleartext-Password', $password]);
        $expiration = radius_expiration_value($expiresAt->format('Y-m-d H:i:s'));
        $stmt->execute([$username, 'Expiration', $expiration]);
        if ($startsAt > $now) {
            $stmt->execute([$username, 'Auth-Type', 'Reject']);
        }

        $pdo->prepare(
            'INSERT INTO userinfo (username, firstname, lastname, email, company, mobilephone, notes, creationdate, creationby, updatedate, updateby)
             VALUES (?, ?, "", ?, ?, ?, ?, NOW(), ?, NOW(), ?)'
        )->execute([
            $username,
            $request['applicant_name'],
            $request['applicant_email'],
            $request['organization'],
            $request['applicant_phone'],
            'Temporary account request ' . $request['request_code'] . ': ' . mb_substr((string) $request['reason'], 0, 120),
            $admin['username'],
            $admin['username'],
        ]);

        $reviewer = $admin['username'];
        $pdo->prepare(
            'UPDATE guest_account_requests
             SET status="approved", radius_username=?, radius_password=?, starts_at=?, expires_at=?,
                  reviewed_by=?, reviewed_at=NOW(), review_note=?, updated_at=NOW()
              WHERE id=?'
        )->execute([$username, encrypt_secret($password), $startsAt->format('Y-m-d H:i:s'), $expiresAt->format('Y-m-d H:i:s'), $reviewer, $note, $requestId]);

        audit($pdo, (int) $admin['id'], 'approve', $requestId, "[email action] approved {$username}");
        $pdo->commit();

        $rows = [
            '申請編號' => $request['request_code'],
            '申請人'   => $request['applicant_name'],
            'Google Email' => $request['applicant_email'],
            'RADIUS 帳號'  => $username,
            '密碼'         => $password,
            '啟用時間'     => $startsAt->format('Y-m-d H:i:s'),
            '到期時間'     => $expiresAt->format('Y-m-d H:i:s'),
            '審核方式'     => 'Email 連結 + 管理者登入',
            '審核者'       => $admin['username'],
            '審核備註'     => $note,
        ];
        notify_applicant(
            $pdo, $request['applicant_email'],
            '[NCUT eduroam] 臨時帳號已開通', '臨時帳號已開通',
            $rows, '請使用以下帳號密碼連線 eduroam。Android 建議使用 PEAP / MSCHAPV2。'
        );
        $adminRows = $rows;
        unset($adminRows['密碼']);
        notify_admins($pdo, '[NCUT eduroam] 臨時帳號已核准', '臨時帳號已核准', $adminRows, '此申請已由管理者登入後完成開通。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── perform reject (account request) ─────────────────────────────────────────

function do_reject(PDO $pdo, array $tokenRow, string $note, array $admin): void
{
    $requestId = (int) $tokenRow['request_id'];

    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? AND status = "pending"');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new RuntimeException('找不到待審核申請，或該申請已處理。');
    }

    $pdo->beginTransaction();
    try {
        consume_email_action_token($pdo, (int) $tokenRow['id']);

        $pdo->prepare(
            'UPDATE guest_account_requests SET status="rejected", reviewed_by=?, reviewed_at=NOW(), review_note=?, updated_at=NOW() WHERE id=? AND status="pending"'
        )->execute([$admin['username'], $note, $requestId]);

        audit($pdo, (int) $admin['id'], 'reject', $requestId, '[email action] rejected request');
        $pdo->commit();

        notify_applicant(
            $pdo, $request['applicant_email'],
            '[NCUT eduroam] 臨時帳號申請未通過', '臨時帳號申請未通過',
            ['申請編號' => $request['request_code'], '申請人' => $request['applicant_name'], '退回原因' => $note ?: '（未填寫）'],
            '您的臨時帳號申請未通過，如需使用請重新申請或聯絡管理者。'
        );
        notify_admins($pdo, '[NCUT eduroam] 臨時帳號申請已退回', '臨時帳號申請已退回',
            ['申請編號' => $request['request_code'], '申請人' => $request['applicant_name'], '退回原因' => $note ?: '（未填寫）', '審核方式' => 'Email 連結 + 管理者登入', '審核者' => $admin['username']],
            '此申請已由管理者登入後退回。'
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── perform approve (extension request) ──────────────────────────────────────

function do_approve_extension(PDO $pdo, array $tokenRow, string $note, array $admin): void
{
    $extId = (int) $tokenRow['request_id'];
    $tz    = new DateTimeZone('Asia/Taipei');

    $stmt = $pdo->prepare(
        'SELECT er.*, ar.starts_at, ar.desired_start, ar.expires_at, ar.request_code, ar.applicant_name, ar.radius_username AS account_username, ar.id AS ar_id
         FROM guest_account_extension_requests er
         JOIN guest_account_requests ar ON ar.id = er.request_id
         WHERE er.id = ? AND er.status = "pending"'
    );
    $stmt->execute([$extId]);
    $ext = $stmt->fetch();
    if (!$ext) {
        throw new RuntimeException('找不到待審展延申請，或該申請已處理。');
    }

    $startDate       = !empty($ext['starts_at'])
        ? (new DateTimeImmutable((string) $ext['starts_at'], $tz))->setTime(0, 0, 0)
        : (new DateTimeImmutable('today', $tz));
    $currentEndDate  = (new DateTimeImmutable((string) $ext['current_expires_at'], $tz))->setTime(0, 0, 0);
    $requestedEndDate= (new DateTimeImmutable((string) $ext['requested_expires_at'], $tz))->setTime(0, 0, 0);
    validate_extension_period($pdo, (string) $ext['applicant_email'], $startDate, $currentEndDate, $requestedEndDate);

    $newExpiresAt = date_to_expires_at($requestedEndDate);
    $expiration   = radius_expiration_value($newExpiresAt);

    $pdo->beginTransaction();
    try {
        consume_email_action_token($pdo, (int) $tokenRow['id']);

        $stmt = $pdo->prepare('UPDATE radcheck SET value=? WHERE username=? AND attribute="Expiration"');
        $stmt->execute([$expiration, $ext['radius_username']]);
        if ($stmt->rowCount() === 0) {
            $pdo->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?, "Expiration", ":=", ?)')->execute([$ext['radius_username'], $expiration]);
        }
        $pdo->prepare('UPDATE guest_account_requests SET expires_at=?, updated_at=NOW() WHERE id=?')
            ->execute([$newExpiresAt, (int) $ext['ar_id']]);
        $pdo->prepare(
            'UPDATE guest_account_extension_requests SET status="approved", reviewed_by=?, reviewed_at=NOW(), review_note=?, updated_at=NOW() WHERE id=?'
        )->execute([$admin['username'], $note, $extId]);

        audit($pdo, (int) $admin['id'], 'approve_extension', (int) $ext['ar_id'], '[email action] approved extension for ' . $ext['radius_username']);
        $pdo->commit();

        $rows = ['申請編號' => $ext['request_code'], 'RADIUS 帳號' => $ext['radius_username'], '原到期時間' => $ext['current_expires_at'], '新到期時間' => $newExpiresAt, '審核方式' => 'Email 連結 + 管理者登入', '審核者' => $admin['username']];
        notify_applicant($pdo, $ext['applicant_email'], '[NCUT eduroam] 臨時帳號展延已核准', '臨時帳號展延已核准', $rows, '您的 eduroam 臨時帳號期限已更新。');
        notify_admins($pdo, '[NCUT eduroam] 臨時帳號展延已核准', '臨時帳號展延已核准', $rows, '此展延申請已由管理者登入後完成。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── perform reject (extension request) ───────────────────────────────────────

function do_reject_extension(PDO $pdo, array $tokenRow, string $note, array $admin): void
{
    $extId = (int) $tokenRow['request_id'];
    $stmt  = $pdo->prepare(
        'SELECT er.*, ar.request_code FROM guest_account_extension_requests er
         JOIN guest_account_requests ar ON ar.id = er.request_id
         WHERE er.id = ? AND er.status = "pending"'
    );
    $stmt->execute([$extId]);
    $ext = $stmt->fetch();
    if (!$ext) {
        throw new RuntimeException('找不到待審展延申請，或該申請已處理。');
    }

    $pdo->beginTransaction();
    try {
        consume_email_action_token($pdo, (int) $tokenRow['id']);

        $pdo->prepare(
            'UPDATE guest_account_extension_requests SET status="rejected", reviewed_by=?, reviewed_at=NOW(), review_note=?, updated_at=NOW() WHERE id=?'
        )->execute([$admin['username'], $note, $extId]);

        audit($pdo, (int) $admin['id'], 'reject_extension', (int) $ext['request_id'], '[email action] rejected extension');
        $pdo->commit();

        notify_applicant($pdo, $ext['applicant_email'], '[NCUT eduroam] 臨時帳號展延申請未通過', '臨時帳號展延申請未通過',
            ['申請編號' => $ext['request_code'], 'RADIUS 帳號' => $ext['radius_username'], '退回原因' => $note ?: '（未填寫）'],
            '您的展延申請未通過，如需延長請重新申請或聯絡管理者。'
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── routing ───────────────────────────────────────────────────────────────────

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    action_error('無效的操作連結。');
}

$tokenRow = validate_email_action_token($pdo, $token);
if (!$tokenRow) {
    action_error('此連結已失效、已使用過，或已過期（有效期為 72 小時）。如需審核請至管理後台。');
}

if (!admin_user()) {
    remember_admin_return_to('/action.php?token=' . urlencode($token));
    flash('error', '請先以管理者 Google 帳號登入後再審核此申請。');
    redirect('/admin.php');
}
$admin = require_admin();

$actionName = (string) $tokenRow['action'];
$isExtension = str_contains($actionName, 'extension');
$isApprove   = str_starts_with($actionName, 'approve');

$requestId = (int) $tokenRow['request_id'];

// Fetch request details for display
if ($isExtension) {
    $stmt = $pdo->prepare(
        'SELECT er.*, ar.request_code, ar.applicant_name
         FROM guest_account_extension_requests er
         JOIN guest_account_requests ar ON ar.id = er.request_id
         WHERE er.id = ?'
    );
} else {
    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ?');
}
$stmt->execute([$requestId]);
$requestData = $stmt->fetch();

if (!$requestData) {
    action_error('找不到對應的申請記錄。');
}

// ── POST: perform action ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_action'])) {
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        verify_csrf();
        match ($actionName) {
            'approve'           => do_approve($pdo, $tokenRow, $note, $admin),
            'reject'            => do_reject($pdo, $tokenRow, $note, $admin),
            'approve_extension' => do_approve_extension($pdo, $tokenRow, $note, $admin),
            'reject_extension'  => do_reject_extension($pdo, $tokenRow, $note, $admin),
        };
    } catch (Throwable $e) {
        action_error($e->getMessage());
    }

    action_page_header('操作完成');
    $label = match ($actionName) {
        'approve'           => '已同意開通',
        'reject'            => '已退回申請',
        'approve_extension' => '已同意展延',
        'reject_extension'  => '已退回展延申請',
    };
    echo '<div class="panel">';
    echo '<h2 style="margin:0 0 12px;color:' . ($isApprove ? '#0b6b38' : '#8a1f11') . '">' . e($label) . '</h2>';
    if ($isExtension) {
        echo '<p>申請編號：<strong>' . e($requestData['request_code']) . '</strong>，RADIUS 帳號：<strong>' . e($requestData['radius_username']) . '</strong></p>';
    } else {
        echo '<p>申請編號：<strong>' . e($requestData['request_code']) . '</strong>，申請人：<strong>' . e($requestData['applicant_name']) . '</strong></p>';
    }
    if ($isApprove) {
        echo '<p>系統已自動寄送通知給申請人。</p>';
    }
    echo '<p><a href="/admin.php" style="color:#0a66c2">前往管理後台</a></p>';
    echo '</div>';
    action_page_footer();
    exit;
}

// ── GET: show confirmation page ───────────────────────────────────────────────

$actionLabel = match ($actionName) {
    'approve'           => '同意開通帳號',
    'reject'            => '退回帳號申請',
    'approve_extension' => '同意展延帳號',
    'reject_extension'  => '退回展延申請',
};

action_page_header($actionLabel);
?>
<div class="panel" style="max-width:680px">
    <h2 style="margin:0 0 4px"><?= e($actionLabel) ?></h2>
    <p class="muted" style="margin:0 0 20px;font-size:14px">請確認申請內容後再操作。此連結 72 小時內有效且只能使用一次。</p>

    <?php if ($isExtension): ?>
    <table style="border-collapse:collapse;width:100%;margin-bottom:20px">
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px;width:130px">申請編號</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['request_code']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">申請人</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['applicant_name']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">RADIUS 帳號</th><td style="border:1px solid #dbe2ea;padding:8px"><code><?= e($requestData['radius_username']) ?></code></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">目前到期</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['current_expires_at']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">申請展延至</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['requested_expires_at']) ?></td></tr>
        <?php if ((string) $requestData['reason'] !== ''): ?>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">展延原因</th><td style="border:1px solid #dbe2ea;padding:8px"><?= nl2br(e($requestData['reason'])) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php else: ?>
    <table style="border-collapse:collapse;width:100%;margin-bottom:20px">
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px;width:130px">申請編號</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['request_code']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">申請人</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['applicant_name']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">Google Email</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['applicant_email']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">希望帳號</th><td style="border:1px solid #dbe2ea;padding:8px"><code><?= e($requestData['requested_username']) ?></code></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">使用期限</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['desired_start']) ?> 至 <?= e($requestData['desired_end']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">單位 / 來源</th><td style="border:1px solid #dbe2ea;padding:8px"><?= e($requestData['organization']) ?></td></tr>
        <tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px">用途</th><td style="border:1px solid #dbe2ea;padding:8px"><?= nl2br(e($requestData['reason'])) ?></td></tr>
    </table>
    <?php if ($isApprove): ?>
    <div class="notice" style="margin-bottom:20px">
        <div>
            <strong>快速開通說明</strong>
            <p style="margin:4px 0 0">將以申請者填寫的「希望帳號」直接開通，起迄時間依申請日期。若需調整帳號名稱或期限，請至管理後台手動審核。</p>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="confirm_action" value="1">
        <?php if (!$isApprove): ?>
        <div class="stack" style="margin-bottom:16px">
            <label>
                <span>退回原因（選填，將寄送給申請人）</span>
                <textarea name="note" rows="3" placeholder="例如：資訊不完整、不符合申請資格…"></textarea>
            </label>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
            <?php if ($isApprove): ?>
            <button type="submit" class="primary" style="padding:12px 28px;font-size:16px;" onclick="return confirm('確定同意此申請？')">✓ 確定<?= e($actionLabel) ?></button>
            <?php else: ?>
            <button type="submit" class="danger" style="padding:12px 28px;font-size:16px;" onclick="return confirm('確定退回此申請？')">✗ 確定<?= e($actionLabel) ?></button>
            <?php endif; ?>
            <a href="/admin.php" style="color:#0a66c2;font-size:14px">前往管理後台</a>
        </div>
    </form>
</div>
<?php
action_page_footer();
