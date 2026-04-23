<?php
declare(strict_types=1);

const JG_PARTNER_SOURCE_URL = 'https://admin.jenanggemi.com/api/partners/public/';
const JG_PARTNER_FALLBACK_FILE = __DIR__ . '/data/partners.json';

function jg_partner_source_fallback(): array
{
    if (!file_exists(JG_PARTNER_FALLBACK_FILE)) {
        return ['partners' => []];
    }

    $raw = file_get_contents(JG_PARTNER_FALLBACK_FILE);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : ['partners' => []];
}

function jg_partner_source_load(): array
{
    $responseBody = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(JG_PARTNER_SOURCE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($responseBody !== false && $statusCode >= 200 && $statusCode < 300) {
            $data = json_decode((string) $responseBody, true);
            if (is_array($data)) {
                return $data;
            }
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);
        $responseBody = @file_get_contents(JG_PARTNER_SOURCE_URL, false, $context);
        if ($responseBody !== false) {
            $data = json_decode((string) $responseBody, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    return jg_partner_source_fallback();
}

function jg_partner_source_find(string $code): ?array
{
    $registry = jg_partner_source_load();
    foreach ($registry['partners'] ?? [] as $partner) {
        if ((string) ($partner['code'] ?? '') === $code) {
            return $partner;
        }
    }
    return null;
}

function jg_partner_source_find_by_slug(string $slug): ?array
{
    $normalizedSlug = trim(trim($slug), '/');
    if ($normalizedSlug === '') {
        return null;
    }

    $registry = jg_partner_source_load();
    foreach ($registry['partners'] ?? [] as $partner) {
        if ((string) ($partner['partner_slug'] ?? '') === $normalizedSlug) {
            return $partner;
        }
    }

    return null;
}

function jg_partner_source_catalog(?array $partner = null): array
{
    $partner = is_array($partner) ? $partner : jg_partner_current_profile();
    if (!is_array($partner)) {
        return [];
    }

    $catalog = [];
    foreach ((array) ($partner['selected_sku_records'] ?? []) as $sku) {
        if (!is_array($sku)) {
            continue;
        }

        $brandName = trim((string) ($sku['brand_name'] ?? ''));
        $productName = trim((string) ($sku['product_name'] ?? $sku['base_product_name'] ?? ''));
        $skuCode = trim((string) ($sku['sku'] ?? ''));
        if ($brandName === '' || $productName === '' || $skuCode === '') {
            continue;
        }

        if (!isset($catalog[$brandName])) {
            $catalog[$brandName] = [];
        }

        if (!isset($catalog[$brandName][$productName])) {
            $catalog[$brandName][$productName] = [
                'skus' => [],
            ];
        }

        $catalog[$brandName][$productName]['skus'][] = [
            'sku' => $skuCode,
            'label' => trim((string) ($sku['label'] ?? '')) ?: $skuCode,
            'flavor' => trim((string) ($sku['flavor_name'] ?? '')),
            'size' => trim((string) ($sku['size_label'] ?? '')),
            'stock' => (int) ($sku['current_stock'] ?? 0),
        ];
    }

    foreach ($catalog as &$products) {
        ksort($products);
        foreach ($products as &$product) {
            usort(
                $product['skus'],
                static fn (array $left, array $right): int => strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
            );
        }
        unset($product);
    }
    unset($products);

    ksort($catalog);

    return $catalog;
}
