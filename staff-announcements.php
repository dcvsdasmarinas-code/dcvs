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

require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$pageTitle = 'Announcements';
$activePage = 'announcements';

$announcements = [];
$aq = $conn->query("SELECT a.*, u.full_name AS creator_name FROM announcements a JOIN users u ON a.created_by = u.user_id ORDER BY a.created_at DESC");
if ($aq) {
    while ($row = $aq->fetch_assoc()) $announcements[] = $row;
}

$barangays = [];
$bq = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($bq) {
    while ($row = $bq->fetch_assoc()) $barangays[] = ['id' => (int)$row['barangay_id'], 'name' => $row['barangay_name']];
}

$success = $_SESSION['announcement_success'] ?? null;
$error = $_SESSION['announcement_error'] ?? null;
unset($_SESSION['announcement_success'], $_SESSION['announcement_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Staff | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />

  <style>
    /* ── Client & Pet Database (CPD) design system ── */
    .cpd{ --green-50:#eef6f0; --green-100:#dcebe0; --green-200:#b8d1c0;
      --green-500:#057b2d; --green-600:#046b25; --green-700:#045e1f;
      --amber-50:#fffbeb; --amber-600:#d97706; --amber-700:#b45309;
      --ink-900:#0f1720; --ink-700:#334155; --ink-500:#64748b;
      --ink-400:#94a3b8; --ink-300:#cbd5e1; --ink-200:#e2e8f0;
      --ink-100:#f1f5f9; --ink-50:#f8fafc; --border:#e6e9ee;
      --white:#ffffff;
      --shadow-sm:0 1px 2px rgba(15,23,32,.04);
      --shadow-md:0 4px 14px rgba(15,23,32,.06);
      --radius:14px; --radius-sm:10px;
      box-sizing:border-box; background:#ffffff; color:var(--ink-900); -webkit-font-smoothing:antialiased;
      font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif; font-size:14px;
    }
    .cpd *{ box-sizing:border-box; }
    .cpd-app{ width:100%; padding:8px 0 40px; }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; }

    .cpd .btn{ display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:13.5px; font-weight:600; border-radius:10px; padding:10px 16px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); cursor:pointer; transition:background .15s ease, box-shadow .15s ease, border-color .15s ease; white-space:nowrap; }
    .cpd .btn:hover{ background:var(--ink-50); border-color:var(--ink-300); }
    .cpd .btn-primary{ background:var(--green-600); border-color:var(--green-600); color:var(--white); box-shadow:var(--shadow-sm); }
    .cpd .btn-primary:hover{ background:var(--green-700); border-color:var(--green-700); }

    /* KPI cards */
    .cpd .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    .cpd .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:18px 20px; background:none; border:none; border-radius:0; box-shadow:none; }
    .cpd .kpi-label{ font-size:12.5px; color:#000; font-weight:600; margin:0 0 8px; }
    .cpd .kpi-value{ font-size:26px; font-weight:700; color:var(--ink-900); letter-spacing:-.02em; line-height:1; margin-bottom:8px; }
    .cpd .kpi-sub{ font-size:12px; color:var(--ink-400); }

    /* Layout */
    .cpd .layout{ display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:0; align-items:stretch; min-height:calc(100vh - 120px); }
    .cpd .panel{ background:var(--white); border:1px solid var(--border); }

    /* Full-page database (no container look) */
    .announcements-cms{ padding:0 !important; background:#ffffff; }
    .reports-fullpage{ background:transparent; }
    .reports-fullpage .cpd-app{ padding:0; }
    .reports-fullpage .layout{ min-height:calc(100vh - var(--topbar-h)); }
    .reports-fullpage .panel{ background:transparent; border:none; }
    .reports-fullpage .filters{ border-right:1px solid var(--border); }
    .reports-fullpage .db-panel{ border-left:none; border-right:1px solid var(--border); }
    .reports-fullpage .profile-panel{ border-left:none; }

    /* Left filters sidebar rail */
    .cpd .filters{ padding:20px 16px; display:flex; flex-direction:column; border-right:1px solid var(--border); position:sticky; top:0; overflow-y:auto; max-height:calc(100vh - var(--topbar-h)); }
    .cpd .rail-nav{ display:flex; flex-direction:column; gap:20px; }
    .cpd .rail-divider{ height:1px; background:var(--border); margin:18px 0; }
    .cpd .rail-filters{ display:flex; flex-direction:column; gap:15px; }
    .cpd .field{ display:flex; flex-direction:column; gap:6px; }
    .cpd .field-label{ font-size:12px; font-weight:600; color:var(--ink-600); }
    .cpd .clear-btn{ display:flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:9px 12px; border-radius:9px; border:1px solid var(--border); background:var(--white); color:var(--ink-600); font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s ease, color .15s ease, border-color .15s ease; }
    .cpd .clear-btn:hover{ background:var(--ink-50); color:var(--ink-800); border-color:var(--ink-300); }
    .cpd .tip-box{ margin-top:auto; }
    .cpd .filter-list{ display:flex; flex-direction:column; gap:4px; }
    .cpd .filter-item{ display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-radius:9px; cursor:pointer; font-weight:500; color:#000; font-size:13.5px; border:none; background:none; width:100%; font-family:inherit; text-align:left; }
    .cpd .filter-item:hover{ background:var(--ink-50); }
    .cpd .filter-item.active{ background:var(--green-50); color:#000; font-weight:600; }
    .cpd .filter-item-left{ display:flex; align-items:center; gap:9px; min-width:0; }
    .cpd .filter-item-left i{ flex-shrink:0; width:14px; font-size:12px; color:#000; }
    .cpd .filter-item.active .filter-item-left i{ color:#000; }
    .cpd .filter-count{ font-size:12px; font-weight:600; color:var(--ink-400); background:var(--ink-100); padding:2px 8px; border-radius:20px; }
    .cpd .filter-item.active .filter-count{ background:var(--green-100); color:var(--green-700); }
    .cpd .section-label{ font-size:11px; font-weight:700; letter-spacing:.06em; color:var(--ink-400); text-transform:uppercase; margin:0 0 10px 2px; }
    .cpd select.select-input{ width:100%; padding:10px 12px; border-radius:9px; border:1px solid var(--border); background:var(--white); font-family:inherit; font-size:13.5px; color:var(--ink-700); appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; cursor:pointer; }
    .cpd .text-input-wrap{ position:relative; }
    .cpd .text-input-wrap input{ width:100%; padding:10px 12px 10px 34px; border-radius:9px; border:1px solid var(--border); font-family:inherit; font-size:13px; color:var(--ink-700); outline:none; }
    .cpd .text-input-wrap input:focus{ border-color:var(--green-500); }
    .cpd .text-input-wrap input::placeholder{ color:var(--ink-400); }
    .cpd .text-input-wrap svg{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--ink-400); }
    .cpd .tip-box{ background:var(--green-50); border:1px solid var(--green-100); border-radius:var(--radius-sm); padding:16px; }
    .cpd .tip-title{ display:flex; align-items:center; gap:7px; font-weight:700; font-size:13px; color:var(--green-700); margin-bottom:6px; }
    .cpd .tip-box p{ margin:0; font-size:12.5px; line-height:1.5; color:#3f6b4c; }

    /* Center db */
    .cpd .db-panel{ padding:18px; border-left:none; border-right:1px solid var(--border); display:flex; flex-direction:column; }
    .cpd .db-panel .kpis{ flex-shrink:0; }
    .cpd .db-panel .db-toolbar{ flex-shrink:0; }
    .cpd .db-table-area{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .db-table-area #tableWrap{ flex:1; min-height:0; overflow-x:auto; }
    .cpd .db-panel .pagination{ flex-shrink:0; margin-top:auto; }
    .cpd .db-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .cpd .view-controls{ display:flex; align-items:center; gap:4px; background:var(--ink-100); padding:3px; border-radius:9px; }
    .cpd .view-controls .seg-btn{ border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--ink-500); padding:6px 12px; border-radius:7px; cursor:pointer; }
    .cpd .view-controls .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }
    .cpd .search-grow-wrap{ position:relative; flex:0 1 auto; width:300px; min-width:180px; }
    .cpd .search-grow-wrap input{ width:100%; padding:11px 14px 11px 38px; border-radius:10px; border:1px solid var(--border); font-family:inherit; font-size:13.5px; color:var(--ink-700); outline:none; }
    .cpd .search-grow-wrap input:focus{ border-color:var(--green-500); }
    .cpd .search-grow-wrap input::placeholder{ color:var(--ink-400); }
    .cpd .search-grow-wrap svg{ position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--ink-400); }
    .cpd .sort-select{ width:auto; min-width:150px; max-width:180px; }
    .cpd .toolbar-filters{ display:flex; align-items:center; gap:8px; }
    .cpd .tf-label{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--ink-600); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
    .cpd .tf-select{ width:auto; min-width:130px; }
    .cpd .tf-clear{ width:auto; padding:10px 12px; }

    .cpd table{ width:100%; border-collapse:collapse; }
    .cpd thead th{ text-align:left; font-size:11.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#000; padding:10px 12px; background:var(--ink-100); white-space:nowrap; border-bottom:10px solid transparent; }
    .cpd tbody tr{ cursor:pointer; transition:background .12s ease; border-bottom:1px solid var(--ink-100); }
    .cpd tbody tr:hover{ background:var(--ink-50); }
    .cpd tbody tr.selected{ background:var(--green-50); }
    .cpd tbody td{ padding:14px 12px; vertical-align:middle; font-size:13.5px; color:var(--ink-700); }
    .cpd .client-name{ font-weight:600; color:var(--ink-900); font-size:13.5px; }
    .cpd .contact-main{ color:var(--ink-700); font-weight:500; }
    .cpd .contact-sub{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .status-pill{ display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; }
    .cpd .row-menu{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .row-menu:hover{ background:var(--ink-100); color:var(--ink-700); }

    /* Row menu dropdown */
    .cpd .row-menu-pop{ position:absolute; z-index:60; background:var(--white); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow-md); padding:6px; min-width:190px; display:none; }
    .cpd .row-menu-pop.open{ display:block; }
    .cpd .row-menu-item{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:none; background:none; border-radius:8px; font-family:inherit; font-size:13px; font-weight:500; color:var(--ink-700); cursor:pointer; text-align:left; }
    .cpd .row-menu-item:hover{ background:var(--ink-50); }
    .cpd .row-menu-item.danger{ color:#dc2626; }
    .cpd .row-menu-item.danger:hover{ background:#fef2f2; }
    .cpd .row-menu-item i{ width:15px; text-align:center; }
    .announcements-cms .row-menu-pop{ position:fixed; z-index:999; }

    .cpd .cards-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }

    .cpd .pagination{ display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:14px; border-top:1px solid var(--border); flex-wrap:wrap; gap:10px; }
    .cpd .pagination-info{ font-size:12.5px; color:var(--ink-500); }
    .cpd .pagination-controls{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .cpd .page-btn{ min-width:32px;height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); font-size:12.5px; font-weight:600; cursor:pointer; display:inline-flex;align-items:center;justify-content:center; }
    .cpd .page-btn:hover{ background:var(--ink-50); }
    .cpd .page-btn.active{ background:var(--green-600); border-color:var(--green-600); color:var(--white); }
    .cpd .page-btn:disabled{ opacity:.45; cursor:not-allowed; }

    /* Shadcn data table + pagination */
    .cpd .sd-data-table-wrap{ overflow-x:auto; border:1px solid #e4e4e7; border-radius:8px; background:#ffffff; }
    .cpd .sd-data-table-wrap > .db-toolbar{ margin-bottom:0; padding:12px 16px; border-bottom:1px solid #e4e4e7; }
    .cpd .sd-data-table{ width:100%; border-collapse:collapse; }
    .cpd .sd-data-table .sd-tr{ border-bottom:1px solid #e4e4e7; transition:background-color 0.15s ease; }
    .cpd .sd-data-table tbody .sd-tr:last-child{ border-bottom:none; }
    .cpd .sd-data-table tbody .sd-tr:hover{ background:#f8fafc; }
    .cpd .sd-data-table tbody .sd-tr.selected{ background:#f8fafc; }
    .cpd .sd-th{
      height:40px; padding:0 12px; text-align:left; vertical-align:middle;
      font-family:inherit; font-size:12px; font-weight:500; color:#000; white-space:nowrap;
      border-bottom:1px solid #e4e4e7; background:#fafafa;
    }
    .cpd .sd-th-action{ width:48px; }
    .cpd .sd-td{ padding:12px; vertical-align:middle; font-size:13px; color:#3f3f46; }
    .cpd .sd-td-action{ text-align:right; }
    .cpd .sd-row-actions{
      display:inline-flex; align-items:center; justify-content:center;
      width:28px; height:28px; padding:0; border:none; border-radius:6px;
      background:transparent; color:#71717a; cursor:pointer; transition:background-color 0.15s ease, color 0.15s ease;
    }
    .cpd .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    .cpd .sd-row-actions .sr-only{ position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
    .cpd .sd-pagination{ display:flex; justify-content:flex-end; }
    .cpd .sd-pagination-content{ display:flex; flex-direction:row; align-items:center; gap:4px; margin:0; padding:0; list-style:none; }
    .cpd .sd-pagination-link{
      display:inline-flex; align-items:center; justify-content:center; gap:4px;
      height:36px; padding:0 16px; border:none; border-radius:6px;
      background:transparent; color:#18181b;
      font-family:inherit; font-size:14px; font-weight:500; line-height:20px; white-space:nowrap;
      cursor:pointer; transition:background-color 0.15s ease, color 0.15s ease;
    }
    .cpd .sd-pagination-link:hover{ background:#f4f4f5; color:#18181b; }
    .cpd .sd-pagination-link:disabled{ opacity:.45; pointer-events:none; }
    .cpd .sd-pagination-link:focus-visible{ outline:2px solid #18181b; outline-offset:2px; }
    .cpd .sd-pagination-previous{ padding-left:10px; }
    .cpd .sd-pagination-next{ padding-right:10px; }

    /* Right profile */
    .cpd .profile-panel{ padding:18px; border-left:none; display:flex; flex-direction:column; overflow-y:auto; }
    .cpd .profile-panel #profileContent{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .profile-header{ display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
    .cpd .profile-avatar{ width:56px;height:56px; border-radius:50%; background:var(--green-100); color:var(--green-700); font-weight:700; font-size:18px; display:flex;align-items:center;justify-content:center; flex-shrink:0; overflow:hidden; }
    .cpd .profile-avatar img{ width:100%; height:100%; object-fit:cover; }
    .cpd .profile-name-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cpd .profile-name-row h2{ font-size:16.5px; font-weight:700; margin:0; color:var(--ink-900); font-family:'Montserrat',sans-serif; }
    .cpd .header-title h1{ font-size:22px; font-weight:700; margin:0 0 2px; letter-spacing:-.01em; color:var(--ink-900); font-family:'Montserrat',sans-serif; }
    .cpd .header-title p{ margin:0; color:var(--ink-500); font-size:13.5px; }
    .cpd .profile-id{ font-size:12px; color:#000; margin-top:2px; font-family:monospace; font-weight:700; }
    .cpd .close-x{ margin-left:auto; color:var(--ink-400); cursor:pointer; background:none;border:none; padding:4px; }
    .cpd .close-x:hover{ color:var(--ink-700); }
    .cpd .tabs{ display:flex; gap:20px; border-bottom:1px solid var(--border); margin-bottom:18px; }
    .cpd .tab{ padding:0 0 10px; font-size:13px; font-weight:600; color:var(--ink-400); cursor:pointer; border-bottom:2px solid transparent; background:none;border:none; font-family:inherit; }
    .cpd .tab.active{ color:var(--green-700); border-bottom-color:var(--green-600); }
    .cpd .info-card-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .cpd .info-card-head h3{ font-size:13.5px; font-weight:700; margin:0; color:var(--ink-900); }
    .cpd .info-rows{ display:flex; flex-direction:column; gap:14px; margin-bottom:22px; }
    .cpd .info-row{ display:flex; align-items:flex-start; gap:11px; }
    .cpd .info-row-icon{ width:36px;height:36px; border-radius:9px; color:#000; display:flex;align-items:center;justify-content:center; flex-shrink:0; }
    .cpd .info-row-label{ font-size:12px; color:var(--ink-400); margin-bottom:2px; }
    .cpd .info-row-value{ font-size:14px; font-weight:600; color:var(--ink-900); word-break:break-word; }
    .cpd .profile-empty{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .profile-empty i{ font-size:36px; margin-bottom:12px; color:var(--ink-300); display:block; }
    .cpd .profile-empty p{ margin:0; font-size:13px; }
    .cpd .profile-footer{ display:flex; align-items:center; justify-content:center; gap:10px; margin-top:auto; padding-top:16px; border-top:1px solid var(--border); }

    /* Card + report shared */
    .cpd .type-badge{ display:inline-flex; align-items:center; gap:6px; padding:0; border-radius:0; font-size:12px; font-weight:600; white-space:nowrap; color:var(--ink-700); background:none; }
    .cpd .rpt-cards{ display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
    .cpd .rpt-card{ background:var(--white); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; cursor:pointer; transition:box-shadow .2s, border-color .2s; display:flex; flex-direction:column; gap:4px; }
    .cpd .rpt-card:hover{ box-shadow:var(--shadow-md); border-color:var(--green-200); }
    .cpd .rpt-card.selected{ border-color:var(--green-600); box-shadow:0 0 0 2px rgba(4,107,37,.15); }
    .cpd .rpt-card-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
    .cpd .rpt-card-ref{ font-size:11.5px; font-weight:700; color:var(--ink-500); }
    .cpd .rpt-card-name{ font-size:14px; font-weight:700; color:var(--ink-900); }
    .cpd .rpt-card-brgy{ font-size:12px; color:var(--ink-500); }
    .cpd .rpt-card-foot{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:8px; padding-top:10px; border-top:1px solid var(--border); }
    .cpd .rpt-card-date{ font-size:11.5px; color:var(--ink-400); }
    .cpd .rpt-card-link{ color:var(--green-700); font-weight:700; font-size:11.5px; }
    .cpd .rpt-section-title{ font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-400); margin:18px 0 10px; }
    .cpd .rpt-desc-box{ background:var(--ink-50); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; font-size:13px; color:var(--ink-700); line-height:1.55; white-space:pre-wrap; }
    .cpd .rpt-history{ list-style:none; margin:0; padding:0; }
    .cpd .rpt-history li{ position:relative; padding:0 0 14px 20px; font-size:12.5px; color:var(--ink-600); }
    .cpd .rpt-history li:before{ content:''; position:absolute; left:4px; top:5px; width:8px;height:8px; border-radius:50%; background:var(--green-500); }
    .cpd .rpt-history li:last-child{ padding-bottom:0; }
    .cpd .rpt-history li .h-date{ display:block; font-size:11px; color:var(--ink-400); margin-top:2px; }
    .cpd .rpt-cards .card-img{ width:100%; height:150px; object-fit:cover; border-radius:var(--radius-sm) var(--radius-sm) 0 0; display:block; background:var(--ink-100); }
    .cpd .rpt-cards .card-img-placeholder{ width:100%; height:150px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-300); }

    /* Modal form helpers */
    .cpd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .cpd-form-grid .form-group-full{ grid-column:1 / -1; }
    .cpd .form-label{ display:block; font-size:12.5px; font-weight:600; color:var(--ink-700); margin-bottom:6px; }

    .cpd-toast{ position:fixed; bottom:24px; right:24px; z-index:10000; padding:13px 18px; border-radius:12px; color:#fff; font-size:13.5px; font-weight:600; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; transform:translateY(20px); opacity:0; pointer-events:none; transition:all .25s ease; }
    .cpd-toast.show{ transform:translateY(0); opacity:1; }
    .cpd-toast.success{ background:var(--green-700); }
    .cpd-toast.error{ background:#dc2626; }

    .cpd .no-results{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .no-results i{ font-size:34px; margin-bottom:10px; color:var(--ink-300); display:block; }
    .cpd .no-results p{ margin:0; font-size:13px; }

    /* ── Announcements-specific ── */
    .acms .post-thumb-sm{ width:96px;height:68px; border-radius:10px; overflow:hidden; flex-shrink:0; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-400); font-size:26px; border:1px solid var(--border); }
    .acms .post-thumb-sm img{ width:100%;height:100%;object-fit:cover; display:block; }
    .acms .rpt-cards .card-img, .acms .rpt-cards .card-img-placeholder{ height:180px; }
    .acms .rpt-cards .card-img-placeholder i{ font-size:40px; color:var(--ink-300); }
    .acms .client-name .fa-star{ color:var(--amber-500); margin-left:5px; font-size:11px; }
    .acms .contact-sub{ max-width:320px; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
    .acms .row-act{ width:30px;height:30px; display:inline-flex;align-items:center;justify-content:center; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-500); cursor:pointer; font-size:12.5px; }
    .acms .row-act:hover{ background:var(--ink-50); color:var(--ink-700); }
    .acms .row-act.view:hover{ background:var(--green-50); color:var(--green-700); border-color:var(--green-200); }
    .acms .row-act.edit:hover{ background:#eff6ff; color:#1d4ed8; border-color:#dbeafe; }

    .acms .status-pill{ display:inline-flex; align-items:center; gap:5px; padding:0; border-radius:0; font-size:12px; font-weight:600; white-space:nowrap; color:var(--ink-700); background:none; }
    .acms .st-dot{ display:none; }
    .acms .st-pub, .acms .st-sched, .acms .st-draft, .acms .st-arch{ background:none; color:var(--ink-700); }

    .acms .type-announcement, .acms .type-advisory{ background:none; color:var(--ink-700); }

    .acms .subj-chip{ display:inline-flex; align-items:center; gap:5px; padding:0; border-radius:20px; font-size:14px; font-weight:600; white-space:nowrap; background:transparent; color:var(--ink-700); }
    .acms .rpt-card-cat{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
    .acms .rpt-card-type{ font-size:12px; font-weight:600; color:var(--green-700); white-space:nowrap; }
    .acms .subj-animal_health{ background:transparent; color:var(--ink-700); padding:0; }
    .acms .subj-services_programs{ background:#eff6ff; color:#1d4ed8; }
    .acms .subj-events{ background:#fffbeb; color:#b45309; }
    .acms .subj-general_notices{ background:transparent; color:var(--ink-700); padding:0; }

    .acms .rpt-cards .rpt-card-brgy{ display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .acms .profile-name-row h2{ font-size:15.5px; line-height:1.3; }

    .acms .acms-preview-stage{ width:100%; aspect-ratio:16/9; max-height:270px; border-radius:12px; overflow:hidden; background:var(--ink-100); border:1px solid var(--border); display:flex;align-items:center;justify-content:center; margin-bottom:4px; }
    .acms .acms-preview-stage img{ width:100%; height:100%; object-fit:cover; display:block; }
    .acms .acms-preview-stage .acms-preview-placeholder{ width:100%; height:100%; display:flex;align-items:center;justify-content:center; color:var(--ink-300); font-size:46px; }

    .acms .toggle-row{ display:flex; align-items:center; gap:10px; background:var(--ink-50); border:1px solid var(--border); border-radius:10px; padding:11px 13px; cursor:pointer; }
    .acms .toggle-row input{ width:17px;height:17px; accent-color:var(--green-600); cursor:pointer; }
    .acms .toggle-row .t-label{ font-size:13px; font-weight:600; color:var(--ink-800); }
    .acms .toggle-row .t-sub{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .acms .barangay-picker{ max-height:150px; overflow-y:auto; border:1px solid var(--ink-200); border-radius:9px; padding:8px 10px; background:#fff; display:grid; grid-template-columns:1fr 1fr; gap:4px 10px; }
    .acms .barangay-picker label{ font-size:12px; font-weight:500; color:var(--ink-700); text-transform:none; letter-spacing:0; display:flex; align-items:center; gap:7px; padding:3px 0; cursor:pointer; }
    .acms .barangay-picker input{ width:14px;height:14px; accent-color:var(--green-600); margin:0; }

    .cpd-form-grid input[type="text"], .cpd-form-grid input[type="url"], .cpd-form-grid input[type="datetime-local"],
    .cpd-form-grid select, .cpd-form-grid textarea{ width:100%; padding:0.62rem 0.8rem; border:1.5px solid var(--ink-200); border-radius:9px; font-size:0.88rem; font-family:inherit; color:var(--ink-900); transition:border .15s, box-shadow .15s; box-sizing:border-box; background:#fff; }
    .cpd-form-grid input:focus, .cpd-form-grid select:focus, .cpd-form-grid textarea:focus{ outline:none; border-color:var(--green-600); box-shadow:0 0 0 3px rgba(4,107,37,.12); }
    .cpd-form-grid textarea{ resize:vertical; min-height:110px; line-height:1.5; }
    .cpd-form-grid select{ cursor:pointer; }

    .acms .photo-upload-area{ position:relative; border:2px dashed var(--ink-200); border-radius:10px; padding:1.4rem; text-align:center; cursor:pointer; transition:all .2s; background:var(--ink-50); }
    .acms .photo-upload-area:hover{ border-color:var(--green-600); background:var(--green-50); }
    .acms .photo-upload-area.has-image{ border-style:solid; padding:0.5rem; }
    .acms .photo-upload-area img{ max-height:140px; border-radius:6px; display:block; margin:0 auto; }
    .acms .photo-upload-area .upload-placeholder i{ font-size:1.7rem; color:var(--ink-400); display:block; margin-bottom:0.35rem; }
    .acms .photo-upload-area .upload-placeholder span{ font-size:0.8rem; color:var(--ink-400); }
    .acms .photo-upload-area input[type="file"]{ position:absolute; inset:0; opacity:0; cursor:pointer; }

    .acms-alerts{ padding:1.25rem 2rem 0; }

    @media (max-width:1280px){
      .cpd .layout{ grid-template-columns:minmax(0,1fr); }
      .cpd .db-panel{ border-right:none; }
      .cpd .profile-panel{ position:fixed; top:var(--topbar-h); right:0; bottom:0; left:auto; width:min(440px,94vw); z-index:150; background:var(--white); border-left:1px solid var(--border); box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform .3s cubic-bezier(.32,.72,.25,1); }
      .cpd .profile-panel.open{ transform:translateX(0); }
    }
    @media (max-width:1180px){
      .cpd .kpis{ grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:820px){
      .cpd .layout{ grid-template-columns:1fr; }
      .cpd .filters{ border-right:none; border-bottom:1px solid var(--border); position:static; max-height:none; }
      .cpd .db-panel{ border-right:none; border-bottom:1px solid var(--border); border-radius:0; }
      .cpd .profile-panel{ top:0; width:94vw; }
      .cpd-form-grid{ grid-template-columns:1fr; }
      .cpd .kpis{ grid-template-columns:1fr 1fr; }
      .acms-alerts{ padding:1rem 0.75rem 0; }
    }

    /* ── Dialog font + button style (shadcn) — Announcements ── */
    .sd-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
      font-family:inherit; font-size:14px; font-weight:500; line-height:1;
      white-space:nowrap; cursor:pointer;
      box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
      transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .sd-btn-default{ background:#18181b; color:#fafafa; }
    .sd-btn-default:hover{ background:#27272a; }
    .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    .sd-btn-outline:hover{ background:#f4f4f5; }

    .announcements-cms .cpd{ font-family:'Inter','Segoe UI',sans-serif; }
    /* KPI cards */
    .announcements-cms .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    .announcements-cms .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    .announcements-cms .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    /* search bar */
    .announcements-cms .search-grow-wrap input{ font-family:'Inter','Segoe UI',sans-serif; }
    .announcements-cms .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    /* toolbar filter selects → dialog outline button look */
    .announcements-cms .toolbar-filters select.select-input{ width:auto; height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .announcements-cms .toolbar-filters select.select-input:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    /* table data (dialog palette) */
    .announcements-cms .sd-th{ background:transparent; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; text-transform:none; letter-spacing:0; }
    .announcements-cms .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    .announcements-cms .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    .announcements-cms .th-sort.th-sorted{ color:#18181b; }
    .announcements-cms .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    .announcements-cms .th-sorted .th-sort-icon{ color:#18181b; }
    .announcements-cms .sd-td{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; }
    .announcements-cms .sd-data-table tbody .sd-tr:hover,
    .announcements-cms .sd-data-table tbody .sd-tr.selected{ background:#f4f4f5; }
    .announcements-cms .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .announcements-cms .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .announcements-cms .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    .announcements-cms .status-pill{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .announcements-cms .subj-chip{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    /* right panel info */
    .announcements-cms .profile-name-row h2{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    .announcements-cms .profile-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    .announcements-cms .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .announcements-cms .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-bottom:2px; }
    .announcements-cms .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .announcements-cms .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; color:#71717a; }
    .announcements-cms .profile-empty p{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    /* pagination (all-clients design) */
    .announcements-cms .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; }
    .announcements-cms .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .announcements-cms .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    .announcements-cms .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    .announcements-cms .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .announcements-cms .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    .announcements-cms .dt-page-btns{ display:flex; align-items:center; gap:4px; }
    .announcements-cms .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .announcements-cms .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
    .announcements-cms .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
    /* Announcements: full-width table + right drawer detail panel */
    .acms .layout{ grid-template-columns:minmax(0,1fr); }
    .acms .db-panel{ border-right:none; }
    #annProfileModal.modal{ position:fixed; inset:0; background:rgba(0,0,0,0.5); padding:0; z-index:999; display:flex; align-items:stretch; justify-content:flex-end; opacity:0; visibility:hidden; transition:opacity .25s ease, visibility .25s ease; }
    #annProfileModal.modal.active{ opacity:1; visibility:visible; }
    #annProfileModal .sd-dialog{ height:100%; width:100%; display:flex; justify-content:flex-end; }
    #annProfileModal .sd-dialog-content{ position:relative; width:100%; max-width:min(480px,92vw); width:min(480px,92vw); height:100%; max-height:none; overflow-y:auto; background:#ffffff; border:none; border-radius:0; padding:24px; display:flex; flex-direction:column; gap:18px; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); }
    #annProfileModal.active .sd-dialog-content{ transform:translateX(0); }
    #annProfileModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; z-index:2; }
    #annProfileModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #annProfileModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #annProfileModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #annProfileModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #annProfileModal .sd-field-group{ flex:1; min-height:0; overflow-y:auto; display:flex; flex-direction:column; gap:16px; }
    #annProfileModal .acms-preview-stage{ width:100%; aspect-ratio:16/9; max-height:270px; border-radius:12px; overflow:hidden; background:var(--ink-100); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; margin-bottom:4px; }
    #annProfileModal .acms-preview-stage img{ width:100%; height:100%; object-fit:cover; display:block; }
    #annProfileModal .acms-preview-stage .acms-preview-placeholder{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:var(--ink-300); font-size:46px; }
    #annProfileModal .status-pill, #annProfileModal .type-badge, #annProfileModal .ann-profile-date{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #annProfileModal .profile-header{ flex-shrink:0; }
    #annProfileModal .sd-dialog-footer{ flex-shrink:0; display:flex; flex-direction:row; justify-content:flex-end; gap:8px; }
    #draftModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
    #draftModal .sd-dialog-content{ position:relative; width:100%; max-width:420px; background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
    #draftModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; }
    #draftModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #draftModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #draftModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #draftModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #draftModal .sd-dialog-footer{ display:flex; flex-direction:row; justify-content:flex-end; gap:8px; margin-top:4px; }
  </style>
</head>
<body class="staff-theme" data-portal="staff">

<?php include 'includes/staff-sidebar.php'; ?>

<main class="main-content announcements-cms">

  <?php if ($error || $success): ?>
    <div class="acms-alerts">
      <?php if ($error): ?>
        <div class="auth-alert auth-alert-error" style="margin:0 0 1rem;"><i class="fas fa-exclamation-circle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="auth-alert auth-alert-success" style="margin:0 0 1rem;"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cpd acms reports-fullpage">
    <div class="cpd-app">

      <div class="layout">

        <!-- Center: Database -->
        <div class="panel db-panel">
          <div class="kpis">
            <div class="kpi-card">
              <p class="kpi-label">Total Posts</p>
              <div class="kpi-value" id="kpiTotal">0</div>
              <p class="kpi-sub">Announcements &amp; advisories</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Published</p>
              <div class="kpi-value" id="kpiPublished">0</div>
              <p class="kpi-sub">Live on the public page</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Scheduled</p>
              <div class="kpi-value" id="kpiScheduled">0</div>
              <p class="kpi-sub">Queued to publish</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Drafts</p>
              <div class="kpi-value" id="kpiDrafts">0</div>
              <p class="kpi-sub">Not yet published</p>
            </div>
          </div>

          <div class="db-table-area">
            <div id="tableWrap" class="sd-data-table-wrap">
              <div class="db-toolbar">
                <div class="search-grow-wrap">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="mainSearch" placeholder="Search by title, category, keyword..." oninput="setSearch(this.value)">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="toolbar-filters">
                    <select class="select-input tf-select" id="annType" onchange="setPost(this.value)">
                      <option value="all">All Types</option>
                      <option value="announcement">Announcements</option>
                      <option value="advisory">Advisories</option>
                    </select>
                    <select class="select-input tf-select" id="annBrgy" onchange="setBrgy(this.value)">
                      <option value="">All Barangays</option>
                      <?php foreach ($barangays as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="button" class="sd-btn sd-btn-default" onclick="openCreate('announcement')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Announcement
                  </button>
                </div>
              </div>
              <table class="sd-data-table">
                <thead id="tableHead"></thead>
                <tbody id="dataRows"></tbody>
              </table>
            </div>
            <div id="cardsWrap" class="cards-grid rpt-cards" style="display:none;"></div>
          </div>

          <div class="dt-pagination">
            <div class="dt-pagination-right">
              <div class="dt-rows">
                <span>Rows per page</span>
                <select onchange="setPerPage(this.value)">
                  <option value="10">10</option>
                  <option value="20">20</option>
                  <option value="30">30</option>
                  <option value="40">40</option>
                  <option value="50">50</option>
                </select>
              </div>
              <div class="dt-page-label" id="pageLabel">Page 1 of 1</div>
              <div class="dt-page-btns">
                <button class="dt-page-btn" id="pageFirst" title="Go to first page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                </button>
                <button class="dt-page-btn" id="pagePrev" title="Go to previous page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <button class="dt-page-btn" id="pageNext" title="Go to next page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <button class="dt-page-btn" id="pageLast" title="Go to last page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>

        </div>

      </div>
    </div>

    <!-- Row menu popup -->
    <div class="cpd row-menu-pop" id="rowMenuPop"></div>

    <!-- Announcement Details Drawer (right drawer) -->
    <div id="annProfileModal" class="modal cpd">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true">
          <button type="button" class="sd-dialog-close" onclick="closeModal('annProfileModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Announcement Details</h2>
            <p class="sd-dialog-description">Review announcement content and publishing details.</p>
          </div>
          <div class="sd-field-group">
            <div id="profileContent"></div>
          </div>
          <div class="sd-dialog-footer" id="annProfileFooter"></div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <style>
      #announcementModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
      #announcementModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
      #announcementModal .sd-dialog-content{
        position:relative; width:100%; max-width:560px;
        background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;
        padding:20px; display:flex; flex-direction:column; gap:12px;
        box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
        animation:modalIn 0.2s ease;
      }
      #announcementModal .sd-dialog-close{
        position:absolute; top:16px; right:16px; width:24px; height:24px;
        display:inline-flex; align-items:center; justify-content:center;
        border-radius:4px; border:none; background:transparent;
        color:#71717a; cursor:pointer; transition:all 0.15s ease;
      }
      #announcementModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
      #announcementModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
      #announcementModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
      #announcementModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
      #announcementModal .sd-field-group{ display:flex; flex-direction:column; gap:12px; }
      #announcementModal .sd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
      #announcementModal .sd-field-full{ grid-column:1 / -1; }
      #announcementModal .sd-field{ display:flex; flex-direction:column; gap:5px; }
      #announcementModal .sd-label{ font-size:14px; font-weight:500; line-height:1; color:#18181b; }
      #announcementModal .sd-label .req{ color:#dc2626; }
      #announcementModal .sd-input{
        width:100%; height:32px; padding:0 12px;
        font-family:inherit; font-size:14px; color:#18181b;
        background:transparent; border:1px solid #e4e4e7; border-radius:6px;
        box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
        outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease;
      }
      #announcementModal .sd-input::placeholder{ color:#a1a1aa; }
      #announcementModal .sd-input:focus-visible{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #announcementModal select.sd-input{
        appearance:none; -webkit-appearance:none;
        background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 12px center;
        padding-right:36px;
      }
      #announcementModal textarea.sd-input{ height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
      #announcementModal .sd-photo-upload{
        position:relative; display:flex; align-items:center; justify-content:center;
        min-height:70px; border:2px dashed #e4e4e7; border-radius:6px;
        background:#fafafa; cursor:pointer;
      }
      #announcementModal .sd-photo-upload.has-image{ border:none; background:transparent; padding:0; }
      #announcementModal .sd-photo-upload img{ width:100%; aspect-ratio:16/9; max-height:270px; object-fit:cover; border-radius:6px; display:block; }
      #announcementModal .sd-photo-upload .upload-placeholder i{ font-size:1.7rem; color:#a1a1aa; display:block; margin-bottom:0.35rem; }
      #announcementModal .sd-photo-upload .upload-placeholder span{ font-size:0.8rem; color:#a1a1aa; }
      #announcementModal .sd-photo-upload input[type="file"]{ position:absolute; inset:0; opacity:0; cursor:pointer; }
      #announcementModal .sd-barangay-picker{ max-height:150px; overflow-y:auto; border:1px solid #e4e4e7; border-radius:6px; padding:8px 10px; background:#fff; display:grid; grid-template-columns:1fr 1fr; gap:4px 10px; }
      #announcementModal .sd-barangay-picker label{ font-size:12px; font-weight:500; color:#18181b; display:flex; align-items:center; gap:7px; padding:3px 0; cursor:pointer; }
      #announcementModal .sd-barangay-picker input{ width:14px;height:14px; accent-color:#18181b; margin:0; }
      #announcementModal .sd-dialog-footer{ display:flex; flex-direction:column-reverse; gap:8px; }
      @media (min-width:640px){ #announcementModal .sd-dialog-footer{ flex-direction:row; justify-content:flex-end; } }
      #announcementModal .sd-btn{
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
        font-family:inherit; font-size:14px; font-weight:500; line-height:1;
        white-space:nowrap; cursor:pointer;
        box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
        transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
      }
      #announcementModal .sd-btn-default{ background:#18181b; color:#fafafa; }
      #announcementModal .sd-btn-default:hover{ background:#27272a; }
      #announcementModal .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
      #announcementModal .sd-btn-outline:hover{ background:#f4f4f5; }
      #announcementModal .sd-btn i{ font-size:12px; }
      @media (max-width:560px){ #announcementModal .sd-form-grid{ grid-template-columns:1fr; } }
    </style>
    <div id="announcementModal" class="modal">
      <form id="announcementForm" method="POST" enctype="multipart/form-data" action="staff-announcements-handler.php" class="sd-dialog">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="formId" value="0">
        <div class="sd-dialog-content" role="dialog" aria-modal="true">
          <button type="button" class="sd-dialog-close" onclick="closeModal('announcementModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title" id="modalTitle">Create Announcement</h2>
            <p class="sd-dialog-description">Fill in the announcement details. Click save when you're done.</p>
          </div>
          <div class="sd-field-group">
            <div class="sd-form-grid">
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="formTitle">Title <span class="req">*</span></label>
                <input type="text" name="title" id="formTitle" class="sd-input" required placeholder="e.g. Free Kapon Caravan">
              </div>

              <div class="sd-field">
                <label class="sd-label" for="formCategory">Content Type <span class="req">*</span></label>
                <select name="category" id="formCategory" class="sd-input">
                  <option value="announcement">Announcement</option>
                  <option value="advisory">Advisory</option>
                </select>
              </div>

              <div class="sd-field">
                <label class="sd-label" for="formSubject">Category</label>
                <select name="subject" id="formSubject" class="sd-input">
                  <option value="general_notices">General Notices</option>
                  <option value="animal_health">Animal Health</option>
                  <option value="free_rabies">Free Rabies</option>
                  <option value="services_programs">Services &amp; Programs</option>
                  <option value="events">Events</option>
                </select>
              </div>

              <div class="sd-field">
                <label class="sd-label" for="formStatus">Status</label>
                <select name="status" id="formStatus" class="sd-input" onchange="onStatusChange()">
                  <option value="draft">Draft</option>
                  <option value="scheduled">Scheduled</option>
                  <option value="published">Published</option>
                </select>
              </div>

              <div class="sd-field" id="scheduleField" style="display:none;">
                <label class="sd-label" for="formPublishAt">Publish Date &amp; Time</label>
                <input type="datetime-local" name="publish_at" id="formPublishAt" class="sd-input">
              </div>

              <div class="sd-field sd-field-full">
                <label class="sd-label" for="formAudience">Visible To</label>
                <select name="audience_type" id="formAudience" class="sd-input" onchange="onAudienceChange()">
                  <option value="all">All Residents</option>
                  <option value="barangay">Specific Barangay</option>
                  <option value="selected">Selected Barangays</option>
                </select>
                <div id="audienceBarangayField" style="display:none;margin-top:8px;">
                  <select name="barangay_id" id="formBarangayId" class="sd-input">
                    <option value="">Select barangay...</option>
                  </select>
                </div>
                <div id="audienceSelectedField" style="display:none;margin-top:8px;">
                  <div class="sd-barangay-picker" id="barangayPicker"></div>
                  <input type="hidden" name="audience_barangays" id="formAudienceBarangays">
                </div>
              </div>

              <div class="sd-field">
                <label class="sd-label" for="formLinkUrl">Link URL (Optional)</label>
                <input type="url" name="link_url" id="formLinkUrl" class="sd-input" placeholder="https://example.com">
              </div>

              <div class="sd-field sd-field-full">
                <label class="sd-label" for="formContent">Content <span class="req">*</span></label>
                <textarea name="content" id="formContent" class="sd-input" required placeholder="Enter announcement content..."></textarea>
              </div>

              <div class="sd-field sd-field-full">
                <label class="sd-label">Poster / Photo</label>
                <div class="sd-photo-upload" id="photoArea">
                  <input type="file" name="photo" id="photoInput" accept="image/*" onchange="previewImage(this, 'photoPreview', 'photoArea')">
                  <div class="upload-placeholder" id="photoPlaceholder">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Click to upload poster photo</span>
                  </div>
                  <img id="photoPreview" style="display:none;" />
                </div>
              </div>
            </div>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('announcementModal')">Cancel</button>
            <button type="submit" class="sd-btn sd-btn-default"><span id="submitBtnText">Create</span></button>
          </div>
        </div>
      </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
      <div class="modal-content">
        <div class="modal-header">
          <h2><i class="fas fa-exclamation-triangle" style="color:var(--red);"></i> Confirm Delete</h2>
          <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;">
          <p>Are you sure you want to permanently delete this item? This action cannot be undone.</p>
        </div>
        <div class="modal-actions" style="justify-content:center;">
          <button class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
          <button class="btn-danger" id="confirmDeleteBtn" onclick="executeDelete()"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </div>
    </div>

    <!-- Move to Draft Modal -->
    <div id="draftModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="closeModal('draftModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Move to Draft</h2>
            <p class="sd-dialog-description">Set this post to draft? It will be hidden from the public page until published again.</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('draftModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmDraftBtn" onclick="executeDraft()"><i class="fas fa-file"></i> Move to Draft</button>
          </div>
        </div>
      </div>
    </div>

    <div class="cpd-toast success" id="cpdToast"></div>
  </div>
</main>

<script>
/* ───────────────────────── Data ───────────────────────── */
const AMS_DATA = <?php echo json_encode($announcements); ?>;
const BARANGAYS = <?php echo json_encode($barangays); ?>;

const SUBJECTS = {
  animal_health: { label: 'Animal Health', icon: 'fa-heart-pulse' },
  services_programs: { label: 'Services & Programs', icon: 'fa-handshake' },
  events: { label: 'Events', icon: 'fa-calendar-day' },
  general_notices: { label: 'General Notices', icon: 'fa-bullhorn' },
  free_rabies: { label: 'Free Rabies', icon: 'fa-syringe' }
};
const STATUS_META = {
  published: { label: 'Published', cls: 'st-pub' },
  scheduled: { label: 'Scheduled', cls: 'st-sched' },
  draft: { label: 'Draft', cls: 'st-draft' },
  archived: { label: 'Archived', cls: 'st-arch' }
};
const TYPE_META = {
  announcement: { label: 'Announcement', cls: 'type-announcement' },
  advisory: { label: 'Advisory', cls: 'type-advisory' }
};

let state = {
  post: 'all',
  status: 'all',
  cat: 'all',
  q: '',
  brgy: '',
  sort: 'newest',
  view: 'table',
  page: 1,
  per: 10,
  selected: 0,
  sortCol: '',
  sortDir: ''
};

const DATA = AMS_DATA.map(function(a){
  return {
    id: parseInt(a.announcement_id, 10),
    title: a.title,
    content: a.content,
    category: a.category,
    subject: a.subject || 'general_notices',
    status: a.status || 'draft',
    photo_url: a.photo_url || '',
    link_url: a.link_url || '',
    barangay_id: a.barangay_id || null,
    audience_type: a.audience_type || 'all',
    audience_barangays: a.audience_barangays ? String(a.audience_barangays) : '',
    is_featured: parseInt(a.is_featured || 0, 10) === 1,
    creator: a.creator_name || '—',
    created_at: a.created_at || '',
    updated_at: a.updated_at || '',
    publish_at: a.publish_at || '',
    archived_at: a.archived_at || ''
  };
});

/* ───────────────────────── Helpers ───────────────────────── */
function fmtDate(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
}
function fmtDateTime(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}
function subjectLabel(s){ return SUBJECTS[s] ? SUBJECTS[s].label : s; }
function statusMeta(s){ return STATUS_META[s] || { label: s, cls: 'st-draft' }; }
function typeMeta(c){ return TYPE_META[c] || { label: c, cls: 'type-announcement' }; }
function audienceLabel(a){
  if(a.audience_type === 'all') return 'All Residents';
  if(a.audience_type === 'barangay'){
    const b = BARANGAYS.find(x => String(x.id) === String(a.barangay_id));
    return b ? b.name : 'Specific Barangay';
  }
  if(a.audience_type === 'selected'){
    const ids = String(a.audience_barangays).split(',').map(Number).filter(Boolean);
    if(ids.length === 0) return 'Selected Barangays';
    if(ids.length <= 3){
      const names = ids.map(id => { const b = BARANGAYS.find(x => String(x.id) === String(id)); return b ? b.name : id; });
      return names.join(', ');
    }
    return ids.length + ' barangays';
  }
  return 'All Residents';
}
function esc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
  });
}
function infoRow(icon, label, value){
  const icons = { bullhorn:'fa-bullhorn', tag:'fa-tag', check:'fa-check-circle', users:'fa-users', user:'fa-user', cal:'fa-calendar-alt', clock:'fa-clock', archive:'fa-archive', link:'fa-link', pen:'fa-pen' };
  return '<div class="info-row"><div class="info-row-icon"><i class="fas ' + (icons[icon] || 'fa-info') + '"></i></div><div><p class="info-row-label">' + label + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
}

/* ───────────────────────── Filtering / sort ───────────────────────── */
function applyFilters(){
  let items = DATA.slice();
  if(state.post !== 'all') items = items.filter(a => a.category === state.post);
  if(state.status !== 'all') items = items.filter(a => a.status === state.status);
  if(state.cat !== 'all') items = items.filter(a => a.subject === state.cat);
  if(state.brgy){
    const bid = String(state.brgy);
    items = items.filter(a => {
      if(a.audience_type === 'all') return true;
      if(a.audience_type === 'barangay') return String(a.barangay_id) === bid;
      if(a.audience_type === 'selected'){
        const ids = String(a.audience_barangays).split(',').map(s => s.trim()).filter(Boolean);
        return ids.indexOf(bid) !== -1;
      }
      return false;
    });
  }
  if(state.q){
    const q = state.q.toLowerCase();
    items = items.filter(a =>
      a.title.toLowerCase().includes(q) ||
      a.content.toLowerCase().includes(q) ||
      subjectLabel(a.subject).toLowerCase().includes(q) ||
      TYPE_META[a.category].label.toLowerCase().includes(q)
    );
  }
  const s = state.sort;
  items.sort(function(a,b){
    if(s === 'newest') return new Date(b.created_at) - new Date(a.created_at);
    if(s === 'oldest') return new Date(a.created_at) - new Date(b.created_at);
    if(s === 'recent') return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
    if(s === 'schedule') return (new Date(a.publish_at)||0) - (new Date(b.publish_at)||0);
    if(s === 'alpha') return a.title.localeCompare(b.title);
    return 0;
  });
  if(state.sortCol){
    const dir = state.sortDir === 'desc' ? -1 : 1;
    const col = state.sortCol;
    items.sort(function(a,b){
      const va = annColVal(a,col), vb = annColVal(b,col);
      if(typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
      return va < vb ? -1 * dir : (va > vb ? 1 * dir : 0);
    });
  }
  return items;
}

function renderCounts(){
  document.getElementById('kpiTotal').textContent = DATA.length;
  document.getElementById('kpiPublished').textContent = DATA.filter(a => a.status === 'published').length;
  document.getElementById('kpiScheduled').textContent = DATA.filter(a => a.status === 'scheduled').length;
  document.getElementById('kpiDrafts').textContent = DATA.filter(a => a.status === 'draft').length;
  setCount('cnt-post-all', DATA.length);
  setCount('cnt-post-announcement', DATA.filter(a => a.category === 'announcement').length);
  setCount('cnt-post-advisory', DATA.filter(a => a.category === 'advisory').length);
  setCount('cnt-st-published', DATA.filter(a => a.status === 'published').length);
  setCount('cnt-st-scheduled', DATA.filter(a => a.status === 'scheduled').length);
  setCount('cnt-st-draft', DATA.filter(a => a.status === 'draft').length);
  setCount('cnt-st-archived', DATA.filter(a => a.status === 'archived').length);
  setCount('cnt-trash', DATA.filter(a => a.status === 'archived').length);
  setCount('cnt-cat-all', DATA.length);
  Object.keys(SUBJECTS).forEach(function(s){
    setCount('cnt-cat-' + s, DATA.filter(a => a.subject === s).length);
  });
}
function setCount(id, n){ const el = document.getElementById(id); if(el) el.textContent = n; }

function annColVal(a, col){
  if(col === 'created') return new Date(a.created_at).getTime() || 0;
  if(col === 'title') return String(a.title||'').toLowerCase();
  if(col === 'type') return String(typeMeta(a.category).label||'').toLowerCase();
  if(col === 'category') return String(subjectLabel(a.subject) || a.category || '').toLowerCase();
  if(col === 'status') return String(a.status||'').toLowerCase();
  return String(a[col] != null ? a[col] : '').toLowerCase();
}
function annSortHead(col, label){
  const active = state.sortCol === col;
  let icon;
  if(active && state.sortDir === 'asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
  else if(active && state.sortDir === 'desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
  else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
  return '<button type="button" class="th-sort' + (active?' th-sorted':'') + '" onclick="annSortBy(\'' + col + '\')">' + label + '<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg></button>';
}
function renderTableHead(){
  document.getElementById('tableHead').innerHTML = '<tr class="sd-tr"><th class="sd-th">Photo</th><th class="sd-th">' + annSortHead('title','Title') + '</th><th class="sd-th">Content</th><th class="sd-th">' + annSortHead('type','Type') + '</th><th class="sd-th">' + annSortHead('category','Category') + '</th><th class="sd-th">' + annSortHead('status','Status') + '</th><th class="sd-th">' + annSortHead('created','Created') + '</th><th class="sd-th sd-th-action"></th></tr>';
}
function annSortBy(col){
  if(state.sortCol === col){ state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
  else { state.sortCol = col; state.sortDir = 'asc'; }
  state.page = 1;
  render();
}

function render(){
  const items = applyFilters();
  const total = items.length;
  const pages = Math.max(1, Math.ceil(total / state.per));
  if(state.page > pages) state.page = pages;
  if(state.page < 1) state.page = 1;
  const start = (state.page - 1) * state.per;
  const slice = items.slice(start, start + state.per);

  renderTableHead();
  const tbody = document.getElementById('dataRows');
  const cards = document.getElementById('cardsWrap');

  if(total === 0){
    tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="6"><div class="no-results"><i class="fas fa-inbox"></i><p>No posts found.</p></div></td></tr>';
    cards.innerHTML = '';
  } else {
    tbody.innerHTML = slice.map(rowHtml).join('');
    cards.innerHTML = slice.map(cardHtml).join('');
  }

  document.getElementById('tableWrap').style.display = state.view === 'table' ? '' : 'none';
  cards.style.display = state.view === 'cards' ? '' : 'none';

  renderPagination(pages);
}

function rowHtml(a){
  const sm = statusMeta(a.status);
  const tm = typeMeta(a.category);
  const subj = SUBJECTS[a.subject] || { label: a.subject, icon: 'fa-tag' };
  const thumb = a.photo_url
    ? '<img src="' + esc(a.photo_url) + '" alt="">'
    : '<i class="fas fa-bullhorn"></i>';
  const featured = a.is_featured ? '<i class="fas fa-star"></i>' : '';
  const dateText = a.status === 'scheduled' ? (a.publish_at ? fmtDateTime(a.publish_at) : 'Scheduled') : fmtDate(a.created_at);
  return '<tr class="sd-tr" data-id="' + a.id + '" onclick="selectPost(' + a.id + ')" style="cursor:pointer;">' +
    '<td class="sd-td"><div class="post-thumb-sm">' + thumb + '</div></td>' +
    '<td class="sd-td"><div class="client-name">' + esc(a.title) + featured + '</div></td>' +
    '<td class="sd-td"><div class="contact-sub">' + esc(a.content) + '</div></td>' +
    '<td class="sd-td"><span class="subj-chip">' + tm.label + '</span></td>' +
    '<td class="sd-td"><span class="subj-chip subj-' + a.subject + '">' + subjectLabel(a.subject) + '</span></td>' +
    '<td class="sd-td"><span class="status-pill ' + sm.cls + '"><span class="st-dot"></span>' + sm.label + '</span></td>' +
    '<td class="sd-td"><div class="contact-main">' + dateText + '</div></td>' +
    '<td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();openRowMenu(event,' + a.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>' +
  '</tr>';
}

function cardHtml(a){
  const sm = statusMeta(a.status);
  const tm = typeMeta(a.category);
  const subj = SUBJECTS[a.subject] || { label: a.subject, icon: 'fa-tag' };
  const featured = a.is_featured ? '<span class="status-pill" style="background:var(--amber-100);color:var(--amber-700);"><i class="fas fa-star"></i> Featured</span>' : '';
  const img = a.photo_url
    ? '<img class="card-img" src="' + esc(a.photo_url) + '" alt="">'
    : '<div class="card-img-placeholder"><i class="fas fa-bullhorn" style="font-size:32px;"></i></div>';
  const dateText = a.status === 'scheduled' ? (a.publish_at ? fmtDateTime(a.publish_at) : 'Scheduled') : fmtDate(a.created_at);
  return '<div class="rpt-card" data-id="' + a.id + '" onclick="selectPost(' + a.id + ')">' +
    img +
    '<div class="rpt-card-head"><span class="rpt-card-ref">#' + a.id + '</span>' + featured + '</div>' +
    '<div class="rpt-card-name">' + esc(a.title) + '</div>' +
    '<div class="rpt-card-cat"><span class="subj-chip subj-' + a.subject + '">' + (subj.icon ? '<i class="fas ' + subj.icon + '"></i>' : '') + subjectLabel(a.subject) + '</span><span class="rpt-card-type">' + tm.label + '</span></div>' +
    '<div class="rpt-card-brgy">' + esc(a.content) + '</div>' +
    '<div class="rpt-card-foot"><span class="rpt-card-date">' + dateText + '</span></div>' +
  '</div>';
}

function renderPagination(pages){
  const prev = document.getElementById('pagePrev');
  const next = document.getElementById('pageNext');
  const first = document.getElementById('pageFirst');
  const last = document.getElementById('pageLast');
  const label = document.getElementById('pageLabel');
  if(prev) prev.disabled = state.page <= 1;
  if(next) next.disabled = state.page >= pages;
  if(first) first.disabled = state.page <= 1;
  if(last) last.disabled = state.page >= pages;
  if(label) label.textContent = 'Page ' + state.page + ' of ' + pages;
  if(first) first.onclick = function(){ state.page = 1; render(); };
  if(prev) prev.onclick = function(){ state.page = Math.max(1, state.page - 1); render(); };
  if(next) next.onclick = function(){ state.page = Math.min(pages, state.page + 1); render(); };
  if(last) last.onclick = function(){ state.page = pages; render(); };
}
function setPerPage(v){
  state.per = parseInt(v, 10);
  state.page = 1;
  render();
}
function goPage(p){
  if(p === 'prev') p = state.page - 1;
  if(p === 'next') p = state.page + 1;
  if(p < 1) return;
  state.page = p;
  render();
}

/* ───────────────────────── State setters ───────────────────────── */
function setPost(v){
  state.post = v; state.status = 'all'; state.page = 1;
  document.querySelectorAll('[data-rail="post"]').forEach(el => el.classList.toggle('active', el.dataset.val === v));
  const rt = document.getElementById('annType');
  if(rt && rt.value !== v) rt.value = v;
  render();
}
function setStatus(v){
  state.status = v; state.post = 'all'; state.page = 1;
  document.querySelectorAll('[data-rail="status"],[data-rail="trash"]').forEach(el => el.classList.toggle('active', el.dataset.val === v));
  const rt = document.getElementById('annType');
  if(rt && rt.value !== 'all') rt.value = 'all';
  render();
}
function setCat(v){
  state.cat = v; state.page = 1;
  document.querySelectorAll('[data-rail="cat"]').forEach(el => el.classList.toggle('active', el.dataset.val === v));
  render();
}
function setSearch(v){ state.q = v; state.page = 1; render(); }
function setBrgy(v){ state.brgy = v; state.page = 1; render(); }
function setSort(v){ state.sort = v; state.page = 1; render(); }
function setView(v){
  state.view = v; state.page = 1;
  document.querySelectorAll('#viewSeg .seg-btn').forEach(b => b.classList.toggle('active', b.dataset.view === v));
  render();
}
function clearFilters(){
  state.post = 'all'; state.status = 'all'; state.cat = 'all'; state.q = ''; state.brgy = ''; state.page = 1;
  const ms = document.getElementById('mainSearch');
  const rs = document.getElementById('railSearch');
  if(ms) ms.value = '';
  if(rs) rs.value = '';
  const rt = document.getElementById('annType');
  if(rt) rt.value = 'all';
  const ab = document.getElementById('annBrgy');
  if(ab) ab.value = '';
  document.querySelectorAll('[data-rail="post"],[data-rail="status"],[data-rail="trash"],[data-rail="cat"]').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('[data-rail="post"][data-val="all"],[data-rail="status"][data-val="all"],[data-rail="cat"][data-val="all"]').forEach(el => el.classList.add('active'));
  render();
}

/* ───────────────────────── Row menu ───────────────────────── */
function openRowMenu(ev, id){
  ev.stopPropagation();
  const a = DATA.find(x => x.id === id);
  const pop = document.getElementById('rowMenuPop');
  let items = '';
  items += '<button class="row-menu-item" onclick="selectPost(' + id + ')"><i class="fas fa-eye"></i>View Post</button>';
  items += '<button class="row-menu-item" onclick="openEdit(' + id + ')"><i class="fas fa-pen"></i>Edit Post</button>';
  if(a.status !== 'published') items += '<button class="row-menu-item" onclick="setPostStatus(' + id + ',\'published\')"><i class="fas fa-check-circle"></i>Publish Now</button>';
  if(a.status !== 'archived') items += '<button class="row-menu-item" onclick="setPostStatus(' + id + ',\'archived\')"><i class="fas fa-archive"></i>Move to Trash</button>';
  else items += '<button class="row-menu-item" onclick="setPostStatus(' + id + ',\'draft\')"><i class="fas fa-undo"></i>Restore from Trash</button>';
  items += '<button class="row-menu-item" onclick="duplicatePost(' + id + ')"><i class="fas fa-copy"></i>Duplicate</button>';
  items += '<button class="row-menu-item danger" onclick="confirmDelete(' + id + ')"><i class="fas fa-trash"></i>Delete Permanently</button>';
  pop.innerHTML = items;
  const r = ev.currentTarget.getBoundingClientRect();
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
  pop.classList.add('open');
  document.addEventListener('click', closeRowMenuOnce);
}
function closeRowMenuOnce(e){
  if(e.target.closest && e.target.closest('.row-menu-pop')) return;
  document.getElementById('rowMenuPop').classList.remove('open');
  document.removeEventListener('click', closeRowMenuOnce);
}

/* ───────────────────────── Selection & details panel ───────────────────────── */
function selectPost(id){
  state.selected = id;
  document.querySelectorAll('#dataRows tr').forEach(r => r.classList.toggle('selected', r.dataset.id == id));
  document.querySelectorAll('#cardsWrap .rpt-card').forEach(r => r.classList.toggle('selected', r.dataset.id == id));
  openModal('annProfileModal');
  renderDetail();
}
function closeProfile(){
  state.selected = 0;
  closeModal('annProfileModal');
  document.getElementById('profileContent').innerHTML =
    '<div class="profile-empty"><i class="fas fa-bullhorn"></i><p>Select a post to view its details</p></div>';
}
function renderDetail(){
  const a = DATA.find(x => x.id === state.selected);
  const box = document.getElementById('profileContent');
  if(!a){ closeProfile(); return; }
  const sm = statusMeta(a.status);
  const tm = typeMeta(a.category);
  const subj = SUBJECTS[a.subject] || { label: a.subject, icon: 'fa-tag' };
  const pubDate = a.publish_at ? fmtDateTime(a.publish_at) : fmtDate(a.created_at);
  box.innerHTML =
    '<div class="profile-header">' +
      '<div style="min-width:0;">' +
        '<div class="profile-name-row"><h2>' + esc(a.title) + '</h2></div>' +
        '<div style="display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap;">' +
          '<span class="type-badge ' + tm.cls + '">' + tm.label + '</span>' +
          '<span class="status-pill ' + sm.cls + '"><span class="st-dot"></span>' + sm.label + '</span>' +
          (a.is_featured ? '<span class="status-pill" style="background:var(--amber-100);color:var(--amber-700);"><i class="fas fa-star"></i> Featured</span>' : '') +
          '<span class="ann-profile-date">' + pubDate + '</span>' +
        '</div>' +
      '</div>' +
      '<button class="close-x" onclick="closeProfile()"><i class="fas fa-times"></i></button>' +
    '</div>' +
    '<div class="rpt-section-title" style="margin-top:6px;">Preview</div>' +
    '<div class="acms-preview-stage">' + (a.photo_url ? '<img src="' + esc(a.photo_url) + '" alt="">' : '<div class="acms-preview-placeholder"><i class="fas fa-bullhorn"></i></div>') + '</div>' +
    '<div class="rpt-section-title">Content</div>' +
    '<div style="white-space:pre-line;font-size:13px;color:var(--ink-700);line-height:1.55;">' + esc(a.content) + '</div>' +
    '<div class="rpt-section-title">Details</div>' +
    '<div class="info-rows">' +
      infoRow('tag', 'Type', tm.label) +
      infoRow('tag2', 'Category', subjectLabel(a.subject)) +
      infoRow('users', 'Visible to', audienceLabel(a)) +
      infoRow('pen', 'Last Updated', a.updated_at ? fmtDate(a.updated_at) : '—') +
    '</div>';
  var footer = document.getElementById('annProfileFooter');
  if (footer) {
    footer.innerHTML =
      '<button type="button" class="sd-btn sd-btn-default" onclick="openEdit(' + a.id + ')">Edit ' + (a.category === 'advisory' ? 'Advisory' : 'Announcement') + '</button>' +
      (a.status !== 'draft'
      ? '<button type="button" class="sd-btn sd-btn-outline" onclick="confirmDraft(' + a.id + ')"><i class="fas fa-file"></i> Draft</button>'
      : '<button type="button" class="sd-btn sd-btn-outline" onclick="setPostStatus(' + a.id + ',\'published\',\'Publish Now\')"><i class="fas fa-check-circle"></i> Publish</button>') +
      '<button type="button" class="sd-btn sd-btn-outline" onclick="window.open(\'announcements.php\',\'_blank\')"><i class="fas fa-external-link-alt"></i> View on Public Page</button>';
  }
}

/* ───────────────────────── Create / edit modal ───────────────────────── */
function openCreate(category){
  document.getElementById('modalTitle').textContent = category === 'advisory' ? 'Create Advisory' : 'Create Announcement';
  document.getElementById('submitBtnText').textContent = 'Create';
  document.getElementById('formId').value = '0';
  document.getElementById('announcementForm').reset();
  document.getElementById('formCategory').value = category;
  document.getElementById('formStatus').value = 'draft';
  document.getElementById('photoPreview').style.display = 'none';
  document.getElementById('photoPlaceholder').style.display = '';
  document.getElementById('photoArea').classList.remove('has-image');
  onStatusChange(); onAudienceChange();
  openModal('announcementModal');
}
function openEdit(id){
  fetch('staff-announcements-handler.php?action=get&id=' + id)
    .then(res => res.json())
    .then(data => {
      if(!data.success){ alert('Failed to load data: ' + data.message); return; }
      const a = data.data;
      const isAdvisory = a.category === 'advisory';
      document.getElementById('modalTitle').textContent = 'Edit ' + (isAdvisory ? 'Advisory' : 'Announcement');
      document.getElementById('submitBtnText').textContent = 'Update';
      document.getElementById('formId').value = a.announcement_id;
      document.getElementById('formTitle').value = a.title;
      document.getElementById('formCategory').value = a.category;
      document.getElementById('formSubject').value = a.subject || 'general_notices';
      document.getElementById('formStatus').value = a.status === 'archived' ? 'draft' : a.status;
      document.getElementById('formPublishAt').value = a.publish_at ? toLocalInput(a.publish_at) : '';
      document.getElementById('formLinkUrl').value = a.link_url || '';
      document.getElementById('formContent').value = a.content;
      document.getElementById('formAudience').value = a.audience_type || 'all';
      document.getElementById('formAudienceBarangays').value = a.audience_barangays || '';
      if(a.audience_type === 'barangay'){
        document.getElementById('formBarangayId').value = a.barangay_id || '';
      }
      onStatusChange(); onAudienceChange();
      const preview = document.getElementById('photoPreview');
      if(a.photo_url){
        preview.src = a.photo_url;
        preview.style.display = 'block';
        document.getElementById('photoPlaceholder').style.display = 'none';
        document.getElementById('photoArea').classList.add('has-image');
      }
      openModal('announcementModal');
    })
    .catch(() => alert('Network error loading data'));
}
function toLocalInput(iso){
  const d = new Date(iso);
  if(isNaN(d)) return '';
  const pad = n => String(n).padStart(2,'0');
  return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}
function onStatusChange(){
  document.getElementById('scheduleField').style.display = document.getElementById('formStatus').value === 'scheduled' ? 'block' : 'none';
}
function onAudienceChange(){
  const v = document.getElementById('formAudience').value;
  document.getElementById('audienceBarangayField').style.display = v === 'barangay' ? 'block' : 'none';
  document.getElementById('audienceSelectedField').style.display = v === 'selected' ? 'block' : 'none';
}

/* ───────────────────────── Form submit ───────────────────────── */
document.getElementById('announcementForm').addEventListener('submit', function(e){
  e.preventDefault();
  const fd = new FormData(this);
  const sel = document.querySelectorAll('#barangayPicker input:checked');
  const selectedIds = Array.from(sel).map(el => el.value);
  if(document.getElementById('formAudience').value === 'selected'){
    fd.set('audience_barangays', selectedIds.join(','));
  }
  const btn = this.querySelector('button[type="submit"]');
  const orig = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  btn.disabled = true;
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(data.success){ closeModal('announcementModal'); location.reload(); }
      else alert('Error: ' + data.message);
    })
    .catch(() => alert('Network error'))
    .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
});

/* ───────────────────────── Image preview ───────────────────────── */
function previewImage(input, previewId, areaId){
  const preview = document.getElementById(previewId);
  const area = document.getElementById(areaId);
  const placeholder = area.querySelector('.upload-placeholder');
  if(input.files && input.files[0]){
    const reader = new FileReader();
    reader.onload = function(e){
      preview.src = e.target.result;
      preview.style.display = 'block';
      if(placeholder) placeholder.style.display = 'none';
      area.classList.add('has-image');
    };
    reader.readAsDataURL(input.files[0]);
  }
}

/* ───────────────────────── Delete flow ───────────────────────── */
let deleteId = 0;
function confirmDelete(id){ deleteId = id; openModal('deleteModal'); }
function executeDelete(){
  if(!deleteId) return;
  const btn = document.getElementById('confirmDeleteBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', deleteId);
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(data.success){ closeModal('deleteModal'); location.reload(); }
      else { alert('Error: ' + data.message); btn.innerHTML = '<i class="fas fa-trash"></i> Delete'; btn.disabled = false; }
    })
    .catch(() => { alert('Network error'); btn.innerHTML = '<i class="fas fa-trash"></i> Delete'; btn.disabled = false; });
}

/* ───────────────────────── Status / duplicate actions ───────────────────────── */
function togglePublish(id){
  const a = DATA.find(x => x.id === id);
  if(!a) return;
  const next = a.status === 'published' ? 'draft' : 'published';
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', id);
  fd.append('status', next);
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => { if(data.success) location.reload(); else alert('Error: ' + data.message); })
    .catch(() => alert('Network error'));
}
function setPostStatus(id, status, label){
  const labels = { published:'Publish Now', archived:'Move to Trash', draft:'Set to Draft', scheduled:'Schedule' };
  if(!confirm('Set this post to "' + (label || labels[status]) + '"?')) return;
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', id);
  fd.append('status', status);
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => { if(data.success) location.reload(); else alert('Error: ' + data.message); })
    .catch(() => alert('Network error'));
}
let draftId = 0;
function confirmDraft(id){
  draftId = id;
  openModal('draftModal');
}
function executeDraft(){
  if(!draftId) return;
  const btn = document.getElementById('confirmDraftBtn');
  if(btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', draftId);
  fd.append('status', 'draft');
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(btn) btn.disabled = false;
      if(data.success){ closeModal('draftModal'); location.reload(); }
      else alert('Error: ' + data.message);
    })
    .catch(() => { if(btn) btn.disabled = false; alert('Network error'); });
}
function duplicatePost(id){
  const fd = new FormData();
  fd.append('action', 'duplicate');
  fd.append('id', id);
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => { if(data.success) location.reload(); else alert('Error: ' + data.message); })
    .catch(() => alert('Network error'));
}

/* ───────────────────────── Init ───────────────────────── */
document.getElementById('formBarangayId').innerHTML = '<option value="">Select barangay...</option>' + BARANGAYS.map(b => '<option value="' + b.id + '">' + esc(b.name) + '</option>').join('');
document.getElementById('barangayPicker').innerHTML = BARANGAYS.map(b =>
  '<label><input type="checkbox" value="' + b.id + '" class="brgy-check">' + esc(b.name) + '</label>'
).join('');
renderCounts();
render();
</script>

</body>
</html>