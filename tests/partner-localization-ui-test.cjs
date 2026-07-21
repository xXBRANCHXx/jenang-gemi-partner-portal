const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const dashboard = fs.readFileSync(path.join(root, 'dashboard.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/index.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

[
  ['Add reseller profiles for order entry and reporting. Built-in marketplaces are always available.', 'Tambahkan profil reseller untuk entri pesanan dan laporan. Marketplace bawaan selalu tersedia.'],
  ['Set a new password to finish unlocking this workspace.', 'Tetapkan kata sandi baru untuk menyelesaikan pembukaan ruang kerja ini.'],
  ['Current password', 'Kata sandi saat ini'],
  ['New password', 'Kata sandi baru'],
  ['Confirm new password', 'Konfirmasi kata sandi baru'],
  ['Platform colors appear after the first order.', 'Warna platform muncul setelah pesanan pertama.'],
  ['No orders from the last 7 days.', 'Tidak ada pesanan dalam 7 hari terakhir.']
].forEach(([english, indonesian]) => {
  assert.ok(dashboard.includes(`['${english}', '${indonesian}']`), `missing Indonesian translation for: ${english}`);
});

assert.doesNotMatch(markup, /data-refresh-orders/, 'the redundant chart refresh control should be removed');
assert.match(markup, /partner-settings-modal partner-password-modal[^>]+data-password-modal/, 'password modal should use partner theme styling');
assert.match(styles, /\.partner-sidebar-profile strong[\s\S]*?color:\s*var\(--partner-text\)/, 'partner name should use theme text color');
assert.match(styles, /\.partner-password-modal \.admin-affiliate-field input[\s\S]*?background:\s*var\(--partner-panel-soft\)[\s\S]*?color:\s*var\(--partner-text\)/, 'password fields should use partner theme colors');
assert.match(dashboard, /partner-order-status is-\$\{statusKind\(order\)\}/, 'orders should render their status as a dedicated badge');
assert.match(dashboard, /partner-order-card is-\$\{statusKind\(order\)\}/, 'order cards should expose their status for visual emphasis');
assert.match(dashboard, /localizedText\('Listed', 'Terdaftar'\)/, 'new orders should use the requested localized listed label');
assert.match(dashboard, /localizedText\('Accepted', 'Diterima'\)/, 'accepted orders should use the requested localized label');
assert.match(styles, /\.partner-order-card\.is-listed:not\(\.is-archived\)[\s\S]*?var\(--partner-listed\)/, 'listed order cards should receive a blue accent');
assert.match(styles, /\.partner-order-card\.is-accepted:not\(\.is-archived\)[\s\S]*?var\(--partner-accepted\)/, 'accepted order cards should receive a prominent green accent');
assert.match(styles, /\.partner-order-card\.is-cancelled:not\(\.is-archived\)[\s\S]*?var\(--partner-cancelled\)/, 'cancelled order cards should receive a red accent');
assert.match(styles, /\.partner-order-card\.is-archived\s*\{[\s\S]*?filter:\s*grayscale\(1\)/, 'archived order cards should be grayed out');

console.log('Partner localization and theme UI checks passed.');
