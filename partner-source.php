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

function jg_partner_source_catalog(): array
{
    return [
        'Jenang Gemi' => [
            'Bubur' => [
                'flavors' => ['Original', 'Coklat', 'Pandan', 'Stroberi'],
                'sizes' => ['250g', '500g', '1000g'],
            ],
            'Jamu' => [
                'flavors' => ['Original', 'Jahe', 'Kunyit Asam'],
                'sizes' => ['10 sachet', '20 sachet'],
            ],
        ],
        'ZERO' => [],
        'ZFIT' => [],
    ];
}
