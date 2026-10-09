<?php
/** Return map drawer details only within the authenticated user's scope. */
$sessionNames = [
    'DCVS_STAFF_SESSION' => 'staff',
    'DCVS_BARANGAY_SESSION' => 'barangay',
];
$sessionName = trim($_GET['sess'] ?? '');
if (!isset($sessionNames[$sessionName])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid session type']);
    exit;
}
session_name($sessionName);
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$expectedRole = $sessionNames[$sessionName];
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $expectedRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$barangayId = $expectedRole === 'barangay'
    ? (int)($_SESSION['barangay_id'] ?? 0)
    : (int)($_GET['barangay_id'] ?? 0);
if ($barangayId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid barangay ID']);
    exit;
}

$brgyName = '';
$brgyQ = $conn->query("SELECT barangay_name FROM barangays WHERE barangay_id = $barangayId");
if ($brgyQ && ($brgyRow = $brgyQ->fetch_assoc())) {
    $brgyName = $brgyRow['barangay_name'];
}

$confirmed = 0;
$pending = 0;
$resolved = 0;

$ccQ = $conn->query("SELECT COUNT(CASE WHEN cc.status = 'active' THEN 1 END) AS confirmed, COUNT(CASE WHEN cc.status = 'resolved' THEN 1 END) AS resolved FROM confirmed_cases cc WHERE cc.barangay_id = $barangayId");
if ($ccQ && ($ccRow = $ccQ->fetch_assoc())) {
    $confirmed = (int)$ccRow['confirmed'];
    $resolved = (int)$ccRow['resolved'];
}

$pendQ = $conn->query("SELECT COUNT(*) AS cnt FROM reports r WHERE r.barangay_id = $barangayId AND r.status = 'pending' AND r.archived_at IS NULL");
if ($pendQ && ($pendRow = $pendQ->fetch_assoc())) {
    $pending = (int)$pendRow['cnt'];
}

$allTypes = ['rabies', 'rescue', 'stray', 'injured', 'dead'];
$counts = array_fill_keys($allTypes, 0);
$bkQ = $conn->query("SELECT r.report_type, COUNT(DISTINCT r.report_id) AS cnt FROM reports r WHERE r.barangay_id = $barangayId AND r.archived_at IS NULL GROUP BY r.report_type");
if ($bkQ) {
    while ($bkRow = $bkQ->fetch_assoc()) {
        $counts[$bkRow['report_type']] = (int)$bkRow['cnt'];
    }
}
$breakdown = [];
foreach ($allTypes as $t) {
    $breakdown[] = ['type' => $t, 'count' => $counts[$t]];
}

$recentCase = null;
$rcQ = $conn->query("SELECT r.report_id, r.report_type, r.description, r.status, r.created_at, r.urgency_level, CONCAT('#RPT-', LPAD(r.report_id, 5, '0')) AS ref_code FROM reports r WHERE r.barangay_id = $barangayId AND r.archived_at IS NULL ORDER BY r.created_at DESC LIMIT 1");
if ($rcQ && ($rcRow = $rcQ->fetch_assoc())) {
    $recentCase = [
        'id' => (int)$rcRow['report_id'],
        'ref_code' => $rcRow['ref_code'],
        'type' => $rcRow['report_type'],
        'description' => $rcRow['description'] ?? '',
        'status' => $rcRow['status'],
        'urgency' => $rcRow['urgency_level'] ?? 'medium',
        'date' => $rcRow['created_at'],
    ];
}

echo json_encode([
    'barangay_id' => $barangayId,
    'name' => $brgyName,
    'confirmed' => $confirmed,
    'pending' => $pending,
    'resolved' => $resolved,
    'total' => $confirmed + $pending + $resolved,
    'breakdown' => $breakdown,
    'recent_case' => $recentCase,
    'last_updated' => date('Y-m-d H:i:s'),
]);
