-- ============================================================================
--  ITFA Student Portal — Super Admin maintenance tables
--  Run this ONCE on the live database (phpMyAdmin) if you are not using
--  `php artisan migrate`. Safe to re-run: uses CREATE TABLE IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `portal_login_audit` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`    BIGINT UNSIGNED NULL,
  `enrollment_id` BIGINT UNSIGNED NULL,
  `lrn`           VARCHAR(40) NULL,
  `event`         ENUM('login','logout','failed') NOT NULL DEFAULT 'login',
  `ip_address`    VARCHAR(45) NULL,
  `user_agent`    VARCHAR(255) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pla_account` (`account_id`),
  KEY `idx_pla_lrn` (`lrn`),
  KEY `idx_pla_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `portal_admin_audit` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   BIGINT UNSIGNED NULL,
  `admin_name` VARCHAR(150) NULL,
  `action`     VARCHAR(80) NOT NULL,
  `target`     VARCHAR(190) NULL,
  `details`    TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_paa_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
