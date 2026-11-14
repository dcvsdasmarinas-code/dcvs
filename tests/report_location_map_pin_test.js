import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const reportPage = fs.readFileSync(path.join(__dirname, '..', 'submit-report-page.php'), 'utf8');

assert.match(reportPage, /map\.on\('click', function\(event\)/);
assert.match(reportPage, /Number\(event\.lngLat\.lng\)/);
assert.match(reportPage, /Number\(event\.lngLat\.lat\)/);
assert.match(reportPage, /reportMapPinElement\.addEventListener\('pointerdown'/);
assert.match(reportPage, /reportMapPinElement\.addEventListener\('pointermove'/);
assert.match(reportPage, /reportMapPinElement\.addEventListener\('pointerup'/);
assert.doesNotMatch(reportPage, /reportMapUserMovement|reportMapLastCenter|reportPinMoveTimer/);
assert.match(reportPage, /function isMobileReportLocation\(\)/);
assert.match(reportPage, /function confirmReportMobileLocation\(\)/);
assert.match(reportPage, /map\.on\('moveend', function\(\) \{\s*if \(!isMobileReportLocation\(\) \|\| reportFallbackMode\) return;/);
assert.match(reportPage, /reportMobilePinMoveTimer = window\.setTimeout\(function\(\) \{[\s\S]*?\}, 300\);/);
assert.match(reportPage, /id="report-location-mobile-center-pin"/);
assert.match(reportPage, /mapHint\.textContent = 'Move the map to place the pin'/);

console.log('Report map click and draggable-pin checks passed.');
