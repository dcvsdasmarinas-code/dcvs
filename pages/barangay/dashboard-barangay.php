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
require_once __DIR__ . '/../../env.php';
loadDcvsEnv();
$dashboardResponseEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

// Re-verify role from database
$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $_SESSION['user_id']);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        $_SESSION = array();
        session_destroy();
        header('Location: login-barangay.php');
        exit;
    }
}
$roleCheck->close();

?>

<?php
// ──────────────────────────────────────────────────────────────────────────
// LIVE DATA — actual database records for this barangay (Paliparan I)
// ──────────────────────────────────────────────────────────────────────────
$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName   = $_SESSION['full_name'] ?? 'Barangay Officer';
$avatarUrl  = $_SESSION['avatar_url'] ?? '';
$activePage = 'overview';
$responseTargetHours = max(1, (int)env('DCVS_RESPONSE_TARGET_HOURS', '24'));
$progressTargetHours = max(1, (int)env('DCVS_PROGRESS_TARGET_HOURS', '48'));

// Barangay info
$barangayName = 'Barangay';
$barangayDistrict = '';
$bq = $conn->prepare("SELECT barangay_name, district FROM barangays WHERE barangay_id = ?");
$bq->bind_param('i', $barangayId);
$bq->execute();
$bResult = $bq->get_result();
if ($bResult && $bRow = $bResult->fetch_assoc()) {
    $barangayName = $bRow['barangay_name'];
    $barangayDistrict = str_replace('±', 'ñ', (string)$bRow['district']);
}
$bq->close();

// Report type & display labels
$typeLabels = ['stray' => 'Stray', 'rabies' => 'Rabies', 'injured' => 'Injured', 'dead' => 'Dead'];
$statusLabels = [
  'pending'   => 'Pending Barangay Review',
  'validated' => 'Pending DCVS Review',
    'confirmed' => 'In progress',
    'scheduled' => 'In progress',
    'resolved'  => 'Resolved',
    'rejected'  => 'Closed',
];

// ── KPI pipeline counts (active reports only) ──
$kpi = ['new' => 0, 'validation' => 0, 'progress' => 0, 'resolved' => 0, 'closed' => 0];
$kq = $conn->prepare("SELECT status, COUNT(*) c FROM reports WHERE barangay_id = ? AND archived_at IS NULL GROUP BY status");
$kq->bind_param('i', $barangayId);
$kq->execute();
$kr = $kq->get_result();
while ($kRow = $kr->fetch_assoc()) {
    switch ($kRow['status']) {
        case 'pending':   $kpi['validation'] += (int)$kRow['c']; break;
        case 'validated': $kpi['new']        += (int)$kRow['c']; break;
        case 'confirmed':
        case 'scheduled': $kpi['progress']   += (int)$kRow['c']; break;
        case 'resolved':  $kpi['resolved']   += (int)$kRow['c']; break;
        case 'rejected':  $kpi['closed']     += (int)$kRow['c']; break;
    }
}
$kq->close();

// ── KPI trend (30 days vs previous 30 days) ──
$kpiTrends = [];
$kpiMap = [
    'new'        => ['validated'],
    'validation' => ['pending'],
    'progress'   => ['confirmed', 'scheduled'],
    'resolved'   => ['resolved'],
    'closed'     => ['rejected'],
];
foreach ($kpiMap as $key => $statuses) {
    $cur = 0; $prev = 0;
    foreach ($statuses as $st) {
        $s = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $s->bind_param('is', $barangayId, $st);
        $s->execute();
        $cur += (int)$s->get_result()->fetch_row()[0];
        $s->close();
        $s = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $s->bind_param('is', $barangayId, $st);
        $s->execute();
        $prev += (int)$s->get_result()->fetch_row()[0];
        $s->close();
    }
    $pct = $prev > 0 ? (int)round(($cur - $prev) / $prev * 100) : ($cur > 0 ? 100 : 0);
    $kpiTrends[$key] = ['pct' => $pct, 'dir' => $cur >= $prev ? 'up' : 'down'];
}

// ── Reports list (real rows for the attention table) ──
$reports = [];
$rq = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.report_type, r.animal_type, r.animal_count, r.animal_behavior,
           r.barangay_validation_status, r.urgency_level, r.status,
           r.location_address, r.description, r.photo_url, r.video_url, r.rejection_reason,
           r.incident_at, r.created_at, r.validated_at, r.confirmed_at, r.updated_at, cc.resolved_at,
           u.full_name AS reporter_name, rw.state AS response_state, rw.response_mode,
           rw.response_deadline, rw.progress_deadline, rw.last_progress_at, rw.turnover_deadline,
           rw.holding_location, rw.animal_condition,
           rr.resolution_details, rr.animal_disposition, rr.resolved_at AS response_resolved_at,
           tt.status AS turnover_status, tt.due_at AS turnover_due_at, tt.received_at AS turnover_received_at,
           v.full_name AS validator_name
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users v ON r.validated_by = v.user_id
    LEFT JOIN confirmed_cases cc ON r.report_id = cc.report_id
    LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
    LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
    LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
    WHERE r.barangay_id = ?
    ORDER BY r.created_at DESC
    LIMIT 50
");
$rq->bind_param('i', $barangayId);
$rq->execute();
$rResult = $rq->get_result();
while ($rRow = $rResult->fetch_assoc()) {
  $reportedAt = !empty($rRow['incident_at']) ? $rRow['incident_at'] : $rRow['created_at'];
    $reports[] = [
        'id'          => $rRow['reference_id'],
        'report_id'   => (int)$rRow['report_id'],
        'category'    => $typeLabels[$rRow['report_type']] ?? ucfirst($rRow['report_type']),
        'type'        => $rRow['report_type'],
        'animalType'  => (string)($rRow['animal_type'] ?? ''),
        'animalCount' => (string)($rRow['animal_count'] ?? ''),
        'animalBehavior' => (string)($rRow['animal_behavior'] ?? ''),
        'location'    => $rRow['location_address'],
        'description' => $rRow['description'],
        'photo'       => $rRow['photo_url'] ?: '',
        'video'       => $rRow['video_url'] ?: '',
        'rejection'   => $rRow['rejection_reason'] ?: '',
        'reporter'    => $rRow['reporter_name'],
        'submitted'   => date('M j, Y g:i A', strtotime($rRow['created_at'])),
        'submittedDate' => date('M j, Y', strtotime($rRow['created_at'])),
        'submittedTime' => date('g:i A', strtotime($rRow['created_at'])),
        'incidentDate' => date('M j, Y', strtotime($reportedAt)),
        '_sortDate'     => date('Y-m-d', strtotime($reportedAt)),
        '_sortTime'     => date('H:i:s', strtotime($reportedAt)),
        'priority'    => ucfirst($rRow['urgency_level']),
        'urgency'     => $rRow['urgency_level'],
        'fieldStatus' => (string)($rRow['barangay_validation_status'] ?? ''),
        'status'      => ($rRow['response_state'] ?? '') === 'barangay_response_approved'
            ? (($rRow['barangay_validation_status'] ?? '') === 'site_visit_pending'
                ? 'Accepted by Barangay · Site visit pending'
                : 'Accepted by Barangay')
            : ($statusLabels[$rRow['status']] ?? ucfirst($rRow['status'])),
        'assignedTo'  => $rRow['validator_name'] ?: 'Unassigned',
        'dateResolved'=> $rRow['resolved_at'] ? date('M j, Y g:i A', strtotime($rRow['resolved_at'])) : '-',
        '_created_at' => $rRow['created_at'],
        '_validated_at' => $rRow['validated_at'],
        '_confirmed_at' => $rRow['confirmed_at'],
        '_updated_at' => $rRow['updated_at'],
        '_db_status'  => $rRow['status'],
        '_validator'  => $rRow['validator_name'] ?: '',
        'responseState' => (string)($rRow['response_state'] ?? ''),
        'responseMode' => (string)($rRow['response_mode'] ?? ''),
        'responseDeadline' => (string)($rRow['response_deadline'] ?? ''),
        'progressDeadline' => (string)($rRow['progress_deadline'] ?? ''),
        'lastProgressAt' => (string)($rRow['last_progress_at'] ?? ''),
        'turnoverDeadline' => (string)($rRow['turnover_deadline'] ?? ''),
        'holdingLocation' => (string)($rRow['holding_location'] ?? ''),
        'animalCondition' => (string)($rRow['animal_condition'] ?? ''),
        'resolutionDetails' => (string)($rRow['resolution_details'] ?? ''),
        'animalDisposition' => (string)($rRow['animal_disposition'] ?? ''),
        'responseResolvedAt' => (string)($rRow['response_resolved_at'] ?? ''),
        'turnoverStatus' => (string)($rRow['turnover_status'] ?? ''),
        'turnoverDueAt' => (string)($rRow['turnover_due_at'] ?? ''),
        'turnoverReceivedAt' => (string)($rRow['turnover_received_at'] ?? ''),
    ];
}
$rq->close();

// ── Comparison chart: actual reports and resolved cases ──
$cmpLabels = [];
$cmpDailyReportMap = [];
$cmpDailyResolvedMap = [];
$cmpStart = date('Y-m-d', strtotime('-29 days'));
$cmpEnd = date('Y-m-d', strtotime('+1 day'));
for ($i = 29; $i >= 0; $i--) {
  $ts = strtotime("-{$i} days");
  $day = date('Y-m-d', $ts);
  $cmpLabels[] = date('M j', $ts);
  $cmpDailyReportMap[$day] = 0;
  $cmpDailyResolvedMap[$day] = 0;
}
$cmpStmt = $conn->prepare("SELECT DATE(COALESCE(incident_at, created_at)) AS report_day, COUNT(*) AS total FROM reports WHERE barangay_id = ? AND COALESCE(incident_at, created_at) >= ? AND COALESCE(incident_at, created_at) < ? GROUP BY DATE(COALESCE(incident_at, created_at))");
if ($cmpStmt) {
  $cmpStmt->bind_param('iss', $barangayId, $cmpStart, $cmpEnd);
  $cmpStmt->execute();
  $cmpResult = $cmpStmt->get_result();
  while ($cmpRow = $cmpResult->fetch_assoc()) $cmpDailyReportMap[$cmpRow['report_day']] = (int)$cmpRow['total'];
  $cmpStmt->close();
}
$cmpStmt = $conn->prepare("SELECT DATE(cc.resolved_at) AS resolved_day, COUNT(DISTINCT r.report_id) AS total FROM confirmed_cases cc INNER JOIN reports r ON r.report_id = cc.report_id WHERE r.barangay_id = ? AND cc.status = 'resolved' AND cc.resolved_at >= ? AND cc.resolved_at < ? GROUP BY DATE(cc.resolved_at)");
if ($cmpStmt) {
  $cmpStmt->bind_param('iss', $barangayId, $cmpStart, $cmpEnd);
  $cmpStmt->execute();
  $cmpResult = $cmpStmt->get_result();
  while ($cmpRow = $cmpResult->fetch_assoc()) $cmpDailyResolvedMap[$cmpRow['resolved_day']] = (int)$cmpRow['total'];
  $cmpStmt->close();
}
$cmpDaily = ['labels' => $cmpLabels, 'reports' => array_values($cmpDailyReportMap), 'resolved' => array_values($cmpDailyResolvedMap)];

$cmpYearly = [];
$firstTrendYear = 2021;
$lastTrendYear = (int)date('Y');
for ($year = $firstTrendYear; $year <= $lastTrendYear; $year++) {
  $cmpYearly[(string)$year] = ['reports' => array_fill(0, 12, 0), 'resolved' => array_fill(0, 12, 0)];
}
$cmpStmt = $conn->prepare("SELECT YEAR(COALESCE(incident_at, created_at)) AS report_year, MONTH(COALESCE(incident_at, created_at)) AS report_month, COUNT(*) AS total FROM reports WHERE barangay_id = ? AND COALESCE(incident_at, created_at) >= ? AND COALESCE(incident_at, created_at) < ? GROUP BY YEAR(COALESCE(incident_at, created_at)), MONTH(COALESCE(incident_at, created_at))");
if ($cmpStmt) {
  $yearStart = $firstTrendYear . '-01-01';
  $yearEnd = ($lastTrendYear + 1) . '-01-01';
  $cmpStmt->bind_param('iss', $barangayId, $yearStart, $yearEnd);
  $cmpStmt->execute();
  $cmpResult = $cmpStmt->get_result();
  while ($cmpRow = $cmpResult->fetch_assoc()) $cmpYearly[(string)$cmpRow['report_year']]['reports'][(int)$cmpRow['report_month'] - 1] = (int)$cmpRow['total'];
  $cmpStmt->close();
}
$cmpStmt = $conn->prepare("SELECT YEAR(cc.resolved_at) AS resolved_year, MONTH(cc.resolved_at) AS resolved_month, COUNT(DISTINCT r.report_id) AS total FROM confirmed_cases cc INNER JOIN reports r ON r.report_id = cc.report_id WHERE r.barangay_id = ? AND cc.status = 'resolved' AND cc.resolved_at >= ? AND cc.resolved_at < ? GROUP BY YEAR(cc.resolved_at), MONTH(cc.resolved_at)");
if ($cmpStmt) {
  $yearStart = $firstTrendYear . '-01-01';
  $yearEnd = ($lastTrendYear + 1) . '-01-01';
  $cmpStmt->bind_param('iss', $barangayId, $yearStart, $yearEnd);
  $cmpStmt->execute();
  $cmpResult = $cmpStmt->get_result();
  while ($cmpRow = $cmpResult->fetch_assoc()) $cmpYearly[(string)$cmpRow['resolved_year']]['resolved'][(int)$cmpRow['resolved_month'] - 1] = (int)$cmpRow['total'];
  $cmpStmt->close();
}

// ── Categories: actual report counts by time period ──
$categories = [];
$categoryIcons = ['stray' => 'paw', 'rabies' => 'rabies', 'injured' => 'injured', 'dead' => 'dead'];
$categoryTypes = ['stray', 'rabies', 'injured', 'dead'];
$categoryPeriods = ['all' => [], '30' => [], '7' => [], 'today' => []];
$categorySummary = [
  'all' => ['total' => 0, 'previousTotal' => null, 'unresolved' => 0],
  '30' => ['total' => 0, 'previousTotal' => 0, 'unresolved' => 0],
  '7' => ['total' => 0, 'previousTotal' => 0, 'unresolved' => 0],
  'today' => ['total' => 0, 'previousTotal' => 0, 'unresolved' => 0],
];
$categoryCounts = [];
$cq2 = $conn->prepare("
    SELECT report_type,
           COUNT(*) AS all_time,
           SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30_days,
           SUM(created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
               AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS previous_30_days,
           SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_7_days,
           SUM(created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
               AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS previous_7_days,
           SUM(created_at >= CURDATE()) AS today,
           SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
               AND created_at < CURDATE()) AS yesterday,
           SUM(status NOT IN ('resolved', 'rejected')) AS unresolved_all,
           SUM(status NOT IN ('resolved', 'rejected')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS unresolved_30_days,
           SUM(status NOT IN ('resolved', 'rejected')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS unresolved_7_days,
           SUM(status NOT IN ('resolved', 'rejected')
               AND created_at >= CURDATE()) AS unresolved_today
    FROM reports
    WHERE barangay_id = ?
    GROUP BY report_type
");
if (!$cq2) {
  error_log('Failed to prepare barangay category summary: ' . $conn->error);
} else {
  $cq2->bind_param('i', $barangayId);
  if (!$cq2->execute()) {
    error_log('Failed to query barangay category summary: ' . $cq2->error);
  } else {
    $cr2 = $cq2->get_result();
    if (!$cr2) {
      error_log('Failed to read barangay category summary: ' . $cq2->error);
    } else {
      while ($cRow = $cr2->fetch_assoc()) $categoryCounts[$cRow['report_type']] = $cRow;
    }
  }
  $cq2->close();
}
$categoryAll = [];
foreach ($categoryTypes as $t) {
  $row = $categoryCounts[$t] ?? [];
  $total = (int)($row['all_time'] ?? 0);
  $categoryAll[$t] = $total;
  $last30 = (int)($row['last_30_days'] ?? 0);
  $last7 = (int)($row['last_7_days'] ?? 0);
  $today = (int)($row['today'] ?? 0);
  $categoryPeriods['all'][] = ['label' => $typeLabels[$t], 'count' => $total];
  $categoryPeriods['30'][] = ['label' => $typeLabels[$t], 'count' => $last30];
  $categoryPeriods['7'][] = ['label' => $typeLabels[$t], 'count' => $last7];
  $categoryPeriods['today'][] = ['label' => $typeLabels[$t], 'count' => $today];
  $categorySummary['all']['total'] += $total;
  $categorySummary['all']['unresolved'] += (int)($row['unresolved_all'] ?? 0);
  $categorySummary['30']['total'] += $last30;
  $categorySummary['30']['previousTotal'] += (int)($row['previous_30_days'] ?? 0);
  $categorySummary['30']['unresolved'] += (int)($row['unresolved_30_days'] ?? 0);
  $categorySummary['7']['total'] += $last7;
  $categorySummary['7']['previousTotal'] += (int)($row['previous_7_days'] ?? 0);
  $categorySummary['7']['unresolved'] += (int)($row['unresolved_7_days'] ?? 0);
  $categorySummary['today']['total'] += $today;
  $categorySummary['today']['previousTotal'] += (int)($row['yesterday'] ?? 0);
  $categorySummary['today']['unresolved'] += (int)($row['unresolved_today'] ?? 0);
}
$categoryGrandTotal = array_sum($categoryAll);
foreach ($categoryTypes as $t) {
  $total = $categoryAll[$t];
  $categories[] = [
    'name'       => $typeLabels[$t],
    'count'      => $total,
    'total'      => $total,
    'percentage' => $categoryGrandTotal > 0 ? (int)round(($total / $categoryGrandTotal) * 100) : 0,
    'icon'       => $categoryIcons[$t],
  ];
}

// ── Activity feed (real report lifecycle events) ──
$activityEvents = [];
foreach ($reports as $r) {
    $events = [];
    $events[] = ['ts' => $r['_created_at'], 'color' => 'teal', 'action' => 'submitted by ' . $r['reporter'], 'detail' => $r['category'] . ' · ' . $r['location']];
    if ($r['_validated_at']) $events[] = ['ts' => $r['_validated_at'], 'color' => 'amber', 'action' => 'validated by ' . ($r['_validator'] ?: 'Barangay'), 'detail' => $r['category'] . ' · ' . $r['location']];
    if ($r['_confirmed_at']) $events[] = ['ts' => $r['_confirmed_at'], 'color' => 'teal', 'action' => 'confirmed by DCVS', 'detail' => $r['category'] . ' · ' . $r['location']];
    if ($r['_db_status'] === 'resolved') $events[] = ['ts' => $r['_updated_at'], 'color' => 'green', 'action' => 'marked as Resolved', 'detail' => $r['category'] . ' · ' . $r['location']];
    if ($r['_db_status'] === 'rejected') $events[] = ['ts' => $r['_updated_at'], 'color' => 'red', 'action' => 'closed (rejected)', 'detail' => $r['category'] . ' · ' . $r['location']];
    foreach ($events as $ev) {
        $ev['reportId'] = $r['id'];
        $activityEvents[] = $ev;
    }
}
usort($activityEvents, fn($a, $b) => strtotime($b['ts']) <=> strtotime($a['ts']));
$activities = [];
foreach (array_slice($activityEvents, 0, 8) as $ev) {
    $ts = strtotime($ev['ts']);
    $activities[] = [
        'day'      => date('d', $ts),
        'month'    => date('M', $ts),
        'color'    => $ev['color'],
        'reportId' => $ev['reportId'],
        'action'   => $ev['action'],
        'detail'   => $ev['detail'],
        'time'     => date('M j, g:i A', $ts),
    ];
}

// ── Unread notifications ──
$unreadNotifs = 0;
$uq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
$uq->bind_param('i', $userId);
$uq->execute();
$unreadNotifs = (int)$uq->get_result()->fetch_row()[0];
$uq->close();

// ── Dashboard config for JS ──
$dashboardConfig = [
    'barangayId'    => (int)$barangayId,
    'barangayName'  => $barangayName,
    'location'      => $barangayDistrict ? $barangayDistrict . ' City, Cavite' : 'Dasmariñas City, Cavite',
    'user'          => ['name' => $fullName, 'role' => 'Barangay Officer'],
    'comparisonChart' => [
      'daily'     => $cmpDaily,
      'yearly'    => $cmpYearly,
    ],
    'metrics'       => $kpi,
    'reports'       => $reports,
    'categories'    => $categories,
    'categoryPeriods' => $categoryPeriods,
    'categorySummary' => $categorySummary,
    'categoryTotal' => $categoryGrandTotal,
    'activities'    => $activities,
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Operations Overview — <?php echo htmlspecialchars('BRGY. ' . strtoupper($barangayName)); ?> Records & Incident Portal</title>
  
  <!-- Modern Typography from Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />

  <style>
    /* ==========================================================================
       DESIGN SYSTEM & CSS VARIABLES
       Clean White Theme / Responsive Architecture
       ========================================================================== */
    :root {
      /* Brand Greens */
      --primary-green: #0B4D26;
      --primary-green-hover: #083B1D;
      --primary-green-light: #EBF7EE;
      --primary-green-soft: #D1EEDB;
      --primary-green-text: #0E6231;
      --accent-green: #107C41;

      /* Neutrals & Surfaces - Pure White Page */
      --bg-page: #FFFFFF;
      --bg-card: #FFFFFF;
      --bg-subtle: #F9FAFB;
      --bg-hover: #F3F4F6;
      --border-color: #E5E7EB;
      --border-color-light: #F0F2F5;
      --border-color-dark: #D1D5DB;

      /* Typography Colors */
      --text-main: #18181B;
      --text-secondary: #71717A;
      --text-muted: #71717A;
      --text-faint: #A1A1AA;

      /* Status & Priority Accents */
      --badge-red-bg: #FEE4E2;
      --badge-red-text: #D92D20;
      --badge-red-dot: #F04438;

      --badge-amber-bg: #FEF0C7;
      --badge-amber-text: #B54708;
      --badge-amber-dot: #F79009;

      --badge-blue-bg: #E0F2FE;
      --badge-blue-text: #0284C7;
      --badge-blue-dot: #0BA5EC;

      --badge-green-bg: #ECFDF3;
      --badge-green-text: #027A48;
      --badge-green-dot: #12B76A;

      --badge-gray-bg: #F2F4F7;
      --badge-gray-text: #344054;
      --badge-gray-dot: #667085;

      /* Metric Step Accent Backgrounds */
      --metric-new-bg: #EBFDF2;
      --metric-new-icon: #0F973D;
      --metric-validation-bg: #FEF6EE;
      --metric-validation-icon: #E07A16;
      --metric-progress-bg: #EFF8FF;
      --metric-progress-icon: #175CD3;
      --metric-resolved-bg: #EDFDF5;
      --metric-resolved-icon: #12B76A;
      --metric-closed-bg: #F8F9FC;
      --metric-closed-icon: #475467;

      /* Timeline Node Colors */
      --ring-teal: #0D9488;
      --ring-green: #10B981;
      --ring-red: #EF4444;
      --ring-amber: #F59E0B;

      /* Dimensions & Shadows */
      --header-height: 70px;
      --radius-sm: 6px;
      --radius-md: 10px;
      --radius-lg: 14px;
      --radius-xl: 18px;
      --shadow-card: 0 1px 3px rgba(16, 24, 40, 0.05), 0 1px 2px rgba(16, 24, 40, 0.03);
      --shadow-dropdown: 0 12px 24px -4px rgba(0, 0, 0, 0.12), 0 4px 8px -2px rgba(0, 0, 0, 0.04);
      --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Reset & Global */
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background-color: var(--bg-page);
      color: var(--text-main);
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      overflow-x: hidden;
      min-height: 100vh;
    }

    .app-wrapper {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background-color: var(--bg-page);
    }

    /* ==========================================================================
       TOP NAVIGATION BAR
       ========================================================================== */
    .topbar {
      height: var(--header-height);
      background-color: #FFFFFF;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      position: sticky;
      top: 0;
      z-index: 40;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .topbar-breadcrumb {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.82rem;
      color: #94a3b8;
    }

    .breadcrumb-root {
      color: #0B4D26;
      font-weight: 600;
    }

    .breadcrumb-sep {
      font-size: 0.6rem;
    }

    .breadcrumb-current {
      color: #0f172a;
      font-weight: 600;
    }

    .barangay-selector {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 999px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      cursor: pointer;
      transition: var(--transition);
    }

    .barangay-selector:hover {
      background-color: var(--bg-hover);
      border-color: var(--border-color-dark);
    }

    .barangay-selector svg {
      color: var(--primary-green);
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    /* ── Sidebar integration (sb2) ── */
    .topbar-toggle {
      display: none;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      background: #fff;
      color: #18181b;
      cursor: pointer;
      flex-shrink: 0;
      transition: background 0.15s, border-color 0.15s;
    }
    .topbar-toggle:hover {
      background: #f4f4f5;
      border-color: var(--border-color-dark);
    }
    body.sb2-active .app-wrapper {
      padding-left: var(--sidebar-w);
      transition: padding-left 0.2s ease;
    }
    body.sb2-active .app-wrapper .topbar {
      left: auto !important;
    }
    @media (max-width: 768px) {
      .topbar-toggle { display: flex !important; }
    }

    /* Notification Dropdown */
    .notif-dropdown { position: relative; }
    .notif-btn { background: none; border: none; font-size: 1.2rem; color: #475569; cursor: pointer; padding: 6px; position: relative; border-radius: 8px; transition: background 0.15s; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; }
    .notif-btn:hover { background: #f1f5f9; }
    .notif-badge { position: absolute; top: 0; right: 0; background: #dc2626; color: #fff; font-size: 0.6rem; font-weight: 700; min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 4px; line-height: 1; }
    .notif-panel { position: absolute; top: calc(100% + 8px); right: 0; width: 380px; background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.12); border: 1px solid #e2e8f0; z-index: 200; display: none; overflow: hidden; }
    .notif-panel.open { display: block; }
    .notif-panel-header { display: flex; justify-content: space-between; align-items: center; padding: 0.9rem 1rem; border-bottom: 1px solid #e2e8f0; }
    .notif-panel-header h4 { margin: 0; font-size: 0.85rem; font-weight: 700; color: #0f172a; }
    .notif-panel-header a { font-size: 0.75rem; color: #0E8131; text-decoration: none; font-weight: 600; cursor: pointer; }
    .notif-panel-header a:hover { text-decoration: underline; }
    .notif-panel-body { max-height: 360px; overflow-y: auto; }
    .notif-item { display: flex; gap: 0.75rem; padding: 0.85rem 1rem; cursor: pointer; transition: background 0.12s; border-bottom: 1px solid #f1f5f9; }
    .notif-item:hover { background: #f8fafc; }
    .notif-item.unread { background: #f0fdf4; }
    .notif-item.unread:hover { background: #dcebe0; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; background: transparent; color: #000; }
    .notif-content { flex: 1; min-width: 0; }
    .notif-title { font-size: 0.8rem; font-weight: 700; color: #0f172a; }
    .notif-msg { font-size: 0.74rem; color: #64748b; line-height: 1.3; margin-top: 1px; }
    .notif-time { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }
    .notif-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.82rem; }
    @media (max-width: 640px) { .notif-panel { width: calc(100vw - 2rem) !important; right: 0.5rem !important; } }

    /* User Profile Pill */
    .user-profile {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 4px 8px;
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: var(--transition);
      border: 1px solid transparent;
    }

    .user-profile:hover {
      background-color: var(--bg-hover);
      border-color: var(--border-color-light);
    }

    .user-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #D5ECE0;
      border: 1px solid #B8DEC9;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary-green);
      font-weight: 700;
      font-size: 12px;
      overflow: hidden;
    }

    .user-meta {
      display: flex;
      flex-direction: column;
      line-height: 1.25;
    }

    .user-name {
      font-size: 14px;
      font-weight: 600;
      color: var(--text-main);
    }

    .user-role {
      font-size: 12px;
      color: var(--text-muted);
    }

    /* ==========================================================================
       DASHBOARD CONTENT AREA
       ========================================================================== */
    .dashboard-content {
      padding: 24px 32px 50px;
      max-width: 1440px;
      margin: 0 auto;
      width: 100%;
      flex: 1;
    }

    /* Action & Date Filter Bar */
    .action-bar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      margin-bottom: 22px;
      flex-wrap: wrap;
      gap: 12px;
      position: relative;
    }

    .action-buttons-group {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      padding: 8px 16px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      cursor: pointer;
      transition: all 0.15s;
    }

    .btn-secondary:hover {
      background: #f4f4f5;
      border-color: #d4d4d8;
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #18181b;
      border: 1px solid #18181b;
      border-radius: 6px;
      padding: 8px 16px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #ffffff;
      cursor: pointer;
      transition: all 0.15s;
    }

    .btn-primary:hover {
      background: #27272a;
      border-color: #27272a;
    }

    /* ==========================================================================
       METRIC KPI CARDS (Appointment style)
       ========================================================================== */
    .metric-pipeline-container {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .pipeline-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 18px 20px;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      font-family: 'Inter', 'Segoe UI', sans-serif;
      cursor: default;
      transition: var(--transition);
    }

    .pipeline-step:hover {
      border-color: var(--border-color-dark);
      box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }

    .step-icon-box { display: none; }

    .step-content {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0;
    }

    .step-label {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      margin: 0 0 6px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .step-value {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 26px;
      font-weight: 600;
      color: #18181b;
      letter-spacing: -0.02em;
      line-height: 1;
      margin: 0 0 6px;
    }

    .step-trend {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      color: #71717a;
    }
    .step-trend svg { display: none; }

    .pipeline-arrow {
      color: var(--border-color-dark);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      padding: 0 4px;
      user-select: none;
    }

    /* ==========================================================================
       MAIN GRID LAYOUT (Left Content vs Right Sidebar)
       ========================================================================== */
    .dashboard-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 350px;
      gap: 24px;
      align-items: stretch;
    }

    .grid-left {
      display: flex;
      flex-direction: column;
      gap: 24px;
      min-width: 0;
    }

    .grid-right {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    /* Both columns stretch to align bottom edges */
    .grid-left > .dashboard-card,
    .grid-right > .dashboard-card { flex: 1; display: flex; flex-direction: column; }
    .grid-right > .category-chart-card { flex: 0 0 auto; }
    .chart-canvas-wrap { height: 320px; position: relative; }
    .comparison-chart-card .chart-container { flex: 1; display: flex; min-height: 0; }
    .comparison-chart-card .chart-canvas-wrap { flex: 1; min-height: 320px; height: auto; }
    .comparison-chart-card #brgyComparisonChart { width: 100% !important; height: 100% !important; }

    /* Reports by category — horizontal bar chart (recharts-style) */
    .category-chart-card .cat-chart-container { flex: 0 0 auto; }
    .category-chart-card .cat-chart-wrap { height: 220px; position: relative; }
    .cat-chart-footer {
      display: flex; flex-direction: column; justify-content: flex-end; gap: 0; flex: 1;
      padding: 12px 16px 16px;
    }
    .cat-chart-empty {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      background: #fff;
      color: var(--text-muted);
      font-size: 13px;
      text-align: center;
    }
    .cat-chart-empty[hidden],
    .cat-chart-share-bar[hidden] { display: none; }

    .cat-chart-footer-top {
      margin-bottom: 8px;
    }
    .cat-chart-trend { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; color: var(--text-main); }
    .cat-chart-note { font-size: 12px; color: var(--text-muted); }

    .cat-chart-share-bar {
      display: flex;
      height: 8px;
      overflow: hidden;
      margin: 12px 0;
      border-radius: 999px;
      background: #f1f5f9;
    }
    .cat-chart-share-segment {
      height: 100%;
      min-width: 0;
      transition: width .2s ease;
    }
    .cat-chart-stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .cat-chart-stat {
      min-width: 0;
      padding: 10px 12px;
      border: 1px solid #eef2f6;
      border-radius: 8px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 400;
      color: #52525b;
      line-height: 1.4;
    }
    .cat-chart-stat strong {
      display: block;
      margin-top: 3px;
      color: #18181b;
      font-size: 14px;
      font-weight: 600;
    }
    .cat-chart-stat .cat-stat-detail {
      display: block;
      margin-top: 2px;
      color: #71717a;
      font-size: 11px;
      overflow-wrap: anywhere;
    }
    .cat-stat-label {
      font-weight: 600;
      color: #18181b;
    }

    .chart-range-controls {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .chart-range {
      display: flex;
      align-items: center;
      border: 1px solid #e4e4e7;
      background: #fff;
      border-radius: 6px;
      overflow: hidden;
    }

    .chart-range-btn {
      padding: 5px 12px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 500;
      background: none;
      border: none;
      color: #71717a;
      cursor: pointer;
      transition: all 0.12s;
    }

    .chart-range-btn:hover {
      background: #f4f4f5;
    }

    .chart-range-btn.active {
      background: #18181b;
      color: #fff;
    }

    .chart-range-extra {
      display: flex;
      align-items: center;
    }

    .chart-range-select {
      height: 28px;
      padding: 0 8px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 500;
      background: #fff;
      color: #18181b;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      outline: none;
      cursor: pointer;
    }

    .chart-legend {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-left: 4px;
    }

    .chart-legend-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 500;
      color: #52525b;
    }

    .chart-legend-swatch {
      width: 10px;
      height: 10px;
      border-radius: 2px;
      flex-shrink: 0;
    }

    .geo-legend-refresh {
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-left: 8px;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      background: #fff;
      color: #475569;
      cursor: pointer;
      transition: color .15s ease, border-color .15s ease, background .15s ease;
    }
    .geo-legend-refresh:hover:not(:disabled) {
      border-color: #0E8131;
      background: #f0fdf4;
      color: #0E8131;
    }
    .geo-legend-refresh:focus-visible {
      outline: 2px solid #0E8131;
      outline-offset: 2px;
    }
    .geo-legend-refresh:disabled {
      cursor: wait;
      opacity: .65;
    }
    .geo-legend-refresh.is-loading i {
      animation: geo-map-refresh-spin .8s linear infinite;
    }
    @keyframes geo-map-refresh-spin {
      to { transform: rotate(360deg); }
    }

    .geo-map-section {
      margin-top: 24px;
    }

    .geo-map-card {
      border-radius: 0;
    }

    .geo-summary-bar {
      background: #fff;
    }

    .geo-summary-item {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #71717a;
    }

    .geo-summary-item strong {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
    }

    .geo-legend {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 500;
      color: #71717a;
    }

    .geo-legend-title {
      font-weight: 600;
      color: #18181b;
    }

    .geo-legend-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 500;
    }

    .geo-legend-item::before {
      content: '';
      width: 10px;
      height: 10px;
      border-radius: 2px;
      background: var(--legend-color);
      flex-shrink: 0;
    }

    /* Reusable Card */
    .dashboard-card {
      background-color: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      overflow: hidden;
    }

    .card-header {
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--border-color-light);
    }

    .card-header.no-border {
      border-bottom: none;
    }

    .card-title-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .card-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text-main);
      letter-spacing: -0.025em;
    }

    .count-badge {
      background-color: var(--bg-hover);
      color: var(--text-secondary);
      font-size: 12px;
      font-weight: 500;
      padding: 1px 7px;
      border-radius: 999px;
      border: 1px solid var(--border-color);
    }

    .card-action-link {
      font-size: 14px;
      font-weight: 500;
      color: var(--primary-green);
      text-decoration: none;
      cursor: pointer;
      transition: var(--transition);
    }

    .card-action-link:hover {
      text-decoration: underline;
    }

    .card-action-btn {
      background: none;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      padding: 4px 8px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .card-action-btn:hover {
      background-color: var(--bg-hover);
      color: var(--text-main);
    }

    .dropdown-wrap {
      position: relative;
    }

    .dropdown-menu {
      display: none;
      position: absolute;
      top: 100%;
      right: 0;
      margin-top: 4px;
      background: #fff;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      min-width: 140px;
      z-index: 20;
      padding: 4px 0;
    }

    .dropdown-menu.show {
      display: block;
    }

    .dropdown-item {
      display: block;
      width: 100%;
      padding: 7px 12px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 500;
      color: #52525b;
      background: none;
      border: none;
      text-align: left;
      cursor: pointer;
      transition: background 0.1s;
    }

    .dropdown-item:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .dropdown-item.active {
      background: #f4f4f5;
      color: #18181b;
      font-weight: 600;
    }

    /* ==========================================================================
       DATA TABLE: REPORTS REQUIRING ATTENTION
       ========================================================================== */
    .table-container {
      width: 100%;
      overflow-x: auto;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      color: #18181b;
      font-size: 14px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .data-table thead th {
      background: #fafafa;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      color: #71717a;
      height: 40px;
      padding: 0 12px;
      border-bottom: 1px solid #e4e4e7;
      text-align: left;
      vertical-align: middle;
      white-space: nowrap;
      user-select: none;
    }

    .data-table tbody td {
      padding: 10px 12px;
      font-size: 14px;
      color: #18181b;
      border-bottom: 1px solid #f4f4f5;
      vertical-align: middle;
    }

    .data-table tbody tr {
      border-bottom: 1px solid #f4f4f5;
      transition: background 0.1s;
    }

    .data-table tbody tr:last-child {
      border-bottom: none;
    }

    .data-table tbody tr:hover {
      background: #f9fafb;
    }

    .report-id-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 400;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      color: #18181b;
    }

    .category-cell {
      font-weight: 500;
      color: #18181b;
    }

    .location-cell {
      color: #71717a;
      font-size: 13px;
      font-weight: 400;
    }

    .submitted-cell {
      color: #71717a;
      font-size: 13px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 2px 8px;
      border-radius: 4px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 500;
      white-space: nowrap;
      line-height: 1.5;
      border: 1px solid #e4e4e7;
      letter-spacing: normal;
    }

    .status-badge.priority-high {
      background-color: #fee2e2;
      color: #991b1b;
      border-color: #fecaca;
    }

    .status-badge.priority-medium {
      background-color: #fef3c7;
      color: #92400e;
      border-color: #fde68a;
    }

    .status-badge.priority-low {
      background-color: #dcfce7;
      color: #166534;
      border-color: #bbf7d0;
    }

    .status-badge.status-pending {
      background-color: #fef3c7;
      color: #92400e;
      border-color: #fde68a;
    }

    .status-badge.status-validation {
      background-color: #dcebe0;
      color: #045e1f;
      border-color: #bbf7d0;
    }

    .status-badge.status-progress {
      background-color: #EBF5FF;
      color: #175CD3;
      border-color: #D5E9FD;
    }

    .status-badge.status-resolved {
      background-color: #0E8131;
      color: #ffffff;
      border-color: #0E8131;
    }

    .status-badge.status-closed {
      background-color: #fee2e2;
      color: #991b1b;
      border-color: #fecaca;
    }

    .date-resolved-cell {
      font-size: 14px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      white-space: nowrap;
    }

    .sd-row-actions {
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      background: transparent;
      border: none;
      border-radius: 6px;
      color: #71717a;
      cursor: pointer;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      line-height: 1;
      transition: all 0.15s ease;
    }

    .sd-row-actions:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .sd-row-actions .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    /* ==========================================================================
       MODERN WAVE CHART (WITH GRADIENT FILL & FLOATING CARD TOOLTIP)
       ========================================================================== */
    .chart-container {
      padding: 18px 24px 24px;
      position: relative;
    }

    .cat-chart-container {
      padding: 12px 0 0;
      display: flex;
      flex-direction: column;
      flex: 1;
    }

    .chart-legend {
      display: flex;
      align-items: center;
      gap: 24px;
      margin-bottom: 18px;
      font-size: 14px;
      font-weight: 500;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #71717A;
    }

    .legend-dot-blue {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background-color: #2563EB;
      display: inline-block;
    }

    .legend-line-dashed {
      width: 20px;
      height: 0;
      border-top: 2px dashed #94A3B8;
      display: inline-block;
    }

    .chart-svg-wrapper {
      position: relative;
      width: 100%;
      height: 250px;
      cursor: crosshair;
    }

    .chart-svg {
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    /* Floating Tooltip Card matching Reference Image */
    .chart-tooltip-popover {
      position: absolute;
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      border-radius: 12px;
      box-shadow: 0 12px 28px -4px rgba(15, 23, 42, 0.14), 0 4px 10px -2px rgba(15, 23, 42, 0.05);
      padding: 12px 16px 14px;
      pointer-events: none;
      z-index: 60;
      min-width: 195px;
      transform: translate(-50%, -122%);
      transition: opacity 0.15s ease, transform 0.15s ease;
      opacity: 0;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .chart-tooltip-popover.show {
      opacity: 1;
    }

    .chart-tooltip-header {
      font-size: 12px;
      font-weight: 600;
      color: #94A3B8;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 2px;
    }

    .chart-tooltip-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 14px;
      color: #71717A;
    }

    .tooltip-label-group {
      display: flex;
      align-items: center;
      gap: 7px;
      font-weight: 500;
    }

    .tooltip-blue-dot {
      width: 7.5px;
      height: 7.5px;
      border-radius: 50%;
      background-color: #2563EB;
      flex-shrink: 0;
    }

    .tooltip-dashed-line {
      width: 12px;
      height: 0;
      border-top: 1.5px dashed #94A3B8;
      flex-shrink: 0;
    }

    .chart-tooltip-val {
      font-weight: 600;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      letter-spacing: -0.02em;
      color: #18181B;
      font-size: 14px;
    }

    .chart-tooltip-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      background-color: #ECFDF3;
      color: #027A48;
      font-weight: 500;
      font-size: 12px;
      padding: 3.5px 8px;
      border-radius: 6px;
      margin-top: 4px;
      align-self: flex-start;
      border: 1px solid #D1FADF;
    }

    /* Vertical Guideline inside SVG */
    .chart-crosshair-line {
      stroke: #CBD5E1;
      stroke-width: 1.5;
      stroke-dasharray: 4 4;
      opacity: 0;
      transition: opacity 0.15s ease;
    }

    .chart-focus-dot {
      transition: transform 0.15s ease, opacity 0.15s ease;
      opacity: 0;
    }

    /* Category List Card */
    .category-list-body {
      padding: 14px 18px 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .category-item {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .category-info {
      display: flex;
      align-items: center;
      gap: 7px;
      width: 125px;
      flex-shrink: 0;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      white-space: nowrap;
    }

    .category-icon {
      width: 15px;
      height: 15px;
      color: var(--text-muted);
      flex-shrink: 0;
    }

    .progress-track {
      flex: 1;
      height: 7px;
      background-color: #F1F3F5;
      border-radius: 999px;
      overflow: hidden;
      position: relative;
    }

    .progress-bar-fill {
      height: 100%;
      background-color: var(--primary-green);
      border-radius: 999px;
      transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .category-stat {
      width: 50px;
      text-align: right;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      font-family: 'IBM Plex Mono', monospace;
      flex-shrink: 0;
    }

    .category-footer {
      margin-top: 6px;
      padding-top: 12px;
      border-top: 1px solid var(--border-color-light);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .category-total-label {
      font-size: 14px;
      font-weight: 600;
      color: var(--primary-green);
    }

    .category-total-val {
      font-size: 18px;
      font-weight: 600;
      letter-spacing: -0.02em;
      color: var(--text-main);
      font-family: 'IBM Plex Mono', monospace;
    }

    /* ==========================================================================
       RIGHT COLUMN: TODAY'S WORKLOAD & CUSTOM TIMELINE FEED
       ========================================================================== */
    .workload-body {
      padding: 16px 18px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .workload-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      background-color: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: var(--transition);
    }

    .workload-item:hover {
      border-color: var(--primary-green-soft);
      background-color: #FBFDFB;
      transform: translateY(-1px);
      box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    }

    .workload-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .workload-icon-box {
      width: 38px;
      height: 38px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .workload-icon-box.amber {
      background-color: #FEF6EE;
      color: #E07A16;
      border: 1px solid #FEE5C8;
    }

    .workload-icon-box.red {
      background-color: #FEF3F2;
      color: #D92D20;
      border: 1px solid #FEE4E2;
    }

    .workload-icon-box.green {
      background-color: #ECFDF3;
      color: #027A48;
      border: 1px solid #D1FADF;
    }

    .workload-texts {
      display: flex;
      flex-direction: column;
    }

    .workload-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-main);
    }

    .workload-sub {
      font-size: 11.5px;
      color: var(--text-muted);
      font-weight: 500;
      margin-top: 1px;
    }

    .workload-sub.alert-red {
      color: #D92D20;
    }

    .workload-right {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .workload-count {
      font-size: 26px;
      font-weight: 600;
      letter-spacing: -0.02em;
      color: var(--text-main);
      font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .workload-chevron {
      color: var(--text-faint);
      font-size: 14px;
    }

    /* ==========================================================================
       CUSTOM TIMELINE / HISTORY FEED WITH DATE & HOLLOW RING DONUT NODES
       ========================================================================== */
    .activity-feed-body {
      padding: 16px 18px 20px;
      display: flex;
      flex-direction: column;
      position: relative;
      max-height: 360px;
      overflow-y: auto;
    }

    .activity-feed-item {
      position: relative;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding-bottom: 22px;
    }

    .activity-feed-item:last-child {
      padding-bottom: 4px;
    }

    /* Left Date Column (e.g. 16 Oct / 31 May) */
    .activity-date-col {
      width: 28px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      flex-shrink: 0;
      text-align: left;
      padding-top: 1px;
    }

    .activity-date-day {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 600;
      color: #18181b;
      line-height: 1.05;
      letter-spacing: -0.02em;
    }

    .activity-date-month {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 11px;
      font-weight: 400;
      color: #71717a;
      line-height: 1.2;
    }

    /* Middle Timeline Track & Hollow Ring Donut Node */
    .activity-track-col {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 18px;
      flex-shrink: 0;
      align-self: stretch;
    }

    /* The continuous vertical connector line */
    .activity-timeline-line {
      position: absolute;
      top: 10px;
      bottom: -18px;
      width: 1.5px;
      background-color: #E5E7EB;
      left: 50%;
      transform: translateX(-50%);
      z-index: 1;
    }

    .activity-feed-item:last-child .activity-timeline-line {
      display: none;
    }

    /* The circular hollow donut ring node */
    .activity-ring-node {
      width: 13.5px;
      height: 13.5px;
      border-radius: 50%;
      background-color: #FFFFFF;
      position: relative;
      z-index: 2;
      margin-top: 2px;
      box-shadow: 0 0 0 2.5px #FFFFFF;
      flex-shrink: 0;
      border: 3px solid #18181b;
    }

    /* Ring Color Accents (Matching user reference) */
    .activity-ring-node.teal {
      border-color: #18181b;
    }

    .activity-ring-node.green {
      border-color: #18181b;
    }

    .activity-ring-node.red {
      border-color: #18181b;
    }

    .activity-ring-node.amber {
      border-color: #18181b;
    }

    /* Right Details Container */
    .activity-content {
      flex: 1;
      min-width: 0;
      padding-top: 0px;
    }

    .activity-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      line-height: 1.4;
    }

    .activity-title strong {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-weight: 600;
      font-size: 13px;
      color: #18181b;
    }

    .activity-meta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 3px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      color: #71717a;
    }

    .activity-desc {
      color: #71717a;
      font-weight: 400;
    }

    .activity-time {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 400;
      color: #71717a;
      flex-shrink: 0;
      margin-left: 6px;
    }

    /* ==========================================================================
       MODALS & DIALOGS
       ========================================================================== */
    .modal-backdrop {
      position: fixed;
      top: 0; left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.4);
      backdrop-filter: blur(2px);
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s ease;
      padding: 16px;
    }

    .modal-backdrop.open {
      opacity: 1;
      pointer-events: auto;
    }

    body:has(.modal-backdrop.open) .sb2 {
      filter: blur(4px);
      transition: filter 0.2s ease;
    }

    .modal-dialog {
      width: 100%;
      display: flex;
      justify-content: center;
    }

    .modal-backdrop.open .modal-dialog {
      animation: modalIn 0.2s ease;
    }

    @keyframes modalIn {
      from { opacity: 0; transform: scale(0.96) translateY(8px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }

    .modal-dialog-content {
      position: relative;
      width: 100%;
      max-width: 560px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
      max-height: 90vh;
      overflow-y: auto;
      font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .modal-dialog-close {
      position: absolute;
      top: 16px; right: 16px;
      width: 24px; height: 24px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      border: none;
      background: transparent;
      color: #71717a;
      cursor: pointer;
      transition: all 0.15s ease;
      font-size: 16px;
    }
    .modal-dialog-close:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .modal-dialog-header {
      display: flex;
      flex-direction: column;
      gap: 6px;
      text-align: left;
    }

    .modal-dialog-title {
      margin: 0;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 18px;
      font-weight: 600;
      line-height: 1;
      letter-spacing: -0.025em;
      color: #18181b;
    }

    .modal-dialog-description {
      margin: 0;
      font-size: 14px;
      color: #71717a;
      line-height: 1.5;
    }

    .modal-dialog-body {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .modal-dialog-footer {
      display: flex;
      flex-direction: row-reverse;
      justify-content: flex-end;
      gap: 8px;
      padding-top: 8px;
      border-top: 1px solid #e4e4e7;
    }

    /* ── shadcn/ui Alert Dialog (Reject / Validate Report) ── */
    .sd-alert-dialog-content {
      position: relative;
      width: 100%;
      max-width: 420px;
      background: #ffffff;
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 12px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.1), 0 4px 6px -4px rgba(15, 23, 42, 0.1);
      font-family: 'Inter', 'Segoe UI', sans-serif;
      animation: modalIn 0.2s ease;
    }

    .sd-alert-dialog-header {
      display: flex;
      flex-direction: column;
      gap: 6px;
      text-align: left;
    }

    .sd-alert-dialog-title {
      margin: 0;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 18px;
      font-weight: 600;
      line-height: 1.2;
      letter-spacing: -0.02em;
      color: #18181b;
    }

    .sd-alert-dialog-description {
      margin: 0;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      line-height: 1.5;
      color: #71717a;
    }

    .sd-alert-dialog-body {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .sd-alert-dialog-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 2px;
    }

    .validation-field-label { display:block;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1.4;color:#18181b; }
    .validation-form-control { font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5; }
    .validation-status-grid { display:grid;grid-template-columns:1fr 1fr;gap:8px; }
    .validation-status-option { display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid #e4e4e7;border-radius:8px;cursor:pointer;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1.4;transition:border-color .15s,background .15s; }
    .validation-status-option:has(input:checked) { border-color:#16a34a;background:#f0fdf4;box-shadow:0 0 0 1px #16a34a; }
    .validation-status-option input { margin-top:2px;accent-color:#15803d;flex:0 0 auto; }
    .validation-status-copy { display:flex;flex-direction:column;gap:3px; }
    .validation-status-copy strong { font-weight:500;color:#18181b; }
    .validation-status-copy span { font-size:12px;font-weight:400;line-height:1.4;color:#71717a; }
    .validation-route-fieldset { border:0;padding:0;margin:0; }
    .validation-route-grid { display:grid;grid-template-columns:1fr;gap:7px; }
    .validation-route-option { display:flex;align-items:flex-start;gap:9px;padding:10px 11px;border:1px solid #e4e4e7;border-radius:8px;cursor:pointer;font-size:13px;line-height:1.4;transition:border-color .15s,background .15s,box-shadow .15s; }
    .validation-route-option:has(input:checked) { border-color:#16a34a;background:#f0fdf4;box-shadow:0 0 0 1px #16a34a; }
    .validation-route-option input { margin:3px 0 0;accent-color:#16a34a;flex:0 0 auto; }
    .validation-route-copy { display:flex;min-width:0;flex:1;flex-direction:column;gap:2px; }
    .validation-route-copy strong { color:#18181b;font-weight:600; }
    .validation-route-copy>span { color:#71717a;font-size:12px;font-weight:400;line-height:1.4; }
    .validation-route-meta { display:flex;flex-wrap:wrap;gap:5px;margin-top:7px; }
    .validation-route-meta span { padding:2px 7px;border:1px solid #e4e4e7;border-radius:999px;background:#fff;color:#52525b;font-size:11px;font-weight:500;line-height:1.35; }
    .validation-risk-check { display:flex;flex-direction:column;gap:3px;padding:10px 12px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;font-size:12px;line-height:1.45; }
    .validation-risk-check.is-high-risk { border-color:#fecaca;background:#fef2f2;color:#991b1b; }
    .validation-risk-title { display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600; }
    .validation-risk-copy { font-weight:400; }
    .validation-upload { position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:120px;padding:18px;border:1px dashed #a1a1aa;border-radius:8px;background:#fafafa;text-align:center;cursor:pointer;transition:border-color .15s,background .15s; }
    .validation-upload:hover,.validation-upload.is-dragging { border-color:#16a34a;background:#f0fdf4; }
    .validation-upload svg { color:#71717a; }
    .validation-upload strong { font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;color:#27272a; }
    .validation-upload-hint,.validation-upload-meta { font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;line-height:1.4;color:#71717a; }
    .validation-file-input { position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer; }
    .validation-notes-meta { display:flex;justify-content:flex-end;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;color:#71717a; }
    .validation-warning { padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;line-height:1.45; }
    @media(max-width:600px) { .validation-status-grid { grid-template-columns:1fr; } }

    .btn-danger {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #dc2626;
      border: 1px solid #dc2626;
      border-radius: 6px;
      padding: 8px 16px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #ffffff;
      cursor: pointer;
      transition: all 0.15s;
    }

    .btn-danger:hover {
      background: #b91c1c;
      border-color: #b91c1c;
    }

    .form-group {
      margin-bottom: 0;
    }

    .form-group:last-child {
      margin-bottom: 0;
    }

    .form-label {
      display: block;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      margin-bottom: 6px;
    }

    .form-control {
      width: 100%;
      padding: 9px 12px;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      color: #18181b;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      background-color: #fff;
    }

    .form-control:focus {
      border-color: #18181b;
      box-shadow: 0 0 0 1px #18181b;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .form-readonly-box {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 14px;
      background: #f4f4f5;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
    }

    .form-readonly-icon {
      font-size: 20px;
      flex-shrink: 0;
    }

    .form-readonly-text {
      display: flex;
      flex-direction: column;
      gap: 1px;
    }

    .form-readonly-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 600;
      color: #18181b;
    }

    .form-readonly-sub {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 400;
      color: #71717a;
    }

    .form-upload-area {
      border: 1px dashed #d4d4d8;
      border-radius: 6px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
    }

    .form-upload-area:hover {
      border-color: #a1a1aa;
      background: #fafafa;
    }

    .form-upload-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
    }

    .modal-footer {
      padding: 0;
      background: none;
      border: none;
      display: flex;
      flex-direction: row-reverse;
      justify-content: flex-end;
      gap: 8px;
    }

    /* Toast Notification */
    .toast-notification {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background-color: #111827;
      color: #FFFFFF;
      padding: 12px 18px;
      border-radius: var(--radius-md);
      font-size: 14px;
      font-weight: 500;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
      display: flex;
      align-items: center;
      gap: 10px;
      z-index: 1100;
      transform: translateY(100px);
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .toast-notification.show {
      transform: translateY(0);
      opacity: 1;
    }

    /* Quick Action Dropdown Menu (same style as All Reports row menu) */
    .quick-dropdown {
      position: absolute;
      background: #ffffff;
      border: 1px solid #e6e9ee;
      border-radius: 10px;
      box-shadow: 0 4px 14px rgba(15, 23, 32, .06);
      padding: 6px;
      z-index: 9999;
      min-width: 190px;
      display: none;
    }

    .quick-dropdown.show {
      display: block;
    }

    .quick-dropdown-item {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      min-height: 36px;
      padding: 9px 10px;
      border: none;
      background: none;
      border-radius: 8px;
      font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
      font-size: 13px;
      font-weight: 500;
      color: #334155;
      cursor: pointer;
      text-align: left;
      line-height: 1.4;
    }

    .quick-dropdown-item:hover {
      background: #f8fafc;
    }

    .quick-dropdown-item svg {
      flex-shrink: 0;
      width: 15px;
      height: 15px;
    }

    /* ==========================================================================
       RESPONSIVE BREAKPOINTS
       ========================================================================== */
    @media (max-width: 1200px) {
      .metric-pipeline-container {
        grid-template-columns: repeat(3, 1fr);
      }
      .dashboard-grid {
        grid-template-columns: 1fr;
      }
      .grid-right {
        display: grid;
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 992px) {
      .topbar {
        padding: 0 20px;
      }
      .dashboard-content {
        padding: 20px 20px 40px;
      }
      .bottom-cards-row {
        grid-template-columns: 1fr;
      }
      .grid-right {
        grid-template-columns: 1fr;
      }
      .search-container {
        width: 220px;
      }
      .header-divider {
        display: none;
      }
    }

    @media (max-width: 768px) {
      .metric-pipeline-container {
        grid-template-columns: repeat(2, 1fr);
      }
      .brand-text {
        display: none;
      }
      .topbar {
        padding: 0 16px;
      }
      .dashboard-content {
        padding: 16px;
      }
      .action-bar {
        flex-direction: column;
        align-items: flex-start;
      }
      .action-buttons-group {
        width: 100%;
        justify-content: flex-end;
      }
    }

    @media (max-width: 640px) {
      .barangay-selector {
        display: none;
      }
      .search-container {
        display: none;
      }
      .user-meta {
        display: none;
      }
    }

    @media (max-width: 480px) {
      .metric-pipeline-container {
        grid-template-columns: 1fr;
      }
      .cat-chart-stats { grid-template-columns: 1fr 1fr; }
      .cat-chart-stat { padding: 8px; font-size: 11px; }
    }


  </style>
  <link rel="stylesheet" href="assets/css/notification.css?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/notification.css'); ?>" />
</head>
<body>

<?php include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>

<div class="app-wrapper">

  <!-- ==========================================================================
       TOP NAVIGATION BAR
       ========================================================================== -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
      </button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="breadcrumb-current" id="breadcrumbCurrent">Overview</span>
      </div>
    </div>

    <div class="topbar-right">
      <!-- Notification Bell -->
      <div class="notif-dropdown" id="notifDropdown">
        <button class="notif-btn" id="notif-btn" type="button" title="<?php echo $unreadNotifs; ?> unread notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
          data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
          data-action-url="includes/staff-notification-action.php?portal=barangay"
          data-load-url="get-notifications.php?limit=30"
          data-app-base-url="">
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
          <div class="notif-panel-body" id="notifList">
            <div class="notif-empty">Loading notifications…</div>
          </div>
        </div>
      </div>

      <!-- User Profile Pill -->
      <div class="user-profile" id="user-profile-btn" title="View Profile">
        <div class="user-avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          <?php endif; ?>
        </div>
        <div class="user-meta">
          <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
          <span class="user-role">Barangay Officer</span>
        </div>
      </div>
    </div>
  </header>

  <!-- ==========================================================================
       MAIN DASHBOARD VIEW CONTAINER (Pure White Page)
       ========================================================================== -->
  <main class="dashboard-content">

    <!-- Action & Date Range Controls -->
    <section class="action-bar">
      <div class="action-buttons-group">
        <button class="btn-secondary" id="export-btn" title="Export report data">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          <span>Export</span>
        </button>

        <button class="btn-primary" id="open-create-report-modal">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          <span>Create report</span>
        </button>
      </div>
    </section>

    <!-- Metric KPI Cards (5 Individual Containers) -->
    <section class="metric-pipeline-container" id="pipeline-steps">
      <!-- 1. New -->
      <div class="pipeline-step">
        <div class="step-icon-box new">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="12" y1="18" x2="12" y2="12"/>
            <line x1="9" y1="15" x2="15" y2="15"/>
          </svg>
        </div>
        <div class="step-content">
          <span class="step-label">Pending DCVS Review</span>
          <span class="step-value" id="kpi-new-val"><?php echo $kpi['new']; ?></span>
          <span class="step-trend">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="18 15 12 9 6 15" transform="rotate(180 12 12)"/>
            </svg>
            <span id="trend-new-val"><?php echo $kpiTrends['new']['dir'] === 'up' ? '↑' : '↓'; ?> <?php echo $kpiTrends['new']['pct']; ?>% vs last month</span>
          </span>
        </div>
      </div>

      <!-- 2. For validation -->
      <div class="pipeline-step">
        <div class="step-icon-box validation">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
            <path d="M9 14h6"/>
            <path d="M9 18h6"/>
            <path d="M9 10h6"/>
          </svg>
        </div>
        <div class="step-content">
          <span class="step-label">Pending Barangay Review</span>
          <span class="step-value" id="kpi-validation-val"><?php echo $kpi['validation']; ?></span>
          <span class="step-trend">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="18 15 12 9 6 15" transform="rotate(180 12 12)"/>
            </svg>
            <span id="trend-validation-val"><?php echo $kpiTrends['validation']['dir'] === 'up' ? '↑' : '↓'; ?> <?php echo $kpiTrends['validation']['pct']; ?>% vs last month</span>
          </span>
        </div>
      </div>

      <!-- 3. In progress -->
      <div class="pipeline-step">
        <div class="step-icon-box progress">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
        </div>
        <div class="step-content">
          <span class="step-label">In progress</span>
          <span class="step-value" id="kpi-progress-val"><?php echo $kpi['progress']; ?></span>
          <span class="step-trend">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="18 15 12 9 6 15" transform="rotate(180 12 12)"/>
            </svg>
            <span id="trend-progress-val"><?php echo $kpiTrends['progress']['dir'] === 'up' ? '↑' : '↓'; ?> <?php echo $kpiTrends['progress']['pct']; ?>% vs last month</span>
          </span>
        </div>
      </div>

      <!-- 4. Resolved -->
      <div class="pipeline-step">
        <div class="step-icon-box resolved">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
        </div>
        <div class="step-content">
          <span class="step-label">Resolved</span>
          <span class="step-value" id="kpi-resolved-val"><?php echo $kpi['resolved']; ?></span>
          <span class="step-trend">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="18 15 12 9 6 15" transform="rotate(180 12 12)"/>
            </svg>
            <span id="trend-resolved-val"><?php echo $kpiTrends['resolved']['dir'] === 'up' ? '↑' : '↓'; ?> <?php echo $kpiTrends['resolved']['pct']; ?>% vs last month</span>
          </span>
        </div>
      </div>

    </section>

    <!-- Reports requiring attention (full width) -->
    <div class="dashboard-card" id="reports" style="margin-bottom:24px;">
      <div class="card-header">
        <div class="card-title-wrap">
          <h2 class="card-title">Reports Requiring Attention</h2>
          <span class="count-badge" id="table-row-count-badge">8</span>
        </div>
      </div>

      <div class="table-container">
        <table class="data-table" id="reports-table">
          <thead>
            <tr>
              <th>Report ID</th>
              <th>Category</th>
              <th>Location</th>
              <th>Reporter</th>
              <th>Incident Date</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Date Resolved</th>
              <th style="width: 36px;"></th>
            </tr>
          </thead>
          <tbody id="reports-table-body">
            <!-- Dynamic rows rendered via JS -->
          </tbody>
        </table>
      </div>
    </div>

    <!-- Main Dashboard 2-Column Grid -->
    <div class="dashboard-grid">

      <!-- ====================================================================
           LEFT COLUMN (Monthly Chart)
           ==================================================================== -->
      <div class="grid-left">

        <!-- Card: Monthly Reports vs. Resolved Cases -->
        <div class="dashboard-card comparison-chart-card">
          <div class="card-header">
            <h2 class="card-title">Monthly Reports vs. Resolved Cases</h2>
            <div class="chart-range-controls">
              <div class="chart-range" id="cmpRange">
                <button class="chart-range-btn active" data-range="30d" onclick="cmpSetRange('30d', this)">Last 30 Days</button>
                <button class="chart-range-btn" data-range="year" onclick="cmpSetRange('year', this)">Per Year</button>
              </div>
              <div class="chart-range-extra" id="cmpYearWrap" style="display:none;">
                <select id="cmpYearSelect" class="chart-range-select" onchange="cmpSetRange('year', null)">
                  <?php for ($y = date('Y'); $y >= 2021; $y--): ?>
                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="chart-legend">
                <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#18181b;"></span> Monthly Reports</span>
                <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#a1a1aa;"></span> Resolved Cases</span>
              </div>
            </div>
          </div>

          <div class="chart-container">
            <div class="chart-canvas-wrap">
              <canvas id="brgyComparisonChart"></canvas>
            </div>
          </div>
        </div>

      </div>

      <!-- ====================================================================
           RIGHT COLUMN (Reports by Category & Recent Activity)
           ==================================================================== -->
      <div class="grid-right">

        <!-- Card 1: Reports by category -->
        <div class="dashboard-card category-chart-card">
          <div class="card-header no-border">
            <h2 class="card-title">Reports By Category</h2>
            <div class="dropdown-wrap" id="categoryPeriodDropdown">
              <button class="card-action-btn" id="category-period-btn">
                <span id="category-period-label">All time</span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </button>
              <div class="dropdown-menu" id="categoryPeriodMenu">
                <button class="dropdown-item active" data-period="all" onclick="setCategoryPeriod('All time', this)">All time</button>
                <button class="dropdown-item" data-period="30" onclick="setCategoryPeriod('Last 30 Days', this)">Last 30 Days</button>
                <button class="dropdown-item" data-period="7" onclick="setCategoryPeriod('Last 7 Days', this)">Last 7 Days</button>
                <button class="dropdown-item" data-period="today" onclick="setCategoryPeriod('Today', this)">Today</button>
              </div>
            </div>
          </div>

          <div class="cat-chart-container">
            <div class="cat-chart-wrap">
              <canvas id="brgyCategoryChart"></canvas>
              <div class="cat-chart-empty" id="category-empty-state" hidden>No reports in this period.</div>
            </div>
          </div>
        </div>

      </div>

    </div>

      </div>

    </div>

  </main>

</div>

<!-- ==========================================================================
     MODALS & DIALOGS
     ========================================================================== -->

<!-- Create Report Modal -->
<div class="modal-backdrop" id="create-report-modal">
  <div class="modal-dialog">
    <div class="modal-dialog-content">
      <button class="modal-dialog-close" id="close-create-modal-btn">&times;</button>
      <div class="modal-dialog-header">
        <h3 class="modal-dialog-title">Create Incident / Service Report</h3>
        <p class="modal-dialog-description">Fill in the details below to submit a new report.</p>
      </div>
      <form id="create-report-form">
        <div class="modal-dialog-body">

          <!-- 1. Report Source (auto-filled) -->
          <div class="form-group">
            <label class="form-label">Report Source</label>
            <div class="form-readonly-box">
              <div class="form-readonly-text">
                <span class="form-readonly-title">Barangay Officer Report</span>
                <span class="form-readonly-sub">Filed by <?php echo htmlspecialchars($fullName); ?> · Brgy. <?php echo htmlspecialchars($barangayName); ?></span>
              </div>
            </div>
          </div>

          <!-- 2. Report Category -->
          <div class="form-group">
            <label class="form-label" for="rpt-category">Report Category</label>
            <select class="form-control" id="rpt-category" required>
              <option value="" disabled selected>Select category</option>
              <option value="Stray Animal">Stray Animal</option>
              <option value="Rabies">Rabies / Suspected Rabies</option>
              <option value="Injured Animal">Injured Animal</option>
              <option value="Dead Animal">Dead Animal</option>
              <option value="Animal Rescue">Animal Rescue</option>
              <option value="Other Animal Welfare">Other Animal Welfare</option>
            </select>
          </div>

          <!-- 3. Incident Date & Time -->
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="rpt-incident-date">Incident Date</label>
              <input type="date" class="form-control" id="rpt-incident-date" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="rpt-incident-time">Time Observed</label>
              <input type="time" class="form-control" id="rpt-incident-time" required>
            </div>
          </div>

          <!-- 4. Location -->
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="rpt-phase">Phase</label>
              <select class="form-control" id="rpt-phase" required>
                <option value="Phase 1">Phase 1</option>
                <option value="Phase 2">Phase 2</option>
                <option value="Phase 3">Phase 3</option>
                <option value="Phase 4">Phase 4</option>
                <option value="Phase 5">Phase 5</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="rpt-priority">Priority Level</label>
              <select class="form-control" id="rpt-priority">
                <option value="Low">Low Priority</option>
                <option value="Medium" selected>Medium Priority</option>
                <option value="High">High Priority</option>
                <option value="Critical">Critical Priority</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="rpt-landmark">Specific Location / Landmark</label>
            <input type="text" class="form-control" id="rpt-landmark" placeholder="e.g. Near Paliparan I Barangay Hall">
          </div>

          <!-- 6. Incident Description / Officer's Observation -->
          <div class="form-group">
            <label class="form-label" for="rpt-desc">Incident Description / Officer's Observation</label>
            <textarea class="form-control" id="rpt-desc" rows="3" placeholder="Describe what you observed, including the animal's condition, location, and other relevant details..." required></textarea>
          </div>

          <!-- 7. Action Taken -->
          <div class="form-group">
            <label class="form-label" for="rpt-action">Initial Action Taken</label>
            <textarea class="form-control" id="rpt-action" rows="2" placeholder="e.g. Coordinated with barangay personnel and secured the area while waiting for veterinary assistance."></textarea>
          </div>

          <!-- 7. Photo / Evidence -->
          <div class="form-group">
            <label class="form-label">Incident Photo</label>
            <div class="form-upload-area" id="rpt-upload-area">
              <input type="file" id="rpt-photos" accept="image/*" style="display:none;">
              <div class="form-upload-placeholder">
                <span style="font-size:13px; color:#71717a;">Click to upload incident photo</span>
                <span style="font-size:12px; color:#a1a1aa; margin-top:4px;">Optional</span>
              </div>
            </div>
          </div>

        </div>
        <div class="modal-dialog-footer">
          <button type="submit" class="btn-primary">Submit Report</button>
          <button type="button" class="btn-secondary" id="cancel-create-modal-btn">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Validate Report AlertDialog (shadcn/ui) -->
<div class="modal-backdrop" id="validate-alert-dialog">
  <div class="modal-dialog">
    <form class="sd-alert-dialog-content" id="dashboard-validation-form" role="dialog" aria-modal="true" aria-labelledby="validate-alert-title" aria-describedby="validate-alert-desc" enctype="multipart/form-data" style="max-width:560px;max-height:90vh;overflow-y:auto;">
      <input type="hidden" name="report_id" value="">
      <input type="hidden" name="action" value="validate">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="validate-alert-title">Validate Report</h3>
        <p class="sd-alert-dialog-description" id="validate-alert-desc">Record what you found on site. If the incident has not been visited yet, select Site visit pending.</p>
        </div>
        <div class="sd-alert-dialog-body">
          <div id="dashboard-validation-context" style="padding:10px 12px;border:1px solid #e4e4e7;border-radius:8px;background:#f8fafc;color:#334155;font-size:13px;"></div>
          <fieldset style="border:0;padding:0;margin:0;">
            <legend class="validation-field-label" style="margin-bottom:8px;">Field status <span style="color:#dc2626;" aria-hidden="true">*</span></legend>
            <div class="validation-status-grid">
              <label class="validation-status-option"><input type="radio" name="field_status" value="site_visit_pending" required checked><span class="validation-status-copy"><strong>Site visit pending</strong><span>The incident has not been checked on site yet.</span></span></label>
              <label class="validation-status-option"><input type="radio" name="field_status" value="confirmed_on_site" required><span class="validation-status-copy"><strong>Confirmed on site</strong><span>Animal and location were verified.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="urgent_response"><span class="validation-status-copy"><strong>Needs urgent response</strong><span>Immediate assistance is needed.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="animal_already_gone"><span class="validation-status-copy"><strong>Animal already gone</strong><span>Animal was no longer at the location.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="wrong_or_unclear_location"><span class="validation-status-copy"><strong>Wrong / unclear location</strong><span>Could not verify the reported location.</span></span></label>
          </div>
        </fieldset>
        <fieldset class="validation-route-fieldset">
          <legend class="validation-field-label" style="margin-bottom:8px;">Who will handle this case? <span style="color:#dc2626;" aria-hidden="true">*</span></legend>
          <div class="validation-route-grid">
            <label class="validation-route-option">
              <input type="radio" name="barangay_will_respond" value="0" required>
              <span class="validation-route-copy"><strong>Forward to DCVS</strong><span>DCVS reviews the report and assigns its own personnel.</span></span>
            </label>
            <?php if ($dashboardResponseEnabled): ?>
            <label class="validation-route-option">
              <input type="radio" name="barangay_will_respond" value="1" required>
              <span class="validation-route-copy">
                <strong>Barangay will respond</strong>
                <span>Your barangay is assigned now. Start the response when the team is heading to the incident. No DCVS approval needed.</span>
                <span class="validation-route-meta">
                  <span>First response within <?php echo $responseTargetHours; ?> hours</span>
                  <span>Progress update every <?php echo $progressTargetHours; ?> hours</span>
                </span>
              </span>
            </label>
            <?php endif; ?>
          </div>
        </fieldset>
        <div class="validation-risk-check" id="dashboard-validation-risk" role="status" aria-live="polite">
          <strong class="validation-risk-title"><span aria-hidden="true" id="dashboard-validation-risk-icon">&#10003;</span><span id="dashboard-validation-risk-title">No high-risk flags</span></strong>
          <span class="validation-risk-copy" id="dashboard-validation-risk-copy">Review the report details before selecting who will handle the case.</span>
        </div>
        <label class="validation-field-label">Review notes / Message to DCVS <span style="color:#dc2626;" aria-hidden="true">*</span>
          <textarea class="sd-input validation-form-control" name="validation_notes" rows="4" maxlength="500" required placeholder="Add review notes or describe your on-site findings..." style="height:auto;padding:10px 12px;margin-top:7px;resize:vertical;"></textarea>
          <span class="validation-notes-meta"><span id="dashboard-validation-notes-count">0</span>/500</span>
        </label>
        <label class="validation-field-label">Photo / Video evidence
          <span class="validation-upload" id="dashboard-validation-upload-zone">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
            <strong>Drop photos or videos here, or click to browse</strong>
            <span class="validation-upload-hint">JPG, PNG, or MP4 · Up to 6 files · 25 MB each</span>
            <span class="validation-upload-meta" id="dashboard-validation-upload-meta">No files selected</span>
            <input class="validation-file-input" id="dashboard-validation-attachments" name="validation_attachments[]" type="file" accept="image/jpeg,image/png,video/mp4" multiple aria-label="Choose photo or video evidence">
          </span>
        </label>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="btn-secondary" id="validate-alert-cancel">Cancel</button>
        <button type="submit" class="btn-primary" id="validate-alert-confirm">Validate &amp; Forward to DCVS</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Report AlertDialog (shadcn/ui) -->
<div class="modal-backdrop" id="reject-alert-dialog">
  <div class="modal-dialog">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="reject-alert-title" aria-describedby="reject-alert-desc">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="reject-alert-title">Reject Report</h3>
        <p class="sd-alert-dialog-description" id="reject-alert-desc">Are you sure you want to reject this report? This action will update the report status.</p>
      </div>
      <div class="sd-alert-dialog-body">
        <label class="form-label" for="reject-reason-input">Rejection Reason</label>
        <textarea class="form-control" id="reject-reason-input" rows="3" placeholder="State the reason for rejecting this report..."></textarea>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="btn-secondary" id="reject-alert-cancel">Cancel</button>
        <button type="button" class="btn-danger" id="reject-alert-confirm">Reject Report</button>
      </div>
    </div>
  </div>
</div>

<!-- Quick Action Dropdown Menu for Table Actions (⋮) -->
<div class="quick-dropdown" id="quick-action-dropdown">
  <button class="quick-dropdown-item" data-action="view">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
    <span>View Details</span>
  </button>
  <button class="quick-dropdown-item" data-action="location">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
    <span>View Location</span>
  </button>
</div>

<!-- Toast notification box -->
<div class="toast-notification" id="toast-msg">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="toast-text">Report updated successfully.</span>
</div>

<!-- ==========================================================================
     INTERACTIVE JAVASCRIPT & DATA BINDINGS
     ========================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/**
 * BARANGAY INCIDENT & OPERATIONS DASHBOARD DATA STORE
 */
const DASHBOARD_CONFIG = <?php echo json_encode($dashboardConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// Global App State
let activeFilter = null;
let currentSelectedReport = null;

// Helper: Get Icon SVG for Categories
function getCategoryIconSvg(type) {
  switch (type) {
    case 'paw':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="4" cy="8" r="2"/><path d="M12 18c-3 0-5-1.5-5-4.5 0-2.5 2-4.5 5-4.5s5 2 5 4.5c0 3-2 4.5-5 4.5z"/></svg>`;
    case 'light':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-1 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/></svg>`;
    case 'trash':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>`;
    case 'volume':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>`;
    case 'road':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 2v4"/><path d="M12 18v4"/></svg>`;
    case 'rabies':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.9 4.9l2.8 2.8"/><path d="M16.3 16.3l2.8 2.8"/><path d="M4.9 19.1l2.8-2.8"/><path d="M16.3 7.7l2.8-2.8"/></svg>`;
    case 'injured':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>`;
    case 'dead':
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
    default:
      return `<svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>`;
  }
}

// Render Reports Table
function renderReportsTable(filterStatus = null, searchQuery = '') {
  const tbody = document.getElementById('reports-table-body');
  const countBadge = document.getElementById('table-row-count-badge');
  tbody.innerHTML = '';

  let filtered = DASHBOARD_CONFIG.reports;

  if (filterStatus) {
    filtered = filtered.filter(r => {
      const status = (r._db_status || r.status).toLowerCase();
      const urgency = (r.urgency || '').toLowerCase();
      if (filterStatus.toLowerCase() === 'pending') {
        return status === 'pending' || urgency === 'high' || urgency === 'critical';
      }
      return status === filterStatus.toLowerCase();
    });
  }

  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    filtered = filtered.filter(r => 
      r.id.toLowerCase().includes(q) ||
      r.category.toLowerCase().includes(q) ||
      r.location.toLowerCase().includes(q) ||
      (r.dateResolved || '').toLowerCase().includes(q) ||
      r.priority.toLowerCase().includes(q)
    );
  }

  countBadge.textContent = filtered.length;

  if (filtered.length === 0) {
    const emptyMsg = (filterStatus && filterStatus.toLowerCase() === 'pending') ? 'No pending or high-priority reports.' : 'No reports found matching your criteria.';
    tbody.innerHTML = `
      <tr>
        <td colspan="9" style="text-align: center; padding: 32px; color: var(--text-muted);">
          ${emptyMsg}
        </td>
      </tr>
    `;
    return;
  }

  filtered.forEach(report => {
    const tr = document.createElement('tr');

    // Priority class
    const pLow = report.priority.toLowerCase();
    const priorityClass = (pLow === 'high' || pLow === 'critical') ? 'priority-high' : (pLow === 'medium' ? 'priority-medium' : 'priority-low');

    // Keep the barangay-review and DCVS-review stages distinct.
    const rawStatus = (report._db_status || '').toLowerCase();
    const sLow = report.status.toLowerCase();
    let statusClass = 'status-pending';
    if (rawStatus === 'validated' || (!rawStatus && sLow.includes('confirmation'))) statusClass = 'status-validation';
    else if (rawStatus === 'confirmed' || rawStatus === 'scheduled' || (!rawStatus && sLow.includes('progress'))) statusClass = 'status-progress';
    else if (rawStatus === 'resolved' || (!rawStatus && sLow.includes('resolved'))) statusClass = 'status-resolved';
    else if (rawStatus === 'rejected' || (!rawStatus && sLow.includes('closed'))) statusClass = 'status-closed';

    const dateResolved = report.dateResolved || '-';
    const resolvedMuted = dateResolved === '-' ? ' style="color:#a1a1aa;"' : '';

    tr.innerHTML = `
      <td>
        <div class="report-id-cell">
          <span>${report.id}</span>
        </div>
      </td>
      <td class="category-cell">${report.category}</td>
      <td class="location-cell">${report.location}</td>
      <td class="reporter-cell">${report.reporter || ''}</td>
      <td class="submitted-cell">${report.incidentDate || report.submittedDate || ''}</td>
      <td>
        <span class="status-badge ${priorityClass}">${report.priority}</span>
      </td>
      <td>
        <span class="status-badge ${statusClass}">${report.status}</span>
      </td>
      <td class="date-resolved-cell"${resolvedMuted}>${dateResolved}</td>
      <td style="text-align: right;">
        <button class="sd-row-actions action-dots-btn" data-id="${report.id}" title="More options">
          <span class="sr-only">Open menu</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
        </button>
      </td>
    `;

    tbody.appendChild(tr);
  });
}

// Sidebar report filter (All / Pending Barangay Review / Resolved Cases)
function setReportFilter(status) {
  activeFilter = (status === 'all' || !status) ? 'all' : status;
  renderReportsTable(activeFilter === 'all' ? null : activeFilter);
}

function updateBarangayReportStatus(report, status) {
  if (!report) return;
  var previousStatus = report._db_status || 'pending';
  if (status === 'confirmed' && report.responseMode === 'barangay_response') {
    report.responseState = 'barangay_response_approved';
  }
  var display = status === 'confirmed' && report.responseState === 'barangay_response_approved'
    ? (report.fieldStatus === 'site_visit_pending'
      ? 'Accepted by Barangay · Site visit pending'
      : 'Accepted by Barangay')
    : ({ validated: 'Pending DCVS Review', confirmed: 'In progress', scheduled: 'In progress', resolved: 'Resolved', rejected: 'Closed' }[status] || (status === 'pending' ? 'Pending Barangay Review' : status));
  report._db_status = status;
  report.status = display;
  if (previousStatus === 'pending') DASHBOARD_CONFIG.metrics.validation = Math.max(0, DASHBOARD_CONFIG.metrics.validation - 1);
  if (status === 'validated') DASHBOARD_CONFIG.metrics.new++;
  if (status === 'rejected') DASHBOARD_CONFIG.metrics.closed++;
  var newCount = document.getElementById('kpi-new-val');
  var validationCount = document.getElementById('kpi-validation-val');
  if (newCount) newCount.textContent = DASHBOARD_CONFIG.metrics.new;
  if (validationCount) validationCount.textContent = DASHBOARD_CONFIG.metrics.validation;
  renderReportsTable(activeFilter === 'all' ? null : (activeFilter || 'pending'));
}

/**
 * Reports by category — horizontal bar chart (recharts "Bar Chart - Custom Label" style)
 */
var brgyBarLabelPlugin = {
  id: 'brgyBarLabel',
  afterDatasetsDraw: function(chart) {
    var ctx = chart.ctx;
    var meta = chart.getDatasetMeta(0);
    var ds = chart.data.datasets[0];
    var labels = chart.data.labels;
    if (!meta || !meta.data || !ds) return;
    var total = ds.data.reduce(function(sum, count) { return sum + Number(count || 0); }, 0);
    ctx.save();
    ctx.textBaseline = 'middle';
    meta.data.forEach(function(bar, i) {
      var count = Number(ds.data[i] || 0);
      if (count <= 0) return;
      var left = bar.base;   // start of bar (value axis 0)
      var right = bar.x;     // end of bar (data value)
      var y = bar.y;         // center of the bar row
      ctx.fillStyle = '#ffffff';
      ctx.font = '600 12px Inter, sans-serif';
      ctx.textAlign = 'left';
      ctx.fillText(labels[i], left + 12, y);
      ctx.fillStyle = '#18181b';
      ctx.font = '500 12px Inter, sans-serif';
      ctx.fillText(count.toLocaleString() + ' (' + Math.round((count / total) * 100) + '%)', right + 10, y);
    });
    ctx.restore();
  }
};

function renderCategoryChart() {
  var el = document.getElementById('brgyCategoryChart');
  if (!el || typeof Chart === 'undefined') return;

  var periods = DASHBOARD_CONFIG.categoryPeriods || {};
  var catData = periods[catCurrentPeriod] || periods.all || [];
  var labels = catData.map(function(d) { return d.label; });
  var values = catData.map(function(d) { return d.count; });
  var scaleMaximum = Math.max(20, values.reduce(function(max, count) {
    return Math.max(max, Number(count) || 0);
  }, 0));
  var total = values.reduce(function(sum, count) { return sum + Number(count || 0); }, 0);
  var rankedCategories = catData.filter(function(d) { return d.count > 0; }).slice().sort(function(a, b) { return b.count - a.count; });
  var summary = ((DASHBOARD_CONFIG.categorySummary || {})[catCurrentPeriod]) || {};
  var comparisonLabel = { '30': 'previous 30 days', '7': 'previous 7 days', today: 'yesterday' }[catCurrentPeriod] || '';
  var criticalCount = catData.reduce(function(sum, item) {
    return ['rabies', 'dead'].indexOf(String(item.label).toLowerCase()) !== -1 ? sum + Number(item.count || 0) : sum;
  }, 0);
  var topCategory = rankedCategories[0];
  var totalStat = document.getElementById('category-total-stat');
  var topStat = document.getElementById('category-top-stat');
  var trendStat = document.getElementById('category-trend-stat');
  var criticalStat = document.getElementById('category-critical-stat');
  var unresolvedStat = document.getElementById('category-unresolved-stat');
  var shareBar = document.getElementById('category-share-bar');
  var emptyState = document.getElementById('category-empty-state');
  var noReports = total === 0;

  if (totalStat) totalStat.textContent = total.toLocaleString() + (total === 1 ? ' report' : ' reports');
  if (topStat) {
    topStat.textContent = topCategory
      ? topCategory.label + ' · ' + Math.round((topCategory.count / total) * 100) + '%'
      : '—';
  }
  if (trendStat) {
    if (!comparisonLabel || summary.previousTotal === null || summary.previousTotal === undefined) {
      trendStat.textContent = 'Not available for all time';
    } else {
      var trendDelta = total - Number(summary.previousTotal || 0);
      trendStat.textContent = trendDelta > 0
        ? '↑ ' + trendDelta.toLocaleString() + ' vs ' + comparisonLabel
        : trendDelta < 0
          ? '↓ ' + Math.abs(trendDelta).toLocaleString() + ' vs ' + comparisonLabel
          : 'No change vs ' + comparisonLabel;
    }
  }
  if (criticalStat) {
    criticalStat.textContent = criticalCount.toLocaleString() + (criticalCount === 1 ? ' report' : ' reports')
      + ' (' + (total > 0 ? Math.round((criticalCount / total) * 100) : 0) + '%)';
  }
  if (unresolvedStat) {
    var unresolved = Number(summary.unresolved || 0);
    unresolvedStat.textContent = unresolved.toLocaleString() + (unresolved === 1 ? ' report' : ' reports');
  }
  if (shareBar) {
    shareBar.replaceChildren();
    catData.forEach(function(item, index) {
      var count = Number(item.count || 0);
      if (count <= 0 || total <= 0) return;
      var segment = document.createElement('span');
      segment.className = 'cat-chart-share-segment';
      segment.style.width = ((count / total) * 100) + '%';
      segment.style.backgroundColor = ['#0E8131', '#DC2626', '#F79009', '#475569'][index % 4];
      segment.title = item.label + ': ' + count + ' (' + Math.round((count / total) * 100) + '%)';
      shareBar.appendChild(segment);
    });
    shareBar.setAttribute('aria-label', noReports ? 'No reports in this period' : 'Category distribution for ' + total + ' reports');
    shareBar.hidden = noReports;
  }
  if (emptyState) emptyState.hidden = !noReports;
  el.style.visibility = noReports ? 'hidden' : 'visible';

  if (brgyCharts['brgyCategoryChart']) {
    var chart = brgyCharts['brgyCategoryChart'];
    chart.data.labels = labels;
    chart.data.datasets[0].data = values;
    chart.options.scales.x.max = scaleMaximum;
    chart.update();
    return;
  }

  brgyCharts['brgyCategoryChart'] = new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Reports',
        data: values,
        backgroundColor: ['#0E8131', '#DC2626', '#F79009', '#475569'],
        borderRadius: 6,
        barThickness: 28,
        maxBarThickness: 28,
        barPercentage: 0.55,
        categoryPercentage: 1
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      layout: {
        padding: { right: 88, top: 8, bottom: 8 }
      },
      scales: {
        x: {
          min: 0,
          max: scaleMaximum,
          grid: { color: 'rgba(0,0,0,0.06)', drawBorder: false },
          border: { display: false },
          ticks: { display: false }
        },
        y: { display: false }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#18181b',
          titleColor: '#f0f0f0',
          bodyColor: '#f0f0f0',
          titleFont: { family: 'Inter', size: 11 },
          bodyFont: { family: 'Inter', size: 12, weight: '700' },
          displayColors: false,
          padding: 10,
          callbacks: {
            label: function(ctx) {
              return ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.x).toLocaleString();
            }
          }
        }
      }
    },
    plugins: [brgyBarLabelPlugin]
  });
}

// Render Redesigned Activity Feed with Left Date & Ring Nodes
function renderActivities() {
  const container = document.getElementById('activity-feed-container');
  container.innerHTML = '';

  if (!DASHBOARD_CONFIG.activities || DASHBOARD_CONFIG.activities.length === 0) {
    container.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-muted); font-size:14px;">No recent activity yet.</div>';
    return;
  }

  DASHBOARD_CONFIG.activities.forEach(act => {
    const item = document.createElement('div');
    item.className = 'activity-feed-item';
    
    // Ring Color class
    const ringColor = act.color || 'teal';

    item.innerHTML = `
      <div class="activity-date-col">
        <span class="activity-date-day">${act.day || '16'}</span>
        <span class="activity-date-month">${act.month || 'Oct'}</span>
      </div>
      <div class="activity-track-col">
        <div class="activity-ring-node ${ringColor}"></div>
        <div class="activity-timeline-line"></div>
      </div>
      <div class="activity-content">
        <div class="activity-title">
          <strong>${act.reportId}</strong> ${act.action}
        </div>
        <div class="activity-meta-row">
          <span class="activity-desc">${act.detail}</span>
          <span class="activity-time">${act.time}</span>
        </div>
      </div>
    `;
    container.appendChild(item);
  });
}

// Toast Helper
function showToast(message) {
  const toast = document.getElementById('toast-msg');
  const text = document.getElementById('toast-text');
  text.textContent = message;
  toast.classList.add('show');
  setTimeout(() => {
    toast.classList.remove('show');
  }, 3200);
}

/* ── shadcn Alert Dialog: Validate / Reject Report ── */
function openAlertDialog(id) {
  document.getElementById(id).classList.add('open');
}

function closeAlertDialog(id) {
  document.getElementById(id).classList.remove('open');
}

function openValidateAlertDialog() {
  if (!currentSelectedReport) return;
  const form = document.getElementById('dashboard-validation-form');
  form.reset();
  form.elements.namedItem('report_id').value = currentSelectedReport.report_id;
  updateDashboardValidationMeta();
  document.getElementById('dashboard-validation-context').textContent =
    (currentSelectedReport.id || 'Report') + ' · ' +
    (currentSelectedReport.category || currentSelectedReport.type || 'Report') + ' · ' +
    (currentSelectedReport.animalType || 'Animal unspecified') + ' · ' +
    (currentSelectedReport.location || 'Location not provided') + ' · ' +
    (currentSelectedReport.priority || 'Unknown priority');
  const submitButton = document.getElementById('validate-alert-confirm');
  submitButton.disabled = false;
  updateDashboardValidationRoutingChoice();
  openAlertDialog('validate-alert-dialog');
}

function updateDashboardValidationRoutingChoice() {
  const form = document.getElementById('dashboard-validation-form');
  const selection = form && form.querySelector('[name="barangay_will_respond"]:checked');
  const willRespond = !!(selection && selection.value === '1');
  const submitButton = document.getElementById('validate-alert-confirm');
  if (submitButton) submitButton.textContent = willRespond
    ? 'Validate & Assign to Barangay'
    : 'Validate & Forward to DCVS';
  updateDashboardValidationRisk(willRespond);
}

function updateDashboardValidationRisk(willRespond) {
  const panel = document.getElementById('dashboard-validation-risk');
  const title = document.getElementById('dashboard-validation-risk-title');
  const icon = document.getElementById('dashboard-validation-risk-icon');
  const copy = document.getElementById('dashboard-validation-risk-copy');
  if (!panel || !title || !icon || !copy) return;
  const report = currentSelectedReport || {};
  const urgency = String(report.urgency || report.priority || '').toLowerCase();
  const behavior = String(report.animalBehavior || '').toLowerCase();
  const count = String(report.animalCount || '').trim();
  const fieldStatus = document.querySelector('#dashboard-validation-form [name="field_status"]:checked');
  const flags = [];
  if (String(report.type || '').toLowerCase() === 'rabies') flags.push('rabies concern');
  if (urgency === 'high' || urgency === 'critical') flags.push(urgency + ' priority');
  if (/aggress|bit(e|ten)|attack/.test(behavior)) flags.push('reported aggressive or biting behavior');
  if (/^(?:6\+|[6-9]\d*|\d{2,})$/.test(count)) flags.push('large number of animals');
  if (fieldStatus && fieldStatus.value === 'urgent_response') flags.push('field status marked urgent');
  const highRisk = flags.length > 0;
  panel.classList.toggle('is-high-risk', highRisk);
  icon.textContent = highRisk ? '⚠' : '✓';
  title.textContent = highRisk ? 'High-risk flags' : 'No high-risk flags';
  copy.textContent = highRisk
    ? flags.join(' · ') + '. ' + (willRespond
      ? 'Coordinate with DCVS according to protocol. This does not prevent your barangay from responding if it can do so safely.'
      : 'Forward for DCVS review and coordination. Urgent cases should be escalated according to protocol.')
    : (willRespond
      ? 'No high-risk flags detected. Your barangay will be assigned now; start the response when the team is heading out.'
      : 'No risk flags detected from the report. DCVS will review and assign personnel.');
}

function updateDashboardValidationMeta() {
  const notes = document.querySelector('#dashboard-validation-form [name="validation_notes"]');
  const count = document.getElementById('dashboard-validation-notes-count');
  const input = document.getElementById('dashboard-validation-attachments');
  const uploadMeta = document.getElementById('dashboard-validation-upload-meta');
  if (notes && count) count.textContent = String(notes.value.length);
  if (input && uploadMeta) {
    const files = Array.from(input.files || []);
    uploadMeta.textContent = files.length
      ? files.length + ' file(s) selected: ' + files.map(file => file.name).join(', ')
      : 'No files selected';
  }
}

function bindDashboardValidationUpload() {
  const input = document.getElementById('dashboard-validation-attachments');
  const zone = document.getElementById('dashboard-validation-upload-zone');
  const notes = document.querySelector('#dashboard-validation-form [name="validation_notes"]');
  if (notes) notes.addEventListener('input', updateDashboardValidationMeta);
  if (!input || !zone) return;
  input.addEventListener('change', updateDashboardValidationMeta);
  zone.addEventListener('dragover', event => {
    event.preventDefault();
    zone.classList.add('is-dragging');
  });
  zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
  zone.addEventListener('drop', event => {
    event.preventDefault();
    zone.classList.remove('is-dragging');
    if (event.dataTransfer && event.dataTransfer.files) {
      input.files = event.dataTransfer.files;
      updateDashboardValidationMeta();
    }
  });
}

function openRejectAlertDialog() {
  if (!currentSelectedReport) return;
  const reasonInput = document.getElementById('reject-reason-input');
  if (reasonInput) reasonInput.value = '';
  openAlertDialog('reject-alert-dialog');
}

function validateReport() {
  const report = currentSelectedReport;
  if (!report) return;
  const form = document.getElementById('dashboard-validation-form');
  const files = form.elements.namedItem('validation_attachments[]').files;
  if (files.length > 6) { showToast('Attach no more than 6 files.'); return; }
  for (const file of files) {
    if (file.size > 25 * 1024 * 1024) { showToast('Each evidence file must be no larger than 25 MB.'); return; }
  }
  const routing = form.querySelector('[name="barangay_will_respond"]:checked');
  const barangayWillRespond = !!(routing && routing.value === '1');
  const fieldStatus = form.querySelector('[name="field_status"]:checked');
  const submitButton = document.getElementById('validate-alert-confirm');
  const cancelButton = document.getElementById('validate-alert-cancel');
  submitButton.disabled = true;
  cancelButton.disabled = true;
  submitButton.textContent = 'Forwarding...';
  fetch('validate-report.php', {
    method: 'POST',
    body: new FormData(form)
  })
  .then(r => r.text().then(text => {
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error('The server returned an invalid response (HTTP ' + r.status + '). Check the PHP error log.');
    }
    if (!r.ok || !data || !data.success) {
      throw new Error((data && data.message) || ('The request failed (HTTP ' + r.status + ').'));
    }
    return data;
  }))
  .then(data => {
    submitButton.disabled = false;
    cancelButton.disabled = false;
    updateDashboardValidationRoutingChoice();
    closeAlertDialog('validate-alert-dialog');
    report.fieldStatus = fieldStatus ? fieldStatus.value : '';
    if (barangayWillRespond) report.responseMode = 'barangay_response';
    updateBarangayReportStatus(report, barangayWillRespond ? 'confirmed' : 'validated');
    var detailModal = document.getElementById('report-detail-modal');
    if (detailModal) detailModal.classList.remove('open');
    currentSelectedReport = null;
    showToast(barangayWillRespond
      ? 'Report validated and assigned to your barangay. Start the response when the team is heading out.'
      : 'Report validated and forwarded to DCVS.');
  })
  .catch(error => {
    submitButton.disabled = false;
    cancelButton.disabled = false;
    updateDashboardValidationRoutingChoice();
    console.error('Could not forward barangay-validated report:', error);
    showToast(error instanceof TypeError
      ? 'Could not contact the server while forwarding the report. Check your connection and try again.'
      : (error.message || 'The report could not be forwarded.'));
  });
}

function rejectReport() {
  const report = currentSelectedReport;
  if (!report) return;
  const reason = (document.getElementById('reject-reason-input') || {}).value || '';
  if (!reason.trim()) {
    showToast('Please state a rejection reason.');
    return;
  }
  fetch('validate-report.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: report.report_id, action: 'reject', reason: reason })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      updateBarangayReportStatus(report, 'rejected');
      var detailModal = document.getElementById('report-detail-modal');
      if (detailModal) detailModal.classList.remove('open');
      currentSelectedReport = null;
      showToast('Report rejected.');
    } else {
      showToast(data.message || 'Failed to reject report.');
    }
  })
  .catch(() => showToast('Network error. Please try again.'));
}

/**
 * ==========================================================================
 * MONTHLY REPORTS VS. PREVIOUS PERIOD — COMPARISON CHART
 * (same chart style as staff portal "Monthly Adoptions vs. Target")
 * ==========================================================================
 */
var brgyCharts = {};

function brgyHexToRgb(hex) {
  var h = (hex || '').replace('#', '');
  if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
  var n = parseInt(h, 16);
  if (isNaN(n)) return '115,115,115';
  return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
}

function brgyTooltipStyle() {
  return {
    backgroundColor: '#ffffff',
    borderColor: 'rgba(0,0,0,0.1)',
    borderWidth: 1,
    titleColor: '#050505',
    bodyColor: '#1a1a1a',
    titleFont: { family: 'Inter', size: 11 },
    bodyFont: { family: 'Inter', size: 12, weight: '700' },
    displayColors: false,
    padding: 10
  };
}

function brgyBaseOpts() {
  return { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: brgyTooltipStyle() } };
}

function brgyAnimOpts() {
  return {
    duration: 1500,
    easing: 'easeOutQuart',
    onProgress: function(a) {
      a.chart.$brgyProg = a.currentStep / a.numSteps;
      if (a.chart.$brgyProg >= 1) { a.chart.$brgyProg = 1; a.chart.$brgyClipped = false; a.chart.$brgyRevealed = true; }
    },
    onComplete: function(a) { a.chart.$brgyProg = 1; a.chart.$brgyClipped = false; a.chart.$brgyRevealed = true; }
  };
}

var brgyLineDrawPlugin = {
  id: 'brgyLineDraw',
  beforeDatasetsDraw: function(chart) {
    if (chart.config.type !== 'line') return;
    if (chart.$brgyProg === undefined && !chart.$brgyRevealed) chart.$brgyProg = 0;
    if (chart.$brgyRevealed) return;
    var p = chart.$brgyProg;
    if (p === undefined || p >= 1) { chart.$brgyRevealed = true; return; }
    var a = chart.chartArea, ctx = chart.ctx;
    var x = a.left + p * (a.right - a.left);
    ctx.save();
    ctx.beginPath();
    ctx.rect(a.left, a.top, Math.max(0, x - a.left), a.bottom - a.top);
    ctx.clip();
    chart.$brgyClipped = true;
  },
  afterDatasetsDraw: function(chart) {
    if (chart.$brgyClipped) {
      chart.ctx.restore();
      chart.$brgyClipped = false;
    }
  }
};

var brgyCrosshairPlugin = {
  id: 'brgyCrosshair',
  beforeDatasetsDraw: function(chart, args, options) {
    var active = chart.getActiveElements();
    if (!active.length) return;
    var x = chart.scales.x.getPixelForValue(active[0].index);
    var a = chart.chartArea;
    var ctx = chart.ctx;
    ctx.save();
    ctx.strokeStyle = 'rgba(0,0,0,0.3)';
    ctx.lineWidth = 1;
    ctx.setLineDash([]);
    ctx.beginPath();
    ctx.moveTo(x + 0.5, a.top);
    ctx.lineTo(x + 0.5, a.bottom);
    ctx.stroke();
    ctx.restore();
  }
};

var cmpCurrentRange = '30d';

function cmpSetRange(range, btn) {
  cmpCurrentRange = range;
  var wrap = document.getElementById('cmpRange');
  if (wrap) wrap.querySelectorAll('.chart-range-btn').forEach(function(b) { b.classList.remove('active'); });
  if (btn) { btn.classList.add('active'); }
  else if (wrap) { wrap.querySelector('[data-range="' + range + '"]').classList.add('active'); }
  var yearWrap = document.getElementById('cmpYearWrap');
  if (yearWrap) yearWrap.style.display = range === 'year' ? 'flex' : 'none';
  renderCmpChart();
}

function renderCmpChart() {
  var el = document.getElementById('brgyComparisonChart');
  if (!el || typeof Chart === 'undefined') return;

  if (brgyCharts['brgyComparisonChart']) {
    brgyCharts['brgyComparisonChart'].destroy();
    delete brgyCharts['brgyComparisonChart'];
  }

  var cmp = (DASHBOARD_CONFIG.comparisonChart || {});
  var labels, reports, resolved;

  if (cmpCurrentRange === 'year') {
    labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var yearSelect = document.getElementById('cmpYearSelect');
    var yearKey = yearSelect ? yearSelect.value : String(new Date().getFullYear());
    var yearData = (cmp.yearly || {})[yearKey] || { reports: Array(12).fill(0), resolved: Array(12).fill(0) };
    reports = yearData.reports;
    resolved = yearData.resolved;
  } else {
    var dailyData = cmp.daily || {};
    labels = dailyData.labels || [];
    reports = dailyData.reports || [];
    resolved = dailyData.resolved || [];
  }

  var rgb = brgyHexToRgb('#18181b');

  brgyCharts['brgyComparisonChart'] = new Chart(el, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Resolved Cases',
          data: resolved,
          borderColor: '#a1a1aa',
          borderWidth: 1,
          borderDash: [4,4],
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#a1a1aa',
          pointHoverBorderColor: '#a1a1aa',
          pointHoverBorderWidth: 2,
          tension: 0.35,
          fill: false
        },
        {
          label: 'Monthly Reports',
          data: reports,
          borderColor: '#18181b',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#18181b',
          pointHoverBorderColor: '#18181b',
          pointHoverBorderWidth: 2,
          tension: 0.35,
          fill: true,
          backgroundColor: function(c) {
            var ca = c.chart.chartArea;
            if (!ca) return 'rgba(' + rgb + ',0.18)';
            var g = c.chart.ctx.createLinearGradient(0, ca.top, 0, ca.bottom);
            g.addColorStop(0, 'rgba(' + rgb + ',0.3)');
            g.addColorStop(1, 'rgba(' + rgb + ',0)');
            return g;
          }
        }
      ]
    },
    options: Object.assign(brgyBaseOpts(), {
      animation: false,
      interaction: { mode: 'index', intersect: false },
      onHover: function(e, active) { if (!active.length) e.native.target.style.cursor = 'default'; else e.native.target.style.cursor = 'pointer'; },
      scales: {
        y: {
          beginAtZero: true,
          max: 20,
          grid: { color: 'rgba(0,0,0,0.06)' },
          ticks: { color: '#737373', font: { family: 'Inter', size: 10 }, precision: 0 }
        },
        x: {
          grid: { display: false },
          ticks: { color: '#737373', font: { family: 'Inter', size: 10 } }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: Object.assign(brgyTooltipStyle(), {
          intersect: false,
          mode: 'index',
          callbacks: {
            label: function(ctx) {
              return ctx.dataset.label + ': ' + Math.round(ctx.parsed.y).toLocaleString();
            }
          }
        })
      }
    }),
    plugins: [brgyCrosshairPlugin]
  });
}

// Category period dropdown
var catCurrentPeriod = 'all';

function setCategoryPeriod(label, btn) {
  document.getElementById('category-period-label').textContent = label;
  document.querySelectorAll('#categoryPeriodMenu .dropdown-item').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('categoryPeriodMenu').classList.remove('show');
  catCurrentPeriod = btn.getAttribute('data-period');
  renderCategoryChart();
}

document.addEventListener('click', function(e) {
  var wrap = document.getElementById('categoryPeriodDropdown');
  if (!wrap) return;
  var btn = document.getElementById('category-period-btn');
  var menu = document.getElementById('categoryPeriodMenu');
  if (btn.contains(e.target)) {
    menu.classList.toggle('show');
  } else if (!menu.contains(e.target)) {
    menu.classList.remove('show');
  }
});

// Document Ready Initialization
document.addEventListener('DOMContentLoaded', () => {
  // Initial renders
  activeFilter = 'pending';
  renderReportsTable(activeFilter);
  renderCmpChart();
  renderCategoryChart();

  // Modal Controls: Create Report
  const createModal = document.getElementById('create-report-modal');
  const openCreateBtn = document.getElementById('open-create-report-modal');
  const closeCreateBtn = document.getElementById('close-create-modal-btn');
  const cancelCreateBtn = document.getElementById('cancel-create-modal-btn');
  const createForm = document.getElementById('create-report-form');

  if (openCreateBtn && createModal) {
    openCreateBtn.addEventListener('click', () => createModal.classList.add('open'));
  }

  const closeCreateModal = () => {
    if (createModal) createModal.classList.remove('open');
    if (createForm) createForm.reset();
  };

  if (closeCreateBtn) closeCreateBtn.addEventListener('click', closeCreateModal);
  if (cancelCreateBtn) cancelCreateBtn.addEventListener('click', closeCreateModal);

  // Form Submit: New Report
  if (createForm) {
    createForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const newId = `RP-2025-0${518 + DASHBOARD_CONFIG.reports.length}`;
      const category = document.getElementById('rpt-category').value;
      const phase = document.getElementById('rpt-phase').value;
      const landmark = document.getElementById('rpt-landmark').value;
      const priority = document.getElementById('rpt-priority').value;
      const desc = document.getElementById('rpt-desc').value;
      const actionTaken = document.getElementById('rpt-action').value;
      const incidentDate = document.getElementById('rpt-incident-date').value;
      const incidentTime = document.getElementById('rpt-incident-time').value;

      const now = new Date();
      const nowStr = now.toISOString();
      const locationParts = [phase];
      if (landmark) locationParts.push(landmark);

      const newReport = {
        id: newId,
        category: category,
        location: locationParts.join(', '),
        reporter: DASHBOARD_CONFIG.user.name,
        submitted: "Just now",
        submittedDate: now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        submittedTime: now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }),
        _sortDate: nowStr.slice(0, 10),
        _sortTime: nowStr.slice(11, 19),
        priority: priority,
        status: "Pending Barangay Review",
        assignedTo: "Unassigned",
        dateResolved: "-",
        description: desc,
        actionTaken: actionTaken,
        incidentDate: incidentDate,
        incidentTime: incidentTime
      };

      DASHBOARD_CONFIG.reports.unshift(newReport);
      DASHBOARD_CONFIG.metrics.validation += 1;
      document.getElementById('kpi-validation-val').textContent = DASHBOARD_CONFIG.metrics.validation;

      // Add to activity with day and donut ring
      DASHBOARD_CONFIG.activities.unshift({
        id: `act-${Date.now()}`,
        day: String(now.getDate()),
        month: now.toLocaleDateString('en-US', { month: 'short' }),
        color: "teal",
        reportId: newId,
        action: "created",
        detail: `${category} · ${locationParts.join(', ')}`,
        time: "Just now"
      });

      renderReportsTable(activeFilter);
      closeCreateModal();
      showToast(`Report ${newId} created successfully.`);
    });
  }

  // Upload area click handler
  const uploadArea = document.getElementById('rpt-upload-area');
  const uploadInput = document.getElementById('rpt-photos');
  if (uploadArea && uploadInput) {
    uploadArea.addEventListener('click', () => uploadInput.click());
    uploadInput.addEventListener('change', function() {
      if (this.files.length > 0) {
        const name = this.files[0].name;
        uploadArea.querySelector('.form-upload-placeholder').innerHTML =
          '<span style="font-size:13px; color:#18181b; font-weight:500;">📷 ' + name + '</span>' +
          '<span style="font-size:12px; color:#a1a1aa; margin-top:4px;">Click to change photo</span>';
      }
    });
  }

  // Set default incident date to today
  const dateInput = document.getElementById('rpt-incident-date');
  if (dateInput) {
    const today = new Date();
    dateInput.value = today.toISOString().split('T')[0];
  }

  // Alert Dialog Controls: Validate / Reject Report (DB update only after confirmation)
  const validateAlertCancel = document.getElementById('validate-alert-cancel');
  const validateAlertConfirm = document.getElementById('validate-alert-confirm');
  const rejectAlertCancel = document.getElementById('reject-alert-cancel');
  const rejectAlertConfirm = document.getElementById('reject-alert-confirm');

  if (validateAlertCancel) validateAlertCancel.addEventListener('click', () => closeAlertDialog('validate-alert-dialog'));
  if (rejectAlertCancel) rejectAlertCancel.addEventListener('click', () => closeAlertDialog('reject-alert-dialog'));

  const dashboardValidationForm = document.getElementById('dashboard-validation-form');
  if (dashboardValidationForm) dashboardValidationForm.addEventListener('submit', event => {
    event.preventDefault();
    validateReport();
  });
  if (dashboardValidationForm) {
    dashboardValidationForm.querySelectorAll('[name="barangay_will_respond"]').forEach(input => {
      input.addEventListener('change', updateDashboardValidationRoutingChoice);
    });
    dashboardValidationForm.querySelectorAll('[name="field_status"]').forEach(input => {
      input.addEventListener('change', updateDashboardValidationRoutingChoice);
    });
  }
  bindDashboardValidationUpload();

  if (rejectAlertConfirm) {
    rejectAlertConfirm.addEventListener('click', () => {
      const reason = (document.getElementById('reject-reason-input') || {}).value || '';
      if (!reason.trim()) {
        showToast('Please state a rejection reason.');
        return;
      }
      closeAlertDialog('reject-alert-dialog');
      rejectReport();
    });
  }

  // Alert dialogs are modal decisions: dismiss with Escape only
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('reject-alert-dialog').classList.contains('open')) {
      closeAlertDialog('reject-alert-dialog');
    } else if (document.getElementById('validate-alert-dialog').classList.contains('open')) {
      closeAlertDialog('validate-alert-dialog');
    }
  });

  // Quick Action Dropdown Menu (⋮)
  const quickDropdown = document.getElementById('quick-action-dropdown');
  document.addEventListener('click', (e) => {
    const dotsBtn = e.target.closest('.action-dots-btn');
    if (dotsBtn) {
      const reportId = dotsBtn.dataset.id;
      currentSelectedReport = DASHBOARD_CONFIG.reports.find(r => r.id === reportId);
      const rect = dotsBtn.getBoundingClientRect();
      quickDropdown.style.top = `${rect.bottom + window.scrollY + 4}px`;
      quickDropdown.style.left = `${rect.right - 190 + window.scrollX}px`;
      quickDropdown.classList.add('show');
    } else if (!e.target.closest('#quick-action-dropdown')) {
      if (quickDropdown) quickDropdown.classList.remove('show');
    }
  });

  // Handle Quick Dropdown Actions
  document.querySelectorAll('.quick-dropdown-item').forEach(item => {
    item.addEventListener('click', () => {
      if (!currentSelectedReport) return;
      quickDropdown.classList.remove('show');
      if (item.dataset.action === 'view') {
        location.href = 'reports-barangay.php?view=' + encodeURIComponent(currentSelectedReport.report_id);
      } else if (item.dataset.action === 'location') {
        location.href = 'dashboard-barangay-geo.php?report_id=' + encodeURIComponent(currentSelectedReport.report_id);
      }
    });
  });

  // Export Data Action
  const exportBtn = document.getElementById('export-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const csvRows = [
        ["Report ID", "Category", "Location", "Incident Date", "Priority", "Status", "Date Resolved"]
      ];
      DASHBOARD_CONFIG.reports.forEach(r => {
        csvRows.push([r.id, r.category, r.location, r.incidentDate || r.submittedDate, r.priority, r.status, r.dateResolved || '-']);
      });
      const csvContent = "data:text/csv;charset=utf-8," + csvRows.map(e => e.join(",")).join("\n");
      const encodedUri = encodeURI(csvContent);
      const link = document.createElement("a");
      link.setAttribute("href", encodedUri);
      link.setAttribute("download", `Paliparan_Reports_${new Date().toISOString().slice(0,10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      showToast("Reports data exported as CSV file.");
    });
  }
});
</script>

<script>
(function() {
  var mapEl = document.getElementById('geoMap');
  if (!mapEl) return;

  var BRGY_ID = DASHBOARD_CONFIG.barangayId;
  var BRGY_NAME = DASHBOARD_CONFIG.barangayName;
  var fitted = false;

  var refreshBtn = document.getElementById('geoRefreshBtn');

  function setText(id, v) {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  }
  function setHtml(id, v) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = v;
  }

  function bbFromFeature(f) {
    var coords = [];
    var geom = f.geometry;
    if (!geom) return [[120.96,14.30],[120.97,14.31]];
    if (geom.type === 'Polygon') coords = geom.coordinates[0];
    else if (geom.type === 'MultiPolygon') coords = geom.coordinates[0][0];
    var minLng = 180, maxLng = -180, minLat = 90, maxLat = -90;
    for (var i = 0; i < coords.length; i++) {
      if (coords[i][0] < minLng) minLng = coords[i][0];
      if (coords[i][0] > maxLng) maxLng = coords[i][0];
      if (coords[i][1] < minLat) minLat = coords[i][1];
      if (coords[i][1] > maxLat) maxLat = coords[i][1];
    }
    return [[minLng, minLat], [maxLng, maxLat]];
  }

  function fetchGeojson() {
    return fetch('geo-map/barangays-public.geojson.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); });
  }

  function findTarget(features) {
    for (var i = 0; i < features.length; i++) {
      if ((features[i].properties && features[i].properties.id) == BRGY_ID) return features[i];
    }
    return null;
  }

  var drawerRequestId = 0;
  var riskConfig = {
    Green: { dot:'#22c55e', text:'#166534', label:'LOW RISK', desc:'No confirmed cases in this barangay' },
    Yellow: { dot:'#eab308', text:'#854d0e', label:'MEDIUM RISK', desc:'Active cases detected in this barangay' },
    Orange: { dot:'#f97316', text:'#9a3412', label:'HIGH RISK', desc:'Multiple active cases require attention' },
    Red: { dot:'#ef4444', text:'#991b1b', label:'CRITICAL RISK', desc:'Significant outbreak — immediate action needed' }
  };
  var reportTypeLabels = { rabies:'Rabies Reports', rescue:'Rescue Reports', stray:'Stray Reports', injured:'Injured Reports', dead:'Dead Reports' };
  var reportTypeColors = { rabies:'#dc2626', rescue:'#2563eb', stray:'#ea580c', injured:'#d97706', dead:'#6b7280' };

  function escapeGeoText(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch];
    });
  }
  function formatGeoDate(value) {
    if (!value) return '--';
    var date = new Date(String(value).replace(' ', 'T'));
    return isNaN(date.getTime()) ? '--' : date.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
  }
  function formatGeoDateTime(value) {
    if (!value) return '--';
    var date = new Date(String(value).replace(' ', 'T'));
    return isNaN(date.getTime()) ? '--' : date.toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' });
  }

  window.openGeoDrawer = function(barangayId, props) {
    var requestId = ++drawerRequestId;
    var risk = (props && props.risk) || 'Green';
    var riskStyle = riskConfig[risk] || riskConfig.Green;
    document.getElementById('geoDrawerName').textContent = (props && props.name) || BRGY_NAME;
    document.getElementById('geoDrawerRiskDot').style.background = riskStyle.dot;
    var riskLabel = document.getElementById('geoDrawerRiskText');
    riskLabel.textContent = riskStyle.label;
    riskLabel.style.color = riskStyle.text;
    document.getElementById('geoDrawerRiskDesc').textContent = riskStyle.desc;
    document.getElementById('geoDrawerConfirmed').textContent = (props && props.confirmed) || 0;
    document.getElementById('geoDrawerPending').textContent = (props && props.pending) || 0;
    document.getElementById('geoDrawerResolved').textContent = (props && props.resolved) || 0;
    document.getElementById('geoDrawerTotal').textContent = (Number((props && props.confirmed) || 0) + Number((props && props.pending) || 0) + Number((props && props.resolved) || 0));
    document.getElementById('geoDrawerUpdated').textContent = formatGeoDateTime(props && props.lastUpdated);
    document.getElementById('geoDrawerViewAll').href = 'dashboard-barangay.php#reports';
    document.getElementById('geoDrawerBreakdown').innerHTML = '<div style="font-size:13px;color:#a1a1aa;padding:8px 0;">Loading...</div>';
    document.getElementById('geoDrawerRecentWrap').style.display = 'none';
    document.getElementById('geoDrawerNoRecent').style.display = 'none';
    document.getElementById('geoDrawerOverlay').classList.add('open');
    document.getElementById('geoDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('geo-map/barangay-detail.php?barangay_id=' + encodeURIComponent(BRGY_ID) + '&sess=DCVS_BARANGAY_SESSION', { credentials:'same-origin' })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (requestId !== drawerRequestId || !document.getElementById('geoDrawer').classList.contains('open')) return;
        if (data.error) throw new Error(data.error);
        document.getElementById('geoDrawerConfirmed').textContent = data.confirmed || 0;
        document.getElementById('geoDrawerPending').textContent = data.pending || 0;
        document.getElementById('geoDrawerResolved').textContent = data.resolved || 0;
        document.getElementById('geoDrawerTotal').textContent = data.total || 0;
        document.getElementById('geoDrawerUpdated').textContent = formatGeoDateTime(data.last_updated);

        var breakdown = '';
        (data.breakdown || []).forEach(function(item) {
          var type = String(item.type || 'other');
          breakdown += '<div class="geo-activity-row"><span class="geo-activity-name"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' + (reportTypeColors[type] || '#71717a') + ';margin-right:8px;vertical-align:middle;"></span>' + escapeGeoText(reportTypeLabels[type] || (type.charAt(0).toUpperCase() + type.slice(1) + ' Reports')) + '</span><span class="geo-activity-count">' + Number(item.count || 0) + '</span></div>';
        });
        document.getElementById('geoDrawerBreakdown').innerHTML = breakdown || '<div style="font-size:13px;color:#a1a1aa;padding:8px 0;">No reports</div>';

        if (data.recent_case) {
          var recent = data.recent_case;
          var urgency = String(recent.urgency || 'medium').toLowerCase();
          var urgencyStyle = {
            critical:{ bg:'#fef2f2', color:'#991b1b', border:'#fecaca' },
            high:{ bg:'#fff7ed', color:'#9a3412', border:'#fed7aa' },
            medium:{ bg:'#eff6ff', color:'#1e40af', border:'#bfdbfe' },
            low:{ bg:'#f9fafb', color:'#374151', border:'#e5e7eb' }
          }[urgency] || { bg:'#f9fafb', color:'#374151', border:'#e5e7eb' };
          var type = String(recent.type || 'report');
          document.getElementById('geoDrawerRecent').innerHTML =
            '<div class="geo-recent-id">' + escapeGeoText(recent.ref_code || '') + '</div>' +
            '<div class="geo-recent-type">' + escapeGeoText(type === 'rabies' ? 'Suspected Rabies' : type.charAt(0).toUpperCase() + type.slice(1)) + '</div>' +
            '<div class="geo-recent-meta"><span class="geo-recent-urgency" style="background:' + urgencyStyle.bg + ';color:' + urgencyStyle.color + ';border:1px solid ' + urgencyStyle.border + ';">' + escapeGeoText(urgency.charAt(0).toUpperCase() + urgency.slice(1) + ' Risk') + '</span><span class="geo-recent-date">' + escapeGeoText(formatGeoDate(recent.date)) + '</span></div>' +
            '<a class="geo-recent-action" href="reports-barangay.php?view=' + encodeURIComponent(recent.id) + '">View Report <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>';
          document.getElementById('geoDrawerRecentWrap').style.display = 'block';
        } else {
          document.getElementById('geoDrawerNoRecent').style.display = 'block';
        }
      })
      .catch(function(error) {
        if (requestId !== drawerRequestId) return;
        console.error('Barangay geo drawer load failed:', error);
        document.getElementById('geoDrawerBreakdown').innerHTML = '<div style="font-size:13px;color:#dc2626;padding:8px 0;">Failed to load report details</div>';
      });
  };

  window.closeGeoDrawer = function() {
    drawerRequestId++;
    document.getElementById('geoDrawerOverlay').classList.remove('open');
    document.getElementById('geoDrawer').classList.remove('open');
    document.body.style.overflow = '';
  };
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('geoDrawer').classList.contains('open')) window.closeGeoDrawer();
  });

  function setupBrgyOnly(map, geojson) {
    var target = findTarget(geojson.features || []);
    if (!target) return;

    var filtered = { type: 'FeatureCollection', features: [target] };
    var src = map.getSource('barangays');
    if (src) {
      ['barangay-fill','barangay-outline','barangay-label','geo-brgy-label'].forEach(function(lid) {
        if (map.getLayer(lid)) map.removeLayer(lid);
      });
      if (map.getSource('brgy-labels')) {
        if (map.getLayer('geo-brgy-label')) map.removeLayer('geo-brgy-label');
        map.removeSource('brgy-labels');
      }
      map.removeSource('barangays');
    }

    map.addSource('barangays', { type: 'geojson', data: filtered, promoteId: 'id' });

    var c = target.geometry.coordinates;
    var cx = 0, cy = 0, cnt = 0;
    if (target.geometry.type === 'Polygon') {
      c[0].forEach(function(p) { cx += p[0]; cy += p[1]; cnt++; });
    } else if (target.geometry.type === 'MultiPolygon') {
      c.forEach(function(ring) { ring[0].forEach(function(p) { cx += p[0]; cy += p[1]; cnt++; }); });
    }
    map.addSource('brgy-labels', {
      type: 'geojson',
      data: { type: 'FeatureCollection', features: [
        { type: 'Feature', properties: { name: target.properties.name }, geometry: { type: 'Point', coordinates: [cx/cnt, cy/cnt] } }
      ]}
    });

    map.addLayer({ id: 'barangay-fill', type: 'fill', source: 'barangays',
      paint: { 'fill-color': '#22c55e', 'fill-opacity': 0.5 } });
    map.addLayer({ id: 'barangay-outline', type: 'line', source: 'barangays',
      paint: { 'line-color': '#ffffff', 'line-width': 2.5, 'line-opacity': 0.9 } });
    map.addLayer({ id: 'geo-brgy-label', type: 'symbol', source: 'brgy-labels',
      layout: { 'text-field': ['get','name'], 'text-size': 11, 'text-anchor': 'center', 'text-allow-overlap': false },
      paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 2 } });

    var bounds = bbFromFeature(target);
    map.fitBounds(bounds, { padding: 60, maxZoom: 17, duration: 800 });

    var p = target.properties || {};
    setText('geoBgyConfirmed', p.confirmed || 0);
    setText('geoBgyPending', p.pending || 0);
    setText('geoBgyResolved', p.resolved || 0);
    setText('geoBgyUpdated', p.lastUpdated ? new Date(p.lastUpdated).toLocaleString() : '--');

    map.on('mouseenter', 'barangay-fill', function() { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'barangay-fill', function() { map.getCanvas().style.cursor = ''; });
    map.on('click', 'barangay-fill', function(clickEvent) {
      var pinLayers = ['report-pins-points', 'report-pins-clusters'].filter(function(layerId) {
        return !!map.getLayer(layerId);
      });
      if (pinLayers.length && map.queryRenderedFeatures(clickEvent.point, { layers: pinLayers }).length) return;
      window.openGeoDrawer(BRGY_ID, target.properties);
    });

    if (window.MapCN && window.MapCN.barangayOverlay) {
      window.MapCN.barangayOverlay.refresh = function() {
        return fetchGeojson().then(function(data) {
          var t = findTarget(data.features || []);
          if (t) {
            var src2 = map.getSource('barangays');
            if (src2) src2.setData({ type: 'FeatureCollection', features: [t] });
            setText('geoBgyConfirmed', t.properties.confirmed || 0);
            setText('geoBgyPending', t.properties.pending || 0);
            setText('geoBgyResolved', t.properties.resolved || 0);
            setText('geoBgyUpdated', t.properties.lastUpdated ? new Date(t.properties.lastUpdated).toLocaleString() : '--');
          }
        });
      };
    }
    fitted = true;
    mapEl.dispatchEvent(new CustomEvent('dcvs:barangaymapscope', {
      detail: { feature: target }
    }));
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', function() {
      if (refreshBtn.disabled) return;
      var refresh = window.MapCN && window.MapCN.barangayOverlay && window.MapCN.barangayOverlay.refresh;
      if (typeof refresh !== 'function') {
        console.error('Barangay map refresh is unavailable.');
        return;
      }
      refreshBtn.disabled = true;
      refreshBtn.classList.add('is-loading');
      refreshBtn.setAttribute('aria-busy', 'true');
      Promise.resolve(refresh()).catch(function(error) {
        console.error('Failed to refresh barangay map:', error);
      }).finally(function() {
        refreshBtn.disabled = false;
        refreshBtn.classList.remove('is-loading');
        refreshBtn.removeAttribute('aria-busy');
      });
    });
  }

  function waitForSource(map, cb) {
    var tries = 0;
    var iv = setInterval(function() {
      if (map.getSource('barangays') || tries++ > 150) { clearInterval(iv); cb(); }
    }, 200);
  }

  mapEl.addEventListener('mapcn:load', function(e) {
    var map = e.detail && e.detail.map;
    if (!map) return;
    waitForSource(map, function() {
      if (window.MapCN && window.MapCN.barangayOverlay) window.MapCN.barangayOverlay.destroy();
      fetchGeojson().then(function(geojson) { setupBrgyOnly(map, geojson); });
    });
  });
})();
</script>

<script>
// Notifications are managed by assets/js/notification-panel.js.
</script>
<script src="assets/js/notification-panel.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/notification-panel.js'); ?>" defer></script>
</body>
</html>
