<?php
/** Return Google Maps directions for an authorized report pin. */
$sessionRoles = [
    'DCVS_STAFF_SESSION' => 'staff',
    'DCVS_BARANGAY_SESSION' => 'barangay',
];
$sessionName = trim(is_string($_GET['sess'] ?? null) ? $_GET['sess'] : '');
if (!isset($sessionRoles[$sessionName])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid session type.']);
    exit;
}

session_name($sessionName);
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Vary: Cookie');

$expectedRole = $sessionRoles[$sessionName];
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $expectedRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$reportIdValue = $_GET['report_id'] ?? '';
if (!is_scalar($reportIdValue) || !ctype_digit((string)$reportIdValue) || (int)$reportIdValue <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid report is required.']);
    exit;
}
$reportId = (int)$reportIdValue;
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);

if ($expectedRole === 'barangay') {
    if ($barangayId <= 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Barangay scope is missing.']);
        exit;
    }
    $reportStatement = $conn->prepare(
        "SELECT r.coordinates_long, r.coordinates_lat, r.barangay_id, b.barangay_name,
                rw.response_mode, rw.state AS response_state
         FROM reports r
         INNER JOIN barangays b ON b.barangay_id = r.barangay_id
         LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
         LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
         WHERE r.report_id = ? AND r.barangay_id = ? AND r.archived_at IS NULL
           AND r.location_status = 'pinned'
           AND r.coordinates_long IS NOT NULL AND r.coordinates_lat IS NOT NULL
           AND (r.status IN ('pending', 'validated', 'confirmed', 'scheduled')
                OR (r.status = 'resolved' AND tt.status = 'pending'))"
    );
    if (!$reportStatement) {
        error_log('Report directions authorization query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Could not check directions access.']);
        exit;
    }
    $reportStatement->bind_param('ii', $reportId, $barangayId);
} else {
    $reportStatement = $conn->prepare(
        "SELECT r.coordinates_long, r.coordinates_lat, r.barangay_id, b.barangay_name,
                rw.response_mode, rw.state AS response_state
         FROM reports r
         INNER JOIN barangays b ON b.barangay_id = r.barangay_id
         LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
         LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
         LEFT JOIN confirmed_cases cc ON cc.report_id = r.report_id AND cc.status = 'active'
         WHERE r.report_id = ? AND r.archived_at IS NULL
           AND r.location_status = 'pinned'
           AND r.coordinates_long IS NOT NULL AND r.coordinates_lat IS NOT NULL
           AND (cc.report_id IS NOT NULL OR r.status IN ('pending', 'validated')
                OR (r.status = 'resolved' AND tt.status = 'pending'))"
    );
    if (!$reportStatement) {
        error_log('Report directions authorization query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Could not check directions access.']);
        exit;
    }
    $reportStatement->bind_param('i', $reportId);
}

if (!$reportStatement->execute()) {
    error_log('Report directions authorization query failed: ' . $reportStatement->error);
    $reportStatement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not check directions access.']);
    exit;
}
$report = $reportStatement->get_result()->fetch_assoc();
$reportStatement->close();
if (!$report) {
    http_response_code(404);
    echo json_encode(['error' => 'This pinned report is not available to your account.']);
    exit;
}

$barangayResponseStates = [
    'barangay_response_approved', 'barangay_responding', 'animal_secured',
    'awaiting_turnover', 'resolved', 'returned_to_barangay',
];
$dcvsTakeoverStates = ['escalated_to_dcvs', 'dcvs_dispatch', 'dcvs_processing'];
$isBarangayResponseRoute = $report['response_mode'] === 'barangay_response'
    && in_array($report['response_state'], $barangayResponseStates, true);
$useBarangayHall = $isBarangayResponseRoute
    || ($expectedRole === 'barangay' && !in_array($report['response_state'], $dcvsTakeoverStates, true));

if ($useBarangayHall) {
    $originStatement = $conn->prepare(
        "SELECT name, coordinates_long, coordinates_lat
         FROM landmarks
         WHERE barangay_id = ? AND category = 'barangay_hall'
           AND is_active = 1 AND location_accuracy IN ('approximate', 'verified')
         ORDER BY location_accuracy = 'verified' DESC, landmark_id
         LIMIT 1"
    );
} else {
    $originStatement = $conn->prepare(
        "SELECT name, coordinates_long, coordinates_lat
         FROM landmarks
         WHERE name = 'Dasmariñas City Veterinary Services Office'
           AND is_active = 1 AND location_accuracy IN ('approximate', 'verified')
         ORDER BY location_accuracy = 'verified' DESC, landmark_id
         LIMIT 1"
    );
}
if (!$originStatement) {
    error_log('Report directions origin query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load the directions starting point.']);
    exit;
}
if ($useBarangayHall) {
    $routeBarangayId = (int)$report['barangay_id'];
    $originStatement->bind_param('i', $routeBarangayId);
}
if (!$originStatement->execute()) {
    error_log('Report directions origin query failed: ' . $originStatement->error);
    $originStatement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not load the directions starting point.']);
    exit;
}
$origin = $originStatement->get_result()->fetch_assoc();
$originStatement->close();
if (!$origin) {
    http_response_code(409);
    echo json_encode(['error' => 'No active office location is configured for these directions.']);
    exit;
}

$originLongitude = (float)$origin['coordinates_long'];
$originLatitude = (float)$origin['coordinates_lat'];
$destinationLongitude = (float)$report['coordinates_long'];
$destinationLatitude = (float)$report['coordinates_lat'];
foreach ([$originLongitude, $originLatitude, $destinationLongitude, $destinationLatitude] as $coordinate) {
    if (!is_finite($coordinate)) {
        http_response_code(422);
        echo json_encode(['error' => 'The directions coordinates are invalid.']);
        exit;
    }
}
if ($originLongitude < -180 || $originLongitude > 180 || $destinationLongitude < -180 || $destinationLongitude > 180
    || $originLatitude < -90 || $originLatitude > 90 || $destinationLatitude < -90 || $destinationLatitude > 90) {
    http_response_code(422);
    echo json_encode(['error' => 'The directions coordinates are outside valid map bounds.']);
    exit;
}

$directionsUrl = 'https://www.google.com/maps/dir/?' . http_build_query([
    'api' => '1',
    'origin' => $originLatitude . ',' . $originLongitude,
    'destination' => $destinationLatitude . ',' . $destinationLongitude,
    'travelmode' => 'driving',
], '', '&', PHP_QUERY_RFC3986);
echo json_encode([
    'directions_url' => $directionsUrl,
    'origin' => [
        'display_name' => $useBarangayHall
            ? 'Barangay ' . (string)$report['barangay_name']
            : 'DCVS Team',
        'coordinates' => [$originLongitude, $originLatitude],
    ],
    'destination' => [$destinationLongitude, $destinationLatitude],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
