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
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login-admin.php');
    exit;
}

$adminId = $_SESSION['user_id'];
$activePage = isset($_GET['type']) ? 'archive-' . $_GET['type'] : 'archive-resident';
$archiveType = $_GET['type'] ?? 'resident';
if (!in_array($archiveType, ['resident', 'barangay', 'staff'])) $archiveType = 'resident';

$roleMap = [
    'resident' => 'resident',
    'barangay' => 'barangay',
    'staff'    => 'staff',
];
$targetRole = $roleMap[$archiveType];
$pageTitle = ucfirst($archiveType) . ' Archive';

$search = trim($_GET['search'] ?? '');
$where = "WHERE u.role = ? AND u.archived_at IS NOT NULL";
$params = [$targetRole];
$types = "s";

if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.reference_id LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "ssss";
}

$countSql = "SELECT COUNT(*) FROM users u $where";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_row()[0];
$countStmt->close();

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$totalPages = max(1, ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT u.user_id, u.reference_id, u.full_name, u.email, u.username, u.role, u.status, u.archived_at, u.created_at,
        b.barangay_name
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        $where
        ORDER BY u.archived_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();
$users = [];
while ($r = $rows->fetch_assoc()) $users[] = $r;
$stmt->close();

if (isset($_POST['action']) && $_POST['action'] === 'restore_user') {
    header('Content-Type: application/json');
    $userId = intval($_POST['user_id'] ?? 0);
    if ($userId === 0) { echo json_encode(['success' => false, 'message' => 'Invalid user.']); exit; }
    $old = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
    $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
    if (!$u) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }

    $ip = $_SERVER['REMOTE_ADDR'];
    $logAction = function($uid, $actionText, $details) use ($conn, $ip) {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $uid, $actionText, $details, $ip);
        $stmt->execute();
        $stmt->close();
    };

    $stmt = $conn->prepare("UPDATE users SET archived_at = NULL, status = 'active' WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        $logAction($adminId, 'User Restored', "Restored user '{$u['full_name']}'");
        $subject = 'Account Restored — Dasmariñas City Veterinary Services';
        $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account has been <strong>restored</strong> by an administrator. You can now log in again.</p>";
        require_once 'mailer.php';
        sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
        $_SESSION['flash_msg'] = "User '{$u['full_name']}' restored successfully.";
        $_SESSION['flash_type'] = 'success';
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to restore user.']);
    }
    $stmt->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $pageTitle; ?> - DCVS Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<link rel="stylesheet" href="assets/css/sb2.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:'Inter','Segoe UI',sans-serif;background:#ffffff;color:#18181b;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;}
.page-title-text{font-size:18px;font-weight:600;letter-spacing:-0.025em;color:#18181b;}
.sd-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;border:1px solid #e4e4e7;background:#fff;color:#18181b;transition:background 0.15s;}
.sd-btn:hover{background:#f4f4f5;}
.sd-btn-primary{background:#18181b;color:#fff;border-color:#18181b;}
.sd-btn-primary:hover{background:#27272a;}
.sd-btn-sm{padding:5px 10px;font-size:13px;}
.sd-btn-danger{color:#dc2626;}
.sd-btn-danger:hover{background:#fef2f2;}
.appt-card{background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;}
.appt-card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e4e4e7;}
.appt-header-left{display:flex;align-items:center;gap:12px;}
.appt-header-right{display:flex;align-items:center;gap:8px;}
.appt-search-wrap{display:flex;align-items:center;gap:8px;}
.appt-search-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
.appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.appt-search-input::placeholder{color:#a1a1aa;}
.appt-status-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;}
.appt-status-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.appt-table{width:100%;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
.appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
.appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
.appt-table tbody tr:last-child{border-bottom:none;}
.appt-table tbody tr:hover{background:#f9fafb;}
.appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
.client-cell{display:flex;flex-direction:column;gap:2px;}
.client-name{font-weight:600;color:#18181b;}
.contact-main{font-size:14px;font-weight:400;color:#18181b;}
.appt-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}
.appt-status-pill.active{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
.appt-status-pill.inactive{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
.appt-status-pill.archived{background:#f4f4f5;border-color:#e4e4e7;color:#71717a;}
.pet-cat-tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}
.pet-cat-tag.tag-admin{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
.pet-cat-tag.tag-staff{background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}
.pet-cat-tag.tag-resident{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
.pet-cat-tag.tag-barangay{background:#faf5ff;border-color:#e9d5ff;color:#7e22ce;}
.pet-action-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;font-size:13px;font-weight:500;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;cursor:pointer;transition:background 0.12s;}
.pet-action-btn:hover{background:#f4f4f5;}
.pet-action-btn.restore{color:#16a34a;border-color:#bbf7d0;}
.pet-action-btn.restore:hover{background:#f0fdf4;}
.dt-page{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;}
.dt-page-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-size:13px;cursor:pointer;}
.dt-page-btn:hover{background:#f4f4f5;}
.dt-page-btn.active{background:#18181b;color:#fff;border-color:#18181b;}
.dt-page-btn:disabled{opacity:0.4;cursor:default;}
.page-count{font-weight:500;color:#18181b;}
.empty-state{text-align:center;padding:48px 20px;color:#71717a;font-size:14px;}
.empty-state strong{color:#18181b;}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;display:flex;flex-direction:column-reverse;gap:0.5rem;z-index:9999;pointer-events:none;}
.toast{pointer-events:auto;display:flex;align-items:flex-start;gap:0.75rem;min-width:300px;max-width:420px;padding:1rem 1.25rem;border-radius:0.625rem;font-size:0.8125rem;line-height:1.4;font-weight:500;background:#fff;color:#18181b;border:1px solid #e4e4e7;box-shadow:0 8px 24px rgba(0,0,0,0.12),0 2px 6px rgba(0,0,0,0.06);transform:translateX(calc(100% + 1.5rem));opacity:0;transition:transform 0.3s cubic-bezier(0.16,1,0.3,1),opacity 0.25s ease;}
.toast.show{transform:translateX(0);opacity:1;}
.toast.hiding{transform:translateX(calc(100% + 1.5rem));opacity:0;}
.toast-icon{flex-shrink:0;width:1.125rem;height:1.125rem;margin-top:1px;}
.toast-body{flex:1;min-width:0;}
.toast-title{font-weight:600;color:#18181b;margin:0;}
.toast-desc{font-weight:400;color:#71717a;margin:0.125rem 0 0;font-size:0.8125rem;}
.toast-close{flex-shrink:0;width:1.25rem;height:1.25rem;display:inline-flex;align-items:center;justify-content:center;border:none;background:transparent;color:#71717a;cursor:pointer;border-radius:0.25rem;transition:background 0.12s,color 0.12s;}
.toast-close:hover{background:#f4f4f5;color:#18181b;}
.toast.success{border-color:#bbf7d0;background:#f0fdf4;}
.toast.success .toast-icon{color:#16a34a;}
.toast.success .toast-title{color:#065f46;}
.toast.error{border-color:#fecaca;background:#fef2f2;}
.toast.error .toast-icon{color:#dc2626;}
.toast.error .toast-title{color:#991b1b;}
.toast.info{border-color:#bae6fd;background:#f0f9ff;}
.toast.info .toast-icon{color:#0284c7;}
.toast.info .toast-title{color:#0c4a6e;}
.sd-dialog-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center;}
.sd-dialog-overlay.active{display:flex;}
.sd-dialog-content{background:#fff;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,0.15);width:100%;max-width:400px;padding:24px;}
.sd-dialog-title{font-size:16px;font-weight:600;color:#18181b;margin:0 0 8px;}
.sd-dialog-desc{font-size:14px;color:#71717a;margin:0 0 20px;line-height:1.5;}
.sd-dialog-actions{display:flex;justify-content:flex-end;gap:8px;}
</style>
</head>
<body>
<?php include 'includes/admin-sidebar.php'; ?>

<main class="main-content">
<?php if (isset($_SESSION['flash_msg'])): ?>
  <script>document.addEventListener('DOMContentLoaded',function(){showToast('<?php echo $_SESSION["flash_type"] === "error" ? "Error" : "Success"; ?>',{type:'<?php echo $_SESSION["flash_type"] === "error" ? "error" : "success"; ?>',description:'<?php echo addslashes($_SESSION["flash_msg"]); ?>'});});</script>
<?php unset($_SESSION['flash_msg']); unset($_SESSION['flash_type']); endif; ?>

<div class="appt-card">
  <div class="appt-card-header">
    <div class="appt-header-right">
      <div class="appt-search-wrap">
        <input type="text" id="searchInput" class="appt-search-input" placeholder="Search by name, email, or reference ID..." value="<?php echo htmlspecialchars($search); ?>" />
      </div>
      <button class="pet-action-btn" id="clearFiltersBtn" style="display:<?php echo $search ? 'inline-flex' : 'none'; ?>;" onclick="clearSearch()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters</button>
    </div>
  </div>

  <?php if (empty($users)): ?>
    <div class="empty-state">
      <p><strong>No archived <?php echo htmlspecialchars($archiveType); ?> accounts found.</strong></p>
      <p>Archived accounts will appear here.</p>
    </div>
  <?php else: ?>
    <table class="appt-table">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Username</th>
          <th>Role</th>
          <th>Barangay</th>
          <th>Status</th>
          <th>Archived</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><span style="font-size:12px;color:#71717a;font-family:'Inter','Segoe UI',sans-serif;"><?php echo htmlspecialchars($u['reference_id']); ?></span></td>
            <td><div class="client-cell"><span class="client-name"><?php echo htmlspecialchars($u['full_name']); ?></span></div></td>
            <td><span class="contact-main"><?php echo htmlspecialchars($u['email']); ?></span></td>
            <td><span class="contact-main"><?php echo htmlspecialchars($u['username']); ?></span></td>
            <td><span class="pet-cat-tag tag-<?php echo htmlspecialchars($u['role']); ?>"><?php echo ucfirst($u['role']); ?></span></td>
            <td style="font-size:14px;color:#18181b;"><?php echo htmlspecialchars($u['barangay_name'] ?? '—'); ?></td>
            <td><span class="appt-status-pill" style="background:#f4f4f5;color:#71717a;border-color:#e4e4e7;">Archived</span></td>
            <td style="font-size:14px;color:#18181b;"><?php echo date('M d, Y', strtotime($u['archived_at'])); ?></td>
            <td>
              <button class="pet-action-btn restore" onclick="restoreUser(<?php echo $u['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">
                <i class="fas fa-undo"></i> Restore
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="dt-page">
      <div>Showing <span class="page-count"><?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRows); ?></span> of <span class="page-count"><?php echo number_format($totalRows); ?></span></div>
      <div class="dt-page-btn" style="display:flex;align-items:center;gap:4px;">
        <button class="dt-page-btn" onclick="goPage(<?php echo max(1, $page - 1); ?>)" <?php if ($page <= 1) echo 'disabled'; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $startPage + 4);
        if ($endPage - $startPage < 4) $startPage = max(1, $endPage - 4);
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
          <button class="dt-page-btn <?php if ($i === $page) echo 'active'; ?>" onclick="goPage(<?php echo $i; ?>)"><?php echo $i; ?></button>
        <?php endfor; ?>
        <button class="dt-page-btn" onclick="goPage(<?php echo min($totalPages, $page + 1); ?>)" <?php if ($page >= $totalPages) echo 'disabled'; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</main>

<div class="toast-container" id="toastContainer"></div>

<div class="sd-dialog-overlay" id="confirmModal">
  <div class="sd-dialog-content">
    <h3 class="sd-dialog-title" id="confirmTitle">Restore User</h3>
    <p class="sd-dialog-desc" id="confirmText">Are you sure you want to restore this user?</p>
    <div class="sd-dialog-actions">
      <button class="sd-btn" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="confirmSubmitBtn" onclick="submitRestore()"><i class="fas fa-undo"></i> Restore</button>
    </div>
  </div>
</div>

<script>
var restoreUserId = 0;

function restoreUser(userId, name) {
  restoreUserId = userId;
  document.getElementById('confirmText').textContent = 'Are you sure you want to restore "' + name + '"? They will be able to log in again.';
  openModal('confirmModal');
}

function submitRestore() {
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
  var fd = new FormData();
  fd.append('action', 'restore_user');
  fd.append('user_id', restoreUserId);
  fetch('admin-archive.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restore';
      closeModal('confirmModal');
      if (resp.success) {
        var params = new URLSearchParams(window.location.search);
        window.location.href = 'admin-archive.php?' + params.toString();
      } else {
        showToast('Restore failed', { type: 'error', description: resp.message || 'Could not restore user.' });
      }
    })
    .catch(function() {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restore';
      closeModal('confirmModal');
      showToast('Network error', { type: 'error', description: 'Could not connect to server.' });
    });
}

function goPage(p) {
  var params = new URLSearchParams(window.location.search);
  params.set('page', p);
  window.location.href = 'admin-archive.php?' + params.toString();
}

var searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  var val = this.value;
  searchTimeout = setTimeout(function() {
    var params = new URLSearchParams(window.location.search);
    if (val) { params.set('search', val); } else { params.delete('search'); }
    params.delete('page');
    window.location.href = 'admin-archive.php?' + params.toString();
  }, 500);
});

function clearSearch() {
  var params = new URLSearchParams(window.location.search);
  params.delete('search');
  params.delete('page');
  window.location.href = 'admin-archive.php?' + params.toString();
}

function showToast(title, opts) {
  opts = opts || {};
  var type = opts.type || 'success';
  var desc = opts.description || '';
  var duration = opts.duration != null ? opts.duration : 4000;
  var container = document.getElementById('toastContainer');
  var el = document.createElement('div');
  el.className = 'toast ' + type;
  var icons = {
    success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    info: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
  };
  var html = icons[type] || icons.success;
  html += '<div class="toast-body"><p class="toast-title">' + title + '</p>';
  if (desc) html += '<p class="toast-desc">' + desc + '</p>';
  html += '</div>';
  html += '<button class="toast-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
  el.innerHTML = html;
  container.appendChild(el);
  requestAnimationFrame(function() { requestAnimationFrame(function() { el.classList.add('show'); }); });
  el.querySelector('.toast-close').addEventListener('click', function() { dismissToast(el); });
  var timer = setTimeout(function() { dismissToast(el); }, duration);
  el._timer = timer;
  el.addEventListener('mouseenter', function() { clearTimeout(el._timer); });
  el.addEventListener('mouseleave', function() { el._timer = setTimeout(function() { dismissToast(el); }, 2000); });
}
function dismissToast(el) {
  if (el._dismissed) return;
  el._dismissed = true;
  clearTimeout(el._timer);
  el.classList.remove('show');
  el.classList.add('hiding');
  setTimeout(function() { el.remove(); }, 300);
}
</script>
</body>
</html>
<?php $conn->close(); ?>
