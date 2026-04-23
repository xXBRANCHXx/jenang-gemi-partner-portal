<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-data-bootstrap.php';

jg_partner_require_auth_json();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(jg_partner_data_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
