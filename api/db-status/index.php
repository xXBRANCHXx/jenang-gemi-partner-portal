<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-data-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$status = jg_partner_data_status();

if (!jg_partner_is_authenticated()) {
    unset($status['database_name'], $status['database_user']);
    foreach ($status['config_files'] as &$configFile) {
        unset($configFile['path']);
    }
    unset($configFile);
    $status['authenticated'] = false;
} else {
    $status['authenticated'] = true;
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
