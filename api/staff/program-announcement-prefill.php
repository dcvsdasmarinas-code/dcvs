<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('Content-Type: application/json');

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';

$programId = filter_var($_GET['program_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($programId === false || $programId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid program ID is required.']);
    exit;
}

$programStmt = $conn->prepare(
    'SELECT program_id, program_name, program_type, description, status, start_date, end_date,
            program_start_time, program_end_time, operating_time, location_mode, venue
     FROM programs WHERE program_id = ?'
);
if (!$programStmt) {
    error_log('Could not prepare program announcement prefill query: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load program details.']);
    exit;
}
$programStmt->bind_param('i', $programId);
if (!$programStmt->execute()) {
    error_log('Could not execute program announcement prefill query: ' . $programStmt->error);
    $programStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load program details.']);
    exit;
}
$program = $programStmt->get_result()->fetch_assoc();
$programStmt->close();

if (!$program) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Program not found.']);
    exit;
}
if (!in_array($program['status'], ['scheduled', 'live'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Only scheduled or ongoing programs can be announced.']);
    exit;
}

$targets = [];
$programLocations = [];
$unmatched = [];
$unavailable = [];
$targetStmt = $conn->prepare(
    'SELECT b.barangay_id, b.barangay_name, b.psgc_mapping_approved, b.psgc_code
     FROM program_barangays pb
     INNER JOIN barangays b ON b.barangay_id = pb.barangay_id
     WHERE pb.program_id = ?
     ORDER BY pb.barangay_id'
);
if (!$targetStmt) {
    error_log('Could not prepare program barangay prefill query: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load program barangays.']);
    exit;
}
$targetStmt->bind_param('i', $programId);
if (!$targetStmt->execute()) {
    error_log('Could not execute program barangay prefill query: ' . $targetStmt->error);
    $targetStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load program barangays.']);
    exit;
}
$targetResult = $targetStmt->get_result();
$hasLinkedTargets = false;
while ($target = $targetResult->fetch_assoc()) {
    $hasLinkedTargets = true;
    $programLocations[] = (string)$target['barangay_name'];
    if ((int)$target['psgc_mapping_approved'] === 1 && $target['psgc_code'] !== null) {
        $targets[] = [
            'barangay_id' => (int)$target['barangay_id'],
            'barangay_name' => (string)$target['barangay_name'],
        ];
    } else {
        $unavailable[] = (string)$target['barangay_name'];
    }
}
$targetStmt->close();

if (!$hasLinkedTargets) {
    $locationNames = array_filter(array_map('trim', explode(',', (string)$program['venue'])), static function (string $name): bool {
        return $name !== '';
    });
    $programLocations = array_values($locationNames);
    if ($locationNames) {
        $barangayResult = $conn->query('SELECT barangay_id, barangay_name FROM barangays');
        if (!$barangayResult) {
            error_log('Could not load barangays for program prefill: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not match program locations.']);
            exit;
        }
        $normalize = static function (string $name): string {
            $name = preg_replace('/\s+/u', ' ', trim($name));
            return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        };
        $barangaysByName = [];
        while ($barangay = $barangayResult->fetch_assoc()) {
            $barangaysByName[$normalize((string)$barangay['barangay_name'])] = [
                'barangay_id' => (int)$barangay['barangay_id'],
                'barangay_name' => (string)$barangay['barangay_name'],
            ];
        }
        $barangayResult->free();
        foreach ($locationNames as $locationName) {
            $normalizedName = $normalize($locationName);
            if (isset($barangaysByName[$normalizedName])) {
                $targets[] = $barangaysByName[$normalizedName];
            } else {
                $unmatched[] = $locationName;
            }
        }
    }
}

$toLocalTime = static function (?string $value): string {
    if (!$value) {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('H:i', $timestamp);
};
$startTime = $toLocalTime($program['program_start_time']);
$endTime = $toLocalTime($program['program_end_time']);
if ($startTime === '' || $endTime === '') {
    preg_match_all('/\b\d{1,2}:\d{2}\s*[ap]\.?m\.?\b/i', (string)$program['operating_time'], $timeMatches);
    if (count($timeMatches[0]) >= 2) {
        $parsedStart = DateTimeImmutable::createFromFormat('!g:i A', strtoupper(preg_replace('/\s+/', ' ', trim(str_replace('.', '', $timeMatches[0][0])))));
        $parsedEnd = DateTimeImmutable::createFromFormat('!g:i A', strtoupper(preg_replace('/\s+/', ' ', trim(str_replace('.', '', $timeMatches[0][1])))));
        if ($parsedStart && $startTime === '') {
            $startTime = $parsedStart->format('H:i');
        }
        if ($parsedEnd && $endTime === '') {
            $endTime = $parsedEnd->format('H:i');
        }
    }
}

$programName = trim((string)$program['program_name']);
if ($programName !== '' && preg_match('/[A-Z]/', $programName) && !preg_match('/[a-z]/', $programName)) {
    $programName = function_exists('mb_convert_case')
        ? mb_convert_case(strtolower($programName), MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($programName));
}
$subjectByType = [
    'vaccination' => 'animal_health',
    'deworming' => 'animal_health',
    'rabies_prevention' => 'animal_health',
    'kapon' => 'services_programs',
    'checkup' => 'services_programs',
    'adoption' => 'services_programs',
    'microchipping' => 'services_programs',
];

$existingStmt = $conn->prepare(
    'SELECT announcement_id, status FROM announcements WHERE program_id = ? ORDER BY announcement_id DESC LIMIT 1'
);
if (!$existingStmt) {
    error_log('Could not prepare linked announcement lookup: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check for an existing announcement.']);
    exit;
}
$existingStmt->bind_param('i', $programId);
if (!$existingStmt->execute()) {
    error_log('Could not execute linked announcement lookup: ' . $existingStmt->error);
    $existingStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check for an existing announcement.']);
    exit;
}
$existing = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

echo json_encode([
    'success' => true,
    'data' => [
        'program_id' => (int)$program['program_id'],
        'title' => $programName,
        'program_type' => (string)$program['program_type'],
        'subject' => $subjectByType[$program['program_type']] ?? '',
        'content' => (string)($program['description'] ?? ''),
        'event_date' => (string)$program['start_date'],
        'end_date' => (string)$program['end_date'] !== (string)$program['start_date'] ? (string)$program['end_date'] : '',
        'event_start_time' => $startTime,
        'event_end_time' => $endTime,
        'location' => $programLocations ? implode(', ', $programLocations) : (string)($program['venue'] ?? ''),
        'barangays' => $targets,
        'unmatched_barangays' => $unmatched,
        'unavailable_barangays' => $unavailable,
        'existing_announcement' => $existing ? [
            'announcement_id' => (int)$existing['announcement_id'],
            'status' => (string)$existing['status'],
        ] : null,
    ],
]);
