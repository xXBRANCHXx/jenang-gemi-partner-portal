<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-report-pdf.php';

function report_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$timezone = new DateTimeZone('Asia/Jakarta');
$orders = [
    [
        'id' => 'PO-ONE',
        'status' => 'FULFILLED',
        'marketplace_platform' => 'Shopee',
        'order_timestamp' => '2026-07-01T10:00:00+07:00',
        'items' => [['product' => 'Original', 'quantity' => 3, 'line_revenue' => 120000]],
        'revenue_total' => 120000,
    ],
    [
        'id' => 'PO-CANCEL',
        'status' => 'CANCELLED',
        'marketplace_platform' => 'Shopee',
        'order_timestamp' => '2026-07-02T10:00:00+07:00',
        'items' => [['product' => 'Original', 'quantity' => 5, 'line_revenue' => 200000]],
        'revenue_total' => 200000,
    ],
    [
        'id' => 'PO-OUTSIDE',
        'status' => 'FULFILLED',
        'marketplace_platform' => 'TikTok/Toped',
        'order_timestamp' => '2026-08-01T00:00:00+07:00',
        'items' => [['product' => 'Durian', 'quantity' => 2, 'line_revenue' => 90000]],
        'revenue_total' => 90000,
    ],
];
$summary = jg_partner_report_aggregate(
    $orders,
    new DateTimeImmutable('2026-07-01', $timezone),
    new DateTimeImmutable('2026-08-01', $timezone),
    $timezone
);
report_expect(2, count($summary['orders']), 'The report range should use an exclusive end bound.');
report_expect(1, $summary['sales_orders'], 'Cancelled orders should not count as sales orders.');
report_expect(3, $summary['units'], 'Cancelled order units should be excluded.');
report_expect(120000.0, $summary['cost'], 'Cancelled order cost should be excluded.');
report_expect(1, $summary['status']['cancelled'], 'Cancelled orders should remain in the status summary.');

$pdf = jg_partner_report_render(['name' => 'Test Partner'], $orders, [
    'language' => 'id',
    'timezone' => 'Asia/Jakarta',
    'start' => new DateTimeImmutable('2026-07-01', $timezone),
    'end' => new DateTimeImmutable('2026-08-01', $timezone),
    'sections' => ['channels', 'products', 'orders'],
    'sample' => true,
]);
report_expect(true, str_starts_with($pdf, '%PDF-1.4'), 'The renderer should return a PDF document.');
report_expect(true, str_ends_with($pdf, "%%EOF\n"), 'The PDF should have a complete trailer.');
report_expect(true, str_contains($pdf, 'Laporan Kinerja Mitra'), 'The PDF should follow Indonesian language settings.');
report_expect(true, str_contains($pdf, 'DATA CONTOH - BUKAN UNTUK PEMBUKUAN'), 'Sample documents should identify the fictional dataset without changing the report layout.');
report_expect('TP', jg_partner_report_profile_initials('Test Partner'), 'Partners without a favicon should receive an initial-based profile mark.');

if (function_exists('imagecreatetruecolor')) {
    $iconPath = sys_get_temp_dir() . '/partner-report-icon-' . bin2hex(random_bytes(4)) . '.png';
    $icon = imagecreatetruecolor(32, 32);
    imagefill($icon, 0, 0, imagecolorallocate($icon, 52, 86, 134));
    imagepng($icon, $iconPath);
    $iconPdf = jg_partner_report_render(['name' => 'Test Partner'], $orders, [
        'language' => 'en',
        'timezone' => 'Asia/Jakarta',
        'start' => new DateTimeImmutable('2026-07-01', $timezone),
        'end' => new DateTimeImmutable('2026-08-01', $timezone),
        'sections' => [],
        'icon_path' => $iconPath,
    ]);
    @unlink($iconPath);
    report_expect(true, str_contains($iconPdf, '/Subtype /Image'), 'Configured partner favicons should be embedded into the PDF.');
}

echo "partner-report-pdf-test: ok\n";
