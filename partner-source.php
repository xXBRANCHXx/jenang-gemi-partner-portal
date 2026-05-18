<?php
declare(strict_types=1);

const JG_PARTNER_SOURCE_URL = 'https://admin.jenanggemi.com/api/partners/public/';
const JG_PARTNER_AUTH_URL = 'https://admin.jenanggemi.com/api/partners/auth/';
const JG_PARTNER_PASSWORD_URL = 'https://admin.jenanggemi.com/api/partners/password/';
const JG_PARTNER_RUNTIME_FILE = __DIR__ . '/data/partners.runtime.json';
const JG_PARTNER_FALLBACK_FILE = __DIR__ . '/data/partners.json';

function jg_partner_source_post_json(string $url, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'error' => 'Unable to encode request.'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
    }

    $decoded = json_decode((string) $responseBody, true);
    $decoded = is_array($decoded) ? $decoded : [];
    if ($statusCode < 200 || $statusCode >= 300) {
        $decoded['ok'] = false;
    }

    return $decoded;
}

function jg_partner_source_fallback(): array
{
    $path = is_file(JG_PARTNER_RUNTIME_FILE) ? JG_PARTNER_RUNTIME_FILE : JG_PARTNER_FALLBACK_FILE;
    if (!file_exists($path)) {
        return ['partners' => []];
    }

    $raw = file_get_contents($path);
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

function jg_partner_source_authenticate(string $code, string $password): ?array
{
    $response = jg_partner_source_post_json(JG_PARTNER_AUTH_URL, [
        'code' => strtoupper(trim($code)),
        'password' => $password,
    ]);

    return !empty($response['ok']) && is_array($response['partner'] ?? null) ? $response['partner'] : null;
}

function jg_partner_source_change_password(string $code, string $currentPassword, string $newPassword): array
{
    return jg_partner_source_post_json(JG_PARTNER_PASSWORD_URL, [
        'code' => strtoupper(trim($code)),
        'current_password' => $currentPassword,
        'new_password' => $newPassword,
    ]);
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
