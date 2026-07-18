<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$stmt = $pdo->query(
    'SELECT id, radius_username
     FROM guest_account_requests
     WHERE status = "approved"
       AND starts_at IS NOT NULL
       AND starts_at <= NOW()
       AND radius_username IS NOT NULL
       AND radius_username <> ""'
);
$items = $stmt->fetchAll();

$removed = 0;
$pdo->beginTransaction();
try {
    $delete = $pdo->prepare(
        'DELETE FROM radcheck
         WHERE username = ?
           AND attribute = "Auth-Type"
           AND value = "Reject"'
    );
    $touch = $pdo->prepare('UPDATE guest_account_requests SET updated_at = NOW() WHERE id = ?');
    $audit = $pdo->prepare(
        'INSERT INTO guest_account_audit (admin_id, action, request_id, message, ip_address, created_at)
         VALUES (NULL, "auto_activate", ?, ?, "127.0.0.1", NOW())'
    );

    foreach ($items as $item) {
        $delete->execute([(string) $item['radius_username']]);
        if ($delete->rowCount() > 0) {
            $removed += $delete->rowCount();
            $touch->execute([(int) $item['id']]);
            $audit->execute([(int) $item['id'], 'auto activated ' . $item['radius_username']]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo 'activated=' . count($items) . ' removed_reject=' . $removed . PHP_EOL;
