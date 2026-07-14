<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

jg_partner_require_auth_json();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$orderId = trim((string) ($_GET['order_id'] ?? ''));
if ($orderId === '') {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Order id is required.']);
    exit;
}

try {
    $order = jg_partner_order_find(jg_partner_current_code(), $orderId);
    $label = is_array($order) ? (($order['labels'] ?? [])[0] ?? null) : null;
    if (!is_array($label)) {
        throw new RuntimeException('Shipping label is unavailable.');
    }
    jg_partner_order_stream_label($label);
} catch (Throwable $exception) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $exception->getMessage() ?: 'Shipping label is unavailable.']);
    exit;
}
