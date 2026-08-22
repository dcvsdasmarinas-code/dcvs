<?php
/**
 * Geo-Mapping API Endpoint
 * Returns barangay data with confirmed case counts for the map.
 * Used by the MapLibre GL-based geo-mapping module.
 */

$sessionNames = ['DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION', 'DCVS_RESIDENT_SESSION', 'DCVS_ADMIN_SESSION'];
$loggedIn = false;
foreach ($sessionNames as $sn) {
    if (isset($_COOKIE[$sn])) {
        session_name($sn);
        @session_start();
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            $loggedIn = true;
            break;
        }
        @session_write_close();
    }
}

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (!$loggedIn) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$barangayId = $_SESSION['barangay_id'] ?? null;

// Build query based on role
if ($role === 'barangay' && $barangayId) {
    $barangayCond = ' AND r.barangay_id = ' . intval($barangayId);
} else {
    $barangayCond = '';
}

// 1. Total cases per barangay (active + resolved)
$confirmedQuery = "SELECT b.barangay_id, b.barangay_name, COUNT(cc.case_id) AS confirmed_count
    FROM barangays b
    LEFT JOIN confirmed_cases cc ON b.barangay_id = cc.barangay_id AND cc.status IN ('active', 'resolved')
    GROUP BY b.barangay_id
    ORDER BY b.barangay_name";
$confirmedResult = $conn->query($confirmedQuery);

$confirmedData = [];
while ($row = $confirmedResult->fetch_assoc()) {
    $confirmedData[$row['barangay_id']] = [
        'confirmed' => (int)$row['confirmed_count']
    ];
}

// 2. Pending reports per barangay
$pendingQuery = "SELECT b.barangay_id, COUNT(r.report_id) AS pending_count
    FROM barangays b
    LEFT JOIN reports r ON b.barangay_id = r.barangay_id AND r.status = 'pending' AND r.archived_at IS NULL
    GROUP BY b.barangay_id";
$pendingResult = $conn->query($pendingQuery);
while ($row = $pendingResult->fetch_assoc()) {
    $bid = $row['barangay_id'];
    if (!isset($confirmedData[$bid])) $confirmedData[$bid] = ['confirmed' => 0];
    $confirmedData[$bid]['pending'] = (int)$row['pending_count'];
}

// 3. Resolved cases per barangay
$resolvedQuery = "SELECT b.barangay_id, COUNT(cc.case_id) AS resolved_count
    FROM barangays b
    LEFT JOIN confirmed_cases cc ON b.barangay_id = cc.barangay_id AND cc.status = 'resolved'
    GROUP BY b.barangay_id";
$resolvedResult = $conn->query($resolvedQuery);
while ($row = $resolvedResult->fetch_assoc()) {
    $bid = $row['barangay_id'];
    if (!isset($confirmedData[$bid])) $confirmedData[$bid] = ['confirmed' => 0];
    $confirmedData[$bid]['resolved'] = (int)$row['resolved_count'];
}

// 4. All barangays with their names
$barangayQuery = "SELECT barangay_id, barangay_name, district FROM barangays ORDER BY barangay_name";
$barangayResult = $conn->query($barangayQuery);

$barangays = [];
while ($row = $barangayResult->fetch_assoc()) {
    $bid = $row['barangay_id'];
    $data = $confirmedData[$bid] ?? ['confirmed' => 0, 'pending' => 0, 'resolved' => 0];
    $barangays[] = [
        'id' => (int)$bid,
        'name' => $row['barangay_name'],
        'district' => $row['district'] ?? '',
        'confirmed' => $data['confirmed'] ?? 0,
        'pending' => $data['pending'] ?? 0,
        'resolved' => $data['resolved'] ?? 0,
    ];
}

// 5. Overall stats
$totalConfirmed = array_sum(array_column($barangays, 'confirmed'));
$totalPending = array_sum(array_column($barangays, 'pending'));
$totalResolved = array_sum(array_column($barangays, 'resolved'));
$affectedBarangays = count(array_filter($barangays, fn($b) => $b['confirmed'] > 0));
$highestRisk = null;
$maxConfirmed = 0;
foreach ($barangays as $b) {
    if ($b['confirmed'] > $maxConfirmed) {
        $maxConfirmed = $b['confirmed'];
        $highestRisk = $b['name'];
    }
}

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'barangays' => $barangays,
    'summary' => [
        'totalConfirmed' => $totalConfirmed,
        'totalPending' => $totalPending,
        'totalResolved' => $totalResolved,
        'affectedBarangays' => $affectedBarangays,
        'highestRiskBarangay' => $highestRisk ?? 'None',
        'lastUpdated' => date('Y-m-d H:i:s'),
        'totalBarangays' => count($barangays)
    ]
]);
