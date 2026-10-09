<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/waitlist.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

dcvsExpireWaitlistClaims($conn);
$activePage = 'waitlist';
$pageTitle = 'Waitlist';
$statuses = ['waiting', 'notified', 'booked', 'expired', 'cancelled'];
$services = ['adoption', 'kapon', 'vaccination', 'checkup', 'deworming'];
$statusFilter = $_GET['status'] ?? 'waiting';
$serviceFilter = $_GET['service'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 15, 20, 30, 50], true)) $perPage = 10;

if (!in_array($statusFilter, array_merge(['all'], $statuses), true)) $statusFilter = 'waiting';
if (!in_array($serviceFilter, array_merge([''], $services), true)) $serviceFilter = '';
if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) $dateFilter = '';

$where = [];
if ($statusFilter !== 'all') $where[] = "w.status = '" . $conn->real_escape_string($statusFilter) . "'";
if ($serviceFilter !== '') $where[] = "w.appointment_type = '" . $conn->real_escape_string($serviceFilter) . "'";
if ($dateFilter !== '') $where[] = "w.preferred_date = '" . $conn->real_escape_string($dateFilter) . "'";
if ($search !== '') {
    $searchLike = '%' . $conn->real_escape_string($search) . '%';
    $where[] = "(u.full_name LIKE '$searchLike' OR u.email LIKE '$searchLike' OR p.pet_name LIKE '$searchLike')";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$kpis = ['waiting' => 0, 'notified' => 0];
$totalWaitlistEntries = 0;
$totalResult = $conn->query("SELECT COUNT(*) AS total FROM appointment_waitlist");
if ($totalResult) {
    $totalWaitlistEntries = (int)$totalResult->fetch_assoc()['total'];
} else {
    error_log('Failed to load total waitlist count: ' . $conn->error);
}
$kpiResult = $conn->query("SELECT status, COUNT(*) AS total FROM appointment_waitlist WHERE status IN ('waiting','notified') GROUP BY status");
if ($kpiResult) {
    while ($row = $kpiResult->fetch_assoc()) $kpis[$row['status']] = (int)$row['total'];
} else {
    error_log('Failed to load waitlist summary counts: ' . $conn->error);
}
$demandDate = null;
$demandCount = 0;
$demandResult = $conn->query("SELECT preferred_date, COUNT(*) AS total FROM appointment_waitlist WHERE status = 'waiting' GROUP BY preferred_date ORDER BY total DESC, preferred_date ASC LIMIT 1");
if ($demandResult && ($demand = $demandResult->fetch_assoc())) {
    $demandDate = $demand['preferred_date'];
    $demandCount = (int)$demand['total'];
} elseif (!$demandResult) {
    error_log('Failed to load waitlist demand summary: ' . $conn->error);
}
$countResult = $conn->query("SELECT COUNT(*) AS total FROM appointment_waitlist w
    JOIN users u ON u.user_id = w.user_id
    LEFT JOIN pets p ON p.pet_id = w.pet_id" . $whereSql);
$totalRows = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
if (!$countResult) error_log('Failed to count filtered waitlist entries: ' . $conn->error);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$rows = [];
$result = $conn->query("SELECT w.waitlist_id, w.user_id, w.pet_id, w.appointment_type, w.urgency_level, w.preferred_date,
        w.preferred_time, w.status, w.created_at, w.notified_at, w.claim_expires_at, u.full_name, u.avatar_url, u.email, u.phone,
        b.barangay_name, p.pet_name, p.photo_url
    FROM appointment_waitlist w
    JOIN users u ON u.user_id = w.user_id
    LEFT JOIN pets p ON p.pet_id = w.pet_id
    LEFT JOIN barangays b ON b.barangay_id = u.barangay_id
    " . $whereSql . "
    ORDER BY FIELD(w.status, 'waiting','notified','booked','expired','cancelled'), w.created_at ASC
    LIMIT $perPage OFFSET $offset");
if ($result) {
    while ($row = $result->fetch_assoc()) $rows[] = $row;
} else {
    error_log('Failed to load waitlist entries: ' . $conn->error);
}
$queryParams = $_GET;
unset($queryParams['page']);
$paginationBase = 'staff-waitlist.php?' . http_build_query($queryParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Waitlist — DCVS Staff</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/responsive.css">
  <style>
    body.staff-theme { background:#fff; }
    .waitlist-page { padding-bottom:32px; font-family:'Inter','Segoe UI',sans-serif; color:#18181b; }
    .waitlist-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; font-family:'Inter','Segoe UI',sans-serif; }
    .waitlist-kpi { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:84px; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); text-align:center; }
    .waitlist-kpi:hover { border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }
    .waitlist-kpi-label { margin:0 0 6px; color:#18181b; font-size:14px; font-weight:500; text-transform:uppercase; }
    .waitlist-kpi-value { margin:0 0 6px; color:#18181b; font-size:26px; font-weight:600; letter-spacing:-.02em; line-height:1; }
    .waitlist-kpi-sub { margin:0; color:#71717a; font-size:14px; font-weight:400; }
    .waitlist-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
    .waitlist-toolbar input,.waitlist-toolbar select { height:36px; box-sizing:border-box; padding:0 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:14px 'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .waitlist-toolbar input[name=search] { flex:0 1 280px; width:280px; min-width:180px; }
    .waitlist-toolbar select { min-width:150px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; appearance:none; -webkit-appearance:none; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; }
    .waitlist-toolbar select:focus,.waitlist-toolbar input:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .waitlist-date-filter { position:relative; display:inline-flex; }
    .waitlist-date-filter input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
    .waitlist-toolbar button,.waitlist-action { height:36px; padding:0 12px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:500 14px 'Inter','Segoe UI',sans-serif; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; white-space:nowrap; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .waitlist-toolbar button:hover,.waitlist-action:hover { background:#f4f4f5; }
    .waitlist-toolbar .waitlist-date-button { width:36px; min-width:36px; padding:0; justify-content:center; }
    .waitlist-toolbar .waitlist-date-button.has-date { border-color:#18181b; }
    .waitlist-date-clear { width:30px; min-width:30px; padding:0 !important; margin-left:-7px; }
    .waitlist-card { overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    .waitlist-table-wrap { min-height:560px; overflow-x:auto; }
    .waitlist-table { width:100%; min-width:1180px; border-collapse:collapse; table-layout:auto; }
    .waitlist-table th { height:42px; padding:0 12px; border-bottom:1px solid #e4e4e7; background:#fafafa; color:#71717a; font-size:14px; font-weight:500; text-align:left; white-space:nowrap; }
    .waitlist-table td { padding:12px; border-bottom:1px solid #e4e4e7; font-size:14px; vertical-align:middle; }
    .waitlist-person-cell { display:flex; align-items:center; gap:10px; min-width:0; }
    .waitlist-person-avatar { width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 36px; overflow:hidden; border-radius:50%; background:#e4e4e7; color:#71717a; font-size:13px; }
    .waitlist-person-avatar img { width:100%; height:100%; object-fit:cover; }
    .waitlist-person-name { min-width:0; overflow-wrap:anywhere; }
    .waitlist-table tr:last-child td { border-bottom:0; }
    .waitlist-subtext { margin-top:3px; color:#71717a; font-size:12px; overflow-wrap:anywhere; }
    .waitlist-pill { display:inline-flex; align-items:center; padding:4px 10px; border:1px solid transparent; border-radius:4px; font-size:12px; font-weight:400; line-height:1.2; background:#f4f4f5; color:#52525b; }
    .waitlist-pill.pending { background:#fff4cc; border-color:#ffda66; color:#a84b00; }
    .waitlist-pill.waiting { background:#fef3c7; color:#92400e; }
    .waitlist-pill.notified { background:#dbeafe; color:#1e40af; }
    .waitlist-pill.booked { background:#dcfce7; color:#166534; }
    .waitlist-row-menu { position:fixed!important; z-index:9999!important; display:none; min-width:190px; padding:6px; border:1px solid #e6e9ee; border-radius:10px; background:#fff; box-shadow:0 4px 14px rgba(15,23,32,.06); }
    .waitlist-row-menu.open { display:block!important; }
    .waitlist-row-menu .row-menu-item { display:flex!important; align-items:center!important; gap:10px!important; width:100%!important; padding:9px 10px!important; border:0!important; border-radius:8px!important; background:none!important; color:#334155!important; font-family:'Inter','Segoe UI',system-ui,sans-serif!important; font-size:13px!important; font-weight:500!important; text-align:left!important; line-height:1.4!important; cursor:pointer!important; text-decoration:none!important; box-sizing:border-box; }
    .waitlist-row-menu .row-menu-item:hover { background:#f8fafc!important; }
    .waitlist-row-menu .row-menu-item.danger { color:#dc2626!important; }
    .waitlist-row-menu .row-menu-item.danger:hover { background:#fef2f2!important; }
    .waitlist-row-menu .row-menu-item svg { flex-shrink:0!important; width:15px!important; height:15px!important; }
    .waitlist-row-actions { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border:0; border-radius:6px; background:transparent; color:#71717a; cursor:pointer; }
    .waitlist-row-actions:hover,.waitlist-row-actions[aria-expanded="true"] { background:#f4f4f5; color:#18181b; }
    .waitlist-row-actions svg { width:16px; height:16px; }
    .waitlist-confirm-overlay { position:fixed; inset:0; z-index:11000; display:none; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.5); box-sizing:border-box; }
    .waitlist-confirm-overlay.open { display:flex; }
    .waitlist-confirm-dialog { position:relative; width:100%; max-width:420px; padding:24px; display:flex; flex-direction:column; gap:16px; border:1px solid #e4e4e7; border-radius:8px; background:#fff; box-shadow:0 20px 25px rgba(15,23,32,.18); font-family:'Inter','Segoe UI',sans-serif; }
    .waitlist-confirm-title { margin:0; color:#18181b; font-size:18px; font-weight:600; line-height:1.2; letter-spacing:-.025em; }
    .waitlist-confirm-message { margin:0; color:#71717a; font-size:14px; line-height:1.5; }
    .waitlist-confirm-footer { display:flex; justify-content:flex-end; gap:8px; }
    .waitlist-confirm-button { height:36px; padding:0 14px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:500 14px 'Inter','Segoe UI',sans-serif; cursor:pointer; }
    .waitlist-confirm-button:hover { background:#f4f4f5; }
    .waitlist-confirm-button.primary { border-color:#18181b; background:#18181b; color:#fff; }
    .waitlist-confirm-button.primary:hover { background:#27272a; }
    .waitlist-confirm-button.danger { border-color:#dc2626; background:#dc2626; color:#fff; }
    .waitlist-confirm-button.danger:hover { background:#b91c1c; }
    .waitlist-confirm-button:disabled { opacity:.55; cursor:not-allowed; }
    .waitlist-empty { padding:42px 16px; text-align:center; color:#71717a; }
    .dt-pagination { display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; margin-top:8px; font-family:'Inter','Segoe UI',sans-serif; }
    .dt-pagination-right { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .dt-rows { display:flex; align-items:center; gap:8px; color:#71717a; font-size:14px; }
    .dt-rows select { height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font:14px 'Inter','Segoe UI',sans-serif; color:#18181b; }
    .dt-page-label { width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    .dt-page-btns { display:flex; align-items:center; gap:4px; }
    .dt-page-btn { height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .dt-page-btn:hover:not(:disabled) { background:#f4f4f5; }
    .dt-page-btn:disabled { opacity:.5; cursor:not-allowed; }
    @media(max-width:1180px) { .waitlist-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media(max-width:800px) { .waitlist-kpis { grid-template-columns:1fr 1fr; gap:10px; } .dt-pagination-right { justify-content:center; gap:12px; } }
    @media(max-width:480px) { .waitlist-kpis { grid-template-columns:1fr; } }
  </style>
</head>
<body class="staff-theme" data-portal="staff">
<?php include __DIR__ . '/../includes/staff-sidebar.php'; ?>
<main class="main-content">
  <div class="waitlist-page">
    <section class="waitlist-kpis" aria-label="Waitlist summary">
      <article class="waitlist-kpi"><p class="waitlist-kpi-label">Total Entries</p><p class="waitlist-kpi-value"><?php echo $totalWaitlistEntries; ?></p><p class="waitlist-kpi-sub">All waitlist requests</p></article>
      <article class="waitlist-kpi"><p class="waitlist-kpi-label">Pending</p><p class="waitlist-kpi-value"><?php echo $kpis['waiting']; ?></p><p class="waitlist-kpi-sub">Waiting for an open slot</p></article>
      <article class="waitlist-kpi"><p class="waitlist-kpi-label">Notified</p><p class="waitlist-kpi-value"><?php echo $kpis['notified']; ?></p><p class="waitlist-kpi-sub">Awaiting resident claim</p></article>
      <article class="waitlist-kpi"><p class="waitlist-kpi-label">Highest Demand Date</p><p class="waitlist-kpi-value" style="font-size:20px;"><?php echo $demandDate ? date('M d, Y', strtotime($demandDate)) : '—'; ?></p><p class="waitlist-kpi-sub"><?php echo $demandCount; ?> waiting resident<?php echo $demandCount === 1 ? '' : 's'; ?></p></article>
    </section>
    <form class="waitlist-toolbar" method="get" action="staff-waitlist.php" data-draft-ignore>
      <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search resident or pet" aria-label="Search resident or pet">
      <span class="waitlist-date-filter">
        <button type="button" class="waitlist-date-button <?php echo $dateFilter !== '' ? 'has-date' : ''; ?>" id="waitlistDateButton" aria-label="Filter by date" title="Filter by date">
          <i class="fas fa-filter" aria-hidden="true"></i>
        </button>
        <input type="date" name="date" id="waitlistDate" value="<?php echo htmlspecialchars($dateFilter); ?>" aria-label="Filter by preferred date">
      </span>
      <?php if ($dateFilter !== ''): ?><button type="button" class="waitlist-date-clear" id="clearWaitlistDate" aria-label="Clear date filter"><i class="fas fa-times"></i></button><?php endif; ?>
      <select name="service" aria-label="Filter by service" onchange="this.form.submit()">
        <option value="">All services</option>
        <?php foreach ($services as $service): ?><option value="<?php echo $service; ?>" <?php echo $serviceFilter === $service ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($service)); ?></option><?php endforeach; ?>
      </select>
      <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
        <?php foreach (array_merge(['all'], $statuses) as $status): ?><option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo $status === 'all' ? 'All statuses' : ($status === 'waiting' ? 'Pending' : htmlspecialchars(ucfirst($status))); ?></option><?php endforeach; ?>
      </select>
    </form>
    <section class="waitlist-card">
      <div class="waitlist-table-wrap">
        <table class="waitlist-table">
          <thead><tr><th>Resident</th><th>Pet Name</th><th>Contact</th><th>Barangay</th><th>Service</th><th>Preferred date</th><th>Time</th><th>Urgency</th><th>Date joined</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="waitlist-empty">No waitlist entries match these filters.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <tr>
              <td><div class="waitlist-person-cell"><span class="waitlist-person-avatar"><?php if (!empty($row['avatar_url'])): ?><img src="<?php echo htmlspecialchars($row['avatar_url']); ?>" alt=""><?php else: ?><i class="fas fa-user" aria-hidden="true"></i><?php endif; ?></span><span class="waitlist-person-name"><?php echo htmlspecialchars($row['full_name']); ?></span></div></td>
              <td><div class="waitlist-person-cell"><span class="waitlist-person-avatar"><?php if (!empty($row['photo_url'])): ?><img src="<?php echo htmlspecialchars($row['photo_url']); ?>" alt=""><?php else: ?><i class="fas fa-paw" aria-hidden="true"></i><?php endif; ?></span><span class="waitlist-person-name"><?php echo htmlspecialchars($row['pet_name'] ?: '-'); ?></span></div></td>
              <td><?php echo htmlspecialchars($row['email'] ?: '—'); ?><div class="waitlist-subtext"><?php echo htmlspecialchars($row['phone'] ?: '—'); ?></div></td>
              <td><?php echo htmlspecialchars($row['barangay_name'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars(ucfirst($row['appointment_type'])); ?></td>
              <td><?php echo date('M d, Y', strtotime($row['preferred_date'])); ?></td>
              <td><?php echo $row['preferred_time'] ? date('g:i A', strtotime($row['preferred_time'])) : '—'; ?></td>
              <td><?php echo htmlspecialchars(ucfirst($row['urgency_level'])); ?></td>
              <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
              <td><span class="waitlist-pill <?php echo $row['status'] === 'waiting' ? 'pending' : htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span><?php if ($row['status'] === 'notified' && $row['claim_expires_at']): ?><div class="waitlist-subtext">Claim by <?php echo date('M d, g:i A', strtotime($row['claim_expires_at'])); ?></div><?php endif; ?></td>
              <td><button type="button" class="waitlist-row-actions" title="More options" aria-label="More options" aria-expanded="false"
                data-waitlist-id="<?php echo (int)$row['waitlist_id']; ?>"
                data-waitlist-status="<?php echo htmlspecialchars($row['status']); ?>"
                onclick="toggleWaitlistMenu(event,<?php echo (int)$row['waitlist_id']; ?>)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
              </button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <div class="waitlist-row-menu" id="waitlistRowMenu" role="menu"></div>
    <div class="waitlist-confirm-overlay" id="waitlistConfirmOverlay" aria-hidden="true">
      <section class="waitlist-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="waitlistConfirmTitle" aria-describedby="waitlistConfirmMessage">
        <h2 class="waitlist-confirm-title" id="waitlistConfirmTitle"></h2>
        <p class="waitlist-confirm-message" id="waitlistConfirmMessage"></p>
        <div class="waitlist-confirm-footer">
          <button type="button" class="waitlist-confirm-button" id="waitlistConfirmCancel">Cancel</button>
          <button type="button" class="waitlist-confirm-button primary" id="waitlistConfirmContinue">Continue</button>
        </div>
      </section>
    </div>
    <div class="dt-pagination">
      <div class="dt-pagination-right">
        <div class="dt-rows"><span>Rows per page</span><select aria-label="Rows per page" onchange="waitlistSetPerPage(this.value)">
          <?php foreach ([10, 15, 20, 30, 50] as $pageSize): ?><option value="<?php echo $pageSize; ?>" <?php echo $perPage === $pageSize ? 'selected' : ''; ?>><?php echo $pageSize; ?></option><?php endforeach; ?>
        </select></div>
        <div class="dt-page-label">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
        <div class="dt-page-btns">
          <button type="button" class="dt-page-btn" aria-label="Go to first page" onclick="waitlistGoToPage(1)" <?php echo $page <= 1 ? 'disabled' : ''; ?>><i class="fas fa-angle-double-left"></i></button>
          <button type="button" class="dt-page-btn" aria-label="Go to previous page" onclick="waitlistGoToPage(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>><i class="fas fa-angle-left"></i></button>
          <button type="button" class="dt-page-btn" aria-label="Go to next page" onclick="waitlistGoToPage(<?php echo $page + 1; ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>><i class="fas fa-angle-right"></i></button>
          <button type="button" class="dt-page-btn" aria-label="Go to last page" onclick="waitlistGoToPage(<?php echo $totalPages; ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>><i class="fas fa-angle-double-right"></i></button>
        </div>
      </div>
    </div>
  </div>
</main>
<script>
var activeWaitlistMenuButton = null;
function toggleWaitlistMenu(event, waitlistId) {
  event.stopPropagation();
  var menu = document.getElementById('waitlistRowMenu');
  var button = event.currentTarget;
  if (activeWaitlistMenuButton === button && menu.classList.contains('open')) {
    closeWaitlistMenu();
    return;
  }
  closeWaitlistMenu();
  activeWaitlistMenuButton = button;
  var status = button.getAttribute('data-waitlist-status');
  var items = [];
  if (status === 'waiting') {
    items.push('<button type="button" class="row-menu-item" role="menuitem" data-waitlist-id="' + waitlistId + '" data-action="notify"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>Notify now</button>');
  }
  if (status === 'waiting' || status === 'notified') {
    items.push('<a class="row-menu-item" role="menuitem" data-waitlist-id="' + waitlistId + '" data-action="book" href="dashboard-staff.php?waitlist_booking=' + waitlistId + '#appointments"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Book for resident</a>');
    items.push('<button type="button" class="row-menu-item danger" role="menuitem" data-waitlist-id="' + waitlistId + '" data-action="cancel"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Remove from waitlist</button>');
  }
  if (!items.length) items.push('<span class="row-menu-item" aria-disabled="true">No actions available</span>');
  menu.innerHTML = items.join('');
  menu.classList.add('open');
  button.setAttribute('aria-expanded', 'true');
  var rect = button.getBoundingClientRect();
  var menuWidth = menu.offsetWidth;
  var menuHeight = menu.offsetHeight;
  var left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8));
  var top = rect.bottom + menuHeight > window.innerHeight - 8 ? rect.top - menuHeight - 4 : rect.bottom + 4;
  menu.style.left = left + 'px';
  menu.style.top = Math.max(8, top) + 'px';
}
function closeWaitlistMenu() {
  var menu = document.getElementById('waitlistRowMenu');
  if (menu) menu.classList.remove('open');
  if (activeWaitlistMenuButton) activeWaitlistMenuButton.setAttribute('aria-expanded', 'false');
  activeWaitlistMenuButton = null;
}
document.getElementById('waitlistRowMenu').addEventListener('click', function(event) {
  var actionButton = event.target.closest('[data-action][data-waitlist-id]');
  if (!actionButton) return;
  event.preventDefault();
  var action = actionButton.getAttribute('data-action');
  var id = actionButton.getAttribute('data-waitlist-id');
  openWaitlistConfirmation(id, action, actionButton.href, actionButton);
});
var pendingWaitlistConfirmation = null;
function openWaitlistConfirmation(id, action, href, trigger) {
  var content = {
    notify: {
      title: 'Notify resident?',
      message: 'A slot will be offered to this resident and start a 24-hour claim window.',
      button: 'Notify now'
    },
    book: {
      title: 'Book for resident?',
      message: 'Continue to the appointment form. Slot capacity will be checked again before booking.',
      button: 'Continue'
    },
    cancel: {
      title: 'Remove from waitlist?',
      message: 'This waitlist entry will be cancelled and the resident will no longer be waiting for this slot.',
      button: 'Remove'
    }
  }[action];
  if (!content) return;
  pendingWaitlistConfirmation = {id:id, action:action, href:href, trigger:trigger};
  document.getElementById('waitlistConfirmTitle').textContent = content.title;
  document.getElementById('waitlistConfirmMessage').textContent = content.message;
  var confirmButton = document.getElementById('waitlistConfirmContinue');
  confirmButton.textContent = content.button;
  confirmButton.classList.toggle('danger', action === 'cancel');
  var overlay = document.getElementById('waitlistConfirmOverlay');
  overlay.classList.add('open');
  overlay.setAttribute('aria-hidden', 'false');
  confirmButton.focus();
}
function closeWaitlistConfirmation() {
  var overlay = document.getElementById('waitlistConfirmOverlay');
  overlay.classList.remove('open');
  overlay.setAttribute('aria-hidden', 'true');
  if (pendingWaitlistConfirmation && pendingWaitlistConfirmation.trigger) pendingWaitlistConfirmation.trigger.focus();
  pendingWaitlistConfirmation = null;
}
document.getElementById('waitlistConfirmCancel').addEventListener('click', closeWaitlistConfirmation);
document.getElementById('waitlistConfirmOverlay').addEventListener('click', function(event) {
  if (event.target === this) closeWaitlistConfirmation();
});
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && document.getElementById('waitlistConfirmOverlay').classList.contains('open')) {
    closeWaitlistConfirmation();
  }
});
document.getElementById('waitlistConfirmContinue').addEventListener('click', function() {
  if (!pendingWaitlistConfirmation) return;
  var pending = pendingWaitlistConfirmation;
  closeWaitlistConfirmation();
  if (pending.action === 'book') {
    window.location.href = pending.href;
    return;
  }
  waitlistAction(pending.id, pending.action, pending.trigger);
});
document.addEventListener('click', function(event) {
  if (!event.target.closest('#waitlistRowMenu') && !event.target.closest('.waitlist-row-actions')) closeWaitlistMenu();
});
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') closeWaitlistMenu();
});
window.addEventListener('resize', closeWaitlistMenu);
window.addEventListener('scroll', closeWaitlistMenu, true);
function waitlistAction(id, action, button) {
  button.disabled = true;
  var data = new URLSearchParams({waitlist_id: String(id), action: action, actor: 'staff'});
  fetch('appointment-waitlist-action.php', {method: 'POST', body: data})
    .then(function(response) {
      return response.json().then(function(result) {
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not update the waitlist.');
        return result;
      });
    })
    .then(function(result) {
      if (typeof showToast === 'function') showToast('Success', {type:'success', description:result.message});
      window.location.reload();
    })
    .catch(function(error) {
      if (typeof showToast === 'function') showToast('Error', {type:'error', description:error.message});
      else window.alert(error.message);
      button.disabled = false;
    });
}
</script>
<script>
function waitlistSubmitWith(values) {
  var url = new URL(window.location.href);
  Object.keys(values).forEach(function(key) {
    if (values[key] === null || values[key] === '') url.searchParams.delete(key);
    else url.searchParams.set(key, values[key]);
  });
  if (!Object.prototype.hasOwnProperty.call(values, 'page')) url.searchParams.delete('page');
  window.location.href = url.toString();
}
function waitlistGoToPage(page) { waitlistSubmitWith({page: String(page)}); }
function waitlistSetPerPage(value) { waitlistSubmitWith({per_page: value, page: '1'}); }
var waitlistDateInput = document.getElementById('waitlistDate');
document.getElementById('waitlistDateButton').addEventListener('click', function() {
  if (waitlistDateInput.showPicker) waitlistDateInput.showPicker();
  else waitlistDateInput.click();
});
waitlistDateInput.addEventListener('change', function() {
  waitlistDateInput.form.submit();
});
var clearWaitlistDate = document.getElementById('clearWaitlistDate');
if (clearWaitlistDate) clearWaitlistDate.addEventListener('click', function() { waitlistSubmitWith({date: null}); });
var waitlistSearchInput = document.querySelector('.waitlist-toolbar input[name="search"]');
var waitlistSearchTimer;
var waitlistInitialSearch = waitlistSearchInput ? waitlistSearchInput.value.trim() : '';
if (waitlistSearchInput) waitlistSearchInput.addEventListener('input', function() {
  clearTimeout(waitlistSearchTimer);
  var searchValue = waitlistSearchInput.value.trim();
  if (searchValue === waitlistInitialSearch) return;
  waitlistSearchTimer = setTimeout(function() {
    if (waitlistSearchInput.value.trim() !== waitlistInitialSearch) waitlistSearchInput.form.submit();
  }, 350);
});
</script>
</body>
</html>
