<?php
// ── Self-contained user data (no dependency on page-defined variables) ──
if (!isset($fullName)) {
    $fullName = $_SESSION['full_name'] ?? 'Barangay Officer';
}
if (!isset($barangayAvatar)) {
    $barangayAvatar = $_SESSION['avatar_url'] ?? '';
    if (empty($barangayAvatar) && isset($_SESSION['user_id'])) {
        $aq = $conn->prepare("SELECT full_name, avatar_url FROM users WHERE user_id = ?");
        if ($aq) {
            $aq->bind_param("i", $_SESSION['user_id']);
            $aq->execute();
            $ar = $aq->get_result();
            if ($row = $ar->fetch_assoc()) {
                $fullName = $row['full_name'] ?? $fullName;
                $barangayAvatar = $row['avatar_url'] ?? '';
                $_SESSION['full_name'] = $fullName;
                $_SESSION['avatar_url'] = $barangayAvatar;
            }
            $aq->close();
        }
    }
}
if (!isset($activePage)) $activePage = 'overview';
if (!isset($barangayName)) $barangayName = 'Barangay';

$sbPageToModule = [
    'overview' => 'operations',
    'geo'      => 'operations',
    'archive'  => 'operations',
];
$sbActiveModule = $sbPageToModule[$activePage] ?? 'operations';

// Item helper: label, icon, optional href / section / page-filter fn
function sbItem($label, $icon, $opts = []) {
    return ['label' => $label, 'icon' => $icon] + $opts;
}
function sbAttrs($item) {
    $a = '';
    if (!empty($item['section'])) $a .= ' data-section="' . htmlspecialchars($item['section']) . '"';
    if (!empty($item['href']))      $a .= ' data-href="' . htmlspecialchars($item['href']) . '"';
    if (!empty($item['fn']))        $a .= ' data-fn="' . htmlspecialchars($item['fn']) . '"';
    if (!empty($item['arg']))       $a .= ' data-arg="' . htmlspecialchars($item['arg']) . '"';
    if (!empty($item['filter']))    $a .= ' data-filter="' . htmlspecialchars($item['filter']) . '"';
    if (!empty($item['logout']))    $a .= ' data-logout="1"';
    return $a;
}

// ── Sidebar content model for the Barangay Portal ──
$sbModules = [
    'operations' => [
        'title' => 'Operations',
        'sections' => [
            [
                'title' => 'Overview', 'icon' => 'fa-home',
                'items' => [
                    sbItem('Overview', 'fa-chart-line', ['href' => 'dashboard-barangay.php', 'initialActive' => $activePage === 'overview']),
                    sbItem('Geo-Mapping', 'fa-map-marked-alt', ['href' => 'dashboard-barangay-geo.php', 'initialActive' => $activePage === 'geo']),
                ],
            ],
            [
                'title' => 'Reports', 'icon' => 'fa-file-medical-alt',
                'items' => [
                    sbItem('All Reports', 'fa-list', ['href' => 'reports-barangay.php']),
                    sbItem('Pending Validation', 'fa-hourglass-half', ['href' => 'reports-barangay.php?status=pending']),
                    sbItem('Resolved Cases', 'fa-check-circle', ['href' => 'reports-barangay.php?status=resolved']),
                ],
            ],
            [
                'title' => 'Archive', 'icon' => 'fa-archive',
                'items' => [
                    sbItem('Archived Reports', 'fa-archive', ['href' => 'barangay-archive.php', 'initialActive' => $activePage === 'archive']),
                ],
            ],
        ],
    ],
    'system' => [
        'title' => 'System',
        'sections' => [
            [
                'title' => 'Account Settings', 'icon' => 'fa-user-cog',
                'items' => [
                    sbItem('Account Settings', 'fa-user-cog', ['href' => 'account-settings-barangay.php']),
                ],
            ],
            [
                'title' => 'Logout', 'icon' => 'fa-sign-out-alt',
                'items' => [
                    sbItem('Logout', 'fa-sign-out-alt', ['href' => 'logout-barangay.php', 'logout' => true]),
                ],
            ],
        ],
    ],
];

// Left icon rail order (module id => icon + label + target)
$sbRail = [
    'operations'    => ['icon' => 'fa-briefcase',      'label' => 'Operations',    'href' => 'dashboard-barangay.php'],
    'system'        => ['icon' => 'fa-cog',            'label' => 'System',        'href' => 'account-settings-barangay.php'],
];
?>
<link rel="stylesheet" href="assets/css/sb2.css" />
<style>
/* Sidebar logout modal — hidden by default, shown when .active */
#logoutModal {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 9999;
  align-items: center;
  justify-content: center;
}
#logoutModal.active {
  display: flex;
}
#logoutModal .modal-content {
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.18);
  max-width: 400px;
  width: 90%;
  overflow: hidden;
}
#logoutModal .modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #e4e4e7;
}
#logoutModal .modal-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #18181b;
  display: flex;
  align-items: center;
  gap: 8px;
}
#logoutModal .modal-close {
  background: none;
  border: none;
  font-size: 16px;
  color: #71717a;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}
#logoutModal .modal-close:hover { color: #18181b; }
#logoutModal .modal-body {
  padding: 24px 20px;
  text-align: center;
}
#logoutModal .modal-body p {
  margin: 0;
  font-size: 15px;
  color: #18181b;
}
#logoutModal .modal-actions {
  display: flex;
  gap: 10px;
  justify-content: center;
  padding: 0 20px 20px;
}
#logoutModal .btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 28px;
  background: #dc2626;
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
}
#logoutModal .btn-primary:hover { background: #b91c1c; }
#logoutModal .btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 28px;
  background: #fff;
  color: #18181b;
  border: 1px solid #e4e4e7;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  font-family: inherit;
  cursor: pointer;
}
#logoutModal .btn-secondary:hover { background: #f4f4f5; }
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
</script>

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
          <span class="sb2-brand-sub"><?php echo htmlspecialchars('Brgy. ' . $barangayName); ?></span>
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
  </div>

  <!-- SidebarRail: desktop collapse handle -->
  <button type="button" class="sb2-rail" id="sb2Rail" aria-label="Toggle Sidebar" title="Toggle Sidebar"></button>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
(function() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var toggle = document.getElementById('sidebarToggle');
  var rail = document.getElementById('sb2Rail');

  document.body.classList.add('sb2-active');

  // ── Desktop icon-collapse (rail / ctrl+b) ──
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

  // Keyboard shortcut (ctrl/cmd + b)
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
  var pageToModule = {
    'overview': 'operations',
    'geo': 'monitoring'
  };
  function activateModule(moduleId) {
    if (!moduleId) return;
    document.querySelectorAll('.sb2-group').forEach(function(g) {
      g.classList.toggle('module-active', g.getAttribute('data-module') === moduleId);
    });
  }

  function openAncestor(item) {
    var inner = item.closest('.sb2-menu-item');
    var outer = inner && inner.parentElement ? inner.parentElement.closest('.sb2-menu-item') : null;
    var coll = outer ? outer.querySelector(':scope > .sb2-collapsible') : null;
    if (coll) {
      coll.classList.add('open');
      coll.setAttribute('aria-expanded', 'true');
    }
  }

  function activateItem(item) {
    document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(el) {
      el.classList.remove('active');
    });
    item.classList.add('active');
    openAncestor(item);
    saveSidebarState();
  }

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

  // Initial activation
  var cfg = window.SB2_CONFIG || {};
  activateModule(pageToModule[cfg.activePage] || cfg.activeModule || 'operations');
  var saved = null;
  try { saved = JSON.parse(localStorage.getItem('sb2State') || 'null'); } catch (e) {}
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
      if (getItemKey(item) === saved.activeKey) {
        activateItem(item);
        // Update breadcrumb on load
        var bc = document.getElementById('breadcrumbCurrent');
        if (bc) {
          var label = item.querySelector('span');
          if (label) bc.textContent = label.textContent.trim();
        }
      }
    });
  }

  // ── Section collapsibles ──
  document.querySelectorAll('.sb2-collapsible').forEach(function(toggle) {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      var open = toggle.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      saveSidebarState();
    });
  });

  // ── Menu item click (scroll to hash section if on same page) ──
  document.querySelectorAll('.sb2-menu-button, .sb2-menu-sub-button').forEach(function(item) {
    if (item.classList.contains('sb2-collapsible')) return;
    item.addEventListener('click', function(e) {
      if (item.getAttribute('data-logout') === '1') {
        e.preventDefault();
        openLogoutModal();
        return;
      }
      var href = item.getAttribute('data-href');
      var filter = item.getAttribute('data-filter');
      activateItem(item);
      // If the link targets a hash on this same page, scroll smoothly
      if (href && href.indexOf('#') !== -1) {
        var hash = href.substring(href.indexOf('#'));
        var target = document.querySelector(hash);
        if (target) {
          e.preventDefault();
          if (filter && typeof window.setReportFilter === 'function') {
            window.setReportFilter(filter);
          }
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          window.location.hash = hash;
        }
      }
      // Update breadcrumb
      var bc = document.getElementById('breadcrumbCurrent');
      if (bc) {
        var label = item.querySelector('span');
        if (label) bc.textContent = label.textContent.trim();
      }
    });
  });

  // Footer logout button
  var footLogout = document.getElementById('sb2Logout');
  if (footLogout) {
    footLogout.addEventListener('click', function() { openLogoutModal(); });
  }
})();
</script>

<script>
function openLogoutModal() {
  var el = document.getElementById('logoutModal');
  if (el) { el.classList.add('active'); el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeLogoutModal() {
  var el = document.getElementById('logoutModal');
  if (el) { el.classList.remove('active'); el.style.display = 'none'; document.body.style.overflow = ''; }
}
document.addEventListener('click', function(e) {
  if (e.target.classList && e.target.classList.contains('modal')) {
    closeLogoutModal();
  }
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLogoutModal();
});
</script>

<div class="modal" id="logoutModal">
  <div class="sd-logout-dialog" role="alertdialog" aria-modal="true" aria-labelledby="logoutDialogTitle">
    <h2 class="sd-logout-title" id="logoutDialogTitle">Confirm Logout</h2>
    <p class="sd-logout-description">Are you sure you want to logout of the Barangay Portal?</p>
    <div class="sd-logout-actions">
      <button type="button" onclick="closeLogoutModal()">Cancel</button>
      <a href="logout-barangay.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</div>
