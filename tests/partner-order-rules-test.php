<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-order-storage.php';

function partner_order_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$partner = [
    'selected_sku_records' => [
        [
            'sku' => 'JG-001',
            'label' => 'Jenang Original',
            'brand_name' => 'Jenang Gemi',
            'product_name' => 'Jenang Original',
            'flavor_name' => 'Original',
            'size_label' => 'Box',
            'volume' => 100,
            'astra_value' => 50,
            'partner_unit_price' => 10000,
            'partner_price' => 20000,
        ],
    ],
];

$basePayload = [
    'items' => [
        ['sku_code' => 'JG-001', 'quantity' => 2],
    ],
    'customer_name' => 'Ayu',
    'order_timestamp' => '2026-07-01T00:00:00Z',
    'deadline_hours' => 2,
];

$partnerOrder = jg_partner_order_build_record('ACME', $partner, $basePayload + [
    'marketplace_platform' => 'Website',
]);
$shopeeOrder = jg_partner_order_build_record('ACME', $partner, $basePayload + [
    'marketplace_platform' => 'Shopee',
]);

partner_order_expect(['pdf'], jg_partner_order_allowed_extensions(), 'Shipment labels should be PDF-only.');
partner_order_expect(24, $partnerOrder['deadline_hours'], 'Non-marketplace partner orders should have at least 24 hours.');
partner_order_expect(2, $shopeeOrder['deadline_hours'], 'Shopee partner orders should keep shorter marketplace deadlines.');
partner_order_expect(40000.0, $partnerOrder['revenue_total'], 'Partner order revenue should sum all item lines.');
partner_order_expect(2, count($partnerOrder['items']) === 1 ? $partnerOrder['items'][0]['quantity'] : 0, 'Partner orders should preserve selected SKU quantities.');

$retentionNow = strtotime('2026-07-16T00:00:00Z');
partner_order_expect(false, jg_partner_order_archive_is_expired([
    'archived_at' => '2026-06-17T00:00:01Z',
], $retentionNow), 'Archived orders should remain available until 30 full days have elapsed.');
partner_order_expect(true, jg_partner_order_archive_is_expired([
    'archived_at' => '2026-06-16T00:00:00Z',
], $retentionNow), 'Archived orders should expire at the 30-day boundary.');
partner_order_expect(false, jg_partner_order_archive_is_expired([
    'archived_at' => '',
], $retentionNow), 'Active orders should never expire under archive retention.');

echo "partner-order-rules-test: ok\n";
