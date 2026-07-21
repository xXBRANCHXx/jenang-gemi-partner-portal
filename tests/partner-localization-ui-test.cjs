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

console.log('Partner localization and theme UI checks passed.');
