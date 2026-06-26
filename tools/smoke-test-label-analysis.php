<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-order-storage.php';

$path = (string) ($argv[1] ?? '/home/branch/Downloads/18 mei - shopee.pdf');
if (!is_file($path)) {
    fwrite(STDERR, "Label PDF not found: {$path}\n");
    exit(2);
}

$partner = [
    'pricing' => [
        'ARENGARUT30' => 10000,
    ],
    'selected_sku_records' => [
        [
            'sku' => 'ARENGARUT30',
            'label' => 'Aren 30 Sachet',
            'tag' => 'BUBUR_SEHAT_AREN',
            'brand_name' => 'Jenang Gemi',
            'product_name' => 'Bubur Sehat Ekstrak Umbi Garut Kaya Manfaat untuk Lambung',
            'base_product_name' => 'Bubur Sehat Ekstrak Umbi Garut',
            'flavor_name' => 'Rasa Aren',
            'size_label' => '30Sachet',
            'partner_price' => 10000,
        ],
    ],
];

$text = jg_partner_order_extract_readable_text($path, basename($path));
$analysis = jg_partner_order_analyze_label_text($partner, $text);
$items = array_values($analysis['items'] ?? []);
$first = $items[0] ?? [];

$ok = ($analysis['platform']['platform'] ?? '') === 'Shopee'
    && count($items) === 1
    && ($first['sku_code'] ?? '') === 'ARENGARUT30'
    && (int) ($first['quantity'] ?? 0) === 1
    && str_contains($text, 'Jenang Gemi')
    && str_contains($text, 'Rasa Aren');

echo json_encode([
    'ok' => $ok,
    'text_has_product' => str_contains($text, 'Jenang Gemi'),
    'text_has_variation' => str_contains($text, 'Rasa Aren'),
    'platform' => $analysis['platform'] ?? [],
    'customer_name' => $analysis['customer_name'] ?? '',
    'items' => $items,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
