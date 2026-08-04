<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-order-storage.php';

const JG_PARTNER_BILLING_MAX_FILE_BYTES = 10 * 1024 * 1024;
const JG_PARTNER_BILLING_NEW_BADGE_DAYS = 7;

function jg_partner_billing_db(): PDO
{
    $pdo = jg_partner_data_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Billing is temporarily unavailable.');
    }
    jg_partner_billing_ensure_schema($pdo);
    return $pdo;
}

function jg_partner_billing_ensure_schema(PDO $pdo): void
{
    static $prepared = [];
    $key = spl_object_id($pdo);
    if (isset($prepared[$key])) {
        return;
    }

    $statements = [
        'CREATE TABLE IF NOT EXISTS partner_weekly_bills (
            bill_id VARCHAR(120) NOT NULL PRIMARY KEY,
            partner_code VARCHAR(64) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "accruing",
            subtotal_amount BIGINT NOT NULL DEFAULT 0,
            adjustment_amount BIGINT NOT NULL DEFAULT 0,
            total_amount BIGINT NOT NULL DEFAULT 0,
            payment_submitted_at DATETIME NULL DEFAULT NULL,
            paid_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_partner_bill_period (partner_code, period_start),
            KEY idx_partner_bills_status (status, due_date),
            KEY idx_partner_bills_partner (partner_code, period_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_weekly_bill_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bill_id VARCHAR(120) NOT NULL,
            partner_code VARCHAR(64) NOT NULL,
            order_id VARCHAR(64) NOT NULL,
            order_date DATETIME NOT NULL,
            platform VARCHAR(64) NOT NULL DEFAULT "",
            customer_name VARCHAR(160) NOT NULL DEFAULT "",
            description VARCHAR(500) NOT NULL DEFAULT "",
            units INT UNSIGNED NOT NULL DEFAULT 0,
            amount BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT "included",
            dispute_id BIGINT UNSIGNED NULL DEFAULT NULL,
            removed_reason VARCHAR(500) NOT NULL DEFAULT "",
            paid_at DATETIME NULL DEFAULT NULL,
            snapshot_json LONGTEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_partner_bill_order (order_id),
            KEY idx_partner_bill_items_bill (bill_id, status),
            KEY idx_partner_bill_items_partner (partner_code, order_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_weekly_bill_disputes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dispute_key VARCHAR(120) NOT NULL,
            bill_id VARCHAR(120) NOT NULL,
            partner_code VARCHAR(64) NOT NULL,
            dispute_type VARCHAR(32) NOT NULL DEFAULT "paid",
            reason TEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            resolution_reason TEXT NULL DEFAULT NULL,
            evidence_file_id BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_partner_dispute_key (dispute_key),
            KEY idx_partner_disputes_status (status, created_at),
            KEY idx_partner_disputes_bill (bill_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_weekly_bill_dispute_items (
            dispute_id BIGINT UNSIGNED NOT NULL,
            bill_item_id BIGINT UNSIGNED NOT NULL,
            original_amount BIGINT NULL DEFAULT NULL,
            proposed_amount BIGINT NULL DEFAULT NULL,
            proposal_json LONGTEXT NULL DEFAULT NULL,
            resolved_amount BIGINT NULL DEFAULT NULL,
            resolution_json LONGTEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (dispute_id, bill_item_id),
            KEY idx_partner_dispute_items_item (bill_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_weekly_bill_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            partner_code VARCHAR(64) NOT NULL,
            bill_id VARCHAR(120) NOT NULL,
            file_kind VARCHAR(32) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            file_data LONGBLOB NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_partner_bill_files_bill (bill_id, file_kind, created_at),
            KEY idx_partner_bill_files_partner (partner_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_weekly_bill_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_key VARCHAR(120) NOT NULL,
            bill_id VARCHAR(120) NOT NULL,
            partner_code VARCHAR(64) NOT NULL,
            amount BIGINT NOT NULL,
            proof_file_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            submitted_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL DEFAULT NULL,
            accounting_transaction_id BIGINT UNSIGNED NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_partner_bill_payment_key (payment_key),
            UNIQUE KEY uniq_partner_bill_payment_bill (bill_id),
            KEY idx_partner_bill_payments_status (status, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS partner_billing_onboarding (
            partner_code VARCHAR(64) NOT NULL PRIMARY KEY,
            billing_seen_at DATETIME NULL DEFAULT NULL,
            tutorial_completed_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_status', 'VARCHAR(32) NOT NULL DEFAULT "unbilled"');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_reference', 'VARCHAR(120) NOT NULL DEFAULT ""');
    jg_partner_data_ensure_column($pdo, 'partner_orders', 'billing_paid_at', 'DATETIME NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_disputes', 'dispute_type', 'VARCHAR(32) NOT NULL DEFAULT "paid"');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_dispute_items', 'original_amount', 'BIGINT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_dispute_items', 'proposed_amount', 'BIGINT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_dispute_items', 'proposal_json', 'LONGTEXT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_dispute_items', 'resolved_amount', 'BIGINT NULL DEFAULT NULL');
    jg_partner_data_ensure_column($pdo, 'partner_weekly_bill_dispute_items', 'resolution_json', 'LONGTEXT NULL DEFAULT NULL');
    jg_partner_data_ensure_index($pdo, 'partner_orders', 'idx_partner_orders_billing', '(partner_code, billing_status, billing_paid_at)');

    $prepared[$key] = true;
}

/** @return array{start:string,end:string,due:string,id_date:string} */
function jg_partner_billing_period(DateTimeImmutable $date, ?DateTimeZone $timezone = null): array
{
    $timezone ??= new DateTimeZone('Asia/Jakarta');
    $localDate = $date->setTimezone($timezone)->setTime(0, 0);
    $daysSinceMonday = (int) $localDate->format('N') - 1;
    $start = $daysSinceMonday > 0 ? $localDate->modify('-' . $daysSinceMonday . ' days') : $localDate;
    $end = $start->modify('+6 days');
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'due' => $end->modify('+3 days')->format('Y-m-d'),
        'id_date' => $start->format('Ymd'),
    ];
}

/**
 * Move legacy Wednesday–Tuesday bill items into calendar-week bills.
 *
 * Bills with a payment, dispute, or uploaded file are intentionally preserved so
 * a period correction can never rewrite an active or completed audit trail.
 *
 * @return list<string> Bill IDs whose totals need recalculating.
 */
function jg_partner_billing_align_calendar_weeks(PDO $pdo, string $partnerCode): array
{
    $stmt = $pdo->prepare(
        'SELECT i.id, i.bill_id, i.order_date, b.status AS bill_status, b.total_amount,
                EXISTS(SELECT 1 FROM partner_weekly_bill_payments p WHERE p.bill_id = b.bill_id) AS has_payment,
                EXISTS(SELECT 1 FROM partner_weekly_bill_disputes d WHERE d.bill_id = b.bill_id) AS has_dispute,
                EXISTS(SELECT 1 FROM partner_weekly_bill_files f WHERE f.bill_id = b.bill_id) AS has_file
         FROM partner_weekly_bill_items i
         JOIN partner_weekly_bills b ON b.bill_id = i.bill_id
         WHERE i.partner_code = :partner_code
         ORDER BY i.id ASC'
    );
    $stmt->execute([':partner_code' => $partnerCode]);
    $items = $stmt->fetchAll();

    $timezone = new DateTimeZone('Asia/Jakarta');
    $utc = new DateTimeZone('UTC');
    $affected = [];
    $targetState = [];

    $insertBill = $pdo->prepare(
        'INSERT IGNORE INTO partner_weekly_bills
            (bill_id, partner_code, period_start, period_end, due_date, status, subtotal_amount,
             adjustment_amount, total_amount, created_at, updated_at)
         VALUES
            (:bill_id, :partner_code, :period_start, :period_end, :due_date, :status, 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $targetLookup = $pdo->prepare(
        'SELECT b.status, b.total_amount,
                EXISTS(SELECT 1 FROM partner_weekly_bill_payments p WHERE p.bill_id = b.bill_id) AS has_payment,
                EXISTS(SELECT 1 FROM partner_weekly_bill_disputes d WHERE d.bill_id = b.bill_id) AS has_dispute,
                EXISTS(SELECT 1 FROM partner_weekly_bill_files f WHERE f.bill_id = b.bill_id) AS has_file
         FROM partner_weekly_bills b WHERE b.bill_id = :bill_id LIMIT 1'
    );
    $moveItem = $pdo->prepare(
        'UPDATE partner_weekly_bill_items SET bill_id = :bill_id, updated_at = UTC_TIMESTAMP() WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $sourceStatus = (string) ($item['bill_status'] ?? '');
            $sourceIsMutable = in_array($sourceStatus, ['accruing', 'unpaid'], true)
                || ($sourceStatus === 'paid' && (int) ($item['total_amount'] ?? 0) === 0);
            if (!$sourceIsMutable
                || (int) ($item['has_payment'] ?? 0) !== 0
                || (int) ($item['has_dispute'] ?? 0) !== 0
                || (int) ($item['has_file'] ?? 0) !== 0) {
                continue;
            }

            try {
                $orderDate = new DateTimeImmutable((string) $item['order_date'], $utc);
            } catch (Throwable) {
                continue;
            }
            $period = jg_partner_billing_period($orderDate, $timezone);
            $targetBillId = jg_partner_billing_bill_id($partnerCode, $period['start']);
            $sourceBillId = (string) $item['bill_id'];
            if ($targetBillId === $sourceBillId) {
                continue;
            }

            $insertBill->execute([
                ':bill_id' => $targetBillId,
                ':partner_code' => $partnerCode,
                ':period_start' => $period['start'],
                ':period_end' => $period['end'],
                ':due_date' => $period['due'],
                ':status' => $period['end'] < jg_partner_billing_local_today() ? 'unpaid' : 'accruing',
            ]);

            if (!array_key_exists($targetBillId, $targetState)) {
                $targetLookup->execute([':bill_id' => $targetBillId]);
                $targetState[$targetBillId] = $targetLookup->fetch() ?: null;
            }
            $target = $targetState[$targetBillId];
            $targetStatus = is_array($target) ? (string) ($target['status'] ?? '') : '';
            $targetIsMutable = in_array($targetStatus, ['accruing', 'unpaid'], true)
                || ($targetStatus === 'paid' && (int) ($target['total_amount'] ?? 0) === 0);
            if (!$targetIsMutable
                || (int) ($target['has_payment'] ?? 0) !== 0
                || (int) ($target['has_dispute'] ?? 0) !== 0
                || (int) ($target['has_file'] ?? 0) !== 0) {
                continue;
            }

            $moveItem->execute([':bill_id' => $targetBillId, ':id' => (int) $item['id']]);
            $affected[$sourceBillId] = true;
            $affected[$targetBillId] = true;
        }

        $deleteEmptyLegacyBills = $pdo->prepare(
            'DELETE FROM partner_weekly_bills
             WHERE partner_code = :partner_code
               AND (WEEKDAY(period_start) <> 0 OR WEEKDAY(period_end) <> 6 OR DATEDIFF(period_end, period_start) <> 6)
               AND NOT EXISTS(SELECT 1 FROM partner_weekly_bill_items i WHERE i.bill_id = partner_weekly_bills.bill_id)
               AND NOT EXISTS(SELECT 1 FROM partner_weekly_bill_payments p WHERE p.bill_id = partner_weekly_bills.bill_id)
               AND NOT EXISTS(SELECT 1 FROM partner_weekly_bill_disputes d WHERE d.bill_id = partner_weekly_bills.bill_id)
               AND NOT EXISTS(SELECT 1 FROM partner_weekly_bill_files f WHERE f.bill_id = partner_weekly_bills.bill_id)'
        );
        $deleteEmptyLegacyBills->execute([':partner_code' => $partnerCode]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return array_keys($affected);
}

function jg_partner_billing_bill_id(string $partnerCode, string $periodStart): string
{
    return 'PB-' . str_replace('-', '', $periodStart) . '-' . strtoupper(substr(hash('sha256', strtoupper(trim($partnerCode))), 0, 12));
}

function jg_partner_billing_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_partner_billing_local_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
}

function jg_partner_billing_order_summary(array $order): array
{
    $items = json_decode((string) ($order['items_json'] ?? ''), true);
    $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    $units = 0;
    $labels = [];
    foreach ($items as $item) {
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        $units += $quantity;
        $label = trim((string) ($item['sku_label'] ?? $item['product'] ?? $item['sku_code'] ?? ''));
        if ($label !== '') {
            $labels[] = $label . ($quantity > 0 ? ' ×' . $quantity : '');
        }
    }
    if ($items === []) {
        $units = max(0, (int) ($order['quantity'] ?? 0));
        $label = trim((string) ($order['sku_label'] ?? $order['product_name'] ?? $order['sku_code'] ?? ''));
        if ($label !== '') {
            $labels[] = $label . ($units > 0 ? ' ×' . $units : '');
        }
    }
    return [
        'units' => $units,
        'description' => mb_substr(implode(', ', $labels), 0, 500),
        'items' => $items,
    ];
}

/**
 * Normalize a partner's proposed unit prices against the immutable bill snapshot.
 *
 * @return array{order_id:string,original_amount:int,proposed_amount:int,lines:list<array<string,mixed>>}
 */
function jg_partner_billing_price_proposal(array $billItem, mixed $proposal): array
{
    $proposal = is_array($proposal) ? $proposal : [];
    $requestedLines = [];
    foreach ((array) ($proposal['lines'] ?? []) as $line) {
        if (!is_array($line)) continue;
        $index = filter_var($line['line_index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || $index < 0 || $index > 999) continue;
        $requestedLines[$index] = $line;
    }
    $hasRequestedLines = $requestedLines !== [];

    $snapshot = json_decode((string) ($billItem['snapshot_json'] ?? ''), true);
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $sourceLines = array_values(array_filter((array) ($snapshot['items'] ?? []), 'is_array'));
    if ($sourceLines === []) {
        $quantity = max(1, (int) ($billItem['units'] ?? 1));
        $sourceLines = [[
            'sku_code' => '',
            'sku_label' => trim((string) ($billItem['description'] ?? '')) ?: (string) ($billItem['order_id'] ?? 'Order'),
            'product' => trim((string) ($billItem['description'] ?? '')) ?: 'Order total',
            'quantity' => $quantity,
            'unit_revenue' => (int) round(((float) ($billItem['amount'] ?? 0)) / $quantity),
        ]];
    }

    $lines = [];
    $proposedAmount = 0;
    foreach ($sourceLines as $index => $source) {
        $requested = $requestedLines[$index] ?? null;
        if (!is_array($requested) && !$hasRequestedLines) {
            $requested = ['unit_price' => $source['unit_revenue'] ?? $source['partner_price'] ?? $source['partner_unit_price'] ?? 0];
        }
        if (!is_array($requested) || !is_numeric($requested['unit_price'] ?? null)) {
            throw new InvalidArgumentException('Enter a proposed price for every product in each selected order.');
        }
        $unitPrice = (int) round((float) $requested['unit_price']);
        if ($unitPrice < 0 || $unitPrice > 1000000000000) {
            throw new InvalidArgumentException('Each proposed product price must be between Rp 0 and Rp 1,000,000,000,000.');
        }
        $quantity = max(1, (int) ($source['quantity'] ?? 1));
        $lineAmount = $unitPrice * $quantity;
        $proposedAmount += $lineAmount;
        $lines[] = [
            'line_index' => $index,
            'sku_code' => (string) ($source['sku_code'] ?? ''),
            'label' => trim((string) ($source['sku_label'] ?? $source['product'] ?? $source['sku_code'] ?? '')) ?: 'Product ' . ($index + 1),
            'quantity' => $quantity,
            'original_unit_price' => (int) round((float) ($source['unit_revenue'] ?? $source['partner_price'] ?? $source['partner_unit_price'] ?? 0)),
            'proposed_unit_price' => $unitPrice,
            'proposed_line_amount' => $lineAmount,
        ];
    }

    return [
        'order_id' => (string) ($billItem['order_id'] ?? ''),
        'original_amount' => (int) round((float) ($billItem['amount'] ?? 0)),
        'proposed_amount' => $proposedAmount,
        'lines' => $lines,
    ];
}

function jg_partner_billing_recalculate_bill(PDO $pdo, string $billId): void
{
    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(amount), 0) AS subtotal,
            COALESCE(SUM(CASE WHEN status <> "removed" THEN amount ELSE 0 END), 0) AS total
         FROM partner_weekly_bill_items
         WHERE bill_id = :bill_id'
    );
    $stmt->execute([':bill_id' => $billId]);
    $totals = $stmt->fetch() ?: ['subtotal' => 0, 'total' => 0];
    $subtotal = (int) round((float) ($totals['subtotal'] ?? 0));
    $total = (int) round((float) ($totals['total'] ?? 0));

    $billStmt = $pdo->prepare('SELECT status, period_end FROM partner_weekly_bills WHERE bill_id = :bill_id LIMIT 1');
    $billStmt->execute([':bill_id' => $billId]);
    $bill = $billStmt->fetch();
    if (!is_array($bill)) {
        return;
    }
    $status = (string) ($bill['status'] ?? 'accruing');
    if (!in_array($status, ['paid', 'payment_submitted', 'disputed'], true)) {
        $status = (string) ($bill['period_end'] ?? '') < jg_partner_billing_local_today() ? 'unpaid' : 'accruing';
    }
    if ($total <= 0 && (string) ($bill['period_end'] ?? '') < jg_partner_billing_local_today()) {
        $status = 'paid';
    }

    $update = $pdo->prepare(
        'UPDATE partner_weekly_bills
         SET subtotal_amount = :subtotal,
             adjustment_amount = :adjustment,
             total_amount = :total,
             status = :status,
             paid_at = CASE WHEN :mark_paid = 1 AND paid_at IS NULL THEN UTC_TIMESTAMP() ELSE paid_at END,
             updated_at = UTC_TIMESTAMP()
         WHERE bill_id = :bill_id'
    );
    $update->execute([
        ':subtotal' => $subtotal,
        ':adjustment' => max(0, $subtotal - $total),
        ':total' => $total,
        ':status' => $status,
        ':mark_paid' => $status === 'paid' ? 1 : 0,
        ':bill_id' => $billId,
    ]);
}

function jg_partner_billing_sync(string $partnerCode): void
{
    $partnerCode = strtoupper(trim($partnerCode));
    if ($partnerCode === '') {
        return;
    }
    $pdo = jg_partner_billing_db();
    $ordersStmt = $pdo->prepare(
        'SELECT id, partner_code, customer_name, product_name, sku_code, sku_label, quantity, status,
                marketplace_platform, revenue_total, items_json, order_timestamp, created_at, billing_paid_at
         FROM partner_orders
         WHERE partner_code = :partner_code
           AND revenue_total > 0
         ORDER BY COALESCE(order_timestamp, created_at) ASC, id ASC'
    );
    $ordersStmt->execute([':partner_code' => $partnerCode]);
    $billIds = [];
    $timezone = new DateTimeZone('Asia/Jakarta');

    $pdo->beginTransaction();
    try {
        foreach ($ordersStmt->fetchAll() as $order) {
            $status = strtoupper(trim((string) ($order['status'] ?? '')));
            $orderId = (string) ($order['id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            if (in_array($status, ['CANCELLED', 'CANCELED'], true)) {
                $cancel = $pdo->prepare(
                    'UPDATE partner_weekly_bill_items
                     SET status = "removed", removed_reason = "Order cancelled", updated_at = UTC_TIMESTAMP()
                     WHERE order_id = :order_id AND status IN ("included", "disputed")'
                );
                $cancel->execute([':order_id' => $orderId]);
                $billLookup = $pdo->prepare('SELECT bill_id FROM partner_weekly_bill_items WHERE order_id = :order_id LIMIT 1');
                $billLookup->execute([':order_id' => $orderId]);
                $cancelledBillId = (string) ($billLookup->fetchColumn() ?: '');
                if ($cancelledBillId !== '') {
                    $billIds[$cancelledBillId] = true;
                }
                continue;
            }
            if (trim((string) ($order['billing_paid_at'] ?? '')) !== '') {
                continue;
            }

            $timestamp = trim((string) ($order['order_timestamp'] ?? $order['created_at'] ?? ''));
            try {
                $orderDate = new DateTimeImmutable($timestamp !== '' ? $timestamp : 'now', new DateTimeZone('UTC'));
            } catch (Throwable) {
                $orderDate = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
            $period = jg_partner_billing_period($orderDate, $timezone);
            $billId = jg_partner_billing_bill_id($partnerCode, $period['start']);
            $billIds[$billId] = true;
            $initialStatus = $period['end'] < jg_partner_billing_local_today() ? 'unpaid' : 'accruing';

            $billInsert = $pdo->prepare(
                'INSERT IGNORE INTO partner_weekly_bills
                    (bill_id, partner_code, period_start, period_end, due_date, status, subtotal_amount,
                     adjustment_amount, total_amount, created_at, updated_at)
                 VALUES
                    (:bill_id, :partner_code, :period_start, :period_end, :due_date, :status, 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $billInsert->execute([
                ':bill_id' => $billId,
                ':partner_code' => $partnerCode,
                ':period_start' => $period['start'],
                ':period_end' => $period['end'],
                ':due_date' => $period['due'],
                ':status' => $initialStatus,
            ]);

            $summary = jg_partner_billing_order_summary($order);
            $itemInsert = $pdo->prepare(
                'INSERT IGNORE INTO partner_weekly_bill_items
                    (bill_id, partner_code, order_id, order_date, platform, customer_name, description, units,
                     amount, status, snapshot_json, created_at, updated_at)
                 VALUES
                    (:bill_id, :partner_code, :order_id, :order_date, :platform, :customer_name, :description, :units,
                     :amount, "included", :snapshot_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $itemInsert->execute([
                ':bill_id' => $billId,
                ':partner_code' => $partnerCode,
                ':order_id' => $orderId,
                ':order_date' => $orderDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ':platform' => mb_substr(trim((string) ($order['marketplace_platform'] ?? '')), 0, 64),
                ':customer_name' => mb_substr(trim((string) ($order['customer_name'] ?? '')), 0, 160),
                ':description' => $summary['description'],
                ':units' => $summary['units'],
                ':amount' => (int) round((float) ($order['revenue_total'] ?? 0)),
                ':snapshot_json' => json_encode([
                    'order_id' => $orderId,
                    'platform' => (string) ($order['marketplace_platform'] ?? ''),
                    'customer_name' => (string) ($order['customer_name'] ?? ''),
                    'items' => $summary['items'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    foreach (jg_partner_billing_align_calendar_weeks($pdo, $partnerCode) as $billId) {
        $billIds[$billId] = true;
    }

    $existingStmt = $pdo->prepare('SELECT bill_id FROM partner_weekly_bills WHERE partner_code = :partner_code');
    $existingStmt->execute([':partner_code' => $partnerCode]);
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $billId) {
        $billIds[(string) $billId] = true;
    }
    foreach (array_keys($billIds) as $billId) {
        jg_partner_billing_recalculate_bill($pdo, $billId);
    }
}

/** @return array{visible:bool,expires_at:?string} */
function jg_partner_billing_new_badge_state(string $createdAt, ?DateTimeImmutable $now = null): array
{
    $utc = new DateTimeZone('UTC');
    $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($createdAt), $utc);
    if (!$created instanceof DateTimeImmutable) {
        return ['visible' => true, 'expires_at' => null];
    }

    $expires = $created->modify('+' . JG_PARTNER_BILLING_NEW_BADGE_DAYS . ' days');
    $now ??= new DateTimeImmutable('now', $utc);
    return [
        'visible' => $now < $expires,
        'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
    ];
}

function jg_partner_billing_onboarding(PDO $pdo, string $partnerCode): array
{
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO partner_billing_onboarding (partner_code, created_at, updated_at)
         VALUES (:partner_code, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insert->execute([':partner_code' => $partnerCode]);
    $stmt = $pdo->prepare('SELECT billing_seen_at, tutorial_completed_at, created_at FROM partner_billing_onboarding WHERE partner_code = :partner_code LIMIT 1');
    $stmt->execute([':partner_code' => $partnerCode]);
    $row = $stmt->fetch() ?: [];
    $newBadge = jg_partner_billing_new_badge_state((string) ($row['created_at'] ?? ''));
    return [
        'seen' => trim((string) ($row['billing_seen_at'] ?? '')) !== '',
        'tutorial_completed' => trim((string) ($row['tutorial_completed_at'] ?? '')) !== '',
        'new_badge_visible' => $newBadge['visible'],
        'new_badge_expires_at' => $newBadge['expires_at'],
    ];
}

function jg_partner_billing_mark_onboarding(string $partnerCode, string $action): array
{
    $pdo = jg_partner_billing_db();
    jg_partner_billing_onboarding($pdo, $partnerCode);
    $column = $action === 'complete_tutorial' ? 'tutorial_completed_at' : 'billing_seen_at';
    $stmt = $pdo->prepare(sprintf(
        'UPDATE partner_billing_onboarding SET `%s` = COALESCE(`%s`, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE partner_code = :partner_code',
        $column,
        $column
    ));
    $stmt->execute([':partner_code' => $partnerCode]);
    return jg_partner_billing_onboarding($pdo, $partnerCode);
}

function jg_partner_billing_payload(string $partnerCode, string $fileEndpoint): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    jg_partner_billing_sync($partnerCode);
    $pdo = jg_partner_billing_db();
    $billStmt = $pdo->prepare(
        'SELECT bill_id, period_start, period_end, due_date, status, subtotal_amount, adjustment_amount,
                total_amount, payment_submitted_at, paid_at, created_at, updated_at
         FROM partner_weekly_bills
         WHERE partner_code = :partner_code
         ORDER BY period_start DESC'
    );
    $billStmt->execute([':partner_code' => $partnerCode]);
    $bills = [];
    foreach ($billStmt->fetchAll() as $bill) {
        $billId = (string) $bill['bill_id'];
        $itemStmt = $pdo->prepare(
            'SELECT id, order_id, order_date, platform, customer_name, description, units, amount, status,
                    dispute_id, removed_reason, paid_at, snapshot_json
             FROM partner_weekly_bill_items
             WHERE bill_id = :bill_id
             ORDER BY order_date ASC, id ASC'
        );
        $itemStmt->execute([':bill_id' => $billId]);
        $items = array_map(static function (array $item): array {
            $snapshot = json_decode((string) ($item['snapshot_json'] ?? ''), true);
            return [
                'id' => (int) $item['id'],
                'order_id' => (string) $item['order_id'],
                'order_date' => (string) $item['order_date'],
                'platform' => (string) $item['platform'],
                'customer_name' => (string) $item['customer_name'],
                'description' => (string) $item['description'],
                'units' => (int) $item['units'],
                'amount' => (int) $item['amount'],
                'status' => (string) $item['status'],
                'dispute_id' => (int) ($item['dispute_id'] ?? 0),
                'removed_reason' => (string) ($item['removed_reason'] ?? ''),
                'paid_at' => (string) ($item['paid_at'] ?? ''),
                'snapshot' => is_array($snapshot) ? $snapshot : [],
            ];
        }, $itemStmt->fetchAll());

        $disputeStmt = $pdo->prepare(
            'SELECT d.id, d.dispute_key, d.dispute_type, d.reason, d.status, d.resolution_reason, d.evidence_file_id,
                    d.created_at, d.resolved_at
             FROM partner_weekly_bill_disputes d
             WHERE d.bill_id = :bill_id
             ORDER BY d.created_at DESC'
        );
        $disputeStmt->execute([':bill_id' => $billId]);
        $disputes = [];
        foreach ($disputeStmt->fetchAll() as $dispute) {
            $selectedStmt = $pdo->prepare(
                'SELECT i.order_id, di.original_amount, di.proposed_amount, di.proposal_json,
                        di.resolved_amount, di.resolution_json
                 FROM partner_weekly_bill_dispute_items di
                 JOIN partner_weekly_bill_items i ON i.id = di.bill_item_id
                 WHERE di.dispute_id = :dispute_id ORDER BY i.order_date ASC'
            );
            $selectedStmt->execute([':dispute_id' => (int) $dispute['id']]);
            $selectedItems = array_map(static function (array $item): array {
                $proposal = json_decode((string) ($item['proposal_json'] ?? ''), true);
                $resolution = json_decode((string) ($item['resolution_json'] ?? ''), true);
                return [
                    'order_id' => (string) $item['order_id'],
                    'original_amount' => (int) ($item['original_amount'] ?? 0),
                    'proposed_amount' => (int) ($item['proposed_amount'] ?? 0),
                    'resolved_amount' => isset($item['resolved_amount']) ? (int) $item['resolved_amount'] : null,
                    'proposal' => is_array($proposal) ? $proposal : null,
                    'resolution' => is_array($resolution) ? $resolution : null,
                ];
            }, $selectedStmt->fetchAll());
            $evidenceId = (int) ($dispute['evidence_file_id'] ?? 0);
            $disputes[] = [
                'id' => (int) $dispute['id'],
                'key' => (string) $dispute['dispute_key'],
                'type' => (string) ($dispute['dispute_type'] ?? 'paid'),
                'reason' => (string) $dispute['reason'],
                'status' => (string) $dispute['status'],
                'resolution_reason' => (string) ($dispute['resolution_reason'] ?? ''),
                'created_at' => (string) $dispute['created_at'],
                'resolved_at' => (string) ($dispute['resolved_at'] ?? ''),
                'order_ids' => array_values(array_column($selectedItems, 'order_id')),
                'price_proposals' => $selectedItems,
                'evidence_url' => $evidenceId > 0 ? $fileEndpoint . '?' . http_build_query(['action' => 'file', 'id' => $evidenceId]) : '',
            ];
        }

        $paymentStmt = $pdo->prepare(
            'SELECT p.id, p.amount, p.status, p.submitted_at, p.confirmed_at, p.proof_file_id,
                    f.original_name, f.mime_type, f.size_bytes
             FROM partner_weekly_bill_payments p
             JOIN partner_weekly_bill_files f ON f.id = p.proof_file_id
             WHERE p.bill_id = :bill_id LIMIT 1'
        );
        $paymentStmt->execute([':bill_id' => $billId]);
        $payment = $paymentStmt->fetch();
        $paymentPayload = null;
        if (is_array($payment)) {
            $paymentPayload = [
                'id' => (int) $payment['id'],
                'amount' => (int) $payment['amount'],
                'status' => (string) $payment['status'],
                'submitted_at' => (string) $payment['submitted_at'],
                'confirmed_at' => (string) ($payment['confirmed_at'] ?? ''),
                'name' => (string) $payment['original_name'],
                'mime_type' => (string) $payment['mime_type'],
                'size_bytes' => (int) $payment['size_bytes'],
                'proof_url' => $fileEndpoint . '?' . http_build_query(['action' => 'file', 'id' => (int) $payment['proof_file_id']]),
            ];
        }

        $bills[] = [
            'id' => $billId,
            'period_start' => (string) $bill['period_start'],
            'period_end' => (string) $bill['period_end'],
            'due_date' => (string) $bill['due_date'],
            'status' => (string) $bill['status'],
            'subtotal_amount' => (int) $bill['subtotal_amount'],
            'adjustment_amount' => (int) $bill['adjustment_amount'],
            'total_amount' => (int) $bill['total_amount'],
            'payment_submitted_at' => (string) ($bill['payment_submitted_at'] ?? ''),
            'paid_at' => (string) ($bill['paid_at'] ?? ''),
            'items' => $items,
            'disputes' => $disputes,
            'payment' => $paymentPayload,
        ];
    }

    $outstanding = 0;
    $awaitingReview = 0;
    $paid = 0;
    foreach ($bills as $bill) {
        if (in_array($bill['status'], ['unpaid', 'payment_submitted', 'disputed'], true)) {
            $outstanding += (int) $bill['total_amount'];
        }
        if (in_array($bill['status'], ['payment_submitted', 'disputed'], true)) {
            $awaitingReview++;
        }
        if ($bill['status'] === 'paid') {
            $paid += (int) $bill['total_amount'];
        }
    }

    return [
        'ok' => true,
        'bills' => $bills,
        'summary' => [
            'outstanding_amount' => $outstanding,
            'awaiting_review' => $awaitingReview,
            'paid_amount' => $paid,
            'bill_count' => count($bills),
        ],
        'onboarding' => jg_partner_billing_onboarding($pdo, $partnerCode),
        'generated_at' => gmdate(DATE_ATOM),
    ];
}

/** @return array{mime_type:string,size_bytes:int,data:string,original_name:string} */
function jg_partner_billing_validate_file(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a PDF or image file.');
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new InvalidArgumentException('The selected file could not be read.');
    }
    $size = (int) ($file['size'] ?? filesize($tmpName) ?: 0);
    if ($size <= 0 || $size > JG_PARTNER_BILLING_MAX_FILE_BYTES) {
        throw new InvalidArgumentException('The file must be 10 MB or smaller.');
    }
    $data = (string) file_get_contents($tmpName);
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $detected = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    $header = substr($data, 0, 16);
    $mime = '';
    if (str_starts_with($header, '%PDF-')) {
        $mime = 'application/pdf';
    } elseif (str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
        $mime = 'image/png';
    } elseif (str_starts_with($header, "\xff\xd8\xff")) {
        $mime = 'image/jpeg';
    } elseif (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
        $mime = 'image/gif';
    } elseif (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        $mime = 'image/webp';
    }
    if ($mime === '' || ($mime !== 'application/pdf' && !str_starts_with($detected, 'image/'))) {
        throw new InvalidArgumentException('Only PDF, PNG, JPG, GIF, or WebP files are accepted.');
    }
    if (str_starts_with($mime, 'image/') && @getimagesize($tmpName) === false) {
        throw new InvalidArgumentException('The selected image is not valid.');
    }
    return [
        'mime_type' => $mime,
        'size_bytes' => $size,
        'data' => $data,
        'original_name' => mb_substr(trim((string) ($file['name'] ?? 'proof')), 0, 255),
    ];
}

function jg_partner_billing_store_file(PDO $pdo, string $partnerCode, string $billId, string $kind, array $file): int
{
    $validated = jg_partner_billing_validate_file($file);
    $stmt = $pdo->prepare(
        'INSERT INTO partner_weekly_bill_files
            (partner_code, bill_id, file_kind, original_name, mime_type, size_bytes, file_data, created_at)
         VALUES
            (:partner_code, :bill_id, :file_kind, :original_name, :mime_type, :size_bytes, :file_data, UTC_TIMESTAMP())'
    );
    $stmt->bindValue(':partner_code', $partnerCode);
    $stmt->bindValue(':bill_id', $billId);
    $stmt->bindValue(':file_kind', $kind);
    $stmt->bindValue(':original_name', $validated['original_name']);
    $stmt->bindValue(':mime_type', $validated['mime_type']);
    $stmt->bindValue(':size_bytes', $validated['size_bytes'], PDO::PARAM_INT);
    $stmt->bindValue(':file_data', $validated['data'], PDO::PARAM_LOB);
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function jg_partner_billing_submit_payment(string $partnerCode, string $billId, array $file): array
{
    jg_partner_billing_sync($partnerCode);
    $pdo = jg_partner_billing_db();
    $pdo->beginTransaction();
    try {
        $billStmt = $pdo->prepare('SELECT * FROM partner_weekly_bills WHERE bill_id = :bill_id AND partner_code = :partner_code FOR UPDATE');
        $billStmt->execute([':bill_id' => $billId, ':partner_code' => $partnerCode]);
        $bill = $billStmt->fetch();
        if (!is_array($bill)) {
            throw new InvalidArgumentException('Bill not found.');
        }
        if ((string) $bill['status'] === 'accruing') {
            throw new InvalidArgumentException('This billing period is still open.');
        }
        if ((string) $bill['status'] === 'paid') {
            throw new InvalidArgumentException('This bill is already paid.');
        }
        if ((string) $bill['status'] === 'disputed') {
            throw new InvalidArgumentException('Wait for the dispute review before paying this bill.');
        }
        if ((int) $bill['total_amount'] <= 0) {
            throw new InvalidArgumentException('This bill has no balance due.');
        }
        $fileId = jg_partner_billing_store_file($pdo, $partnerCode, $billId, 'payment_proof', $file);
        $paymentKey = 'PAY-' . str_replace('PB-', '', $billId);
        $payment = $pdo->prepare(
            'INSERT INTO partner_weekly_bill_payments
                (payment_key, bill_id, partner_code, amount, proof_file_id, status, submitted_at, updated_at)
             VALUES
                (:payment_key, :bill_id, :partner_code, :amount, :proof_file_id, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                amount = VALUES(amount), proof_file_id = VALUES(proof_file_id), status = "pending",
                submitted_at = UTC_TIMESTAMP(), confirmed_at = NULL, accounting_transaction_id = NULL, updated_at = UTC_TIMESTAMP()'
        );
        $payment->execute([
            ':payment_key' => $paymentKey,
            ':bill_id' => $billId,
            ':partner_code' => $partnerCode,
            ':amount' => (int) $bill['total_amount'],
            ':proof_file_id' => $fileId,
        ]);
        $update = $pdo->prepare(
            'UPDATE partner_weekly_bills SET status = "payment_submitted", payment_submitted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE bill_id = :bill_id'
        );
        $update->execute([':bill_id' => $billId]);
        $pdo->commit();
        return ['ok' => true, 'bill_id' => $billId, 'status' => 'payment_submitted'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function jg_partner_billing_submit_dispute(string $partnerCode, string $billId, array $orderIds, string $reason, mixed $priceProposals = []): array
{
    jg_partner_billing_sync($partnerCode);
    $reason = trim($reason);
    if (mb_strlen($reason) < 8) {
        throw new InvalidArgumentException('Describe why these orders were already paid.');
    }
    $orderIds = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $orderIds))));
    if ($orderIds === []) {
        throw new InvalidArgumentException('Select at least one order to dispute.');
    }
    if (count($orderIds) > 100) {
        throw new InvalidArgumentException('Too many orders were selected.');
    }

    $pdo = jg_partner_billing_db();
    $pdo->beginTransaction();
    try {
        $billStmt = $pdo->prepare('SELECT * FROM partner_weekly_bills WHERE bill_id = :bill_id AND partner_code = :partner_code FOR UPDATE');
        $billStmt->execute([':bill_id' => $billId, ':partner_code' => $partnerCode]);
        $bill = $billStmt->fetch();
        if (!is_array($bill)) {
            throw new InvalidArgumentException('Bill not found.');
        }
        if (!in_array((string) $bill['status'], ['unpaid'], true)) {
            throw new InvalidArgumentException('This bill cannot be disputed in its current state.');
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $pdo->prepare(
            'SELECT id, order_id, description, units, amount, snapshot_json FROM partner_weekly_bill_items
             WHERE bill_id = ? AND partner_code = ? AND status = "included" AND order_id IN (' . $placeholders . ') FOR UPDATE'
        );
        $itemStmt->execute([$billId, $partnerCode, ...$orderIds]);
        $items = $itemStmt->fetchAll();
        if (count($items) !== count($orderIds)) {
            throw new InvalidArgumentException('One or more selected orders are no longer available for dispute.');
        }
        $proposalByOrder = [];
        foreach ((array) $priceProposals as $proposal) {
            if (!is_array($proposal)) continue;
            $proposalOrderId = trim((string) ($proposal['order_id'] ?? ''));
            if ($proposalOrderId !== '') $proposalByOrder[$proposalOrderId] = $proposal;
        }
        $normalizedProposals = [];
        $hasPriceChange = false;
        foreach ($items as $item) {
            $orderId = (string) $item['order_id'];
            $normalized = jg_partner_billing_price_proposal($item, $proposalByOrder[$orderId] ?? null);
            $normalizedProposals[(int) $item['id']] = $normalized;
            if ($proposalByOrder !== [] && $normalized['proposed_amount'] !== $normalized['original_amount']) $hasPriceChange = true;
        }

        $disputeType = $hasPriceChange ? 'price' : 'paid';
        $disputeKey = 'DSP-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $insert = $pdo->prepare(
            'INSERT INTO partner_weekly_bill_disputes
                (dispute_key, bill_id, partner_code, dispute_type, reason, status, created_at, updated_at)
             VALUES
                (:dispute_key, :bill_id, :partner_code, :dispute_type, :reason, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insert->execute([
            ':dispute_key' => $disputeKey,
            ':bill_id' => $billId,
            ':partner_code' => $partnerCode,
            ':dispute_type' => $disputeType,
            ':reason' => mb_substr($reason, 0, 4000),
        ]);
        $disputeId = (int) $pdo->lastInsertId();
        $link = $pdo->prepare(
            'INSERT INTO partner_weekly_bill_dispute_items
                (dispute_id, bill_item_id, original_amount, proposed_amount, proposal_json, created_at)
             VALUES (:dispute_id, :bill_item_id, :original_amount, :proposed_amount, :proposal_json, UTC_TIMESTAMP())'
        );
        $mark = $pdo->prepare(
            'UPDATE partner_weekly_bill_items SET status = "disputed", dispute_id = :dispute_id, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        foreach ($items as $item) {
            $proposal = $normalizedProposals[(int) $item['id']];
            $link->execute([
                ':dispute_id' => $disputeId,
                ':bill_item_id' => (int) $item['id'],
                ':original_amount' => $proposal['original_amount'],
                ':proposed_amount' => $proposal['proposed_amount'],
                ':proposal_json' => json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $mark->execute([':dispute_id' => $disputeId, ':id' => (int) $item['id']]);
        }
        $billUpdate = $pdo->prepare('UPDATE partner_weekly_bills SET status = "disputed", updated_at = UTC_TIMESTAMP() WHERE bill_id = :bill_id');
        $billUpdate->execute([':bill_id' => $billId]);
        $pdo->commit();
        return ['ok' => true, 'bill_id' => $billId, 'dispute_id' => $disputeId, 'type' => $disputeType, 'status' => 'pending'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function jg_partner_billing_stream_file(string $partnerCode, int $fileId): never
{
    $pdo = jg_partner_billing_db();
    $stmt = $pdo->prepare(
        'SELECT original_name, mime_type, size_bytes, file_data
         FROM partner_weekly_bill_files WHERE id = :id AND partner_code = :partner_code LIMIT 1'
    );
    $stmt->execute([':id' => $fileId, ':partner_code' => $partnerCode]);
    $file = $stmt->fetch();
    if (!is_array($file)) {
        throw new RuntimeException('File not found.');
    }
    $data = (string) ($file['file_data'] ?? '');
    header('Content-Type: ' . (string) ($file['mime_type'] ?? 'application/octet-stream'));
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: inline; filename="' . addcslashes((string) ($file['original_name'] ?? 'proof'), "\"\\") . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}
