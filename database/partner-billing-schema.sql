-- Manual equivalent of the schema created by partner-billing-storage.php.
-- Import after database/partner-data-schema.sql when automatic migrations are unavailable.

ALTER TABLE `partner_orders`
  ADD COLUMN IF NOT EXISTS `billing_status` VARCHAR(32) NOT NULL DEFAULT 'unbilled',
  ADD COLUMN IF NOT EXISTS `billing_reference` VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `billing_paid_at` DATETIME NULL DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_partner_orders_billing` (`partner_code`, `billing_status`, `billing_paid_at`);

CREATE TABLE IF NOT EXISTS `partner_favicons` (
  `partner_code` VARCHAR(64) NOT NULL,
  `theme` VARCHAR(8) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(64) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_data` LONGBLOB NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`partner_code`, `theme`),
  KEY `idx_partner_favicons_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `partner_favicons`
  ADD COLUMN IF NOT EXISTS `file_data` LONGBLOB NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `partner_weekly_bills` (
  `bill_id` VARCHAR(120) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `period_type` VARCHAR(32) NOT NULL DEFAULT 'calendar_week',
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'accruing',
  `subtotal_amount` BIGINT NOT NULL DEFAULT 0,
  `adjustment_amount` BIGINT NOT NULL DEFAULT 0,
  `total_amount` BIGINT NOT NULL DEFAULT 0,
  `payment_submitted_at` DATETIME NULL DEFAULT NULL,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`bill_id`),
  UNIQUE KEY `uniq_partner_bill_type_period` (`partner_code`, `period_type`, `period_start`),
  KEY `idx_partner_bills_status` (`status`, `due_date`),
  KEY `idx_partner_bills_partner` (`partner_code`, `period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `partner_weekly_bills`
  ADD COLUMN IF NOT EXISTS `period_type` VARCHAR(32) NOT NULL DEFAULT 'calendar_week' AFTER `partner_code`;

DROP INDEX IF EXISTS `uniq_partner_bill_period` ON `partner_weekly_bills`;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_partner_bill_type_period`
  ON `partner_weekly_bills` (`partner_code`, `period_type`, `period_start`);

CREATE TABLE IF NOT EXISTS `partner_weekly_bill_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_id` VARCHAR(120) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `order_id` VARCHAR(64) NOT NULL,
  `order_date` DATETIME NOT NULL,
  `platform` VARCHAR(64) NOT NULL DEFAULT '',
  `customer_name` VARCHAR(160) NOT NULL DEFAULT '',
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `units` INT UNSIGNED NOT NULL DEFAULT 0,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'included',
  `dispute_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `removed_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `paid_at` DATETIME NULL DEFAULT NULL,
  `snapshot_json` LONGTEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_bill_order` (`order_id`),
  KEY `idx_partner_bill_items_bill` (`bill_id`, `status`),
  KEY `idx_partner_bill_items_partner` (`partner_code`, `order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_weekly_bill_disputes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dispute_key` VARCHAR(120) NOT NULL,
  `bill_id` VARCHAR(120) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `dispute_type` VARCHAR(32) NOT NULL DEFAULT 'paid',
  `reason` TEXT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `resolution_reason` TEXT NULL DEFAULT NULL,
  `evidence_file_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_dispute_key` (`dispute_key`),
  KEY `idx_partner_disputes_status` (`status`, `created_at`),
  KEY `idx_partner_disputes_bill` (`bill_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_weekly_bill_dispute_items` (
  `dispute_id` BIGINT UNSIGNED NOT NULL,
  `bill_item_id` BIGINT UNSIGNED NOT NULL,
  `original_amount` BIGINT NULL DEFAULT NULL,
  `proposed_amount` BIGINT NULL DEFAULT NULL,
  `proposal_json` LONGTEXT NULL DEFAULT NULL,
  `resolved_amount` BIGINT NULL DEFAULT NULL,
  `resolution_json` LONGTEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`dispute_id`, `bill_item_id`),
  KEY `idx_partner_dispute_items_item` (`bill_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `partner_weekly_bill_disputes`
  ADD COLUMN IF NOT EXISTS `dispute_type` VARCHAR(32) NOT NULL DEFAULT 'paid';

ALTER TABLE `partner_weekly_bill_dispute_items`
  ADD COLUMN IF NOT EXISTS `original_amount` BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `proposed_amount` BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `proposal_json` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `resolved_amount` BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `resolution_json` LONGTEXT NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `partner_weekly_bill_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_code` VARCHAR(64) NOT NULL,
  `bill_id` VARCHAR(120) NOT NULL,
  `file_kind` VARCHAR(32) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `file_data` LONGBLOB NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_bill_files_bill` (`bill_id`, `file_kind`, `created_at`),
  KEY `idx_partner_bill_files_partner` (`partner_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_weekly_bill_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_key` VARCHAR(120) NOT NULL,
  `bill_id` VARCHAR(120) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `amount` BIGINT NOT NULL,
  `proof_file_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `submitted_at` DATETIME NOT NULL,
  `confirmed_at` DATETIME NULL DEFAULT NULL,
  `accounting_transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_bill_payment_key` (`payment_key`),
  UNIQUE KEY `uniq_partner_bill_payment_bill` (`bill_id`),
  KEY `idx_partner_bill_payments_status` (`status`, `submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_billing_onboarding` (
  `partner_code` VARCHAR(64) NOT NULL,
  `billing_seen_at` DATETIME NULL DEFAULT NULL,
  `tutorial_completed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`partner_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
