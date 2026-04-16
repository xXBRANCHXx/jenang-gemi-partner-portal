<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';

jg_partner_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

const JG_ORDER_FILE = __DIR__ . '/../../data/orders.json';

function jg_order_default(): array
{
    return [
        'meta' => [
            'version' => '1.00.00',
            'updated_at' => gmdate(DATE_ATOM),
        ],
        'orders' => [],
    ];
}

function jg_order_read(): array
{
    if (!file_exists(JG_ORDER_FILE)) {
        return jg_order_default();
    }
    $raw = file_get_contents(JG_ORDER_FILE);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : jg_order_default();
}

function jg_order_write(array $data): void
{
    $data['meta']['updated_at'] = gmdate(DATE_ATOM);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode orders.');
    }
    file_put_contents(JG_ORDER_FILE, $encoded . PHP_EOL, LOCK_EX);
}

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

function jg_order_text(mixed $value, string $label): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        jg_order_fail($label . ' is required.');
    }
    return $normalized;
}

$partnerCode = jg_partner_current_code();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$database = jg_order_read();
$database['orders'] = array_values(array_filter($database['orders'] ?? [], 'is_array'));

if ($method === 'GET') {
    $orders = array_values(array_filter($database['orders'], static fn(array $order): bool => (string) ($order['partner_code'] ?? '') === $partnerCode));
    echo json_encode(['orders' => $orders], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    jg_order_fail('Method not allowed.', 405);
}

$request = jg_order_request();
$action = (string) ($request['action'] ?? '');

if ($action === 'create') {
    $id = 'order-' . substr(sha1($partnerCode . microtime(true)), 0, 10);
    $database['orders'][] = [
        'id' => $id,
        'partner_code' => $partnerCode,
        'customer_name' => jg_order_text($request['customer_name'] ?? '', 'Customer name'),
        'brand' => jg_order_text($request['brand'] ?? '', 'Brand'),
        'product' => jg_order_text($request['product'] ?? '', 'Product'),
        'flavor' => jg_order_text($request['flavor'] ?? '', 'Flavor'),
        'size' => jg_order_text($request['size'] ?? '', 'Size'),
        'quantity' => max(1, (int) ($request['quantity'] ?? 1)),
        'notes' => trim((string) ($request['notes'] ?? '')),
        'status' => 'draft',
        'created_at' => gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
    ];
    jg_order_write($database);
    echo json_encode(['orders' => array_values(array_filter($database['orders'], static fn(array $order): bool => (string) ($order['partner_code'] ?? '') === $partnerCode))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'update') {
    $id = jg_order_text($request['id'] ?? '', 'Order id');
    foreach ($database['orders'] as &$order) {
        if ((string) ($order['id'] ?? '') !== $id || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }
        $order['customer_name'] = jg_order_text($request['customer_name'] ?? '', 'Customer name');
        $order['brand'] = jg_order_text($request['brand'] ?? '', 'Brand');
        $order['product'] = jg_order_text($request['product'] ?? '', 'Product');
        $order['flavor'] = jg_order_text($request['flavor'] ?? '', 'Flavor');
        $order['size'] = jg_order_text($request['size'] ?? '', 'Size');
        $order['quantity'] = max(1, (int) ($request['quantity'] ?? 1));
        $order['notes'] = trim((string) ($request['notes'] ?? ''));
        $order['updated_at'] = gmdate(DATE_ATOM);
        jg_order_write($database);
        echo json_encode(['orders' => array_values(array_filter($database['orders'], static fn(array $item): bool => (string) ($item['partner_code'] ?? '') === $partnerCode))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    unset($order);
    jg_order_fail('Order not found.', 404);
}

if ($action === 'delete') {
    $id = jg_order_text($request['id'] ?? '', 'Order id');
    $database['orders'] = array_values(array_filter($database['orders'], static fn(array $order): bool => !((string) ($order['id'] ?? '') === $id && (string) ($order['partner_code'] ?? '') === $partnerCode)));
    jg_order_write($database);
    echo json_encode(['orders' => array_values(array_filter($database['orders'], static fn(array $order): bool => (string) ($order['partner_code'] ?? '') === $partnerCode))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

jg_order_fail('Unknown action.', 400);
