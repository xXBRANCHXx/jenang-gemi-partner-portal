<?php
declare(strict_types=1);

if (getenv('JG_PARTNER_BILLING_SMOKE_ALLOW_RESET') !== '1') {
    fwrite(STDERR, "Set JG_PARTNER_BILLING_SMOKE_ALLOW_RESET=1 to run the destructive disposable-database smoke test.\n");
    exit(2);
}

$databaseName = (string) getenv('JG_PARTNER_DB_NAME');
if (!preg_match('/(?:_smoke|_test)$/', $databaseName)) {
    fwrite(STDERR, "The smoke database name must end in _smoke or _test.\n");
    exit(2);
}

require dirname(__DIR__) . '/partner-billing-storage.php';

function billing_smoke_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function billing_smoke_order(PDO $pdo, string $id, string $status, int $amount, string $timestamp): void
{
    $items = [[
        'sku_code' => 'SMOKE-SKU',
        'sku_label' => 'Smoke product',
        'product' => 'Smoke product',
        'quantity' => 1,
        'unit_revenue' => $amount,
    ]];
    $stmt = $pdo->prepare(
        'INSERT INTO partner_orders
            (id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity,
             status, marketplace_platform, revenue_total, items_json, order_timestamp, created_at, updated_at)
         VALUES
            (:id, "SMOKE", "Smoke customer", "Smoke brand", "Smoke product", "SMOKE-SKU", "Smoke product", 1,
             :status, "Shopee", :amount, :items_json, :order_timestamp, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':id' => $id,
        ':status' => $status,
        ':amount' => $amount,
        ':items_json' => json_encode($items, JSON_UNESCAPED_SLASHES),
        ':order_timestamp' => $timestamp,
        ':created_at' => $timestamp,
        ':updated_at' => $timestamp,
    ]);
}

$pdo = jg_partner_billing_db();
$tables = [
    'partner_return_adjustments',
    'partner_weekly_bill_dispute_items',
    'partner_weekly_bill_disputes',
    'partner_weekly_bill_payments',
    'partner_weekly_bill_files',
    'partner_weekly_bill_items',
    'partner_weekly_bills',
    'partner_billing_onboarding',
    'partner_order_labels',
    'partner_orders',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $pdo->exec('TRUNCATE TABLE `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$period = jg_partner_billing_period(new DateTimeImmutable('2026-08-12 00:00:00', new DateTimeZone('UTC')));
$legacyBillId = 'PB-20260810-LEGACY-SMOKE';
$billInsert = $pdo->prepare(
    'INSERT INTO partner_weekly_bills
        (bill_id, partner_code, period_type, period_start, period_end, due_date, status,
         subtotal_amount, adjustment_amount, total_amount, created_at, updated_at)
     VALUES
        (:bill_id, "SMOKE", "calendar_week", :period_start, :period_end, :due_date, "accruing",
         0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
);
$billInsert->execute([
    ':bill_id' => $legacyBillId,
    ':period_start' => $period['start'],
    ':period_end' => $period['end'],
    ':due_date' => $period['due'],
]);

billing_smoke_order($pdo, 'ORDER-A', 'IS_LISTED', 57500, '2026-08-10 03:00:00');
billing_smoke_order($pdo, 'ORDER-B', 'FULFILLED', 57500, '2026-08-11 04:00:00');
billing_smoke_order($pdo, 'ORDER-ZERO', 'IS_LISTED', 0, '2026-08-12 05:00:00');
billing_smoke_order($pdo, 'ORDER-CANCELLED', 'CANCELLED', 99000, '2026-08-12 06:00:00');

$orphanId = jg_partner_billing_bill_id('SMOKE', $period['start'], 'calendar_week');
$orphanInsert = $pdo->prepare(
    'INSERT INTO partner_weekly_bill_items
        (bill_id, partner_code, order_id, order_date, platform, customer_name, description, units,
         amount, status, snapshot_json, created_at, updated_at)
     VALUES
        (:bill_id, "SMOKE", "ORDER-A", "2026-08-10 03:00:00", "Shopee", "Smoke customer", "Smoke product", 1,
         57500, "included", "{}", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
);
$orphanInsert->execute([':bill_id' => $orphanId]);

jg_partner_billing_sync('SMOKE');
jg_partner_billing_sync('SMOKE');

$activeItems = $pdo->query(
    'SELECT COUNT(*) FROM partner_weekly_bill_items
     WHERE partner_code = "SMOKE" AND order_id IN ("ORDER-A", "ORDER-B", "ORDER-ZERO") AND status = "included"'
)->fetchColumn();
billing_smoke_expect(3, (int) $activeItems, 'Every active order, including Rp 0, must be represented exactly once.');
billing_smoke_expect(0, (int) $pdo->query('SELECT COUNT(*) FROM partner_weekly_bill_items WHERE order_id = "ORDER-CANCELLED"')->fetchColumn(), 'A never-billed cancelled order must remain excluded.');
billing_smoke_expect(0, (int) $pdo->query('SELECT COUNT(*) FROM partner_weekly_bill_items i LEFT JOIN partner_weekly_bills b ON b.bill_id = i.bill_id WHERE i.partner_code = "SMOKE" AND b.bill_id IS NULL')->fetchColumn(), 'The legacy-ID orphan must be repaired.');
billing_smoke_expect(3, (int) $pdo->query('SELECT COUNT(*) FROM partner_weekly_bill_items WHERE bill_id = "' . $legacyBillId . '"')->fetchColumn(), 'All current-period items must use the database canonical legacy bill ID.');

$currentBill = $pdo->query('SELECT subtotal_amount, adjustment_amount, total_amount FROM partner_weekly_bills WHERE bill_id = "' . $legacyBillId . '"')->fetch();
billing_smoke_expect(115000, (int) $currentBill['subtotal_amount'], 'The current bill subtotal must reconcile.');
billing_smoke_expect(0, (int) $currentBill['adjustment_amount'], 'The current bill must not invent an adjustment.');
billing_smoke_expect(115000, (int) $currentBill['total_amount'], 'The current bill total must reconcile.');
billing_smoke_expect([], jg_partner_billing_integrity_issues($pdo, 'SMOKE'), 'The repeated sync must finish with no integrity issues.');

$pdo->exec('UPDATE partner_orders SET status = "CANCELLED" WHERE id = "ORDER-B"');
jg_partner_billing_sync('SMOKE');
billing_smoke_expect('removed', (string) $pdo->query('SELECT status FROM partner_weekly_bill_items WHERE order_id = "ORDER-B"')->fetchColumn(), 'Cancelling a billed order must remove it from the payable total.');
billing_smoke_expect(57500, (int) $pdo->query('SELECT total_amount FROM partner_weekly_bills WHERE bill_id = "' . $legacyBillId . '"')->fetchColumn(), 'Cancellation must recalculate the bill total.');

billing_smoke_order($pdo, 'ORDER-OLD', 'FULFILLED', 120000, '2026-08-03 03:00:00');
jg_partner_billing_sync('SMOKE');
$oldPeriod = jg_partner_billing_period(new DateTimeImmutable('2026-08-03 03:00:00', new DateTimeZone('UTC')));
$oldBillId = jg_partner_billing_bill_id('SMOKE', $oldPeriod['start'], 'calendar_week');
$oldBill = $pdo->query('SELECT status, total_amount FROM partner_weekly_bills WHERE bill_id = "' . $oldBillId . '"')->fetch();
billing_smoke_expect('unpaid', (string) $oldBill['status'], 'A backdated order must reopen its closed period as unpaid.');
billing_smoke_expect(120000, (int) $oldBill['total_amount'], 'The backdated bill total must reconcile.');

$payment = $pdo->prepare(
    'INSERT INTO partner_weekly_bill_payments
        (payment_key, bill_id, partner_code, amount, proof_file_id, status, submitted_at, updated_at)
     VALUES ("PAY-SMOKE", :bill_id, "SMOKE", 120000, 1, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
);
$payment->execute([':bill_id' => $oldBillId]);
$pdo->prepare('UPDATE partner_weekly_bills SET status = "payment_submitted" WHERE bill_id = :bill_id')->execute([':bill_id' => $oldBillId]);
billing_smoke_order($pdo, 'ORDER-LATE', 'FULFILLED', 30000, '2026-08-04 03:00:00');
$lateOrderRejected = false;
try {
    jg_partner_billing_sync('SMOKE');
} catch (RuntimeException $error) {
    $lateOrderRejected = $error->getMessage() === 'A late order reached a billing period with an active payment or dispute.';
}
billing_smoke_expect(true, $lateOrderRejected, 'A late order must not mutate a bill while its payment is under review.');
billing_smoke_expect(0, (int) $pdo->query('SELECT COUNT(*) FROM partner_weekly_bill_items WHERE order_id = "ORDER-LATE"')->fetchColumn(), 'Rejected late-order insertion must roll back.');
billing_smoke_expect(120000, (int) $pdo->query('SELECT total_amount FROM partner_weekly_bills WHERE bill_id = "' . $oldBillId . '"')->fetchColumn(), 'Rejected late-order insertion must preserve the submitted bill total.');

$pdo->exec('DELETE FROM partner_orders WHERE id = "ORDER-LATE"');
$pdo->beginTransaction();
$pdo->prepare('UPDATE partner_weekly_bills SET total_amount = total_amount + 1 WHERE bill_id = :bill_id')->execute([':bill_id' => $legacyBillId]);
$tamperIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($tamperIssues['bill_total_mismatches'] ?? 0), 'The integrity audit must detect a tampered stored bill total.');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->exec('DELETE FROM partner_weekly_bill_items WHERE order_id = "ORDER-ZERO"');
$missingIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($missingIssues['missing_items'] ?? 0), 'The integrity audit must detect an active order missing its bill item.');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->exec('UPDATE partner_weekly_bill_items SET amount = amount + 1 WHERE order_id = "ORDER-A"');
$amountIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($amountIssues['amount_mismatches'] ?? 0), 'The integrity audit must detect an order amount mismatch.');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->exec('UPDATE partner_weekly_bill_items SET order_date = "2026-09-01 00:00:00" WHERE order_id = "ORDER-A"');
$periodIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($periodIssues['period_mismatches'] ?? 0), 'The integrity audit must detect a mutable item outside its bill period.');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->prepare('UPDATE partner_weekly_bill_payments SET amount = amount - 1 WHERE bill_id = :bill_id')->execute([':bill_id' => $oldBillId]);
$paymentIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($paymentIssues['payment_total_mismatches'] ?? 0), 'The integrity audit must detect payment evidence for the wrong bill total.');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->exec(
    'INSERT INTO partner_weekly_bill_files
        (partner_code, bill_id, file_kind, original_name, mime_type, size_bytes, file_data, created_at)
     VALUES ("SMOKE", "MISSING-BILL", "evidence", "smoke.txt", "text/plain", 1, "x", UTC_TIMESTAMP())'
);
$auditIssues = jg_partner_billing_integrity_issues($pdo, 'SMOKE');
billing_smoke_expect(1, (int) ($auditIssues['orphaned_audit_records'] ?? 0), 'The integrity audit must detect evidence detached from a bill.');
$pdo->rollBack();

echo "partner-billing-mariadb-smoke: ok\n";
