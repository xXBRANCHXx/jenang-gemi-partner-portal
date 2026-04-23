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

function jg_partner_data_last_error(?string $message = null): string
{
    static $lastError = '';

    if ($message !== null) {
        $lastError = $message;
    }

    return $lastError;
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

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
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
    } catch (Throwable $exception) {
        jg_partner_data_last_error($exception->getMessage());
        $pdo = null;
    }

    return $pdo instanceof PDO ? $pdo : null;
}

function jg_partner_data_status(): array
{
    $config = jg_partner_data_db_config();
    $pdo = jg_partner_data_db();
    $tables = [];

    if ($pdo instanceof PDO) {
        foreach (['partner_orders', 'partner_order_labels'] as $tableName) {
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
        'storage' => $pdo instanceof PDO ? 'mysql' : 'json',
        'host' => $config['host'],
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
            status VARCHAR(32) NOT NULL DEFAULT "draft",
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_partner_orders_partner_created (partner_code, created_at),
            KEY idx_partner_orders_partner_status (partner_code, status),
            KEY idx_partner_orders_partner_sku (partner_code, sku_code)
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
            created_at DATETIME NOT NULL,
            KEY idx_partner_order_labels_order (order_id, created_at),
            KEY idx_partner_order_labels_partner (partner_code, created_at),
            CONSTRAINT fk_partner_order_labels_order FOREIGN KEY (order_id) REFERENCES partner_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    jg_partner_data_ensure_column($pdo, 'partner_orders', 'order_timestamp', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'items_json', 'LONGTEXT NULL DEFAULT NULL');
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
