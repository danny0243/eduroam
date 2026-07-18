<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

const IMPORT_MAX_BYTES = 1048576;
const IMPORT_MAX_ROWS = 1000;

function truthy_form_value(string $value): bool
{
    $value = strtolower(trim($value));
    return in_array($value, ['1', 'true', 'yes', 'y', 'on', '是', '永久', '永久有效', 'permanent'], true);
}

function parse_account_datetime(string $value, string $label, bool $endOfDay = false): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException($label . '不可空白。');
    }

    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'Y-m-d', 'Y/m/d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Asia/Taipei'));
        if (!$dt instanceof DateTimeImmutable) {
            continue;
        }
        if (in_array($format, ['Y-m-d', 'Y/m/d'], true)) {
            return $endOfDay ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
        }
        return $dt;
    }

    throw new RuntimeException($label . '格式不正確。');
}

function create_account_from_admin(PDO $pdo, array $admin, array $data, string $source): array
{
    $importLike = $source !== 'manual';
    $sourceLabel = match ($source) {
        'sql_view' => '管理者 SQL View 匯入帳號',
        'import' => '管理者匯入帳號',
        default => '管理者新增帳號',
    };
    $auditAction = match ($source) {
        'sql_view' => 'import_sql_view_account',
        'import' => 'import_account',
        default => 'create_account',
    };
    $name = trim((string) ($data['applicant_name'] ?? ''));
    $email = strtolower(trim((string) ($data['applicant_email'] ?? '')));
    $phone = trim((string) ($data['applicant_phone'] ?? ''));
    $organization = trim((string) ($data['organization'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? ''));
    $username = normalize_radius_username((string) ($data['radius_username'] ?? ''));
    $password = trim((string) ($data['radius_password'] ?? ''));
    $permanent = !empty($data['permanent']);
    $sendNotice = array_key_exists('send_notice', $data) ? (bool) $data['send_notice'] : true;
    $startRaw = trim((string) ($data['starts_at'] ?? ''));
    $expiresRaw = trim((string) ($data['expires_at'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('請輸入姓名。');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('請輸入有效 Email。');
    }
    if ($organization === '' && $importLike) {
        $organization = '未指定';
    }
    if ($organization === '') {
        throw new RuntimeException('請輸入單位。');
    }
    if ($reason === '') {
        $reason = $sourceLabel;
    }
    if (!validate_radius_username($username)) {
        throw new RuntimeException('帳號必須是 username@ncut.edu.tw 格式。');
    }
    $passwordGenerated = false;
    if ($password === '') {
        $password = generate_password();
        $passwordGenerated = true;
    }
    if (strlen($password) < 8 || strlen($password) > 64) {
        throw new RuntimeException('密碼長度需介於 8 到 64 字元。');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
    $startsAt = $startRaw === '' ? $now : parse_account_datetime($startRaw, '啟用時間');
    $expiresAt = null;
    if (!$permanent) {
        $expiresAt = $expiresRaw === '' ? $startsAt->modify('+7 days') : parse_account_datetime($expiresRaw, '使用迄止', true);
        if ($expiresAt <= $now) {
            throw new RuntimeException('到期時間必須晚於現在。');
        }
        if ($expiresAt <= $startsAt) {
            throw new RuntimeException('使用迄止時間必須晚於啟用時間。');
        }
    }

    if (radius_user_exists($pdo, $username) || requested_radius_username_exists($pdo, $username)) {
        throw new RuntimeException('RADIUS 帳號已存在或已在申請中：' . $username);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM guest_account_requests WHERE applicant_email = ? AND status IN ("pending", "approved", "disabled")');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException('此 Email 已有申請中或已開通的帳號：' . $email);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ":=", ?)');
        $stmt->execute([$username, 'Cleartext-Password', $password]);
        if ($expiresAt instanceof DateTimeImmutable) {
            $stmt->execute([$username, 'Expiration', radius_expiration_value($expiresAt->format('Y-m-d H:i:s'))]);
        }
        if ($startsAt > $now) {
            $stmt->execute([$username, 'Auth-Type', 'Reject']);
        }

        $code = request_code();
        $stmt = $pdo->prepare(
            'INSERT INTO userinfo
                (username, firstname, lastname, email, company, mobilephone, notes, creationdate, creationby, updatedate, updateby)
             VALUES (?, ?, "", ?, ?, ?, ?, NOW(), ?, NOW(), ?)'
        );
        $stmt->execute([
            $username,
            $name,
            $email,
            $organization,
            $phone,
            'Temporary account ' . $code . ' created by admin: ' . mb_substr($reason, 0, 120),
            $admin['username'],
            $admin['username'],
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO guest_account_requests
                (request_code, applicant_name, applicant_email, applicant_phone, organization, reason,
                 desired_start, desired_end, requested_username, requested_password, status, radius_username, radius_password,
                 starts_at, expires_at, reviewed_by, reviewed_at, review_note, request_ip, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "approved", ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $code,
            $name,
            $email,
            $phone,
            $organization,
            $reason,
            $startsAt->format('Y-m-d'),
            $expiresAt instanceof DateTimeImmutable ? $expiresAt->format('Y-m-d') : null,
            $username,
            encrypt_secret($password),
            $username,
            encrypt_secret($password),
            $startsAt->format('Y-m-d H:i:s'),
            $expiresAt instanceof DateTimeImmutable ? $expiresAt->format('Y-m-d H:i:s') : null,
            $admin['username'],
            $sourceLabel,
            client_ip(),
            user_agent(),
        ]);
        $requestId = (int) $pdo->lastInsertId();
        audit($pdo, (int) $admin['id'], $auditAction, $requestId, 'created ' . $username);
        $pdo->commit();

        if ($sendNotice) {
            $rows = [
                '申請編號' => $code,
                '申請人' => $name,
                'Google Email' => $email,
                'RADIUS 帳號' => $username,
                '密碼' => $password,
                '啟用時間' => $startsAt->format('Y-m-d H:i:s'),
                '有效期限' => $expiresAt instanceof DateTimeImmutable ? $expiresAt->format('Y-m-d H:i:s') : '永久有效',
                '建立者' => $admin['username'],
            ];
            notify_applicant(
                $pdo,
                $email,
                '[NCUT eduroam] 臨時帳號已建立',
                '臨時帳號已建立',
                $rows,
                '請使用以下帳號密碼連線 eduroam。Android 建議使用 PEAP / MSCHAPV2。'
            );
            $adminRows = $rows;
            unset($adminRows['密碼']);
            notify_admins(
                $pdo,
                '[NCUT eduroam] 管理者已建立臨時帳號',
                '管理者已建立臨時帳號',
                $adminRows,
                '此帳號由管理者建立；通知內容不包含密碼。'
            );
        }

        return [
            'id' => $requestId,
            'code' => $code,
            'username' => $username,
            'password_generated' => $passwordGenerated,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_account(PDO $pdo, array $admin): void
{
    $result = create_account_from_admin($pdo, $admin, [
        'applicant_name' => $_POST['applicant_name'] ?? '',
        'applicant_email' => $_POST['applicant_email'] ?? '',
        'applicant_phone' => $_POST['applicant_phone'] ?? '',
        'organization' => $_POST['organization'] ?? '',
        'reason' => $_POST['reason'] ?? '',
        'radius_username' => $_POST['radius_username'] ?? '',
        'radius_password' => $_POST['radius_password'] ?? '',
        'starts_at' => $_POST['starts_at'] ?? '',
        'expires_at' => $_POST['expires_at'] ?? '',
        'permanent' => !empty($_POST['permanent']),
        'send_notice' => !empty($_POST['send_notice']),
    ], 'manual');
    $suffix = $result['password_generated'] ? (!empty($_POST['send_notice']) ? ' 密碼已自動產生並寄送通知。' : ' 密碼已自動產生。') : '';
    flash('success', '已新增帳號 ' . $result['username'] . '。' . $suffix);
}

function import_header_key(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
    $normalized = strtolower(str_replace([' ', '-', '／', '/'], '_', $header));
    $map = [
        'name' => 'applicant_name',
        'applicant_name' => 'applicant_name',
        '姓名' => 'applicant_name',
        'email' => 'applicant_email',
        'google_email' => 'applicant_email',
        'applicant_email' => 'applicant_email',
        '電子郵件' => 'applicant_email',
        'username' => 'radius_username',
        'radius_username' => 'radius_username',
        '帳號' => 'radius_username',
        'radius帳號' => 'radius_username',
        'radius_帳號' => 'radius_username',
        'password' => 'radius_password',
        'radius_password' => 'radius_password',
        '密碼' => 'radius_password',
        'organization' => 'organization',
        '單位' => 'organization',
        'phone' => 'applicant_phone',
        'applicant_phone' => 'applicant_phone',
        '電話' => 'applicant_phone',
        'starts_at' => 'starts_at',
        'start' => 'starts_at',
        '啟用時間' => 'starts_at',
        '使用起日' => 'starts_at',
        'expires_at' => 'expires_at',
        'expire' => 'expires_at',
        '到期時間' => 'expires_at',
        '使用迄止' => 'expires_at',
        '使用迄日' => 'expires_at',
        'permanent' => 'permanent',
        '永久有效' => 'permanent',
        'reason' => 'reason',
        '用途' => 'reason',
    ];
    return $map[$normalized] ?? $normalized;
}

function import_accounts(PDO $pdo, array $admin): void
{
    if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
        throw new RuntimeException('請選擇 CSV 檔案。');
    }
    $file = $_FILES['csv_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('CSV 上傳失敗，請重新選擇檔案。');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > IMPORT_MAX_BYTES) {
        throw new RuntimeException('CSV 檔案大小需介於 1 byte 到 1 MB。');
    }
    $handle = fopen((string) $file['tmp_name'], 'rb');
    if (!$handle) {
        throw new RuntimeException('無法讀取 CSV 檔案。');
    }

    $sendNotice = !empty($_POST['send_notice']);
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        throw new RuntimeException('CSV 檔案沒有標題列。');
    }
    $headers = array_map(static fn($item) => import_header_key((string) $item), $headers);
    $required = ['applicant_name', 'applicant_email', 'radius_username'];
    foreach ($required as $key) {
        if (!in_array($key, $headers, true)) {
            fclose($handle);
            throw new RuntimeException('CSV 缺少必要欄位：' . $key);
        }
    }

    $created = 0;
    $errors = [];
    $rowNumber = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if ($rowNumber > IMPORT_MAX_ROWS + 1) {
            fclose($handle);
            throw new RuntimeException('CSV 最多一次匯入 ' . IMPORT_MAX_ROWS . ' 筆資料。');
        }
        if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
            continue;
        }
        $data = [];
        foreach ($headers as $index => $key) {
            $data[$key] = trim((string) ($row[$index] ?? ''));
        }
        $data['permanent'] = truthy_form_value((string) ($data['permanent'] ?? ''));
        $data['send_notice'] = $sendNotice;
        try {
            create_account_from_admin($pdo, $admin, $data, 'import');
            $created++;
        } catch (Throwable $e) {
            $errors[] = '第 ' . $rowNumber . ' 列：' . $e->getMessage();
        }
    }
    fclose($handle);

    if ($created > 0) {
        flash('success', "匯入完成，已建立 {$created} 組帳號。");
    }
    if ($errors) {
        flash('error', '部分資料未匯入：' . implode('；', array_slice($errors, 0, 8)) . (count($errors) > 8 ? '；其餘略。' : ''));
    }
    if ($created === 0 && !$errors) {
        flash('error', 'CSV 沒有可匯入的資料列。');
    }
}

function import_sql_view_accounts(PDO $pdo, array $admin): void
{
    $views = array_filter(sql_view_list($pdo), static fn($v) => (bool) $v['enabled']);
    if (empty($views)) {
        throw new RuntimeException('尚未設定或啟用任何 SQL View 匯入來源，請先到系統設定完成設定。');
    }
    $sendNotice = !empty($_POST['send_notice']);
    $allRows = [];
    foreach ($views as $view) {
        $settings = sql_view_row_to_settings($view);
        foreach (sql_view_fetch_rows($settings, IMPORT_MAX_ROWS + 1) as $row) {
            $allRows[] = $row;
            if (count($allRows) > IMPORT_MAX_ROWS) {
                throw new RuntimeException('SQL View 最多一次匯入 ' . IMPORT_MAX_ROWS . ' 筆資料，請調整 View 篩選條件後再執行。');
            }
        }
    }
    $created = 0;
    $errors = [];
    $rowNumber = 0;
    foreach ($allRows as $row) {
        $rowNumber++;
        if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
            continue;
        }
        $data = [
            'applicant_name' => trim((string) ($row['applicant_name'] ?? '')),
            'applicant_email' => trim((string) ($row['applicant_email'] ?? '')),
            'radius_username' => trim((string) ($row['radius_username'] ?? '')),
            'radius_password' => trim((string) ($row['radius_password'] ?? '')),
            'organization' => trim((string) ($row['organization'] ?? '')),
            'applicant_phone' => trim((string) ($row['applicant_phone'] ?? '')),
            'starts_at' => trim((string) ($row['starts_at'] ?? '')),
            'expires_at' => trim((string) ($row['expires_at'] ?? '')),
            'permanent' => truthy_form_value((string) ($row['permanent'] ?? '')),
            'reason' => trim((string) ($row['reason'] ?? '')),
            'send_notice' => $sendNotice,
        ];
        try {
            create_account_from_admin($pdo, $admin, $data, 'sql_view');
            $created++;
        } catch (Throwable $e) {
            $label = $data['radius_username'] !== '' ? $data['radius_username'] : '第 ' . $rowNumber . ' 筆';
            $errors[] = $label . '：' . $e->getMessage();
        }
    }

    if ($created > 0) {
        flash('success', "SQL View 匯入完成，已建立 {$created} 組帳號。");
    }
    if ($errors) {
        flash('error', '部分 SQL View 資料未匯入：' . implode('；', array_slice($errors, 0, 8)) . (count($errors) > 8 ? '；其餘略。' : ''));
    }
    if ($created === 0 && !$errors) {
        flash('error', 'SQL View 沒有可匯入的資料。');
    }
}

function download_import_sample(): never
{
    $filename = 'ncut-eduroam-import-sample.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'wb');
    if (!$out) {
        throw new RuntimeException('無法產生 CSV 範例檔。');
    }
    fputcsv($out, ['name', 'email', 'username', 'password', 'organization', 'phone', 'starts_at', 'expires_at', 'permanent', 'reason']);
    fputcsv($out, ['王小明', 'user1@ncut.edu.tw', 'user1@ncut.edu.tw', '', '資訊中心', '04-23924505', '2026-07-01 08:00', '2026-07-31 23:59', '0', '活動臨時帳號']);
    fputcsv($out, ['陳美華', 'user2@ncut.edu.tw', 'user2@ncut.edu.tw', 'NcutTemp2026!', '教務處', '', '2026-07-01 08:00', '', '1', '永久有效測試帳號']);
    fclose($out);
    exit;
}

function approve_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $username = normalize_radius_username((string) ($_POST['radius_username'] ?? ''));
    $password = trim((string) ($_POST['radius_password'] ?? ''));
    $startsAt = parse_datetime_local((string) ($_POST['starts_at'] ?? ''));
    $expiresAt = parse_datetime_local((string) ($_POST['expires_at'] ?? ''));
    $permanent = false;
    $note = trim((string) ($_POST['review_note'] ?? ''));

    if (!validate_radius_username($username)) {
        throw new RuntimeException('帳號必須是 username@ncut.edu.tw 格式。');
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
    if ($expiresAt <= $now) {
        throw new RuntimeException('到期時間必須晚於現在。');
    }

    if ($expiresAt <= $startsAt) {
        throw new RuntimeException('使用迄止時間必須晚於啟用時間。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('找不到待審核申請，或該申請已處理。');
        }
        if ($password === '') {
            $password = decrypt_secret((string) ($request['requested_password'] ?? ''));
        }
        if ($password === '') {
            $password = generate_password();
        }
        if (strlen($password) < 8 || strlen($password) > 64) {
            throw new RuntimeException('密碼長度需介於 8 到 64 字元。');
        }
        validate_requested_period(
            $pdo,
            (string) $request['applicant_email'],
            parse_date_input($startsAt->format('Y-m-d'), '使用起日'),
            parse_date_input($expiresAt->format('Y-m-d'), '使用迄日')
        );
        if (radius_user_exists($pdo, $username)) {
            throw new RuntimeException('RADIUS 帳號已存在，請改用其他帳號。');
        }

        $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ":=", ?)');
        $stmt->execute([$username, 'Cleartext-Password', $password]);
        $expiration = radius_expiration_value($expiresAt->format('Y-m-d H:i:s'));
        $stmt->execute([$username, 'Expiration', $expiration]);
        if ($startsAt > $now) {
            $stmt->execute([$username, 'Auth-Type', 'Reject']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO userinfo
                (username, firstname, lastname, email, company, mobilephone, notes, creationdate, creationby, updatedate, updateby)
             VALUES (?, ?, "", ?, ?, ?, ?, NOW(), ?, NOW(), ?)'
        );
        $stmt->execute([
            $username,
            $request['applicant_name'],
            $request['applicant_email'],
            $request['organization'],
            $request['applicant_phone'],
            'Temporary account request ' . $request['request_code'] . ': ' . mb_substr((string) $request['reason'], 0, 120),
            $admin['username'],
            $admin['username'],
        ]);

        $stmt = $pdo->prepare(
            'UPDATE guest_account_requests
             SET status = "approved", radius_username = ?, radius_password = ?, starts_at = ?, expires_at = ?,
                  reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $username,
            encrypt_secret($password),
            $startsAt->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
            $admin['username'],
            $note,
            $id,
        ]);

        audit($pdo, (int) $admin['id'], 'approve', $id, "approved {$username}");
        $pdo->commit();
        $expiresLabel = $permanent ? '永久有效' : $expiresAt->format('Y-m-d H:i:s');
        $rows = [
            '申請編號' => $request['request_code'],
            '申請人' => $request['applicant_name'],
            'Google Email' => $request['applicant_email'],
            'RADIUS 帳號' => $username,
            '密碼' => $password,
            '有效期限' => $expiresLabel,
            '審核者' => $admin['username'],
            '審核備註' => $note,
        ];
        $rows['啟用時間'] = $startsAt->format('Y-m-d H:i:s');
        $rows['到期時間'] = $expiresAt->format('Y-m-d H:i:s');
        $adminRows = $rows;
        unset($adminRows['密碼']);

        notify_applicant(
            $pdo,
            $request['applicant_email'],
            '[NCUT eduroam] 臨時帳號已開通',
            '臨時帳號已開通',
            $rows,
            '請使用以下帳號密碼連線 eduroam。Android 建議使用 PEAP / MSCHAPV2。'
        );
        notify_admins(
            $pdo,
            '[NCUT eduroam] 臨時帳號已核准',
            '臨時帳號已核准',
            $adminRows,
            '此申請已完成開通。'
        );
        flash('success', "已核准 {$username}。");
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function reject_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['review_note'] ?? ''));
    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? AND status = "pending"');
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new RuntimeException('找不到待退回申請。');
    }
    $stmt = $pdo->prepare(
        'UPDATE guest_account_requests
         SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
         WHERE id = ? AND status = "pending"'
    );
    $stmt->execute([$admin['username'], $note, $id]);
    audit($pdo, (int) $admin['id'], 'reject', $id, 'rejected request');
    $rows = [
        '申請編號' => $request['request_code'],
        '申請人' => $request['applicant_name'],
        'Google Email' => $request['applicant_email'],
        '希望帳號' => $request['requested_username'],
        '審核者' => $admin['username'],
        '退回原因' => $note,
    ];
    notify_applicant(
        $pdo,
        $request['applicant_email'],
        '[NCUT eduroam] 臨時帳號申請未通過',
        '臨時帳號申請未通過',
        $rows,
        '您的臨時帳號申請未通過，如需使用請重新申請或聯絡管理者。'
    );
    notify_admins(
        $pdo,
        '[NCUT eduroam] 臨時帳號申請已退回',
        '臨時帳號申請已退回',
        $rows,
        '此申請已由管理者退回。'
    );
    flash('success', '已退回申請。');
}

function delete_radius_rows(PDO $pdo, string $username): void
{
    foreach (['radcheck', 'radreply', 'radusergroup', 'userinfo'] as $table) {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE username = ?");
        $stmt->execute([$username]);
    }
}

function disable_account(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'approved' || empty($request['radius_username'])) {
            throw new RuntimeException('只能停用已核准帳號。');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ? AND attribute = "Auth-Type" AND value = "Reject"');
        $stmt->execute([$request['radius_username']]);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Auth-Type", ":=", "Reject")');
            $stmt->execute([$request['radius_username']]);
        }

        $stmt = $pdo->prepare('UPDATE guest_account_requests SET status = "disabled", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        audit($pdo, (int) $admin['id'], 'disable', $id, 'disabled ' . $request['radius_username']);
        $pdo->commit();
        flash('success', '帳號已停用。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function enable_account(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'disabled' || empty($request['radius_username'])) {
            throw new RuntimeException('只能啟用已停用帳號。');
        }
        if (!empty($request['expires_at']) && new DateTimeImmutable($request['expires_at']) <= new DateTimeImmutable('now')) {
            throw new RuntimeException('帳號已過期，請先延長期限。');
        }
        if (!empty($request['starts_at']) && new DateTimeImmutable($request['starts_at'], new DateTimeZone('Asia/Taipei')) > new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'))) {
            throw new RuntimeException('尚未到預約啟用時間，不能提前啟用。');
        }

        $stmt = $pdo->prepare('DELETE FROM radcheck WHERE username = ? AND attribute = "Auth-Type" AND value = "Reject"');
        $stmt->execute([$request['radius_username']]);

        $stmt = $pdo->prepare('UPDATE guest_account_requests SET status = "approved", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        audit($pdo, (int) $admin['id'], 'enable', $id, 'enabled ' . $request['radius_username']);
        $pdo->commit();
        flash('success', '帳號已重新啟用。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_account(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || !in_array($request['status'], ['approved', 'disabled'], true) || empty($request['radius_username'])) {
            throw new RuntimeException('只能刪除已開通或已停用的帳號。');
        }

        delete_radius_rows($pdo, (string) $request['radius_username']);

        $stmt = $pdo->prepare('UPDATE guest_account_requests SET status = "deleted", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        audit($pdo, (int) $admin['id'], 'delete', $id, 'deleted ' . $request['radius_username']);
        $pdo->commit();
        flash('success', '帳號已刪除，RADIUS 登入資料已移除。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function disable_closed_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || !in_array($request['status'], ['rejected', 'deleted'], true)) {
            throw new RuntimeException('只能停用已退回或已刪除的申請紀錄。');
        }
        if ($request['status'] === 'deleted') {
            throw new RuntimeException('此申請紀錄已停用。');
        }

        if (!empty($request['radius_username'])) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
            $stmt->execute([$request['radius_username']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ? AND attribute = "Auth-Type" AND value = "Reject"');
                $stmt->execute([$request['radius_username']]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Auth-Type", ":=", "Reject")');
                    $stmt->execute([$request['radius_username']]);
                }
            }
        }

        $stmt = $pdo->prepare('UPDATE guest_account_requests SET status = "deleted", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        audit($pdo, (int) $admin['id'], 'disable_closed_request', $id, 'disabled closed request ' . $request['request_code']);
        $pdo->commit();
        flash('success', '已停用此退回申請紀錄。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_closed_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || !in_array($request['status'], ['rejected', 'deleted'], true)) {
            throw new RuntimeException('只能刪除已退回或已刪除的申請紀錄。');
        }

        if (!empty($request['radius_username'])) {
            delete_radius_rows($pdo, (string) $request['radius_username']);
        }

        audit($pdo, (int) $admin['id'], 'delete_closed_request', $id, 'deleted closed request ' . $request['request_code']);
        $stmt = $pdo->prepare('DELETE FROM guest_account_requests WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->commit();
        flash('success', '已刪除此申請紀錄。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function reset_password(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $password = trim((string) ($_POST['radius_password'] ?? ''));
    if ($password === '') {
        $password = generate_password();
    }
    if (strlen($password) < 8 || strlen($password) > 64) {
        throw new RuntimeException('密碼長度需介於 8 到 64 字元。');
    }

    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ?');
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    if (!$request || empty($request['radius_username'])) {
        throw new RuntimeException('找不到可重設密碼的帳號。');
    }

    $stmt = $pdo->prepare('UPDATE radcheck SET value = ? WHERE username = ? AND attribute = "Cleartext-Password"');
    $stmt->execute([$password, $request['radius_username']]);
    $stmt = $pdo->prepare('UPDATE guest_account_requests SET radius_password = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([encrypt_secret($password), $id]);
    audit($pdo, (int) $admin['id'], 'reset_password', $id, 'reset password for ' . $request['radius_username']);
    flash('success', "已重設 {$request['radius_username']} 密碼。");
}

function extend_account(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $permanent = !empty($_POST['permanent']);
    $expiresAt = null;
    if (!$permanent) {
        $expiresAt = parse_datetime_local((string) ($_POST['expires_at'] ?? ''));
    }
    if (!$permanent && $expiresAt <= new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'))) {
        throw new RuntimeException('到期時間必須晚於現在。');
    }

    $stmt = $pdo->prepare('SELECT * FROM guest_account_requests WHERE id = ?');
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    if (!$request || empty($request['radius_username'])) {
        throw new RuntimeException('找不到可延長期限的帳號。');
    }

    if ($permanent) {
        $stmt = $pdo->prepare('DELETE FROM radcheck WHERE username = ? AND attribute = "Expiration"');
        $stmt->execute([$request['radius_username']]);
        $stmt = $pdo->prepare('UPDATE guest_account_requests SET expires_at = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    } else {
        $expiration = radius_expiration_value($expiresAt->format('Y-m-d H:i:s'));
        $stmt = $pdo->prepare('UPDATE radcheck SET value = ? WHERE username = ? AND attribute = "Expiration"');
        $stmt->execute([$expiration, $request['radius_username']]);
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Expiration", ":=", ?)');
            $stmt->execute([$request['radius_username'], $expiration]);
        }
        $stmt = $pdo->prepare('UPDATE guest_account_requests SET expires_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$expiresAt->format('Y-m-d H:i:s'), $id]);
    }
    audit($pdo, (int) $admin['id'], 'extend', $id, 'extended ' . $request['radius_username']);
    flash('success', '帳號期限已更新。');
}

function approve_extension_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['review_note'] ?? ''));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT er.*, ar.starts_at, ar.desired_start, ar.expires_at, ar.request_code, ar.applicant_name, ar.radius_username AS account_username
             FROM guest_account_extension_requests er
             JOIN guest_account_requests ar ON ar.id = er.request_id
             WHERE er.id = ?
             FOR UPDATE'
        );
        $stmt->execute([$id]);
        $extension = $stmt->fetch();
        if (!$extension || $extension['status'] !== 'pending') {
            throw new RuntimeException('找不到待審展延申請。');
        }
        if (empty($extension['account_username']) || $extension['account_username'] !== $extension['radius_username']) {
            throw new RuntimeException('展延申請對應的 RADIUS 帳號不正確。');
        }
        if (empty($extension['expires_at'])) {
            throw new RuntimeException('永久有效帳號不需要展延。');
        }

        $startDate = !empty($extension['starts_at'])
            ? (new DateTimeImmutable((string) $extension['starts_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0)
            : (!empty($extension['desired_start'])
                ? parse_date_input((string) $extension['desired_start'], '使用起日')
                : new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')));
        $currentEndDate = (new DateTimeImmutable((string) $extension['expires_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0);
        $requestedEndDate = (new DateTimeImmutable((string) $extension['requested_expires_at'], new DateTimeZone('Asia/Taipei')))->setTime(0, 0, 0);
        validate_extension_period($pdo, (string) $extension['applicant_email'], $startDate, $currentEndDate, $requestedEndDate);

        $newExpiresAt = date_to_expires_at($requestedEndDate);
        $expiration = radius_expiration_value($newExpiresAt);
        $stmt = $pdo->prepare('UPDATE radcheck SET value = ? WHERE username = ? AND attribute = "Expiration"');
        $stmt->execute([$expiration, $extension['radius_username']]);
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Expiration", ":=", ?)');
            $stmt->execute([$extension['radius_username'], $expiration]);
        }

        $stmt = $pdo->prepare('UPDATE guest_account_requests SET expires_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newExpiresAt, (int) $extension['request_id']]);
        $stmt = $pdo->prepare(
            'UPDATE guest_account_extension_requests
             SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$admin['username'], $note, $id]);
        audit($pdo, (int) $admin['id'], 'approve_extension', (int) $extension['request_id'], 'approved extension for ' . $extension['radius_username']);
        $pdo->commit();

        $rows = [
            '申請編號' => $extension['request_code'],
            'RADIUS 帳號' => $extension['radius_username'],
            '原到期時間' => $extension['expires_at'],
            '新到期時間' => $newExpiresAt,
            '審核者' => $admin['username'],
            '審核備註' => $note,
        ];
        notify_applicant(
            $pdo,
            $extension['applicant_email'],
            '[NCUT eduroam] 臨時帳號展延已核准',
            '臨時帳號展延已核准',
            $rows,
            '您的 eduroam 臨時帳號期限已更新。'
        );
        notify_admins(
            $pdo,
            '[NCUT eduroam] 臨時帳號展延已核准',
            '臨時帳號展延已核准',
            $rows,
            '此展延申請已完成處理。'
        );
        flash('success', '展延申請已核准。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function reject_extension_request(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['review_note'] ?? ''));
    $stmt = $pdo->prepare(
        'SELECT er.*, ar.request_code
         FROM guest_account_extension_requests er
         JOIN guest_account_requests ar ON ar.id = er.request_id
         WHERE er.id = ? AND er.status = "pending"'
    );
    $stmt->execute([$id]);
    $extension = $stmt->fetch();
    if (!$extension) {
        throw new RuntimeException('找不到待退回展延申請。');
    }

    $stmt = $pdo->prepare(
        'UPDATE guest_account_extension_requests
         SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
         WHERE id = ? AND status = "pending"'
    );
    $stmt->execute([$admin['username'], $note, $id]);
    audit($pdo, (int) $admin['id'], 'reject_extension', (int) $extension['request_id'], 'rejected extension for ' . $extension['radius_username']);

    $rows = [
        '申請編號' => $extension['request_code'],
        'RADIUS 帳號' => $extension['radius_username'],
        '目前到期' => $extension['current_expires_at'],
        '申請展延至' => $extension['requested_expires_at'],
        '退回原因' => $note,
    ];
    notify_applicant(
        $pdo,
        $extension['applicant_email'],
        '[NCUT eduroam] 臨時帳號展延未通過',
        '臨時帳號展延未通過',
        $rows,
        '您的展延申請未通過，如仍需使用請聯絡管理者。'
    );
    flash('success', '展延申請已退回。');
}

function create_admin(PDO $pdo, array $admin): void
{
    $email = strtolower(trim((string) ($_POST['new_admin_email'] ?? '')));
    $adminId = upsert_google_admin($pdo, $email, $email);
    audit($pdo, (int) $admin['id'], 'create_admin', null, 'granted google admin ' . $email);
    flash('success', "已新增 Google 管理員 {$email}。");
}

function add_allowed_domain(PDO $pdo, array $admin): void
{
    $domain = normalize_domain((string) ($_POST['domain'] ?? ''));
    if (!validate_domain($domain)) {
        throw new RuntimeException('請輸入有效網域，例如 gmail.com。');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO guest_allowed_domains (domain, enabled, created_by, created_at, updated_at)
         VALUES (?, 1, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE enabled = 1, updated_at = NOW()'
    );
    $stmt->execute([$domain, $admin['username']]);
    audit($pdo, (int) $admin['id'], 'add_allowed_domain', null, 'allowed domain ' . $domain);
    flash('success', "已加入允許申請網域 {$domain}。");
}

function remove_allowed_domain(PDO $pdo, array $admin): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT domain FROM guest_allowed_domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetchColumn();
    if (!$domain) {
        throw new RuntimeException('找不到指定網域。');
    }
    $stmt = $pdo->prepare('UPDATE guest_allowed_domains SET enabled = 0, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    audit($pdo, (int) $admin['id'], 'remove_allowed_domain', null, 'removed allowed domain ' . $domain);
    flash('success', "已移除允許申請網域 {$domain}。");
}

function admin_view_for_action(string $action): string
{
    return match ($action) {
        'create_account' => 'create',
        'import_accounts', 'import_sql_view_accounts' => 'import',
        'disable', 'enable', 'delete', 'reset_password', 'extend' => 'accounts',
        'disable_closed_request', 'delete_closed_request' => 'closed',
        'approve', 'reject', 'approve_extension', 'reject_extension' => 'queue',
        default => 'queue',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        verify_csrf();

        if ($action === 'login') {
            security_audit($pdo, 'admin_password_login_blocked', 'blocked legacy password login attempt');
            throw new RuntimeException('密碼登入已停用，請使用 Google 管理者帳號登入。');
        }

        if ($action === 'firebase_login') {
            assert_auth_rate_limit($pdo);
            $idToken = (string) ($_POST['id_token'] ?? '');
            if ($idToken === '') {
                security_audit($pdo, 'admin_token_failed', 'empty firebase id token');
                throw new RuntimeException('未收到 Google 登入 Token。');
            }
            try {
                $googleUser = verify_firebase_id_token($idToken);
            } catch (Throwable $e) {
                security_audit($pdo, 'admin_token_failed', 'firebase token verification failed: ' . $e->getMessage());
                throw $e;
            }
            $adminId = google_admin_id($pdo, $googleUser['email']);
            if (!$adminId) {
                security_audit($pdo, 'admin_google_denied', 'unauthorized google admin login: ' . $googleUser['email']);
                throw new RuntimeException('此 Google 帳號未被授權管理。');
            }
            $now = time();
            session_regenerate_id(true);
            $_SESSION['auth'] = [
                'email'    => strtolower($googleUser['email']),
                'name'     => $googleUser['name'],
                'picture'  => $googleUser['picture'],
                'sub'      => $googleUser['sub'],
                'is_admin' => true,
                'admin_id' => $adminId,
                'authenticated_at' => $now,
                'last_activity' => $now,
            ];
            $pdo->prepare('UPDATE guest_account_admins SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$adminId]);
            audit($pdo, (int) $adminId, 'admin_login', null, 'google admin login');
            flash('success', 'Google 登入成功。');
            redirect(consume_admin_return_to());
        }

        if ($action === 'logout') {
            unset($_SESSION['auth']);
            $_SESSION['firebase_signed_out'] = true;
            flash('success', '已登出。');
            redirect('/admin.php');
        }

        $admin = require_admin();
        match ($action) {
            'create_account' => create_account($pdo, $admin),
            'import_accounts' => import_accounts($pdo, $admin),
            'import_sql_view_accounts' => import_sql_view_accounts($pdo, $admin),
            'approve' => approve_request($pdo, $admin),
            'reject' => reject_request($pdo, $admin),
            'disable' => disable_account($pdo, $admin),
            'enable' => enable_account($pdo, $admin),
            'delete' => delete_account($pdo, $admin),
            'disable_closed_request' => disable_closed_request($pdo, $admin),
            'delete_closed_request' => delete_closed_request($pdo, $admin),
            'reset_password' => reset_password($pdo, $admin),
            'extend' => extend_account($pdo, $admin),
            'approve_extension' => approve_extension_request($pdo, $admin),
            'reject_extension' => reject_extension_request($pdo, $admin),
            default => throw new RuntimeException('未知的管理動作。'),
        };
        redirect('/admin.php?view=' . admin_view_for_action($action));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin.php?view=' . admin_view_for_action($action));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['action'] ?? '') === 'download_import_sample') {
    require_admin();
    download_import_sample();
}

$admin = admin_user();
render_header('管理者後台 - ' . APP_NAME, true);

if (!$admin): ?>
<section class="panel narrow">
    <h1>管理者登入</h1>
    <form method="post" id="firebase-login-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="firebase_login">
        <input type="hidden" name="id_token" id="firebase-id-token">
        <button type="button" class="google-button" id="google-login-button">
            <span class="google-mark">G</span>
            使用 Google 登入
        </button>
        <p class="muted small">僅允許已授權的 Google 管理者帳號登入。</p>
    </form>
</section>
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

const button = document.getElementById("google-login-button");
const tokenInput = document.getElementById("firebase-id-token");
const form = document.getElementById("firebase-login-form");
let suppressAutoLogin = new URLSearchParams(window.location.search).has("signed_out") || <?= !empty($_SESSION['firebase_signed_out']) ? 'true' : 'false' ?>;
<?php unset($_SESSION['firebase_signed_out']); ?>

const submitFirebaseUser = async (user, forceRefresh = false) => {
    if (!user || !tokenInput || !form || form.dataset.submitting === "1") {
        return;
    }
    form.dataset.submitting = "1";
    try {
        tokenInput.value = await user.getIdToken(forceRefresh);
        form.submit();
    } catch (error) {
        form.dataset.submitting = "0";
        throw error;
    }
};

onAuthStateChanged(auth, async (user) => {
    await authReady;
    if (suppressAutoLogin) {
        if (user) {
            await signOut(auth);
        }
        return;
    }
    if (user) {
        await submitFirebaseUser(user, false);
    }
});

button?.addEventListener("click", async () => {
    button.disabled = true;
    button.textContent = "Google 登入中...";
    try {
        await authReady;
        suppressAutoLogin = false;
        const result = await signInWithPopup(auth, provider);
        await submitFirebaseUser(result.user, true);
    } catch (error) {
        button.disabled = false;
        button.innerHTML = '<span class="google-mark">G</span>使用 Google 登入';
        alert(error?.message || "Google 登入失敗");
    }
});
</script>
<?php
render_footer();
exit;
endif;

$pending = $pdo->query('SELECT * FROM guest_account_requests WHERE status = "pending" ORDER BY created_at ASC')->fetchAll();
$pendingExtensions = $pdo->query(
    'SELECT er.*, ar.request_code, ar.applicant_name, ar.organization, ar.starts_at, ar.expires_at
     FROM guest_account_extension_requests er
     JOIN guest_account_requests ar ON ar.id = er.request_id
     WHERE er.status = "pending"
     ORDER BY er.created_at ASC'
)->fetchAll();
$managed = $pdo->query('SELECT * FROM guest_account_requests WHERE status IN ("approved", "disabled") ORDER BY updated_at DESC LIMIT 100')->fetchAll();
$closed = $pdo->query('SELECT * FROM guest_account_requests WHERE status IN ("rejected", "deleted") ORDER BY updated_at DESC LIMIT 50')->fetchAll();
$sqlViews = sql_view_list($pdo);
$enabledSvCount = count(array_filter($sqlViews, static fn($v) => (bool) $v['enabled']));
$defaultStarts = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i');
$defaultExpires = (new DateTimeImmutable('+7 days', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i');
$adminViews = [
    'queue' => ['label' => '待處理', 'count' => count($pending) + count($pendingExtensions)],
    'accounts' => ['label' => '已開通帳號', 'count' => count($managed)],
    'create' => ['label' => '手動新增', 'count' => null],
    'import' => ['label' => '批次匯入', 'count' => null],
    'closed' => ['label' => '歷史紀錄', 'count' => count($closed)],
];
$view = (string) ($_GET['view'] ?? 'queue');
if (!array_key_exists($view, $adminViews)) {
    $view = 'queue';
}
?>
<section class="dashboard-head">
    <div>
        <h1>臨時帳號管理</h1>
        <p>審核申請後才會寫入 FreeRADIUS。帳號需使用 <code>@ncut.edu.tw</code> realm。</p>
    </div>
    <div class="stats">
        <span><strong><?= count($pending) ?></strong> 待審</span>
        <span><strong><?= count($pendingExtensions) ?></strong> 展延待審</span>
        <span><strong><?= count($managed) ?></strong> 管理中</span>
    </div>
</section>

<nav class="tabbar admin-workspace-tabs" aria-label="臨時帳號管理工作區">
    <?php foreach ($adminViews as $key => $item): ?>
        <a class="<?= $key === $view ? 'active' : '' ?>" href="<?= e('/admin.php?' . http_build_query(['view' => $key])) ?>">
            <span><?= e($item['label']) ?></span>
            <?php if ($item['count'] !== null): ?>
                <strong><?= (int) $item['count'] ?></strong>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'create'): ?>
<section class="panel">
    <h2>新增帳號</h2>
    <form method="post" class="form-grid expires-control">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_account">
        <label>
            <span>姓名</span>
            <input name="applicant_name" required maxlength="128" autocomplete="name">
        </label>
        <label>
            <span>Email</span>
            <input type="email" name="applicant_email" required maxlength="200" autocomplete="email">
        </label>
        <label>
            <span>RADIUS 帳號</span>
            <input name="radius_username" required maxlength="64" placeholder="username@ncut.edu.tw">
        </label>
        <label>
            <span>密碼</span>
            <input type="password" name="radius_password" minlength="8" maxlength="64" placeholder="空白自動產生" autocomplete="new-password">
        </label>
        <label>
            <span>單位</span>
            <input name="organization" required maxlength="200" placeholder="例如：資訊中心 / 活動名稱">
        </label>
        <label>
            <span>電話</span>
            <input name="applicant_phone" maxlength="64">
        </label>
        <label>
            <span>啟用時間</span>
            <input type="datetime-local" name="starts_at" value="<?= e($defaultStarts) ?>" required>
        </label>
        <label>
            <span>使用迄止</span>
            <input type="datetime-local" name="expires_at" value="<?= e($defaultExpires) ?>" class="expires-input" required>
        </label>
        <label class="wide">
            <span>用途 / 備註</span>
            <textarea name="reason" rows="3" maxlength="2000" placeholder="管理者新增帳號"></textarea>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="permanent" value="1" class="permanent-checkbox">
            <span>永久有效</span>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="send_notice" value="1" checked>
            <span>建立後寄送帳密通知給使用者</span>
        </label>
        <div class="actions wide">
            <button type="submit" class="primary">新增並開通</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($view === 'import'): ?>
<section class="panel">
    <div class="section-title-row">
        <div>
            <h2>匯入帳號</h2>
            <p class="muted small">請上傳 UTF-8 CSV。必要欄位：<code>name,email,username</code>；可選欄位：<code>password,organization,phone,starts_at,expires_at,permanent,reason</code>。密碼空白會自動產生。</p>
        </div>
        <a class="secondary button-link" href="/admin.php?action=download_import_sample">下載 CSV 範例</a>
    </div>
    <form method="post" class="stack" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_accounts">
        <label>
            <span>CSV 檔案</span>
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="send_notice" value="1" checked>
            <span>匯入後寄送帳密通知給使用者</span>
        </label>
        <div class="csv-help">
            <code>name,email,username,password,organization,phone,starts_at,expires_at,permanent,reason</code>
            <code>王小明,user@ncut.edu.tw,user@ncut.edu.tw,,資訊中心,,2026-07-01 08:00,2026-07-31 23:59,0,活動臨時帳號</code>
        </div>
        <div class="actions">
            <button type="submit" class="primary">匯入並開通</button>
        </div>
    </form>
    <div class="divider">或</div>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_sql_view_accounts">
        <div class="csv-help">
            <strong>SQL View 匯入來源</strong>
            <?php if ($enabledSvCount > 0): ?>
            <code>已啟用 <?= $enabledSvCount ?> 個來源：<?= implode('、', array_map(
                fn($v) => e($v['name']),
                array_filter($sqlViews, static fn($v) => (bool) $v['enabled'])
            )) ?></code>
            <?php else: ?>
            <code>尚未啟用任何來源，請先到系統設定完成 SQL View 串接設定</code>
            <?php endif; ?>
            <a href="/admin-settings.php#sv-panel">前往設定 SQL View 來源</a>
        </div>
        <label class="inline-check">
            <input type="checkbox" name="send_notice" value="1" checked>
            <span>匯入後寄送帳密通知給使用者</span>
        </label>
        <div class="actions">
            <button type="submit" class="primary" <?= $enabledSvCount > 0 ? '' : 'disabled' ?>>從 SQL View 匯入並開通</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($view === 'queue'): ?>
<section class="panel">
    <h2>展延待審</h2>
    <?php if (!$pendingExtensions): ?>
        <p class="muted">目前沒有待審展延申請。</p>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($pendingExtensions as $item): ?>
                <article class="request-card">
                    <div class="card-title">
                        <strong><?= e($item['applicant_name']) ?></strong>
                        <span>EXT-<?= (int) $item['id'] ?></span>
                    </div>
                    <dl>
                        <dt>原申請</dt><dd><?= e($item['request_code']) ?></dd>
                        <dt>Email</dt><dd><?= e($item['applicant_email']) ?></dd>
                        <dt>帳號</dt><dd><code><?= e($item['radius_username']) ?></code></dd>
                        <dt>單位</dt><dd><?= e($item['organization']) ?></dd>
                        <dt>目前到期</dt><dd><?= e($item['expires_at']) ?></dd>
                        <dt>申請展延至</dt><dd><?= e($item['requested_expires_at']) ?></dd>
                        <dt>原因</dt><dd><?= nl2br(e($item['reason'])) ?></dd>
                    </dl>
                    <form method="post" class="approval-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve_extension">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <label>
                            <span>審核備註</span>
                            <input name="review_note" maxlength="500">
                        </label>
                        <div class="actions">
                            <button type="submit" class="primary">核准展延</button>
                        </div>
                    </form>
                    <form method="post" class="reject-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject_extension">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input name="review_note" placeholder="退回原因">
                        <button type="submit" class="secondary">退回</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>待審申請</h2>
    <?php if (!$pending): ?>
        <p class="muted">目前沒有待審申請。</p>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($pending as $item): ?>
                <?php
                $suggested = $item['requested_username'] ?: generate_guest_username($pdo);
                $suggestedStart = !empty($item['desired_start'])
                    ? (new DateTimeImmutable($item['desired_start'] . ' 00:00:00', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i')
                    : (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i');
                $suggestedEnd = !empty($item['desired_end'])
                    ? (new DateTimeImmutable($item['desired_end'] . ' 23:59:00', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i')
                    : $defaultExpires;
                ?>
                <article class="request-card">
                    <div class="card-title">
                        <strong><?= e($item['applicant_name']) ?></strong>
                        <span><?= e($item['request_code']) ?></span>
                    </div>
                    <dl>
                        <dt>Email</dt><dd><?= e($item['applicant_email']) ?></dd>
                        <dt>帳號</dt><dd><code><?= e($item['requested_username'] ?: '-') ?></code></dd>
                        <dt>電話</dt><dd><?= e($item['applicant_phone'] ?: '-') ?></dd>
                        <dt>單位</dt><dd><?= e($item['organization']) ?></dd>
                        <dt>期限</dt><dd><?= e($item['desired_start']) ?> 到 <?= e($item['desired_end']) ?></dd>
                        <dt>密碼</dt><dd><span class="muted small">不顯示</span></dd>
                        <dt>用途</dt><dd><?= nl2br(e($item['reason'])) ?></dd>
                    </dl>
                    <form method="post" class="approval-form expires-control">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <label>
                            <span>RADIUS 帳號</span>
                            <input name="radius_username" value="<?= e($suggested) ?>" required>
                        </label>
                        <label>
                            <span>密碼</span>
                            <input type="password" name="radius_password" placeholder="空白則使用申請者設定密碼" autocomplete="new-password">
                        </label>
                        <label>
                            <span>啟用時間</span>
                            <input type="datetime-local" name="starts_at" value="<?= e($suggestedStart) ?>" required>
                        </label>
                        <label>
                            <span>使用迄止</span>
                            <input type="datetime-local" name="expires_at" value="<?= e($suggestedEnd) ?>" class="expires-input" required>
                        </label>
                        <label class="inline-check" hidden>
                            <input type="checkbox" value="1" disabled>
                            <span>永久有效</span>
                        </label>
                        <label>
                            <span>審核備註</span>
                            <input name="review_note" maxlength="500">
                        </label>
                        <div class="actions">
                            <button type="submit" class="primary">核准開通</button>
                        </div>
                    </form>
                    <form method="post" class="reject-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input name="review_note" placeholder="退回原因">
                        <button type="submit" class="secondary">退回</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($view === 'accounts'): ?>
<section class="panel">
    <h2>已開通帳號</h2>
    <?php if (!$managed): ?>
        <p class="muted">尚未開通臨時帳號。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>狀態</th>
                    <th>帳號</th>
                    <th>啟用</th>
                    <th>申請人</th>
                    <th>到期</th>
                    <th>管理</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($managed as $item): ?>
                    <tr>
                        <td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
                        <td><code><?= e($item['radius_username']) ?></code></td>
                        <td><?= e($item['starts_at'] ?: '立即') ?></td>
                        <td><?= e($item['applicant_name']) ?></td>
                        <td><?= e($item['expires_at'] ?: '永久有效') ?></td>
                        <td class="manage-cell">
                            <details class="manage-menu">
                                <summary>管理操作</summary>
                                <div class="manage-actions">
                                    <div class="action-group">
                                        <div class="action-title">狀態</div>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $item['status'] === 'disabled' ? 'enable' : 'disable' ?>">
                                            <button class="secondary" type="submit"><?= $item['status'] === 'disabled' ? '啟用帳號' : '停用帳號' ?></button>
                                        </form>
                                    </div>
                                    <div class="action-group">
                                        <div class="action-title">密碼</div>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="password" name="radius_password" placeholder="新密碼，空白自動產生" autocomplete="new-password">
                                            <button class="secondary" type="submit">改密碼</button>
                                        </form>
                                    </div>
                                    <div class="action-group">
                                        <div class="action-title">效期</div>
                                        <form method="post" class="expires-control">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="extend">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="datetime-local" name="expires_at" class="expires-input" required>
                                            <label class="inline-check">
                                                <input type="checkbox" name="permanent" value="1" class="permanent-checkbox">
                                                <span>永久有效</span>
                                            </label>
                                            <button class="secondary" type="submit">更新效期</button>
                                        </form>
                                    </div>
                                    <div class="action-group danger-zone">
                                        <div class="action-title">刪除</div>
                                        <form method="post" onsubmit="return confirm('確定要刪除這個臨時帳號？此動作會移除 RADIUS 登入資料。');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button class="danger" type="submit">刪除帳號</button>
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
</section>
<?php endif; ?>

<?php if ($view === 'closed'): ?>
<section class="panel">
    <h2>已退回申請</h2>
    <?php if (!$closed): ?>
        <p class="muted">目前沒有退回紀錄。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>狀態</th><th>申請編號</th><th>姓名</th><th>Email</th><th>單位</th><th>原因</th><th>處理時間</th><th>管理</th></tr></thead>
                <tbody>
                <?php foreach ($closed as $item): ?>
                    <tr>
                        <td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
                        <td><?= e($item['request_code']) ?></td>
                        <td><?= e($item['applicant_name']) ?></td>
                        <td><?= e($item['applicant_email']) ?></td>
                        <td><?= e($item['organization']) ?></td>
                        <td><?= e($item['review_note']) ?></td>
                        <td><?= e($item['updated_at']) ?></td>
                        <td>
                            <div class="inline-actions">
                                <?php if ($item['status'] === 'rejected'): ?>
                                    <form method="post" onsubmit="return confirm('確定要停用此退回申請紀錄？');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="disable_closed_request">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button class="secondary" type="submit">停用</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted small">已停用</span>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('確定要刪除此申請紀錄？此動作會從管理清單移除該筆資料。');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_closed_request">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button class="danger" type="submit">刪除</button>
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
<?php endif; ?>
<script>
document.querySelectorAll(".expires-control").forEach((form) => {
    const checkbox = form.querySelector(".permanent-checkbox");
    const input = form.querySelector(".expires-input");
    if (!checkbox || !input) {
        return;
    }
    const sync = () => {
        input.required = !checkbox.checked;
        input.disabled = checkbox.checked;
        if (checkbox.checked) {
            input.value = "";
        }
    };
    checkbox.addEventListener("change", sync);
    sync();
});
</script>
<?php
render_footer();
