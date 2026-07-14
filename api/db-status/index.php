<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

header('Content-Type: application/json; charset=utf-8');

$status = jg_partner_data_status();
$privateStorageReady = false;
try {
    $privateStorageDirectory = jg_partner_order_upload_directory();
    $privateStorageReady = is_dir($privateStorageDirectory) && is_writable($privateStorageDirectory);
} catch (Throwable) {
    $privateStorageReady = false;
}
$status['private_storage_ready'] = $privateStorageReady;

if (!jg_partner_is_authenticated()) {
    $tables = (array) ($status['tables'] ?? []);
    echo json_encode([
        'ok' => !empty($status['connected'])
            && !empty($tables['partner_orders'])
            && !empty($tables['partner_order_labels'])
            && $privateStorageReady,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    $status['authenticated'] = true;
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
