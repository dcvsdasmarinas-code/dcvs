<?php
require_once __DIR__ . '/../includes/reports/location.php';

function expectOsmPoiCondition($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$snapshotPath = __DIR__ . '/../geo-map/osm-pois.geojson';
$snapshot = json_decode(file_get_contents($snapshotPath), true);
expectOsmPoiCondition(
    is_array($snapshot) && ($snapshot['type'] ?? '') === 'FeatureCollection',
    'The OSM POI snapshot must be a GeoJSON FeatureCollection.'
);
expectOsmPoiCondition(
    ($snapshot['metadata']['source'] ?? '') === 'OpenStreetMap contributors'
        && ($snapshot['metadata']['license'] ?? '') === 'Open Database License (ODbL) 1.0',
    'The OSM source and ODbL licensing metadata must be present.'
);
expectOsmPoiCondition(
    count($snapshot['features']) === (int)$snapshot['metadata']['feature_count'],
    'The recorded POI count must match the snapshot.'
);

$officialBarangays = dcvsLoadOfficialBarangayPolygons(
    __DIR__ . '/../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
    __DIR__ . '/../geo-map/barangays-boundary.json'
);
$allowedCategories = [
    'food', 'education', 'healthcare', 'shopping', 'worship',
    'government', 'recreation', 'sports', 'community', 'other',
];
$seenIds = [];
$coveredBarangays = [];
$sportsFeatureCount = 0;
$coveredCourtCount = 0;
foreach ($snapshot['features'] as $feature) {
    $properties = $feature['properties'] ?? [];
    $coordinates = $feature['geometry']['coordinates'] ?? [];
    expectOsmPoiCondition(
        ($feature['type'] ?? '') === 'Feature'
            && ($feature['geometry']['type'] ?? '') === 'Point'
            && count($coordinates) === 2,
        'Every OSM place must be represented by a point.'
    );
    expectOsmPoiCondition(
        trim((string)($properties['name'] ?? '')) !== ''
            && in_array($properties['category'] ?? '', $allowedCategories, true),
        'Every mapped place must have a name and a supported category.'
    );
    if (($properties['category'] ?? '') === 'sports') {
        expectOsmPoiCondition(
            ($properties['category_label'] ?? '') === 'Sports & covered courts'
                && ($properties['subcategory'] ?? '') === 'covered_court'
                && ($properties['type_label'] ?? '') === 'Covered court',
            'Covered courts must use the dedicated sports category and type.'
        );
        $sportsFeatureCount++;
        if (preg_match('/\bcovered[\s_-]+court\b/i', (string)($properties['name'] ?? '')) === 1) {
            $coveredCourtCount++;
        }
    }
    expectOsmPoiCondition(
        preg_match('~^osm:(node|way|relation):\d+$~', (string)($properties['id'] ?? '')) === 1
            && !isset($seenIds[$properties['id']]),
        'OSM IDs must be valid and unique.'
    );
    $seenIds[$properties['id']] = true;

    $resolvedBarangay = dcvsResolveOfficialBarangayPin(
        $coordinates[0],
        $coordinates[1],
        $officialBarangays
    );
    expectOsmPoiCondition(
        $resolvedBarangay !== null
            && $resolvedBarangay['barangay_name'] === ($properties['barangay'] ?? ''),
        'Every snapshot point must fall inside its recorded official barangay.'
    );
    $coveredBarangays[$resolvedBarangay['barangay_name']] = true;
}

expectOsmPoiCondition(count($coveredBarangays) > 0, 'The snapshot must cover at least one official barangay.');
expectOsmPoiCondition($sportsFeatureCount > 0, 'The OSM snapshot must include covered courts.');
expectOsmPoiCondition($coveredCourtCount > 0, 'The OSM snapshot must include named covered courts.');
echo 'OSM POI snapshot checks passed: ' . count($snapshot['features']) . ' places across '
    . count($coveredBarangays) . '/75 barangays, including ' . $sportsFeatureCount . ' sports/court venues and '
    . $coveredCourtCount . ' explicitly named covered courts.' . PHP_EOL;
$missingBarangays = [];
foreach ($officialBarangays as $officialBarangay) {
    $name = $officialBarangay['properties']['barangay_name'];
    if (!isset($coveredBarangays[$name])) $missingBarangays[] = $name;
}
if ($missingBarangays) {
    echo 'No matching named OSM POIs were mapped in: ' . implode(', ', $missingBarangays) . PHP_EOL;
}
