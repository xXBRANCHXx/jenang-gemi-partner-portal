const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const markup = fs.readFileSync(path.join(root, 'dashboard/index.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'dashboard.js'), 'utf8');
const billing = fs.readFileSync(path.join(root, 'partner-billing.js'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');
const router = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api', 'billing', 'index.php'), 'utf8');

assert.match(markup, /data-partner-section-link="billing"[\s\S]*data-billing-new/, 'Billing navigation should carry the persistent NEW badge.');
assert.match(markup, /data-partner-account="<\?php echo htmlspecialchars\(hash\('sha256'/, 'Tutorial storage should be scoped to the signed-in partner account without exposing its code.');
assert.match(markup, /data-partner-section="billing"/, 'The partner workspace should expose a Billing page.');
assert.match(markup, /data-billing-tutorial/, 'A first-visit billing tutorial should be present.');
assert.match(dashboard, /billing:\s*'Billing'/, 'Dashboard section routing should recognize Billing.');
assert.match(dashboard, /\['Billing', 'Tagihan'\]/, 'Billing navigation should follow Indonesian language selection.');
assert.match(billing, /submit_payment[\s\S]*submit_dispute/, 'Partners should be able to submit proof or dispute selected orders.');
assert.match(billing, /'Open my bills', 'Buka tagihan saya'/, 'The first-run tutorial must be localized.');
assert.match(billing, /localStorage\.getItem\(tutorialStorageKey\)[\s\S]*localStorage\.setItem\(tutorialStorageKey, 'seen'\)/, 'Tutorial completion should be remembered on each device per partner account.');
assert.match(billing, /newBadge\.hidden = !Boolean\(payload\.onboarding\?\.new_badge_visible\)/, 'The NEW badge should follow its seven-day server window.');
assert.doesNotMatch(billing, /newBadge\.hidden\s*=\s*true/, 'Opening Billing must not dismiss the NEW badge.');
assert.match(markup, /data-billing-hero-title[\s\S]*\$billingStaticCopy/, 'The initial billing hero should be rendered in the selected language.');
assert.match(billing, /renderShellLanguage[\s\S]*'How it works', 'Cara kerja'/, 'Billing shell copy should update when the language setting changes.');
assert.match(api, /jg_partner_billing_localized_error[\s\S]*Tagihan sementara tidak tersedia/, 'Partner-visible API errors should follow the selected language.');
assert.match(billing, /data-billing-dispute-order/, 'Disputes should target exact order IDs.');
assert.match(styles, /\.partner-billing-new[\s\S]*background:\s*#ef2b45/, 'The NEW marker should use the requested red treatment.');
assert.match(styles, /\.partner-billing-workspace[\s\S]*grid-template-columns/, 'Bills and their breakdown should use a readable master-detail layout.');
assert.match(styles, /data-active-section='billing'[\s\S]*grid-template-columns:\s*1fr/, 'Billing should collapse the portal shell at tablet and mobile widths.');
assert.match(router, /api\/billing/, 'Billing API traffic should stay inside the authenticated partner route.');

console.log('Partner billing UI checks passed.');
