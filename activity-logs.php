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

$pageTitle = 'Activity Logs';
$activePage = 'activity-logs';

// Fetch recent activities - newest first
$recentActivities = [];
$activitiesQuery = $conn->query("
    SELECT a.log_id, a.action, a.details, a.created_at, u.full_name, u.role
    FROM activity_logs a
    JOIN users u ON a.user_id = u.user_id
    ORDER BY a.created_at DESC
");
if ($activitiesQuery) {
    while ($row = $activitiesQuery->fetch_assoc()) {
        $recentActivities[] = $row;
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
    .activity-cms { background: #ffffff; }
    .al-wrap { padding: 1.5rem 2rem 3rem; background: #ffffff; }

    .table-card { display: flex; flex-direction: column; border: 1px solid #e4e4e7; border-radius: 8px; background: #ffffff; overflow: hidden; }

    /* ── Table (shadcn) ── */
    .table-card .sd-data-table-wrap { overflow-x: auto; min-height: 31.5rem; flex: 1; }
    .table-card .sd-data-table { width: 100%; border-collapse: collapse; }
    .table-card .sd-data-table .sd-tr { border-bottom: 1px solid #e4e4e7; transition: background 0.12s; }
    .table-card .sd-data-table .sd-tr:last-child { border-bottom: none; }
    .table-card .sd-data-table .sd-tr.sd-last-row { border-bottom: none; }
    .table-card .sd-data-table tbody .sd-tr:hover { background: #f4f4f5; }
    .table-card .sd-th { height: 40px; padding: 0 12px; text-align: left; vertical-align: middle; font-family: 'Inter','Segoe UI',sans-serif; font-size: 12px; font-weight: 500; color: #71717a; white-space: nowrap; text-transform: none; border-bottom: 1px solid #e4e4e7; background: transparent; }
    .table-card .sd-td { padding: 12px; vertical-align: middle; font-family: 'Inter','Segoe UI',sans-serif; font-size: 14px; color: #18181b; line-height: 1.4; }

    /* ── Pagination (all-clients design) ── */
    .activity-cms .dt-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid #e4e4e7; }
    .activity-cms .dt-pagination-right { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
    .activity-cms .dt-rows { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #71717a; }
    .activity-cms .dt-rows select { height: 32px; width: 70px; border-radius: 6px; border: 1px solid #e4e4e7; background: #fff; padding: 0 8px; font-size: 14px; color: #18181b; cursor: pointer; font-family: inherit; }
    .activity-cms .dt-rows select:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .activity-cms .dt-page-label { width: 110px; text-align: center; font-size: 14px; font-weight: 500; color: #18181b; white-space: nowrap; }
    .activity-cms .dt-page-btns { display: flex; align-items: center; gap: 4px; }
    .activity-cms .dt-page-btn { height: 32px; width: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid #e4e4e7; background: #fff; color: #18181b; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .activity-cms .dt-page-btn:hover:not(:disabled) { background: #f4f4f5; }
    .activity-cms .dt-page-btn:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Pagination (shadcn, inside table) ── */
    .table-card .sd-pagination { display: flex; justify-content: flex-end; align-items: center; padding: 0.8rem 1.25rem 1rem; border-top: 1px solid #e4e4e7; flex-shrink: 0; min-height: 52px; }
    .table-card .sd-pagination-content { display: flex; flex-direction: row; align-items: center; gap: 4px; margin: 0; padding: 0; list-style: none; }
    .table-card .sd-pagination-link { display: inline-flex; align-items: center; justify-content: center; height: 36px; padding: 0 16px; border: none; border-radius: 6px; background: transparent; color: #18181b; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s ease; gap: 6px; }
    .table-card .sd-pagination-link:hover { background: #f4f4f5; }
    .table-card .sd-pagination-link:disabled { opacity: 0.45; pointer-events: none; }
    .table-card .sd-pagination-link:focus-visible { outline: none; box-shadow: 0 0 0 2px #18181b; }
    .table-card .sd-pagination-link.sd-pagination-previous { padding-left: 10px; }
    .table-card .sd-pagination-link.sd-pagination-next { padding-right: 10px; }

    @media (max-width: 900px) {
      .al-wrap { padding: 1rem 0.75rem 2rem; }
    }
  </style>
</head>
<body>
  <?php include 'includes/staff-sidebar.php'; ?>
  <main class="main-content activity-cms">
    <div class="al-wrap">
      <div class="table-card" id="recentActivitiesCard">
        <div class="sd-data-table-wrap">
        <table class="sd-data-table activities-table">
          <thead>
            <tr class="sd-tr">
              <th class="sd-th" style="width:45px;">No.</th>
              <th class="sd-th">Log ID</th>
              <th class="sd-th">User</th>
              <th class="sd-th">Role</th>
              <th class="sd-th">Action</th>
              <th class="sd-th">Details</th>
              <th class="sd-th">Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentActivities)): ?>
              <tr class="sd-tr"><td class="sd-td" colspan="7" style="text-align:center;">No recent activities found.</td></tr>
            <?php else: ?>
              <?php $actNum = 1; foreach ($recentActivities as $act): ?>
                <tr class="sd-tr" data-action="<?php echo htmlspecialchars(strtolower($act['action'])); ?>">
                  <td class="sd-td" style="color:#71717a; font-size:0.85rem; font-weight:600;"><?php echo $actNum++; ?></td>
                  <td class="sd-td" style="color:#71717a;">#LOG-<?php echo str_pad($act['log_id'], 4, '0', STR_PAD_LEFT); ?></td>
                  <td class="sd-td"><strong><?php echo htmlspecialchars($act['full_name']); ?></strong></td>
                  <td class="sd-td" style="font-weight:500; color:#18181b;"><?php echo ucfirst($act['role']); ?></td>
                  <td class="sd-td" style="color:#18181b;"><?php echo htmlspecialchars($act['action']); ?></td>
                  <td class="sd-td" style="color:#71717a; font-size:0.9rem;"><?php echo htmlspecialchars($act['details']); ?></td>
                  <td class="sd-td" style="color:#71717a; font-size:0.85rem;"><?php echo date('M d, Y h:i A', strtotime($act['created_at'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
        <div class="dt-pagination" id="actPagination">
          <div class="dt-pagination-right">
            <div class="dt-rows">
              <span>Rows per page</span>
              <select onchange="window.actSetPerPage(this.value)">
                <option value="15">15</option>
                <option value="20">20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
              </select>
            </div>
            <div class="dt-page-label" id="actPageLabel">Page 1 of 1</div>
            <div class="dt-page-btns">
              <button class="dt-page-btn" id="actPageFirst" title="Go to first page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
              </button>
              <button class="dt-page-btn" id="actPagePrev" title="Go to previous page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <button class="dt-page-btn" id="actPageNext" title="Go to next page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
              <button class="dt-page-btn" id="actPageLast" title="Go to last page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    var PER_PAGE_ACT = 15;
    var tbody = document.querySelector('.activities-table tbody');
    var pag = document.getElementById('actPagination');
    if (!tbody || !pag) return;
    var allRows = Array.from(tbody.querySelectorAll('tr[data-action]'));
    var currentPage = 1;

    function renderTable() {
      allRows.forEach(function(r) { r.style.display = 'none'; r.classList.remove('sd-last-row'); });
      var start = (currentPage - 1) * PER_PAGE_ACT;
      var end = start + PER_PAGE_ACT;
      var visible = allRows.slice(start, end);
      visible.forEach(function(r) { r.style.display = ''; });
      if (visible.length) visible[visible.length - 1].classList.add('sd-last-row');
      renderPagination();
    }

    function renderPagination() {
      var total = allRows.length;
      var tp = Math.max(1, Math.ceil(total / PER_PAGE_ACT));
      if (currentPage > tp) currentPage = tp;
      if (currentPage < 1) currentPage = 1;
      var first = document.getElementById('actPageFirst');
      var prev = document.getElementById('actPagePrev');
      var next = document.getElementById('actPageNext');
      var last = document.getElementById('actPageLast');
      var label = document.getElementById('actPageLabel');
      if (first) first.disabled = currentPage <= 1;
      if (prev) prev.disabled = currentPage <= 1;
      if (next) next.disabled = currentPage >= tp;
      if (last) last.disabled = currentPage >= tp;
      if (label) label.textContent = 'Page ' + currentPage + ' of ' + tp;
      if (first) first.onclick = function() { actPageGo(1); };
      if (prev) prev.onclick = function() { actPageGo(currentPage - 1); };
      if (next) next.onclick = function() { actPageGo(currentPage + 1); };
      if (last) last.onclick = function() { actPageGo(tp); };
    }

    window.actPageGo = function(page) {
      var tp = Math.ceil(allRows.length / PER_PAGE_ACT);
      if (page < 1 || page > tp) return;
      currentPage = page;
      renderTable();
    };

    window.actSetPerPage = function(v) {
      PER_PAGE_ACT = parseInt(v, 10);
      currentPage = 1;
      renderTable();
    };

    renderTable();
  });
  </script>
</body>
</html>
