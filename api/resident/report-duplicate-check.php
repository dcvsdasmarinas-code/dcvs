<?php
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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/location.php';
require_once __DIR__ . '/../../includes/reports/duplicates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function reportDuplicateCheckReply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    reportDuplicateCheckReply(401, ['success' => false, 'message' => 'Sign in as a resident to check for similar reports.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reportDuplicateCheckReply(405, ['success' => false, 'message' => 'Use the report form to check for similar reports.']);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    reportDuplicateCheckReply(403, ['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
}

$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
};
$latitudeInput = $readPostString('latitude');
$longitudeInput = $readPostString('longitude');
$_SESSION['report_duplicate_candidates'] = ['created_at' => time(), 'ids' => [], 'attachments' => []];
if ($latitudeInput === '' && $longitudeInput === '') {
    reportDuplicateCheckReply(200, ['success' => true, 'candidates' => []]);
}
if ($latitudeInput === '' || $longitudeInput === ''
    || !is_numeric($latitudeInput) || !is_numeric($longitudeInput)) {
    reportDuplicateCheckReply(400, ['success' => false, 'message' => 'The incident pin is incomplete. Place it again and retry.']);
}

$latitude = (float)$latitudeInput;
$longitude = (float)$longitudeInput;
$reportType = reportTypeKey($readPostString('report_type'));
$barangayCode = $readPostString('barangay_psgc_code');
if (!in_array($reportType, ['stray', 'rabies', 'injured', 'dead'], true)
    || !is_finite($latitude) || !is_finite($longitude)
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    reportDuplicateCheckReply(400, ['success' => false, 'message' => 'The report type or incident pin is invalid.']);
}

loadDcvsEnv();
if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') !== '1') {
    reportDuplicateCheckReply(200, ['success' => true, 'candidates' => []]);
}

try {
    $features = dcvsLoadOfficialBarangayPolygons(
        __DIR__ . '/../../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/../../geo-map/barangays-boundary.json'
    );
    if (!dcvsReportPinFeatureEnabled($conn, $features)) {
        throw new RuntimeException('Report pin configuration is not ready.');
    }
    $resolvedBarangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $features);
    if ($resolvedBarangay === null
        || !hash_equals((string)$resolvedBarangay['psgc_code'], $barangayCode)) {
        reportDuplicateCheckReply(400, ['success' => false, 'message' => 'The incident pin and selected barangay do not match. Move the pin and retry.']);
    }

    $barangayQuery = $conn->prepare("
        SELECT barangay_id
        FROM barangays
        WHERE psgc_code = ? AND psgc_mapping_approved = 1
        LIMIT 1
    ");
    if (!$barangayQuery) {
        error_log('Could not prepare duplicate-check barangay lookup: ' . $conn->error);
        throw new RuntimeException('Could not verify the incident barangay.');
    }
    $barangayQuery->bind_param('s', $barangayCode);
    if (!$barangayQuery->execute()) {
        error_log('Could not execute duplicate-check barangay lookup: ' . $barangayQuery->error);
        $barangayQuery->close();
        throw new RuntimeException('Could not verify the incident barangay.');
    }
    $barangay = $barangayQuery->get_result()->fetch_assoc();
    $barangayQuery->close();
    if (!$barangay) {
        reportDuplicateCheckReply(400, ['success' => false, 'message' => 'The incident barangay could not be verified.']);
    }

    $candidates = dcvsReportDuplicateCandidates(
        $conn,
        (int)$barangay['barangay_id'],
        $reportType,
        $latitude,
        $longitude
    );
    $authorizedAttachments = [];
    foreach ($candidates as $candidate) {
        if ($candidate['photo_attachment_id'] !== null) {
            $authorizedAttachments[(string)$candidate['photo_attachment_id']] = (int)$candidate['report_id'];
        }
    }
    $_SESSION['report_duplicate_candidates'] = [
        'created_at' => time(),
        'ids' => array_map(static function (array $candidate): int {
            return (int)$candidate['report_id'];
        }, $candidates),
        'attachments' => $authorizedAttachments,
    ];

    reportDuplicateCheckReply(200, [
        'success' => true,
        'candidates' => array_map(static function (array $candidate): array {
            return [
                'report_id' => (int)$candidate['report_id'],
                'report_type' => (string)$candidate['report_type'],
                'status' => (string)$candidate['status'],
                'created_at' => (string)$candidate['created_at'],
                'distance_meters' => (int)$candidate['distance_meters'],
                'photo_attachment_id' => $candidate['photo_attachment_id'],
            ];
        }, $candidates),
    ]);
} catch (InvalidArgumentException | DomainException $exception) {
    reportDuplicateCheckReply(400, ['success' => false, 'message' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    error_log('Resident report duplicate check failed: ' . $exception->getMessage());
    reportDuplicateCheckReply(503, ['success' => false, 'message' => 'Similar reports could not be checked right now. You can still submit your report.']);
}
