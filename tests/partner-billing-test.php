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

final class PartnerBillingFakePdo extends PDO
{
    /** @var list<array<string,string>> */
    public array $bills;

    /** @param list<array<string,string>> $bills */
    public function __construct(array $bills = [])
    {
        $this->bills = $bills;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PartnerBillingFakeStatement($this, $query);
    }
}

final class PartnerBillingFakeStatement extends PDOStatement
{
    private PartnerBillingFakePdo $database;
    private string $sql;
    private mixed $result = false;

    public function __construct(PartnerBillingFakePdo $database, string $sql)
    {
        $this->database = $database;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_contains($this->sql, 'INSERT INTO partner_weekly_bills')) {
            foreach ($this->database->bills as $bill) {
                if ($bill['bill_id'] === $params[':bill_id']
                    || ($bill['partner_code'] === $params[':partner_code']
                        && $bill['period_type'] === $params[':period_type']
                        && $bill['period_start'] === $params[':period_start'])) {
                    return true;
                }
            }
            $this->database->bills[] = [
                'bill_id' => $params[':bill_id'],
                'partner_code' => $params[':partner_code'],
                'period_type' => $params[':period_type'],
                'period_start' => $params[':period_start'],
                'period_end' => $params[':period_end'],
                'due_date' => $params[':due_date'],
            ];
            return true;
        }

        if (str_contains($this->sql, 'FROM partner_weekly_bills')) {
            $this->result = false;
            foreach ($this->database->bills as $bill) {
                if ($bill['partner_code'] === $params[':partner_code']
                    && $bill['period_type'] === $params[':period_type']
                    && $bill['period_start'] === $params[':period_start']) {
                    $this->result = $bill;
                    break;
                }
            }
            return true;
        }

        throw new RuntimeException('Unexpected fake billing query.');
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result;
    }
}

$utc = new DateTimeZone('UTC');
$first = jg_partner_billing_period(new DateTimeImmutable('2026-07-01 00:00:00', $utc));
partner_billing_expect('2026-06-29', $first['start'], 'A Wednesday order must belong to the preceding Monday.');
partner_billing_expect('2026-07-05', $first['end'], 'Calendar weeks must end on Sunday.');
partner_billing_expect('2026-07-08', $first['due'], 'Closed calendar-week bills should be due three days after Sunday.');

$boundary = jg_partner_billing_period(new DateTimeImmutable('2026-07-05 16:59:59', $utc));
partner_billing_expect('2026-06-29', $boundary['start'], 'The final WIB second of Sunday must remain in the closing calendar week.');
$next = jg_partner_billing_period(new DateTimeImmutable('2026-07-05 17:00:00', $utc));
partner_billing_expect('2026-07-06', $next['start'], 'Midnight WIB on Monday must start a new billing week.');
partner_billing_expect('2026-07-12', $next['end'], 'Every calendar week must run through Sunday.');

$before = jg_partner_billing_period(new DateTimeImmutable('2026-06-30 12:00:00', $utc));
partner_billing_expect('2026-06-29', $before['start'], 'Tuesday must belong to its Monday–Sunday calendar week.');
$month = jg_partner_billing_period(new DateTimeImmutable('2028-02-17 12:00:00', $utc), 'calendar_month');
partner_billing_expect('calendar_month', $month['type'], 'Calendar-month selection must be preserved.');
partner_billing_expect('2028-02-01', $month['start'], 'Calendar months must begin on day one.');
partner_billing_expect('2028-02-29', $month['end'], 'Calendar months must respect leap years.');
partner_billing_expect('2028-03-03', $month['due'], 'Calendar-month bills remain due three days after month end.');
partner_billing_expect('calendar_week', jg_partner_billing_period_type('unknown'), 'Unknown settings must safely use the default calendar week.');
partner_billing_expect('calendar_week', jg_partner_billing_period_type('business_week'), 'Legacy business-week profiles must migrate to calendar-week behavior.');
partner_billing_expect(
    jg_partner_billing_bill_id('BAGGOS', '2026-07-01', 'calendar_week'),
    jg_partner_billing_bill_id(' baggos ', '2026-07-01', 'calendar_week'),
    'Bill IDs must be stable for normalized partner codes.'
);
partner_billing_expect(false, jg_partner_billing_bill_id('BAGGOS', '2026-07-01', 'calendar_week') === jg_partner_billing_bill_id('BAGGOS', '2026-07-01', 'calendar_month'), 'PO IDs must be unique across period types.');

$legacyPeriod = jg_partner_billing_period(new DateTimeImmutable('2026-08-12 00:00:00', $utc));
$legacyBillId = 'PB-20260810-LEGACY';
$legacyDatabase = new PartnerBillingFakePdo([[
    'bill_id' => $legacyBillId,
    'partner_code' => 'BAGGOS',
    'period_type' => 'calendar_week',
    'period_start' => $legacyPeriod['start'],
    'period_end' => $legacyPeriod['end'],
    'due_date' => $legacyPeriod['due'],
]]);
partner_billing_expect(
    $legacyBillId,
    jg_partner_billing_ensure_period_bill($legacyDatabase, 'BAGGOS', $legacyPeriod, 'accruing'),
    'A legacy bill ID must remain canonical when its unique period already exists.'
);
partner_billing_expect(1, count($legacyDatabase->bills), 'Canonical period resolution must not create a duplicate bill container.');

$newDatabase = new PartnerBillingFakePdo();
partner_billing_expect(
    jg_partner_billing_bill_id('BAGGOS', $legacyPeriod['start'], 'calendar_week'),
    jg_partner_billing_ensure_period_bill($newDatabase, 'BAGGOS', $legacyPeriod, 'accruing'),
    'A new period must return the bill ID that was actually inserted.'
);

partner_billing_expect(true, jg_partner_billing_bill_is_mutable([
    'status' => 'paid',
    'total_amount' => 230000,
    'has_active_payment' => 0,
    'has_active_dispute' => 0,
    'has_file' => 1,
]), 'A closed zero bill must remain repairable when only obsolete proof history remains.');
partner_billing_expect(false, jg_partner_billing_bill_is_mutable([
    'status' => 'paid',
    'total_amount' => 230000,
    'has_active_payment' => 1,
    'has_active_dispute' => 0,
]), 'A bill with a real payment audit trail must remain immutable.');
partner_billing_expect(false, jg_partner_billing_bill_is_mutable([
    'status' => 'unpaid',
    'has_active_payment' => 0,
    'has_active_dispute' => 1,
]), 'A pending dispute must prevent automatic bill movement.');
partner_billing_expect(true, jg_partner_billing_bill_is_mutable([
    'status' => 'unpaid',
    'has_active_payment' => 0,
    'has_active_dispute' => 0,
    'has_accepted_dispute' => 1,
]), 'An accepted dispute must not block unrelated orders from moving into a bill.');
partner_billing_expect(false, jg_partner_billing_bill_accepts_new_orders([
    'status' => 'payment_submitted',
    'has_active_payment' => 1,
    'has_active_dispute' => 0,
]), 'A bill under payment review must reject late order insertion.');
partner_billing_expect(false, jg_partner_billing_bill_accepts_new_orders([
    'status' => 'disputed',
    'has_active_payment' => 0,
    'has_active_dispute' => 1,
]), 'A disputed bill must reject late order insertion.');
partner_billing_expect(true, jg_partner_billing_bill_accepts_new_orders([
    'status' => 'unpaid',
    'has_active_payment' => 0,
    'has_active_dispute' => 0,
]), 'An unpaid bill without an active audit workflow may accept backdated orders and recalculate.');

$billingSource = (string) file_get_contents(dirname(__DIR__) . '/partner-billing-storage.php');
partner_billing_expect(true, str_contains($billingSource, 'i.paid_at IS NULL') && str_contains($billingSource, 'i.status <> "removed"'), 'Period changes must move only unpaid, non-removed orders.');
partner_billing_expect(true, strpos($billingSource, 'jg_partner_billing_recalculate_bill($pdo, $billId);') < strpos($billingSource, '$deleteEmptyLegacyBills = $pdo->prepare('), 'PO totals must recalculate before obsolete POs are deleted inside the transaction.');
partner_billing_expect(true, str_contains($billingSource, 'NOT EXISTS(SELECT 1 FROM partner_weekly_bill_payments') && str_contains($billingSource, 'NOT EXISTS(SELECT 1 FROM partner_weekly_bill_disputes'), 'Obsolete PO deletion must preserve payment and dispute audit records.');
partner_billing_expect(true, str_contains($billingSource, 'function jg_partner_billing_merge_duplicate_periods'), 'Exact duplicate periods must be consolidated after rebucketing.');
partner_billing_expect(true, str_contains($billingSource, 'UPDATE partner_weekly_bill_items SET bill_id = :target_id') && str_contains($billingSource, 'UPDATE partner_weekly_bill_disputes SET bill_id = :target_id'), 'Duplicate consolidation must preserve item and dispute audit history on the canonical bill.');
partner_billing_expect(true, strpos($billingSource, 'jg_partner_billing_rebucket_partner($pdo, $partnerCode, $periodType)') < strpos($billingSource, 'jg_partner_billing_merge_duplicate_periods($pdo, $partnerCode, $periodType)'), 'Legacy orders must rebucket before exact duplicate periods are merged.');
partner_billing_expect(true, str_contains($billingSource, 'function jg_partner_billing_ensure_period_bill'), 'Bill insertion must resolve the canonical row selected by the unique period key.');
partner_billing_expect(true, str_contains($billingSource, 'ON DUPLICATE KEY UPDATE bill_id = bill_id'), 'Existing legacy bills must be preserved instead of silently replacing their audit ID.');
partner_billing_expect(true, str_contains($billingSource, 'AND current_bill.bill_id IS NULL'), 'A later sync must repair unpaid order items orphaned by the legacy bill-ID migration.');
partner_billing_expect(true, str_contains($billingSource, 'could not be attached to a valid bill'), 'Billing sync must fail loudly if an order item is still detached after repair.');
partner_billing_expect(true, str_contains($billingSource, 'function jg_partner_billing_assert_integrity'), 'Every completed sync must run the production integrity audit.');
partner_billing_expect(true, str_contains($billingSource, 'A late order reached a billing period with an active payment or dispute.'), 'Late orders must not silently change bills under payment or dispute review.');
partner_billing_expect(false, str_contains($billingSource, 'INSERT IGNORE INTO partner_weekly_bills'), 'Bill creation must not silently continue with an ID that the database rejected.');
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
