<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

function jg_store_label_fail(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_store_label_fail('Method not allowed.', 405);
}

$orderId = trim((string) ($_GET['order_id'] ?? ''));
$expires = (int) ($_GET['expires'] ?? 0);
$signature = strtolower(trim((string) ($_GET['signature'] ?? '')));
$token = jg_partner_portal_config_value('JG_STORE_OPS_ORDERS_TOKEN', 'store_ops_orders_token');

if (!jg_partner_order_verify_store_download($orderId, $expires, $signature, $token)) {
    jg_store_label_fail('Unauthorized.', 401);
}

try {
    $order = null;
    foreach (jg_partner_order_list_all() as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $orderId) {
            $order = $candidate;
            break;
        }
    }
    $label = is_array($order) ? (($order['labels'] ?? [])[0] ?? null) : null;
    if (!is_array($label)) {
        throw new RuntimeException('Shipping label is unavailable.');
    }
    jg_partner_order_stream_label($label);
} catch (Throwable $exception) {
    jg_store_label_fail($exception->getMessage() ?: 'Shipping label is unavailable.', 404);
}
