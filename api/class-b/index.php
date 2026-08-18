<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-class-b-storage.php';

jg_partner_require_auth_json();
header('Cache-Control: no-store');

function jg_class_b_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$partnerCode = jg_partner_current_code();
$partner = jg_partner_current_profile();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? 'summary')));

try {
    jg_partner_class_b_require_profile($partner);
    if ($method === 'GET' && $action === 'proof') {
        jg_partner_class_b_stream_proof(jg_partner_class_b_db(), $partnerCode, (int) ($_GET['id'] ?? 0));
    }
    if ($method === 'GET') {
        jg_class_b_json(['ok' => true, 'data' => jg_partner_class_b_summary($partnerCode)]);
    }
    if ($method !== 'POST') jg_class_b_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    jg_partner_require_csrf_json();

    if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
        $request = $_POST;
    } else {
        $request = json_decode((string) file_get_contents('php://input'), true);
        $request = is_array($request) ? $request : [];
    }
    $action = strtolower(trim((string) ($request['action'] ?? $action)));
    if ($action === 'request_deposit') {
        $proof = isset($_FILES['proof']) && is_array($_FILES['proof']) ? $_FILES['proof'] : [];
        $deposit = jg_partner_class_b_submit_deposit($partnerCode, $request['amount'] ?? null, $proof);
        jg_class_b_json(['ok' => true, 'deposit' => $deposit, 'data' => jg_partner_class_b_summary($partnerCode)]);
    }
    if ($action === 'create_order') {
        $order = jg_partner_class_b_create_order($partnerCode, $partner ?? [], $request);
        jg_class_b_json(['ok' => true, 'order' => $order, 'data' => jg_partner_class_b_summary($partnerCode)]);
    }
    jg_class_b_json(['ok' => false, 'error' => 'Unknown Class B action.'], 400);
} catch (InvalidArgumentException $error) {
    jg_class_b_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    jg_class_b_json(['ok' => false, 'error' => $error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('Class B partner API failed: ' . $error->getMessage());
    jg_class_b_json(['ok' => false, 'error' => 'Class B operations are temporarily unavailable.'], 500);
}
