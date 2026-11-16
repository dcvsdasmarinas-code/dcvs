import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const pinSource = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'report-map-pins.js'), 'utf8');
const mapStyles = fs.readFileSync(path.join(__dirname, '..', 'geo-map', 'map.css'), 'utf8');

assert.match(pinSource, /'match', \['downcase', \['to-string', \['get', 'status'\]\]\]/);
assert.match(pinSource, /'pending', '#92400e'/);
assert.match(pinSource, /'validated', '#166534'/);
assert.match(pinSource, /'resolved', '#166534'/);
assert.match(pinSource, /'rejected', '#991b1b'/);
assert.doesNotMatch(pinSource, /'match', \['get', 'priority'\]/);
assert.match(mapStyles, /\.dcvs-report-pin-status-pending\s*\{[^}]*color:\s*#92400e/s);
assert.match(mapStyles, /\.dcvs-report-pin-status-resolved\s*\{[^}]*color:\s*#166534/s);
assert.match(mapStyles, /\.dcvs-report-pin-status-rejected\s*\{[^}]*color:\s*#991b1b/s);

console.log('Report map pin status color checks passed.');
