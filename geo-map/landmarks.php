<?php
/** Return active mapped landmarks to authenticated resident, staff, or barangay maps. */
$sessionRoles = [
    'DCVS_STAFF_SESSION' => 'staff',
    'DCVS_BARANGAY_SESSION' => 'barangay',
    'resident' => 'resident',
];
$sessionKey = trim(is_string($_GET['sess'] ?? null) ? $_GET['sess'] : '');
if (!isset($sessionRoles[$sessionKey])) {
    http_response_code(400);
    header('Content-Type: application/geo+json; charset=utf-8');
    echo json_encode(['error' => 'Invalid session type.']);
    exit;
}

if ($sessionKey !== 'resident') {
    session_name($sessionKey);
}
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Vary: Cookie');

$expectedRole = $sessionRoles[$sessionKey];
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $expectedRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if ($expectedRole === 'barangay' && $barangayId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Barangay scope is missing.']);
    exit;
}

$sql = "SELECT l.landmark_id, l.name, l.address, l.category, l.barangay_id,
            l.coordinates_long, l.coordinates_lat, l.location_accuracy,
            b.barangay_name
        FROM landmarks l
        LEFT JOIN barangays b ON b.barangay_id = l.barangay_id
        WHERE l.is_active = 1
          AND l.location_accuracy IN ('approximate', 'verified')
          AND l.coordinates_long BETWEEN 120.78 AND 121.10
          AND l.coordinates_lat BETWEEN 14.17 AND 14.49";
if ($expectedRole === 'barangay') {
    $sql .= ' AND l.barangay_id = ?';
}
$sql .= ' ORDER BY l.category, l.name, l.landmark_id';

$statement = $conn->prepare($sql);
if (!$statement) {
    error_log('Landmark map query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load mapped landmarks.']);
    exit;
}
if ($expectedRole === 'barangay') {
    $statement->bind_param('i', $barangayId);
}
if (!$statement->execute()) {
    error_log('Landmark map query failed: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not load mapped landmarks.']);
    exit;
}

$features = [];
$result = $statement->get_result();
while ($row = $result->fetch_assoc()) {
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$row['coordinates_long'], (float)$row['coordinates_lat']],
        ],
        'properties' => [
            'id' => (int)$row['landmark_id'],
            'name' => $row['name'],
            'address' => $row['address'] ?? '',
            'category' => $row['category'],
            'is_dcvs_office' => $row['name'] === 'Dasmariñas City Veterinary Services Office',
            'is_own_barangay_hall' => $expectedRole === 'barangay'
                && $row['category'] === 'barangay_hall'
                && (int)$row['barangay_id'] === $barangayId,
            'barangay' => $row['barangay_name'] ?? '',
            'accuracy' => $row['location_accuracy'],
        ],
    ];
}
$statement->close();

echo json_encode(
    ['type' => 'FeatureCollection', 'features' => $features],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
