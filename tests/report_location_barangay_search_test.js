import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const reportPage = fs.readFileSync(path.join(__dirname, '..', 'submit-report-page.php'), 'utf8');

assert.match(reportPage, /function focusReportBarangay\(code\)/);
assert.match(reportPage, /reportMapInstance\.fitBounds\(bounds, \{ padding: 48, maxZoom: 15\.5, duration: 500 \}\)/);
assert.doesNotMatch(reportPage, /beginReportProgrammaticMove/);

console.log('Report barangay search focus checks passed.');
