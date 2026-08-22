<?php
session_name('DCVS_ADMIN_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: login-admin.php');
    exit;
}

// Re-verify role from database
$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $_SESSION['user_id']);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        session_destroy();
        header('Location: login-admin.php');
        exit;
    }
}
$roleCheck->close();

// ── Today / Yesterday date helpers (used by activity logs) ──
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── Users table ──
$r = $conn->query("SELECT COUNT(*) AS c FROM users");
$totalUsersCount = $r->fetch_assoc()['c'];
$allUsers = [];
$result = $conn->query("SELECT u.user_id, u.reference_id, u.full_name, u.email, u.role, u.barangay_id, u.status, u.created_at, u.phone, u.address, u.avatar_url, u.username, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id ORDER BY u.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $allUsers[] = $row;
}

// ── Activity logs table ──
$r = $conn->query("SELECT COUNT(*) AS c FROM activity_logs");
$totalActCount = $r->fetch_assoc()['c'];
$allActivity = [];
$result = $conn->query("SELECT a.*, u.full_name FROM activity_logs a LEFT JOIN users u ON a.user_id = u.user_id ORDER BY a.created_at DESC");
while ($row = $result->fetch_assoc()) {
    $allActivity[] = $row;
}



// ── Fetch admin details from DB (single source of truth) ──
$adminUserId = $_SESSION['user_id'];
$adminData = [];
$stmt = $conn->prepare("SELECT user_id, reference_id, username, email, full_name, role, phone, address, avatar_url, status, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $adminUserId);
$stmt->execute();
$adminData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullName = $adminData['full_name'] ?? $_SESSION['full_name'] ?? 'Administrator';
$adminRefId = $adminData['reference_id'] ?? '—';
$adminUsername = $adminData['username'] ?? '';
$adminPhone = $adminData['phone'] ?? '';
$adminAddress = $adminData['address'] ?? '';
$adminAvatar = $adminData['avatar_url'] ?? '';
$adminCreated = $adminData['created_at'] ?? '';
$adminEmail = $adminData['email'] ?? $_SESSION['email'] ?? '';
$pageTitle = 'User Management';
$activePage = 'users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Admin Dashboard — Dasmari&ntilde;as City Veterinary Services Animal Welfare System" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Admin | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <style>
    body { --topbar-h: 60px; }

    .page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:2rem; flex-wrap:wrap; gap:1rem; }
    .page-header-left { }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; letter-spacing:-0.02em; }
    .page-subtitle { font-size:0.85rem; color:#64748b; margin:4px 0 0; line-height:1.5; }
    .page-actions { display:flex; gap:8px; align-items:center; }

    .section-heading {
      display:flex; align-items:center; justify-content:space-between;
      margin-bottom:1rem; padding-bottom:0.75rem;
      border-bottom:1px solid #f1f5f9;
    }
    .section-heading h3 {
      font-size:0.88rem; font-weight:700; color:#0f172a;
      margin:0; display:flex; align-items:center; gap:8px;
    }
    .section-heading h3 i { color:#045e1f; font-size:0.85rem; }
    .section-heading .badge-count {
      font-size:0.72rem; font-weight:600; color:#94a3b8;
      background:#f8fafc; border:1px solid #eef2f6;
      padding:2px 10px; border-radius:999px;
    }

    .kpi-section { margin-bottom:1.75rem; }
    .kpi-grid-people { display:grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap:1rem; }
    .kpi-grid-data { display:grid; grid-template-columns: 1.5fr 1.5fr 1fr 1fr; gap:1rem; }
    .kpi-grid-status { display:grid; grid-template-columns: repeat(4, 1fr); gap:1rem; }

    .kpi-card {
      background:#fff; border:1px solid #eef2f6; border-radius:14px; padding:1.35rem 1.5rem;
      box-shadow:0 1px 3px rgba(0,0,0,0.04);
      transition:box-shadow 0.25s, transform 0.25s; position:relative; overflow:hidden;
    }
    .kpi-card:hover { box-shadow:0 8px 32px rgba(0,0,0,0.08); transform:translateY(-2px); }
    .kpi-card::before {
      content:''; position:absolute; top:0; left:0; width:100%; height:3px;
    }
    .kpi-card.featured::before { background:linear-gradient(90deg,#045e1f,#4ade80); }
    .kpi-card.featured { padding:1.5rem 1.75rem; }
    .kpi-card.featured .kpi-number { font-size:2.2rem; }
    .kpi-card.green-border::before { background:linear-gradient(90deg,#10b981,#34d399); }
    .kpi-card.blue-border::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
    .kpi-card.amber-border::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
    .kpi-card.purple-border::before { background:linear-gradient(90deg,#8b5cf6,#a78bfa); }
    .kpi-card.gray-border::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
    .kpi-card.red-border::before { background:linear-gradient(90deg,#ef4444,#f87171); }

    .kpi-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:0.35rem; }
    .kpi-icon {
      width:36px; height:36px; border-radius:10px;
      display:flex; align-items:center; justify-content:center;
      font-size:0.9rem; flex-shrink:0;
    }
    .kpi-icon.green-bg { background:#f0fdf4; color:#059669; }
    .kpi-icon.blue-bg { background:#eff6ff; color:#2563eb; }
    .kpi-icon.amber-bg { background:#fffbeb; color:#d97706; }
    .kpi-icon.purple-bg { background:#f5f3ff; color:#7c3aed; }
    .kpi-icon.gray-bg { background:#f8fafc; color:#64748b; }
    .kpi-icon.red-bg { background:#fef2f2; color:#dc2626; }
    .kpi-icon.dark-green-bg { background:#f0fdf4; color:#045e1f; }

    .kpi-number {
      font-family:'Montserrat',sans-serif; font-size:1.65rem;
      font-weight:800; color:#0f172a; line-height:1; display:block;
    }
    .kpi-label { font-size:0.72rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.04em; display:block; margin-top:2px; }

    .dash-card {
      background:#fff; border:1px solid #eef2f6; border-radius:14px;
      box-shadow:0 1px 3px rgba(0,0,0,0.04); margin-bottom:1.5rem;
      overflow:hidden;
    }
    .dash-card-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:1rem 1.5rem; border-bottom:1px solid #f1f5f9;
    }
    .dash-card-header h3 {
      font-size:0.88rem; font-weight:700; color:#0f172a;
      margin:0; display:flex; align-items:center; gap:8px;
    }
    .dash-card-header h3 i { color:#045e1f; font-size:0.85rem; }
    .dash-card-body { padding:1.25rem 1.5rem; }
    .dash-card-body:has(.timeline) { padding:0; }
    .dash-card-body:has(table.data-table) { padding:0; }
    .view-all-link {
      font-size:0.78rem; font-weight:600; color:#045e1f;
      text-decoration:none; cursor:pointer; transition:color 0.15s;
    }
    .view-all-link:hover { color:#043012; }

    .chart-area { height:120px; position:relative; }
    .chart-bars { display:flex; align-items:flex-end; gap:4px; height:90px; }
    .chart-bar {
      flex:1; border-radius:3px 3px 0 0;
      background:linear-gradient(to top, rgba(4,94,31,0.2), rgba(4,94,31,0.5));
      transition:all 0.2s; position:relative; min-height:4px;
    }
    .chart-bar:hover { background:linear-gradient(to top, rgba(4,94,31,0.35), rgba(4,94,31,0.65)); }
    .chart-labels { display:flex; gap:4px; margin-top:8px; }
    .chart-labels span { flex:1; text-align:center; font-size:0.6rem; color:#94a3b8; }

    .timeline-group { }
    .timeline-group-header {
      padding:0.75rem 1.5rem 0.5rem;
      font-size:0.72rem; font-weight:700; color:#94a3b8;
      text-transform:uppercase; letter-spacing:0.05em;
      background:#fafcff; border-bottom:1px solid #f1f5f9;
    }
    .timeline-item {
      display:flex; align-items:flex-start; gap:12px;
      padding:0.75rem 1.5rem; border-bottom:1px solid #f8fafc;
      transition:background 0.12s;
    }
    .timeline-item:last-child { border-bottom:none; }
    .timeline-item:hover { background:#fafcff; }
    .timeline-dot {
      width:10px; height:10px; border-radius:50%; flex-shrink:0;
      margin-top:5px; border:2px solid #fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);
    }
    .timeline-dot.login { background:#10b981; }
    .timeline-dot.register { background:#3b82f6; }
    .timeline-dot.report { background:#f59e0b; }
    .timeline-dot.update { background:#8b5cf6; }
    .timeline-body { flex:1; min-width:0; }
    .timeline-user { font-weight:600; color:#0f172a; font-size:0.85rem; }
    .timeline-action { color:#64748b; font-size:0.82rem; }
    .timeline-time { font-size:0.7rem; color:#94a3b8; margin-top:2px; display:flex; align-items:center; gap:6px; }

    .role-chip {
      display:inline-flex; align-items:center; gap:4px;
      padding:2px 10px; border-radius:6px; font-size:0.72rem; font-weight:500;
      border:1px solid #e2e8f0; color:#475569;
    }
    .role-chip i { font-size:0.65rem; color:#94a3b8; }

    .status-indicator {
      display:inline-flex; align-items:center; gap:6px;
      font-size:0.78rem; font-weight:500; color:#475569;
    }
    .status-dot {
      width:7px; height:7px; border-radius:50%; flex-shrink:0;
    }
    .status-dot.active { background:#10b981; }
    .status-dot.inactive { background:#f59e0b; }
    .status-dot.archived { background:#94a3b8; }

    .charts-grid {
      display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;
    }
    .chart-wrap { position:relative; height:220px; }
    .chart-wrap canvas { max-height:220px; }

    .progress-row { display:flex; align-items:center; gap:12px; }
    .progress-label { width:90px; font-size:0.82rem; color:#64748b; font-weight:500; }
    .progress-bar { flex:1; height:6px; background:#f1f5f9; border-radius:999px; overflow:hidden; }
    .progress-fill { height:100%; border-radius:999px; transition:width 0.6s ease; }
    .progress-count { font-weight:700; color:#0f172a; font-size:0.85rem; min-width:30px; text-align:right; }

    .settings-layout { display:grid; gap:1rem; max-width:1000px; margin:0 auto; width:100%; box-sizing:border-box; }
    .profile-card-full { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:1.25rem 1.5rem; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
    .settings-section { margin-bottom:1rem; }
    .settings-section-title { font-size:0.9rem; font-weight:700; color:#0f172a; margin-bottom:0.75rem; display:flex; align-items:center; gap:8px; }
    .settings-section-title i { color:#045e1f; }
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; }
    .settings-actions { display:flex; gap:10px; margin-top:0.75rem; flex-wrap:wrap; }
    .settings-form { }
    .form-group { display:flex; flex-direction:column; gap:3px; }
    .form-group label { font-size:0.78rem; font-weight:600; color:#334155; }
    .form-group-full { grid-column:1 / -1; }
    .form-control { width:100%; padding:7px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.82rem; font-family:inherit; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .form-control:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    textarea.form-control { font-family:inherit; font-size:0.82rem; line-height:1.4; }
    .settings-grid-single { display:grid; grid-template-columns:1fr; gap:0.85rem; }
    .profile-header-banner { background:linear-gradient(135deg,#045e1f,#043012); padding:1.25rem 1.5rem; border-radius:12px; text-align:center; color:#fff; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; }
    .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
    .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
    .profile-dropdown.open { display:block; }
    .profile-dropdown .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
    .profile-dropdown .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
    .profile-dropdown .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
    .profile-header-info { text-align:left; flex:1; }
    .profile-header-info h2 { font-size:1.25rem; font-weight:800; margin:0; }
    .profile-header-info p { font-size:0.82rem; color:rgba(255,255,255,0.75); margin:2px 0; }
    .profile-id-badge { display:inline-block; background:rgba(255,255,255,0.15); padding:3px 10px; border-radius:999px; font-size:0.72rem; }
    .account-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.65rem; margin-top:0.75rem; }
    .profile-photo-view { text-align:center; padding:1rem; }
    .profile-photo-view img { max-width:300px; max-height:300px; border-radius:12px; }
    .no-profile-photo { color:#94a3b8; padding:2rem; }
    .no-profile-photo i { font-size:3rem; }
    .profile-photo-delete-btn { position:absolute; top:12px; right:12px; background:#fef2f2; border:1px solid #fecaca; color:#dc2626; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.15s; }
    .profile-photo-delete-btn:hover { background:#fecaca; }
    .btn-outline { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:0.78rem; font-weight:600; border:1px solid #d0d5dd; border-radius:7px; background:#fff; color:#344054; cursor:pointer; transition:all 0.15s; }
    .btn-outline:hover { background:#f2f4f7; border-color:#98a2b3; }
    .view-profile-modal .modal-content { max-width:560px; }
    .view-profile-avatar { width:80px; height:80px; border-radius:50%; object-fit:cover; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:1.6rem; color:#94a3b8; font-weight:700; flex-shrink:0; }
    .view-profile-header { display:flex; align-items:center; gap:16px; padding-bottom:1.25rem; border-bottom:1px solid #eef2f6; }
    .view-profile-name { font-size:1.25rem; font-weight:700; color:#0f172a; margin:0; }
    .view-profile-role { font-size:0.85rem; color:#64748b; margin:3px 0 0; }
    .view-profile-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem 2rem; margin-top:1.25rem; }
    .view-profile-field-label { font-size:0.72rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px; }
    .view-profile-field-value { font-size:0.95rem; color:#0f172a; font-weight:500; word-break:break-word; }

    .table-container {
      border:1px solid #eef2f6; border-radius:14px; overflow:hidden;
      box-shadow:0 1px 3px rgba(0,0,0,0.04);
    }
    .table-container .data-table { margin:0; }
    .table-container .data-table thead th { position:sticky; top:0; z-index:2; }

    /* ── Pagination ── */
    .pagination-bar {
      display:flex; align-items:center; justify-content:space-between;
      padding:12px 20px; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:8px;
    }
    .pag-info { font-size:0.78rem; color:#94a3b8; }
    .pag-info strong { color:#475569; }
    .pag-btns { display:flex; gap:4px; align-items:center; }
    .pag-btn {
      display:inline-flex; align-items:center; justify-content:center;
      min-width:32px; height:32px; border-radius:6px;
      border:1px solid #e2e8f0; background:#fff;
      color:#475569; font-size:0.78rem; font-weight:600;
      cursor:pointer; transition:all 0.15s; text-decoration:none;
    }
    .pag-btn:hover { border-color:#045e1f; color:#045e1f; }
    .pag-active { background:#045e1f !important; color:#fff !important; border-color:#045e1f !important; }
    .pag-btn.disabled { opacity:0.3; pointer-events:none; }
    .per-page-select {
      padding:4px 8px; border:1px solid #e2e8f0; border-radius:6px;
      font-size:0.75rem; font-family:'Inter','Segoe UI',sans-serif;
      color:#475569; background:#fff; cursor:pointer; outline:none;
      margin-left:4px;
    }
    .per-page-select:focus { border-color:#045e1f; }

    @media (max-width:1200px) {
      .kpi-grid-people { grid-template-columns:repeat(2, 1fr); }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 2; }
      .kpi-grid-data { grid-template-columns:repeat(2, 1fr); }
      .kpi-grid-status { grid-template-columns:repeat(2, 1fr); }
      .charts-grid { grid-template-columns:1fr; }
    }
    @media (max-width:768px) {
      .kpi-grid-people { grid-template-columns:1fr 1fr; }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 2; }
      .kpi-grid-data { grid-template-columns:1fr; }
      .kpi-grid-status { grid-template-columns:1fr 1fr; }
      .charts-grid { grid-template-columns:1fr; }
      .settings-grid { grid-template-columns:1fr; }
      .page-header { flex-direction:column; }
      .pagination-bar { flex-direction:column; align-items:stretch; text-align:center; }
    }
    @media (max-width:480px) {
      .kpi-grid-people { grid-template-columns:1fr; }
      .kpi-grid-people .kpi-card:first-child { grid-column:span 1; }
      .kpi-grid-status { grid-template-columns:1fr; }
    }
  /* ── Profile Settings (new layout) ── */
  .profile-settings-wrapper * { box-sizing:border-box; }
  .profile-settings-wrapper { max-width:680px; margin:0 auto; }
  .profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
  .profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
  .profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
  .profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; object-fit:cover; background:#d9d9d9; cursor:pointer; }
  .profile-settings-wrapper .ps-avatar-plain { width:56px; height:56px; border-radius:50%; background:#d9d9d9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#666; cursor:pointer; }
  .profile-settings-wrapper .ps-profile-name { font-size:15px; font-weight:700; }
  .profile-settings-wrapper .ps-profile-sub { font-size:13px; color:#6b7280; margin-top:2px; }
  .profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
  .profile-settings-wrapper .ps-card-header h2 { font-size:16px; font-weight:700; margin:0; }
  .profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-size:13px; font-weight:600; color:#1f2328; cursor:pointer; transition:background .15s,border-color .15s; }
  .profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
  .profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
  .profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
  .profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
  .profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
  .profile-settings-wrapper .ps-field label { display:block; font-size:13px; color:#4b5563; margin-bottom:6px; }
  .profile-settings-wrapper .ps-field input,
  .profile-settings-wrapper .ps-field select,
  .profile-settings-wrapper .ps-field textarea { width:100%; padding:10px 12px; font-size:14px; border-radius:8px; border:1px solid #e2e5ea; background:#f9fafb; color:#1f2328; outline:none; transition:background .15s,border-color .15s,box-shadow .15s; box-sizing:border-box; font-family:inherit; }
  .profile-settings-wrapper .ps-field input:disabled,
  .profile-settings-wrapper .ps-field select:disabled,
  .profile-settings-wrapper .ps-field textarea:disabled { color:#374151; cursor:default; }
  .profile-settings-wrapper .ps-field input:not(:disabled),
  .profile-settings-wrapper .ps-field select:not(:disabled),
  .profile-settings-wrapper .ps-field textarea:not(:disabled) { background:#fff; border-color:#d7dae0; }
  .profile-settings-wrapper .ps-field input:not(:disabled):focus,
  .profile-settings-wrapper .ps-field select:not(:disabled):focus,
  .profile-settings-wrapper .ps-field textarea:not(:disabled):focus { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.08); }
  .profile-settings-wrapper .ps-btn { padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; border:1px solid transparent; }
  .profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
  .profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
  .profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
  .profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
  .profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
  .profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
  .profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
  .profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
  </style>
</head>
<body>

<?php include 'includes/admin-sidebar.php'; ?>

<main class="main-content">

  <section id="users" class="content-section active">
    <div class="page-header">
      <div class="page-header-left">
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">View and manage all registered accounts in the system.</p>
      </div>
      <div class="page-actions">
        <a href="role-management.php" class="btn-primary" style="padding:8px 18px;font-size:0.8rem;">
          <i class="fas fa-cog"></i> Full Role Management
        </a>
      </div>
    </div>
    <div class="dash-card">
      <div class="dash-card-header">
        <h3>All Accounts</h3>
        <span class="badge-count"><?php echo $totalUsersCount; ?> total</span>
      </div>
      <div class="table-container" style="border:none;border-radius:0;box-shadow:none;">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Registered</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allUsers as $u): ?>
                <tr>
                  <td><span style="font-size:0.75rem;color:#045e1f;font-weight:600;font-family:monospace;"><?php echo htmlspecialchars($u['reference_id'] ?? '—'); ?></span></td>
                  <td class="name-cell"><?php echo htmlspecialchars($u['full_name']); ?></td>
                  <td><?php echo htmlspecialchars($u['email']); ?></td>
                  <td><span class="role-chip"><i class="fas <?php echo $u['role']==='admin'?'fa-crown':($u['role']==='staff'?'fa-user-md':($u['role']==='barangay'?'fa-map-pin':'fa-user')); ?>"></i> <?php echo ucfirst($u['role']); ?></span></td>
                  <td><span class="status-indicator"><span class="status-dot <?php echo $u['status']; ?>"></span> <?php echo ucfirst($u['status']); ?></span></td>
                  <td style="font-size:0.78rem;color:#94a3b8;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                  <td><button class="btn-sm btn-outline" onclick='viewUserProfile(<?php echo json_encode($u); ?>)'><i class="fas fa-eye"></i> View</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="adminUserPagination" class="pagination-bar"></div>
      </div>
    </div>
  </section>

  <!-- ── ACTIVITY LOGS ── -->
  <section id="activity" class="content-section" style="display:none;">
    <div class="page-header">
      <div class="page-header-left">
        <h1 class="page-title">Activity Logs</h1>
        <p class="page-subtitle">Complete audit trail of all system actions.</p>
      </div>
    </div>
    <div class="dash-card">
      <div class="dash-card-header">
        <h3>All Activity</h3>
        <span class="badge-count"><?php echo $totalActCount; ?> total</span>
      </div>
      <div class="table-container" style="border:none;border-radius:0;box-shadow:none;">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Details</th>
                <th>Source</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allActivity as $act): ?>
                <tr>
                  <td style="font-size:0.78rem;color:#94a3b8;"><?php echo date('M d, Y h:i A', strtotime($act['created_at'])); ?></td>
                  <td class="name-cell"><?php echo htmlspecialchars($act['full_name'] ?? 'System'); ?></td>
                  <td><span style="color:#045e1f;font-weight:600;font-size:0.82rem;"><?php echo htmlspecialchars($act['action']); ?></span></td>
                  <td style="font-size:0.82rem;color:#64748b;"><?php echo htmlspecialchars($act['details'] ?? ''); ?></td>
                  <td><?php
                    $src = $act['action'] ?? '';
                    $srcLower = strtolower($src);
                    if (strpos($srcLower, 'login') !== false) echo '<span style="color:#2563eb;font-size:0.78rem;"><i class="fas fa-sign-in-alt"></i> Login</span>';
                    elseif (strpos($srcLower, 'logout') !== false) echo '<span style="color:#dc2626;font-size:0.78rem;"><i class="fas fa-sign-out-alt"></i> Logout</span>';
                    elseif (strpos($srcLower, 'report') !== false) echo '<span style="color:#d97706;font-size:0.78rem;"><i class="fas fa-file-alt"></i> Reports</span>';
                    elseif (strpos($srcLower, 'appointment') !== false) echo '<span style="color:#7c3aed;font-size:0.78rem;"><i class="fas fa-calendar-alt"></i> Appointments</span>';
                    elseif (strpos($srcLower, 'register') !== false) echo '<span style="color:#059669;font-size:0.78rem;"><i class="fas fa-user-plus"></i> Registration</span>';
                    else echo '<span style="color:#64748b;font-size:0.78rem;"><i class="fas fa-globe"></i> System</span>';
                  ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="adminActPagination" class="pagination-bar"></div>
      </div>
    </div>
  </section>



  <!-- ── VISIT OUR OFFICE ── -->
  <!-- ── SETTINGS / ACCOUNT SETTINGS ── -->
  <section id="settings" class="content-section" style="display:none;">
    <div class="page-header">
      <div>
        <h1 class="page-title" style="font-size:22px;font-weight:800;margin:0;">My Profile</h1>
        <p class="page-subtitle">Manage your admin profile and account information</p>
      </div>
    </div>

    <?php if (isset($_SESSION['profile_error'])): ?>
      <div id="profileError" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#991b1b;"><?php echo htmlspecialchars($_SESSION['profile_error']); unset($_SESSION['profile_error']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['profile_success'])): ?>
      <div id="profileSuccess" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#065f46;"><?php echo htmlspecialchars($_SESSION['profile_success']); unset($_SESSION['profile_success']); ?></div>
    <?php endif; ?>

    <div class="profile-settings-wrapper">

      <!-- Profile summary -->
      <div class="ps-card ps-profile-card">
        <div class="ps-profile-left">
          <div class="profile-header-avatar-wrapper">
            <?php if (!empty($adminAvatar)): ?>
              <div class="ps-avatar"><img id="adminAvatarImg" src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)"></div>
            <?php else: ?>
              <div class="ps-avatar-plain" onclick="toggleProfileDropdown(event)"><i class="fas fa-user"></i></div>
            <?php endif; ?>
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
            <div class="ps-profile-sub"><?php echo htmlspecialchars($adminEmail); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($adminAddress); ?></div>
          </div>
        </div>
      </div>

      <form id="adminSettingsForm" action="update-admin-profile.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event, 'adminAvatarImg')">

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
              <input type="email" name="email" value="<?php echo htmlspecialchars($adminEmail); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Username</label>
              <input type="text" name="username" value="<?php echo htmlspecialchars($adminUsername); ?>" readonly data-group="personal">
            </div>
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($adminPhone); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row ps-single">
            <div class="ps-field">
              <label>Address</label>
              <textarea name="address" disabled data-group="personal"><?php echo htmlspecialchars($adminAddress); ?></textarea>
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
  </section>

</main>

<script>
(function() {
  var target = window.location.hash.replace('#', '') || localStorage.getItem('adminActiveSection') || 'users';
  if (target && document.getElementById(target)) {
    document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
    document.getElementById(target).style.display = 'block';
    document.querySelectorAll('.sidebar .nav-link').forEach(function(l) { l.classList.remove('active'); });
    var navId = 'nav' + target.charAt(0).toUpperCase() + target.slice(1);
    var nav = document.getElementById(navId);
    if (nav) nav.classList.add('active');
    var labels = {users:'User Management',activity:'Activity Logs',settings:'Settings'};
    document.getElementById('breadcrumbCurrent').textContent = labels[target] || target;
  } else {
    document.getElementById('users').style.display = 'block';
  }
})();

var currentSection = 'users';

function switchSection(id) {
  document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
  var target = document.getElementById(id);
  if (target) target.style.display = 'block';
  document.querySelectorAll('.sidebar .nav-link').forEach(function(l) { l.classList.remove('active'); });
  var nav = document.getElementById('nav' + id.charAt(0).toUpperCase() + id.slice(1));
  if (nav) nav.classList.add('active');
  var labels = {users:'User Management',activity:'Activity Logs',settings:'Settings'};
  document.getElementById('breadcrumbCurrent').textContent = labels[id] || id;
  currentSection = id;
  localStorage.setItem('adminActiveSection', id);
  window.location.hash = id;
  window.scrollTo({top:0});
}

document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
  link.addEventListener('click', function(e) {
    var section = link.getAttribute('data-section');
    if (section) {
      e.preventDefault();
      switchSection(section);
    }
  });
});

// ── USERS: client pagination ──
(function() {
  var PER_PAGE = 15;
  var tbody = document.querySelector('#users-section .data-table tbody');
  var pag = document.getElementById('adminUserPagination');
  if (!tbody || !pag) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var currentPage = 1;

  function renderTable() {
    allRows.forEach(function(r) { r.style.display = ''; });
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (tp <= 1) { pag.innerHTML = ''; return; }
    currentPage = Math.min(currentPage, tp);
    allRows.forEach(function(r) { r.style.display = 'none'; });
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    allRows.slice(start, end).forEach(function(r) { r.style.display = ''; });
    var h = '';
    h += '<span class="pag-info">Page <strong>' + currentPage + '</strong> of <strong>' + tp + '</strong> | <strong>' + total + '</strong> total</span>';
    h += '<div class="pag-btns">';
    h += '<a href="#" onclick="window.adminUserGo(' + (currentPage - 1) + ');return false;" class="pag-btn' + (currentPage <= 1 ? ' disabled' : '') + '"><i class="fas fa-angle-left"></i></a>';
    h += '<a href="#" onclick="window.adminUserGo(' + (currentPage + 1) + ');return false;" class="pag-btn' + (currentPage >= tp ? ' disabled' : '') + '"><i class="fas fa-angle-right"></i></a>';
    h += '</div>';
    pag.innerHTML = h;
  }

  window.adminUserGo = function(page) {
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };

  renderTable();
})();

// ── ACTIVITY LOGS: client pagination ──
(function() {
  var PER_PAGE = 15;
  var tbody = document.querySelector('#activity .data-table tbody');
  var pag = document.getElementById('adminActPagination');
  if (!tbody || !pag) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var currentPage = 1;

  function renderTable() {
    allRows.forEach(function(r) { r.style.display = ''; });
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (tp <= 1) { pag.innerHTML = ''; return; }
    currentPage = Math.min(currentPage, tp);
    allRows.forEach(function(r) { r.style.display = 'none'; });
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    allRows.slice(start, end).forEach(function(r) { r.style.display = ''; });
    var h = '';
    h += '<span class="pag-info">Page <strong>' + currentPage + '</strong> of <strong>' + tp + '</strong> | <strong>' + total + '</strong> total</span>';
    h += '<div class="pag-btns">';
    h += '<a href="#" onclick="window.adminActGo(' + (currentPage - 1) + ');return false;" class="pag-btn' + (currentPage <= 1 ? ' disabled' : '') + '"><i class="fas fa-angle-left"></i></a>';
    h += '<a href="#" onclick="window.adminActGo(' + (currentPage + 1) + ');return false;" class="pag-btn' + (currentPage >= tp ? ' disabled' : '') + '"><i class="fas fa-angle-right"></i></a>';
    h += '</div>';
    pag.innerHTML = h;
  }

  window.adminActGo = function(page) {
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };

  renderTable();
})();

// ── Profile Settings Edit Toggle ──
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
    var sf = document.getElementById('adminSettingsForm');
    if (sf) {
        sf.addEventListener('submit', function() {
            this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
        });
    }
    window.previewAvatar = function(e, imgId) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var img = document.getElementById(imgId);
                if (img) { img.src = ev.target.result; }
            };
            reader.readAsDataURL(file);
        }
    };

    setTimeout(function() {
        var err = document.getElementById('profileError');
        var suc = document.getElementById('profileSuccess');
        if (err) { err.style.transition = 'opacity 0.5s'; err.style.opacity = '0'; setTimeout(function() { err.style.display = 'none'; }, 500); }
        if (suc) { suc.style.transition = 'opacity 0.5s'; suc.style.opacity = '0'; setTimeout(function() { suc.style.display = 'none'; }, 500); }
    }, 3000);
});

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

function viewUserProfile(u) {
  document.getElementById('vpName').textContent = u.full_name || '—';
  var roleIcon = {'admin':'fa-crown','staff':'fa-user-md','barangay':'fa-map-pin','resident':'fa-user'};
  document.getElementById('vpRole').innerHTML = '<i class="fas '+(roleIcon[u.role]||'fa-user')+'"></i> '+ (u.role ? u.role.charAt(0).toUpperCase()+u.role.slice(1) : '—');
  document.getElementById('vpRef').textContent = u.reference_id || '—';
  document.getElementById('vpUsername').textContent = u.username || '—';
  document.getElementById('vpEmail').textContent = u.email || '—';
  document.getElementById('vpPhone').textContent = u.phone || '—';
  document.getElementById('vpAddress').textContent = u.address || '—';
  document.getElementById('vpBarangay').textContent = u.barangay_name || '—';
  var statusEl = document.getElementById('vpStatus');
  if (u.status === 'active') { statusEl.innerHTML = '<span style="color:#16a34a;font-weight:600;">Active</span>'; }
  else if (u.status === 'inactive') { statusEl.innerHTML = '<span style="color:#94a3b8;font-weight:600;">Inactive</span>'; }
  else { statusEl.textContent = u.status || '—'; }
  document.getElementById('vpRegistered').textContent = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : '—';
  var avatarEl = document.getElementById('vpAvatar');
  if (u.avatar_url) { avatarEl.innerHTML = '<img src="'+u.avatar_url+'" alt="Avatar" style="width:72px;height:72px;border-radius:50%;object-fit:cover;">'; }
  else { var initials = (u.full_name||'').split(' ').map(function(s){return s.charAt(0)}).join('').toUpperCase().slice(0,2) || '?'; avatarEl.textContent = initials; }
  openModal('viewProfileModal');
}

function viewProfilePhoto() {
    document.getElementById('profileDropdown').classList.remove('open');
    openModal('profilePhotoModal');
}

function confirmRemoveAvatar() {
    if (confirm('Remove your profile photo?')) {
        window.location.href = 'remove-photo.php';
    }
}
</script>

<!-- Profile Photo Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="modal-content" style="max-width:500px;">
    <div class="modal-header">
      <h3>Profile Photo</h3>
      <button class="modal-close" onclick="closeModal('profilePhotoModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <?php if (!empty($adminAvatar)): ?>
        <button class="profile-photo-delete-btn" onclick="confirmRemoveAvatar(); closeModal('profilePhotoModal');" title="Delete Photo">
          <i class="fas fa-trash"></i>
        </button>
      <?php endif; ?>
      <div class="profile-photo-view">
        <?php if (!empty($adminAvatar)): ?>
          <img src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Profile Photo" id="profilePhotoViewImg">
        <?php else: ?>
          <div class="no-profile-photo">
            <i class="fas fa-user"></i>
            <p>No profile photo uploaded</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- View User Profile Modal -->
<div class="modal view-profile-modal" id="viewProfileModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>User Profile</h3>
      <button class="modal-close" onclick="closeModal('viewProfileModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="view-profile-header">
        <div class="view-profile-avatar" id="vpAvatar"></div>
        <div>
          <div class="view-profile-name" id="vpName"></div>
          <div class="view-profile-role" id="vpRole"></div>
        </div>
      </div>
      <div class="view-profile-grid">
        <div class="view-profile-field"><div class="view-profile-field-label">Reference ID</div><div class="view-profile-field-value" id="vpRef">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Username</div><div class="view-profile-field-value" id="vpUsername">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Email</div><div class="view-profile-field-value" id="vpEmail">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Phone</div><div class="view-profile-field-value" id="vpPhone">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Address</div><div class="view-profile-field-value" id="vpAddress">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Barangay</div><div class="view-profile-field-value" id="vpBarangay">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Status</div><div class="view-profile-field-value" id="vpStatus">—</div></div>
        <div class="view-profile-field"><div class="view-profile-field-label">Registered</div><div class="view-profile-field-value" id="vpRegistered">—</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content" style="max-width:420px;">
    <div class="modal-header">
      <h3><i class="fas fa-key"></i> Change Password</h3>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="changePasswordForm" action="update-admin-password.php" method="POST">
      <div class="modal-body">
        <div class="form-group"><label>Current Password <span class="req">*</span></label><input type="password" name="current_password" class="form-control" required /></div>
        <div class="form-group"><label>New Password <span class="req">*</span></label><input type="password" name="new_password" class="form-control" required /></div>
        <div class="form-group"><label>Confirm New Password <span class="req">*</span></label><input type="password" name="confirm_password" class="form-control" required /></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Change Password</button>
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
