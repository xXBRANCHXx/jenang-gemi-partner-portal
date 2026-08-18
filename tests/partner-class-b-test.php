<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-class-b-storage.php';

function class_b_expect(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

class_b_expect(!jg_partner_class_b_profile(['partner_class' => 'A']), 'Class A profiles must never enter the prepaid dashboard.');
class_b_expect(jg_partner_class_b_profile(['partner_class' => 'B']), 'Class B profiles must enter the prepaid dashboard.');
class_b_expect(jg_partner_class_b_money('Rp 2,500,000') === 2500000.0, 'Localized deposit input must normalize safely.');
class_b_expect(jg_partner_class_b_estimated_weight([
    ['size' => '550 ml', 'quantity' => 2],
    ['size' => '1 kg', 'quantity' => 1],
]) === 2100, 'Estimated shipment weight must combine every product line.');

$billing = (string) file_get_contents(dirname(__DIR__) . '/partner-billing-storage.php');
class_b_expect(str_contains($billing, 'COALESCE(order_type, "class_a_dropship") <> "class_b_stock"'), 'Class B stock purchases must never create Class A bills.');
$dashboard = (string) file_get_contents(dirname(__DIR__) . '/dashboard/index.php');
class_b_expect(str_contains($dashboard, "partner_class") && str_contains($dashboard, "class-b-dashboard/index.php"), 'Only Class B profiles should route to the new dashboard.');
class_b_expect(str_contains($dashboard, "data-partner-section=\"billing\""), 'The original Class A billing dashboard must remain present.');
$classBDashboard = (string) file_get_contents(dirname(__DIR__) . '/class-b-dashboard/index.php');
class_b_expect(str_contains($classBDashboard, '/admin.css'), 'Class B must share the established Class A visual system.');
class_b_expect(str_contains($classBDashboard, 'data-section="settings"'), 'Class B must include a complete settings view.');
class_b_expect(str_contains($classBDashboard, 'data-language-setting') && str_contains($classBDashboard, 'data-timezone-setting'), 'Class B must support the same language and time settings as Class A.');
class_b_expect(str_contains($classBDashboard, 'data-favicon-form') && str_contains($classBDashboard, 'data-password-form'), 'Class B must support favicon and password management.');
class_b_expect(str_contains($classBDashboard, 'data-delivery-address') && !str_contains($classBDashboard, 'name="recipient_address"'), 'Checkout must show profile delivery details without editable recipient fields.');
$classBDashboardJs = (string) file_get_contents(dirname(__DIR__) . '/class-b-dashboard/class-b-dashboard.js');
class_b_expect(str_contains($classBDashboardJs, "action:'update_preferences'") && str_contains($classBDashboardJs, "action:'change_password'"), 'Class B settings must persist through the shared session API.');
$classBStorage = (string) file_get_contents(dirname(__DIR__) . '/partner-class-b-storage.php');
class_b_expect(!str_contains($classBStorage, "\$payload['recipient_email']") && !str_contains($classBStorage, "\$payload['recipient_address']"), 'Order delivery identity must be sourced from the saved partner profile, never client input.');
$classBApi = (string) file_get_contents(dirname(__DIR__) . '/api/class-b/index.php');
class_b_expect(str_contains($classBApi, 'jg_partner_refresh_current_profile()'), 'Checkout must refresh workspace delivery details before creating an order.');
$partnerAuth = (string) file_get_contents(dirname(__DIR__) . '/partner-auth.php');
class_b_expect(str_contains($partnerAuth, "array_key_exists('partner_class', \$partner)"), 'An incomplete public profile must never replace and invalidate an authenticated partner session.');
$orderStorage = (string) file_get_contents(dirname(__DIR__) . '/partner-order-storage.php');
class_b_expect(str_contains($orderStorage, "=== 'class_b_stock'"), 'Class B shipping labels must remain available in history instead of entering Class A retention cleanup.');
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/partner-data-schema.sql');
foreach (['partner_wallets', 'partner_wallet_transactions', 'partner_deposit_requests', 'partner_stock_events'] as $table) {
    class_b_expect(str_contains($schema, $table), 'Class B schema is missing ' . $table . '.');
}

echo "Partner Class B tests passed.\n";
