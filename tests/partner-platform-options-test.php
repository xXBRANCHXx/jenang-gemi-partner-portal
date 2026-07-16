<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-platform-storage.php';

function partner_platform_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$builtins = jg_partner_platform_builtins();
partner_platform_expect('Shopee', $builtins[0]['name'], 'Shopee should remain the first built-in platform.');
partner_platform_expect('TikTok/Toped', $builtins[1]['name'], 'TikTok/Toped should remain the second built-in platform.');
partner_platform_expect('Bandung Reseller', jg_partner_platform_normalize_name('  Bandung   Reseller  '), 'Custom reseller names should normalize whitespace.');
partner_platform_expect('Shopee Bandung', jg_partner_platform_normalize_name('Shopee Bandung'), 'Custom reseller names may reference a built-in platform without becoming that platform.');

$reservedRejected = false;
try {
    jg_partner_platform_normalize_name('TikTok Shop');
} catch (InvalidArgumentException) {
    $reservedRejected = true;
}
partner_platform_expect(true, $reservedRejected, 'Built-in aliases should not be duplicated as custom platforms.');

echo "partner-platform-options-test: ok\n";
