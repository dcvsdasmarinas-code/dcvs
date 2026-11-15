import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const reportPage = fs.readFileSync(path.join(__dirname, '..', 'submit-report-page.php'), 'utf8');
const reportMapPins = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'report-map-pins.js'), 'utf8');

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

const exactLocationSource = reportMapPins.match(/function exactLocation\(location, barangay\) \{[\s\S]*?\n  \}/);
assert.ok(exactLocationSource, 'Report map exact-location formatter should exist.');
const exactLocation = new Function(`${exactLocationSource[0]}\nreturn exactLocation;`)();
assert.equal(
  exactLocation('Augustine Avenue, 4114 Dasmariñas, Philippines', 'Burol'),
  'Augustine Avenue'
);
assert.equal(
  exactLocation('Augustine Avenue, 4114 Dasmarinas, Philippines', 'Burol'),
  'Augustine Avenue'
);

console.log('Report map interaction and location-label checks passed.');
