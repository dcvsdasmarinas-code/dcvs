<?php
// ── Self-contained user data (no dependency on page-defined variables) ──
if (!isset($fullName)) {
    $fullName = $_SESSION['full_name'] ?? 'Resident';
}
if (!isset($residentAvatar)) {
    $residentAvatar = $_SESSION['avatar_url'] ?? '';
    // If still empty, fetch from DB
    if (empty($residentAvatar) && isset($_SESSION['user_id'])) {
        $aq = $conn->prepare("SELECT full_name, avatar_url FROM users WHERE user_id = ?");
        if ($aq) {
            $aq->bind_param("i", $_SESSION['user_id']);
            $aq->execute();
            $ar = $aq->get_result();
            if ($row = $ar->fetch_assoc()) {
                $fullName = $row['full_name'] ?? $fullName;
                $residentAvatar = $row['avatar_url'] ?? '';
                $_SESSION['full_name'] = $fullName;
                $_SESSION['avatar_url'] = $residentAvatar;
            }
            $aq->close();
        }
    }
}
if (!isset($barangayName)) {
    $barangayName = $_SESSION['barangay'] ?? '';
}
if (!isset($activePage)) $activePage = '';
if (!isset($pageTitle)) $pageTitle = 'Resident Portal';
$notifRootPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
$notifDocumentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$notifAppBaseUrl = '';
if ($notifRootPath !== false && $notifDocumentRoot !== false
    && strncasecmp($notifRootPath, $notifDocumentRoot, strlen($notifDocumentRoot)) === 0) {
    $notifAppBaseUrl = '/' . trim(str_replace(DIRECTORY_SEPARATOR, '/', substr($notifRootPath, strlen($notifDocumentRoot))), '/');
}

// Fetch staff_message notifications for this resident (for bell icon)
$staffMessages = [];
$archivedStaffMessages = [];
$unreadMsgCount = 0;
if (isset($_SESSION['user_id'])) {
    $nq = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'staff_message' AND archived_at IS NULL ORDER BY created_at DESC LIMIT 30");
    if ($nq) {
        $nq->bind_param("i", $_SESSION['user_id']);
        $nq->execute();
        $nr = $nq->get_result();
        while ($row = $nr->fetch_assoc()) $staffMessages[] = $row;
        $nq->close();
    }
    $archivedQuery = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'staff_message' AND archived_at IS NOT NULL ORDER BY archived_at DESC LIMIT 30");
    if ($archivedQuery) {
        $archivedQuery->bind_param("i", $_SESSION['user_id']);
        $archivedQuery->execute();
        $archivedResult = $archivedQuery->get_result();
        while ($row = $archivedResult->fetch_assoc()) $archivedStaffMessages[] = $row;
        $archivedQuery->close();
    }
    $staffMessages = array_merge($staffMessages, $archivedStaffMessages);
    // Enrich each notification with message_type label
    $typeLabels = ['general'=>'General','report'=>'Report','appointment'=>'Appointment','adoption'=>'Adoption','followup'=>'Follow-up','other'=>'Other'];
    foreach ($staffMessages as &$n) {
        if (!empty($n['message_type']) && isset($typeLabels[$n['message_type']])) {
            $n['message_type_label'] = $typeLabels[$n['message_type']];
        } else {
            $n['message_type_label'] = '';
        }
    }
    unset($n);
    // Enrich with sender avatar/name from users table (reference_id stores the staff user_id)
    $staffIds = array_unique(array_filter(array_map(fn($m) => $m['reference_id'] ?? 0, $staffMessages)));
    if (!empty($staffIds)) {
        $ids = implode(',', array_map('intval', $staffIds));
        $sq = $conn->query("SELECT user_id, avatar_url, full_name FROM users WHERE user_id IN ($ids)");
        if ($sq) {
            $senderMap = [];
            while ($srow = $sq->fetch_assoc()) $senderMap[$srow['user_id']] = $srow;
            foreach ($staffMessages as &$n) {
                $rid = $n['reference_id'] ?? 0;
                if ($rid && isset($senderMap[$rid])) {
                    $n['sender_avatar'] = $senderMap[$rid]['avatar_url'];
                    $n['sender_name'] = $senderMap[$rid]['full_name'];
                }
            }
            unset($n);
        }
    }
    // Count only unread staff_message notifications (what this dropdown shows)
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'staff_message' AND is_read = 0 AND archived_at IS NULL");
    if ($ucStmt) {
        $ucStmt->bind_param("i", $_SESSION['user_id']);
        $ucStmt->execute();
        $unreadMsgCount = (int)$ucStmt->get_result()->fetch_row()[0];
        $ucStmt->close();
    }
}

// ── Map the current page to the matching sidebar module ──
$sbActiveModule = ($activePage === 'account-settings') ? 'system' : 'main';

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
    if (!empty($item['conditional'])) $a .= ' data-conditional="' . htmlspecialchars($item['conditional']) . '"';
    return $a;
}

// ── Sidebar content model (all modules are rendered; JS shows the active one) ──
// Each module is a group dropdown; each section renders as a nested dropdown
// whose toggle label is the section title, with the section's items as options.
// Single-item sections render as a plain menu item instead of a dropdown.
$sbModules = [
    'main' => [
        'title' => 'Main',
        'sections' => [
            [
                'title' => 'Dashboard', 'icon' => 'fa-th-large',
                'items' => [
                    sbItem('Dashboard', 'fa-th-large', ['section' => 'overview', 'href' => 'dashboard-resident.php#overview', 'initialActive' => $activePage === 'overview']),
                ],
            ],
            [
                'title' => 'Pets', 'icon' => 'fa-paw',
                'items' => [
                    sbItem('Pet List', 'fa-list', ['section' => 'my-pets', 'href' => 'dashboard-resident.php#my-pets', 'initialActive' => $activePage === 'my-pets']),
                    sbItem('Adoption Listings', 'fa-paw', ['section' => 'adoption', 'href' => 'dashboard-resident.php#adoption', 'initialActive' => $activePage === 'adoption']),
                ],
            ],
            [
                'title' => 'Reports', 'icon' => 'fa-file-medical-alt',
                'items' => [
                    sbItem('Reports', 'fa-file-medical-alt', ['section' => 'my-reports', 'href' => 'dashboard-resident.php#my-reports', 'initialActive' => $activePage === 'my-reports']),
                    sbItem('Submit Report', 'fa-file-medical-alt', ['section' => 'submit-report', 'href' => 'submit-report-page.php', 'initialActive' => $activePage === 'submit-report']),
                ],
            ],
            [
                'title' => 'Appointments', 'icon' => 'fa-calendar-alt',
                'items' => [
                    sbItem('Appointments', 'fa-calendar-alt', ['section' => 'appointments', 'href' => 'dashboard-resident.php#appointments', 'initialActive' => $activePage === 'appointments']),
                    sbItem('My Waitlist', 'fa-list-alt', ['section' => 'my-waitlist', 'href' => 'dashboard-resident.php#my-waitlist', 'initialActive' => $activePage === 'my-waitlist']),
                    sbItem('Schedule Appointment', 'fa-calendar-plus', ['section' => 'book-appt', 'href' => 'dashboard-resident.php#book-appt', 'initialActive' => $activePage === 'book-appt']),
                    sbItem('Calendar Booking', 'fa-calendar-check', ['section' => 'calendar-booking', 'href' => 'dashboard-resident.php#calendar-booking', 'initialActive' => $activePage === 'calendar-booking']),
                ],
            ],
            [
                'title' => 'Archive', 'icon' => 'fa-archive',
                'items' => [
                    sbItem('Pets', 'fa-dog', ['section' => 'archive', 'href' => 'dashboard-resident.php#archive', 'fn' => 'setArchiveType', 'arg' => 'pet', 'initialActive' => $activePage === 'archive']),
                    sbItem('Reports', 'fa-file-medical-alt', ['section' => 'archive', 'href' => 'dashboard-resident.php#archive', 'fn' => 'setArchiveType', 'arg' => 'report']),
                    sbItem('Appointments', 'fa-calendar-alt', ['section' => 'archive', 'href' => 'dashboard-resident.php#archive', 'fn' => 'setArchiveType', 'arg' => 'appointment']),
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
                    sbItem('Account Settings', 'fa-user-cog', ['section' => 'account-settings', 'href' => 'dashboard-resident.php#account-settings', 'initialActive' => $activePage === 'account-settings']),
                ],
            ],
            [
                'title' => 'Logout', 'icon' => 'fa-sign-out-alt',
                'items' => [
                    sbItem('Logout', 'fa-sign-out-alt', ['href' => 'logout.php', 'logout' => true]),
                ],
            ],
        ],
    ],
];
?>
<style>
  .sb2-menu-item.sb2-conditional { display:none; }
  .sb2-menu-item.sb2-conditional.visible { display:block; }
  #logoutModal.modal{background:rgba(0,0,0,.5);padding:1rem;z-index:1100;}
  #logoutModal .sd-logout-dialog{width:100%;max-width:400px;background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);}
  #logoutModal .sd-logout-title{margin:0 0 8px;color:#18181b;font:600 18px/1.25 Inter,'Segoe UI',sans-serif;}
  #logoutModal .sd-logout-description{margin:0;color:#71717a;font:400 14px/1.5 Inter,'Segoe UI',sans-serif;}
  #logoutModal .sd-logout-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:24px;}
  #logoutModal .sd-logout-actions a,#logoutModal .sd-logout-actions button{height:36px;padding:0 16px;border-radius:6px;font:500 14px/1 Inter,'Segoe UI',sans-serif;display:inline-flex;align-items:center;gap:7px;cursor:pointer;text-decoration:none;}
  #logoutModal .sd-logout-actions a{background:#18181b;color:#fafafa;border:1px solid #18181b;}
  #logoutModal .sd-logout-actions button{background:#fff;color:#18181b;border:1px solid #e4e4e7;}
</style>
<script>
window.SB2_CONFIG = {
  activeModule: <?php echo json_encode($sbActiveModule); ?>,
  activePage: <?php echo json_encode($activePage); ?>
};
window.DCVS_PORTAL_ROLE = 'resident';
window.DCVS_PORTAL_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
<script src="<?php echo htmlspecialchars($notifAppBaseUrl . '/assets/js/portal-form-drafts.js', ENT_QUOTES, 'UTF-8'); ?>?v=20261007"></script>

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
          <span class="sb2-brand-sub">Resident Portal</span>
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
                      <li class="sb2-menu-item<?php echo !empty($item['conditional']) ? ' sb2-conditional' : ''; ?>"<?php echo !empty($item['conditional']) ? ' data-conditional="' . htmlspecialchars($item['conditional']) . '"' : ''; ?>>
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

  <!-- SidebarRail: desktop collapse handle -->
  <button type="button" class="sb2-rail" id="sb2Rail" aria-label="Toggle Sidebar" title="Toggle Sidebar"></button>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar" id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
  </button>
  <div class="topbar-breadcrumb">
    <span class="breadcrumb-root">Resident Portal</span>
    <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
    <span class="breadcrumb-current" id="breadcrumbCurrent"><?php
      $breadcrumbMap = [
        'overview' => 'Dashboard', 'my-pets' => 'Pet List', 'my-reports' => 'My Reports',
        'submit-report' => 'Submit Report', 'appointments' => 'Appointments', 'book-appt' => 'Schedule Appointment',
        'calendar-booking' => 'Calendar Booking', 'adoption' => 'Adoption Listings', 'archive' => 'Archive',
        'account-settings' => 'Account Settings'
      ];
      echo htmlspecialchars($breadcrumbMap[$activePage] ?? 'Dashboard');
    ?></span>
  </div>
  <div class="topbar-right">
    <!-- Notification Bell -->
    <div class="notif-dropdown" id="msgDropdown">
      <button class="notif-btn" id="msgBell" type="button">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="notif-badge" data-realtime="notif-badge" style="<?php echo $unreadMsgCount > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadMsgCount > 9 ? '9+' : $unreadMsgCount; ?></span>
      </button>
      <div class="notif-panel" id="msgPanel" data-notification-panel
        data-portal="resident"
        data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
        data-action-url="<?php echo htmlspecialchars($notifAppBaseUrl . '/includes/staff-notification-action.php?portal=resident', ENT_QUOTES, 'UTF-8'); ?>"
        data-app-base-url="<?php echo htmlspecialchars($notifAppBaseUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-unread-count="<?php echo (int)$unreadMsgCount; ?>">
        <div class="notif-panel-header">
          <h4>Staff Messages</h4>
          <button class="notif-mark-all" type="button" <?php echo $unreadMsgCount === 0 ? 'disabled' : ''; ?>>
            <i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read
          </button>
        </div>
        <div class="notif-tabs" role="tablist" aria-label="Notification filters">
          <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadMsgCount > 0 ? ($unreadMsgCount > 99 ? '99+' : $unreadMsgCount) : ''; ?></span></button>
          <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
        </div>
        <div class="notif-feedback" role="status" aria-live="polite"></div>
        <div class="notif-panel-body" id="notificationList">
          <?php foreach ($staffMessages as $n): ?>
              <?php
                $senderName = trim((string)($n['sender_name'] ?? ''));
                $senderParts = preg_split('/\s+/', $senderName, -1, PREG_SPLIT_NO_EMPTY);
                $senderInitials = '';
                if (!empty($senderParts)) {
                    $senderInitials = strtoupper(substr($senderParts[0], 0, 1));
                    if (count($senderParts) > 1) $senderInitials .= strtoupper(substr($senderParts[count($senderParts) - 1], 0, 1));
                }
                $senderAvatar = trim((string)($n['sender_avatar'] ?? ''));
                if ($senderAvatar !== '' && !preg_match('#^https?://#i', $senderAvatar) && $senderAvatar[0] !== '/') {
                    $senderAvatar = $notifAppBaseUrl . '/' . ltrim($senderAvatar, '/');
                }
              ?>
              <article class="notif-item <?php echo empty($n['is_read']) ? 'unread' : ''; ?>"
                data-notif-item data-nid="<?php echo (int)$n['notification_id']; ?>"
                data-unread="<?php echo empty($n['is_read']) ? '1' : '0'; ?>" data-archived="<?php echo empty($n['archived_at']) ? '0' : '1'; ?>">
                <div class="notif-avatar" aria-hidden="true" title="<?php echo htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars($senderInitials, ENT_QUOTES, 'UTF-8'); ?>
                  <?php if ($senderAvatar !== ''): ?><img src="<?php echo htmlspecialchars($senderAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy"><?php endif; ?>
                </div>
                <div class="notif-content">
                  <div class="notif-row-heading">
                    <div class="notif-copy">
                      <div class="notif-title"><?php echo htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="notif-msg"><?php echo htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <button type="button" class="notif-more" aria-label="Notification options" aria-expanded="false"><i class="fas fa-ellipsis-h" aria-hidden="true"></i></button>
                    <span class="notif-unread-dot" aria-label="Unread"></span>
                  </div>
                  <div class="notif-meta"><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime($n['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
                <div class="notif-row-menu" role="menu">
                  <button type="button" role="menuitem" data-notif-action="<?php echo empty($n['is_read']) ? 'read' : 'unread'; ?>"><i class="fas fa-check" aria-hidden="true"></i><?php echo empty($n['is_read']) ? 'Mark as read' : 'Mark as unread'; ?></button>
                  <button type="button" role="menuitem" data-notif-action="<?php echo empty($n['archived_at']) ? 'archive' : 'unarchive'; ?>"><i class="fas fa-inbox" aria-hidden="true"></i><?php echo empty($n['archived_at']) ? 'Archive' : 'Move to inbox'; ?></button>
                </div>
              </article>
          <?php endforeach; ?>
          <div class="notif-empty" hidden>No messages from staff.</div>
        </div>
      </div>
    </div>

    <!-- User Profile Pill -->
    <div class="user-profile" id="user-profile-btn" title="View Profile">
      <div class="user-avatar">
        <?php if (!empty($residentAvatar)): ?>
          <img src="<?php echo htmlspecialchars($residentAvatar); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        <?php endif; ?>
      </div>
      <div class="user-meta">
        <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
        <span class="user-role">Resident</span>
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
<link rel="stylesheet" href="<?php echo htmlspecialchars($notifAppBaseUrl . '/assets/css/notification.css?v=' . (int)filemtime(__DIR__ . '/../assets/css/notification.css'), ENT_QUOTES, 'UTF-8'); ?>" />
<script src="<?php echo htmlspecialchars($notifAppBaseUrl . '/assets/js/notification-panel.js?v=' . (int)filemtime(__DIR__ . '/../assets/js/notification-panel.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<div class="modal" id="logoutModal">
  <div class="sd-logout-dialog" role="alertdialog" aria-modal="true" aria-labelledby="logoutDialogTitle">
    <h2 class="sd-logout-title" id="logoutDialogTitle">Confirm Logout</h2>
    <p class="sd-logout-description">Are you sure you want to logout of the Resident Portal?</p>
    <div class="sd-logout-actions">
      <button type="button" onclick="closeModal('logoutModal')">Cancel</button>
      <a href="logout.php" onclick="localStorage.removeItem('activeSection');localStorage.removeItem('sb2State')"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
  // Maps dashboard-resident.php hash sections to sidebar modules
  var sectionToModule = {
    'overview': 'main', 'my-pets': 'main', 'my-reports': 'main', 'appointments': 'main',
    'book-appt': 'main', 'adoption': 'main', 'archive': 'main', 'account-settings': 'system'
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

  // Show/hide conditional sidebar items (e.g. "Schedule Appointment" only when booking)
  function updateConditionalItems(sectionId) {
    document.querySelectorAll('.sb2-menu-item.sb2-conditional').forEach(function(li) {
      var cond = li.getAttribute('data-conditional') || '';
      li.classList.toggle('visible', !!cond && cond === sectionId);
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
    var match = document.querySelector('.sb2-menu-sub-button.active[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-button.active[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-sub-button[data-section="' + sectionId + '"]') ||
                document.querySelector('.sb2-menu-button[data-section="' + sectionId + '"]');
    document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(item) {
      if (match && item === match) return;
      item.classList.remove('active');
    });
    if (match) {
      match.classList.add('active');
      openAncestor(match);
    }
    saveSidebarState();
    updateConditionalItems(sectionId);
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

  // Initial activation: prefer URL hash, else server page
  var initHash = window.location.hash.replace('#', '');
  if (initHash && sectionToModule[initHash]) {
    activateSection(initHash);
  } else {
    var cfg = window.SB2_CONFIG || {};
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem('sb2State') || 'null'); } catch (e) {}
    activateModule(cfg.activeModule || 'main');
    // Use activePage from PHP config to highlight the correct sidebar item
    var pageSection = cfg.activePage || 'overview';
    if (sectionToModule[pageSection]) {
      activateSection(pageSection);
    } else if (pageSection) {
      // Cross-page navigation (e.g. submit-report-page.php) — find and activate the matching item directly
      var matchItem = document.querySelector('.sb2-menu-sub-button[data-section="' + pageSection + '"]') ||
                      document.querySelector('.sb2-menu-button[data-section="' + pageSection + '"]');
      if (matchItem) {
        activateItem(matchItem);
      } else {
        var def = document.querySelector('.sb2-menu-button[data-section="overview"]');
        if (def) def.classList.add('active');
      }
    } else {
      var def = document.querySelector('.sb2-menu-button[data-section="overview"]');
      if (def) def.classList.add('active');
    }
    updateConditionalItems(pageSection);
    // Restore previously open collapsibles after a cross-page navigation
    if (saved && saved.open && saved.open.length) {
      document.querySelectorAll('.sb2-collapsible').forEach(function(b) {
        var t = b.querySelector(':scope > span');
        if (t && t.textContent && saved.open.indexOf(t.textContent.trim()) !== -1) {
          b.classList.add('open');
          b.setAttribute('aria-expanded', 'true');
        }
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

      // Cross-page links (href contains .php) should navigate directly
      if (href && href.indexOf('.php') !== -1 && href.indexOf('#') === -1) {
        activateItem(item);
        saveSidebarState();
        return;
      }

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
function openModal(id) {
  var el = document.getElementById(id);
  if (el) { el.classList.add('active'); el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
  // Set return_section from current tab hash so redirect goes back to the same tab
  var form = el ? el.querySelector('form') : null;
  var ri = form ? form.querySelector('input[name="return_section"]') : null;
  if (ri) { ri.value = window.location.hash.replace('#','') || 'overview'; }
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

function toggleMsg() { document.getElementById('msgPanel')?.classList.toggle('open'); }
document.addEventListener('click', function(e) {
  var dd = document.getElementById('msgDropdown');
  if (dd && !dd.contains(e.target)) document.getElementById('msgPanel')?.classList.remove('open');
});

function markMsgRead() {
  fetch('mark-notifications-read.php?type=resident').then(function() {
    document.querySelectorAll('.notif-item.unread').forEach(function(el) { el.classList.remove('unread'); });
    var badge = document.querySelector('.notif-badge');
    if (badge) badge.style.display = 'none';
    var navBadge = document.querySelector('.nav-badge');
    if (navBadge) navBadge.style.display = 'none';
  });
}

function openMsg(id) {
  // Mark single notification as read via AJAX
  fetch('mark-notifications-read.php?type=resident&nid=' + id).then(function() {
    var item = document.querySelector('.notif-item[data-nid="' + id + '"]');
    if (item) item.classList.remove('unread');
  });
  // Close the dropdown
  document.getElementById('msgPanel')?.classList.remove('open');
}

window.setInterval(function() {
  fetch('includes/account-status.php?sess=PHPSESSID', { credentials: 'same-origin', cache: 'no-store' })
    .then(function(response) {
      if (response.status !== 401) return null;
      return response.json();
    })
    .then(function(result) {
      if (result && result.redirect) window.location.replace(result.redirect);
    })
    .catch(function(error) {
      console.error('Account status check failed:', error);
    });
}, 8000);
</script>