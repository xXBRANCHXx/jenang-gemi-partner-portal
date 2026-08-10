<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function jg_partner_data_db_config(): array
{
    return [
        'host' => jg_partner_portal_config_value('JG_PARTNER_DB_HOST', 'partner_db_host', 'localhost'),
        'port' => jg_partner_portal_config_value('JG_PARTNER_DB_PORT', 'partner_db_port', '3306'),
        'name' => jg_partner_portal_config_value('JG_PARTNER_DB_NAME', 'partner_db_name'),
        'user' => jg_partner_portal_config_value('JG_PARTNER_DB_USER', 'partner_db_user'),
        'pass' => jg_partner_portal_config_value('JG_PARTNER_DB_PASSWORD', 'partner_db_password'),
        'charset' => jg_partner_portal_config_value('JG_PARTNER_DB_CHARSET', 'partner_db_charset', 'utf8mb4'),
    ];
}

function jg_partner_data_mysql_is_configured(): bool
{
    $config = jg_partner_data_db_config();
    return $config['name'] !== '' && $config['user'] !== '' && $config['pass'] !== '';
}

function jg_partner_data_last_error(?string $message = null): string
{
    static $lastError = '';

    if ($message !== null) {
        $lastError = $message;
    }

    return $lastError;
}

function jg_partner_data_host_candidates(string $host): array
{
    $hosts = [$host];

    if ($host === 'local.server') {
        $hosts[] = 'localhost';
    }

    return array_values(array_unique(array_filter($hosts)));
}

function jg_partner_data_db(): ?PDO
{
    static $pdo = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($pdo === null) {
        return null;
    }

    $config = jg_partner_data_db_config();
    if ($config['name'] === '' || $config['user'] === '' || $config['pass'] === '') {
        $missing = [];
        if ($config['name'] === '') $missing[] = 'database name';
        if ($config['user'] === '') $missing[] = 'database user';
        if ($config['pass'] === '') $missing[] = 'database password';
        jg_partner_data_last_error('Missing ' . implode(', ', $missing) . '.');
        $pdo = null;
        return null;
    }

    $errors = [];
    foreach (jg_partner_data_host_candidates($config['host']) as $host) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $config['port'],
            $config['name'],
            $config['charset']
        );

        try {
            $pdo = new PDO(
                $dsn,
                $config['user'],
                $config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            jg_partner_data_ensure_schema($pdo);
            jg_partner_data_last_error('');
            break;
        } catch (Throwable $exception) {
            $errors[] = $host . ': ' . $exception->getMessage();
            $pdo = null;
        }
    }

    if (!$pdo instanceof PDO && $errors !== []) {
        jg_partner_data_last_error(implode(' | ', $errors));
    }

    return $pdo instanceof PDO ? $pdo : null;
}

function jg_partner_data_status(): array
{
    $config = jg_partner_data_db_config();
    $pdo = jg_partner_data_db();
    $tables = [];

    if ($pdo instanceof PDO) {
        foreach ([
            'partner_orders',
            'partner_order_labels',
            'partner_platform_options',
            'partner_favicons',
            'partner_preferences',
            'partner_weekly_bills',
            'partner_weekly_bill_items',
            'partner_weekly_bill_disputes',
            'partner_weekly_bill_dispute_items',
            'partner_weekly_bill_files',
            'partner_weekly_bill_payments',
            'partner_billing_onboarding',
            'partner_return_adjustments',
        ] as $tableName) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $stmt->execute([':table_name' => $tableName]);
            $tables[$tableName] = (int) $stmt->fetchColumn() > 0;
        }
    }

    return [
        'connected' => $pdo instanceof PDO,
        'storage' => $pdo instanceof PDO ? 'mysql' : (jg_partner_data_mysql_is_configured() ? 'unavailable' : 'json'),
        'host' => $config['host'],
        'attempted_hosts' => jg_partner_data_host_candidates($config['host']),
        'port' => $config['port'],
        'database_configured' => $config['name'] !== '',
        'user_configured' => $config['user'] !== '',
        'password_configured' => $config['pass'] !== '',
        'database_name' => $config['name'],
        'database_user' => $config['user'],
        'config_files' => [
            [
                'path' => __DIR__ . '/config.local.php',
                'exists' => is_file(__DIR__ . '/config.local.php'),
            ],
            [
                'path' => '/public_html/config.local.php',
                'exists' => is_file('/public_html/config.local.php'),
            ],
        ],
        'tables' => $tables,
        'error' => jg_partner_data_last_error(),
    ];
}

function jg_partner_data_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_partner_data_ensure_schema(PDO $pdo): void
{
    $statements = [
        'CREATE TABLE IF NOT EXISTS partner_orders (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            partner_code VARCHAR(64) NOT NULL,
            customer_name VARCHAR(160) NOT NULL,
            brand_name VARCHAR(160) NOT NULL,
            product_name VARCHAR(160) NOT NULL,
            sku_code VARCHAR(32) NOT NULL,
            sku_label VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            notes VARCHAR(300) NOT NULL DEFAULT "",
            status VARCHAR(32) NOT NULL DEFAULT "IS_LISTED",
            marketplace_platform VARCHAR(32) NOT NULL DEFAULT "",
            deadline_hours TINYINT UNSIGNED NOT NULL DEFAULT 24,
            deadline_at DATETIME NULL DEFAULT NULL,
            revenue_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            inference_json LONGTEXT NULL DEFAULT NULL,
            items_json LONGTEXT NULL DEFAULT NULL,
            archived_at DATETIME NULL DEFAULT NULL,
            billing_status VARCHAR(32) NOT NULL DEFAULT "unbilled",
            billing_reference VARCHAR(120) NOT NULL DEFAULT "",
            billing_paid_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_partner_orders_partner_created (partner_code, created_at),
            KEY idx_partner_orders_partner_status (partner_code, status),
            KEY idx_partner_orders_partner_sku (partner_code, sku_code),
            KEY idx_partner_orders_archived (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_order_labels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(64) NOT NULL,
            partner_code VARCHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL DEFAULT "",
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NULL DEFAULT NULL,
            deleted_at DATETIME NULL DEFAULT NULL,
            deletion_reason VARCHAR(64) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL,
            KEY idx_partner_order_labels_order (order_id, created_at),
            KEY idx_partner_order_labels_partner (partner_code, created_at),
            KEY idx_partner_order_labels_expiry (deleted_at, expires_at),
            CONSTRAINT fk_partner_order_labels_order FOREIGN KEY (order_id) REFERENCES partner_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_platform_options (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            partner_code VARCHAR(64) NOT NULL,
            platform_name VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_partner_platform_name (partner_code, platform_name),
            KEY idx_partner_platform_partner (partner_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_favicons (
            partner_code VARCHAR(64) NOT NULL,
            theme VARCHAR(8) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(64) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_data LONGBLOB NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (partner_code, theme),
            KEY idx_partner_favicons_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_preferences (
            partner_code VARCHAR(64) NOT NULL PRIMARY KEY,
            language VARCHAR(8) NOT NULL DEFAULT "id",
            timezone VARCHAR(64) NOT NULL DEFAULT "Asia/Jakarta",
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_partner_preferences_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    jg_partner_data_ensure_column($pdo, 'partner_orders', 'order_timestamp', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'items_json', 'LONGTEXT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'archived_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'marketplace_platform', 'VARCHAR(32) NOT NULL DEFAULT ""');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'deadline_hours', 'TINYINT UNSIGNED NOT NULL DEFAULT 24');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'deadline_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'revenue_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'inference_json', 'LONGTEXT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_status', 'VARCHAR(32) NOT NULL DEFAULT "unbilled"');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_reference', 'VARCHAR(120) NOT NULL DEFAULT ""');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_paid_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_order_labels', 'expires_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_order_labels', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_order_labels', 'deletion_reason', 'VARCHAR(64) NOT NULL DEFAULT ""');
    jg_partner_data_ensure_column($pdo, 'partner_favicons', 'file_data', 'LONGBLOB NULL DEFAULT NULL');
    jg_partner_data_ensure_index($pdo, 'partner_orders', 'idx_partner_orders_archived', '(archived_at)');
    jg_partner_data_ensure_index($pdo, 'partner_orders', 'idx_partner_orders_billing', '(partner_code, billing_status, billing_paid_at)');
    jg_partner_data_ensure_index($pdo, 'partner_order_labels', 'idx_partner_order_labels_expiry', '(deleted_at, expires_at)');
}

function jg_partner_data_ensure_column(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName) ?: $tableName;
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName) ?: $columnName;
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $safeTable, $safeColumn, $definition));
}

function jg_partner_data_ensure_index(PDO $pdo, string $tableName, string $indexName, string $columns): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` ADD INDEX `%s` %s', $tableName, $indexName, $columns));
}
