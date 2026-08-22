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

    /* ── KPI Cards (matching resident style) ── */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
    .kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: box-shadow 0.2s, transform 0.2s; }
    .kpi-card:hover { box-shadow: 0 1px 3px rgba(0,0,0,0.04); transform: none; }
    .kpi-label { font-size: 0.78rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
    .kpi-value { font-size: 1.75rem; font-weight: 800; color: #0f172a; font-family: 'Montserrat', sans-serif; margin-top: 4px; }
    .kpi-change { display:flex; align-items:center; gap:4px; margin-top:6px; font-size:0.72rem; }
    .kpi-arrow { font-size:0.55rem; }
    .kpi-arrow.up { color:#059669; }
    .kpi-arrow.down { color:#dc2626; }
    .kpi-pct { font-weight:700; color:#0f172a; }
    .kpi-sub { color:#94a3b8; font-weight:400; margin-left:2px; }
    .kpi-period-bar { display:flex; align-items:center; gap:10px; margin-bottom:1rem; }
    .kpi-period-label { font-size:0.72rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.03em; }
    .kpi-period-btns { display:flex; gap:4px; }
    .kpi-period-btn { padding:4px 14px; font-size:0.72rem; font-weight:600; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#64748b; cursor:pointer; transition:all 0.15s; }
    .kpi-period-btn:hover { border-color:#cbd5e1; background:#f8fafc; }
    .kpi-period-btn.active { border-color:#0E8131; background:#ecfdf5; color:#0E8131; }

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

    /* â”€â”€ Review Modal â”€â”€ */
    .review-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .review-detail-grid .rd-label { font-size: 0.72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
    .review-detail-grid .rd-value { font-size: 0.95rem; color: #0f172a; font-weight: 600; }
    .review-detail-section { margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; }
    .review-detail-section:last-of-type { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .review-section-title { font-size: 0.8rem; font-weight: 700; color: #0E8131; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 1rem; display: flex; align-items: center; gap: 8px; }
    .review-actions { display: flex; gap: 1rem; margin-top: 1.5rem; justify-content: flex-end; }
    .review-photo-wrap { margin-top: 1rem; }
    .review-photo-wrap img { max-height: 280px; max-width: 100%; border-radius: 10px; border: 1px solid #e2e8f0; cursor: pointer; }

    /* â”€â”€ Confirmation Summary â”€â”€ */
    .confirmation-summary { background: #f8fafc; border-radius: 10px; padding: 1.25rem; border: 1px solid #e2e8f0; margin-bottom: 1rem; }
    .confirmation-summary .cs-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e2e8f0; font-size: 0.88rem; }
    .confirmation-summary .cs-row:last-child { border-bottom: none; }
    .confirmation-summary .cs-label { color: #64748b; font-weight: 500; }
    .confirmation-summary .cs-value { color: #0f172a; font-weight: 600; text-align: right; }

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
  .profile-settings-wrapper .ps-profile-name { font-size:15px; font-weight:700; }
  .profile-settings-wrapper .ps-profile-sub { font-size:13px; color:#6b7280; margin-top:2px; }
  .profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
  .profile-settings-wrapper .ps-card-header h2 { font-size:16px; font-weight:700; margin:0; }
  .profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-size:13px; font-weight:600; color:#1f2328; cursor:pointer; transition:background .15s,border-color .15s; }
  .profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
  .profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
  .profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
  .profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
  .profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
  .profile-settings-wrapper .ps-field label { display:block; font-size:13px; color:#4b5563; margin-bottom:6px; }
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
  .profile-settings-wrapper .ps-btn { padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; border:1px solid transparent; }
  .profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
  .profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
  .profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
  .profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
  .profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
  .profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
  .profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
  .profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
  .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
  .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
  .profile-dropdown.open { display:block; }
  .profile-dropdown .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
  .profile-dropdown .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
  .profile-dropdown .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
  .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
  .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }
  .main-content { padding: 1.75rem 2rem !important; }
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

    <div class="welcome-banner">
      <div class="welcome-text">
        <p class="welcome-label">Good day,</p>
        <h1 class="welcome-name"><?php echo htmlspecialchars($fullName); ?></h1>
        <p class="welcome-sub">
          Brgy. <?php echo htmlspecialchars($barangayName); ?>, Dasmari&ntilde;as City
          &nbsp;&middot;&nbsp;
          <i class="fas fa-user-shield"></i> Barangay Officer
        </p>
      </div>
      <div class="welcome-date">
        <div class="date-card">
          <span class="date-day" id="todayDay"></span>
          <span class="date-month" id="todayMonth"></span>
          <span class="date-year" id="todayYear"></span>
        </div>
      </div>
    </div>

    <!-- Period Toggle -->
    <div class="kpi-period-bar">
      <span class="kpi-period-label">vs previous month</span>
      <div class="kpi-period-btns">
        <button class="kpi-period-btn" data-period="1">Day</button>
        <button class="kpi-period-btn" data-period="7">Week</button>
        <button class="kpi-period-btn active" data-period="30">Month</button>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-content">
          <div class="kpi-label">Pending Reports</div>
          <div class="kpi-value" data-kpi="pending"><?php echo $stats['pending']; ?></div>
          <div class="kpi-change" data-kpi-change="pending">
            <span class="kpi-arrow <?php echo $brgyKpiDir['pending']; ?>"><?php echo $brgyKpiDir['pending'] === 'up' ? '&#9650;' : '&#9660;'; ?></span>
            <span class="kpi-pct"><?php echo abs($brgyKpiPct['pending']); ?>%</span>
            <span class="kpi-sub">vs prev 30 days</span>
          </div>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-content">
          <div class="kpi-label">Validated</div>
          <div class="kpi-value" data-kpi="validated"><?php echo $stats['validated']; ?></div>
          <div class="kpi-change" data-kpi-change="validated">
            <span class="kpi-arrow <?php echo $brgyKpiDir['validated']; ?>"><?php echo $brgyKpiDir['validated'] === 'up' ? '&#9650;' : '&#9660;'; ?></span>
            <span class="kpi-pct"><?php echo abs($brgyKpiPct['validated']); ?>%</span>
            <span class="kpi-sub">vs prev 30 days</span>
          </div>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-content">
          <div class="kpi-label">Confirmed</div>
          <div class="kpi-value" data-kpi="confirmed"><?php echo $stats['confirmed']; ?></div>
          <div class="kpi-change" data-kpi-change="confirmed">
            <span class="kpi-arrow <?php echo $brgyKpiDir['confirmed']; ?>"><?php echo $brgyKpiDir['confirmed'] === 'up' ? '&#9650;' : '&#9660;'; ?></span>
            <span class="kpi-pct"><?php echo abs($brgyKpiPct['confirmed']); ?>%</span>
            <span class="kpi-sub">vs prev 30 days</span>
          </div>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-content">
          <div class="kpi-label">Rejected</div>
          <div class="kpi-value" data-kpi="rejected"><?php echo $stats['rejected']; ?></div>
          <div class="kpi-change" data-kpi-change="rejected">
            <span class="kpi-arrow <?php echo $brgyKpiDir['rejected']; ?>"><?php echo $brgyKpiDir['rejected'] === 'up' ? '&#9650;' : '&#9660;'; ?></span>
            <span class="kpi-pct"><?php echo abs($brgyKpiPct['rejected']); ?>%</span>
            <span class="kpi-sub">vs prev 30 days</span>
          </div>
        </div>
      </div>
    </div>

    <?php if (count($reports) > 0): ?>
    <!-- Split Row: Report Activity (left) + Reports by Category (right) -->
    <div class="analytics-grid">
      <div class="analytics-left">
        <div class="chart-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <h3 style="margin:0;font-size:0.92rem;font-weight:700;color:#0f172a;">Report Activity</h3>
            <select id="yearSelect" style="padding:4px 10px;font-size:0.8rem;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-weight:600;cursor:pointer;outline:none;">
              <?php foreach ($availableYears as $y): ?>
              <option value="<?php echo $y; ?>" <?php echo $y === 2025 ? 'selected' : ''; ?>><?php echo $y; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
          <div id="rabiesAdvisory" class="rabies-advisory" style="display:none;margin-top:10px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:0.75rem;color:#991b1b;line-height:1.5;align-items:flex-start;gap:8px;">
            <i class="fas fa-exclamation-triangle" style="color:#dc2626;margin-top:1px;flex-shrink:0;"></i>
            <div><strong>Inspection Advisory:</strong> When report activity reaches 90% or higher of the monitoring threshold, DCVS is advised to conduct a general rabies inspection, strengthen surveillance in affected barangays, and coordinate appropriate response measures to help prevent further spread.</div>
          </div>
        </div>
      </div>
      <div class="analytics-right">
        <?php
        $catTotal = array_sum($categoryCounts);
        $catColors = ['stray' => '#6266ea', 'rabies' => '#ee4b4b', 'injured' => '#f2a613', 'dead' => '#9aa4ae'];
        $cx = 280; $cy = 200; $R = 155; $r = 90;
        $gap = 3;
        $segments = [];
        $liveKeys = [];
        foreach (['stray', 'rabies', 'injured', 'dead'] as $k) {
            if (($categoryCounts[$k] ?? 0) > 0) $liveKeys[] = $k;
        }
        $numLive = count($liveKeys);
        $adjustedTotal = $numLive > 0 ? 360 - ($numLive * $gap) : 360;
        $startAngle = $numLive > 0 ? -90 + ($gap / 2) : -90;
        foreach (['stray', 'rabies', 'injured', 'dead'] as $key) {
            $val = $categoryCounts[$key] ?? 0;
            if ($val <= 0 || $catTotal <= 0) continue;
            $sweep = ($val / $catTotal) * $adjustedTotal;
            $endAngle = $startAngle + $sweep;
            $sRad = deg2rad($startAngle); $eRad = deg2rad($endAngle);
            $ox1 = $cx + $R * cos($sRad); $oy1 = $cy + $R * sin($sRad);
            $ox2 = $cx + $R * cos($eRad); $oy2 = $cy + $R * sin($eRad);
            $ix1 = $cx + $r * cos($sRad); $iy1 = $cy + $r * sin($sRad);
            $ix2 = $cx + $r * cos($eRad); $iy2 = $cy + $r * sin($eRad);
            $largeArc = $sweep > 180 ? 1 : 0;
            $path = sprintf("M%.2f,%.2f A%d,%d 0 %d,1 %.2f,%.2f L%.2f,%.2f A%d,%d 0 %d,0 %.2f,%.2f Z",
                $ox1, $oy1, $R, $R, $largeArc, $ox2, $oy2,
                $ix2, $iy2, $r, $r, $largeArc, $ix1, $iy1);
            $midAngle = $startAngle + $sweep / 2;
            $midRad = deg2rad($midAngle);
            $dx = 14 * cos($midRad);
            $dy = 14 * sin($midRad);
            $segments[] = ['key' => $key, 'path' => $path, 'color' => $catColors[$key], 'dx' => $dx, 'dy' => $dy];
            $startAngle = $endAngle + $gap;
        }
        ?>
        <div class="chart-card" style="display:flex;flex-direction:column;">
          <h3 class="brgy-title" style="margin-bottom:2px;">Reports by Category</h3>
          <p class="brgy-desc" style="margin-bottom:12px;">&nbsp;</p>
          <div style="display:flex;justify-content:center;align-items:center;flex:1;">
            <svg viewBox="0 0 600 430" style="width:100%;max-width:340px;height:auto;" xmlns="http://www.w3.org/2000/svg">
              <?php if ($catTotal > 0): ?>
              <?php foreach ($segments as $seg): ?>
              <g class="segment <?php echo $seg['key']; ?>" style="--dx:<?php echo round($seg['dx'],1); ?>px;--dy:<?php echo round($seg['dy'],1); ?>px">
                <path d="<?php echo $seg['path']; ?>" fill="<?php echo $seg['color']; ?>"/>
              </g>
              <?php endforeach; ?>
              <?php else: ?>
              <circle cx="280" cy="200" r="155" fill="#f1f5f9"/>
              <?php endif; ?>
              <circle cx="280" cy="200" r="90" fill="#ffffff"/>
              <text x="280" y="190" text-anchor="middle" style="font-size:30px;font-weight:800;fill:#0f172a;font-family:Montserrat,sans-serif;"><?php echo $catTotal; ?></text>
              <text x="280" y="216" text-anchor="middle" style="font-size:13px;fill:#94a3b8;font-family:Inter,sans-serif;font-weight:500;">Total</text>
            </svg>
          </div>
          <div style="display:flex;flex-direction:column;gap:2px;margin-top:14px;padding:0 2px;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;">
              <span style="display:flex;align-items:center;gap:7px;font-size:13px;color:#334155;font-weight:500;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#6266ea;"></span> Stray
              </span>
              <span style="font-weight:700;color:#0f172a;font-size:13px;"><?php echo $categoryCounts['stray'] ?? 0; ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;">
              <span style="display:flex;align-items:center;gap:7px;font-size:13px;color:#334155;font-weight:500;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ee4b4b;"></span> Rabies
              </span>
              <span style="font-weight:700;color:#0f172a;font-size:13px;"><?php echo $categoryCounts['rabies'] ?? 0; ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;">
              <span style="display:flex;align-items:center;gap:7px;font-size:13px;color:#334155;font-weight:500;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f2a613;"></span> Injured
              </span>
              <span style="font-weight:700;color:#0f172a;font-size:13px;"><?php echo $categoryCounts['injured'] ?? 0; ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;">
              <span style="display:flex;align-items:center;gap:7px;font-size:13px;color:#334155;font-weight:500;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#9aa4ae;"></span> Dead
              </span>
              <span style="font-weight:700;color:#0f172a;font-size:13px;"><?php echo $categoryCounts['dead'] ?? 0; ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ===== Geo-Mapping Module ===== -->
    <div class="geo-map-section">
      <div class="geo-map-card">
        <!-- Summary bar -->
        <div class="geo-summary-bar">
          <span class="geo-summary-item"><i class="fas fa-certificate" style="color:#0E8131;"></i> Confirmed: <strong id="geoTotalConfirmed">0</strong></span>
          <span class="geo-summary-item"><i class="fas fa-map-marker-alt" style="color:#6266ea;"></i> Affected: <strong id="geoAffectedBgy">0 / 75</strong></span>
          <span class="geo-summary-item"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Highest Risk: <strong id="geoHighestRisk">--</strong></span>
          <span class="geo-summary-item"><i class="fas fa-sync-alt" style="color:#94a3b8;"></i> Updated: <strong id="geoLastRefresh">--</strong></span>
          <span class="geo-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="geoSearch" placeholder="Search barangay..." oninput="MapCN.barangayOverlay.search(this.value)">
          </span>
        </div>
        <!-- Map container -->
        <div id="geoMap" data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php"}' style="width:100%;height:520px;position:relative;">
          <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
        </div>
        <!-- Slide-in Info Panel -->
        <div class="geo-info-panel" id="geoInfoPanel">
          <div class="geo-panel-header">
            <h3><span id="geoPanelName">Barangay</span></h3>
            <button class="geo-panel-close" onclick="MapCN.barangayOverlay.closePanel()">&times;</button>
          </div>
          <div class="geo-panel-body">
            <div style="margin-bottom:1rem;">
              <span class="geo-panel-risk-badge" id="geoPanelRisk" style="background:#dcfce7;color:#166534;">Low</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Confirmed Cases</span>
              <span class="geo-panel-stat-value" id="geoPanelConfirmed">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Pending Reports</span>
              <span class="geo-panel-stat-value" id="geoPanelPending">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Resolved Cases</span>
              <span class="geo-panel-stat-value" id="geoPanelResolved">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Last Updated</span>
              <span class="geo-panel-stat-value" id="geoPanelUpdated">--</span>
            </div>
          </div>
        </div>
        <!-- Legend -->
        <div class="geo-legend">
          <span style="font-weight:600;color:#0f172a;font-size:0.8rem;">Risk Level:</span>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#22c55e;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">0 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#eab308;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">1–5 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#f97316;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">6–15 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#ef4444;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">16+ cases</span></div>
          <div style="margin-left:auto;">
            <button class="action-btn-sm blue" onclick="MapCN.barangayOverlay.refresh()"><i class="fas fa-sync-alt"></i> Refresh</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Reports Preview -->
    <div class="section-header">
      <h2><i class="fas fa-file-medical-alt"></i> Recent Reports</h2>
      <button class="action-btn-sm blue" onclick="switchSection('reports')">View All <i class="fas fa-arrow-right"></i></button>
    </div>
    <div class="table-card">
      <table class="data-table" id="previewTable">
        <thead>
          <tr>
            <th style="width:40px;">No.</th>
            <th>Report ID</th>
            <th>Resident Name</th>
            <th>Category</th>
            <th>Date Submitted</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $preview = array_slice($reports, 0, 5); $no = 1; ?>
          <?php if (empty($preview)): ?>
            <tr><td colspan="7" class="table-empty">No reports yet.</td></tr>
          <?php else: ?>
            <?php foreach ($preview as $r): ?>
            <tr>
              <td style="text-align:center;color:#94a3b8;font-size:0.8rem;"><?php echo $no++; ?></td>
              <td><span class="id-mono"><?php echo htmlspecialchars($r['reference_id'] ?? '#RPT-'.$r['report_id']); ?></span></td>
              <td style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars($r['reporter_name']); ?></td>
              <td><span class="status-pill scheduled"><?php echo ucfirst($r['report_type']); ?></span></td>
              <td style="white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
              <td><span class="status-pill <?php echo $r['status']; ?>"><?php echo statusLabel($r['status']); ?></span></td>
              <td>
                <?php if ($r['status'] === 'pending'): ?>
                  <button class="action-btn-sm green" onclick="openReview(<?php echo $r['report_id']; ?>)"><i class="fas fa-search"></i> Review</button>
                <?php elseif ($r['status'] === 'validated'): ?>
                  <span class="status-pill validated">Awaiting DCVS</span>
                <?php elseif ($r['status'] === 'confirmed'): ?>
                  <span class="status-pill confirmed">Confirmed</span>
                <?php elseif ($r['status'] === 'rejected'): ?>
                  <span class="status-pill rejected">Rejected</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- â•â•â• REPORTS â•â•â• -->
  <section id="reports" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title"><?php echo htmlspecialchars($barangayName); ?> Reports</h1>
        <p class="page-subtitle">Review, validate, and forward reports to DCVS.</p>
      </div>
    </div>

    <div class="table-card">
      <div class="table-header-bar">
        <div class="table-title-group">
          <h2>All Reports</h2>
          <span class="table-count-badge"><?php echo count($reports); ?> total</span>
        </div>
        <div class="filter-bar" style="margin-bottom:0;">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="reportSearch" placeholder="Search by name, ID, location..." onkeyup="filterReports()">
          </div>
          <select id="catFilter" onchange="filterReports()" class="filter-select">
            <option value="">All Categories</option>
            <option value="stray">Stray</option>
            <option value="rabies">Rabies</option>
            <option value="injured">Injured</option>
            <option value="dead">Dead</option>
          </select>
          <select id="statusFilter" onchange="filterReports()" class="filter-select">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="validated">Validated by Barangay</option>
            <option value="confirmed">Confirmed by DCVS</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
      </div>
      <table class="data-table" id="reportsTable">
        <thead>
          <tr>
            <th style="width:40px;">No.</th>
            <th>Case ID</th>
            <th>Resident Name</th>
            <th>Category</th>
            <th>Date Submitted</th>
            <th>Current Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($reports)): ?>
            <tr><td colspan="7" class="table-empty"><i class="fas fa-inbox"></i>No reports submitted yet.</td></tr>
          <?php else: ?>
            <?php $caseSeq = 0; ?>
            <?php foreach ($reports as $i => $r): ?>
            <?php if (!empty($r['case_id'])) $caseSeq++; ?>
            <tr data-status="<?php echo $r['status']; ?>">
              <td style="text-align:center;color:#94a3b8;font-size:0.8rem;"><?php echo $i + 1; ?></td>
              <td><span class="id-mono"><?php echo !empty($r['case_id']) ? '#CASE-'.str_pad($caseSeq, 4, '0', STR_PAD_LEFT) : htmlspecialchars($r['reference_id'] ?? '#RPT-'.$r['report_id']); ?></span></td>
              <td style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars($r['reporter_name']); ?></td>
              <td><span class="status-pill scheduled"><?php echo ucfirst($r['report_type']); ?></span></td>
              <td style="white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
              <td><span class="status-pill <?php echo $r['status']; ?>"><?php echo statusLabel($r['status']); ?></span></td>
              <td>
                <?php if ($r['status'] === 'pending'): ?>
                  <button class="action-btn-sm green" onclick="openReview(<?php echo $r['report_id']; ?>)"><i class="fas fa-search"></i> View Details</button>
                <?php elseif ($r['status'] === 'validated'): ?>
                  <span class="status-pill validated">Awaiting DCVS</span>
                <?php elseif ($r['status'] === 'confirmed'): ?>
                  <span class="status-pill confirmed">Confirmed</span>
                <?php elseif ($r['status'] === 'rejected'): ?>
                  <span class="status-pill rejected">Rejected</span>
                  <?php if (!empty($r['rejection_reason'])): ?>
                    <br><span class="why-link" data-report='<?php echo htmlspecialchars(json_encode([
                        'ref_id' => $r['reference_id'] ?? '#RPT-'.$r['report_id'],
                        'report_type' => ucfirst($r['report_type']),
                        'created_at' => date('M d, Y', strtotime($r['created_at'])),
                        'barangay_name' => $barangayName,
                        'reporter_name' => $r['reporter_name'],
                        'rejection_reason' => $r['rejection_reason'],
                        'rejected_by' => $r['rejected_by_name'] ?? 'DCVS Staff',
                        'rejected_at' => $r['confirmed_at'] ? date('M d, Y \a\t h:i A', strtotime($r['confirmed_at'])) : 'N/A'
                    ], JSON_HEX_APOS | JSON_HEX_QUOT)); ?>" onclick="openRejectionModal(this)">Why?</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ────────── VISIT OUR OFFICE ────────── -->
  <!-- ────────── ACCOUNT SETTINGS ────────── -->
  <section id="account-settings" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title" style="font-size:22px;font-weight:800;margin:0;">My Profile</h1>
        <p class="page-subtitle">Manage your barangay profile and account information</p>
      </div>
    </div>

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
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-search"></i> Review Report</h2>
      <button class="modal-close" onclick="closeModal('reviewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="reviewModalBody">
      <!-- populated by JS -->
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

// Today's date for welcome banner
(function() {
  const today = new Date();
  const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const dayEl = document.getElementById('todayDay');
  const monthEl = document.getElementById('todayMonth');
  const yearEl = document.getElementById('todayYear');
  if (dayEl) dayEl.textContent = today.getDate();
  if (monthEl) monthEl.textContent = monthNames[today.getMonth()];
  if (yearEl) yearEl.textContent = today.getFullYear();
})();

// â”€â”€ Notifications â”€â”€
function markAllRead(e) {
  e.preventDefault();
  fetch('mark-notifications-read.php?type=barangay').then(() => {
    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
    const badge = document.querySelector('.notif-badge');
    if (badge) badge.remove();
  });
}


// â”€â”€ Filter Reports â”€â”€
function filterReports() {
  const search = document.getElementById('reportSearch')?.value.toLowerCase() || '';
  const cat = document.getElementById('catFilter')?.value || '';
  const status = document.getElementById('statusFilter')?.value || '';
  document.querySelectorAll('#reportsTable tbody tr').forEach(function(row) {
    if (row.getAttribute('data-status') === null) return;
    const text = row.textContent.toLowerCase();
    const rowStatus = row.getAttribute('data-status');
    const rowCat = row.querySelector('td:nth-child(4)')?.textContent.toLowerCase().trim() || '';
    const matchSearch = !search || text.includes(search);
    const matchCat = !cat || rowCat === cat;
    const matchStatus = !status || rowStatus === status;
    row.style.display = matchSearch && matchCat && matchStatus ? '' : 'none';
  });
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
        'reporter_address' => $r['address'] ?? '',
        'rejection_reason' => $r['rejection_reason'] ?? ''
    ];
}, $reports)); ?>;

function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c; }); }

function fmtDate(d) { return new Date(d).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'}); }

function statusBadge(status) {
  const lb = {pending:'Pending',validated:'Validated by Barangay',confirmed:'Confirmed by DCVS',rejected:'Rejected',resolved:'Resolved'};
  return `<span class="status-pill ${status}">${lb[status]||status}</span>`;
}

// â”€â”€ View Report Modal â”€â”€
function openReview(id) {
  closeModal('confirmModal');
  closeModal('rejectModal');
  const r = reportsData.find(d => d.report_id === id);
  if (!r) return;
  const photoHtml = r.photo_url
    ? `<div style="margin-top:0.75rem;"><div class="rd-label">Attachments</div>
       <div style="margin-top:4px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;display:inline-block;cursor:pointer;" onclick="window.open('${escapeHtml(r.photo_url)}')">
         <img src="${escapeHtml(r.photo_url)}" alt="Report Photo" style="max-height:280px;max-width:100%;display:block;">
       </div>
       <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Click to view full image</div></div>`
    : '<div style="margin-top:0.75rem;"><div class="rd-label">Attachments</div><p style="color:#94a3b8;font-size:0.82rem;">No photo uploaded.</p></div>';

  document.getElementById('reviewModalBody').innerHTML = `
    <div class="review-detail-section">
      <h4 class="review-section-title"><i class="fas fa-user"></i> Resident Information</h4>
      <div class="review-detail-grid">
        <div><div class="rd-label">Name</div><div class="rd-value">${escapeHtml(r.reporter_name)}</div></div>
        <div><div class="rd-label">Contact Number</div><div class="rd-value">${escapeHtml(r.reporter_phone || r.reporter_email)}</div></div>
        <div><div class="rd-label">Barangay</div><div class="rd-value"><?php echo htmlspecialchars($barangayName); ?></div></div>
      </div>
    </div>
    <div class="review-detail-section">
      <h4 class="review-section-title"><i class="fas fa-file-alt"></i> Report Information</h4>
      <div class="review-detail-grid">
        <div><div class="rd-label">Report ID</div><div class="rd-value">${escapeHtml(r.ref_id)}</div></div>
        <div><div class="rd-label">Report Type</div><div class="rd-value">${escapeHtml(r.report_type)}</div></div>
        <div><div class="rd-label">Date Submitted</div><div class="rd-value">${fmtDate(r.created_at)}</div></div>
        <div style="grid-column:1/-1;"><div class="rd-label">Incident Location</div><div class="rd-value">${escapeHtml(r.location_address)}</div></div>
        <div style="grid-column:1/-1;"><div class="rd-label">Description</div><p style="color:#475569;font-size:0.88rem;margin:2px 0 0;line-height:1.5;">${escapeHtml(r.description)}</p></div>
      </div>
    </div>
    ${photoHtml}
    <div class="review-detail-section" style="border-bottom:none;margin-bottom:0;padding-bottom:0;">
      <h4 class="review-section-title"><i class="fas fa-info-circle"></i> Current Status</h4>
      <div>${statusBadge(r.status)}</div>
      ${r.status === 'rejected' && r.rejection_reason ? `<p style="color:#dc2626;font-size:0.85rem;margin-top:6px;"><strong>Reason:</strong> ${escapeHtml(r.rejection_reason)}</p>` : ''}
    </div>
    <div class="review-actions">
      ${r.status === 'pending' ? `
        <button class="action-btn-sm red" onclick="openRejectModal(${r.report_id})" style="padding:8px 20px;font-size:0.85rem;"><i class="fas fa-times"></i> Reject Report</button>
        <button class="action-btn-sm green" onclick="openConfirm(${r.report_id})" style="padding:8px 20px;font-size:0.85rem;"><i class="fas fa-check"></i> Validate Report</button>
      ` : `<button type="button" class="btn-secondary" onclick="closeModal('reviewModal')">Close</button>`}
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
      <div class="review-section-title"><i class="fas fa-user"></i> Resident Information</div>
      <div class="cs-row"><span class="cs-label">Name</span><span class="cs-value">${escapeHtml(r.reporter_name)}</span></div>
      <div class="cs-row"><span class="cs-label">Contact</span><span class="cs-value">${escapeHtml(r.reporter_phone || r.reporter_email)}</span></div>
      <div class="cs-row" style="border-bottom:2px solid #e2e8f0;padding-bottom:0.75rem;margin-bottom:0.5rem;"><span class="cs-label">Barangay</span><span class="cs-value"><?php echo htmlspecialchars($barangayName); ?></span></div>

      <div class="review-section-title"><i class="fas fa-file-alt"></i> Report Information</div>
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
<?php if (count($reports) > 0): ?>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';

// Crosshair plugin (vertical guideline on hover)
const crosshairPlugin = {
  id: 'crosshair',
  afterDraw: function(chart) {
    const tooltip = chart.tooltip;
    if (!tooltip || !tooltip._active || !tooltip._active.length) return;
    const ctx = chart.ctx;
    const activePoint = tooltip._active[0];
    const x = activePoint.element.x;
    const yScale = chart.scales.y;
    ctx.save();
    ctx.setLineDash([5, 4]);
    ctx.strokeStyle = 'rgba(148, 163, 184, 0.45)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x, yScale.top);
    ctx.lineTo(x, yScale.bottom);
    ctx.stroke();
    ctx.restore();
  }
};

// Monthly Activity (Jan–Dec)
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

<?php endif; ?>

// ── KPI Period Toggle ──
(function() {
  var btns = document.querySelectorAll('.kpi-period-btn');
  if (!btns.length) return;
  var statEls = document.querySelectorAll('[data-kpi]');
  var chgEls = document.querySelectorAll('[data-kpi-change]');
  var vsLabels = {1:'yesterday',7:'previous week',30:'previous month'};
  btns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      btns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      var period = this.getAttribute('data-period');
      var vsText = vsLabels[period] || 'prev 30 days';
      document.querySelector('.kpi-period-label').textContent = 'vs ' + vsText;
      var subs = document.querySelectorAll('.kpi-sub');
      for (var i = 0; i < subs.length; i++) subs[i].textContent = 'vs ' + vsText;
      fetch('brgy-filter-kpi.php?period=' + period)
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.error) return;
          statEls.forEach(function(el) {
            var key = el.getAttribute('data-kpi');
            if (d.total[key] !== undefined) el.textContent = d.total[key];
          });
          chgEls.forEach(function(el) {
            var key = el.getAttribute('data-kpi-change');
            if (d.pct[key] === undefined) return;
            var curVal = d.cur ? (d.cur[key] || 0) : 0;
            var prevVal = d.prev ? (d.prev[key] || 0) : 0;
            var pct = d.pct[key];
            var dir = curVal >= prevVal ? 'up' : 'down';
            var pctDisplay;
            if (prevVal === 0) {
              pctDisplay = curVal === 0 ? '0% change' : 'No previous data';
            } else {
              pctDisplay = Math.abs(pct) + '%';
            }
            el.innerHTML = '<span class="kpi-arrow ' + dir + '">' + (dir === 'up' ? '&#9650;' : '&#9660;') + '</span> <span class="kpi-pct">' + pctDisplay + '</span> <span class="kpi-sub">vs ' + vsText + '</span>';
          });
        })
        .catch(function() {});
    });
  });
})();
</script>

<script src="includes/realtime.js"></script>
<script>
document.addEventListener('dcvs:realtime', function(e) {
    var data = e.detail;
    // Reports table
    if (data.reports && data.reports.length) {
        var tbody = document.querySelector('#reportsTable tbody');
        if (tbody) {
            data.reports.forEach(function(rpt) {
                if (tbody.querySelector('tr[data-id="' + rpt.report_id + '"]')) return;
                var ref = rpt.reference_id || '#RPT-' + String(rpt.report_id).padStart(4, '0');
                var type = rpt.report_type.charAt(0).toUpperCase() + rpt.report_type.slice(1);
                var dt = new Date(rpt.created_at.replace(' ','T'));
                var ds = dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                var st = rpt.status.charAt(0).toUpperCase() + rpt.status.slice(1);
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', rpt.report_id);
                tr.innerHTML = '<td style="text-align:center;color:#94a3b8;font-size:0.8rem;">?</td>' +
                    '<td><span class="id-mono">' + ref + '</span></td>' +
                    '<td style="font-weight:600;color:#0f172a;">' + (rpt.reporter_name || '') + '</td>' +
                    '<td><span class="status-pill scheduled">' + type + '</span></td>' +
                    '<td style="white-space:nowrap;">' + ds + '</td>' +
                    '<td><span class="status-pill ' + st.toLowerCase() + '">' + st + '</span></td>' +
                    '<td><button class="action-btn-sm green" title="View Details"><i class="fas fa-search"></i> View Details</button></td>';
                tbody.appendChild(tr);
                requestAnimationFrame(function(){tr.style.transition='opacity 0.4s';tr.style.opacity='1';});
                tr.style.opacity='0';
            });
        }
    }
});
</script>
</body>
</html>

