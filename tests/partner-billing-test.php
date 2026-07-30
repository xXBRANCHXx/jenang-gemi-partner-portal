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
partner_billing_expect('2026-07-01', $first['start'], 'The launch block must start July 1.');
partner_billing_expect('2026-07-07', $first['end'], 'The launch block must cover exactly seven days.');
partner_billing_expect('2026-07-10', $first['due'], 'Closed bills should be due three days after their block.');

$boundary = jg_partner_billing_period(new DateTimeImmutable('2026-07-07 16:59:59', $utc));
partner_billing_expect('2026-07-01', $boundary['start'], 'The last WIB second of July 7 must remain in the first block.');
$next = jg_partner_billing_period(new DateTimeImmutable('2026-07-07 17:00:00', $utc));
partner_billing_expect('2026-07-08', $next['start'], 'Midnight WIB on July 8 must start the next block.');
partner_billing_expect('2026-07-14', $next['end'], 'Every block must remain seven calendar days.');

$before = jg_partner_billing_period(new DateTimeImmutable('2026-06-30 12:00:00', $utc));
partner_billing_expect('2026-06-24', $before['start'], 'Periods before the anchor must roll backward in seven-day blocks.');
partner_billing_expect(
    jg_partner_billing_bill_id('BAGGOS', '2026-07-01'),
    jg_partner_billing_bill_id(' baggos ', '2026-07-01'),
    'Bill IDs must be stable for normalized partner codes.'
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

echo "partner-billing-test: ok\n";
