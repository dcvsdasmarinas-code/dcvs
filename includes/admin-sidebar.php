<aside class="sidebar" id="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-brand">
      <img src="uploads/site/logo.png" alt="DCVS" class="sidebar-logo" />
      <div class="sidebar-brand-text">
        <span class="sidebar-brand-name">DCVS</span>
        <span class="sidebar-brand-sub">Admin Portal</span>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <p class="nav-section-label">Main</p>
    <ul class="sidebar-links">
      <li><a href="dashboard-admin.php" class="nav-link <?php echo $activePage === 'users' ? 'active' : ''; ?>" data-section="users" id="navUsers">
        <span class="nav-link-icon"><i class="fas fa-users"></i></span><span class="nav-link-text">User Management</span>
      </a></li>
      <li><a href="role-management.php" class="nav-link <?php echo $activePage === 'roles' ? 'active' : ''; ?>" id="navRoles">
        <span class="nav-link-icon"><i class="fas fa-user-shield"></i></span><span class="nav-link-text">Role Management</span>
      </a></li>
      <li><a href="dashboard-admin.php#activity" class="nav-link <?php echo $activePage === 'activity' ? 'active' : ''; ?>" data-section="activity" id="navActivity">
        <span class="nav-link-icon"><i class="fas fa-history"></i></span><span class="nav-link-text">Activity Logs</span>
      </a></li>
    </ul>
    <p class="nav-section-label">Account</p>
    <ul class="sidebar-links">
      <li><a href="dashboard-admin.php#settings" class="nav-link <?php echo $activePage === 'settings' ? 'active' : ''; ?>" data-section="settings" id="navSettings">
        <span class="nav-link-icon"><i class="fas fa-cog"></i></span><span class="nav-link-text">Settings</span>
      </a></li>
      <li><a href="#" class="nav-link nav-link-logout" id="logoutBtn">
        <span class="nav-link-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-link-text">Logout</span>
      </a></li>
    </ul>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
  </button>
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
    <a href="#" onclick="event.preventDefault(); openModal('logoutModal');" style="color:#94a3b8;font-size:1rem;padding:6px;" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
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
  .topbar-user-name { font-size:0.82rem; font-weight:600; color:#0f172a; }
</style>

<!-- Logout Confirmation Modal -->
<div class="modal" id="logoutModal">
  <div class="modal-content" style="max-width:400px;">
    <div class="modal-header">
      <h3><i class="fas fa-sign-out-alt"></i> Confirm Sign Out</h3>
      <button class="modal-close" onclick="closeModal('logoutModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;padding:2rem;">
      <i class="fas fa-question-circle" style="font-size:3rem;color:#94a3b8;margin-bottom:1rem;"></i>
      <p style="font-size:1.05rem;color:#475569;">Are you sure you want to sign out?</p>
    </div>
    <div class="modal-actions" style="justify-content:center;">
      <a href="logout-admin.php" class="btn-primary" style="padding:10px 28px;"><i class="fas fa-check"></i> Yes, Sign Out</a>
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

// Logout button handler
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
</script>
