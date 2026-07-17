<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/jg-partner-favicon-test-' . bin2hex(random_bytes(4));
putenv('JG_PARTNER_PRIVATE_STORAGE_DIR=' . $testRoot . '/private');

require dirname(__DIR__) . '/partner-favicon-storage.php';

function partner_favicon_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

partner_favicon_expect('light', jg_partner_favicon_theme(' LIGHT '), 'Light theme should normalize.');
partner_favicon_expect('dark', jg_partner_favicon_theme('dark'), 'Dark theme should normalize.');
$invalidThemeRejected = false;
try {
    jg_partner_favicon_theme('system');
} catch (InvalidArgumentException) {
    $invalidThemeRejected = true;
}
partner_favicon_expect(true, $invalidThemeRejected, 'Only light and dark favicon slots should be accepted.');

@mkdir($testRoot, 0700, true);
$pngPath = $testRoot . '/favicon.png';
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAQAAAC1+jfqAAAADElEQVR42mNkIAAYRxWAAQAG9AABT8rZ9QAAAABJRU5ErkJggg==', true);
file_put_contents($pngPath, $pngBytes);
$validated = jg_partner_favicon_validate_upload([
    'name' => 'favicon.png',
    'tmp_name' => $pngPath,
    'size' => filesize($pngPath),
    'error' => UPLOAD_ERR_OK,
]);
partner_favicon_expect('png', $validated['extension'], 'A square PNG should be accepted.');
partner_favicon_expect('image/png', $validated['mime_type'], 'PNG MIME type should be normalized.');

$invalidPath = $testRoot . '/fake.png';
file_put_contents($invalidPath, 'not an image');
$invalidFileRejected = false;
try {
    jg_partner_favicon_validate_upload([
        'name' => 'fake.png',
        'tmp_name' => $invalidPath,
        'size' => filesize($invalidPath),
        'error' => UPLOAD_ERR_OK,
    ]);
} catch (InvalidArgumentException) {
    $invalidFileRejected = true;
}
partner_favicon_expect(true, $invalidFileRejected, 'Invalid image bytes should be rejected.');

$empty = jg_partner_favicon_public_settings_from_records([], '/acme/api/favicon/');
partner_favicon_expect(false, $empty['light']['configured'], 'Light favicon should start empty.');
partner_favicon_expect(false, $empty['dark']['configured'], 'Dark favicon should start empty.');

@unlink($pngPath);
@unlink($invalidPath);
@rmdir($testRoot . '/private/favicons');
@rmdir($testRoot . '/private');
@rmdir($testRoot);

echo "partner-favicon-test: ok\n";
