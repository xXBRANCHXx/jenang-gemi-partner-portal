<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-data-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$status = jg_partner_data_status();

if (!jg_partner_is_authenticated()) {
    $tables = (array) ($status['tables'] ?? []);
    echo json_encode([
        'ok' => !empty($status['connected']) && !empty($tables['partner_orders']) && !empty($tables['partner_order_labels']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    $status['authenticated'] = true;
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
