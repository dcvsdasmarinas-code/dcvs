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

require_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$pageTitle = 'Announcements';
$activePage = 'announcements';

$announcements = [];
$autoPublish = $conn->prepare("UPDATE announcements SET status = 'published', updated_at = NOW() WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW() AND archived_at IS NULL");
if ($autoPublish) {
    $autoPublish->execute();
    $autoPublish->close();
}
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

$createFromProgram = array_key_exists('create_from_program', $_GET);
$programAnnouncementPrefill = null;
$programIdParam = $_GET['create_from_program'] ?? '';
$programId = is_string($programIdParam) || is_int($programIdParam)
    ? filter_var($programIdParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($createFromProgram && $programId !== false) {
    $programStmt = $conn->prepare("SELECT p.program_name, p.location_mode, p.venue, GROUP_CONCAT(DISTINCT b.barangay_name ORDER BY pb.barangay_id SEPARATOR ', ') AS barangay_names FROM programs p LEFT JOIN program_barangays pb ON pb.program_id = p.program_id LEFT JOIN barangays b ON b.barangay_id = pb.barangay_id WHERE p.program_id = ? GROUP BY p.program_id");
    if ($programStmt) {
        $programId = (int)$programId;
        $programStmt->bind_param('i', $programId);
        if ($programStmt->execute()) {
            $programRow = $programStmt->get_result()->fetch_assoc();
            if ($programRow) {
                $barangayNames = trim((string)$programRow['barangay_names']);
                if ($programRow['location_mode'] === 'multiple') {
                    $programLocation = $barangayNames !== '' ? $barangayNames : (string)$programRow['venue'];
                } else {
                    $programLocation = (string)$programRow['venue'];
                }
                $programAnnouncementPrefill = [
                    'name' => $programRow['program_name'],
                    'location' => $programLocation,
                ];
            }
        } else {
            error_log('Unable to load program for announcement prefill: ' . $programStmt->error);
        }
        $programStmt->close();
    } else {
        error_log('Unable to prepare program announcement prefill query: ' . $conn->error);
    }
}
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
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Inter',sans-serif; }

    .cpd .btn{ display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:13.5px; font-weight:600; border-radius:10px; padding:10px 16px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); cursor:pointer; transition:background .15s ease, box-shadow .15s ease, border-color .15s ease; white-space:nowrap; }
    .cpd .btn:hover{ background:var(--ink-50); border-color:var(--ink-300); }
    .cpd .btn-primary{ background:var(--green-600); border-color:var(--green-600); color:var(--white); box-shadow:var(--shadow-sm); }
    .cpd .btn-primary:hover{ background:var(--green-700); border-color:var(--green-700); }

    /* KPI cards */
    .cpd .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    .cpd .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); }
    .cpd .kpi-card:hover{ border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }
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
    .cpd .db-table-area #tableWrap{ flex:1; min-height:var(--staff-table-min-height); overflow-x:auto; }
    .cpd .db-panel .pagination{ flex-shrink:0; margin-top:auto; }
    .cpd .db-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .cpd .view-controls{ display:flex; align-items:center; gap:4px; background:var(--ink-100); padding:3px; border-radius:9px; }
    .cpd .view-controls .seg-btn{ border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--ink-500); padding:6px 12px; border-radius:7px; cursor:pointer; }
    .cpd .view-controls .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }
    .cpd .toolbar-main-controls{ display:flex; align-items:center; gap:8px; flex:1 1 auto; min-width:0; }
    .cpd .search-grow-wrap{ position:relative; flex:0 0 250px; width:250px; min-width:0; }
    .cpd .search-grow-wrap input{ width:100%; height:36px; padding:0 12px 0 38px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; outline:none; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .cpd .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .cpd .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    .cpd .search-grow-wrap svg{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#71717a; width:15px; height:15px; }
    .cpd .sort-select{ width:auto; min-width:150px; max-width:180px; }
    .cpd .toolbar-filters{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cpd .tf-label{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--ink-600); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
    .cpd .tf-select{ width:auto; min-width:150px; height:36px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; padding:0 26px 0 10px; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; }
    .cpd .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .cpd .tf-clear{ width:auto; padding:10px 12px; }

    .cpd table{ width:100%; border-collapse:collapse; }
    .cpd thead th{ text-align:left; font-size:11.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#000; padding:10px 12px; background:var(--ink-100); white-space:nowrap; border-bottom:10px solid transparent; }
    .cpd tbody tr{ cursor:pointer; transition:background .12s ease; border-bottom:1px solid var(--ink-100); }
    .cpd tbody tr:hover{ background:var(--ink-50); }
    .cpd tbody tr.selected{ background:var(--green-50); }
    .cpd tbody td{ padding:14px 12px; vertical-align:middle; font-size:13.5px; color:var(--ink-700); }
    .cpd .client-name{ font-weight:500; color:var(--ink-900); font-size:13.5px; }
    .cpd .contact-main{ color:var(--ink-700); font-weight:400; }
    .cpd .contact-sub{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .status-pill{ display:inline-flex; align-items:center; padding:0; border:none; border-radius:0; background:none; box-shadow:none; font-family:'Inter','Segoe UI',sans-serif; font-size:13.5px; font-weight:600; white-space:nowrap; line-height:1.5; letter-spacing:0; }
    .cpd .status-active{ background:none; color:#18181b; border:none; }
    .cpd .status-inactive{ background:none; color:#18181b; border:none; }
    .cpd .st-pub{ background:none; color:#18181b; }
    .cpd .st-sched{ background:none; color:#18181b; }
    .cpd .st-draft{ background:none; color:#18181b; }
    .cpd .st-arch{ background:none; color:#18181b; }
    .cpd .status-stack{ display:flex; flex-direction:column; align-items:flex-start; gap:4px; font-family:'Inter','Segoe UI',sans-serif; font-size:13.5px; font-weight:600; line-height:1.5; color:#18181b; }
    .cpd .status-date{ display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:12.5px; font-weight:500; line-height:1.5; color:#71717a; }
    .cpd .row-menu{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .row-menu:hover{ background:var(--ink-100); color:var(--ink-700); }

    /* Row menu dropdown */
    .cpd .row-menu-pop{ position:fixed!important; z-index:9999!important; background:#ffffff!important; border:1px solid #e6e9ee!important; border-radius:10px!important; box-shadow:0 4px 14px rgba(15,23,32,.06)!important; padding:6px!important; min-width:190px!important; display:none; }
    .cpd .row-menu-pop.open{ display:block!important; }
    .cpd .row-menu-item{ display:flex!important; align-items:center!important; gap:10px!important; width:100%!important; padding:9px 10px!important; border:none!important; background:none!important; border-radius:8px!important; font-family:'Inter','Segoe UI',system-ui,sans-serif!important; font-size:13px!important; font-weight:500!important; color:#334155!important; cursor:pointer!important; text-align:left!important; line-height:1.4!important; }
    .cpd .row-menu-item:hover{ background:#f8fafc!important; }
    .cpd .row-menu-item.danger{ color:#dc2626!important; }
    .cpd .row-menu-item.danger:hover{ background:#fef2f2!important; }
    .cpd .row-menu-item i,.cpd .row-menu-item svg{ flex-shrink:0!important; width:15px!important; height:15px!important; }

    .cpd .cards-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }

    .cpd .pagination{ display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:14px; border-top:1px solid var(--border); flex-wrap:wrap; gap:10px; }
    .cpd .pagination-info{ font-size:12.5px; color:var(--ink-500); }
    .cpd .pagination-controls{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .cpd .page-btn{ min-width:32px;height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); font-size:12.5px; font-weight:600; cursor:pointer; display:inline-flex;align-items:center;justify-content:center; }
    .cpd .page-btn:hover{ background:var(--ink-50); }
    .cpd .page-btn.active{ background:var(--green-600); border-color:var(--green-600); color:var(--white); }
    .cpd .page-btn:disabled{ opacity:.45; cursor:not-allowed; }

    /* Shadcn data table + pagination */
    .cpd .sd-data-table-wrap{ overflow-x:auto; border:1px solid #e4e4e7; border-radius:8px; background:#ffffff; min-height:var(--staff-table-min-height); }
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
    .cpd .sd-data-table tbody .sd-td{ padding:8px; }
    .cpd .sd-td-action{ text-align:right; }
    .cpd .sd-row-actions{
      display:inline-flex; align-items:center; justify-content:center;
      width:32px; height:32px; padding:0; border:none; border-radius:6px;
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
    .cpd .profile-name-row h2{ font-size:16.5px; font-weight:700; margin:0; color:var(--ink-900); font-family:'Inter',sans-serif; }
    .cpd .header-title h1{ font-size:22px; font-weight:700; margin:0 0 2px; letter-spacing:-.01em; color:var(--ink-900); font-family:'Inter',sans-serif; }
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
    .cpd .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; color:#71717a; margin-bottom:2px; }
    .cpd .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; color:#18181b; word-break:break-word; }
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
    .cpd .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; text-transform:none; letter-spacing:0; color:#18181b; margin:18px 0 10px; }
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
    .sd-alert-dialog, .sd-dialog{ font-family:'Inter','Segoe UI',sans-serif; }
    .sd-alert-dialog-content,
    #deleteModal .sd-dialog-content,
    #archiveModal .sd-dialog-content,
    #duplicateModal .sd-dialog-content,
    #draftModal .sd-dialog-content,
    #publishModal .sd-dialog-content{
      position:relative; width:100%; max-width:420px; background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:12px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(15,23,42,0.1), 0 4px 6px -4px rgba(15,23,42,0.1); animation:modalIn 0.2s ease;
    }
    .sd-alert-dialog-header, .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    .sd-alert-dialog-title, .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1.2; letter-spacing:-0.02em; color:#18181b; }
    .sd-alert-dialog-description, .sd-dialog-description{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; line-height:1.5; color:#71717a; }
    .sd-alert-dialog-footer, .sd-dialog-footer{ display:flex; align-items:center; justify-content:flex-end; gap:8px; margin-top:2px; }
    .sd-alert-dialog-close,
    .sd-dialog-close{ position:absolute; top:12px; right:12px; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; }
    .sd-alert-dialog-close:hover,
    .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    .sd-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
      font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1;
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
    .announcements-cms .announcements-toolbar{ margin-bottom:12px; padding:0; }
    .announcements-cms .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    /* toolbar filter selects → dialog outline button look */
    .announcements-cms .toolbar-filters select.select-input{ width:auto; height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .announcements-cms .toolbar-filters select.select-input:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    /* table data (dialog palette) */
    .announcements-cms .sd-th{ background:#fafafa; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; text-transform:none; letter-spacing:0; }
    .announcements-cms .sd-th{ padding:0 8px; }
    .announcements-cms .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    .announcements-cms .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    .announcements-cms .th-sort.th-sorted{ color:#18181b; }
    .announcements-cms .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    .announcements-cms .th-sorted .th-sort-icon{ color:#18181b; }
    .announcements-cms .sd-td{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; }
    .announcements-cms .sd-data-table tbody .sd-tr:hover,
    .announcements-cms .sd-data-table tbody .sd-tr.selected{ background:#f4f4f5; }
    .announcements-cms .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .announcements-cms .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    .announcements-cms .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; margin-top:0; }
    .announcements-cms .status-pill{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    .announcements-cms .subj-chip{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    /* right panel info */
    .announcements-cms .profile-name-row h2{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    .announcements-cms .profile-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    .announcements-cms .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .announcements-cms .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; color:#71717a; margin-bottom:2px; }
    .announcements-cms .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; color:#18181b; }
    .announcements-cms .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
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
    #annProfileModal .sd-dialog-content{ position:relative; width:100%; max-width:min(480px,92vw); width:min(480px,92vw); height:100%; max-height:none; overflow-y:auto; background:#ffffff; border:none; border-radius:0; padding:24px 24px 16px; display:flex; flex-direction:column; gap:18px; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); }
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
    #annProfileModal .type-badge, #annProfileModal .ann-profile-date{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #annProfileModal .profile-header{ flex-shrink:0; }
    #annProfileModal .sd-dialog-footer{ flex-shrink:0; display:flex; flex-direction:row; justify-content:flex-end; gap:8px; padding-top:12px; margin-top:4px; padding-left:16px; border-top:1px solid #e4e4e7; }
    #annProfileModal .rpt-section-title-action{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    #annProfileModal .ann-edit-icon{ display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; flex:0 0 30px; padding:0; border:1px solid #e4e4e7; border-radius:6px; background:#fff; cursor:pointer; }
    #annProfileModal .ann-edit-icon img{ display:block; width:16px; height:16px; object-fit:contain; }
    #annProfileModal .ann-edit-icon:hover{ border-color:#18181b; background:#f4f4f5; }
    #draftModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
    #draftModal .sd-dialog-content{ position:relative; width:100%; max-width:420px; background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
    #draftModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; }
    #draftModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #draftModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #draftModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #draftModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #draftModal .sd-dialog-footer{ display:flex; flex-direction:row; justify-content:flex-end; gap:8px; margin-top:4px; }
    #publishModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
    #publishModal .sd-dialog-content{ position:relative; width:100%; max-width:420px; background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
    #publishModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; }
    #publishModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #publishModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #publishModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #publishModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #publishModal .sd-dialog-footer{ display:flex; flex-direction:row; justify-content:flex-end; gap:8px; margin-top:4px; }

    /* ── Checkbox bulk selection ── */
    .announcements-cms .sd-table-cb{ width:44px; padding-left:14px; padding-right:0; }
    .announcements-cms .sd-table-cb input[type="checkbox"]{ width:15px; height:15px; accent-color:#18181b; cursor:pointer; margin:0; display:block; }
    .bulk-bar{ display:none; align-items:center; gap:12px; margin-bottom:4px; padding-top:10px; font-family:'Inter','Segoe UI',sans-serif; }
    .bulk-bar.active{ display:flex; }
    .bulk-bar .bulk-count{ font-size:14px; color:#71717a; white-space:nowrap; margin-right:4px; }
    .bulk-bar .bulk-actions{ display:flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; }
    .bulk-bar .bulk-btn{ display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 12px; border-radius:5px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:500; color:#52525b; cursor:pointer; transition:background-color .15s ease, color .15s ease; }
    .bulk-bar .bulk-btn:hover{ background:#f4f4f5; color:#18181b; }
    .bulk-bar .bulk-btn.danger{ color:#dc2626; }
    .bulk-bar .bulk-btn.danger:hover{ background:#fef2f2; }
    .bulk-bar .bulk-btn svg{ width:14px; height:14px; flex-shrink:0; }

    /* Bulk details alert dialog (shadcn AlertDialog style) */
    #annBulkModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
    #annBulkModal .sd-dialog-content{ position:relative; width:100%; max-width:440px; max-height:calc(100vh - 2rem); overflow:hidden; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
    #annBulkModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; z-index:2; }
    #annBulkModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #annBulkModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #annBulkModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #annBulkModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #annBulkModal .sd-field-group{ flex:1; min-height:0; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right:2px; }
    #annBulkModal .sd-dialog-footer{ flex-shrink:0; display:flex; flex-direction:row; align-items:center; justify-content:flex-end; gap:8px; margin-top:4px; }
    .ann-bulk-list{ display:flex; flex-direction:column; gap:8px; }
    .ann-bulk-item{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; border:1px solid #e4e4e7; border-radius:8px; background:#fafafa; }
    .ann-bulk-item-skip{ opacity:.8; background:#fff; border-style:dashed; }
    .ann-bulk-item-main{ min-width:0; }
    .ann-bulk-item-title{ font-size:14px; font-weight:500; color:#18181b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ann-bulk-item-sub{ font-size:12.5px; color:#71717a; margin-top:2px; }
    .ann-bulk-item-right{ display:flex; align-items:center; gap:8px; flex-shrink:0; }
    .ann-bulk-mark{ font-size:12px; font-weight:600; padding:3px 8px; border-radius:999px; white-space:nowrap; }
    .ann-bulk-mark-go{ background:#ECFDF3; color:#027A48; border:1px solid #D1FADF; }
    .ann-bulk-mark-skip{ background:#f4f4f5; color:#71717a; border:1px solid #e4e4e7; }
    .ann-bulk-progress{ margin-right:auto; font-size:13px; color:#71717a; white-space:nowrap; }

    /* Bulk alert (scheduled + publish) */
    #annBulkAlertModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
    #annBulkAlertModal .sd-dialog-content{ position:relative; width:100%; max-width:440px; background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
    #annBulkAlertModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; }
    #annBulkAlertModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #annBulkAlertModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #annBulkAlertModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #annBulkAlertModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #annBulkAlertModal .sd-dialog-footer{ display:flex; align-items:center; flex-direction:row; justify-content:flex-end; gap:8px; margin-top:4px; }
    .ann-alert-banner{ display:flex; align-items:flex-start; gap:8px; padding:10px 12px; border-radius:8px; background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; font-size:13px; line-height:1.5; }
    .ann-alert-banner i{ margin-top:2px; font-size:12px; }
    .ann-alert-list{ display:flex; flex-direction:column; gap:6px; max-height:210px; overflow-y:auto; }
    .ann-alert-row{ display:flex; align-items:center; gap:8px; font-size:13.5px; color:#18181b; padding:7px 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fafafa; }
    .ann-alert-row i{ color:#92400E; font-size:12px; flex-shrink:0; }
    .ann-alert-row span:not(.ann-alert-when){ min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ann-alert-when{ margin-left:auto; font-size:12px; color:#71717a; white-space:nowrap; }
    .ann-alert-note{ margin:0; font-size:13px; color:#71717a; line-height:1.5; }
  </style>
</head>
<body class="staff-theme" data-portal="staff">

<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>

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
            <div class="db-toolbar announcements-toolbar">
              <div class="toolbar-main-controls">
                <div class="search-grow-wrap">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="mainSearch" placeholder="Search by title, category, keyword..." oninput="setSearch(this.value)">
                </div>
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
              </div>
              <button type="button" class="sd-btn sd-btn-default" onclick="openCreate('announcement')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Announcement
              </button>
            </div>
            <div class="bulk-bar" id="annBulkBar">
              <div class="bulk-actions">
                <span class="bulk-count" id="annBulkCount">0 posts selected</span>
                <button type="button" class="bulk-btn" onclick="annBulkAction('duplicate')" title="Duplicate selected posts">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  Duplicate
                </button>
                <button type="button" class="bulk-btn" onclick="annBulkAction('draft')" title="Move selected posts to draft">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  Draft
                </button>
                <button type="button" class="bulk-btn" onclick="annBulkAction('publish')" title="Publish selected drafts">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Publish
                </button>
                <button type="button" class="bulk-btn danger" onclick="annBulkAction('archive')" title="Archive selected posts">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                  Archive
                </button>
                <button type="button" class="bulk-btn" onclick="annClearSelection()" title="Clear selection">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  Clear selection
                </button>
              </div>
            </div>
            <div id="tableWrap" class="sd-data-table-wrap">
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
        <input type="hidden" name="audience_type" id="formAudienceType" value="all">
        <input type="hidden" name="barangay_id" id="formBarangayId" value="">
        <input type="hidden" name="audience_barangays" id="formAudienceBarangays" value="">
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
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="formProgramLocation">Location/s</label>
                <input type="text" name="location" id="formProgramLocation" class="sd-input" placeholder="e.g. Paliparan 1, Salawag">
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

              <div class="sd-field" id="scheduleField">
                <label class="sd-label" for="formPublishAt">Schedule Publish Date &amp; Time</label>
                <input type="datetime-local" name="publish_at" id="formPublishAt" class="sd-input" onchange="onScheduleChange()">
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
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="closeModal('deleteModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Delete Permanently</h2>
            <p class="sd-dialog-description">This announcement will be removed permanently and cannot be undone.</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmDeleteBtn" onclick="executeDelete()" style="background:#dc2626;border-color:#dc2626;">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div id="archiveModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="annCancelActionModal('archiveModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Archive Announcement</h2>
            <p class="sd-dialog-description">This item will be moved to the archive and hidden from the public announcements page.</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="annCancelActionModal('archiveModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmArchiveBtn" onclick="executeArchive()">Archive</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Duplicate Confirmation Modal -->
    <div id="duplicateModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="annCancelActionModal('duplicateModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Duplicate Announcement</h2>
            <p class="sd-dialog-description">Create a copy of this announcement as a new draft?</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="annCancelActionModal('duplicateModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmDuplicateBtn" onclick="executeDuplicate()">Duplicate</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Move to Draft Modal -->
    <div id="draftModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="annCancelActionModal('draftModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Move to Draft</h2>
            <p class="sd-dialog-description">Set this post to draft? It will be hidden from the public page until published again.</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="annCancelActionModal('draftModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmDraftBtn" onclick="executeDraft()"><i class="fas fa-file"></i> Move to Draft</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Publish Modal -->
    <div id="publishModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
          <button type="button" class="sd-dialog-close" onclick="annCancelActionModal('publishModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title">Publish Now</h2>
            <p class="sd-dialog-description">Publish this post? It will be visible to the public page immediately.</p>
          </div>
          <div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="annCancelActionModal('publishModal')">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="confirmPublishBtn" onclick="executePublish()"><i class="fas fa-check-circle"></i> Publish Now</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Bulk details alert dialog (shadcn AlertDialog style) -->
    <div id="annBulkModal" class="modal cpd">
      <div class="sd-dialog">
        <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="annBulkTitle" aria-describedby="annBulkDesc">
          <button type="button" class="sd-dialog-close" onclick="annCloseBulk()" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title" id="annBulkTitle">Review Selection</h2>
            <p class="sd-dialog-description" id="annBulkDesc"></p>
          </div>
          <div class="sd-field-group">
            <div id="annBulkList" class="ann-bulk-list"></div>
          </div>
          <div class="sd-dialog-footer">
            <span class="ann-bulk-progress" id="annBulkProgress"></span>
            <button type="button" class="sd-btn sd-btn-outline" id="annBulkCancelBtn" onclick="annCloseBulk()">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="annBulkConfirmBtn" onclick="annRunBulkFromDetails()">Confirm</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Bulk alert: scheduled posts + Publish -->
    <div id="annBulkAlertModal" class="modal">
      <div class="sd-dialog">
        <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="annAlertTitle" aria-describedby="annAlertCount">
          <button type="button" class="sd-dialog-close" onclick="annCloseAlert()" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title" id="annAlertTitle">Scheduled Posts in Selection</h2>
            <p class="sd-dialog-description" id="annAlertCount"></p>
          </div>
          <div class="ann-alert-banner"><i class="fas fa-triangle-exclamation"></i><span>Scheduled posts publish automatically and cannot be published manually.</span></div>
          <div class="ann-alert-list" id="annAlertList"></div>
          <p class="ann-alert-note" id="annAlertNote" style="display:none;"></p>
          <div class="sd-dialog-footer">
            <span class="ann-bulk-progress" id="annAlertProgress"></span>
            <button type="button" class="sd-btn sd-btn-outline" id="annAlertCancelBtn" onclick="annCloseAlert()">Cancel</button>
            <button type="button" class="sd-btn sd-btn-default" id="annAlertConfirmBtn" onclick="annRunBulkFromAlert()">Publish Drafts</button>
          </div>
        </div>
      </div>
    </div>

    <?php include __DIR__ . '/../../includes/portal-toast.php'; ?>
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
  post: (function() {
    try {
      const savedPost = localStorage.getItem('staffAnnouncementsPost');
      return ['all', 'announcement', 'advisory'].indexOf(savedPost) !== -1 ? savedPost : 'all';
    } catch (e) {
      return 'all';
    }
  })(),
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
    location: a.location || '',
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

/* ── Bulk flash toast (survives reload) ── */
(function(){
  try{
    const raw = sessionStorage.getItem('annBulkToast');
    if(!raw) return;
    sessionStorage.removeItem('annBulkToast');
    const f = JSON.parse(raw);
    setTimeout(function(){ annToast(f.m, f.e); }, 250);
  }catch(e){}
})();

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
  return '<div class="info-row"><div><p class="info-row-label">' + label + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
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
      a.location.toLowerCase().includes(q) ||
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
  if(col === 'location') return String(a.location||'').toLowerCase();
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
  document.getElementById('tableHead').innerHTML = '<tr class="sd-tr"><th class="sd-th sd-table-cb"><input type="checkbox" id="annSelectAll" aria-label="Select all posts" onclick="event.stopPropagation();annToggleSelectAll(this)"></th><th class="sd-th">Photo</th><th class="sd-th">' + annSortHead('title','Title') + '</th><th class="sd-th">' + annSortHead('location','Location/s') + '</th><th class="sd-th">Content</th><th class="sd-th">' + annSortHead('type','Type') + '</th><th class="sd-th">' + annSortHead('category','Category') + '</th><th class="sd-th">' + annSortHead('status','Status') + '</th><th class="sd-th">' + annSortHead('created','Created') + '</th><th class="sd-th sd-th-action"></th></tr>';
}
function annSortBy(col){
  if(state.sortCol === col){ state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
  else { state.sortCol = col; state.sortDir = 'asc'; }
  state.page = 1;
  setPost(state.post);
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
    tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="10"><div class="no-results"><i class="fas fa-inbox"></i><p>No posts found.</p></div></td></tr>';
    cards.innerHTML = '';
  } else {
    tbody.innerHTML = slice.map(rowHtml).join('');
    cards.innerHTML = slice.map(cardHtml).join('');
  }

  document.getElementById('tableWrap').style.display = state.view === 'table' ? '' : 'none';
  cards.style.display = state.view === 'cards' ? '' : 'none';

  renderPagination(pages);
  annUpdateBulkBar();
}

function statusDisplay(a){
  const sm = statusMeta(a.status);
  let detail = fmtDate(a.created_at);
  if (a.status === 'scheduled') detail = a.publish_at ? fmtDateTime(a.publish_at) : 'Scheduled';
  if (a.status === 'published' && a.publish_at) detail = fmtDateTime(a.publish_at);
  if (a.status === 'archived' && a.archived_at) detail = fmtDateTime(a.archived_at);
  return { label: sm.label, detail: detail };
}

function rowHtml(a){
  const sm = statusMeta(a.status);
  const tm = typeMeta(a.category);
  const subj = SUBJECTS[a.subject] || { label: a.subject, icon: 'fa-tag' };
  const thumb = a.photo_url
    ? '<img src="' + esc(a.photo_url) + '" alt="">'
    : '<i class="fas fa-bullhorn"></i>';
  const featured = a.is_featured ? '<i class="fas fa-star"></i>' : '';
  const statusText = statusDisplay(a);
  const dateText = a.status === 'scheduled' ? (a.publish_at ? fmtDateTime(a.publish_at) : 'Scheduled') : fmtDate(a.created_at);
  return '<tr class="sd-tr" data-id="' + a.id + '" onclick="selectPost(' + a.id + ')" style="cursor:pointer;">' +
    '<td class="sd-td sd-table-cb"><input type="checkbox" class="row-cb" data-id="' + a.id + '"' + (annSel.has(a.id) ? ' checked' : '') + ' onclick="event.stopPropagation();annToggleRow(' + a.id + ', this.checked)" aria-label="Select post"></td>' +
    '<td class="sd-td"><div class="post-thumb-sm">' + thumb + '</div></td>' +
    '<td class="sd-td"><div class="client-name">' + esc(a.title) + featured + '</div></td>' +
    '<td class="sd-td"><div class="contact-main">' + (a.location ? esc(a.location) : '—') + '</div></td>' +
    '<td class="sd-td"><div class="contact-sub">' + esc(a.content) + '</div></td>' +
    '<td class="sd-td"><span class="subj-chip">' + tm.label + '</span></td>' +
    '<td class="sd-td">' + esc(subjectLabel(a.subject)) + '</td>' +
    '<td class="sd-td"><div class="status-stack"><span class="status-pill ' + sm.cls + '"><span class="st-dot"></span>' + sm.label + '</span><span class="status-date">' + esc(statusText.detail) + '</span></div></td>' +
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
    (a.location ? '<div class="rpt-card-brgy"><i class="fas fa-location-dot"></i> ' + esc(a.location) + '</div>' : '') +
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
  try { localStorage.setItem('staffAnnouncementsPost', v); } catch (e) {}
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
function closeRowMenu(){
  const pop = document.getElementById('rowMenuPop');
  if (pop) {
    pop.classList.remove('open');
    pop.dataset.menuId = '';
    pop.style.top = '';
    pop.style.left = '';
  }
  document.removeEventListener('click', closeRowMenuOnce);
}

function openRowMenu(ev, id){
  ev.stopPropagation();
  const a = DATA.find(x => x.id === id);
  const pop = document.getElementById('rowMenuPop');
  if (!a || !pop) return;
  if (pop.classList.contains('open') && pop.dataset.menuId == String(id)) {
    closeRowMenu();
    return;
  }

  closeRowMenu();

  let items = '';
  const menuIcons = {
    view: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    edit: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    publish: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    archive: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
    restore: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5"/></svg>',
    copy: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    delete: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>'
  };
  items += '<button class="row-menu-item" onclick="closeRowMenu(); selectPost(' + id + ');">' + menuIcons.view + 'View Post</button>';
  items += '<button class="row-menu-item" onclick="closeRowMenu(); openEdit(' + id + ');">' + menuIcons.edit + 'Edit Post</button>';
  if (a.status !== 'published' && a.status !== 'scheduled') items += '<button class="row-menu-item" onclick="closeRowMenu(); confirmPublish(' + id + ');">' + menuIcons.publish + 'Publish Now</button>';
  if (a.status !== 'archived') items += '<button class="row-menu-item" onclick="closeRowMenu(); confirmArchive(' + id + ');">' + menuIcons.archive + 'Archive</button>';
  else items += '<button class="row-menu-item" onclick="closeRowMenu(); setPostStatus(' + id + ',\'draft\');">' + menuIcons.restore + 'Restore from Archive</button>';
  items += '<button class="row-menu-item" onclick="closeRowMenu(); confirmDuplicate(' + id + ');">' + menuIcons.copy + 'Duplicate</button>';
  items += '<button class="row-menu-item danger" onclick="closeRowMenu(); confirmDelete(' + id + ');">' + menuIcons.delete + 'Delete Permanently</button>';
  pop.innerHTML = items;
  const r = ev.currentTarget.getBoundingClientRect();
  pop.dataset.menuId = String(id);
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
  pop.classList.add('open');
  document.addEventListener('click', closeRowMenuOnce);
}
function closeRowMenuOnce(e){
  const pop = document.getElementById('rowMenuPop');
  if (!pop) return;
  if (e && e.target && e.target.closest && (e.target.closest('.row-menu-pop') || e.target.closest('.sd-row-actions'))) {
    return;
  }
  closeRowMenu();
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
    '<div class="profile-header" style="display:flex;align-items:flex-start;justify-content:space-between;padding-top:8px;">' +
      '<div>' +
        '<div class="profile-name-row"><h2>' + esc(a.title) + '</h2></div>' +
        '<span class="ann-profile-date">Posted on: ' + pubDate + '</span>' +
      '</div>' +
      '<span class="status-pill ' + (a.status === 'published' ? 'status-active' : 'status-inactive') + '" style="flex-shrink:0;margin-top:4px;' + (a.status === 'published' ? 'background:#ECFDF3;color:#027A48;border-color:#D1FADF;' : 'background:#FEF3C7;color:#92400E;border-color:#FDE68A;') + '">' + sm.label + '</span>' +
    '</div>' +
    '<div class="rpt-section-title rpt-section-title-action" style="margin-top:6px;"><span>Preview</span><button type="button" class="ann-edit-icon" onclick="openEdit(' + a.id + ')" aria-label="Edit announcement" title="Edit announcement"><img src="assets/images/edit%20icon.png" alt=""></button></div>' +
    '<div class="acms-preview-stage">' + (a.photo_url ? '<img src="' + esc(a.photo_url) + '" alt="">' : '<div class="acms-preview-placeholder"><i class="fas fa-bullhorn"></i></div>') + '</div>' +
    '<div class="rpt-section-title">Details</div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
      infoRow('tag', 'Type', tm.label) +
      infoRow('tag2', 'Category', subjectLabel(a.subject)) +
      infoRow('map-marker-alt', 'Location/s', a.location || '—') +
      infoRow('pen', 'Last Updated', a.updated_at ? fmtDate(a.updated_at) : '—') +
    '</div>' +
    '<div style="margin-top:16px;background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;">' +
      '<div style="font-size:13px;font-weight:500;color:#18181b;white-space:pre-line;line-height:1.55;">' + esc(a.content) + '</div>' +
    '</div>';
  var footer = document.getElementById('annProfileFooter');
  if (footer) {
    const showPublish = a.status !== 'published' && a.status !== 'scheduled';
    footer.innerHTML =
      (a.status !== 'archived' ? '<button type="button" class="sd-btn sd-btn-outline" onclick="confirmArchive(' + a.id + ')"><i class="fas fa-archive"></i> Archive</button>' : '') +
      (showPublish
      ? '<button type="button" class="sd-btn sd-btn-outline" onclick="confirmPublish(' + a.id + ')"><i class="fas fa-check-circle"></i> Publish</button>'
      : '<button type="button" class="sd-btn sd-btn-outline" onclick="confirmDraft(' + a.id + ')"><i class="fas fa-file"></i> Draft</button>') +
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
  onStatusChange();
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
      document.getElementById('formProgramLocation').value = a.location || '';
      document.getElementById('formCategory').value = a.category;
      document.getElementById('formSubject').value = a.subject || 'general_notices';
      document.getElementById('formStatus').value = a.status === 'archived' ? 'draft' : a.status;
      document.getElementById('formPublishAt').value = a.publish_at ? toLocalInput(a.publish_at) : '';
      document.getElementById('formLinkUrl').value = a.link_url || '';
      document.getElementById('formContent').value = a.content;
      document.getElementById('formAudienceType').value = a.audience_type || 'all';
      document.getElementById('formBarangayId').value = a.barangay_id || '';
      document.getElementById('formAudienceBarangays').value = a.audience_barangays || '';
      onStatusChange();
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
  // schedule field always visible
}
function onScheduleChange(){
  var val = document.getElementById('formPublishAt').value;
  if(val){
    document.getElementById('formStatus').value = 'scheduled';
    document.getElementById('scheduleField').style.display = 'block';
  }
}
/* ───────────────────────── Form submit ───────────────────────── */
document.getElementById('announcementForm').addEventListener('submit', function(e){
  e.preventDefault();
  const fd = new FormData(this);
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
      else { alert('Error: ' + data.message); btn.innerHTML = 'Delete'; btn.disabled = false; }
    })
    .catch(() => { alert('Network error'); btn.innerHTML = 'Delete'; btn.disabled = false; });
}

let archiveId = 0;
function confirmArchive(id){ annRestoreBulkConfirm(); archiveId = id; openModal('archiveModal'); }
function executeArchive(){
  if(annRunPendingBulk('archive')) return;
  if(!archiveId) return;
  const btn = document.getElementById('confirmArchiveBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', archiveId);
  fd.append('status', 'archived');
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(data.success){ closeModal('archiveModal'); location.reload(); }
      else { alert('Error: ' + data.message); btn.innerHTML = 'Archive'; btn.disabled = false; }
    })
    .catch(() => { alert('Network error'); btn.innerHTML = 'Archive'; btn.disabled = false; });
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
  annRestoreBulkConfirm();
  draftId = id;
  openModal('draftModal');
}
function executeDraft(){
  if(annRunPendingBulk('draft')) return;
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

let publishId = 0;
function confirmPublish(id){
  annRestoreBulkConfirm();
  publishId = id;
  openModal('publishModal');
}
function executePublish(){
  if(annRunPendingBulk('publish')) return;
  if(!publishId) return;
  const btn = document.getElementById('confirmPublishBtn');
  if(btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', publishId);
  fd.append('status', 'published');
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(btn) btn.disabled = false;
      if(data.success){ closeModal('publishModal'); location.reload(); }
      else alert('Error: ' + data.message);
    })
    .catch(() => { if(btn) btn.disabled = false; alert('Network error'); });
}

let duplicateId = 0;
function confirmDuplicate(id){ annRestoreBulkConfirm(); duplicateId = id; openModal('duplicateModal'); }
function executeDuplicate(){
  if(annRunPendingBulk('duplicate')) return;
  if(!duplicateId) return;
  const btn = document.getElementById('confirmDuplicateBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duplicating...';
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'duplicate');
  fd.append('id', duplicateId);
  fetch('staff-announcements-handler.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if(data.success){ closeModal('duplicateModal'); location.reload(); }
      else { alert('Error: ' + data.message); btn.innerHTML = 'Duplicate'; btn.disabled = false; }
    })
    .catch(() => { alert('Network error'); btn.innerHTML = 'Duplicate'; btn.disabled = false; });
}

/* ── Checkbox bulk selection ── */
let annSel = new Set();
let annBulkRunning = false;
let annBulkAborted = false;
let annBulkFinishing = false;
let annBulkApplied = 0;
let annBulkTotal = 0;
let annBulkActiveModal = '';
let annBulkPending = null;

const ANN_BULK_STATUS = { draft: 'draft', publish: 'published', archive: 'archived' };
const ANN_BULK_META = {
  duplicate: { title: 'Duplicate Posts', icon: 'fa-copy' },
  draft: { title: 'Move to Draft', icon: 'fa-file' },
  publish: { title: 'Publish Posts', icon: 'fa-check-circle' },
  archive: { title: 'Archive Posts', icon: 'fa-archive' }
};

function annToast(msg, isError){
  if(typeof portalToast === 'function'){ portalToast(isError ? 'Error' : 'Success', msg); }
}

function annUpdateBulkBar(){
  const n = annSel.size;
  const bar = document.getElementById('annBulkBar');
  if(bar) bar.classList.toggle('active', n > 0);
  const cnt = document.getElementById('annBulkCount');
  if(cnt) cnt.textContent = n + (n === 1 ? ' post selected' : ' posts selected');
  const head = document.getElementById('annSelectAll');
  if(head){
    const vis = Array.prototype.slice.call(document.querySelectorAll('#dataRows .row-cb'));
    const on = vis.filter(function(c){ return c.checked; }).length;
    head.checked = vis.length > 0 && on === vis.length;
    head.indeterminate = on > 0 && on < vis.length;
  }
}

function annToggleRow(id, checked){
  if(checked) annSel.add(id); else annSel.delete(id);
  annUpdateBulkBar();
}

function annToggleSelectAll(cb){
  document.querySelectorAll('#dataRows .row-cb').forEach(function(c){
    c.checked = cb.checked;
    const id = parseInt(c.getAttribute('data-id'), 10);
    if(cb.checked) annSel.add(id); else annSel.delete(id);
  });
  annUpdateBulkBar();
}

function annClearSelection(){
  annSel.clear();
  document.querySelectorAll('#dataRows .row-cb').forEach(function(c){ c.checked = false; });
  annUpdateBulkBar();
}

function annGet(id){ return DATA.find(function(x){ return x.id === id; }); }

function annBulkPlan(action, ids){
  const eligible = [], skips = [];
  let scheduledCount = 0;
  ids.forEach(function(id){
    const a = annGet(id);
    if(!a){ skips.push({ id:id, title:'#' + id, status:'', reason:'Post not found' }); return; }
    if(a.status === 'scheduled') scheduledCount++;
    let ok = false, reason = '';
    if(action === 'duplicate'){ ok = true; }
    else if(action === 'archive'){ ok = a.status !== 'archived'; reason = 'Already archived'; }
    else if(action === 'draft'){ ok = a.status !== 'draft'; reason = 'Already a draft'; }
    else if(action === 'publish'){
      if(a.status === 'draft') ok = true;
      else if(a.status === 'scheduled') reason = 'Scheduled — publishes automatically';
      else if(a.status === 'published') reason = 'Already published';
      else reason = 'Archived — restore first';
    }
    if(ok) eligible.push(a);
    else skips.push({ id:a.id, title:a.title, status:a.status, reason:reason });
  });
  return { eligible: eligible, skips: skips, scheduledCount: scheduledCount };
}

function annConfirmLabel(action, n){
  if(action === 'duplicate') return 'Duplicate ' + n + ' ' + (n === 1 ? 'Post' : 'Posts');
  if(action === 'draft') return 'Move ' + n + ' to Draft';
  if(action === 'publish') return 'Publish ' + n + ' ' + (n === 1 ? 'Draft' : 'Drafts');
  return 'Archive ' + n + ' ' + (n === 1 ? 'Post' : 'Posts');
}

function annEligibleLabel(action){
  if(action === 'duplicate') return 'Will duplicate';
  if(action === 'draft') return 'Will move to draft';
  if(action === 'publish') return 'Will publish';
  return 'Will archive';
}

function annNoEligibleMsg(action){
  if(action === 'duplicate') return 'No selected posts can be duplicated.';
  if(action === 'draft') return 'No selected drafts to move.';
  if(action === 'publish') return 'No selected drafts to publish.';
  return 'No selected posts can be archived.';
}

function annRestoreBulkConfirm(){
  if(!annBulkPending) return;
  const pending = annBulkPending;
  const modal = document.getElementById(pending.modalId);
  if(modal){
    modal.querySelector('.sd-dialog-title').textContent = pending.originalTitle;
    modal.querySelector('.sd-dialog-description').textContent = pending.originalDescription;
    const confirm = document.getElementById(pending.confirmId);
    if(confirm){ confirm.innerHTML = pending.originalConfirm; confirm.disabled = false; }
    const cancel = modal.querySelector('.sd-dialog-footer .sd-btn-outline');
    if(cancel) cancel.textContent = pending.originalCancel;
  }
  annBulkPending = null;
  annBulkActiveModal = '';
}

function annCancelActionModal(modalId){
  if(annBulkRunning){ annAbortBulk(); return; }
  if(annBulkPending && annBulkPending.modalId === modalId) annRestoreBulkConfirm();
  closeModal(modalId);
}

function annOpenActionConfirm(action, plan){
  annRestoreBulkConfirm();
  const settings = {
    duplicate: { modalId:'duplicateModal', confirmId:'confirmDuplicateBtn', title:'Duplicate Posts' },
    draft: { modalId:'draftModal', confirmId:'confirmDraftBtn', title:'Move to Draft' },
    publish: { modalId:'publishModal', confirmId:'confirmPublishBtn', title:'Publish Posts' },
    archive: { modalId:'archiveModal', confirmId:'confirmArchiveBtn', title:'Archive Posts' }
  }[action];
  const modal = document.getElementById(settings.modalId);
  const title = modal.querySelector('.sd-dialog-title');
  const description = modal.querySelector('.sd-dialog-description');
  const confirm = document.getElementById(settings.confirmId);
  const cancel = modal.querySelector('.sd-dialog-footer .sd-btn-outline');
  const count = plan.eligible.length;
  const skipped = plan.skips.length;
  const postLabel = count === 1 ? 'post' : 'posts';
  let message;
  if(action === 'duplicate') message = 'Create ' + count + ' selected ' + postLabel + ' as new drafts?';
  else if(action === 'draft') message = 'Move ' + count + ' selected ' + postLabel + ' to draft? They will be hidden from the public page.';
  else if(action === 'publish') message = 'Publish ' + count + ' selected draft' + (count === 1 ? '' : 's') + ' now? They will be visible on the public page immediately.';
  else message = 'Archive ' + count + ' selected ' + postLabel + '? They will be hidden from the public announcements page.';
  if(skipped) message += ' ' + skipped + ' ineligible selected ' + (skipped === 1 ? 'post will' : 'posts will') + ' be skipped.';
  annBulkPending = {
    action: action,
    ids: plan.eligible.map(function(a){ return a.id; }),
    modalId: settings.modalId,
    confirmId: settings.confirmId,
    originalTitle: title.textContent,
    originalDescription: description.textContent,
    originalConfirm: confirm.innerHTML,
    originalCancel: cancel.textContent,
    description: message
  };
  title.textContent = settings.title;
  description.textContent = message;
  confirm.textContent = annConfirmLabel(action, count);
  confirm.disabled = false;
  annBulkActiveModal = settings.modalId;
  openModal(settings.modalId);
}

function annRunPendingBulk(action){
  if(!annBulkPending || annBulkPending.action !== action) return false;
  annRunBulk(action, annBulkPending.ids);
  return true;
}

function annBulkAction(action){
  if(annBulkRunning || annBulkFinishing) return;
  const ids = Array.from(annSel);
  if(!ids.length){ annToast('Select at least one post first.', true); return; }
  const plan = annBulkPlan(action, ids);
  if(!plan.eligible.length){ annToast(annNoEligibleMsg(action), true); return; }
  annOpenActionConfirm(action, plan);
}

function annOpenDetails(action, plan, ids){
  const meta = ANN_BULK_META[action];
  document.getElementById('annBulkTitle').textContent = meta.title;
  document.getElementById('annBulkDesc').textContent =
    'Review the ' + ids.length + ' selected post' + (ids.length === 1 ? '' : 's') +
    ' below. Skipped items will not be changed.';
  let html = '';
  plan.eligible.forEach(function(a){
    const sm = statusMeta(a.status);
    html += '<div class="ann-bulk-item">' +
      '<div class="ann-bulk-item-main">' +
        '<div class="ann-bulk-item-title">' + esc(a.title) + '</div>' +
        '<div class="ann-bulk-item-sub">' + typeMeta(a.category).label + ' · ' + subjectLabel(a.subject) + '</div>' +
      '</div>' +
      '<div class="ann-bulk-item-right">' +
        '<span class="status-pill ' + sm.cls + '"><span class="st-dot"></span>' + sm.label + '</span>' +
        '<span class="ann-bulk-mark ann-bulk-mark-go">' + annEligibleLabel(action) + '</span>' +
      '</div>' +
    '</div>';
  });
  plan.skips.forEach(function(s){
    const sm = statusMeta(s.status);
    html += '<div class="ann-bulk-item ann-bulk-item-skip">' +
      '<div class="ann-bulk-item-main">' +
        '<div class="ann-bulk-item-title">' + esc(s.title) + '</div>' +
        '<div class="ann-bulk-item-sub">Skipped — ' + esc(s.reason) + '</div>' +
      '</div>' +
      '<div class="ann-bulk-item-right">' +
        (s.status ? '<span class="status-pill ' + sm.cls + '"><span class="st-dot"></span>' + sm.label + '</span>' : '') +
        '<span class="ann-bulk-mark ann-bulk-mark-skip">Skipped</span>' +
      '</div>' +
    '</div>';
  });
  document.getElementById('annBulkList').innerHTML = html;
  const btn = document.getElementById('annBulkConfirmBtn');
  btn.innerHTML = '<i class="fas ' + meta.icon + '"></i> ' + annConfirmLabel(action, plan.eligible.length);
  btn.disabled = false;
  btn.dataset.action = action;
  btn.dataset.ids = plan.eligible.map(function(a){ return a.id; }).join(',');
  const cancel = document.getElementById('annBulkCancelBtn');
  cancel.disabled = false;
  cancel.textContent = 'Cancel';
  const prog = document.getElementById('annBulkProgress');
  if(prog) prog.textContent = '';
  annBulkActiveModal = 'annBulkModal';
  openModal('annBulkModal');
}

function annOpenAlert(plan, ids){
  const sched = ids.map(annGet).filter(function(a){ return a && a.status === 'scheduled'; });
  const drafts = plan.eligible.length;
  document.getElementById('annAlertCount').textContent =
    sched.length + ' of the ' + ids.length + ' selected post' + (ids.length === 1 ? ' is' : 's are') +
    ' scheduled. Scheduled posts publish automatically and cannot be published manually.';
  const other = ids.length - sched.length - drafts;
  const note = document.getElementById('annAlertNote');
  if(other > 0){
    note.style.display = '';
    note.textContent = other + ' other selected post' + (other === 1 ? ' is' : 's are') + ' not in draft status and will be skipped.';
  } else {
    note.style.display = 'none';
    note.textContent = '';
  }
  document.getElementById('annAlertList').innerHTML = sched.map(function(a){
    return '<div class="ann-alert-row"><i class="fas fa-clock"></i><span>' + esc(a.title) + '</span><span class="ann-alert-when">' +
      (a.publish_at ? fmtDateTime(a.publish_at) : 'Scheduled') + '</span></div>';
  }).join('');
  const cancel = document.getElementById('annAlertCancelBtn');
  const btn = document.getElementById('annAlertConfirmBtn');
  cancel.disabled = false;
  if(drafts > 0){
    btn.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Publish ' + drafts + (drafts === 1 ? ' Draft' : ' Drafts');
    btn.dataset.ids = plan.eligible.map(function(a){ return a.id; }).join(',');
    cancel.textContent = 'Cancel';
  } else {
    btn.style.display = 'none';
    btn.dataset.ids = '';
    cancel.textContent = 'Close';
  }
  const prog = document.getElementById('annAlertProgress');
  if(prog) prog.textContent = '';
  annBulkActiveModal = 'annBulkAlertModal';
  openModal('annBulkAlertModal');
}

function annIdsFrom(btn){
  return String((btn && btn.dataset && btn.dataset.ids) || '').split(',').filter(Boolean).map(Number);
}

function annRunBulkFromDetails(){
  const btn = document.getElementById('annBulkConfirmBtn');
  annRunBulk(btn.dataset.action, annIdsFrom(btn));
}
function annRunBulkFromAlert(){
  annRunBulk('publish', annIdsFrom(document.getElementById('annAlertConfirmBtn')));
}

function annRunBulk(action, ids){
  if(annBulkRunning || annBulkFinishing) return;
  if(!ids.length){ annToast(annNoEligibleMsg(action), true); return; }
  annBulkRunning = true;
  annBulkAborted = false;
  annBulkApplied = 0;
  annBulkTotal = ids.length;
  const confirmBtn = document.getElementById('annBulkConfirmBtn');
  const alertBtn = document.getElementById('annAlertConfirmBtn');
  const cancelBtn = document.getElementById('annBulkCancelBtn');
  const alertCancel = document.getElementById('annAlertCancelBtn');
  const actionModal = annBulkPending ? document.getElementById(annBulkPending.modalId) : null;
  const actionConfirm = annBulkPending ? document.getElementById(annBulkPending.confirmId) : null;
  const actionCancel = actionModal ? actionModal.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
  if(confirmBtn) confirmBtn.disabled = true;
  if(alertBtn) alertBtn.disabled = true;
  if(actionConfirm) actionConfirm.disabled = true;
  if(cancelBtn) cancelBtn.textContent = 'Stop';
  if(alertCancel) alertCancel.textContent = 'Stop';
  if(actionCancel) actionCancel.textContent = 'Stop';
  const progMain = document.getElementById('annBulkProgress');
  const progAlert = document.getElementById('annAlertProgress');
  const statusVal = ANN_BULK_STATUS[action] || '';
  let i = 0, failed = 0, lastErr = '';
  function step(){
    if(annBulkAborted){ annFinishBulk(action, true, failed, lastErr); return; }
    if(i >= ids.length){ annFinishBulk(action, false, failed, lastErr); return; }
    const id = ids[i];
    const progTxt = 'Progress: ' + (i + 1) + ' of ' + ids.length;
    if(progMain) progMain.textContent = progTxt;
    if(progAlert) progAlert.textContent = progTxt;
    if(actionModal){
      const description = actionModal.querySelector('.sd-dialog-description');
      if(description) description.textContent = progTxt;
    }
    const fd = new FormData();
    if(action === 'duplicate'){ fd.append('action', 'duplicate'); fd.append('id', id); }
    else { fd.append('action', 'status'); fd.append('id', id); fd.append('status', statusVal); }
    fetch('staff-announcements-handler.php', { method:'POST', body:fd })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if(data && data.success) annBulkApplied++;
        else { failed++; lastErr = (data && data.message) || 'Failed'; }
        i++;
        step();
      })
      .catch(function(){ failed++; lastErr = 'Network error'; i++; step(); });
  }
  step();
}

function annFinishBulk(action, aborted, failed, lastErr){
  annBulkRunning = false;
  const applied = annBulkApplied, total = annBulkTotal;
  const confirmBtn = document.getElementById('annBulkConfirmBtn');
  const alertBtn = document.getElementById('annAlertConfirmBtn');
  const cancelBtn = document.getElementById('annBulkCancelBtn');
  const alertCancel = document.getElementById('annAlertCancelBtn');
  const actionModal = annBulkPending ? document.getElementById(annBulkPending.modalId) : null;
  const actionConfirm = annBulkPending ? document.getElementById(annBulkPending.confirmId) : null;
  const actionCancel = actionModal ? actionModal.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
  if(confirmBtn) confirmBtn.disabled = false;
  if(alertBtn) alertBtn.disabled = false;
  if(actionConfirm) actionConfirm.disabled = false;
  if(cancelBtn){ cancelBtn.disabled = false; cancelBtn.textContent = 'Cancel'; }
  if(alertCancel){ alertCancel.disabled = false; alertCancel.textContent = 'Cancel'; }
  if(actionCancel && annBulkPending) actionCancel.textContent = annBulkPending.originalCancel;
  if(actionModal && annBulkPending){
    const description = actionModal.querySelector('.sd-dialog-description');
    if(description) description.textContent = annBulkPending.description;
  }
  if(aborted){
    if(applied > 0){
      annBulkFinishing = true;
      annFlash('Cancelled — ' + applied + ' of ' + total + ' post' + (total === 1 ? '' : 's') + ' already changed.', true);
      setTimeout(function(){ location.reload(); }, 700);
    } else {
      const modalId = annBulkActiveModal;
      annRestoreBulkConfirm();
      if(modalId) closeModal(modalId);
      annToast('Cancelled — no posts were changed.');
    }
    return;
  }
  const verb = action === 'duplicate' ? 'Duplicated' : action === 'draft' ? 'Moved to draft' : action === 'publish' ? 'Published' : 'Archived';
  if(applied === 0 && failed > 0){
    annToast(verb + ' 0 posts — ' + failed + ' failed' + (lastErr ? ' (' + lastErr + ')' : '') + '.', true);
    return;
  }
  let msg = verb + ' ' + applied + ' post' + (applied === 1 ? '' : 's') + '.';
  if(failed) msg += ' ' + failed + ' failed' + (lastErr ? ' (' + lastErr + ')' : '') + '.';
  annBulkFinishing = true;
  annFlash(msg, failed > 0);
  setTimeout(function(){ location.reload(); }, 700);
}

function annFlash(msg, isError){
  try{ sessionStorage.setItem('annBulkToast', JSON.stringify({ m: String(msg), e: !!isError })); }catch(e){}
}

function annAbortBulk(){
  if(!annBulkRunning) return;
  annBulkAborted = true;
  const p1 = document.getElementById('annBulkProgress');
  const p2 = document.getElementById('annAlertProgress');
  if(p1) p1.textContent = 'Stopping…';
  if(p2) p2.textContent = 'Stopping…';
  const modal = document.getElementById(annBulkActiveModal);
  const description = modal ? modal.querySelector('.sd-dialog-description') : null;
  if(description) description.textContent = 'Stopping…';
}

function annCloseBulk(){
  if(annBulkRunning){ annAbortBulk(); return; }
  closeModal('annBulkModal');
}
function annCloseAlert(){
  if(annBulkRunning){ annAbortBulk(); return; }
  closeModal('annBulkAlertModal');
}

document.addEventListener('click', function(e){
  if(!annBulkRunning) return;
  if(e && e.target && e.target.closest && e.target.closest('.modal .sd-dialog-content')) return;
  else {
    if(e && e.stopPropagation) e.stopPropagation();
    annAbortBulk();
  }
}, true);

document.addEventListener('keydown', function(e){
  if(!annBulkRunning) return;
  if(e && e.key === 'Escape'){
    if(e.stopPropagation) e.stopPropagation();
    if(e.preventDefault) e.preventDefault();
    annAbortBulk();
  } else return;
}, true);
/* ── Checkbox bulk selection END ── */

/* ───────────────────────── Init ───────────────────────── */
renderCounts();
render();

/* ── Auto-open Create Announcement from Programs page ── */
(function(){
  var params = new URLSearchParams(window.location.search);
  if (!params.has('create_from_program')) return;
  var programPrefill = <?php echo json_encode($programAnnouncementPrefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  openCreate('announcement');
  if (programPrefill) {
    document.getElementById('formTitle').value = programPrefill.name || '';
    document.getElementById('formProgramLocation').value = programPrefill.location || '';
  }
  params.delete('create_from_program');
  var cleanSearch = params.toString();
  var cleanUrl = window.location.pathname + (cleanSearch ? '?' + cleanSearch : '') + window.location.hash;
  history.replaceState(null, '', cleanUrl);
})();
</script>

</body>
</html>