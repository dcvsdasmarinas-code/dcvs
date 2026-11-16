import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(__dirname, '..', 'includes', 'aether-dashboard.php'), 'utf8');
const handler = source.match(/window\.aethOpenPriorityReports = function\(event\) \{[\s\S]*?\n  \};/);

assert.ok(handler, 'Priority Reports navigation handler should exist');
assert.equal((source.match(/onclick="aethOpenPriorityReports\(event\)"/g) || []).length, 3);
assert.equal((source.match(/dashboard-staff\.php#reports-pending/g) || []).length >= 3, true);

let prevented = false;
let menuClicked = false;
const menuLink = { click: () => { menuClicked = true; } };
const window = { location: { assign() { throw new Error('unexpected full-page navigation'); } } };
const document = {
  querySelector: () => menuLink,
  getElementById: () => null
};

vm.runInNewContext(handler[0], { window, document });
window.aethOpenPriorityReports({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, true);
assert.equal(menuClicked, true);

let switchedSection = '';
const fallbackDocument = {
  querySelector: () => null,
  getElementById: () => ({})
};
const fallbackWindow = {
  switchSection: section => { switchedSection = section; },
  location: { assign() { throw new Error('unexpected full-page navigation'); } }
};
vm.runInNewContext(handler[0], {
  window: fallbackWindow,
  document: fallbackDocument
});
fallbackWindow.aethOpenPriorityReports();
assert.equal(switchedSection, 'reports-pending');

console.log('Priority Reports navigation checks passed.');
