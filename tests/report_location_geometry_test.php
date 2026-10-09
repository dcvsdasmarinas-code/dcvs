<?php
require_once __DIR__ . '/../includes/reports/location.php';

function expectLocationCondition($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$crosswalkPath = __DIR__ . '/../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv';
$boundaryPath = __DIR__ . '/../geo-map/barangays-boundary.json';
$features = dcvsLoadOfficialBarangayPolygons($crosswalkPath, $boundaryPath);
expectLocationCondition(count($features) === 75, 'Expected 75 official polygon mappings.');

$square = [
    'type' => 'Polygon',
    'coordinates' => [[[0, 0], [2, 0], [2, 2], [0, 2], [0, 0]]],
];
expectLocationCondition(dcvsPointInPolygon(1, 1, $square), 'Interior point should be inside polygon.');
expectLocationCondition(dcvsPointInPolygon(0, 1, $square), 'Polygon boundary should be included.');
expectLocationCondition(!dcvsPointInPolygon(3, 1, $square), 'Exterior point should be outside polygon.');

$polygonWithHole = [
    'type' => 'Polygon',
    'coordinates' => [
        [[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]],
        [[1, 1], [3, 1], [3, 3], [1, 3], [1, 1]],
    ],
];
expectLocationCondition(!dcvsPointInPolygon(2, 2, $polygonWithHole), 'Point inside polygon hole should be excluded.');
expectLocationCondition(dcvsPointInPolygon(0.5, 0.5, $polygonWithHole), 'Point outside polygon hole should remain inside.');

$outsideCity = dcvsResolveOfficialBarangayPin(0, 0, $features);
expectLocationCondition($outsideCity === null, 'A point outside all city polygons should not resolve.');

$testFeatures = [
    [
        'properties' => ['psgc_code' => '0000000001', 'barangay_name' => 'West'],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]],
    ],
    [
        'properties' => ['psgc_code' => '0000000002', 'barangay_name' => 'East'],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[1, 0], [2, 0], [2, 1], [1, 1], [1, 0]]]],
    ],
];
$resolved = dcvsResolveOfficialBarangayPin(0.5, 0.5, $testFeatures);
expectLocationCondition($resolved['psgc_code'] === '0000000001', 'Interior pin should resolve to its PSGC identity.');

$ambiguousBoundaryRejected = false;
try {
    dcvsResolveOfficialBarangayPin(1, 0.5, $testFeatures);
} catch (DomainException $exception) {
    $ambiguousBoundaryRejected = true;
}
expectLocationCondition($ambiguousBoundaryRejected, 'Shared barangay boundaries should be rejected as ambiguous.');

$invalidRejected = false;
try {
    dcvsResolveOfficialBarangayPin(181, 0, $features);
} catch (InvalidArgumentException $exception) {
    $invalidRejected = true;
}
expectLocationCondition($invalidRejected, 'Out-of-range coordinates should be rejected.');

echo "Report location geometry checks passed.\n";
