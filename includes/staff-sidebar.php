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
$appRootPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
$documentRootPath = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$appBaseUrl = '';
if ($appRootPath !== false && $documentRootPath !== false
    && strncasecmp($appRootPath, $documentRootPath, strlen($documentRootPath)) === 0) {
    $appBaseUrl = '/' . trim(str_replace(DIRECTORY_SEPARATOR, '/', substr($appRootPath, strlen($documentRootPath))), '/');
}
require_once __DIR__ . '/waitlist.php';
dcvsExpireWaitlistClaims($conn);
$waitlistBadgeCount = 0;
if ($waitlistCountStmt = $conn->prepare("SELECT COUNT(*) FROM appointment_waitlist WHERE status = 'waiting'")) {
    if ($waitlistCountStmt->execute()) {
        $waitlistBadgeCount = (int)$waitlistCountStmt->get_result()->fetch_row()[0];
    } else {
        error_log('Failed to load staff waitlist badge: ' . $waitlistCountStmt->error);
    }
    $waitlistCountStmt->close();
} else {
    error_log('Failed to prepare staff waitlist badge: ' . $conn->error);
}

// Fetch notifications for this staff user (shared across all staff pages)
$notifications = [];
$archivedNotifications = [];
$unreadNotifCount = 0;
if (isset($_SESSION['user_id'])) {
  $visibleNotificationFilter = "n.user_id = ?";
  $nq = $conn->prepare("
      SELECT n.*, activity_user.full_name AS activity_user_name, activity_user.avatar_url AS activity_avatar_url
      FROM notifications n
      LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
      LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
      LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
      LEFT JOIN users activity_user ON activity_user.user_id = CASE
        WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
        WHEN n.reference_type = 'report' THEN r.reporter_id
        WHEN n.reference_type = 'appointments' THEN a.user_id
        WHEN n.reference_type = 'pets' THEN p.owner_id
        WHEN n.reference_type = 'staff_message' THEN n.reference_id
        ELSE NULL
      END
      WHERE $visibleNotificationFilter AND n.archived_at IS NULL
      ORDER BY n.created_at DESC LIMIT 30");
  if ($nq) {
      $nq->bind_param("i", $_SESSION['user_id']);
      $nq->execute();
      $nr = $nq->get_result();
      while ($row = $nr->fetch_assoc()) $notifications[] = $row;
      $nq->close();
  } else {
      error_log('Failed to prepare staff notifications: ' . $conn->error);
  }
  $archivedNq = $conn->prepare("
      SELECT n.*, activity_user.full_name AS activity_user_name, activity_user.avatar_url AS activity_avatar_url
      FROM notifications n
      LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
      LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
      LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
      LEFT JOIN users activity_user ON activity_user.user_id = CASE
        WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
        WHEN n.reference_type = 'report' THEN r.reporter_id
        WHEN n.reference_type = 'appointments' THEN a.user_id
        WHEN n.reference_type = 'pets' THEN p.owner_id
        WHEN n.reference_type = 'staff_message' THEN n.reference_id
        ELSE NULL
      END
      WHERE $visibleNotificationFilter AND n.archived_at IS NOT NULL
      ORDER BY n.archived_at DESC LIMIT 30");
  if ($archivedNq) {
      $archivedNq->bind_param("i", $_SESSION['user_id']);
      $archivedNq->execute();
      $archivedResult = $archivedNq->get_result();
      while ($row = $archivedResult->fetch_assoc()) $archivedNotifications[] = $row;
      $archivedNq->close();
  } else {
      error_log('Failed to prepare archived staff notifications: ' . $conn->error);
  }
  $ucStmt = $conn->prepare("
      SELECT COALESCE(SUM(n.is_read = 0), 0) AS unread_count
      FROM notifications n
      LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
      WHERE $visibleNotificationFilter AND n.archived_at IS NULL");
  if ($ucStmt) {
      $ucStmt->bind_param("i", $_SESSION['user_id']);
      $ucStmt->execute();
      $notifCounts = $ucStmt->get_result()->fetch_assoc();
      $unreadNotifCount = (int)($notifCounts['unread_count'] ?? 0);
      $ucStmt->close();
  } else {
      error_log('Failed to prepare staff notification counts: ' . $conn->error);
  }
}

// ── Map the current page to the matching sidebar module ──
// dashboard-staff.php keeps $activePage = 'overview' server-side; the real
// section is resolved client-side from the URL hash (see SB2 JS below).
$sbPageToModule = [
    'availability'   => 'operations',
    'waitlist'       => 'operations',
    'geo'            => 'monitoring',
    'announcements'  => 'communication',
    'communication'  => 'communication',
    'pet-listings'   => 'operations',
    'activity-logs'  => 'monitoring',
    'archive'        => 'monitoring',
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
                    sbItem('Cases Analytics', 'fa-chart-pie', ['section' => 'analytics', 'href' => 'dashboard-staff.php#cases-analytics', 'fn' => 'aethGo', 'arg' => 'engine']),
                    sbItem('Appointment Overview', 'fa-calendar-check', ['section' => 'analytics', 'href' => 'dashboard-staff.php#appointment-overview', 'fn' => 'aethGo', 'arg' => 'intel']),
                ],
            ],
            [
                'title' => 'City Drives', 'icon' => 'fa-city',
                'items' => [
                    sbItem('City Drives', 'fa-city', ['section' => 'analytics', 'href' => 'dashboard-staff.php#city-drives', 'fn' => 'aethGo', 'arg' => 'citydrives']),
                    sbItem('Programs & Services', 'fa-layer-group', ['section' => 'analytics', 'href' => 'dashboard-staff.php#programs-services', 'fn' => 'aethGo', 'arg' => 'architect']),
                ],
            ],
            [
                'title' => 'Adoption', 'icon' => 'fa-paw',
                'items' => [
                    sbItem('Adoption Insights', 'fa-chart-bar', ['section' => 'adoption', 'href' => 'dashboard-staff.php#adoption', 'fn' => 'aethGo', 'arg' => 'budget']),
                    sbItem('Adoption Pool', 'fa-dog', ['href' => 'adoption-pool.php']),
                ],
            ],
            [
                'title' => 'Reports', 'icon' => 'fa-file-medical-alt',
                'items' => [
                    sbItem('All Reports', 'fa-list', ['section' => 'reports-all', 'href' => 'dashboard-staff.php#reports-all']),
                    sbItem('Pending Validation', 'fa-hourglass-half', ['section' => 'reports-pending', 'href' => 'dashboard-staff.php#reports-pending']),
                    sbItem('Active Cases', 'fa-clipboard-check', ['section' => 'reports-active', 'href' => 'dashboard-staff.php#reports-active']),
                    sbItem('Resolved Cases', 'fa-check-circle', ['section' => 'reports-resolved', 'href' => 'dashboard-staff.php#reports-resolved']),
                ],
            ],
            [
                'title' => 'Appointments', 'icon' => 'fa-calendar-alt',
                'items' => [
                    sbItem('Appointment Requests', 'fa-calendar-check', ['section' => 'appointments', 'href' => 'dashboard-staff.php#appointments']),
                    sbItem('Waitlist', 'fa-list-alt', ['href' => 'staff-waitlist.php', 'badge' => $waitlistBadgeCount, 'initialActive' => $activePage === 'waitlist']),
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
            [
                'title' => 'Archive', 'icon' => 'fa-archive',
                'items' => [
                    sbItem('Reports', 'fa-file-medical-alt', ['href' => 'staff-archive.php?type=reports', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'reports']),
                    sbItem('Appointments', 'fa-calendar-check', ['href' => 'staff-archive.php?type=appointments', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'appointments']),
                    sbItem('Clients', 'fa-users', ['href' => 'staff-archive.php?type=clients', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'clients']),
                    sbItem('Pets', 'fa-paw', ['href' => 'staff-archive.php?type=pets', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'pets']),
                    sbItem('Rescued Animals', 'fa-dog', ['href' => 'staff-archive.php?type=rescued', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'rescued']),
                    sbItem('Announcements', 'fa-bullhorn', ['href' => 'staff-archive.php?type=announcements', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'announcements']),
                    sbItem('Program', 'fa-layer-group', ['href' => 'staff-archive.php?type=programs', 'initialActive' => $activePage === 'archive' && ($_GET['type'] ?? '') === 'programs']),
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
window.DCVS_PORTAL_ROLE = 'staff';
window.DCVS_PORTAL_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/portal-form-drafts.js', ENT_QUOTES, 'UTF-8'); ?>?v=20261007-2"></script>

<aside class="sb2" id="sidebar" data-state="expanded" data-collapsible="icon">
  <div class="sb2-inner">

    <!-- SidebarHeader: brand -->
    <div class="sb2-header">
      <div class="sb2-brand">
        <div class="sb2-brand-logo">
          <img src="uploads/site/DCVS LOGO.png" alt="DCVS" />
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
              if (!empty($sec['sub'])) {
                foreach ($sec['sub'] as $subSec) {
                  foreach ($subSec['items'] as $item) {
                    if (!empty($item['initialActive'])) $secHasActive = true;
                  }
                }
              }
              $single = count($sec['items']) === 1 && empty($sec['sub']);
            ?>
              <?php if ($single):
                $item = $sec['items'][0]; ?>
                <li class="sb2-menu-item">
                  <a href="<?php echo htmlspecialchars($item['href'] ?? '#'); ?>"
                     class="sb2-menu-button <?php echo !empty($item['initialActive']) ? 'active' : ''; ?>"
                     title="<?php echo htmlspecialchars($item['label']); ?>"
                     <?php echo sbAttrs($item); ?>>
                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['label']); ?><?php if (isset($item['badge']) && (int)$item['badge'] > 0): ?> <span class="sb2-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?></span>
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
                          <span><?php echo htmlspecialchars($item['label']); ?><?php if (isset($item['badge']) && (int)$item['badge'] > 0): ?> <span class="sb2-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?></span>
                        </a>
                      </li>
                    <?php endforeach; ?>
                    <?php if (!empty($sec['sub'])): ?>
                      <?php foreach ($sec['sub'] as $subSec): ?>
                        <li class="sb2-menu-item">
                          <button type="button"
                            class="sb2-menu-button sb2-collapsible sb2-sub-collapsible <?php echo $secHasActive ? 'open' : ''; ?>"
                            aria-expanded="<?php echo $secHasActive ? 'true' : 'false'; ?>"
                            title="<?php echo htmlspecialchars($subSec['title']); ?>">
                            <i class="fas <?php echo htmlspecialchars($subSec['icon'] ?? 'fa-circle'); ?>"></i>
                            <span><?php echo htmlspecialchars($subSec['title']); ?></span>
                            <svg class="sb2-caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                          </button>
                          <ul class="sb2-menu-sub">
                            <?php foreach ($subSec['items'] as $item): ?>
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
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </ul>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
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
  <div class="topbar-breadcrumb">
    <span class="breadcrumb-root">Staff Portal</span>
    <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
    <span class="breadcrumb-current" id="breadcrumbCurrent"><?php
      $breadcrumbMap = [
        'overview' => 'Dashboard', 'analytics' => 'Cases Analytics', 'adoption' => 'Adoption Insights',
        'reports-all' => 'All Reports', 'reports-pending' => 'Pending Validation',
        'reports-active' => 'Active Cases', 'reports-resolved' => 'Resolved Cases',
        'appointments' => 'Appointment Requests', 'calendar-schedule' => 'Calendar Schedule',
        'client-pets' => 'All Clients', 'communication' => 'Communication',
        'adoption-pool' => 'Adoption Pool', 'announcements' => 'Announcements',
      ];
      echo htmlspecialchars($breadcrumbMap[$activePage] ?? 'Dashboard');
    ?></span>
  </div>
  <div class="topbar-right">
    <!-- Notification Bell -->
    <div class="notif-dropdown" id="notifDropdown">
      <button class="notif-btn" id="notifBell" onclick="toggleNotif()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if (isset($unreadNotifCount) && $unreadNotifCount > 0): ?>
          <span class="notif-badge" data-realtime="notif-badge"><?php echo $unreadNotifCount > 9 ? '9+' : $unreadNotifCount; ?></span>
        <?php endif; ?>
      </button>
      <div class="notif-panel" id="notifPanel" data-notification-panel
        data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
        data-action-url="<?php echo htmlspecialchars($appBaseUrl . '/includes/staff-notification-action.php', ENT_QUOTES, 'UTF-8'); ?>"
        data-app-base-url="<?php echo htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-unread-count="<?php echo (int)$unreadNotifCount; ?>">
        <div class="notif-panel-header">
          <h4>Notifications</h4>
          <button class="notif-mark-all" type="button" onclick="markAllNotifRead()" <?php echo $unreadNotifCount === 0 ? 'disabled' : ''; ?>>
            <i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read
          </button>
        </div>
        <div class="notif-tabs" role="tablist" aria-label="Notification filters">
          <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadNotifCount > 0 ? ($unreadNotifCount > 99 ? '99+' : $unreadNotifCount) : ''; ?></span></button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
          <span class="notif-tabs-spacer"></span>
        </div>
        <div class="notif-feedback" id="notifFeedback" role="status" aria-live="polite"></div>
        <div class="notif-panel-body" id="notificationList">
          <?php foreach (array_merge($notifications, $archivedNotifications) as $n): ?>
          <?php
            $notifType = (string)$n['type'];
            $context = trim((string)($n['reference_type'] ?? ''));
              if ($context !== '') $context = ucfirst($context);
              if (!empty($n['reference_id'])) $context .= ($context !== '' ? ' · ' : '') . '#' . (int)$n['reference_id'];
              $createdAt = strtotime($n['created_at']);
              $ageSeconds = max(0, time() - ($createdAt ?: time()));
              if ($ageSeconds < 60) $relativeTime = 'Just now';
              elseif ($ageSeconds < 3600) $relativeTime = floor($ageSeconds / 60) . ' min ago';
              elseif ($ageSeconds < 86400) $relativeTime = floor($ageSeconds / 3600) . ' hr ago';
              elseif ($ageSeconds < 604800) {
                $daysAgo = (int)floor($ageSeconds / 86400);
                $relativeTime = $daysAgo . ' day' . ($daysAgo === 1 ? '' : 's') . ' ago';
              }
              else $relativeTime = date('M j', $createdAt ?: time());
              $activityName = trim((string)($n['activity_user_name'] ?? ''));
              $nameParts = preg_split('/\s+/', $activityName, -1, PREG_SPLIT_NO_EMPTY);
              $activityInitials = '';
              if (!empty($nameParts)) {
                $activityInitials = strtoupper(substr($nameParts[0], 0, 1));
                if (count($nameParts) > 1) $activityInitials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
              }
              $activityAvatar = trim((string)($n['activity_avatar_url'] ?? ''));
              if ($activityAvatar !== '' && !preg_match('#^https?://#i', $activityAvatar) && $activityAvatar[0] !== '/') {
                $activityAvatar = $appBaseUrl . '/' . ltrim($activityAvatar, '/');
              }
            ?>
            <article class="notif-item <?php echo empty($n['is_read']) ? 'unread' : ''; ?>"
              data-notif-item data-nid="<?php echo (int)$n['notification_id']; ?>"
              data-type="<?php echo htmlspecialchars($notifType, ENT_QUOTES, 'UTF-8'); ?>"
              data-unread="<?php echo empty($n['is_read']) ? '1' : '0'; ?>"
              data-archived="<?php echo empty($n['archived_at']) ? '0' : '1'; ?>">
              <div class="notif-avatar" aria-hidden="true" title="<?php echo htmlspecialchars($activityName, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($activityInitials, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($activityAvatar !== ''): ?>
                  <img src="<?php echo htmlspecialchars($activityAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                <?php endif; ?>
              </div>
              <div class="notif-content">
                <div class="notif-row-heading">
                  <div class="notif-copy">
                    <div class="notif-title"><?php echo htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($n['message'])): ?>
                      <div class="notif-msg"><?php echo htmlspecialchars(trim(preg_replace('/\s+/', ' ', strip_tags((string)$n['message']))), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                  </div>
                  <button type="button" class="notif-more" aria-label="Notification options" aria-expanded="false">
                    <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                  </button>
                  <span class="notif-unread-dot" aria-label="Unread"></span>
                </div>
                <div class="notif-meta">
                  <span><?php echo htmlspecialchars($relativeTime, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if ($context !== ''): ?><span class="notif-meta-separator">·</span><span class="notif-context"><?php echo htmlspecialchars($context, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                </div>
              </div>
              <div class="notif-row-menu" role="menu">
                <button type="button" role="menuitem" data-notif-action="<?php echo empty($n['is_read']) ? 'read' : 'unread'; ?>">
                  <i class="fas fa-check" aria-hidden="true"></i><?php echo empty($n['is_read']) ? 'Mark as read' : 'Mark as unread'; ?>
                </button>
                <button type="button" role="menuitem" data-notif-action="<?php echo empty($n['archived_at']) ? 'archive' : 'unarchive'; ?>">
                  <i class="fas fa-inbox" aria-hidden="true"></i><?php echo empty($n['archived_at']) ? 'Archive' : 'Move to inbox'; ?>
                </button>
              </div>
            </article>
          <?php endforeach; ?>
          <div class="notif-empty" id="notifEmpty" hidden>No notifications yet.</div>
        </div>
      </div>
    </div>

    <!-- User Profile Pill -->
    <div class="user-profile" id="user-profile-btn" title="View Profile">
      <div class="user-avatar">
        <?php if (!empty($staffAvatar)): ?>
          <img src="<?php echo htmlspecialchars($staffAvatar); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        <?php endif; ?>
      </div>
      <div class="user-meta">
        <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
        <span class="user-role">Staff</span>
      </div>
    </div>
  </div>
</header>

<style>
  /* Notification Bell */
  .notif-dropdown { position: relative; }
  .notif-btn { background: none; border: none; font-size: 1.2rem; color: #475569; cursor: pointer; padding: 6px; position: relative; border-radius: 8px; transition: background 0.15s; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; }
  .notif-btn:hover { background: #f1f5f9; }
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
  .notif-item.unread:hover { background: #dcebe0; }
  .notif-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; background: transparent; color: #000; }
  .notif-content { flex: 1; min-width: 0; }
  .notif-title { font-size: 0.8rem; font-weight: 700; color: #0f172a; }
  .notif-msg { font-size: 0.74rem; color: #64748b; line-height: 1.3; margin-top: 1px; }
  .notif-time { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }
  .notif-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.82rem; }

  /* User Profile Pill */
  .user-profile { display: flex; align-items: center; gap: 10px; padding: 4px 8px; border-radius: 6px; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent; }
  .user-profile:hover { background-color: #F3F4F6; border-color: #F0F2F5; }
  .user-avatar { width: 34px; height: 34px; border-radius: 50%; background: #D5ECE0; border: 1px solid #B8DEC9; display: flex; align-items: center; justify-content: center; color: #0B4D26; font-weight: 700; font-size: 12px; overflow: hidden; }
  .user-meta { display: flex; flex-direction: column; line-height: 1.25; }
  .user-name { font-size: 14px; font-weight: 600; color: #18181B; }
  .user-role { font-size: 12px; color: #71717A; }
</style>
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/notification.css?v=' . (int)filemtime(__DIR__ . '/../assets/css/notification.css'), ENT_QUOTES, 'UTF-8'); ?>" />

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
    <p class="sd-logout-description">Are you sure you want to logout of the Staff Portal?</p>
    <div class="sd-logout-actions">
      <button type="button" onclick="closeModal('logoutModal')">Cancel</button>
      <a href="logout-staff.php" onclick="localStorage.removeItem('staffActiveTab')"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
    'adoption': 'operations',
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

    var bcLabels = {
      'overview':'Dashboard','analytics':'Cases Analytics','adoption':'Adoption Insights',
      'reports-all':'All Reports','reports-pending':'Pending Validation',
      'reports-active':'Active Cases','reports-resolved':'Resolved Cases',
      'appointments':'Appointment Requests','calendar-schedule':'Calendar Schedule',
      'client-pets':'All Clients','client-pets-with':'With Pets','client-pets-none':'No Pets'
    };
    var bc = document.getElementById('breadcrumbCurrent');
    if (bc) bc.textContent = bcLabels[sectionId] || sectionId;
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
  var saved = null;
  try { saved = JSON.parse(localStorage.getItem('sb2State') || 'null'); } catch (e) {}
  if (initHash && sectionToModule[initHash]) {
    activateSection(initHash);
    if (saved && saved.activeKey) {
      document.querySelectorAll('.sb2-menu-button:not(.sb2-collapsible), .sb2-menu-sub-button').forEach(function(item) {
        if (getItemKey(item) === saved.activeKey && item.getAttribute('data-section') === initHash) {
          activateItem(item);
        }
      });
    }
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

function toggleNotif() {
  var panel = document.getElementById('notifPanel');
  if (!panel) return;
  panel.classList.toggle('open');
  if (panel.classList.contains('open')) refreshNotifView();
  else closeNotifMenus();
}

document.addEventListener('click', function(e) {
  var dd = document.getElementById('notifDropdown');
  if (dd && !dd.contains(e.target)) {
    var panel = document.getElementById('notifPanel');
    if (panel) panel.classList.remove('open');
  }
  if (!e.target.closest('.notif-row-menu, .notif-more')) closeNotifMenus();
});

function closeNotifMenus() {
  document.querySelectorAll('.notif-row-menu.open').forEach(function(menu) {
    menu.classList.remove('open');
    var trigger = menu.closest('.notif-item').querySelector('.notif-more');
    trigger.setAttribute('aria-expanded', 'false');
  });
}

function showNotifFeedback(message) {
  var feedback = document.getElementById('notifFeedback');
  if (!feedback) return;
  feedback.textContent = message || '';
  feedback.classList.toggle('visible', !!message);
}

function setNotifCount(name, value) {
  var panel = document.getElementById('notifPanel');
  if (!panel) return;
  value = Math.max(0, value);
  panel.dataset[name + 'Count'] = value;
  var tabCount = panel.querySelector('[data-tab-count="' + name + '"]');
  if (tabCount) tabCount.textContent = value > 99 ? '99+' : (value > 0 ? String(value) : '');
  if (name === 'unread') {
    var badge = document.querySelector('[data-realtime="notif-badge"]');
    var markAll = panel.querySelector('.notif-mark-all');
    if (badge) {
      badge.textContent = value > 9 ? '9+' : String(value);
      badge.style.display = value > 0 ? 'flex' : 'none';
    }
    if (markAll) markAll.disabled = value === 0;
  }
}

function refreshNotifView() {
  var panel = document.getElementById('notifPanel');
  if (!panel) return;
  var activeTab = panel.querySelector('.notif-tab.active');
  var tab = activeTab ? activeTab.dataset.notifTab : 'all';
  var visibleCount = 0;
  panel.querySelectorAll('[data-notif-item]').forEach(function(item) {
    var unread = item.dataset.unread === '1';
    var archived = item.dataset.archived === '1';
    var visible = tab === 'inbox' ? (!archived && unread)
      : tab === 'archived' ? archived
      : !archived;
    item.hidden = !visible;
    if (visible) visibleCount++;
  });
  var empty = document.getElementById('notifEmpty');
  if (empty) {
    empty.hidden = visibleCount > 0;
    empty.textContent = tab === 'inbox' ? 'You’re all caught up.'
      : tab === 'archived' ? 'No archived notifications.'
      : 'No notifications yet.';
  }
}

function positionNotifMenu(trigger, menu) {
  var triggerRect = trigger.getBoundingClientRect();
  var panelRect = document.getElementById('notifPanel').getBoundingClientRect();
  var menuRect = menu.getBoundingClientRect();
  var top = triggerRect.bottom + 5;
  if (top + menuRect.height > Math.min(panelRect.bottom, window.innerHeight - 8)) {
    top = triggerRect.top - menuRect.height - 5;
  }
  top = Math.max(8, Math.min(top, window.innerHeight - menuRect.height - 8));
  var left = Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - 8);
  left = Math.max(8, Math.max(panelRect.left + 8, left));
  menu.style.top = top + 'px';
  menu.style.left = left + 'px';
}

function updateNotifRow(row, action) {
  var panel = document.getElementById('notifPanel');
  var wasUnread = row.dataset.unread === '1' && row.dataset.archived !== '1';
  if (action === 'read') row.dataset.unread = '0';
  if (action === 'unread') row.dataset.unread = '1';
  if (action === 'archive') row.dataset.archived = '1';
  if (action === 'unarchive') row.dataset.archived = '0';
  row.classList.toggle('unread', row.dataset.unread === '1');

  var isUnread = row.dataset.unread === '1' && row.dataset.archived !== '1';
  var unreadCount = Number(panel.dataset.unreadCount || 0) + Number(isUnread) - Number(wasUnread);
  setNotifCount('unread', unreadCount);

  var labels = {
    read: ['unread', 'Mark as unread'],
    unread: ['read', 'Mark as read'],
    archive: ['unarchive', 'Move to inbox'],
    unarchive: ['archive', 'Archive']
  };
  var next = labels[action];
  var button = row.querySelector('[data-notif-action="' + action + '"]');
  if (button && next) {
    button.dataset.notifAction = next[0];
    button.lastChild.textContent = next[1];
  }
  refreshNotifView();
}

function requestNotifAction(action, notificationId) {
  var panel = document.getElementById('notifPanel');
  var body = new URLSearchParams({
    csrf_token: panel.dataset.csrf || '',
    action: action
  });
  if (notificationId) body.set('notification_id', notificationId);
  return fetch(panel.dataset.actionUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  }).then(function(response) {
    return response.json().then(function(data) {
      if (!response.ok || !data.success) throw new Error(data.message || 'Could not update notifications.');
      return data;
    });
  });
}

function markAllNotifRead() {
  requestNotifAction('mark_all_read').then(function() {
    document.querySelectorAll('#notificationList [data-notif-item][data-archived="0"]').forEach(function(row) {
      row.dataset.unread = '0';
      row.classList.remove('unread');
      var button = row.querySelector('[data-notif-action="read"]');
      if (button) {
        button.dataset.notifAction = 'unread';
        button.lastChild.textContent = 'Mark as unread';
      }
    });
    setNotifCount('unread', 0);
    showNotifFeedback('');
    refreshNotifView();
  }).catch(function(error) {
    showNotifFeedback(error.message);
  });
}

document.addEventListener('click', function(event) {
  var tab = event.target.closest('[data-notif-tab]');
  if (tab) {
    var panel = document.getElementById('notifPanel');
    panel.querySelectorAll('[data-notif-tab]').forEach(function(button) {
      var selected = button === tab;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    closeNotifMenus();
    refreshNotifView();
    return;
  }

  var trigger = event.target.closest('.notif-more');
  if (trigger) {
    event.stopPropagation();
    var row = trigger.closest('[data-notif-item]');
    var menu = row.querySelector('.notif-row-menu');
    var wasOpen = menu.classList.contains('open');
    closeNotifMenus();
    if (!wasOpen) {
      menu.classList.add('open');
      trigger.setAttribute('aria-expanded', 'true');
      positionNotifMenu(trigger, menu);
    }
    return;
  }

  var actionButton = event.target.closest('[data-notif-action]');
  if (actionButton) {
    var item = actionButton.closest('[data-notif-item]');
    var action = actionButton.dataset.notifAction;
    actionButton.disabled = true;
    requestNotifAction(action, item.dataset.nid).then(function() {
      updateNotifRow(item, action);
      showNotifFeedback('');
    }).catch(function(error) {
      showNotifFeedback(error.message);
    }).finally(function() {
      actionButton.disabled = false;
      closeNotifMenus();
    });
  }
});

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    closeNotifMenus();
    var panel = document.getElementById('notifPanel');
    if (panel) panel.classList.remove('open');
  }
});

function viewProfilePhoto() {
  if (typeof openModal === 'function') openModal('profilePhotoModal');
}
</script>