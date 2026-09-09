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
?>

<?php
// ──────────────────────────────────────────────────────────────────────────
// LIVE DATA — actual database records for this barangay (Paliparan I)
// ──────────────────────────────────────────────────────────────────────────
$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName   = $_SESSION['full_name'] ?? 'Barangay Officer';
$avatarUrl  = $_SESSION['avatar_url'] ?? '';

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
    'pending'   => 'New',
    'validated' => 'For validation',
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
        case 'pending':   $kpi['new']        += (int)$kRow['c']; break;
        case 'validated': $kpi['validation'] += (int)$kRow['c']; break;
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
    'new'        => ['pending'],
    'validation' => ['validated'],
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
    SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status,
           r.location_address, r.description, r.created_at, r.validated_at,
           r.confirmed_at, r.updated_at,
           u.full_name AS reporter_name,
           v.full_name AS validator_name
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users v ON r.validated_by = v.user_id
    WHERE r.barangay_id = ? AND r.archived_at IS NULL
    ORDER BY r.created_at DESC
    LIMIT 50
");
$rq->bind_param('i', $barangayId);
$rq->execute();
$rResult = $rq->get_result();
while ($rRow = $rResult->fetch_assoc()) {
    $reports[] = [
        'id'          => $rRow['reference_id'],
        'category'    => $typeLabels[$rRow['report_type']] ?? ucfirst($rRow['report_type']),
        'location'    => $rRow['location_address'],
        'description' => $rRow['description'],
        'reporter'    => $rRow['reporter_name'],
        'submitted'   => date('M j, Y g:i A', strtotime($rRow['created_at'])),
        'priority'    => ucfirst($rRow['urgency_level']),
        'status'      => $statusLabels[$rRow['status']] ?? ucfirst($rRow['status']),
        'assignedTo'  => $rRow['validator_name'] ?: 'Unassigned',
        '_created_at' => $rRow['created_at'],
        '_validated_at' => $rRow['validated_at'],
        '_confirmed_at' => $rRow['confirmed_at'],
        '_updated_at' => $rRow['updated_at'],
        '_db_status'  => $rRow['status'],
        '_validator'  => $rRow['validator_name'] ?: '',
    ];
}
$rq->close();

// ── Comparison chart: placeholder/demo data for now ──
$cmpLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$cmpPrev   = [28, 28, 30, 30, 32, 32, 33, 33, 35, 35, 36, 36];
$cmpActual = [22, 25, 24, 27, 29, 28, 30, 32, 31, 34, 33, 35];

// ── Categories (report_type counts, all-time active) ──
$categories = [];
$categoryIcons = ['stray' => 'paw', 'rabies' => 'rabies', 'injured' => 'injured', 'dead' => 'dead'];
$cq2 = $conn->prepare("SELECT report_type, COUNT(*) c FROM reports WHERE barangay_id = ? AND archived_at IS NULL GROUP BY report_type");
$cq2->bind_param('i', $barangayId);
$cq2->execute();
$cr2 = $cq2->get_result();
$categoryAll = [];
while ($cRow = $cr2->fetch_assoc()) $categoryAll[$cRow['report_type']] = (int)$cRow['c'];
$cq2->close();
$categoryGrandTotal = array_sum($categoryAll);

foreach (['stray', 'rabies', 'injured', 'dead'] as $t) {
    $total = $categoryAll[$t] ?? 0;
    $categories[] = [
        'name'       => $typeLabels[$t],
        'count'      => $total,
        'total'      => $total,
        'percentage' => $categoryGrandTotal > 0 ? (int)round(($total / max($categoryGrandTotal, 1)) * 100) : 0,
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
$uq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
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
        'labels'   => $cmpLabels,
        'previous' => $cmpPrev,
        'actual'   => $cmpActual,
    ],
    'metrics'       => $kpi,
    'reports'       => $reports,
    'categories'    => $categories,
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

  <!-- MapCN bundle (geo mapping) -->
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css">
  <script src="dist/mapcn.umd.js"></script>


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

    .header-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: inherit;
    }

    .brand-seal-wrapper {
      width: 40px;
      height: 40px;
      flex-shrink: 0;
    }

    .brand-seal-svg {
      width: 100%;
      height: 100%;
      display: block;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-size: 14px;
      font-weight: 700;
      letter-spacing: -0.025em;
      color: var(--primary-green);
      line-height: 1.2;
    }

    .brand-subtitle {
      font-size: 12px;
      color: var(--text-muted);
      font-weight: 500;
      line-height: 1.3;
      margin-top: 1px;
    }

    .header-divider {
      width: 1px;
      height: 28px;
      background-color: var(--border-color);
    }

    .page-title-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .page-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text-main);
      letter-spacing: -0.025em;
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

    /* Search Box */
    .search-container {
      position: relative;
      width: 320px;
    }

    .search-input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .search-icon {
      position: absolute;
      left: 11px;
      width: 15px;
      height: 15px;
      color: var(--text-muted);
      pointer-events: none;
    }

    .search-input {
      width: 100%;
      height: 36px;
      padding: 0 44px 0 34px;
      background-color: #FAFAFA;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font-size: 14px;
      color: var(--text-main);
      font-family: inherit;
      transition: var(--transition);
      outline: none;
    }

    .search-input:focus {
      background-color: #FFFFFF;
      border-color: var(--primary-green);
      box-shadow: 0 0 0 3px rgba(11, 77, 38, 0.1);
    }

    .search-input::placeholder {
      color: var(--text-muted);
    }

    .search-shortcut {
      position: absolute;
      right: 9px;
      background-color: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 4px;
      font-size: 10.5px;
      font-family: 'IBM Plex Mono', monospace;
      color: var(--text-muted);
      padding: 1px 5px;
      font-weight: 600;
      pointer-events: none;
    }

    /* Notification Bell */
    .notif-btn {
      position: relative;
      background: none;
      border: none;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: var(--text-secondary);
      transition: var(--transition);
    }

    .notif-btn:hover {
      background-color: var(--bg-hover);
      color: var(--text-main);
    }

    .notif-badge {
      position: absolute;
      top: 4px;
      right: 4px;
      background-color: #E63946;
      color: #FFFFFF;
      font-size: 10px;
      font-weight: 700;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid #FFFFFF;
    }

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
      justify-content: space-between;
      margin-bottom: 22px;
      flex-wrap: wrap;
      gap: 12px;
      position: relative;
    }

    .date-filter-group {
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
    }

    .date-dropdown-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      padding: 7.5px 14px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
      user-select: none;
    }

    .date-dropdown-btn:hover {
      background-color: var(--bg-hover);
      border-color: var(--border-color-dark);
    }

    .date-dropdown-btn.active {
      border-color: var(--primary-green);
      box-shadow: 0 0 0 3px rgba(11, 77, 38, 0.1);
    }

    .date-comparison-label {
      font-size: 12px;
      color: var(--text-muted);
      font-weight: 500;
      transition: var(--transition);
    }

    /* Interactive Date Range Dropdown Popover */
    .date-picker-popover {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-dropdown);
      padding: 16px;
      width: 330px;
      z-index: 50;
      display: none;
      animation: popoverFadeIn 0.2s ease;
    }

    @keyframes popoverFadeIn {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .date-picker-popover.show {
      display: block;
    }

    .date-preset-list {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-bottom: 14px;
    }

    .date-preset-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 10px;
      border-radius: var(--radius-sm);
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      background: none;
      border: none;
      cursor: pointer;
      transition: var(--transition);
      text-align: left;
      width: 100%;
    }

    .date-preset-item:hover {
      background-color: var(--primary-green-light);
      color: var(--primary-green);
    }

    .date-preset-item.selected {
      background-color: var(--primary-green);
      color: #FFFFFF;
    }

    .custom-range-divider {
      height: 1px;
      background-color: var(--border-color-light);
      margin: 12px 0;
    }

    .custom-range-title {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-bottom: 8px;
    }

    .custom-range-inputs {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-bottom: 12px;
    }

    .custom-range-inputs input {
      width: 100%;
      padding: 6px 8px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font-size: 12px;
      font-family: inherit;
      color: var(--text-main);
      outline: none;
    }

    .custom-range-inputs input:focus {
      border-color: var(--primary-green);
    }

    .apply-custom-date-btn {
      width: 100%;
      padding: 8px;
      background-color: var(--primary-green);
      color: #FFFFFF;
      font-weight: 500;
      font-size: 14px;
      border: none;
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: var(--transition);
    }

    .apply-custom-date-btn:hover {
      background-color: var(--primary-green-hover);
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
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      padding: 7.5px 14px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }

    .btn-secondary:hover {
      background-color: var(--bg-hover);
      border-color: var(--border-color-dark);
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: var(--primary-green);
      border: 1px solid var(--primary-green);
      border-radius: var(--radius-sm);
      padding: 7.5px 16px;
      font-size: 14px;
      font-weight: 500;
      color: #FFFFFF;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 1px 3px rgba(11, 77, 38, 0.25);
    }

    .btn-primary:hover {
      background: var(--primary-green-hover);
    }

    /* ==========================================================================
       METRIC KPI CARDS (INDIVIDUAL CONTAINERS)
       ========================================================================== */
    .metric-pipeline-container {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
      background: transparent;
      border: none;
      box-shadow: none;
      padding: 0;
    }

    .pipeline-step {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 16px 18px;
      box-shadow: var(--shadow-card);
      display: flex;
      align-items: center;
      gap: 14px;
      cursor: default;
      transition: var(--transition);
      min-width: 0;
    }

    .pipeline-step:hover {
      border-color: var(--border-color-dark);
      box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }

    .step-icon-box {
      width: 42px;
      height: 42px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: var(--transition);
    }

    .step-icon-box.new {
      background-color: var(--metric-new-bg);
      color: var(--metric-new-icon);
      border: 1px solid #D2F4DC;
    }

    .step-icon-box.validation {
      background-color: var(--metric-validation-bg);
      color: var(--metric-validation-icon);
      border: 1px solid #FEE5C8;
    }

    .step-icon-box.progress {
      background-color: var(--metric-progress-bg);
      color: var(--metric-progress-icon);
      border: 1px solid #D5E9FD;
    }

    .step-icon-box.resolved {
      background-color: var(--metric-resolved-bg);
      color: var(--metric-resolved-icon);
      border: 1px solid #CEF6DE;
    }

    .step-icon-box.closed {
      background-color: var(--metric-closed-bg);
      color: var(--metric-closed-icon);
      border: 1px solid #E4E7EC;
    }

    .step-icon-box svg {
      width: 20px;
      height: 20px;
    }

    .step-content {
      display: flex;
      flex-direction: column;
    }

    .step-label {
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      margin-bottom: 2px;
    }

    .step-value {
      font-size: 26px;
      font-weight: 600;
      color: var(--text-main);
      line-height: 1.1;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      letter-spacing: -0.02em;
      transition: transform 0.2s ease;
    }

    .step-trend {
      font-size: 12px;
      font-weight: 500;
      color: var(--badge-green-text);
      display: flex;
      align-items: center;
      gap: 3px;
      margin-top: 3px;
    }

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

    /* Chart card grows to align vertically with the Recent activity card */
    .grid-left > .dashboard-card:last-child,
    .grid-right > .dashboard-card:last-child { flex: 1; min-height: 0; }
    .grid-left > .dashboard-card:last-child { display: flex; flex-direction: column; }
    .grid-left > .dashboard-card:last-child .chart-container { flex: 1; display: flex; flex-direction: column; }
    .chart-canvas-wrap { flex: 1; min-height: 340px; position: relative; }

    /* Reports by category — horizontal bar chart (recharts-style) */
    .cat-chart-wrap { height: 220px; position: relative; }
    .cat-chart-footer {
      display: flex; flex-direction: column; gap: 2px;
      padding-top: 12px; margin-top: 12px;
      border-top: 1px solid var(--border-color-light);
    }
    .cat-chart-trend { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; color: var(--text-main); }
    .cat-chart-note { font-size: 12px; color: var(--text-muted); }

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
      text-align: left;
      font-size: 14px;
    }

    .data-table th {
      padding: 11px 16px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-muted);
      background-color: #FAFAFA;
      border-bottom: 1px solid var(--border-color);
      white-space: nowrap;
      text-transform: none;
    }

    .data-table td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-color-light);
      color: var(--text-main);
      vertical-align: middle;
      white-space: nowrap;
    }

    .data-table tbody tr {
      transition: var(--transition);
      cursor: pointer;
    }

    .data-table tbody tr:hover {
      background-color: #F9FBFA;
    }

    .data-table tbody tr:last-child td {
      border-bottom: none;
    }

    .report-id-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 600;
      font-family: 'IBM Plex Mono', monospace;
      font-size: 12px;
      color: var(--text-main);
    }

    .priority-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .priority-dot.high { background-color: var(--badge-red-dot); }
    .priority-dot.medium { background-color: var(--badge-amber-dot); }
    .priority-dot.low { background-color: var(--badge-green-dot); }

    .category-cell {
      font-weight: 500;
      color: var(--text-main);
    }

    .location-cell {
      color: var(--text-muted);
      font-size: 13px;
    }

    .submitted-cell {
      color: var(--text-muted);
      font-size: 12px;
      font-family: 'IBM Plex Mono', monospace;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      padding: 2.5px 8.5px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 500;
      line-height: 1.2;
    }

    .status-badge.priority-high {
      background-color: var(--badge-red-bg);
      color: var(--badge-red-text);
    }

    .status-badge.priority-medium {
      background-color: var(--badge-amber-bg);
      color: var(--badge-amber-text);
    }

    .status-badge.priority-low {
      background-color: var(--badge-green-bg);
      color: var(--badge-green-text);
    }

    .status-badge.status-new {
      background-color: var(--badge-green-bg);
      color: var(--badge-green-text);
    }

    .status-badge.status-validation {
      background-color: var(--badge-amber-bg);
      color: var(--badge-amber-text);
    }

    .status-badge.status-progress {
      background-color: #EBF5FF;
      color: #175CD3;
    }

    .status-badge.status-resolved {
      background-color: #ECFDF3;
      color: #027A48;
    }

    .status-badge.status-closed {
      background-color: var(--badge-gray-bg);
      color: var(--badge-gray-text);
    }

    .assignee-cell {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
    }

    .assignee-cell.unassigned {
      color: var(--text-faint);
    }

    .row-action-btn {
      background: none;
      border: none;
      color: var(--text-muted);
      cursor: pointer;
      padding: 4px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--transition);
    }

    .row-action-btn:hover {
      background-color: var(--bg-hover);
      color: var(--text-main);
    }

    /* ==========================================================================
       MODERN WAVE CHART (WITH GRADIENT FILL & FLOATING CARD TOOLTIP)
       ========================================================================== */
    .chart-container {
      padding: 18px 24px 24px;
      position: relative;
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
      font-size: 14px;
      font-weight: 600;
      color: #18181B;
      line-height: 1.05;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      letter-spacing: -0.02em;
    }

    .activity-date-month {
      font-size: 12px;
      font-weight: 400;
      color: #A1A1AA;
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
      border: 3px solid #0D9488;
    }

    /* Ring Color Accents (Matching user reference) */
    .activity-ring-node.teal {
      border-color: #0D9488;
    }

    .activity-ring-node.green {
      border-color: #10B981;
    }

    .activity-ring-node.red {
      border-color: #EF4444;
    }

    .activity-ring-node.amber {
      border-color: #F59E0B;
    }

    /* Right Details Container */
    .activity-content {
      flex: 1;
      min-width: 0;
      padding-top: 0px;
    }

    .activity-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--text-main);
      line-height: 1.35;
    }

    .activity-title strong {
      color: var(--primary-green-text);
      font-family: 'IBM Plex Mono', monospace;
      font-size: 12px;
    }

    .activity-meta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 3px;
      font-size: 12px;
      color: var(--text-muted);
    }

    .activity-desc {
      color: var(--text-secondary);
      font-weight: 500;
    }

    .activity-time {
      font-family: 'IBM Plex Mono', monospace;
      font-size: 12px;
      color: var(--text-muted);
      flex-shrink: 0;
      margin-left: 6px;
    }

    /* ==========================================================================
       MODALS & DIALOGS
       ========================================================================== */
    .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(17, 24, 39, 0.45);
      backdrop-filter: blur(3px);
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
      padding: 16px;
    }

    .modal-backdrop.open {
      opacity: 1;
      pointer-events: auto;
    }

    .modal-dialog {
      background: #FFFFFF;
      border-radius: var(--radius-xl);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
      width: 100%;
      max-width: 540px;
      overflow: hidden;
      transform: translateY(12px) scale(0.98);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .modal-backdrop.open .modal-dialog {
      transform: translateY(0) scale(1);
    }

    .modal-header {
      padding: 18px 22px;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-title {
      font-size: 18px;
      font-weight: 600;
      letter-spacing: -0.025em;
      color: var(--text-main);
    }

    .modal-close-btn {
      background: none;
      border: none;
      color: var(--text-muted);
      font-size: 20px;
      cursor: pointer;
      padding: 4px;
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-close-btn:hover {
      background-color: var(--bg-hover);
      color: var(--text-main);
    }

    .modal-body {
      padding: 22px;
      max-height: 75vh;
      overflow-y: auto;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-group:last-child {
      margin-bottom: 0;
    }

    .form-label {
      display: block;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      margin-bottom: 6px;
    }

    .form-control {
      width: 100%;
      padding: 9px 12px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font-size: 14px;
      color: var(--text-main);
      font-family: inherit;
      outline: none;
      transition: var(--transition);
      background-color: #FFFFFF;
    }

    .form-control:focus {
      border-color: var(--primary-green);
      box-shadow: 0 0 0 3px rgba(11, 77, 38, 0.1);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .modal-footer {
      padding: 16px 22px;
      background-color: #FAFAFA;
      border-top: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
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
      z-index: 200;
      transform: translateY(100px);
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .toast-notification.show {
      transform: translateY(0);
      opacity: 1;
    }

    /* Quick Action Dropdown Menu */
    .quick-dropdown {
      position: absolute;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-dropdown);
      padding: 6px;
      z-index: 50;
      min-width: 170px;
      display: none;
    }

    .quick-dropdown.show {
      display: block;
    }

    .quick-dropdown-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 7px 10px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-main);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: var(--transition);
      border: none;
      background: none;
      width: 100%;
      text-align: left;
    }

    .quick-dropdown-item:hover {
      background-color: var(--bg-hover);
      color: var(--primary-green);
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
    }
  </style>
</head>
<body>

<div class="app-wrapper">

  <!-- ==========================================================================
       TOP NAVIGATION BAR
       ========================================================================== -->
  <header class="topbar">
    <div class="topbar-left">
      <!-- Official Barangay Seal & Branding -->
      <a href="#" class="header-brand">
        <div class="brand-seal-wrapper">
          <svg class="brand-seal-svg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="50" cy="50" r="46" stroke="#0B4D26" stroke-width="4" fill="#FFFFFF"/>
            <circle cx="50" cy="50" r="41" stroke="#0B4D26" stroke-width="1.5" stroke-dasharray="3 3"/>
            <path d="M50 18L30 30V48C30 65 50 78 50 78C50 78 70 65 70 48V30L50 18Z" fill="#EBF7EE" stroke="#0B4D26" stroke-width="2.5"/>
            <path d="M50 26L40 33V44C40 54 50 63 50 63C50 63 60 54 60 44V33L50 26Z" fill="#0B4D26"/>
            <path d="M46 45L49 48L55 39" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M26 50C26 63.2548 36.7452 74 50 74C63.2548 74 74 63.2548 74 50" stroke="#0B4D26" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="brand-text">
          <span class="brand-title"><?php echo htmlspecialchars('BRGY. ' . strtoupper($barangayName)); ?></span>
          <span class="brand-subtitle"><?php echo htmlspecialchars(($barangayDistrict ? $barangayDistrict . ' · Portal' : 'Dasmariñas City, Cavite · Portal')); ?></span>
        </div>
      </a>

      <div class="header-divider"></div>

      <!-- Page Title & Barangay Dropdown -->
      <div class="page-title-group">
        <h1 class="page-title">Operations Overview</h1>
        
        <div class="barangay-selector" title="Switch Jurisdiction / Barangay">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 21h18"/>
            <path d="M5 21V7l7-4 7 4v14"/>
            <path d="M9 10a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v11"/>
          </svg>
          <span>Brgy. <?php echo htmlspecialchars($barangayName); ?></span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>
      </div>
    </div>

    <div class="topbar-right">
      <!-- Global Search -->
      <div class="search-container">
        <div class="search-input-wrapper">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="global-search" class="search-input" placeholder="Search reports, residents, services...">
          <span class="search-shortcut">⌘ K</span>
        </div>
      </div>

      <!-- Notification Bell -->
      <button class="notif-btn" id="notif-btn" title="<?php echo $unreadNotifs; ?> unread notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if ($unreadNotifs > 0): ?>
        <span class="notif-badge"><?php echo $unreadNotifs; ?></span>
        <?php endif; ?>
      </button>

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
      <div class="date-filter-group">
        <!-- Interactive Date Range Dropdown Button -->
        <button class="date-dropdown-btn" id="date-range-btn" aria-expanded="false" aria-haspopup="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <span id="current-date-range-text"><?php echo date('M j', strtotime('first day of this month')) . ' – ' . date('M j, Y', strtotime('last day of this month')); ?></span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>
        <span class="date-comparison-label" id="date-comparison-text"><?php echo 'Compared to ' . date('M j', strtotime('first day of last month')) . ' – ' . date('M j, Y', strtotime('last day of last month')); ?></span>

        <!-- Functional Date Picker Popover Menu -->
        <div class="date-picker-popover" id="date-picker-popover">
          <div class="date-preset-list">
            <button class="date-preset-item selected" data-preset="this-month" data-label="<?php echo date('M j', strtotime('first day of this month')) . ' – ' . date('M j, Y', strtotime('last day of this month')); ?>" data-comp="<?php echo 'Compared to ' . date('M j', strtotime('first day of last month')) . ' – ' . date('M j, Y', strtotime('last day of last month')); ?>">
              <span>This Month (<?php echo date('M Y'); ?>)</span>
              <span>✓</span>
            </button>
            <button class="date-preset-item" data-preset="last-month" data-label="<?php echo date('M j', strtotime('first day of last month')) . ' – ' . date('M j, Y', strtotime('last day of last month')); ?>" data-comp="<?php echo 'Compared to ' . date('M j', strtotime('first day of -2 months')) . ' – ' . date('M j, Y', strtotime('last day of -2 months')); ?>">
              <span>Last Month (<?php echo date('M Y', strtotime('last month')); ?>)</span>
              <span></span>
            </button>
            <button class="date-preset-item" data-preset="last-7-days" data-label="<?php echo date('M j', strtotime('-6 days')) . ' – ' . date('M j, Y'); ?>" data-comp="<?php echo 'Compared to ' . date('M j', strtotime('-13 days')) . ' – ' . date('M j, Y', strtotime('-7 days')); ?>">
              <span>Last 7 Days</span>
              <span></span>
            </button>
            <button class="date-preset-item" data-preset="last-30-days" data-label="<?php echo date('M j', strtotime('-29 days')) . ' – ' . date('M j, Y'); ?>" data-comp="Compared to previous 30 days">
              <span>Last 30 Days</span>
              <span></span>
            </button>
            <button class="date-preset-item" data-preset="year-to-date" data-label="<?php echo 'Jan 1 – ' . date('M j, Y'); ?>" data-comp="<?php echo 'Compared to Jan 1 – ' . date('M j, Y', strtotime('Jan 1 last year')); ?>">
              <span>Year to Date (<?php echo date('Y'); ?>)</span>
              <span></span>
            </button>
          </div>

          <div class="custom-range-divider"></div>
          <div class="custom-range-title">Custom Date Range</div>
          <div class="custom-range-inputs">
            <div>
              <label style="font-size: 10.5px; color: var(--text-muted); display: block; margin-bottom: 2px;">Start Date</label>
              <input type="date" id="custom-start-date" value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div>
              <label style="font-size: 10.5px; color: var(--text-muted); display: block; margin-bottom: 2px;">End Date</label>
              <input type="date" id="custom-end-date" value="<?php echo date('Y-m-d'); ?>">
            </div>
          </div>
          <button type="button" class="apply-custom-date-btn" id="apply-custom-date-btn">Apply Custom Range</button>
        </div>
      </div>

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
          <span class="step-label">New</span>
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
          <span class="step-label">For validation</span>
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

      <!-- 5. Closed -->
      <div class="pipeline-step">
        <div class="step-icon-box closed">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
        <div class="step-content">
          <span class="step-label">Closed</span>
          <span class="step-value" id="kpi-closed-val"><?php echo $kpi['closed']; ?></span>
          <span class="step-trend">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="18 15 12 9 6 15" transform="rotate(180 12 12)"/>
            </svg>
            <span id="trend-closed-val"><?php echo $kpiTrends['closed']['dir'] === 'up' ? '↑' : '↓'; ?> <?php echo $kpiTrends['closed']['pct']; ?>% vs last month</span>
          </span>
        </div>
      </div>
    </section>

    <!-- Main Dashboard 2-Column Grid -->
    <div class="dashboard-grid">

      <!-- ====================================================================
           LEFT COLUMN (Table + Bottom Charts)
           ==================================================================== -->
      <div class="grid-left">

        <!-- Table: Reports requiring attention -->
        <div class="dashboard-card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h2 class="card-title">Reports requiring attention</h2>
              <span class="count-badge" id="table-row-count-badge">8</span>
            </div>
            <a href="#view-all-reports" class="card-action-link" id="view-all-reports-btn">View all</a>
          </div>

          <div class="table-container">
            <table class="data-table" id="reports-table">
              <thead>
                <tr>
                  <th>Report ID</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Submitted</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned to</th>
                  <th style="width: 36px;"></th>
                </tr>
              </thead>
              <tbody id="reports-table-body">
                <!-- Dynamic rows rendered via JS -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- Card 2: Monthly Reports vs. Previous Period (comparison chart) -->
        <div class="dashboard-card">
          <div class="card-header">
            <h2 class="card-title">Monthly Reports vs. Previous Period</h2>
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
        <div class="dashboard-card">
          <div class="card-header">
            <h2 class="card-title">Reports by category</h2>
            <button class="card-action-btn" id="category-period-btn">
              <span>All time</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>
          </div>

          <div class="chart-container" style="padding-top:12px;">
            <div class="cat-chart-wrap">
              <canvas id="brgyCategoryChart"></canvas>
            </div>
            <div class="cat-chart-footer">
              <div class="cat-chart-trend">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                Trending up by 5.2% this month
              </div>
              <div class="cat-chart-note">Showing reports by category</div>
            </div>
          </div>
        </div>

        <!-- Card 2: Recent Activity Timeline -->
        <div class="dashboard-card">
          <div class="card-header">
            <h2 class="card-title">Recent activity</h2>
            <a href="#view-activity" class="card-action-link" id="view-all-activity-btn">View all</a>
          </div>

          <div class="activity-feed-body" id="activity-feed-container">
            <!-- Dynamic timeline items rendered via JS with Left Date & Donut Rings -->
          </div>
        </div>

      </div>

    </div>

    <!-- Geo Map Section -->
    <div class="geo-map-section">
      <div class="geo-map-card">
        <div class="geo-summary-bar">
          <span class="geo-summary-item"><i class="fas fa-map-pin" style="color:#0E8131;"></i> Barangay: <strong id="geoBgyName"><?php echo htmlspecialchars($barangayName); ?></strong></span>
          <span class="geo-summary-item"><i class="fas fa-certificate" style="color:#0E8131;"></i> Active Cases: <strong id="geoBgyConfirmed">0</strong></span>
          <span class="geo-summary-item"><i class="fas fa-hourglass-half" style="color:#6266ea;"></i> Pending: <strong id="geoBgyPending">0</strong></span>
          <span class="geo-summary-item"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Resolved: <strong id="geoBgyResolved">0</strong></span>
          <span class="geo-summary-item"><i class="fas fa-sync-alt" style="color:#94a3b8;"></i> Updated: <strong id="geoBgyUpdated">--</strong></span>
        </div>

        <div id="geoMap"
             data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php"}'
             style="width:100%;position:relative;">
          <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
        </div>

        <!-- Info Panel (click barangay) -->
        <div class="geo-info-panel" id="geoInfoPanel">
          <div class="geo-info-header">
            <span class="geo-info-name" id="geoPanelName">--</span>
            <span class="geo-info-risk" id="geoPanelRisk">--</span>
            <button class="geo-info-close" id="geoPanelClose"><i class="fas fa-times"></i></button>
          </div>
          <div class="geo-info-row">
            <div class="geo-info-stat">
              <span class="geo-info-stat-label">Confirmed</span>
              <span class="geo-info-stat-value geo-info-confirmed" id="geoPanelConfirmed">0</span>
            </div>
            <div class="geo-info-stat">
              <span class="geo-info-stat-label">Pending</span>
              <span class="geo-info-stat-value geo-info-pending" id="geoPanelPending">0</span>
            </div>
            <div class="geo-info-stat">
              <span class="geo-info-stat-label">Resolved</span>
              <span class="geo-info-stat-value geo-info-resolved" id="geoPanelResolved">0</span>
            </div>
          </div>
          <div class="geo-info-updated" id="geoPanelUpdated">Last updated: --</div>
        </div>

        <!-- Legend -->
        <div class="geo-legend">
          <span class="geo-legend-title">Risk Level</span>
          <span class="geo-legend-item" style="--legend-color:#22c55e;">Low</span>
          <span class="geo-legend-item" style="--legend-color:#eab308;">Medium</span>
          <span class="geo-legend-item" style="--legend-color:#f97316;">High</span>
          <span class="geo-legend-item" style="--legend-color:#ef4444;">Critical</span>
          <button class="geo-legend-refresh" id="geoRefreshBtn" title="Refresh map"><i class="fas fa-sync-alt"></i></button>
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
    <div class="modal-header">
      <h3 class="modal-title">+ Create Incident / Service Report</h3>
      <button class="modal-close-btn" id="close-create-modal-btn">&times;</button>
    </div>
    <form id="create-report-form">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="rpt-category">Report Category</label>
          <select class="form-control" id="rpt-category" required>
            <option value="Animal welfare">🐾 Animal welfare</option>
            <option value="Streetlight">💡 Streetlight</option>
            <option value="Noise complaint">🔊 Noise complaint</option>
            <option value="Road obstruction">🚧 Road obstruction</option>
            <option value="Garbage collection">🗑️ Garbage collection</option>
            <option value="Drainage">💧 Drainage</option>
            <option value="Other">🔄 Other Public Concern</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="rpt-zone">Zone</label>
            <select class="form-control" id="rpt-zone" required>
              <option value="Zone 1">Zone 1</option>
              <option value="Zone 2">Zone 2</option>
              <option value="Zone 3">Zone 3</option>
              <option value="Zone 4">Zone 4</option>
              <option value="Zone 5">Zone 5</option>
              <option value="Zone 6">Zone 6</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="rpt-purok">Purok</label>
            <input type="text" class="form-control" id="rpt-purok" placeholder="e.g. Purok 3" required value="Purok 1">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="rpt-priority">Priority Level</label>
            <select class="form-control" id="rpt-priority">
              <option value="High">High Priority</option>
              <option value="Medium" selected>Medium Priority</option>
              <option value="Low">Low Priority</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="rpt-assignee">Assign To</label>
            <select class="form-control" id="rpt-assignee">
              <option value="Unassigned">Unassigned</option>
              <option value="Bords Bordagul">Bords Bordagul (You)</option>
              <option value="Maria Santos">Maria Santos</option>
              <option value="Jose Dela Cruz">Jose Dela Cruz</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="rpt-desc">Incident Description / Resident Note</label>
          <textarea class="form-control" id="rpt-desc" rows="3" placeholder="Provide incident details, reporter info, or immediate observations..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="cancel-create-modal-btn">Cancel</button>
        <button type="submit" class="btn-primary">Submit Report</button>
      </div>
    </form>
  </div>
</div>

<!-- Report Details Drawer Modal -->
<div class="modal-backdrop" id="report-detail-modal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; color:var(--text-muted);" id="detail-id">RP-2025-0517</span>
        <h3 class="modal-title" id="detail-category" style="margin-top:2px;">Incident Details</h3>
      </div>
      <button class="modal-close-btn" id="close-detail-modal-btn">&times;</button>
    </div>
    <div class="modal-body" id="detail-modal-body">
      <!-- Dynamic detailed summary -->
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-secondary" id="close-detail-footer-btn">Close</button>
      <button type="button" class="btn-primary" id="update-status-btn">Update Status</button>
    </div>
  </div>
</div>

<!-- Floating Action Dropdown Menu for Table Actions (⋮) -->
<div class="quick-dropdown" id="quick-action-dropdown">
  <button class="quick-dropdown-item" data-action="view">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    <span>View Details</span>
  </button>
  <button class="quick-dropdown-item" data-action="assign">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <span>Reassign Officer</span>
  </button>
  <button class="quick-dropdown-item" data-action="progress">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span>Mark In Progress</span>
  </button>
  <button class="quick-dropdown-item" data-action="resolve">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span>Mark Resolved</span>
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
    filtered = filtered.filter(r => r.status.toLowerCase() === filterStatus.toLowerCase());
  }

  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    filtered = filtered.filter(r => 
      r.id.toLowerCase().includes(q) ||
      r.category.toLowerCase().includes(q) ||
      r.location.toLowerCase().includes(q) ||
      r.assignedTo.toLowerCase().includes(q) ||
      r.priority.toLowerCase().includes(q)
    );
  }

  countBadge.textContent = filtered.length;

  if (filtered.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-muted);">
          No reports found matching your criteria.
        </td>
      </tr>
    `;
    return;
  }

  filtered.forEach(report => {
    const tr = document.createElement('tr');
    tr.dataset.id = report.id;

    // Priority class & dot
    const pLow = report.priority.toLowerCase();
    const priorityClass = (pLow === 'high' || pLow === 'critical') ? 'priority-high' : (pLow === 'medium' ? 'priority-medium' : 'priority-low');
    const priorityDotClass = (pLow === 'high' || pLow === 'critical') ? 'high' : (pLow === 'medium' ? 'medium' : 'low');

    // Status class
    const sLow = report.status.toLowerCase();
    let statusClass = 'status-new';
    if (sLow.includes('validation')) statusClass = 'status-validation';
    else if (sLow.includes('progress')) statusClass = 'status-progress';
    else if (sLow.includes('resolved')) statusClass = 'status-resolved';
    else if (sLow.includes('closed')) statusClass = 'status-closed';

    tr.innerHTML = `
      <td>
        <div class="report-id-cell">
          <span class="priority-dot ${priorityDotClass}"></span>
          <span>${report.id}</span>
        </div>
      </td>
      <td class="category-cell">${report.category}</td>
      <td class="location-cell">${report.location}</td>
      <td class="submitted-cell">${report.submitted}</td>
      <td>
        <span class="status-badge ${priorityClass}">${report.priority}</span>
      </td>
      <td>
        <span class="status-badge ${statusClass}">${report.status}</span>
      </td>
      <td class="assignee-cell ${report.assignedTo === 'Unassigned' ? 'unassigned' : ''}">
        ${report.assignedTo}
      </td>
      <td style="text-align: right;">
        <button class="row-action-btn action-dots-btn" data-id="${report.id}" title="Quick actions">⋮</button>
      </td>
    `;

    // Row Click: Open Details
    tr.addEventListener('click', (e) => {
      if (e.target.closest('.action-dots-btn')) return;
      openReportDetails(report.id);
    });

    tbody.appendChild(tr);
  });
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
    ctx.save();
    ctx.textBaseline = 'middle';
    meta.data.forEach(function(bar, i) {
      var left = bar.base;   // start of bar (value axis 0)
      var right = bar.x;     // end of bar (data value)
      var y = bar.y;         // center of the bar row
      ctx.fillStyle = '#ffffff';
      ctx.font = '600 12px Inter, sans-serif';
      ctx.textAlign = 'left';
      ctx.fillText(labels[i], left + 12, y);
      ctx.fillStyle = '#18181b';
      ctx.font = '500 12px Inter, sans-serif';
      ctx.fillText(String(ds.data[i]), right + 10, y);
    });
    ctx.restore();
  }
};

function initCategoryChart() {
  var el = document.getElementById('brgyCategoryChart');
  if (!el || typeof Chart === 'undefined') return;

  if (brgyCharts['brgyCategoryChart']) {
    brgyCharts['brgyCategoryChart'].destroy();
    delete brgyCharts['brgyCategoryChart'];
  }

  Chart.defaults.font.family = 'Inter, sans-serif';

  // Placeholder data — matches the reference style (swap with real counts later)
  var catData = [
    { label: "Animal welfare", count: 186 },
    { label: "Streetlight", count: 305 },
    { label: "Garbage collection", count: 237 },
    { label: "Noise complaint", count: 73 },
    { label: "Road obstruction", count: 209 },
    { label: "Others", count: 214 }
  ];

  brgyCharts['brgyCategoryChart'] = new Chart(el, {
    type: 'bar',
    data: {
      labels: catData.map(function(d) { return d.label; }),
      datasets: [{
        label: 'Reports',
        data: catData.map(function(d) { return d.count; }),
        backgroundColor: '#3B82F6',
        borderRadius: 6,
        barThickness: 28,
        maxBarThickness: 28
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      layout: {
        padding: { right: 48 }
      },
      scales: {
        x: {
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

// Open Details Modal
function openReportDetails(reportId) {
  const report = DASHBOARD_CONFIG.reports.find(r => r.id === reportId);
  if (!report) return;
  currentSelectedReport = report;

  document.getElementById('detail-id').textContent = report.id;
  document.getElementById('detail-category').textContent = report.category;

  const modalBody = document.getElementById('detail-modal-body');
  modalBody.innerHTML = `
    <div style="display:flex; gap:10px; margin-bottom:16px;">
      <span class="status-badge priority-${report.priority.toLowerCase()}">Priority: ${report.priority}</span>
      <span class="status-badge status-${report.status.toLowerCase().replace(' ', '-')}">Status: ${report.status}</span>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; font-size:14px;">
      <div>
        <span style="color:var(--text-muted); font-size:12px; display:block;">Location</span>
        <strong>${report.location}</strong>
      </div>
      <div>
        <span style="color:var(--text-muted); font-size:12px; display:block;">Assigned Officer</span>
        <strong>${report.assignedTo}</strong>
      </div>
      <div>
        <span style="color:var(--text-muted); font-size:12px; display:block;">Date Submitted</span>
        <strong>${report.submitted}</strong>
      </div>
      <div>
        <span style="color:var(--text-muted); font-size:12px; display:block;">Reported By</span>
        <strong>${report.reporter || 'Resident'}</strong>
      </div>
    </div>
    <div style="background:var(--bg-subtle); padding:12px 14px; border-radius:var(--radius-md); border:1px solid var(--border-color-light);">
      <span style="font-size:12px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:4px;">OFFICIAL REPORT LOG / SUMMARY</span>
      <p style="font-size:14px; color:var(--text-main); line-height:1.5;">
        ${report.description || 'No description provided.'}
      </p>
    </div>
  `;

  document.getElementById('report-detail-modal').classList.add('open');
}

/**
 * ==========================================================================
 * FUNCTIONAL DATE RANGE PICKER HANDLERS
 * ==========================================================================
 */
function initDateRangePicker() {
  const dateBtn = document.getElementById('date-range-btn');
  const popover = document.getElementById('date-picker-popover');
  const currentText = document.getElementById('current-date-range-text');
  const compText = document.getElementById('date-comparison-text');
  const presetItems = document.querySelectorAll('.date-preset-item');
  const applyCustomBtn = document.getElementById('apply-custom-date-btn');
  const customStart = document.getElementById('custom-start-date');
  const customEnd = document.getElementById('custom-end-date');

  // Toggle Popover
  dateBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = popover.classList.contains('show');
    if (isOpen) {
      popover.classList.remove('show');
      dateBtn.classList.remove('active');
    } else {
      popover.classList.add('show');
      dateBtn.classList.add('active');
    }
  });

  // Close when clicking outside
  document.addEventListener('click', (e) => {
    if (!popover.contains(e.target) && !dateBtn.contains(e.target)) {
      popover.classList.remove('show');
      dateBtn.classList.remove('active');
    }
  });

  // Handle Preset Click
  presetItems.forEach(item => {
    item.addEventListener('click', () => {
      presetItems.forEach(i => {
        i.classList.remove('selected');
        i.querySelector('span:last-child').textContent = '';
      });
      item.classList.add('selected');
      item.querySelector('span:last-child').textContent = '✓';

      const label = item.dataset.label;
      const comp = item.dataset.comp;
      const preset = item.dataset.preset;

      currentText.textContent = label;
      compText.textContent = comp;

      // Dynamically simulate metric variations per period
      updateDashboardDataForRange(preset, label);

      popover.classList.remove('show');
      dateBtn.classList.remove('active');
      showToast(`Date range set to: ${label}`);
    });
  });

  // Handle Custom Date Range Apply
  if (applyCustomBtn) {
    applyCustomBtn.addEventListener('click', () => {
      const startVal = customStart.value;
      const endVal = customEnd.value;
      if (!startVal || !endVal) {
        showToast('Please select valid start and end dates.');
        return;
      }

      const formatDate = (dStr) => {
        const d = new Date(dStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      };

      const customRangeText = `${formatDate(startVal)} – ${formatDate(endVal)}`;
      currentText.textContent = customRangeText;
      compText.textContent = `Compared to previous period`;

      presetItems.forEach(i => {
        i.classList.remove('selected');
        i.querySelector('span:last-child').textContent = '';
      });

      updateDashboardDataForRange('custom', customRangeText);
      popover.classList.remove('show');
      dateBtn.classList.remove('active');
      showToast(`Applied custom date range: ${customRangeText}`);
    });
  }
}

// Function to update metric values when date range changes
function updateDashboardDataForRange(rangeKey, label) {
  // Date range changes only update the label for now — all figures come
  // from real database records and are refreshed on reload.
  const vals = document.querySelectorAll('.step-value');
  vals.forEach(v => {
    v.style.transform = 'scale(1.1)';
    setTimeout(() => v.style.transform = 'scale(1)', 200);
  });
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

function initComparisonChart() {
  var el = document.getElementById('brgyComparisonChart');
  if (!el || typeof Chart === 'undefined') return;

  if (brgyCharts['brgyComparisonChart']) {
    brgyCharts['brgyComparisonChart'].destroy();
    delete brgyCharts['brgyComparisonChart'];
  }

  Chart.defaults.font.family = 'Inter, sans-serif';

  var cmp = (DASHBOARD_CONFIG.comparisonChart || {});
  var labels = cmp.labels || ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var previous = cmp.previous || [];
  var actual = cmp.actual || [];
  var rgb = brgyHexToRgb('#18181b');

  brgyCharts['brgyComparisonChart'] = new Chart(el, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Previous',
          data: previous,
          borderColor: '#737373',
          borderWidth: 1,
          borderDash: [4,4],
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#737373',
          pointHoverBorderColor: '#737373',
          pointHoverBorderWidth: 2,
          tension: 0.35,
          fill: false
        },
        {
          label: 'This period',
          data: actual,
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
      animation: brgyAnimOpts(),
      interaction: { mode: 'index', intersect: false },
      onHover: function(e, active) { if (!active.length) e.native.target.style.cursor = 'default'; else e.native.target.style.cursor = 'pointer'; },
      scales: {
        y: {
          beginAtZero: true,
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
    plugins: [brgyLineDrawPlugin, brgyCrosshairPlugin]
  });
}

// Document Ready Initialization
document.addEventListener('DOMContentLoaded', () => {
  // Initial renders
  renderReportsTable();
  renderActivities();
  initDateRangePicker();
  initComparisonChart();
  initCategoryChart();

  // Global Search
  const globalSearch = document.getElementById('global-search');
  if (globalSearch) {
    globalSearch.addEventListener('input', (e) => {
      renderReportsTable(activeFilter, e.target.value);
    });
  }

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
      const zone = document.getElementById('rpt-zone').value;
      const purok = document.getElementById('rpt-purok').value;
      const priority = document.getElementById('rpt-priority').value;
      const assignee = document.getElementById('rpt-assignee').value;

      const newReport = {
        id: newId,
        category: category,
        location: `${zone}, ${purok}`,
        submitted: "Just now",
        priority: priority,
        status: "New",
        assignedTo: assignee
      };

      DASHBOARD_CONFIG.reports.unshift(newReport);
      DASHBOARD_CONFIG.metrics.new += 1;
      document.getElementById('kpi-new-val').textContent = DASHBOARD_CONFIG.metrics.new;

      // Add to activity with day and donut ring
      const now = new Date();
      DASHBOARD_CONFIG.activities.unshift({
        id: `act-${Date.now()}`,
        day: String(now.getDate()),
        month: now.toLocaleDateString('en-US', { month: 'short' }),
        color: "teal",
        reportId: newId,
        action: "created",
        detail: `${category} · ${zone}, ${purok}`,
        time: "Just now"
      });

      renderReportsTable(activeFilter);
      renderActivities();
      closeCreateModal();
      showToast(`Report ${newId} created successfully.`);
    });
  }

  // Details Modal Close
  const detailModal = document.getElementById('report-detail-modal');
  const closeDetailBtn = document.getElementById('close-detail-modal-btn');
  const closeDetailFooterBtn = document.getElementById('close-detail-footer-btn');
  const updateStatusBtn = document.getElementById('update-status-btn');

  const closeDetailModal = () => {
    if (detailModal) detailModal.classList.remove('open');
  };

  if (closeDetailBtn) closeDetailBtn.addEventListener('click', closeDetailModal);
  if (closeDetailFooterBtn) closeDetailFooterBtn.addEventListener('click', closeDetailModal);

  if (updateStatusBtn) {
    updateStatusBtn.addEventListener('click', () => {
      if (currentSelectedReport) {
        currentSelectedReport.status = currentSelectedReport.status === 'Resolved' ? 'Closed' : 'Resolved';
        renderReportsTable(activeFilter);
        closeDetailModal();
        showToast(`Status for ${currentSelectedReport.id} updated to ${currentSelectedReport.status}.`);
      }
    });
  }

  // Quick Action Dropdown Menu
  const quickDropdown = document.getElementById('quick-action-dropdown');
  document.addEventListener('click', (e) => {
    const dotsBtn = e.target.closest('.action-dots-btn');
    if (dotsBtn) {
      const reportId = dotsBtn.dataset.id;
      currentSelectedReport = DASHBOARD_CONFIG.reports.find(r => r.id === reportId);
      const rect = dotsBtn.getBoundingClientRect();
      quickDropdown.style.top = `${rect.bottom + window.scrollY + 4}px`;
      quickDropdown.style.left = `${rect.right - 170 + window.scrollX}px`;
      quickDropdown.classList.add('show');
    } else if (!e.target.closest('#quick-action-dropdown')) {
      if (quickDropdown) quickDropdown.classList.remove('show');
    }
  });

  // Handle Quick Dropdown Actions
  const dropdownItems = document.querySelectorAll('.quick-dropdown-item');
  dropdownItems.forEach(item => {
    item.addEventListener('click', () => {
      const action = item.dataset.action;
      if (!currentSelectedReport) return;
      
      if (action === 'view') {
        openReportDetails(currentSelectedReport.id);
      } else if (action === 'assign') {
        currentSelectedReport.assignedTo = DASHBOARD_CONFIG.user.name;
        showToast(`Assigned ${currentSelectedReport.id} to ${DASHBOARD_CONFIG.user.name}.`);
      } else if (action === 'progress') {
        currentSelectedReport.status = "In progress";
        showToast(`Marked ${currentSelectedReport.id} as In progress.`);
      } else if (action === 'resolve') {
        currentSelectedReport.status = "Resolved";
        showToast(`Marked ${currentSelectedReport.id} as Resolved.`);
      }
      
      quickDropdown.classList.remove('show');
      renderReportsTable(activeFilter);
    });
  });

  // Export Data Action
  const exportBtn = document.getElementById('export-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const csvRows = [
        ["Report ID", "Category", "Location", "Submitted", "Priority", "Status", "Assigned To"]
      ];
      DASHBOARD_CONFIG.reports.forEach(r => {
        csvRows.push([r.id, r.category, r.location, r.submitted, r.priority, r.status, r.assignedTo]);
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
  var panelClose = document.getElementById('geoPanelClose');
  var refreshBtn = document.getElementById('geoRefreshBtn');
  if (!mapEl) return;

  var BRGY_ID = DASHBOARD_CONFIG.barangayId;
  var BRGY_NAME = DASHBOARD_CONFIG.barangayName;
  var fitted = false;

  function setText(id, v) {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  }

  function bbFromFeature(f) {
    var coords = [];
    var geom = f.geometry;
    if (!geom) return [[120.96,14.30],[120.97,14.31]];
    if (geom.type === 'Polygon') {
      coords = geom.coordinates[0];
    } else if (geom.type === 'MultiPolygon') {
      coords = geom.coordinates[0][0];
    }
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

  function setupBrgyOnly(map, geojson) {
    var target = findTarget(geojson.features || []);
    if (!target) return;

    // Filter source to ONLY the current barangay
    var filtered = { type: 'FeatureCollection', features: [target] };
    var src = map.getSource('barangays');
    if (src) {
      // Remove existing layers that reference the source
      ['barangay-fill','barangay-outline','barangay-label','geo-brgy-label'].forEach(function(lid) {
        if (map.getLayer(lid)) map.removeLayer(lid);
      });
      if (map.getSource('brgy-labels')) {
        if (map.getLayer('geo-brgy-label')) map.removeLayer('geo-brgy-label');
        map.removeSource('brgy-labels');
      }
      map.removeSource('barangays');
    }

    // Add filtered source
    map.addSource('barangays', { type: 'geojson', data: filtered, promoteId: 'id' });

    // Add label source (centroid of target)
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

    // Add layers
    map.addLayer({ id: 'barangay-fill', type: 'fill', source: 'barangays',
      paint: { 'fill-color': '#22c55e', 'fill-opacity': 0.5 } });
    map.addLayer({ id: 'barangay-outline', type: 'line', source: 'barangays',
      paint: { 'line-color': '#ffffff', 'line-width': 2.5, 'line-opacity': 0.9 } });
    map.addLayer({ id: 'geo-brgy-label', type: 'symbol', source: 'brgy-labels',
      layout: { 'text-field': ['get','name'], 'text-size': 11, 'text-anchor': 'center', 'text-allow-overlap': false },
      paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 2 } });

    // Fit to barangay bounds
    var bounds = bbFromFeature(target);
    map.fitBounds(bounds, { padding: 60, maxZoom: 14, duration: 800 });

    // Update summary stats
    var p = target.properties || {};
    setText('geoBgyConfirmed', p.confirmed || 0);
    setText('geoBgyPending', p.pending || 0);
    setText('geoBgyResolved', p.resolved || 0);
    setText('geoBgyUpdated', p.lastUpdated ? new Date(p.lastUpdated).toLocaleString() : '--');

    // Disable MapCN auto-refresh so it doesn't override our filtered source
    if (window.MapCN && window.MapCN.barangayOverlay) {
      window.MapCN.barangayOverlay.refresh = function() {
        fetchGeojson().then(function(data) {
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
  }

  if (panelClose) {
    panelClose.addEventListener('click', function() {
      document.getElementById('geoInfoPanel').classList.remove('open');
    });
  }
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function() {
      if (window.MapCN && window.MapCN.barangayOverlay) {
        window.MapCN.barangayOverlay.refresh();
      }
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
      // Stop MapCN's internal 30s auto-refresh (it resets all 75 barangays)
      if (window.MapCN && window.MapCN.barangayOverlay) {
        window.MapCN.barangayOverlay.destroy();
      }
      fetchGeojson().then(function(geojson) {
        setupBrgyOnly(map, geojson);
      });
    });
  });
})();
</script>

</body>
</html>
