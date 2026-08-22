<?php
// ── Self-contained user data (no dependency on page-defined variables) ──
if (!isset($fullName)) {
    $fullName = $_SESSION['full_name'] ?? 'Resident';
}
if (!isset($barangayName)) {
    $barangayName = $_SESSION['barangay'] ?? '';
}
if (!isset($activePage)) $activePage = '';
if (!isset($pageTitle)) $pageTitle = 'Resident Portal';

// Fetch staff_message notifications for this resident (for bell icon)
$staffMessages = [];
$unreadMsgCount = 0;
if (isset($_SESSION['user_id'])) {
    $nq = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'staff_message' ORDER BY created_at DESC LIMIT 20");
    if ($nq) {
        $nq->bind_param("i", $_SESSION['user_id']);
        $nq->execute();
        $nr = $nq->get_result();
        while ($row = $nr->fetch_assoc()) $staffMessages[] = $row;
        $nq->close();
    }
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
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'staff_message' AND is_read = 0");
    if ($ucStmt) {
        $ucStmt->bind_param("i", $_SESSION['user_id']);
        $ucStmt->execute();
        $unreadMsgCount = (int)$ucStmt->get_result()->fetch_row()[0];
        $ucStmt->close();
    }
}
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-brand">
      <img src="uploads/site/logo.png" alt="DCVS" class="sidebar-logo" />
      <div class="sidebar-brand-text">
        <span class="sidebar-brand-name">DCVS</span>
        <span class="sidebar-brand-sub">Resident Portal</span>
      </div>
    </div>
    <div class="sidebar-profile">
      <div class="sidebar-avatar">
        <?php if (!empty($_SESSION['avatar_url'])): ?>
          <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Profile Avatar">
        <?php else: ?>
          <i class="fas fa-user"></i>
        <?php endif; ?>
      </div>
      <div class="sidebar-profile-info">
        <p class="sidebar-profile-name"><?php echo htmlspecialchars($fullName); ?></p>
        <p class="sidebar-profile-barangay"><i class="fas fa-map-pin"></i> Brgy. <?php echo htmlspecialchars($barangayName); ?></p>
        <p class="sidebar-profile-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?></p>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <p class="nav-section-label">Main Menu</p>
    <ul class="sidebar-links">
      <li><a href="#" class="nav-link <?php echo $activePage === 'overview' ? 'active' : ''; ?>" data-section="overview" id="navOverview"><span class="nav-link-icon"><i class="fas fa-th-large"></i></span><span class="nav-link-text">Dashboard</span></a></li>
      <li><a href="#" class="nav-link <?php echo $activePage === 'my-pets' ? 'active' : ''; ?>" data-section="my-pets" id="navPets"><span class="nav-link-icon"><i class="fas fa-paw"></i></span><span class="nav-link-text">My Pets</span></a></li>
      <li><a href="#" class="nav-link <?php echo $activePage === 'my-reports' ? 'active' : ''; ?>" data-section="my-reports" id="navReports"><span class="nav-link-icon"><i class="fas fa-file-medical-alt"></i></span><span class="nav-link-text">My Reports</span></a></li>
      <li><a href="#" class="nav-link <?php echo $activePage === 'appointments' ? 'active' : ''; ?>" data-section="appointments" id="navAppointments"><span class="nav-link-icon"><i class="fas fa-calendar-alt"></i></span><span class="nav-link-text">Appointments</span></a></li>
      <li><a href="#" class="nav-link <?php echo $activePage === 'archive' ? 'active' : ''; ?>" data-section="archive" id="navArchive"><span class="nav-link-icon"><i class="fas fa-archive"></i></span><span class="nav-link-text">Archive</span></a></li>
      <li><a href="#" class="nav-link <?php echo $activePage === 'adoption' ? 'active' : ''; ?>" data-section="adoption" id="navAdoption"><span class="nav-link-icon"><i class="fas fa-paw"></i></span><span class="nav-link-text">Adoption Listings</span></a></li>

    </ul>
    <p class="nav-section-label" style="margin-top:1.5rem;">Account</p>
    <ul class="sidebar-links">
      <li><a href="#" class="nav-link <?php echo $activePage === 'account-settings' ? 'active' : ''; ?>" data-section="account-settings" id="navSettings"><span class="nav-link-icon"><i class="fas fa-cog"></i></span><span class="nav-link-text">Account Settings</span></a></li>
      <li><a href="#" class="nav-link nav-link-logout" id="logoutBtn"><span class="nav-link-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-link-text">Logout</span></a></li>
    </ul>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar" id="topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
  </button>
  <div class="topbar-right">
    <div class="notif-dropdown" id="msgDropdown">
      <button class="notif-bell" id="msgBell" onclick="toggleMsg()">
        <i class="fas fa-envelope"></i>
        <?php if ($unreadMsgCount > 0): ?>
          <span class="notif-badge" data-realtime="notif-badge"><?php echo $unreadMsgCount > 9 ? '9+' : $unreadMsgCount; ?></span>
        <?php endif; ?>
      </button>
      <div class="notif-panel" id="msgPanel">
        <div class="notif-panel-header">
          <h4>Staff Messages</h4>
          <?php if ($unreadMsgCount > 0): ?>
            <a onclick="markMsgRead()">Mark all read</a>
          <?php endif; ?>
        </div>
        <div class="notif-panel-body" id="notificationList">
          <?php if (empty($staffMessages)): ?>
            <div class="notif-empty">No messages from staff.</div>
          <?php else: ?>
            <?php foreach ($staffMessages as $n): ?>
              <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" onclick="openMsg(<?php echo $n['notification_id']; ?>)" data-nid="<?php echo $n['notification_id']; ?>">
                <div class="notif-icon staff_message"><i class="fas fa-envelope"></i></div>
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
  .notif-icon.staff_message { background: transparent; color: #000; }
  .notif-content { flex: 1; min-width: 0; }
  .notif-title { font-size: 0.8rem; font-weight: 700; color: #0f172a; }
  .notif-msg { font-size: 0.74rem; color: #64748b; line-height: 1.3; margin-top: 1px; }
  .notif-time { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }
  .notif-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.82rem; }

  .nav-badge { display: inline-flex; align-items:center; justify-content:center; background:#dc2626; color:#fff; font-size:0.6rem; font-weight:700; min-width:18px; height:18px; border-radius:9px; padding:0 4px; margin-left:auto; line-height:1; }
  .sidebar-links li a .nav-badge { margin-left:auto; }
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
      <a href="logout.php" class="btn-primary" style="padding:10px 28px;"><i class="fas fa-check"></i> Yes</a>
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
</script>