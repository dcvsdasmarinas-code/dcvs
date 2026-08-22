<aside class="sidebar" id="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-brand">
      <img src="uploads/site/logo.png" alt="DCVS" class="sidebar-logo" />
      <div class="sidebar-brand-text">
        <span class="sidebar-brand-name">DCVS</span>
        <span class="sidebar-brand-sub">Barangay Portal</span>
      </div>
    </div>
    <div class="sidebar-profile">
      <div class="sidebar-avatar">
        <?php if (!empty($_SESSION['avatar_url'])): ?>
          <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Avatar">
        <?php else: ?>
          <i class="fas fa-user-shield"></i>
        <?php endif; ?>
      </div>
      <div class="sidebar-profile-info">
        <p class="sidebar-profile-name"><?php echo htmlspecialchars($fullName); ?></p>
        <p class="sidebar-profile-barangay"><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($barangayName ?? ''); ?></p>
        <p class="sidebar-profile-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?></p>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <p class="nav-section-label">Main Menu</p>
    <ul class="sidebar-links">
      <li><a href="." class="nav-link <?php echo $activePage === 'overview' ? 'active' : ''; ?>" data-section="overview" id="navOverview"><span class="nav-link-icon"><i class="fas fa-th-large"></i></span><span class="nav-link-text">Dashboard</span></a></li>
      <li><a href="." class="nav-link <?php echo $activePage === 'reports' ? 'active' : ''; ?>" data-section="reports" id="navReports"><span class="nav-link-icon"><i class="fas fa-file-medical-alt"></i></span><span class="nav-link-text">Reports</span></a></li>
      <li><a href="." class="nav-link <?php echo $activePage === 'account-settings' ? 'active' : ''; ?>" data-section="account-settings" id="navAccountSettings"><span class="nav-link-icon"><i class="fas fa-cog"></i></span><span class="nav-link-text">Account Settings</span></a></li>
    </ul>
    <p class="nav-section-label" style="margin-top:1.5rem;">Account</p>
    <ul class="sidebar-links">
      <li><a href="." class="nav-link nav-link-logout" id="logoutBtn"><span class="nav-link-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-link-text">Logout</span></a></li>
    </ul>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar" id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
  <div class="topbar-right">
    <div class="notif-dropdown" id="notifDropdown">
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
            <a href="." onclick="markAllRead(event)">Mark all read</a>
          <?php endif; ?>
        </div>
        <div class="notif-panel-body" id="notificationList">
          <?php if (empty($notifications)): ?>
            <div class="notif-empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($notifications as $n): ?>
              <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" data-nid="<?php echo $n['notification_id']; ?>">
                <div class="notif-icon <?php echo $n['type']; ?>">
                  <i class="fas <?php echo match($n['type']) {
                    'new_report' => 'fa-file-alt',
                    'report_validated' => 'fa-check-circle',
                    'report_rejected' => 'fa-times-circle',
                    'report_confirmed' => 'fa-check-double',
                    default => 'fa-bell'
                  }; ?>"></i>
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
        <?php if (!empty($_SESSION['avatar_url'])): ?>
          <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Avatar" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
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

<div class="modal" id="logoutModal">
  <div class="modal-content" style="max-width:400px;">
    <div class="modal-header">
      <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
      <button class="modal-close" onclick="closeModal('logoutModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;padding:2rem;">
      <i class="fas fa-question-circle" style="font-size:3rem;color:#94a3b8;margin-bottom:1rem;"></i>
      <p style="font-size:1.05rem;color:#475569;">Are you sure you want to logout?</p>
    </div>
    <div class="modal-actions" style="justify-content:center;">
      <a href="logout-barangay.php" class="btn-primary" style="padding:10px 28px;"><i class="fas fa-check"></i> Yes, Logout</a>
      <button type="button" class="btn-secondary" onclick="closeModal('logoutModal')">Cancel</button>
    </div>
  </div>
</div>

<script>
(function() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var toggle = document.getElementById('sidebarToggle');
  function handleResize() {
    if (window.innerWidth > 768) {
      sidebar.classList.remove('collapsed');
      if (overlay) overlay.classList.remove('active');
    }
  }
  window.addEventListener('resize', handleResize);
  if (toggle) {
    toggle.addEventListener('click', function() {
      sidebar.classList.toggle('collapsed');
      if (overlay) overlay.classList.toggle('active');
    });
  }
  if (overlay) {
    overlay.addEventListener('click', function() {
      sidebar.classList.remove('collapsed');
      overlay.classList.remove('active');
    });
  }
})();

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
</script>