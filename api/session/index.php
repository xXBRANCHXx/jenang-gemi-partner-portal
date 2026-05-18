<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    jg_partner_require_auth_json();
    $partner = jg_partner_current_profile();
    echo json_encode([
        'partner' => $partner,
        'catalog' => jg_partner_source_catalog($partner),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'DELETE') {
    jg_partner_logout();
    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    jg_partner_require_auth_json();
    $request = json_decode((string) file_get_contents('php://input'), true);
    $request = is_array($request) ? $request : [];
    $action = (string) ($request['action'] ?? '');
    if ($action !== 'change_password') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $currentPassword = (string) ($request['current_password'] ?? '');
    $newPassword = (string) ($request['new_password'] ?? '');
    $confirmPassword = (string) ($request['confirm_password'] ?? '');
    if ($newPassword !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['error' => 'New passwords do not match.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = jg_partner_source_change_password(jg_partner_current_code(), $currentPassword, $newPassword);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode(['error' => (string) ($result['error'] ?? 'Unable to update password.')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
