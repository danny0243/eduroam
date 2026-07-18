<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'request');

        if ($action === 'firebase_login') {
            $idToken = (string) ($_POST['id_token'] ?? '');
            if ($idToken === '') {
                throw new RuntimeException('未收到 Google 登入 Token。');
            }
            $googleUser = verify_firebase_id_token($idToken);
            $adminId    = google_admin_id($pdo, $googleUser['email']);

            session_regenerate_id(true);
            $_SESSION['auth'] = [
                'email'    => strtolower($googleUser['email']),
                'name'     => $googleUser['name'],
                'picture'  => $googleUser['picture'],
                'sub'      => $googleUser['sub'],
                'is_admin' => $adminId !== null,
                'admin_id' => $adminId,
            ];

            if ($adminId) {
                $pdo->prepare('UPDATE guest_account_admins SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?')
                    ->execute([$adminId]);
                flash('success', 'Google 登入成功（管理者）。');
                redirect('/admin.php');
            }

            if (!email_domain_allowed($pdo, $googleUser['email'])) {
                flash('error', '此 Google Email 網域尚未開放申請。');
            } else {
                flash('success', 'Google 登入成功，請填寫申請資料。');
            }
            redirect('/');
        }

        if ($action === 'logout') {
            unset($_SESSION['auth']);
            $_SESSION['firebase_signed_out'] = true;
            flash('success', '已登出。');
            redirect('/?signed_out=1');
        }

        $google = google_applicant();
        if (!$google) {
            throw new RuntimeException('請先使用 Google 登入後再申請。');
        }
        if (!email_domain_allowed($pdo, $google['email'])) {
            throw new RuntimeException('此 Google Email 網域尚未開放申請。');
        }
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            throw new RuntimeException('申請資料無法送出。');
        }

        if ($action === 'request_extension') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $requestedEndRaw = trim((string) ($_POST['requested_expires_date'] ?? ''));
            $extensionReason = trim((string) ($_POST['extension_reason'] ?? ''));
            if ($extensionReason === '' || mb_strlen($extensionReason) > 2000) {
                throw new RuntimeException('請填寫 2000 字以內的展延原因。');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT *
                     FROM guest_account_requests
                     WHERE id = ?
                       AND applicant_email = ?
                       AND status IN ("approved", "disabled")
                       AND radius_username IS NOT NULL
                       AND radius_username <> ""
                       AND expires_at IS NOT NULL
                     FOR UPDATE'
                );
                $stmt->execute([$requestId, $google['email']]);
                $account = $stmt->fetch();
                if (!$account) {
                    throw new RuntimeException('找不到可申請展延的帳號。');
                }

                $stmt = $pdo->prepare('SELECT COUNT(*) FROM guest_account_extension_requests WHERE request_id = ? AND status = "pending"');
                $stmt->execute([$requestId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('此帳號已有待審展延申請。');
                }

                $startDate = !empty($account['starts_at'])
                    ? (new DateTimeImmutable((string) $account['starts_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0)
                    : (!empty($account['desired_start'])
                        ? parse_date_input((string) $account['desired_start'], '使用起日')
                        : new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')));
                $currentEndDate = (new DateTimeImmutable((string) $account['expires_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0);
                $requestedEndDate = parse_date_input($requestedEndRaw, '展延迄日');
                validate_extension_period($pdo, (string) $account['applicant_email'], $startDate, $currentEndDate, $requestedEndDate);

                $requestedExpiresAt = date_to_expires_at($requestedEndDate);
                $stmt = $pdo->prepare(
                    'INSERT INTO guest_account_extension_requests
                        (request_id, applicant_email, radius_username, current_expires_at, requested_expires_at, reason,
                         status, request_ip, user_agent, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, NOW(), NOW())'
                );
                $stmt->execute([
                    (int) $account['id'],
                    $account['applicant_email'],
                    $account['radius_username'],
                    $account['expires_at'],
                    $requestedExpiresAt,
                    $extensionReason,
                    client_ip(),
                    user_agent(),
                ]);
                $extensionId = (int) $pdo->lastInsertId();

                $rows = [
                    '申請編號' => $account['request_code'],
                    'RADIUS 帳號' => $account['radius_username'],
                    '目前到期' => $account['expires_at'],
                    '申請展延至' => $requestedExpiresAt,
                    '展延原因' => $extensionReason,
                ];
                notify_applicant(
                    $pdo,
                    $account['applicant_email'],
                    '[NCUT eduroam] 已收到臨時帳號展延申請',
                    '已收到臨時帳號展延申請',
                    $rows,
                    '您的展延申請已送出，請等待管理者審核。'
                );
                $approveExtToken = generate_email_action_token($pdo, 'approve_extension', $extensionId);
                $rejectExtToken  = generate_email_action_token($pdo, 'reject_extension',  $extensionId);
                $baseUrl = 'https://eduroam.ncut.edu.tw';
                notify_admins(
                    $pdo,
                    '[NCUT eduroam] 有新的臨時帳號展延申請待審',
                    '新的臨時帳號展延申請待審',
                    $rows + ['管理後台' => $baseUrl . '/admin.php'],
                    '請直接點選下方按鈕審核，或登入管理後台。',
                    [
                        ['label' => '✓ 同意展延', 'url' => $baseUrl . '/action.php?token=' . $approveExtToken, 'danger' => false],
                        ['label' => '✗ 退回申請', 'url' => $baseUrl . '/action.php?token=' . $rejectExtToken,  'danger' => true],
                    ]
                );

                $pdo->commit();
                flash('success', "展延申請已送出，編號 EXT-{$extensionId}。");
                redirect('/');
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'change_password') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
            if (strlen($newPassword) < 8 || strlen($newPassword) > 64) {
                throw new RuntimeException('密碼長度需介於 8 到 64 字元。');
            }
            if (!hash_equals($newPassword, $newPasswordConfirm)) {
                throw new RuntimeException('兩次輸入的新密碼不一致。');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT *
                     FROM guest_account_requests
                     WHERE id = ?
                       AND applicant_email = ?
                       AND status IN ("approved", "disabled")
                       AND radius_username IS NOT NULL
                       AND radius_username <> ""
                     FOR UPDATE'
                );
                $stmt->execute([$requestId, $google['email']]);
                $account = $stmt->fetch();
                if (!$account) {
                    throw new RuntimeException('找不到可修改密碼的帳號。');
                }

                $stmt = $pdo->prepare('UPDATE radcheck SET value = ? WHERE username = ? AND attribute = "Cleartext-Password"');
                $stmt->execute([$newPassword, $account['radius_username']]);
                if ($stmt->rowCount() === 0) {
                    $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Cleartext-Password", ":=", ?)');
                    $stmt->execute([$account['radius_username'], $newPassword]);
                }
                $stmt = $pdo->prepare('UPDATE guest_account_requests SET radius_password = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([encrypt_secret($newPassword), (int) $account['id']]);
                $stmt = $pdo->prepare(
                    'INSERT INTO guest_account_audit (admin_id, action, request_id, message, ip_address, created_at)
                     VALUES (NULL, "user_change_password", ?, ?, ?, NOW())'
                );
                $stmt->execute([(int) $account['id'], 'user changed password for ' . $account['radius_username'], client_ip()]);

                $rows = [
                    '申請編號' => $account['request_code'],
                    'RADIUS 帳號' => $account['radius_username'],
                    '修改時間' => date('Y-m-d H:i:s'),
                ];
                notify_applicant(
                    $pdo,
                    $account['applicant_email'],
                    '[NCUT eduroam] 臨時帳號密碼已修改',
                    '臨時帳號密碼已修改',
                    $rows,
                    '您的 eduroam 臨時帳號密碼已完成修改。'
                );
                notify_admins(
                    $pdo,
                    '[NCUT eduroam] 使用者已修改臨時帳號密碼',
                    '使用者已修改臨時帳號密碼',
                    $rows,
                    '使用者已自行修改密碼；通知內容不包含密碼。'
                );

                $pdo->commit();
                flash('success', '密碼已更新。');
                redirect('/');
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM guest_account_requests WHERE applicant_email = ? AND status IN ("pending", "approved", "disabled")');
        $stmt->execute([$google['email']]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('此 Google 帳號已有申請中或已開通的帳號，一個帳號只能申請一組。');
        }

        $name = trim((string) ($_POST['applicant_name'] ?? ''));
        $email = $google['email'];
        $requestedUsername = normalize_radius_username((string) ($_POST['requested_username'] ?? ''));
        $phone = trim((string) ($_POST['applicant_phone'] ?? ''));
        $organization = trim((string) ($_POST['organization'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $desiredStart = trim((string) ($_POST['desired_start'] ?? ''));
        $desiredEnd = trim((string) ($_POST['desired_end'] ?? ''));
        $requestedPassword = (string) ($_POST['requested_password'] ?? '');
        $requestedPasswordConfirm = (string) ($_POST['requested_password_confirm'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 128) {
            throw new RuntimeException('請填寫正確的申請人姓名。');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            throw new RuntimeException('請填寫正確的 Email。');
        }
        if (!validate_radius_username($requestedUsername)) {
            throw new RuntimeException('帳號必須是 username@ncut.edu.tw 格式，且長度不可超過 64 字元。');
        }
        if (radius_user_exists($pdo, $requestedUsername) || requested_radius_username_exists($pdo, $requestedUsername)) {
            throw new RuntimeException('此帳號已存在或已在申請中，請改用其他帳號。');
        }
        if (mb_strlen($phone) > 64 || mb_strlen($organization) > 200 || mb_strlen($reason) > 2000) {
            throw new RuntimeException('欄位內容過長，請簡化後再送出。');
        }
        if ($organization === '' || $reason === '') {
            throw new RuntimeException('請填寫單位/來訪來源與申請用途。');
        }
        if (strlen($requestedPassword) < 8 || strlen($requestedPassword) > 64) {
            throw new RuntimeException('密碼長度需介於 8 到 64 字元。');
        }
        if (!hash_equals($requestedPassword, $requestedPasswordConfirm)) {
            throw new RuntimeException('兩次輸入的密碼不一致。');
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei'));
        $maxEnd = $today->modify('+20 years');
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $desiredEnd, new DateTimeZone('Asia/Taipei'));
        if (!$endDate || $endDate < $today || $endDate > $maxEnd) {
            throw new RuntimeException('請重新選擇有效的使用起訖日期。');
        }

        $startDate = parse_date_input($desiredStart, '使用起日');
        $endDate = parse_date_input($desiredEnd, '使用迄日');
        validate_requested_period($pdo, $email, $startDate, $endDate);

        $code = request_code();
        $stmt = $pdo->prepare(
            'INSERT INTO guest_account_requests
                (request_code, applicant_name, applicant_email, applicant_phone, organization, reason,
                 desired_start, desired_end, requested_username, requested_password, status, request_ip, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $code,
            $name,
            $email,
            $phone,
            $organization,
            $reason,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $requestedUsername,
            encrypt_secret($requestedPassword),
            client_ip(),
            user_agent(),
        ]);
        $newRequestId = (int) $pdo->lastInsertId();

        $rows = [
            '申請編號' => $code,
            '申請人' => $name,
            'Google Email' => $email,
            '希望帳號' => $requestedUsername,
            '單位 / 來源' => $organization,
            '使用起日' => $startDate->format('Y-m-d'),
            '使用迄日' => $endDate->format('Y-m-d'),
            '用途' => $reason,
        ];

        $approveToken = generate_email_action_token($pdo, 'approve', $newRequestId);
        $rejectToken  = generate_email_action_token($pdo, 'reject',  $newRequestId);
        $baseUrl = 'https://eduroam.ncut.edu.tw';

        notify_applicant(
            $pdo,
            $email,
            '[NCUT eduroam] 已收到臨時帳號申請',
            '已收到臨時帳號申請',
            $rows,
            '您的申請已送出，請等待管理者審核。'
        );
        notify_admins(
            $pdo,
            '[NCUT eduroam] 有新的臨時帳號申請待審',
            '新的臨時帳號申請待審',
            $rows + ['管理後台' => $baseUrl . '/admin.php'],
            '請直接點選下方按鈕審核，或登入管理後台。',
            [
                ['label' => '✓ 同意開通', 'url' => $baseUrl . '/action.php?token=' . $approveToken, 'danger' => false],
                ['label' => '✗ 退回申請', 'url' => $baseUrl . '/action.php?token=' . $rejectToken,  'danger' => true],
            ]
        );

        flash('success', "申請已送出，申請編號 {$code}。請等待管理者審核。");
        redirect('/');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/');
    }
}

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei'));
$defaultStart = $today->format('Y-m-d');
$defaultEnd = $today->modify('+7 days')->format('Y-m-d');
$google = google_applicant();
$domainAllowed = $google ? email_domain_allowed($pdo, $google['email']) : false;
$defaultName = $google['name'] ?? '';
$defaultEmail = $google['email'] ?? '';
$maxMonths = request_max_months_for_email($pdo, $defaultEmail);
$defaultMaxEnd = request_max_end_date($pdo, $defaultEmail, $today)->format('Y-m-d');
$defaultUsername = '';
if ($defaultEmail !== '') {
    $defaultUsername = preg_replace('/[^a-z0-9._%+\-]/', '', strtolower(strtok($defaultEmail, '@') ?: '')) . '@ncut.edu.tw';
}
$myRequests = [];
$myExtensionRequests = [];
$pendingExtensionByRequest = [];
$hasAccountApplication = false;
if ($google) {
    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE applicant_email = ? ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([$google['email']]);
    $myRequests = $stmt->fetchAll();
    foreach ($myRequests as $request) {
        if (in_array($request['status'], ['pending', 'approved', 'disabled'], true)) {
            $hasAccountApplication = true;
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM guest_account_extension_requests WHERE applicant_email = ? ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([$google['email']]);
    foreach ($stmt->fetchAll() as $extension) {
        $requestId = (int) $extension['request_id'];
        $myExtensionRequests[$requestId][] = $extension;
        if ($extension['status'] === 'pending') {
            $pendingExtensionByRequest[$requestId] = $extension;
        }
    }
}

if (!$google) {
    render_header(APP_NAME);
    $suppressAutoLogin = !empty($_SESSION['firebase_signed_out']) ? 'true' : 'false';
    unset($_SESSION['firebase_signed_out']);
    ?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh">
<section class="panel narrow" style="width:100%;max-width:420px">
    <h2 style="margin:0 0 8px">eduroam 臨時帳號申請</h2>
    <p class="muted" style="margin:0 0 24px">請使用 Google 帳號登入後，才能查看申請頁面或送出申請。</p>
    <form method="post" id="firebase-request-login-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="firebase_login">
        <input type="hidden" name="id_token" id="firebase-id-token">
        <button type="button" class="google-button" id="google-login-button">
            <span class="google-mark">G</span>
            使用 Google 登入
        </button>
        <p class="muted small">登入後系統會確認您的 Email 網域是否開放申請。</p>
    </form>
</section>
</div>
<script type="module">
import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js";
import { getAuth, GoogleAuthProvider, signInWithPopup, setPersistence, browserLocalPersistence, onAuthStateChanged, signOut } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js";

const firebaseConfig = <?= json_encode(firebase_web_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const app = initializeApp(firebaseConfig);
const auth = getAuth(app);
const authReady = setPersistence(auth, browserLocalPersistence).catch((error) => {
    console.warn("Firebase auth persistence was not enabled.", error);
});
const provider = new GoogleAuthProvider();
provider.setCustomParameters({ prompt: "select_account" });

const googleButton = document.getElementById("google-login-button");
const tokenInput = document.getElementById("firebase-id-token");
const loginForm = document.getElementById("firebase-request-login-form");
let suppressAutoLogin = new URLSearchParams(window.location.search).has("signed_out") || <?= $suppressAutoLogin ?>;

const submitFirebaseUser = async (user, forceRefresh = false) => {
    if (!user || !tokenInput || !loginForm || loginForm.dataset.submitting === "1") return;
    loginForm.dataset.submitting = "1";
    try {
        tokenInput.value = await user.getIdToken(forceRefresh);
        loginForm.submit();
    } catch (error) {
        loginForm.dataset.submitting = "0";
        throw error;
    }
};

onAuthStateChanged(auth, async (user) => {
    await authReady;
    if (suppressAutoLogin) {
        if (user) await signOut(auth);
        return;
    }
    if (user) await submitFirebaseUser(user, false);
});

googleButton?.addEventListener("click", async () => {
    googleButton.disabled = true;
    googleButton.textContent = "Google 登入中...";
    try {
        await authReady;
        suppressAutoLogin = false;
        const result = await signInWithPopup(auth, provider);
        await submitFirebaseUser(result.user, true);
    } catch (error) {
        googleButton.disabled = false;
        googleButton.innerHTML = '<span class="google-mark">G</span>使用 Google 登入';
        alert(error?.message || "Google 登入失敗");
    }
});
</script>
<?php
    render_footer();
    exit;
}

render_header(APP_NAME);
?>
<section class="hero">
    <div>
        <h1>eduroam 臨時帳號申請</h1>
        <p>提供來賓或短期活動使用。送出申請後，管理者核准才會建立可登入 eduroam 的帳號。</p>
    </div>
</section>

<?php if (!$domainAllowed): ?>
<section class="panel narrow">
    <h2>此 Google Email 暫不開放申請</h2>
    <p>目前登入帳號：<code><?= e($google['email']) ?></code></p>
    <p class="muted">請使用已開放網域的 Google 帳號，或洽管理者加入允許網域清單。</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="secondary">切換 Google 帳號</button>
    </form>
</section>
<?php elseif ($hasAccountApplication): ?>
<section class="panel narrow">
    <h2>已有申請紀錄</h2>
    <p>此 Google 帳號已有申請中或已開通的 eduroam 臨時帳號，一個帳號只能申請一組。</p>
    <p class="muted">請在下方「我的申請紀錄」查看狀態、申請展延或修改密碼。</p>
</section>
<?php else: ?>
<section class="panel">
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request">
        <input type="text" name="website" class="hidden-field" tabindex="-1" autocomplete="off">

        <label>
            <span>申請人姓名</span>
            <input name="applicant_name" required maxlength="128" autocomplete="name" value="<?= e($defaultName) ?>">
        </label>

        <label>
            <span>Google Email</span>
            <input type="email" name="applicant_email" id="applicant-email" required maxlength="200" autocomplete="email" value="<?= e($defaultEmail) ?>" readonly>
        </label>

        <label>
            <span>希望使用的帳號</span>
            <input name="requested_username" id="requested-username" required maxlength="64" autocomplete="username" placeholder="username@ncut.edu.tw" value="<?= e($defaultUsername) ?>">
        </label>

        <label>
            <span>聯絡電話</span>
            <input name="applicant_phone" maxlength="64" autocomplete="tel">
        </label>

        <label>
            <span>單位 / 來訪來源</span>
            <input name="organization" required maxlength="200">
        </label>

        <label>
            <span>使用起日</span>
            <input type="date" name="desired_start" id="desired-start" required value="<?= e($defaultStart) ?>" min="<?= e($defaultStart) ?>">
        </label>

        <label>
            <span>使用迄日</span>
            <input type="date" name="desired_end" id="desired-end" required value="<?= e($defaultEnd) ?>" min="<?= e($defaultStart) ?>" max="<?= e($defaultMaxEnd) ?>">
        </label>

        <label>
            <span>密碼</span>
            <input type="password" name="requested_password" required minlength="8" maxlength="64" autocomplete="new-password">
        </label>

        <label>
            <span>確認密碼</span>
            <input type="password" name="requested_password_confirm" required minlength="8" maxlength="64" autocomplete="new-password">
        </label>

        <label class="wide">
            <span>申請用途</span>
            <textarea name="reason" required maxlength="2000" rows="5"></textarea>
        </label>

        <div class="actions wide">
            <button type="submit" class="primary">送出申請</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($google): ?>
<section class="panel">
    <h2>我的申請紀錄</h2>
    <?php if (!$myRequests): ?>
        <p class="muted">目前沒有申請紀錄。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>申請編號</th>
                    <th>帳號</th>
                    <th>狀態</th>
                    <th>使用期間</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($myRequests as $item): ?>
                    <?php
                    $requestId = (int) $item['id'];
                    $pendingExtension = $pendingExtensionByRequest[$requestId] ?? null;
                    $canExtend = in_array($item['status'], ['approved', 'disabled'], true)
                        && !empty($item['radius_username'])
                        && !empty($item['expires_at'])
                        && !$pendingExtension;
                    $canChangePassword = in_array($item['status'], ['approved', 'disabled'], true)
                        && !empty($item['radius_username']);
                    $extensionMin = '';
                    $extensionMax = '';
                    $extensionDefault = '';
                    if ($canExtend) {
                        $startDate = !empty($item['starts_at'])
                            ? (new DateTimeImmutable((string) $item['starts_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0)
                            : (!empty($item['desired_start'])
                                ? parse_date_input((string) $item['desired_start'], '使用起日')
                                : new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')));
                        $currentEndDate = (new DateTimeImmutable((string) $item['expires_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0);
                        $todayDate = new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei'));
                        $minDate = $currentEndDate->modify('+1 day');
                        if ($minDate < $todayDate) {
                            $minDate = $todayDate;
                        }
                        $maxDate = request_max_end_date($pdo, (string) $item['applicant_email'], $startDate);
                        if ($maxDate < $minDate) {
                            $canExtend = false;
                        } else {
                            $defaultDate = $currentEndDate->modify('+7 days');
                            if ($defaultDate < $minDate) {
                                $defaultDate = $minDate;
                            }
                            if ($defaultDate > $maxDate) {
                                $defaultDate = $maxDate;
                            }
                            $extensionMin = $minDate->format('Y-m-d');
                            $extensionMax = $maxDate->format('Y-m-d');
                            $extensionDefault = $defaultDate->format('Y-m-d');
                        }
                    }
                    ?>
                    <tr>
                        <td><?= e($item['request_code']) ?></td>
                        <td><code><?= e($item['radius_username'] ?: $item['requested_username']) ?></code></td>
                        <td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
                        <td><?= e(($item['starts_at'] ?: $item['desired_start'] ?: '-') . ' 到 ' . ($item['expires_at'] ?: $item['desired_end'] ?: '-')) ?></td>
                        <td>
                            <?php if ($canChangePassword): ?>
                                <form method="post" class="stack">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="change_password">
                                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                    <input type="text" name="website" class="hidden-field" tabindex="-1" autocomplete="off">
                                    <label>
                                        <span>新密碼</span>
                                        <input type="password" name="new_password" required minlength="8" maxlength="64" autocomplete="new-password">
                                    </label>
                                    <label>
                                        <span>確認新密碼</span>
                                        <input type="password" name="new_password_confirm" required minlength="8" maxlength="64" autocomplete="new-password">
                                    </label>
                                    <button type="submit" class="secondary">修改密碼</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($pendingExtension): ?>
                                <span class="muted small">展延待審至 <?= e($pendingExtension['requested_expires_at']) ?></span>
                            <?php elseif ($canExtend): ?>
                                <form method="post" class="stack">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_extension">
                                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                    <input type="text" name="website" class="hidden-field" tabindex="-1" autocomplete="off">
                                    <label>
                                        <span>展延迄日</span>
                                        <input type="date" name="requested_expires_date" required min="<?= e($extensionMin) ?>" max="<?= e($extensionMax) ?>" value="<?= e($extensionDefault) ?>">
                                    </label>
                                    <label>
                                        <span>展延原因</span>
                                        <input name="extension_reason" required maxlength="2000">
                                    </label>
                                    <button type="submit" class="secondary">申請展延</button>
                                </form>
                            <?php elseif ($item['status'] === 'approved' && empty($item['expires_at'])): ?>
                                <span class="muted small">永久有效，不需展延</span>
                            <?php else: ?>
                                <span class="muted small">目前不可展延</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($myExtensionRequests[$requestId])): ?>
                        <?php foreach ($myExtensionRequests[$requestId] as $extension): ?>
                            <tr>
                                <td colspan="5" class="muted small">
                                    展延 #EXT-<?= (int) $extension['id'] ?>：
                                    <?= e($extension['status']) ?>，
                                    目前到期 <?= e($extension['current_expires_at']) ?>，
                                    申請至 <?= e($extension['requested_expires_at']) ?>
                                    <?= $extension['review_note'] !== '' ? '，備註：' . e($extension['review_note']) : '' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="notice">
    <strong>連線提醒</strong>
    <span>核准後請使用管理者提供的帳號密碼連線 eduroam。Android 建議使用 geteduroam 或手動設定 PEAP / MSCHAPV2。</span>
</section>
<script>
(() => {
    const email = document.getElementById("applicant-email");
    const username = document.getElementById("requested-username");
    let usernameEdited = false;

    const sanitizeLocalPart = (value) => value
        .toLowerCase()
        .replace(/@.*$/, "")
        .replace(/[^a-z0-9._%+\-]/g, "")
        .slice(0, 51);

    username?.addEventListener("input", () => {
        usernameEdited = username.value.trim() !== "";
    });

    email?.addEventListener("input", () => {
        if (!username || usernameEdited) {
            return;
        }
        const localPart = sanitizeLocalPart(email.value);
        username.value = localPart ? `${localPart}@ncut.edu.tw` : "";
    });

    const startInput = document.getElementById("desired-start");
    const endInput = document.getElementById("desired-end");
    const maxMonths = <?= (int) $maxMonths ?>;
    const formatDate = (date) => {
        const yyyy = date.getFullYear();
        const mm = String(date.getMonth() + 1).padStart(2, "0");
        const dd = String(date.getDate()).padStart(2, "0");
        return `${yyyy}-${mm}-${dd}`;
    };
    const syncDateLimits = () => {
        if (!startInput || !endInput || !startInput.value) {
            return;
        }
        const start = new Date(`${startInput.value}T00:00:00`);
        if (Number.isNaN(start.getTime())) {
            return;
        }
        const max = new Date(start);
        max.setMonth(max.getMonth() + maxMonths);
        endInput.min = startInput.value;
        endInput.max = formatDate(max);
        if (!endInput.value || endInput.value < endInput.min) {
            endInput.value = endInput.min;
        }
        if (endInput.value > endInput.max) {
            endInput.value = endInput.max;
        }
    };
    startInput?.addEventListener("change", syncDateLimits);
    syncDateLimits();
})();
</script>
<?php
render_footer();
