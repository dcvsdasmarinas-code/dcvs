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
    return $a;
}

$sbModules = [
    'user-management' => [
        'title' => 'User Management',
        'sections' => [
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
    'user-management' => ['icon' => 'fa-users',          'label' => 'Users',      'section' => 'users',    'href' => 'role-management.php'],
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
          <img src="uploads/site/logo.png" alt="DCVS" />
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

    <div class="sb2-footer">
      <div class="sb2-user">
        <div class="sb2-user-avatar" title="<?php echo htmlspecialchars($fullName); ?>">
          <?php if (!empty($adminAvatar)): ?>
            <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Avatar">
          <?php else: ?>
            <?php echo strtoupper(substr($fullName, 0, 1)); ?>
          <?php endif; ?>
        </div>
        <div class="sb2-user-info">
          <span class="sb2-user-name"><?php echo htmlspecialchars($fullName); ?></span>
          <span class="sb2-user-role">Admin</span>
        </div>
        <button type="button" class="sb2-user-more" id="sb2Logout" aria-label="Logout" title="Logout">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </div>
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
    <span class="topbar-time" id="clockDisplay"></span>
    <div class="topbar-user">
      <div class="topbar-avatar">
        <?php if (!empty($adminAvatar)): ?>
          <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Avatar" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <?php echo strtoupper(substr($fullName, 0, 1)); ?>
        <?php endif; ?>
      </div>
      <span class="topbar-user-name"><?php echo htmlspecialchars($fullName); ?></span>
    </div>
  </div>
</header>

<style>
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
  var logoutBtn = document.getElementById('sb2Logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function() {
      window.location.href = 'logout-admin.php';
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
