<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$providedToken = (string) ($_SERVER['HTTP_X_JG_ADMIN_TOKEN'] ?? '');
if (!hash_equals(JG_ADMIN_CODE_HASH, $providedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

const JG_PARTNER_FILE = __DIR__ . '/../../data/partners.json';
const JG_PARTNER_PAGE_ROOT = __DIR__ . '/../..';

function jg_partner_default(): array
{
    return [
        'meta' => [
            'version' => '1.00.00',
            'updated_at' => gmdate(DATE_ATOM),
        ],
        'partners' => [],
    ];
}

function jg_partner_read(): array
{
    if (!file_exists(JG_PARTNER_FILE)) {
        return jg_partner_default();
    }

    $raw = file_get_contents(JG_PARTNER_FILE);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return jg_partner_default();
    }

    $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    $data['meta']['version'] = (string) ($data['meta']['version'] ?? '1.00.00');
    $data['meta']['updated_at'] = (string) ($data['meta']['updated_at'] ?? gmdate(DATE_ATOM));
    $data['partners'] = array_values(array_filter($data['partners'] ?? [], 'is_array'));
    return $data;
}

function jg_partner_write(array $data): void
{
    $data['meta']['updated_at'] = gmdate(DATE_ATOM);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode partner data.');
    }

    file_put_contents(JG_PARTNER_FILE, $encoded . PHP_EOL, LOCK_EX);
}

function jg_partner_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_partner_request(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jg_partner_bump_patch(string $version): string
{
    if (!preg_match('/^(\d+)\.(\d{2})\.(\d{2})$/', $version, $matches)) {
        return '1.00.00';
    }

    $major = (int) $matches[1];
    $middle = (int) $matches[2];
    $patch = (int) $matches[3] + 1;
    if ($patch > 99) {
        $patch = 0;
        $middle += 1;
    }

    return sprintf('%d.%02d.%02d', $major, $middle, $patch);
}

function jg_partner_text(string $value, int $max = 160): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($normalized === '') {
        jg_partner_fail('This field is required.');
    }
    if (mb_strlen($normalized) > $max) {
        jg_partner_fail('Text is too long.');
    }
    return $normalized;
}

function jg_partner_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'partner';
}

function jg_partner_next_sequence(array $partners): int
{
    $max = 0;
    foreach ($partners as $partner) {
        $code = (string) ($partner['code'] ?? '');
        if (preg_match('/^partner-(\d+)-/', $code, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return $max + 1;
}

function jg_partner_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        $normalized[] = $text;
    }

    return array_values(array_unique($normalized));
}

function jg_partner_amount(mixed $value): float
{
    if ($value === '' || $value === null) {
        return 0;
    }
    if (!is_numeric($value)) {
        jg_partner_fail('Pricing values must be numeric.');
    }
    return round((float) $value, 2);
}

function jg_partner_slug_exists(array $partners, string $slug, string $exceptCode = ''): bool
{
    foreach ($partners as $partner) {
        if ($exceptCode !== '' && (string) ($partner['code'] ?? '') === $exceptCode) {
            continue;
        }

        if ((string) ($partner['partner_slug'] ?? '') === $slug) {
            return true;
        }
    }

    return false;
}

function jg_partner_reserved_slugs(): array
{
    return [
        'api',
        'dashboard',
        'data',
        'logout',
        'profile',
        'profiles',
    ];
}

function jg_partner_safe_slug(string $value, array $partners, string $exceptCode = ''): string
{
    $base = jg_partner_slug($value);
    $slug = $base;
    $suffix = 2;

    while (in_array($slug, jg_partner_reserved_slugs(), true) || jg_partner_slug_exists($partners, $slug, $exceptCode)) {
        $slug = $base . '-' . $suffix;
        $suffix += 1;
    }

    return $slug;
}

function jg_partner_default_product_access(): array
{
    return [
        'Jenang Gemi' => [
            'Bubur' => [
                'enabled' => true,
                'sizes' => ['15 Sachet', '30 Sachet', '60 Sachet'],
            ],
            'Jamu' => [
                'enabled' => true,
                'sizes' => ['15 Sachet', '30 Sachet', '60 Sachet'],
            ],
        ],
        'ZERO' => [],
        'ZFIT' => [],
    ];
}

function jg_partner_default_pricing(): array
{
    return [
        'Jenang Gemi' => [
            'Bubur' => [
                '15 Sachet' => 0,
                '30 Sachet' => 0,
                '60 Sachet' => 0,
            ],
            'Jamu' => [
                '15 Sachet' => 0,
                '30 Sachet' => 0,
                '60 Sachet' => 0,
            ],
        ],
        'ZERO' => [],
        'ZFIT' => [],
    ];
}

function jg_partner_normalize_sizes(mixed $value): array
{
    $allowed = ['15 Sachet', '30 Sachet', '60 Sachet'];
    $values = jg_partner_list($value);
    return array_values(array_filter($allowed, static fn(string $size): bool => in_array($size, $values, true)));
}

function jg_partner_product_access(mixed $value, array $companies): array
{
    $default = jg_partner_default_product_access();
    $incoming = is_array($value) ? $value : [];

    foreach ($default as $company => $products) {
        if (!in_array($company, $companies, true)) {
            $default[$company] = [];
            continue;
        }

        foreach ($products as $productName => $config) {
            $incomingConfig = is_array($incoming[$company][$productName] ?? null) ? $incoming[$company][$productName] : [];
            $enabled = !empty($incomingConfig['enabled']);
            $sizes = jg_partner_normalize_sizes($incomingConfig['sizes'] ?? []);
            $default[$company][$productName] = [
                'enabled' => $enabled,
                'sizes' => $enabled ? $sizes : [],
            ];
        }
    }

    return $default;
}

function jg_partner_pricing_matrix(mixed $value, array $companies): array
{
    $default = jg_partner_default_pricing();
    $incoming = is_array($value) ? $value : [];

    foreach ($default as $company => $products) {
        if (!in_array($company, $companies, true)) {
            $default[$company] = [];
            continue;
        }

        foreach ($products as $productName => $sizes) {
            foreach ($sizes as $size => $amount) {
                $default[$company][$productName][$size] = jg_partner_amount($incoming[$company][$productName][$size] ?? $amount);
            }
        }
    }

    return $default;
}

function jg_partner_response(array $data, ?array $partner = null): void
{
    $payload = ['database' => $data];
    if ($partner !== null) {
        $payload['partner'] = $partner;
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_partner_page_path(string $slug): string
{
    return rtrim(JG_PARTNER_PAGE_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . jg_partner_slug($slug);
}

function jg_partner_delete_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        throw new RuntimeException('Unable to read partner page directory.');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            jg_partner_delete_directory($path);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove partner page file.');
        }
    }

    if (!rmdir($directory)) {
        throw new RuntimeException('Unable to remove partner page directory.');
    }
}

function jg_partner_page_markup(array $partner): string
{
    $name = htmlspecialchars((string) ($partner['name'] ?? 'Partner'), ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars((string) ($partner['code'] ?? ''), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$name} | Jenang Gemi Partner</title>
    <style>
        :root {
            color-scheme: light;
            --page-bg: #f4efe4;
            --card-bg: rgba(255, 252, 247, 0.94);
            --text-main: #1f241c;
            --text-muted: #5d6656;
            --accent: #6f8f31;
            --accent-soft: #dce8b5;
            --border: rgba(31, 36, 28, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at top left, rgba(220, 232, 181, 0.95), transparent 32%),
                radial-gradient(circle at bottom right, rgba(111, 143, 49, 0.18), transparent 28%),
                var(--page-bg);
        }
        .partner-page {
            width: min(720px, 100%);
            padding: 32px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: var(--card-bg);
            box-shadow: 0 24px 60px rgba(31, 36, 28, 0.12);
        }
        .partner-kicker {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        h1 {
            margin: 18px 0 12px;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1;
        }
        p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.7;
            color: var(--text-muted);
        }
        .partner-code {
            margin-top: 24px;
            font-size: 0.95rem;
            color: var(--text-main);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="partner-page">
        <span class="partner-kicker">Partner Homepage</span>
        <h1>{$name}</h1>
        <p>This feature is under construction.</p>
        <p class="partner-code">Partner code: {$code}</p>
    </main>
</body>
</html>
HTML;
}

function jg_partner_sync_page(array $partner, ?string $previousSlug = null): void
{
    $slug = jg_partner_slug((string) ($partner['partner_slug'] ?? ''));
    $directory = jg_partner_page_path($slug);

    if ($previousSlug !== null && $previousSlug !== '' && $previousSlug !== $slug) {
        $oldDirectory = jg_partner_page_path($previousSlug);
        if (is_dir($oldDirectory) && !is_dir($directory) && !rename($oldDirectory, $directory)) {
            throw new RuntimeException('Unable to rename partner page directory.');
        }
    }

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create partner page.');
    }

    $file = $directory . DIRECTORY_SEPARATOR . 'index.php';
    if (file_put_contents($file, jg_partner_page_markup($partner), LOCK_EX) === false) {
        throw new RuntimeException('Unable to write partner page.');
    }
}

function jg_partner_remove_page(string $slug): void
{
    jg_partner_delete_directory(jg_partner_page_path($slug));
}

function jg_partner_sync_registry_pages(array $database): void
{
    foreach ($database['partners'] as $partner) {
        if (!is_array($partner)) {
            continue;
        }
        jg_partner_sync_page($partner);
    }
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$database = jg_partner_read();
jg_partner_sync_registry_pages($database);

if ($method === 'GET') {
    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        jg_partner_response($database);
    }

    foreach ($database['partners'] as $partner) {
        if ((string) ($partner['code'] ?? '') === $code) {
            jg_partner_response($database, $partner);
        }
    }

    jg_partner_fail('Partner not found.', 404);
}

if ($method !== 'POST') {
    jg_partner_fail('Method not allowed.', 405);
}

$request = jg_partner_request();
$action = (string) ($request['action'] ?? '');

if ($action === 'create') {
    $name = jg_partner_text((string) ($request['name'] ?? ''));
    $companies = jg_partner_list($request['companies'] ?? []);
    if (!$companies) {
        jg_partner_fail('Select at least one company.');
    }

    $sequence = jg_partner_next_sequence($database['partners']);
    $slug = jg_partner_safe_slug((string) ($request['partner_slug'] ?? $name), $database['partners']);
    $code = sprintf('partner-%03d-%s', $sequence, $slug);

    $partner = [
        'code' => $code,
        'name' => $name,
        'companies' => $companies,
        'product_access' => jg_partner_product_access($request['product_access'] ?? [], $companies),
        'pricing' => jg_partner_pricing_matrix($request['pricing'] ?? [], $companies),
        'notes' => trim((string) ($request['notes'] ?? '')),
        'partner_slug' => $slug,
        'store_path' => '/' . $slug . '/',
        'created_at' => gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
    ];

    jg_partner_sync_page($partner);
    $database['partners'][] = $partner;
    $database['meta']['version'] = jg_partner_bump_patch((string) $database['meta']['version']);
    jg_partner_write($database);
    jg_partner_response($database);
}

if ($action === 'update') {
    $code = trim((string) ($request['code'] ?? ''));
    if ($code === '') {
        jg_partner_fail('Partner code is required.');
    }

    $updated = false;
    foreach ($database['partners'] as &$partner) {
        if ((string) ($partner['code'] ?? '') !== $code) {
            continue;
        }

        $name = jg_partner_text((string) ($request['name'] ?? ($partner['name'] ?? '')));
        $companies = jg_partner_list($request['companies'] ?? ($partner['companies'] ?? []));
        if (!$companies) {
            jg_partner_fail('Select at least one company.');
        }

        $previousSlug = (string) ($partner['partner_slug'] ?? '');
        $slug = jg_partner_safe_slug((string) ($request['partner_slug'] ?? ($partner['partner_slug'] ?? $name)), $database['partners'], $code);

        $partner['name'] = $name;
        $partner['companies'] = $companies;
        $partner['product_access'] = jg_partner_product_access($request['product_access'] ?? ($partner['product_access'] ?? []), $companies);
        $partner['pricing'] = jg_partner_pricing_matrix($request['pricing'] ?? ($partner['pricing'] ?? []), $companies);
        $partner['notes'] = trim((string) ($request['notes'] ?? ($partner['notes'] ?? '')));
        $partner['partner_slug'] = $slug;
        $partner['store_path'] = '/' . $slug . '/';
        $partner['updated_at'] = gmdate(DATE_ATOM);
        jg_partner_sync_page($partner, $previousSlug);
        $updated = true;
        break;
    }
    unset($partner);

    if (!$updated) {
        jg_partner_fail('Partner not found.', 404);
    }

    $database['meta']['version'] = jg_partner_bump_patch((string) $database['meta']['version']);
    jg_partner_write($database);
    jg_partner_response($database);
}

if ($action === 'delete') {
    $code = trim((string) ($request['code'] ?? ''));
    if ($code === '') {
        jg_partner_fail('Partner code is required.');
    }

    $deleted = false;
    foreach ($database['partners'] as $index => $partner) {
        if ((string) ($partner['code'] ?? '') !== $code) {
            continue;
        }

        jg_partner_remove_page((string) ($partner['partner_slug'] ?? ''));
        array_splice($database['partners'], $index, 1);
        $deleted = true;
        break;
    }

    if (!$deleted) {
        jg_partner_fail('Partner not found.', 404);
    }

    $database['meta']['version'] = jg_partner_bump_patch((string) $database['meta']['version']);
    jg_partner_write($database);
    jg_partner_response($database);
}

jg_partner_fail('Unknown action.', 400);
