<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-preference-storage.php';

function preference_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$defaults = jg_partner_preference_defaults();
preference_expect('id', $defaults['language'], 'Language should default to Indonesian.');
preference_expect('Asia/Jakarta', $defaults['timezone'], 'Time zone should default to WIB.');

$normalized = jg_partner_preference_normalize(['language' => ' EN ', 'timezone' => 'Asia/Makassar']);
preference_expect('en', $normalized['language'], 'English should normalize.');
preference_expect('Asia/Makassar', $normalized['timezone'], 'WITA should be accepted.');

$invalid = false;
try {
    jg_partner_preference_normalize(['language' => 'fr', 'timezone' => 'Europe/Paris']);
} catch (InvalidArgumentException) {
    $invalid = true;
}
preference_expect(true, $invalid, 'Unsupported regional preferences should be rejected.');

echo "partner-preference-test: ok\n";
