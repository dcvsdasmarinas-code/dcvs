<?php
session_name('DCVS_BARANGAY_SESSION');
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['barangay_id']) || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

require_once 'db.php';

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)$_SESSION['barangay_id'];
$fullName   = $conn->real_escape_string($_SESSION['full_name'] ?? 'Barangay Officer');
$barangayName = 'Barangay';
$barangayDistrict = '';

$bq = $conn->prepare("SELECT name, district FROM barangays WHERE barangay_id = ?");
if ($bq) {
    $bq->bind_param('i', $barangayId);
    $bq->execute();
    $bRow = $bq->get_result()->fetch_assoc();
    if ($bRow) {
        $barangayName     = str_replace('±', 'ñ', (string)$bRow['name']);
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

$reports = [];
$rq = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status,
           r.location_address, r.description, r.photo_url, r.rejection_reason,
           r.created_at, r.validated_at, r.confirmed_at, r.updated_at,
           r.barangay_notes,
           u.full_name AS reporter_name,
           v.full_name AS validator_name
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users v ON r.validated_by = v.user_id
    WHERE r.barangay_id = ?
    ORDER BY r.created_at DESC
");
$rq->bind_param('i', $barangayId);
$rq->execute();
$rResult = $rq->get_result();
while ($rRow = $rResult->fetch_assoc()) {
    $reports[] = [
        'id'            => $rRow['reference_id'],
        'report_id'     => (int)$rRow['report_id'],
        'category'      => $typeLabels[$rRow['report_type']] ?? ucfirst($rRow['report_type']),
        'type'          => $rRow['report_type'],
        'location'      => $rRow['location_address'],
        'description'   => $rRow['description'],
        'photo'         => $rRow['photo_url'] ?: '',
        'rejection'     => $rRow['rejection_reason'] ?: '',
        'barangay_notes'=> $rRow['barangay_notes'] ?: '',
        'reporter'      => $rRow['reporter_name'],
        'submitted'     => date('M j, Y g:i A', strtotime($rRow['created_at'])),
        'priority'      => ucfirst($rRow['urgency_level']),
        'urgency'       => $rRow['urgency_level'],
        'status'        => $statusLabels[$rRow['status']] ?? ucfirst($rRow['status']),
        'assignedTo'    => $rRow['validator_name'] ?: 'Unassigned',
        '_created_at'   => $rRow['created_at'],
        '_validated_at' => $rRow['validated_at'],
        '_confirmed_at' => $rRow['confirmed_at'],
        '_updated_at'   => $rRow['updated_at'],
        '_db_status'    => $rRow['status'],
        '_validator'    => $rRow['validator_name'] ?: '',
    ];
}
$rq->close();

$avatarUrl = '';
$aq = $conn->prepare("SELECT avatar_url FROM users WHERE user_id = ?");
if ($aq) {
    $aq->bind_param('i', $userId);
    $aq->execute();
    $aRow = $aq->get_result()->fetch_assoc();
    if ($aRow) $avatarUrl = $aRow['avatar_url'] ?? '';
    $aq->close();
}

$activePage = 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Reports — <?php echo htmlspecialchars('BRGY. ' . strtoupper($barangayName)); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; -webkit-font-smoothing: antialiased; }
    body { background: #fff; }

    /* ── Topbar ── */
    .topbar{height:64px;background:#fff;border-bottom:1px solid #e4e4e7;display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:40;}
    .topbar-left{display:flex;align-items:center;gap:16px;}
    .topbar-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;cursor:pointer;}
    @media(max-width:768px){.topbar-toggle{display:flex!important;}}
    .barangay-selector{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:#fff;border:1px solid #e4e4e7;border-radius:999px;font-size:14px;font-weight:500;color:#18181b;cursor:pointer;transition:all .15s;}
    .barangay-selector:hover{background:#f4f4f5;}
    .topbar-right{display:flex;align-items:center;gap:14px;}

    /* ── Main content ── */
    .main-content { padding: 1.25rem 2rem 1.75rem; margin-top: 0 !important; }

    .page-header{display:flex;justify-content:flex-end;margin-bottom:1rem;}

    /* ── Card header (search + filters + buttons) ── */
    .appt-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1rem;}
    .appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .appt-search-wrap{display:flex;align-items:center;gap:8px;}
    .appt-search-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
    .appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;}
    .appt-status-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

    /* ── Buttons ── */
    .pet-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 14px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#71717a;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;text-decoration:none;}
    .pet-action-btn:hover{background:#f4f4f5;color:#18181b;border-color:#d4d4d8;}
    .pet-action-btn.primary-btn{background:#18181b;color:#fff;border-color:#18181b;}
    .pet-action-btn.primary-btn:hover{background:#27272a;}

    /* ── Bulk selection ── */
    .bulk-bar{display:none;align-items:center;gap:12px;margin-bottom:4px;font-family:'Inter','Segoe UI',sans-serif;}
    .bulk-bar.active{display:flex;}
    .bulk-bar .bulk-count{font-size:14px;color:#71717a;white-space:nowrap;margin-right:4px;}
    .bulk-bar .bulk-actions{display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;}
    .bulk-bar .bulk-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:30px;padding:0 12px;border-radius:5px;border:none;background:transparent;color:#52525b;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;}
    .bulk-bar .bulk-btn:hover{background:#f4f4f5;color:#18181b;}
    .bulk-bar .bulk-btn.danger{color:#dc2626;}
    .bulk-bar .bulk-btn.danger:hover{background:#fef2f2;}
    .bulk-bar .bulk-btn svg{width:14px;height:14px;flex-shrink:0;}

    /* ── Table ── */
    .appt-db-table-area{background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow-x:auto;}
    .appt-table{width:100%;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
    .appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}
    .sd-table-cb{width:16px;height:16px;accent-color:#18181b;cursor:pointer;}

    /* ── Sort buttons ── */
    .sd-th{position:relative;}
    .th-sort{display:inline-flex;align-items:center;gap:6px;height:28px;margin-left:-12px;padding:0 12px;border-radius:6px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
    .th-sorted .th-sort-icon{color:#18181b;}

    /* ── Client cell (ID + type) ── */
    .client-cell{display:flex;flex-direction:column;gap:2px;}
    .client-name{font-weight:600;color:#18181b;font-size:14px;}
    .contact-main{font-size:14px;font-weight:400;color:#18181b;}

    /* ── Status pill ── */
    .appt-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}

    /* ── Priority dot ── */
    .priority-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;display:inline-block;}
    .priority-dot.high{background:#ef4444;}
    .priority-dot.medium{background:#f59e0b;}
    .priority-dot.low{background:#22c55e;}

    /* ── Category tag ── */
    .pet-cat-tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}
    .tag-stray{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
    .tag-rabies{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
    .tag-injured{background:#fffbeb;color:#92400e;border-color:#fde68a;}
    .tag-dead{background:#f4f4f5;color:#52525b;border-color:#e4e4e7;}

    /* ── Pagination ── */
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

    /* ── Action dropdown ── */
    .action-dropdown{position:static;display:inline-block;}
    .action-trigger{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#94a3b8;cursor:pointer;transition:all .15s;}
    .action-trigger:hover{border-color:#cbd5e1;color:#475569;background:#f8fafc;}
    .action-menu{position:fixed;z-index:9999;min-width:210px;background:#fff;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.18);padding:6px;display:none;}
    .action-menu.open{display:block;}
    .action-menu-item{display:flex;align-items:center;gap:10px;width:100%;padding:8px 12px;border:none;border-radius:6px;background:none;font-size:14px;font-weight:500;color:#475569;cursor:pointer;transition:all .1s;text-align:left;text-decoration:none;font-family:'Inter','Segoe UI',sans-serif;}
    .action-menu-item:hover{background:#f8fafc;color:#0f172a;}
    .action-menu-item i{width:16px;text-align:center;font-size:14px;color:#94a3b8;}
    .action-menu-item.danger{color:#dc2626;}
    .action-menu-item.danger:hover{background:#fef2f2;}
    .action-menu-item.danger i{color:#dc2626;}
    .action-menu-item.success{color:#059669;}
    .action-menu-item.success:hover{background:#f0fdf4;}
    .action-menu-item.success i{color:#059669;}
    .menu-divider{height:1px;background:#f1f5f9;margin:4px 8px;}

    /* ── Modal (detail) ── */
    .modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);z-index:300;align-items:center;justify-content:center;padding:16px;}
    .modal.open{display:flex;}
    .sd-dialog{width:100%;display:flex;justify-content:center;}
    .sd-dialog-content{position:relative;width:100%;max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);animation:modalIn 0.2s ease;max-height:90vh;overflow-y:auto;}
    .sd-dialog-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:all .15s ease;}
    .sd-dialog-close:hover{background:#f4f4f5;color:#18181b;}
    .sd-dialog-header{display:flex;flex-direction:column;gap:6px;text-align:left;}
    .sd-dialog-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;}
    .sd-dialog-body{display:flex;flex-direction:column;gap:16px;}
    .sd-dialog-footer{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding-top:16px;border-top:1px solid #e4e4e7;}
    @keyframes modalIn{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}

    .sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 16px;border-radius:6px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;border:1px solid transparent;text-decoration:none;}
    .sd-btn-primary{background:#18181b;color:#fff;border-color:#18181b;}
    .sd-btn-primary:hover{background:#27272a;}
    .sd-btn-secondary{background:#fff;color:#18181b;border-color:#e4e4e7;}
    .sd-btn-secondary:hover{background:#f4f4f5;}
    .sd-btn-danger{background:#dc2626;color:#fff;border-color:#dc2626;}
    .sd-btn-danger:hover{background:#b91c1c;}
    .sd-field-group{display:flex;flex-direction:column;gap:16px;}
    .sd-section{margin-bottom:16px;}
    .sd-section:last-child{margin-bottom:0;}
    .sd-section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#18181b;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f4f4f5;}
    .sd-section-title span{color:#71717a;font-size:12px;font-weight:600;}
    .sd-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;}
    .sd-info-item .sd-info-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#71717a;}
    .sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;margin-top:2px;word-break:break-word;}

    .sd-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1;color:#18181b;}
    .sd-input,.sd-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;width:100%;}
    .sd-input:focus,.sd-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

    /* ── Toast ── */
    .toast-notification{position:fixed;bottom:24px;right:24px;background:#111827;color:#fff;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 10px 15px -3px rgba(0,0,0,.2);display:flex;align-items:center;gap:10px;z-index:200;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);}
    .toast-notification.show{transform:translateY(0);opacity:1;}

    @media(max-width:768px){.topbar{padding:0 16px;}.main-content{padding:1rem;}.appt-card-header{flex-direction:column;align-items:stretch;}.appt-header-right{flex-wrap:wrap;}}
  </style>
</head>
<body>

<?php include 'includes/barangay-sidebar.php'; ?>

  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
    </div>
    <div class="topbar-right"></div>
  </header>

  <main class="main-content">

    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <input type="text" id="searchInput" class="appt-search-input" placeholder="Search by ID, category, location..." />
        </div>
        <select class="appt-status-select" id="statusFilter">
          <option value="">All Status</option>
          <option value="pending">New</option>
          <option value="validated">For validation</option>
          <option value="confirmed">In progress</option>
          <option value="resolved">Resolved</option>
          <option value="rejected">Closed</option>
        </select>
        <select class="appt-status-select" id="categoryFilter">
          <option value="">All Categories</option>
          <option value="stray">Stray</option>
          <option value="rabies">Rabies</option>
          <option value="injured">Injured</option>
          <option value="dead">Dead</option>
        </select>
        <select class="appt-status-select" id="priorityFilter">
          <option value="">All Priority</option>
          <option value="high">High</option>
          <option value="medium">Medium</option>
          <option value="low">Low</option>
        </select>
        <button class="pet-action-btn" id="clearFiltersBtn" style="display:none;" onclick="clearAllFilters()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters
        </button>
      </div>
      <button class="pet-action-btn primary-btn" onclick="exportAll()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export</button>
    </div>

    <div class="bulk-bar" id="bulkBar">
      <div class="bulk-actions">
        <span class="bulk-count"><span id="bulkCount">0</span> reports selected</span>
        <button class="bulk-btn" onclick="bulkExport()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export Selected</button>
        <button class="bulk-btn danger" onclick="bulkDelete()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Delete</button>
      </div>
    </div>

    <div class="appt-db-table-area" id="tableWrap">
      <table class="appt-table">
        <thead>
          <tr>
            <th style="width:40px;"><input type="checkbox" class="sd-table-cb" id="selectAll" onclick="toggleSelectAll(this)" /></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="id" onclick="TBL.sortBy('id')">Report ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="category" onclick="TBL.sortBy('category')">Category<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th>Location</th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="submitted" onclick="TBL.sortBy('submitted')">Submitted<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="priority" onclick="TBL.sortBy('priority')">Priority<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="TBL.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th>Assigned to</th>
            <th style="width:48px;"></th>
          </tr>
        </thead>
        <tbody id="reportsTableBody"></tbody>
      </table>
    </div>
    <div id="reportsPagination" class="appt-pagination"></div>

  </main>

<!-- Report Detail Modal -->
<div class="modal" id="reportDetailModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content">
      <button class="sd-dialog-close" onclick="closeModal('reportDetailModal')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <span style="font-size:13px;color:#71717a;" id="detail-id">RPT-0000-0</span>
        <h3 class="sd-dialog-title" id="detail-category">Incident Details</h3>
      </div>
      <div class="sd-dialog-body" id="detail-body"></div>
      <div class="sd-dialog-footer" id="detail-footer"></div>
    </div>
  </div>
</div>

<!-- Confirm Modal -->
<div class="modal" id="confirmModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
      <div style="text-align:center;">
        <div id="confirmIcon" style="margin-bottom:14px;">
          <i class="fas fa-exclamation-triangle" style="font-size:40px;color:#f59e0b;"></i>
        </div>
        <div id="confirmText" style="font-size:14px;color:#71717a;line-height:1.5;">Are you sure?</div>
      </div>
      <div class="sd-dialog-footer" style="justify-content:center;">
        <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="confirmSubmitBtn"><i class="fas fa-check"></i> Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-notification" id="toast-msg"><i class="fas fa-check-circle"></i><span id="toast-text"></span></div>

<script>
var REPORTS_DATA = <?php echo json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var currentSelectedReport = null;

function getFilteredList() {
  var s = document.getElementById('searchInput').value.toLowerCase();
  var st = document.getElementById('statusFilter').value;
  var cat = document.getElementById('categoryFilter').value;
  var pri = document.getElementById('priorityFilter').value;
  return REPORTS_DATA.filter(function(r) {
    if (st && (r._db_status || '').toLowerCase() !== st.toLowerCase()) return false;
    if (cat && r.type.toLowerCase() !== cat.toLowerCase()) return false;
    if (pri && r.urgency.toLowerCase() !== pri.toLowerCase()) return false;
    if (s && (r.id.toLowerCase().indexOf(s) !== -1 || r.category.toLowerCase().indexOf(s) !== -1 || r.location.toLowerCase().indexOf(s) !== -1 || r.assignedTo.toLowerCase().indexOf(s) !== -1)) return true;
    if (!s) return true;
    return false;
  });
}

/* ── Table body render ── */
function renderRows(list) {
  var tbody = document.getElementById('reportsTableBody');
  tbody.innerHTML = '';
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px 16px;color:#71717a;font-size:14px;">No reports found.</td></tr>';
    return;
  }
  list.forEach(function(r) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-rid', r.report_id);

    var dotClass = (r.urgency === 'high' || r.urgency === 'critical') ? 'high' : (r.urgency === 'medium' ? 'medium' : 'low');

    var pillBg, pillColor, pillBorder;
    switch (r._db_status) {
      case 'pending': pillBg='#fef3c7';pillColor='#92400e';pillBorder='#fde68a';break;
      case 'validated': pillBg='#dcebe0';pillColor='#045e1f';pillBorder='#bbf7d0';break;
      case 'confirmed':case 'scheduled': pillBg='#EBF5FF';pillColor='#175CD3';pillBorder='#D5E9FD';break;
      case 'resolved': pillBg='#ECFDF5';pillColor='#027A48';pillBorder='#a7f3d0';break;
      case 'rejected': pillBg='#fee2e2';pillColor='#991b1b';pillBorder='#fecaca';break;
      default: pillBg='#f4f4f5';pillColor='#71717a';pillBorder='#e4e4e7';
    }

    var catClass = 'tag-' + r.type;

    tr.innerHTML =
      '<td><input type="checkbox" class="sd-table-cb row-cb" value="' + r.report_id + '" onclick="updateBulkBar()" /></td>' +
      '<td><div class="client-cell"><span class="client-name">' + r.id + '</span></div></td>' +
      '<td><span class="pet-cat-tag ' + catClass + '">' + r.category + '</span></td>' +
      '<td style="font-size:14px;color:#71717a;">' + r.location + '</td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.submitted + '</td>' +
      '<td><span style="display:inline-flex;align-items:center;gap:6px;"><span class="priority-dot ' + dotClass + '"></span>' + r.priority + '</span></td>' +
      '<td><span class="appt-status-pill" style="background:' + pillBg + ';color:' + pillColor + ';border-color:' + pillBorder + ';">' + r.status + '</span></td>' +
      '<td style="font-size:14px;color:' + (r.assignedTo === 'Unassigned' ? '#a1a1aa' : '#18181b') + ';">' + r.assignedTo + '</td>' +
      '<td class="sd-td-action"><div class="action-dropdown"><button class="action-trigger" onclick="event.stopPropagation();toggleDropdown(this)" title="Actions"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg></button><div class="action-menu">' +
      (r._db_status === 'pending' ? '<button class="action-menu-item success" onclick="closeDropdowns();validateSingle(' + r.report_id + ')"><i class="fas fa-check"></i> Validate</button><div class="menu-divider"></div>' : '') +
      (r._db_status === 'pending' || r._db_status === 'resolved' ? '<button class="action-menu-item" onclick="closeDropdowns();archiveSingle(' + r.report_id + ',\'' + r.id + '\')"><i class="fas fa-archive"></i> Archive</button><div class="menu-divider"></div><button class="action-menu-item danger" onclick="closeDropdowns();deleteSingle(' + r.report_id + ',\'' + r.id + '\')"><i class="fas fa-trash"></i> Delete</button>' : '') +
      '</div></div></td>';
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function(e) {
      if (e.target.closest('.sd-table-cb') || e.target.closest('.action-dropdown')) return;
      openReportDetail(r.id);
    });
    tbody.appendChild(tr);
  });
}

/* ── Sort ── */
var TBL = (function() {
  var sortCol = '', sortDir = 'asc';
  var colIdx = {id:1, category:2, submitted:4, priority:5, status:6};

  function doSort(col) {
    if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortCol = col; sortDir = 'asc'; }

    var list = getFilteredList();
    list.sort(function(a, b) {
      var va = '', vb = '';
      if (col === 'id') { va = a.id; vb = b.id; }
      else if (col === 'category') { va = a.category; vb = b.category; }
      else if (col === 'submitted') { va = a._created_at; vb = b._created_at; }
      else if (col === 'priority') { va = a.urgency; vb = b.urgency; }
      else if (col === 'status') { va = a.status; vb = b.status; }
      va = va.toLowerCase(); vb = vb.toLowerCase();
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

    renderRows(list);
    paginate();
  }
  return { sortBy: doSort };
})();

/* ── Pagination ── */
var PER_PAGE = 15, currentPage = 1;
function paginate() {
  var tbody = document.getElementById('reportsTableBody');
  var pag = document.getElementById('reportsPagination');
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var total = allRows.length;
  var tp = Math.max(1, Math.ceil(total / PER_PAGE));
  if (currentPage > tp) currentPage = tp;
  if (currentPage < 1) currentPage = 1;
  var start = (currentPage - 1) * PER_PAGE;
  allRows.forEach(function(r, i) { r.style.display = (i >= start && i < start + PER_PAGE) ? '' : 'none'; });

  var h = '<div class="dt-pagination-right">';
  h += '<div class="dt-rows"><span>Rows per page</span><select onchange="setPerPage(this.value)"><option value="15"' + (PER_PAGE === 15 ? ' selected' : '') + '>15</option><option value="25"' + (PER_PAGE === 25 ? ' selected' : '') + '>25</option><option value="50"' + (PER_PAGE === 50 ? ' selected' : '') + '>50</option></select></div>';
  h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
  h += '<div class="dt-page-btns">';
  h += '<button class="dt-page-btn" onclick="goPage(1)"' + (currentPage <= 1 ? ' disabled' : '') + ' title="First"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + ' title="Prev"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Next"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Last"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
  h += '</div></div>';
  pag.innerHTML = h;
}
function goPage(p) { currentPage = p; paginate(); }
function setPerPage(v) { PER_PAGE = parseInt(v) || 15; currentPage = 1; paginate(); }

/* ── Bulk selection ── */
function toggleSelectAll(cb) {
  document.querySelectorAll('.row-cb').forEach(function(b) { b.checked = cb.checked; });
  updateBulkBar();
}
function updateBulkBar() {
  var checked = document.querySelectorAll('.row-cb:checked');
  document.getElementById('bulkCount').textContent = checked.length;
  document.getElementById('bulkBar').classList.toggle('active', checked.length > 0);
  var all = document.querySelectorAll('.row-cb');
  var sel = document.getElementById('selectAll');
  sel.checked = all.length > 0 && checked.length === all.length;
  sel.indeterminate = checked.length > 0 && checked.length < all.length;
}
function getSelectedIds() {
  var ids = [];
  document.querySelectorAll('.row-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
  return ids;
}
function bulkExport() {
  var ids = getSelectedIds();
  if (!ids.length) return;
  var rows = [['Report ID','Category','Type','Location','Submitted','Priority','Status','Assigned To']];
  REPORTS_DATA.filter(function(r) { return ids.indexOf(r.report_id) !== -1; }).forEach(function(r) {
    rows.push([r.id, r.category, r.type, r.location, r.submitted, r.priority, r.status, r.assignedTo]);
  });
  downloadCSV(rows);
}
function bulkDelete() { showToast('Bulk delete is not available.'); }

/* ── Action dropdown ── */
function toggleDropdown(btn) {
  var menu = btn.parentElement.querySelector('.action-menu');
  var isOpen = menu.classList.contains('open');
  closeDropdowns();
  if (!isOpen) {
    var rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.left = Math.max(8, rect.right - 210) + 'px';
    menu.classList.add('open');
  }
}
function closeDropdowns() { document.querySelectorAll('.action-menu.open').forEach(function(m) { m.classList.remove('open'); }); }
document.addEventListener('click', function(e) { if (!e.target.closest('.action-dropdown')) closeDropdowns(); });

/* ── Report detail modal ── */
function openReportDetail(reportId) {
  var report = REPORTS_DATA.find(function(r) { return r.id === reportId; });
  if (!report) return;
  currentSelectedReport = report;

  document.getElementById('detail-id').textContent = report.id;
  document.getElementById('detail-category').textContent = report.category;

  var isPending = report._db_status === 'pending';
  var isRejected = report._db_status === 'rejected';

  var photoHtml = report.photo
    ? '<div class="sd-section">' +
      '<div class="sd-section-title"><span>03</span> Attached Photo Evidence</div>' +
      '<img src="' + report.photo + '" alt="Photo" style="width:100%;border-radius:6px;border:1px solid #e4e4e7;max-height:260px;object-fit:cover;" onerror="this.style.display=\'none\'" /></div>' : '';

  var rejectionHtml = isRejected && report.rejection
    ? '<div class="sd-section">' +
      '<div class="sd-section-title" style="color:#991b1b;border-bottom-color:#fecaca;"><span>Rejection Reason</span></div>' +
      '<p style="font-size:14px;color:#991b1b;line-height:1.6;margin:0;">' + report.rejection + '</p></div>' : '';

  var pillBg, pillColor, pillBorder;
  switch (report._db_status) {
    case 'pending': pillBg='#fef3c7';pillColor='#92400e';pillBorder='#fde68a';break;
    case 'validated': pillBg='#dcebe0';pillColor='#045e1f';pillBorder='#bbf7d0';break;
    case 'confirmed':case 'scheduled': pillBg='#EBF5FF';pillColor='#175CD3';pillBorder='#D5E9FD';break;
    case 'resolved': pillBg='#ECFDF5';pillColor='#027A48';pillBorder='#a7f3d0';break;
    case 'rejected': pillBg='#fee2e2';pillColor='#991b1b';pillBorder='#fecaca';break;
    default: pillBg='#f4f4f5';pillColor='#71717a';pillBorder='#e4e4e7';
  }

  document.getElementById('detail-body').innerHTML =
    '<div style="display:flex;gap:10px;">' +
      '<span class="appt-status-pill" style="background:' + pillBg + ';color:' + pillColor + ';border-color:' + pillBorder + ';">Status: ' + report.status + '</span>' +
      '<span class="appt-status-pill" style="background:' + (report.urgency==='high'||report.urgency==='critical'?'#fee2e2':'#fef3c7') + ';color:' + (report.urgency==='high'||report.urgency==='critical'?'#991b1b':'#92400e') + ';border-color:' + (report.urgency==='high'||report.urgency==='critical'?'#fecaca':'#fde68a') + ';">Priority: ' + report.priority + '</span>' +
    '</div>' +
    '<div class="sd-section">' +
      '<div class="sd-section-title"><span>01</span> Report Details</div>' +
      '<div class="sd-info-grid">' +
        '<div class="sd-info-item"><div class="sd-info-label">Type of Report</div><div class="sd-info-value">' + report.category + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Urgency Level</div><div class="sd-info-value">' + report.priority + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Location</div><div class="sd-info-value">' + report.location + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Date Submitted</div><div class="sd-info-value">' + report.submitted + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Reported By</div><div class="sd-info-value">' + (report.reporter || 'Resident') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Assigned Officer</div><div class="sd-info-value">' + report.assignedTo + '</div></div>' +
      '</div>' +
    '</div>' +
    '<div class="sd-section">' +
      '<div class="sd-section-title"><span>02</span> Description</div>' +
      '<div class="sd-info-grid">' +
        '<div class="sd-info-item" style="grid-column:1/-1;"><div class="sd-info-label">Situation &amp; Detailed Description</div><div class="sd-info-value">' + (report.description || 'No description provided.') + '</div></div>' +
      '</div>' +
    '</div>' +
    photoHtml + rejectionHtml;

  var footer = document.getElementById('detail-footer');
  if (isPending) {
    footer.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:10px;width:100%;">' +
        '<div id="rejectReasonWrap" style="display:none;flex-direction:column;gap:10px;">' +
          '<label class="sd-label" for="rejectReasonInput">Rejection Reason</label>' +
          '<textarea class="sd-input" id="rejectReasonInput" rows="3" placeholder="State the reason for rejecting..." style="height:auto;padding:8px 12px;"></textarea>' +
          '<div style="display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:8px;">' +
            '<button class="sd-btn sd-btn-danger" onclick="doReject()">Confirm Reject</button>' +
            '<button class="sd-btn sd-btn-secondary" onclick="document.getElementById(\'rejectReasonWrap\').style.display=\'none\'">Cancel</button>' +
          '</div>' +
        '</div>' +
        '<div style="display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:8px;">' +
          '<button class="sd-btn sd-btn-primary" onclick="doValidate()">Validate Report</button>' +
          '<button class="sd-btn sd-btn-secondary" style="color:#dc2626;border-color:#fecaca;" onclick="var w=document.getElementById(\'rejectReasonWrap\');w.style.display=w.style.display===\'none\'?\'flex\':\'none\'">Reject Report</button>' +
          '<button class="sd-btn sd-btn-secondary" onclick="closeModal(\'reportDetailModal\')">Close</button>' +
        '</div>' +
      '</div>';
  } else {
    footer.innerHTML = '<button class="sd-btn sd-btn-secondary" onclick="closeModal(\'reportDetailModal\')">Close</button>';
  }

  document.getElementById('reportDetailModal').classList.add('open');
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id) { document.getElementById(id).classList.add('open'); }

/* ── Confirm modal ── */
var pendingConfirmAction = null;
function confirmAction(action, rid, refId, message) {
  pendingConfirmAction = { action: action, rid: rid, refId: refId };
  document.getElementById('confirmText').innerHTML = message;
  var icon = document.getElementById('confirmIcon');
  var btn = document.getElementById('confirmSubmitBtn');
  btn.innerHTML = '<i class="fas fa-check"></i> Confirm';
  btn.className = 'sd-btn sd-btn-primary';
  if (action === 'delete') {
    icon.innerHTML = '<i class="fas fa-trash" style="font-size:40px;color:#dc2626;"></i>';
    btn.className = 'sd-btn sd-btn-danger';
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
  } else if (action === 'archive') {
    icon.innerHTML = '<i class="fas fa-archive" style="font-size:40px;color:#64748b;"></i>';
  }
  btn.onclick = function() { submitConfirmedAction(); };
  openModal('confirmModal');
}
function submitConfirmedAction() {
  if (!pendingConfirmAction) return;
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
  var a = pendingConfirmAction;
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: a.rid, action: a.action })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false; closeModal('confirmModal');
    if (d.success) { showToast(a.action === 'delete' ? 'Report deleted.' : 'Report archived.'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { btn.disabled = false; closeModal('confirmModal'); showToast('Network error.'); });
}

function doValidate() {
  var report = currentSelectedReport;
  if (!report) return;
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: report.report_id, action: 'validate' })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success) { showToast('Report validated.'); closeModal('reportDetailModal'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { showToast('Network error.'); });
}

function doReject() {
  var report = currentSelectedReport;
  if (!report) return;
  var reason = (document.getElementById('rejectReasonInput') || {}).value || '';
  if (!reason.trim()) { showToast('Please state a rejection reason.'); return; }
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: report.report_id, action: 'reject', reason: reason })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success) { showToast('Report rejected.'); closeModal('reportDetailModal'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { showToast('Network error.'); });
}

function validateSingle(rid) {
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: rid, action: 'validate' })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success) { showToast('Report validated.'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { showToast('Network error.'); });
}

function rejectSingle(rid, refId) {
  var reason = prompt('Rejection reason for ' + refId + ':');
  if (reason === null || !reason.trim()) return;
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: rid, action: 'reject', reason: reason })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success) { showToast('Report rejected.'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { showToast('Network error.'); });
}

function archiveSingle(rid, refId) {
  confirmAction('archive', rid, refId, 'Archive <strong>' + refId + '</strong>?');
}

function deleteSingle(rid, refId) {
  confirmAction('delete', rid, refId, 'Permanently delete <strong>' + refId + '</strong>? This cannot be undone.');
}

/* ── Export CSV ── */
function downloadCSV(rows) {
  var csv = rows.map(function(r) { return r.map(function(c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
  var blob = new Blob([csv], { type: 'text/csv' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'reports-<?php echo htmlspecialchars($barangayName); ?>-' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  showToast('CSV exported.');
}
function exportAll() {
  var rows = [['Report ID','Category','Type','Location','Submitted','Priority','Status','Assigned To']];
  getFilteredList().forEach(function(r) { rows.push([r.id,r.category,r.type,r.location,r.submitted,r.priority,r.status,r.assignedTo]); });
  downloadCSV(rows);
}

/* ── Clear filters ── */
function clearAllFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('categoryFilter').value = '';
  document.getElementById('priorityFilter').value = '';
  refresh();
}
function updateClearBtn() {
  var has = document.getElementById('searchInput').value || document.getElementById('statusFilter').value || document.getElementById('categoryFilter').value || document.getElementById('priorityFilter').value;
  document.getElementById('clearFiltersBtn').style.display = has ? 'inline-flex' : 'none';
}

/* ── Toast ── */
function showToast(msg) {
  var t = document.getElementById('toast-msg');
  document.getElementById('toast-text').textContent = msg;
  t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 3000);
}

/* ── URL params ── */
var URL_PARAMS = new URLSearchParams(window.location.search);
var initialStatus = URL_PARAMS.get('status') || '';
if (initialStatus) document.getElementById('statusFilter').value = initialStatus;

/* ── Refresh ── */
function refresh() {
  var hasUserFilter = document.getElementById('searchInput').value || document.getElementById('categoryFilter').value || document.getElementById('priorityFilter').value || (document.getElementById('statusFilter').value && document.getElementById('statusFilter').value !== initialStatus);
  document.getElementById('clearFiltersBtn').style.display = hasUserFilter ? 'inline-flex' : 'none';
  renderRows(getFilteredList());
  currentPage = 1;
  paginate();
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', function() {
  refresh();
  document.getElementById('searchInput').addEventListener('input', refresh);
  document.getElementById('statusFilter').addEventListener('change', refresh);
  document.getElementById('categoryFilter').addEventListener('change', refresh);
  document.getElementById('priorityFilter').addEventListener('change', refresh);
  document.getElementById('selectAll').addEventListener('change', function() { toggleSelectAll(this); });
});
</script>

</body>
</html>
