<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/jg-partner-label-test-' . bin2hex(random_bytes(4));
putenv('JG_PARTNER_PRIVATE_STORAGE_DIR=' . $testRoot . '/private');

require dirname(__DIR__) . '/partner-order-storage.php';

function label_security_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

function label_security_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $child = $path . '/' . $item;
        is_dir($child) ? label_security_remove_tree($child) : @unlink($child);
    }
    @rmdir($path);
}

$now = strtotime('2026-07-14T00:00:00Z');
label_security_expect(
    '2026-07-21T00:00:00+00:00',
    jg_partner_order_label_expiration_for_status('IS_LISTED', $now),
    'Open labels should have a seven-day safety cap.'
);
label_security_expect(
    '2026-07-17T00:00:00+00:00',
    jg_partner_order_label_expiration_for_status('FULFILLED', $now),
    'Fulfilled labels should expire after three days.'
);
label_security_expect(
    '2026-07-15T00:00:00+00:00',
    jg_partner_order_label_expiration_for_status('CANCELLED', $now),
    'Cancelled labels should expire after one day.'
);
label_security_expect(true, jg_partner_order_label_is_expired([
    'expires_at' => '2026-07-14T00:00:00Z',
], $now), 'A label at its expiry timestamp should be unavailable.');

$token = 'test-store-ops-secret';
$expires = $now + 300;
$signature = jg_partner_order_sign_store_download('PO123', $expires, $token);
label_security_expect(true, jg_partner_order_verify_store_download('PO123', $expires, $signature, $token, $now), 'Valid Store Ops signatures should pass.');
label_security_expect(false, jg_partner_order_verify_store_download('PO124', $expires, $signature, $token, $now), 'A signature must be bound to its order.');
label_security_expect(false, jg_partner_order_verify_store_download('PO123', $now - 1, $signature, $token, $now), 'Expired Store Ops signatures should fail.');

$uploadDirectory = jg_partner_order_upload_directory();
label_security_expect($testRoot . '/private/shipping-labels', $uploadDirectory, 'Labels should use private storage.');

$pdf = $testRoot . '/valid.pdf';
@mkdir($testRoot, 0700, true);
file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
jg_partner_order_assert_pdf_upload([
    'name' => 'label.pdf',
    'tmp_name' => $pdf,
    'size' => filesize($pdf),
]);

$tooLarge = $testRoot . '/too-large.pdf';
$handle = fopen($tooLarge, 'wb');
fwrite($handle, '%PDF-');
ftruncate($handle, JG_PARTNER_LABEL_MAX_BYTES + 1);
fclose($handle);
$oversizeRejected = false;
try {
    jg_partner_order_assert_pdf_upload([
        'name' => 'large.pdf',
        'tmp_name' => $tooLarge,
        'size' => JG_PARTNER_LABEL_MAX_BYTES + 1,
    ]);
} catch (InvalidArgumentException $exception) {
    $oversizeRejected = str_contains($exception->getMessage(), '10 MB');
}
label_security_expect(true, $oversizeRejected, 'Oversized labels should be rejected.');

$candidates = jg_partner_order_label_file_candidates(['stored_name' => '../../secret.pdf']);
label_security_expect(true, str_ends_with($candidates[0] ?? '', '/shipping-labels/secret.pdf'), 'Stored filenames should be reduced to a basename.');

$availablePdfPath = $uploadDirectory . '/available-label.pdf';
file_put_contents($availablePdfPath, "%PDF-1.4\n%%EOF\n");
label_security_expect($availablePdfPath, jg_partner_order_label_pdf_file_path([
    'stored_name' => 'available-label.pdf',
    'created_at' => gmdate(DATE_ATOM),
]), 'An available PDF should resolve to its private file.');
label_security_expect(true, jg_partner_order_has_available_label_pdf([
    'labels' => [[
        'stored_name' => 'available-label.pdf',
        'created_at' => gmdate(DATE_ATOM),
    ]],
]), 'Orders with an available PDF should be eligible for the Store Ops feed.');
file_put_contents($uploadDirectory . '/not-a-pdf.pdf', "not a pdf\n");
label_security_expect(null, jg_partner_order_label_pdf_file_path([
    'stored_name' => 'not-a-pdf.pdf',
    'created_at' => gmdate(DATE_ATOM),
]), 'Non-PDF files must not be exposed as Store Ops shipping labels.');
@unlink($availablePdfPath);
@unlink($uploadDirectory . '/not-a-pdf.pdf');

label_security_remove_tree($testRoot);
echo "label-security-test: ok\n";
