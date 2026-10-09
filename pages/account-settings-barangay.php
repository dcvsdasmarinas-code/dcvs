<?php
session_name('DCVS_BARANGAY_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName   = $_SESSION['full_name'] ?? 'Barangay Officer';
$avatarUrl  = $_SESSION['avatar_url'] ?? '';
$activePage = 'account-settings';

// Barangay info
$barangayName = 'Barangay';
$bq = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
$bq->bind_param('i', $barangayId);
$bq->execute();
$bResult = $bq->get_result();
if ($bResult && $bRow = $bResult->fetch_assoc()) {
    $barangayName = $bRow['barangay_name'];
}
$bq->close();

// Fetch user profile data
$barangayEmail = $_SESSION['email'] ?? '';
$barangayPhone = $_SESSION['phone'] ?? '';
$barangayAddress = $_SESSION['address'] ?? '';
$pq = $conn->prepare("SELECT email, phone, address, avatar_url FROM users WHERE user_id = ?");
$pq->bind_param("i", $userId);
$pq->execute();
$pr = $pq->get_result();
if ($pr && $pRow = $pr->fetch_assoc()) {
    $barangayEmail = $pRow['email'] ?? $barangayEmail;
    $barangayPhone = $pRow['phone'] ?? $barangayPhone;
    $barangayAddress = $pRow['address'] ?? $barangayAddress;
    $avatarUrl = $pRow['avatar_url'] ?? $avatarUrl;
}
$pq->close();

$unreadNotifs = 0;
$uq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
if ($uq) {
    $uq->bind_param('i', $userId);
    $uq->execute();
    $unreadNotifs = (int)$uq->get_result()->fetch_row()[0];
    $uq->close();
}

$pageTitle = 'Account Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $pageTitle; ?> — DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/sb2.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #ffffff !important; color: #18181b; --topbar-h: 64px; --bg: #ffffff !important; }
    body.sb2-active .topbar { left: auto !important; margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); }
    .topbar { position: sticky; top: 0; left: auto; width: calc(100% - var(--sidebar-w, 16rem)); margin-left: var(--sidebar-w, 16rem); height: var(--topbar-h); background: #fff; border-bottom: 1px solid #e4e4e7; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 40; transition: margin-left 0.2s ease, width 0.2s ease; }
    .topbar-left { display: flex; align-items: center; gap: 16px; }
    .topbar-toggle { display: none; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid #e4e4e7; border-radius: 8px; background: #fff; color: #18181b; cursor: pointer; }
    @media(max-width:768px) {
      .topbar-toggle { display: flex !important; }
      .topbar,
      body.sb2-active .topbar { left: 0 !important; width: 100%; margin-left: 0; padding: 0 16px; }
      .topbar-left { min-width: 0; }
      .topbar-breadcrumb { min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
      .breadcrumb-root,
      .breadcrumb-current { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    }
    .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: #94a3b8; }
    .breadcrumb-root { color: #0B4D26; font-weight: 600; }
    .breadcrumb-sep { font-size: 0.6rem; }
    .breadcrumb-current { color: #0f172a; font-weight: 600; }
    .barangay-selector { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; background: #fff; border: 1px solid #e4e4e7; border-radius: 999px; font-size: 14px; font-weight: 500; color: #18181b; cursor: pointer; transition: all .15s; }
    .barangay-selector:hover { background: #f4f4f5; }
    .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }
    .notif-dropdown{position:relative;}
    .notif-btn{background:none;border:none;font-size:1.2rem;color:#475569;cursor:pointer;padding:6px;position:relative;border-radius:8px;transition:background .15s;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
    .notif-btn:hover{background:#f1f5f9;}
    .notif-badge{position:absolute;top:0;right:0;background:#dc2626;color:#fff;font-size:.6rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1;}
    .notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:380px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);border:1px solid #e2e8f0;z-index:200;display:none;overflow:hidden;}
    .notif-panel.open{display:block;}
    .notif-panel-header{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1rem;border-bottom:1px solid #e2e8f0;}
    .notif-panel-header h4{margin:0;font-size:.85rem;font-weight:700;color:#0f172a;}
    .notif-panel-header a{font-size:.75rem;color:#0E8131;text-decoration:none;font-weight:600;cursor:pointer;}
    .notif-panel-header a:hover{text-decoration:underline;}
    .notif-panel-body{max-height:360px;overflow-y:auto;}
    .notif-item{display:flex;gap:.75rem;padding:.85rem 1rem;cursor:pointer;transition:background .12s;border-bottom:1px solid #f1f5f9;}
    .notif-item:hover{background:#f8fafc;}
    .notif-item.unread{background:#f0fdf4;}
    .notif-item.unread:hover{background:#dcebe0;}
    .notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;background:transparent;color:#000;}
    .notif-content{flex:1;min-width:0;}
    .notif-title{font-size:.8rem;font-weight:700;color:#0f172a;}
    .notif-msg{font-size:.74rem;color:#64748b;line-height:1.3;margin-top:1px;}
    .notif-time{font-size:.65rem;color:#94a3b8;margin-top:3px;}
    .notif-empty{text-align:center;padding:2rem;color:#94a3b8;font-size:.82rem;}
    @media(max-width:640px){.notif-panel{width:calc(100vw - 2rem)!important;right:.5rem!important;}}
    .user-profile{display:flex;align-items:center;gap:10px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);border:1px solid transparent;}
    .user-profile:hover{background:#F3F4F6;border-color:#F0F2F5;}
    .user-avatar{width:34px;height:34px;border-radius:50%;background:#D5ECE0;border:1px solid #B8DEC9;display:flex;align-items:center;justify-content:center;color:#0B4D26;font-weight:700;font-size:12px;overflow:hidden;}
    .user-meta{display:flex;flex-direction:column;line-height:1.25;}
    .user-name{font-size:14px;font-weight:600;color:#18181B;}
    .user-role{font-size:12px;color:#71717A;}
    .main-content { margin-top: 0 !important; padding: 1.75rem 2rem; min-height: calc(100vh - var(--topbar-h)); background: #ffffff !important; }

    /* Profile Settings */
    .profile-settings-wrapper * { box-sizing: border-box; }
    .profile-settings-wrapper { max-width: 680px; margin: 0 auto; }
    .profile-settings-wrapper .ps-card { background: #fff; border: 1px solid #e6e8eb; border-radius: 14px; padding: 22px 24px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
    .profile-settings-wrapper .ps-profile-card { display: flex; align-items: center; justify-content: space-between; }
    .profile-settings-wrapper .ps-profile-left { display: flex; align-items: center; gap: 16px; }
    .profile-settings-wrapper .ps-avatar { width: 56px; height: 56px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #d9d9d9; cursor: pointer; }
    .profile-settings-wrapper .ps-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .profile-settings-wrapper .ps-avatar-plain { width: 56px; height: 56px; border-radius: 50%; background: #d9d9d9; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #666; cursor: pointer; }
    .profile-settings-wrapper .ps-profile-name { font-size: 18px; font-weight: 600; color: #18181b; line-height: 1; letter-spacing: -0.025em; }
    .profile-settings-wrapper .ps-profile-sub { font-size: 14px; color: #71717a; line-height: 1.5; margin-top: 2px; }
    .profile-settings-wrapper .ps-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .profile-settings-wrapper .ps-card-header h2 { font-size: 18px; font-weight: 600; color: #18181b; margin: 0; }
    .profile-settings-wrapper .ps-edit-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #d7dae0; border-radius: 8px; padding: 7px 14px; font-size: 14px; font-weight: 500; color: #18181b; cursor: pointer; transition: background .15s, border-color .15s; }
    .profile-settings-wrapper .ps-edit-btn:hover { background: #f5f6f8; border-color: #c3c8d0; }
    .profile-settings-wrapper .ps-edit-btn.active { background: #f0f0f0; border-color: #000; color: #000; }
    .profile-settings-wrapper .ps-edit-btn svg { width: 13px; height: 13px; }
    .profile-settings-wrapper .ps-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px; }
    .profile-settings-wrapper .ps-field label { display: block; font-size: 14px; font-weight: 500; color: #18181b; margin-bottom: 6px; }
    .profile-settings-wrapper .ps-field input,
    .profile-settings-wrapper .ps-field select,
    .profile-settings-wrapper .ps-field textarea { width: 100%; padding: 10px 12px; font-size: 14px; border-radius: 8px; border: 1px solid #e2e5ea; background: #f9fafb; color: #1f2328; outline: none; transition: background .15s, border-color .15s, box-shadow .15s; box-sizing: border-box; font-family: inherit; }
    .profile-settings-wrapper .ps-field input:disabled,
    .profile-settings-wrapper .ps-field select:disabled,
    .profile-settings-wrapper .ps-field textarea:disabled { color: #374151; cursor: default; }
    .profile-settings-wrapper .ps-field input:not(:disabled),
    .profile-settings-wrapper .ps-field select:not(:disabled),
    .profile-settings-wrapper .ps-field textarea:not(:disabled) { background: #fff; border-color: #d7dae0; }
    .profile-settings-wrapper .ps-field input:not(:disabled):focus,
    .profile-settings-wrapper .ps-field select:not(:disabled):focus,
    .profile-settings-wrapper .ps-field textarea:not(:disabled):focus { border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,0.08); }
    .profile-settings-wrapper .ps-btn { padding: 9px 18px; font-size: 14px; font-weight: 500; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
    .profile-settings-wrapper .ps-btn-primary { background: #000; color: #fff; border-color: #000; }
    .profile-settings-wrapper .ps-btn-primary:hover { background: #1a1a1a; }
    .profile-settings-wrapper .ps-btn-secondary { background: #fff; color: #1f2328; border-color: #d7dae0; }
    .profile-settings-wrapper .ps-btn-secondary:hover { background: #f5f6f8; }
    .profile-settings-wrapper .ps-footer-actions { display: flex; justify-content: flex-start; gap: 10px; margin-top: 4px; }
    .profile-header-avatar-wrapper { position: relative; cursor: pointer; flex-shrink: 0; }
    .profile-dropdown { position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 6px; display: none; z-index: 100; min-width: 160px; }
    .profile-dropdown.open { display: block; }
    .profile-dropdown .dropdown-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border: none; border-radius: 6px; background: none; font-size: 0.82rem; font-weight: 500; color: #475569; cursor: pointer; transition: all 0.1s; width: 100%; text-align: left; white-space: nowrap; }
    .profile-dropdown .dropdown-item:hover { background: #f8fafc; color: #0f172a; }
    .profile-dropdown .dropdown-item i { width: 16px; text-align: center; color: #94a3b8; }

    .flash-msg { text-align: center; margin: 0 auto 14px; font-size: 13px; }
    .flash-msg.error { color: #991b1b; }
    .flash-msg.success { color: #065f46; }

    .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.45); z-index: 9999; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: #fff; border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.18); max-width: 400px; width: 90%; overflow: hidden; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e4e4e7; }
    .modal-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: #18181b; display: flex; align-items: center; gap: 8px; }
    .modal-close { background: none; border: none; font-size: 16px; color: #71717a; cursor: pointer; padding: 4px; line-height: 1; }
    .modal-close:hover { color: #18181b; }
    .modal-body { padding: 24px 20px; }
    .modal-body .form-group { margin-bottom: 1rem; }
    .modal-body label { display: block; font-size: 13px; font-weight: 500; color: #18181b; margin-bottom: 4px; }
    .modal-body input { width: 100%; padding: 10px 12px; font-size: 14px; border-radius: 8px; border: 1px solid #e2e5ea; background: #f9fafb; outline: none; font-family: inherit; }
    .modal-body input:focus { border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,0.08); }
    .modal-footer { display: flex; gap: 10px; justify-content: flex-end; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; }
    .btn-primary { background: #000; color: #fff; border: 1px solid #000; padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
    .btn-primary:hover { background: #1a1a1a; }
    .btn-secondary { background: #fff; color: #1f2328; border: 1px solid #d7dae0; padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
    .btn-secondary:hover { background: #f5f6f8; }

    @media(max-width:768px) {
      .main-content { padding: 1rem; }
      .topbar { padding: 0 1rem; }
    }
  </style>
</head>
<body class="sb2-active">

<?php include 'includes/barangay-sidebar.php'; ?>

  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
      </button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="breadcrumb-current" id="breadcrumbCurrent">Account Settings</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="notif-dropdown" id="notifDropdown">
        <button class="notif-btn" id="notif-btn" type="button" title="<?php echo $unreadNotifs; ?> unread notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
          data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
          data-action-url="includes/staff-notification-action.php?portal=barangay"
          data-load-url="get-notifications.php?limit=30" data-app-base-url="">
          <div class="notif-panel-header">
            <h4>Notifications</h4>
            <button class="notif-mark-all" type="button" <?php echo $unreadNotifs === 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read</button>
          </div>
          <div class="notif-tabs" role="tablist" aria-label="Notification filters">
            <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadNotifs > 0 ? ($unreadNotifs > 99 ? '99+' : $unreadNotifs) : ''; ?></span></button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
          </div>
          <div class="notif-feedback" role="status" aria-live="polite"></div>
          <div class="notif-panel-body" id="notifList">
            <div class="notif-empty">Loading notifications…</div>
          </div>
        </div>
      </div>
      <div class="user-profile" id="user-profile-btn" title="View Profile">
        <div class="user-avatar">
          <?php if (!empty($avatarUrl)): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          <?php endif; ?>
        </div>
        <div class="user-meta">
          <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
          <span class="user-role">Barangay Officer</span>
        </div>
      </div>
    </div>
  </header>

  <main class="main-content">
    <?php if (isset($_SESSION['profile_error'])): ?>
      <div class="flash-msg error" id="profileError"><?php echo htmlspecialchars($_SESSION['profile_error']); unset($_SESSION['profile_error']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['profile_success'])): ?>
      <div class="flash-msg success" id="profileSuccess"><?php echo htmlspecialchars($_SESSION['profile_success']); unset($_SESSION['profile_success']); ?></div>
    <?php endif; ?>

    <div class="profile-settings-wrapper">

      <!-- Profile summary -->
      <div class="ps-card ps-profile-card">
        <div class="ps-profile-left">
          <div class="profile-header-avatar-wrapper">
            <div class="ps-avatar" id="brgyAvatarContainer" style="<?php echo empty($avatarUrl) ? 'display:none' : ''; ?>">
              <img id="brgyAvatarImg" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)">
            </div>
            <div class="ps-avatar-plain" id="brgyAvatarPlain" onclick="toggleProfileDropdown(event)" style="<?php echo empty($avatarUrl) ? '' : 'display:none'; ?>"><i class="fas fa-user"></i></div>
            <div class="profile-dropdown" id="profileDropdown">
              <div class="dropdown-item" onclick="event.stopPropagation();viewProfilePhoto()">
                <i class="fas fa-eye"></i> <span>View Profile</span>
              </div>
              <div class="dropdown-item" onclick="event.stopPropagation();document.getElementById('avatarInput').click()">
                <i class="fas fa-camera"></i> <span>Change Profile</span>
              </div>
            </div>
          </div>
          <div>
            <div class="ps-profile-name"><?php echo htmlspecialchars($fullName); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($barangayEmail); ?></div>
            <div class="ps-profile-sub">Brgy. <?php echo htmlspecialchars($barangayName); ?>, Dasmari&ntilde;as</div>
          </div>
        </div>
      </div>

      <form id="brgySettingsForm" action="update-barangay-profile.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event,'brgyAvatarImg','brgyAvatarContainer','brgyAvatarPlain')">

        <!-- Personal Information -->
        <div class="ps-card" id="personal-card">
          <div class="ps-card-header">
            <h2>Personal Information</h2>
            <button type="button" class="ps-edit-btn" onclick="toggleEdit('personal')">
              <span class="btn-label">Edit</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Full Name</label>
              <input type="text" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Email Address</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($barangayEmail); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($barangayPhone); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Address</label>
              <textarea name="address" disabled data-group="personal" style="min-height:42px;resize:vertical;"><?php echo htmlspecialchars($barangayAddress); ?></textarea>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="ps-footer-actions">
          <button type="button" class="ps-btn ps-btn-secondary" onclick="openModal('changePasswordModal')">Change Password</button>
          <button type="submit" class="ps-btn ps-btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </main>

</div>

<!-- Profile Photo Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Profile Photo</h3>
      <button class="modal-close" onclick="closeModal('profilePhotoModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <?php if (!empty($avatarUrl)): ?>
        <button class="btn-secondary" onclick="confirmRemoveAvatar(); closeModal('profilePhotoModal');" title="Delete Photo" style="position:absolute;top:12px;right:12px;padding:6px 10px;font-size:12px;">
          <i class="fas fa-trash"></i>
        </button>
      <?php endif; ?>
      <div style="display:flex;align-items:center;justify-content:center;min-height:200px;">
        <?php if (!empty($avatarUrl)): ?>
          <img id="profilePhotoImg" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Profile" style="max-width:100%;max-height:300px;border-radius:10px;">
        <?php else: ?>
          <div id="profilePhotoNoImg" style="display:flex;flex-direction:column;align-items:center;gap:10px;color:#94a3b8;">
            <i class="fas fa-user" style="font-size:3rem;"></i>
            <span>No profile photo</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-key"></i> Change Password</h3>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form action="update-barangay-password.php" method="POST">
      <div class="modal-body">
        <div class="form-group">
          <label>Current Password</label>
          <input type="password" name="current_password" required>
        </div>
        <div class="form-group">
          <label>New Password</label>
          <input type="password" name="new_password" required minlength="8">
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="8">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) { var m = document.getElementById(id); if (m) m.classList.add('active'); }
function closeModal(id) { var m = document.getElementById(id); if (m) m.classList.remove('active'); }

function toggleEdit(group) {
  var wrapper = document.querySelector('.profile-settings-wrapper');
  if (!wrapper) return;
  var inputs = wrapper.querySelectorAll('[data-group="' + group + '"]');
  var btn = document.getElementById(group + '-card').querySelector('.ps-edit-btn');
  var isEditing = btn.classList.contains('active');
  inputs.forEach(function(input) { input.disabled = isEditing; });
  btn.classList.toggle('active', !isEditing);
  btn.querySelector('.btn-label').textContent = isEditing ? 'Edit' : 'Done';
  if (!isEditing && inputs.length) inputs[0].focus();
}

document.addEventListener('DOMContentLoaded', function() {
  var sf = document.getElementById('brgySettingsForm');
  if (sf) {
    sf.addEventListener('submit', function() {
      this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
    });
  }

  setTimeout(function() {
    var err = document.getElementById('profileError');
    var suc = document.getElementById('profileSuccess');
    if (err) { err.style.transition = 'opacity 0.5s'; err.style.opacity = '0'; setTimeout(function() { err.style.display = 'none'; }, 500); }
    if (suc) { suc.style.transition = 'opacity 0.5s'; suc.style.opacity = '0'; setTimeout(function() { suc.style.display = 'none'; }, 500); }
  }, 3000);
});

function previewAvatar(e, imgId, containerId, plainId) {
  var file = e.target.files[0];
  if (file) {
    var reader = new FileReader();
    reader.onload = function(ev) {
      var img = document.getElementById(imgId);
      if (img) {
        img.src = ev.target.result;
        var cont = document.getElementById(containerId);
        var plain = document.getElementById(plainId);
        if (cont) cont.style.display = '';
        if (plain) plain.style.display = 'none';
      }
    };
    reader.readAsDataURL(file);
  }
}

function toggleProfileDropdown(e) {
  e.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('open');
}

document.addEventListener('click', function(e) {
  var d = document.getElementById('profileDropdown');
  if (d && d.classList.contains('open') && !d.contains(e.target) && !e.target.closest('.profile-header-avatar-wrapper')) {
    d.classList.remove('open');
  }
});

function viewProfilePhoto() {
  var img = document.getElementById('profilePhotoImg');
  var currentAvatar = document.querySelector('.ps-avatar img');
  if (currentAvatar && img) {
    img.src = currentAvatar.src;
    img.style.display = 'block';
  }
  openModal('profilePhotoModal');
}

function confirmRemoveAvatar() {
  if (confirm('Remove your profile picture?')) {
    window.location.href = 'remove-photo.php';
  }
}

// Sidebar toggle
var sidebar = document.getElementById('sidebar');
var toggle = document.getElementById('sidebarToggle');
if (toggle && sidebar) {
  toggle.addEventListener('click', function() {
    sidebar.classList.toggle('mobile-open');
  });
}

</script>
<script src="assets/js/notification-panel.js?v=<?php echo (int)filemtime(__DIR__ . '/../assets/js/notification-panel.js'); ?>" defer></script>
<link rel="stylesheet" href="assets/css/notification.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/css/notification.css'); ?>">
</body>
</html>
