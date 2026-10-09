<?php
session_name('DCVS_BARANGAY_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if (!$barangayId) {
    echo json_encode(['error' => 'No barangay assigned']);
    exit;
}

$period = $_GET['period'] ?? '30';
$periods = ['1', '7', '30'];
if (!in_array($period, $periods, true)) $period = '30';

if ($period === '1') {
    $curClause  = "AND DATE(created_at) = CURDATE()";
    $prevClause = "AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $sparkDays  = 1;
} elseif ($period === '7') {
    $curClause  = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $prevClause = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $sparkDays  = 7;
} else {
    $curClause  = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $prevClause = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $sparkDays  = 30;
}

// Total all-time counts per status
$total = [];
$statuses = ['pending', 'validated', 'confirmed', 'rejected'];
foreach ($statuses as $st) {
    $q = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL");
    $q->bind_param("is", $barangayId, $st);
    $q->execute();
    $total[$st] = (int)$q->get_result()->fetch_row()[0];
    $q->close();
}

// Period counts
$cur = $prev = $pct = [];
foreach ($statuses as $st) {
    $cq = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL $curClause");
    $cq->bind_param("is", $barangayId, $st);
    $cq->execute();
    $cur[$st] = (int)$cq->get_result()->fetch_row()[0];
    $cq->close();

    $pq = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL $prevClause");
    $pq->bind_param("is", $barangayId, $st);
    $pq->execute();
    $prev[$st] = (int)$pq->get_result()->fetch_row()[0];
    $pq->close();

    $pct[$st] = $prev[$st] > 0 ? round(($cur[$st] - $prev[$st]) / $prev[$st] * 100, 1) : ($cur[$st] > 0 ? 100 : 0);
}

echo json_encode([
    'period'     => $period,
    'total'      => $total,
    'cur'        => $cur,
    'prev'       => $prev,
    'pct'        => $pct,
]);
