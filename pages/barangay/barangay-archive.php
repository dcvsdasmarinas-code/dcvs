<?php
session_name('DCVS_BARANGAY_SESSION');
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['barangay_id']) || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/barangay-app-base.php';
$appBaseUrl = dcvsBarangayAppBaseUrl();

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)$_SESSION['barangay_id'];
$fullName   = $conn->real_escape_string($_SESSION['full_name'] ?? 'Barangay Officer');
$barangayName = 'Barangay';
$barangayDistrict = '';

$bq = $conn->prepare("SELECT barangay_name, district FROM barangays WHERE barangay_id = ?");
if ($bq) {
    $bq->bind_param('i', $barangayId);
    $bq->execute();
    $bRow = $bq->get_result()->fetch_assoc();
    if ($bRow) {
        $barangayName     = str_replace('±', 'ñ', (string)$bRow['barangay_name']);
        $barangayDistrict = str_replace('±', 'ñ', (string)$bRow['district']);
    }
    $bq->close();
}

$typeLabels   = ['stray' => 'Stray', 'rabies' => 'Rabies', 'injured' => 'Injured', 'dead' => 'Dead'];
$statusLabels = [
    'pending' => 'New', 'validated' => 'For validation',
    'confirmed' => 'In progress', 'scheduled' => 'In progress',
    'resolved' => 'Resolved', 'rejected' => 'Closed',
];

$archivedReports = [];
$aq = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status,
           r.location_address, r.description, r.photo_url,
           r.created_at, r.archived_at, r.validated_at, r.updated_at,
           u.full_name AS reporter_name,
           v.full_name AS validator_name
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users v ON r.validated_by = v.user_id
    WHERE r.barangay_id = ? AND r.archived_at IS NOT NULL
    ORDER BY r.archived_at DESC
");
$aq->bind_param('i', $barangayId);
$aq->execute();
$arResult = $aq->get_result();
while ($arRow = $arResult->fetch_assoc()) {
    $archivedReports[] = [
        'id'            => $arRow['reference_id'],
        'report_id'     => (int)$arRow['report_id'],
        'category'      => $typeLabels[$arRow['report_type']] ?? ucfirst($arRow['report_type']),
        'type'          => $arRow['report_type'],
        'location'      => $arRow['location_address'],
        'description'   => $arRow['description'],
        'photo'         => $arRow['photo_url'] ?: '',
        'reporter'      => $arRow['reporter_name'],
        'submitted'     => date('M j, Y g:i A', strtotime($arRow['created_at'])),
        'archivedAt'    => date('M j, Y g:i A', strtotime($arRow['archived_at'])),
        'priority'      => ucfirst($arRow['urgency_level']),
        'urgency'       => $arRow['urgency_level'],
        'status'        => $statusLabels[$arRow['status']] ?? ucfirst($arRow['status']),
        'assignedTo'    => $arRow['validator_name'] ?: 'Unassigned',
    ];
}
$aq->close();

$avatarUrl = '';
$avq = $conn->prepare("SELECT avatar_url FROM users WHERE user_id = ?");
if ($avq) {
    $avq->bind_param('i', $userId);
    $avq->execute();
    $avRow = $avq->get_result()->fetch_assoc();
    if ($avRow) $avatarUrl = $avRow['avatar_url'] ?? '';
    $avq->close();
}

$unreadNotifs = 0;
$uq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
if ($uq) {
    $uq->bind_param('i', $userId);
    $uq->execute();
    $unreadNotifs = (int)$uq->get_result()->fetch_row()[0];
    $uq->close();
}

$activePage = 'archive';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Archived Reports — <?php echo htmlspecialchars('BRGY. ' . strtoupper($barangayName)); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/sb2.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/notification.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/notification.css'); ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; -webkit-font-smoothing: antialiased; }
    body { background: #fff; font-family: 'Inter', 'Segoe UI', sans-serif; }

    .app-wrapper{min-width:0;min-height:100vh;display:flex;flex-direction:column;background:#fff;}
    body.sb2-active .app-wrapper{padding-left:var(--sidebar-w);transition:padding-left .2s ease;}
    body.sb2-active .app-wrapper .topbar{left:auto!important;}
    body.sb2-active .app-wrapper .main-content{margin-left:0!important;}

    .topbar{height:64px;background:#fff;border-bottom:1px solid #e4e4e7;display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:40;}
    .topbar-left{display:flex;align-items:center;gap:16px;}
    .topbar-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;cursor:pointer;}
    @media(max-width:768px){.topbar-toggle{display:flex!important;}}
    .topbar-breadcrumb{display:flex;align-items:center;gap:8px;font-size:0.82rem;color:#94a3b8;}
    .breadcrumb-root{color:#0B4D26;font-weight:600;}
    .breadcrumb-sep{font-size:0.6rem;}
    .breadcrumb-current{color:#0f172a;font-weight:600;}
    .topbar-right{display:flex;align-items:center;gap:14px;}
    .notif-dropdown{position:relative;}
    .notif-btn{background:none;border:none;font-size:1.2rem;color:#475569;cursor:pointer;padding:6px;position:relative;border-radius:8px;transition:background .15s;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
    .notif-btn:hover{background:#f1f5f9;}
    .notif-badge{position:absolute;top:0;right:0;background:#dc2626;color:#fff;font-size:.6rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1;}
    .notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:380px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);border:1px solid #e2e8f0;z-index:200;display:none;overflow:hidden;}
    .notif-panel.open{display:block;}
    .notif-panel-header{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1rem;border-bottom:1px solid #e2e8f0;}
    .notif-panel-header h4{margin:0;font-size:.85rem;font-weight:700;color:#0f172a;}
    .notif-panel-header a{font-size:.75rem;color:#0E8131;text-decoration:none;font-weight:600;cursor:pointer;}
    .notif-panel-header a:hover{text-decoration:underline;}
    .notif-panel-body{max-height:360px;overflow-y:auto;}
    .notif-item{display:flex;gap:.75rem;padding:.85rem 1rem;cursor:pointer;transition:background .12s;border-bottom:1px solid #f1f5f9;}
    .notif-item:hover{background:#f8fafc;}
    .notif-item.unread{background:#f0fdf4;}
    .notif-item.unread:hover{background:#dcebe0;}
    .notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;background:transparent;color:#000;}
    .notif-content{flex:1;min-width:0;}
    .notif-title{font-size:.8rem;font-weight:700;color:#0f172a;}
    .notif-msg{font-size:.74rem;color:#64748b;line-height:1.3;margin-top:1px;}
    .notif-time{font-size:.65rem;color:#94a3b8;margin-top:3px;}
    .notif-empty{text-align:center;padding:2rem;color:#94a3b8;font-size:.82rem;}
    @media(max-width:640px){.notif-panel{width:calc(100vw - 2rem)!important;right:.5rem!important;}}
    .user-profile{display:flex;align-items:center;gap:10px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);border:1px solid transparent;}
    .user-profile:hover{background:#F3F4F6;border-color:#F0F2F5;}
    .user-avatar{width:34px;height:34px;border-radius:50%;background:#D5ECE0;border:1px solid #B8DEC9;display:flex;align-items:center;justify-content:center;color:#0B4D26;font-weight:700;font-size:12px;overflow:hidden;}
    .user-meta{display:flex;flex-direction:column;line-height:1.25;}
    .user-name{font-size:14px;font-weight:600;color:#18181B;}
    .user-role{font-size:12px;color:#71717A;}

    .main-content { min-width:0; padding: 1.25rem 2rem 1.75rem; margin-top: 0 !important; }

    .appt-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1rem;}
    .appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .appt-search-wrap{display:flex;align-items:center;gap:8px;}
    .appt-search-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
    .appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;}
    .appt-status-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

    .pet-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 14px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#71717a;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;text-decoration:none;}
    .pet-action-btn:hover{background:#f4f4f5;color:#18181b;border-color:#d4d4d8;}

    .appt-db-table-area{width:100%;min-width:0;max-width:100%;contain:paint;background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow-x:auto;}
    .appt-table{width:100%;min-width:980px;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
    .appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
    .sd-th{position:relative;}
    .th-sort{display:inline-flex;align-items:center;gap:6px;height:28px;margin-left:-12px;padding:0 12px;border-radius:6px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}

    .client-cell{display:flex;flex-direction:column;gap:2px;}
    .client-name{font-size:14px;font-weight:500;color:#18181b;}

    .priority-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0;}
    .priority-dot.urgent{background:#dc2626;}
    .priority-dot.high{background:#ea580c;}
    .priority-dot.medium{background:#d97706;}
    .priority-dot.low{background:#16a34a;}

    .appt-status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:500;border:1px solid;}

    .tag-stray{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
    .tag-rabies{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .tag-injured{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;}
    .tag-dead{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}

    .action-dropdown{position:relative;}
    .action-trigger{background:none;border:none;cursor:pointer;padding:4px;border-radius:4px;color:#71717a;transition:all .15s;}
    .action-trigger:hover{background:#f4f4f5;color:#18181b;}
    .action-menu{display:none;position:absolute;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:160px;z-index:10;padding:4px;}
    .action-menu.open{display:block;}
    .action-menu-item{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border:none;background:none;font-size:13px;font-weight:500;color:#18181b;cursor:pointer;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;transition:background .1s;}
    .action-menu-item:hover{background:#f4f4f5;}
    .action-menu-item.success{color:#045e1f;}
    .action-menu-item.danger{color:#dc2626;}
    .action-menu-item.danger:hover{background:#fef2f2;}
    .menu-divider{height:1px;background:#e4e4e7;margin:4px 0;}

    .empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;text-align:center;}
    .empty-state i{font-size:48px;color:#d4d4d8;margin-bottom:16px;}
    .empty-state h3{font-size:16px;font-weight:600;color:#18181b;margin-bottom:8px;}
    .empty-state p{font-size:14px;color:#71717a;}

    /* Confirm modal */
    .confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
    .confirm-overlay.open{display:flex;}
    .confirm-box{background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.18);max-width:400px;width:90%;overflow:hidden;}
    .confirm-box .c-header{padding:16px 20px;border-bottom:1px solid #e4e4e7;display:flex;align-items:center;justify-content:space-between;}
    .confirm-box .c-header h3{font-size:15px;font-weight:600;color:#18181b;}
    .confirm-box .c-body{padding:24px 20px;text-align:center;}
    .confirm-box .c-body p{font-size:14px;color:#18181b;margin:0;}
    .confirm-box .c-actions{display:flex;gap:10px;justify-content:center;padding:0 20px 20px;}
    .confirm-box .btn-primary{display:inline-flex;align-items:center;gap:6px;padding:10px 28px;background:#045e1f;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:500;font-family:inherit;cursor:pointer;}
    .confirm-box .btn-primary:hover{background:#034a18;}
    .confirm-box .btn-secondary{display:inline-flex;align-items:center;gap:6px;padding:10px 28px;background:#fff;color:#18181b;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;font-weight:500;font-family:inherit;cursor:pointer;}
    .confirm-box .btn-secondary:hover{background:#f4f4f5;}

    .toast-notification{position:fixed;bottom:24px;right:24px;background:#111827;color:#fff;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 10px 15px -3px rgba(0,0,0,.2);display:flex;align-items:center;gap:10px;z-index:200;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);}
    .toast-notification.show{transform:translateY(0);opacity:1;}

    @media(max-width:768px){.topbar{gap:12px;padding:0 16px;}.topbar-left{min-width:0;}.topbar-breadcrumb{min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}.breadcrumb-root,.breadcrumb-current{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.topbar-right{flex:0 0 auto;gap:8px;}.user-meta{display:none;}.main-content{min-width:0;padding:1rem;}.appt-card-header{flex-direction:column;align-items:stretch;}.appt-header-right{width:100%;flex-wrap:wrap;}.appt-search-wrap{min-width:0;flex:1;}.appt-search-input{width:100%;min-width:0;}.appt-status-select{min-width:0;max-width:100%;}}
    @media(max-width:480px){.topbar{padding:0 10px;}.main-content{padding:12px;}.appt-header-right>*{max-width:100%;}}
  </style>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>

<?php $barangaySidebarStylesheetInHead = true; include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>

<div class="app-wrapper">
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="breadcrumb-current" id="breadcrumbCurrent">Archived Reports</span>
      </div>
    </div>
    <div class="topbar-right">
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
          data-action-url="<?php echo htmlspecialchars($appBaseUrl . '/includes/staff-notification-action.php?portal=barangay', ENT_QUOTES, 'UTF-8'); ?>"
          data-load-url="<?php echo htmlspecialchars($appBaseUrl . '/get-notifications.php?limit=30', ENT_QUOTES, 'UTF-8'); ?>" data-app-base-url="<?php echo htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">
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

  <main class="main-content">

    <div class="appt-card-header portal-mobile-toolbar">
      <div class="appt-header-right portal-toolbar-controls">
        <div class="portal-toolbar-filter-grid">
        <select class="appt-status-select portal-toolbar-last-filter" id="categoryFilter">
          <option value="">All Categories</option>
          <option value="stray">Stray</option>
          <option value="rabies">Rabies</option>
          <option value="injured">Injured</option>
          <option value="dead">Dead</option>
        </select>
        </div>
      </div>
    </div>

    <div class="appt-db-table-area">
      <table class="appt-table">
        <thead>
          <tr>
            <th class="sd-th"><button class="th-sort" data-col="id">Report ID</button></th>
            <th class="sd-th"><button class="th-sort" data-col="reporter">Reporter</button></th>
            <th class="sd-th"><button class="th-sort" data-col="category">Category</button></th>
            <th class="sd-th"><button class="th-sort" data-col="location">Location</button></th>
            <th class="sd-th"><button class="th-sort" data-col="submitted">Date Submitted</button></th>
            <th class="sd-th"><button class="th-sort" data-col="archivedAt">Date Archived</button></th>
            <th class="sd-th"><button class="th-sort" data-col="priority">Priority</button></th>
            <th class="sd-th"><button class="th-sort" data-col="status">Status</button></th>
          </tr>
        </thead>
        <tbody id="archiveTableBody"></tbody>
      </table>
      <div id="emptyState" class="empty-state" style="display:none;">
        <i class="fas fa-archive"></i>
        <h3>No archived reports found</h3>
        <p>Archived reports will appear here.</p>
      </div>
    </div>
  </main>
</div>

<!-- Restore Confirm Modal -->
<div class="confirm-overlay" id="restoreModal">
  <div class="confirm-box">
    <div class="c-header"><h3>Restore Report</h3></div>
    <div class="c-body">
      <p id="restoreMsg"></p>
    </div>
    <div class="c-actions">
      <button class="btn-secondary" onclick="closeRestoreModal()">Cancel</button>
      <button class="btn-primary" id="restoreSubmitBtn" onclick="submitRestore()"><i class="fas fa-undo"></i> Restore</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-notification" id="toast"></div>

<script>
var RAW_DATA = <?php echo json_encode($archivedReports); ?>;
var allRows = RAW_DATA.slice();
var filtered = allRows.slice();
var pendingRestoreId = null;

function showToast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 3000);
}

function getFiltered() {
  var cat = document.getElementById('categoryFilter').value;
  filtered = allRows.filter(function(r) {
    if (cat && r.type !== cat) return false;
    return true;
  });
  render();
}

function render() {
  var tbody = document.getElementById('archiveTableBody');
  var emptyEl = document.getElementById('emptyState');

  if (filtered.length === 0) {
    tbody.innerHTML = '';
    emptyEl.style.display = 'flex';
    return;
  }
  emptyEl.style.display = 'none';

  tbody.innerHTML = '';
  filtered.forEach(function(r) {
    var dotClass = 'priority-dot ' + r.urgency;
    var pillBg, pillColor, pillBorder;
    var s = r.status.toLowerCase();
    if (s === 'resolved') { pillBg='#0E8131'; pillColor='#ffffff'; pillBorder='#0E8131'; }
    else if (s === 'new' || s === 'for validation') { pillBg='#FEF3C7'; pillColor='#92400E'; pillBorder='#FDE68A'; }
    else if (s === 'in progress') { pillBg='#EFF6FF'; pillColor='#1E40AF'; pillBorder='#BFDBFE'; }
    else if (s === 'closed') { pillBg='#F4F4F5'; pillColor='#71717A'; pillBorder='#E4E4E7'; }
    else { pillBg='#F4F4F5'; pillColor='#71717A'; pillBorder='#E4E4E7'; }

    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><div class="client-cell"><span class="client-name">' + r.id + '</span></div></td>' +
      '<td style="font-size:14px;color:#18181b;font-weight:600;">' + r.reporter + '</td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.category + '</td>' +
      '<td style="font-size:14px;color:#71717a;">' + r.location + '</td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.submitted + '</td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.archivedAt + '</td>' +
      '<td><span style="display:inline-flex;align-items:center;gap:6px;"><span class="' + dotClass + '"></span>' + r.priority + '</span></td>' +
      '<td><span class="appt-status-pill" style="background:' + pillBg + ';color:' + pillColor + ';border-color:' + pillBorder + ';">' + r.status + '</span></td>';
    tbody.appendChild(tr);
  });
}

/* Dropdown */
function toggleDropdown(btn) {
  closeDropdowns();
  var menu = btn.nextElementSibling;
  if (menu) menu.classList.toggle('open');
}
function closeDropdowns() {
  document.querySelectorAll('.action-menu.open').forEach(function(m) { m.classList.remove('open'); });
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.action-dropdown')) closeDropdowns();
});

/* Restore */
function restoreSingle(rid, refId) {
  pendingRestoreId = rid;
  document.getElementById('restoreMsg').innerHTML = 'Restore <strong>' + refId + '</strong> from the archive?';
  document.getElementById('restoreSubmitBtn').disabled = false;
  document.getElementById('restoreSubmitBtn').innerHTML = '<i class="fas fa-undo"></i> Restore';
  document.getElementById('restoreModal').classList.add('open');
}
function closeRestoreModal() {
  document.getElementById('restoreModal').classList.remove('open');
  pendingRestoreId = null;
}
function submitRestore() {
  if (!pendingRestoreId) return;
  var btn = document.getElementById('restoreSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
  fetch('restore-report.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: pendingRestoreId })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    closeRestoreModal();
    if (d.success) {
      showToast('Report restored.');
      setTimeout(function() { location.reload(); }, 800);
    } else {
      showToast(d.message || 'Failed to restore.');
    }
  }).catch(function() {
    btn.disabled = false;
    closeRestoreModal();
    showToast('Network error.');
  });
}

/* Sort */
var sortCol = '', sortDir = 'asc';
document.querySelectorAll('.th-sort').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var col = this.getAttribute('data-col');
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = 'asc'; }
    document.querySelectorAll('.th-sort').forEach(function(b) { b.classList.remove('th-sorted'); });
    this.classList.add('th-sorted');
    allRows.sort(function(a, b) {
      var va = a[col] || '', vb = b[col] || '';
      if (typeof va === 'string') va = va.toLowerCase();
      if (typeof vb === 'string') vb = vb.toLowerCase();
      if (va < vb) return sortDir === 'asc' ? -1 : 1;
      if (va > vb) return sortDir === 'asc' ? 1 : -1;
      return 0;
    });
    getFiltered();
  });
});

/* Filter */
document.getElementById('categoryFilter').addEventListener('change', getFiltered);

/* Init */
render();

</script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/notification-panel.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/notification-panel.js'); ?>" defer></script>

</body>
</html>
