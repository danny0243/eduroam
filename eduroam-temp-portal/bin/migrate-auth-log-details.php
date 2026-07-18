<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

$columns = [
    'nasipaddress' => "ALTER TABLE radpostauth ADD COLUMN nasipaddress VARCHAR(45) NULL AFTER class",
    'nasidentifier' => "ALTER TABLE radpostauth ADD COLUMN nasidentifier VARCHAR(128) NULL AFTER nasipaddress",
    'nasportid' => "ALTER TABLE radpostauth ADD COLUMN nasportid VARCHAR(64) NULL AFTER nasidentifier",
    'calledstationid' => "ALTER TABLE radpostauth ADD COLUMN calledstationid VARCHAR(128) NULL AFTER nasportid",
    'callingstationid' => "ALTER TABLE radpostauth ADD COLUMN callingstationid VARCHAR(128) NULL AFTER calledstationid",
    'packet_src_ipaddress' => "ALTER TABLE radpostauth ADD COLUMN packet_src_ipaddress VARCHAR(45) NULL AFTER callingstationid",
];

$added = [];
foreach ($columns as $column => $sql) {
    if (table_column_exists($pdo, 'radpostauth', $column)) {
        continue;
    }
    $pdo->exec($sql);
    $added[] = $column;
}

foreach ([
    'ix_radpostauth_username_authdate' => 'CREATE INDEX ix_radpostauth_username_authdate ON radpostauth (username, authdate)',
    'ix_radpostauth_callingstationid' => 'CREATE INDEX ix_radpostauth_callingstationid ON radpostauth (callingstationid)',
    'ix_radpostauth_nasipaddress' => 'CREATE INDEX ix_radpostauth_nasipaddress ON radpostauth (nasipaddress)',
] as $index => $sql) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $stmt->execute(['radpostauth', $index]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

echo 'auth_log_detail_columns=' . ($added ? implode(',', $added) : 'already_ok') . PHP_EOL;
