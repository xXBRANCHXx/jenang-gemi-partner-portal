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
    'pricing' => [
        'JG-001' => 20000,
    ],
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
partner_order_expect(12, $partnerOrder['deadline_hours'], 'Partner order deadlines should allow 12 hours.');
partner_order_expect(12, $shopeeOrder['deadline_hours'], 'Marketplace order deadlines should have the same 12-hour minimum.');
partner_order_expect(24, jg_partner_order_normalize_deadline_hours(null), 'Partner order deadlines should default to 24 hours.');
partner_order_expect(48, jg_partner_order_normalize_deadline_hours(72), 'Partner order deadlines should have a 48-hour maximum.');
partner_order_expect(40000.0, $partnerOrder['revenue_total'], 'Partner order revenue should sum all item lines.');
partner_order_expect(20000.0, $partnerOrder['items'][0]['unit_revenue'], 'Partner pricing should remain the configured SKU-level price.');
partner_order_expect(20000.0, $partnerOrder['items'][0]['partner_unit_price'], 'Partner unit pricing should remain at SKU level.');
partner_order_expect(2, count($partnerOrder['items']) === 1 ? $partnerOrder['items'][0]['quantity'] : 0, 'Partner orders should preserve selected SKU quantities.');
partner_order_expect('TikTok/Toped', jg_partner_order_normalize_marketplace_platform('TikTok Shop'), 'TikTok aliases should use the combined built-in platform.');
partner_order_expect('Shopee Bandung', jg_partner_order_normalize_marketplace_platform('Shopee Bandung'), 'Custom reseller names should not collapse into built-in platforms.');

$missingPlatformRejected = false;
try {
    jg_partner_order_build_record('ACME', $partner, $basePayload);
} catch (InvalidArgumentException $error) {
    $missingPlatformRejected = $error->getMessage() === 'Order platform is required.';
}
partner_order_expect(true, $missingPlatformRejected, 'New partner orders should require a selected platform.');

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
partner_order_expect(true, jg_partner_order_status_can_transition('IS_LISTED', 'IS_BEING_FULFILLED'), 'Store Ops should be able to start a listed order.');
partner_order_expect(false, jg_partner_order_status_can_transition('IS_BEING_FULFILLED', 'CANCELLED'), 'A started order must no longer be cancellable.');
partner_order_expect(false, jg_partner_order_status_can_transition('FULFILLED', 'IS_LISTED'), 'A fulfilled order must not return to the Store Ops queue.');
partner_order_expect(false, jg_partner_order_status_can_transition('CANCELLED', 'IS_BEING_FULFILLED'), 'A stale Store Ops client must not revive a cancelled order.');
partner_order_expect(true, jg_partner_order_is_store_visible(['status' => 'IS_LISTED']), 'Listed orders should appear in Store Ops.');
partner_order_expect(false, jg_partner_order_is_store_visible(['status' => 'CANCELLED']), 'Cancelled orders should disappear from Store Ops.');
partner_order_expect(false, jg_partner_order_is_store_visible(['status' => 'IS_BEING_FULFILLED']), 'Orders already handed to Store Ops should not be re-listed.');

$analytics = jg_partner_order_analytics([
    [
        'order_timestamp' => '2026-07-01T08:00:00Z',
        'archived_at' => '',
    ],
    [
        'order_timestamp' => '2026-07-02T09:00:00Z',
        'archived_at' => '2026-07-03T00:00:00Z',
    ],
]);
partner_order_expect(1, $analytics['total_orders'], 'Archived orders should not count toward analytics totals.');
partner_order_expect(1, $analytics['monthly_by_year']['2026'][6], 'Archived orders should not count toward monthly analytics.');
partner_order_expect(0, $analytics['hourly_distribution'][9], 'Archived orders should not count toward hourly analytics.');

echo "partner-order-rules-test: ok\n";
