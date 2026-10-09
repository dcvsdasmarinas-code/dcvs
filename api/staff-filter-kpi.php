<?php
session_name('DCVS_STAFF_SESSION');
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
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$period = $_GET['period'] ?? '30';
$periods = ['1', '7', '30'];
if (!in_array($period, $periods, true)) $period = '30';

// Build current and previous date clauses
if ($period === '1') {
    $curClause  = "AND DATE(created_at) = CURDATE()";
    $prevClause = "AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($period === '7') {
    $curClause  = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $prevClause = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} else {
    $curClause  = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $prevClause = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
}

// Helper to run a query with a clause
function kpiQuery($conn, $sql, $clause) {
    $r = $conn->query("$sql $clause");
    return $r ? (int)$r->fetch_row()[0] : 0;
}

// Total all-time counts (display value)
$totalResidents = kpiQuery($conn, "SELECT COUNT(*) FROM users WHERE role = 'resident'", "");
$totalPets      = kpiQuery($conn, "SELECT COUNT(*) FROM pets WHERE archived_at IS NULL", "");
$totalReports   = kpiQuery($conn, "SELECT COUNT(*) FROM reports WHERE archived_at IS NULL", "");

// For rabies: total currently active
$totalRabies    = kpiQuery($conn, "SELECT COUNT(*) FROM reports WHERE report_type = 'rabies' AND status IN ('pending', 'validated') AND archived_at IS NULL", "");

// Period counts (new in current period) — for flow metrics
$curResidents = kpiQuery($conn, "SELECT COUNT(*) FROM users WHERE role = 'resident'", $curClause);
$curPets      = kpiQuery($conn, "SELECT COUNT(*) FROM pets WHERE archived_at IS NULL", $curClause);
$curReports   = kpiQuery($conn, "SELECT COUNT(*) FROM reports WHERE archived_at IS NULL", $curClause);

// Previous period counts
$prevResidents = kpiQuery($conn, "SELECT COUNT(*) FROM users WHERE role = 'resident'", $prevClause);
$prevPets      = kpiQuery($conn, "SELECT COUNT(*) FROM pets WHERE archived_at IS NULL", $prevClause);
$prevReports   = kpiQuery($conn, "SELECT COUNT(*) FROM reports WHERE archived_at IS NULL", $prevClause);

// Rabies is a STOCK metric (total active count), so compare total now
// vs total at the start of the current period
$newRabiesInPeriod = kpiQuery($conn, "SELECT COUNT(*) FROM reports WHERE report_type = 'rabies' AND status IN ('pending', 'validated') AND archived_at IS NULL", $curClause);
$curRabies  = $totalRabies;
$prevRabies = max(0, $totalRabies - $newRabiesInPeriod);

// Calculate percentage changes
function calcPct($cur, $prev) {
    if ($prev > 0) return round(($cur - $prev) / $prev * 100, 1);
    return $cur > 0 ? 100 : 0;
}

// ── Reports by Barangay (Top 5, period-filtered) ──
$brgyData = [];
$brgyQ = $conn->query("
  SELECT b.barangay_id, b.barangay_name,
    COUNT(*) as total,
    SUM(CASE WHEN r.report_type = 'stray' THEN 1 ELSE 0 END) as stray,
    SUM(CASE WHEN r.report_type = 'rabies' THEN 1 ELSE 0 END) as rabies,
    SUM(CASE WHEN r.report_type = 'injured' THEN 1 ELSE 0 END) as injured,
    SUM(CASE WHEN r.report_type = 'dead' THEN 1 ELSE 0 END) as dead,
    SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN r.status = 'validated' THEN 1 ELSE 0 END) as validated,
    SUM(CASE WHEN r.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as rejected
  FROM reports r
  JOIN barangays b ON r.barangay_id = b.barangay_id
  WHERE r.archived_at IS NULL
  GROUP BY b.barangay_id, b.barangay_name
  ORDER BY total DESC
  LIMIT 5
");
if ($brgyQ) {
    while ($row = $brgyQ->fetch_assoc()) {
        $brgyData[] = [
            'id' => (int)$row['barangay_id'],
            'name' => $row['barangay_name'],
            'total' => (int)$row['total'],
            'stray' => (int)$row['stray'],
            'rabies' => (int)$row['rabies'],
            'injured' => (int)$row['injured'],
            'dead' => (int)$row['dead'],
            'pending' => (int)$row['pending'],
            'validated' => (int)$row['validated'],
            'confirmed' => (int)$row['confirmed'],
            'resolved' => (int)$row['resolved'],
            'rejected' => (int)$row['rejected'],
        ];
    }
}

// ── Daily trend data for sparklines (last 30 days) ──
$sparkDays = 30;
$startDate = date('Y-m-d', strtotime("-".($sparkDays-1)." days"));
function dailyTrend($conn, $sql, $startDate, $sparkDays) {
    $arr = array_fill(0, $sparkDays, 0);
    $q = $conn->query("$sql AND DATE(t.created_at) >= '$startDate' GROUP BY DATE(t.created_at)");
    if ($q) while ($row = $q->fetch_assoc()) {
        $idx = (int)((strtotime($row['d']) - strtotime($startDate)) / 86400);
        if ($idx >= 0 && $idx < $sparkDays) $arr[$idx] = (int)$row['c'];
    }
    return $arr;
}
$sparkResidents = dailyTrend($conn, "SELECT DATE(created_at) as d, COUNT(*) as c FROM users t WHERE role='resident'", $startDate, $sparkDays);
$sparkPets      = dailyTrend($conn, "SELECT DATE(created_at) as d, COUNT(*) as c FROM pets t WHERE archived_at IS NULL", $startDate, $sparkDays);
$sparkReports   = dailyTrend($conn, "SELECT DATE(created_at) as d, COUNT(*) as c FROM reports t WHERE archived_at IS NULL", $startDate, $sparkDays);
$sparkRabies    = dailyTrend($conn, "SELECT DATE(created_at) as d, COUNT(*) as c FROM reports t WHERE report_type='rabies' AND archived_at IS NULL", $startDate, $sparkDays);

echo json_encode([
    'period'          => $period,
    'brgyData'        => $brgyData,
    'totalResidents'  => $totalResidents,
    'totalPets'       => $totalPets,
    'totalReports'    => $totalReports,
    'totalRabies'     => $totalRabies,
    'curResidents'    => $curResidents,
    'curPets'         => $curPets,
    'curReports'      => $curReports,
    'curRabies'       => $curRabies,
    'prevResidents'   => $prevResidents,
    'prevPets'        => $prevPets,
    'prevReports'     => $prevReports,
    'prevRabies'      => $prevRabies,
    'pctResidents'    => calcPct($curResidents, $prevResidents),
    'pctPets'         => calcPct($curPets, $prevPets),
    'pctReports'      => calcPct($curReports, $prevReports),
    'pctRabies'       => calcPct($curRabies, $prevRabies),
    'sparkResidents'  => $sparkResidents,
    'sparkPets'       => $sparkPets,
    'sparkReports'    => $sparkReports,
    'sparkRabies'     => $sparkRabies,
]);
