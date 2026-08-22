<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/geo+json');
header('Cache-Control: no-cache, must-revalidate');

$boundaryFile = __DIR__ . '/barangays-boundary.json';
if (!file_exists($boundaryFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Boundary data not found']);
    exit;
}

$geojson = json_decode(file_get_contents($boundaryFile), true);
if (!$geojson || !isset($geojson['features'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid boundary data']);
    exit;
}

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

$validTypes = ['stray', 'rabies', 'injured', 'dead'];
if ($type !== '' && !in_array($type, $validTypes, true)) $type = '';

$typeFilter = $type !== '' ? " AND r.report_type = '" . $conn->real_escape_string($type) . "'" : '';
$typeFilterCc = $type !== '' ? " AND cc.case_type = '" . $conn->real_escape_string($type) . "'" : '';

$confirmedExpr = "COUNT(DISTINCT CASE WHEN cc.status = 'active' THEN cc.case_id END)";
$pendingExpr = "COUNT(DISTINCT CASE WHEN r.status = 'pending' THEN r.report_id END)";
$resolvedExpr = "COUNT(DISTINCT CASE WHEN cc.status = 'resolved' THEN cc.case_id END)";

if ($status === 'active') {
    $pendingExpr = "0";
    $resolvedExpr = "0";
} elseif ($status === 'pending') {
    $confirmedExpr = "0";
    $resolvedExpr = "0";
} elseif ($status === 'resolved') {
    $confirmedExpr = "0";
    $pendingExpr = "0";
}

$caseQuery = "SELECT b.barangay_id, b.barangay_name, b.district,
    $confirmedExpr AS confirmed,
    $pendingExpr AS pending,
    $resolvedExpr AS resolved
    FROM barangays b
    LEFT JOIN confirmed_cases cc ON b.barangay_id = cc.barangay_id$typeFilterCc
    LEFT JOIN reports r ON b.barangay_id = r.barangay_id AND r.archived_at IS NULL$typeFilter
    GROUP BY b.barangay_id";

$result = $conn->query($caseQuery);
$dbData = [];
while ($row = $result->fetch_assoc()) {
    $dbData[$row['barangay_name']] = [
        'id' => (int)$row['barangay_id'],
        'confirmed' => (int)$row['confirmed'],
        'pending' => (int)$row['pending'],
        'resolved' => (int)$row['resolved'],
        'district' => $row['district'] ?? '',
    ];
}

$totalConfirmed = 0;
$affectedCount = 0;
$maxConfirmed = 0;
$highestName = '';

foreach ($geojson['features'] as &$feature) {
    $props = &$feature['properties'];
    $barangayName = $props['name'] ?? '';

    $data = $dbData[$barangayName] ?? null;
    if (!$data) {
        foreach ($dbData as $dbKey => $dbVal) {
            if (strcasecmp(trim($dbKey), $barangayName) === 0) { $data = $dbVal; break; }
        }
    }
    $data = $data ?? ['id' => 0, 'confirmed' => 0, 'pending' => 0, 'resolved' => 0, 'district' => ''];

    $confirmed = $data['confirmed'];
    if ($confirmed > 15) $risk = 'Red';
    elseif ($confirmed >= 6) $risk = 'Orange';
    elseif ($confirmed >= 1) $risk = 'Yellow';
    else $risk = 'Green';

    $props['id'] = $data['id'];
    $props['confirmed'] = $confirmed;
    $props['pending'] = $data['pending'];
    $props['resolved'] = $data['resolved'];
    $props['risk'] = $risk;
    $props['lastUpdated'] = date('Y-m-d H:i:s');

    $totalConfirmed += $confirmed;
    if ($confirmed > 0) $affectedCount++;
    if ($confirmed > $maxConfirmed) { $maxConfirmed = $confirmed; $highestName = $barangayName; }
}
unset($feature);

foreach ($geojson['features'] as &$feature) {
    $keep = ['id', 'name', 'confirmed', 'pending', 'resolved', 'risk', 'lastUpdated'];
    $feature['properties'] = array_intersect_key($feature['properties'], array_flip($keep));
}
unset($feature);

$geojson['metadata'] = [
    'totalConfirmed' => $totalConfirmed,
    'totalPending' => array_sum(array_column($dbData, 'pending')),
    'totalResolved' => array_sum(array_column($dbData, 'resolved')),
    'affectedBarangays' => $affectedCount,
    'highestRiskBarangay' => $highestName ?: 'None',
    'lastUpdated' => date('Y-m-d H:i:s'),
    'totalBarangays' => count($geojson['features']),
];

echo json_encode($geojson);
