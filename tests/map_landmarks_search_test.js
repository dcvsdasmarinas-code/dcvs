import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
globalThis.window = { DCVS_APP_BASE_URL: '' };
globalThis.document = {
  currentScript: null,
  querySelectorAll: function() { return []; }
};

const mapLandmarksSource = fs.readFileSync(__dirname + '/../assets/js/map-landmarks.js', 'utf8');
vm.runInThisContext(mapLandmarksSource, { filename: 'map-landmarks.js' });

const poiFontStacks = Array.from(
  mapLandmarksSource.matchAll(/'text-font': \[([^\]]*)\]/g),
  match => match[1]
);
assert.deepStrictEqual(poiFontStacks, [
  "'Open Sans Bold'",
  "'Open Sans Regular'",
  "'Open Sans Bold'",
  "'Open Sans Bold'"
]);
assert.match(mapLandmarksSource, /sourceOptions\.cluster = true/);
assert.match(mapLandmarksSource, /sourceOptions\.clusterRadius = 50/);
assert.match(mapLandmarksSource, /sourceOptions\.clusterMaxZoom = 12/);
assert.match(mapLandmarksSource, /var placesVisible = isReportMap/);
assert.match(mapLandmarksSource, /var landmarkMinZoom = 12/);
assert.match(mapLandmarksSource, /minzoom: isReportMap \? 13 : landmarkMinZoom/);
assert.match(mapLandmarksSource, /'text-variable-anchor': \['top', 'bottom', 'left', 'right'\]/);
assert.match(mapLandmarksSource, /'text-radial-offset': 0\.8/);
assert.match(mapLandmarksSource, /'text-max-width': 8/);
assert.match(mapLandmarksSource, /'symbol-sort-key': \['get', 'rank'\]/);
assert.match(mapLandmarksSource, /'text-field': \['get', 'map_label'\]/);
assert.match(mapLandmarksSource, /'step', \['zoom'\], 0, 12, 1, 14, 2, 16, 3, 17, 4/);
assert.match(mapLandmarksSource, /key: 'shops-food', label: 'Shops and food', checked: true/);
assert.match(mapLandmarksSource, /if \(isStaffMap\) \{\s*applyFilters\(\);\s*return;\s*\}/);
assert.doesNotMatch(mapLandmarksSource, /Places \(' \+ features\.length/);
assert.match(mapLandmarksSource, /Zoom in to level 13 to see place names/);
assert.match(mapLandmarksSource, /map\.on\('zoomend', updateZoomHint\)/);
assert.doesNotMatch(mapLandmarksSource, /user-provided coordinate CSVs/);
assert.strictEqual(
  window.DCVSMapLandmarks.formatMapLabel('Department of Foreign Affairs Consular Office'),
  'Department of Foreign Affairs…'
);
assert.strictEqual(
  window.DCVSMapLandmarks.formatMapLabel('Barangay Hall'),
  'Barangay Hall'
);

window.DCVSMapLandmarks.features = [
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.95, 14.32] },
    properties: {
      name: 'Jollibee',
      category: 'food',
      category_label: 'Food & dining',
      type_label: 'Fast food',
      address: "Governor's Drive",
      barangay: 'Burol',
      accuracy: 'mapped-point',
      source: 'OpenStreetMap'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.96, 14.33] },
    properties: {
      name: 'Dasmarinas Central School',
      category: 'education',
      category_label: 'Schools & universities',
      type_label: 'School',
      address: '',
      barangay: 'San Agustin I',
      accuracy: 'feature-center',
      source: 'OpenStreetMap'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.968203, 14.322237] },
    properties: {
      name: 'San Andres 1 Covered Court',
      category: 'sports',
      category_label: 'Sports & covered courts',
      type_label: 'Covered court',
      address: '8XC9+V7W, Area E, Dasmariñas, Cavite',
      plus_code: '8XC9+V7W',
      barangay: 'San Andres I',
      accuracy: 'user-provided',
      source: 'User-provided covered-court coordinates',
      coordinate_source: 'Google Maps Plus Code'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.97, 14.34] },
    properties: {
      name: 'Verified Barangay Hall',
      category: 'government',
      category_label: 'Government & public services',
      type_label: 'Barangay hall',
      address: '',
      barangay: 'Burol',
      accuracy: 'verified',
      source: 'DCVS verified records'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.9304615, 14.3351255] },
    properties: {
      name: 'San Jose Barangay Hall',
      category: 'government',
      category_label: 'Government & public services',
      type_label: 'Barangay hall',
      address: '',
      barangay: 'San Jose',
      accuracy: 'verified',
      source: 'Google Maps pin provided by user'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.9505396, 14.3339785] },
    properties: {
      name: 'Kolehiyo ng Lungsod ng Dasmariñas (KLD)',
      category: 'education',
      category_label: 'Schools & universities',
      type_label: 'College',
      address: 'Burol Main, Dasmariñas, Cavite',
      barangay: 'Burol',
      accuracy: 'feature-center',
      source: 'OpenStreetMap'
    }
  },
  {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [120.94, 14.33] },
    properties: {
      name: 'Unverified Barangay Hall',
      category: 'government',
      category_label: 'Government & public services',
      type_label: 'Barangay hall',
      address: '',
      barangay: 'San Jose',
      accuracy: 'approximate',
      source: 'DCVS database'
    }
  }
];

const foodMatches = window.DCVSMapLandmarks.suggest('jollibee', 5);
assert.strictEqual(foodMatches.length, 1);
assert.strictEqual(foodMatches[0].name, 'Jollibee');
assert.deepStrictEqual(foodMatches[0].coordinates, [120.95, 14.32]);
assert.strictEqual(foodMatches[0].approximate, false);

const barangayMatches = window.DCVSMapLandmarks.suggest('San Agustin I', 5);
assert.strictEqual(barangayMatches.length, 1);
assert.strictEqual(barangayMatches[0].name, 'Dasmarinas Central School');
assert.strictEqual(barangayMatches[0].approximate, true);

const courtMatches = window.DCVSMapLandmarks.suggest('covered court', 5);
assert.strictEqual(courtMatches.length, 1);
assert.strictEqual(courtMatches[0].name, 'San Andres 1 Covered Court');
assert.strictEqual(courtMatches[0].type_label, 'Covered court');
assert.strictEqual(courtMatches[0].category_label, 'Sports & covered courts');
assert.deepStrictEqual(courtMatches[0].coordinates, [120.968203, 14.322237]);
assert.strictEqual(courtMatches[0].plus_code, '8XC9+V7W');
assert.strictEqual(courtMatches[0].coordinate_source, 'Google Maps Plus Code');
assert.strictEqual(courtMatches[0].approximate, false);

const verifiedMatch = window.DCVSMapLandmarks.suggest('Verified Barangay Hall', 5)
  .find(place => place.name === 'Verified Barangay Hall');
assert.ok(verifiedMatch);
assert.strictEqual(verifiedMatch.approximate, false);

const sanJoseMatch = window.DCVSMapLandmarks.suggest('San Jose Barangay Hall', 5)
  .find(place => place.name === 'San Jose Barangay Hall');
assert.ok(sanJoseMatch);
assert.deepStrictEqual(sanJoseMatch.coordinates, [120.9304615, 14.3351255]);
assert.strictEqual(sanJoseMatch.approximate, false);

const kldMatch = window.DCVSMapLandmarks.suggest('KLD', 5)
  .find(place => place.name === 'Kolehiyo ng Lungsod ng Dasmariñas (KLD)');
assert.ok(kldMatch);
assert.deepStrictEqual(kldMatch.coordinates, [120.9505396, 14.3339785]);
assert.strictEqual(kldMatch.barangay, 'Burol');
assert.strictEqual(kldMatch.approximate, true);

const approximateMatch = window.DCVSMapLandmarks.suggest('Unverified Barangay Hall', 5)
  .find(place => place.name === 'Unverified Barangay Hall');
assert.ok(approximateMatch);
assert.strictEqual(approximateMatch.approximate, true);

const foodIcon = decodeURIComponent(window.DCVSMapLandmarks.iconDataUrl('food').split(',')[1]);
const governmentIcon = decodeURIComponent(window.DCVSMapLandmarks.iconDataUrl('government').split(',')[1]);
const sportsIcon = decodeURIComponent(window.DCVSMapLandmarks.iconDataUrl('sports').split(',')[1]);
assert.match(foodIcon, /<path /);
assert.doesNotMatch(foodIcon, /<circle |<text /);
assert.match(governmentIcon, /<path /);
assert.notStrictEqual(foodIcon, governmentIcon);
assert.match(sportsIcon, /<path /);
assert.doesNotMatch(sportsIcon, /<circle |<text /);
assert.notStrictEqual(sportsIcon, governmentIcon);

console.log('Map landmark search and category icon checks passed.');
