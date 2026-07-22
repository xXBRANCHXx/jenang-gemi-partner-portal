const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const dashboard = fs.readFileSync(path.join(root, 'dashboard.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/index.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

assert.doesNotMatch(markup, /data-timeframe="90d"/, 'the hard-to-read 90-day preset should be removed');
assert.doesNotMatch(markup, /data-timeframe="24h"/, 'the rolling 24-hour preset should be removed');
assert.match(markup, /data-timeframe="today">Today</, 'the chart should offer a clear Today preset');
assert.match(dashboard, /if \(range === 'today'\)[\s\S]*?new Date\(now\.getFullYear\(\), now\.getMonth\(\), now\.getDate\(\)\)/, 'Today should start at local midnight');
assert.match(markup, /type="checkbox" data-chart-month-toggle/, 'month mode should have a checkbox');
assert.match(markup, /type="checkbox" data-chart-custom-toggle/, 'custom mode should have a checkbox');
assert.match(markup, /type="date" data-chart-start-date/, 'custom mode should have a start date');
assert.match(markup, /type="date" data-chart-end-date/, 'custom mode should have an end date');
assert.match(dashboard, /new Date\(bounds\.year, bounds\.monthIndex \+ 1, 0\)\.getDate\(\)/, 'month mode should calculate the selected month length');
assert.match(dashboard, /Array\.from\(\{ length: days \}/, 'month mode should render a bucket for every day');
assert.match(dashboard, /end: new Date\(selectedEnd\.getFullYear\(\), selectedEnd\.getMonth\(\), selectedEnd\.getDate\(\) \+ 1\)/, 'custom end dates should be inclusive');
assert.match(dashboard, /button\.disabled = usesDateFilter/, 'date modes should disable preset toggles');
assert.match(dashboard, /chartMonthToggle\.disabled = usesCustomRange/, 'custom mode should disable month mode');
assert.match(dashboard, /chartCustomToggle\.disabled = usesMonth/, 'month mode should disable custom mode');
assert.match(styles, /\.partner-timeframe-toggle\.is-disabled[\s\S]*?filter:\s*grayscale\(1\)/, 'disabled preset toggles should be visibly grayed out');

console.log('Partner date range UI checks passed.');
