<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    jg_partner_require_auth_json();
    $partner = jg_partner_current_profile();
    echo json_encode([
        'partner' => $partner,
        'catalog' => jg_partner_source_catalog(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'DELETE') {
    jg_partner_logout();
    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
