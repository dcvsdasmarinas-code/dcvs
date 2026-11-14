import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const reportScript = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'reports-database.js'), 'utf8');
const staffPage = fs.readFileSync(path.join(__dirname, '..', 'pages', 'staff', 'dashboard-staff.php'), 'utf8');

const helper = reportScript.match(/function reportDateInRange\(timestamp, dateFrom, dateTo\) \{[\s\S]*?\n  \}/);
assert.ok(helper, 'shared report date-range helper should exist');
const reportDateInRange = vm.runInNewContext('(' + helper[0] + ')');
const selectedDay = new Date(2026, 9, 23);
const withinSelectedDay = new Date(2026, 9, 23, 12).getTime();

assert.equal(reportDateInRange(withinSelectedDay, '2026-10-23', '2026-10-23'), true);
assert.equal(reportDateInRange(new Date(2026, 9, 22, 23, 59, 59).getTime(), '2026-10-23', ''), false);
assert.equal(reportDateInRange(new Date(2026, 9, 24).getTime(), '', '2026-10-23'), false);
assert.ok(selectedDay.getTime() < withinSelectedDay);

assert.match(reportScript, /reportDateInRange\(report\.ts, dateFrom, dateTo\)/);
assert.match(reportScript, /window\.rptOverdueSetDate = function/);
assert.match(staffPage, /data-date-filter="rptOverdue"/);
assert.match(staffPage, /id="rptOverdueDateFrom"/);
assert.match(staffPage, /text-transform:none;letter-spacing:normal/);
assert.match(staffPage, /#reports-overdue tbody td\{[^}]*font-size:14px;color:#18181b/s);

console.log('Overdue reports date filter and table style checks passed.');
