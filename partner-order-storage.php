<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-data-bootstrap.php';

const JG_PARTNER_ORDER_JSON_FILE = __DIR__ . '/data/orders.json';
const JG_PARTNER_LABEL_UPLOAD_DIR = __DIR__ . '/uploads/shipping-labels';

function jg_partner_order_default_database(): array
{
    return [
        'meta' => [
            'version' => '1.00.00',
            'updated_at' => gmdate(DATE_ATOM),
            'storage' => 'json',
        ],
        'orders' => [],
    ];
}

function jg_partner_order_storage_mode(): string
{
    return jg_partner_data_db() instanceof PDO ? 'mysql' : 'json';
}

function jg_partner_order_read_json_database(): array
{
    if (!is_file(JG_PARTNER_ORDER_JSON_FILE)) {
        return jg_partner_order_default_database();
    }

    $raw = @file_get_contents(JG_PARTNER_ORDER_JSON_FILE);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return jg_partner_order_default_database();
    }

    $decoded['meta'] = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
    $decoded['orders'] = array_values(array_filter($decoded['orders'] ?? [], 'is_array'));
    $decoded['meta']['storage'] = 'json';

    return $decoded;
}

function jg_partner_order_write_json_database(array $database): void
{
    $database['meta']['updated_at'] = gmdate(DATE_ATOM);
    $database['meta']['storage'] = 'json';

    $encoded = json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode orders.');
    }

    file_put_contents(JG_PARTNER_ORDER_JSON_FILE, $encoded . PHP_EOL, LOCK_EX);
}

function jg_partner_order_allowed_sku_index(?array $partner): array
{
    $index = [];

    foreach ((array) ($partner['selected_sku_records'] ?? []) as $sku) {
        if (!is_array($sku)) {
            continue;
        }

        $skuCode = trim((string) ($sku['sku'] ?? ''));
        if ($skuCode === '') {
            continue;
        }

        $index[$skuCode] = [
            'sku' => $skuCode,
            'label' => trim((string) ($sku['label'] ?? '')) ?: $skuCode,
            'brand_name' => trim((string) ($sku['brand_name'] ?? '')),
            'product_name' => trim((string) ($sku['product_name'] ?? $sku['base_product_name'] ?? '')),
            'flavor_name' => trim((string) ($sku['flavor_name'] ?? '')),
            'size_label' => trim((string) ($sku['size_label'] ?? '')),
            'current_stock' => (int) ($sku['current_stock'] ?? 0),
        ];
    }

    return $index;
}

function jg_partner_order_validate_sku(?array $partner, mixed $skuCode): array
{
    $normalized = trim((string) $skuCode);
    if ($normalized === '') {
        throw new InvalidArgumentException('SKU is required.');
    }

    $allowed = jg_partner_order_allowed_sku_index($partner);
    if (!isset($allowed[$normalized])) {
        throw new InvalidArgumentException('That SKU is not enabled for this partner.');
    }

    return $allowed[$normalized];
}

function jg_partner_order_normalize_timestamp(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return gmdate(DATE_ATOM);
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Order timestamp is invalid.');
    }

    return gmdate(DATE_ATOM, $timestamp);
}

function jg_partner_order_normalize_items(?array $partner, mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('Add at least one product to the invoice.');
    }

    $items = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sku = jg_partner_order_validate_sku($partner, $item['sku_code'] ?? null);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));

        $items[] = [
            'sku_code' => $sku['sku'],
            'sku_label' => $sku['label'],
            'brand' => $sku['brand_name'],
            'product' => $sku['product_name'],
            'flavor' => $sku['flavor_name'],
            'size' => $sku['size_label'],
            'quantity' => $quantity,
        ];
    }

    if ($items === []) {
        throw new InvalidArgumentException('Add at least one product to the invoice.');
    }

    return $items;
}

function jg_partner_order_item_summary(array $items): array
{
    $first = $items[0] ?? [];
    $productNames = array_values(array_unique(array_filter(array_map(
        static fn (array $item): string => trim((string) ($item['product'] ?? '')),
        $items
    ))));

    return [
        'brand' => (string) ($first['brand'] ?? ''),
        'product' => $productNames === [] ? (string) ($first['product'] ?? '') : implode(', ', $productNames),
        'sku_code' => (string) ($first['sku_code'] ?? ''),
        'sku_label' => count($items) === 1 ? (string) ($first['sku_label'] ?? '') : count($items) . ' invoice items',
        'flavor' => (string) ($first['flavor'] ?? ''),
        'size' => (string) ($first['size'] ?? ''),
        'quantity' => array_sum(array_map(static fn (array $item): int => max(1, (int) ($item['quantity'] ?? 1)), $items)),
    ];
}

function jg_partner_order_normalize_text(mixed $value, string $label, int $maxLength = 160, bool $required = true): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if ($normalized === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }

        return '';
    }

    if (mb_strlen($normalized) > $maxLength) {
        throw new InvalidArgumentException($label . ' is too long.');
    }

    return $normalized;
}

function jg_partner_order_build_record(string $partnerCode, ?array $partner, array $payload, ?array $existing = null): array
{
    $items = jg_partner_order_normalize_items($partner, $payload['items'] ?? []);
    $summary = jg_partner_order_item_summary($items);
    $createdAt = (string) ($existing['created_at'] ?? gmdate(DATE_ATOM));
    $labelRecords = array_values(array_filter((array) ($existing['labels'] ?? []), 'is_array'));
    $orderTimestamp = jg_partner_order_normalize_timestamp($payload['order_timestamp'] ?? ($existing['order_timestamp'] ?? $createdAt));

    return [
        'id' => (string) ($existing['id'] ?? ('order-' . substr(sha1($partnerCode . microtime(true) . random_int(1000, 9999)), 0, 12))),
        'partner_code' => $partnerCode,
        'customer_name' => jg_partner_order_normalize_text($payload['customer_name'] ?? '', 'Customer name'),
        'brand' => $summary['brand'],
        'product' => $summary['product'],
        'sku_code' => $summary['sku_code'],
        'sku_label' => $summary['sku_label'],
        'flavor' => $summary['flavor'],
        'size' => $summary['size'],
        'quantity' => $summary['quantity'],
        'items' => $items,
        'order_timestamp' => $orderTimestamp,
        'notes' => jg_partner_order_normalize_text($payload['notes'] ?? '', 'Notes', 300, false),
        'status' => trim((string) ($existing['status'] ?? 'draft')) ?: 'draft',
        'created_at' => $createdAt,
        'updated_at' => gmdate(DATE_ATOM),
        'labels' => $labelRecords,
    ];
}

function jg_partner_order_fetch_labels_mysql(PDO $pdo, string $partnerCode): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, original_name, stored_name, relative_path, mime_type, size_bytes, created_at
         FROM partner_order_labels
         WHERE partner_code = :partner_code
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':partner_code' => $partnerCode]);

    $labelsByOrder = [];
    foreach ($stmt->fetchAll() as $row) {
        $orderId = (string) ($row['order_id'] ?? '');
        if ($orderId === '') {
            continue;
        }

        $relativePath = trim((string) ($row['relative_path'] ?? ''));
        $labelsByOrder[$orderId][] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['original_name'] ?? ''),
            'stored_name' => (string) ($row['stored_name'] ?? ''),
            'path' => $relativePath,
            'url' => $relativePath !== '' ? '../' . ltrim($relativePath, '/') : '',
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_partner_order_attach_labels(array $orders, array $labelsByOrder): array
{
    foreach ($orders as &$order) {
        $orderId = (string) ($order['id'] ?? '');
        $order['labels'] = array_values(array_filter($labelsByOrder[$orderId] ?? (array) ($order['labels'] ?? []), 'is_array'));
        $order['label_count'] = count($order['labels']);
    }
    unset($order);

    return $orders;
}

function jg_partner_order_list(string $partnerCode): array
{
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, items_json, created_at, updated_at
             FROM partner_orders
             WHERE partner_code = :partner_code
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':partner_code' => $partnerCode]);

        $orders = [];
        foreach ($stmt->fetchAll() as $row) {
            $items = json_decode((string) ($row['items_json'] ?? ''), true);
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            if ($items === []) {
                $items = [[
                    'sku_code' => (string) ($row['sku_code'] ?? ''),
                    'sku_label' => (string) ($row['sku_label'] ?? ''),
                    'brand' => (string) ($row['brand_name'] ?? ''),
                    'product' => (string) ($row['product_name'] ?? ''),
                    'quantity' => (int) ($row['quantity'] ?? 1),
                ]];
            }

            $orders[] = [
                'id' => (string) ($row['id'] ?? ''),
                'partner_code' => (string) ($row['partner_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'brand' => (string) ($row['brand_name'] ?? ''),
                'product' => (string) ($row['product_name'] ?? ''),
                'sku_code' => (string) ($row['sku_code'] ?? ''),
                'sku_label' => (string) ($row['sku_label'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 1),
                'items' => $items,
                'order_timestamp' => (string) ($row['order_timestamp'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'draft'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return jg_partner_order_attach_labels($orders, jg_partner_order_fetch_labels_mysql($pdo, $partnerCode));
    }

    $database = jg_partner_order_read_json_database();
    $orders = array_values(array_filter(
        $database['orders'],
        static fn (array $order): bool => (string) ($order['partner_code'] ?? '') === $partnerCode
    ));

    foreach ($orders as &$order) {
        if (!isset($order['items']) || !is_array($order['items'])) {
            $order['items'] = [[
                'sku_code' => (string) ($order['sku_code'] ?? ''),
                'sku_label' => (string) ($order['sku_label'] ?? ''),
                'brand' => (string) ($order['brand'] ?? ''),
                'product' => (string) ($order['product'] ?? ''),
                'quantity' => (int) ($order['quantity'] ?? 1),
            ]];
        }
        $order['order_timestamp'] = (string) ($order['order_timestamp'] ?? $order['created_at'] ?? '');
    }
    unset($order);

    return jg_partner_order_attach_labels($orders, []);
}

function jg_partner_order_find(string $partnerCode, string $orderId): ?array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return null;
    }

    foreach (jg_partner_order_list($partnerCode) as $order) {
        if ((string) ($order['id'] ?? '') === $orderId) {
            return $order;
        }
    }

    return null;
}

function jg_partner_order_save(string $partnerCode, ?array $partner, array $payload, string $action): array
{
    if ($action !== 'create' && $action !== 'update') {
        throw new InvalidArgumentException('Unknown action.');
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        if ($action === 'create') {
            $record = jg_partner_order_build_record($partnerCode, $partner, $payload);
            $stmt = $pdo->prepare(
                'INSERT INTO partner_orders
                    (id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, items_json, created_at, updated_at)
                 VALUES
                    (:id, :partner_code, :customer_name, :brand_name, :product_name, :sku_code, :sku_label, :quantity, :notes, :status, :order_timestamp, :items_json, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':id' => $record['id'],
                ':partner_code' => $record['partner_code'],
                ':customer_name' => $record['customer_name'],
                ':brand_name' => $record['brand'],
                ':product_name' => $record['product'],
                ':sku_code' => $record['sku_code'],
                ':sku_label' => $record['sku_label'],
                ':quantity' => $record['quantity'],
                ':notes' => $record['notes'],
                ':status' => $record['status'],
                ':order_timestamp' => gmdate('Y-m-d H:i:s', strtotime($record['order_timestamp'])),
                ':items_json' => json_encode($record['items'], JSON_UNESCAPED_SLASHES),
                ':created_at' => gmdate('Y-m-d H:i:s', strtotime($record['created_at'])),
                ':updated_at' => gmdate('Y-m-d H:i:s', strtotime($record['updated_at'])),
            ]);

            return jg_partner_order_find($partnerCode, $record['id']) ?? $record;
        }

        $orderId = jg_partner_order_normalize_text($payload['id'] ?? '', 'Order id');
        $existing = jg_partner_order_find($partnerCode, $orderId);
        if (!is_array($existing)) {
            throw new RuntimeException('Order not found.');
        }

        $record = jg_partner_order_build_record($partnerCode, $partner, $payload, $existing);
        $stmt = $pdo->prepare(
            'UPDATE partner_orders
             SET customer_name = :customer_name,
                 brand_name = :brand_name,
                 product_name = :product_name,
                 sku_code = :sku_code,
                 sku_label = :sku_label,
                 quantity = :quantity,
                 notes = :notes,
                 order_timestamp = :order_timestamp,
                 items_json = :items_json,
                 updated_at = :updated_at
             WHERE id = :id AND partner_code = :partner_code'
        );
        $stmt->execute([
            ':customer_name' => $record['customer_name'],
            ':brand_name' => $record['brand'],
            ':product_name' => $record['product'],
            ':sku_code' => $record['sku_code'],
            ':sku_label' => $record['sku_label'],
            ':quantity' => $record['quantity'],
            ':notes' => $record['notes'],
            ':order_timestamp' => gmdate('Y-m-d H:i:s', strtotime($record['order_timestamp'])),
            ':items_json' => json_encode($record['items'], JSON_UNESCAPED_SLASHES),
            ':updated_at' => gmdate('Y-m-d H:i:s', strtotime($record['updated_at'])),
            ':id' => $record['id'],
            ':partner_code' => $partnerCode,
        ]);

        return jg_partner_order_find($partnerCode, $orderId) ?? $record;
    }

    $database = jg_partner_order_read_json_database();

    if ($action === 'create') {
        $record = jg_partner_order_build_record($partnerCode, $partner, $payload);
        $database['orders'][] = $record;
        jg_partner_order_write_json_database($database);
        return $record;
    }

    $orderId = jg_partner_order_normalize_text($payload['id'] ?? '', 'Order id');
    foreach ($database['orders'] as $index => $order) {
        if ((string) ($order['id'] ?? '') !== $orderId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        $record = jg_partner_order_build_record($partnerCode, $partner, $payload, $order);
        $database['orders'][$index] = $record;
        jg_partner_order_write_json_database($database);
        return $record;
    }

    throw new RuntimeException('Order not found.');
}

function jg_partner_order_delete(string $partnerCode, string $orderId): void
{
    $normalizedId = jg_partner_order_normalize_text($orderId, 'Order id');
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('DELETE FROM partner_orders WHERE id = :id AND partner_code = :partner_code');
        $stmt->execute([
            ':id' => $normalizedId,
            ':partner_code' => $partnerCode,
        ]);
        return;
    }

    $database = jg_partner_order_read_json_database();
    $database['orders'] = array_values(array_filter(
        $database['orders'],
        static fn (array $order): bool => !((string) ($order['id'] ?? '') === $normalizedId && (string) ($order['partner_code'] ?? '') === $partnerCode)
    ));
    jg_partner_order_write_json_database($database);
}

function jg_partner_order_upload_directory(): string
{
    if (!is_dir(JG_PARTNER_LABEL_UPLOAD_DIR)) {
        mkdir(JG_PARTNER_LABEL_UPLOAD_DIR, 0775, true);
    }

    return JG_PARTNER_LABEL_UPLOAD_DIR;
}

function jg_partner_order_allowed_extensions(): array
{
    return ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'zpl', 'txt', 'prn'];
}

function jg_partner_order_store_uploaded_labels(string $partnerCode, string $orderId, array $files): array
{
    $existingOrder = jg_partner_order_find($partnerCode, $orderId);
    if (!is_array($existingOrder)) {
        throw new RuntimeException('Order not found.');
    }
    if (!empty($existingOrder['labels'])) {
        throw new InvalidArgumentException('Delete the current shipping label before uploading another one.');
    }

    $uploadDir = jg_partner_order_upload_directory();
    $savedLabels = [];

    foreach ($files['name'] ?? [] as $index => $originalName) {
        if ($savedLabels !== []) {
            throw new InvalidArgumentException('Upload only one shipping label per order.');
        }
        $errorCode = (int) (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE));
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the uploaded files failed to upload.');
        }

        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        $sizeBytes = (int) ($files['size'][$index] ?? 0);
        $safeOriginal = trim((string) $originalName);
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));

        if ($safeOriginal === '') {
            throw new RuntimeException('Uploaded file name is invalid.');
        }
        if (!in_array($extension, jg_partner_order_allowed_extensions(), true)) {
            throw new RuntimeException('Unsupported file type. Use PDF, image, or label-print file formats.');
        }

        $storedName = sprintf(
            '%s-%s-%s.%s',
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($partnerCode)) ?: 'partner',
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($orderId)) ?: 'order',
            substr(sha1($safeOriginal . microtime(true) . random_int(1000, 9999)), 0, 12),
            $extension
        );

        $targetPath = rtrim($uploadDir, '/') . '/' . $storedName;
        if (!@move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Unable to save uploaded label.');
        }

        $mimeType = '';
        if (function_exists('mime_content_type')) {
            $mimeType = (string) @mime_content_type($targetPath);
        }

        $savedLabels[] = [
            'name' => $safeOriginal,
            'stored_name' => $storedName,
            'path' => 'uploads/shipping-labels/' . $storedName,
            'url' => '../uploads/shipping-labels/' . $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    if ($savedLabels === []) {
        return $existingOrder['labels'] ?? [];
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'INSERT INTO partner_order_labels
                (order_id, partner_code, original_name, stored_name, relative_path, mime_type, size_bytes, created_at)
             VALUES
                (:order_id, :partner_code, :original_name, :stored_name, :relative_path, :mime_type, :size_bytes, :created_at)'
        );

        foreach ($savedLabels as $label) {
            $stmt->execute([
                ':order_id' => $orderId,
                ':partner_code' => $partnerCode,
                ':original_name' => $label['name'],
                ':stored_name' => $label['stored_name'],
                ':relative_path' => $label['path'],
                ':mime_type' => $label['mime_type'],
                ':size_bytes' => $label['size_bytes'],
                ':created_at' => gmdate('Y-m-d H:i:s', strtotime($label['created_at'])),
            ]);
        }

        $fresh = jg_partner_order_find($partnerCode, $orderId);
        return $fresh['labels'] ?? [];
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as &$order) {
        if ((string) ($order['id'] ?? '') !== $orderId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        $existingLabels = array_values(array_filter((array) ($order['labels'] ?? []), 'is_array'));
        $order['labels'] = array_merge($existingLabels, $savedLabels);
        $order['updated_at'] = gmdate(DATE_ATOM);
        break;
    }
    unset($order);
    jg_partner_order_write_json_database($database);

    $fresh = jg_partner_order_find($partnerCode, $orderId);
    return $fresh['labels'] ?? [];
}

function jg_partner_order_analytics(array $orders): array
{
    $monthlyByYear = [];
    $hourlyBuckets = array_fill(0, 24, 0);

    foreach ($orders as $order) {
        $timestamp = strtotime((string) ($order['order_timestamp'] ?? $order['created_at'] ?? ''));
        if ($timestamp === false) {
            continue;
        }

        $year = gmdate('Y', $timestamp);
        $monthIndex = (int) gmdate('n', $timestamp) - 1;
        if (!isset($monthlyByYear[$year])) {
            $monthlyByYear[$year] = array_fill(0, 12, 0);
        }
        if ($monthIndex >= 0 && $monthIndex < 12) {
            $monthlyByYear[$year][$monthIndex] += 1;
        }

        $hourIndex = (int) gmdate('G', $timestamp);
        $hourlyBuckets[$hourIndex] += 1;
    }

    ksort($monthlyByYear);

    $busiestHour = 0;
    $busiestCount = -1;
    foreach ($hourlyBuckets as $hour => $count) {
        if ($count > $busiestCount) {
            $busiestCount = $count;
            $busiestHour = $hour;
        }
    }

    return [
        'years' => array_values(array_keys($monthlyByYear)),
        'monthly_by_year' => $monthlyByYear,
        'hourly_distribution' => $hourlyBuckets,
        'busiest_hour' => sprintf('%02d:00', $busiestHour),
        'total_orders' => count($orders),
    ];
}

function jg_partner_order_delete_label(string $partnerCode, string $orderId): array
{
    $order = jg_partner_order_find($partnerCode, $orderId);
    if (!is_array($order)) {
        throw new RuntimeException('Order not found.');
    }

    foreach ((array) ($order['labels'] ?? []) as $label) {
        $path = __DIR__ . '/' . ltrim((string) ($label['path'] ?? ''), '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('DELETE FROM partner_order_labels WHERE order_id = :order_id AND partner_code = :partner_code');
        $stmt->execute([
            ':order_id' => $orderId,
            ':partner_code' => $partnerCode,
        ]);
        return [];
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as &$storedOrder) {
        if ((string) ($storedOrder['id'] ?? '') !== $orderId || (string) ($storedOrder['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }
        $storedOrder['labels'] = [];
        $storedOrder['updated_at'] = gmdate(DATE_ATOM);
        break;
    }
    unset($storedOrder);
    jg_partner_order_write_json_database($database);

    return [];
}
