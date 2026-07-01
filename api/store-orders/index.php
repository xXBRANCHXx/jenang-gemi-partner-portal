<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

header('Content-Type: application/json; charset=utf-8');

function jg_store_orders_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_orders_token(): string
{
    return jg_partner_portal_config_value('JG_STORE_OPS_ORDERS_TOKEN', 'store_ops_orders_token');
}

function jg_store_orders_request_token(): string
{
    $header = (string) ($_SERVER['HTTP_X_STORE_OPS_TOKEN'] ?? '');
    if (trim($header) !== '') {
        return trim($header);
    }

    return trim((string) ($_GET['token'] ?? ''));
}

function jg_store_orders_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jg_store_orders_display_id(string $orderId): string
{
    $normalized = strtoupper(trim($orderId));
    $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', $normalized) ?: $normalized;
    return str_starts_with($normalized, 'PARTNER-') ? $normalized : 'PARTNER-' . $normalized;
}

function jg_store_orders_deadline_at(array $order): int
{
    $deadlineRaw = trim((string) ($order['deadline_at'] ?? ''));
    if ($deadlineRaw !== '') {
        $deadlineSource = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $deadlineRaw) ? $deadlineRaw : $deadlineRaw . ' UTC';
        $deadlineTimestamp = strtotime($deadlineSource);
        if ($deadlineTimestamp !== false) {
            return $deadlineTimestamp * 1000;
        }
    }

    $raw = (string) ($order['order_timestamp'] ?? $order['created_at'] ?? $order['updated_at'] ?? '');
    $timestamp = $raw !== '' ? strtotime($raw . ' UTC') : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    $deadlineHours = max(1, min(48, (int) ($order['deadline_hours'] ?? 24)));
    return ($timestamp + ($deadlineHours * 3600)) * 1000;
}

function jg_store_orders_items(array $order): array
{
    $items = [];
    foreach ((array) ($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sku = strtoupper(trim((string) ($item['sku_code'] ?? $item['sku'] ?? '')));
        $productName = trim(implode(' ', array_filter([
            (string) ($item['brand'] ?? ''),
            (string) ($item['product'] ?? $item['sku_label'] ?? ''),
            (string) ($item['flavor'] ?? ''),
            (string) ($item['size'] ?? ''),
        ])));
        $items[] = [
            'sku' => $sku,
            'barcode' => $sku,
            'source_tag' => $sku,
            'productName' => $productName !== '' ? $productName : ($sku !== '' ? $sku : 'Partner item'),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'sourcePlatform' => 'Partner',
            'unitRevenue' => (float) ($item['unit_revenue'] ?? $item['partner_price'] ?? 0),
            'lineRevenue' => (float) ($item['line_revenue'] ?? 0),
            'matchConfidence' => (float) ($item['match_confidence'] ?? 0),
        ];
    }

    return $items;
}

function jg_store_orders_base_url(): string
{
    $configured = jg_partner_portal_config_value('JG_PARTNER_PORTAL_BASE_URL', 'partner_portal_base_url');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' ? $scheme . '://' . $host : 'https://partner.jenanggemi.com';
}

function jg_store_orders_labels(array $order): array
{
    $baseUrl = jg_store_orders_base_url();
    $labels = [];
    foreach ((array) ($order['labels'] ?? []) as $label) {
        if (!is_array($label)) {
            continue;
        }

        $path = ltrim((string) ($label['path'] ?? ''), '/');
        $labels[] = [
            'name' => (string) ($label['name'] ?? 'Partner shipping label'),
            'path' => $path,
            'url' => $path !== '' ? $baseUrl . '/' . $path : '',
            'mime_type' => (string) ($label['mime_type'] ?? ''),
            'size_bytes' => (int) ($label['size_bytes'] ?? 0),
            'created_at' => (string) ($label['created_at'] ?? ''),
        ];
    }

    return $labels;
}

function jg_store_orders_has_labels(array $order): bool
{
    foreach ((array) ($order['labels'] ?? []) as $label) {
        if (is_array($label) && trim((string) ($label['path'] ?? $label['url'] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function jg_store_orders_normalize(array $order): array
{
    $createdAt = (string) ($order['created_at'] ?? '');
    $updatedAt = (string) ($order['updated_at'] ?? '');

    return [
        'id' => jg_store_orders_display_id((string) ($order['id'] ?? '')),
        'sourceOrderId' => (string) ($order['id'] ?? ''),
        'platform' => 'Partner',
        'account' => (string) ($order['partner_code'] ?? 'Partner'),
        'partnerCode' => strtoupper(trim((string) ($order['partner_code'] ?? ''))),
        'status' => (string) ($order['status'] ?? 'IS_LISTED'),
        'marketplaceStatus' => trim((string) ($order['marketplace_platform'] ?? '')) !== '' ? 'PARTNER_' . strtoupper(preg_replace('/[^A-Z0-9]+/', '_', (string) ($order['marketplace_platform'] ?? ''))) : 'PARTNER_ORDER',
        'marketplacePlatform' => (string) ($order['marketplace_platform'] ?? ''),
        'deadlineHours' => (int) ($order['deadline_hours'] ?? 24),
        'instant' => false,
        'deadlineAt' => jg_store_orders_deadline_at($order),
        'createdAt' => $createdAt !== '' ? gmdate(DATE_ATOM, strtotime($createdAt . ' UTC') ?: time()) : null,
        'updatedAt' => $updatedAt !== '' ? gmdate(DATE_ATOM, strtotime($updatedAt . ' UTC') ?: time()) : null,
        'customerName' => (string) ($order['customer_name'] ?? ''),
        'notes' => (string) ($order['notes'] ?? ''),
        'revenueTotal' => (float) ($order['revenue_total'] ?? 0),
        'inference' => is_array($order['inference'] ?? null) ? $order['inference'] : [],
        'items' => jg_store_orders_items($order),
        'labels' => jg_store_orders_labels($order),
    ];
}

$configuredToken = jg_store_orders_token();
if ($configuredToken === '') {
    jg_store_orders_fail('Store Ops order feed token is not configured.', 503);
}

if (!hash_equals($configuredToken, jg_store_orders_request_token())) {
    jg_store_orders_fail('Unauthorized.', 401);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $payload = jg_store_orders_request_body();
    $action = (string) ($payload['action'] ?? '');
    if ($action !== 'update_status') {
        jg_store_orders_fail('Unknown action.', 400);
    }

    $orderId = trim((string) ($payload['order'] ?? $payload['order_id'] ?? ''));
    $status = trim((string) ($payload['status'] ?? ''));
    if ($orderId === '') {
        jg_store_orders_fail('Order id is required.');
    }

    try {
        if (!jg_partner_order_set_status($orderId, $status)) {
            jg_store_orders_fail('Order not found.', 404);
        }
    } catch (InvalidArgumentException $exception) {
        jg_store_orders_fail($exception->getMessage(), 422);
    }

    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'GET') {
    jg_store_orders_fail('Method not allowed.', 405);
}

try {
    $orders = array_values(array_filter(array_map(
        static fn (array $order): array => jg_store_orders_normalize($order),
        jg_partner_order_list_all()
    ), static fn (array $order): bool => (string) ($order['sourceOrderId'] ?? '') !== '' && jg_store_orders_has_labels($order)));
} catch (Throwable $exception) {
    jg_store_orders_fail($exception->getMessage() ?: 'Unable to load partner orders.', 500);
}

echo json_encode([
    'ok' => true,
    'orders' => $orders,
    'meta' => [
        'source' => 'partner-portal',
        'storage' => jg_partner_order_storage_mode(),
        'count' => count($orders),
        'fetched_at' => gmdate(DATE_ATOM),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
