<?php
// ── Self-contained user data (no dependency on page-defined variables) ──
if (!isset($fullName)) {
    $fullName = $_SESSION['full_name'] ?? 'Staff Member';
}
if (!isset($staffAvatar)) {
    $staffAvatar = $_SESSION['avatar_url'] ?? '';
    // If still empty, fetch from DB
    if (empty($staffAvatar) && isset($_SESSION['user_id'])) {
        $aq = $conn->prepare("SELECT full_name, avatar_url FROM users WHERE user_id = ?");
        if ($aq) {
            $aq->bind_param("i", $_SESSION['user_id']);
            $aq->execute();
            $ar = $aq->get_result();
            if ($row = $ar->fetch_assoc()) {
                $fullName = $row['full_name'] ?? $fullName;
                $staffAvatar = $row['avatar_url'] ?? '';
                $_SESSION['full_name'] = $fullName;
                $_SESSION['avatar_url'] = $staffAvatar;
            }
            $aq->close();
        }
    }
}
if (!isset($activePage)) $activePage = '';
if (!isset($pageTitle)) $pageTitle = 'Staff Portal';

// Fetch notifications for this staff user (shared across all staff pages)
$notifications = [];
$unreadNotifCount = 0;
if (isset($_SESSION['user_id'])) {
  $nq = $conn->prepare("
      SELECT n.* FROM notifications n
      LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
      WHERE n.user_id = ?
        AND NOT (n.type = 'report_validated' AND r.report_id IS NOT NULL AND r.status != 'validated')
      ORDER BY n.created_at DESC LIMIT 20");
  if ($nq) {
      $nq->bind_param("i", $_SESSION['user_id']);
      $nq->execute();
      $nr = $nq->get_result();
      while ($row = $nr->fetch_assoc()) $notifications[] = $row;
      $nq->close();
  }
  // Count only the unread notifications actually shown in the panel, so the
  // badge never over-counts stale report_validated notifications.
  $ucStmt = $conn->prepare("
      SELECT COUNT(*) FROM notifications n
      LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
      WHERE n.user_id = ?
        AND n.is_read = 0
        AND NOT (n.type = 'report_validated' AND r.report_id IS NOT NULL AND r.status != 'validated')");
  if ($ucStmt) {
      $ucStmt->bind_param("i", $_SESSION['user_id']);
      $ucStmt->execute();
      $unreadNotifCount = (int)$ucStmt->get_result()->fetch_row()[0];
      $ucStmt->close();
  }
}

// ── Map the current page to the matching sidebar module ──
// dashboard-staff.php keeps $activePage = 'overview' server-side; the real
// section is resolved client-side from the URL hash (see SB2 JS below).
$sbPageToModule = [
    'availability'   => 'operations',
    'geo'            => 'monitoring',
    'announcements'  => 'communication',
    'communication'  => 'communication',
    'pet-listings'   => 'operations',
    'activity-logs'  => 'monitoring',
];
$sbActiveModule = $sbPageToModule[$activePage] ?? 'operations';

// Item helper: label, icon, optional href / dashboard-section / page-filter fn
function sbItem($label, $icon, $opts = []) {
    return ['label' => $label, 'icon' => $icon] + $opts;
}
function sbAttrs($item) {
    $a = '';
    if (!empty($item['section'])) $a .= ' data-section="' . htmlspecialchars($item['section']) . '"';
    if (!empty($item['href']))      $a .= ' data-href="' . htmlspecialchars($item['href']) . '"';
    if (!empty($item['fn']))        $a .= ' data-fn="' . htmlspecialchars($item['fn']) . '"';
    if (!empty($item['arg']))       $a .= ' data-arg="' . htmlspecialchars($item['arg']) . '"';
    if (!empty($item['logout']))    $a .= ' data-logout="1"';
    return $a;
}

// ── Sidebar content model (all modules are rendered; JS shows the active one) ──
// Each module is a group dropdown; each section renders as a nested dropdown
// whose toggle label is the section title, with the section's items as options.
// Single-item sections render as a plain menu item instead of a dropdown.
$sbModules = [
    'operations' => [
        'title' => 'Operations',
        'sections' => [
            [
                'title' => 'Analytics', 'icon' => 'fa-chart-line',
                'items' => [
                    sbItem('Cases Analytics', 'fa-chart-pie', ['section' => 'analytics', 'href' => 'dashboard-staff.php#analytics', 'fn' => 'aethGo', 'arg' => 'engine']),
                    sbItem('Appointment Overview', 'fa-calendar-check', ['section' => 'analytics', 'href' => 'dashboard-staff.php#analytics', 'fn' => 'aethGo', 'arg' => 'intel']),
                    sbItem('Adoption Insights', 'fa-paw', ['section' => 'analytics', 'href' => 'dashboard-staff.php#analytics', 'fn' => 'aethGo', 'arg' => 'budget']),
                    sbItem('Programs & Services', 'fa-layer-group', ['section' => 'analytics', 'href' => 'dashboard-staff.php#analytics', 'fn' => 'aethGo', 'arg' => 'architect']),
                ],
            ],
            [
                'title' => 'Reports', 'icon' => 'fa-file-medical-alt',
                'items' => [
                    sbItem('All Reports', 'fa-list', ['section' => 'reports-all', 'href' => 'dashboard-staff.php#reports-all']),
                    sbItem('Pending Validation', 'fa-hourglass-half', ['section' => 'reports-pending', 'href' => 'dashboard-staff.php#reports-pending']),
                    sbItem('Active Cases', 'fa-activity', ['section' => 'reports-active', 'href' => 'dashboard-staff.php#reports-active']),
                    sbItem('Resolved Cases', 'fa-check-circle', ['section' => 'reports-resolved', 'href' => 'dashboard-staff.php#reports-resolved']),
                ],
            ],
            [
                'title' => 'Appointments', 'icon' => 'fa-calendar-alt',
                'items' => [
                    sbItem('Appointment Requests', 'fa-calendar-check', ['section' => 'appointments', 'href' => 'dashboard-staff.php#appointments']),
                    sbItem('Calendar Schedule', 'fa-calendar-day', ['section' => 'calendar-schedule', 'href' => 'dashboard-staff.php#calendar-schedule']),
                ],
            ],
            [
                'title' => 'Availability', 'icon' => 'fa-clock',
                'items' => [
                    sbItem('Weekly Schedule', 'fa-calendar-week', ['href' => 'availability.php', 'initialActive' => $activePage === 'availability' && empty($_GET['panel'])]),
                    sbItem('Date Overrides', 'fa-pen-square', ['href' => 'availability.php?panel=overrides', 'initialActive' => ($activePage === 'availability' && ($_GET['panel'] ?? '') === 'overrides')]),
                    sbItem('Bulk Schedule', 'fa-layer-group', ['href' => 'availability.php?panel=bulk', 'initialActive' => ($activePage === 'availability' && ($_GET['panel'] ?? '') === 'bulk')]),
                    sbItem('Schedule Preview', 'fa-eye', ['href' => 'availability.php?panel=preview', 'initialActive' => ($activePage === 'availability' && ($_GET['panel'] ?? '') === 'preview')]),
                ],
            ],
            [
                'title' => 'Clients & Pets', 'icon' => 'fa-database',
                'items' => [
                    sbItem('All Clients', 'fa-users', ['section' => 'client-pets', 'href' => 'dashboard-staff.php#client-pets', 'fn' => 'setGroup', 'arg' => 'all']),
                    sbItem('With Pets', 'fa-paw', ['section' => 'client-pets', 'href' => 'dashboard-staff.php#client-pets', 'fn' => 'setGroup', 'arg' => 'with']),
                    sbItem('No Pets', 'fa-user-slash', ['section' => 'client-pets', 'href' => 'dashboard-staff.php#client-pets', 'fn' => 'setGroup', 'arg' => 'none']),
                ],
            ],
            [
                'title' => 'Pet Listings', 'icon' => 'fa-paw',
                'items' => [
                    sbItem('All Pets', 'fa-paw', ['href' => 'staff-pets.php', 'fn' => 'setListing', 'arg' => 'all', 'initialActive' => $activePage === 'pet-listings']),
                    sbItem('Available', 'fa-check-circle', ['href' => 'staff-pets.php', 'fn' => 'setListing', 'arg' => 'available']),
                    sbItem('Adopted', 'fa-home', ['href' => 'staff-pets.php', 'fn' => 'setListing', 'arg' => 'adopted']),
                    sbItem('Withdrawn', 'fa-eye-slash', ['href' => 'staff-pets.php', 'fn' => 'setListing', 'arg' => 'withdrawn']),
                ],
            ],
        ],
    ],
    'communication' => [
        'title' => 'Communication',
        'sections' => [
            [
                'title' => 'Messages', 'icon' => 'fa-envelope',
                'items' => [
                    sbItem('Messages', 'fa-envelope', ['href' => 'staff-communication.php', 'initialActive' => $activePage === 'communication']),
                ],
            ],
            [
                'title' => 'Announcements', 'icon' => 'fa-bullhorn',
                'items' => [
                    sbItem('All Posts', 'fa-list', ['href' => 'staff-announcements.php', 'fn' => 'setPost', 'arg' => 'all', 'initialActive' => $activePage === 'announcements']),
                    sbItem('Announcements', 'fa-bullhorn', ['href' => 'staff-announcements.php', 'fn' => 'setPost', 'arg' => 'announcement']),
                    sbItem('Advisories', 'fa-exclamation-triangle', ['href' => 'staff-announcements.php', 'fn' => 'setPost', 'arg' => 'advisory']),
                    sbItem('Published', 'fa-check-circle', ['href' => 'staff-announcements.php', 'fn' => 'setStatus', 'arg' => 'published']),
                    sbItem('Scheduled', 'fa-calendar', ['href' => 'staff-announcements.php', 'fn' => 'setStatus', 'arg' => 'scheduled']),
                    sbItem('Drafts', 'fa-pen', ['href' => 'staff-announcements.php', 'fn' => 'setStatus', 'arg' => 'draft']),
                ],
            ],
        ],
    ],
    'monitoring' => [
        'title' => 'Monitoring',
        'sections' => [
            [
                'title' => 'Activity Logs', 'icon' => 'fa-history',
                'items' => [
                    sbItem('Activity Logs', 'fa-history', ['href' => 'activity-logs.php', 'initialActive' => $activePage === 'activity-logs']),
                ],
            ],
            [
                'title' => 'Geo-Mapping', 'icon' => 'fa-map-marked-alt',
                'items' => [
                    sbItem('Geo-Mapping', 'fa-map-marked-alt', ['href' => 'dashboard-staff-geo.php', 'initialActive' => $activePage === 'geo']),
                ],
            ],
        ],
    ],
    'system' => [
        'title' => 'System',
        'sections' => [
            [
                'title' => 'Settings', 'icon' => 'fa-cog',
                'items' => [
                    sbItem('Account Settings', 'fa-user-cog', ['section' => 'account-settings', 'href' => 'dashboard-staff.php#account-settings']),
                    sbItem('Change Password', 'fa-key', ['href' => 'change-password.php']),
                ],
            ],
            [
                'title' => 'Logout', 'icon' => 'fa-sign-out-alt',
                'items' => [
                    sbItem('Logout', 'fa-sign-out-alt', ['href' => 'logout-staff.php', 'logout' => true]),
                ],
            ],
        ],
    ],
];

// Left icon rail order (module id => icon + label + target)
$sbRail = [
    'operations'    => ['icon' => 'fa-briefcase',      'label' => 'Operations',    'section' => 'reports-all',     'href' => 'dashboard-staff.php#reports-all'],
    'communication' => ['icon' => 'fa-envelope',       'label' => 'Communication', 'href' => 'staff-communication.php'],
    'monitoring'    => ['icon' => 'fa-chart-line',     'label' => 'Monitoring',    'href' => 'activity-logs.php'],
    'system'        => ['icon' => 'fa-cog',            'label' => 'System',        'section' => 'account-settings', 'href' => 'dashboard-staff.php#account-settings'],
];
?>
<link rel="stylesheet" href="assets/css/sb2.css" />
<script>
window.SB2_CONFIG = {
  activeModule: <?php echo json_encode($sbActiveModule); ?>,
  activePage: <?php echo json_encode($activePage); ?>
};
</script>

<aside class="sb2" id="sidebar" data-state="expanded" data-collapsible="icon">
  <div class="sb2-inner">

    <!-- SidebarHeader: brand -->
    <div class="sb2-header">
      <div class="sb2-brand">
        <div class="sb2-brand-logo">
          <img src="uploads/site/logo.png" alt="DCVS" />
        </div>
        <div class="sb2-brand-text">
          <span class="sb2-brand-name">DCVS</span>
          <span class="sb2-brand-sub">Staff Portal</span>
        </div>
      </div>
    </div>

    <!-- SidebarContent: one group per module -->
    <div class="sb2-content" id="sb2Menu">
      <?php foreach ($sbModules as $modId => $mod): ?>
        <div class="sb2-group <?php echo $sbActiveModule === $modId ? 'module-active' : ''; ?>" data-module="<?php echo $modId; ?>">
          <div class="sb2-group-label"><?php echo htmlspecialchars($mod['title']); ?></div>
          <ul class="sb2-menu">
            <?php foreach ($mod['sections'] as $sec):
              $secHasActive = false;
              foreach ($sec['items'] as $item) {
                if (!empty($item['initialActive'])) $secHasActive = true;
              }
              $single = count($sec['items']) === 1;
            ?>
              <?php if ($single):
                $item = $sec['items'][0]; ?>
                <li class="sb2-menu-item">
                  <a href="<?php echo htmlspecialchars($item['href'] ?? '#'); ?>"
                     class="sb2-menu-button <?php echo !empty($item['initialActive']) ? 'active' : ''; ?>"
                     title="<?php echo htmlspecialchars($item['label']); ?>"
                     <?php echo sbAttrs($item); ?>>
                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['label']); ?></span>
                  </a>
                </li>
              <?php else: ?>
                <li class="sb2-menu-item">
                  <button type="button"
                    class="sb2-menu-button sb2-collapsible <?php echo $secHasActive ? 'open' : ''; ?>"
                    aria-expanded="<?php echo $secHasActive ? 'true' : 'false'; ?>"
                    title="<?php echo htmlspecialchars($sec['title']); ?>">
                    <i class="fas <?php echo htmlspecialchars($sec['icon'] ?? 'fa-circle'); ?>"></i>
                    <span><?php echo htmlspecialchars($sec['title']); ?></span>
                    <svg class="sb2-caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                  </button>
                  <ul class="sb2-menu-sub">
                    <?php foreach ($sec['items'] as $item): ?>
                      <li class="sb2-menu-item">
                        <a href="<?php echo htmlspecialchars($item['href'] ?? '#'); ?>"
                           class="sb2-menu-sub-button <?php echo !empty($item['initialActive']) ? 'active' : ''; ?>"
                           title="<?php echo htmlspecialchars($item['label']); ?>"
                           <?php echo sbAttrs($item); ?>>
                          <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                          <span><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SidebarFooter: user row -->
    <div class="sb2-footer">
      <div class="sb2-user">
        <div class="sb2-user-avatar" title="<?php echo htmlspecialchars($fullName); ?>">
          <?php if (!empty($staffAvatar)): ?>
            <img src="<?php echo htmlspecialchars($staffAvatar); ?>" alt="Avatar">
          <?php else: ?>
            <?php echo strtoupper(substr($fullName, 0, 1)); ?>
          <?php endif; ?>
        </div>
        <div class="sb2-user-info">
          <span class="sb2-user-name"><?php echo htmlspecialchars($fullName); ?></span>
          <span class="sb2-user-role">Staff</span>
        </div>
        <button type="button" class="sb2-user-more" id="sb2Logout" aria-label="Logout" title="Logout">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- SidebarRail: desktop collapse handle -->
  <button type="button" class="sb2-rail" id="sb2Rail" aria-label="Toggle Sidebar" title="Toggle Sidebar"></button>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar" id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
  </button>
  <div class="topbar-right">
    <div class="notif-dropdown" id="notifDropdown" style="margin-left:auto;">
      <button class="notif-bell" id="notifBell" onclick="toggleNotif()">
        <i class="fas fa-bell"></i>
        <?php if (isset($unreadNotifCount) && $unreadNotifCount > 0): ?>
          <span class="notif-badge" data-realtime="notif-badge"><?php echo $unreadNotifCount > 9 ? '9+' : $unreadNotifCount; ?></span>
        <?php endif; ?>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-header">
          <h4>Notifications</h4>
          <?php if (isset($unreadNotifCount) && $unreadNotifCount > 0): ?>
            <a onclick="markAllNotifRead()">Mark all read</a>
          <?php endif; ?>
        </div>
        <div class="notif-panel-body" id="notificationList">
          <?php if (empty($notifications)): ?>
            <div class="notif-empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($notifications as $n): ?>
              <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" data-nid="<?php echo $n['notification_id']; ?>">
                <div class="notif-icon <?php echo $n['type']; ?>">
                  <?php
                    $icon = match($n['type']) {
                      'new_report' => 'fa-file-alt',
                      'report_validated' => 'fa-check-circle',
                      'report_confirmed' => 'fa-check-double',
                      'report_rejected' => 'fa-times-circle',

                      default => 'fa-bell'
                    };
                  ?>
                  <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div class="notif-content">
                  <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                  <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
                  <div class="notif-time"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <span class="topbar-time" id="clockDisplay"></span>
    <div class="topbar-user">
      <div class="topbar-avatar">
        <?php if (!empty($staffAvatar)): ?>
          <img src="<?php echo htmlspecialchars($staffAvatar); ?>" alt="Avatar" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <?php echo strtoupper(substr($fullName, 0, 1)); ?>
        <?php endif; ?>
      </div>
      <span class="topbar-user-name"><?php echo htmlspecialchars($fullName); ?></span>
    </div>
  </div>
</header>

<style>
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
  .topbar-time { font-size:0.8rem; color:#94a3b8; font-weight:500; }
  .topbar-user {
    display:flex; align-items:center; gap:8px;
    background:#f8fafc; border:1px solid #eef2f6;
    border-radius:999px; padding:4px 12px 4px 6px;
  }
  .topbar-avatar {
    width:30px; height:30px; border-radius:50%;
    background:linear-gradient(135deg,#045e1f,#043012);
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:0.75rem;
  }
  .topbar-avatar img { width:30px; height:30px; border-radius:50%; object-fit:cover; }
  .topbar-user-name { font-size:0.82rem; font-weight:600; color:#0f172a; }
</style>

<div class="modal" id="logoutModal">
  <div class="modal-content" style="max-width:400px;">
    <div class="modal-header">
      <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
      <button class="modal-close" onclick="closeModal('logoutModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;padding:2rem;">
      <i class="fas fa-question-circle" style="font-size:3rem;color:#94a3b8;margin-bottom:1rem;"></i>
      <p style="font-size:1.05rem;color:#475569;">Are you sure you want to logout of the Staff Portal?</p>
    </div>
    <div class="modal-actions" style="justify-content:center;">
      <a href="logout-staff.php" class="btn-primary" style="padding:10px 28px;" onclick="localStorage.removeItem('staffActiveTab')"><i class="fas fa-check"></i> Yes, Logout</a>
      <button type="button" class="btn-secondary" onclick="closeModal('logoutModal')">Cancel</button>
    </div>
  </div>
</div>

<script>
(function() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var toggle = document.getElementById('sidebarToggle');
  var rail = document.getElementById('sb2Rail');

  // Flag the layout so the topbar / main-content offsets use the sidebar width
  document.body.classList.add('sb2-active');

  // ── Desktop icon-collapse (shadcn SidebarRail / ctrl+b) ──
  function setDesktopState(collapsed) {
    sidebar.setAttribute('data-state', collapsed ? 'collapsed' : 'expanded');
    document.body.classList.toggle('sb2-collapsed', collapsed);
    try { localStorage.setItem('sb2Collapsed', collapsed ? '1' : '0'); } catch (e) {}
  }
  try {
    if (window.innerWidth > 768 && localStorage.getItem('sb2Collapsed') === '1') setDesktopState(true);
  } catch (e) {}

  // ── Mobile drawer toggle ──
  function handleResize() {
    if (window.innerWidth > 768) {
      sidebar.classList.remove('collapsed');
      if (overlay) overlay.classList.remove('active');
    } else {
      sidebar.setAttribute('data-state', 'expanded');
    }
  }
  window.addEventListener('resize', handleResize);
  if (toggle) {
    toggle.addEventListener('click', function() {
      sidebar.classList.toggle('collapsed');
      if (overlay) overlay.classList.toggle('active');
    });
  }
  if (rail) {
    rail.addEventListener('click', function() {
      setDesktopState(sidebar.getAttribute('data-state') !== 'collapsed');
    });
  }
  if (overlay) {
    overlay.addEventListener('click', function() {
      sidebar.classList.remove('collapsed');
      overlay.classList.remove('active');
    });
  }

  // Keyboard shortcut (ctrl/cmd + b) to toggle the sidebar
  document.addEventListener('keydown', function(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'b') {
      e.preventDefault();
      if (window.innerWidth <= 768) {
        sidebar.classList.toggle('collapsed');
        if (overlay) overlay.classList.toggle('active');
      } else {
        setDesktopState(sidebar.getAttribute('data-state') !== 'collapsed');
      }
    }
  });

  // ── Module activation ──
  // Maps dashboard-staff.php hash sections to sidebar modules
  var sectionToModule = {
    'analytics': 'operations', 'overview': 'operations', 'users': 'operations',
    'appointments': 'operations', 'calendar-schedule': 'operations',
    'client-pets': 'operations',
    'reports-all': 'operations', 'reports-pending': 'operations',
    'reports-active': 'operations', 'reports-resolved': 'operations',
    'geomapping': 'monitoring',
    'account-settings': 'system'
  };

  // ── Sidebar state persistence (keeps open collapsibles / active item across pages) ──
  function getItemKey(item) {
    return (item.getAttribute('data-section') || '') + '|' +
           (item.getAttribute('data-href') || '') + '|' +
           (item.getAttribute('data-fn') || '') + '|' +
           (item.getAttribute('data-arg') || '');
  }
  function saveSidebarState() {
    try {
      var open = [];
      document.querySelectorAll('.sb2-collapsible.open').forEach(function(b) {
        var t = b.querySelector(':scope > span');
        if (t && t.textContent) open.push(t.textContent.trim());
      });
      var activeItem = document.querySelector('.sb2-menu-button.active:not(.sb2-collapsible), .sb2-menu-sub-button.active');
      var state = { open: open, module: null };
      if (activeItem) state.activeKey = getItemKey(activeItem);
      localStorage.setItem('sb2State', JSON.stringify(state));
    } catch (e) {}
  }

  function activateModule(moduleId) {
    if (!moduleId) return;
    document.querySelectorAll('.sb2-group').forEach(function(g) {
      g.classList.toggle('module-active', g.getAttribute('data-module') === moduleId);
    });
  }

  // Open the collapsible group that contains a sub-item
  function openAncestor(item) {
    var inner = item.closest('.sb2-menu-item');
    var outer = inner && inner.parentElement ? inner.parentElement.closest('.sb2-menu-item') : null;
    var coll = outer ? outer.querySelector(':scope > .sb2-collapsible') : null;
    if (coll) {
      coll.classList.add('open');
      coll.setAttribute('aria-expanded', 'true');
    }
  }

  function activateSection(sectionId) {
    var moduleId = sectionToModule[sectionId];
    if (moduleId) activateModule(moduleId);
    // Preserve a manually-activated sub-item that targets this section
    // (e.g. Client & Pets: All Clients / With Pets / No Pets all share data-section).
    var match = document.querySelector('.sb2-menu-sub-button.active[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-button.active[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-sub-button[data-section="' + sectionId + '"]');
    document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(item) {
      if (match && item === match) return;
      item.classList.remove('active');
    });
    if (match) {
      match.classList.add('active');
      openAncestor(match);
    }
    saveSidebarState();
  }

  // Highlight a specific sidebar item (and open its ancestor collapsible)
  function activateItem(item) {
    document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(el) {
      el.classList.remove('active');
    });
    item.classList.add('active');
    openAncestor(item);
    saveSidebarState();
  }

  // Initial activation: prefer URL hash (dashboard-staff.php), else server page
  var initHash = window.location.hash.replace('#', '');
  if (initHash && sectionToModule[initHash]) {
    activateSection(initHash);
  } else {
    var cfg = window.SB2_CONFIG || {};
    var pageToModule = {
      'availability': 'operations',
      'geo': 'monitoring',
      'announcements': 'communication',
      'communication': 'communication',
      'pet-listings': 'operations',
      'activity-logs': 'monitoring'
    };
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem('sb2State') || 'null'); } catch (e) {}
    activateModule(pageToModule[cfg.activePage] || cfg.activeModule || 'operations');
    // Restore previously open collapsibles + active item after a cross-page navigation
    if (saved && saved.open && saved.open.length) {
      document.querySelectorAll('.sb2-collapsible').forEach(function(b) {
        var t = b.querySelector(':scope > span');
        if (t && t.textContent && saved.open.indexOf(t.textContent.trim()) !== -1) {
          b.classList.add('open');
          b.setAttribute('aria-expanded', 'true');
        }
      });
    }
    if (saved && saved.activeKey) {
      document.querySelectorAll('.sb2-menu-button:not(.sb2-collapsible), .sb2-menu-sub-button').forEach(function(item) {
        if (getItemKey(item) === saved.activeKey) activateItem(item);
      });
    }
  }

  // React to dashboard hash navigation
  window.addEventListener('hashchange', function() {
    var hash = window.location.hash.replace('#', '');
    if (hash && sectionToModule[hash]) activateSection(hash);
  });

  // Expose for dashboard.js switchSection()
  window.SB2 = {
    activateModule: activateModule,
    activateSection: activateSection
  };

  // ── Section collapsibles (expand/collapse only; submenu items navigate) ──
  document.querySelectorAll('.sb2-collapsible').forEach(function(toggle) {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      var open = toggle.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      saveSidebarState();
    });
  });

  // ── Menu item click (skip section collapsibles) ──
  document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(item) {
    if (item.classList.contains('sb2-collapsible')) return;
    item.addEventListener('click', function(e) {
      if (item.getAttribute('data-logout') === '1') {
        e.preventDefault();
        openModal('logoutModal');
        return;
      }
      var section = item.getAttribute('data-section');
      var fn = item.getAttribute('data-fn');
      var arg = item.getAttribute('data-arg');
      var href = item.getAttribute('data-href');

      if (section && typeof switchSection === 'function') {
        e.preventDefault();
        switchSection(section);
        if (fn && typeof window[fn] === 'function') window[fn](arg);
        activateItem(item);
        return;
      }
      if (fn && typeof window[fn] === 'function') {
        e.preventDefault();
        window[fn](arg);
        activateItem(item);
        return;
      }
      // Default: plain navigation (href is already set on the anchor)
      // Record which item was chosen so the sidebar state survives the reload.
      activateItem(item);
      saveSidebarState();
    });
  });

  // Footer logout button
  var footLogout = document.getElementById('sb2Logout');
  if (footLogout) {
    footLogout.addEventListener('click', function() { openModal('logoutModal'); });
  }
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function(e) {
      e.preventDefault();
      openModal('logoutModal');
    });
  }
});

function openModal(id) {
  var el = document.getElementById(id);
  if (el) { el.classList.add('active'); el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) { el.classList.remove('active'); el.style.display = 'none'; document.body.style.overflow = ''; }
}
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.classList.remove('active');
    e.target.style.display = 'none';
    document.body.style.overflow = '';
  }
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal.active').forEach(function(m) {
      m.classList.remove('active');
      m.style.display = 'none';
      document.body.style.overflow = '';
    });
  }
});

function updateClock() {
  var now = new Date();
  var opts = { weekday:'short', month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' };
  var el = document.getElementById('clockDisplay');
  if (el) el.textContent = now.toLocaleDateString('en-US', opts);
}
updateClock();
setInterval(updateClock, 30000);

function toggleNotif() { document.getElementById('notifPanel')?.classList.toggle('open'); }
document.addEventListener('click', function(e) {
  var dd = document.getElementById('notifDropdown');
  if (dd && !dd.contains(e.target)) document.getElementById('notifPanel')?.classList.remove('open');
});

function markAllNotifRead() {
  fetch('mark-notifications-read.php?type=staff').then(function() {
    document.querySelectorAll('.notif-item.unread').forEach(function(el) { el.classList.remove('unread'); });
    var badge = document.querySelector('.notif-badge');
    if (badge) badge.style.display = 'none';
  });
}
function viewProfilePhoto() {
  if (typeof openModal === 'function') openModal('profilePhotoModal');
}
</script>