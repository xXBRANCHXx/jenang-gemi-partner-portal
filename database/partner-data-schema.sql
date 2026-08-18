CREATE TABLE IF NOT EXISTS `partner_orders` (
  `id` VARCHAR(64) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `customer_name` VARCHAR(160) NOT NULL,
  `brand_name` VARCHAR(160) NOT NULL,
  `product_name` VARCHAR(160) NOT NULL,
  `sku_code` VARCHAR(32) NOT NULL,
  `sku_label` VARCHAR(255) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `notes` VARCHAR(300) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'IS_LISTED',
  `order_timestamp` DATETIME NULL DEFAULT NULL,
  `marketplace_platform` VARCHAR(32) NOT NULL DEFAULT '',
  `deadline_hours` TINYINT UNSIGNED NOT NULL DEFAULT 24,
  `deadline_at` DATETIME NULL DEFAULT NULL,
  `revenue_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `inference_json` LONGTEXT NULL DEFAULT NULL,
  `items_json` LONGTEXT NULL DEFAULT NULL,
  `order_type` VARCHAR(32) NOT NULL DEFAULT 'class_a_dropship',
  `recipient_email` VARCHAR(190) NOT NULL DEFAULT '',
  `recipient_phone` VARCHAR(64) NOT NULL DEFAULT '',
  `recipient_address` TEXT NULL DEFAULT NULL,
  `shipping_weight_grams` INT UNSIGNED NOT NULL DEFAULT 0,
  `executive_status` VARCHAR(32) NOT NULL DEFAULT 'not_required',
  `balance_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `submitted_at` DATETIME NULL DEFAULT NULL,
  `shipment_arranged_at` DATETIME NULL DEFAULT NULL,
  `archived_at` DATETIME NULL DEFAULT NULL,
  `billing_status` VARCHAR(32) NOT NULL DEFAULT 'unbilled',
  `billing_reference` VARCHAR(120) NOT NULL DEFAULT '',
  `billing_paid_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_orders_partner_created` (`partner_code`, `created_at`),
  KEY `idx_partner_orders_partner_status` (`partner_code`, `status`),
  KEY `idx_partner_orders_partner_sku` (`partner_code`, `sku_code`),
  KEY `idx_partner_orders_archived` (`archived_at`),
  KEY `idx_partner_orders_billing` (`partner_code`, `billing_status`, `billing_paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_order_labels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` VARCHAR(64) NOT NULL,
  `partner_code` VARCHAR(64) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `relative_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_data` LONGBLOB NULL DEFAULT NULL,
  `uploaded_by` VARCHAR(80) NOT NULL DEFAULT 'partner',
  `expires_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deletion_reason` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_order_labels_order` (`order_id`, `created_at`),
  KEY `idx_partner_order_labels_partner` (`partner_code`, `created_at`),
  KEY `idx_partner_order_labels_expiry` (`deleted_at`, `expires_at`),
  CONSTRAINT `fk_partner_order_labels_order`
    FOREIGN KEY (`order_id`) REFERENCES `partner_orders` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_platform_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_code` VARCHAR(64) NOT NULL,
  `platform_name` VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_platform_name` (`partner_code`, `platform_name`),
  KEY `idx_partner_platform_partner` (`partner_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_wallets` (
  `partner_code` VARCHAR(64) NOT NULL PRIMARY KEY,
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_partner_wallets_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `partner_code` VARCHAR(64) NOT NULL,
  `transaction_type` VARCHAR(32) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `balance_after` DECIMAL(14,2) NOT NULL,
  `reference_type` VARCHAR(32) NOT NULL DEFAULT '',
  `reference_id` VARCHAR(80) NOT NULL DEFAULT '',
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `actor` VARCHAR(80) NOT NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uniq_partner_wallet_reference` (`partner_code`,`transaction_type`,`reference_type`,`reference_id`),
  KEY `idx_partner_wallet_activity` (`partner_code`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_deposit_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `partner_code` VARCHAR(64) NOT NULL,
  `requested_amount` DECIMAL(14,2) NOT NULL,
  `approved_amount` DECIMAL(14,2) NULL DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `proof_name` VARCHAR(255) NOT NULL,
  `proof_mime` VARCHAR(120) NOT NULL,
  `proof_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `proof_data` LONGBLOB NOT NULL,
  `review_note` VARCHAR(1000) NOT NULL DEFAULT '',
  `submitted_at` DATETIME NOT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  `reviewed_by` VARCHAR(80) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL,
  KEY `idx_partner_deposit_queue` (`status`,`submitted_at`),
  KEY `idx_partner_deposit_history` (`partner_code`,`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_stock_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `partner_code` VARCHAR(64) NOT NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `entity_id` VARCHAR(80) NOT NULL,
  `event_type` VARCHAR(48) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `detail` VARCHAR(1000) NOT NULL DEFAULT '',
  `actor` VARCHAR(80) NOT NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL,
  KEY `idx_partner_stock_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_partner_stock_partner` (`partner_code`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
