const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const endpoint = fs.readFileSync(path.join(root, 'api/store-orders/index.php'), 'utf8');
const storage = fs.readFileSync(path.join(root, 'partner-return-storage.php'), 'utf8');
const billing = fs.readFileSync(path.join(root, 'partner-billing-storage.php'), 'utf8');

assert.match(endpoint, /\$_GET\['action'\][\s\S]*return_catalog/);
assert.match(endpoint, /apply_return_adjustment/);
assert.match(storage, /'restock' => \['rate_basis_points' => 1500/);
assert.match(storage, /'damaged' => \['rate_basis_points' => 4000/);
assert.match(storage, /'unrecoverable' => \['rate_basis_points' => 10000/);
assert.match(storage, /jg_partner_billing_recalculate_bill\(\$pdo, \$billId\)/);
assert.match(billing, /CREATE TABLE IF NOT EXISTS partner_return_adjustments/);

console.log('partner-return-contract-test: ok');
