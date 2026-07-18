CREATE TABLE IF NOT EXISTS guest_account_admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(128) DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_account_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_account_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_code VARCHAR(32) NOT NULL,
    applicant_name VARCHAR(128) NOT NULL,
    applicant_email VARCHAR(200) NOT NULL,
    applicant_phone VARCHAR(64) DEFAULT '',
    organization VARCHAR(200) NOT NULL,
    reason TEXT NOT NULL,
    desired_start DATE DEFAULT NULL,
    desired_end DATE DEFAULT NULL,
    requested_username VARCHAR(128) DEFAULT NULL,
    requested_password VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'disabled', 'deleted') NOT NULL DEFAULT 'pending',
    radius_username VARCHAR(128) DEFAULT NULL,
    radius_password VARCHAR(255) DEFAULT NULL,
    starts_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    reviewed_by VARCHAR(64) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT '',
    request_ip VARCHAR(64) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_account_requests_code (request_code),
    KEY ix_guest_account_requests_status_created (status, created_at),
    KEY ix_guest_account_requests_radius_username (radius_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_allowed_domains (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(190) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(128) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_allowed_domains_domain (domain),
    KEY ix_guest_allowed_domains_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_mail_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_account_extension_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    applicant_email VARCHAR(200) NOT NULL,
    radius_username VARCHAR(128) NOT NULL,
    current_expires_at DATETIME NOT NULL,
    requested_expires_at DATETIME NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by VARCHAR(64) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT '',
    request_ip VARCHAR(64) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_guest_extension_status_created (status, created_at),
    KEY ix_guest_extension_request (request_id),
    KEY ix_guest_extension_email (applicant_email),
    CONSTRAINT fk_guest_extension_request
        FOREIGN KEY (request_id) REFERENCES guest_account_requests (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_account_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(64) NOT NULL,
    notification_key VARCHAR(128) NOT NULL,
    recipient_email VARCHAR(200) NOT NULL,
    status ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
    error_message VARCHAR(500) DEFAULT '',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_notification_once (request_id, notification_type, notification_key),
    KEY ix_guest_notification_sent (sent_at),
    CONSTRAINT fk_guest_notification_request
        FOREIGN KEY (request_id) REFERENCES guest_account_requests (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_account_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(64) NOT NULL,
    request_id INT UNSIGNED DEFAULT NULL,
    message VARCHAR(500) DEFAULT '',
    ip_address VARCHAR(64) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_guest_account_audit_created (created_at),
    KEY ix_guest_account_audit_request (request_id),
    CONSTRAINT fk_guest_account_audit_admin
        FOREIGN KEY (admin_id) REFERENCES guest_account_admins (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radius_roaming_blocklist (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radius_proxy_groups (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radius_proxy_servers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
