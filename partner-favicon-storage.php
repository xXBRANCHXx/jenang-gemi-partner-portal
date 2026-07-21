<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-order-storage.php';

const JG_PARTNER_FAVICON_MAX_BYTES = 1024 * 1024;
const JG_PARTNER_FAVICON_JSON_FILE = __DIR__ . '/data/partner-favicons.json';

function jg_partner_favicon_theme(string $theme): string
{
    $theme = strtolower(trim($theme));
    if (!in_array($theme, ['light', 'dark'], true)) {
        throw new InvalidArgumentException('Favicon theme must be light or dark.');
    }
    return $theme;
}

function jg_partner_favicon_directory(): string
{
    $directory = jg_partner_order_private_storage_root() . '/favicons';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private favicon storage is unavailable.');
    }
    @chmod($directory, 0700);
    return $directory;
}

function jg_partner_favicon_file_path(array $record): ?string
{
    $storedName = basename(trim((string) ($record['stored_name'] ?? '')));
    if ($storedName === '' || $storedName === '.' || $storedName === '..') {
        return null;
    }
    $path = jg_partner_favicon_directory() . '/' . $storedName;
    return is_file($path) ? $path : null;
}

/** @return array{extension:string,mime_type:string,size_bytes:int} */
function jg_partner_favicon_validate_upload(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a PNG or ICO favicon.');
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'ico'], true)) {
        throw new InvalidArgumentException('Favicon must be a PNG or ICO file.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('The favicon upload is unavailable.');
    }
    $size = (int) ($file['size'] ?? filesize($tmpName) ?: 0);
    if ($size <= 0 || $size > JG_PARTNER_FAVICON_MAX_BYTES) {
        throw new InvalidArgumentException('Favicon must be no larger than 1 MB.');
    }

    $header = (string) @file_get_contents($tmpName, false, null, 0, 8);
    if ($extension === 'png') {
        if ($header !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('Favicon must be a valid PNG file.');
        }
        $dimensions = @getimagesize($tmpName);
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 16 || $width > 1024 || $height !== $width) {
            throw new InvalidArgumentException('PNG favicon must be square and between 16×16 and 1024×1024 pixels.');
        }
        return ['extension' => 'png', 'mime_type' => 'image/png', 'size_bytes' => $size];
    }

    if (substr($header, 0, 4) !== "\x00\x00\x01\x00") {
        throw new InvalidArgumentException('Favicon must be a valid ICO file.');
    }
    return ['extension' => 'ico', 'mime_type' => 'image/x-icon', 'size_bytes' => $size];
}

function jg_partner_favicon_json_read(): array
{
    if (!is_file(JG_PARTNER_FAVICON_JSON_FILE)) {
        return ['favicons' => []];
    }
    $decoded = json_decode((string) file_get_contents(JG_PARTNER_FAVICON_JSON_FILE), true);
    return is_array($decoded) && is_array($decoded['favicons'] ?? null) ? $decoded : ['favicons' => []];
}

function jg_partner_favicon_json_write(array $database): void
{
    $directory = dirname(JG_PARTNER_FAVICON_JSON_FILE);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Favicon metadata storage is unavailable.');
    }
    $encoded = json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode favicon metadata.');
    }
    $temporary = JG_PARTNER_FAVICON_JSON_FILE . '.tmp';
    if (file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !@rename($temporary, JG_PARTNER_FAVICON_JSON_FILE)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to save favicon metadata.');
    }
}

/** @return array<string, array<string, mixed>> */
function jg_partner_favicon_list(string $partnerCode): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    $records = [];
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'SELECT partner_code, theme, original_name, stored_name, mime_type, size_bytes, created_at, updated_at
             FROM partner_favicons WHERE partner_code = :partner_code'
        );
        $stmt->execute([':partner_code' => $partnerCode]);
        foreach ($stmt->fetchAll() as $record) {
            if (is_array($record)) {
                $records[(string) ($record['theme'] ?? '')] = $record;
            }
        }
    } elseif (jg_partner_data_mysql_is_configured()) {
        throw new RuntimeException('Favicon storage is temporarily unavailable.');
    } else {
        foreach (jg_partner_favicon_json_read()['favicons'] as $record) {
            if (is_array($record) && strtoupper((string) ($record['partner_code'] ?? '')) === $partnerCode) {
                $records[(string) ($record['theme'] ?? '')] = $record;
            }
        }
    }

    foreach ($records as $theme => $record) {
        if (!in_array($theme, ['light', 'dark'], true) || jg_partner_favicon_file_path($record) === null) {
            unset($records[$theme]);
        }
    }
    return $records;
}

function jg_partner_favicon_find(string $partnerCode, string $theme): ?array
{
    $theme = jg_partner_favicon_theme($theme);
    return jg_partner_favicon_list($partnerCode)[$theme] ?? null;
}

function jg_partner_favicon_public_settings(string $partnerCode, string $endpoint): array
{
    return jg_partner_favicon_public_settings_from_records(jg_partner_favicon_list($partnerCode), $endpoint);
}

function jg_partner_favicon_public_settings_from_records(array $records, string $endpoint): array
{
    $settings = [];
    foreach (['light', 'dark'] as $theme) {
        $record = $records[$theme] ?? null;
        $updatedAt = is_array($record) ? (string) ($record['updated_at'] ?? '') : '';
        $settings[$theme] = [
            'configured' => is_array($record),
            'name' => is_array($record) ? (string) ($record['original_name'] ?? '') : '',
            'mime_type' => is_array($record) ? (string) ($record['mime_type'] ?? '') : '',
            'size_bytes' => is_array($record) ? (int) ($record['size_bytes'] ?? 0) : 0,
            'updated_at' => $updatedAt,
            'url' => is_array($record) ? $endpoint . '?' . http_build_query([
                'theme' => $theme,
                'v' => strtotime($updatedAt) ?: time(),
            ]) : '',
        ];
    }
    return $settings;
}

function jg_partner_favicon_save(string $partnerCode, string $theme, array $file): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    $theme = jg_partner_favicon_theme($theme);
    $validated = jg_partner_favicon_validate_upload($file);
    $existing = jg_partner_favicon_find($partnerCode, $theme);
    $storedName = sprintf(
        '%s-%s-%s.%s',
        preg_replace('/[^a-z0-9]+/i', '-', strtolower($partnerCode)) ?: 'partner',
        $theme,
        bin2hex(random_bytes(8)),
        $validated['extension']
    );
    $targetPath = jg_partner_favicon_directory() . '/' . $storedName;
    if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('Unable to save favicon.');
    }
    @chmod($targetPath, 0600);

    $now = gmdate(DATE_ATOM);
    $record = [
        'partner_code' => $partnerCode,
        'theme' => $theme,
        'original_name' => mb_substr(trim((string) ($file['name'] ?? 'favicon.' . $validated['extension'])), 0, 255),
        'stored_name' => $storedName,
        'mime_type' => $validated['mime_type'],
        'size_bytes' => $validated['size_bytes'],
        'created_at' => (string) ($existing['created_at'] ?? $now),
        'updated_at' => $now,
    ];

    try {
        $pdo = jg_partner_data_db();
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare(
                'INSERT INTO partner_favicons
                    (partner_code, theme, original_name, stored_name, mime_type, size_bytes, created_at, updated_at)
                 VALUES
                    (:partner_code, :theme, :original_name, :stored_name, :mime_type, :size_bytes, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    original_name = VALUES(original_name), stored_name = VALUES(stored_name),
                    mime_type = VALUES(mime_type), size_bytes = VALUES(size_bytes), updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                ':partner_code' => $partnerCode,
                ':theme' => $theme,
                ':original_name' => $record['original_name'],
                ':stored_name' => $storedName,
                ':mime_type' => $record['mime_type'],
                ':size_bytes' => $record['size_bytes'],
                ':created_at' => gmdate('Y-m-d H:i:s', strtotime($record['created_at']) ?: time()),
                ':updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } elseif (jg_partner_data_mysql_is_configured()) {
            throw new RuntimeException('Favicon storage is temporarily unavailable.');
        } else {
            $database = jg_partner_favicon_json_read();
            $database['favicons'] = array_values(array_filter(
                $database['favicons'],
                static fn (mixed $candidate): bool => !is_array($candidate)
                    || strtoupper((string) ($candidate['partner_code'] ?? '')) !== $partnerCode
                    || (string) ($candidate['theme'] ?? '') !== $theme
            ));
            $database['favicons'][] = $record;
            jg_partner_favicon_json_write($database);
        }
    } catch (Throwable $exception) {
        @unlink($targetPath);
        throw $exception;
    }

    if (is_array($existing)) {
        $oldPath = jg_partner_favicon_file_path($existing);
        if ($oldPath !== null && $oldPath !== $targetPath) {
            @unlink($oldPath);
        }
    }
    return $record;
}

function jg_partner_favicon_delete(string $partnerCode, string $theme): bool
{
    $partnerCode = strtoupper(trim($partnerCode));
    $theme = jg_partner_favicon_theme($theme);
    $existing = jg_partner_favicon_find($partnerCode, $theme);
    if (!is_array($existing)) {
        return false;
    }

    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('DELETE FROM partner_favicons WHERE partner_code = :partner_code AND theme = :theme');
        $stmt->execute([':partner_code' => $partnerCode, ':theme' => $theme]);
    } elseif (jg_partner_data_mysql_is_configured()) {
        throw new RuntimeException('Favicon storage is temporarily unavailable.');
    } else {
        $database = jg_partner_favicon_json_read();
        $database['favicons'] = array_values(array_filter(
            $database['favicons'],
            static fn (mixed $candidate): bool => !is_array($candidate)
                || strtoupper((string) ($candidate['partner_code'] ?? '')) !== $partnerCode
                || (string) ($candidate['theme'] ?? '') !== $theme
        ));
        jg_partner_favicon_json_write($database);
    }

    $path = jg_partner_favicon_file_path($existing);
    if ($path !== null) {
        @unlink($path);
    }
    return true;
}

function jg_partner_favicon_stream(
    string $partnerCode,
    string $theme,
    string $cacheControl = 'private, max-age=86400'
): never
{
    $record = jg_partner_favicon_find($partnerCode, $theme);
    $path = is_array($record) ? jg_partner_favicon_file_path($record) : null;
    if (!is_array($record) || $path === null) {
        throw new RuntimeException('Favicon is not configured.');
    }

    $etag = '"partner-favicon-' . hash_file('sha256', $path) . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . (string) ($record['mime_type'] ?? 'image/png'));
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: ' . $cacheControl);
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
