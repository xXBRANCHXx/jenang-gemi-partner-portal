<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const JG_ARCHIVE_CLEANUP_PARTNER_CODE = 'BAGGOSMEDIA123';

function jg_archive_cleanup_fail(string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$requestToken = trim((string) ($_SERVER['HTTP_X_STORE_OPS_TOKEN'] ?? ''));
$authorized = false;
foreach (jg_partner_order_store_ops_tokens() as $configuredToken) {
    $authorized = ($requestToken !== '' && hash_equals($configuredToken, $requestToken)) || $authorized;
}
if (!$authorized) {
    jg_archive_cleanup_fail('Unauthorized.', 401);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    jg_archive_cleanup_fail('Method not allowed.', 405);
}

$pdo = jg_partner_data_db();
if (!$pdo instanceof PDO) {
    jg_archive_cleanup_fail('Production database is unavailable.', 503);
}

$countArchived = static function () use ($pdo): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM partner_orders
         WHERE partner_code = :partner_code
           AND archived_at IS NOT NULL
           AND TRIM(archived_at) <> ""'
    );
    $stmt->execute([':partner_code' => JG_ARCHIVE_CLEANUP_PARTNER_CODE]);
    return (int) $stmt->fetchColumn();
};

if ($method === 'GET') {
    echo json_encode([
        'ok' => true,
        'partner_code' => JG_ARCHIVE_CLEANUP_PARTNER_CODE,
        'archived_count' => $countArchived(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT o.id AS order_id,
            l.original_name, l.stored_name, l.relative_path, l.mime_type,
            l.size_bytes, l.expires_at, l.created_at
     FROM partner_orders o
     LEFT JOIN partner_order_labels l ON l.order_id = o.id AND l.deleted_at IS NULL
     WHERE o.partner_code = :partner_code
       AND o.archived_at IS NOT NULL
       AND TRIM(o.archived_at) <> ""
     ORDER BY o.id'
);
$stmt->execute([':partner_code' => JG_ARCHIVE_CLEANUP_PARTNER_CODE]);

$labelsByOrder = [];
foreach ($stmt->fetchAll() as $row) {
    $orderId = trim((string) ($row['order_id'] ?? ''));
    if ($orderId === '') {
        continue;
    }
    $labelsByOrder[$orderId] ??= [];
    if (trim((string) ($row['stored_name'] ?? '')) !== '' || trim((string) ($row['relative_path'] ?? '')) !== '') {
        $labelsByOrder[$orderId][] = $row;
    }
}

$deletableIds = [];
$fileFailures = [];
foreach ($labelsByOrder as $orderId => $labels) {
    if (jg_partner_order_unlink_labels($labels)) {
        $deletableIds[] = $orderId;
    } else {
        $fileFailures[] = $orderId;
    }
}

$deleted = 0;
if ($deletableIds !== []) {
    $placeholders = implode(',', array_fill(0, count($deletableIds), '?'));
    $delete = $pdo->prepare(
        'DELETE FROM partner_orders
         WHERE partner_code = ?
           AND archived_at IS NOT NULL
           AND TRIM(archived_at) <> ""
           AND id IN (' . $placeholders . ')'
    );
    $delete->execute(array_merge([JG_ARCHIVE_CLEANUP_PARTNER_CODE], $deletableIds));
    $deleted = $delete->rowCount();
}

$remaining = $countArchived();
$ok = $remaining === 0 && $fileFailures === [];
if (!$ok) {
    http_response_code(500);
}
echo json_encode([
    'ok' => $ok,
    'partner_code' => JG_ARCHIVE_CLEANUP_PARTNER_CODE,
    'deleted_count' => $deleted,
    'remaining_count' => $remaining,
    'file_failure_count' => count($fileFailures),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
