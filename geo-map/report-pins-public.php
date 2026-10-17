<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/reports/location.php';

loadDcvsEnv();
$featureEnabled = false;
try {
    $officialFeatures = dcvsLoadOfficialBarangayPolygons(
        __DIR__ . '/../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/barangays-boundary.json'
    );
    $featureEnabled = dcvsReportPinFeatureEnabled($conn, $officialFeatures);
} catch (RuntimeException $exception) {
    error_log('Public report pins readiness check failed: ' . $exception->getMessage());
}
if (!$featureEnabled) {
    http_response_code(503);
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Report pins are not enabled.']);
    exit;
}

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: public, max-age=30, must-revalidate');

$query = $conn->query("SELECT r.coordinates_long, r.coordinates_lat,
        r.report_type, r.status,
        b.barangay_name
    FROM reports r
    LEFT JOIN barangays b ON b.barangay_id = r.barangay_id
    WHERE r.archived_at IS NULL
      AND r.coordinates_lat IS NOT NULL
      AND r.coordinates_long IS NOT NULL
    ORDER BY r.created_at DESC");
if (!$query) {
    error_log('Public report pin query failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load public report pins.']);
    exit;
}

$features = [];
while ($row = $query->fetch_assoc()) {
    $longitude = (float)$row['coordinates_long'];
    $latitude = (float)$row['coordinates_lat'];
    if (!is_finite($longitude) || !is_finite($latitude)
        || $longitude < -180 || $longitude > 180
        || $latitude < -90 || $latitude > 90) {
        continue;
    }
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$longitude, $latitude],
        ],
        'properties' => [
            'type' => (string)$row['report_type'],
            'status' => (string)$row['status'],
            'barangay' => (string)($row['barangay_name'] ?: 'Unknown'),
        ],
    ];
}
$query->free();

echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
