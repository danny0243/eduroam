<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS radius_roaming_blocklist (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        block_type ENUM('username', 'realm', 'calling_station_id') NOT NULL,
        block_value VARCHAR(190) NOT NULL,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        blocked_until DATETIME DEFAULT NULL,
        created_by VARCHAR(128) DEFAULT NULL,
        disabled_by VARCHAR(128) DEFAULT NULL,
        disabled_at DATETIME DEFAULT NULL,
        last_synced_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_radius_roaming_blocklist_type_value (block_type, block_value),
        KEY ix_radius_roaming_blocklist_enabled_until (enabled, blocked_until),
        KEY ix_radius_roaming_blocklist_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "radius_roaming_blocklist=ok\n";
