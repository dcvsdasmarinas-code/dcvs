<?php
$requestedPortal = trim((string)($_GET['portal'] ?? ''));
$portalSessions = [
    'staff' => ['session' => 'DCVS_STAFF_SESSION', 'role' => 'staff', 'login' => 'login-staff.php'],
    'barangay' => ['session' => 'DCVS_BARANGAY_SESSION', 'role' => 'barangay', 'login' => 'login-barangay.php'],
];
if (!isset($portalSessions[$requestedPortal])) {
    http_response_code(400);
    exit('Invalid portal.');
}
$portal = $portalSessions[$requestedPortal];
session_name($portal['session']);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/duplicates.php';
require_once __DIR__ . '/../../includes/app-base.php';
$appBaseUrl = dcvsAppBaseUrl();

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $portal['role']) {
    header('Location: ' . $portal['login']);
    exit;
}
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if ($portal['role'] === 'barangay' && $barangayId <= 0) {
    http_response_code(403);
    exit('Barangay scope is missing.');
}

$sql = "
    SELECT child.report_id, child.reference_id, child.report_type, child.status,
           child.duplicate_status, child.parent_report_id, child.created_at,
           child.description, child.location_address, child.coordinates_lat,
           child.coordinates_long, child.animal_type, child.animal_breed,
           child.animal_count, child.animal_behavior, child.incident_at, child.photo_url AS child_photo_url,
           child.urgency_level, child.reporter_id, child.barangay_id,
           child.video_url, child.landmark_id,
           parent.report_id AS resolved_parent_id, parent.reference_id AS parent_reference_id,
           parent.report_type AS parent_report_type, parent.status AS parent_status,
           parent.created_at AS parent_created_at, parent.description AS parent_description,
           parent.location_address AS parent_location_address,
           parent.coordinates_lat AS parent_lat, parent.coordinates_long AS parent_lng,
           parent.animal_type AS parent_animal_type, parent.animal_breed AS parent_animal_breed,
           parent.animal_count AS parent_animal_count, parent.animal_behavior AS parent_animal_behavior,
           parent.incident_at AS parent_incident_at, parent.photo_url AS parent_photo_url,
           parent.landmark_id AS parent_landmark_id,
           parent.urgency_level AS parent_urgency, parent.reporter_id AS parent_reporter_id,
           parent.barangay_id AS parent_barangay_id,
           parent.video_url AS parent_video_url,
           reporter.full_name AS reporter_name, reporter.reference_id AS reporter_reference_id,
           reporter.phone AS reporter_phone, reporter.email AS reporter_email,
           parentReporter.full_name AS parent_reporter_name,
           parentReporter.reference_id AS parent_reporter_reference_id,
           parentReporter.phone AS parent_reporter_phone, parentReporter.email AS parent_reporter_email,
           childBarangay.barangay_name AS child_barangay_name,
           parentBarangay.barangay_name AS parent_barangay_name,
           childLandmark.name AS child_landmark_name, parentLandmark.name AS parent_landmark_name,
           childConfirmed.full_name AS child_confirmed_staff,
           childValidated.full_name AS child_validated_staff,
           parentConfirmed.full_name AS parent_confirmed_staff,
           parentValidated.full_name AS parent_validated_staff,
           childCase.resolved_at AS child_resolved_at,
           parentCase.resolved_at AS parent_resolved_at,
           latest.action AS latest_action, latest.parent_report_id AS latest_parent_report_id,
           latest.created_at AS latest_action_at, latest.notes AS latest_notes,
           actor.full_name AS latest_actor,
           (SELECT COUNT(*) FROM reports supporting
            WHERE supporting.parent_report_id = parent.report_id
              AND supporting.duplicate_status = 'merged'
              AND supporting.archived_at IS NULL) AS supporting_report_count
    FROM reports child
    LEFT JOIN (
        SELECT log.report_id, log.parent_report_id, log.action, log.created_at,
               log.notes, log.performed_by
        FROM report_duplicate_log log
        INNER JOIN (
            SELECT report_id, MAX(duplicate_log_id) AS duplicate_log_id
            FROM report_duplicate_log
            GROUP BY report_id
        ) latest_id ON latest_id.duplicate_log_id = log.duplicate_log_id
    ) latest ON latest.report_id = child.report_id
    LEFT JOIN reports parent ON parent.report_id = COALESCE(child.parent_report_id, latest.parent_report_id)
    LEFT JOIN users reporter ON reporter.user_id = child.reporter_id
    LEFT JOIN users parentReporter ON parentReporter.user_id = parent.reporter_id
    LEFT JOIN barangays childBarangay ON childBarangay.barangay_id = child.barangay_id
    LEFT JOIN barangays parentBarangay ON parentBarangay.barangay_id = parent.barangay_id
    LEFT JOIN landmarks childLandmark ON childLandmark.landmark_id = child.landmark_id
    LEFT JOIN landmarks parentLandmark ON parentLandmark.landmark_id = parent.landmark_id
    LEFT JOIN users childConfirmed ON childConfirmed.user_id = child.confirmed_by
    LEFT JOIN users childValidated ON childValidated.user_id = child.validated_by
    LEFT JOIN users parentConfirmed ON parentConfirmed.user_id = parent.confirmed_by
    LEFT JOIN users parentValidated ON parentValidated.user_id = parent.validated_by
    LEFT JOIN confirmed_cases childCase ON childCase.report_id = child.report_id
    LEFT JOIN confirmed_cases parentCase ON parentCase.report_id = parent.report_id
    LEFT JOIN users actor ON actor.user_id = latest.performed_by
    WHERE child.archived_at IS NULL
      AND child.duplicate_status = 'suspected'
      AND child.parent_report_id IS NOT NULL
";
if ($portal['role'] === 'barangay') {
    $sql .= ' AND child.barangay_id = ?';
}
$sql .= ' ORDER BY child.created_at DESC';
$query = $conn->prepare($sql);
if (!$query) {
    error_log('Could not prepare possible duplicates queue: ' . $conn->error);
    http_response_code(500);
    exit('Could not load similar reports.');
}
if ($portal['role'] === 'barangay') $query->bind_param('i', $barangayId);
if (!$query->execute()) {
    error_log('Could not load possible duplicates queue: ' . $query->error);
    $query->close();
    http_response_code(500);
    exit('Could not load similar reports.');
}
$reports = $query->get_result();
$duplicatePairs = [];
$barangayOptions = [];
$lowercase = static function (string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$reportTypeLabels = [
    'stray' => 'Stray Animal',
    'rabies' => 'Rabies',
    'injured' => 'Injured Animal',
    'dead' => 'Dead Animal',
    'rescue' => 'Rescue',
];
$typeOptions = $reportTypeLabels;
$statusLabels = [
    'pending' => 'Pending Barangay Review',
    'validated' => 'Pending DCVS Review',
    'rejected' => 'Rejected',
    'confirmed' => 'Confirmed',
    'scheduled' => 'Scheduled',
    'resolved' => 'Resolved',
];
while ($pair = $reports->fetch_assoc()) {
    $pair['report_type_label'] = $reportTypeLabels[$pair['report_type']] ?? ucfirst((string)$pair['report_type']);
    $pair['child_status_label'] = $statusLabels[$pair['status']] ?? ucfirst((string)$pair['status']);
    $pair['parent_status_label'] = $statusLabels[$pair['parent_status']] ?? ucfirst((string)$pair['parent_status']);
    $pair['distance_meters'] = null;
    $pair['time_difference_seconds'] = null;
    if ($pair['parent_created_at'] !== null) {
        $pair['time_difference_seconds'] = abs(strtotime((string)$pair['created_at']) - strtotime((string)$pair['parent_created_at']));
    }
    if (
        $pair['coordinates_lat'] !== null && $pair['coordinates_long'] !== null
        && $pair['parent_lat'] !== null && $pair['parent_lng'] !== null
    ) {
        $pair['distance_meters'] = (int)round(dcvsReportDuplicateDistanceMeters(
            (float)$pair['coordinates_lat'],
            (float)$pair['coordinates_long'],
            (float)$pair['parent_lat'],
            (float)$pair['parent_lng']
        ));
    }

    $parentSupportCount = (int)$pair['supporting_report_count'];
    $pair['report_count'] = 2 + $parentSupportCount;
    $duplicatePairs[] = $pair;
}
$reports->free();
$mappedLandmarks = [];
if ($duplicatePairs !== []) {
    $landmarksQuery = $conn->query("
        SELECT landmark_id, name, coordinates_lat, coordinates_long, location_accuracy
        FROM landmarks
        WHERE is_active = 1
          AND location_accuracy IN ('approximate', 'verified')
          AND coordinates_long BETWEEN 120.78 AND 121.10
          AND coordinates_lat BETWEEN 14.17 AND 14.49
    ");
    if (!$landmarksQuery) {
        error_log('Could not load mapped landmarks for Similar Reports: ' . $conn->error);
        http_response_code(500);
        exit('Could not load report landmarks.');
    }
    while ($landmark = $landmarksQuery->fetch_assoc()) {
        $mappedLandmarks[(int)$landmark['landmark_id']] = [
            'name' => (string)$landmark['name'],
            'latitude' => (float)$landmark['coordinates_lat'],
            'longitude' => (float)$landmark['coordinates_long'],
            'accuracy' => (string)$landmark['location_accuracy'],
        ];
    }
    $landmarksQuery->free();
}
$nearestPinLandmark = static function ($latitude, $longitude, $assignedLandmarkId) use (&$mappedLandmarks): string {
    $assignedId = (int)$assignedLandmarkId;
    if ($assignedId > 0 && isset($mappedLandmarks[$assignedId])) {
        return $mappedLandmarks[$assignedId]['name'];
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)
        || (float)$latitude < -90 || (float)$latitude > 90
        || (float)$longitude < -180 || (float)$longitude > 180) {
        return '';
    }
    $nearestName = '';
    $nearestDistance = 1000;
    foreach ($mappedLandmarks as $landmark) {
        $distance = dcvsReportDuplicateDistanceMeters(
            (float)$latitude,
            (float)$longitude,
            $landmark['latitude'],
            $landmark['longitude']
        );
        if ($distance < $nearestDistance) {
            $nearestDistance = $distance;
            $nearestName = $landmark['name'];
        }
    }
    return $nearestName;
};
$sharedLandmarkRadiusMeters = 100.0;
$nearestSharedVerifiedLandmark = static function ($latitudeA, $longitudeA, $latitudeB, $longitudeB) use (&$mappedLandmarks, $sharedLandmarkRadiusMeters): string {
    foreach ([[$latitudeA, $longitudeA], [$latitudeB, $longitudeB]] as [$latitude, $longitude]) {
        if (!is_numeric($latitude) || !is_numeric($longitude)
            || (float)$latitude < -90 || (float)$latitude > 90
            || (float)$longitude < -180 || (float)$longitude > 180) {
            return '';
        }
    }

    $nearestSharedName = '';
    $nearestSharedDistance = $sharedLandmarkRadiusMeters;
    foreach ($mappedLandmarks as $landmarkId => $landmark) {
        if ($landmark['accuracy'] !== 'verified') {
            continue;
        }
        $distanceA = dcvsReportDuplicateDistanceMeters(
            (float)$latitudeA,
            (float)$longitudeA,
            $landmark['latitude'],
            $landmark['longitude']
        );
        $distanceB = dcvsReportDuplicateDistanceMeters(
            (float)$latitudeB,
            (float)$longitudeB,
            $landmark['latitude'],
            $landmark['longitude']
        );
        $furthestPinDistance = max($distanceA, $distanceB);
        if ($furthestPinDistance <= $nearestSharedDistance) {
            $nearestSharedName = $landmark['name'];
            $nearestSharedDistance = $furthestPinDistance;
        }
    }
    return $nearestSharedName;
};
foreach ($duplicatePairs as &$pair) {
    $pair['child_pin_landmark_name'] = $nearestPinLandmark(
        $pair['coordinates_lat'],
        $pair['coordinates_long'],
        $pair['landmark_id']
    );
    $pair['parent_pin_landmark_name'] = $nearestPinLandmark(
        $pair['parent_lat'],
        $pair['parent_lng'],
        $pair['parent_landmark_id']
    );
    $pair['shared_verified_landmark_name'] = $nearestSharedVerifiedLandmark(
        $pair['parent_lat'],
        $pair['parent_lng'],
        $pair['coordinates_lat'],
        $pair['coordinates_long']
    );
}
unset($pair);
$barangayOptionsSql = $portal['role'] === 'barangay'
    ? 'SELECT barangay_name FROM barangays WHERE barangay_id = ? ORDER BY barangay_name'
    : 'SELECT barangay_name FROM barangays ORDER BY barangay_name';
$barangayOptionsQuery = $conn->prepare($barangayOptionsSql);
if (!$barangayOptionsQuery) {
    error_log('Could not prepare Similar Reports barangay filters: ' . $conn->error);
    http_response_code(500);
    exit('Could not load barangay filters.');
}
if ($portal['role'] === 'barangay') $barangayOptionsQuery->bind_param('i', $barangayId);
if (!$barangayOptionsQuery->execute()) {
    error_log('Could not load Similar Reports barangay filters: ' . $barangayOptionsQuery->error);
    $barangayOptionsQuery->close();
    http_response_code(500);
    exit('Could not load barangay filters.');
}
$barangayOptionsResult = $barangayOptionsQuery->get_result();
while ($barangayOption = $barangayOptionsResult->fetch_assoc()) {
    $barangayName = trim((string)$barangayOption['barangay_name']);
    if ($barangayName !== '') $barangayOptions[$barangayName] = $barangayName;
}
$barangayOptionsResult->free();
$barangayOptionsQuery->close();
ksort($barangayOptions, SORT_NATURAL | SORT_FLAG_CASE);
$photosByReportId = [];
$photoReportIds = [];
foreach ($duplicatePairs as $pair) {
    $photoReportIds[(int)$pair['report_id']] = true;
    $parentReportId = (int)($pair['resolved_parent_id'] ?? 0);
    if ($parentReportId > 0) $photoReportIds[$parentReportId] = true;
}
if ($photoReportIds !== []) {
    $photoReportIds = array_keys($photoReportIds);
    $placeholders = implode(',', array_fill(0, count($photoReportIds), '?'));
    $photosQuery = $conn->prepare("SELECT attachment_id, report_id
        FROM report_attachments
        WHERE report_id IN ($placeholders)
          AND attachment_type = 'photo'
          AND attachment_source = 'resident'
        ORDER BY report_id, display_order, attachment_id");
    if (!$photosQuery) {
        error_log('Could not prepare similar report photo lookup: ' . $conn->error);
        http_response_code(500);
        exit('Could not load similar report photos.');
    }
    $photoBindValues = array_values($photoReportIds);
    $photoBindParameters = [str_repeat('i', count($photoBindValues))];
    foreach ($photoBindValues as &$photoBindValue) {
        $photoBindParameters[] = &$photoBindValue;
    }
    unset($photoBindValue);
    if (!call_user_func_array([$photosQuery, 'bind_param'], $photoBindParameters)
        || !$photosQuery->execute()) {
        error_log('Could not load similar report photos: ' . $photosQuery->error);
        $photosQuery->close();
        http_response_code(500);
        exit('Could not load similar report photos.');
    }
    $photoRows = $photosQuery->get_result();
    while ($photo = $photoRows->fetch_assoc()) {
        $photosByReportId[(int)$photo['report_id']][] = (int)$photo['attachment_id'];
    }
    $photoRows->free();
    $photosQuery->close();
}
$pageTitle = 'Similar Reports';
$activePage = 'report-duplicates';
$csrf = csrfToken();
$unreadNotifs = 0;
if ($portal['role'] === 'barangay') {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $unreadQuery = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL');
    if (!$unreadQuery) {
        error_log('Could not prepare Similar Reports notification count: ' . $conn->error);
    } else {
        $unreadQuery->bind_param('i', $currentUserId);
        if ($unreadQuery->execute()) {
            $unreadNotifs = (int)$unreadQuery->get_result()->fetch_row()[0];
        } else {
            error_log('Could not load Similar Reports notification count: ' . $unreadQuery->error);
        }
        $unreadQuery->close();
    }
}
$successMessage = is_string($_SESSION['report_duplicate_success'] ?? null) ? $_SESSION['report_duplicate_success'] : '';
$errorMessage = is_string($_SESSION['report_duplicate_error'] ?? null) ? $_SESSION['report_duplicate_error'] : '';
unset($_SESSION['report_duplicate_success'], $_SESSION['report_duplicate_error']);
$sessionArg = $portal['role'] === 'barangay' ? 'DCVS_BARANGAY_SESSION' : 'DCVS_STAFF_SESSION';
$photoUrlsByReportId = [];
foreach ($photosByReportId as $photoReportId => $attachmentIds) {
    foreach ($attachmentIds as $attachmentId) {
        $photoUrlsByReportId[$photoReportId][] = $appBaseUrl . '/api/shared/report-evidence-file.php?id='
            . (int)$attachmentId . '&sess=' . rawurlencode($sessionArg);
    }
}
$legacyReportPhotoUrl = static function ($url) use ($appBaseUrl): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    $parts = parse_url($url);
    if ($parts === false) return '';
    $path = (string)($parts['path'] ?? $url);
    if (isset($parts['host'])) {
        $base = parse_url($appBaseUrl);
        if ($base === false
            || strtolower((string)($parts['host'] ?? '')) !== strtolower((string)($base['host'] ?? ''))
            || strtolower((string)($parts['scheme'] ?? '')) !== strtolower((string)($base['scheme'] ?? ''))
            || (int)($parts['port'] ?? 0) !== (int)($base['port'] ?? 0)) {
            return '';
        }
    }
    $uploadsOffset = strpos($path, 'uploads/');
    if ($uploadsOffset === false) return '';
    $relativePath = substr($path, $uploadsOffset);
    if (!preg_match('~^uploads/(?:reports|report-evidence)/[^/]+$~', $relativePath)
        || in_array(substr($relativePath, strrpos($relativePath, '/') + 1), ['.', '..'], true)) {
        return '';
    }
    return rtrim($appBaseUrl, '/') . '/' . $relativePath;
};
foreach ($duplicatePairs as $pair) {
    $childReportId = (int)$pair['report_id'];
    $parentReportId = (int)($pair['resolved_parent_id'] ?? 0);
    if (empty($photoUrlsByReportId[$childReportId])) {
        $legacyUrl = $legacyReportPhotoUrl($pair['child_photo_url']);
        if ($legacyUrl !== '') $photoUrlsByReportId[$childReportId][] = $legacyUrl;
    }
    if ($parentReportId > 0 && empty($photoUrlsByReportId[$parentReportId])) {
        $legacyUrl = $legacyReportPhotoUrl($pair['parent_photo_url']);
        if ($legacyUrl !== '') $photoUrlsByReportId[$parentReportId][] = $legacyUrl;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Similar Reports — DCVS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/sb2.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/notification.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/notification.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/geo-map/map.css?v=' . (int)filemtime(__DIR__ . '/../../geo-map/map.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.umd.cjs?v=' . (int)filemtime(__DIR__ . '/../../dist/mapcn.umd.cjs'), ENT_QUOTES, 'UTF-8'); ?>"></script>
  <style>
    .duplicates-content {
      width: auto;
      max-width: none;
      margin: 0;
      padding: 0;
      color: #18181b;
      font: 13px/1.45 Inter, "Segoe UI", sans-serif;
    }
    .duplicate-page-header h1 { margin: 0 0 2px; color: #18181b; font-size: 20px; font-weight: 650; letter-spacing: -.02em; }
    .duplicates-intro { max-width: 720px; margin: 0 0 16px; color: #71717a; font-size: 12px; }
    .duplicate-flash { margin: 0 0 12px; padding: 9px 12px; border: 1px solid; border-radius: 6px; font-size: 12px; }
    .duplicate-flash.success { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
    .duplicate-flash.error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .duplicate-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .duplicate-filters input, .duplicate-filters select { height: 31px; border: 1px solid #d4d4d8; border-radius: 6px; background: #fff; color: #18181b; font: inherit; font-size: 12px; outline: none; }
    .duplicate-filters input { width: min(100%, 217px); padding: 0 9px; }
    .duplicate-filters input::placeholder { color: #71717a; }
    .duplicate-filters select { min-width: 88px; padding: 0 8px; }
    .duplicate-filters input:focus, .duplicate-filters select:focus { border-color: #a1a1aa; box-shadow: 0 0 0 2px rgba(24,24,27,.08); }
    .duplicate-review-grid { display: grid; grid-template-columns: 230px minmax(0, 1fr); align-items: start; gap: 14px; }
    .duplicate-queue { display: grid; gap: 7px; }
    .duplicate-queue-item { position: relative; display: block; width: 100%; min-height: 63px; padding: 9px 10px; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #18181b; text-align: left; cursor: pointer; }
    .duplicate-queue-item:hover { border-color: #a1a1aa; background: #fafafa; }
    .duplicate-queue-item[aria-current="true"] { border: 1.5px solid #15803d; background: #f0fdf4; box-shadow: 0 0 0 1px rgba(21,128,61,.05); }
    .duplicate-queue-title { display: block; max-width: calc(100% - 55px); overflow: hidden; color: #18181b; font-size: 12px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
    .duplicate-queue-summary, .duplicate-queue-meta { display: block; margin-top: 2px; color: #52525b; font-size: 11px; }
    .duplicate-queue-meta { color: #71717a; }
    .duplicate-comparison { min-width: 0; padding: 12px 13px 14px; border: 1px solid #e4e4e7; border-radius: 0; background: #fff; }
    .duplicate-comparison[hidden], .duplicate-queue-item[hidden], .duplicate-empty-filter[hidden] { display: none; }
    .duplicate-comparison-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 10px; }
    .duplicate-comparison-head h2 { margin: 0; color: #18181b; font-size: 14px; font-weight: 650; }
    .duplicate-distance-meta { margin: 3px 0 0; color: #71717a; font-size: 13px; font-weight: 400; line-height: 1.45; }
    .duplicate-table { display: grid; min-width: 0; }
    .duplicate-table-head, .duplicate-compare-row { display: grid; grid-template-columns: 76px minmax(0, 1fr) minmax(0, 1fr) 82px; align-items: center; gap: 7px; min-width: 0; }
    .duplicate-table-head { min-height: 38px; border-bottom: 1px solid #e4e4e7; color: #71717a; text-align: center; }
    .duplicate-table-head > span:first-child { text-align: left; }
    .duplicate-column-title { display: block; color: #18181b; font-size: 14px; font-weight: 500; line-height: 1.35; }
    .duplicate-table-head > span:last-child .duplicate-column-title { color: #18181b; }
    .duplicate-compare-row { min-height: 36px; padding: 6px 0; border-bottom: 1px solid #f1f1f2; }
    .duplicate-compare-row > span { min-width: 0; overflow-wrap: anywhere; }
    .duplicate-compare-label { color: #71717a; font-size: 13px; font-weight: 600; }
    .duplicate-compare-value { color: #18181b; font-size: 14px; font-weight: 400; line-height: 1.4; }
    .duplicate-result { display: inline-flex; align-items: center; justify-self: end; padding: 2px 7px; border: 1px solid #e4e4e7; border-radius: 4px; font: 500 11px/1.3 Inter, "Segoe UI", sans-serif; text-align: center; white-space: nowrap; }
    .duplicate-result.match { border-color: #15803d; background: #15803d; color: #fff; }
    .duplicate-result.differs { border-color: #fde68a; background: #fef3c7; color: #92400e; }
    .duplicate-result.unknown { border-color: #e4e4e7; background: #f4f4f5; color: #52525b; }
    .duplicate-photo-row { align-items: start; }
    .duplicate-photo-cell { min-width: 0; }
    .duplicate-photo-gallery { display: grid; max-height: 224px; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 7px; overflow: auto; }
    .duplicate-photo-link { display: block; min-width: 0; overflow: hidden; border: 1px solid #e4e4e7; border-radius: 6px; background: #f4f4f5; }
    .duplicate-photo-link img { display: block; width: 100%; height: 104px; object-fit: cover; }
    .duplicate-photo-empty { display: flex; min-height: 58px; align-items: center; justify-content: center; border-radius: 6px; background: #f4f4f5; color: #71717a; font-size: 12px; }
    .duplicate-map-section { margin-top: 12px; }
    .duplicate-map-heading { margin: 0 0 6px; color: #18181b; font-size: 12px; font-weight: 600; }
    .duplicate-map-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 7px; color: #52525b; font-size: 11px; }
    .duplicate-map-legend-item { display: inline-flex; align-items: center; gap: 5px; }
    .duplicate-map-legend-item[hidden] { display: none; }
    .duplicate-map-legend-dot { width: 9px; height: 9px; border-radius: 50%; }
    .duplicate-map-legend-dot.main { background: #15803d; }
    .duplicate-map-legend-dot.similar { background: #7c3aed; }
    .duplicate-map-slot { position: relative; min-height: 120px; overflow: hidden; border: 1px solid #e4e4e7; border-radius: 7px; background: #f4f4f5; }
    .duplicate-geo-map { width: 100%; height: min(680px, max(560px, 80vh)); position: relative; }
    .duplicate-geo-map[hidden] { display: none !important; }
    .duplicate-map-unavailable { display: flex; min-height: 120px; align-items: center; justify-content: center; padding: 16px; color: #71717a; font-size: 12px; text-align: center; }
    .duplicate-map-unavailable[hidden] { display: none !important; }
    .duplicate-empty-detail[hidden] { display: none; }
    .duplicate-confirm-dialog { position: fixed; top: 50%; left: 50%; width: min(420px, calc(100vw - 32px)); max-height: calc(100vh - 32px); margin: 0; padding: 22px; transform: translate(-50%, -50%); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #18181b; box-shadow: 0 20px 40px rgba(0,0,0,.2); font: 14px/1.5 Inter, "Segoe UI", sans-serif; }
    .duplicate-confirm-dialog::backdrop { background: rgba(15,23,42,.48); }
    .duplicate-confirm-dialog h2 { margin: 0 0 8px; font-size: 16px; font-weight: 600; letter-spacing: -.01em; }
    .duplicate-confirm-dialog p { margin: 0; color: #71717a; font-size: 13px; }
    .duplicate-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }
    .duplicate-confirm-actions button { min-height: 36px; padding: 0 12px; border: 1px solid #e4e4e7; border-radius: 6px; background: #fff; color: #18181b; font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; }
    .duplicate-confirm-actions button:hover { background: #f4f4f5; }
    .duplicate-confirm-actions button[data-confirm-action] { border-color: #15803d; background: #15803d; color: #fff; }
    .duplicate-confirm-actions button[data-confirm-action]:hover { background: #166534; }
    .duplicate-confirm-actions button[data-confirm-action][data-variant="destructive"] { border-color: #dc2626; background: #dc2626; }
    .duplicate-confirm-actions button[data-confirm-action][data-variant="destructive"]:hover { background: #b91c1c; }
    .duplicate-confirm-actions button:focus-visible { outline: 2px solid #15803d; outline-offset: 2px; }
    .duplicate-review-form { margin-top: 11px; }
    .duplicate-review-form label { display: block; margin-bottom: 8px; color: #52525b; font-size: 11px; font-weight: 500; }
    .duplicate-action-buttons { display: flex; flex-wrap: wrap; gap: 6px; }
    .duplicate-action-buttons button { min-height: 30px; padding: 0 10px; border: 1px solid #d4d4d8; border-radius: 6px; background: #fff; color: #3f3f46; font: inherit; font-size: 11px; font-weight: 550; cursor: pointer; }
    .duplicate-action-buttons button:hover { background: #f4f4f5; }
    .duplicate-action-buttons button[data-action="merge"] { border-color: #15803d; background: #15803d; color: #fff; }
    .duplicate-action-buttons button[data-action="merge"]:hover { background: #166534; }
    .duplicate-action-buttons button[data-action="not_duplicate"] { color: #9f1239; }
    .duplicate-action-buttons button[data-action="not_duplicate"]:hover { border-color: #fecdd3; background: #fff1f2; }
    .duplicate-latest-action { margin-top: 9px; color: #71717a; font-size: 10px; }
    .duplicate-empty-filter { padding: 24px 12px; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #71717a; font-size: 12px; text-align: center; }
    .duplicate-empty-queue { padding: 18px 12px; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #71717a; font-size: 12px; line-height: 1.5; text-align: center; }
    .duplicate-empty-detail { display: flex; min-height: 250px; align-items: center; justify-content: center; padding: 18px; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #71717a; font-size: 12px; text-align: center; }
    @media (max-width: 1100px) {
      .duplicate-review-grid { grid-template-columns: 190px minmax(0, 1fr); }
      .duplicate-table-head, .duplicate-compare-row { grid-template-columns: 64px minmax(0, 1fr) minmax(0, 1fr) 76px; gap: 5px; }
    }
    @media (max-width: 900px) {
      .duplicate-review-grid { grid-template-columns: 190px minmax(0, 1fr); gap: 10px; }
      .duplicate-review-grid { min-width: 720px; }
      .duplicates-content { overflow-x: auto; }
    }
    @media (max-width: 700px) {
      .duplicates-content { padding: 16px 12px 32px; }
      .duplicate-filters input { flex: 1 1 100%; width: 100%; }
      .duplicate-review-grid { display: flex; min-width: 0; flex-direction: column; }
      .duplicate-queue { width: 100%; grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .duplicate-queue-item { min-width: 0; }
      .duplicate-comparison, .duplicate-empty-detail { width: 100%; }
      .duplicate-table-head, .duplicate-compare-row { grid-template-columns: 54px minmax(0, 1fr) minmax(0, 1fr) 66px; gap: 4px; }
      .duplicate-compare-value { font-size: 14px; }
      .duplicate-result { padding: 2px 5px; font-size: 10px; }
      .duplicate-geo-map { height: min(560px, max(430px, 70vh)); }
    }
    body.duplicate-review-page { font-family: 'Inter', 'Segoe UI', sans-serif; color: #18181b; }
    body.duplicate-review-page .sb2-menu-button,
    body.duplicate-review-page .sb2-menu-sub-button { font-family: 'Inter', 'Segoe UI', sans-serif; font-size: 14px; font-weight: 500; }
    .duplicates-content {
      box-sizing: border-box;
      width: auto;
      max-width: none;
      min-height: calc(100vh - var(--topbar-h, 64px));
      margin: 0;
      padding: 0;
      color: #14201A;
    }
    body.duplicate-review-page .main-content { background: #fff; }
    body.duplicate-review-page .topbar { height: 64px; padding: 0 32px; }
    body.duplicate-review-page .topbar-left { display: flex; min-width: 0; align-items: center; gap: 16px; }
    body.duplicate-review-page .topbar-right { display: flex; min-width: 0; align-items: center; gap: 14px; }
    body.duplicate-review-page .notif-dropdown { position: relative; }
    body.duplicate-review-page .notif-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; padding: 6px; border: 0; border-radius: 8px; background: none; color: #475569; cursor: pointer; }
    body.duplicate-review-page .notif-btn:hover { background: #f1f5f9; }
    body.duplicate-review-page .notif-badge { position: absolute; top: 0; right: 0; display: flex; min-width: 18px; height: 18px; align-items: center; justify-content: center; padding: 0 4px; border-radius: 9px; background: #dc2626; color: #fff; font-size: 10px; font-weight: 700; }
    body.duplicate-review-page .notif-panel { position: absolute; top: calc(100% + 8px); right: 0; z-index: 200; display: none; width: 380px; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
    body.duplicate-review-page .notif-panel.open { display: block; }
    body.duplicate-review-page .notif-panel-header { display: flex; align-items: center; justify-content: space-between; padding: .9rem 1rem; border-bottom: 1px solid #e2e8f0; }
    body.duplicate-review-page .notif-panel-header h4 { margin: 0; color: #0f172a; font-size: .85rem; font-weight: 700; }
    body.duplicate-review-page .notif-mark-all { border: 0; background: transparent; color: #0e8131; font-size: .75rem; font-weight: 600; cursor: pointer; }
    body.duplicate-review-page .notif-mark-all:disabled { opacity: .5; cursor: default; }
    body.duplicate-review-page .notif-tabs { display: flex; gap: 4px; padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
    body.duplicate-review-page .notif-tab { padding: 5px 8px; border: 0; border-radius: 6px; background: transparent; color: #64748b; font-size: 12px; cursor: pointer; }
    body.duplicate-review-page .notif-tab.active { background: #f0fdf4; color: #166534; }
    body.duplicate-review-page .notif-panel-body { max-height: 360px; overflow-y: auto; }
    body.duplicate-review-page .notif-feedback { padding: 0 12px; color: #b91c1c; font-size: 12px; }
    body.duplicate-review-page .notif-empty { padding: 2rem; color: #94a3b8; font-size: .82rem; text-align: center; }
    @media (max-width: 768px) {
      body.duplicate-review-page .topbar { left: 0 !important; height: 60px; padding: 0 16px; }
      body.duplicate-review-page .topbar-left { gap: 8px; }
      body.duplicate-review-page .topbar-right { gap: 8px; }
    }
    @media (max-width: 640px) {
      body.duplicate-review-page .notif-panel { right: .5rem; width: calc(100vw - 2rem); }
      body.duplicate-review-page .user-meta { display: none; }
    }
    .duplicate-content-inner { width: 100%; max-width: none; margin: 0; }
    .duplicates-content, .duplicates-content button, .duplicates-content input,
    .duplicates-content select, .duplicates-content textarea { font-family: 'Inter', 'Segoe UI', sans-serif; }
    .duplicate-page-header h1 { color: #14201A; font-size: 20px; font-weight: 600; letter-spacing: -.025em; }
    .duplicates-intro { color: #5B675F; font-size: 14px; line-height: 1.45; }
    .duplicate-flash { font-size: 13px; }
    .duplicate-filters input { height: 36px; font-size: 14px; }
    .duplicate-filters select.tf-select { min-width: 100px; height: 36px; padding: 0 26px 0 10px; border: 1px solid #E2E6E1; border-radius: 6px; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%235B675F' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 10px center; color: #14201A; font-size: 14px; font-weight: 500; box-shadow: 0 1px 2px rgba(0,0,0,.05); appearance: none; -webkit-appearance: none; }
    .duplicate-filters select.tf-select:focus { border-color: #166534; box-shadow: 0 0 0 1px #166534; }
    .duplicate-queue-item { min-height: 70px; padding: 10px 12px; border-color: #E2E6E1; color: #14201A; }
    .duplicate-queue-item:hover { border-color: #5B675F; }
    .duplicate-queue-item[aria-current="true"] { border-color: #166534; background: #f0fdf4; }
    .duplicate-queue-title { color: #14201A; font-size: 14px; font-weight: 500; }
    .duplicate-queue-summary, .duplicate-queue-meta { color: #5B675F; font-size: 13px; }
    .duplicate-queue-meta { font-size: 12.5px; }
    .duplicate-comparison { padding: 14px 14px 16px; }
    .duplicate-comparison-head h2 { font-size: 16px; font-weight: 600; }
    .duplicate-distance-meta { font-size: 12px; }
    .duplicate-review-form label { font-size: 13px; }
    .duplicate-action-buttons button { font-size: 13px; font-weight: 500; }
    .duplicate-latest-action { font-size: 12px; }
    .duplicate-empty-filter, .duplicate-empty-queue, .duplicate-empty-detail { font-size: 14px; }
    .duplicate-review-grid { width: 100%; grid-template-columns: 260px minmax(0, 1fr); align-items: start; gap: 20px; }
    .duplicate-queue { position: sticky; top: calc(var(--topbar-h, 64px) + 14px); max-height: calc(100vh - var(--topbar-h, 64px) - 28px); overflow-y: auto; padding: 0; border: 0; border-radius: 0; background: transparent; }
    .duplicate-queue::before { content: "NEEDS REVIEW · <?php echo count($duplicatePairs); ?>"; display: block; padding: 2px 2px 7px; color: #5B675F; font-size: 11px; font-weight: 600; letter-spacing: .04em; }
    .duplicate-comparison { position: relative; width: 100%; max-width: 1120px; min-width: 0; overflow: visible; padding: 18px 18px 0; border: 1px solid #E2E6E1; border-radius: 10px; background: #fff; box-shadow: 0 1px 3px rgba(20,32,26,.04); }
    .duplicate-comparison-head,
    .duplicate-pair-insights,
    .duplicate-section-caption { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .duplicate-comparison-head { margin-bottom: 12px; }
    .duplicate-comparison-head h2 { color: #14201A; font-size: 20px; font-weight: 600; letter-spacing: -.025em; line-height: 1.2; }
    .duplicate-distance-meta { max-width: 760px; margin-top: 5px; color: #5B675F; font-size: 14px; font-weight: 400; line-height: 1.5; }
    .duplicate-pair-insights { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 16px; }
    .duplicate-insight { display: grid; min-width: 80px; gap: 1px; align-content: center; padding: 8px 11px; border: 1px solid #E2E6E1; border-radius: 8px; background: #fff; }
    .duplicate-distance-insight { min-width: 160px; max-width: 245px; }
    .duplicate-insight strong { color: #14201A; font-size: 18px; font-weight: 600; letter-spacing: -.025em; line-height: 1.1; }
    .duplicate-insight span { overflow-wrap: anywhere; color: #5B675F; font-size: 12px; line-height: 1.4; }
    .duplicate-insight.same-resident { min-width: 200px; border-color: #DCCFF0; background: #F7F3FD; }
    .duplicate-insight.same-resident strong { color: #4C1D95; font-size: 14px; font-weight: 600; letter-spacing: -.01em; }
    .duplicate-section-caption { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin: 0 0 8px; }
    .duplicate-section-caption h3 { margin: 0; color: #14201A; font-size: 16px; font-weight: 600; letter-spacing: -.02em; line-height: 1.3; }
    .duplicate-section-caption > span { color: #5B675F; font-size: 12px; line-height: 1.4; }
    .duplicate-report-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .duplicate-report-card { min-width: 0; overflow: hidden; border: 1px solid #E2E6E1; border-radius: 9px; background: #fff; }
    .duplicate-card-media { position: relative; display: block; box-sizing: border-box; width: 100%; aspect-ratio: 16 / 10; max-height: 300px; overflow: hidden; background: #E9EDEA; }
    .duplicate-card-photos { position: absolute; inset: 0; display: grid; width: 100%; height: 100%; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: 1fr; gap: 0; }
    .duplicate-card-photos[data-photo-count="1"] { grid-template-columns: 1fr; }
    .duplicate-card-status { position: absolute; z-index: 1; top: 9px; left: 9px; max-width: calc(100% - 18px); overflow: hidden; padding: 4px 7px; border-radius: 5px; background: #166534; color: #fff; font-size: 10px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
    .duplicate-card-status.similar { background: #6D28D9; }
    .duplicate-card-photo { position: relative; display: block; min-width: 0; min-height: 0; overflow: hidden; background: #E9EDEA; }
    .duplicate-card-photo img { position: absolute; inset: 0; display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; }
    .duplicate-card-photo-empty { position: absolute; inset: 0; display: grid; place-items: center; color: #5B675F; font-size: 12px; }
    .duplicate-summary-fields { display: grid; gap: 4px; margin: 0; padding: 9px; }
    .duplicate-summary-row { display: grid; grid-template-columns: 64px minmax(0, 1fr); gap: 8px; align-items: start; padding: 6px 7px; border-radius: 6px; background: #fff; font-size: 12px; }
    .duplicate-summary-row dt { color: #5B675F; font-weight: 400; }
    .duplicate-summary-row dd { min-width: 0; margin: 0; overflow-wrap: anywhere; color: #14201A; }
    .duplicate-summary-row.is-different { background: #FFF8E6; }
    .duplicate-summary-row.is-match { background: #F7F3FD; }
    .duplicate-summary-row.duplicate-description-row { align-items: start; }
    .duplicate-summary-row.duplicate-description-row dd { line-height: 1.5; }
    .duplicate-field-note { display: inline-flex; align-items: center; margin-left: 7px; padding: 3px 8px; border: 1px solid #F2D18C; border-radius: 999px; background: #FFF3D6; color: #7A4B00; font-size: 11px; font-weight: 600; line-height: 1.25; vertical-align: middle; white-space: nowrap; }
    .duplicate-match-chip { display: inline-flex; align-items: center; margin-left: 7px; padding: 3px 8px; border: 1px solid #DCCFF0; border-radius: 999px; background: #F7F3FD; color: #4C1D95; font-size: 11px; font-weight: 600; line-height: 1.25; vertical-align: middle; white-space: nowrap; }
    .duplicate-diff-highlight { padding: 0 1px; background: #FFF8E6; color: inherit; }
    .duplicate-fields-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 37px; margin-top: 7px; color: #5B675F; font-size: 11px; }
    .duplicate-show-fields { padding: 5px 0; border: 0; background: transparent; color: #166534; font: inherit; font-weight: 600; cursor: pointer; }
    .duplicate-show-fields:hover { text-decoration: underline; }
    .duplicate-matching-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 18px; margin: 0 0 12px; padding: 8px 12px; border: 1px solid #E2E6E1; border-radius: 9px; background: #fff; }
    .duplicate-matching-fields[hidden] { display: none; }
    .duplicate-matching-field { display: flex; min-width: 0; flex-direction: column; gap: 3px; padding: 7px 4px; border-bottom: 1px solid #F0F2F0; }
    .duplicate-matching-field dt { color: #5B675F; font-size: 11px; }
    .duplicate-matching-field dd { min-width: 0; margin: 0; overflow-wrap: anywhere; color: #14201A; font-size: 12px; }
    .duplicate-map-section { margin: 12px 0 16px; }
    .duplicate-map-heading { font-size: 14px; }
    .duplicate-geo-map { height: min(460px, max(340px, 56vh)); }
    .duplicate-review-form { position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0 -18px; padding: 10px 18px; border-top: 1px solid #E2E6E1; background: rgba(255,255,255,.97); box-shadow: 0 -5px 14px rgba(20,32,26,.06); backdrop-filter: blur(8px); }
    .duplicate-action-explanation { max-width: 540px; margin: 0; color: #5B675F; font-size: 11px; line-height: 1.4; }
    .duplicate-action-explanation strong { color: #14201A; font-weight: 600; }
    .duplicate-action-buttons { flex-wrap: nowrap; justify-content: flex-end; gap: 8px; }
    .duplicate-action-buttons button { min-height: 36px; padding: 0 12px; border-color: #166534; color: #166534; font-size: 12px; white-space: nowrap; }
    .duplicate-action-buttons button[data-action="merge"] { border-color: #166534; background: #166534; color: #fff; font-weight: 600; }
    .duplicate-action-buttons button[data-action="merge"]:hover { background: #14532d; }
    .duplicate-action-buttons button[data-action="not_duplicate"] { color: #166534; }
    .duplicate-action-buttons button[data-action="not_duplicate"]:hover { border-color: #166534; background: #F7F3FD; }
    .duplicate-empty-detail { border-radius: 10px; }
    @media (max-width: 900px) {
      .duplicate-review-grid { grid-template-columns: 180px minmax(0, 1fr); min-width: 0; }
      .duplicates-content { overflow-x: visible; }
      .duplicate-summary-row { grid-template-columns: 58px minmax(0, 1fr); gap: 5px; }
      .duplicate-matching-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 700px) {
      .duplicates-content { overflow: visible; }
      .duplicate-review-grid { display: flex; flex-direction: column; min-width: 0; }
      .duplicate-queue { position: static; width: 100%; max-height: 190px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .duplicate-queue::before { grid-column: 1 / -1; }
      .duplicate-comparisons { width: 100%; }
      .duplicate-comparison { padding: 14px 12px 0; }
      .duplicate-report-cards { grid-template-columns: 1fr; }
      .duplicate-card-media { max-height: 300px; }
      .duplicate-review-form { align-items: stretch; flex-direction: column; gap: 8px; margin: 0 -12px; padding: 10px 12px max(10px, env(safe-area-inset-bottom)); }
      .duplicate-action-buttons { display: grid; grid-template-columns: 1fr 1fr; }
      .duplicate-action-buttons button { min-height: 40px; padding: 4px 8px; white-space: normal; }
      .duplicate-geo-map { height: min(420px, max(280px, 48vh)); }
    }
  </style>
</head>
<body class="sb2-active duplicate-review-page">
<?php
if ($portal['role'] === 'staff') {
    include __DIR__ . '/../../includes/staff-sidebar.php';
} else {
    include __DIR__ . '/../../includes/barangay-sidebar.php';
}
?>
<?php if ($portal['role'] === 'barangay'):
  $topbarAvatar = trim((string)($barangayAvatar ?? ''));
  if ($topbarAvatar !== '' && !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $topbarAvatar)) {
      $topbarAvatar = $appBaseUrl . '/' . ltrim($topbarAvatar, '/');
  }
?>
<header class="topbar">
  <div class="topbar-left">
    <button class="topbar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
    <div class="topbar-breadcrumb">
      <span class="breadcrumb-root">Barangay Portal</span>
      <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
      <span class="breadcrumb-current" id="breadcrumbCurrent">Similar Reports</span>
    </div>
  </div>
  <div class="topbar-right">
    <div class="notif-dropdown" id="notifDropdown">
      <button class="notif-btn" id="notif-btn" type="button" title="<?php echo (int)$unreadNotifs; ?> unread notifications" aria-label="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
      </button>
      <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
        data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
        data-action-url="<?php echo htmlspecialchars($appBaseUrl . '/includes/staff-notification-action.php?portal=barangay', ENT_QUOTES, 'UTF-8'); ?>"
        data-load-url="<?php echo htmlspecialchars($appBaseUrl . '/get-notifications.php?limit=30', ENT_QUOTES, 'UTF-8'); ?>"
        data-app-base-url="<?php echo htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="notif-panel-header">
          <h4>Notifications</h4>
          <button class="notif-mark-all" type="button" <?php echo $unreadNotifs === 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read</button>
        </div>
        <div class="notif-tabs" role="tablist" aria-label="Notification filters">
          <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadNotifs > 0 ? ($unreadNotifs > 99 ? '99+' : $unreadNotifs) : ''; ?></span></button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
        </div>
        <div class="notif-feedback" role="status" aria-live="polite"></div>
        <div class="notif-panel-body" id="notifList"><div class="notif-empty">Loading notifications…</div></div>
      </div>
    </div>
    <div class="user-profile">
      <div class="user-avatar">
        <?php if ($topbarAvatar !== ''): ?>
          <img src="<?php echo htmlspecialchars($topbarAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
          <i class="fas fa-user" aria-hidden="true"></i>
        <?php endif; ?>
      </div>
      <div class="user-meta">
        <span class="user-name"><?php echo htmlspecialchars((string)($fullName ?? 'Barangay Officer'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="user-role">Barangay Officer</span>
      </div>
    </div>
  </div>
</header>
<?php endif; ?>
<main class="main-content">
  <?php
    $escape = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    $formatDetailDate = static function ($value): string {
        return $value ? date('M d, Y h:i A', strtotime((string)$value)) : 'Not available';
    };
    $normalizeComparable = static function ($value) use ($lowercase): string {
        preg_match_all('/[\p{L}\p{N}]+/u', (string)$value, $matches);
        $tokens = array_map($lowercase, $matches[0] ?? []);
        return implode(' ', $tokens);
    };
    $compareResult = static function ($main, $similar) use ($normalizeComparable): array {
        $mainValue = trim((string)$main);
        $similarValue = trim((string)$similar);
        if ($mainValue === '' || $similarValue === '') return ['Unknown', 'unknown'];
        return $normalizeComparable($mainValue) === $normalizeComparable($similarValue)
            ? ['Match', 'match']
            : ['Differs', 'differs'];
    };
    $highlightDifferences = static function ($value, $other) use ($escape, $lowercase, $normalizeComparable): string {
        $text = trim((string)$value);
        if ($text === '') return $escape('Not provided');
        $otherText = trim((string)$other);
        if ($normalizeComparable($text) === $normalizeComparable($otherText)) {
            return $escape($text);
        }
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $leftMatches);
        preg_match_all('/[\p{L}\p{N}]+/u', $otherText, $rightMatches);
        $leftTokens = array_map($lowercase, $leftMatches[0] ?? []);
        $rightTokens = array_map($lowercase, $rightMatches[0] ?? []);
        $leftCount = count($leftTokens);
        $rightCount = count($rightTokens);
        $lcs = array_fill(0, $leftCount + 1, array_fill(0, $rightCount + 1, 0));
        for ($leftIndex = $leftCount - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = $rightCount - 1; $rightIndex >= 0; $rightIndex--) {
                $lcs[$leftIndex][$rightIndex] = $leftTokens[$leftIndex] === $rightTokens[$rightIndex]
                    ? $lcs[$leftIndex + 1][$rightIndex + 1] + 1
                    : max($lcs[$leftIndex + 1][$rightIndex], $lcs[$leftIndex][$rightIndex + 1]);
            }
        }
        $matchingLeftTokens = [];
        $leftIndex = 0;
        $rightIndex = 0;
        while ($leftIndex < $leftCount && $rightIndex < $rightCount) {
            if ($leftTokens[$leftIndex] === $rightTokens[$rightIndex]) {
                $matchingLeftTokens[$leftIndex] = true;
                $leftIndex++;
                $rightIndex++;
            } elseif ($lcs[$leftIndex + 1][$rightIndex] >= $lcs[$leftIndex][$rightIndex + 1]) {
                $leftIndex++;
            } else {
                $rightIndex++;
            }
        }
        $tokenIndex = 0;
        $highlighted = preg_replace_callback(
            '/[\p{L}\p{N}]+/u',
            static function (array $matches) use ($escape, &$tokenIndex, $matchingLeftTokens): string {
                $currentIndex = $tokenIndex++;
                $token = (string)$matches[0];
                $escapedToken = $escape($token);
                return isset($matchingLeftTokens[$currentIndex])
                    ? $escapedToken
                    : '<mark class="duplicate-diff-highlight">' . $escapedToken . '</mark>';
            },
            $text
        );
        return $highlighted === null ? $escape($text) : $highlighted;
    };
    $formatDistance = static function ($distance): string {
        if ($distance === null) return 'Pin unavailable';
        return (int)$distance >= 1000
            ? number_format((int)$distance / 1000, 1) . ' km apart'
            : (int)$distance . ' m apart';
    };
    $formatElapsed = static function ($seconds): string {
        if ($seconds === null) return 'Time unavailable';
        $minutes = max(0, (int)floor((int)$seconds / 60));
        $days = (int)floor($minutes / 1440);
        $hours = (int)floor(($minutes % 1440) / 60);
        $remainingMinutes = $minutes % 60;
        $parts = [];
        if ($days > 0) $parts[] = $days . ($days === 1 ? ' day' : ' days');
        if ($hours > 0) $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
        if ($remainingMinutes > 0 || !$parts) {
            $parts[] = $remainingMinutes . ($remainingMinutes === 1 ? ' minute' : ' minutes');
        }
        if (count($parts) === 1) return $parts[0] . ' apart';
        $lastPart = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $lastPart . ' apart';
    };
  ?>
  <div class="duplicates-content">
  <div class="duplicate-content-inner">
    <header class="duplicate-page-header">
      <h1>Similar Reports</h1>
      <p class="duplicates-intro">Compare report details, photos, and incident locations before accepting a separate report or linking it to an existing incident.</p>
    </header>

    <?php if ($successMessage !== ''): ?>
      <div class="duplicate-flash success" role="status"><?php echo $escape($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
      <div class="duplicate-flash error" role="alert"><?php echo $escape($errorMessage); ?></div>
    <?php endif; ?>

    <div class="duplicate-filters" role="search">
      <input id="duplicateSearch" type="search" placeholder="Search report ID or location" aria-label="Search report ID or location">
      <select id="duplicateBarangay" class="select-input tf-select staff-toolbar-select" aria-label="Filter by barangay">
        <option value="">All Barangays</option>
        <?php foreach ($barangayOptions as $barangayName): ?>
          <option value="<?php echo $escape($lowercase($barangayName)); ?>"><?php echo $escape($barangayName); ?></option>
        <?php endforeach; ?>
      </select>
      <select id="duplicateType" class="select-input tf-select staff-toolbar-select" aria-label="Filter by report type">
        <option value="">All Types</option>
        <?php foreach ($typeOptions as $type => $label): ?>
          <option value="<?php echo $escape($type); ?>"><?php echo $escape($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="duplicate-review-grid">
      <section class="duplicate-queue" aria-label="Similar report pairs">
        <?php if ($duplicatePairs === []): ?>
          <div class="duplicate-empty-queue">No reports are awaiting review.</div>
        <?php endif; ?>
        <?php foreach ($duplicatePairs as $index => $pair): ?>
          <?php
            $childId = (int)$pair['report_id'];
            $parentId = (int)($pair['resolved_parent_id'] ?? 0);
            $barangayName = (string)($pair['child_barangay_name'] ?: $pair['parent_barangay_name'] ?: 'Unknown barangay');
            $searchText = implode(' ', [
                $pair['reference_id'] ?? '',
                $pair['parent_reference_id'] ?? '',
                $pair['location_address'] ?? '',
                $pair['parent_location_address'] ?? '',
                $barangayName,
                $pair['report_type_label'],
            ]);
          ?>
          <button
            class="duplicate-queue-item"
            type="button"
            data-pair-id="<?php echo $childId; ?>"
            data-barangay="<?php echo $escape($lowercase($barangayName)); ?>"
            data-type="<?php echo $escape($pair['report_type']); ?>"
            data-search="<?php echo $escape($lowercase($searchText)); ?>"
            aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>"
          >
            <span class="duplicate-queue-title"><?php echo $escape($barangayName . ' · ' . $pair['report_type_label']); ?></span>
            <span class="duplicate-queue-summary"><?php echo $escape(ucfirst((string)$pair['animal_type'] ?: 'Animal')); ?> · <?php echo (int)$pair['report_count']; ?> reports</span>
            <span class="duplicate-queue-meta"><?php echo $escape($formatDistance($pair['distance_meters'])); ?> · <?php echo $escape($formatElapsed($pair['time_difference_seconds'])); ?></span>
          </button>
        <?php endforeach; ?>
        <div class="duplicate-empty-filter" data-duplicate-empty hidden>No pairs match these filters.</div>
      </section>
      <section class="duplicate-comparisons" aria-label="Selected report comparison">
        <?php if ($duplicatePairs === []): ?>
          <div class="duplicate-empty-detail">A report comparison will appear here when a possible match needs review.</div>
        <?php endif; ?>
        <?php foreach ($duplicatePairs as $index => $pair): ?>
          <?php
            $childId = (int)$pair['report_id'];
            $parentId = (int)($pair['resolved_parent_id'] ?? 0);
            $barangayName = (string)($pair['child_barangay_name'] ?: $pair['parent_barangay_name'] ?: 'Unknown barangay');
            $childReference = (string)($pair['reference_id'] ?: ('Report #' . $childId));
            $parentReference = (string)($pair['parent_reference_id'] ?: ('Report #' . $parentId));
            $childLocation = trim((string)$pair['location_address']);
            $parentLocation = trim((string)$pair['parent_location_address']);
            $childPhotoUrls = $photoUrlsByReportId[$childId] ?? [];
            $parentPhotoUrls = $photoUrlsByReportId[$parentId] ?? [];
            $reportDetails = [
                [
                    'heading' => 'Main report',
                    'reference' => $parentReference,
                    'reporter' => $pair['parent_reporter_name'],
                    'created_at' => $pair['parent_created_at'],
                    'incident_at' => $pair['parent_incident_at'],
                    'location' => $parentLocation,
                    'landmark' => $pair['parent_pin_landmark_name'] ?: '',
                    'type' => $reportTypeLabels[$pair['parent_report_type']] ?? ucfirst((string)$pair['parent_report_type']),
                    'animal_type' => $pair['parent_animal_type'],
                    'animal_breed' => $pair['parent_animal_breed'],
                    'animal_count' => $pair['parent_animal_count'],
                    'animal_behavior' => $pair['parent_animal_behavior'],
                    'resident_id' => $pair['parent_reporter_reference_id'],
                    'phone' => $pair['parent_reporter_phone'],
                    'email' => $pair['parent_reporter_email'],
                    'barangay' => $pair['parent_barangay_name'],
                    'status' => $pair['parent_status_label'],
                    'assigned_staff' => $pair['parent_confirmed_staff'] ?: $pair['parent_validated_staff'],
                    'resolved_at' => $pair['parent_resolved_at'],
                    'description' => $pair['parent_description'],
                    'photo_urls' => $parentPhotoUrls,
                ],
                [
                    'heading' => 'Similar report',
                    'reference' => $childReference,
                    'reporter' => $pair['reporter_name'],
                    'created_at' => $pair['created_at'],
                    'incident_at' => $pair['incident_at'],
                    'location' => $childLocation,
                    'landmark' => $pair['child_pin_landmark_name'] ?: '',
                    'type' => $pair['report_type_label'],
                    'animal_type' => $pair['animal_type'],
                    'animal_breed' => $pair['animal_breed'],
                    'animal_count' => $pair['animal_count'],
                    'animal_behavior' => $pair['animal_behavior'],
                    'resident_id' => $pair['reporter_reference_id'],
                    'phone' => $pair['reporter_phone'],
                    'email' => $pair['reporter_email'],
                    'barangay' => $pair['child_barangay_name'],
                    'status' => $pair['child_status_label'],
                    'assigned_staff' => $pair['child_confirmed_staff'] ?: $pair['child_validated_staff'],
                    'resolved_at' => $pair['child_resolved_at'],
                    'description' => $pair['description'],
                    'photo_urls' => $childPhotoUrls,
                ],
            ];
            $mainDetail = $reportDetails[0];
            $similarDetail = $reportDetails[1];
            $mainCoordinatesAvailable = is_numeric($pair['parent_lat'])
                && is_numeric($pair['parent_lng'])
                && (float)$pair['parent_lat'] >= -90 && (float)$pair['parent_lat'] <= 90
                && (float)$pair['parent_lng'] >= -180 && (float)$pair['parent_lng'] <= 180;
            $similarCoordinatesAvailable = is_numeric($pair['coordinates_lat'])
                && is_numeric($pair['coordinates_long'])
                && (float)$pair['coordinates_lat'] >= -90 && (float)$pair['coordinates_lat'] <= 90
                && (float)$pair['coordinates_long'] >= -180 && (float)$pair['coordinates_long'] <= 180;
            $sameResident = (int)$pair['parent_reporter_id'] > 0
                && (int)$pair['parent_reporter_id'] === (int)$pair['reporter_id'];
            $elapsedLabel = str_replace(' apart', '', $formatElapsed($pair['time_difference_seconds']));
            if ($pair['distance_meters'] === null) {
                $distanceValue = '—';
                $distanceUnit = 'Pin unavailable';
            } elseif ((int)$pair['distance_meters'] >= 1000) {
                $distanceValue = number_format((int)$pair['distance_meters'] / 1000, 1) . ' km';
                $distanceUnit = 'apart · ' . (
                    $pair['shared_verified_landmark_name'] !== ''
                        ? 'both near ' . $pair['shared_verified_landmark_name']
                        : 'within ' . $barangayName
                );
            } else {
                $distanceValue = (string)(int)$pair['distance_meters'] . ' m';
                $distanceUnit = (int)$pair['distance_meters'] <= 10 ? 'pins nearly overlap · ' : 'apart · ';
                $distanceUnit .= $pair['shared_verified_landmark_name'] !== ''
                    ? 'both near ' . $pair['shared_verified_landmark_name']
                    : 'within ' . $barangayName;
            }
            $comparisonFields = [
                ['Type', $mainDetail['type'], $similarDetail['type']],
                ['Breed', $mainDetail['animal_breed'], $similarDetail['animal_breed']],
                ['Barangay', $mainDetail['barangay'], $similarDetail['barangay']],
                ['Count', $mainDetail['animal_count'], $similarDetail['animal_count']],
                ['Animal', $mainDetail['animal_type'], $similarDetail['animal_type']],
                ['Behavior', $mainDetail['animal_behavior'], $similarDetail['animal_behavior']],
                ['Landmark', $mainDetail['landmark'], $similarDetail['landmark']],
                ['Status', $mainDetail['status'], $similarDetail['status']],
                ['Assigned staff', $mainDetail['assigned_staff'], $similarDetail['assigned_staff']],
                [
                    'Resolved at',
                    $mainDetail['resolved_at'] ? $formatDetailDate($mainDetail['resolved_at']) : '',
                    $similarDetail['resolved_at'] ? $formatDetailDate($similarDetail['resolved_at']) : '',
                ],
            ];
            $matchingFields = [];
            foreach ($comparisonFields as $comparisonField) {
                if ($compareResult($comparisonField[1], $comparisonField[2])[1] === 'match') {
                    $matchingFields[] = [$comparisonField[0], trim((string)$comparisonField[1])];
                }
            }
            $matchedFieldCount = count($matchingFields);
            $submissionOrder = strtotime((string)$pair['created_at']) >= strtotime((string)$pair['parent_created_at'])
                ? 'later'
                : 'earlier';
          ?>
          <article
            class="duplicate-comparison"
            data-comparison-id="<?php echo $childId; ?>"
            data-main-report-id="<?php echo $parentId; ?>"
            data-similar-report-id="<?php echo $childId; ?>"
            data-main-lat="<?php echo $escape($mainCoordinatesAvailable ? (string)$pair['parent_lat'] : ''); ?>"
            data-main-lng="<?php echo $escape($mainCoordinatesAvailable ? (string)$pair['parent_lng'] : ''); ?>"
            data-similar-lat="<?php echo $escape($similarCoordinatesAvailable ? (string)$pair['coordinates_lat'] : ''); ?>"
            data-similar-lng="<?php echo $escape($similarCoordinatesAvailable ? (string)$pair['coordinates_long'] : ''); ?>"
            data-main-barangay-id="<?php echo (int)$pair['parent_barangay_id']; ?>"
            data-similar-barangay-id="<?php echo (int)$pair['barangay_id']; ?>"
            data-main-barangay-name="<?php echo $escape($pair['parent_barangay_name'] ?? ''); ?>"
            data-similar-barangay-name="<?php echo $escape($pair['child_barangay_name'] ?? ''); ?>"
            <?php echo $index === 0 ? '' : 'hidden'; ?>
          >
            <header class="duplicate-comparison-head">
              <div>
                  <h2>Possible Duplicate Reports</h2>
                  <p class="duplicate-distance-meta">These reports may refer to the same incident. Review and compare their details before deciding whether to link them as one incident or treat them as separate reports.</p>
              </div>
            </header>
              <div class="duplicate-pair-insights" aria-label="Report similarity details">
                <div class="duplicate-insight duplicate-distance-insight"><strong><?php echo $escape($distanceValue); ?></strong><span><?php echo $escape($distanceUnit); ?></span></div>
                <div class="duplicate-insight"><strong><?php echo $escape($elapsedLabel); ?></strong><span>between submissions</span></div>
                <?php if ($sameResident): ?>
                  <div class="duplicate-insight same-resident"><strong>Same resident</strong><span>Both reports came from one account.</span></div>
                <?php endif; ?>
              </div>
              <div class="duplicate-section-caption">
                <h3>Compare the two reports</h3>
                <span>Highlighted words differ between descriptions.</span>
              </div>
              <div class="duplicate-report-cards">
                <?php foreach ([
                    ['detail' => $mainDetail, 'other' => $similarDetail, 'kind' => 'main', 'title' => 'Main report', 'state' => 'In Reports table'],
                    ['detail' => $similarDetail, 'other' => $mainDetail, 'kind' => 'similar', 'title' => 'Similar report', 'state' => 'On hold'],
                ] as $reportCard): ?>
                  <?php
                    $detail = $reportCard['detail'];
                    $other = $reportCard['other'];
                    $reporterMatch = $sameResident;
                    $descriptionDiffers = $compareResult($detail['description'], $other['description'])[1] === 'differs';
                  ?>
                  <section class="duplicate-report-card" aria-label="<?php echo $escape($reportCard['title']); ?>">
                    <div class="duplicate-card-media">
                      <span class="duplicate-card-status <?php echo $escape($reportCard['kind']); ?>">
                        <?php echo $escape($reportCard['title'] . ' · ' . $reportCard['state']); ?>
                      </span>
                      <?php if ($detail['photo_urls'] !== []): ?>
                        <div class="duplicate-card-photos" data-photo-count="<?php echo count($detail['photo_urls']); ?>">
                          <?php foreach ($detail['photo_urls'] as $photoIndex => $photoUrl): ?>
                            <div class="duplicate-card-photo">
                              <img src="<?php echo $escape($photoUrl); ?>" alt="<?php echo $escape($reportCard['title']); ?> photo <?php echo (int)$photoIndex + 1; ?>" loading="lazy">
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <div class="duplicate-card-photo-empty">Photo from <?php echo $escape(strtolower($reportCard['title'])); ?></div>
                      <?php endif; ?>
                    </div>
                    <dl class="duplicate-summary-fields">
                      <div class="duplicate-summary-row">
                        <dt>Report ID</dt>
                        <dd><?php echo $escape($detail['reference']); ?></dd>
                      </div>
                      <div class="duplicate-summary-row is-different">
                        <dt>Submitted</dt>
                        <dd>
                          <?php echo $escape($formatDetailDate($detail['created_at'])); ?>
                          <span class="duplicate-field-note"><?php echo $reportCard['kind'] === 'main' ? 'First report' : $escape($elapsedLabel . ' ' . $submissionOrder); ?></span>
                        </dd>
                      </div>
                      <div class="duplicate-summary-row <?php echo $reporterMatch ? 'is-match' : ''; ?>">
                        <dt>Reporter</dt>
                        <dd><?php echo $escape($detail['reporter'] ?: 'Not provided'); ?><?php if ($reporterMatch): ?><span class="duplicate-match-chip">Same resident</span><?php endif; ?></dd>
                      </div>
                      <div class="duplicate-summary-row duplicate-description-row <?php echo $descriptionDiffers ? 'is-different' : ''; ?>">
                        <dt>Details</dt>
                        <dd><?php echo $highlightDifferences($detail['description'], $other['description']); ?></dd>
                      </div>
                    </dl>
                  </section>
                <?php endforeach; ?>
              </div>
              <div class="duplicate-fields-toggle-row">
                <span><?php echo $matchedFieldCount > 0
                    ? '✓ ' . $matchedFieldCount . ' fields match: ' . implode(', ', array_map('strtolower', array_column($matchingFields, 0)))
                    : 'No matching fields'; ?></span>
                <?php if ($matchedFieldCount > 0): ?>
                  <button class="duplicate-show-fields" type="button" aria-expanded="false" aria-controls="duplicateMatchingFields-<?php echo $childId; ?>">Show all fields</button>
                <?php endif; ?>
              </div>
              <?php if ($matchedFieldCount > 0): ?>
                <dl class="duplicate-matching-fields" id="duplicateMatchingFields-<?php echo $childId; ?>" hidden>
                  <?php foreach ($matchingFields as [$fieldLabel, $fieldValue]): ?>
                    <div class="duplicate-matching-field">
                      <dt><?php echo $escape($fieldLabel); ?></dt>
                      <dd><?php echo $escape($fieldValue); ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              <?php endif; ?>
            <section class="duplicate-map-section" aria-label="Report incident locations">
              <h3 class="duplicate-map-heading">Map pins</h3>
              <div class="duplicate-map-legend" aria-hidden="true">
                <span class="duplicate-map-legend-item" data-map-legend="main"><span class="duplicate-map-legend-dot main"></span>Main report</span>
                <span class="duplicate-map-legend-item" data-map-legend="similar"><span class="duplicate-map-legend-dot similar"></span>Similar report</span>
              </div>
              <div class="duplicate-map-slot">
                <?php if ($index === 0): ?>
                  <div
                    id="similarReportMap"
                    class="duplicate-geo-map"
                    data-boundary-url="<?php echo $escape($appBaseUrl . '/geo-map/barangays-public.geojson.php'); ?>"
                    data-report-details-url="<?php echo $escape($appBaseUrl . '/geo-map/report-pins.php'); ?>"
                    data-report-directions-url="<?php echo $escape($appBaseUrl . '/geo-map/report-directions.php'); ?>"
                    data-report-session="<?php echo $escape($sessionArg); ?>"
                    data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":false,"style":{"version":8,"glyphs":"https://tiles.basemaps.cartocdn.com/v/20261026-01/fonts/{fontstack}/{range}.pbf","sources":{"esri-world-imagery":{"type":"raster","tiles":["https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"],"tileSize":256,"attribution":"Esri, Maxar, Earthstar Geographics, and the GIS User Community"}},"layers":[{"id":"esri-world-imagery","type":"raster","source":"esri-world-imagery"}]}}'
                    role="region"
                    aria-label="Map showing the main and similar report incident pins"
                  ></div>
                <?php endif; ?>
                <div class="duplicate-map-unavailable" data-duplicate-map-unavailable hidden>
                  Neither report has a valid map location.
                </div>
                <div class="duplicate-map-unavailable" data-duplicate-map-error role="status" hidden>
                  The report barangay boundary could not be loaded.
                </div>
              </div>
            </section>

            <form class="duplicate-review-form" method="post" action="report-duplicate-action.php?portal=<?php echo rawurlencode($requestedPortal); ?>">
              <input type="hidden" name="portal" value="<?php echo $escape($requestedPortal); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf); ?>">
              <input type="hidden" name="report_id" value="<?php echo $childId; ?>">
              <input type="hidden" name="parent_report_id" value="<?php echo $parentId; ?>">
              <p class="duplicate-action-explanation"><strong>Separate:</strong> the report appears in the Reports table as its own incident. <strong>Link:</strong> it becomes supporting information under the main report and is counted as one incident.</p>
              <div class="duplicate-action-buttons">
                <button type="submit" name="action" value="not_duplicate" data-action="not_duplicate">Mark as Separate Report</button>
                <button type="submit" name="action" value="merge" data-action="merge">Link as One Incident</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      </section>
    </div>
  </div>
</div>
</main>
<dialog class="duplicate-confirm-dialog" id="duplicateConfirmDialog" role="alertdialog" aria-modal="true" aria-labelledby="duplicateConfirmTitle" aria-describedby="duplicateConfirmDescription">
  <h2 id="duplicateConfirmTitle"></h2>
  <p id="duplicateConfirmDescription"></p>
  <div class="duplicate-confirm-actions">
    <button type="button" data-confirm-cancel>Cancel</button>
    <button type="button" data-confirm-action>Confirm</button>
  </div>
</dialog>
<script>
  (function () {
    var confirmationDialog = document.getElementById('duplicateConfirmDialog');
    var confirmationTitle = document.getElementById('duplicateConfirmTitle');
    var confirmationDescription = document.getElementById('duplicateConfirmDescription');
    var confirmationCancel = document.querySelector('[data-confirm-cancel]');
    var confirmationAction = document.querySelector('[data-confirm-action]');
    var pendingForm = null;
    var pendingSubmitter = null;

    function clearPendingConfirmation() {
      pendingForm = null;
      pendingSubmitter = null;
    }

    function closeConfirmationDialog() {
      clearPendingConfirmation();
      if (confirmationDialog && typeof confirmationDialog.close === 'function') {
        confirmationDialog.close();
      } else if (confirmationDialog) {
        confirmationDialog.removeAttribute('open');
      }
    }

    if (confirmationDialog && confirmationTitle && confirmationDescription && confirmationAction) {
      document.querySelectorAll('.duplicate-review-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
          if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
          }
          event.preventDefault();
          pendingForm = form;
          pendingSubmitter = event.submitter || form.querySelector('button[type="submit"]');
          var action = pendingSubmitter
            ? (pendingSubmitter.getAttribute('data-action') || pendingSubmitter.value)
            : '';
          var copy = {
            link: {
              title: 'Link these reports?',
              description: 'They will remain separate cases while being marked as related incidents.',
              confirm: 'Link reports'
            },
            merge: {
              title: 'Link as One Incident?',
              description: 'Both original submissions will be kept for traceability. The linked report will follow the primary report and will not appear as a separate case.',
              confirm: 'Link as One Incident'
            },
            not_duplicate: {
              title: 'Mark as Separate Report?',
              description: 'This submission will be approved as an independent incident and will appear in the report lists.',
              confirm: 'Mark as Separate Report'
            }
          }[action] || {
            title: 'Confirm this review action?',
            description: 'Please confirm that you want to apply this report review action.',
            confirm: 'Confirm'
          };
          confirmationTitle.textContent = copy.title;
          confirmationDescription.textContent = copy.description;
          confirmationAction.textContent = copy.confirm;
          confirmationAction.setAttribute('data-variant', action === 'not_duplicate' ? 'destructive' : 'default');
          if (typeof confirmationDialog.showModal === 'function') {
            confirmationDialog.showModal();
          } else {
            confirmationDialog.setAttribute('open', 'open');
          }
        });
      });

      confirmationCancel.addEventListener('click', closeConfirmationDialog);
      confirmationDialog.addEventListener('cancel', clearPendingConfirmation);
      confirmationDialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeConfirmationDialog();
        }
      });
      confirmationDialog.addEventListener('click', function (event) {
        if (event.target === confirmationDialog) closeConfirmationDialog();
      });
      confirmationAction.addEventListener('click', function () {
        var form = pendingForm;
        var submitter = pendingSubmitter;
        clearPendingConfirmation();
        if (typeof confirmationDialog.close === 'function') {
          confirmationDialog.close();
        } else {
          confirmationDialog.removeAttribute('open');
        }
        if (!form) return;

        form.dataset.confirmed = 'true';
        if (typeof form.requestSubmit === 'function') {
          if (submitter && submitter.form === form) form.requestSubmit(submitter);
          else form.requestSubmit();
          return;
        }
        if (submitter && submitter.name) {
          var actionInput = document.createElement('input');
          actionInput.type = 'hidden';
          actionInput.name = submitter.name;
          actionInput.value = submitter.value;
          form.appendChild(actionInput);
        }
        form.submit();
      });
    }

    var pairRows = Array.prototype.slice.call(document.querySelectorAll('.duplicate-queue-item'));
    var comparisons = Array.prototype.slice.call(document.querySelectorAll('.duplicate-comparison'));
    var mapElement = document.getElementById('similarReportMap');
    var mapInstance = null;
    var search = document.getElementById('duplicateSearch');
    var barangay = document.getElementById('duplicateBarangay');
    var type = document.getElementById('duplicateType');
    var emptyFilter = document.querySelector('[data-duplicate-empty]');
    var selectedId = '';
    var barangayBoundaryPromise = null;
    var barangayBoundaryUrl = mapElement ? mapElement.getAttribute('data-boundary-url') : '';
    var reportSession = mapElement ? mapElement.getAttribute('data-report-session') : '';
    var reportPinDrawer = null;
    var reportPinInteractionsRegistered = false;

    document.querySelectorAll('.duplicate-show-fields').forEach(function (button) {
      button.addEventListener('click', function () {
        var fields = document.getElementById(button.getAttribute('aria-controls'));
        if (!fields) return;
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        button.textContent = expanded ? 'Show all fields' : 'Hide matching fields';
        fields.hidden = expanded;
      });
    });

    function reportPinAppendInformationRow(parent, label, value) {
      var row = document.createElement('div');
      row.className = 'dcvs-report-pin-info-row';
      var content = document.createElement('div');
      content.className = 'dcvs-report-pin-info-content';
      var heading = document.createElement('span');
      heading.className = 'dcvs-report-pin-info-label';
      heading.textContent = label;
      var text = document.createElement('span');
      text.className = 'dcvs-report-pin-info-value';
      text.textContent = value === undefined || value === null || value === '' ? '—' : String(value);
      content.appendChild(heading);
      content.appendChild(text);
      row.appendChild(content);
      parent.appendChild(row);
    }

    function reportPinAddSection(parent, title, extraClass) {
      var section = document.createElement('section');
      section.className = 'dcvs-report-pin-drawer-section' + (extraClass ? ' ' + extraClass : '');
      var heading = document.createElement('h3');
      heading.textContent = title;
      section.appendChild(heading);
      parent.appendChild(section);
      return section;
    }

    function reportPinFormatDate(value) {
      var normalized = String(value || '').trim().replace(' ', 'T');
      var date = normalized ? new Date(normalized) : null;
      return date && !isNaN(date.getTime())
        ? new Intl.DateTimeFormat('en-US', {
          month: 'long',
          day: 'numeric',
          year: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
          timeZone: 'Asia/Manila'
        }).format(date)
        : (value || '—');
    }

    function reportPinStatusLabel(properties) {
      var stages = {
        barangay_response_approved: 'Accepted by Barangay',
        barangay_responding: 'Barangay responding',
        animal_secured: 'Animal secured',
        awaiting_turnover: 'Resolved · turnover pending',
        resolved: properties.turnover_status === 'receipt_confirmed' ? 'Turnover completed' : 'Resolved',
        turned_over: 'Turnover completed',
        escalated_to_dcvs: 'Escalated to DCVS',
        unable_to_locate: 'Unable to locate',
        returned_to_barangay: 'Returned to Barangay',
        dcvs_dispatch: 'DCVS dispatch',
        dcvs_processing: 'DCVS processing',
        closed_not_actionable: 'Closed'
      };
      var statusLabels = {
        pending: 'Pending Barangay review',
        validated: 'Pending DCVS review',
        confirmed: 'In progress',
        scheduled: 'In progress',
        resolved: 'Resolved',
        rejected: 'Rejected',
        active: 'Active'
      };
      return stages[properties.response_state] || statusLabels[properties.status] || properties.status || '—';
    }

    function reportPinLatestUpdate(properties) {
      var actionLabels = {
        accept_barangay_assignment: 'accepted the Barangay assignment',
        start_response: 'started responding to the report',
        update_progress: 'updated the response progress',
        mark_secured: 'secured the animal',
        resolve_barangay: 'marked the incident as resolved',
        confirm_turnover: 'completed the animal turnover',
        unable_to_secure: 'escalated the case to DCVS',
        unable_to_locate: 'reported that the animal could not be located',
        staff_decide: 'dispatched the DCVS team'
      };
      if (!properties.latest_response_action) return 'No response updates have been recorded yet.';
      var action = actionLabels[properties.latest_response_action] || properties.latest_response_action.replace(/_/g, ' ');
      return (properties.latest_response_actor || 'The response team') + ' ' + action
        + (properties.latest_response_at ? ' on ' + reportPinFormatDate(properties.latest_response_at) : '')
        + (properties.latest_response_reason ? ' and noted: ' + properties.latest_response_reason : '') + '.';
    }

    function reportPinCreateDrawer() {
      var drawer = document.createElement('section');
      drawer.className = 'dcvs-report-pin-drawer';
      drawer.setAttribute('role', 'dialog');
      drawer.setAttribute('aria-label', 'Report pin details');

      var hero = document.createElement('div');
      hero.className = 'dcvs-report-pin-hero';
      var preview = document.createElement('div');
      preview.className = 'dcvs-report-pin-hero-preview';
      var placeholder = document.createElement('div');
      placeholder.className = 'dcvs-report-pin-hero-placeholder';
      placeholder.textContent = 'Loading report details…';
      preview.appendChild(placeholder);
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'dcvs-report-pin-drawer-close';
      close.setAttribute('aria-label', 'Close report pin details');
      close.textContent = '×';
      close.addEventListener('click', function() {
        if (drawer.parentNode) drawer.remove();
        if (reportPinDrawer === drawer) reportPinDrawer = null;
      });
      hero.appendChild(preview);
      hero.appendChild(close);
      drawer.appendChild(hero);

      var header = document.createElement('div');
      header.className = 'dcvs-report-pin-drawer-header';
      var titleRow = document.createElement('div');
      titleRow.className = 'dcvs-report-pin-drawer-title-row';
      var title = document.createElement('h2');
      title.textContent = 'Report details';
      var badge = document.createElement('span');
      badge.className = 'dcvs-report-pin-status-badge';
      badge.textContent = 'Loading…';
      titleRow.appendChild(title);
      titleRow.appendChild(badge);
      header.appendChild(titleRow);
      drawer.appendChild(header);

      var body = document.createElement('div');
      body.className = 'dcvs-report-pin-drawer-body';
      var loading = document.createElement('div');
      loading.className = 'dcvs-report-pin-info-value';
      loading.textContent = 'Loading report information…';
      body.appendChild(loading);
      drawer.appendChild(body);
      drawer.addEventListener('click', function(event) { event.stopPropagation(); });
      return { drawer: drawer, preview: preview, title: title, badge: badge, body: body };
    }

    function reportPinSafeMediaUrl(value) {
      var url = String(value || '').trim();
      if (!url || url.indexOf('//') === 0 || /^(?:javascript|data|vbscript):/i.test(url)) return '';
      try {
        var parsedUrl = new URL(url, window.location.href);
        return parsedUrl.origin === window.location.origin ? url : '';
      } catch (error) {
        return '';
      }
    }

    function reportPinRenderDetails(view, properties, reportId) {
      var typeLabels = {
        stray: 'Stray Animal',
        rabies: 'Rabies Case',
        injured: 'Injured Animal',
        dead: 'Dead Animal'
      };
      view.title.textContent = typeLabels[String(properties.type || '').toLowerCase()] || properties.type || 'Report';
      view.badge.className = 'dcvs-report-pin-status-badge dcvs-report-pin-status-' +
        String(properties.status || 'unknown').toLowerCase().replace(/[^a-z0-9-]/g, '');
      view.badge.textContent = reportPinStatusLabel(properties);

      var attachments = [];
      [properties.attachments, properties.barangay_evidence].forEach(function(group) {
        if (!Array.isArray(group)) return;
        group.forEach(function(item) {
          var url = reportPinSafeMediaUrl(item && item.url);
          if (url && (item.type === 'photo' || item.type === 'video')) {
            attachments.push({ type: item.type, url: url });
          }
        });
      });
      if (!attachments.length) {
        var photo = reportPinSafeMediaUrl(properties.photo);
        var video = reportPinSafeMediaUrl(properties.video);
        if (photo) attachments.push({ type: 'photo', url: photo });
        else if (video) attachments.push({ type: 'video', url: video });
      }
      view.preview.textContent = '';
      if (attachments.length) {
        var media = document.createElement(attachments[0].type === 'photo' ? 'img' : 'video');
        media.src = attachments[0].url;
        if (attachments[0].type === 'photo') {
          media.alt = 'Report photo';
          media.loading = 'eager';
        } else {
          media.controls = true;
          media.playsInline = true;
          media.preload = 'metadata';
        }
        media.addEventListener('error', function() {
          var unavailable = document.createElement('div');
          unavailable.className = 'dcvs-report-pin-hero-placeholder';
          unavailable.textContent = attachments[0].type === 'photo' ? 'Photo unavailable' : 'Video unavailable';
          view.preview.textContent = '';
          view.preview.appendChild(unavailable);
        });
        view.preview.appendChild(media);
      } else {
        var noMedia = document.createElement('div');
        noMedia.className = 'dcvs-report-pin-hero-placeholder';
        noMedia.textContent = 'Photo unavailable';
        view.preview.appendChild(noMedia);
      }

      view.body.textContent = '';
      var location = reportPinAddSection(view.body, 'Location');
      reportPinAppendInformationRow(location, 'Barangay', properties.barangay);
      reportPinAppendInformationRow(location, 'Exact Location', properties.location);
      var directions = document.createElement('a');
      directions.className = 'dcvs-report-pin-directions';
      directions.textContent = 'Preparing Google Maps directions…';
      directions.setAttribute('aria-disabled', 'true');
      directions.target = '_blank';
      directions.rel = 'noopener noreferrer';
      directions.setAttribute('data-directions-report-id', String(reportId));
      location.appendChild(directions);

      var incident = reportPinAddSection(view.body, 'Incident information');
      reportPinAppendInformationRow(incident, 'Incident Date', reportPinFormatDate(properties.incident_at));
      reportPinAppendInformationRow(incident, 'Priority', properties.priority);
      reportPinAppendInformationRow(incident, 'Animal Type', properties.animal_type);
      reportPinAppendInformationRow(incident, 'Estimated Number', properties.animal_count);
      reportPinAppendInformationRow(incident, 'Behavior', properties.animal_behavior);
      reportPinAppendInformationRow(incident, 'Description', properties.description);

      var response = reportPinAddSection(view.body, 'Live case monitoring', 'dcvs-report-pin-live-section');
      var responseTeam = properties.response_mode === 'barangay_response'
        ? 'Brgy. ' + properties.barangay + ' (assigned)'
        : (properties.status === 'pending' || properties.status === 'validated' ? 'Awaiting assignment' : 'DCVS review');
      var deadline = properties.response_deadline || properties.progress_deadline
        || (properties.turnover_status === 'pending' ? properties.turnover_due_at : '');
      reportPinAppendInformationRow(response, 'Response stage', reportPinStatusLabel(properties));
      reportPinAppendInformationRow(response, 'Responsible team', responseTeam);
      reportPinAppendInformationRow(response, 'Next target', deadline ? reportPinFormatDate(deadline) : 'No deadline');
      reportPinAppendInformationRow(response, 'Latest update', reportPinLatestUpdate(properties));

      var directionsBaseUrl = mapElement.getAttribute('data-report-directions-url');
      var directionsUrl = new URL(directionsBaseUrl, window.location.href);
      directionsUrl.searchParams.set('sess', reportSession);
      directionsUrl.searchParams.set('report_id', String(reportId));
      fetch(directionsUrl.href, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function(httpResponse) {
        return httpResponse.json().then(function(data) {
          if (!httpResponse.ok) throw new Error(data.error || 'Could not prepare directions.');
          return data;
        });
      }).then(function(data) {
        if (!directions.isConnected) return;
        var target = new URL(data.directions_url);
        if (target.protocol !== 'https:' || target.hostname !== 'www.google.com' || target.pathname !== '/maps/dir/') {
          throw new Error('Google Maps returned an untrusted directions link.');
        }
        directions.href = target.href;
        directions.textContent = 'Get Directions in Google Maps';
        directions.setAttribute('aria-disabled', 'false');
      }).catch(function(error) {
        if (!directions.isConnected) return;
        console.error('Could not prepare Similar Reports directions:', error);
        directions.textContent = 'Directions unavailable';
        directions.title = error.message || 'Could not prepare directions.';
      });
    }

    function openReportPinDetails(role) {
      var article = comparisons.find(function(item) {
        return item.getAttribute('data-comparison-id') === selectedId;
      });
      if (!article || !mapElement) return;
      var reportId = Number(article.getAttribute('data-' + role + '-report-id'));
      if (!Number.isSafeInteger(reportId) || reportId <= 0) return;

      if (reportPinDrawer && reportPinDrawer.parentNode) reportPinDrawer.remove();
      var view = reportPinCreateDrawer();
      reportPinDrawer = view.drawer;
      var parent = window.matchMedia('(max-width: 540px)').matches ? document.body : mapElement;
      parent.appendChild(view.drawer);
      view.drawer.querySelector('.dcvs-report-pin-drawer-close').focus();

      var detailsUrl = new URL(mapElement.getAttribute('data-report-details-url'), window.location.href);
      detailsUrl.searchParams.set('sess', reportSession);
      detailsUrl.searchParams.set('report_id', String(reportId));
      fetch(detailsUrl.href, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/geo+json' }
      }).then(function(response) {
        return response.json().then(function(data) {
          if (!response.ok) throw new Error(data.error || 'Could not load report details.');
          return data;
        });
      }).then(function(geojson) {
        if (!geojson || !Array.isArray(geojson.features)) {
          throw new Error('The report details response is invalid.');
        }
        var feature = geojson.features.find(function(item) {
          return Number(item.properties && item.properties.report_id) === reportId;
        });
        if (!feature) throw new Error('No report details were returned for this map pin.');
        if (reportPinDrawer === view.drawer && view.drawer.isConnected) {
          var properties = feature.properties || {};
          var photoCells = article.querySelectorAll('.duplicate-photo-cell');
          var photoCell = photoCells[role === 'main' ? 0 : 1];
          var photo = photoCell && photoCell.querySelector('img');
          if (photo) {
            properties = Object.assign({}, properties, {
              attachments: [{ type: 'photo', url: photo.src }]
            });
          }
          reportPinRenderDetails(view, properties, reportId);
        }
      }).catch(function(error) {
        console.error('Could not load Similar Reports pin details:', error);
        if (reportPinDrawer === view.drawer && view.drawer.isConnected) {
          view.preview.textContent = '';
          var unavailable = document.createElement('div');
          unavailable.className = 'dcvs-report-pin-hero-placeholder';
          unavailable.textContent = 'Report details unavailable';
          view.preview.appendChild(unavailable);
          view.body.textContent = 'Could not load report details. Please try again.';
        }
      });
    }

    function placeReportPinDrawer() {
      if (!reportPinDrawer || !reportPinDrawer.isConnected || !mapElement) return;
      var parent = window.matchMedia('(max-width: 540px)').matches ? document.body : mapElement;
      if (reportPinDrawer.parentNode !== parent) parent.appendChild(reportPinDrawer);
    }

    function fetchBarangayBoundaries() {
      if (!barangayBoundaryPromise) {
        barangayBoundaryPromise = fetch(barangayBoundaryUrl, { credentials: 'same-origin' })
          .then(function (response) {
            if (!response.ok) throw new Error('Barangay boundary request failed with status ' + response.status + '.');
            return response.json();
          })
          .then(function (geojson) {
            if (!geojson || !Array.isArray(geojson.features)) {
              throw new Error('Barangay boundary response did not contain features.');
            }
            return geojson.features;
          })
          .catch(function (error) {
            barangayBoundaryPromise = null;
            throw error;
          });
      }
      return barangayBoundaryPromise;
    }

    function loadSelectedBarangays(article) {
      if (!article || !mapInstance || !barangayBoundaryUrl) return;
      var mapSlot = article.querySelector('.duplicate-map-slot');
      var boundaryError = mapSlot && mapSlot.querySelector('[data-duplicate-map-error]');
      var selectedIds = [
        Number(article.getAttribute('data-main-barangay-id')),
        Number(article.getAttribute('data-similar-barangay-id'))
      ].filter(function (id, index, ids) {
        return id > 0 && ids.indexOf(id) === index;
      });
      var selectedNames = [
        article.getAttribute('data-main-barangay-name'),
        article.getAttribute('data-similar-barangay-name')
      ].map(function (name) {
        return (name || '').trim().toLocaleLowerCase();
      }).filter(function (name, index, names) {
        return name !== '' && names.indexOf(name) === index;
      });

      if (selectedIds.length === 0 && selectedNames.length === 0) {
        if (boundaryError) {
          boundaryError.textContent = 'Barangay boundary is unavailable for these reports.';
          boundaryError.hidden = false;
        }
        return;
      }

      fetchBarangayBoundaries().then(function (features) {
        var selectedFeatures = features.filter(function (feature) {
          var properties = feature && feature.properties ? feature.properties : {};
          return selectedIds.indexOf(Number(properties.id)) !== -1
            || selectedNames.indexOf((properties.name || '').trim().toLocaleLowerCase()) !== -1;
        });
        if (selectedFeatures.length === 0) throw new Error('No barangay boundary matched the selected reports.');

        var sourceData = { type: 'FeatureCollection', features: selectedFeatures };
        var source = mapInstance.getSource('similar-report-selected-barangays');
        if (source) {
          source.setData(sourceData);
        } else {
          mapInstance.addSource('similar-report-selected-barangays', { type: 'geojson', data: sourceData });
        }

        var beforePin = mapInstance.getLayer('similar-report-main-pin') ? 'similar-report-main-pin' : undefined;
        if (!mapInstance.getLayer('similar-report-selected-barangay-fill')) {
          var fillLayer = {
            id: 'similar-report-selected-barangay-fill',
            type: 'fill',
            source: 'similar-report-selected-barangays',
            paint: {
              'fill-color': '#22c55e',
              'fill-opacity': 0.2
            }
          };
          if (beforePin) mapInstance.addLayer(fillLayer, beforePin);
          else mapInstance.addLayer(fillLayer);
        }
        if (!mapInstance.getLayer('similar-report-selected-barangay-outline')) {
          var outlineLayer = {
            id: 'similar-report-selected-barangay-outline',
            type: 'line',
            source: 'similar-report-selected-barangays',
            paint: {
              'line-color': '#ffffff',
              'line-width': 1.5,
              'line-opacity': 1
            }
          };
          if (beforePin) mapInstance.addLayer(outlineLayer, beforePin);
          else mapInstance.addLayer(outlineLayer);
        }
        if (!mapInstance.getLayer('similar-report-selected-barangay-label')) {
          var labelLayer = {
            id: 'similar-report-selected-barangay-label',
            type: 'symbol',
            source: 'similar-report-selected-barangays',
            layout: {
              'text-field': ['get', 'name'],
              'text-size': 11,
              'text-anchor': 'center',
              'text-allow-overlap': false
            },
            paint: {
              'text-color': '#1e293b',
              'text-halo-color': '#ffffff',
              'text-halo-width': 2
            }
          };
          if (beforePin) mapInstance.addLayer(labelLayer, beforePin);
          else mapInstance.addLayer(labelLayer);
        }

        if (boundaryError) boundaryError.hidden = true;
        var bounds = [Infinity, Infinity, -Infinity, -Infinity];
        function includePositions(coordinates) {
          if (!Array.isArray(coordinates)) return;
          if (coordinates.length >= 2 && typeof coordinates[0] === 'number' && typeof coordinates[1] === 'number') {
            bounds[0] = Math.min(bounds[0], coordinates[0]);
            bounds[1] = Math.min(bounds[1], coordinates[1]);
            bounds[2] = Math.max(bounds[2], coordinates[0]);
            bounds[3] = Math.max(bounds[3], coordinates[1]);
            return;
          }
          coordinates.forEach(includePositions);
        }
        selectedFeatures.forEach(function (feature) {
          if (feature.geometry) includePositions(feature.geometry.coordinates);
        });
        if (Number.isFinite(bounds[0]) && Number.isFinite(bounds[1])
            && Number.isFinite(bounds[2]) && Number.isFinite(bounds[3])) {
          mapInstance.fitBounds([[bounds[0], bounds[1]], [bounds[2], bounds[3]]], {
            padding: 42,
            maxZoom: 15,
            duration: 0
          });
        }
      }).catch(function (error) {
        console.error('Could not highlight the report barangay on the Similar Reports map.', error);
        if (boundaryError) {
          boundaryError.textContent = 'The report barangay boundary could not be loaded.';
          boundaryError.hidden = false;
        }
      });
    }

    function coordinateFrom(article, prefix) {
      var latitudeValue = article.getAttribute('data-' + prefix + '-lat');
      var longitudeValue = article.getAttribute('data-' + prefix + '-lng');
      if (!latitudeValue || !longitudeValue) return null;
      var latitude = Number(latitudeValue);
      var longitude = Number(longitudeValue);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
          || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) return null;
      return { latitude: latitude, longitude: longitude };
    }

    function updateMapForComparison(article) {
      if (!mapElement) return;
      if (!article) {
        if (reportPinDrawer && reportPinDrawer.parentNode) reportPinDrawer.remove();
        reportPinDrawer = null;
        mapElement.hidden = true;
        return;
      }
      if (reportPinDrawer && reportPinDrawer.parentNode) reportPinDrawer.remove();
      reportPinDrawer = null;
      var mapSlot = article.querySelector('.duplicate-map-slot');
      var unavailable = mapSlot && mapSlot.querySelector('[data-duplicate-map-unavailable]');
      var boundaryError = mapSlot && mapSlot.querySelector('[data-duplicate-map-error]');
      if (!mapSlot) return;
      if (mapElement.parentNode !== mapSlot) mapSlot.insertBefore(mapElement, unavailable || null);

      var mainPoint = coordinateFrom(article, 'main');
      var similarPoint = coordinateFrom(article, 'similar');
      var mainLegend = article.querySelector('[data-map-legend="main"]');
      var similarLegend = article.querySelector('[data-map-legend="similar"]');
      if (mainLegend) mainLegend.hidden = !mainPoint;
      if (similarLegend) similarLegend.hidden = !similarPoint;
      var points = [];
      if (mainPoint) points.push({ role: 'main', point: mainPoint });
      if (similarPoint) points.push({ role: 'similar', point: similarPoint });
      mapElement.hidden = points.length === 0;
      if (unavailable) unavailable.hidden = points.length > 0;
      if (boundaryError) boundaryError.hidden = true;
      if (!mapInstance || points.length === 0) {
        return;
      }

      window.requestAnimationFrame(function () {
        mapInstance.resize();
        var features = points.map(function (entry) {
          return {
            type: 'Feature',
            properties: { role: entry.role },
            geometry: {
              type: 'Point',
              coordinates: [entry.point.longitude, entry.point.latitude]
            }
          };
        });
        var geojson = { type: 'FeatureCollection', features: features };
        var source = mapInstance.getSource('similar-report-pins');
        if (!source) {
          mapInstance.addSource('similar-report-pins', { type: 'geojson', data: geojson });
          mapInstance.addLayer({
            id: 'similar-report-main-pin',
            type: 'circle',
            source: 'similar-report-pins',
            filter: ['==', ['get', 'role'], 'main'],
            paint: {
              'circle-radius': 10,
              'circle-color': '#15803d',
              'circle-stroke-color': '#ffffff',
              'circle-stroke-width': 2,
              'circle-translate': [-7, 0]
            }
          });
          mapInstance.addLayer({
            id: 'similar-report-similar-pin',
            type: 'circle',
            source: 'similar-report-pins',
            filter: ['==', ['get', 'role'], 'similar'],
            paint: {
              'circle-radius': 10,
              'circle-color': '#7c3aed',
              'circle-stroke-color': '#ffffff',
              'circle-stroke-width': 2,
              'circle-translate': [7, 0]
            }
          });
        } else {
          source.setData(geojson);
        }
        if (!reportPinInteractionsRegistered) {
          reportPinInteractionsRegistered = true;
          [
            ['similar-report-main-pin', 'main'],
            ['similar-report-similar-pin', 'similar']
          ].forEach(function(layer) {
            mapInstance.on('click', layer[0], function(event) {
              if (!event.features || !event.features[0]) return;
              openReportPinDetails(layer[1]);
            });
            mapInstance.on('mouseenter', layer[0], function() {
              mapInstance.getCanvas().style.cursor = 'pointer';
            });
            mapInstance.on('mouseleave', layer[0], function() {
              mapInstance.getCanvas().style.cursor = '';
            });
          });
          window.addEventListener('resize', placeReportPinDrawer);
        }
        loadSelectedBarangays(article);

        if (points.length === 1
            || (points.length === 2
              && points[0].point.latitude === points[1].point.latitude
              && points[0].point.longitude === points[1].point.longitude)) {
          mapInstance.easeTo({
            center: [points[0].point.longitude, points[0].point.latitude],
            zoom: 15,
            duration: 0
          });
          return;
        }
        var longitudes = points.map(function (entry) { return entry.point.longitude; });
        var latitudes = points.map(function (entry) { return entry.point.latitude; });
        mapInstance.fitBounds([
          [Math.min.apply(Math, longitudes), Math.min.apply(Math, latitudes)],
          [Math.max.apply(Math, longitudes), Math.max.apply(Math, latitudes)]
        ], { padding: 44, maxZoom: 15, duration: 0 });
      });
    }

    if (mapElement) {
      mapElement.addEventListener('mapcn:load', function (event) {
        mapInstance = event.detail && event.detail.map;
        if (mapInstance) {
          updateMapForComparison(comparisons.find(function (comparison) {
            return comparison.getAttribute('data-comparison-id') === selectedId;
          }) || null);
        }
      }, { once: true });
    }

    function updateView() {
      var query = search ? search.value.trim().toLocaleLowerCase() : '';
      var visible = [];
      pairRows.forEach(function (row) {
        var matches = (!query || (row.getAttribute('data-search') || '').indexOf(query) !== -1)
          && (!barangay || !barangay.value || row.getAttribute('data-barangay') === barangay.value)
          && (!type || !type.value || row.getAttribute('data-type') === type.value);
        row.hidden = !matches;
        if (matches) visible.push(row);
      });

      if (!visible.some(function (row) { return row.getAttribute('data-pair-id') === selectedId; })) {
        selectedId = visible.length ? visible[0].getAttribute('data-pair-id') : '';
      }
      pairRows.forEach(function (row) {
        row.setAttribute('aria-current', row.getAttribute('data-pair-id') === selectedId && !row.hidden ? 'true' : 'false');
      });
      comparisons.forEach(function (comparison) {
        comparison.hidden = comparison.getAttribute('data-comparison-id') !== selectedId;
      });
      if (emptyFilter) emptyFilter.hidden = visible.length > 0 || pairRows.length === 0;
      var selectedComparison = comparisons.find(function (comparison) {
        return comparison.getAttribute('data-comparison-id') === selectedId;
      }) || null;
      if (selectedComparison) {
        selectedComparison.querySelectorAll('.duplicate-photo-gallery img').forEach(function (image) {
          image.loading = 'eager';
        });
      }
      updateMapForComparison(selectedComparison);
    }

    pairRows.forEach(function (row) {
      row.addEventListener('click', function () {
        selectedId = row.getAttribute('data-pair-id');
        updateView();
      });
    });
    [search, barangay, type].filter(Boolean).forEach(function (control) {
      control.addEventListener(control === search ? 'input' : 'change', updateView);
    });
    updateView();
  })();
</script>
<?php if ($portal['role'] === 'barangay'): ?>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/notification-panel.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/notification-panel.js'); ?>" defer></script>
<?php endif; ?>
</body>
</html>
<?php $query->close(); ?>
