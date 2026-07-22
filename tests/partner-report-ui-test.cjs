const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const dashboard = fs.readFileSync(path.join(root, 'dashboard.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/index.php'), 'utf8');
const router = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api/reports/index.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

assert.match(markup, /data-partner-section-link="reports">Reports</, 'Reports should be available in partner navigation');
assert.match(markup, /data-partner-section="reports"/, 'the dashboard should include a Reports page');
assert.match(markup, /data-report-start[\s\S]*data-report-end/, 'partners should be able to select a custom report period');
assert.match(markup, /value="channels"[\s\S]*value="products"[\s\S]*value="orders"/, 'partners should choose optional report sections');
assert.match(markup, /data-report-download/, 'the report builder should expose a PDF download action');
assert.match(dashboard, /root\.dataset\.reportsEndpoint/, 'the report UI should use the partner-scoped reports endpoint');
assert.match(dashboard, /response\.blob\(\)/, 'successful PDF responses should download as a file');
assert.match(dashboard, /localizedText\('Generating PDF…'/, 'report progress should be localized');
assert.match(router, /api\/reports/, 'partner-scoped report routes should be protected by the workspace router');
assert.match(api, /jg_partner_require_auth_json\(\)/, 'direct report requests should require partner authentication');
assert.match(api, /jg_partner_preferences\(\$partnerCode\)/, 'PDF rendering should use saved regional preferences');
assert.match(api, /jg_partner_favicon_list\(\$partnerCode\)/, 'PDF rendering should use the partner favicon when configured');
assert.match(api, /'icon_path' => \$iconPath/, 'the selected partner icon should be passed to the production renderer');
assert.match(api, /Content-Type: application\/pdf/, 'the report endpoint should return a PDF');
assert.match(styles, /\.partner-report-paper[\s\S]*aspect-ratio:\s*210\s*\/\s*297/, 'the on-page preview should use A4 proportions');

console.log('Partner report UI checks passed.');
