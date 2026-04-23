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
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `order_timestamp` DATETIME NULL DEFAULT NULL,
  `items_json` LONGTEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_orders_partner_created` (`partner_code`, `created_at`),
  KEY `idx_partner_orders_partner_status` (`partner_code`, `status`),
  KEY `idx_partner_orders_partner_sku` (`partner_code`, `sku_code`)
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
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_order_labels_order` (`order_id`, `created_at`),
  KEY `idx_partner_order_labels_partner` (`partner_code`, `created_at`),
  CONSTRAINT `fk_partner_order_labels_order`
    FOREIGN KEY (`order_id`) REFERENCES `partner_orders` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
