<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS radius_proxy_groups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        realm VARCHAR(190) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        pool_type ENUM('fail-over', 'load-balance') NOT NULL DEFAULT 'fail-over',
        nostrip TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(500) DEFAULT '',
        created_by VARCHAR(128) DEFAULT NULL,
        last_synced_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_radius_proxy_groups_realm (realm),
        KEY ix_radius_proxy_groups_enabled (enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS radius_proxy_servers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id INT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        server_host VARCHAR(190) NOT NULL,
        auth_port SMALLINT UNSIGNED NOT NULL DEFAULT 1812,
        acct_port SMALLINT UNSIGNED NOT NULL DEFAULT 1813,
        shared_secret TEXT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        response_window SMALLINT UNSIGNED NOT NULL DEFAULT 20,
        zombie_period SMALLINT UNSIGNED NOT NULL DEFAULT 40,
        revive_interval SMALLINT UNSIGNED NOT NULL DEFAULT 120,
        status_check ENUM('none', 'status-server') NOT NULL DEFAULT 'status-server',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_radius_proxy_servers_group (group_id, enabled),
        CONSTRAINT fk_radius_proxy_servers_group
            FOREIGN KEY (group_id) REFERENCES radius_proxy_groups (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "radius_proxy_tables=ok\n";
