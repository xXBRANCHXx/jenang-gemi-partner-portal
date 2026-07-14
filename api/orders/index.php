<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

jg_partner_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

function jg_order_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_order_is_multipart(): bool
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    return str_contains($contentType, 'multipart/form-data');
}

function jg_order_request(): array
{
    if (jg_order_is_multipart()) {
        $payload = trim((string) ($_POST['payload'] ?? ''));
        if ($payload !== '') {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                jg_order_fail('Order payload is invalid JSON.');
            }
            return $decoded;
        }

        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$partnerCode = jg_partner_current_code();
$partner = jg_partner_current_profile();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    try {
        $orders = jg_partner_order_list($partnerCode);
        echo json_encode([
            'orders' => $orders,
            'analytics' => jg_partner_order_analytics($orders),
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $exception) {
        jg_order_fail($exception->getMessage() ?: 'Unable to load orders.', 500);
    }
}

if ($method !== 'POST') {
    jg_order_fail('Method not allowed.', 405);
}

jg_partner_require_csrf_json();

$request = jg_order_request();
$action = (string) ($request['action'] ?? '');

try {
    if ($action === 'create' || $action === 'update') {
        if ($action === 'create') {
            if (!jg_order_is_multipart() || !isset($_FILES['labels']) || !is_array($_FILES['labels'])) {
                jg_order_fail('Upload a shipment label PDF.');
            }
            $order = jg_partner_order_create_with_label($partnerCode, $partner, $request, $_FILES['labels']);
        } else {
            $order = jg_partner_order_save($partnerCode, $partner, $request, $action);
        }
        $orders = jg_partner_order_list($partnerCode);
        echo json_encode([
            'order' => $order,
            'orders' => $orders,
            'analytics' => jg_partner_order_analytics($orders),
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'cancel' || $action === 'delete') {
        $id = trim((string) ($request['id'] ?? ''));
        if ($id === '') {
            jg_order_fail('Order id is required.');
        }

        jg_partner_order_cancel($partnerCode, $id);
        $orders = jg_partner_order_list($partnerCode);
        echo json_encode([
            'orders' => $orders,
            'analytics' => jg_partner_order_analytics($orders),
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'archive' || $action === 'unarchive') {
        $id = trim((string) ($request['id'] ?? ''));
        if ($id === '') {
            jg_order_fail('Order id is required.');
        }

        jg_partner_order_set_archived($partnerCode, $id, $action === 'archive');
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
    $message = $exception->getMessage() ?: 'Unable to save order.';
    jg_order_fail($message, str_contains(strtolower($message), 'not found') ? 404 : 500);
} catch (Throwable) {
    jg_order_fail('Unable to save order.', 500);
}

jg_order_fail('Unknown action.', 400);
