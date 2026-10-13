<?php
/** Return all non-archived pinned reports for the signed-in staff or assigned barangay. */
$sessionRoles = [
    'DCVS_STAFF_SESSION' => 'staff',
    'DCVS_BARANGAY_SESSION' => 'barangay',
];
$sessionName = trim($_GET['sess'] ?? '');
if (!isset($sessionRoles[$sessionName])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid session type']);
    exit;
}

session_name($sessionName);
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/reports/location.php';

header('Content-Type: application/geo+json');
header('Cache-Control: no-store, private');
header('Vary: Cookie');

$expectedRole = $sessionRoles[$sessionName];
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $expectedRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

loadDcvsEnv();
if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') !== '1') {
    http_response_code(503);
    echo json_encode(['error' => 'Report pins are not enabled.']);
    exit;
}

try {
    $features = dcvsLoadOfficialBarangayPolygons(
        dirname(__DIR__) . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/barangays-boundary.json'
    );
    if (!dcvsReportPinFeatureEnabled($conn, $features)) {
        http_response_code(503);
        echo json_encode(['error' => 'Report pin configuration is not ready.']);
        exit;
    }
} catch (RuntimeException $exception) {
    error_log('Report pins boundary readiness check failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Report pin configuration is not ready.']);
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if ($expectedRole === 'barangay' && $barangayId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Barangay scope is missing.']);
    exit;
}

$targetReportId = null;
if ($expectedRole === 'staff' && array_key_exists('report_id', $_GET)) {
    $targetReportValue = $_GET['report_id'];
    if (!is_scalar($targetReportValue) || !ctype_digit((string)$targetReportValue) || (int)$targetReportValue <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid report is required.']);
        exit;
    }
    $targetReportId = (int)$targetReportValue;
}

$sql = "SELECT r.report_id, r.coordinates_long, r.coordinates_lat,
            r.reference_id, r.report_type, r.animal_type, r.animal_count,
            r.animal_behavior, r.description, r.incident_at,
            r.urgency_level, r.location_address, r.location_verified,
            r.photo_url, r.video_url,
            ra.attachment_type, ra.attachment_source, ra.file_url, ra.mime_type,
            r.barangay_id, b.barangay_name, r.status AS case_status, r.updated_at,
            rw.response_mode, rw.state AS response_state, rw.response_deadline,
            rw.progress_deadline, rw.last_progress_at,
            tt.status AS turnover_status, tt.due_at AS turnover_due_at, tt.received_at AS turnover_received_at,
            latest.action AS latest_response_action, latest.reason AS latest_response_reason,
            latest.created_at AS latest_response_at, latest_actor.full_name AS latest_response_actor
        FROM reports r
        INNER JOIN barangays b ON b.barangay_id = r.barangay_id
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
        LEFT JOIN (
            SELECT h.report_id, h.action, h.reason, h.created_at, h.actor_id
            FROM report_response_history h
            INNER JOIN (
                SELECT report_id, MAX(history_id) AS history_id
                FROM report_response_history
                GROUP BY report_id
            ) latest_history ON latest_history.history_id = h.history_id
        ) latest ON latest.report_id = r.report_id
        LEFT JOIN users latest_actor ON latest_actor.user_id = latest.actor_id
        LEFT JOIN report_attachments ra ON ra.report_id = r.report_id
        WHERE r.archived_at IS NULL
          AND r.coordinates_lat IS NOT NULL
          AND r.coordinates_long IS NOT NULL";
if ($expectedRole === 'staff') {
    if ($targetReportId !== null) $sql .= " AND r.report_id = ?";
}
if ($expectedRole === 'barangay') {
    $sql = "SELECT r.report_id, r.coordinates_long, r.coordinates_lat,
                r.reference_id, r.report_type, r.animal_type, r.animal_count,
                r.animal_behavior, r.description, r.incident_at,
                r.urgency_level, r.location_address, r.location_verified,
                r.photo_url, r.video_url,
                ra.attachment_type, ra.attachment_source, ra.file_url, ra.mime_type,
                r.barangay_id, b.barangay_name, r.status AS case_status, r.updated_at,
                rw.response_mode, rw.state AS response_state, rw.response_deadline,
                rw.progress_deadline, rw.last_progress_at,
                tt.status AS turnover_status, tt.due_at AS turnover_due_at, tt.received_at AS turnover_received_at,
                latest.action AS latest_response_action, latest.reason AS latest_response_reason,
                latest.created_at AS latest_response_at, latest_actor.full_name AS latest_response_actor
            FROM reports r
            INNER JOIN barangays b ON b.barangay_id = r.barangay_id
            LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
            LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
            LEFT JOIN (
                SELECT h.report_id, h.action, h.reason, h.created_at, h.actor_id
                FROM report_response_history h
                INNER JOIN (
                    SELECT report_id, MAX(history_id) AS history_id
                    FROM report_response_history
                    GROUP BY report_id
                ) latest_history ON latest_history.history_id = h.history_id
            ) latest ON latest.report_id = r.report_id
            LEFT JOIN users latest_actor ON latest_actor.user_id = latest.actor_id
            LEFT JOIN report_attachments ra ON ra.report_id = r.report_id
            WHERE r.archived_at IS NULL
              AND r.coordinates_lat IS NOT NULL
              AND r.coordinates_long IS NOT NULL
              AND r.barangay_id = ?";
}
$sql .= ' ORDER BY r.created_at DESC, ra.display_order ASC, ra.attachment_id ASC';
$statement = $conn->prepare($sql);
if (!$statement) {
    error_log('Report pins query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load report pins.']);
    exit;
}
if ($expectedRole === 'barangay') {
    $statement->bind_param('i', $barangayId);
} elseif ($targetReportId !== null) {
    $statement->bind_param('i', $targetReportId);
}
if (!$statement->execute()) {
    error_log('Report pins query execution failed: ' . $statement->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load report pins.']);
    exit;
}

$result = $statement->get_result();
$geoJson = ['type' => 'FeatureCollection', 'features' => []];
$featureIndexes = [];
while ($row = $result->fetch_assoc()) {
    $reportId = (int)$row['report_id'];
    if (!isset($featureIndexes[$reportId])) {
        $featureIndexes[$reportId] = count($geoJson['features']);
        $geoJson['features'][] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float)$row['coordinates_long'], (float)$row['coordinates_lat']],
            ],
            'properties' => [
                    'report_id' => (int)$row['report_id'],
                    'reference_id' => $row['reference_id'],
                    'type' => $row['report_type'],
                    'animal_type' => $row['animal_type'],
                    'animal_count' => $row['animal_count'],
                    'animal_behavior' => $row['animal_behavior'],
                    'description' => $row['description'],
                    'incident_at' => $row['incident_at'],
                    'priority' => $row['urgency_level'],
                    'status' => $row['case_status'],
                    'barangay_id' => (int)$row['barangay_id'],
                    'barangay' => $row['barangay_name'],
                    'response_mode' => (string)($row['response_mode'] ?? ''),
                    'response_state' => (string)($row['response_state'] ?? ''),
                    'response_deadline' => (string)($row['response_deadline'] ?? ''),
                    'progress_deadline' => (string)($row['progress_deadline'] ?? ''),
                    'last_progress_at' => (string)($row['last_progress_at'] ?? ''),
                    'turnover_status' => (string)($row['turnover_status'] ?? ''),
                    'turnover_due_at' => (string)($row['turnover_due_at'] ?? ''),
                    'turnover_received_at' => (string)($row['turnover_received_at'] ?? ''),
                    'latest_response_action' => (string)($row['latest_response_action'] ?? ''),
                    'latest_response_reason' => (string)($row['latest_response_reason'] ?? ''),
                    'latest_response_at' => (string)($row['latest_response_at'] ?? ''),
                    'latest_response_actor' => (string)($row['latest_response_actor'] ?? ''),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                    'location' => $row['location_address'],
                    'verified' => (bool)$row['location_verified'],
                    'photo' => $row['photo_url'],
                    'video' => $row['video_url'],
                    'attachments' => [],
            ],
        ];
    }
    if (!empty($row['file_url'])) {
        $featureIndex = $featureIndexes[$reportId];
        $attachment = [
            'type' => $row['attachment_type'],
            'url' => $row['file_url'],
            'mime_type' => $row['mime_type'],
        ];
        if ($expectedRole === 'staff' && $row['attachment_source'] === 'barangay_validation') {
            $geoJson['features'][$featureIndex]['properties']['barangay_evidence'][] = $attachment;
        } else {
            $geoJson['features'][$featureIndex]['properties']['attachments'][] = $attachment;
        }
    }
}
$statement->close();

echo json_encode($geoJson, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
