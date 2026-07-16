<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-auth.php';

function partner_auth_profile_expect(bool $expected, bool $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

partner_auth_profile_expect(true, jg_partner_profile_has_complete_sku_access([
    'selected_skus' => [],
]), 'Partners with no approved SKU codes should have a complete profile.');

partner_auth_profile_expect(false, jg_partner_profile_has_complete_sku_access([
    'selected_skus' => ['JG-001'],
]), 'Approved SKU codes without records should invalidate the cached profile.');

partner_auth_profile_expect(true, jg_partner_profile_has_complete_sku_access([
    'selected_skus' => ['JG-001'],
    'selected_sku_records' => [
        ['sku' => 'JG-001'],
    ],
]), 'Approved SKU records should keep the cached profile valid.');

echo "partner-auth-profile-test: ok\n";
