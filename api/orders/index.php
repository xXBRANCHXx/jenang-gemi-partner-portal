<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

jg_partner_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

function jg_order_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_order_request(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$partnerCode = jg_partner_current_code();
$partner = jg_partner_current_profile();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $orders = jg_partner_order_list($partnerCode);
    echo json_encode([
        'orders' => $orders,
        'analytics' => jg_partner_order_analytics($orders),
        'storage' => jg_partner_order_storage_mode(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    jg_order_fail('Method not allowed.', 405);
}

$request = jg_order_request();
$action = (string) ($request['action'] ?? '');

try {
    if ($action === 'create' || $action === 'update') {
        $order = jg_partner_order_save($partnerCode, $partner, $request, $action);
        $orders = jg_partner_order_list($partnerCode);
        echo json_encode([
            'order' => $order,
            'orders' => $orders,
            'analytics' => jg_partner_order_analytics($orders),
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete') {
        $id = trim((string) ($request['id'] ?? ''));
        if ($id === '') {
            jg_order_fail('Order id is required.');
        }

        jg_partner_order_delete($partnerCode, $id);
        $orders = jg_partner_order_list($partnerCode);
        echo json_encode([
            'orders' => $orders,
            'analytics' => jg_partner_order_analytics($orders),
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (InvalidArgumentException $exception) {
    jg_order_fail($exception->getMessage(), 422);
} catch (RuntimeException $exception) {
    jg_order_fail($exception->getMessage(), 404);
} catch (Throwable) {
    jg_order_fail('Unable to save order.', 500);
}

jg_order_fail('Unknown action.', 400);
