<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-report-pdf.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$platforms = ['Shopee', 'TikTok/Toped', 'Jakarta Reseller', 'Bandung Retail'];
$products = [
    ['sku' => 'JG-ORI-250', 'product' => 'Jenang Gemi Original', 'flavor' => 'Original', 'size' => '250 g', 'price' => 38000],
    ['sku' => 'JG-DRN-250', 'product' => 'Jenang Gemi Premium', 'flavor' => 'Durian', 'size' => '250 g', 'price' => 46000],
    ['sku' => 'JG-CHC-250', 'product' => 'Jenang Gemi Premium', 'flavor' => 'Chocolate', 'size' => '250 g', 'price' => 44000],
    ['sku' => 'JG-GFT-500', 'product' => 'Jenang Gemi Gift Box', 'flavor' => 'Assorted', 'size' => '500 g', 'price' => 92000],
    ['sku' => 'JG-PND-250', 'product' => 'Jenang Gemi Classic', 'flavor' => 'Pandan', 'size' => '250 g', 'price' => 41000],
];
$customers = ['Alya Rahman', 'Bima Santoso', 'Citra Lestari', 'Dimas Pratama', 'Farah Putri', 'Gilang Saputra', 'Hana Wijaya', 'Intan Permata'];
$statuses = ['FULFILLED', 'FULFILLED', 'IS_BEING_FULFILLED', 'FULFILLED', 'IS_LISTED', 'COMPLETED', 'CANCELLED'];
$orders = [];
$base = new DateTimeImmutable('2026-06-02 09:15:00', $timezone);
for ($index = 0; $index < 28; $index++) {
    $first = $products[$index % count($products)];
    $quantity = 1 + (($index * 3) % 5);
    $items = [[
        'sku_code' => $first['sku'],
        'product' => $first['product'],
        'flavor' => $first['flavor'],
        'size' => $first['size'],
        'quantity' => $quantity,
        'unit_revenue' => $first['price'],
        'line_revenue' => $first['price'] * $quantity,
    ]];
    if ($index % 4 === 0) {
        $second = $products[($index + 2) % count($products)];
        $items[] = [
            'sku_code' => $second['sku'],
            'product' => $second['product'],
            'flavor' => $second['flavor'],
            'size' => $second['size'],
            'quantity' => 2,
            'unit_revenue' => $second['price'],
            'line_revenue' => $second['price'] * 2,
        ];
    }
    $orders[] = [
        'id' => 'PO2606' . str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
        'customer_name' => $customers[$index % count($customers)],
        'marketplace_platform' => $platforms[$index % count($platforms)],
        'status' => $statuses[$index % count($statuses)],
        'order_timestamp' => $base->modify('+' . ($index * 2) . ' days +' . (($index * 37) % 540) . ' minutes')->format(DATE_ATOM),
        'items' => $items,
        'revenue_total' => array_sum(array_column($items, 'line_revenue')),
        'archived_at' => '',
    ];
}

$pdf = jg_partner_report_render(
    ['name' => 'Baggos Partners'],
    $orders,
    [
        'language' => 'en',
        'timezone' => 'Asia/Jakarta',
        'start' => new DateTimeImmutable('2026-06-01', $timezone),
        'end' => new DateTimeImmutable('2026-08-01', $timezone),
        'generated_at' => new DateTimeImmutable('2026-07-22 08:45:00', $timezone),
        'document_ref' => 'BG-SAMPLE-20260722',
        'sections' => ['channels', 'products', 'orders'],
        'sample' => true,
        'icon_path' => $argv[2] ?? null,
    ]
);

$output = $argv[1] ?? (dirname(__DIR__, 2) . '/Partner_Report_SAMPLE.pdf');
if (@file_put_contents($output, $pdf, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write sample PDF to {$output}\n");
    exit(1);
}
fwrite(STDOUT, $output . PHP_EOL);
