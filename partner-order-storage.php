<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-data-bootstrap.php';

const JG_PARTNER_ORDER_JSON_FILE = __DIR__ . '/data/orders.json';
const JG_PARTNER_LABEL_UPLOAD_DIR = __DIR__ . '/uploads/shipping-labels';

function jg_partner_order_default_database(): array
{
    return [
        'meta' => [
            'version' => '1.00.00',
            'updated_at' => gmdate(DATE_ATOM),
            'storage' => 'json',
        ],
        'orders' => [],
    ];
}

function jg_partner_order_assert_fallback_storage_allowed(): void
{
    if (!jg_partner_data_mysql_is_configured()) {
        return;
    }

    throw new LogicException('Order storage is temporarily unavailable.');
}

function jg_partner_order_storage_mode(): string
{
    if (jg_partner_data_db() instanceof PDO) {
        return 'mysql';
    }

    return jg_partner_data_mysql_is_configured() ? 'unavailable' : 'json';
}

function jg_partner_order_read_json_database(): array
{
    jg_partner_order_assert_fallback_storage_allowed();

    if (!is_file(JG_PARTNER_ORDER_JSON_FILE)) {
        return jg_partner_order_default_database();
    }

    $raw = @file_get_contents(JG_PARTNER_ORDER_JSON_FILE);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return jg_partner_order_default_database();
    }

    $decoded['meta'] = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
    $decoded['orders'] = array_values(array_filter($decoded['orders'] ?? [], 'is_array'));
    $decoded['meta']['storage'] = 'json';

    return $decoded;
}

function jg_partner_order_write_json_database(array $database): void
{
    jg_partner_order_assert_fallback_storage_allowed();

    $database['meta']['updated_at'] = gmdate(DATE_ATOM);
    $database['meta']['storage'] = 'json';

    $encoded = json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode orders.');
    }

    file_put_contents(JG_PARTNER_ORDER_JSON_FILE, $encoded . PHP_EOL, LOCK_EX);
}

function jg_partner_order_allowed_sku_index(?array $partner): array
{
    $index = [];
    $pricing = is_array($partner['pricing'] ?? null) ? $partner['pricing'] : [];

    foreach ((array) ($partner['selected_sku_records'] ?? []) as $sku) {
        if (!is_array($sku)) {
            continue;
        }

        $skuCode = trim((string) ($sku['sku'] ?? ''));
        if ($skuCode === '') {
            continue;
        }

        $index[$skuCode] = [
            'sku' => $skuCode,
            'label' => trim((string) ($sku['label'] ?? '')) ?: $skuCode,
            'tag' => trim((string) ($sku['tag'] ?? '')),
            'brand_name' => trim((string) ($sku['brand_name'] ?? '')),
            'product_name' => trim((string) ($sku['product_name'] ?? $sku['base_product_name'] ?? '')),
            'base_product_name' => trim((string) ($sku['base_product_name'] ?? $sku['product_name'] ?? '')),
            'flavor_name' => trim((string) ($sku['flavor_name'] ?? '')),
            'size_label' => trim((string) ($sku['size_label'] ?? '')),
            'item_tags' => array_values(array_filter((array) ($sku['item_tags'] ?? $sku['online_tags'] ?? $sku['aliases'] ?? []), static fn ($value): bool => trim((string) $value) !== '')),
            'current_stock' => (int) ($sku['current_stock'] ?? 0),
            'partner_price' => (float) ($sku['partner_price'] ?? $pricing[$skuCode] ?? 0),
        ];
    }

    return $index;
}

function jg_partner_order_validate_sku(?array $partner, mixed $skuCode): array
{
    $normalized = trim((string) $skuCode);
    if ($normalized === '') {
        throw new InvalidArgumentException('Matched product is required.');
    }

    $allowed = jg_partner_order_allowed_sku_index($partner);
    if (!isset($allowed[$normalized])) {
        throw new InvalidArgumentException('That matched product is not enabled for this partner.');
    }

    return $allowed[$normalized];
}

function jg_partner_order_normalize_search_text(mixed $value): string
{
    $text = strtoupper((string) $value);
    $text = preg_replace('/[^A-Z0-9]+/', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function jg_partner_order_tokenize(mixed $value): array
{
    $normalized = jg_partner_order_normalize_search_text($value);
    if ($normalized === '') {
        return [];
    }

    $stopwords = [
        'A' => true,
        'AN' => true,
        'AND' => true,
        'AT' => true,
        'BY' => true,
        'DAN' => true,
        'DI' => true,
        'FOR' => true,
        'FROM' => true,
        'IN' => true,
        'KE' => true,
        'OF' => true,
        'ON' => true,
        'OR' => true,
        'THE' => true,
        'TO' => true,
        'UNTUK' => true,
        'WITH' => true,
        'YANG' => true,
        'ORDER' => true,
        'PESANAN' => true,
        'RESI' => true,
        'AWB' => true,
        'QTY' => true,
        'QUANTITY' => true,
        'JUMLAH' => true,
        'PCS' => true,
        'PC' => true,
        'PACK' => true,
        'SHOPEE' => true,
        'SPX' => true,
        'TIKTOK' => true,
        'SHOP' => true,
        'EXPRESS' => true,
        'PENERIMA' => true,
        'RECIPIENT' => true,
        'BUYER' => true,
        'CUSTOMER' => true,
        'NAMA' => true,
    ];

    return array_values(array_unique(array_filter(explode(' ', $normalized), static function (string $token) use ($stopwords): bool {
        return strlen($token) >= 2 && !isset($stopwords[$token]);
    })));
}

function jg_partner_order_token_positions(string $text): array
{
    $positions = [];
    foreach (jg_partner_order_tokenize($text) as $index => $token) {
        $positions[$token][] = $index;
    }

    return $positions;
}

function jg_partner_order_add_phrase(array &$phrases, string $kind, mixed $value, float $weight, bool $core = true): void
{
    $normalized = jg_partner_order_normalize_search_text($value);
    $tokens = jg_partner_order_tokenize($normalized);
    if ($normalized === '' || $tokens === []) {
        return;
    }

    $key = $kind . ':' . $normalized;
    $currentWeight = (float) ($phrases[$key]['weight'] ?? 0);
    if ($currentWeight >= $weight) {
        return;
    }

    $phrases[$key] = [
        'kind' => $kind,
        'text' => $normalized,
        'tokens' => $tokens,
        'weight' => $weight,
        'core' => $core,
    ];
}

function jg_partner_order_sku_phrases(array $sku): array
{
    $phrases = [];
    $product = trim((string) ($sku['product_name'] ?? ''));
    $baseProduct = trim((string) ($sku['base_product_name'] ?? ''));
    $flavor = trim((string) ($sku['flavor_name'] ?? ''));
    $brand = trim((string) ($sku['brand_name'] ?? ''));
    $size = trim((string) ($sku['size_label'] ?? ''));

    jg_partner_order_add_phrase($phrases, 'product', $product, 12, true);
    jg_partner_order_add_phrase($phrases, 'base_product', $baseProduct, 11, true);
    if ($product !== '' && $flavor !== '' && stripos($product, $flavor) === false) {
        jg_partner_order_add_phrase($phrases, 'product_flavor', $product . ' ' . $flavor, 14, true);
    }
    if ($baseProduct !== '' && $flavor !== '' && stripos($baseProduct, $flavor) === false) {
        jg_partner_order_add_phrase($phrases, 'product_flavor', $baseProduct . ' ' . $flavor, 13, true);
    }
    jg_partner_order_add_phrase($phrases, 'flavor', $flavor, 7, true);
    jg_partner_order_add_phrase($phrases, 'size', $size, 5, false);
    jg_partner_order_add_phrase($phrases, 'brand', $brand, 2, false);

    foreach ((array) ($sku['item_tags'] ?? []) as $tag) {
        jg_partner_order_add_phrase($phrases, 'item_tag', $tag, 13, true);
    }

    $skuTag = trim((string) ($sku['tag'] ?? ''));
    if ($skuTag !== '') {
        jg_partner_order_add_phrase($phrases, 'item_tag', str_replace('_', ' ', $skuTag), 8, true);
    }

    // Weak fallbacks only. Shipping labels usually do not contain internal SKUs,
    // so these never satisfy the core match gate by themselves.
    jg_partner_order_add_phrase($phrases, 'sku_fallback', $sku['sku'] ?? '', 1.2, false);
    jg_partner_order_add_phrase($phrases, 'label_fallback', $sku['label'] ?? '', 1.2, false);

    return array_values($phrases);
}

function jg_partner_order_phrase_match_score(array $phrase, string $normalizedText, array $labelTokens): array
{
    $tokens = array_values((array) ($phrase['tokens'] ?? []));
    if ($tokens === []) {
        return ['score' => 0.0, 'coverage' => 0.0, 'exact' => false];
    }

    $hits = 0;
    foreach ($tokens as $token) {
        if (isset($labelTokens[$token])) {
            $hits++;
        }
    }

    $coverage = $hits / max(1, count($tokens));
    $weight = (float) ($phrase['weight'] ?? 0);
    $phraseText = (string) ($phrase['text'] ?? '');
    $exact = $phraseText !== '' && str_contains($normalizedText, $phraseText);

    if ($exact) {
        return [
            'score' => $weight * (1.35 + (0.28 * count($tokens))),
            'coverage' => 1.0,
            'exact' => true,
        ];
    }

    if ($coverage >= 0.72 && count($tokens) >= 2) {
        return [
            'score' => $weight * $coverage,
            'coverage' => $coverage,
            'exact' => false,
        ];
    }

    if ($coverage >= 1.0 && count($tokens) === 1 && ($phrase['core'] ?? false)) {
        return [
            'score' => $weight * 0.64,
            'coverage' => $coverage,
            'exact' => false,
        ];
    }

    return ['score' => 0.0, 'coverage' => $coverage, 'exact' => false];
}

function jg_partner_order_match_sku_against_label(array $sku, string $normalizedText, array $labelTokens): array
{
    $score = 0.0;
    $coreScore = 0.0;
    $bestPhrase = '';
    $bestPhraseScore = 0.0;
    $evidence = [];
    $profileWeights = [];

    foreach (jg_partner_order_sku_phrases($sku) as $phrase) {
        foreach ((array) ($phrase['tokens'] ?? []) as $token) {
            $profileWeights[$token] = max((float) ($profileWeights[$token] ?? 0), (float) ($phrase['weight'] ?? 0));
        }

        $match = jg_partner_order_phrase_match_score($phrase, $normalizedText, $labelTokens);
        $phraseScore = (float) ($match['score'] ?? 0);
        if ($phraseScore <= 0) {
            continue;
        }

        $score += $phraseScore;
        if (($phrase['core'] ?? false) && !in_array((string) ($phrase['kind'] ?? ''), ['sku_fallback', 'label_fallback'], true)) {
            $coreScore += $phraseScore;
        }

        if ($phraseScore > $bestPhraseScore) {
            $bestPhrase = (string) ($phrase['text'] ?? '');
            $bestPhraseScore = $phraseScore;
        }

        $evidence[] = [
            'kind' => (string) ($phrase['kind'] ?? 'phrase'),
            'phrase' => (string) ($phrase['text'] ?? ''),
            'coverage' => round((float) ($match['coverage'] ?? 0), 2),
            'exact' => (bool) ($match['exact'] ?? false),
        ];
    }

    $weightedHits = 0.0;
    $totalWeight = 0.0;
    foreach ($profileWeights as $token => $weight) {
        $totalWeight += $weight;
        if (isset($labelTokens[$token])) {
            $weightedHits += $weight;
        }
    }
    if ($totalWeight > 0 && $weightedHits > 0) {
        $coverage = $weightedHits / $totalWeight;
        $score += $weightedHits * 0.45 + ($coverage * 7);
    }

    return [
        'score' => $score,
        'core_score' => $coreScore,
        'matched_phrase' => $bestPhrase,
        'evidence' => $evidence,
    ];
}

function jg_partner_order_infer_platform(string $text): array
{
    $upper = jg_partner_order_normalize_search_text($text);
    $signals = [
        'Shopee' => [
            'SHOPEE' => 5,
            'SPX' => 3,
            'SHOPEE EXPRESS' => 5,
            'NO RESI' => 2,
            'NO PESANAN' => 2,
            'PESANAN' => 1,
            'AIR WAYBILL' => 2,
            'AWB' => 2,
        ],
        'TikTok Shop' => [
            'TIKTOK' => 5,
            'TIKTOK SHOP' => 6,
            'SELLER SKU' => 3,
            'SKU ID' => 3,
            'PACKAGE ID' => 3,
            'ORDER ID' => 2,
            'TTS' => 2,
        ],
    ];

    $scores = [];
    $reasons = [];
    foreach ($signals as $platform => $platformSignals) {
        $scores[$platform] = 0;
        foreach ($platformSignals as $signal => $weight) {
            if (str_contains($upper, $signal)) {
                $scores[$platform] += $weight;
                $reasons[] = $signal;
            }
        }
    }

    arsort($scores);
    $bestPlatform = (string) array_key_first($scores);
    $bestScore = (int) ($scores[$bestPlatform] ?? 0);
    $secondScore = 0;
    foreach ($scores as $platform => $score) {
        if ($platform !== $bestPlatform) {
            $secondScore = (int) $score;
            break;
        }
    }

    if ($bestScore <= 0) {
        return [
            'platform' => 'Needs review',
            'confidence' => 0.28,
            'score' => 0,
            'reasons' => ['No marketplace keyword found'],
        ];
    }

    if ($bestScore === $secondScore) {
        return [
            'platform' => 'Needs review',
            'confidence' => 0.52,
            'score' => $bestScore,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    return [
        'platform' => $bestPlatform,
        'confidence' => min(0.98, 0.48 + ($bestScore * 0.06) + (($bestScore - $secondScore) * 0.025)),
        'score' => $bestScore,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

function jg_partner_order_quantity_near_alias(string $normalizedText, string $alias): int
{
    $position = strpos($normalizedText, $alias);
    if ($position === false) {
        return 1;
    }

    $before = substr($normalizedText, max(0, $position - 42), 42);
    $after = substr($normalizedText, $position + strlen($alias), 64);
    $patterns = [
        [$after, '/^\s*(?:X|\*)\s*([0-9]{1,3})\b/'],
        [$after, '/^\s*(?:QTY|JUMLAH|QUANTITY)\s*[:\-]?\s*([0-9]{1,3})\b/'],
        [$after, '/^\s*([0-9]{1,3})\s*(?:PCS|PC|PCK|PACK)\b/'],
        [$before, '/(?:QTY|JUMLAH|QUANTITY)\s*[:\-]?\s*([0-9]{1,3})\s*$/'],
        [$before, '/\b([0-9]{1,3})\s*(?:PCS|PC|PCK|PACK)\s*$/'],
    ];

    foreach ($patterns as [$window, $pattern]) {
        if (preg_match($pattern, $window, $matches)) {
            return max(1, min(999, (int) $matches[1]));
        }
    }

    return 1;
}

function jg_partner_order_infer_customer_name(string $text): string
{
    $flat = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($flat === '') {
        return '';
    }

    $patterns = [
        '/(?:PENERIMA|RECIPIENT|BUYER|CUSTOMER|NAMA)\s*[:\-]?\s*([A-Z0-9][A-Z0-9 ._\-]{2,48})/i',
        '/(?:TO|KEPADA)\s*[:\-]?\s*([A-Z0-9][A-Z0-9 ._\-]{2,48})/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $flat, $matches)) {
            continue;
        }

        $candidate = trim((string) ($matches[1] ?? ''));
        $candidate = preg_replace('/\b(?:TEL|PHONE|HP|ALAMAT|ADDRESS|ORDER|SKU|QTY)\b.*$/i', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B-:");
        if ($candidate !== '') {
            return mb_substr($candidate, 0, 80);
        }
    }

    return '';
}

function jg_partner_order_infer_items(?array $partner, string $text): array
{
    $allowed = jg_partner_order_allowed_sku_index($partner);
    $normalizedText = jg_partner_order_normalize_search_text($text);
    $labelTokens = jg_partner_order_token_positions($text);
    $matches = [];

    foreach ($allowed as $skuCode => $sku) {
        $match = jg_partner_order_match_sku_against_label($sku, $normalizedText, $labelTokens);
        $score = (float) ($match['score'] ?? 0);
        $coreScore = (float) ($match['core_score'] ?? 0);
        $matchedAlias = (string) ($match['matched_phrase'] ?? '');

        if ($score < 12 || $coreScore < 7) {
            continue;
        }

        $quantity = $matchedAlias !== '' ? jg_partner_order_quantity_near_alias($normalizedText, $matchedAlias) : 1;
        $unitRevenue = max(0.0, (float) ($sku['partner_price'] ?? 0));
        $matches[] = [
            'sku_code' => $skuCode,
            'sku_label' => $sku['label'],
            'brand' => $sku['brand_name'],
            'product' => $sku['product_name'],
            'flavor' => $sku['flavor_name'],
            'size' => $sku['size_label'],
            'quantity' => $quantity,
            'unit_revenue' => $unitRevenue,
            'line_revenue' => $unitRevenue * $quantity,
            'match_score' => round($score, 2),
            'match_confidence' => min(0.99, round(0.38 + ($score / 36) + min(0.2, $coreScore / 60), 2)),
            'matched_alias' => $matchedAlias,
            'match_evidence' => array_slice((array) ($match['evidence'] ?? []), 0, 5),
        ];
    }

    usort($matches, static fn (array $left, array $right): int => ($right['match_score'] <=> $left['match_score']) ?: strcmp((string) $left['sku_code'], (string) $right['sku_code']));

    $deduped = [];
    foreach ($matches as $match) {
        $skuCode = (string) ($match['sku_code'] ?? '');
        if ($skuCode === '' || isset($deduped[$skuCode])) {
            continue;
        }
        $deduped[$skuCode] = $match;
    }

    return array_values($deduped);
}

function jg_partner_order_normalize_timestamp(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return gmdate(DATE_ATOM);
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Order timestamp is invalid.');
    }

    return gmdate(DATE_ATOM, $timestamp);
}

function jg_partner_order_normalize_deadline_hours(mixed $value): int
{
    $hours = (int) $value;
    if ($hours <= 0) {
        return 24;
    }

    return max(1, min(48, $hours));
}

function jg_partner_order_deadline_at(string $orderTimestamp, int $deadlineHours): string
{
    $timestamp = strtotime($orderTimestamp);
    if ($timestamp === false) {
        $timestamp = time();
    }

    return gmdate(DATE_ATOM, $timestamp + ($deadlineHours * 3600));
}

function jg_partner_order_normalize_marketplace_platform(mixed $value): string
{
    $raw = trim((string) $value);
    $normalized = strtolower(preg_replace('/\s+/', ' ', $raw) ?? '');
    if ($normalized === '') {
        return 'Needs review';
    }
    if (str_contains($normalized, 'shopee') || $normalized === 'spx') {
        return 'Shopee';
    }
    if (str_contains($normalized, 'tiktok') || str_contains($normalized, 'tik tok')) {
        return 'TikTok Shop';
    }

    return mb_substr($raw, 0, 32);
}

function jg_partner_order_normalize_inference(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return [
        'platform' => is_array($value['platform'] ?? null) ? $value['platform'] : [],
        'items' => array_values(array_filter((array) ($value['items'] ?? []), 'is_array')),
        'customer_name' => mb_substr(trim((string) ($value['customer_name'] ?? '')), 0, 120),
        'label_excerpt' => mb_substr(trim((string) ($value['label_excerpt'] ?? '')), 0, 900),
        'analyzed_at' => trim((string) ($value['analyzed_at'] ?? gmdate(DATE_ATOM))) ?: gmdate(DATE_ATOM),
    ];
}

function jg_partner_order_normalize_items(?array $partner, mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('Upload a label that matches at least one partner product.');
    }

    $items = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sku = jg_partner_order_validate_sku($partner, $item['sku_code'] ?? null);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitRevenue = max(0.0, (float) ($item['unit_revenue'] ?? $item['partner_price'] ?? $sku['partner_price'] ?? 0));

        $items[] = [
            'sku_code' => $sku['sku'],
            'sku_label' => $sku['label'],
            'brand' => $sku['brand_name'],
            'product' => $sku['product_name'],
            'flavor' => $sku['flavor_name'],
            'size' => $sku['size_label'],
            'quantity' => $quantity,
            'unit_revenue' => $unitRevenue,
            'line_revenue' => $unitRevenue * $quantity,
            'match_confidence' => max(0.0, min(1.0, (float) ($item['match_confidence'] ?? 0))),
            'match_score' => max(0.0, (float) ($item['match_score'] ?? 0)),
            'matched_alias' => trim((string) ($item['matched_alias'] ?? '')),
            'match_evidence' => array_slice(array_values(array_filter((array) ($item['match_evidence'] ?? []), 'is_array')), 0, 5),
        ];
    }

    if ($items === []) {
        throw new InvalidArgumentException('Upload a label that matches at least one partner product.');
    }

    return $items;
}

function jg_partner_order_item_summary(array $items): array
{
    $first = $items[0] ?? [];
    $productNames = array_values(array_unique(array_filter(array_map(
        static fn (array $item): string => trim((string) ($item['product'] ?? '')),
        $items
    ))));

    return [
        'brand' => (string) ($first['brand'] ?? ''),
        'product' => $productNames === [] ? (string) ($first['product'] ?? '') : implode(', ', $productNames),
        'sku_code' => (string) ($first['sku_code'] ?? ''),
        'sku_label' => count($items) === 1 ? (string) ($first['sku_label'] ?? '') : count($items) . ' invoice items',
        'flavor' => (string) ($first['flavor'] ?? ''),
        'size' => (string) ($first['size'] ?? ''),
        'quantity' => array_sum(array_map(static fn (array $item): int => max(1, (int) ($item['quantity'] ?? 1)), $items)),
    ];
}

function jg_partner_order_normalize_text(mixed $value, string $label, int $maxLength = 160, bool $required = true): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if ($normalized === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }

        return '';
    }

    if (mb_strlen($normalized) > $maxLength) {
        throw new InvalidArgumentException($label . ' is too long.');
    }

    return $normalized;
}

function jg_partner_order_build_record(string $partnerCode, ?array $partner, array $payload, ?array $existing = null): array
{
    $items = jg_partner_order_normalize_items($partner, $payload['items'] ?? []);
    $summary = jg_partner_order_item_summary($items);
    $createdAt = (string) ($existing['created_at'] ?? gmdate(DATE_ATOM));
    $labelRecords = array_values(array_filter((array) ($existing['labels'] ?? []), 'is_array'));
    $orderTimestamp = jg_partner_order_normalize_timestamp($payload['order_timestamp'] ?? ($existing['order_timestamp'] ?? $createdAt));
    $deadlineHours = jg_partner_order_normalize_deadline_hours($payload['deadline_hours'] ?? ($existing['deadline_hours'] ?? 24));
    $deadlineAt = jg_partner_order_deadline_at($orderTimestamp, $deadlineHours);
    $inference = jg_partner_order_normalize_inference($payload['inference'] ?? ($existing['inference'] ?? []));
    $customerName = jg_partner_order_normalize_text($payload['customer_name'] ?? ($inference['customer_name'] ?? ''), 'Customer name', 160, false);
    if ($customerName === '') {
        $customerName = 'Label recipient';
    }
    $revenueTotal = array_reduce($items, static fn (float $sum, array $item): float => $sum + max(0.0, (float) ($item['line_revenue'] ?? 0)), 0.0);

    return [
        'id' => (string) ($existing['id'] ?? ('PO' . gmdate('ymd') . strtoupper(substr(sha1($partnerCode . microtime(true) . random_int(1000, 9999)), 0, 8)))),
        'partner_code' => $partnerCode,
        'customer_name' => $customerName,
        'brand' => $summary['brand'],
        'product' => $summary['product'],
        'sku_code' => $summary['sku_code'],
        'sku_label' => $summary['sku_label'],
        'flavor' => $summary['flavor'],
        'size' => $summary['size'],
        'quantity' => $summary['quantity'],
        'items' => $items,
        'order_timestamp' => $orderTimestamp,
        'deadline_hours' => $deadlineHours,
        'deadline_at' => $deadlineAt,
        'marketplace_platform' => jg_partner_order_normalize_marketplace_platform($payload['marketplace_platform'] ?? ($inference['platform']['platform'] ?? $existing['marketplace_platform'] ?? 'Needs review')),
        'revenue_total' => $revenueTotal,
        'inference' => $inference,
        'notes' => jg_partner_order_normalize_text($payload['notes'] ?? '', 'Notes', 300, false),
        'status' => trim((string) ($existing['status'] ?? 'IS_LISTED')) ?: 'IS_LISTED',
        'archived_at' => (string) ($existing['archived_at'] ?? ''),
        'created_at' => $createdAt,
        'updated_at' => gmdate(DATE_ATOM),
        'labels' => $labelRecords,
    ];
}

function jg_partner_order_fetch_labels_mysql(PDO $pdo, string $partnerCode): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, original_name, stored_name, relative_path, mime_type, size_bytes, created_at
         FROM partner_order_labels
         WHERE partner_code = :partner_code
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':partner_code' => $partnerCode]);

    $labelsByOrder = [];
    foreach ($stmt->fetchAll() as $row) {
        $orderId = (string) ($row['order_id'] ?? '');
        if ($orderId === '') {
            continue;
        }

        $relativePath = trim((string) ($row['relative_path'] ?? ''));
        $labelsByOrder[$orderId][] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['original_name'] ?? ''),
            'stored_name' => (string) ($row['stored_name'] ?? ''),
            'path' => $relativePath,
            'url' => $relativePath !== '' ? '../' . ltrim($relativePath, '/') : '',
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_partner_order_attach_labels(array $orders, array $labelsByOrder): array
{
    foreach ($orders as &$order) {
        $orderId = (string) ($order['id'] ?? '');
        $order['labels'] = array_values(array_filter($labelsByOrder[$orderId] ?? (array) ($order['labels'] ?? []), 'is_array'));
        $order['label_count'] = count($order['labels']);
    }
    unset($order);

    return $orders;
}

function jg_partner_order_list(string $partnerCode): array
{
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, marketplace_platform, deadline_hours, deadline_at, revenue_total, inference_json, items_json, archived_at, created_at, updated_at
             FROM partner_orders
             WHERE partner_code = :partner_code
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':partner_code' => $partnerCode]);

        $orders = [];
        foreach ($stmt->fetchAll() as $row) {
            $items = json_decode((string) ($row['items_json'] ?? ''), true);
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            if ($items === []) {
                $items = [[
                    'sku_code' => (string) ($row['sku_code'] ?? ''),
                    'sku_label' => (string) ($row['sku_label'] ?? ''),
                    'brand' => (string) ($row['brand_name'] ?? ''),
                    'product' => (string) ($row['product_name'] ?? ''),
                    'quantity' => (int) ($row['quantity'] ?? 1),
                ]];
            }
            $inference = json_decode((string) ($row['inference_json'] ?? ''), true);

            $orders[] = [
                'id' => (string) ($row['id'] ?? ''),
                'partner_code' => (string) ($row['partner_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'brand' => (string) ($row['brand_name'] ?? ''),
                'product' => (string) ($row['product_name'] ?? ''),
                'sku_code' => (string) ($row['sku_code'] ?? ''),
                'sku_label' => (string) ($row['sku_label'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 1),
                'items' => $items,
                'order_timestamp' => (string) ($row['order_timestamp'] ?? ''),
                'deadline_hours' => (int) ($row['deadline_hours'] ?? 24),
                'deadline_at' => (string) ($row['deadline_at'] ?? ''),
                'marketplace_platform' => (string) ($row['marketplace_platform'] ?? ''),
                'revenue_total' => (float) ($row['revenue_total'] ?? 0),
                'inference' => is_array($inference) ? $inference : [],
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'IS_LISTED'),
                'archived_at' => (string) ($row['archived_at'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return jg_partner_order_attach_labels($orders, jg_partner_order_fetch_labels_mysql($pdo, $partnerCode));
    }

    $database = jg_partner_order_read_json_database();
    $orders = array_values(array_filter(
        $database['orders'],
        static fn (array $order): bool => (string) ($order['partner_code'] ?? '') === $partnerCode
    ));

    foreach ($orders as &$order) {
        if (!isset($order['items']) || !is_array($order['items'])) {
            $order['items'] = [[
                'sku_code' => (string) ($order['sku_code'] ?? ''),
                'sku_label' => (string) ($order['sku_label'] ?? ''),
                'brand' => (string) ($order['brand'] ?? ''),
                'product' => (string) ($order['product'] ?? ''),
                'quantity' => (int) ($order['quantity'] ?? 1),
            ]];
        }
        $order['order_timestamp'] = (string) ($order['order_timestamp'] ?? $order['created_at'] ?? '');
        $order['deadline_hours'] = (int) ($order['deadline_hours'] ?? 24);
        $order['deadline_at'] = (string) ($order['deadline_at'] ?? jg_partner_order_deadline_at((string) $order['order_timestamp'], (int) $order['deadline_hours']));
        $order['marketplace_platform'] = (string) ($order['marketplace_platform'] ?? '');
        $order['revenue_total'] = (float) ($order['revenue_total'] ?? 0);
        $order['inference'] = is_array($order['inference'] ?? null) ? $order['inference'] : [];
        $order['archived_at'] = (string) ($order['archived_at'] ?? '');
    }
    unset($order);

    return jg_partner_order_attach_labels($orders, []);
}

function jg_partner_order_fetch_all_labels_mysql(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, order_id, partner_code, original_name, stored_name, relative_path, mime_type, size_bytes, created_at
         FROM partner_order_labels
         ORDER BY created_at DESC, id DESC'
    );

    $labelsByOrder = [];
    foreach ($stmt->fetchAll() as $row) {
        $orderId = (string) ($row['order_id'] ?? '');
        if ($orderId === '') {
            continue;
        }

        $relativePath = trim((string) ($row['relative_path'] ?? ''));
        $labelsByOrder[$orderId][] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['original_name'] ?? ''),
            'stored_name' => (string) ($row['stored_name'] ?? ''),
            'path' => $relativePath,
            'url' => $relativePath !== '' ? '../' . ltrim($relativePath, '/') : '',
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_partner_order_list_all(): array
{
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->query(
            'SELECT id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, marketplace_platform, deadline_hours, deadline_at, revenue_total, inference_json, items_json, archived_at, created_at, updated_at
             FROM partner_orders
             ORDER BY created_at DESC, id DESC'
        );

        $orders = [];
        foreach ($stmt->fetchAll() as $row) {
            $items = json_decode((string) ($row['items_json'] ?? ''), true);
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            if ($items === []) {
                $items = [[
                    'sku_code' => (string) ($row['sku_code'] ?? ''),
                    'sku_label' => (string) ($row['sku_label'] ?? ''),
                    'brand' => (string) ($row['brand_name'] ?? ''),
                    'product' => (string) ($row['product_name'] ?? ''),
                    'quantity' => (int) ($row['quantity'] ?? 1),
                ]];
            }
            $inference = json_decode((string) ($row['inference_json'] ?? ''), true);

            $orders[] = [
                'id' => (string) ($row['id'] ?? ''),
                'partner_code' => (string) ($row['partner_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'brand' => (string) ($row['brand_name'] ?? ''),
                'product' => (string) ($row['product_name'] ?? ''),
                'sku_code' => (string) ($row['sku_code'] ?? ''),
                'sku_label' => (string) ($row['sku_label'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 1),
                'items' => $items,
                'order_timestamp' => (string) ($row['order_timestamp'] ?? ''),
                'deadline_hours' => (int) ($row['deadline_hours'] ?? 24),
                'deadline_at' => (string) ($row['deadline_at'] ?? ''),
                'marketplace_platform' => (string) ($row['marketplace_platform'] ?? ''),
                'revenue_total' => (float) ($row['revenue_total'] ?? 0),
                'inference' => is_array($inference) ? $inference : [],
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'IS_LISTED'),
                'archived_at' => (string) ($row['archived_at'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return jg_partner_order_attach_labels($orders, jg_partner_order_fetch_all_labels_mysql($pdo));
    }

    $database = jg_partner_order_read_json_database();
    $orders = array_values(array_filter($database['orders'], 'is_array'));
    foreach ($orders as &$order) {
        $order['order_timestamp'] = (string) ($order['order_timestamp'] ?? $order['created_at'] ?? '');
        $order['deadline_hours'] = (int) ($order['deadline_hours'] ?? 24);
        $order['deadline_at'] = (string) ($order['deadline_at'] ?? jg_partner_order_deadline_at((string) $order['order_timestamp'], (int) $order['deadline_hours']));
        $order['marketplace_platform'] = (string) ($order['marketplace_platform'] ?? '');
        $order['revenue_total'] = (float) ($order['revenue_total'] ?? 0);
        $order['inference'] = is_array($order['inference'] ?? null) ? $order['inference'] : [];
    }
    unset($order);
    return jg_partner_order_attach_labels($orders, []);
}

function jg_partner_order_find(string $partnerCode, string $orderId): ?array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return null;
    }

    foreach (jg_partner_order_list($partnerCode) as $order) {
        if ((string) ($order['id'] ?? '') === $orderId) {
            return $order;
        }
    }

    return null;
}

function jg_partner_order_is_editable(array $order): bool
{
    $status = strtoupper(trim((string) ($order['status'] ?? 'IS_LISTED')));
    return $status === '' || in_array($status, ['LISTED', 'IS_LISTED'], true);
}

function jg_partner_order_is_archived(array $order): bool
{
    return trim((string) ($order['archived_at'] ?? '')) !== '';
}

function jg_partner_order_assert_editable(array $order): void
{
    if (!jg_partner_order_is_editable($order)) {
        throw new InvalidArgumentException('Only IS_LISTED partner orders can be canceled.');
    }
}

function jg_partner_order_cancel(string $partnerCode, string $orderId): array
{
    $normalizedId = jg_partner_order_normalize_text($orderId, 'Order id');
    $existing = jg_partner_order_find($partnerCode, $normalizedId);
    if (!is_array($existing)) {
        throw new RuntimeException('Order not found.');
    }
    jg_partner_order_assert_editable($existing);

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'UPDATE partner_orders
             SET status = :status, updated_at = :updated_at
             WHERE id = :id AND partner_code = :partner_code'
        );
        $stmt->execute([
            ':status' => 'CANCELLED',
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $normalizedId,
            ':partner_code' => $partnerCode,
        ]);

        return jg_partner_order_find($partnerCode, $normalizedId) ?? array_merge($existing, [
            'status' => 'CANCELLED',
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as $index => $order) {
        if ((string) ($order['id'] ?? '') !== $normalizedId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        jg_partner_order_assert_editable($order);
        $database['orders'][$index]['status'] = 'CANCELLED';
        $database['orders'][$index]['updated_at'] = gmdate(DATE_ATOM);
        jg_partner_order_write_json_database($database);
        return $database['orders'][$index];
    }

    throw new RuntimeException('Order not found.');
}

function jg_partner_order_set_archived(string $partnerCode, string $orderId, bool $archived): array
{
    $normalizedId = jg_partner_order_normalize_text($orderId, 'Order id');
    $existing = jg_partner_order_find($partnerCode, $normalizedId);
    if (!is_array($existing)) {
        throw new RuntimeException('Order not found.');
    }

    $archivedAt = $archived ? gmdate(DATE_ATOM) : '';
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'UPDATE partner_orders
             SET archived_at = :archived_at, updated_at = :updated_at
             WHERE id = :id AND partner_code = :partner_code'
        );
        $stmt->execute([
            ':archived_at' => $archived ? gmdate('Y-m-d H:i:s', strtotime($archivedAt)) : null,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $normalizedId,
            ':partner_code' => $partnerCode,
        ]);

        return jg_partner_order_find($partnerCode, $normalizedId) ?? array_merge($existing, [
            'archived_at' => $archivedAt,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as $index => $order) {
        if ((string) ($order['id'] ?? '') !== $normalizedId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        $database['orders'][$index]['archived_at'] = $archivedAt;
        $database['orders'][$index]['updated_at'] = gmdate(DATE_ATOM);
        jg_partner_order_write_json_database($database);
        return $database['orders'][$index];
    }

    throw new RuntimeException('Order not found.');
}

function jg_partner_order_save(string $partnerCode, ?array $partner, array $payload, string $action): array
{
    if ($action !== 'create' && $action !== 'update') {
        throw new InvalidArgumentException('Unknown action.');
    }
    if ($action === 'update') {
        throw new InvalidArgumentException('Partner orders cannot be edited after creation. Cancel the IS_LISTED order and upload a new label.');
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        if ($action === 'create') {
            $record = jg_partner_order_build_record($partnerCode, $partner, $payload);
            $stmt = $pdo->prepare(
                'INSERT INTO partner_orders
                    (id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, marketplace_platform, deadline_hours, deadline_at, revenue_total, inference_json, items_json, archived_at, created_at, updated_at)
                 VALUES
                    (:id, :partner_code, :customer_name, :brand_name, :product_name, :sku_code, :sku_label, :quantity, :notes, :status, :order_timestamp, :marketplace_platform, :deadline_hours, :deadline_at, :revenue_total, :inference_json, :items_json, :archived_at, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':id' => $record['id'],
                ':partner_code' => $record['partner_code'],
                ':customer_name' => $record['customer_name'],
                ':brand_name' => $record['brand'],
                ':product_name' => $record['product'],
                ':sku_code' => $record['sku_code'],
                ':sku_label' => $record['sku_label'],
                ':quantity' => $record['quantity'],
                ':notes' => $record['notes'],
                ':status' => $record['status'],
                ':order_timestamp' => gmdate('Y-m-d H:i:s', strtotime($record['order_timestamp'])),
                ':marketplace_platform' => $record['marketplace_platform'],
                ':deadline_hours' => $record['deadline_hours'],
                ':deadline_at' => gmdate('Y-m-d H:i:s', strtotime($record['deadline_at'])),
                ':revenue_total' => number_format((float) ($record['revenue_total'] ?? 0), 2, '.', ''),
                ':inference_json' => json_encode($record['inference'], JSON_UNESCAPED_SLASHES),
                ':items_json' => json_encode($record['items'], JSON_UNESCAPED_SLASHES),
                ':archived_at' => trim((string) ($record['archived_at'] ?? '')) !== '' ? gmdate('Y-m-d H:i:s', strtotime((string) $record['archived_at'])) : null,
                ':created_at' => gmdate('Y-m-d H:i:s', strtotime($record['created_at'])),
                ':updated_at' => gmdate('Y-m-d H:i:s', strtotime($record['updated_at'])),
            ]);

            return jg_partner_order_find($partnerCode, $record['id']) ?? $record;
        }

        $orderId = jg_partner_order_normalize_text($payload['id'] ?? '', 'Order id');
        $existing = jg_partner_order_find($partnerCode, $orderId);
        if (!is_array($existing)) {
            throw new RuntimeException('Order not found.');
        }
        jg_partner_order_assert_editable($existing);

        $record = jg_partner_order_build_record($partnerCode, $partner, $payload, $existing);
        $stmt = $pdo->prepare(
            'UPDATE partner_orders
             SET customer_name = :customer_name,
                 brand_name = :brand_name,
                 product_name = :product_name,
                 sku_code = :sku_code,
                 sku_label = :sku_label,
                 quantity = :quantity,
                 notes = :notes,
                 order_timestamp = :order_timestamp,
                 items_json = :items_json,
                 updated_at = :updated_at
             WHERE id = :id AND partner_code = :partner_code'
        );
        $stmt->execute([
            ':customer_name' => $record['customer_name'],
            ':brand_name' => $record['brand'],
            ':product_name' => $record['product'],
            ':sku_code' => $record['sku_code'],
            ':sku_label' => $record['sku_label'],
            ':quantity' => $record['quantity'],
            ':notes' => $record['notes'],
            ':order_timestamp' => gmdate('Y-m-d H:i:s', strtotime($record['order_timestamp'])),
            ':items_json' => json_encode($record['items'], JSON_UNESCAPED_SLASHES),
            ':updated_at' => gmdate('Y-m-d H:i:s', strtotime($record['updated_at'])),
            ':id' => $record['id'],
            ':partner_code' => $partnerCode,
        ]);

        return jg_partner_order_find($partnerCode, $orderId) ?? $record;
    }

    $database = jg_partner_order_read_json_database();

    if ($action === 'create') {
        $record = jg_partner_order_build_record($partnerCode, $partner, $payload);
        $database['orders'][] = $record;
        jg_partner_order_write_json_database($database);
        return $record;
    }

    $orderId = jg_partner_order_normalize_text($payload['id'] ?? '', 'Order id');
    foreach ($database['orders'] as $index => $order) {
        if ((string) ($order['id'] ?? '') !== $orderId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        jg_partner_order_assert_editable($order);
        $record = jg_partner_order_build_record($partnerCode, $partner, $payload, $order);
        $database['orders'][$index] = $record;
        jg_partner_order_write_json_database($database);
        return $record;
    }

    throw new RuntimeException('Order not found.');
}

function jg_partner_order_delete(string $partnerCode, string $orderId): void
{
    $normalizedId = jg_partner_order_normalize_text($orderId, 'Order id');
    $existing = jg_partner_order_find($partnerCode, $normalizedId);
    if (!is_array($existing)) {
        throw new RuntimeException('Order not found.');
    }
    jg_partner_order_assert_editable($existing);

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('DELETE FROM partner_orders WHERE id = :id AND partner_code = :partner_code');
        $stmt->execute([
            ':id' => $normalizedId,
            ':partner_code' => $partnerCode,
        ]);
        return;
    }

    $database = jg_partner_order_read_json_database();
    $database['orders'] = array_values(array_filter(
        $database['orders'],
        static fn (array $order): bool => !((string) ($order['id'] ?? '') === $normalizedId && (string) ($order['partner_code'] ?? '') === $partnerCode)
    ));
    jg_partner_order_write_json_database($database);
}

function jg_partner_order_set_status(string $orderId, string $status): bool
{
    $normalizedId = jg_partner_order_normalize_text($orderId, 'Order id');
    $normalizedStatus = strtoupper(trim($status));
    if (!in_array($normalizedStatus, ['IS_LISTED', 'IS_BEING_FULFILLED', 'FULFILLED', 'CANCELLED'], true)) {
        throw new InvalidArgumentException('Order status is invalid.');
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'UPDATE partner_orders
             SET status = :status, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $normalizedStatus,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $normalizedId,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM partner_orders WHERE id = :id AND status = :status');
        $checkStmt->execute([
            ':id' => $normalizedId,
            ':status' => $normalizedStatus,
        ]);
        return (int) $checkStmt->fetchColumn() > 0;
    }

    $database = jg_partner_order_read_json_database();
    $updated = false;
    foreach ($database['orders'] as &$order) {
        if ((string) ($order['id'] ?? '') !== $normalizedId) {
            continue;
        }
        $order['status'] = $normalizedStatus;
        $order['updated_at'] = gmdate(DATE_ATOM);
        $updated = true;
        break;
    }
    unset($order);

    if ($updated) {
        jg_partner_order_write_json_database($database);
    }

    return $updated;
}

function jg_partner_order_upload_directory(): string
{
    if (!is_dir(JG_PARTNER_LABEL_UPLOAD_DIR)) {
        mkdir(JG_PARTNER_LABEL_UPLOAD_DIR, 0775, true);
    }

    return JG_PARTNER_LABEL_UPLOAD_DIR;
}

function jg_partner_order_allowed_extensions(): array
{
    return ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'zpl', 'txt', 'prn'];
}

function jg_partner_order_normalize_uploaded_files(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return [[
            'name' => (string) ($files['name'] ?? ''),
            'type' => (string) ($files['type'] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'] ?? 0),
        ]];
    }

    $normalized = [];
    foreach ($names as $index => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'type' => (string) (($files['type'] ?? [])[$index] ?? ''),
            'tmp_name' => (string) (($files['tmp_name'] ?? [])[$index] ?? ''),
            'error' => (int) (($files['error'] ?? [])[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) (($files['size'] ?? [])[$index] ?? 0),
        ];
    }

    return $normalized;
}

function jg_partner_order_extract_readable_text(string $path, string $originalName): string
{
    $parts = [$originalName];
    $raw = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
    if (is_string($raw) && $raw !== '') {
        if (preg_match_all('/[A-Za-z0-9][A-Za-z0-9 .,:;_\/#()@+\-]{2,}/', $raw, $matches)) {
            $parts[] = implode(' ', array_slice($matches[0], 0, 1200));
        }
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
}

function jg_partner_order_analyze_label_text(?array $partner, string $labelText): array
{
    $platform = jg_partner_order_infer_platform($labelText);
    $items = jg_partner_order_infer_items($partner, $labelText);
    $customerName = jg_partner_order_infer_customer_name($labelText);

    return [
        'platform' => $platform,
        'items' => $items,
        'customer_name' => $customerName,
        'label_excerpt' => mb_substr($labelText, 0, 900),
        'analyzed_at' => gmdate(DATE_ATOM),
    ];
}

function jg_partner_order_analyze_uploaded_labels(?array $partner, array $files): array
{
    $uploadedFiles = jg_partner_order_normalize_uploaded_files($files);
    $firstFile = null;
    foreach ($uploadedFiles as $file) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $firstFile = $file;
        break;
    }

    if (!is_array($firstFile)) {
        throw new InvalidArgumentException('Select one label file.');
    }
    if ((int) ($firstFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The label failed to upload.');
    }

    $safeOriginal = trim((string) ($firstFile['name'] ?? ''));
    $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
    if ($safeOriginal === '' || !in_array($extension, jg_partner_order_allowed_extensions(), true)) {
        throw new RuntimeException('Unsupported file type. Use PDF, image, or label-print file formats.');
    }

    $labelText = jg_partner_order_extract_readable_text((string) ($firstFile['tmp_name'] ?? ''), $safeOriginal);
    return jg_partner_order_analyze_label_text($partner, $labelText);
}

function jg_partner_order_store_uploaded_labels(string $partnerCode, string $orderId, array $files): array
{
    $existingOrder = jg_partner_order_find($partnerCode, $orderId);
    if (!is_array($existingOrder)) {
        throw new RuntimeException('Order not found.');
    }
    if (!empty($existingOrder['labels'])) {
        throw new InvalidArgumentException('Delete the current shipping label before uploading another one.');
    }

    $uploadDir = jg_partner_order_upload_directory();
    $savedLabels = [];

    foreach (jg_partner_order_normalize_uploaded_files($files) as $file) {
        if ($savedLabels !== []) {
            throw new InvalidArgumentException('Upload only one shipping label per order.');
        }
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the uploaded files failed to upload.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $sizeBytes = (int) ($file['size'] ?? 0);
        $safeOriginal = trim((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));

        if ($safeOriginal === '') {
            throw new RuntimeException('Uploaded file name is invalid.');
        }
        if (!in_array($extension, jg_partner_order_allowed_extensions(), true)) {
            throw new RuntimeException('Unsupported file type. Use PDF, image, or label-print file formats.');
        }

        $storedName = sprintf(
            '%s-%s-%s.%s',
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($partnerCode)) ?: 'partner',
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($orderId)) ?: 'order',
            substr(sha1($safeOriginal . microtime(true) . random_int(1000, 9999)), 0, 12),
            $extension
        );

        $targetPath = rtrim($uploadDir, '/') . '/' . $storedName;
        if (!@move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Unable to save uploaded label.');
        }

        $mimeType = '';
        if (function_exists('mime_content_type')) {
            $mimeType = (string) @mime_content_type($targetPath);
        }

        $savedLabels[] = [
            'name' => $safeOriginal,
            'stored_name' => $storedName,
            'path' => 'uploads/shipping-labels/' . $storedName,
            'url' => '../uploads/shipping-labels/' . $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    if ($savedLabels === []) {
        return $existingOrder['labels'] ?? [];
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'INSERT INTO partner_order_labels
                (order_id, partner_code, original_name, stored_name, relative_path, mime_type, size_bytes, created_at)
             VALUES
                (:order_id, :partner_code, :original_name, :stored_name, :relative_path, :mime_type, :size_bytes, :created_at)'
        );

        foreach ($savedLabels as $label) {
            $stmt->execute([
                ':order_id' => $orderId,
                ':partner_code' => $partnerCode,
                ':original_name' => $label['name'],
                ':stored_name' => $label['stored_name'],
                ':relative_path' => $label['path'],
                ':mime_type' => $label['mime_type'],
                ':size_bytes' => $label['size_bytes'],
                ':created_at' => gmdate('Y-m-d H:i:s', strtotime($label['created_at'])),
            ]);
        }

        $fresh = jg_partner_order_find($partnerCode, $orderId);
        return $fresh['labels'] ?? [];
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as &$order) {
        if ((string) ($order['id'] ?? '') !== $orderId || (string) ($order['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }

        $existingLabels = array_values(array_filter((array) ($order['labels'] ?? []), 'is_array'));
        $order['labels'] = array_merge($existingLabels, $savedLabels);
        $order['updated_at'] = gmdate(DATE_ATOM);
        break;
    }
    unset($order);
    jg_partner_order_write_json_database($database);

    $fresh = jg_partner_order_find($partnerCode, $orderId);
    return $fresh['labels'] ?? [];
}

function jg_partner_order_analytics(array $orders): array
{
    $monthlyByYear = [];
    $hourlyBuckets = array_fill(0, 24, 0);

    foreach ($orders as $order) {
        if (jg_partner_order_is_archived($order)) {
            continue;
        }
        $timestamp = strtotime((string) ($order['order_timestamp'] ?? $order['created_at'] ?? ''));
        if ($timestamp === false) {
            continue;
        }

        $year = gmdate('Y', $timestamp);
        $monthIndex = (int) gmdate('n', $timestamp) - 1;
        if (!isset($monthlyByYear[$year])) {
            $monthlyByYear[$year] = array_fill(0, 12, 0);
        }
        if ($monthIndex >= 0 && $monthIndex < 12) {
            $monthlyByYear[$year][$monthIndex] += 1;
        }

        $hourIndex = (int) gmdate('G', $timestamp);
        $hourlyBuckets[$hourIndex] += 1;
    }

    ksort($monthlyByYear);

    $busiestHour = 0;
    $busiestCount = -1;
    foreach ($hourlyBuckets as $hour => $count) {
        if ($count > $busiestCount) {
            $busiestCount = $count;
            $busiestHour = $hour;
        }
    }

    return [
        'years' => array_values(array_keys($monthlyByYear)),
        'monthly_by_year' => $monthlyByYear,
        'hourly_distribution' => $hourlyBuckets,
        'busiest_hour' => sprintf('%02d:00', $busiestHour),
        'total_orders' => count($orders),
    ];
}

function jg_partner_order_delete_label(string $partnerCode, string $orderId): array
{
    $order = jg_partner_order_find($partnerCode, $orderId);
    if (!is_array($order)) {
        throw new RuntimeException('Order not found.');
    }

    foreach ((array) ($order['labels'] ?? []) as $label) {
        $path = __DIR__ . '/' . ltrim((string) ($label['path'] ?? ''), '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('DELETE FROM partner_order_labels WHERE order_id = :order_id AND partner_code = :partner_code');
        $stmt->execute([
            ':order_id' => $orderId,
            ':partner_code' => $partnerCode,
        ]);
        return [];
    }

    $database = jg_partner_order_read_json_database();
    foreach ($database['orders'] as &$storedOrder) {
        if ((string) ($storedOrder['id'] ?? '') !== $orderId || (string) ($storedOrder['partner_code'] ?? '') !== $partnerCode) {
            continue;
        }
        $storedOrder['labels'] = [];
        $storedOrder['updated_at'] = gmdate(DATE_ATOM);
        break;
    }
    unset($storedOrder);
    jg_partner_order_write_json_database($database);

    return [];
}
