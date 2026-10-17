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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/barangay-announcements.php';
require_once __DIR__ . '/../../includes/barangay-app-base.php';
$barangayAppBaseUrl = dcvsBarangayAppBaseUrl();

$userId = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Barangay Officer';
$avatarUrl = $_SESSION['avatar_url'] ?? '';
$activePage = 'announcements';
$barangaySidebarStylesheetInHead = true;
$barangayName = 'Barangay';

$barangayQuery = $conn->prepare('SELECT barangay_name FROM barangays WHERE barangay_id = ?');
if (!$barangayQuery) {
    throw new RuntimeException('Could not prepare barangay profile query: ' . $conn->error);
}
$barangayQuery->bind_param('i', $barangayId);
if (!$barangayQuery->execute()) {
    $error = $barangayQuery->error;
    $barangayQuery->close();
    throw new RuntimeException('Could not load barangay profile: ' . $error);
}
$barangayRow = $barangayQuery->get_result()->fetch_assoc();
if ($barangayRow) {
    $barangayName = str_replace('±', 'ñ', (string)$barangayRow['barangay_name']);
}
$barangayQuery->close();

$announcements = loadBarangayAnnouncementsForPortal($conn, $barangayId);
$eventCsrfToken = csrfToken();

$unreadNotifs = 0;
$notificationQuery = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL');
if ($notificationQuery) {
    $notificationQuery->bind_param('i', $userId);
    if (!$notificationQuery->execute()) {
        error_log('Unable to load barangay announcement page notification count: ' . $notificationQuery->error);
    } else {
        $unreadNotifs = (int)$notificationQuery->get_result()->fetch_row()[0];
    }
    $notificationQuery->close();
}

function barangayAnnouncementDate(array $announcement): DateTimeImmutable
{
    $date = $announcement['publish_at'] ?: $announcement['created_at'];
    try {
        return new DateTimeImmutable((string)$date);
    } catch (Exception $exception) {
        error_log('Invalid announcement publish date: ' . $exception->getMessage());
        return new DateTimeImmutable('@0');
    }
}

function barangayAnnouncementCalendarDate(array $announcement): DateTimeImmutable
{
    if ((int)($announcement['is_event'] ?? 0) === 1 && !empty($announcement['event_date'])) {
        try {
            return new DateTimeImmutable((string)$announcement['event_date']);
        } catch (Exception $exception) {
            error_log('Invalid announcement event date: ' . $exception->getMessage());
        }
    }
    return barangayAnnouncementDate($announcement);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcements — Brgy. <?php echo htmlspecialchars($barangayName); ?> Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/css/notification.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/notification.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/css/sb2.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    :root { --ann-border:#e4e4e7; --ann-muted:#71717a; --ann-ink:#18181b; --ann-green:#0e8131; }
    *, *::before, *::after { box-sizing:border-box; }
    body { margin:0; color:var(--ann-ink); font-family:'Inter','Segoe UI',sans-serif; font-size:14px; line-height:1.5; background:#fff; }
    .app-wrapper { min-height:100vh; display:flex; flex-direction:column; background:#fff; }
    body.sb2-active .app-wrapper { padding-left:var(--sidebar-w); transition:padding-left .2s ease; }
    .topbar { position:sticky; top:0; z-index:40; height:70px; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:0 24px; background:#fff; border-bottom:1px solid var(--ann-border); }
    body.sb2-active .app-wrapper .topbar { left:auto!important; }
    .topbar-left,.topbar-right,.topbar-breadcrumb,.user-profile { display:flex; align-items:center; }
    .topbar-left { gap:16px; min-width:0; }
    .topbar-right { flex:0 0 auto; }
    .topbar-breadcrumb { gap:8px; min-width:0; color:#94a3b8; font-size:13px; }
    .breadcrumb-root { color:var(--ann-green); font-weight:600; }
    .breadcrumb-current { color:#0f172a; font-weight:600; }
    .breadcrumb-root,.breadcrumb-current { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .breadcrumb-sep { font-size:10px; }
    .topbar-right { gap:14px; }
    .topbar-toggle { display:none; align-items:center; justify-content:center; width:38px; height:38px; border:1px solid var(--ann-border); border-radius:6px; background:#fff; cursor:pointer; }
    .notif-dropdown { position:relative; }
    .notif-btn { position:relative; width:36px; height:36px; display:flex; align-items:center; justify-content:center; border:0; border-radius:6px; background:transparent; color:#475569; cursor:pointer; }
    .notif-badge { position:absolute; top:0; right:0; min-width:18px; height:18px; padding:0 4px; display:flex; align-items:center; justify-content:center; border-radius:9px; background:#dc2626; color:#fff; font-size:10px; font-weight:700; }
    .user-profile { gap:10px; }
    .user-avatar { width:34px; height:34px; display:grid; place-items:center; overflow:hidden; border:1px solid #b8dec9; border-radius:50%; background:#d5ece0; color:var(--ann-green); }
    .user-name { display:block; color:var(--ann-ink); font-size:13px; font-weight:600; line-height:1.3; }
    .user-role { display:block; color:var(--ann-muted); font-size:12px; }
    .dashboard-content { width:100%; min-width:0; flex:1; padding:24px 20px 48px; }
    .announcement-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .announcement-search-controls { display:flex; align-items:center; gap:8px; width:min(100%,540px); }
    .announcement-search-wrap { position:relative; flex:1 1 auto; min-width:0; }
    .announcement-search-icon { position:absolute; top:50%; left:12px; width:16px; height:16px; transform:translateY(-50%); color:#71717a; pointer-events:none; }
    .announcement-search { width:100%; height:36px; padding:0 12px 0 36px; border:1px solid var(--ann-border); border-radius:6px; outline:none; background:#fff; color:var(--ann-ink); font:400 14px 'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .announcement-search::placeholder { color:#94a3b8; opacity:1; }
    .announcement-search:focus { border-color:#a1a1aa; box-shadow:0 0 0 3px rgba(161,161,170,.18); }
    .announcement-filter-wrap { position:relative; flex:0 0 152px; }
    .announcement-filter { width:100%; height:36px; appearance:none; padding:0 34px 0 12px; border:1px solid var(--ann-border); border-radius:6px; outline:none; background:#fff; color:var(--ann-ink); font:400 14px 'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); cursor:pointer; }
    .announcement-filter:focus { border-color:#a1a1aa; box-shadow:0 0 0 3px rgba(161,161,170,.18); }
    .announcement-filter-chevron { position:absolute; top:50%; right:12px; width:14px; height:14px; transform:translateY(-50%); color:#71717a; pointer-events:none; }
    .category-filters { display:flex; align-items:center; border:1px solid var(--ann-border); border-radius:6px; overflow:hidden; }
    .category-filter { min-height:34px; padding:0 12px; border:0; border-left:1px solid var(--ann-border); background:#fff; color:#52525b; cursor:pointer; font:500 12px 'Inter','Segoe UI',sans-serif; }
    .category-filter:first-child { border-left:0; }
    .category-filter[aria-pressed="true"] { background:#18181b; color:#fff; }
    .announcement-main { min-width:0; border:1px solid var(--ann-border); border-radius:8px; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .announcement-list { margin:0; padding:0; list-style:none; }
    .announcement-item { display:flex; gap:14px; padding:16px; border-bottom:1px solid var(--ann-border); font-family:'Inter','Segoe UI',sans-serif; }
    .announcement-item[hidden], .announcement-empty[hidden] { display:none; }
    .announcement-item:last-child { border-bottom:0; }
    .announcement-date { width:48px; height:48px; flex:0 0 48px; display:flex; flex-direction:column; align-items:center; justify-content:center; border:1px solid var(--ann-border); border-radius:6px; }
    .announcement-date-day { font-size:18px; font-weight:600; line-height:1; }
    .announcement-date-month { margin-top:3px; color:var(--ann-muted); font-size:10px; font-weight:500; text-transform:uppercase; line-height:1; }
    .announcement-body { min-width:0; flex:1; }
    .announcement-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .announcement-title { margin:0; color:var(--ann-ink); font-size:14px; font-weight:600; line-height:1.45; }
    .announcement-category { flex:0 0 auto; padding:2px 7px; border:1px solid var(--ann-border); border-radius:4px; color:#52525b; font-size:12px; font-weight:500; }
    .announcement-meta { display:flex; flex-wrap:wrap; gap:6px 12px; margin-top:5px; color:var(--ann-muted); font-size:13px; }
    .announcement-meta span { display:inline-flex; align-items:center; gap:5px; }
    .announcement-content { margin:9px 0 0; color:#52525b; font-size:14px; line-height:1.55; white-space:pre-line; }
    .announcement-link { display:inline-flex; align-items:center; gap:6px; margin-top:8px; color:var(--ann-green); font-size:13px; font-weight:600; text-decoration:none; }
    .announcement-link:hover { text-decoration:underline; }
    .announcement-empty { padding:36px 18px; color:var(--ann-muted); font-size:13px; text-align:center; }
    .event-details-button { min-height:32px; margin-top:10px; padding:0 11px; border:1px solid #d4d4d8; border-radius:6px; background:#fff; color:#18181b; font:500 13px 'Inter','Segoe UI',sans-serif; cursor:pointer; }
    .event-details-button:hover { background:#f4f4f5; }
    .event-status-pill { display:inline-flex; align-items:center; padding:2px 7px; border:1px solid var(--ann-border); border-radius:999px; color:#52525b; font-size:11px; font-weight:600; }
    .event-status-pill.confirmed { border-color:#0E8131; background:#0E8131; color:#fff; }
    .event-status-pill.changes_requested { border-color:#fed7aa; background:#fff7ed; color:#c2410c; }
    .event-dialog-overlay { position:fixed; inset:0; z-index:1000; display:none; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.55); }
    .event-dialog-overlay.open { display:flex; }
    .event-dialog { width:min(100%,560px); max-height:min(90vh,760px); display:flex; flex-direction:column; overflow:hidden; border:1px solid var(--ann-border); border-radius:8px; background:#fff; box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1); font-family:'Inter','Segoe UI',sans-serif; }
    .event-dialog-header { position:relative; flex:0 0 auto; padding:24px 56px 16px 24px; }
    .event-dialog-title { margin:0; color:#18181b; font-family:inherit; font-size:18px; font-weight:600; line-height:1.3; letter-spacing:-.025em; }
    .event-dialog-description { margin:6px 0 0; color:var(--ann-muted); font-family:inherit; font-size:14px; line-height:1.5; }
    .event-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:4px; background:transparent; color:#71717a; cursor:pointer; }
    .event-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    .event-dialog-content { min-height:0; overflow-y:auto; padding:0 24px 8px; }
    .event-dialog-footer { flex:0 0 auto; display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; padding:16px 24px 24px; border-top:1px solid var(--ann-border); }
    .event-dialog-button { min-height:36px; padding:0 16px; border:1px solid var(--ann-border); border-radius:6px; background:#fff; color:#18181b; font:500 14px/1 'Inter','Segoe UI',sans-serif; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .event-dialog-button:hover:not(:disabled) { background:#f4f4f5; }
    .event-dialog-button-primary { border-color:#18181b; background:#18181b; color:#fafafa; }
    .event-dialog-button-primary:hover:not(:disabled) { background:#27272a; }
    .event-dialog-button:disabled { cursor:wait; opacity:.6; }
    .event-detail-section { padding:16px 0; border-bottom:1px solid var(--ann-border); font-family:'Inter','Segoe UI',sans-serif; }
    .event-detail-section:last-child { border-bottom:0; }
    .event-detail-heading { margin:0 0 12px; color:#18181b; font-family:inherit; font-size:14px; font-weight:600; line-height:1.4; }
    .event-detail-help { margin:-6px 0 12px; color:var(--ann-muted); font-family:inherit; font-size:13px; line-height:1.5; }
    .event-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .event-detail-label { display:block; color:var(--ann-muted); font-family:inherit; font-size:13px; font-weight:600; }
    .event-detail-value { display:block; margin-top:2px; color:#18181b; font-family:inherit; font-size:14px; font-weight:400; overflow-wrap:anywhere; }
    .event-checklist { display:grid; gap:9px; margin:0; padding:0; list-style:none; }
    .event-checklist label { display:flex; align-items:flex-start; gap:9px; color:#3f3f46; font-family:inherit; font-size:14px; line-height:1.5; }
    .event-checklist input { flex:0 0 auto; margin:3px 0 0; accent-color:#15803d; }
    .event-required { color:#dc2626; font-size:11px; font-weight:600; }
    .event-response-note { width:100%; min-height:82px; margin-top:10px; padding:9px 11px; border:1px solid var(--ann-border); border-radius:6px; resize:vertical; color:#18181b; font:400 14px/1.5 'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .event-response-note:focus { outline:none; border-color:#a1a1aa; box-shadow:0 0 0 3px rgba(161,161,170,.18); }
    .event-response-feedback { margin:12px 0 0; color:var(--ann-muted); font-family:'Inter','Segoe UI',sans-serif; font-size:13px; line-height:1.5; }
    .notif-panel { position:absolute; top:calc(100% + 8px); right:0; z-index:100; width:380px; display:none; overflow:hidden; border:1px solid #e2e8f0; border-radius:8px; background:#fff; box-shadow:0 8px 32px rgba(0,0,0,.12); }
    .notif-panel.open { display:block; }
    .notif-panel-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #e2e8f0; }
    .notif-panel-header h4 { margin:0; font-size:13px; font-weight:600; }
    .notif-mark-all { border:0; background:transparent; color:var(--ann-green); cursor:pointer; font:500 12px 'Inter','Segoe UI',sans-serif; }
    .notif-tabs { display:flex; gap:4px; padding:8px 12px; border-bottom:1px solid #e2e8f0; }
    .notif-tab { padding:5px 8px; border:0; border-radius:4px; background:transparent; color:#52525b; font:500 12px 'Inter','Segoe UI',sans-serif; cursor:pointer; }
    .notif-tab.active { background:#f4f4f5; color:#18181b; }
    .notif-tab-count { margin-left:4px; }
    .notif-feedback { padding:0 12px; color:var(--ann-muted); font-size:12px; }
    .notif-panel-body { max-height:360px; overflow-y:auto; }
    @media (max-width:1024px) {
      .announcement-toolbar { align-items:stretch; flex-direction:column; }
      .announcement-search-controls { width:100%; }
      .category-filters { align-self:flex-start; max-width:100%; }
    }
    @media (max-width:768px) {
      body.sb2-active .app-wrapper { padding-left:0; }
      .topbar-toggle { display:flex; }
    }
    @media (max-width:600px) {
      .topbar { height:60px; padding:0 14px; }
      .topbar-right { gap:6px; }
      .user-meta { display:none; }
      .dashboard-content { padding:18px 14px 32px; }
      .announcement-toolbar { align-items:stretch; flex-direction:column; }
      .announcement-search-controls { width:100%; flex-direction:column; align-items:stretch; }
      .announcement-search-wrap { width:auto; }
      .announcement-filter-wrap { flex:0 0 auto; width:100%; }
      .category-filters { align-self:stretch; max-width:100%; }
      .category-filter { flex:1 1 auto; padding-inline:8px; white-space:normal; line-height:1.25; }
      .announcement-title-row { flex-direction:column; gap:5px; }
      .event-detail-grid { grid-template-columns:1fr; }
      .event-dialog-overlay { align-items:stretch; padding:0; }
      .event-dialog { width:100%; height:100vh; height:100dvh; max-height:100vh; max-height:100dvh; border-radius:0; }
      .event-dialog-header { padding:20px 52px 14px 20px; }
      .event-dialog-content { padding:0 20px 8px; }
      .event-dialog-footer { padding:14px 20px 20px; }
      .event-dialog-footer { flex-direction:column-reverse; }
      .event-dialog-footer .event-dialog-button { width:100%; }
      .notif-panel { position:fixed; top:60px; right:8px; width:min(380px,calc(100vw - 16px)); }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>
<div class="app-wrapper">
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
        <span class="breadcrumb-current">Announcements</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="notif-dropdown" id="notifDropdown">
        <button class="notif-btn" id="notif-btn" type="button" title="<?php echo $unreadNotifs; ?> unread notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
          data-csrf="<?php echo htmlspecialchars(function_exists('csrfToken') ? csrfToken() : '', ENT_QUOTES, 'UTF-8'); ?>"
          data-action-url="includes/staff-notification-action.php?portal=barangay"
          data-load-url="get-notifications.php?limit=30" data-app-base-url="">
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
          <div class="notif-panel-body" id="notifList"><div class="notif-empty">Loading notifications…</div></div>
        </div>
      </div>
      <div class="user-profile">
        <div class="user-avatar">
          <?php if ($avatarUrl): ?><img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><i class="fas fa-user" aria-hidden="true"></i><?php endif; ?>
        </div>
        <div class="user-meta"><span class="user-name"><?php echo htmlspecialchars($barangayName); ?></span><span class="user-role">Barangay Officer</span></div>
      </div>
    </div>
  </header>

  <main class="dashboard-content">
    <div class="announcement-toolbar" aria-label="Announcement search and filters">
      <div class="announcement-search-controls">
        <label class="announcement-search-wrap">
          <svg class="announcement-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
          <input class="announcement-search" type="search" id="announcementSearch" placeholder="Search announcements..." aria-label="Search announcements">
        </label>
        <label class="announcement-filter-wrap">
          <select class="announcement-filter" id="announcementCategoryFilter" aria-label="Filter announcements by category">
            <option value="all">All categories</option>
            <option value="vaccination">Vaccination</option>
            <option value="kapon">Kapon</option>
            <option value="deworming">Deworming</option>
            <option value="meeting">Meeting</option>
            <option value="advisory">Advisory</option>
          </select>
          <svg class="announcement-filter-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
        </label>
      </div>
      <div class="category-filters" role="group" aria-label="Filter announcement category">
        <button class="category-filter" type="button" data-category="all" aria-pressed="true">All</button>
        <button class="category-filter" type="button" data-category="announcement" aria-pressed="false">Announcements</button>
        <button class="category-filter" type="button" data-category="advisory" aria-pressed="false">Advisories</button>
      </div>
    </div>

    <section class="announcement-main" aria-label="Published announcements">
      <?php if ($announcements): ?>
        <ul class="announcement-list" id="announcementList">
          <?php foreach ($announcements as $announcement): ?>
              <?php
                $announcementDate = barangayAnnouncementDate($announcement);
                $announcementCalendarDate = barangayAnnouncementCalendarDate($announcement);
                $category = in_array($announcement['category'], ['announcement', 'advisory'], true) ? $announcement['category'] : 'announcement';
                $isEvent = (int)($announcement['is_event'] ?? 0) === 1;
                $eventResponseStatus = (string)($announcement['event_response_status'] ?? 'pending');
                $eventStatusText = $eventResponseStatus === 'confirmed' ? 'Preparedness confirmed'
                    : ($eventResponseStatus === 'changes_requested' ? 'Changes requested' : 'Response pending');
                $searchText = strtolower($announcement['title'] . ' ' . $announcement['content'] . ' ' . $announcement['location'] . ' ' . $category);
                $filterText = strtolower($announcement['title'] . ' ' . $announcement['content'] . ' ' . $announcement['subject']);
                $filterCategories = [];
                if ($category === 'advisory') {
                    $filterCategories[] = 'advisory';
                } else {
                    if ($announcement['subject'] === 'free_rabies' || preg_match('/\b(vaccin\w*|rabies)\b/i', $filterText)) {
                        $filterCategories[] = 'vaccination';
                    }
                    if (preg_match('/\bkapon\b/i', $filterText)) {
                        $filterCategories[] = 'kapon';
                    }
                    if (preg_match('/\bdeworm\w*\b/i', $filterText)) {
                        $filterCategories[] = 'deworming';
                    }
                    if (preg_match('/\bmeeting\b/i', $filterText)) {
                        $filterCategories[] = 'meeting';
                    }
                }
                $announcementLink = trim((string)($announcement['link_url'] ?? ''));
                $linkScheme = parse_url($announcementLink, PHP_URL_SCHEME);
                $safeAnnouncementLink = $linkScheme !== false && $linkScheme !== null
                    ? in_array(strtolower($linkScheme), ['http', 'https'], true) && filter_var($announcementLink, FILTER_VALIDATE_URL) !== false
                    : strncmp($announcementLink, '//', 2) !== 0 && strpos($announcementLink, '\\') === false;
              ?>
            <li class="announcement-item" id="announcement-<?php echo (int)$announcement['announcement_id']; ?>" data-category="<?php echo htmlspecialchars($category); ?>" data-filter-categories="<?php echo htmlspecialchars(implode(' ', $filterCategories)); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                <time class="announcement-date" datetime="<?php echo htmlspecialchars($announcementCalendarDate->format(DateTimeInterface::ATOM)); ?>">
                  <span class="announcement-date-day"><?php echo $announcementCalendarDate->format('j'); ?></span>
                  <span class="announcement-date-month"><?php echo htmlspecialchars($announcementCalendarDate->format('M')); ?></span>
                </time>
                <div class="announcement-body">
                  <div class="announcement-title-row">
                    <h2 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h2>
                    <span class="announcement-category"><?php echo $isEvent ? 'Event' : htmlspecialchars(ucfirst($category)); ?></span>
                  </div>
                  <div class="announcement-meta">
                    <span><i class="far fa-clock" aria-hidden="true"></i> Published <?php echo htmlspecialchars($announcementDate->format('M j, Y')); ?></span>
                    <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars($announcement['location'] ?: 'Brgy. ' . $barangayName); ?></span>
                    <?php if ($isEvent): ?>
                      <span><i class="far fa-calendar" aria-hidden="true"></i> Event <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$announcement['event_date']))); ?><?php if (!empty($announcement['event_end_date']) && $announcement['event_end_date'] !== $announcement['event_date']): ?>–<?php echo htmlspecialchars(date('M j, Y', strtotime((string)$announcement['event_end_date']))); ?><?php endif; ?>, <?php echo htmlspecialchars(date('g:i A', strtotime((string)$announcement['event_start_time']))); ?>–<?php echo htmlspecialchars(date('g:i A', strtotime((string)$announcement['event_end_time']))); ?></span>
                      <span class="event-status-pill <?php echo htmlspecialchars($eventResponseStatus); ?>"><?php echo htmlspecialchars($eventStatusText); ?></span>
                    <?php endif; ?>
                  </div>
                  <p class="announcement-content"><?php echo htmlspecialchars(trim(strip_tags((string)$announcement['content']))); ?></p>
                  <?php if ($announcementLink !== '' && $safeAnnouncementLink): ?>
                    <a class="announcement-link" href="<?php echo htmlspecialchars($announcementLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">More information <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                  <?php endif; ?>
                  <?php if ($isEvent): ?>
                    <button class="event-details-button" type="button" onclick="openAnnouncementEvent(<?php echo (int)$announcement['announcement_id']; ?>)">View event details and preparedness</button>
                  <?php endif; ?>
                </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="announcement-empty" id="announcementNoMatches" hidden>No announcements match your search.</p>
      <?php else: ?>
        <div class="announcement-empty">There are no published announcements for Brgy. <?php echo htmlspecialchars($barangayName); ?> yet.</div>
      <?php endif; ?>
    </section>
    <div class="event-dialog-overlay" id="eventDrawerOverlay" aria-hidden="true">
      <section class="event-dialog" role="dialog" aria-modal="true" aria-labelledby="eventDrawerTitle" aria-describedby="eventDrawerDescription">
        <header class="event-dialog-header">
          <div>
            <h2 class="event-dialog-title" id="eventDrawerTitle">Event details</h2>
            <p class="event-dialog-description" id="eventDrawerDescription">Review the schedule and confirm your barangay's preparedness.</p>
          </div>
          <button class="event-dialog-close" id="eventDialogClose" type="button" onclick="closeAnnouncementEvent()" aria-label="Close event details"><i class="fas fa-xmark"></i></button>
        </header>
        <div class="event-dialog-content" id="eventDrawerContent"></div>
        <footer class="event-dialog-footer">
          <button class="event-dialog-button" type="button" onclick="closeAnnouncementEvent()">Close</button>
          <button class="event-dialog-button" id="eventRequestChangesButton" type="button" onclick="submitActiveAnnouncementEventResponse('changes_requested')">Request changes</button>
          <button class="event-dialog-button event-dialog-button-primary" id="eventConfirmButton" type="button" onclick="submitActiveAnnouncementEventResponse('confirmed')">Confirm preparedness</button>
        </footer>
      </section>
    </div>
  </main>
</div>

<script>
  const BARANGAY_EVENT_DATA = <?php echo json_encode($announcements, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const BARANGAY_PORTAL_NAME = <?php echo json_encode($barangayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const EVENT_RESPONSE_CSRF = <?php echo json_encode($eventCsrfToken); ?>;
  var activeAnnouncementEventId = 0;
  var eventDialogReturnFocus = null;
  function escapeEventHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
    });
  }
  function formatEventDate(value) {
    if (!value) return '—';
    var date = new Date(value + 'T00:00:00');
    return isNaN(date) ? escapeEventHtml(value) : date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }
  function formatEventTime(value) {
    if (!value) return '—';
    var date = new Date('1970-01-01T' + value);
    return isNaN(date) ? escapeEventHtml(value) : date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  function formatEventDateTime(value) {
    if (!value) return '—';
    var date = new Date(String(value).replace(' ', 'T'));
    return isNaN(date) ? escapeEventHtml(value) : date.toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function openAnnouncementEvent(announcementId) {
    var event = BARANGAY_EVENT_DATA.find(function(item) {
      return Number(item.announcement_id) === Number(announcementId) && Number(item.is_event) === 1;
    });
    if (!event) return;
    activeAnnouncementEventId = Number(event.announcement_id);
    eventDialogReturnFocus = document.activeElement;
    var responseStatus = event.event_response_status || 'pending';
    var deadline = event.response_deadline ? new Date(String(event.response_deadline).replace(' ', 'T')) : null;
    var isClosed = deadline && !isNaN(deadline) && deadline.getTime() < Date.now();
    var requirements = event.event_requirements || [];
    var requirementHtml = requirements.map(function(requirement) {
      return '<li><label><input type="checkbox" class="event-ready-checkbox" value="' + Number(requirement.requirement_id) + '"' +
        (requirement.is_ready ? ' checked' : '') + (isClosed ? ' disabled' : '') + '>' +
        '<span>' + escapeEventHtml(requirement.requirement_label) +
        (requirement.quantity ? ' <span style="color:#71717a;">× ' + Number(requirement.quantity) + '</span>' : '') +
        (Number(requirement.is_required) === 1 ? ' <span class="event-required">Required</span>' : '') + '</span></label></li>';
    }).join('');
    var statusText = responseStatus === 'confirmed' ? 'Preparedness confirmed'
      : responseStatus === 'changes_requested' ? 'Changes requested' : 'Awaiting your response';
    var statusColor = responseStatus === 'confirmed' ? '#15803d'
      : responseStatus === 'changes_requested' ? '#c2410c' : '#71717a';
    var venueProviders = { dcvs_office: 'DCVS Office', barangay_venue: 'Barangay venue', other: 'Other' };
    document.getElementById('eventDrawerTitle').textContent = event.title || 'Event details';
    document.getElementById('eventConfirmButton').disabled = !!isClosed || requirements.length === 0;
    document.getElementById('eventRequestChangesButton').disabled = !!isClosed;
    document.getElementById('eventDrawerContent').innerHTML =
      '<section class="event-detail-section"><h3 class="event-detail-heading">When and where</h3>' +
        '<p class="event-detail-help">Event date, time, venue, and response deadline.</p><div class="event-detail-grid">' +
        '<div><span class="event-detail-label">' + (event.event_end_date && event.event_end_date !== event.event_date ? 'Event dates' : 'Event date') + '</span><span class="event-detail-value">' + formatEventDate(event.event_date) +
          (event.event_end_date && event.event_end_date !== event.event_date ? ' – ' + formatEventDate(event.event_end_date) : '') + '</span></div>' +
        '<div><span class="event-detail-label">Time</span><span class="event-detail-value">' + formatEventTime(event.event_start_time) + '–' + formatEventTime(event.event_end_time) + '</span></div>' +
        '<div><span class="event-detail-label">Venue provided by</span><span class="event-detail-value">' + escapeEventHtml(venueProviders[event.venue_provider] || '—') + '</span></div>' +
        '<div><span class="event-detail-label">Venue</span><span class="event-detail-value">' + escapeEventHtml(event.venue_name || '—') + '</span></div>' +
        '<div><span class="event-detail-label">Barangay response deadline</span><span class="event-detail-value">' + formatEventDateTime(event.response_deadline) + '</span></div>' +
        '<div><span class="event-detail-label">Your response</span><span class="event-detail-value">' +
          (responseStatus === 'confirmed'
            ? '<span class="event-status-pill confirmed">' + statusText + '</span>'
            : '<span style="color:' + statusColor + ';">' + statusText + '</span>') +
        '</span></div>' +
      '</div></section>' +
      '<section class="event-detail-section"><h3 class="event-detail-heading">Who it applies to</h3>' +
        '<p class="event-detail-help">This event announcement is targeted to your barangay.</p>' +
        '<div><span class="event-detail-label">Barangay audience</span><span class="event-detail-value">Brgy. ' + escapeEventHtml(BARANGAY_PORTAL_NAME) + '</span></div>' +
      '</section>' +
      '<section class="event-detail-section"><h3 class="event-detail-heading">Barangay preparedness requirements</h3>' +
        '<p class="event-detail-help">Review the requirements and confirm your barangay\'s readiness.</p>' +
      (requirementHtml ? '<ul class="event-checklist">' + requirementHtml + '</ul>' : '<p class="event-response-feedback">No preparedness requirements were listed.</p>') +
      '<textarea class="event-response-note" id="eventResponseNote" maxlength="4000" placeholder="Add a note for DCVS. A note is required when requesting changes.">' +
        escapeEventHtml(event.event_response_note || '') + '</textarea>' +
      '<p class="event-response-feedback" id="eventResponseFeedback">' +
        (isClosed ? 'The response deadline has passed.' : 'All required items must be checked before you can confirm.') + '</p>' +
      '</section>';
    var overlay = document.getElementById('eventDrawerOverlay');
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', closeEventDrawerOnEscape);
    document.getElementById('eventDialogClose').focus();
  }
  function closeEventDrawerOnEscape(event) {
    if (event.key === 'Escape') closeAnnouncementEvent();
  }
  function closeAnnouncementEvent() {
    var overlay = document.getElementById('eventDrawerOverlay');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', closeEventDrawerOnEscape);
    activeAnnouncementEventId = 0;
    if (eventDialogReturnFocus && eventDialogReturnFocus.focus) {
      eventDialogReturnFocus.focus();
    }
    eventDialogReturnFocus = null;
  }
  function submitActiveAnnouncementEventResponse(decision) {
    if (activeAnnouncementEventId > 0) {
      submitAnnouncementEventResponse(activeAnnouncementEventId, decision);
    }
  }
  function submitAnnouncementEventResponse(announcementId, decision) {
    var feedback = document.getElementById('eventResponseFeedback');
    var checks = Array.prototype.slice.call(document.querySelectorAll('.event-ready-checkbox'));
    var readyIds = checks.filter(function(check) { return check.checked; }).map(function(check) { return check.value; });
    var note = document.getElementById('eventResponseNote').value.trim();
    if (decision === 'confirmed' && checks.some(function(check) {
      return check.closest('label').querySelector('.event-required') && !check.checked;
    })) {
      feedback.textContent = 'Complete every required item before confirming.';
      feedback.style.color = '#dc2626';
      return;
    }
    if (decision === 'changes_requested' && !note) {
      feedback.textContent = 'Add a note describing the requested changes.';
      feedback.style.color = '#dc2626';
      document.getElementById('eventResponseNote').focus();
      return;
    }
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.event-dialog-footer button'));
    var buttonsInitiallyDisabled = buttons.map(function(button) { return button.disabled; });
    buttons.forEach(function(button) { button.disabled = true; });
    feedback.textContent = 'Saving your response…';
    feedback.style.color = '';
    var body = new URLSearchParams();
    body.append('csrf_token', EVENT_RESPONSE_CSRF);
    body.append('announcement_id', String(announcementId));
    body.append('decision', decision);
    body.append('response_note', note);
    readyIds.forEach(function(id) { body.append('ready_requirements[]', id); });
    var responseUrl = new URL('announcement-event-response.php', window.location.href);
    fetch(responseUrl.toString(), { method: 'POST', body: body })
      .then(function(response) {
        return response.json().then(function(data) {
          if (!response.ok || !data.success) throw new Error(data.message || 'Could not save your response.');
          return data;
        });
      })
      .then(function() { window.location.reload(); })
      .catch(function(error) {
        feedback.textContent = error.message || 'Could not save your response. Please try again.';
        feedback.style.color = '#dc2626';
        buttons.forEach(function(button, index) { button.disabled = buttonsInitiallyDisabled[index]; });
      });
  }
  document.getElementById('eventDrawerOverlay').addEventListener('click', function(event) {
    if (event.target === this) closeAnnouncementEvent();
  });
  (function() {
    var selectedCategory = 'all';
    var search = document.getElementById('announcementSearch');
    var categoryFilter = document.getElementById('announcementCategoryFilter');
    var items = Array.prototype.slice.call(document.querySelectorAll('.announcement-item'));
    var noMatches = document.getElementById('announcementNoMatches');
    function filterAnnouncements() {
      var query = (search.value || '').trim().toLowerCase();
      var selectedFilter = categoryFilter.value;
      var visible = 0;
      items.forEach(function(item) {
        var categoryMatch = selectedCategory === 'all' || item.dataset.category === selectedCategory;
        var typeMatch = selectedFilter === 'all' || (item.dataset.filterCategories || '').split(' ').indexOf(selectedFilter) !== -1;
        var textMatch = !query || item.dataset.search.indexOf(query) !== -1;
        item.hidden = !(categoryMatch && typeMatch && textMatch);
        if (!item.hidden) visible++;
      });
      if (noMatches) noMatches.hidden = visible !== 0;
    }
    if (search) search.addEventListener('input', filterAnnouncements);
    if (categoryFilter) categoryFilter.addEventListener('change', filterAnnouncements);
    document.querySelectorAll('.category-filter').forEach(function(button) {
      button.addEventListener('click', function() {
        selectedCategory = button.dataset.category || 'all';
        document.querySelectorAll('.category-filter').forEach(function(filter) {
          filter.setAttribute('aria-pressed', filter === button ? 'true' : 'false');
        });
        filterAnnouncements();
      });
    });
  })();
</script>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/notification-panel.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/notification-panel.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
