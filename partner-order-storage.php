<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-data-bootstrap.php';

const JG_PARTNER_ORDER_JSON_FILE = __DIR__ . '/data/orders.json';
const JG_PARTNER_LEGACY_LABEL_UPLOAD_DIR = __DIR__ . '/uploads/shipping-labels';
const JG_PARTNER_LABEL_MAX_BYTES = 10 * 1024 * 1024;
const JG_PARTNER_LABEL_OPEN_RETENTION_SECONDS = 7 * 86400;
const JG_PARTNER_LABEL_FULFILLED_RETENTION_SECONDS = 3 * 86400;
const JG_PARTNER_LABEL_CANCELLED_RETENTION_SECONDS = 86400;
const JG_PARTNER_ARCHIVED_ORDER_RETENTION_SECONDS = 30 * 86400;

function jg_partner_order_private_storage_root(): string
{
    $configured = jg_partner_portal_config_value('JG_PARTNER_PRIVATE_STORAGE_DIR', 'partner_private_storage_dir');
    $root = $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : dirname(__DIR__) . '/partner-portal-private';
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Private label storage is unavailable.');
    }
    @chmod($root, 0700);
    return $root;
}

function jg_partner_order_label_storage_relative_path(string $storedName): string
{
    return 'shipping-labels/' . basename($storedName);
}

function jg_partner_order_label_expiration_for_status(string $status, ?int $now = null): string
{
    $now ??= time();
    $normalized = strtoupper(trim($status));
    $seconds = match ($normalized) {
        'FULFILLED' => JG_PARTNER_LABEL_FULFILLED_RETENTION_SECONDS,
        'CANCELLED' => JG_PARTNER_LABEL_CANCELLED_RETENTION_SECONDS,
        default => JG_PARTNER_LABEL_OPEN_RETENTION_SECONDS,
    };

    return gmdate(DATE_ATOM, $now + $seconds);
}

function jg_partner_order_label_is_expired(array $label, ?int $now = null): bool
{
    $now ??= time();
    $expiresAt = trim((string) ($label['expires_at'] ?? ''));
    if ($expiresAt === '') {
        $createdAt = strtotime((string) ($label['created_at'] ?? ''));
        $expiresAt = gmdate(DATE_ATOM, ($createdAt !== false ? $createdAt : $now) + JG_PARTNER_LABEL_OPEN_RETENTION_SECONDS);
    }
    $timestamp = strtotime($expiresAt);
    return $timestamp !== false && $timestamp <= $now;
}

function jg_partner_order_sign_store_download(string $orderId, int $expires, string $token): string
{
    return hash_hmac('sha256', trim($orderId) . "\n" . $expires, $token);
}

function jg_partner_order_verify_store_download(string $orderId, int $expires, string $signature, string $token, ?int $now = null): bool
{
    $now ??= time();
    if ($orderId === '' || $token === '' || $signature === '' || $expires < $now || $expires > $now + 600) {
        return false;
    }
    return hash_equals(jg_partner_order_sign_store_download($orderId, $expires, $token), strtolower($signature));
}

function jg_partner_order_label_file_candidates(array $label): array
{
    $storedName = basename(trim((string) ($label['stored_name'] ?? $label['path'] ?? '')));
    if ($storedName === '' || $storedName === '.' || $storedName === '..') {
        return [];
    }

    return [
        jg_partner_order_private_storage_root() . '/shipping-labels/' . $storedName,
        JG_PARTNER_LEGACY_LABEL_UPLOAD_DIR . '/' . $storedName,
    ];
}

function jg_partner_order_label_file_path(array $label): ?string
{
    foreach (jg_partner_order_label_file_candidates($label) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

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

        $volume = is_numeric($sku['volume'] ?? null) ? (float) $sku['volume'] : 0.0;
        $astraValue = is_numeric($sku['astra_value'] ?? $sku['astra'] ?? null) ? (float) ($sku['astra_value'] ?? $sku['astra']) : 0.0;
        $unitCount = ($volume > 0 && $astraValue > 0) ? max(1.0, round($volume / $astraValue, 4)) : max(1.0, (float) ($sku['unit_count'] ?? 1));
        $partnerUnitPrice = max(0.0, (float) ($sku['partner_unit_price'] ?? $pricing[$skuCode] ?? 0));
        $partnerSkuPrice = max(0.0, (float) ($sku['partner_price'] ?? ($partnerUnitPrice * $unitCount)));

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
            'volume' => $volume,
            'astra_value' => $astraValue,
            'unit_count' => $unitCount,
            'partner_unit_price' => $partnerUnitPrice,
            'partner_price' => $partnerSkuPrice,
        ];
    }

    return $index;
}

function jg_partner_order_validate_sku(?array $partner, mixed $skuCode): array
{
    $normalized = trim((string) $skuCode);
    if ($normalized === '') {
        throw new InvalidArgumentException('Approved SKU is required.');
    }

    $allowed = jg_partner_order_allowed_sku_index($partner);
    if (!isset($allowed[$normalized])) {
        throw new InvalidArgumentException('That approved SKU is not enabled for this partner.');
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
        $candidate = preg_replace('/\b(?:PENGIRIM|SENDER|TEL|PHONE|HP|ALAMAT|ADDRESS|ORDER|SKU|QTY)\b.*$/i', '', $candidate) ?? $candidate;
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

    return max(12, min(48, $hours));
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
        throw new InvalidArgumentException('Select at least one approved SKU.');
    }

    $items = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sku = jg_partner_order_validate_sku($partner, $item['sku_code'] ?? null);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitCount = max(1.0, (float) ($sku['unit_count'] ?? 1));
        $partnerUnitPrice = max(0.0, (float) ($sku['partner_unit_price'] ?? 0));
        $unitRevenue = max(0.0, (float) ($sku['partner_price'] ?? ($partnerUnitPrice * $unitCount)));
        $billableUnits = round($quantity * $unitCount, 4);

        $items[] = [
            'sku_code' => $sku['sku'],
            'sku_label' => $sku['label'],
            'brand' => $sku['brand_name'],
            'product' => $sku['product_name'],
            'flavor' => $sku['flavor_name'],
            'size' => $sku['size_label'],
            'quantity' => $quantity,
            'unit_count' => $unitCount,
            'billable_units' => $billableUnits,
            'partner_unit_price' => $partnerUnitPrice,
            'unit_revenue' => $unitRevenue,
            'line_revenue' => $unitRevenue * $quantity,
            'match_confidence' => max(0.0, min(1.0, (float) ($item['match_confidence'] ?? 0))),
            'match_score' => max(0.0, (float) ($item['match_score'] ?? 0)),
            'matched_alias' => trim((string) ($item['matched_alias'] ?? '')),
            'match_evidence' => array_slice(array_values(array_filter((array) ($item['match_evidence'] ?? []), 'is_array')), 0, 5),
        ];
    }

    if ($items === []) {
        throw new InvalidArgumentException('Select at least one approved SKU.');
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
    $inference = jg_partner_order_normalize_inference($payload['inference'] ?? ($existing['inference'] ?? []));
    $marketplacePlatform = jg_partner_order_normalize_marketplace_platform($payload['marketplace_platform'] ?? ($inference['platform']['platform'] ?? $existing['marketplace_platform'] ?? 'Needs review'));
    $deadlineAt = jg_partner_order_deadline_at($orderTimestamp, $deadlineHours);
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
        'marketplace_platform' => $marketplacePlatform,
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
        'SELECT id, order_id, original_name, stored_name, relative_path, mime_type, size_bytes, expires_at, created_at
         FROM partner_order_labels
         WHERE partner_code = :partner_code AND deleted_at IS NULL
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
            'url' => '/api/label-file/?order_id=' . rawurlencode($orderId),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_partner_order_attach_labels(array $orders, array $labelsByOrder): array
{
    foreach ($orders as &$order) {
        $orderId = (string) ($order['id'] ?? '');
        $labels = array_values(array_filter(
            $labelsByOrder[$orderId] ?? (array) ($order['labels'] ?? []),
            static fn (mixed $label): bool => is_array($label) && !jg_partner_order_label_is_expired($label)
        ));
        foreach ($labels as &$label) {
            $label['url'] = '/api/label-file/?order_id=' . rawurlencode($orderId);
        }
        unset($label);
        $order['labels'] = $labels;
        $order['label_count'] = count($order['labels']);
    }
    unset($order);

    return $orders;
}

function jg_partner_order_list(string $partnerCode): array
{
    jg_partner_order_cleanup_retention();
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
        'SELECT id, order_id, partner_code, original_name, stored_name, relative_path, mime_type, size_bytes, expires_at, created_at
         FROM partner_order_labels
         WHERE deleted_at IS NULL
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
            'url' => '/api/label-file/?order_id=' . rawurlencode($orderId),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_partner_order_list_all(): array
{
    jg_partner_order_cleanup_retention();
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

function jg_partner_order_archive_is_expired(array $order, ?int $now = null): bool
{
    $archivedAt = strtotime(trim((string) ($order['archived_at'] ?? '')));
    if ($archivedAt === false) {
        return false;
    }

    $now ??= time();
    return $archivedAt <= $now - JG_PARTNER_ARCHIVED_ORDER_RETENTION_SECONDS;
}

function jg_partner_order_assert_editable(array $order): void
{
    if (!jg_partner_order_is_editable($order)) {
        throw new InvalidArgumentException('Only IS_LISTED partner orders can be canceled.');
    }
}

function jg_partner_order_insert_record_mysql(PDO $pdo, array $record): void
{
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
}

function jg_partner_order_schedule_label_expiration(string $orderId, string $status): void
{
    $normalizedStatus = strtoupper(trim($status));
    if (!in_array($normalizedStatus, ['FULFILLED', 'CANCELLED'], true)) {
        return;
    }

    $expiresAt = jg_partner_order_label_expiration_for_status($normalizedStatus);
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'UPDATE partner_order_labels
             SET expires_at = CASE
                 WHEN expires_at IS NULL OR expires_at > :expires_at_compare THEN :expires_at_value
                 ELSE expires_at
             END
             WHERE order_id = :order_id AND deleted_at IS NULL'
        );
        $mysqlExpiresAt = gmdate('Y-m-d H:i:s', strtotime($expiresAt));
        $stmt->execute([
            ':expires_at_compare' => $mysqlExpiresAt,
            ':expires_at_value' => $mysqlExpiresAt,
            ':order_id' => $orderId,
        ]);
        return;
    }

    $database = jg_partner_order_read_json_database();
    $changed = false;
    foreach ($database['orders'] as &$order) {
        if ((string) ($order['id'] ?? '') !== $orderId) {
            continue;
        }
        foreach ((array) ($order['labels'] ?? []) as $index => $label) {
            if (!is_array($label)) {
                continue;
            }
            $current = strtotime((string) ($label['expires_at'] ?? ''));
            $target = strtotime($expiresAt);
            if ($target !== false && ($current === false || $current > $target)) {
                $order['labels'][$index]['expires_at'] = $expiresAt;
                $changed = true;
            }
        }
        break;
    }
    unset($order);
    if ($changed) {
        jg_partner_order_write_json_database($database);
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

        jg_partner_order_schedule_label_expiration($normalizedId, 'CANCELLED');

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
        jg_partner_order_schedule_label_expiration($normalizedId, 'CANCELLED');
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
            jg_partner_order_insert_record_mysql($pdo, $record);

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
    if (!jg_partner_order_unlink_labels((array) ($existing['labels'] ?? []))) {
        throw new RuntimeException('Unable to securely delete the shipping label file.');
    }

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
            jg_partner_order_schedule_label_expiration($normalizedId, $normalizedStatus);
            return true;
        }

        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM partner_orders WHERE id = :id AND status = :status');
        $checkStmt->execute([
            ':id' => $normalizedId,
            ':status' => $normalizedStatus,
        ]);
        $exists = (int) $checkStmt->fetchColumn() > 0;
        if ($exists) {
            jg_partner_order_schedule_label_expiration($normalizedId, $normalizedStatus);
        }
        return $exists;
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
        jg_partner_order_schedule_label_expiration($normalizedId, $normalizedStatus);
    }

    return $updated;
}

function jg_partner_order_upload_directory(): string
{
    $directory = jg_partner_order_private_storage_root() . '/shipping-labels';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private label storage is unavailable.');
    }
    @chmod($directory, 0700);

    return $directory;
}

function jg_partner_order_allowed_extensions(): array
{
    return ['pdf'];
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

function jg_partner_order_required_uploaded_label_file(array $files): array
{
    $uploadedFiles = [];
    foreach (jg_partner_order_normalize_uploaded_files($files) as $file) {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploadedFiles[] = $file;
    }

    if ($uploadedFiles === []) {
        throw new InvalidArgumentException('Upload a shipment label PDF.');
    }
    if (count($uploadedFiles) > 1) {
        throw new InvalidArgumentException('Upload only one shipment label PDF.');
    }

    $file = $uploadedFiles[0];
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The shipment label PDF failed to upload.');
    }

    return $file;
}

function jg_partner_order_assert_pdf_upload(array $file): void
{
    $safeOriginal = trim((string) ($file['name'] ?? ''));
    $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
    if ($safeOriginal === '' || !in_array($extension, jg_partner_order_allowed_extensions(), true)) {
        throw new InvalidArgumentException('Upload a shipment label PDF.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('The shipment label PDF failed to upload.');
    }

    $size = (int) ($file['size'] ?? filesize($tmpName) ?: 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('Shipment label PDF is empty.');
    }
    if ($size > JG_PARTNER_LABEL_MAX_BYTES) {
        throw new InvalidArgumentException('Shipment label PDF must be 10 MB or smaller.');
    }

    $header = @file_get_contents($tmpName, false, null, 0, 5);
    if (!is_string($header) || $header !== '%PDF-') {
        throw new InvalidArgumentException('Shipment label must be a valid PDF file.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedType = strtolower((string) $finfo->file($tmpName));
        if (!in_array($detectedType, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
            throw new InvalidArgumentException('Shipment label must be a valid PDF file.');
        }
    }
}

function jg_partner_order_prepare_uploaded_label(string $partnerCode, string $orderId, array $files): array
{
    $file = jg_partner_order_required_uploaded_label_file($files);
    jg_partner_order_assert_pdf_upload($file);

    $uploadDir = jg_partner_order_upload_directory();
    $safeOriginal = trim((string) ($file['name'] ?? 'shipment-label.pdf'));
    $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION)) ?: 'pdf';
    $storedName = sprintf(
        '%s-%s-%s.%s',
        preg_replace('/[^a-z0-9]+/i', '-', strtolower($partnerCode)) ?: 'partner',
        preg_replace('/[^a-z0-9]+/i', '-', strtolower($orderId)) ?: 'order',
        substr(sha1($safeOriginal . microtime(true) . random_int(1000, 9999)), 0, 12),
        $extension
    );

    $targetPath = rtrim($uploadDir, '/') . '/' . $storedName;
    if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('Unable to save shipment label PDF.');
    }

    $mimeType = '';
    if (function_exists('mime_content_type')) {
        $mimeType = (string) @mime_content_type($targetPath);
    }

    return [
        'name' => $safeOriginal,
        'stored_name' => $storedName,
        'path' => jg_partner_order_label_storage_relative_path($storedName),
        'url' => '/api/label-file/?order_id=' . rawurlencode($orderId),
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/pdf',
        'size_bytes' => (int) ($file['size'] ?? 0),
        'expires_at' => jg_partner_order_label_expiration_for_status('IS_LISTED'),
        'created_at' => gmdate(DATE_ATOM),
    ];
}

function jg_partner_order_unlink_labels(array $labels): bool
{
    $deleted = true;
    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        foreach (jg_partner_order_label_file_candidates($label) as $path) {
            if (is_file($path)) {
                if (!@unlink($path) && is_file($path)) {
                    $deleted = false;
                }
            }
        }
    }
    return $deleted;
}

function jg_partner_order_cleanup_expired_archives(?int $now = null): int
{
    static $ranAt = 0;
    $now ??= time();
    if ($ranAt > 0 && $now - $ranAt < 60) {
        return 0;
    }
    $ranAt = $now;

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT o.id AS order_id,
                    l.original_name, l.stored_name, l.relative_path, l.mime_type, l.size_bytes, l.expires_at, l.created_at
             FROM partner_orders o
             LEFT JOIN partner_order_labels l ON l.order_id = o.id AND l.deleted_at IS NULL
             WHERE o.archived_at IS NOT NULL
               AND o.archived_at <= :cutoff'
        );
        $cutoff = gmdate('Y-m-d H:i:s', $now - JG_PARTNER_ARCHIVED_ORDER_RETENTION_SECONDS);
        $stmt->execute([':cutoff' => $cutoff]);

        $labelsByOrder = [];
        foreach ($stmt->fetchAll() as $row) {
            $orderId = trim((string) ($row['order_id'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            $labelsByOrder[$orderId] ??= [];
            if (trim((string) ($row['stored_name'] ?? '')) !== '') {
                $labelsByOrder[$orderId][] = $row;
            }
        }

        $deletableIds = [];
        foreach ($labelsByOrder as $orderId => $labels) {
            if (jg_partner_order_unlink_labels($labels)) {
                $deletableIds[] = $orderId;
            }
        }
        if ($deletableIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($deletableIds), '?'));
        $delete = $pdo->prepare(
            'DELETE FROM partner_orders
             WHERE archived_at IS NOT NULL
               AND archived_at <= ?
               AND id IN (' . $placeholders . ')'
        );
        $delete->execute(array_merge([$cutoff], $deletableIds));
        return $delete->rowCount();
    }

    $database = jg_partner_order_read_json_database();
    $retained = [];
    $deleted = 0;
    foreach ($database['orders'] as $order) {
        if (!jg_partner_order_archive_is_expired($order, $now)
            || !jg_partner_order_unlink_labels((array) ($order['labels'] ?? []))) {
            $retained[] = $order;
            continue;
        }
        $deleted += 1;
    }
    if ($deleted > 0) {
        $database['orders'] = $retained;
        jg_partner_order_write_json_database($database);
    }
    return $deleted;
}

function jg_partner_order_cleanup_retention(?int $now = null): array
{
    return [
        'orders' => jg_partner_order_cleanup_expired_archives($now),
        'labels' => jg_partner_order_cleanup_expired_labels($now),
    ];
}

function jg_partner_order_cleanup_expired_labels(?int $now = null): int
{
    static $ranAt = 0;
    $now ??= time();
    if ($ranAt > 0 && $now - $ranAt < 60) {
        return 0;
    }
    $ranAt = $now;

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT id, original_name, stored_name, relative_path, mime_type, size_bytes, expires_at, created_at
             FROM partner_order_labels
             WHERE deleted_at IS NULL
               AND COALESCE(expires_at, DATE_ADD(created_at, INTERVAL 7 DAY)) <= :now'
        );
        $stmt->execute([':now' => gmdate('Y-m-d H:i:s', $now)]);
        $expired = array_values(array_filter($stmt->fetchAll(), 'is_array'));
        if ($expired === []) {
            return 0;
        }

        $expired = array_values(array_filter(
            $expired,
            static fn (array $label): bool => jg_partner_order_unlink_labels([$label])
        ));
        if ($expired === []) {
            return 0;
        }
        $ids = array_map(static fn (array $label): int => (int) ($label['id'] ?? 0), $expired);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare(
                'UPDATE partner_order_labels
                 SET original_name = "Expired shipping label", stored_name = "", relative_path = "",
                     mime_type = "", size_bytes = 0, deleted_at = ?, deletion_reason = "retention_expired"
                 WHERE id IN (' . $placeholders . ')'
            );
            $update->execute(array_merge([gmdate('Y-m-d H:i:s', $now)], $ids));
        }
        return count($expired);
    }

    $database = jg_partner_order_read_json_database();
    $deleted = 0;
    foreach ($database['orders'] as &$order) {
        $retained = [];
        foreach ((array) ($order['labels'] ?? []) as $label) {
            if (!is_array($label)) {
                continue;
            }
            if (jg_partner_order_label_is_expired($label, $now)) {
                if (jg_partner_order_unlink_labels([$label])) {
                    $deleted += 1;
                    continue;
                }
            }
            $retained[] = $label;
        }
        $order['labels'] = $retained;
    }
    unset($order);
    if ($deleted > 0) {
        jg_partner_order_write_json_database($database);
    }
    return $deleted;
}

function jg_partner_order_stream_label(array $label): never
{
    if (jg_partner_order_label_is_expired($label)) {
        throw new RuntimeException('Shipping label has expired.');
    }
    $path = jg_partner_order_label_file_path($label);
    if ($path === null) {
        throw new RuntimeException('Shipping label file is unavailable.');
    }

    $downloadName = trim((string) ($label['name'] ?? 'shipping-label.pdf'));
    $downloadName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $downloadName) ?: 'shipping-label.pdf';
    if (!str_ends_with(strtolower($downloadName), '.pdf')) {
        $downloadName .= '.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . addcslashes($downloadName, "\\\"") . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    readfile($path);
    exit;
}

function jg_partner_order_insert_label_mysql(PDO $pdo, string $partnerCode, string $orderId, array $label): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO partner_order_labels
            (order_id, partner_code, original_name, stored_name, relative_path, mime_type, size_bytes, expires_at, created_at)
         VALUES
            (:order_id, :partner_code, :original_name, :stored_name, :relative_path, :mime_type, :size_bytes, :expires_at, :created_at)'
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':partner_code' => $partnerCode,
        ':original_name' => $label['name'],
        ':stored_name' => $label['stored_name'],
        ':relative_path' => $label['path'],
        ':mime_type' => $label['mime_type'],
        ':size_bytes' => $label['size_bytes'],
        ':expires_at' => gmdate('Y-m-d H:i:s', strtotime((string) $label['expires_at'])),
        ':created_at' => gmdate('Y-m-d H:i:s', strtotime((string) $label['created_at'])),
    ]);
}

function jg_partner_order_create_with_label(string $partnerCode, ?array $partner, array $payload, array $files): array
{
    $record = jg_partner_order_build_record($partnerCode, $partner, $payload);

    $pdo = jg_partner_data_db();
    if (!$pdo instanceof PDO) {
        jg_partner_order_assert_fallback_storage_allowed();
    }

    $label = jg_partner_order_prepare_uploaded_label($partnerCode, (string) $record['id'], $files);
    $record['labels'] = [$label];

    if ($pdo instanceof PDO) {
        try {
            $pdo->beginTransaction();
            jg_partner_order_insert_record_mysql($pdo, $record);
            jg_partner_order_insert_label_mysql($pdo, $partnerCode, (string) $record['id'], $label);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jg_partner_order_unlink_labels([$label]);
            throw $exception;
        }

        return jg_partner_order_find($partnerCode, (string) $record['id']) ?? $record;
    }

    try {
        $database = jg_partner_order_read_json_database();
        $database['orders'][] = $record;
        jg_partner_order_write_json_database($database);
    } catch (Throwable $exception) {
        jg_partner_order_unlink_labels([$label]);
        throw $exception;
    }

    return $record;
}

function jg_partner_order_run_pdftotext(string $path): string
{
    if (!function_exists('proc_open') || !is_file($path)) {
        return '';
    }

    $candidates = ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'];
    foreach ($candidates as $binary) {
        if ($binary !== 'pdftotext' && !is_executable($binary)) {
            continue;
        }

        $command = escapeshellcmd($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' -';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            continue;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $text = trim((string) $stdout);
        if ($exitCode === 0 && $text !== '') {
            return $text;
        }
        if ($stderr !== '') {
            continue;
        }
    }

    return '';
}

function jg_partner_order_pdf_objects(string $raw): array
{
    $objects = [];
    if (!preg_match_all('/(\d+)\s+0\s+obj(.*?)endobj/s', $raw, $matches, PREG_SET_ORDER)) {
        return $objects;
    }

    foreach ($matches as $match) {
        $objects[(int) $match[1]] = (string) $match[2];
    }

    return $objects;
}

function jg_partner_order_pdf_stream_bytes(string $objectBody): string
{
    if (!preg_match('/stream(?:\r\n|\n|\r)(.*?)endstream/s', $objectBody, $matches)) {
        return '';
    }

    return preg_replace('/(?:\r\n|\n|\r)$/', '', (string) $matches[1]) ?? (string) $matches[1];
}

function jg_partner_order_pdf_decode_stream(string $objectBody): string
{
    $stream = jg_partner_order_pdf_stream_bytes($objectBody);
    if ($stream === '') {
        return '';
    }

    if (!str_contains($objectBody, 'FlateDecode')) {
        return $stream;
    }

    foreach ([
        static fn (string $value): string|false => @gzuncompress($value),
        static fn (string $value): string|false => @gzdecode($value),
        static fn (string $value): string|false => @gzinflate($value),
        static fn (string $value): string|false => strlen($value) > 6 ? @gzinflate(substr($value, 2, -4)) : false,
    ] as $decode) {
        $decoded = $decode($stream);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }

    return '';
}

function jg_partner_order_pdf_unicode_from_hex(string $hex): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
    if ($hex === '') {
        return '';
    }
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }

    $bytes = @pack('H*', $hex);
    if (!is_string($bytes) || $bytes === '') {
        return '';
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    $chars = '';
    for ($index = 0; $index + 1 < strlen($bytes); $index += 2) {
        $codepoint = (ord($bytes[$index]) << 8) + ord($bytes[$index + 1]);
        if ($codepoint > 0 && $codepoint < 128) {
            $chars .= chr($codepoint);
        }
    }

    return $chars;
}

function jg_partner_order_pdf_parse_cmap(string $cmap): array
{
    $map = [];
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', (string) $block, $pairs, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($pairs as $pair) {
                $map[strtoupper(str_pad($pair[1], 4, '0', STR_PAD_LEFT))] = jg_partner_order_pdf_unicode_from_hex($pair[2]);
            }
        }
    }

    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', (string) $block, $ranges, PREG_SET_ORDER)) {
                foreach ($ranges as $range) {
                    $start = hexdec($range[1]);
                    $end = hexdec($range[2]);
                    $destination = hexdec($range[3]);
                    for ($code = $start; $code <= $end; $code++) {
                        $map[strtoupper(str_pad(dechex($code), 4, '0', STR_PAD_LEFT))] = jg_partner_order_pdf_unicode_from_hex(str_pad(dechex($destination + ($code - $start)), strlen($range[3]), '0', STR_PAD_LEFT));
                    }
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', (string) $block, $arrayRanges, PREG_SET_ORDER)) {
                foreach ($arrayRanges as $range) {
                    $start = hexdec($range[1]);
                    if (!preg_match_all('/<([0-9A-Fa-f]+)>/', (string) $range[3], $destinations)) {
                        continue;
                    }
                    foreach ($destinations[1] as $offset => $destination) {
                        $map[strtoupper(str_pad(dechex($start + $offset), 4, '0', STR_PAD_LEFT))] = jg_partner_order_pdf_unicode_from_hex($destination);
                    }
                }
            }
        }
    }

    return $map;
}

function jg_partner_order_pdf_font_maps(array $objects): array
{
    $fontMaps = [];
    foreach ($objects as $objectBody) {
        if (!preg_match_all('/\/(F[0-9A-Za-z]+)\s+(\d+)\s+0\s+R/', (string) $objectBody, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $fontName = (string) $match[1];
            $fontObjectId = (int) $match[2];
            $fontObject = (string) ($objects[$fontObjectId] ?? '');
            if ($fontObject === '' || !preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $fontObject, $unicodeMatch)) {
                continue;
            }

            $cmapObject = (string) ($objects[(int) $unicodeMatch[1]] ?? '');
            $cmapText = jg_partner_order_pdf_decode_stream($cmapObject);
            if ($cmapText === '') {
                continue;
            }
            $fontMaps[$fontName] = jg_partner_order_pdf_parse_cmap($cmapText);
        }
    }

    return $fontMaps;
}

function jg_partner_order_pdf_decode_hex_text(string $hex, array $map): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
    if ($hex === '') {
        return '';
    }

    $output = '';
    for ($index = 0; $index + 3 < strlen($hex); $index += 4) {
        $code = strtoupper(substr($hex, $index, 4));
        $output .= $map[$code] ?? '';
    }

    return $output;
}

function jg_partner_order_pdf_decode_literal_text(string $literal): string
{
    $literal = trim($literal);
    if (str_starts_with($literal, '(') && str_ends_with($literal, ')')) {
        $literal = substr($literal, 1, -1);
    }

    return stripcslashes($literal);
}

function jg_partner_order_pdf_extract_text_native(string $path): string
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $objects = jg_partner_order_pdf_objects($raw);
    $fontMaps = jg_partner_order_pdf_font_maps($objects);
    if ($fontMaps === []) {
        return '';
    }

    $chunks = [];
    foreach ($objects as $objectBody) {
        $decoded = jg_partner_order_pdf_decode_stream((string) $objectBody);
        if ($decoded === '' || str_contains($decoded, 'begincmap') || (!str_contains($decoded, ' Tj') && !str_contains($decoded, ' TJ'))) {
            continue;
        }

        $currentFont = '';
        if (!preg_match_all('/\/(F[0-9A-Za-z]+)\s+[-0-9.]+\s+Tf|<([0-9A-Fa-f\s]+)>\s*Tj|\[(.*?)\]\s*TJ|(\((?:\\\\.|[^\\\\)])*\))\s*Tj/s', $decoded, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $currentFont = (string) $match[1];
                continue;
            }

            $map = $fontMaps[$currentFont] ?? [];
            if (!empty($match[2]) && $map !== []) {
                $text = jg_partner_order_pdf_decode_hex_text((string) $match[2], $map);
                if (trim($text) !== '') {
                    $chunks[] = $text;
                }
                continue;
            }

            if (!empty($match[3]) && $map !== []) {
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>|(\((?:\\\\.|[^\\\\)])*\))/', (string) $match[3], $parts, PREG_SET_ORDER)) {
                    $line = '';
                    foreach ($parts as $part) {
                        if (!empty($part[1])) {
                            $line .= jg_partner_order_pdf_decode_hex_text((string) $part[1], $map);
                        } elseif (!empty($part[2])) {
                            $line .= jg_partner_order_pdf_decode_literal_text((string) $part[2]);
                        }
                    }
                    if (trim($line) !== '') {
                        $chunks[] = $line;
                    }
                }
                continue;
            }

            if (!empty($match[4])) {
                $text = jg_partner_order_pdf_decode_literal_text((string) $match[4]);
                if (trim($text) !== '') {
                    $chunks[] = $text;
                }
            }
        }
    }

    return trim(implode("\n", $chunks));
}

function jg_partner_order_extract_pdf_text(string $path): string
{
    $text = jg_partner_order_run_pdftotext($path);
    if (trim($text) !== '') {
        return $text;
    }

    return jg_partner_order_pdf_extract_text_native($path);
}

function jg_partner_order_extract_readable_text(string $path, string $originalName): string
{
    $parts = [$originalName];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $pdfText = $extension === 'pdf' ? jg_partner_order_extract_pdf_text($path) : '';
    if ($pdfText !== '') {
        $parts[] = $pdfText;
    }

    $raw = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
    if (is_string($raw) && $raw !== '' && ($extension !== 'pdf' || $pdfText === '')) {
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
    $firstFile = jg_partner_order_required_uploaded_label_file($files);
    jg_partner_order_assert_pdf_upload($firstFile);

    $safeOriginal = trim((string) ($firstFile['name'] ?? 'shipment-label.pdf'));
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

    $savedLabels = [
        jg_partner_order_prepare_uploaded_label($partnerCode, $orderId, $files),
    ];

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        try {
            foreach ($savedLabels as $label) {
                jg_partner_order_insert_label_mysql($pdo, $partnerCode, $orderId, $label);
            }
        } catch (Throwable $exception) {
            jg_partner_order_unlink_labels($savedLabels);
            throw $exception;
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
    try {
        jg_partner_order_write_json_database($database);
    } catch (Throwable $exception) {
        jg_partner_order_unlink_labels($savedLabels);
        throw $exception;
    }

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

    if (!jg_partner_order_unlink_labels((array) ($order['labels'] ?? []))) {
        throw new RuntimeException('Unable to securely delete the shipping label file.');
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'UPDATE partner_order_labels
             SET original_name = "Deleted shipping label", stored_name = "", relative_path = "",
                 mime_type = "", size_bytes = 0, deleted_at = :deleted_at, deletion_reason = "partner_deleted"
             WHERE order_id = :order_id AND partner_code = :partner_code AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':deleted_at' => gmdate('Y-m-d H:i:s'),
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
