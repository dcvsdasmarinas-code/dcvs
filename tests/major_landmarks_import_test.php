<?php
require_once __DIR__ . '/../includes/reports/location.php';

function expectMajorLandmarkCondition($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$csvPath = __DIR__ . '/../dasmarinas_major_landmarks_coordinates.csv';
$jsonPath = __DIR__ . '/../geo-map/major-landmarks.geojson';
$csv = fopen($csvPath, 'rb');
expectMajorLandmarkCondition($csv !== false, 'The supplied major-landmark CSV must be readable.');
$headers = fgetcsv($csv, null, ',', '"', '\\');
expectMajorLandmarkCondition(is_array($headers), 'The major-landmark CSV must have a header.');
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
$rows = [];
$lineNumber = 1;
while (($values = fgetcsv($csv, null, ',', '"', '\\')) !== false) {
    $lineNumber++;
    if (count($values) === 1 && trim((string)$values[0]) === '') continue;
    expectMajorLandmarkCondition(count($values) === count($headers), 'Every CSV row must have the expected number of fields.');
    $rows[$lineNumber] = array_combine($headers, $values);
}
fclose($csv);

$snapshot = json_decode(file_get_contents($jsonPath), true);
expectMajorLandmarkCondition(
    is_array($snapshot) && ($snapshot['type'] ?? '') === 'FeatureCollection'
        && count($snapshot['features'] ?? []) === count($rows)
        && ($snapshot['metadata']['feature_count'] ?? 0) === count($rows),
    'The major-landmark GeoJSON must contain every CSV row.'
);
expectMajorLandmarkCondition(
    ($snapshot['metadata']['source'] ?? '') === 'User-provided major landmarks coordinates CSV',
    'The major-landmark GeoJSON must retain its source description.'
);

$officialBarangays = dcvsLoadOfficialBarangayPolygons(
    __DIR__ . '/../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
    __DIR__ . '/../geo-map/barangays-boundary.json'
);
$osmSnapshot = json_decode(file_get_contents(__DIR__ . '/../geo-map/osm-pois.geojson'), true);
$osmLocationIds = [
    'Kolehiyo ng Lungsod ng Dasmariñas (KLD)' => 'osm:relation:9467534',
    'Dasmariñas Arena' => 'osm:way:832189771',
    'Pagamutan ng Dasmariñas' => 'osm:relation:8541125',
];
$osmLocationFeatures = [];
foreach (($osmSnapshot['features'] ?? []) as $osmFeature) {
    $osmId = $osmFeature['properties']['id'] ?? '';
    if (in_array($osmId, $osmLocationIds, true)) {
        $osmLocationFeatures[$osmId] = $osmFeature;
    }
}
expectMajorLandmarkCondition(
    count($osmLocationFeatures) === count($osmLocationIds),
    'The mapped OSM features for KLD, the Arena, and Pagamutan must be available.'
);
$unassignedCount = 0;
$seenIds = [];
$kldCorrected = false;
$mappedOsmLocations = [];
foreach ($snapshot['features'] as $feature) {
    $properties = $feature['properties'] ?? [];
    $coordinates = $feature['geometry']['coordinates'] ?? [];
    $rowNumber = (int)($properties['csv_row'] ?? 0);
    $row = $rows[$rowNumber] ?? null;
    expectMajorLandmarkCondition(is_array($row), 'Every major landmark must reference a source CSV row.');
    expectMajorLandmarkCondition(
        ($feature['type'] ?? '') === 'Feature'
            && ($feature['geometry']['type'] ?? '') === 'Point'
            && count($coordinates) === 2,
        'Every major landmark must have point coordinates.'
    );
    $name = (string)($properties['name'] ?? '');
    $isKld = strpos($name, '(KLD)') !== false;
    $osmId = $osmLocationIds[$name] ?? null;
    $osmFeature = $osmId !== null ? ($osmLocationFeatures[$osmId] ?? null) : null;
    $baseFieldsMatch = !isset($seenIds[$properties['id'] ?? ''])
        && $name === trim((string)$row['Landmark / Establishment'])
        && ($properties['address'] ?? '') === trim((string)$row['Google Maps Address']);
    if (is_array($osmFeature)) {
        $osmCoordinates = $osmFeature['geometry']['coordinates'] ?? [];
        expectMajorLandmarkCondition(
            $baseFieldsMatch
                && ($properties['source'] ?? '') === 'OpenStreetMap'
                && ($properties['accuracy'] ?? '') === 'feature-center'
                && ($properties['osm_url'] ?? '') === ($osmFeature['properties']['osm_url'] ?? '')
                && !isset($properties['coordinate_source'])
                && !isset($properties['plus_code'])
                && (float)$coordinates[0] === (float)$osmCoordinates[0]
                && (float)$coordinates[1] === (float)$osmCoordinates[1],
            $name . ' must use its mapped OSM feature center instead of stale CSV coordinates.'
        );
        $mappedOsmLocations[$name] = true;
        if ($isKld) $kldCorrected = true;
    } else {
        expectMajorLandmarkCondition(
            $baseFieldsMatch
                && ($properties['plus_code'] ?? '') === trim((string)$row['Plus Code'])
                && ($properties['accuracy'] ?? '') === 'user-provided'
                && ($properties['coordinate_source'] ?? '') === 'Coordinates supplied in major landmarks CSV'
                && (float)$coordinates[1] === (float)$row['Latitude']
                && (float)$coordinates[0] === (float)$row['Longitude'],
            'Imported landmark names, addresses, plus codes, and coordinates must match the CSV.'
        );
    }
    $seenIds[$properties['id']] = true;

    $barangay = dcvsResolveOfficialBarangayPin($coordinates[0], $coordinates[1], $officialBarangays);
    if ($barangay) {
        expectMajorLandmarkCondition(
            ($properties['barangay'] ?? '') === $barangay['barangay_name']
                && !isset($properties['boundary_status']),
            'In-boundary major landmarks must be assigned to their containing official barangay.'
        );
        if ($isKld) {
            expectMajorLandmarkCondition(
                $barangay['barangay_name'] === 'Burol',
                'The KLD campus feature must be assigned to Burol, not San Agustin II.'
            );
        }
    } else {
        expectMajorLandmarkCondition(
            isset($properties['boundary_status'])
                && $properties['boundary_status'] === 'outside_official_boundary'
                && ($properties['barangay'] ?? '') === '',
            'Only approved out-of-boundary major landmarks may remain unassigned.'
        );
        $unassignedCount++;
    }
}

expectMajorLandmarkCondition($kldCorrected, 'The KLD source coordinate must be corrected.');
expectMajorLandmarkCondition(
    count($mappedOsmLocations) === count($osmLocationIds),
    'KLD, the Arena, and Pagamutan must use their current OSM feature centers.'
);
expectMajorLandmarkCondition($unassignedCount === 7, 'Exactly the seven approved Salitran pins should remain unassigned.');
echo 'Major-landmark import checks passed: ' . count($snapshot['features']) . ' CSV locations, '
    . (count($snapshot['features']) - $unassignedCount) . ' assigned to official barangays, '
    . $unassignedCount . ' marked outside official boundaries; KLD, Arena, and Pagamutan use mapped OSM feature centers.' . PHP_EOL;
