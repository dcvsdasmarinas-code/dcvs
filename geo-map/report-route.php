<?php
/** Return a road route from the authorized office to an authorized report pin. */
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
require_once __DIR__ . '/../env.php';

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
        error_log('Report route authorization query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Could not check route access.']);
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
        error_log('Report route authorization query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Could not check route access.']);
        exit;
    }
    $reportStatement->bind_param('i', $reportId);
}
if (!$reportStatement->execute()) {
    error_log('Report route authorization query failed: ' . $reportStatement->error);
    $reportStatement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not check route access.']);
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
        "SELECT name, coordinates_long, coordinates_lat, location_accuracy
         FROM landmarks
         WHERE barangay_id = ? AND category = 'barangay_hall'
           AND is_active = 1 AND location_accuracy IN ('approximate', 'verified')
         ORDER BY location_accuracy = 'verified' DESC, landmark_id
         LIMIT 1"
    );
} else {
    $originStatement = $conn->prepare(
        "SELECT name, coordinates_long, coordinates_lat, location_accuracy
         FROM landmarks
         WHERE name = 'Dasmariñas City Veterinary Services Office'
           AND is_active = 1 AND location_accuracy IN ('approximate', 'verified')
         ORDER BY location_accuracy = 'verified' DESC, landmark_id
         LIMIT 1"
    );
}
if (!$originStatement) {
    error_log('Report route origin query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load the route starting point.']);
    exit;
}
if ($useBarangayHall) {
    $routeBarangayId = (int)$report['barangay_id'];
    $originStatement->bind_param('i', $routeBarangayId);
}
if (!$originStatement->execute()) {
    error_log('Report route origin query failed: ' . $originStatement->error);
    $originStatement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not load the route starting point.']);
    exit;
}
$origin = $originStatement->get_result()->fetch_assoc();
$originStatement->close();
if (!$origin) {
    http_response_code(409);
    echo json_encode(['error' => 'No active office location is configured for this route.']);
    exit;
}

loadDcvsEnv();
$routingBaseUrl = trim((string)env('DCVS_ROUTING_API_URL', ''));
if ($routingBaseUrl === '') {
    http_response_code(503);
    echo json_encode(['error' => 'The routing service is not configured yet.']);
    exit;
}
if (!function_exists('curl_init')) {
    error_log('The PHP cURL extension is required for report routing.');
    http_response_code(503);
    echo json_encode(['error' => 'The routing service is unavailable on this server.']);
    exit;
}

$routingUrlParts = parse_url($routingBaseUrl);
if (!is_array($routingUrlParts)
    || !in_array(strtolower($routingUrlParts['scheme'] ?? ''), ['http', 'https'], true)
    || empty($routingUrlParts['host'])
    || isset($routingUrlParts['query'])
    || isset($routingUrlParts['fragment'])) {
    error_log('DCVS_ROUTING_API_URL must be an HTTP(S) routing service base URL without query or fragment.');
    http_response_code(503);
    echo json_encode(['error' => 'The routing service configuration is invalid.']);
    exit;
}

$profile = strtolower(trim((string)env('DCVS_ROUTING_PROFILE', 'driving')));
if (!in_array($profile, ['driving', 'walking', 'cycling'], true)) {
    error_log('DCVS_ROUTING_PROFILE must be driving, walking, or cycling.');
    http_response_code(503);
    echo json_encode(['error' => 'The routing profile is invalid.']);
    exit;
}

$originLongitude = (float)$origin['coordinates_long'];
$originLatitude = (float)$origin['coordinates_lat'];
$originDisplayName = $useBarangayHall
    ? 'Barangay ' . (string)$report['barangay_name']
    : 'DCVS Team';
$originInitials = 'DC';
if ($useBarangayHall) {
    $nameParts = preg_split('/\s+/u', trim((string)$report['barangay_name']), -1, PREG_SPLIT_NO_EMPTY);
    $firstInitial = '';
    if (!empty($nameParts) && preg_match('/[\p{L}\p{N}]/u', $nameParts[0], $initialMatch)) {
        $firstInitial = strtoupper($initialMatch[0]);
    }
    $suffix = !empty($nameParts) ? strtoupper((string)end($nameParts)) : '';
    $romanNumerals = ['I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5', 'VI' => '6', 'VII' => '7', 'VIII' => '8', 'IX' => '9', 'X' => '10'];
    if (isset($romanNumerals[$suffix])) {
        $originInitials = $firstInitial . $romanNumerals[$suffix];
    } elseif (preg_match('/^[0-9]+$/', $suffix)) {
        $originInitials = $firstInitial . $suffix;
    } elseif (count($nameParts) > 1 && preg_match('/[\p{L}\p{N}]/u', $nameParts[count($nameParts) - 1], $lastInitial)) {
        $originInitials = $firstInitial . strtoupper($lastInitial[0]);
    } else {
        $originInitials = $firstInitial !== '' ? $firstInitial : 'BR';
    }
}
$destinationLongitude = (float)$report['coordinates_long'];
$destinationLatitude = (float)$report['coordinates_lat'];
$coordinates = implode(';', [
    $originLongitude . ',' . $originLatitude,
    $destinationLongitude . ',' . $destinationLatitude,
]);
$routingUrl = rtrim($routingBaseUrl, '/') . '/route/v1/' . $profile . '/' . $coordinates
    . '?overview=full&steps=true&geometries=geojson';

$curl = curl_init($routingUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$routingResponse = curl_exec($curl);
$routingHttpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$routingError = curl_error($curl);
curl_close($curl);
if (!is_string($routingResponse) || $routingHttpStatus < 200 || $routingHttpStatus >= 300) {
    error_log('Report routing request failed: ' . ($routingError !== '' ? $routingError : 'HTTP ' . $routingHttpStatus));
    http_response_code(502);
    echo json_encode(['error' => 'A road route could not be calculated. Try again later.']);
    exit;
}

$routingData = json_decode($routingResponse, true);
$route = is_array($routingData) ? ($routingData['routes'][0] ?? null) : null;
$routeCoordinates = $route['geometry']['coordinates'] ?? null;
if (!is_array($routeCoordinates) || count($routeCoordinates) < 2) {
    http_response_code(422);
    echo json_encode(['error' => 'No road route is available between the office and this pin.']);
    exit;
}
foreach ($routeCoordinates as $coordinate) {
    if (!is_array($coordinate) || !isset($coordinate[0], $coordinate[1])
        || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])
        || (float)$coordinate[0] < -180 || (float)$coordinate[0] > 180
        || (float)$coordinate[1] < -90 || (float)$coordinate[1] > 90) {
        http_response_code(502);
        echo json_encode(['error' => 'The routing service returned invalid route geometry.']);
        exit;
    }
}

$steps = [];
$routeSteps = $route['legs'][0]['steps'] ?? [];
foreach (is_array($routeSteps) ? $routeSteps : [] as $step) {
    if (!is_array($step)) {
        continue;
    }
    $maneuver = $step['maneuver'] ?? [];
    if (!is_array($maneuver)) {
        continue;
    }
    $stepCoordinates = $maneuver['location'] ?? null;
    if (!is_array($stepCoordinates) || count($stepCoordinates) < 2
        || !is_numeric($stepCoordinates[0]) || !is_numeric($stepCoordinates[1])) {
        continue;
    }
    $maneuverType = trim((string)($maneuver['type'] ?? 'continue'));
    $modifier = trim((string)($maneuver['modifier'] ?? ''));
    $roadName = trim((string)($step['name'] ?? ''));
    $description = ucfirst(str_replace('_', ' ', $maneuverType));
    if ($modifier !== '') {
        $description .= ' ' . $modifier;
    }
    if ($roadName !== '') {
        $description .= ' onto ' . $roadName;
    }
    $steps[] = [
        'coordinates' => [(float)$stepCoordinates[0], (float)$stepCoordinates[1]],
        'instruction' => $description,
        'distance_meters' => max(0, (int)round((float)($step['distance'] ?? 0))),
        'is_maneuver' => !in_array($maneuverType, ['depart', 'arrive', 'waypoint'], true),
    ];
}

echo json_encode([
    'origin' => [
        'name' => $origin['name'],
        'display_name' => $originDisplayName,
        'identity' => $useBarangayHall ? 'barangay' : 'dcvs',
        'initials' => $originInitials,
        'assigned' => $isBarangayResponseRoute,
        'accuracy' => $origin['location_accuracy'],
        'coordinates' => [$originLongitude, $originLatitude],
    ],
    'destination' => [$destinationLongitude, $destinationLatitude],
    'route' => [
        'coordinates' => array_map(
            static function ($coordinate) {
                return [(float)$coordinate[0], (float)$coordinate[1]];
            },
            $routeCoordinates
        ),
        'distance_meters' => max(0, (int)round((float)($route['distance'] ?? 0))),
        'duration_seconds' => max(0, (int)round((float)($route['duration'] ?? 0))),
        'steps' => $steps,
    ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
