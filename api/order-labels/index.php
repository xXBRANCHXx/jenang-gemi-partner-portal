<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

jg_partner_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

function jg_partner_label_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    jg_partner_label_fail('Method not allowed.', 405);
}

$partnerCode = jg_partner_current_code();
$orderId = trim((string) ($_POST['order_id'] ?? ''));
if ($orderId === '') {
    jg_partner_label_fail('Order id is required.');
}

$action = trim((string) ($_POST['action'] ?? 'upload')) ?: 'upload';

try {
    if ($action === 'delete') {
        $labels = jg_partner_order_delete_label($partnerCode, $orderId);
        echo json_encode([
            'ok' => true,
            'labels' => $labels,
            'storage' => jg_partner_order_storage_mode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!isset($_FILES['labels']) || !is_array($_FILES['labels'])) {
        jg_partner_label_fail('Select one label file.');
    }

    $labels = jg_partner_order_store_uploaded_labels($partnerCode, $orderId, $_FILES['labels']);
    echo json_encode([
        'ok' => true,
        'labels' => $labels,
        'storage' => jg_partner_order_storage_mode(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (InvalidArgumentException $exception) {
    jg_partner_label_fail($exception->getMessage(), 422);
} catch (RuntimeException $exception) {
    jg_partner_label_fail($exception->getMessage(), 404);
} catch (Throwable) {
    jg_partner_label_fail('Unable to upload labels.', 500);
}
