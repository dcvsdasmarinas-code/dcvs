<?php
require_once __DIR__ . '/../includes/reports/location.php';

function expectCoveredCourtCondition($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$csvPath = __DIR__ . '/../dasmarinas_covered_courts_coordinates.csv';
$jsonPath = __DIR__ . '/../geo-map/covered-courts.geojson';
$csv = fopen($csvPath, 'rb');
expectCoveredCourtCondition($csv !== false, 'The supplied covered-court CSV must be readable.');
$headers = fgetcsv($csv, null, ',', '"', '\\');
expectCoveredCourtCondition(is_array($headers), 'The covered-court CSV must have a header.');
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
$rows = [];
$lineNumber = 1;
while (($values = fgetcsv($csv, null, ',', '"', '\\')) !== false) {
    $lineNumber++;
    if (count($values) === 1 && trim((string)$values[0]) === '') continue;
    expectCoveredCourtCondition(count($values) === count($headers), 'Every CSV row must have the expected number of fields.');
    $rows[$lineNumber] = array_combine($headers, $values);
}
fclose($csv);

$snapshot = json_decode(file_get_contents($jsonPath), true);
expectCoveredCourtCondition(
    is_array($snapshot) && ($snapshot['type'] ?? '') === 'FeatureCollection'
        && count($snapshot['features'] ?? []) === count($rows)
        && ($snapshot['metadata']['feature_count'] ?? 0) === count($rows),
    'The covered-court GeoJSON must contain every CSV row.'
);
expectCoveredCourtCondition(
    ($snapshot['metadata']['source'] ?? '') === 'User-provided covered-court coordinates CSV',
    'The covered-court GeoJSON must retain its source description.'
);

$officialBarangays = dcvsLoadOfficialBarangayPolygons(
    __DIR__ . '/../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
    __DIR__ . '/../geo-map/barangays-boundary.json'
);
$unassignedCount = 0;
$seenIds = [];
foreach ($snapshot['features'] as $feature) {
    $properties = $feature['properties'] ?? [];
    $coordinates = $feature['geometry']['coordinates'] ?? [];
    $rowNumber = (int)($properties['csv_row'] ?? 0);
    $row = $rows[$rowNumber] ?? null;
    expectCoveredCourtCondition(is_array($row), 'Every imported court must reference a source CSV row.');
    expectCoveredCourtCondition(
        ($feature['type'] ?? '') === 'Feature'
            && ($feature['geometry']['type'] ?? '') === 'Point'
            && count($coordinates) === 2,
        'Every covered court must have point coordinates.'
    );
    expectCoveredCourtCondition(
        !isset($seenIds[$properties['id'] ?? ''])
            && ($properties['name'] ?? '') === trim((string)$row['Covered Court'])
            && ($properties['category'] ?? '') === 'sports'
            && ($properties['subcategory'] ?? '') === 'covered_court'
            && ($properties['address'] ?? '') === trim((string)$row['Google Maps Address / Reference'])
            && ($properties['coordinate_source'] ?? '') === trim((string)$row['Coordinate Source'])
            && (float)$coordinates[1] === (float)$row['Latitude']
            && (float)$coordinates[0] === (float)$row['Longitude'],
        'Imported court names, addresses, sources, and coordinates must match the CSV.'
    );
    $seenIds[$properties['id']] = true;

    $barangay = dcvsResolveOfficialBarangayPin($coordinates[0], $coordinates[1], $officialBarangays);
    if ($barangay) {
        expectCoveredCourtCondition(
            ($properties['barangay'] ?? '') === $barangay['barangay_name']
                && !isset($properties['boundary_status']),
            'In-boundary courts must be assigned to their containing official barangay.'
        );
    } else {
        expectCoveredCourtCondition(
            ($properties['name'] ?? '') === 'Diamond Village Covered Court'
                && ($properties['barangay'] ?? '') === ''
                && ($properties['boundary_status'] ?? '') === 'outside_official_boundary',
            'The approved Diamond Village pin must remain unassigned and flagged outside the official boundary.'
        );
        $unassignedCount++;
    }
}
expectCoveredCourtCondition($unassignedCount === 1, 'Only the approved outside-boundary location may remain unassigned.');
echo 'Covered-court import checks passed: ' . count($snapshot['features']) . ' CSV locations, '
    . (count($snapshot['features']) - $unassignedCount) . ' assigned to official barangays, '
    . $unassignedCount . ' marked outside official boundaries.' . PHP_EOL;
