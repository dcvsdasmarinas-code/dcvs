<?php
session_name('DCVS_ADMIN_SESSION');
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
require_once 'includes/activity-log-format.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: login-admin.php');
    exit;
}

// Re-verify role from database
$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $_SESSION['user_id']);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        session_destroy();
        header('Location: login-admin.php');
        exit;
    }
}
$roleCheck->close();

// ── Today / Yesterday date helpers (used by activity logs) ──
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── Users table ──
$r = $conn->query("SELECT COUNT(*) AS c FROM users");
$totalUsersCount = $r->fetch_assoc()['c'];
$allUsers = [];
$result = $conn->query("SELECT u.user_id, u.reference_id, u.full_name, u.email, u.role, u.barangay_id, u.status, u.created_at, u.phone, u.address, u.avatar_url, u.username, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id ORDER BY u.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $allUsers[] = $row;
}

// ── Activity logs table ──
$actSearch = trim($_GET['act_search'] ?? '');
$actAction = $_GET['act_action'] ?? '';
$actUser = $_GET['act_user'] ?? '';
$actSource = $_GET['act_source'] ?? '';
$actDateFrom = $_GET['act_date_from'] ?? '';
$actDateTo = $_GET['act_date_to'] ?? '';
$actDateFilter = $_GET['act_date'] ?? '';
if ($actDateFilter) {
    $actDateFrom = $actDateFilter;
    $actDateTo = $actDateFilter;
}
$actWhere = "WHERE 1=1";
$actParams = [];
$actTypes = "";
if ($actSearch) {
    $actWhere .= " AND (a.action LIKE ? OR a.details LIKE ? OR u.full_name LIKE ?)";
    $s = "%$actSearch%";
    $actParams = [$s, $s, $s];
    $actTypes = "sss";
}
if ($actAction) {
    $actWhere .= " AND a.action = ?";
    $actParams[] = $actAction;
    $actTypes .= "s";
}
if ($actUser) {
    $actWhere .= " AND a.user_id = ?";
    $actParams[] = intval($actUser);
    $actTypes .= "i";
}
if ($actSource) {
    if ($actSource === 'authentication') {
        $actWhere .= " AND (a.action LIKE '%Login%' OR a.action LIKE '%Logout%' OR a.action LIKE '%Password%' OR a.action LIKE '%Failed%')";
    } elseif ($actSource === 'user_management') {
        $actWhere .= " AND (a.action LIKE '%User%' OR a.action LIKE '%Role%' OR a.action LIKE '%Status%' OR a.action LIKE '%Activate%' OR a.action LIKE '%Deactivate%' OR a.action LIKE '%Delete%')";
    } elseif ($actSource === 'appointments') {
        $actWhere .= " AND (a.action LIKE '%Appointment%')";
    } elseif ($actSource === 'client_management') {
        $actWhere .= " AND (a.action LIKE '%Client%' OR a.action LIKE '%Pet%' OR a.action LIKE '%Resident%')";
    } elseif ($actSource === 'rescue_reports') {
        $actWhere .= " AND (a.action LIKE '%Rescue%')";
    } elseif ($actSource === 'vaccination') {
        $actWhere .= " AND (a.action LIKE '%Vaccin%')";
    } elseif ($actSource === 'reports_analytics') {
        $actWhere .= " AND (a.action LIKE '%Report%' OR a.action LIKE '%Export%' OR a.action LIKE '%Analytics%')";
    } elseif ($actSource === 'settings') {
        $actWhere .= " AND (a.action LIKE '%Setting%')";
    }
}
if ($actDateFrom) {
    $actWhere .= " AND a.created_at >= ?";
    $actParams[] = $actDateFrom . ' 00:00:00';
    $actTypes .= "s";
}
if ($actDateTo) {
    $actWhere .= " AND a.created_at <= ?";
    $actParams[] = $actDateTo . ' 23:59:59';
    $actTypes .= "s";
}
$actSql = "SELECT a.*, u.full_name, u.avatar_url FROM activity_logs a LEFT JOIN users u ON a.user_id = u.user_id $actWhere ORDER BY a.created_at DESC";
$actQ = $conn->prepare($actSql);
if ($actTypes) $actQ->bind_param($actTypes, ...$actParams);
$actQ->execute();
$allActivity = $actQ->get_result()->fetch_all(MYSQLI_ASSOC);
$actQ->close();
$activityEntityMaps = dcvsActivityLogEntityMaps($conn, $allActivity);

$allActUsers = [];
$auResult = $conn->query("SELECT DISTINCT u.user_id, u.full_name FROM activity_logs a LEFT JOIN users u ON a.user_id = u.user_id WHERE a.user_id IS NOT NULL ORDER BY u.full_name ASC");
if ($auResult) $allActUsers = $auResult->fetch_all(MYSQLI_ASSOC);


// ── Fetch admin details from DB (single source of truth) ──
$adminUserId = $_SESSION['user_id'];
$adminData = [];
$stmt = $conn->prepare("SELECT user_id, reference_id, username, email, full_name, role, phone, address, avatar_url, status, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $adminUserId);
$stmt->execute();
$adminData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullName = $adminData['full_name'] ?? $_SESSION['full_name'] ?? 'Administrator';
$adminRefId = $adminData['reference_id'] ?? '—';
$adminUsername = $adminData['username'] ?? '';
$adminPhone = $adminData['phone'] ?? '';
$adminAddress = $adminData['address'] ?? '';
$adminAvatar = $adminData['avatar_url'] ?? '';
$adminCreated = $adminData['created_at'] ?? '';
$adminEmail = $adminData['email'] ?? $_SESSION['email'] ?? '';
$pageTitle = 'User Management';
$activePage = 'users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Admin Dashboard — Dasmari&ntilde;as City Veterinary Services Animal Welfare System" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Admin | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <style>
    body { --topbar-h: 60px; background:#ffffff; }

    .page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:2rem; flex-wrap:wrap; gap:1rem; }
    .page-header-left { }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Inter',sans-serif; letter-spacing:-0.02em; }
    .page-subtitle { font-size:0.85rem; color:#64748b; margin:4px 0 0; line-height:1.5; }
    .page-actions { display:flex; gap:8px; align-items:center; }

    .section-heading {
      display:flex; align-items:center; justify-content:space-between;
      margin-bottom:1rem; padding-bottom:0.75rem;
      border-bottom:1px solid #f1f5f9;
    }
    .section-heading h3 {
      font-size:0.88rem; font-weight:700; color:#0f172a;
      margin:0; display:flex; align-items:center; gap:8px;
    }
    .section-heading h3 i { color:#045e1f; font-size:0.85rem; }
    .section-heading .badge-count {
      font-size:0.72rem; font-weight:600; color:#94a3b8;
      background:#f8fafc; border:1px solid #eef2f6;
      padding:2px 10px; border-radius:999px;
    }

    .kpi-section { margin-bottom:1.75rem; }
    .kpi-grid-people { display:grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap:1rem; }
    .kpi-grid-data { display:grid; grid-template-columns: 1.5fr 1.5fr 1fr 1fr; gap:1rem; }
    .kpi-grid-status { display:grid; grid-template-columns: repeat(4, 1fr); gap:1rem; }

    .kpi-card {
      background:#fff; border:1px solid #eef2f6; border-radius:14px; padding:1.35rem 1.5rem;
      box-shadow:0 1px 3px rgba(0,0,0,0.04);
      transition:box-shadow 0.25s, transform 0.25s; position:relative; overflow:hidden;
    }
    .kpi-card:hover { box-shadow:0 8px 32px rgba(0,0,0,0.08); transform:translateY(-2px); }
    .kpi-card::before {
      content:''; position:absolute; top:0; left:0; width:100%; height:3px;
    }
    .kpi-card.featured::before { background:linear-gradient(90deg,#045e1f,#4ade80); }
    .kpi-card.featured { padding:1.5rem 1.75rem; }
    .kpi-card.featured .kpi-number { font-size:2.2rem; }
    .kpi-card.green-border::before { background:linear-gradient(90deg,#10b981,#34d399); }
    .kpi-card.blue-border::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
    .kpi-card.amber-border::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
    .kpi-card.purple-border::before { background:linear-gradient(90deg,#8b5cf6,#a78bfa); }
    .kpi-card.gray-border::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
    .kpi-card.red-border::before { background:linear-gradient(90deg,#ef4444,#f87171); }

    .kpi-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:0.35rem; }
    .kpi-icon {
      width:36px; height:36px; border-radius:10px;
      display:flex; align-items:center; justify-content:center;
      font-size:0.9rem; flex-shrink:0;
    }
    .kpi-icon.green-bg { background:#f0fdf4; color:#059669; }
    .kpi-icon.blue-bg { background:#eff6ff; color:#2563eb; }
    .kpi-icon.amber-bg { background:#fffbeb; color:#d97706; }
    .kpi-icon.purple-bg { background:#f5f3ff; color:#7c3aed; }
    .kpi-icon.gray-bg { background:#f8fafc; color:#64748b; }
    .kpi-icon.red-bg { background:#fef2f2; color:#dc2626; }
    .kpi-icon.dark-green-bg { background:#f0fdf4; color:#045e1f; }

    .kpi-number {
      font-family:'Inter',sans-serif; font-size:1.65rem;
      font-weight:800; color:#0f172a; line-height:1; display:block;
    }
    .kpi-label { font-size:0.72rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.04em; display:block; margin-top:2px; }

    .dash-card {
      background:#fff; border:1px solid #eef2f6; border-radius:14px;
      box-shadow:0 1px 3px rgba(0,0,0,0.04); margin-bottom:1.5rem;
      overflow:hidden;
    }
    .dash-card-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:1rem 1.5rem; border-bottom:1px solid #f1f5f9;
    }
    .dash-card-header h3 {
      font-size:0.88rem; font-weight:700; color:#0f172a;
      margin:0; display:flex; align-items:center; gap:8px;
    }
    .dash-card-header h3 i { color:#045e1f; font-size:0.85rem; }
    .dash-card-body { padding:1.25rem 1.5rem; }
    .dash-card-body:has(.timeline) { padding:0; }
    .dash-card-body:has(table.data-table) { padding:0; }
    .view-all-link {
      font-size:0.78rem; font-weight:600; color:#045e1f;
      text-decoration:none; cursor:pointer; transition:color 0.15s;
    }
    .view-all-link:hover { color:#043012; }

    .chart-area { height:120px; position:relative; }
    .chart-bars { display:flex; align-items:flex-end; gap:4px; height:90px; }
    .chart-bar {
      flex:1; border-radius:3px 3px 0 0;
      background:linear-gradient(to top, rgba(4,94,31,0.2), rgba(4,94,31,0.5));
      transition:all 0.2s; position:relative; min-height:4px;
    }
    .chart-bar:hover { background:linear-gradient(to top, rgba(4,94,31,0.35), rgba(4,94,31,0.65)); }
    .chart-labels { display:flex; gap:4px; margin-top:8px; }
    .chart-labels span { flex:1; text-align:center; font-size:0.6rem; color:#94a3b8; }

    .timeline-group { }
    .timeline-group-header {
      padding:0.75rem 1.5rem 0.5rem;
      font-size:0.72rem; font-weight:700; color:#94a3b8;
      text-transform:uppercase; letter-spacing:0.05em;
      background:#fafcff; border-bottom:1px solid #f1f5f9;
    }
    .timeline-item {
      display:flex; align-items:flex-start; gap:12px;
      padding:0.75rem 1.5rem; border-bottom:1px solid #f8fafc;
      transition:background 0.12s;
    }
    .timeline-item:last-child { border-bottom:none; }
    .timeline-item:hover { background:#fafcff; }
    .timeline-dot {
      width:10px; height:10px; border-radius:50%; flex-shrink:0;
      margin-top:5px; border:2px solid #fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);
    }
    .timeline-dot.login { background:#10b981; }
    .timeline-dot.register { background:#3b82f6; }
    .timeline-dot.report { background:#f59e0b; }
    .timeline-dot.update { background:#8b5cf6; }
    .timeline-body { flex:1; min-width:0; }
    .timeline-user { font-weight:600; color:#0f172a; font-size:0.85rem; }
    .timeline-action { color:#64748b; font-size:0.82rem; }
    .timeline-time { font-size:0.7rem; color:#94a3b8; margin-top:2px; display:flex; align-items:center; gap:6px; }

    .role-chip {
      display:inline-flex; align-items:center; gap:4px;
      padding:2px 10px; border-radius:6px; font-size:0.72rem; font-weight:500;
      border:1px solid #e2e8f0; color:#475569;
    }
    .role-chip i { font-size:0.65rem; color:#94a3b8; }

    .status-indicator {
      display:inline-flex; align-items:center; gap:6px;
      font-size:0.78rem; font-weight:500; color:#475569;
    }
    .status-dot {
      width:7px; height:7px; border-radius:50%; flex-shrink:0;
    }
    .status-dot.active { background:#10b981; }
    .status-dot.inactive { background:#f59e0b; }
    .status-dot.archived { background:#94a3b8; }

    .charts-grid {
      display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;
    }
    .chart-wrap { position:relative; height:220px; }
    .chart-wrap canvas { max-height:220px; }

    .progress-row { display:flex; align-items:center; gap:12px; }
    .progress-label { width:90px; font-size:0.82rem; color:#64748b; font-weight:500; }
    .progress-bar { flex:1; height:6px; background:#f1f5f9; border-radius:999px; overflow:hidden; }
    .progress-fill { height:100%; border-radius:999px; transition:width 0.6s ease; }
    .progress-count { font-weight:700; color:#0f172a; font-size:0.85rem; min-width:30px; text-align:right; }

    .settings-layout { display:grid; gap:1rem; max-width:1000px; margin:0 auto; width:100%; box-sizing:border-box; }
    .profile-card-full { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:1.25rem 1.5rem; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
    .settings-section { margin-bottom:1rem; }
    .settings-section-title { font-size:0.9rem; font-weight:700; color:#0f172a; margin-bottom:0.75rem; display:flex; align-items:center; gap:8px; }
    .settings-section-title i { color:#045e1f; }
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; }
    .settings-actions { display:flex; gap:10px; margin-top:0.75rem; flex-wrap:wrap; }
    .settings-form { }
    .form-group { display:flex; flex-direction:column; gap:3px; }
    .form-group label { font-size:0.78rem; font-weight:600; color:#334155; }
    .form-group-full { grid-column:1 / -1; }
    .form-control { width:100%; padding:7px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.82rem; font-family:inherit; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .form-control:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    textarea.form-control { font-family:inherit; font-size:0.82rem; line-height:1.4; }
    .settings-grid-single { display:grid; grid-template-columns:1fr; gap:0.85rem; }
    .profile-header-banner { background:linear-gradient(135deg,#045e1f,#043012); padding:1.25rem 1.5rem; border-radius:12px; text-align:center; color:#fff; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; }
    .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
    .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
    .profile-dropdown.open { display:block; }
    .profile-dropdown .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
    .profile-dropdown .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
    .profile-dropdown .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
    .profile-header-info { text-align:left; flex:1; }
    .profile-header-info h2 { font-size:1.25rem; font-weight:800; margin:0; }
    .profile-header-info p { font-size:0.82rem; color:rgba(255,255,255,0.75); margin:2px 0; }
    .profile-id-badge { display:inline-block; background:rgba(255,255,255,0.15); padding:3px 10px; border-radius:999px; font-size:0.72rem; }
    .account-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.65rem; margin-top:0.75rem; }
    .profile-photo-view { text-align:center; padding:1rem; }
    .profile-photo-view img { max-width:300px; max-height:300px; border-radius:12px; }
    .no-profile-photo { color:#94a3b8; padding:2rem; }
    .no-profile-photo i { font-size:3rem; }
    .profile-photo-delete-btn { position:absolute; top:12px; right:12px; background:#fef2f2; border:1px solid #fecaca; color:#dc2626; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.15s; }
    .profile-photo-delete-btn:hover { background:#fecaca; }
    .btn-outline { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:0.78rem; font-weight:600; border:1px solid #d0d5dd; border-radius:7px; background:#fff; color:#344054; cursor:pointer; transition:all 0.15s; }
    .btn-outline:hover { background:#f2f4f7; border-color:#98a2b3; }

    /* ── Reused my-pets / shadcn table design ── */
    .appt-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
    .appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .appt-search-wrap{position:relative;}
    .appt-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
    .appt-search-input{height:36px;padding:0 12px 0 36px;width:280px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s,box-shadow .15s;}
    .appt-search-input:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;background:#fff;}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;padding:0 28px 0 12px;border:1px solid #e4e4e7;border-radius:6px;background-color:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:border-color .15s,box-shadow .15s;}
    .appt-status-select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}

    .appt-db-table-area{overflow:hidden;border:1px solid #e4e4e7;border-radius:8px;background:#fff;padding:0;}
    #users .appt-table{font-family:'Inter','Segoe UI',sans-serif;}
    #users .user-reference{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;}
    #users .client-name.user-full-name{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;}
    .appt-table{width:100%;border-collapse:collapse;color:#18181b;font-size:14px;}
    .appt-table thead th{background:#fafafa;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#71717a;height:40px;padding:0 12px;border-bottom:1px solid #e4e4e7;text-align:left;vertical-align:middle;white-space:nowrap;user-select:none;}
    .appt-table thead th.sd-th-action{text-align:right;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:pointer;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody tr.selected{background:#f4f4f5;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}
    #activity .appt-table tbody td{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;line-height:1.6;}
    #activity .appt-table tbody td .client-name,
    #activity .appt-table tbody td .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;line-height:1.6;}
    #activity .appt-table tbody td .appt-status-pill{font-family:'Inter',sans-serif;font-size:13px;font-weight:500;line-height:1.4;}

    /* ── Sort buttons ── */
    .sd-th{position:relative;}
    .th-sort{display:inline-flex;align-items:center;gap:6px;height:28px;margin-left:-12px;padding:0 12px;border-radius:6px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
    .th-sorted .th-sort-icon{color:#18181b;}

    .client-cell{display:flex;align-items:center;gap:10px;min-width:0;}
    .client-name{font-family:'Inter','Segoe UI',sans-serif;font-weight:500;color:#18181b;font-size:14px;white-space:nowrap;}
    .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}

    .appt-status-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7;}
    .pet-cat-tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;white-space:nowrap;line-height:1.4;}
    .pet-cat-tag.tag-admin{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
    .pet-cat-tag.tag-staff{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
    .pet-cat-tag.tag-resident{background:#fef3c7;color:#92400e;border-color:#fde68a;}
    .pet-cat-tag.tag-barangay{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe;}

    .pet-action-btn{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;background:transparent;border:none;color:#a1a1aa;cursor:pointer;text-decoration:none;transition:background .15s,color .15s;}
    .pet-action-btn:hover{background:#f4f4f5;color:#18181b;}

    .appt-pagination{display:flex;align-items:center;justify-content:flex-end;padding:12px 16px;border-top:1px solid #e4e4e7;}
    .dt-pagination-right{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    .dt-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}
    .dt-rows select{height:32px;width:70px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;padding:0 8px;font-size:14px;color:#18181b;cursor:pointer;font-family:inherit;}
    .dt-rows select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
    .dt-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
    .dt-page-btns{display:flex;align-items:center;gap:4px;}
    .dt-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}
    .dt-page-btn:hover:not(:disabled){background:#f4f4f5;}
    .dt-page-btn:disabled{opacity:.5;cursor:not-allowed;}

    /* ── Reused sd-dialog (shadcn) style ── */
    .sd-dialog{width:100%;display:flex;justify-content:center;}
    .sd-dialog-content{position:relative;width:100%;max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);animation:modalIn 0.2s ease;max-height:90vh;overflow-y:auto;}
    .sd-dialog-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:all 0.15s ease;}
    .sd-dialog-close:hover{background:#f4f4f5;color:#18181b;}
    .sd-dialog-header{display:flex;flex-direction:column;gap:6px;text-align:left;}
    .sd-dialog-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;}
    .sd-dialog-description{margin:0;font-size:14px;color:#71717a;line-height:1.5;}
    .sd-dialog-footer{display:flex;align-items:center;gap:8px;}
    .sd-field-group{display:flex;flex-direction:column;gap:16px;}
    .sd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .sd-field-full{grid-column:1 / -1;}
    .sd-field{display:flex;flex-direction:column;gap:5px;}
    .sd-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1;color:#18181b;}
    .sd-section{margin-bottom:16px;}
    .sd-section:last-child{margin-bottom:0;}
    .sd-section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#18181b;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f4f4f5;}
    .sd-section-title span{color:#71717a;font-size:12px;font-weight:600;}
    .sd-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;}
    .sd-info-item .sd-info-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#71717a;}
    .sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;margin-top:2px;word-break:break-word;}
    .sd-avatar-lg{width:72px;height:72px;border-radius:50%;overflow:hidden;background:#e4e4e7;color:#71717a;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600;flex-shrink:0;}
    .sd-avatar-lg img{width:100%;height:100%;object-fit:cover;}
    .sd-profile-head{display:flex;align-items:flex-start;gap:14px;padding-bottom:16px;border-bottom:1px solid #e4e4e7;}
    .sd-profile-name{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.025em;margin:0;}
    .sd-profile-sub{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#71717a;margin:2px 0 0;}
    #viewProfileModal{font-family:'Inter','Segoe UI',sans-serif;}
    #viewProfileModal .sd-dialog-title,
    #viewProfileModal .sd-dialog-description,
    #viewProfileModal .sd-profile-name,
    #viewProfileModal .sd-profile-sub,
    #viewProfileModal .sd-section-title,
    #viewProfileModal .sd-info-item .sd-info-label,
    #viewProfileModal .sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif;}
    #viewProfileModal .sd-profile-head,
    #viewProfileModal .sd-section-title{border-bottom:0;}
    @keyframes modalIn{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}

    .table-container {
      border:1px solid #eef2f6; border-radius:14px; overflow:hidden;
      box-shadow:0 1px 3px rgba(0,0,0,0.04);
    }
    .table-container .data-table { margin:0; }
    .table-container .data-table thead th { position:sticky; top:0; z-index:2; }

    /* ── Pagination ── */
    .pagination-bar {
      display:flex; align-items:center; justify-content:space-between;
      padding:12px 20px; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:8px;
    }
    .pag-info { font-size:0.78rem; color:#94a3b8; }
    .pag-info strong { color:#475569; }
    .pag-btns { display:flex; gap:4px; align-items:center; }
    .pag-btn {
      display:inline-flex; align-items:center; justify-content:center;
      min-width:32px; height:32px; border-radius:6px;
      border:1px solid #e2e8f0; background:#fff;
      color:#475569; font-size:0.78rem; font-weight:600;
      cursor:pointer; transition:all 0.15s; text-decoration:none;
    }
    .pag-btn:hover { border-color:#045e1f; color:#045e1f; }
    .pag-active { background:#045e1f !important; color:#fff !important; border-color:#045e1f !important; }
    .pag-btn.disabled { opacity:0.3; pointer-events:none; }
    .per-page-select {
      padding:4px 8px; border:1px solid #e2e8f0; border-radius:6px;
      font-size:0.75rem; font-family:'Inter','Segoe UI',sans-serif;
      color:#475569; background:#fff; cursor:pointer; outline:none;
      margin-left:4px;
    }
    .per-page-select:focus { border-color:#045e1f; }

    @media (max-width:1200px) {
      .kpi-grid-people { grid-template-columns:repeat(2, 1fr); }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 2; }
      .kpi-grid-data { grid-template-columns:repeat(2, 1fr); }
      .kpi-grid-status { grid-template-columns:repeat(2, 1fr); }
      .charts-grid { grid-template-columns:1fr; }
    }
    @media (max-width:768px) {
      .kpi-grid-people { grid-template-columns:1fr 1fr; }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 2; }
      .kpi-grid-data { grid-template-columns:1fr; }
      .kpi-grid-status { grid-template-columns:1fr 1fr; }
      .charts-grid { grid-template-columns:1fr; }
      .settings-grid { grid-template-columns:1fr; }
      .page-header { flex-direction:column; }
      .pagination-bar { flex-direction:column; align-items:stretch; text-align:center; }
    }
    @media (max-width:480px) {
      .kpi-grid-people { grid-template-columns:1fr; }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 1; }
      .kpi-grid-status { grid-template-columns:1fr; }
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
  #settings.content-section { background:#fff; }
  </style>
  <script>
    (function() {
      var target = window.location.hash.replace('#', '') || localStorage.getItem('adminActiveSection') || 'users';
      if (target !== 'activity' && target !== 'settings') {
        window.location.replace('role-management.php');
      }
    })();
  </script>
</head>
<body>

<?php include 'includes/admin-sidebar.php'; ?>

<main class="main-content">

  <section id="users" class="content-section active">
    <div class="page-header" style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
      <a href="role-management.php" class="btn-primary" style="padding:8px 18px;font-size:0.8rem;">
        <i class="fas fa-cog"></i> Full Role Management
      </a>
    </div>

    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="adminUserSearch" class="appt-search-input" placeholder="Search by name, email, or role...">
        </div>
        <select id="adminRoleFilter" class="appt-status-select">
          <option value="all">All Roles</option>
          <option value="admin">Admin</option>
          <option value="staff">Staff</option>
          <option value="resident">Resident</option>
          <option value="barangay">Barangay</option>
        </select>
        <select id="adminStatusFilter" class="appt-status-select">
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>

    <div class="appt-db-table-area">
      <div style="overflow-x:auto;">
        <table class="appt-table">
          <thead>
            <tr>
              <th class="sd-th"><button type="button" class="th-sort" data-col="ref" onclick="sortBy('ref')">Reference<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="name" onclick="sortBy('name')">Full Name<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="email" onclick="sortBy('email')">Email<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="role" onclick="sortBy('role')">Role<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="registered" onclick="sortBy('registered')">Registered<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th-action">Action</th>
            </tr>
          </thead>
          <tbody id="adminUserBody">
            <?php foreach ($allUsers as $u): ?>
              <tr>
                <td><span class="user-reference"><?php echo htmlspecialchars($u['reference_id'] ?? '—'); ?></span></td>
                <td><div class="client-cell"><span class="client-name user-full-name"><?php echo htmlspecialchars($u['full_name']); ?></span></div></td>
                <td><span class="contact-main"><?php echo htmlspecialchars($u['email']); ?></span></td>
                <td><?php
                  $roleTag = 'tag-resident';
                  if ($u['role'] === 'admin') $roleTag = 'tag-admin';
                  elseif ($u['role'] === 'staff') $roleTag = 'tag-staff';
                  elseif ($u['role'] === 'barangay') $roleTag = 'tag-barangay';
                ?><span class="pet-cat-tag <?php echo $roleTag; ?>"><?php echo ucfirst($u['role']); ?></span></td>
                <td><?php
                  $statusStyle = '';
                  if ($u['status'] === 'active') $statusStyle = 'background:#f0fdf4;color:#166534;border-color:#bbf7d0;';
                  elseif ($u['status'] === 'inactive') $statusStyle = 'background:#fef3c7;color:#92400e;border-color:#fde68a;';
                  else $statusStyle = 'background:#f4f4f5;color:#52525b;border-color:#e4e4e7;';
                ?><span class="appt-status-pill" style="<?php echo $statusStyle; ?>"><?php echo ucfirst($u['status']); ?></span></td>
                <td><span class="contact-main"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></span></td>
                <td class="sd-td-action"><button class="pet-action-btn" title="View Profile" onclick='viewUserProfile(<?php echo json_encode($u); ?>)'><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="appt-pagination" id="adminUserPagination"></div>
  </section>

  <!-- ── ACTIVITY LOGS ── -->
  <section id="activity" class="content-section" style="display:none;">
    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="adminActSearch" class="appt-search-input" placeholder="Search activity, user, or details..." value="<?php echo htmlspecialchars($actSearch); ?>">
        </div>
        <select class="appt-status-select" id="actActionFilter">
          <option value="">All Actions</option>
          <option value="Login" <?php echo $actAction==='Login'?'selected':''; ?>>Login</option>
          <option value="Logout" <?php echo $actAction==='Logout'?'selected':''; ?>>Logout</option>
          <option value="Client Updated" <?php echo $actAction==='Client Updated'?'selected':''; ?>>Client Updated</option>
          <option value="Appointment Scheduled" <?php echo $actAction==='Appointment Scheduled'?'selected':''; ?>>Appointment Scheduled</option>
          <option value="User Created" <?php echo $actAction==='User Created'?'selected':''; ?>>User Created</option>
          <option value="User Edited" <?php echo $actAction==='User Edited'?'selected':''; ?>>User Edited</option>
          <option value="User Deleted" <?php echo $actAction==='User Deleted'?'selected':''; ?>>User Deleted</option>
          <option value="Role Changed" <?php echo $actAction==='Role Changed'?'selected':''; ?>>Role Changed</option>
          <option value="Status Changed" <?php echo $actAction==='Status Changed'?'selected':''; ?>>Status Changed</option>
          <option value="Password Reset" <?php echo $actAction==='Password Reset'?'selected':''; ?>>Password Reset</option>
        </select>
        <select class="appt-status-select" id="actUserFilter">
          <option value="">All Users</option>
          <?php foreach ($allActUsers as $au): ?>
            <option value="<?php echo $au['user_id']; ?>" <?php echo $actUser==$au['user_id']?'selected':''; ?>><?php echo htmlspecialchars($au['full_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="appt-status-select" id="actSourceFilter">
          <option value="">All Sources</option>
          <option value="authentication" <?php echo $actSource==='authentication'?'selected':''; ?>>Authentication</option>
          <option value="user_management" <?php echo $actSource==='user_management'?'selected':''; ?>>User Management</option>
          <option value="appointments" <?php echo $actSource==='appointments'?'selected':''; ?>>Appointments</option>
          <option value="client_management" <?php echo $actSource==='client_management'?'selected':''; ?>>Client Management</option>
          <option value="rescue_reports" <?php echo $actSource==='rescue_reports'?'selected':''; ?>>Rescue Reports</option>
          <option value="vaccination" <?php echo $actSource==='vaccination'?'selected':''; ?>>Vaccination Records</option>
          <option value="reports_analytics" <?php echo $actSource==='reports_analytics'?'selected':''; ?>>Reports & Analytics</option>
          <option value="settings" <?php echo $actSource==='settings'?'selected':''; ?>>Settings</option>
        </select>
        <div style="position:relative;display:inline-block;">
          <button id="actDateFilter" onclick="toggleActCalendar()" title="Filter by date" style="height:36px;width:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#71717a;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);<?php echo ($actDateFrom||$actDateTo)?'background:#f4f4f5;color:#18181b;border-color:#d4d4d8;':'' ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg></button>
          <div id="actCalendarPopup" style="display:none;position:absolute;top:calc(100% + 6px);right:0;z-index:9999;background:#fff;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.12);padding:16px;width:280px;font-family:'Inter','Segoe UI',sans-serif;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
              <button onclick="actCalPrev()" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;font-size:16px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
              <div style="display:flex;align-items:center;gap:4px;">
                <select id="actCalMonth" onchange="actCalRender()" style="border:none;background:transparent;font-size:14px;font-weight:600;color:#18181b;cursor:pointer;font-family:inherit;outline:none;"></select>
                <select id="actCalYear" onchange="actCalRender()" style="border:none;background:transparent;font-size:14px;font-weight:600;color:#18181b;cursor:pointer;font-family:inherit;outline:none;"></select>
              </div>
              <button onclick="actCalNext()" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;font-size:16px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:4px;">
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Su</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Mo</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Tu</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">We</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Th</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Fr</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Sa</span>
            </div>
            <div id="actCalDays" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div>
          </div>
        </div>
        <input type="hidden" id="actDateFilterHidden" value="<?php echo htmlspecialchars($actDateFrom ?: $actDateTo); ?>">
        <button class="pet-action-btn" id="actClearBtn" style="display:<?php echo ($actSearch||$actAction||$actUser||$actSource||$actDateFrom||$actDateTo)?'inline-flex':'none'; ?>;" onclick="clearActFilters()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters</button>
      </div>
    </div>

    <div class="appt-db-table-area">
      <div style="overflow-x:auto;">
        <table class="appt-table">
          <thead>
            <tr>
              <th>User</th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="date" onclick="actSortBy('date')">Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th class="sd-th"><button type="button" class="th-sort" data-col="time" onclick="actSortBy('time')">Time<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
              <th>Activity</th>
              <th>Source</th>
            </tr>
          </thead>
          <tbody id="adminActBody">
            <?php foreach ($allActivity as $act): ?>
              <tr>
                <td>
                  <div class="client-cell" style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($act['avatar_url'])): ?>
                      <img src="<?php echo htmlspecialchars($act['avatar_url']); ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;background:#f4f4f5;" />
                    <?php else: ?>
                      <div style="width:32px;height:32px;border-radius:50%;background:#f4f4f5;display:flex;align-items:center;justify-content:center;color:#71717a;font-size:13px;font-weight:600;flex-shrink:0;"><?php echo strtoupper(substr($act['full_name'] ?? 'S', 0, 1)); ?></div>
                    <?php endif; ?>
                    <span class="client-name"><?php echo htmlspecialchars($act['full_name'] ?? 'System'); ?></span>
                  </div>
                </td>
                <td><span class="contact-main"><?php echo date('M d, Y', strtotime($act['created_at'])); ?></span></td>
                <td><span class="contact-main"><?php echo date('h:i A', strtotime($act['created_at'])); ?></span></td>
                <td><span class="contact-main"><?php echo htmlspecialchars(dcvsFormatActivityLogDetails($act['details'] ?? '', $activityEntityMaps)); ?></span></td>
                <td><?php
                  $src = $act['action'] ?? '';
                  $srcLower = strtolower($src);
                  if (strpos($srcLower, 'login') !== false || strpos($srcLower, 'logout') !== false || strpos($srcLower, 'password') !== false) echo '<span class="appt-status-pill" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe;">Authentication</span>';
                  elseif (strpos($srcLower, 'user') !== false || strpos($srcLower, 'role') !== false || strpos($srcLower, 'status') !== false || strpos($srcLower, 'activate') !== false || strpos($srcLower, 'deactivate') !== false) echo '<span class="appt-status-pill" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">User Management</span>';
                  elseif (strpos($srcLower, 'appointment') !== false) echo '<span class="appt-status-pill" style="background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe;">Appointments</span>';
                  elseif (strpos($srcLower, 'client') !== false || strpos($srcLower, 'pet') !== false || strpos($srcLower, 'resident') !== false) echo '<span class="appt-status-pill" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">Client Management</span>';
                  elseif (strpos($srcLower, 'rescue') !== false) echo '<span class="appt-status-pill" style="background:#fef2f2;color:#991b1b;border-color:#fecaca;">Rescue Reports</span>';
                  elseif (strpos($srcLower, 'vaccin') !== false) echo '<span class="appt-status-pill" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">Vaccination Records</span>';
                  elseif (strpos($srcLower, 'report') !== false || strpos($srcLower, 'export') !== false || strpos($srcLower, 'analytics') !== false) echo '<span class="appt-status-pill" style="background:#faf5ff;color:#6b21a8;border-color:#e9d5ff;">Reports & Analytics</span>';
                  elseif (strpos($srcLower, 'setting') !== false) echo '<span class="appt-status-pill" style="background:#f4f4f5;color:#52525b;border-color:#e4e4e7;">Settings</span>';
                  else echo '<span class="appt-status-pill" style="background:#f4f4f5;color:#52525b;border-color:#e4e4e7;">System</span>';
                ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="appt-pagination" id="adminActPagination"></div>
  </section>



  <!-- ── VISIT OUR OFFICE ── -->
  <!-- ── SETTINGS / ACCOUNT SETTINGS ── -->
  <section id="settings" class="content-section" style="display:none;">

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
            <?php if (!empty($adminAvatar)): ?>
              <div class="ps-avatar"><img id="adminAvatarImg" src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)"></div>
            <?php else: ?>
              <div class="ps-avatar-plain" onclick="toggleProfileDropdown(event)"><i class="fas fa-user"></i></div>
            <?php endif; ?>
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
            <div class="ps-profile-sub"><?php echo htmlspecialchars($adminEmail); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($adminAddress); ?></div>
          </div>
        </div>
      </div>

      <form id="adminSettingsForm" action="update-admin-profile.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event, 'adminAvatarImg')">

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
              <input type="email" name="email" value="<?php echo htmlspecialchars($adminEmail); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Username</label>
              <input type="text" name="username" value="<?php echo htmlspecialchars($adminUsername); ?>" readonly data-group="personal">
            </div>
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($adminPhone); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row ps-single">
            <div class="ps-field">
              <label>Address</label>
              <textarea name="address" disabled data-group="personal"><?php echo htmlspecialchars($adminAddress); ?></textarea>
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

<script>
(function() {
  var target = window.location.hash.replace('#', '') || localStorage.getItem('adminActiveSection') || 'users';
  if (target && document.getElementById(target)) {
    document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
    document.getElementById(target).style.display = 'block';
    var labels = {users:'User Management',activity:'Activity Logs',settings:'Settings'};
    var bc = document.getElementById('breadcrumbCurrent');
    if (bc) bc.textContent = labels[target] || target;
  } else {
    document.getElementById('users').style.display = 'block';
  }
})();

var currentSection = 'users';

function switchSection(id) {
  document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
  var target = document.getElementById(id);
  if (target) target.style.display = 'block';
  var labels = {users:'User Management',activity:'Activity Logs',settings:'Settings'};
  var bc = document.getElementById('breadcrumbCurrent');
  if (bc) bc.textContent = labels[id] || id;
  document.body.classList.add('white-page');
  currentSection = id;
  localStorage.setItem('adminActiveSection', id);
  window.location.hash = id;
  window.scrollTo({top:0});
}

// ── USERS: client pagination + search/filter ──
(function() {
  var PER_PAGE = 15;
  var tbody = document.getElementById('adminUserBody');
  var pag = document.getElementById('adminUserPagination');
  var searchInput = document.getElementById('adminUserSearch');
  var roleFilter = document.getElementById('adminRoleFilter');
  var statusFilter = document.getElementById('adminStatusFilter');
  if (!tbody || !pag) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var filteredRows = allRows.slice();
  var currentPage = 1;

  function applyFilters() {
    var q = (searchInput ? searchInput.value : '').toLowerCase();
    var role = roleFilter ? roleFilter.value : 'all';
    var status = statusFilter ? statusFilter.value : 'all';
    filteredRows = allRows.filter(function(r) {
      var text = r.textContent.toLowerCase();
      var matchQ = !q || text.indexOf(q) !== -1;
      var matchRole = role === 'all' || text.indexOf(role) !== -1;
      var matchStatus = status === 'all' || text.indexOf(status) !== -1;
      return matchQ && matchRole && matchStatus;
    });
    currentPage = 1;
    renderTable();
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (roleFilter) roleFilter.addEventListener('change', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);

  function renderTable() {
    allRows.forEach(function(r) { r.style.display = 'none'; });
    var total = filteredRows.length;
    var tp = Math.max(1, Math.ceil(total / PER_PAGE));
    if (currentPage > tp) currentPage = tp;
    if (currentPage < 1) currentPage = 1;
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    filteredRows.slice(start, end).forEach(function(r) { r.style.display = ''; });
    renderPag(tp, total);
  }

  function renderPag(tp, total) {
    var h = '';
    h += '<div class="dt-pagination-right">';
    h += '<div class="dt-rows"><span>Rows per page</span><select onchange="window.adminUserPerPage(this.value)"><option value="15">15</option><option value="25">25</option><option value="50">50</option></select></div>';
    h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
    h += '<div class="dt-page-btns">';
    h += '<button class="dt-page-btn" onclick="window.adminUserGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + ' title="First page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminUserGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + ' title="Previous"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminUserGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Next"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminUserGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Last page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
    h += '</div></div>';
    pag.innerHTML = h;
  }

  window.adminUserGo = function(page) {
    var tp = Math.ceil(filteredRows.length / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };
  window.adminUserPerPage = function(v) {
    PER_PAGE = parseInt(v, 10) || 15;
    currentPage = 1;
    renderTable();
  };

  renderTable();
})();

// ── ACTIVITY LOGS: server-side filter + client pagination ──
(function() {
  var PER_PAGE = 15;
  var tbody = document.getElementById('adminActBody');
  var pag = document.getElementById('adminActPagination');
  if (!tbody || !pag) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var currentPage = 1;

  function renderTable() {
    allRows.forEach(function(r) { r.style.display = 'none'; });
    var total = allRows.length;
    var tp = Math.max(1, Math.ceil(total / PER_PAGE));
    if (currentPage > tp) currentPage = tp;
    if (currentPage < 1) currentPage = 1;
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    allRows.slice(start, end).forEach(function(r) { r.style.display = ''; });
    var h = '';
    h += '<div class="dt-pagination-right">';
    h += '<div class="dt-rows"><span>Rows per page</span><select onchange="window.adminActPerPage(this.value)"><option value="15">15</option><option value="25">25</option><option value="50">50</option></select></div>';
    h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
    h += '<div class="dt-page-btns">';
    h += '<button class="dt-page-btn" onclick="window.adminActGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + ' title="First page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminActGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + ' title="Previous"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminActGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Next"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
    h += '<button class="dt-page-btn" onclick="window.adminActGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Last page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
    h += '</div></div>';
    pag.innerHTML = h;
  }

  window.adminActGo = function(page) {
    var tp = Math.ceil(allRows.length / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };
  window.adminActPerPage = function(v) {
    PER_PAGE = parseInt(v, 10) || 15;
    currentPage = 1;
    renderTable();
  };

  window.adminActSort = function(col) {
    if (actSortState.col === col) { actSortState.dir = actSortState.dir === 'asc' ? 'desc' : 'asc'; }
    else { actSortState.col = col; actSortState.dir = 'asc'; }
    allRows.sort(function(a, b) {
      var cellsA = a.querySelectorAll('td');
      var cellsB = b.querySelectorAll('td');
      var ia = col === 'date' ? 1 : 2;
      var va = cellsA[ia] ? cellsA[ia].textContent.trim() : '';
      var vb = cellsB[ia] ? cellsB[ia].textContent.trim() : '';
      if (col === 'date') {
        var da = new Date(va);
        var db = new Date(vb);
        if (!isNaN(da) && !isNaN(db)) { var res = da - db; return actSortState.dir === 'asc' ? res : -res; }
      }
      var res = va.localeCompare(vb);
      return actSortState.dir === 'asc' ? res : -res;
    });
    document.querySelectorAll('#activity .appt-table thead .th-sort').forEach(function(btn) {
      var c = btn.getAttribute('data-col');
      btn.classList.toggle('th-sorted', c === actSortState.col);
      var icon = btn.querySelector('.th-sort-icon');
      if (icon) {
        if (c === actSortState.col) {
          icon.innerHTML = actSortState.dir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
        } else {
          icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
        }
      }
    });
    currentPage = 1;
    renderTable();
  };

  renderTable();
})();

var actSortState = { col: '', dir: 'asc' };
function actSortBy(col) { window.adminActSort(col); }

function buildActUrl() {
  var p = [];
  var s = document.getElementById('adminActSearch').value;
  var a = document.getElementById('actActionFilter').value;
  var u = document.getElementById('actUserFilter').value;
  var src = document.getElementById('actSourceFilter').value;
  var df = document.getElementById('actDateFilterHidden').value;
  if (s) p.push('act_search=' + encodeURIComponent(s));
  if (a) p.push('act_action=' + encodeURIComponent(a));
  if (u) p.push('act_user=' + encodeURIComponent(u));
  if (src) p.push('act_source=' + encodeURIComponent(src));
  if (df) p.push('act_date=' + encodeURIComponent(df));
  return 'dashboard-admin.php' + (p.length ? '?' + p.join('&') : '');
}
function clearActFilters() { window.location.href = 'dashboard-admin.php'; }

var actTimer;
document.getElementById('adminActSearch').addEventListener('input', function() {
  clearTimeout(actTimer);
  actTimer = setTimeout(function() { window.location.href = buildActUrl(); }, 500);
});
document.getElementById('actActionFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });
document.getElementById('actUserFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });
document.getElementById('actSourceFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });

// ── Custom calendar for activity logs ──
var actCalDate = (function() {
  var v = document.getElementById('actDateFilterHidden').value;
  if (v) { var p = v.split('-'); return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2])); }
  return new Date();
})();
var actCalOpen = false;
var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function actCalInit() {
  var ms = document.getElementById('actCalMonth');
  var ys = document.getElementById('actCalYear');
  ms.innerHTML = '';
  months.forEach(function(m, i) { ms.innerHTML += '<option value="'+i+'">'+m+'</option>'; });
  ys.innerHTML = '';
  var cy = new Date().getFullYear();
  for (var y = cy - 5; y <= cy + 5; y++) { ys.innerHTML += '<option value="'+y+'">'+y+'</option>'; }
}

function actCalRender() {
  var ms = document.getElementById('actCalMonth');
  var ys = document.getElementById('actCalYear');
  var m = parseInt(ms.value);
  var y = parseInt(ys.value);
  actCalDate.setMonth(m);
  actCalDate.setFullYear(y);
  var firstDay = new Date(y, m, 1).getDay();
  var daysInMonth = new Date(y, m + 1, 0).getDate();
  var daysInPrev = new Date(y, m, 0).getDate();
  var today = new Date();
  var sel = document.getElementById('actDateFilterHidden').value;
  var selDate = sel ? new Date(sel + 'T00:00:00') : null;
  var html = '';
  var day = 1;
  var nextDay = 1;
  for (var row = 0; row < 6; row++) {
    for (var col = 0; col < 7; col++) {
      var idx = row * 7 + col;
      if (idx < firstDay) {
        var d = daysInPrev - firstDay + idx + 1;
        html += '<button onclick="actCalSelect('+y+','+m+','+d+',true)" style="width:32px;height:32px;border-radius:8px;border:none;background:transparent;color:#a1a1aa;font-size:13px;cursor:pointer;font-family:inherit;">'+d+'</button>';
      } else if (day <= daysInMonth) {
        var isToday = day === today.getDate() && m === today.getMonth() && y === today.getFullYear();
        var isSelected = selDate && day === selDate.getDate() && m === selDate.getMonth() && y === selDate.getFullYear();
        var bg = isSelected ? '#18181b' : isToday ? '#f4f4f5' : 'transparent';
        var clr = isSelected ? '#fff' : '#18181b';
        var fw = isToday && !isSelected ? 600 : 400;
        html += '<button onclick="actCalSelect('+y+','+m+','+day+',false)" style="width:32px;height:32px;border-radius:8px;border:none;background:'+bg+';color:'+clr+';font-size:13px;cursor:pointer;font-family:inherit;font-weight:'+fw+'">'+day+'</button>';
        day++;
      } else {
        html += '<button onclick="actCalSelect('+y+','+m+','+nextDay+',true)" style="width:32px;height:32px;border-radius:8px;border:none;background:transparent;color:#a1a1aa;font-size:13px;cursor:pointer;font-family:inherit;">'+nextDay+'</button>';
        nextDay++;
      }
    }
    if (day > daysInMonth && row > 3) break;
  }
  document.getElementById('actCalDays').innerHTML = html;
  ms.value = m;
  ys.value = y;
}

function actCalSelect(y, m, d, overflow) {
  if (overflow) {
    if (d > 15) { m--; if (m < 0) { m = 11; y--; } } else { m++; if (m > 11) { m = 0; y++; } }
  }
  var dt = new Date(y, m, d);
  var str = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
  document.getElementById('actDateFilterHidden').value = str;
  window.location.href = buildActUrl();
}

function actCalPrev() {
  var ms = document.getElementById('actCalMonth');
  var ys = document.getElementById('actCalYear');
  var m = parseInt(ms.value) - 1;
  var y = parseInt(ys.value);
  if (m < 0) { m = 11; y--; }
  ms.value = m;
  ys.value = y;
  actCalRender();
}

function actCalNext() {
  var ms = document.getElementById('actCalMonth');
  var ys = document.getElementById('actCalYear');
  var m = parseInt(ms.value) + 1;
  var y = parseInt(ys.value);
  if (m > 11) { m = 0; y++; }
  ms.value = m;
  ys.value = y;
  actCalRender();
}

function toggleActCalendar() {
  var pop = document.getElementById('actCalendarPopup');
  actCalOpen = !actCalOpen;
  if (actCalOpen) {
    actCalInit();
    document.getElementById('actCalMonth').value = actCalDate.getMonth();
    document.getElementById('actCalYear').value = actCalDate.getFullYear();
    actCalRender();
    pop.style.display = 'block';
  } else {
    pop.style.display = 'none';
  }
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('#actCalendarPopup') && !e.target.closest('#actDateFilter')) {
    document.getElementById('actCalendarPopup').style.display = 'none';
    actCalOpen = false;
  }
});

// ── Users table sort (all columns) ──
(function() {
  var tbody = document.getElementById('adminUserBody');
  if (!tbody) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var sortCol = '';
  var sortDir = 'asc';

  function getCellVal(row, col) {
    var cells = row.querySelectorAll('td');
    var idx = {ref:0, name:1, email:2, role:3, status:4, registered:5};
    var i = idx[col];
    return i !== undefined && cells[i] ? cells[i].textContent.trim().toLowerCase() : '';
  }

  window.sortBy = function(col) {
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = 'asc'; }
    allRows.sort(function(a, b) {
      var va = getCellVal(a, col), vb = getCellVal(b, col);
      var res = va.localeCompare(vb);
      return sortDir === 'asc' ? res : -res;
    });
    document.querySelectorAll('.appt-table thead .th-sort').forEach(function(btn) {
      var c = btn.getAttribute('data-col');
      btn.classList.toggle('th-sorted', c === sortCol);
      var icon = btn.querySelector('.th-sort-icon');
      if (icon) {
        if (c === sortCol) {
          icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
        } else {
          icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
        }
      }
    });
    tbody.innerHTML = '';
    allRows.forEach(function(r) { tbody.appendChild(r); });
  };
})();

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
    var sf = document.getElementById('adminSettingsForm');
    if (sf) {
        sf.addEventListener('submit', function() {
            this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
        });
    }
    window.previewAvatar = function(e, imgId) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var img = document.getElementById(imgId);
                if (img) { img.src = ev.target.result; }
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

function viewUserProfile(u) {
  document.getElementById('vpName').textContent = u.full_name || '—';
  var roleIcon = {'admin':'fa-crown','staff':'fa-user-md','barangay':'fa-map-pin','resident':'fa-user'};
  document.getElementById('vpRole').innerHTML = '<i class="fas '+(roleIcon[u.role]||'fa-user')+'"></i> '+ (u.role ? u.role.charAt(0).toUpperCase()+u.role.slice(1) : '—');
  document.getElementById('vpRef').textContent = u.reference_id || '—';
  document.getElementById('vpUsername').textContent = u.username || '—';
  document.getElementById('vpEmail').textContent = u.email || '—';
  document.getElementById('vpPhone').textContent = u.phone || '—';
  document.getElementById('vpAddress').textContent = u.address || '—';
  document.getElementById('vpBarangay').textContent = u.barangay_name || '—';
  var statusEl = document.getElementById('vpStatus');
  if (u.status === 'active') { statusEl.innerHTML = '<span class="appt-status-pill" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">Active</span>'; }
  else if (u.status === 'inactive') { statusEl.innerHTML = '<span class="appt-status-pill" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">Inactive</span>'; }
  else { statusEl.textContent = u.status || '—'; }
  document.getElementById('vpRegistered').textContent = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : '—';
  var avatarEl = document.getElementById('vpAvatar');
  avatarEl.textContent = '';
  if (u.avatar_url) {
    var avatarImage = document.createElement('img');
    avatarImage.src = u.avatar_url;
    avatarImage.alt = 'Avatar';
    avatarEl.appendChild(avatarImage);
  } else {
    var initials = (u.full_name||'').split(' ').map(function(s){return s.charAt(0)}).join('').toUpperCase().slice(0,2) || '?';
    avatarEl.textContent = initials;
  }
  openModal('viewProfileModal');
}

function viewProfilePhoto() {
    document.getElementById('profileDropdown').classList.remove('open');
    openModal('profilePhotoModal');
}

function confirmRemoveAvatar() {
    if (confirm('Remove your profile photo?')) {
        window.location.href = 'remove-photo.php';
    }
}
</script>

<!-- Profile Photo Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('profilePhotoModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Profile Photo</h2>
        <p class="sd-dialog-description">View your uploaded profile photo.</p>
      </div>
      <div style="text-align:center;padding:16px 0;">
        <?php if (!empty($adminAvatar)): ?>
          <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Profile Photo" style="max-width:100%;max-height:400px;border-radius:8px;object-fit:contain;">
          <div style="margin-top:16px;">
            <button class="pet-action-btn danger" onclick="confirmRemoveAvatar(); closeModal('profilePhotoModal');"><i class="fas fa-trash"></i> Remove Photo</button>
          </div>
        <?php else: ?>
          <div style="padding:40px 20px;color:#71717a;">
            <p style="font-size:14px;margin:0;">No profile photo uploaded</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- View User Profile Modal -->
<div class="modal user-profile-drawer" id="viewProfileModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('viewProfileModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">User Profile</h2>
        <p class="sd-dialog-description">View user account details and information.</p>
      </div>
      <div class="sd-field-group">
        <div class="sd-profile-head">
          <div class="sd-avatar-lg" id="vpAvatar"></div>
          <div>
            <div class="sd-profile-name" id="vpName"></div>
            <div class="sd-profile-sub" id="vpRole"></div>
          </div>
        </div>
        <div class="sd-section">
          <div class="sd-section-title"><span>01</span> Account Information</div>
          <div class="sd-info-grid">
            <div class="sd-info-item"><div class="sd-info-label">Reference ID</div><div class="sd-info-value" id="vpRef">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Username</div><div class="sd-info-value" id="vpUsername">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Email</div><div class="sd-info-value" id="vpEmail">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Phone</div><div class="sd-info-value" id="vpPhone">—</div></div>
          </div>
        </div>
        <div class="sd-section">
          <div class="sd-section-title"><span>02</span> Personal Details</div>
          <div class="sd-info-grid">
            <div class="sd-info-item sd-field-full"><div class="sd-info-label">Address</div><div class="sd-info-value" id="vpAddress">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Barangay</div><div class="sd-info-value" id="vpBarangay">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Status</div><div class="sd-info-value" id="vpStatus">—</div></div>
            <div class="sd-info-item"><div class="sd-info-label">Registered</div><div class="sd-info-value" id="vpRegistered">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content" style="max-width:420px;">
    <div class="modal-header">
      <h3><i class="fas fa-key"></i> Change Password</h3>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="changePasswordForm" action="update-admin-password.php" method="POST">
      <div class="modal-body">
        <div class="form-group"><label>Current Password <span class="req">*</span></label><input type="password" name="current_password" class="form-control" required /></div>
        <div class="form-group"><label>New Password <span class="req">*</span></label><input type="password" name="new_password" class="form-control" required /></div>
        <div class="form-group"><label>Confirm New Password <span class="req">*</span></label><input type="password" name="confirm_password" class="form-control" required /></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Change Password</button>
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
