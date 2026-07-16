<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-data-bootstrap.php';

const JG_PARTNER_PLATFORM_JSON_FILE = __DIR__ . '/data/platform-options.json';
const JG_PARTNER_PLATFORM_MAX_CUSTOM = 20;

function jg_partner_platform_builtins(): array
{
    return [
        [
            'id' => 'builtin-shopee',
            'name' => 'Shopee',
            'caption' => 'Shopee marketplace order',
            'kind' => 'shopee',
            'removable' => false,
        ],
        [
            'id' => 'builtin-tiktok-toped',
            'name' => 'TikTok/Toped',
            'caption' => 'TikTok/Toped marketplace order',
            'kind' => 'tiktok',
            'removable' => false,
        ],
    ];
}

function jg_partner_platform_comparison_key(mixed $value): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), 'UTF-8');
}

function jg_partner_platform_normalize_name(mixed $value): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Platform name is required.');
    }
    if (mb_strlen($name, 'UTF-8') > 32) {
        throw new InvalidArgumentException('Platform name must be 32 characters or fewer.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        throw new InvalidArgumentException('Platform name contains unsupported characters.');
    }

    $reserved = ['shopee', 'spx', 'tiktok', 'tik tok', 'tiktok shop', 'tiktok/toped', 'tiktok toped', 'tiktok/tokopedia', 'tiktok tokopedia', 'tokopedia'];
    foreach (jg_partner_platform_builtins() as $builtin) {
        $reserved[] = jg_partner_platform_comparison_key($builtin['name']);
    }
    if (in_array(jg_partner_platform_comparison_key($name), $reserved, true)) {
        throw new InvalidArgumentException('That platform is already built in.');
    }

    return $name;
}

function jg_partner_platform_default_json_database(): array
{
    return [
        'meta' => [
            'next_id' => 1,
            'updated_at' => gmdate(DATE_ATOM),
        ],
        'options' => [],
    ];
}

function jg_partner_platform_read_json_database(): array
{
    if (jg_partner_data_mysql_is_configured()) {
        throw new LogicException('Platform option storage is temporarily unavailable.');
    }
    if (!is_file(JG_PARTNER_PLATFORM_JSON_FILE)) {
        return jg_partner_platform_default_json_database();
    }

    $decoded = json_decode((string) @file_get_contents(JG_PARTNER_PLATFORM_JSON_FILE), true);
    if (!is_array($decoded)) {
        return jg_partner_platform_default_json_database();
    }
    $decoded['meta'] = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
    $decoded['meta']['next_id'] = max(1, (int) ($decoded['meta']['next_id'] ?? 1));
    $decoded['options'] = array_values(array_filter($decoded['options'] ?? [], 'is_array'));
    return $decoded;
}

function jg_partner_platform_write_json_database(array $database): void
{
    if (jg_partner_data_mysql_is_configured()) {
        throw new LogicException('Platform option storage is temporarily unavailable.');
    }
    $database['meta'] = is_array($database['meta'] ?? null) ? $database['meta'] : [];
    $database['meta']['updated_at'] = gmdate(DATE_ATOM);
    $encoded = json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || @file_put_contents(JG_PARTNER_PLATFORM_JSON_FILE, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save platform options.');
    }
}

function jg_partner_platform_custom_options(string $partnerCode): array
{
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT id, platform_name, created_at
             FROM partner_platform_options
             WHERE partner_code = :partner_code
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([':partner_code' => $partnerCode]);
        $rows = $stmt->fetchAll();
    } else {
        $database = jg_partner_platform_read_json_database();
        $rows = array_values(array_filter(
            $database['options'],
            static fn (array $option): bool => (string) ($option['partner_code'] ?? '') === $partnerCode
        ));
    }

    return array_map(static fn (array $row): array => [
        'id' => 'custom-' . (int) ($row['id'] ?? 0),
        'name' => (string) ($row['platform_name'] ?? ''),
        'caption' => 'Custom reseller profile',
        'kind' => 'custom',
        'removable' => true,
    ], $rows);
}

function jg_partner_platform_options(string $partnerCode): array
{
    return array_merge(jg_partner_platform_builtins(), jg_partner_platform_custom_options($partnerCode));
}

function jg_partner_platform_create(string $partnerCode, mixed $value): array
{
    $name = jg_partner_platform_normalize_name($value);
    $existing = jg_partner_platform_custom_options($partnerCode);
    if (count($existing) >= JG_PARTNER_PLATFORM_MAX_CUSTOM) {
        throw new InvalidArgumentException('A maximum of 20 custom platforms is allowed.');
    }
    $comparisonKey = jg_partner_platform_comparison_key($name);
    foreach ($existing as $option) {
        if (jg_partner_platform_comparison_key($option['name'] ?? '') === $comparisonKey) {
            throw new InvalidArgumentException('That custom platform already exists.');
        }
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'INSERT INTO partner_platform_options (partner_code, platform_name, created_at)
             VALUES (:partner_code, :platform_name, :created_at)'
        );
        $stmt->execute([
            ':partner_code' => $partnerCode,
            ':platform_name' => $name,
            ':created_at' => jg_partner_data_now(),
        ]);
    } else {
        $database = jg_partner_platform_read_json_database();
        $id = max(1, (int) ($database['meta']['next_id'] ?? 1));
        $database['meta']['next_id'] = $id + 1;
        $database['options'][] = [
            'id' => $id,
            'partner_code' => $partnerCode,
            'platform_name' => $name,
            'created_at' => gmdate(DATE_ATOM),
        ];
        jg_partner_platform_write_json_database($database);
    }

    return jg_partner_platform_options($partnerCode);
}

function jg_partner_platform_delete(string $partnerCode, mixed $optionId): array
{
    $normalizedId = (int) preg_replace('/^custom-/', '', trim((string) $optionId));
    if ($normalizedId <= 0) {
        throw new InvalidArgumentException('Custom platform is invalid.');
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'DELETE FROM partner_platform_options
             WHERE id = :id AND partner_code = :partner_code'
        );
        $stmt->execute([
            ':id' => $normalizedId,
            ':partner_code' => $partnerCode,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Custom platform was not found.');
        }
    } else {
        $database = jg_partner_platform_read_json_database();
        $before = count($database['options']);
        $database['options'] = array_values(array_filter(
            $database['options'],
            static fn (array $option): bool => !(
                (int) ($option['id'] ?? 0) === $normalizedId
                && (string) ($option['partner_code'] ?? '') === $partnerCode
            )
        ));
        if (count($database['options']) === $before) {
            throw new InvalidArgumentException('Custom platform was not found.');
        }
        jg_partner_platform_write_json_database($database);
    }

    return jg_partner_platform_options($partnerCode);
}
