<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-billing-storage.php';

/** @return array{rate_basis_points:int,kind:string,label:string} */
function jg_partner_return_policy(string $faultParty, string $conditionCode): array
{
    $faultParty = strtolower(trim($faultParty));
    $conditionCode = strtolower(trim($conditionCode));
    if (!in_array($faultParty, ['us', 'partner'], true)) {
        throw new InvalidArgumentException('Choose who was at fault for this Partner return.');
    }
    if (!in_array($conditionCode, ['restock', 'damaged', 'unrecoverable'], true)) {
        throw new InvalidArgumentException('Choose the condition of the returned products.');
    }
    if ($faultParty === 'us') {
        return ['rate_basis_points' => 10000, 'kind' => 'refund', 'label' => 'Partner return refund'];
    }
    return match ($conditionCode) {
        'restock' => ['rate_basis_points' => 1500, 'kind' => 'fee', 'label' => 'Partner restock fee'],
        'damaged' => ['rate_basis_points' => 4000, 'kind' => 'fee', 'label' => 'Partner damaged goods fee'],
        'unrecoverable' => ['rate_basis_points' => 10000, 'kind' => 'fee', 'label' => 'Partner product loss fee'],
    };
}

/** @return list<array<string,mixed>> */
function jg_partner_return_order_items(array $order): array
{
    $items = json_decode((string) ($order['items_json'] ?? ''), true);
    $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    if ($items === []) {
        $quantity = max(1, (int) ($order['quantity'] ?? 1));
        $items = [[
            'sku_code' => (string) ($order['sku_code'] ?? ''),
            'sku_label' => (string) ($order['sku_label'] ?? $order['product_name'] ?? ''),
            'product' => (string) ($order['product_name'] ?? ''),
            'quantity' => $quantity,
            'unit_revenue' => $quantity > 0 ? ((float) ($order['revenue_total'] ?? 0) / $quantity) : 0,
        ]];
    }

    $normalized = [];
    $totalUnits = array_sum(array_map(static fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)), $items));
    $fallbackUnitPrice = $totalUnits > 0 ? ((float) ($order['revenue_total'] ?? 0) / $totalUnits) : 0;
    foreach ($items as $item) {
        $sku = strtoupper(trim((string) ($item['sku_code'] ?? $item['sku'] ?? '')));
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        if ($sku === '' || $quantity < 1) continue;
        $unitPrice = max(0, (int) round((float) ($item['unit_revenue'] ?? $item['partner_price'] ?? $item['partner_unit_price'] ?? $fallbackUnitPrice)));
        if (!isset($normalized[$sku])) {
            $normalized[$sku] = [
                'sku' => $sku,
                'product_name' => trim((string) ($item['sku_label'] ?? $item['product'] ?? $sku)) ?: $sku,
                'ordered_qty' => 0,
                'unit_price' => $unitPrice,
            ];
        }
        if ($normalized[$sku]['unit_price'] !== $unitPrice) {
            throw new RuntimeException(sprintf('%s has inconsistent Partner pricing in the original order.', $sku));
        }
        $normalized[$sku]['ordered_qty'] += $quantity;
    }
    return array_values($normalized);
}

/** @return array{selected_value:int,adjustment_amount:int,rate_basis_points:int,kind:string,label:string,items:list<array<string,mixed>>} */
function jg_partner_return_calculate(array $order, array $inputItems, string $faultParty, string $conditionCode, array $alreadyReturned = []): array
{
    $policy = jg_partner_return_policy($faultParty, $conditionCode);
    $requested = [];
    foreach ($inputItems as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? $item['sku_code'] ?? '')));
        $quantity = max(0, (int) ($item['returned_qty'] ?? $item['quantity'] ?? 0));
        if ($sku !== '' && $quantity > 0) $requested[$sku] = ($requested[$sku] ?? 0) + $quantity;
    }
    if ($requested === []) throw new InvalidArgumentException('Select at least one returned Partner product.');

    $catalog = [];
    foreach (jg_partner_return_order_items($order) as $item) $catalog[(string) $item['sku']] = $item;
    $selected = [];
    $selectedValue = 0;
    foreach ($requested as $sku => $quantity) {
        $source = $catalog[$sku] ?? null;
        if (!is_array($source)) throw new InvalidArgumentException(sprintf('%s was not part of the Partner order.', $sku));
        $remaining = max(0, (int) $source['ordered_qty'] - (int) ($alreadyReturned[$sku] ?? 0));
        if ($quantity > $remaining) throw new InvalidArgumentException(sprintf('%s only has %d returnable unit%s left.', $sku, $remaining, $remaining === 1 ? '' : 's'));
        $lineValue = (int) $source['unit_price'] * $quantity;
        $selectedValue += $lineValue;
        $selected[] = [
            'sku' => $sku,
            'product_name' => (string) $source['product_name'],
            'quantity' => $quantity,
            'unit_price' => (int) $source['unit_price'],
            'line_value' => $lineValue,
        ];
    }
    $amount = (int) round($selectedValue * ($policy['rate_basis_points'] / 10000));
    if ($policy['kind'] === 'refund') $amount *= -1;
    return $policy + [
        'selected_value' => $selectedValue,
        'adjustment_amount' => $amount,
        'items' => $selected,
    ];
}

function jg_partner_return_existing(PDO $pdo, string $adjustmentKey): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, adjustment_key, return_number, partner_code, original_order_id, bill_id,
                bill_item_id, fault_party, condition_code, rate_basis_points, selected_value,
                adjustment_amount, item_snapshot_json, created_at
         FROM partner_return_adjustments WHERE adjustment_key = :adjustment_key LIMIT 1'
    );
    $stmt->execute([':adjustment_key' => $adjustmentKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) return null;
    $row['id'] = (int) $row['id'];
    $row['bill_item_id'] = (int) $row['bill_item_id'];
    $row['rate_basis_points'] = (int) $row['rate_basis_points'];
    $row['selected_value'] = (int) $row['selected_value'];
    $row['adjustment_amount'] = (int) $row['adjustment_amount'];
    $row['items'] = json_decode((string) $row['item_snapshot_json'], true) ?: [];
    unset($row['item_snapshot_json']);
    return $row;
}

function jg_partner_return_assert_idempotent(array $existing, array $payload, string $partnerCode, string $orderId, string $faultParty, string $conditionCode): void
{
    $requested = [];
    foreach ((array) ($payload['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? $item['sku_code'] ?? '')));
        $qty = max(0, (int) ($item['returned_qty'] ?? $item['quantity'] ?? 0));
        if ($sku !== '' && $qty > 0) $requested[$sku] = ($requested[$sku] ?? 0) + $qty;
    }
    $saved = [];
    foreach ((array) ($existing['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        if ($sku !== '') $saved[$sku] = ($saved[$sku] ?? 0) + max(0, (int) ($item['quantity'] ?? 0));
    }
    ksort($requested);
    ksort($saved);
    if ((string) ($existing['partner_code'] ?? '') !== $partnerCode
        || (string) ($existing['original_order_id'] ?? '') !== $orderId
        || (string) ($existing['fault_party'] ?? '') !== $faultParty
        || (string) ($existing['condition_code'] ?? '') !== $conditionCode
        || $requested !== $saved) {
        throw new RuntimeException('This return was already applied to the Partner bill with different details. Restore the original draft selections before retrying.');
    }
}

/** @return array<string,int> */
function jg_partner_return_already_returned(PDO $pdo, string $partnerCode, string $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT item_snapshot_json FROM partner_return_adjustments
         WHERE partner_code = :partner_code AND original_order_id = :order_id
         ORDER BY id FOR UPDATE'
    );
    $stmt->execute([':partner_code' => $partnerCode, ':order_id' => $orderId]);
    $totals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $items = json_decode((string) $json, true);
        foreach ((array) $items as $item) {
            if (!is_array($item)) continue;
            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku !== '') $totals[$sku] = ($totals[$sku] ?? 0) + max(0, (int) ($item['quantity'] ?? 0));
        }
    }
    return $totals;
}

function jg_partner_return_open_bill(PDO $pdo, string $partnerCode): array
{
    $stmt = $pdo->prepare(
        'SELECT b.* FROM partner_weekly_bills b
         WHERE b.partner_code = :partner_code
           AND b.status IN ("accruing", "unpaid")
           AND NOT EXISTS(
               SELECT 1 FROM partner_weekly_bill_payments p
               WHERE p.bill_id = b.bill_id AND p.status IN ("pending", "confirmed")
           )
           AND NOT EXISTS(
               SELECT 1 FROM partner_weekly_bill_disputes d
               WHERE d.bill_id = b.bill_id AND d.status = "pending"
           )
         ORDER BY CASE b.status WHEN "accruing" THEN 0 ELSE 1 END, b.period_start DESC, b.created_at DESC
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':partner_code' => $partnerCode]);
    $bill = $stmt->fetch();
    if (!is_array($bill)) throw new RuntimeException('This partner does not have a mutable open bill for the return adjustment.');
    return $bill;
}

function jg_partner_return_apply(array $payload): array
{
    $adjustmentKey = mb_substr(trim((string) ($payload['adjustment_key'] ?? '')), 0, 120);
    $returnNumber = mb_substr(trim((string) ($payload['return_number'] ?? '')), 0, 64);
    $partnerCode = strtoupper(mb_substr(trim((string) ($payload['partner_code'] ?? '')), 0, 64));
    $orderId = mb_substr(trim((string) ($payload['order_id'] ?? '')), 0, 64);
    $faultParty = strtolower(trim((string) ($payload['fault_party'] ?? '')));
    $conditionCode = strtolower(trim((string) ($payload['condition_code'] ?? '')));
    if ($adjustmentKey === '' || $returnNumber === '' || $partnerCode === '' || $orderId === '') {
        throw new InvalidArgumentException('Return number, partner, and original order are required.');
    }

    $pdo = jg_partner_billing_db();
    jg_partner_billing_sync($partnerCode);
    $existing = jg_partner_return_existing($pdo, $adjustmentKey);
    if (is_array($existing)) {
        jg_partner_return_assert_idempotent($existing, $payload, $partnerCode, $orderId, $faultParty, $conditionCode);
        return $existing;
    }

    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare(
            'SELECT id, partner_code, product_name, sku_code, sku_label, quantity,
                    revenue_total, items_json, created_at
             FROM partner_orders WHERE id = :order_id AND partner_code = :partner_code LIMIT 1 FOR UPDATE'
        );
        $orderStmt->execute([':order_id' => $orderId, ':partner_code' => $partnerCode]);
        $order = $orderStmt->fetch();
        if (!is_array($order)) throw new InvalidArgumentException('The selected order does not belong to this partner.');
        $alreadyReturned = jg_partner_return_already_returned($pdo, $partnerCode, $orderId);
        $calculation = jg_partner_return_calculate(
            $order,
            (array) ($payload['items'] ?? []),
            $faultParty,
            $conditionCode,
            $alreadyReturned
        );
        $bill = jg_partner_return_open_bill($pdo, $partnerCode);
        $billId = (string) $bill['bill_id'];
        $syntheticOrderId = 'RET-' . strtoupper(substr(hash('sha256', $adjustmentKey), 0, 28));
        $units = array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $calculation['items']));
        $description = sprintf(
            '%s · %s · %s · %s',
            $calculation['label'],
            $returnNumber,
            $orderId,
            $conditionCode
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO partner_weekly_bill_items
                (bill_id, partner_code, order_id, order_date, platform, customer_name,
                 description, units, amount, status, snapshot_json, created_at, updated_at)
             VALUES
                (:bill_id, :partner_code, :order_id, UTC_TIMESTAMP(), "Partner Return", "",
                 :description, :units, :amount, "included", :snapshot_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $snapshot = json_encode([
            'return_number' => $returnNumber,
            'original_order_id' => $orderId,
            'fault_party' => $faultParty,
            'condition_code' => $conditionCode,
            'rate_basis_points' => $calculation['rate_basis_points'],
            'selected_value' => $calculation['selected_value'],
            'items' => $calculation['items'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $insertItem->execute([
            ':bill_id' => $billId,
            ':partner_code' => $partnerCode,
            ':order_id' => $syntheticOrderId,
            ':description' => mb_substr($description, 0, 500),
            ':units' => $units,
            ':amount' => $calculation['adjustment_amount'],
            ':snapshot_json' => $snapshot,
        ]);
        $billItemId = (int) $pdo->lastInsertId();
        $insertAdjustment = $pdo->prepare(
            'INSERT INTO partner_return_adjustments
                (adjustment_key, return_number, partner_code, original_order_id, bill_id,
                 bill_item_id, fault_party, condition_code, rate_basis_points, selected_value,
                 adjustment_amount, item_snapshot_json, created_at)
             VALUES
                (:adjustment_key, :return_number, :partner_code, :original_order_id, :bill_id,
                 :bill_item_id, :fault_party, :condition_code, :rate_basis_points, :selected_value,
                 :adjustment_amount, :item_snapshot_json, UTC_TIMESTAMP())'
        );
        $insertAdjustment->execute([
            ':adjustment_key' => $adjustmentKey,
            ':return_number' => $returnNumber,
            ':partner_code' => $partnerCode,
            ':original_order_id' => $orderId,
            ':bill_id' => $billId,
            ':bill_item_id' => $billItemId,
            ':fault_party' => $faultParty,
            ':condition_code' => $conditionCode,
            ':rate_basis_points' => $calculation['rate_basis_points'],
            ':selected_value' => $calculation['selected_value'],
            ':adjustment_amount' => $calculation['adjustment_amount'],
            ':item_snapshot_json' => json_encode($calculation['items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        jg_partner_billing_recalculate_bill($pdo, $billId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException) {
            $raced = jg_partner_return_existing($pdo, $adjustmentKey);
            if (is_array($raced)) {
                jg_partner_return_assert_idempotent($raced, $payload, $partnerCode, $orderId, $faultParty, $conditionCode);
                return $raced;
            }
        }
        throw $error;
    }
    $saved = jg_partner_return_existing($pdo, $adjustmentKey);
    if (!is_array($saved)) throw new RuntimeException('The Partner return adjustment was saved but could not be reloaded.');
    return $saved;
}
