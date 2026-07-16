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
  `archived_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_orders_partner_created` (`partner_code`, `created_at`),
  KEY `idx_partner_orders_partner_status` (`partner_code`, `status`),
  KEY `idx_partner_orders_partner_sku` (`partner_code`, `sku_code`),
  KEY `idx_partner_orders_archived` (`archived_at`)
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
