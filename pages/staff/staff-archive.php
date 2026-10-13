<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
$appBaseUrl = dcvsStaffAppBaseUrl();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$activePage = 'archive';
$staffId = $_SESSION['user_id'];

$type = $_GET['type'] ?? 'reports';
if (!in_array($type, ['reports','appointments','clients','pets','rescued','announcements','programs'])) $type = 'reports';
$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? '');

$typeTitles = [
    'reports'       => 'Archived Reports',
    'appointments'  => 'Archived Appointments',
    'clients'       => 'Archived Clients',
    'pets'          => 'Archived Pets',
    'rescued'       => 'Rescued Animals',
    'announcements' => 'Archived Announcements',
    'programs'      => 'Archived Programs',
];
$pageTitle = $typeTitles[$type] . ' - DCVS';
$pageTitleShort = $typeTitles[$type];

function archiveQuery($conn, $sql, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($params && $types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

$s = $search ? "%$search%" : '';
$rows = [];
$count = 0;

switch ($type) {
    case 'reports':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT r.report_id, r.reference_id, r.report_type, r.location_address, r.urgency_level, r.status, r.created_at, r.archived_at, r.photo_url,
                    u.full_name AS reporter_name, b.barangay_name
             FROM reports r
             LEFT JOIN users u ON r.reporter_id = u.user_id
             LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
             WHERE r.archived_at IS NOT NULL"
             . ($search ? " AND (r.reference_id LIKE ? OR r.report_type LIKE ? OR u.full_name LIKE ? OR b.barangay_name LIKE ?)" : "")
             . ($f ? " AND r.report_type = ?" : "")
             . " ORDER BY r.archived_at DESC",
            array_merge($search ? [$s,$s,$s,$s] : [], $f ? [$f] : []),
            ($search ? "ssss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
    case 'appointments':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.created_at, a.archived_at,
                    u.full_name AS client_name, COALESCE(p.pet_name, '-') AS pet_name
             FROM appointments a
             LEFT JOIN users u ON a.user_id = u.user_id
             LEFT JOIN pets p ON a.pet_id = p.pet_id
             WHERE a.archived_at IS NOT NULL"
             . ($search ? " AND (a.reference_id LIKE ? OR a.appointment_type LIKE ? OR u.full_name LIKE ?)" : "")
             . ($f ? " AND a.appointment_type = ?" : "")
             . " ORDER BY a.archived_at DESC",
            array_merge($search ? [$s,$s,$s] : [], $f ? [$f] : []),
            ($search ? "sss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
    case 'clients':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT u.user_id, u.reference_id, u.full_name, u.email, u.phone, u.status, u.created_at, u.archived_at,
                    b.barangay_name
             FROM users u
             LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
             WHERE u.archived_at IS NOT NULL AND u.role = 'resident'"
             . ($search ? " AND (u.reference_id LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR b.barangay_name LIKE ?)" : "")
             . ($f ? " AND b.barangay_name = ?" : "")
             . " ORDER BY u.archived_at DESC",
            array_merge($search ? [$s,$s,$s,$s] : [], $f ? [$f] : []),
            ($search ? "ssss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
    case 'pets':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT p.pet_id, p.reference_id, p.pet_name, p.pet_type, p.breed, p.gender, p.age, p.photo_url, p.created_at, p.archived_at,
                    u.full_name AS owner_name, b.barangay_name
             FROM pets p
             LEFT JOIN users u ON p.owner_id = u.user_id
             LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
             WHERE p.archived_at IS NOT NULL"
             . ($search ? " AND (p.reference_id LIKE ? OR p.pet_name LIKE ? OR p.pet_type LIKE ? OR u.full_name LIKE ?)" : "")
             . ($f ? " AND p.pet_type = ?" : "")
             . " ORDER BY p.archived_at DESC",
            array_merge($search ? [$s,$s,$s,$s] : [], $f ? [$f] : []),
            ($search ? "ssss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
    case 'announcements':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT announcement_id, title, category, status, created_at, archived_at
             FROM announcements
             WHERE archived_at IS NOT NULL"
             . ($search ? " AND (title LIKE ? OR category LIKE ?)" : "")
             . ($f ? " AND category = ?" : "")
             . " ORDER BY archived_at DESC",
            array_merge($search ? [$s,$s] : [], $f ? [$f] : []),
            ($search ? "ss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
      case 'programs':
        $f = $filter;
        $rows = archiveQuery($conn,
            "SELECT p.*, GROUP_CONCAT(DISTINCT b.barangay_name SEPARATOR ', ') AS brgy_names," .
            " (SELECT COALESCE(SUM(pp.dogs_served + pp.cats_served), 0) FROM program_progress pp WHERE pp.program_id = p.program_id) AS reached" .
            " FROM programs p" .
            " LEFT JOIN program_barangays pb ON p.program_id = pb.program_id" .
            " LEFT JOIN barangays b ON pb.barangay_id = b.barangay_id" .
            " WHERE p.status = 'archived'"
             . ($search ? " AND (p.program_name LIKE ? OR p.venue LIKE ?)" : "")
             . ($f ? " AND p.program_type = ?" : "")
             . " GROUP BY p.program_id" .
             " ORDER BY p.updated_at DESC, p.program_id DESC",
            array_merge($search ? [$s,$s] : [], $f ? [$f] : []),
            ($search ? "ss" : "") . ($f ? "s" : "")
        );
        $count = count($rows);
        break;
      case 'rescued':
        $rows = archiveQuery($conn,
          "SELECT listing_id, name, species, breed, age, gender, rescued_date, photo_url, status, created_at
           FROM pet_listings
           WHERE status = 'rescued'"
           . ($search ? " AND (name LIKE ? OR species LIKE ? OR breed LIKE ?)" : "")
           . " ORDER BY created_at DESC",
          $search ? [$s, $s, $s] : [],
          $search ? "sss" : ""
        );
        $count = count($rows);
        break;
}

$rptStatusColors = [
    'pending'   => ['bg'=>'#FEF3C7','color'=>'#92400E','border'=>'#FDE68A'],
    'validated' => ['bg'=>'#DBEAFE','color'=>'#1E40AF','border'=>'#BFDBFE'],
    'active'    => ['bg'=>'#ECFDF3','color'=>'#027A48','border'=>'#D1FADF'],
    'resolved'  => ['bg'=>'#0E8131','color'=>'#FFFFFF','border'=>'#0E8131'],
    'rejected'  => ['bg'=>'#FEE2E2','color'=>'#991B1B','border'=>'#FECACA'],
    'archived'  => ['bg'=>'#F4F4F5','color'=>'#71717A','border'=>'#E4E4E7'],
];

$apptStatusColors = [
    'pending'    => ['bg'=>'#FEF3C7','color'=>'#92400E','border'=>'#FDE68A'],
    'confirmed'  => ['bg'=>'#ECFDF3','color'=>'#027A48','border'=>'#D1FADF'],
    'scheduled'  => ['bg'=>'#0E8131','color'=>'#FFFFFF','border'=>'#0E8131'],
    'completed'  => ['bg'=>'#DBEAFE','color'=>'#1E40AF','border'=>'#BFDBFE'],
    'cancelled'  => ['bg'=>'#F1F5F9','color'=>'#64748B','border'=>'#E2E8F0'],
    'archived'   => ['bg'=>'#F4F4F5','color'=>'#71717A','border'=>'#E4E4E7'],
];

function pillStyle($colors, $status) {
    $c = $colors[strtolower($status)] ?? ['bg'=>'#F4F4F5','color'=>'#71717A','border'=>'#E4E4E7'];
    return "background:{$c['bg']};color:{$c['color']};border-color:{$c['border']};";
}

function fmtDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fmtTime($t) { return $t ? date('h:i A', strtotime($t)) : '—'; }

if (isset($_POST['action']) && $_POST['action'] === 'restore') {
    header('Content-Type: application/json');
    $rType = $_POST['restore_type'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    if ($id === 0 || !in_array($rType, ['report','appointment','client','pet','announcement'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
    }
    $entityLabel = ucfirst($rType);
    $labelSql = [
        'report' => "SELECT CONCAT(UPPER(LEFT(r.report_type, 1)), LOWER(SUBSTRING(r.report_type, 2)), ' report from ', COALESCE(u.full_name, 'a resident')) AS label FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id = ?",
        'appointment' => "SELECT CONCAT(UPPER(LEFT(a.appointment_type, 1)), LOWER(SUBSTRING(a.appointment_type, 2)), ' appointment for ', COALESCE(u.full_name, 'a resident'), IF(p.pet_name IS NULL OR p.pet_name = '', '', CONCAT(' (pet: ', p.pet_name, ')'))) AS label FROM appointments a LEFT JOIN users u ON u.user_id = a.user_id LEFT JOIN pets p ON p.pet_id = a.pet_id WHERE a.appointment_id = ?",
        'client' => "SELECT CONCAT('resident ', full_name) AS label FROM users WHERE user_id = ?",
        'pet' => "SELECT CONCAT('pet ', pet_name) AS label FROM pets WHERE pet_id = ?",
        'announcement' => "SELECT CONCAT('announcement ', title) AS label FROM announcements WHERE announcement_id = ?",
    ];
    $labelStmt = $conn->prepare($labelSql[$rType]);
    if ($labelStmt) {
        $labelStmt->bind_param("i", $id);
        if ($labelStmt->execute()) {
            $label = $labelStmt->get_result()->fetch_assoc();
            if (!empty($label['label'])) $entityLabel = $label['label'];
        } else {
            error_log('Failed to load restored archive item label: ' . $labelStmt->error);
        }
        $labelStmt->close();
    } else {
        error_log('Failed to prepare restored archive item label lookup: ' . $conn->error);
    }
    if ($rType === 'client') {
        $sql = "UPDATE users SET archived_at = NULL, status = 'active' WHERE user_id = ?";
    } else {
        $map = ['report'=>'reports','appointment'=>'appointments','pet'=>'pets','announcement'=>'announcements'];
        $col = ['report'=>'report_id','appointment'=>'appointment_id','pet'=>'pet_id','announcement'=>'announcement_id'];
        $sql = "UPDATE {$map[$rType]} SET archived_at = NULL WHERE {$col[$rType]} = ?";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $actionText = ucfirst($rType) . ' Restored';
        $details = "Restored " . $entityLabel . " from archive.";
        $logStmt->bind_param("isss", $staffId, $actionText, $details, $ip);
        $logStmt->execute();
        $logStmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Restore failed.']);
    }
    $stmt->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/sb2.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:'Inter','Segoe UI',sans-serif;background:#ffffff;color:#18181b;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;}
.page-title-text{font-size:18px;font-weight:600;letter-spacing:-0.025em;color:#18181b;}
.sd-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;border:1px solid #e4e4e7;background:#fff;color:#18181b;transition:background 0.15s;}
.sd-btn:hover{background:#f4f4f5;}
.sd-btn-primary{background:#18181b;color:#fff;border-color:#18181b;}
.sd-btn-primary:hover{background:#27272a;}
.appt-card{background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;}
.archive-table-wrap{width:100%;max-width:100%;min-width:0;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.archive-table-wrap .appt-table{width:100%;}
.archive-toolbar{display:flex;align-items:center;justify-content:flex-start;gap:8px;margin-bottom:1rem;}
.appt-card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e4e4e7;}
.appt-header-right{display:flex;align-items:center;gap:8px;}
.appt-search-wrap{display:flex;align-items:center;gap:8px;}
.appt-search-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
.appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.appt-search-input::placeholder{color:#a1a1aa;}
.appt-table{width:100%;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
.appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
.appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
.appt-table tbody tr:last-child{border-bottom:none;}
.appt-table tbody tr:hover{background:#f9fafb;}
.appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
.client-cell{display:flex;flex-direction:column;gap:2px;}
.client-name{font-weight:600;color:#18181b;}
.client-id{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;}
.contact-main{font-size:14px;font-weight:400;color:#18181b;}
.appt-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}
.pet-action-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;font-size:13px;font-weight:500;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;cursor:pointer;transition:background 0.12s;}
.pet-action-btn:hover{background:#f4f4f5;}
.pet-action-btn.restore{color:#16a34a;border-color:#bbf7d0;}
.pet-action-btn.restore:hover{background:#f0fdf4;}
.empty-state{text-align:center;padding:48px 20px;color:#71717a;font-size:14px;}
.empty-state strong{color:#18181b;}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;display:flex;flex-direction:column-reverse;gap:0.5rem;z-index:9999;pointer-events:none;}
.toast{pointer-events:auto;display:flex;align-items:flex-start;gap:0.75rem;min-width:300px;max-width:420px;padding:1rem 1.25rem;border-radius:0.625rem;font-size:0.8125rem;line-height:1.4;font-weight:500;background:#fff;color:#18181b;border:1px solid #e4e4e7;box-shadow:0 8px 24px rgba(0,0,0,0.12),0 2px 6px rgba(0,0,0,0.06);transform:translateX(calc(100% + 1.5rem));opacity:0;transition:transform 0.3s cubic-bezier(0.16,1,0.3,1),opacity 0.25s ease;}
.toast.show{transform:translateX(0);opacity:1;}
.toast.hiding{transform:translateX(calc(100% + 1.5rem));opacity:0;}
.toast-icon{flex-shrink:0;width:1.125rem;height:1.125rem;margin-top:1px;}
.toast-body{flex:1;min-width:0;}
.toast-title{font-weight:600;color:#18181b;margin:0;}
.toast-desc{font-weight:400;color:#71717a;margin:0.125rem 0 0;font-size:0.8125rem;}
.toast-close{flex-shrink:0;width:1.25rem;height:1.25rem;display:inline-flex;align-items:center;justify-content:center;border:none;background:transparent;color:#71717a;cursor:pointer;border-radius:0.25rem;transition:background 0.12s,color 0.12s;}
.toast-close:hover{background:#f4f4f5;color:#18181b;}
.toast.success{border-color:#bbf7d0;background:#f0fdf4;}
.toast.success .toast-icon{color:#16a34a;}
.toast.success .toast-title{color:#065f46;}
.toast.error{border-color:#fecaca;background:#fef2f2;}
.toast.error .toast-icon{color:#dc2626;}
.toast.error .toast-title{color:#991b1b;}
.sd-dialog-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center;}
.sd-dialog-overlay.active{display:flex;}
.sd-dialog-content{background:#fff;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,0.15);width:100%;max-width:400px;padding:24px;}
.sd-dialog-title{font-size:16px;font-weight:600;color:#18181b;margin:0 0 8px;}
.sd-dialog-desc{font-size:14px;color:#71717a;margin:0 0 20px;line-height:1.5;}
.sd-dialog-actions{display:flex;justify-content:flex-end;gap:8px;}
.appt-search-wrap{position:relative;}
.appt-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
.appt-search-input{height:36px;padding:0 12px 0 36px;width:240px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s,box-shadow .15s;}
.appt-search-input:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;background:#fff;}
.appt-search-input::placeholder{color:#a1a1aa;}
.appt-status-select{height:36px;padding:0 28px 0 12px;border:1px solid #e4e4e7;border-radius:6px;background-color:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:border-color .15s,box-shadow .15s;}
.appt-status-select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
@media(max-width:768px){
  .main-content{box-sizing:border-box;width:100%;max-width:100%;min-width:0;}
  .archive-toolbar{align-items:stretch;flex-direction:column;}
  .archive-toolbar .appt-search-wrap,.archive-toolbar .appt-search-input,.archive-toolbar .appt-status-select{box-sizing:border-box;width:100%;max-width:none;min-width:0;}
  .archive-toolbar .pet-action-btn{align-self:flex-start;min-height:36px;}
  .archive-table-wrap .appt-table{min-width:760px;}
}
@media(max-width:480px){
  .archive-toolbar{gap:10px;}
  .archive-toolbar .pet-action-btn{width:100%;justify-content:center;}
  .empty-state{padding:36px 16px;}
}
</style>
</head>
<body class="staff-theme" data-portal="staff">
<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>

<main class="main-content">

<div class="page-header archive-toolbar">
  <div class="appt-search-wrap">
    <svg class="appt-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" id="searchInput" class="appt-search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" />
  </div>
  <?php if ($type === 'reports'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Types</option>
      <option value="stray" <?php echo ($_GET['filter'] ?? '')==='stray'?'selected':''; ?>>Stray Animal</option>
      <option value="cruelty" <?php echo ($_GET['filter'] ?? '')==='cruelty'?'selected':''; ?>>Animal Cruelty</option>
      <option value="injury" <?php echo ($_GET['filter'] ?? '')==='injury'?'selected':''; ?>>Injured Animal</option>
      <option value="rabies" <?php echo ($_GET['filter'] ?? '')==='rabies'?'selected':''; ?>>Rabies Suspect</option>
    </select>
  <?php elseif ($type === 'appointments'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Types</option>
      <option value="vaccination" <?php echo ($_GET['filter'] ?? '')==='vaccination'?'selected':''; ?>>Vaccination</option>
      <option value="checkup" <?php echo ($_GET['filter'] ?? '')==='checkup'?'selected':''; ?>>Check-up</option>
      <option value="sterilization" <?php echo ($_GET['filter'] ?? '')==='sterilization'?'selected':''; ?>>Sterilization</option>
    </select>
  <?php elseif ($type === 'clients'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Barangays</option>
      <?php
      $brgyQ = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name");
      while ($b = $brgyQ->fetch_assoc()):
      ?>
        <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>" <?php echo ($_GET['filter'] ?? '')===$b['barangay_name']?'selected':''; ?>><?php echo htmlspecialchars($b['barangay_name']); ?></option>
      <?php endwhile; ?>
    </select>
  <?php elseif ($type === 'pets'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Species</option>
      <option value="dog" <?php echo ($_GET['filter'] ?? '')==='dog'?'selected':''; ?>>Dog</option>
      <option value="cat" <?php echo ($_GET['filter'] ?? '')==='cat'?'selected':''; ?>>Cat</option>
      <option value="bird" <?php echo ($_GET['filter'] ?? '')==='bird'?'selected':''; ?>>Bird</option>
      <option value="other" <?php echo ($_GET['filter'] ?? '')==='other'?'selected':''; ?>>Other</option>
    </select>
  <?php elseif ($type === 'rescued'): ?>
    <select id="filterSelect" class="appt-status-select" disabled>
      <option>Rescued Animals</option>
    </select>
  <?php elseif ($type === 'announcements'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Categories</option>
      <option value="advisory" <?php echo ($_GET['filter'] ?? '')==='advisory'?'selected':''; ?>>Advisory</option>
      <option value="announcement" <?php echo ($_GET['filter'] ?? '')==='announcement'?'selected':''; ?>>Announcement</option>
    </select>
  <?php elseif ($type === 'programs'): ?>
    <select id="filterSelect" class="appt-status-select" onchange="applyFilter()">
      <option value="">All Types</option>
      <option value="kapon" <?php echo ($_GET['filter'] ?? '')==='kapon'?'selected':''; ?>>Kapon</option>
      <option value="vaccination" <?php echo ($_GET['filter'] ?? '')==='vaccination'?'selected':''; ?>>Vaccination</option>
      <option value="checkup" <?php echo ($_GET['filter'] ?? '')==='checkup'?'selected':''; ?>>Check-up</option>
      <option value="deworming" <?php echo ($_GET['filter'] ?? '')==='deworming'?'selected':''; ?>>Deworming</option>
      <option value="adoption" <?php echo ($_GET['filter'] ?? '')==='adoption'?'selected':''; ?>>Adoption</option>
      <option value="rabies_prevention" <?php echo ($_GET['filter'] ?? '')==='rabies_prevention'?'selected':''; ?>>Rabies Prevention</option>
      <option value="microchipping" <?php echo ($_GET['filter'] ?? '')==='microchipping'?'selected':''; ?>>Microchipping</option>
      <option value="other" <?php echo ($_GET['filter'] ?? '')==='other'?'selected':''; ?>>Other</option>
    </select>
  <?php endif; ?>
  <?php if ($search || ($_GET['filter'] ?? '')): ?>
    <button class="pet-action-btn" onclick="clearSearch()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear</button>
  <?php endif; ?>
</div>

<div class="appt-card">

<?php if (empty($rows)): ?>
  <div class="empty-state"><i class="fas fa-archive" style="font-size:32px;display:block;margin-bottom:8px;opacity:.4;"></i><p><strong>No archived <?php echo htmlspecialchars($type); ?> found.</strong></p></div>
<?php else: ?>
  <div class="archive-table-wrap">
  <table class="appt-table no-responsive">
    <thead>
      <tr>
<?php if ($type === 'reports'): ?>
        <th>Reference</th><th>Reporter</th><th>Type</th><th>Barangay</th><th>Priority</th><th>Status</th><th>Archived</th><th></th>
<?php elseif ($type === 'appointments'): ?>
        <th>Reference</th><th>Client</th><th>Type</th><th>Pet</th><th>Date</th><th>Time</th><th>Status</th><th>Archived</th><th></th>
<?php elseif ($type === 'clients'): ?>
        <th>Reference</th><th>Full Name</th><th>Email</th><th>Phone</th><th>Barangay</th><th>Archived</th><th></th>
<?php elseif ($type === 'pets'): ?>
        <th>Reference</th><th>Name</th><th>Species</th><th>Breed</th><th>Gender</th><th>Owner</th><th>Barangay</th><th>Archived</th><th></th>
<?php elseif ($type === 'rescued'): ?>
  <th>Listing ID</th><th>Name</th><th>Species</th><th>Breed</th><th>Gender</th><th>Rescue Date</th><th>Status</th><th>Recorded</th>
<?php elseif ($type === 'announcements'): ?>
        <th>Title</th><th>Category</th><th>Status</th><th>Created</th><th>Archived</th><th></th>
<?php elseif ($type === 'programs'): ?>
        <th>Program</th><th>Location</th><th>Date</th><th>Target</th><th>Reached</th><th>Archived</th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
<?php if ($type === 'reports'): ?>
        <td><span class="client-id"><?php echo htmlspecialchars($r['reference_id']); ?></span></td>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['reporter_name'] ?? '—'); ?></span></div></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['report_type']); ?></span></td>
        <td><span class="client-name"><?php echo htmlspecialchars($r['barangay_name'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo ucfirst($r['urgency_level']); ?></span></td>
        <td><span class="appt-status-pill" style="<?php echo pillStyle($rptStatusColors, $r['status']); ?>"><?php echo ucfirst($r['status']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['archived_at']); ?></span></td>
        <td><button class="pet-action-btn restore" onclick="restoreItem('report',<?php echo $r['report_id']; ?>,'<?php echo htmlspecialchars(addslashes($r['report_type'])); ?>','<?php echo htmlspecialchars(addslashes($r['reporter_name'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($r['barangay_name'] ?? '')); ?>')"><i class="fas fa-undo"></i> Restore</button></td>
<?php elseif ($type === 'appointments'): ?>
        <td><span class="client-id"><?php echo htmlspecialchars($r['reference_id']); ?></span></td>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['client_name'] ?? '—'); ?></span></div></td>
        <td><span class="contact-main"><?php echo ucfirst($r['appointment_type']); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['pet_name']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['preferred_date']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtTime($r['preferred_time']); ?></span></td>
        <td><span class="appt-status-pill" style="<?php echo pillStyle($apptStatusColors, $r['status']); ?>"><?php echo ucfirst($r['status']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['archived_at']); ?></span></td>
        <td><button class="pet-action-btn restore" onclick="restoreItem('appointment',<?php echo $r['appointment_id']; ?>,'<?php echo htmlspecialchars(addslashes($r['appointment_type'])); ?>','<?php echo htmlspecialchars(addslashes($r['client_name'] ?? '')); ?>','')"><i class="fas fa-undo"></i> Restore</button></td>
<?php elseif ($type === 'clients'): ?>
        <td><span class="client-id"><?php echo htmlspecialchars($r['reference_id']); ?></span></td>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['full_name']); ?></span></div></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['phone'] ?? '—'); ?></span></td>
        <td><span class="client-name"><?php echo htmlspecialchars($r['barangay_name'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['archived_at']); ?></span></td>
        <td><button class="pet-action-btn restore" onclick="restoreItem('client',<?php echo $r['user_id']; ?>,'client','<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['barangay_name'] ?? '')); ?>')"><i class="fas fa-undo"></i> Restore</button></td>
<?php elseif ($type === 'pets'): ?>
        <td><span class="client-id"><?php echo htmlspecialchars($r['reference_id']); ?></span></td>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['pet_name']); ?></span></div></td>
        <td><span class="contact-main"><?php echo ucfirst($r['pet_type']); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['breed'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo ucfirst($r['gender']); ?></span></td>
        <td><span class="client-name"><?php echo htmlspecialchars($r['owner_name'] ?? '—'); ?></span></td>
        <td><span class="client-name"><?php echo htmlspecialchars($r['barangay_name'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['archived_at']); ?></span></td>
        <td><button class="pet-action-btn restore" onclick="restoreItem('pet',<?php echo $r['pet_id']; ?>,'<?php echo htmlspecialchars(addslashes($r['pet_type'])); ?>','<?php echo htmlspecialchars(addslashes($r['pet_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['barangay_name'] ?? '')); ?>')"><i class="fas fa-undo"></i> Restore</button></td>
      <?php elseif ($type === 'rescued'): ?>
        <td><span class="client-id">#PL-<?php echo str_pad((int)$r['listing_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['name']); ?></span></div></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['species']); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['breed'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['gender'] ?? '—'); ?></span></td>
        <td><span class="contact-main"><?php echo htmlspecialchars($r['rescued_date'] ?? '—'); ?></span></td>
        <td><span class="appt-status-pill" style="background:#FEF3C7;color:#92400E;border-color:#FDE68A;">Rescued</span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['created_at']); ?></span></td>
<?php elseif ($type === 'announcements'): ?>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['title']); ?></span></div></td>
        <td><span class="contact-main"><?php echo ucfirst($r['category']); ?></span></td>
        <td><span class="appt-status-pill" style="background:#F4F4F5;color:#71717A;border-color:#E4E4E7;"><?php echo ucfirst($r['status']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['created_at']); ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['archived_at']); ?></span></td>
        <td><button class="pet-action-btn restore" onclick="restoreItem('announcement',<?php echo $r['announcement_id']; ?>,'<?php echo htmlspecialchars(addslashes($r['category'])); ?>','<?php echo htmlspecialchars(addslashes($r['title'])); ?>','')"><i class="fas fa-undo"></i> Restore</button></td>
<?php elseif ($type === 'programs'): ?>
        <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($r['program_name']); ?></span></div></td>
        <td><span class="client-name"><?php $pLoc = trim((string)($r['brgy_names'] ?? '')) !== '' ? $r['brgy_names'] : ($r['venue'] ?: 'City-wide'); echo htmlspecialchars($pLoc); ?></span></td>
        <td><span class="contact-main"><?php
          $pS = strtotime($r['start_date']); $pE = strtotime($r['end_date']);
          if ($pS === $pE) { $pDates = date('M j, Y', $pS); }
          elseif (date('Y-m', $pS) === date('Y-m', $pE)) { $pDates = date('M j', $pS) . '–' . date('j, Y', $pE); }
          else { $pDates = date('M j', $pS) . '–' . date('M j, Y', $pE); }
          echo $pDates;
        ?></span></td>
        <td><span class="contact-main"><?php echo (int)$r['target_reach'] > 0 ? (int)$r['target_reach'] : '—'; ?></span></td>
        <td><span class="contact-main"><?php echo (int)$r['reached']; ?></span></td>
        <td><span class="contact-main"><?php echo fmtDate($r['updated_at']); ?></span></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
</main>

<div class="toast-container" id="toastContainer"></div>

<div class="sd-dialog-overlay" id="confirmModal">
  <div class="sd-dialog-content">
    <h3 class="sd-dialog-title" id="confirmTitle">Restore Item</h3>
    <p class="sd-dialog-desc" id="confirmText">Are you sure you want to restore this item?</p>
    <div class="sd-dialog-actions">
      <button class="sd-btn" onclick="closeModal()">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="confirmSubmitBtn" onclick="submitRestore()"><i class="fas fa-undo"></i> Restore</button>
    </div>
  </div>
</div>

<script>
var restoreType = '', restoreId = 0;
var currentType = '<?php echo $type; ?>';

function clearSearch() {
  window.location.href = 'staff-archive.php?type=' + currentType;
}

function applyFilter() {
  var val = document.getElementById('filterSelect').value;
  var params = new URLSearchParams(window.location.search);
  if (val) { params.set('filter', val); } else { params.delete('filter'); }
  params.delete('search');
  window.location.href = 'staff-archive.php?' + params.toString();
}

function restoreItem(type, id, label, name, location) {
  restoreType = type; restoreId = id;
  var msg = 'Are you sure you want to restore';
  if (type === 'report') {
    msg += ' report ' + label + ' from ' + name + (location ? ' in ' + location : '') + '?';
  } else if (type === 'appointment') {
    msg += ' ' + label + ' appointment from ' + name + '?';
  } else if (type === 'client') {
    msg += ' client ' + name + (location ? ' from ' + location : '') + '?';
  } else if (type === 'pet') {
    msg += ' ' + label + ' ' + name + (location ? ' from ' + location : '') + '?';
  } else if (type === 'announcement') {
    msg += ' ' + label + ' announcement "' + name + '"?';
  } else {
    msg += ' this item?';
  }
  document.getElementById('confirmText').textContent = msg;
  document.getElementById('confirmModal').classList.add('active');
}

function closeModal() {
  document.getElementById('confirmModal').classList.remove('active');
}

function submitRestore() {
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
  var fd = new FormData();
  fd.append('action', 'restore');
  fd.append('restore_type', restoreType);
  fd.append('id', restoreId);
  fetch('staff-archive.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restore';
      closeModal();
      if (resp.success) {
        showToast('Restored', { type:'success', description:'Item has been restored successfully.' });
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        showToast('Failed', { type:'error', description: resp.message || 'Could not restore item.' });
      }
    })
    .catch(function(){
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restore';
      closeModal();
      showToast('Network error', { type:'error', description:'Could not connect to server.' });
    });
}

var searchTimeout;
document.getElementById('searchInput').addEventListener('input', function(){
  clearTimeout(searchTimeout);
  var val = this.value;
  searchTimeout = setTimeout(function(){
    var params = new URLSearchParams(window.location.search);
    if (val) { params.set('search', val); } else { params.delete('search'); }
    params.set('type', currentType);
    var filterVal = document.getElementById('filterSelect').value;
    if (filterVal) { params.set('filter', filterVal); }
    window.location.href = 'staff-archive.php?' + params.toString();
  }, 600);
});

function showToast(title, opts) {
  opts = opts || {};
  var type = opts.type || 'success';
  var desc = opts.description || '';
  var container = document.getElementById('toastContainer');
  var el = document.createElement('div');
  el.className = 'toast ' + type;
  var icons = {
    success:'<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error:'<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
  };
  var html = (icons[type]||icons.success) + '<div class="toast-body"><p class="toast-title">' + title + '</p>';
  if (desc) html += '<p class="toast-desc">' + desc + '</p>';
  html += '</div><button class="toast-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
  el.innerHTML = html;
  container.appendChild(el);
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('show'); }); });
  el.querySelector('.toast-close').addEventListener('click', function(){ dismissToast(el); });
  var timer = setTimeout(function(){ dismissToast(el); }, 4000);
  el._timer = timer;
  el.addEventListener('mouseenter', function(){ clearTimeout(el._timer); });
  el.addEventListener('mouseleave', function(){ el._timer = setTimeout(function(){ dismissToast(el); }, 2000); });
}
function dismissToast(el) {
  if (el._dismissed) return; el._dismissed = true;
  clearTimeout(el._timer); el.classList.remove('show'); el.classList.add('hiding');
  setTimeout(function(){ el.remove(); }, 300);
}

document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
document.getElementById('confirmModal').addEventListener('click', function(e){ if (e.target === this) closeModal(); });
</script>
</body>
</html>
<?php $conn->close(); ?>
