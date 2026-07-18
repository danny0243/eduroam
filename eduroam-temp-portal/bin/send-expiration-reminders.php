<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

const PORTAL_URL = 'https://eduroam.ncut.edu.tw/';
const REMINDER_DAYS = [5, 3];

function reminder_key(int $daysBefore, string $expiresAt): string
{
    return sprintf('expiration:%d:%s', $daysBefore, $expiresAt);
}

function reminder_body(int $daysBefore, string $expiresAt): string
{
    return "您的 eduroam 臨時帳號將於 {$expiresAt} 到期，距離到期約 {$daysBefore} 天。\n"
        . "如需繼續使用，請登入申請系統，在「我的申請紀錄」送出展延申請。\n"
        . "若您已不需要使用，可以忽略此通知。";
}

function reminder_html(string $title, array $rows, string $body): string
{
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.55;color:#1d2635">';
    $html .= '<h2 style="margin:0 0 16px">' . e($title) . '</h2>';
    $html .= '<p>' . nl2br(e($body)) . '</p>';
    $html .= '<p style="margin:20px 0"><a href="' . e(PORTAL_URL) . '" style="display:inline-block;background:#1667c7;color:#ffffff;text-decoration:none;border-radius:6px;padding:10px 16px;font-weight:bold">登入申請展延</a></p>';
    $html .= '<table style="border-collapse:collapse;width:100%;max-width:720px">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th style="text-align:left;border:1px solid #dbe2ea;background:#f7f9fc;padding:8px;width:150px">' . e((string) $label) . '</th>';
        $html .= '<td style="border:1px solid #dbe2ea;padding:8px">' . nl2br(e((string) $value)) . '</td></tr>';
    }
    $html .= '</table>';
    $html .= '<p style="color:#6b7787;font-size:13px;margin-top:18px">NCUT eduroam 臨時帳號申請系統</p>';
    $html .= '</body></html>';
    return $html;
}

function send_expiration_reminder(PDO $pdo, array $account, int $daysBefore): void
{
    $expiresAt = (string) $account['expires_at'];
    $notificationKey = reminder_key($daysBefore, $expiresAt);
    $rows = [
        '申請編號' => (string) $account['request_code'],
        'RADIUS 帳號' => (string) $account['radius_username'],
        '目前到期時間' => $expiresAt,
        '剩餘天數' => $daysBefore . ' 天',
        '申請展延網址' => PORTAL_URL,
    ];

    send_portal_mail($pdo, [
        'to' => [(string) $account['applicant_email']],
        'subject' => "[NCUT eduroam] 臨時帳號將於 {$daysBefore} 天後到期",
        'text' => text_mail('eduroam 臨時帳號即將到期', $rows, reminder_body($daysBefore, $expiresAt)),
        'html' => reminder_html('eduroam 臨時帳號即將到期', $rows, reminder_body($daysBefore, $expiresAt)),
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO guest_account_notifications
            (request_id, notification_type, notification_key, recipient_email, status, sent_at, created_at)
         VALUES (?, "expiration_reminder", ?, ?, "sent", NOW(), NOW())'
        . ' ON DUPLICATE KEY UPDATE
             recipient_email = VALUES(recipient_email),
             status = "sent",
             error_message = "",
             sent_at = NOW()'
    );
    $stmt->execute([(int) $account['id'], $notificationKey, (string) $account['applicant_email']]);

    $stmt = $pdo->prepare(
        'INSERT INTO guest_account_audit (admin_id, action, request_id, message, ip_address, created_at)
         VALUES (NULL, "expiration_reminder", ?, ?, "127.0.0.1", NOW())'
    );
    $stmt->execute([(int) $account['id'], "sent {$daysBefore}-day expiration reminder to " . $account['applicant_email']]);
}

$pdo = db();
$dryRun = in_array('--dry-run', $argv ?? [], true);
if (!mail_settings($pdo)['enabled']) {
    echo 'mail_disabled=1' . PHP_EOL;
    exit(0);
}

$sent = 0;
$skipped = 0;
$failed = 0;

$select = $pdo->prepare(
    'SELECT ar.*
     FROM guest_account_requests ar
     WHERE ar.status = "approved"
       AND ar.expires_at IS NOT NULL
       AND ar.radius_username IS NOT NULL
       AND ar.radius_username <> ""
       AND ar.applicant_email <> ""
       AND DATE(ar.expires_at) = DATE(DATE_ADD(CURDATE(), INTERVAL ? DAY))
       AND NOT EXISTS (
           SELECT 1
           FROM guest_account_extension_requests er
           WHERE er.request_id = ar.id
             AND er.status = "pending"
       )
     ORDER BY ar.expires_at ASC, ar.id ASC'
);

$alreadySent = $pdo->prepare(
    'SELECT COUNT(*)
     FROM guest_account_notifications
     WHERE request_id = ?
       AND notification_type = "expiration_reminder"
       AND notification_key = ?
       AND status = "sent"'
);

$logFailure = $pdo->prepare(
    'INSERT INTO guest_account_notifications
        (request_id, notification_type, notification_key, recipient_email, status, error_message, sent_at, created_at)
     VALUES (?, "expiration_reminder", ?, ?, "failed", ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        error_message = VALUES(error_message),
        sent_at = NOW()'
);

foreach (REMINDER_DAYS as $daysBefore) {
    $select->execute([$daysBefore]);
    foreach ($select->fetchAll() as $account) {
        $notificationKey = reminder_key($daysBefore, (string) $account['expires_at']);
        $alreadySent->execute([(int) $account['id'], $notificationKey]);
        if ((int) $alreadySent->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        if ($dryRun) {
            $sent++;
            continue;
        }

        try {
            send_expiration_reminder($pdo, $account, $daysBefore);
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $message = mb_substr($e->getMessage(), 0, 500);
            $logFailure->execute([
                (int) $account['id'],
                $notificationKey,
                (string) $account['applicant_email'],
                $message,
            ]);
            error_log('eduroam expiration reminder failed: request_id=' . (int) $account['id'] . ' ' . $message);
        }
    }
}

echo ($dryRun ? 'dry_run=1 would_send=' : 'sent=') . $sent . ' skipped=' . $skipped . ' failed=' . $failed . PHP_EOL;
