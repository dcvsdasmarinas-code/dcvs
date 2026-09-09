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
require_once 'db.php';

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

$userId = $_SESSION['user_id'];
$barangayId = $_SESSION['barangay_id'];
$fullName = $_SESSION['full_name'] ?? 'Barangay Officer';

// Fetch barangay name
$barangayName = 'Unknown Barangay';
$bq = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
$bq->bind_param("i", $barangayId);
$bq->execute();
$br = $bq->get_result();
if ($br && $row = $br->fetch_assoc()) $barangayName = $row['barangay_name'];
$bq->close();

// Handle flash messages
$reportSuccess = $_SESSION['report_success'] ?? null;
$reportError = $_SESSION['report_error'] ?? null;
unset($_SESSION['report_success'], $_SESSION['report_error']);

// Fetch barangay user's own profile data
$barangayEmail = $_SESSION['email'] ?? '';
$barangayPhone = $_SESSION['phone'] ?? '';
$barangayAddress = $_SESSION['address'] ?? '';
$barangayAvatar = $_SESSION['avatar_url'] ?? '';
$pq = $conn->prepare("SELECT email, phone, address, avatar_url FROM users WHERE user_id = ?");
$pq->bind_param("i", $userId);
$pq->execute();
$pr = $pq->get_result();
if ($pr && $row = $pr->fetch_assoc()) {
    $barangayEmail = $row['email'] ?? $barangayEmail;
    $barangayPhone = $row['phone'] ?? $barangayPhone;
    $barangayAddress = $row['address'] ?? $barangayAddress;
    $barangayAvatar = $row['avatar_url'] ?? $barangayAvatar;
}
$pq->close();

// Fetch reports for this barangay (including reporter info)
$reports = [];
$rq = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.reporter_id, r.report_type, r.description,
           r.location_address, r.coordinates_lat, r.coordinates_long, r.barangay_id,
           r.urgency_level, r.photo_url, r.status, r.validated_by, r.validated_at,
           r.confirmed_by, r.confirmed_at, r.created_at, r.updated_at,
           r.barangay_notes, r.rejection_reason,
           u.full_name AS reporter_name, u.phone AS reporter_phone, u.email AS reporter_email,
           u.avatar_url AS reporter_avatar,
           s.full_name AS rejected_by_name,
           cc.case_id
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users s ON r.confirmed_by = s.user_id
    LEFT JOIN confirmed_cases cc ON r.report_id = cc.report_id
    WHERE r.barangay_id = ? AND r.archived_at IS NULL
    ORDER BY r.created_at DESC
");
$rq->bind_param("i", $barangayId);
$rq->execute();
$rs = $rq->get_result();
while ($row = $rs->fetch_assoc()) $reports[] = $row;
$rq->close();

// Stats
$stats = ['pending' => 0, 'validated' => 0, 'rejected' => 0, 'confirmed' => 0, 'resolved' => 0, 'scheduled' => 0];
foreach ($reports as $r) $stats[$r['status']]++;

// ── Brgy KPI Comparison ──
$brgyPeriod = 30;
$brgyKpiCur = $brgyKpiPrev = $brgyKpiPct = $brgyKpiDir = [];
$brgyStatuses = ['pending', 'validated', 'confirmed', 'rejected'];
foreach ($brgyStatuses as $st) {
    $cur = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $cur->bind_param("isi", $barangayId, $st, $brgyPeriod);
    $cur->execute(); $c = (int)$cur->get_result()->fetch_row()[0]; $cur->close();
    $prev = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $pDays = $brgyPeriod * 2;
    $prev->bind_param("isii", $barangayId, $st, $pDays, $brgyPeriod);
    $prev->execute(); $p = (int)$prev->get_result()->fetch_row()[0]; $prev->close();
    $brgyKpiCur[$st] = $c; $brgyKpiPrev[$st] = $p;
    $brgyKpiPct[$st] = $p > 0 ? round(($c - $p) / $p * 100, 1) : ($c > 0 ? 100 : 0);
    $brgyKpiDir[$st] = $c >= $p ? 'up' : 'down';
}

// ── KPI Sparkline series (last 12 months) — placeholder/demo data for now ──
$brgyKpiTrends = [
    'pending'   => [5, 8, 6, 9, 12, 10, 14, 11, 15, 13, 17, 16],
    'validated' => [2, 4, 3, 5, 7, 6, 9, 8, 10, 9, 12, 11],
    'confirmed' => [1, 2, 2, 3, 4, 3, 5, 5, 6, 6, 8, 7],
    'rejected'  => [3, 2, 3, 2, 4, 3, 2, 3, 2, 4, 3, 3],
];
// Placeholder/demo record counts + change % for the KPI cards
$brgyKpiPlaceholder = [
    'pending'   => ['value' => 16, 'pct' => 18.3, 'dir' => 'up'],
    'validated' => ['value' => 11, 'pct' => 4.2, 'dir' => 'up'],
    'confirmed' => ['value' => 7,  'pct' => 12.1, 'dir' => 'up'],
    'rejected'  => ['value' => 3,  'pct' => -6.5, 'dir' => 'down'],
];

// Chart data: monthly report activity (with placeholder data for previous years)
$currentYear = (int)date('Y');
$allYearData = [];

// Current year — real data from DB
$monthlyCounts = array_fill(1, 12, 0);
$mq = $conn->prepare("SELECT MONTH(created_at) AS m, COUNT(*) AS c FROM reports WHERE barangay_id = ? AND YEAR(created_at) = ? AND archived_at IS NULL GROUP BY MONTH(created_at)");
$mq->bind_param("ii", $barangayId, $currentYear);
$mq->execute();
$mr = $mq->get_result();
while ($row = $mr->fetch_assoc()) $monthlyCounts[(int)$row['m']] = (int)$row['c'];
$mq->close();
$allYearData[$currentYear] = array_values($monthlyCounts);

// Placeholder data for previous years (2023–$currentYear-1)
$placeholderSeeds = [
    2023 => [10, 9, 12, 14, 18, 15, 13, 11, 16, 19, 14, 11],
    2024 => [11, 10, 13, 16, 17, 14, 12, 13, 17, 18, 15, 12],
    2025 => [12, 11, 14, 15, 19, 16, 14, 12, 18, 20, 16, 13],
];
$availableYears = [$currentYear];
for ($y = 2023; $y < $currentYear; $y++) {
    if (isset($placeholderSeeds[$y])) {
        $allYearData[$y] = $placeholderSeeds[$y];
        $availableYears[] = $y;
    }
}
sort($availableYears);

// Chart data: reports by category
$categoryCounts = ['stray' => 0, 'rabies' => 0, 'injured' => 0, 'dead' => 0];
$cq = $conn->prepare("SELECT report_type, COUNT(*) AS c FROM reports WHERE barangay_id = ? AND archived_at IS NULL GROUP BY report_type");
$cq->bind_param("i", $barangayId);
$cq->execute();
$cr = $cq->get_result();
while ($row = $cr->fetch_assoc()) {
    $t = strtolower($row['report_type']);
    if (isset($categoryCounts[$t])) $categoryCounts[$t] = (int)$row['c'];
}
$cq->close();

// Fetch notifications for this barangay officer (only active report references)
$notifications = [];
$nq = $conn->prepare("
    SELECT n.* FROM notifications n
    LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
    WHERE n.user_id = ?
      AND NOT (n.type = 'new_report' AND r.report_id IS NOT NULL AND r.status != 'pending')
    ORDER BY n.created_at DESC LIMIT 20");
$nq->bind_param("i", $userId);
$nq->execute();
$nr = $nq->get_result();
while ($row = $nr->fetch_assoc()) $notifications[] = $row;
$nq->close();
$unreadNotifCount = count(array_filter($notifications, fn($n) => !$n['is_read']));
$pageTitle = 'Dashboard';
$activePage = 'overview';

// ── Workflow Stage Counts ──
$wfStages = [
    'new'        => 0,
    'validated'  => 0,
    'in_progress'=> 0,
    'resolved'   => 0,
    'rejected'   => 0
];
foreach ($reports as $r) {
    switch ($r['status']) {
        case 'pending':   $wfStages['new']++; break;
        case 'validated': $wfStages['validated']++; break;
        case 'confirmed':
        case 'scheduled': $wfStages['in_progress']++; break;
        case 'resolved':  $wfStages['resolved']++; break;
        case 'rejected':  $wfStages['rejected']++; break;
    }
}

// ── Workflow Period-over-Period (30 days) ──
$wfPeriod = 30;
$wfChange = [];
$wfStatusMap = [
    'new'        => ['pending'],
    'validated'  => ['validated'],
    'in_progress'=> ['confirmed','scheduled'],
    'resolved'   => ['resolved'],
    'rejected'   => ['rejected']
];
foreach ($wfStatusMap as $stage => $statuses) {
    $curCount = 0; $prevCount = 0;
    foreach ($statuses as $st) {
        $cStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $cStmt->bind_param("isi", $barangayId, $st, $wfPeriod);
        $cStmt->execute(); $curCount += (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();
        $pDays = $wfPeriod * 2;
        $pStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = ? AND archived_at IS NULL AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $pStmt->bind_param("isii", $barangayId, $st, $pDays, $wfPeriod);
        $pStmt->execute(); $prevCount += (int)$pStmt->get_result()->fetch_row()[0]; $pStmt->close();
    }
    $pct = $prevCount > 0 ? round(($curCount - $prevCount) / $prevCount * 100, 1) : ($curCount > 0 ? 100.0 : 0.0);
    $dir = $curCount >= $prevCount ? 'up' : 'down';
    $wfChange[$stage] = ['cur' => $curCount, 'prev' => $prevCount, 'pct' => $pct, 'dir' => $dir];
}

// ── Workload: Validation Due (pending > 24h) ──
$validationDueStmt = $conn->prepare("
    SELECT COUNT(*) FROM reports 
    WHERE barangay_id = ? AND status = 'pending' AND archived_at IS NULL 
    AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$validationDueStmt->bind_param("i", $barangayId);
$validationDueStmt->execute();
$validationDueCount = (int)$validationDueStmt->get_result()->fetch_row()[0];
$validationDueStmt->close();

// ── Workload: Overdue (pending > 48h or validated > 7 days) ──
$overdueStmt = $conn->prepare("
    SELECT COUNT(*) FROM reports 
    WHERE barangay_id = ? AND archived_at IS NULL 
    AND (
        (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR))
        OR 
        (status = 'validated' AND validated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
    )
");
$overdueStmt->bind_param("i", $barangayId);
$overdueStmt->execute();
$overdueCount = (int)$overdueStmt->get_result()->fetch_row()[0];
$overdueStmt->close();

// ── Workload: Validated by current officer ──
$myValidatedStmt = $conn->prepare("
    SELECT COUNT(*) FROM reports 
    WHERE barangay_id = ? AND validated_by = ? AND archived_at IS NULL 
    AND status IN ('validated','confirmed','scheduled')
");
$myValidatedStmt->bind_param("ii", $barangayId, $userId);
$myValidatedStmt->execute();
$myValidatedCount = (int)$myValidatedStmt->get_result()->fetch_row()[0];
$myValidatedStmt->close();

// ── Activity Feed: recent activity_logs touching this barangay's reports ──
$activityFeed = [];
$afStmt = $conn->prepare("
    SELECT al.action, al.details, al.created_at, u.full_name AS actor_name,
           r.reference_id, r.report_type, r.location_address
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN reports r ON al.details LIKE CONCAT('%', r.reference_id, '%')
    WHERE al.user_id IN (
        SELECT user_id FROM users WHERE barangay_id = ?
    )
    AND al.action IN ('Login','Logout','submit_report','Report Validated','Report Rejected','Report Confirmed','Report Scheduled','Report Resolved','validate_report','reject_report','confirm_report','schedule_report','resolve_report')
    ORDER BY al.created_at DESC
    LIMIT 15
");
$afStmt->bind_param("i", $barangayId);
$afStmt->execute();
$afResult = $afStmt->get_result();
while ($row = $afResult->fetch_assoc()) $activityFeed[] = $row;
$afStmt->close();

// ── Submitted vs Resolved monthly (last 6 months) for trend ──
$trendMonths = 6;
$trendSubmitted = [];
$trendResolved = [];
$trendLabels = [];
for ($i = $trendMonths - 1; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $mEnd = date('Y-m-t', strtotime("-$i months"));
    $mLabel = date('M', strtotime("-$i months"));
    $trendLabels[] = $mLabel;

    $sStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND archived_at IS NULL AND DATE(created_at) BETWEEN ? AND ?");
    $sStmt->bind_param("iss", $barangayId, $mStart, $mEnd);
    $sStmt->execute(); $trendSubmitted[] = (int)$sStmt->get_result()->fetch_row()[0]; $sStmt->close();

    $rStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE barangay_id = ? AND status = 'resolved' AND archived_at IS NULL AND DATE(updated_at) BETWEEN ? AND ?");
    $rStmt->bind_param("iss", $barangayId, $mStart, $mEnd);
    $rStmt->execute(); $trendResolved[] = (int)$rStmt->get_result()->fetch_row()[0]; $rStmt->close();
}

// Status display labels
function statusLabel($status) {
    return match($status) {
        'pending' => 'Pending',
        'validated' => 'Validated by Barangay',
        'rejected' => 'Rejected',
        'confirmed' => 'Confirmed by DCVS',
        'scheduled' => 'Scheduled',
        'resolved' => 'Resolved',
        default => ucfirst($status)
    };
}
function statusClass($status) {
    return match($status) {
        'pending' => 'pending',
        'validated' => 'validated',
        'rejected' => 'rejected',
        'confirmed' => 'confirmed',
        'resolved' => 'resolved',
        default => $status
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?> — Barangay | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/responsive.css">
  <!-- MapCN bundle -->
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css">
  <script src="dist/mapcn.umd.cjs"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --bg-body: #f8fafc;
      --bg-card: #ffffff;
      --border: #e2e8f0;
      --text: #0f172a;
      --text-2: #475569;
      --text-3: #94a3b8;
      --shadow: 0 4px 24px rgba(0,0,0,0.06);
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
      --radius: 12px;
      --radius-sm: 8px;
      --green: #0E8131;
      --green-light: #f0fdf4;
    }

    /* â”€â”€ Sidebar: match Staff green gradient â”€â”€ */
    .sidebar { background: linear-gradient(180deg, #0b1a0f, #0a3015, #065019) !important; }
    .sidebar-brand-name,
    .sidebar-brand-sub,
    .sidebar-profile-name,
    .nav-link,
    .nav-section-label { color: #fff !important; }
    .sidebar-profile-barangay i,
    .sidebar-profile-contact i { color: #94a3b8 !important; }
    .sidebar-links .nav-link { color: #cbd5e1 !important; }
    .sidebar-links .nav-link:hover { background: rgba(255,255,255,0.08) !important; color: #fff !important; border: none !important; }
    .sidebar-links .nav-link.active { background: rgba(14,129,49,0.45) !important; border: 1px solid rgba(74,222,128,0.2) !important; color: #fff !important; }
    .sidebar-links .nav-link.active .nav-link-text { color: #fff !important; }
    .sidebar-links .nav-link.active .nav-link-icon i { color: #4ade80 !important; }
    .sidebar-links .nav-link-logout:hover { background: rgba(239,68,68,0.15) !important; color: #fca5a5 !important; border-color: transparent !important; }
    .stat-icon-wrap { display: none; }
    .stat-card::before { display: none; }

    /* ── KPI Cards — aether Analytics glance-card (copied with container style) ── */
    .ab-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
    .ab-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.25rem; }
    .ab-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
    .ab-card { background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
    .ab-glance { padding:1.1rem 1.25rem; position:relative; overflow:hidden; }
    .ab-glance-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#737373; margin:0 0 0.4rem; }
    .ab-glance-val-row { display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap; }
    .ab-glance-val { font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1; color:#050505; }
    .ab-glance-chg { font-size:14px; font-family:'Inter','Segoe UI',sans-serif; display:inline-flex; align-items:center; gap:0.15rem; }
    .ab-chg-up { color:#0E8131; }
    .ab-chg-down { color:#e53e3e; }
    .ab-spark-wrap { height:48px; position:relative; }
    .ab-spark-wrap canvas { width:100% !important; height:100% !important; }
    .kpi-period-bar { display:flex; align-items:center; gap:10px; margin-bottom:1rem; }
    .kpi-period-label { font-size:0.72rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.03em; }
    .kpi-period-btns { display:flex; gap:4px; }
    .kpi-period-btn { padding:4px 14px; font-size:0.72rem; font-weight:600; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#64748b; cursor:pointer; transition:all 0.15s; }
    .kpi-period-btn:hover { border-color:#cbd5e1; background:#f8fafc; }
    .kpi-period-btn.active { border-color:#0E8131; background:#ecfdf5; color:#0E8131; }
    @media (max-width: 1200px) { .ab-grid-4 { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 640px) { .ab-grid-4 { grid-template-columns: 1fr; } }

    /* â”€â”€ Welcome Banner uses .welcome-banner from dashboard.css â”€â”€ */

    .page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:2rem; flex-wrap:wrap; gap:1rem; }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; letter-spacing:-0.02em; }
    .page-subtitle { font-size:0.85rem; color:#64748b; margin:4px 0 0; line-height:1.5; }

    /* â”€â”€ Analytics Grid (75/25 split) â”€â”€ */
    .analytics-grid { display:flex; gap:1.25rem; margin-bottom:1.75rem; align-items:stretch; }
    .analytics-left { flex:3; display:flex; flex-direction:column; min-width:0; }
    .analytics-right { flex:1; display:flex; flex-direction:column; min-width:0; }
    .analytics-left .chart-card { flex:1; display:flex; flex-direction:column; }
    .analytics-left .chart-card .chart-wrap { flex:1; min-height:380px; height:auto; }
    .analytics-right .chart-card { flex:1; display:flex; flex-direction:column; }

    /* â”€â”€ Charts â”€â”€ */
    .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.75rem; align-items: stretch; }
    .chart-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .chart-card h3 { font-size: 0.92rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem; display: flex; align-items: center; gap: 8px; }
    .chart-card h3 i { color: #0E8131; }
    .chart-card .chart-wrap { position: relative; height: 240px; }
    .chart-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.85rem; }

    /* Donut chart styles */
    .segment { cursor: pointer; transition: transform 0.25s ease; }
    .segment path { transition: filter 0.25s ease; }
    .segment:hover path { filter: brightness(1.08); }
    .segment:hover { transform: translate(var(--dx, 0px), var(--dy, 0px)); }
    .legend-item { display:flex; align-items:center; gap:6px; font-size:13px; color:#4a5060; }
    .legend-dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
    .brgy-title { margin:0 0 2px 0; font-size:0.85rem; font-weight:700; color:#0f172a; }
    .brgy-desc { margin:0 0 12px 0; font-size:0.72rem; color:#64748b; }

    /* â”€â”€ Section Header â”€â”€ */
    .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem; }
    .section-header h2 { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
    .section-header h2 i { color: #0E8131; }

    /* â”€â”€ Filter Bar (search + selects) â”€â”€ */
    .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 1rem; }

    /* â”€â”€ Tables (Staff-style) â”€â”€ */
    .table-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    /* Reports page: full white background */
    #reports.content-section {
      margin:-1.75rem -2rem;
      width:calc(100% + 4rem);
      max-width:none;
      background:#ffffff;
      min-height:calc(100vh - var(--topbar-h, 64px));
      padding:1.5rem 2rem;
      display:flex;
      flex-direction:column;
    }
    body.white-page #reports.content-section { background:#ffffff; }
    .table-card .table-header-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 16px;
      border-bottom: 1px solid #f1f5f9;
      margin-bottom: 16px;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    .table-card .table-title-group { display: flex; align-items: center; gap: 10px; }
    .table-card .table-title-group h2 { font-size: 1.05rem; font-weight: 700; color: #0f172a; margin: 0; }
    .table-card .table-title-group .table-count-badge {
      color: #64748b; font-weight: 600;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .data-table thead th {
      padding: 0.85rem 1rem; font-size: 0.8rem; font-weight: 600; color: #64748b;
      text-transform: uppercase; letter-spacing: 0.04em; background: #f8fafc;
      border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap;
    }
    .data-table tbody td { padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover td { background: #f0fdf4; }
    .data-table .table-empty { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
    .data-table .table-empty i { font-size: 2rem; display: block; margin-bottom: 0.5rem; color: #d1d5db; }

    /* â”€â”€ Search Box â”€â”€ */
    .search-box { display: inline-flex; align-items: center; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 0 12px; transition: border-color 0.15s, box-shadow 0.15s; }
    .search-box:focus-within { border-color: #0E8131; box-shadow: 0 0 0 3px rgba(14,129,49,0.1); }
    .search-box i { color: #94a3b8; font-size: 0.85rem; }
    .search-box input { border: none; background: transparent; padding: 8px 10px; font-size: 0.85rem; color: #1e293b; outline: none; width: 100%; min-width: 180px; }
    .search-box input::placeholder { color: #94a3b8; }

    /* â”€â”€ Select Filters â”€â”€ */
    .filter-select {
      padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e2e8f0;
      font-size: 0.82rem; background: #fff; color: #334155; cursor: pointer;
      transition: border-color 0.15s;
    }
    .filter-select:focus { border-color: #0E8131; outline: none; box-shadow: 0 0 0 3px rgba(14,129,49,0.1); }

    /* â”€â”€ Status Pills (plain text) â”€â”€ */
    .status-pill {
      display: inline; padding: 0; border-radius: 0;
      font-size: inherit; font-weight: 500;
      color: #334155; background: none; white-space: nowrap;
    }
    .status-pill::before { display: none; }

    /* â”€â”€ Action Buttons â”€â”€ */
    .action-btn-sm {
      padding: 6px 14px; border-radius: 6px; border: none; font-size: 0.78rem;
      font-weight: 600; cursor: pointer; transition: all 0.15s; display: inline-flex;
      align-items: center; gap: 6px;
    }
    .action-btn-sm.green { background: #f0fdf4; color: #0E8131; }
    .action-btn-sm.green:hover { background: #dcfce7; transform: translateY(-1px); }
    .action-btn-sm.red { background: #fef2f2; color: #dc2626; }
    .action-btn-sm.red:hover { background: #fee2e2; transform: translateY(-1px); }
    .action-btn-sm.blue { background: #eff6ff; color: #2563eb; }
    .action-btn-sm.blue:hover { background: #dbeafe; transform: translateY(-1px); }
    .action-btn-sm.gray { background: #f1f5f9; color: #475569; }
    .action-btn-sm.gray:hover { background: #e2e8f0; transform: translateY(-1px); }

    /* â”€â”€ Modal Body spacing â”€â”€ */
    .modal-body { padding: 1.75rem 2rem; }

    /* â”€â”€ Review Report dialog (shadcn sd-dialog style) â”€â”€ */
    #reviewModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #reviewModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #reviewModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:20px 24px; display:flex; flex-direction:column; gap:12px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto; }
    #reviewModal .sd-dialog-close { position:sticky; top:12px; align-self:flex-end; margin-left:auto; margin-bottom:-28px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; z-index:2; }
    #reviewModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #reviewModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; padding-right:32px; }
    #reviewModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #reviewModal .sd-dialog-description { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; line-height:1.5; }
    #reviewModal .sd-field-group { display:flex; flex-direction:column; gap:10px; font-family:'Inter','Segoe UI',sans-serif; }
    #reviewModal .sd-dialog-footer { display:flex; flex-direction:row; justify-content:flex-end; align-items:center; gap:8px; padding-top:4px; }
    #reviewModal .sd-btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; white-space:nowrap; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #reviewModal .sd-btn-default{ background:#18181b; color:#fafafa; }
    #reviewModal .sd-btn-default:hover{ background:#27272a; }
    #reviewModal .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    #reviewModal .sd-btn-outline:hover{ background:#f4f4f5; }
    #reviewModal .sd-btn-danger{ background:#ffffff; border-color:#fecaca; color:#b91c1c; }
    #reviewModal .sd-btn-danger:hover{ background:#fef2f2; }

    /* â”€â”€ Review Modal â”€â”€ */
    .review-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1.25rem; }
    .review-detail-grid .rd-label { font-size: 14px; font-weight: 500; color: #71717a; line-height: 1; margin-bottom: 4px; font-family: 'Inter','Segoe UI',sans-serif; text-transform: none; }
    .review-detail-grid .rd-value { font-size: 14px; color: #18181b; font-weight: 400; line-height: 1.4; word-break: break-word; font-family: 'Inter','Segoe UI',sans-serif; }
    .review-detail-section { margin-bottom: 0.9rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; }
    .review-detail-section:last-of-type { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .review-section-title { font-size: 14px; font-weight: 600; color: #18181b; letter-spacing: -0.01em; margin: 0 0 0.5rem; display: flex; align-items: center; gap: 8px; font-family: 'Inter','Segoe UI',sans-serif; text-transform: none; }
    .review-actions { display: flex; gap: 1rem; margin-top: 1.5rem; justify-content: flex-end; }
    .review-photo-wrap { margin-top: 2px; }
    .review-photo-img { max-height: 130px; max-width: 180px; width: auto; height: auto; display: block; border-radius: 8px; }
    .review-photo-wrap img { max-height: 130px; max-width: 180px; width: auto; height: auto; border-radius: 8px; border: 1px solid #e2e8f0; cursor: pointer; display: block; }
    #reviewModal .rpt-status{ display:inline-flex; align-items:center; padding:3px 10px; border-radius:4px; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    #reviewModal .rpt-status.pending{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
    #reviewModal .rpt-status.validated{ background:#dbeafe; color:#1d4ed8; border-color:#bfdbfe; }
    #reviewModal .rpt-status.confirmed{ background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }
    #reviewModal .rpt-status.rejected{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    #reviewModal .rpt-status.resolved{ background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }

    /* ── Reports table (shadcn data-table style, copied from staff All Clients) ── */
    #reports { font-family:'Inter','Segoe UI',sans-serif; }
    #reports .db-toolbar{ display:flex; align-items:center; gap:10px; margin-bottom:1rem; flex-wrap:wrap; justify-content:flex-start; }
    #reports .db-toolbar-actions{ margin-left:auto; display:flex; align-items:center; gap:8px; }
    #reports .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
    #reports .dt-container > div{ overflow-x:auto; }
    #reports table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #reports thead tr{ border-bottom:1px solid #e4e4e7; }
    #reports thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; }
    #reports thead th.sd-th-action{ text-align:right; }
    #reports tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
    #reports tbody tr:last-child{ border-bottom:none; }
    #reports tbody tr:hover{ background:#fafafa; }
    #reports tbody tr.selected, #reports tbody tr.selected:hover{ background:#f4f4f5; }
    #reports tbody td{ padding:10px 8px; vertical-align:middle; font-size:14px; color:#18181b; }
    #reports tbody td.sd-td-action{ text-align:right; }
    #reports .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #reports .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #reports .th-sort.th-sorted{ color:#18181b; }
    #reports .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #reports .th-sorted .th-sort-icon{ color:#18181b; }
    #reports .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #reports .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    #reports .client-cell{ display:flex; align-items:center; gap:10px; }
    #reports .avatar{ width:36px;height:36px; border-radius:50%; display:flex;align-items:center;justify-content:center; font-weight:700; font-size:12.5px; color:#045e1f; background:#dcebe0; flex-shrink:0; overflow:hidden; }
    #reports .avatar img{ width:100%; height:100%; object-fit:cover; }
    #reports .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #reports .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #reports .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #reports .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #reports .rpt-status{ display:inline-flex; align-items:center; padding:3px 10px; border-radius:4px; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    #reports .rpt-status.pending{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
    #reports .rpt-status.validated{ background:#dbeafe; color:#1d4ed8; border-color:#bfdbfe; }
    #reports .rpt-status.confirmed{ background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }
    #reports .rpt-status.rejected{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    #reports .rpt-status.resolved{ background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }
    #reports .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:16px 8px 4px; margin-top:auto; }
    #reports .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    #reports .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    #reports .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    #reports .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    #reports .dt-page-btns{ display:flex; align-items:center; gap:4px; }
    #reports .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    #reports .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
    #reports .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
    #reports .search-grow-wrap{ position:relative; }
    #reports .search-grow-wrap input{ height:36px; width:100%; min-width:200px; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
    #reports .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports .search-grow-wrap input::placeholder{ color:#71717a; }
    #reports .search-grow-wrap svg{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#71717a; }
    #reports .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #reports .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports .sd-btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; white-space:nowrap; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #reports .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    #reports .sd-btn-outline:hover{ background:#f4f4f5; }
    #reports .no-results{ text-align:center; padding:48px 12px; color:#71717a; }
    #reports .no-results svg{ color:#d4d4d8; display:block; margin:0 auto 10px; }
    #reports .no-results p{ margin:0; font-size:13px; }

    /* ── Confirmation Summary ── */
    .confirmation-summary { background: #f8fafc; border-radius: 10px; padding: 1.25rem; border: 1px solid #e2e8f0; margin-bottom: 1rem; font-family: 'Inter','Segoe UI',sans-serif; }
    .confirmation-summary .cs-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e2e8f0; font-size: 0.88rem; }
    .confirmation-summary .cs-row:last-child { border-bottom: none; }
    .confirmation-summary .cs-label { color: #64748b; font-weight: 500; font-family: 'Inter','Segoe UI',sans-serif; }
    .confirmation-summary .cs-value { color: #0f172a; font-weight: 600; text-align: right; font-family: 'Inter','Segoe UI',sans-serif; }

    /* â”€â”€ Modal Header Variants â”€â”€ */
    .modal-header.barangay { background: linear-gradient(135deg, #0E8131, #0a5c24); }

    /* â”€â”€ Notifications â”€â”€ */
    .notif-dropdown { position: relative; }
    .notif-bell { background: none; border: none; font-size: 1.2rem; color: #475569; cursor: pointer; padding: 6px; position: relative; border-radius: 8px; transition: background 0.15s; }
    .notif-bell:hover { background: #f1f5f9; }
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
    .notif-item.unread:hover { background: #dcfce7; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; background: transparent; color: #000; }
    .notif-content { flex: 1; min-width: 0; }
    .notif-title { font-size: 0.8rem; font-weight: 700; color: #0f172a; }
    .notif-msg { font-size: 0.74rem; color: #64748b; line-height: 1.3; margin-top: 1px; }
    .notif-time { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }
    .notif-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.82rem; }

    /* â”€â”€ Chart.js legend fix â”€â”€ */
    .analytics-left .chart-card canvas { max-height:none; }

    @media (max-width: 900px) {
      .analytics-grid { flex-direction:column; }
      .charts-grid { grid-template-columns: 1fr; }
      .review-detail-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .barangay-welcome { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
    }
  /* ── Profile Settings (new layout) ── */
  .profile-settings-wrapper * { box-sizing:border-box; }
  .profile-settings-wrapper { max-width:680px; margin:0 auto; }
  .profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
  .profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
  .profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
  .profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; object-fit:cover; background:#d9d9d9; cursor:pointer; }
  .profile-settings-wrapper .ps-avatar-plain { width:56px; height:56px; border-radius:50%; background:#d9d9d9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#666; cursor:pointer; }
  .profile-settings-wrapper .ps-profile-name { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
  .profile-settings-wrapper .ps-profile-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; line-height:1.5; margin-top:2px; }
  .profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
  .profile-settings-wrapper .ps-card-header h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; margin:0; }
  .profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; cursor:pointer; transition:background .15s,border-color .15s; }
  .profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
  .profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
  .profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
  .profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
  .profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
  .profile-settings-wrapper .ps-field label { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; margin-bottom:6px; }
  .profile-settings-wrapper .ps-field input,
  .profile-settings-wrapper .ps-field select,
  .profile-settings-wrapper .ps-field textarea { width:100%; padding:10px 12px; font-size:14px; border-radius:8px; border:1px solid #e2e5ea; background:#f9fafb; color:#1f2328; outline:none; transition:background .15s,border-color .15s,box-shadow .15s; box-sizing:border-box; font-family:inherit; }
  .profile-settings-wrapper .ps-field input:disabled,
  .profile-settings-wrapper .ps-field select:disabled,
  .profile-settings-wrapper .ps-field textarea:disabled { color:#374151; cursor:default; }
  .profile-settings-wrapper .ps-field input:not(:disabled),
  .profile-settings-wrapper .ps-field select:not(:disabled),
  .profile-settings-wrapper .ps-field textarea:not(:disabled) { background:#fff; border-color:#d7dae0; }
  .profile-settings-wrapper .ps-field input:not(:disabled):focus,
  .profile-settings-wrapper .ps-field select:not(:disabled):focus,
  .profile-settings-wrapper .ps-field textarea:not(:disabled):focus { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.08); }
  .profile-settings-wrapper .ps-btn { padding:9px 18px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; border-radius:8px; cursor:pointer; border:1px solid transparent; }
  .profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
  .profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
  .profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
  .profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
  .profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
  .profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
  .profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
  .profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
  #account-settings { background:#fff; }
  .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
  .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
  .profile-dropdown.open { display:block; }
  .profile-dropdown .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
  .profile-dropdown .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
  .profile-dropdown .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
  .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
  .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }
  .main-content { padding: 1.75rem 2rem !important; }

  /* ══════════════════════════════════════════
     Operations Center Dashboard
     ══════════════════════════════════════════ */

  /* Header */
  .ops-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;
  }
  .ops-title {
    font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0;
    font-family: 'Inter', 'Segoe UI', sans-serif; letter-spacing: -0.02em;
  }
  .ops-subtitle {
    font-size: 0.8rem; color: #64748b; margin: 2px 0 0;
  }
  .ops-header-controls {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  }
  .ops-period-select {
    height: 34px; padding: 0 12px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    font-size: 0.8rem; font-weight: 500; background: #fff; color: #334155;
    cursor: pointer; outline: none; font-family: inherit;
    transition: border-color 0.15s;
  }
  .ops-period-select:focus { border-color: #0E8131; box-shadow: 0 0 0 3px rgba(14,129,49,0.1); }
  .ops-btn-primary {
    height: 34px; padding: 0 16px; border-radius: 8px; border: none;
    background: #0E8131; color: #fff; font-size: 0.8rem; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    font-family: inherit; transition: background 0.15s, transform 0.1s;
  }
  .ops-btn-primary:hover { background: #0a6c28; transform: translateY(-1px); }
  .ops-btn-secondary {
    height: 34px; padding: 0 14px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #fff; color: #334155; font-size: 0.8rem; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    font-family: inherit; transition: all 0.15s;
  }
  .ops-btn-secondary:hover { border-color: #cbd5e1; background: #f8fafc; }
  .ops-btn-ghost {
    height: 30px; padding: 0 10px; border-radius: 6px; border: none;
    background: transparent; color: #0E8131; font-size: 0.8rem; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    font-family: inherit; transition: background 0.15s;
  }
  .ops-btn-ghost:hover { background: #f0fdf4; }

  /* Workflow Strip */
  .wf-strip {
    display: flex; align-items: center; gap: 0; margin-bottom: 1.5rem;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 1rem 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .wf-stage {
    flex: 1; display: flex; align-items: center; gap: 12px;
    padding: 0.75rem 1rem; border-radius: 8px; text-decoration: none;
    transition: background 0.15s, transform 0.1s; cursor: pointer;
  }
  .wf-stage:hover { background: #f0fdf4; transform: translateY(-2px); }
  .wf-stage-icon {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
  }
  .wf-stage-icon.new { background: #dcfce7; color: #16a34a; }
  .wf-stage-icon.validated { background: #fed7aa; color: #ea580c; }
  .wf-stage-icon.in_progress { background: #bfdbfe; color: #2563eb; }
  .wf-stage-icon.resolved { background: #bbf7d0; color: #15803d; }
  .wf-stage-icon.rejected { background: #fecaca; color: #dc2626; }
  .wf-stage-body {
    display: flex; flex-direction: column; gap: 2px;
  }
  .wf-stage-count {
    font-size: 1.5rem; font-weight: 700; color: #0f172a;
    font-family: 'Inter', sans-serif; line-height: 1;
  }
  .wf-stage-label {
    font-size: 0.75rem; font-weight: 600; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.03em;
  }
  .wf-stage-change {
    font-size: 0.7rem; font-weight: 600;
  }
  .wf-up { color: #0E8131; }
  .wf-down { color: #dc2626; }
  .wf-chevron {
    color: #cbd5e1; padding: 0 4px; flex-shrink: 0;
    font-size: 0.75rem;
  }

  /* Workspace Grid (8/4 split) */
  .ops-workspace {
    display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem;
    margin-bottom: 1.5rem;
  }
  .ops-reports-col { min-width: 0; }
  .ops-sidebar-col { display: flex; flex-direction: column; gap: 1rem; }

  /* Generic Card */
  .ops-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 1.25rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .ops-card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 0.875rem; flex-wrap: wrap; gap: 0.5rem;
  }
  .ops-card-title {
    font-size: 0.9rem; font-weight: 700; color: #0f172a; margin: 0;
    display: flex; align-items: center; gap: 8px;
  }
  .ops-card-title i { color: #0E8131; font-size: 0.9rem; }
  .ops-card-count {
    font-size: 0.8rem; color: #64748b; font-weight: 600;
  }
  .ops-card-subtitle {
    font-size: 0.75rem; color: #94a3b8; font-weight: 500;
  }

  /* Toolbar */
  .ops-card-toolbar {
    display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 0.875rem;
  }
  .ops-search-box {
    display: inline-flex; align-items: center; gap: 8px;
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px;
    padding: 0 12px; flex: 1; min-width: 180px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .ops-search-box:focus-within {
    border-color: #0E8131; box-shadow: 0 0 0 3px rgba(14,129,49,0.1);
  }
  .ops-search-box i { color: #94a3b8; font-size: 0.85rem; }
  .ops-search-box input {
    border: none; background: transparent; padding: 8px 0; font-size: 0.85rem;
    color: #1e293b; outline: none; width: 100%; font-family: inherit;
  }
  .ops-search-box input::placeholder { color: #94a3b8; }
  .ops-filter-select {
    height: 36px; padding: 0 12px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    font-size: 0.8rem; font-weight: 500; background: #fff; color: #334155;
    cursor: pointer; outline: none; font-family: inherit;
    transition: border-color 0.15s;
  }
  .ops-filter-select:focus {
    border-color: #0E8131; box-shadow: 0 0 0 3px rgba(14,129,49,0.1);
  }

  /* Table */
  .ops-table-container {
    overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px;
    margin-bottom: 0.875rem;
  }
  .ops-table {
    width: 100%; border-collapse: collapse; font-size: 0.82rem;
  }
  .ops-table thead th {
    padding: 0.7rem 1rem; font-size: 0.75rem; font-weight: 600;
    color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;
    background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    text-align: left; white-space: nowrap;
  }
  .ops-table tbody td {
    padding: 0.7rem 1rem; border-bottom: 1px solid #f1f5f9;
    color: #334155;
  }
  .ops-table tbody tr:last-child td { border-bottom: none; }
  .ops-table tbody tr:hover td { background: #f0fdf4; cursor: pointer; }
  .ops-th-action { width: 50px; }
  .ops-refid {
    font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.8rem;
    font-weight: 500; color: #475569;
  }
  .ops-cat-badge {
    display: inline-block; padding: 3px 10px; border-radius: 6px;
    font-size: 0.75rem; font-weight: 600; background: #f1f5f9; color: #475569;
  }
  .ops-location {
    max-width: 160px; overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap; font-size: 0.8rem;
  }
  .ops-date { white-space: nowrap; font-size: 0.8rem; color: #64748b; }

  /* Priority */
  .ops-prio {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.75rem; font-weight: 600; white-space: nowrap;
  }
  .ops-prio-dot {
    width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
  }
  .prio-critical .ops-prio-dot { background: #dc2626; }
  .prio-high .ops-prio-dot { background: #ea580c; }
  .prio-medium .ops-prio-dot { background: #f59e0b; }
  .prio-low .ops-prio-dot { background: #22c55e; }

  /* Status Chip */
  .ops-status-chip {
    display: inline-block; padding: 3px 10px; border-radius: 4px;
    font-size: 0.72rem; font-weight: 600; white-space: nowrap;
    border: 1px solid;
  }
  .ops-status-chip.pending { background: #fef9c3; color: #a16207; border-color: #fde047; }
  .ops-status-chip.validated { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; }
  .ops-status-chip.confirmed { background: #dcfce7; color: #15803d; border-color: #86efac; }
  .ops-status-chip.scheduled { background: #e0e7ff; color: #4338ca; border-color: #a5b4fc; }
  .ops-status-chip.resolved { background: #dcfce7; color: #15803d; border-color: #86efac; }
  .ops-status-chip.rejected { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

  .ops-assignee { font-size: 0.82rem; color: #64748b; }
  .ops-actions-cell { text-align: center; }
  .ops-kebab {
    width: 32px; height: 32px; border-radius: 6px; border: none;
    background: transparent; color: #94a3b8; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all 0.15s;
  }
  .ops-kebab:hover { background: #f1f5f9; color: #475569; }

  .ops-card-footer {
    padding-top: 1rem; border-top: 1px solid #f1f5f9;
    display: flex; justify-content: center;
  }
  .ops-table-empty {
    text-align: center; padding: 3rem 1rem; color: #94a3b8;
  }
  .ops-table-empty i { font-size: 2.5rem; margin-bottom: 0.5rem; color: #d1d5db; display: block; }

  /* Workload Cards */
  .ops-workload {
    display: flex; flex-direction: column; gap: 10px;
  }
  .ops-workload-card {
    display: flex; align-items: center; gap: 12px;
    padding: 0.875rem 1rem; background: #fff; border: 1px solid #e2e8f0;
    border-radius: 10px; text-decoration: none; transition: all 0.15s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .ops-workload-card:hover {
    border-color: #0E8131; box-shadow: 0 4px 12px rgba(14,129,49,0.12);
    transform: translateY(-2px);
  }
  .ops-workload-icon {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
  }
  .ops-wl-due { background: #fef3c7; color: #92400e; }
  .ops-wl-overdue { background: #fee2e2; color: #991b1b; }
  .ops-wl-mine { background: #dbeafe; color: #1e40af; }
  .ops-workload-body {
    flex: 1; display: flex; flex-direction: column; gap: 1px; min-width: 0;
  }
  .ops-workload-count {
    font-size: 1.25rem; font-weight: 700; color: #0f172a;
    font-family: 'Inter', sans-serif; line-height: 1;
  }
  .ops-workload-label {
    font-size: 0.8rem; font-weight: 600; color: #334155;
  }
  .ops-workload-desc {
    font-size: 0.7rem; color: #94a3b8; line-height: 1.3;
  }
  .ops-workload-arrow {
    color: #cbd5e1; font-size: 0.8rem; flex-shrink: 0;
    transition: color 0.15s;
  }
  .ops-workload-card:hover .ops-workload-arrow { color: #0E8131; }
  .ops-workload-urgent { border-left: 3px solid #f59e0b; }
  .ops-workload-critical { border-left: 3px solid #dc2626; }

  /* Activity Feed */
  .ops-activity-card { padding: 1rem 1.25rem; }
  .ops-activity-list {
    display: flex; flex-direction: column; gap: 0;
  }
  .ops-activity-item {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 0.6rem 0; border-bottom: 1px solid #f1f5f9;
  }
  .ops-activity-item:last-child { border-bottom: none; }
  .ops-activity-dot {
    width: 6px; height: 6px; border-radius: 50%; background: #cbd5e1;
    margin-top: 6px; flex-shrink: 0;
  }
  .ops-activity-body {
    flex: 1; display: flex; flex-direction: column; gap: 1px; min-width: 0;
  }
  .ops-activity-action {
    font-size: 0.8rem; font-weight: 600; color: #0f172a;
  }
  .ops-activity-detail {
    font-size: 0.72rem; color: #64748b; line-height: 1.3;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .ops-activity-time {
    font-size: 0.68rem; color: #94a3b8;
  }
  .ops-activity-empty {
    text-align: center; padding: 1.5rem 1rem; color: #94a3b8; font-size: 0.8rem;
  }

  /* Monitoring Row */
  .ops-monitoring {
    display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem;
  }
  .ops-trend-card, .ops-category-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 1.25rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .ops-chart-wrap {
    position: relative; height: 220px;
  }
  .ops-right-stack {
    display: flex; flex-direction: column; gap: 1.25rem;
  }
  .ops-right-stack .ops-category-card { flex: 1; }
  .ops-right-stack .ops-activity-card { flex: 1; }
  .ops-trend-toggle {
    display: flex; gap: 4px; background: #f1f5f9; border-radius: 6px; padding: 3px;
  }
  .ops-trend-btn {
    padding: 4px 12px; border-radius: 4px; border: none; background: transparent;
    font-size: 0.75rem; font-weight: 600; color: #64748b; cursor: pointer;
    font-family: inherit; transition: all 0.15s;
  }
  .ops-trend-btn:hover { color: #334155; }
  .ops-trend-btn.active { background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }

  /* Category Bars */
  .ops-cat-bars {
    display: flex; flex-direction: column; gap: 12px;
  }
  .ops-cat-bar-row {
    display: flex; align-items: center; gap: 10px;
  }
  .ops-cat-bar-label {
    flex: 0 0 110px; font-size: 0.8rem; font-weight: 500; color: #334155;
    display: flex; align-items: center; gap: 6px;
  }
  .ops-cat-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
  }
  .ops-cat-bar-track {
    flex: 1; height: 16px; background: #f1f5f9; border-radius: 8px;
    overflow: hidden;
  }
  .ops-cat-bar-fill {
    height: 100%; border-radius: 8px; transition: width 0.6s ease;
  }
  .ops-cat-bar-count {
    flex: 0 0 36px; text-align: right; font-size: 0.85rem;
    font-weight: 700; color: #0f172a;
  }

  /* Responsive */
  @media (max-width: 1100px) {
    .ops-workspace { grid-template-columns: 1fr; }
    .ops-monitoring { grid-template-columns: 1fr; }
    .ops-right-stack { flex-direction: row; }
    .ops-right-stack .ops-category-card, .ops-right-stack .ops-activity-card { flex: 1; }
  }
  @media (max-width: 768px) {
    .ops-header { flex-direction: column; align-items: flex-start; }
    .ops-header-controls { width: 100%; }
    .wf-strip {
      flex-direction: column; align-items: stretch; gap: 0;
    }
    .wf-chevron {
      text-align: center; padding: 4px 0;
      transform: rotate(90deg);
    }
    .wf-stage { padding: 0.75rem; }
    .ops-right-stack { flex-direction: column; }
  }
  </style>
</head>
<body data-realtime-page="barangay">

<?php include 'includes/barangay-sidebar.php'; ?>

<!-- â•â•â• MAIN â•â•â• -->
<main class="main-content" id="mainContent">

<?php if ($reportError): ?>
  <div class="auth-alert auth-alert-error" style="margin:1rem 0;"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($reportError); ?></span></div>
<?php endif; ?>
<?php if ($reportSuccess): ?>
  <div class="auth-alert auth-alert-success" style="margin:1rem 0;"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($reportSuccess); ?></span></div>
<?php endif; ?>

  <!-- â•â•â• OVERVIEW â•â•â• -->
  <section id="overview" class="content-section active">

    <div class="ops-header">
      <div class="ops-header-left">
        <h1 class="ops-title">Operations Overview</h1>
        <p class="ops-subtitle">Brgy. <?php echo htmlspecialchars($barangayName); ?> &middot; <?php echo date('l, F d, Y'); ?></p>
      </div>
      <div class="ops-header-controls">
        <select id="opsPeriodSelect" class="ops-period-select">
          <option value="7">Last 7 days</option>
          <option value="30" selected>Last 30 days</option>
          <option value="90">Last 90 days</option>
        </select>
        <button class="ops-btn-secondary" onclick="brgyReportsExport()">
          <i class="fas fa-download"></i> Export
        </button>
        <button class="ops-btn-primary" onclick="switchSection('reports')">
          <i class="fas fa-plus"></i> View Reports
        </button>
      </div>
    </div>

    <div class="wf-strip">
      <a href="#" class="wf-stage" onclick="event.preventDefault(); switchSection('reports'); brgyReportsSetStatus('pending');">
        <div class="wf-stage-icon new"><i class="fas fa-file-alt"></i></div>
        <div class="wf-stage-body">
          <span class="wf-stage-count"><?php echo $wfStages['new']; ?></span>
          <span class="wf-stage-label">New</span>
          <span class="wf-stage-change <?php echo $wfChange['new']['dir'] === 'up' ? 'wf-up' : 'wf-down'; ?>">
            <?php echo $wfChange['new']['dir'] === 'up' ? '&#9650;' : '&#9660;'; ?> <?php echo abs($wfChange['new']['pct']); ?>%
          </span>
        </div>
      </a>
      <div class="wf-chevron"><i class="fas fa-chevron-right"></i></div>
      <a href="#" class="wf-stage" onclick="event.preventDefault(); switchSection('reports'); brgyReportsSetStatus('validated');">
        <div class="wf-stage-icon validated"><i class="fas fa-check-circle"></i></div>
        <div class="wf-stage-body">
          <span class="wf-stage-count"><?php echo $wfStages['validated']; ?></span>
          <span class="wf-stage-label">Validated</span>
          <span class="wf-stage-change <?php echo $wfChange['validated']['dir'] === 'up' ? 'wf-up' : 'wf-down'; ?>">
            <?php echo $wfChange['validated']['dir'] === 'up' ? '&#9650;' : '&#9660;'; ?> <?php echo abs($wfChange['validated']['pct']); ?>%
          </span>
        </div>
      </a>
      <div class="wf-chevron"><i class="fas fa-chevron-right"></i></div>
      <a href="#" class="wf-stage" onclick="event.preventDefault(); switchSection('reports');">
        <div class="wf-stage-icon in_progress"><i class="fas fa-spinner"></i></div>
        <div class="wf-stage-body">
          <span class="wf-stage-count"><?php echo $wfStages['in_progress']; ?></span>
          <span class="wf-stage-label">In Progress</span>
          <span class="wf-stage-change <?php echo $wfChange['in_progress']['dir'] === 'up' ? 'wf-up' : 'wf-down'; ?>">
            <?php echo $wfChange['in_progress']['dir'] === 'up' ? '&#9650;' : '&#9660;'; ?> <?php echo abs($wfChange['in_progress']['pct']); ?>%
          </span>
        </div>
      </a>
      <div class="wf-chevron"><i class="fas fa-chevron-right"></i></div>
      <a href="#" class="wf-stage" onclick="event.preventDefault(); switchSection('reports'); brgyReportsSetStatus('resolved');">
        <div class="wf-stage-icon resolved"><i class="fas fa-check-double"></i></div>
        <div class="wf-stage-body">
          <span class="wf-stage-count"><?php echo $wfStages['resolved']; ?></span>
          <span class="wf-stage-label">Resolved</span>
          <span class="wf-stage-change <?php echo $wfChange['resolved']['dir'] === 'up' ? 'wf-up' : 'wf-down'; ?>">
            <?php echo $wfChange['resolved']['dir'] === 'up' ? '&#9650;' : '&#9660;'; ?> <?php echo abs($wfChange['resolved']['pct']); ?>%
          </span>
        </div>
      </a>
      <div class="wf-chevron"><i class="fas fa-chevron-right"></i></div>
      <a href="#" class="wf-stage wf-stage-rejected" onclick="event.preventDefault(); switchSection('reports'); brgyReportsSetStatus('rejected');">
        <div class="wf-stage-icon rejected"><i class="fas fa-times-circle"></i></div>
        <div class="wf-stage-body">
          <span class="wf-stage-count"><?php echo $wfStages['rejected']; ?></span>
          <span class="wf-stage-label">Rejected</span>
          <span class="wf-stage-change <?php echo $wfChange['rejected']['dir'] === 'up' ? 'wf-up' : 'wf-down'; ?>">
            <?php echo $wfChange['rejected']['dir'] === 'up' ? '&#9650;' : '&#9660;'; ?> <?php echo abs($wfChange['rejected']['pct']); ?>%
          </span>
        </div>
      </a>
    </div>

    <!-- Main Workspace Grid -->
    <div class="ops-workspace">
      <!-- Reports Requiring Attention -->
      <div class="ops-reports-col">
        <div class="ops-card">
          <div class="ops-card-header">
            <h3 class="ops-card-title"><i class="fas fa-exclamation-circle"></i> Reports Requiring Attention</h3>
            <span class="ops-card-count"><?php echo count($reports); ?> total</span>
          </div>
          <div class="ops-card-toolbar">
            <div class="ops-search-box">
              <i class="fas fa-search"></i>
              <input type="text" id="opsSearchInput" placeholder="Search by ID, reporter, type..." oninput="opsTableSearch(this.value)">
            </div>
            <select class="ops-filter-select" id="opsCatFilter" onchange="opsTableFilterCat(this.value)">
              <option value="">All Categories</option>
              <option value="stray">Stray Animal</option>
              <option value="rabies">Rabies</option>
              <option value="injured">Injured Animal</option>
              <option value="dead">Dead Animal</option>
            </select>
            <select class="ops-filter-select" id="opsStatusFilter" onchange="opsTableFilterStatus(this.value)">
              <option value="">All Statuses</option>
              <option value="pending">Pending</option>
              <option value="validated">Validated</option>
              <option value="confirmed">Confirmed</option>
              <option value="scheduled">Scheduled</option>
              <option value="resolved">Resolved</option>
              <option value="rejected">Rejected</option>
            </select>
            <select class="ops-filter-select" id="opsPriorityFilter" onchange="opsTableFilterPriority(this.value)">
              <option value="">All Priorities</option>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </select>
          </div>
          <div class="ops-table-container">
            <table class="ops-table">
              <thead>
                <tr>
                  <th>Report ID</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Submitted</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Validated By</th>
                  <th class="ops-th-action"></th>
                </tr>
              </thead>
              <tbody id="opsTableBody"></tbody>
            </table>
          </div>
          <div class="ops-card-footer">
            <button class="ops-btn-ghost" onclick="switchSection('reports')">
              View all reports <i class="fas fa-arrow-right"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Sidebar: Workload + Activity -->
      <div class="ops-sidebar-col">
        <div class="ops-workload">
          <a href="#" class="ops-workload-card <?php echo $validationDueCount > 0 ? 'ops-workload-urgent' : ''; ?>" onclick="event.preventDefault(); switchSection('reports'); brgyReportsSetStatus('pending');">
            <div class="ops-workload-icon ops-wl-due"><i class="fas fa-clock"></i></div>
            <div class="ops-workload-body">
              <span class="ops-workload-count"><?php echo $validationDueCount; ?></span>
              <span class="ops-workload-label">Validation Due</span>
              <span class="ops-workload-desc">Reports awaiting review &gt; 24h</span>
            </div>
            <i class="fas fa-chevron-right ops-workload-arrow"></i>
          </a>
          <a href="#" class="ops-workload-card <?php echo $overdueCount > 0 ? 'ops-workload-critical' : ''; ?>" onclick="event.preventDefault(); switchSection('reports');">
            <div class="ops-workload-icon ops-wl-overdue"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="ops-workload-body">
              <span class="ops-workload-count"><?php echo $overdueCount; ?></span>
              <span class="ops-workload-label">Overdue Cases</span>
              <span class="ops-workload-desc">Exceeded handling time</span>
            </div>
            <i class="fas fa-chevron-right ops-workload-arrow"></i>
          </a>
          <a href="#" class="ops-workload-card" onclick="event.preventDefault(); switchSection('reports');">
            <div class="ops-workload-icon ops-wl-mine"><i class="fas fa-user-check"></i></div>
            <div class="ops-workload-body">
              <span class="ops-workload-count"><?php echo $myValidatedCount; ?></span>
              <span class="ops-workload-label">Validated by You</span>
              <span class="ops-workload-desc">Awaiting DCVS action</span>
            </div>
            <i class="fas fa-chevron-right ops-workload-arrow"></i>
          </a>
        </div>
      </div>
    </div>

    <!-- Monitoring Row -->
    <div class="ops-monitoring">
      <div class="ops-card ops-trend-card">
        <div class="ops-card-header">
          <h3 class="ops-card-title"><i class="fas fa-chart-line"></i> Report Volume &amp; Resolution Trend</h3>
          <div class="ops-trend-toggle">
            <button class="ops-trend-btn active" onclick="opsTrendPeriod('daily',this)">Daily</button>
            <button class="ops-trend-btn" onclick="opsTrendPeriod('weekly',this)">Weekly</button>
          </div>
        </div>
        <div class="ops-chart-wrap">
          <canvas id="opsTrendChart"></canvas>
        </div>
      </div>
      <div class="ops-right-stack">
        <div class="ops-card ops-category-card">
          <div class="ops-card-header">
            <h3 class="ops-card-title"><i class="fas fa-list-ol"></i> Reports by Category</h3>
            <span class="ops-card-count"><?php echo array_sum($categoryCounts); ?> total</span>
          </div>
          <div class="ops-cat-bars">
            <?php
            $catColors = ['stray' => '#6266ea', 'rabies' => '#ee4b4b', 'injured' => '#f2a613', 'dead' => '#9aa4ae'];
            $catLabels = ['stray' => 'Stray Animal', 'rabies' => 'Rabies', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal'];
            $catTotal = max(array_sum($categoryCounts), 1);
            foreach (['stray', 'rabies', 'injured', 'dead'] as $catKey):
              $catVal = $categoryCounts[$catKey] ?? 0;
              $catPct = round(($catVal / $catTotal) * 100, 1);
            ?>
            <div class="ops-cat-bar-row">
              <div class="ops-cat-bar-label">
                <span class="ops-cat-dot" style="background:<?php echo $catColors[$catKey]; ?>;"></span>
                <?php echo $catLabels[$catKey]; ?>
              </div>
              <div class="ops-cat-bar-track">
                <div class="ops-cat-bar-fill" style="width:<?php echo $catPct; ?>%;background:<?php echo $catColors[$catKey]; ?>;"></div>
              </div>
              <span class="ops-cat-bar-count"><?php echo $catVal; ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="ops-card ops-activity-card">
          <div class="ops-card-header">
            <h3 class="ops-card-title"><i class="fas fa-history"></i> Recent Activity</h3>
          </div>
          <div class="ops-activity-list">
            <?php if (empty($activityFeed)): ?>
              <div class="ops-activity-empty">No recent activity.</div>
            <?php else: ?>
              <?php foreach ($activityFeed as $af): ?>
                <div class="ops-activity-item">
                  <div class="ops-activity-dot"></div>
                  <div class="ops-activity-body">
                    <span class="ops-activity-action"><?php echo htmlspecialchars($af['action']); ?></span>
                    <span class="ops-activity-detail"><?php echo htmlspecialchars($af['actor_name'] ?? 'System'); ?><?php if (!empty($af['reference_id'])): ?> &middot; <?php echo htmlspecialchars($af['reference_id']); ?><?php endif; ?></span>
                    <span class="ops-activity-time"><?php echo date('M d, g:ia', strtotime($af['created_at'])); ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- â•â•â• REPORTS â•â•â• -->
  <section id="reports" class="content-section">
    <div class="db-toolbar">
      <div class="search-grow-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="reportSearch" placeholder="Search by ID, reporter, type..." oninput="brgyReportsSetSearch(this.value)">
      </div>
      <div class="toolbar-filters">
        <select class="select-input tf-select" id="catFilter" onchange="brgyReportsSetType(this.value)">
          <option value="">All Categories</option>
          <option value="stray">Stray Animal</option>
          <option value="rabies">Rabies</option>
          <option value="injured">Injured Animal</option>
          <option value="dead">Dead Animal</option>
        </select>
        <select class="select-input tf-select" id="statusFilter" onchange="brgyReportsSetStatus(this.value)">
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="validated">Validated by Barangay</option>
          <option value="confirmed">Confirmed by DCVS</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <div class="db-toolbar-actions">
        <button type="button" class="sd-btn sd-btn-outline" onclick="brgyReportsExport()" title="Export Reports">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </button>
      </div>
    </div>

    <div class="dt-container" id="reportsTableWrap">
      <div style="overflow-x:auto;">
        <table>
          <thead id="reportsTableHead"></thead>
          <tbody id="reportsRows"></tbody>
        </table>
      </div>
    </div>

    <div class="dt-pagination">
      <div class="dt-pagination-right">
        <div class="dt-rows">
          <span>Rows per page</span>
          <select onchange="brgyReportsSetPerPage(this.value)">
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="30">30</option>
            <option value="40">40</option>
            <option value="50">50</option>
          </select>
        </div>
        <div class="dt-page-label" id="brgyRptPageLabel">Page 1 of 1</div>
        <div class="dt-page-btns">
          <button class="dt-page-btn" id="brgyRptPageFirst" title="Go to first page">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
          </button>
          <button class="dt-page-btn" id="brgyRptPagePrev" title="Go to previous page">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <button class="dt-page-btn" id="brgyRptPageNext" title="Go to next page">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <button class="dt-page-btn" id="brgyRptPageLast" title="Go to last page">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
          </button>
        </div>
      </div>
    </div>
  </section>

  <!-- ────────── VISIT OUR OFFICE ────────── -->
  <!-- ────────── ACCOUNT SETTINGS ────────── -->
  <section id="account-settings" class="content-section">

    <?php if (isset($_SESSION['profile_error'])): ?>
      <div id="profileError" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#991b1b;"><?php echo htmlspecialchars($_SESSION['profile_error']); unset($_SESSION['profile_error']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['profile_success'])): ?>
      <div id="profileSuccess" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#065f46;"><?php echo htmlspecialchars($_SESSION['profile_success']); unset($_SESSION['profile_success']); ?></div>
    <?php endif; ?>

    <div class="profile-settings-wrapper">

      <!-- Profile summary -->
      <div class="ps-card ps-profile-card">
        <div class="ps-profile-left">
          <div class="profile-header-avatar-wrapper">
            <div class="ps-avatar" id="brgyAvatarContainer" style="<?php echo empty($barangayAvatar) ? 'display:none' : ''; ?>">
              <img id="brgyAvatarImg" src="<?php echo htmlspecialchars($barangayAvatar); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)">
            </div>
            <div class="ps-avatar-plain" id="brgyAvatarPlain" onclick="toggleProfileDropdown(event)" style="<?php echo empty($barangayAvatar) ? '' : 'display:none'; ?>"><i class="fas fa-user"></i></div>
            <div class="profile-dropdown" id="profileDropdown">
              <div class="dropdown-item" onclick="event.stopPropagation();viewProfilePhoto()">
                <i class="fas fa-eye"></i> <span>View Profile</span>
              </div>
              <div class="dropdown-item" onclick="event.stopPropagation();document.getElementById('avatarInput').click()">
                <i class="fas fa-camera"></i> <span>Change Profile</span>
              </div>
            </div>
          </div>
          <div>
            <div class="ps-profile-name"><?php echo htmlspecialchars($fullName); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($barangayEmail); ?></div>
            <div class="ps-profile-sub">Brgy. <?php echo htmlspecialchars($barangayName); ?>, Dasmari&ntilde;as</div>
          </div>
        </div>
      </div>

      <form id="brgySettingsForm" action="update-barangay-profile.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event,'brgyAvatarImg','brgyAvatarContainer','brgyAvatarPlain')">

        <!-- Personal Information -->
        <div class="ps-card" id="personal-card">
          <div class="ps-card-header">
            <h2>Personal Information</h2>
            <button type="button" class="ps-edit-btn" onclick="toggleEdit('personal')">
              <span class="btn-label">Edit</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Full Name</label>
              <input type="text" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Email Address</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($barangayEmail); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($barangayPhone); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Address</label>
              <textarea name="address" disabled data-group="personal"><?php echo htmlspecialchars($barangayAddress); ?></textarea>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="ps-footer-actions">
          <button type="button" class="ps-btn ps-btn-secondary" onclick="openModal('changePasswordModal')">Change Password</button>
          <button type="submit" class="ps-btn ps-btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </section>

</main>



<!-- â•â•â• MODALS â•â•â• -->

<!-- Review Modal -->
<div class="modal" id="reviewModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('reviewModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Review Report</h2>
        <p class="sd-dialog-description">Review the submitted report and its case details.</p>
      </div>
      <div class="sd-field-group" id="reviewModalBody">
        <!-- populated by JS -->
      </div>
    </div>
  </div>
</div>

<!-- Reject Report Modal -->
<div class="modal" id="rejectModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-times-circle"></i> Reject Report</h2>
      <button class="modal-close" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="rejectModalBody">
      <!-- populated by JS -->
    </div>
  </div>
</div>

<!-- Rejection Details Modal -->
<div class="modal" id="rejectionModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-times-circle"></i> Report Rejected</h2>
      <button class="modal-close" onclick="closeModal('rejectionModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="rejectionModalBody">
      <!-- populated by JS -->
    </div>
  </div>
</div>

<!-- Confirmation Summary Modal -->
<div class="modal" id="confirmModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Confirmation Summary</h2>
      <button class="modal-close" onclick="closeModal('confirmModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="confirmModalBody">
      <!-- populated by JS -->
    </div>
  </div>
</div>

<!-- Profile Photo Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Profile Photo</h3>
      <button class="modal-close" onclick="closeModal('profilePhotoModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <div id="profilePhotoDisplay" style="margin-bottom:1rem;">
        <img id="profilePhotoImg" src="" alt="Profile Photo" style="max-width:280px;max-height:280px;border-radius:12px;border:2px solid #e2e8f0;object-fit:cover;display:none;">
        <div id="profilePhotoNoImg" style="width:150px;height:150px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:2.5rem;color:#94a3b8;"><i class="fas fa-user"></i></div>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-key"></i> Change Password</h3>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form action="update-barangay-password.php" method="POST">
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:1rem;">
          <label>Current Password</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="form-group" style="margin-bottom:1rem;">
          <label>New Password</label>
          <input type="password" name="new_password" class="form-control" required minlength="8">
        </div>
        <div class="form-group" style="margin-bottom:1rem;">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="8">
        </div>
      </div>
      <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;padding:1rem 1.5rem;border-top:1px solid #e2e8f0;">
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Password</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/dashboard.js"></script>
<script>
// Section switching
function switchSection(sectionId) {
  document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
  const section = document.getElementById(sectionId);
  if (section) section.classList.add('active');
  const navLink = document.querySelector(`.nav-link[data-section="${sectionId}"]`);
  if (navLink) navLink.classList.add('active');
  const names = { overview: 'Dashboard', reports: 'Reports', 'account-settings': 'Account Settings' };
  document.getElementById('breadcrumbCurrent').textContent = names[sectionId] || sectionId;
  document.body.classList.toggle('white-page', sectionId === 'reports' || sectionId === 'account-settings');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
document.querySelectorAll('.nav-link[data-section]').forEach(function(link) {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    switchSection(this.getAttribute('data-section'));
  });
});

// ── Profile Settings Edit Toggle ──
function toggleEdit(group) {
  var wrapper = document.querySelector('.profile-settings-wrapper');
  if (!wrapper) return;
  var inputs = wrapper.querySelectorAll('[data-group="' + group + '"]');
  var btn = document.getElementById(group + '-card').querySelector('.ps-edit-btn');
  var isEditing = btn.classList.contains('active');
  inputs.forEach(function(input) { input.disabled = isEditing; });
  btn.classList.toggle('active', !isEditing);
  btn.querySelector('.btn-label').textContent = isEditing ? 'Edit' : 'Done';
  if (!isEditing && inputs.length) inputs[0].focus();
}

document.addEventListener('DOMContentLoaded', function() {
  var sf = document.getElementById('brgySettingsForm');
  if (sf) {
    sf.addEventListener('submit', function() {
      this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
    });
  }
  window.previewAvatar = function(e, imgId, containerId, plainId) {
    var file = e.target.files[0];
    if (file) {
      var reader = new FileReader();
      reader.onload = function(ev) {
        var img = document.getElementById(imgId);
        if (img) {
          img.src = ev.target.result;
          var cont = document.getElementById(containerId);
          var plain = document.getElementById(plainId);
          if (cont) cont.style.display = '';
          if (plain) plain.style.display = 'none';
        }
      };
      reader.readAsDataURL(file);
    }
  };

  setTimeout(function() {
    var err = document.getElementById('profileError');
    var suc = document.getElementById('profileSuccess');
    if (err) { err.style.transition = 'opacity 0.5s'; err.style.opacity = '0'; setTimeout(function() { err.style.display = 'none'; }, 500); }
    if (suc) { suc.style.transition = 'opacity 0.5s'; suc.style.opacity = '0'; setTimeout(function() { suc.style.display = 'none'; }, 500); }
  }, 3000);
});

function toggleProfileDropdown(e) {
  e.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('open');
}

document.addEventListener('click', function(e) {
  var d = document.getElementById('profileDropdown');
  if (d && d.classList.contains('open') && !d.contains(e.target) && !e.target.closest('.profile-header-avatar-wrapper')) {
    d.classList.remove('open');
  }
});

function viewProfilePhoto() {
  var img = document.getElementById('profilePhotoImg');
  var noImg = document.getElementById('profilePhotoNoImg');
  var currentAvatar = document.querySelector('.ps-avatar img');
  if (currentAvatar) {
    img.src = currentAvatar.src;
    img.style.display = 'block';
    noImg.style.display = 'none';
  } else {
    img.style.display = 'none';
    noImg.style.display = 'flex';
  }
  openModal('profilePhotoModal');
}

function confirmRemoveAvatar() {
  if (confirm('Remove your profile picture?')) {
    window.location.href = 'remove-photo.php';
  }
}

// Notifications â”€â”€
function markAllRead(e) {
  e.preventDefault();
  fetch('mark-notifications-read.php?type=barangay').then(() => {
    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
    const badge = document.querySelector('.notif-badge');
    if (badge) badge.remove();
  });
}


// ── Reports table (shadcn data-table style, copied from staff All Clients) ──
var brgyReportsState = { search:'', type:'', status:'', sortCol:'', sortDir:'', page:1, perPage:10 };

function brgyRptEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function brgyRptStatusLabel(status){ return ({pending:'Pending',validated:'Validated by Barangay',confirmed:'Confirmed by DCVS',rejected:'Rejected',resolved:'Resolved'})[status]||status; }
function brgyRptStatusPill(status){ return '<span class="rpt-status '+brgyRptEsc(status)+'">'+brgyRptEsc(brgyRptStatusLabel(status))+'</span>'; }
function brgyRptInitials(name){ return String(name||'?').trim().split(/\s+/).map(function(w){return w.charAt(0);}).slice(0,2).join('').toUpperCase(); }

var brgyRptCols = [
  { id:'ref', label:'Case ID', sort:'ref' },
  { id:'reporter', label:'Reporter', sort:'reporter' },
  { id:'contact', label:'Contact', sort:'contact' },
  { id:'type', label:'Category', sort:'type' },
  { id:'date', label:'Date Submitted', sort:'date' },
  { id:'status', label:'Status', sort:'status' }
];

function brgyRptColVal(col, r){
  if (col==='ref') return String(r.ref_id||'').toLowerCase();
  if (col==='reporter') return String(r.reporter_name||'').toLowerCase();
  if (col==='contact') return String(r.reporter_phone||'').toLowerCase();
  if (col==='type') return String(r.report_type||'').toLowerCase();
  if (col==='date') return new Date(r.created_at).getTime()||0;
  if (col==='status') return String(r.status||'').toLowerCase();
  return 0;
}

function brgyRptFiltered(){
  var list = reportsData.slice();
  var q = brgyReportsState.search.toLowerCase();
  if (q){
    list = list.filter(function(r){
      return (r.ref_id||'').toLowerCase().indexOf(q)!==-1 ||
        (r.reporter_name||'').toLowerCase().indexOf(q)!==-1 ||
        (r.report_type||'').toLowerCase().indexOf(q)!==-1 ||
        (r.status||'').toLowerCase().indexOf(q)!==-1;
    });
  }
  if (brgyReportsState.type){ list = list.filter(function(r){ return r.report_type===brgyReportsState.type; }); }
  if (brgyReportsState.status){ list = list.filter(function(r){ return r.status===brgyReportsState.status; }); }
  if (brgyReportsState.sortCol){
    var dir = brgyReportsState.sortDir==='desc' ? -1 : 1;
    list.sort(function(a,b){
      var av = brgyRptColVal(brgyReportsState.sortCol,a);
      var bv = brgyRptColVal(brgyReportsState.sortCol,b);
      if (typeof av==='number' && typeof bv==='number') return (av-bv)*dir;
      return String(av).localeCompare(String(bv))*dir;
    });
  }
  return list;
}

function brgyRptSortHeadHtml(col){
  var active = brgyReportsState.sortCol===col.sort;
  var icon;
  if (active && brgyReportsState.sortDir==='asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
  else if (active && brgyReportsState.sortDir==='desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
  else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
  return '<button class="th-sort'+(active?' th-sorted':'')+'" onclick="brgyRptToggleSort(\''+col.sort+'\')">'+brgyRptEsc(col.label)+'<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+icon+'</svg></button>';
}

function brgyRptCellHtml(col, r){
  if (col.id==='ref') return '<span class="client-id">'+brgyRptEsc(r.ref_id)+'</span>';
  if (col.id==='reporter') return '<div class="client-cell"><div class="avatar">'+(r.reporter_avatar ? '<img src="'+brgyRptEsc(r.reporter_avatar)+'" alt="">' : brgyRptEsc(brgyRptInitials(r.reporter_name)))+'</div><div class="client-name">'+brgyRptEsc(r.reporter_name)+'</div></div>';
  if (col.id==='contact') return '<div class="contact-main">'+brgyRptEsc(r.reporter_phone||'-')+'</div>';
  if (col.id==='type') return '<div class="contact-main">'+brgyRptEsc(r.report_type.charAt(0).toUpperCase()+r.report_type.slice(1))+'</div>';
  if (col.id==='date') return '<div class="contact-main">'+brgyRptEsc(new Date(r.created_at).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'}))+'</div>';
  if (col.id==='status') return brgyRptStatusPill(r.status);
  return '';
}

function brgyRptRowHtml(r){
  var html = '<tr class="sd-tr" data-id="'+r.report_id+'" onclick="openReview('+r.report_id+')" style="cursor:pointer;">';
  for (var i=0;i<brgyRptCols.length;i++){ html += '<td class="sd-td">'+brgyRptCellHtml(brgyRptCols[i],r)+'</td>'; }
  html += '<td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();openReview('+r.report_id+')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
  html += '</tr>';
  return html;
}

function brgyReportsRender(){
  var list = brgyRptFiltered();
  var total = list.length;
  var per = brgyReportsState.perPage;
  var pages = Math.max(1, Math.ceil(total/per));
  if (brgyReportsState.page > pages) brgyReportsState.page = pages;
  if (brgyReportsState.page < 1) brgyReportsState.page = 1;
  var start = (brgyReportsState.page-1)*per;
  var slice = list.slice(start, start+per);

  var head = document.getElementById('reportsTableHead');
  var hh = '<tr class="sd-tr">';
  for (var i=0;i<brgyRptCols.length;i++){ hh += '<th class="sd-th">'+brgyRptSortHeadHtml(brgyRptCols[i])+'</th>'; }
  hh += '<th class="sd-th sd-th-action"></th>';
  head.innerHTML = hh;

  var tbody = document.getElementById('reportsRows');
  if (total===0){
    tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="7"><div class="no-results"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>No reports found.</p></div></td></tr>';
  } else {
    tbody.innerHTML = slice.map(brgyRptRowHtml).join('');
  }

  var prev = document.getElementById('brgyRptPagePrev');
  var next = document.getElementById('brgyRptPageNext');
  var first = document.getElementById('brgyRptPageFirst');
  var last = document.getElementById('brgyRptPageLast');
  var label = document.getElementById('brgyRptPageLabel');
  if (prev) prev.disabled = brgyReportsState.page<=1;
  if (next) next.disabled = brgyReportsState.page>=pages;
  if (first) first.disabled = brgyReportsState.page<=1;
  if (last) last.disabled = brgyReportsState.page>=pages;
  if (label) label.textContent = 'Page ' + brgyReportsState.page + ' of ' + pages;
  if (first) first.onclick = function(){ brgyReportsState.page = 1; brgyReportsRender(); };
  if (prev) prev.onclick = function(){ brgyReportsState.page = Math.max(1, brgyReportsState.page-1); brgyReportsRender(); };
  if (next) next.onclick = function(){ brgyReportsState.page = Math.min(pages, brgyReportsState.page+1); brgyReportsRender(); };
  if (last) last.onclick = function(){ brgyReportsState.page = pages; brgyReportsRender(); };
}

function brgyRptToggleSort(col){
  if (brgyReportsState.sortCol===col){ brgyReportsState.sortDir = brgyReportsState.sortDir==='asc' ? 'desc' : 'asc'; }
  else { brgyReportsState.sortCol = col; brgyReportsState.sortDir = 'asc'; }
  brgyReportsState.page = 1;
  brgyReportsRender();
}
function brgyReportsSetSearch(v){ brgyReportsState.search = v; brgyReportsState.page = 1; brgyReportsRender(); }
function brgyReportsSetType(v){ brgyReportsState.type = v; brgyReportsState.page = 1; brgyReportsRender(); }
function brgyReportsSetStatus(v){ brgyReportsState.status = v; brgyReportsState.page = 1; brgyReportsRender(); }
function brgyReportsSetPerPage(v){ brgyReportsState.perPage = parseInt(v,10); brgyReportsState.page = 1; brgyReportsRender(); }
function brgyReportsExport(){
  var list = brgyRptFiltered();
  var csv = [['Case ID','Reporter','Contact','Category','Date Submitted','Status']];
  list.forEach(function(r){
    csv.push([r.ref_id, r.reporter_name, r.reporter_phone||'', r.report_type, new Date(r.created_at).toLocaleDateString('en-US'), brgyRptStatusLabel(r.status)]);
  });
  var text = csv.map(function(row){ return row.map(function(c){ return '"'+String(c==null?'':c).replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
  var a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('\uFEFF'+text);
  a.download = 'barangay-reports.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

// Data store
const reportsData = <?php echo json_encode(array_map(function($r) {
    return [
        'report_id' => $r['report_id'],
        'ref_id' => $r['reference_id'] ?? '#RPT-'.$r['report_id'],
        'report_type' => $r['report_type'],
        'description' => $r['description'],
        'location_address' => $r['location_address'],
        'urgency_level' => $r['urgency_level'],
        'status' => $r['status'],
        'photo_url' => $r['photo_url'],
        'created_at' => $r['created_at'],
        'reporter_name' => $r['reporter_name'],
        'reporter_phone' => $r['reporter_phone'],
        'reporter_email' => $r['reporter_email'],
        'reporter_avatar' => $r['reporter_avatar'] ?? '',
        'reporter_address' => $r['address'] ?? '',
        'rejection_reason' => $r['rejection_reason'] ?? ''
    ];
}, $reports)); ?>;

// Render the reports table once the data store is ready
if (document.getElementById('reportsRows')) { brgyReportsRender(); }

function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c; }); }

function fmtDate(d) { return new Date(d).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'}); }

function statusBadge(status) {
  const lb = {pending:'Pending',validated:'Validated by Barangay',confirmed:'Confirmed by DCVS',rejected:'Rejected',resolved:'Resolved'};
  return `<span class="rpt-status ${status}">${lb[status]||status}</span>`;
}

// â”€â”€ View Report Modal â”€â”€
function openReview(id) {
  closeModal('confirmModal');
  closeModal('rejectModal');
  const r = reportsData.find(d => d.report_id === id);
  if (!r) return;
  const photoHtml = r.photo_url
    ? `<div class="review-photo-wrap">
       <div style="margin-top:4px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;display:inline-block;cursor:pointer;" onclick="window.open('${escapeHtml(r.photo_url)}')">
         <img src="${escapeHtml(r.photo_url)}" alt="Report Photo" class="review-photo-img">
       </div>
       <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Click to view full image</div></div>`
    : '<p style="color:#94a3b8;font-size:0.82rem;margin:2px 0 0;">No photo uploaded.</p>';

  document.getElementById('reviewModalBody').innerHTML = `
    <div class="review-detail-section">
      <h4 class="review-section-title">Resident Information</h4>
      <div class="review-detail-grid">
        <div><div class="rd-label">Name</div><div class="rd-value">${escapeHtml(r.reporter_name)}</div></div>
        <div><div class="rd-label">Contact Number</div><div class="rd-value">${escapeHtml(r.reporter_phone || r.reporter_email)}</div></div>
        <div><div class="rd-label">Barangay</div><div class="rd-value"><?php echo htmlspecialchars($barangayName); ?></div></div>
      </div>
    </div>
    <div class="review-detail-section">
      <h4 class="review-section-title">Report Information</h4>
      <div class="review-detail-grid">
        <div><div class="rd-label">Report ID</div><div class="rd-value">${escapeHtml(r.ref_id)}</div></div>
        <div><div class="rd-label">Report Type</div><div class="rd-value">${escapeHtml(r.report_type)}</div></div>
        <div><div class="rd-label">Date Submitted</div><div class="rd-value">${fmtDate(r.created_at)}</div></div>
        <div style="grid-column:1/-1;"><div class="rd-label">Incident Location</div><div class="rd-value">${escapeHtml(r.location_address)}</div></div>
        <div style="grid-column:1/-1;"><div class="rd-label">Description</div><p style="color:#18181b;font-size:14px;margin:1px 0 0;line-height:1.45;">${escapeHtml(r.description)}</p></div>
      </div>
    </div>
    <div class="review-detail-section">
      <h4 class="review-section-title">Attachments</h4>
      ${photoHtml}
    </div>
    <div class="review-detail-section" style="border-bottom:none;margin-bottom:0;padding-bottom:0;">
      <h4 class="review-section-title">Current Status</h4>
      <div>${statusBadge(r.status)}</div>
      ${r.status === 'rejected' && r.rejection_reason ? `<p style="color:#18181b;font-size:14px;margin-top:6px;"><strong>Reason:</strong> ${escapeHtml(r.rejection_reason)}</p>` : ''}
    </div>
    <div class="sd-dialog-footer">
      ${r.status === 'pending' ? `
        <button type="button" class="sd-btn sd-btn-default" onclick="openConfirm(${r.report_id})"><i class="fas fa-check"></i> Validate Report</button>
        <button type="button" class="sd-btn sd-btn-danger" onclick="openRejectModal(${r.report_id})"><i class="fas fa-times"></i> Reject Report</button>
      ` : `<button type="button" class="sd-btn sd-btn-default" onclick="closeModal('reviewModal')">Close</button>`}
    </div>
  `;
  openModal('reviewModal');
}

// â”€â”€ Reject Modal â”€â”€
function openRejectModal(id) {
  closeModal('reviewModal');
  const r = reportsData.find(d => d.report_id === id);
  document.getElementById('rejectModalBody').innerHTML = `
    <p style="color:var(--text-2);margin-bottom:1rem;">You are about to reject this report. Please provide a reason — this will be visible to the resident.</p>
    <div class="form-group">
      <label>Rejection Reason <span class="req">*</span></label>
      <textarea id="rejectReason" rows="4" placeholder="Explain why this report was not validated..."></textarea>
    </div>
    <div class="review-actions">
      <button type="button" class="btn-secondary" onclick="openReview(${id})">Back</button>
      <button class="btn-danger" onclick="doReject(${id})"><i class="fas fa-times"></i> Reject Report</button>
    </div>
  `;
  openModal('rejectModal');
}
function doReject(id) {
  const reason = document.getElementById('rejectReason')?.value?.trim();
  if (!reason) { alert('Please provide a reason for rejection.'); return; }
  closeModal('rejectModal');
  const form = document.createElement('form');
  form.method = 'POST'; form.action = 'reject-report.php';
  const i1 = document.createElement('input'); i1.type='hidden'; i1.name='report_id'; i1.value=id; form.appendChild(i1);
  const i2 = document.createElement('input'); i2.type='hidden'; i2.name='reason'; i2.value=reason; form.appendChild(i2);
  document.body.appendChild(form); form.submit();
}

// â”€â”€ Rejection Details Modal â”€â”€
function openRejectionModal(el) {
  const data = JSON.parse(el.getAttribute('data-report'));
  const body = document.getElementById('rejectionModalBody');
  
  body.innerHTML = `
    <div class="review-detail-grid" style="margin-bottom:1.25rem;">
      <div><div class="rd-label">Report ID</div><div class="rd-value">${data.ref_id}</div></div>
      <div><div class="rd-label">Report Type</div><div class="rd-value">${data.report_type}</div></div>
      <div><div class="rd-label">Submitted</div><div class="rd-value">${data.created_at}</div></div>
      <div><div class="rd-label">Barangay</div><div class="rd-value">${data.barangay_name}</div></div>
      <div><div class="rd-label">Reporter</div><div class="rd-value">${data.reporter_name}</div></div>
      <div><div class="rd-label">Status</div><div class="rd-value" style="color:var(--red);font-weight:700;">Rejected</div></div>
      <div><div class="rd-label">Rejected By</div><div class="rd-value">${data.rejected_by}</div></div>
      <div><div class="rd-label">Date Rejected</div><div class="rd-value">${data.rejected_at}</div></div>
    </div>
    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border-2);">
      <div class="rd-label" style="color:var(--red);margin-bottom:8px;">Rejection Reason</div>
      <textarea readonly style="background:#fef2f2;color:#991b1b;border-color:#fecaca;min-height:80px;">${data.rejection_reason}</textarea>
    </div>
  `;
  openModal('rejectionModal');
}

// â”€â”€ Confirmation Summary â”€â”€
function openConfirm(id) {
  closeModal('reviewModal');
  const r = reportsData.find(d => d.report_id === id);
  if (!r) return;
  const photoHtml = r.photo_url
    ? `<div style="text-align:center;margin:0.5rem 0;"><img src="${escapeHtml(r.photo_url)}" alt="Photo" style="max-height:180px;max-width:100%;border-radius:8px;border:1px solid #e2e8f0;"></div>`
    : '';
  document.getElementById('confirmModalBody').innerHTML = `
    <p style="color:var(--text-2);margin-bottom:1rem;">You are about to forward this validated report to the <strong>DCVS Staff</strong> for final confirmation. Please review all information carefully before proceeding.</p>
    <div class="confirmation-summary">
      <div class="review-section-title">Resident Information</div>
      <div class="cs-row"><span class="cs-label">Name</span><span class="cs-value">${escapeHtml(r.reporter_name)}</span></div>
      <div class="cs-row"><span class="cs-label">Contact</span><span class="cs-value">${escapeHtml(r.reporter_phone || r.reporter_email)}</span></div>
      <div class="cs-row" style="border-bottom:2px solid #e2e8f0;padding-bottom:0.75rem;margin-bottom:0.5rem;"><span class="cs-label">Barangay</span><span class="cs-value"><?php echo htmlspecialchars($barangayName); ?></span></div>

      <div class="review-section-title">Report Information</div>
      <div class="cs-row"><span class="cs-label">Report ID</span><span class="cs-value">${escapeHtml(r.ref_id)}</span></div>
      <div class="cs-row"><span class="cs-label">Category</span><span class="cs-value">${escapeHtml(r.report_type)}</span></div>
      <div class="cs-row"><span class="cs-label">Location</span><span class="cs-value">${escapeHtml(r.location_address)}</span></div>
      <div class="cs-row"><span class="cs-label">Description</span><span class="cs-value">${escapeHtml(r.description)}</span></div>
      <div class="cs-row" style="border-bottom:none;"><span class="cs-label">Current Status</span><span class="cs-value">${statusBadge(r.status)}</span></div>
    </div>
    ${photoHtml}
    <div class="form-group" style="margin-top:1rem;">
      <label>Barangay Review Notes (optional)</label>
      <textarea id="barangayNotes" rows="2" placeholder="Add any observations or notes for DCVS..."></textarea>
    </div>
    <div class="review-actions" style="justify-content:space-between;margin-top:1.25rem;">
      <button type="button" class="btn-secondary" onclick="openReview(${id})"><i class="fas fa-arrow-left"></i> Back</button>
      <button class="btn-primary" onclick="forwardReport(${id})"><i class="fas fa-paper-plane"></i> Forward to DCVS</button>
    </div>
  `;
  openModal('confirmModal');
}

function forwardReport(id) {
  const notes = document.getElementById('barangayNotes')?.value || '';
  const form = document.createElement('form');
  form.method = 'POST'; form.action = 'validate-report.php';
  const i1 = document.createElement('input'); i1.type='hidden'; i1.name='report_id'; i1.value=id; form.appendChild(i1);
  const i2 = document.createElement('input'); i2.type='hidden'; i2.name='barangay_notes'; i2.value=notes; form.appendChild(i2);
  document.body.appendChild(form); form.submit();
}

// Charts

// ── Ops Table: Reports Requiring Attention ──
var opsState = { search: '', cat: '', status: '', priority: '' };

function opsTableSearch(v) { opsState.search = v.toLowerCase(); opsRenderTable(); }
function opsTableFilterCat(v) { opsState.cat = v; opsRenderTable(); }
function opsTableFilterStatus(v) { opsState.status = v; opsRenderTable(); }
function opsTableFilterPriority(v) { opsState.priority = v; opsRenderTable(); }

function opsEsc(s) { return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

function opsStatusLabel(s) {
  return ({pending:'Pending',validated:'Validated by Barangay',confirmed:'Confirmed by DCVS',scheduled:'Scheduled',rejected:'Rejected',resolved:'Resolved'})[s]||s;
}

function opsRenderTable() {
  var list = reportsData.slice();
  var q = opsState.search;
  if (q) {
    list = list.filter(function(r) {
      return (r.ref_id||'').toLowerCase().indexOf(q) !== -1 ||
        (r.reporter_name||'').toLowerCase().indexOf(q) !== -1 ||
        (r.report_type||'').toLowerCase().indexOf(q) !== -1 ||
        (r.location_address||'').toLowerCase().indexOf(q) !== -1 ||
        (r.status||'').toLowerCase().indexOf(q) !== -1;
    });
  }
  if (opsState.cat) list = list.filter(function(r) { return r.report_type === opsState.cat; });
  if (opsState.status) list = list.filter(function(r) { return r.status === opsState.status; });
  if (opsState.priority) list = list.filter(function(r) { return r.urgency_level === opsState.priority; });

  list.sort(function(a, b) { return new Date(b.created_at) - new Date(a.created_at); });

  var show = list.slice(0, 10);
  var tbody = document.getElementById('opsTableBody');
  if (!tbody) return;

  if (show.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="ops-table-empty"><i class="fas fa-inbox"></i><br>No reports match your filters.</td></tr>';
    return;
  }

  var html = '';
  show.forEach(function(r) {
    var prio = r.urgency_level || 'medium';
    var prioClass = 'prio-' + prio;
    var prioLabel = prio.charAt(0).toUpperCase() + prio.slice(1);
    var statusCls = r.status;
    var validatedBy = '--';
    var dt = new Date(r.created_at);
    var dateStr = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

    html += '<tr class="ops-row" onclick="openReview(' + r.report_id + ')">';
    html += '<td><span class="ops-refid">' + opsEsc(r.ref_id) + '</span></td>';
    html += '<td><span class="ops-cat-badge">' + opsEsc(r.report_type.charAt(0).toUpperCase() + r.report_type.slice(1)) + '</span></td>';
    html += '<td class="ops-location">' + opsEsc(r.location_address) + '</td>';
    html += '<td class="ops-date">' + opsEsc(dateStr) + '</td>';
    html += '<td><span class="ops-prio ' + prioClass + '"><span class="ops-prio-dot"></span>' + prioLabel + '</span></td>';
    html += '<td><span class="ops-status-chip ' + statusCls + '">' + opsEsc(opsStatusLabel(r.status)) + '</span></td>';
    html += '<td class="ops-assignee">' + opsEsc(validatedBy) + '</td>';
    html += '<td class="ops-actions-cell"><button class="ops-kebab" onclick="event.stopPropagation();openReview(' + r.report_id + ')"><i class="fas fa-ellipsis-v"></i></button></td>';
    html += '</tr>';
  });
  tbody.innerHTML = html;
}

if (document.getElementById('opsTableBody')) { opsRenderTable(); }

// ── Trend Chart: Submitted vs Resolved ──
(function() {
  var ctx = document.getElementById('opsTrendChart');
  if (!ctx) return;

  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color = '#64748b';

  var labels = <?php echo json_encode($trendLabels); ?>;
  var submitted = <?php echo json_encode($trendSubmitted); ?>;
  var resolved = <?php echo json_encode($trendResolved); ?>;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Submitted',
          data: submitted,
          borderColor: '#0E8131',
          backgroundColor: 'rgba(14,129,49,0.08)',
          borderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: '#0E8131',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          tension: 0.3,
          fill: true
        },
        {
          label: 'Resolved',
          data: resolved,
          borderColor: '#6266ea',
          backgroundColor: 'rgba(98,102,234,0.06)',
          borderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: '#6266ea',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          tension: 0.3,
          fill: true
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          position: 'top',
          align: 'end',
          labels: {
            usePointStyle: true,
            pointStyle: 'circle',
            padding: 16,
            font: { size: 12, weight: '600' },
            color: '#475569'
          }
        },
        tooltip: {
          backgroundColor: '#1e293b',
          titleColor: '#94a3b8',
          bodyColor: '#f8fafc',
          titleFont: { size: 11, weight: '500' },
          bodyFont: { size: 12, weight: '700' },
          padding: 10,
          cornerRadius: 8,
          displayColors: true
        }
      },
      scales: {
        y: {
          display: true,
          beginAtZero: true,
          grid: { color: '#f1f5f9', drawBorder: false },
          ticks: { font: { size: 11 }, color: '#94a3b8', precision: 0 }
        },
        x: {
          grid: { display: false },
          border: { display: false },
          ticks: { color: '#94a3b8', font: { size: 11 } }
        }
      }
    }
  });
})();

function opsTrendPeriod(period, btn) {
  document.querySelectorAll('.ops-trend-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}

// Monthly Activity (Jan–Dec) — kept for Reports section if needed
(function() {
  const allData = <?php echo json_encode($allYearData); ?>;
  let chartInstance = null;
  const labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  function buildData(rawData) {
    const maxVal  = Math.max(...rawData, 1);
    const chartMax = maxVal <= 20 ? 50 : maxVal <= 50 ? 75 : maxVal <= 100 ? 100 : Math.ceil(maxVal / 50) * 50;
    const shadowData = rawData.map(v => +(v * 0.6).toFixed(2));
    return { rawData, chartMax, shadowData };
  }

  function updateChart(year) {
    const rawData = allData[year] || Array(12).fill(0);
    const { rawData: rd, chartMax, shadowData } = buildData(rawData);

    if (!chartInstance) {
      const ctx = document.getElementById('monthlyChart');
      if (!ctx) return;
      chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: '',
              data: shadowData,
              borderColor: 'transparent',
              backgroundColor: function(ctx) {
                const chart = ctx.chart;
                const {ctx: c2, chartArea: ca} = chart;
                if (!ca) return 'rgba(30,41,59,0.06)';
                const g = c2.createLinearGradient(0, ca.top, 0, ca.bottom);
                g.addColorStop(0, 'rgba(30,41,59,0.13)');
                g.addColorStop(1, 'rgba(30,41,59,0.00)');
                return g;
              },
              borderWidth: 0,
              pointRadius: 0,
              tension: 0.3,
              fill: true
            },
            {
              label: 'Reports',
              data: rawData,
              borderColor: '#334155',
              backgroundColor: function(ctx) {
                const chart = ctx.chart;
                const {ctx: c2, chartArea: ca} = chart;
                if (!ca) return 'rgba(30,41,59,0.18)';
                const g = c2.createLinearGradient(0, ca.top, 0, ca.bottom);
                g.addColorStop(0, 'rgba(30,41,59,0.28)');
                g.addColorStop(1, 'rgba(30,41,59,0.01)');
                return g;
              },
              borderWidth: 1.5,
              pointRadius: 0,
              pointHoverRadius: 4,
              pointHoverBackgroundColor: '#334155',
              pointHoverBorderColor: '#fff',
              pointHoverBorderWidth: 2,
              tension: 0.3,
              fill: true
            }
          ]
        },
        plugins: [crosshairPlugin],
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#1e293b',
              titleColor: '#94a3b8',
              bodyColor: '#f8fafc',
              titleFont: { size: 11, weight: '500' },
              bodyFont: { size: 12, weight: '700' },
              padding: 10,
              cornerRadius: 8,
              displayColors: false,
              callbacks: {
                title: function(items) { return items[0].label; },
                label: function(item) {
                  if (item.datasetIndex === 1)
                    return item.raw + ' report' + (item.raw !== 1 ? 's' : '');
                  return null;
                }
              },
              filter: function(item) { return item.datasetIndex === 1; }
            }
          },
          scales: {
            y: {
              display: true,
              beginAtZero: true,
              max: chartMax,
              grid: { color: '#f1f5f9', drawBorder: false },
              ticks: {
                font: { size: 10 },
                color: '#94a3b8',
                precision: 0,
                stepSize: Math.max(5, Math.ceil(chartMax / 6 / 5) * 5)
              }
            },
            x: {
              grid: { display: false },
              border: { display: false },
              ticks: {
                color: '#94a3b8',
                font: { size: 10 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 12
              }
            }
          }
        }
      });
    } else {
      chartInstance.data.datasets[0].data = shadowData;
      chartInstance.data.datasets[1].data = rawData;
      chartInstance.options.scales.y.max = chartMax;
      chartInstance.options.scales.y.ticks.stepSize = Math.max(5, Math.ceil(chartMax / 6 / 5) * 5);
      chartInstance.update();
    }
    // Show rabies advisory if any month reaches 90% of threshold
    var advisory = document.getElementById('rabiesAdvisory');
    if (advisory) {
      var threshold = Math.round(chartMax * 0.9);
      var triggered = rawData.some(function(v) { return v >= threshold; });
      advisory.style.display = triggered ? 'flex' : 'none';
    }
  }

  // Initial render with current year
  updateChart(2025);

  // Year switcher
  var sel = document.getElementById('yearSelect');
  if (sel) {
    sel.addEventListener('change', function() {
      updateChart(parseInt(this.value));
    });
  }
})();

</script>

<script src="includes/realtime.js"></script>
<script>
document.addEventListener('dcvs:realtime', function(e) {
    var data = e.detail;
    if (data.reports && data.reports.length && typeof brgyReportsRender === 'function') {
        brgyReportsRender();
    }
    if (typeof opsRenderTable === 'function') {
        opsRenderTable();
    }
});
</script>
</body>
</html>
