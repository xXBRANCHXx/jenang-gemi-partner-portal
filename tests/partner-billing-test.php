<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-billing-storage.php';

function partner_billing_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$utc = new DateTimeZone('UTC');
$first = jg_partner_billing_period(new DateTimeImmutable('2026-07-01 00:00:00', $utc));
partner_billing_expect('2026-06-29', $first['start'], 'A Wednesday order must belong to the preceding Monday.');
partner_billing_expect('2026-07-05', $first['end'], 'Calendar billing weeks must end on Sunday.');
partner_billing_expect('2026-07-08', $first['due'], 'Closed bills should be due three days after Sunday.');

$boundary = jg_partner_billing_period(new DateTimeImmutable('2026-07-05 16:59:59', $utc));
partner_billing_expect('2026-06-29', $boundary['start'], 'The last WIB second of Sunday must remain in the closing week.');
$next = jg_partner_billing_period(new DateTimeImmutable('2026-07-05 17:00:00', $utc));
partner_billing_expect('2026-07-06', $next['start'], 'Midnight WIB on Monday must start a new billing week.');
partner_billing_expect('2026-07-12', $next['end'], 'Every billing week must run through Sunday.');

$before = jg_partner_billing_period(new DateTimeImmutable('2026-06-30 12:00:00', $utc));
partner_billing_expect('2026-06-29', $before['start'], 'Tuesday must belong to its Monday–Sunday calendar week.');
partner_billing_expect(
    jg_partner_billing_bill_id('BAGGOS', '2026-07-01'),
    jg_partner_billing_bill_id(' baggos ', '2026-07-01'),
    'Bill IDs must be stable for normalized partner codes.'
);

partner_billing_expect(true, jg_partner_billing_bill_is_mutable([
    'status' => 'paid',
    'total_amount' => 230000,
    'has_payment' => 0,
    'has_dispute' => 0,
    'has_file' => 0,
]), 'A closed zero bill must remain repairable when backdated orders later give it a balance.');
partner_billing_expect(false, jg_partner_billing_bill_is_mutable([
    'status' => 'paid',
    'total_amount' => 230000,
    'has_payment' => 1,
    'has_dispute' => 0,
    'has_file' => 1,
]), 'A bill with a real payment audit trail must remain immutable.');
partner_billing_expect(
    'unpaid',
    jg_partner_billing_recalculated_status('paid', '2026-08-02', 230000, false, '2026-08-05'),
    'Backdated orders must reopen a synthetically paid closed bill as unpaid.'
);
partner_billing_expect(
    'paid',
    jg_partner_billing_recalculated_status('paid', '2026-08-02', 230000, true, '2026-08-05'),
    'A confirmed payment must keep a closed bill paid.'
);
partner_billing_expect(
    'paid',
    jg_partner_billing_recalculated_status('unpaid', '2026-08-02', 0, false, '2026-08-05'),
    'A genuinely empty closed bill may still settle automatically.'
);

$badgeCreated = '2026-07-30 02:00:00';
$beforeBadgeExpiry = jg_partner_billing_new_badge_state(
    $badgeCreated,
    new DateTimeImmutable('2026-08-06 01:59:59', $utc)
);
partner_billing_expect(true, $beforeBadgeExpiry['visible'], 'The NEW badge must remain visible for the full seven-day window.');
partner_billing_expect('2026-08-06T02:00:00Z', $beforeBadgeExpiry['expires_at'], 'The NEW badge expiry must be exactly seven days after onboarding starts.');
$atBadgeExpiry = jg_partner_billing_new_badge_state(
    $badgeCreated,
    new DateTimeImmutable('2026-08-06 02:00:00', $utc)
);
partner_billing_expect(false, $atBadgeExpiry['visible'], 'The NEW badge must hide once its seven-day window expires.');
partner_billing_expect(true, jg_partner_billing_new_badge_state('')['visible'], 'An invalid start time must fail open so partners still discover Billing.');

$invalidFile = tempnam(sys_get_temp_dir(), 'billing-invalid-');
file_put_contents($invalidFile, 'not really an image');
$invalidRejected = false;
try {
    jg_partner_billing_validate_file([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $invalidFile,
        'name' => 'fake.png',
        'size' => filesize($invalidFile),
    ]);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
@unlink($invalidFile);
partner_billing_expect(true, $invalidRejected, 'Proof validation must inspect content instead of trusting extensions.');

$pdfFile = tempnam(sys_get_temp_dir(), 'billing-pdf-');
file_put_contents($pdfFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
$pdf = jg_partner_billing_validate_file([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $pdfFile,
    'name' => 'transfer.pdf',
    'size' => filesize($pdfFile),
]);
@unlink($pdfFile);
partner_billing_expect('application/pdf', $pdf['mime_type'], 'Valid PDF proof should be accepted.');

$priceProposal = jg_partner_billing_price_proposal([
    'order_id' => 'PO-PRICE-1',
    'amount' => 35000,
    'units' => 3,
    'snapshot_json' => json_encode(['items' => [
        ['sku_code' => 'SKU-A', 'sku_label' => 'Product A', 'quantity' => 2, 'unit_revenue' => 10000],
        ['sku_code' => 'SKU-B', 'sku_label' => 'Product B', 'quantity' => 1, 'unit_revenue' => 15000],
    ]]),
], ['lines' => [
    ['line_index' => 0, 'unit_price' => 12000],
    ['line_index' => 1, 'unit_price' => 8000],
]]);
partner_billing_expect(35000, $priceProposal['original_amount'], 'The dispute must preserve the original order value.');
partner_billing_expect(32000, $priceProposal['proposed_amount'], 'The proposed order value must sum editable product prices by quantity.');
partner_billing_expect(12000, $priceProposal['lines'][0]['proposed_unit_price'], 'Each proposed product price must remain auditable.');
partner_billing_expect(true, jg_partner_billing_price_proposal_changed($priceProposal), 'A changed product price must classify the dispute as a price dispute.');

$sameTotalProposal = jg_partner_billing_price_proposal([
    'order_id' => 'PO-PRICE-SAME-TOTAL',
    'amount' => 20000,
    'units' => 2,
    'snapshot_json' => json_encode(['items' => [
        ['sku_code' => 'SKU-A', 'quantity' => 1, 'unit_revenue' => 8000],
        ['sku_code' => 'SKU-B', 'quantity' => 1, 'unit_revenue' => 12000],
    ]]),
], ['lines' => [
    ['line_index' => 0, 'unit_price' => 9000],
    ['line_index' => 1, 'unit_price' => 11000],
]]);
partner_billing_expect(20000, $sameTotalProposal['proposed_amount'], 'Offsetting product edits may preserve the order total.');
partner_billing_expect(true, jg_partner_billing_price_proposal_changed($sameTotalProposal), 'Line edits must remain price disputes even when the order total is unchanged.');
partner_billing_expect(true, jg_partner_billing_price_proposal_changed([
    'original_amount' => 59000,
    'proposed_amount' => 32000,
    'lines' => [['original_unit_price' => 32000, 'proposed_unit_price' => 32000]],
]), 'A proposal must remain a price dispute when the immutable bill total differs from its product snapshot.');

$missingPriceRejected = false;
try {
    jg_partner_billing_price_proposal([
        'order_id' => 'PO-PRICE-2', 'amount' => 20000, 'units' => 2,
        'snapshot_json' => json_encode(['items' => [
            ['sku_code' => 'SKU-A', 'quantity' => 1, 'unit_revenue' => 10000],
            ['sku_code' => 'SKU-B', 'quantity' => 1, 'unit_revenue' => 10000],
        ]]),
    ], ['lines' => [['line_index' => 0, 'unit_price' => 10000]]]);
} catch (InvalidArgumentException) {
    $missingPriceRejected = true;
}
partner_billing_expect(true, $missingPriceRejected, 'Every selected product must include a proposed price.');

$legacyProposal = jg_partner_billing_price_proposal([
    'order_id' => 'PO-LEGACY', 'amount' => 10000, 'units' => 1,
    'snapshot_json' => json_encode(['items' => [['sku_code' => 'SKU-A', 'quantity' => 1, 'unit_revenue' => 10000]]]),
], []);
partner_billing_expect(10000, $legacyProposal['proposed_amount'], 'Older paid-dispute requests without price fields must retain their current behavior.');
partner_billing_expect(false, jg_partner_billing_price_proposal_changed($legacyProposal), 'An unchanged legacy request must remain an already-paid dispute.');

echo "partner-billing-test: ok\n";
