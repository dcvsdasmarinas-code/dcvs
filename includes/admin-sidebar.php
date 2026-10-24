<?php
if (!isset($fullName)) {
    $fullName = $_SESSION['full_name'] ?? 'Admin';
}
if (!isset($adminAvatar)) {
    $adminAvatar = $_SESSION['avatar_url'] ?? '';
    if (empty($adminAvatar) && isset($_SESSION['user_id'])) {
        $aq = $conn->prepare("SELECT full_name, avatar_url FROM users WHERE user_id = ?");
        if ($aq) {
            $aq->bind_param("i", $_SESSION['user_id']);
            $aq->execute();
            $ar = $aq->get_result();
            if ($row = $ar->fetch_assoc()) {
                $fullName = $row['full_name'] ?? $fullName;
                $adminAvatar = $row['avatar_url'] ?? '';
                $_SESSION['full_name'] = $fullName;
                $_SESSION['avatar_url'] = $adminAvatar;
            }
            $aq->close();
        }
    }
}
if (!isset($activePage)) $activePage = '';
if (!isset($pageTitle)) $pageTitle = 'Admin Portal';
require_once __DIR__ . '/app-base.php';
$notifAppBaseUrl = dcvsAppBaseUrl();
$adminNotifications = [];
$adminArchivedNotifications = [];
$adminUnreadNotificationCount = 0;
if (isset($_SESSION['user_id'])) {
    $notificationQuery = $conn->prepare("
        SELECT n.*, actor.full_name AS actor_name, actor.avatar_url AS actor_avatar
        FROM notifications n
        LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
        LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
        LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
        LEFT JOIN users actor ON actor.user_id = CASE
            WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
            WHEN n.reference_type = 'report' THEN r.reporter_id
            WHEN n.reference_type = 'appointments' THEN a.user_id
            WHEN n.reference_type = 'pets' THEN p.owner_id
            WHEN n.reference_type = 'staff_message' THEN n.reference_id
            ELSE NULL
        END
        WHERE n.user_id = ? AND n.archived_at IS NULL
        ORDER BY n.created_at DESC LIMIT 30");
    if ($notificationQuery) {
        $notificationQuery->bind_param('i', $_SESSION['user_id']);
        $notificationQuery->execute();
        $notificationResult = $notificationQuery->get_result();
        while ($row = $notificationResult->fetch_assoc()) $adminNotifications[] = $row;
        $notificationQuery->close();
    } else {
        error_log('Failed to prepare admin notifications: ' . $conn->error);
    }
    $archivedQuery = $conn->prepare("
        SELECT n.*, actor.full_name AS actor_name, actor.avatar_url AS actor_avatar
        FROM notifications n
        LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
        LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
        LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
        LEFT JOIN users actor ON actor.user_id = CASE
            WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
            WHEN n.reference_type = 'report' THEN r.reporter_id
            WHEN n.reference_type = 'appointments' THEN a.user_id
            WHEN n.reference_type = 'pets' THEN p.owner_id
            WHEN n.reference_type = 'staff_message' THEN n.reference_id
            ELSE NULL
        END
        WHERE n.user_id = ? AND n.archived_at IS NOT NULL
        ORDER BY n.archived_at DESC LIMIT 30");
    if ($archivedQuery) {
        $archivedQuery->bind_param('i', $_SESSION['user_id']);
        $archivedQuery->execute();
        $archivedResult = $archivedQuery->get_result();
        while ($row = $archivedResult->fetch_assoc()) $adminArchivedNotifications[] = $row;
        $archivedQuery->close();
    } else {
        error_log('Failed to prepare archived admin notifications: ' . $conn->error);
    }
    $unreadQuery = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
    if ($unreadQuery) {
        $unreadQuery->bind_param('i', $_SESSION['user_id']);
        $unreadQuery->execute();
        $adminUnreadNotificationCount = (int)$unreadQuery->get_result()->fetch_row()[0];
        $unreadQuery->close();
    } else {
        error_log('Failed to prepare admin notification count: ' . $conn->error);
    }
}

$sbActiveModule = 'user-management';
if ($activePage === 'activity') $sbActiveModule = 'monitoring';
if ($activePage === 'settings') $sbActiveModule = 'system';

function sbItem($label, $icon, $opts = []) {
    return ['label' => $label, 'icon' => $icon] + $opts;
}
function sbAttrs($item) {
    $a = '';
    if (!empty($item['section'])) $a .= ' data-section="' . htmlspecialchars($item['section']) . '"';
    if (!empty($item['href']))      $a .= ' data-href="' . htmlspecialchars($item['href']) . '"';
  if (!empty($item['logout']))    $a .= ' data-logout="1"';
    return $a;
}

$sbModules = [
    'user-management' => [
        'title' => 'User Management',
        'sections' => [
            [
                'title' => 'Overview', 'icon' => 'fa-chart-pie',
                'items' => [
                    sbItem('Dashboard', 'fa-chart-pie', ['href' => 'dashboard-admin.php', 'initialActive' => $activePage === 'overview']),
                ],
            ],
            [
                'title' => 'Users', 'icon' => 'fa-users',
                'items' => [
                    sbItem('All Users', 'fa-list', ['section' => 'users', 'href' => 'role-management.php', 'initialActive' => $activePage === 'users']),
                ],
            ],
            [
                'title' => 'Archive', 'icon' => 'fa-archive',
                'items' => [
                    sbItem('Residents', 'fa-user', ['href' => 'admin-archive.php?type=resident', 'initialActive' => $activePage === 'archive-resident']),
                    sbItem('Barangay', 'fa-building', ['href' => 'admin-archive.php?type=barangay', 'initialActive' => $activePage === 'archive-barangay']),
                    sbItem('Staff', 'fa-user-tie', ['href' => 'admin-archive.php?type=staff', 'initialActive' => $activePage === 'archive-staff']),
                ],
            ],
        ],
    ],
    'monitoring' => [
        'title' => 'Monitoring',
        'sections' => [
            [
                'title' => 'Logs', 'icon' => 'fa-history',
                'items' => [
                    sbItem('Activity Logs', 'fa-list-alt', ['section' => 'activity', 'href' => 'dashboard-admin.php#activity', 'initialActive' => $activePage === 'activity']),
                ],
            ],
        ],
    ],
    'system' => [
        'title' => 'System',
        'sections' => [
            [
                'title' => 'Configuration', 'icon' => 'fa-cog',
                'items' => [
                    sbItem('Settings', 'fa-cog', ['section' => 'settings', 'href' => 'dashboard-admin.php#settings', 'initialActive' => $activePage === 'settings']),
                ],
            ],
            [
                'title' => 'Session', 'icon' => 'fa-sign-out-alt',
                'items' => [
                    sbItem('Logout', 'fa-sign-out-alt', ['href' => 'logout-admin.php', 'logout' => true]),
                ],
            ],
        ],
    ],
];

$sbRail = [
    'user-management' => ['icon' => 'fa-chart-pie',      'label' => 'Dashboard', 'href' => 'dashboard-admin.php'],
    'monitoring'      => ['icon' => 'fa-history',        'label' => 'Activity',   'section' => 'activity', 'href' => 'dashboard-admin.php#activity'],
    'system'          => ['icon' => 'fa-cog',            'label' => 'System',     'section' => 'settings', 'href' => 'dashboard-admin.php#settings'],
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
    <div class="sb2-header">
      <div class="sb2-brand">
        <div class="sb2-brand-logo">
          <img src="uploads/site/DCVS LOGO.png" alt="DCVS" />
        </div>
        <div class="sb2-brand-text">
          <span class="sb2-brand-name">DCVS</span>
          <span class="sb2-brand-sub">Admin Portal</span>
        </div>
      </div>
    </div>

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
  </div>

  <button type="button" class="sb2-rail" id="sb2Rail" aria-label="Toggle Sidebar" title="Toggle Sidebar"></button>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar" id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
  </button>
  <div class="topbar-breadcrumb">
    <span class="breadcrumb-root">Admin Portal</span>
    <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
    <span class="breadcrumb-current" id="breadcrumbCurrent">User Management</span>
  </div>
  <div class="topbar-right">
    <div class="notif-dropdown" id="adminNotifDropdown">
      <button class="notif-btn" type="button" aria-label="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="notif-badge" data-realtime="notif-badge" style="<?php echo $adminUnreadNotificationCount > 0 ? '' : 'display:none;'; ?>"><?php echo $adminUnreadNotificationCount > 9 ? '9+' : $adminUnreadNotificationCount; ?></span>
      </button>
      <div class="notif-panel" id="adminNotifPanel" data-notification-panel
        data-portal="admin"
        data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
        data-action-url="<?php echo htmlspecialchars($notifAppBaseUrl . '/includes/staff-notification-action.php?portal=admin', ENT_QUOTES, 'UTF-8'); ?>"
        data-app-base-url="<?php echo htmlspecialchars($notifAppBaseUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-unread-count="<?php echo (int)$adminUnreadNotificationCount; ?>">
        <div class="notif-panel-header">
          <h4>Notifications</h4>
          <button class="notif-mark-all" type="button" <?php echo $adminUnreadNotificationCount === 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read</button>
        </div>
        <div class="notif-tabs" role="tablist" aria-label="Notification filters">
          <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $adminUnreadNotificationCount > 0 ? ($adminUnreadNotificationCount > 99 ? '99+' : $adminUnreadNotificationCount) : ''; ?></span></button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
        </div>
        <div class="notif-feedback" role="status" aria-live="polite"></div>
        <div class="notif-panel-body">
          <?php foreach (array_merge($adminNotifications, $adminArchivedNotifications) as $notification): ?>
            <?php
              $actorName = trim((string)($notification['actor_name'] ?? ''));
              $actorParts = preg_split('/\s+/', $actorName, -1, PREG_SPLIT_NO_EMPTY);
              $actorInitials = '';
              if (!empty($actorParts)) {
                  $actorInitials = strtoupper(substr($actorParts[0], 0, 1));
                  if (count($actorParts) > 1) $actorInitials .= strtoupper(substr($actorParts[count($actorParts) - 1], 0, 1));
              }
              $actorAvatar = trim((string)($notification['actor_avatar'] ?? ''));
              if ($actorAvatar !== '' && !preg_match('#^https?://#i', $actorAvatar) && $actorAvatar[0] !== '/') {
                  $actorAvatar = $notifAppBaseUrl . '/' . ltrim($actorAvatar, '/');
              }
            ?>
            <article class="notif-item <?php echo empty($notification['is_read']) ? 'unread' : ''; ?>" data-notif-item
              data-nid="<?php echo (int)$notification['notification_id']; ?>"
              data-unread="<?php echo empty($notification['is_read']) ? '1' : '0'; ?>"
              data-archived="<?php echo empty($notification['archived_at']) ? '0' : '1'; ?>">
              <div class="notif-avatar" aria-hidden="true" title="<?php echo htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($actorInitials, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($actorAvatar !== ''): ?><img src="<?php echo htmlspecialchars($actorAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy"><?php endif; ?>
              </div>
              <div class="notif-content">
                <div class="notif-row-heading">
                  <div class="notif-copy">
                    <div class="notif-title"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($notification['message'])): ?><div class="notif-msg"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                  </div>
                  <button type="button" class="notif-more" aria-label="Notification options" aria-expanded="false"><i class="fas fa-ellipsis-h" aria-hidden="true"></i></button>
                  <span class="notif-unread-dot" aria-label="Unread"></span>
                </div>
                <div class="notif-meta"><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime($notification['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span></div>
              </div>
              <div class="notif-row-menu" role="menu">
                <button type="button" role="menuitem" data-notif-action="<?php echo empty($notification['is_read']) ? 'read' : 'unread'; ?>"><i class="fas fa-check" aria-hidden="true"></i><?php echo empty($notification['is_read']) ? 'Mark as read' : 'Mark as unread'; ?></button>
                <button type="button" role="menuitem" data-notif-action="<?php echo empty($notification['archived_at']) ? 'archive' : 'unarchive'; ?>"><i class="fas fa-inbox" aria-hidden="true"></i><?php echo empty($notification['archived_at']) ? 'Archive' : 'Move to inbox'; ?></button>
              </div>
            </article>
          <?php endforeach; ?>
          <div class="notif-empty" hidden>No notifications yet.</div>
        </div>
      </div>
    </div>
    <!-- User Profile Pill -->
    <div class="user-profile" id="user-profile-btn" title="View Profile">
      <div class="user-avatar">
        <?php if (!empty($adminAvatar)): ?>
          <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        <?php endif; ?>
      </div>
      <div class="user-meta">
        <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
        <span class="user-role">Admin</span>
      </div>
    </div>
  </div>
</header>

<script src="<?php echo htmlspecialchars($notifAppBaseUrl . '/assets/js/notification-panel.js?v=' . (int)filemtime(__DIR__ . '/../assets/js/notification-panel.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<style>
  /* User Profile Pill */
  .user-profile { display: flex; align-items: center; gap: 10px; padding: 4px 8px; border-radius: 6px; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent; }
  .user-profile:hover { background-color: #F3F4F6; border-color: #F0F2F5; }
  .user-avatar { width: 34px; height: 34px; border-radius: 50%; background: #D5ECE0; border: 1px solid #B8DEC9; display: flex; align-items: center; justify-content: center; color: #0B4D26; font-weight: 700; font-size: 12px; overflow: hidden; }
  .user-meta { display: flex; flex-direction: column; line-height: 1.25; }
  .user-name { font-size: 14px; font-weight: 600; color: #18181B; }
  .user-role { font-size: 12px; color: #71717A; }
</style>

<style>
  #logoutModal.modal{background:rgba(0,0,0,.5);padding:1rem;z-index:1100;}
  #logoutModal .sd-logout-dialog{width:100%;max-width:400px;background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);}
  #logoutModal .sd-logout-title{margin:0 0 8px;color:#18181b;font:600 18px/1.25 Inter,'Segoe UI',sans-serif;}
  #logoutModal .sd-logout-description{margin:0;color:#71717a;font:400 14px/1.5 Inter,'Segoe UI',sans-serif;}
  #logoutModal .sd-logout-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:24px;}
  #logoutModal .sd-logout-actions a,#logoutModal .sd-logout-actions button{height:36px;padding:0 16px;border-radius:6px;font:500 14px/1 Inter,'Segoe UI',sans-serif;display:inline-flex;align-items:center;gap:7px;cursor:pointer;text-decoration:none;}
  #logoutModal .sd-logout-actions a{background:#18181b;color:#fafafa;border:1px solid #18181b;}
  #logoutModal .sd-logout-actions button{background:#fff;color:#18181b;border:1px solid #e4e4e7;}
</style>
<div class="modal" id="logoutModal">
  <div class="sd-logout-dialog" role="alertdialog" aria-modal="true" aria-labelledby="logoutDialogTitle">
    <h2 class="sd-logout-title" id="logoutDialogTitle">Confirm Logout</h2>
    <p class="sd-logout-description">Are you sure you want to logout of the Admin Portal?</p>
    <div class="sd-logout-actions">
      <button type="button" onclick="closeModal('logoutModal')">Cancel</button>
      <a href="logout-admin.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</div>

<script>
(function() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var toggle = document.getElementById('sidebarToggle');
  var rail = document.getElementById('sb2Rail');

  document.body.classList.add('sb2-active');

  function setDesktopState(collapsed) {
    sidebar.setAttribute('data-state', collapsed ? 'collapsed' : 'expanded');
    document.body.classList.toggle('sb2-collapsed', collapsed);
    try { localStorage.setItem('sb2Collapsed', collapsed ? '1' : '0'); } catch (e) {}
  }
  try {
    if (window.innerWidth > 768 && localStorage.getItem('sb2Collapsed') === '1') setDesktopState(true);
  } catch (e) {}

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

  var sectionToModule = {
    'users': 'user-management', 'roles': 'user-management',
    'activity': 'monitoring', 'settings': 'system'
  };

  function activateModule(moduleId) {
    if (!moduleId) return;
    document.querySelectorAll('.sb2-group').forEach(function(g) {
      g.classList.toggle('module-active', g.getAttribute('data-module') === moduleId);
    });
  }

  function activateSection(sectionId) {
    var moduleId = sectionToModule[sectionId];
    if (moduleId) activateModule(moduleId);
    var match = document.querySelector('.sb2-menu-sub-button[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-button[data-section="' + sectionId + '"]');
    document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(item) {
      if (match && item === match) return;
      item.classList.remove('active');
    });
    if (match) {
      match.classList.add('active');
      var inner = match.closest('.sb2-menu-item');
      var outer = inner && inner.parentElement ? inner.parentElement.closest('.sb2-menu-item') : null;
      var coll = outer ? outer.querySelector(':scope > .sb2-collapsible') : null;
      if (coll) { coll.classList.add('open'); coll.setAttribute('aria-expanded', 'true'); }
    }
  }

  var initHash = window.location.hash.replace('#', '');
  if (initHash && sectionToModule[initHash]) {
    activateSection(initHash);
  }
  window.addEventListener('hashchange', function() {
    var h = window.location.hash.replace('#', '');
    if (h && sectionToModule[h]) activateSection(h);
  });
})();

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('[data-logout="1"]').forEach(function(item) {
    item.addEventListener('click', function(e) {
      e.preventDefault();
      openModal('logoutModal');
    });
  });
  var logoutBtn = document.getElementById('sb2Logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function() {
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
document.querySelectorAll('.sb2-collapsible').forEach(function(btn) {
  btn.addEventListener('click', function() {
    btn.classList.toggle('open');
    btn.setAttribute('aria-expanded', btn.classList.contains('open'));
  });
});
</script>
