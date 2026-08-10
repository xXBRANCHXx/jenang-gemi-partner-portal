<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-return-storage.php';

function partner_return_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$order = [
    'id' => 'ORDER-100',
    'revenue_total' => 55000,
    'items_json' => json_encode(['items' => []]),
];
$order['items_json'] = json_encode([
    ['sku_code' => 'SKU-A', 'sku_label' => 'Product A', 'quantity' => 2, 'unit_revenue' => 10000],
    ['sku_code' => 'SKU-B', 'sku_label' => 'Product B', 'quantity' => 1, 'unit_revenue' => 35000],
]);
$selection = [
    ['sku' => 'SKU-A', 'returned_qty' => 2],
    ['sku' => 'SKU-B', 'returned_qty' => 1],
];

$ourRestock = jg_partner_return_calculate($order, $selection, 'us', 'restock');
partner_return_expect(55000, $ourRestock['selected_value'], 'Selected value must use the immutable Partner purchase price.');
partner_return_expect(-55000, $ourRestock['adjustment_amount'], 'Our fault must refund 100% regardless of condition.');

$ourDamaged = jg_partner_return_calculate($order, $selection, 'us', 'damaged');
partner_return_expect(-55000, $ourDamaged['adjustment_amount'], 'Our damaged goods fault must still refund the full purchase price.');

$restockFee = jg_partner_return_calculate($order, $selection, 'partner', 'restock');
partner_return_expect(1500, $restockFee['rate_basis_points'], 'Partner restocking must use a 15% fee.');
partner_return_expect(8250, $restockFee['adjustment_amount'], 'The 15% fee must use selected Partner purchase value.');

$damagedFee = jg_partner_return_calculate($order, $selection, 'partner', 'damaged');
partner_return_expect(22000, $damagedFee['adjustment_amount'], 'Partner damaged goods must use a 40% fee.');

$lossFee = jg_partner_return_calculate($order, $selection, 'partner', 'unrecoverable');
partner_return_expect(55000, $lossFee['adjustment_amount'], 'Partner unrecoverable loss must use a 100% fee.');

$overReturnRejected = false;
try {
    jg_partner_return_calculate($order, [['sku' => 'SKU-A', 'returned_qty' => 1]], 'partner', 'restock', ['SKU-A' => 2]);
} catch (InvalidArgumentException) {
    $overReturnRejected = true;
}
partner_return_expect(true, $overReturnRejected, 'Cumulative Partner returns must never exceed the original quantity.');

$existing = [
    'partner_code' => 'PARTNER-A', 'original_order_id' => 'ORDER-100',
    'fault_party' => 'partner', 'condition_code' => 'damaged',
    'items' => [['sku' => 'SKU-A', 'quantity' => 2]],
];
jg_partner_return_assert_idempotent($existing, ['items' => [['sku' => 'SKU-A', 'returned_qty' => 2]]], 'PARTNER-A', 'ORDER-100', 'partner', 'damaged');
$changedRetryRejected = false;
try {
    jg_partner_return_assert_idempotent($existing, ['items' => [['sku' => 'SKU-A', 'returned_qty' => 1]]], 'PARTNER-A', 'ORDER-100', 'partner', 'damaged');
} catch (RuntimeException) {
    $changedRetryRejected = true;
}
partner_return_expect(true, $changedRetryRejected, 'An idempotent retry must reject altered quantities after billing.');

echo "partner-return-test: ok\n";
