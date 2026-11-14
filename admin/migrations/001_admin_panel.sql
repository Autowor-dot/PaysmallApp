-- =====================================================================
-- PaySmallApp / LipaLink — Admin panel migration 001
--
-- Purely ADDITIVE: creates new admin_* / site_settings / email_log tables
-- and adds nullable or defaulted columns to users and transactions.
-- It never drops, renames or deletes anything. Safe to re-run.
--
-- Applied by admin/setup.php (requires the install_key), or paste into
-- phpMyAdmin > SQL. Uses MariaDB's ADD COLUMN IF NOT EXISTS (InfinityFree
-- runs MariaDB); on MySQL 8 the runner skips "duplicate column" errors.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Administrator accounts (separate from customer/seller `users`)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name            VARCHAR(120) NOT NULL,
    email                VARCHAR(190) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    role                 ENUM('super_admin','admin','support') NOT NULL DEFAULT 'support',
    status               ENUM('active','inactive') NOT NULL DEFAULT 'active',
    failed_attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    last_login_at        DATETIME NULL,
    last_login_ip        VARCHAR(45) NULL,
    created_by           INT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Login attempts (rate limiting by IP + audit of failed logins)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Audit log. Append-only: the admin panel has no edit/delete path.
-- Admin name/email/role are copied so entries stay meaningful even if
-- the admin account is later changed. No FK on purpose.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id     INT UNSIGNED NULL,
    admin_name   VARCHAR(120) NULL,
    admin_email  VARCHAR(190) NULL,
    admin_role   VARCHAR(20)  NULL,
    action       VARCHAR(60)  NOT NULL,
    target_type  VARCHAR(40)  NULL,
    target_id    VARCHAR(64)  NULL,
    target_label VARCHAR(200) NULL,
    details      TEXT         NULL,
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_admin (admin_id, created_at),
    KEY idx_audit_action (action),
    KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Website settings (key/value)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(64) NOT NULL,
    setting_value TEXT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('site_name',                         'LipaLink'),
    ('site_tagline',                      'Pay small, own more.'),
    ('contact_email',                     ''),
    ('support_email',                     ''),
    ('contact_phone',                     ''),
    ('contact_address',                   ''),
    ('maintenance_mode',                  '0'),
    ('maintenance_message',               'PaySmallApp is undergoing scheduled maintenance. Please check back shortly.'),
    ('allow_user_registration',           '1'),
    ('allow_seller_registration',         '1'),
    ('notify_email_agreement_request',    '1'),
    ('notify_email_agreement_accepted',   '1'),
    ('notify_email_payment_receipt',      '1'),
    ('admin_session_timeout_minutes',     '30'),
    ('admin_max_login_attempts',          '5'),
    ('admin_lockout_minutes',             '15');

-- ---------------------------------------------------------------------
-- Outgoing email log (delivery status as reported by PHP mail())
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient    VARCHAR(190) NOT NULL,
    subject      VARCHAR(255) NOT NULL,
    context      VARCHAR(60)  NOT NULL DEFAULT 'general',
    related_type VARCHAR(40)  NULL,
    related_id   VARCHAR(64)  NULL,
    status       ENUM('sent','failed','disabled') NOT NULL,
    error        VARCHAR(255) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_created (created_at),
    KEY idx_email_status (status),
    KEY idx_email_context (context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Cases: payment disputes, suspicious agreements, customer complaints
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_cases (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_code      VARCHAR(40)  NOT NULL,
    type           ENUM('payment_dispute','suspicious_agreement','complaint','account_issue','other') NOT NULL DEFAULT 'other',
    subject        VARCHAR(200) NOT NULL,
    description    TEXT NULL,
    status         ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
    priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    user_id        INT UNSIGNED NULL,
    seller_id      INT UNSIGNED NULL,
    agreement_id   INT UNSIGNED NULL,
    transaction_id INT UNSIGNED NULL,
    assigned_to    INT UNSIGNED NULL,
    created_by     INT UNSIGNED NULL,
    resolution     TEXT NULL,
    resolved_at    DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cases_code (case_code),
    KEY idx_cases_status (status, priority),
    KEY idx_cases_agreement (agreement_id),
    KEY idx_cases_transaction (transaction_id),
    KEY idx_cases_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_case_notes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id    INT UNSIGNED NOT NULL,
    admin_id   INT UNSIGNED NULL,
    admin_name VARCHAR(120) NULL,
    note       TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_case_notes_case (case_id),
    CONSTRAINT fk_case_notes_case FOREIGN KEY (case_id) REFERENCES admin_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Existing tables: new columns only (defaults keep current rows valid)
-- ---------------------------------------------------------------------
-- Account status for customers (all existing users stay 'active')
ALTER TABLE users ADD COLUMN IF NOT EXISTS status ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS status_reason VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS status_changed_at DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- How a completed transaction was confirmed: 'provider' (gateway webhook),
-- 'simulator' (development button). NULL = not independently verified.
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS verified_via VARCHAR(20) NULL;
UPDATE transactions SET verified_via = 'simulator'
    WHERE verified_via IS NULL AND status = 'completed' AND provider_reference LIKE 'SIMULATED-%';

ALTER TABLE sellers ADD COLUMN IF NOT EXISTS status_reason VARCHAR(255) NULL;

-- Added with the seller hub / reminders (002): email reminders switch
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('notify_email_reminders', '1');

-- Added with the Brevo email integration: welcome emails and customer receipts switches
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('notify_email_registration', '1');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('notify_email_payment_receipt_customer', '1');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('notify_email_security', '1');
