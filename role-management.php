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
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'admin') {
    header('Location: login-admin.php');
    exit;
}

$adminId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];
$adminAvatar = $_SESSION['avatar_url'] ?? '';

// Re-verify role from database
$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $adminId);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        $_SESSION = array();
        session_destroy();
        header('Location: login-admin.php');
        exit;
    }
}
$roleCheck->close();

// ── Handle AJAX POST actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'Invalid action.'];
    $ip = $_SERVER['REMOTE_ADDR'];

    $logAction = function($userId, $actionText, $details) use ($conn, $ip) {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $actionText, $details, $ip);
        $stmt->execute();
        $stmt->close();
    };

    if ($action === 'get_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT user_id, username, email, full_name, role, barangay_id, phone, address, status FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($u) {
            echo json_encode(['success' => true, 'user' => $u]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
        }
        exit;
    }

    if ($action === 'create_user' || $action === 'edit_user') {
        $isEdit = ($action === 'edit_user');
        $userId = intval($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'resident';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $barangayIdVal = !empty($_POST['barangay_id']) ? intval($_POST['barangay_id']) : null;

        $errors = [];

        if ($isEdit) {
            if ($userId === 0) $errors[] = 'Invalid user.';
        } else {
            if (empty($password)) $errors[] = 'Password is required.';
        }
        if (empty($fullName)) $errors[] = 'Full name is required.';
        if (empty($email)) $errors[] = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        if (empty($username)) $errors[] = 'Username is required.';

        if (empty($errors)) {
            if ($isEdit) {
                $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $chk->bind_param("si", $email, $userId); $chk->execute();
                if ($chk->get_result()->num_rows > 0) $errors[] = 'Email already in use.';
                $chk->close();

                $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
                $chk->bind_param("si", $username, $userId); $chk->execute();
                if ($chk->get_result()->num_rows > 0) $errors[] = 'Username already taken.';
                $chk->close();
            } else {
                $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $chk->bind_param("s", $email); $chk->execute();
                if ($chk->get_result()->num_rows > 0) $errors[] = 'Email already in use.';
                $chk->close();

                $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                $chk->bind_param("s", $username); $chk->execute();
                if ($chk->get_result()->num_rows > 0) $errors[] = 'Username already taken.';
                $chk->close();
            }
        }

        if (empty($errors)) {
            if ($isEdit) {
                $oldRoleQ = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
                $oldRoleQ->bind_param("i", $userId); $oldRoleQ->execute(); $origRow = $oldRoleQ->get_result()->fetch_assoc(); $oldRoleQ->close();
                if ($origRow) {
                    $origRole = $origRow['role'];
                    if ($origRole === 'resident' && $role !== 'resident') {
                        $errors[] = 'A Resident account cannot change role. Only profile fields may be edited.';
                    }
                    if ($role === 'admin' && $origRole !== 'staff') {
                        $errors[] = 'Only DCVS Staff accounts can be promoted to Admin.';
                    }
                }
            }
        }

        if (empty($errors)) {
            $hash = $isEdit ? null : password_hash($password, PASSWORD_DEFAULT);

            if ($isEdit) {
                $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, username=?, role=?, phone=?, address=?, barangay_id=?, status=? WHERE user_id=?");
                $stmt->bind_param("ssssssssi", $fullName, $email, $username, $role, $phone, $address, $barangayIdVal, $status, $userId);
                if ($stmt->execute()) {
                    $detail = "Edited user '$fullName' ($email)";
                    $logAction($adminId, 'User Edited', $detail);
                    $_SESSION['flash_msg'] = "User '$fullName' updated successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: role-management.php');
                    exit;
                } else {
                    $errors[] = 'Failed to update user: ' . $stmt->error;
                }
            } else {
                $refId = generateReferenceId($conn, '#USR-', 'users');
                $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role, barangay_id, phone, address, reference_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("sssssssss", $username, $hash, $email, $fullName, $role, $barangayIdVal, $phone, $address, $refId);
                if ($stmt->execute()) {
                    $logAction($adminId, 'User Created', "Admin created user '$fullName' ($email) as $role");
                    $_SESSION['flash_msg'] = "User '$fullName' created successfully as $role.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: role-management.php');
                    exit;
                } else {
                    $errors[] = 'Failed to create user: ' . $stmt->error;
                }
            }
            $stmt->close();
        }

        if (!empty($errors)) {
            $_SESSION['flash_msg'] = implode('<br>', $errors);
            $_SESSION['flash_type'] = 'error';
            header('Location: role-management.php');
            exit;
        }
        echo json_encode($response); exit;
    }

    if ($action === 'change_role') {
        $userId = intval($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';
        $validRoles = ['resident','barangay','staff','admin'];

        if ($userId === 0 || !in_array($newRole, $validRoles)) {
            $response['message'] = 'Invalid user or role.';
            echo json_encode($response); exit;
        }

        $old = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        if ($u['role'] === 'resident') {
            $response['message'] = 'A Resident account cannot change role.';
            echo json_encode($response); exit;
        }
        if ($newRole === 'admin' && $u['role'] !== 'staff') {
            $response['message'] = 'Only DCVS Staff accounts can be promoted to Admin.';
            echo json_encode($response); exit;
        }
        if ($newRole === 'admin' && $u['role'] === 'admin') {
            $response['message'] = 'This user is already an Admin.';
            echo json_encode($response); exit;
        }
        if ($u['role'] === 'admin' && $newRole !== 'staff') {
            $response['message'] = 'An Admin account can only be demoted to DCVS Staff.';
            echo json_encode($response); exit;
        }

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $stmt->bind_param("si", $newRole, $userId);
        if ($stmt->execute()) {
            $detail = "Changed role of '{$u['full_name']}' from {$u['role']} to $newRole";
            $logAction($adminId, 'Role Changed', $detail);
            $response = ['success' => true, 'message' => "Role of '{$u['full_name']}' changed from {$u['role']} to $newRole."];
        } else {
            $response['message'] = 'Failed to change role.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'toggle_status') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email, status FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $newStatus = $u['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $newStatus, $userId);
        if ($stmt->execute()) {
            $actionLabel = $newStatus === 'active' ? 'Activated' : 'Deactivated';
            $detail = "$actionLabel user '{$u['full_name']}'";
            $logAction($adminId, "User $actionLabel", $detail);
            if ($newStatus === 'inactive') {
                $subject = 'Account Deactivated — Dasmariñas City Veterinary Services';
                $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account has been <strong>deactivated</strong> by an administrator.</p><p>You will not be able to log in until your account is reactivated. Please contact the administrator for more information.</p>";
                sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            }
            $response = ['success' => true, 'message' => "$actionLabel user '{$u['full_name']}'."];
        } else {
            $response['message'] = 'Failed to update status.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'archive_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $stmt = $conn->prepare("UPDATE users SET archived_at = NOW(), status = 'inactive' WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $detail = "Archived user '{$u['full_name']}'";
            $logAction($adminId, 'User Archived', $detail);
            $subject = 'Account Archived — Dasmariñas City Veterinary Services';
            $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account has been <strong>archived</strong> by an administrator.</p><p>You will not be able to log in until your account is restored. Please contact the administrator for more information.</p>";
            sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            $response = ['success' => true, 'message' => "User '{$u['full_name']}' archived."];
        } else {
            $response['message'] = 'Failed to archive user.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'restore_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $stmt = $conn->prepare("UPDATE users SET archived_at = NULL, status = 'active' WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $detail = "Restored user '{$u['full_name']}'";
            $logAction($adminId, 'User Restored', $detail);
            $subject = 'Account Restored — Dasmariñas City Veterinary Services';
            $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account has been <strong>restored</strong> by an administrator. You can now log in again.</p>";
            sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            $response = ['success' => true, 'message' => "User '{$u['full_name']}' restored."];
        } else {
            $response['message'] = 'Failed to restore user.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $related = [];
        $tables = ['pets' => 'owner_id', 'reports' => 'reporter_id', 'appointments' => 'user_id', 'notifications' => 'user_id', 'adoption_applications' => 'applicant_id', 'adoption_listings' => 'listed_by', 'alerts' => 'created_by', 'announcements' => 'created_by'];
        foreach ($tables as $table => $col) {
            $chk = $conn->query("SELECT COUNT(*) FROM $table WHERE $col = $userId");
            if ($chk && $chk->fetch_row()[0] > 0) $related[] = $table;
        }
        if (!empty($related)) {
            $response['message'] = "Cannot delete — user has records in: " . implode(', ', $related) . ". Archive the user instead.";
            echo json_encode($response); exit;
        }

        $subject = 'Account Deleted — Dasmariñas City Veterinary Services';
        $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account has been <strong>permanently deleted</strong> by an administrator.</p><p>If you believe this was a mistake, please contact the administrator.</p>";
        sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');

        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM activity_logs WHERE user_id = $userId");
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $userId); $stmt->execute(); $stmt->close();
            $conn->commit();
            $detail = "Permanently deleted user '{$u['full_name']}' (ID: $userId)";
            $logAction($adminId, 'User Deleted', $detail);
            $response = ['success' => true, 'message' => "User '{$u['full_name']}' permanently deleted."];
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Failed to delete user: ' . $e->getMessage();
        }
        echo json_encode($response); exit;
    }

    if ($action === 'reset_password') {
        $userId = intval($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if (strlen($newPassword) < 6) {
            $response['message'] = 'Password must be at least 6 characters.';
            echo json_encode($response); exit;
        }

        $old = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $hash, $userId);
        if ($stmt->execute()) {
            $detail = "Reset password for user '{$u['full_name']}'";
            $logAction($adminId, 'Password Reset', $detail);
            $subject = 'Password Changed — Dasmariñas City Veterinary Services';
            $htmlBody = "<p>Hello <strong>{$u['full_name']}</strong>,</p><p>Your DCVS account password has been <strong>reset</strong> by an administrator.</p><p>Please use the new password provided to log in. We recommend changing your password after logging in.</p>";
            sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            $response = ['success' => true, 'message' => "Password reset for '{$u['full_name']}'."];
        } else {
            $response['message'] = 'Failed to reset password.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    echo json_encode($response);
    exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ── Fetch users for initial display ──────────────────────────────
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
$params = [];
$types = "";

if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.reference_id LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s];
    $types = "ssss";
}
if ($roleFilter) {
    $where .= " AND u.role = ?";
    $params[] = $roleFilter;
    $types .= "s";
}
if ($statusFilter) {
    if ($statusFilter === 'archived') {
        $where .= " AND u.archived_at IS NOT NULL";
    } else {
        $where .= " AND u.status = ? AND u.archived_at IS NULL";
        $params[] = $statusFilter;
        $types .= "s";
    }
} else {
    $where .= " AND u.archived_at IS NULL";
}

$sql = "SELECT u.*, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id $where ORDER BY u.created_at DESC";
$q = $conn->prepare($sql);
if ($types) $q->bind_param($types, ...$params);
$q->execute();
$users = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();
$totalUsers = count($users);

$barangays = [];
$br = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($br) $barangays = $br->fetch_all(MYSQLI_ASSOC);

$archivedCount = $conn->query("SELECT COUNT(*) FROM users WHERE archived_at IS NOT NULL")->fetch_row()[0];
$pageTitle = 'Role Management';
$activePage = 'roles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?php echo htmlspecialchars($pageTitle); ?> — Admin | DCVS</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<link rel="stylesheet" href="assets/css/dashboard.css" />
<link rel="stylesheet" href="assets/css/responsive.css" />
<style>
body { background:#f8fafc; }
.main-content { padding:1.75rem 2rem; }

.page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.75rem; flex-wrap:wrap; gap:1rem; }
.page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; display:flex; align-items:center; gap:10px; letter-spacing:-0.02em; }
.page-title i { color:#045e1f; }
.page-subtitle { font-size:0.85rem; color:#64748b; margin:4px 0 0; line-height:1.5; }

.filters-bar { display:flex; gap:10px; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; }
.search-box {
  display:flex; align-items:center; gap:8px;
  background:#fff; border:1px solid #e2e8f0; border-radius:8px;
  padding:0 14px; flex:1; min-width:200px; max-width:360px;
  transition:border-color 0.2s;
}
.search-box:focus-within { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
.search-box i { color:#94a3b8; font-size:0.85rem; }
.search-box input {
  flex:1; border:none; outline:none; padding:9px 0;
  font-size:0.82rem; font-family:'Inter','Segoe UI',sans-serif;
  color:#0f172a; background:transparent;
}
.filter-select {
  padding:9px 14px; border:1px solid #e2e8f0; border-radius:8px;
  font-size:0.82rem; font-family:'Inter','Segoe UI',sans-serif;
  color:#475569; background:#fff; cursor:pointer; outline:none;
  transition:border-color 0.2s; min-width:150px;
}
.filter-select:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
.btn-primary-sm {
  display:inline-flex; align-items:center; gap:6px;
  padding:9px 18px; border-radius:8px; border:none;
  background:#000; color:#fff; font-size:0.82rem; font-weight:600;
  cursor:pointer; transition:all 0.2s; text-decoration:none;
}
.btn-primary-sm:hover { background:#222; transform:translateY(-1px); box-shadow:0 4px 16px rgba(0,0,0,0.12); }

.table-container {
  background:#fff; border:1px solid #eef2f6; border-radius:14px;
  overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04);
}
.table-container .data-table { margin:0; }
.data-table thead th { position:sticky; top:0; z-index:2; }

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
.status-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.status-dot.active { background:#10b981; }
.status-dot.inactive { background:#f59e0b; }
.status-dot.archived { background:#94a3b8; }

.action-dropdown { position:static; display:inline-block; }
.action-trigger {
  display:inline-flex; align-items:center; justify-content:center;
  width:32px; height:32px; border-radius:6px;
  border:1px solid #e2e8f0; background:#fff;
  color:#94a3b8; cursor:pointer; transition:all 0.15s;
}
.action-trigger:hover { border-color:#cbd5e1; color:#475569; background:#f8fafc; }
.action-menu {
  position:fixed; z-index:9999;
  min-width:210px; background:#fff;
  border:1px solid #e2e8f0; border-radius:10px;
  box-shadow:0 10px 40px rgba(0,0,0,0.18);
  padding:6px; display:none;
}
.action-menu.open { display:block; }
.action-menu-item {
  display:flex; align-items:center; gap:10px;
  width:100%; padding:8px 12px; border:none;
  border-radius:6px; background:none;
  font-size:0.82rem; font-weight:500; color:#475569;
  cursor:pointer; transition:all 0.1s; text-align:left;
  text-decoration:none;
}
.action-menu-item:hover { background:#f8fafc; color:#0f172a; }
.action-menu-item i { width:16px; text-align:center; font-size:0.8rem; color:#94a3b8; }
.action-menu-item.danger { color:#dc2626; }
.action-menu-item.danger:hover { background:#fef2f2; }
.action-menu-item.danger i { color:#dc2626; }
.action-menu-item.warning { color:#d97706; }
.action-menu-item.warning:hover { background:#fffbeb; }
.action-menu-item.warning i { color:#d97706; }
.action-menu-item.success { color:#059669; }
.action-menu-item.success:hover { background:#f0fdf4; }
.action-menu-item.success i { color:#059669; }
.menu-divider { height:1px; background:#f1f5f9; margin:4px 8px; }

/* ── Pagination ── */
.pagination-bar {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 20px; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:8px;
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

/* ── Compact User Modal (fixes tall form scrollbar) ── */
#userModal .modal-body {
  padding:24px 28px 12px 28px;
}
#userModal .form-group {
  margin-bottom:12px;
}
#userModal .form-row {
  gap:14px;
}
#userModal .form-group label {
  font-size:0.8rem;
  margin-bottom:4px;
}
#userModal .form-control {
  padding:8px 12px;
  font-size:0.82rem;
}
#userModal .modal-actions {
  padding:14px 28px 24px 28px;
}
#userModal textarea {
  min-height:54px;
}

.flash-message {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; border-radius:10px; font-size:0.85rem;
  font-weight:500; margin-bottom:1rem;
}
.flash-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#065f46; }
.flash-error { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

.toast-container { position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.toast {
  padding:12px 18px; border-radius:10px; font-size:0.82rem; font-weight:500;
  display:flex; align-items:center; gap:10px;
  box-shadow:0 8px 32px rgba(0,0,0,0.12);
  animation:toastIn 0.25s ease; max-width:400px; pointer-events:auto;
}
.toast-success { background:#f0fdf4; color:#065f46; border:1px solid #bbf7d0; border-left:3px solid #059669; }
.toast-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-left:3px solid #dc2626; }
.toast i { font-size:1rem; }
@keyframes toastIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }

.role-warning { margin-top:8px; padding:10px 12px; border-radius:8px; border-left:3px solid #f59e0b; background:#fffbeb; font-size:0.82rem; color:#92400e; }
.role-warning i { margin-right:6px; }

.confirm-icon { text-align:center; margin-bottom:14px; }
.confirm-icon i { font-size:2.5rem; }
.confirm-text { text-align:center; font-size:0.9rem; color:#64748b; line-height:1.5; }
.confirm-text strong { color:#0f172a; }
.text-muted { color:#94a3b8; font-size:0.82rem; }
.text-xs { font-size:0.72rem; color:#94a3b8; }

@media (max-width:768px) {
  .filters-bar { flex-direction:column; align-items:stretch; }
  .search-box { max-width:none; }
  .filter-select { width:100%; }
  .pagination-bar { flex-direction:column; align-items:stretch; text-align:center; }
}
</style>
</head>
<body>

<?php include 'includes/admin-sidebar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Role Management</h1>
      <p class="page-subtitle">Centralized user and access control — manage all accounts across all roles</p>
    </div>
  </div>

  <?php if (isset($_SESSION['flash_msg'])): ?>
    <div class="flash-message flash-<?php echo $_SESSION['flash_type'] === 'error' ? 'error' : 'success'; ?>">
      <i class="fas fa-<?php echo $_SESSION['flash_type'] === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
      <span><?php echo htmlspecialchars($_SESSION['flash_msg']); ?></span>
    </div>
  <?php unset($_SESSION['flash_msg']); unset($_SESSION['flash_type']); endif; ?>

  <div class="filters-bar">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="searchInput" placeholder="Search by name, email, username, or reference ID..." value="<?php echo htmlspecialchars($search); ?>" />
    </div>
    <select class="filter-select" id="roleFilter">
      <option value="">All Roles</option>
      <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
      <option value="staff" <?php echo $roleFilter === 'staff' ? 'selected' : ''; ?>>DCVS Staff</option>
      <option value="barangay" <?php echo $roleFilter === 'barangay' ? 'selected' : ''; ?>>Barangay Official</option>
      <option value="resident" <?php echo $roleFilter === 'resident' ? 'selected' : ''; ?>>Resident</option>
    </select>
    <select class="filter-select" id="statusFilter">
      <option value="">Active Users</option>
      <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
      <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
      <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived <?php echo $archivedCount ? "($archivedCount)" : ''; ?></option>
    </select>
    <button class="btn-primary-sm" onclick="openAddModal()"><i class="fas fa-plus"></i> Add User</button>
  </div>

  <div class="table-container" id="tableWrap">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
          <th>Reference</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Barangay</th>
            <th>Status</th>
            <th>Registered</th>
            <th style="width:60px;"></th>
          </tr>
        </thead>
        <tbody id="userTableBody">
          <?php if (empty($users)): ?>
            <tr><td colspan="8" class="table-empty">No users found.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u): ?>
              <tr>
              <td><span style="font-family:monospace;font-size:0.75rem;color:#045e1f;font-weight:600;"><?php echo htmlspecialchars($u['reference_id'] ?? '—'); ?></span></td>
                <td class="name-cell"><?php echo htmlspecialchars($u['full_name']); ?></td>
                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="role-chip"><i class="fas <?php echo $u['role']==='admin'?'fa-crown':($u['role']==='staff'?'fa-user-md':($u['role']==='barangay'?'fa-map-pin':'fa-user')); ?>"></i> <?php echo ucfirst($u['role']); ?></span></td>
                <td style="font-size:0.78rem;color:#64748b;"><?php echo htmlspecialchars($u['barangay_name'] ?? '—'); ?></td>
                <td>
                  <?php if ($u['archived_at']): ?>
                    <span class="status-indicator"><span class="status-dot archived"></span> Archived</span>
                  <?php else: ?>
                    <span class="status-indicator"><span class="status-dot <?php echo $u['status']; ?>"></span> <?php echo ucfirst($u['status']); ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-size:0.75rem;color:#94a3b8;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td>
                  <div class="action-dropdown" data-userid="<?php echo $u['user_id']; ?>">
                    <button class="action-trigger" onclick="toggleDropdown(this)" title="Actions">
                      <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="action-menu">
                      <button class="action-menu-item" onclick="closeDropdowns();openEditModal(<?php echo $u['user_id']; ?>)">
                        <i class="fas fa-edit"></i> Edit
                      </button>
                      <?php if ($u['role'] !== 'resident'): ?>
                      <button class="action-menu-item" onclick="closeDropdowns();openRoleModal(<?php echo (int)$u['user_id']; ?>, '<?php echo htmlspecialchars($u['role'], ENT_QUOTES); ?>')">
                        <i class="fas fa-exchange-alt"></i> Change Role
                      </button>
                      <?php endif; ?>
                      <div class="menu-divider"></div>
                      <?php if ($u['archived_at']): ?>
                        <button class="action-menu-item success" onclick="closeDropdowns();confirmAction('restore_user', <?php echo $u['user_id']; ?>, 'Restore user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? This will reactivate their account.')">
                          <i class="fas fa-undo"></i> Restore
                        </button>
                      <?php else: ?>
                        <button class="action-menu-item <?php echo $u['status'] === 'active' ? 'warning' : 'success'; ?>" onclick="closeDropdowns();confirmAction('toggle_status', <?php echo $u['user_id']; ?>, '<?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>?')">
                          <i class="fas fa-<?php echo $u['status'] === 'active' ? 'ban' : 'check-circle'; ?>"></i>
                          <?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="action-menu-item" onclick="closeDropdowns();confirmAction('archive_user', <?php echo $u['user_id']; ?>, 'Archive user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? They will be deactivated and hidden.')">
                          <i class="fas fa-archive"></i> Archive
                        </button>
                      <?php endif; ?>
                      <button class="action-menu-item" onclick="closeDropdowns();openPasswordModal(<?php echo $u['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">
                        <i class="fas fa-key"></i> Reset Password
                      </button>
                      <div class="menu-divider"></div>
                      <button class="action-menu-item danger" onclick="closeDropdowns();confirmAction('delete_user', <?php echo $u['user_id']; ?>, 'Permanently delete user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? This will also remove their activity logs.')">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div id="rolePagination" class="pagination-bar"></div>
  </div>
</main>

<div class="toast-container" id="toastContainer"></div>

<!-- ══ Add/Edit User Modal (compact) ══ -->
<div class="modal" id="userModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="userModalTitle">Add New User</h3>
      <button class="modal-close" onclick="closeModal('userModal')"><i class="fas fa-times"></i></button>
    </div>
    <form id="userForm" action="role-management.php" method="POST" onsubmit="return handleUserFormSubmit()">
      <input type="hidden" name="user_id" id="editUserId" value="" />
      <input type="hidden" name="action" id="userFormAction" value="create_user" />
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" class="form-control" name="full_name" id="uFullName" required />
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" class="form-control" name="email" id="uEmail" required />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Username</label>
            <input type="text" class="form-control" name="username" id="uUsername" required />
          </div>
          <div class="form-group" id="passwordFieldGroup">
            <label>Password</label>
            <input type="password" class="form-control" name="password" id="uPassword" placeholder="Min. 6 characters" />
            <div class="text-xs" style="margin-top:2px;">Leave blank to keep current password when editing.</div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Role</label>
            <select class="form-control" name="role" id="uRole">
              <option value="resident">Resident</option>
              <option value="barangay">Barangay Official</option>
              <option value="staff">DCVS Staff</option>
              <option value="admin">Admin</option>
            </select>
            <div id="uRoleHint" style="display:none;margin-top:3px;font-size:0.75rem;color:#d97706;">
              <i class="fas fa-lock"></i> Resident accounts cannot change role.
            </div>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select class="form-control" name="status" id="uStatus">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input type="text" class="form-control" name="phone" id="uPhone" placeholder="e.g. 09123456789" />
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Barangay</label>
            <select class="form-control" name="barangay_id" id="uBarangay">
              <option value="">None</option>
              <?php foreach ($barangays as $b): ?>
                <option value="<?php echo $b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Address</label>
            <input type="text" class="form-control" name="address" id="uAddress" placeholder="Street, Barangay, City" />
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="closeModal('userModal')">Cancel</button>
        <button type="submit" class="btn-primary" id="userFormSubmit"><i class="fas fa-save"></i> <span id="userFormBtnText">Create User</span></button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Change Role Modal ══ -->
<div class="modal" id="roleModal">
  <div class="modal-content" style="max-width:480px;">
    <div class="modal-header">
      <h3>Change User Role</h3>
      <button class="modal-close" onclick="closeModal('roleModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="confirm-text" style="margin-bottom:16px;">
        Change role for user <strong id="roleUserName">—</strong><br />
        <span class="text-muted">Current role: <span id="roleCurrentRole">—</span></span>
      </div>
      <div class="form-group">
        <label>New Role</label>
        <select class="form-control" id="roleNewRole">
          <option value="resident">Resident</option>
          <option value="barangay">Barangay Official</option>
          <option value="staff">DCVS Staff</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div id="roleChangeWarning" class="role-warning" style="display:block;">
        <i class="fas fa-exclamation-triangle"></i>
        Changing a role updates the user's permissions immediately. They will only access the dashboard corresponding to their new role.
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" onclick="closeModal('roleModal')">Cancel</button>
      <button type="button" class="btn-primary" id="roleSubmitBtn" onclick="submitRoleChange()"><i class="fas fa-exchange-alt"></i> Change Role</button>
    </div>
  </div>
</div>

<!-- ══ Confirm Action Modal ══ -->
<div class="modal" id="confirmModal">
  <div class="modal-content" style="max-width:420px;">
    <div class="modal-body" style="padding:30px 28px 20px;">
      <div class="confirm-icon" id="confirmIcon">
        <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
      </div>
      <div class="confirm-text" id="confirmText">Are you sure?</div>
    </div>
    <div class="modal-actions" style="justify-content:center;padding-top:16px;">
      <button type="button" class="btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
      <button type="button" class="btn-primary" id="confirmSubmitBtn" onclick="submitConfirmedAction()"><i class="fas fa-check"></i> Confirm</button>
    </div>
  </div>
</div>

<!-- ══ Password Reset Modal ══ -->
<div class="modal" id="passwordModal">
  <div class="modal-content" style="max-width:400px;">
    <div class="modal-header">
      <h3>Reset Password</h3>
      <button class="modal-close" onclick="closeModal('passwordModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="confirm-text" style="margin-bottom:12px;">
        Reset password for <strong id="pwdUserName">—</strong>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label>New Password</label>
        <input type="password" class="form-control" id="pwdNewPassword" placeholder="Min. 6 characters" />
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" class="form-control" id="pwdConfirmPassword" placeholder="Re-enter password" />
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" onclick="closeModal('passwordModal')">Cancel</button>
      <button type="button" class="btn-primary" onclick="submitPasswordReset()"><i class="fas fa-key"></i> Reset Password</button>
    </div>
  </div>
</div>

<script>
function toggleDropdown(btn) {
  var menu = btn.parentElement.querySelector('.action-menu');
  var isOpen = menu.classList.contains('open');
  closeDropdowns();
  if (!isOpen) {
    var rect = btn.getBoundingClientRect();
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.left = Math.max(8, rect.right - 210) + 'px';
    menu.classList.add('open');
  }
}
function closeDropdowns() {
  document.querySelectorAll('.action-menu.open').forEach(function(m) { m.classList.remove('open'); });
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.action-dropdown')) closeDropdowns();
});

function showToast(message, type) {
  var c = document.getElementById('toastContainer');
  var t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
  c.appendChild(t);
  setTimeout(function() { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(function() { t.remove(); }, 300); }, 3500);
}

function apiPost(data, callback) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'role-management.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.timeout = 15000;
  xhr.onload = function() {
    try { var resp = JSON.parse(xhr.responseText); callback(resp); }
    catch(e) { console.error('API response text:', xhr.responseText); callback({success:false, message:'Server error - response was not valid JSON.'}); }
  };
  xhr.onerror = function() { callback({success:false, message:'Network error - check your connection.'}); };
  xhr.ontimeout = function() { callback({success:false, message:'Request timed out - please try again.'}); };
  var fd = [];
  for (var k in data) { if (data.hasOwnProperty(k)) fd.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k])); }
  xhr.send(fd.join('&'));
}

function reloadTable() {
  var p = [];
  var s = document.getElementById('searchInput').value;
  var r = document.getElementById('roleFilter').value;
  var st = document.getElementById('statusFilter').value;
  if (s) p.push('search=' + encodeURIComponent(s));
  if (r) p.push('role=' + encodeURIComponent(r));
  if (st) p.push('status=' + encodeURIComponent(st));
  p.push('page=1');
  window.location.href = 'role-management.php?' + p.join('&');
}

// ── Client-side pagination ──
(function() {
  var PER_PAGE = 15;
  var tbody = document.querySelector('#usersTable tbody');
  var pag = document.getElementById('rolePagination');
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
    h += '<a href="#" onclick="window.rolePageGo(' + (currentPage - 1) + ');return false;" class="pag-btn' + (currentPage <= 1 ? ' disabled' : '') + '"><i class="fas fa-angle-left"></i></a>';
    h += '<a href="#" onclick="window.rolePageGo(' + (currentPage + 1) + ');return false;" class="pag-btn' + (currentPage >= tp ? ' disabled' : '') + '"><i class="fas fa-angle-right"></i></a>';
    h += '</div>';
    pag.innerHTML = h;
  }

  window.rolePageGo = function(page) {
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };

  renderTable();
})();

var searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(reloadTable, 400);
});
document.getElementById('roleFilter').addEventListener('change', reloadTable);
document.getElementById('statusFilter').addEventListener('change', reloadTable);

function handleUserFormSubmit() {
  var roleSel = document.getElementById('uRole');
  if (roleSel.disabled) roleSel.disabled = false;
  return true;
}

function openAddModal() {
  document.getElementById('userModalTitle').textContent = 'Add New User';
  document.getElementById('userFormAction').value = 'create_user';
  document.getElementById('userFormBtnText').textContent = 'Create User';
  document.getElementById('editUserId').value = '';
  document.getElementById('uFullName').value = '';
  document.getElementById('uEmail').value = '';
  document.getElementById('uUsername').value = '';
  document.getElementById('uPassword').value = '';
  document.getElementById('uPassword').required = true;
  document.getElementById('uPassword').placeholder = 'Min. 6 characters';
  document.getElementById('passwordFieldGroup').style.display = 'block';
  var roleSel = document.getElementById('uRole');
  roleSel.value = 'resident';
  roleSel.disabled = false;
  roleSel.style.opacity = '1';
  roleSel.title = '';
  document.getElementById('uRoleHint').style.display = 'none';
  document.getElementById('uStatus').value = 'active';
  document.getElementById('uPhone').value = '';
  document.getElementById('uBarangay').value = '';
  document.getElementById('uAddress').value = '';
  openModal('userModal');
}

function openEditModal(userId) {
  document.getElementById('userModalTitle').textContent = 'Edit User';
  document.getElementById('userFormAction').value = 'edit_user';
  document.getElementById('userFormBtnText').textContent = 'Save Changes';
  document.getElementById('editUserId').value = userId;
  document.getElementById('uPassword').required = false;
  document.getElementById('uPassword').placeholder = 'Leave blank to keep current';
  document.getElementById('passwordFieldGroup').style.display = 'block';

  apiPost({action:'get_user', user_id: userId}, function(resp) {
    if (resp.success && resp.user) {
      var u = resp.user;
      document.getElementById('uFullName').value = u.full_name;
      document.getElementById('uEmail').value = u.email;
      document.getElementById('uUsername').value = u.username;
      document.getElementById('uPassword').value = '';
      document.getElementById('uRole').value = u.role;
      document.getElementById('uStatus').value = u.status;
      document.getElementById('uPhone').value = u.phone || '';
      document.getElementById('uBarangay').value = u.barangay_id || '';
      document.getElementById('uAddress').value = u.address || '';

      var roleSel = document.getElementById('uRole');
      if (u.role === 'resident') {
        roleSel.disabled = true;
        roleSel.title = 'Resident accounts cannot change role.';
        roleSel.style.opacity = '0.6';
        document.getElementById('uRoleHint').style.display = 'block';
      } else {
        roleSel.disabled = false;
        roleSel.title = '';
        roleSel.style.opacity = '1';
        document.getElementById('uRoleHint').style.display = 'none';
      }
      openModal('userModal');
    } else {
      showToast(resp.message || 'Failed to fetch user data.', 'error');
    }
  });
}

var roleChangeUserId = 0;
function openRoleModal(userId, currentRole) {
  roleChangeUserId = userId;
  document.getElementById('roleCurrentRole').textContent = currentRole;
  var newRoleSel = document.getElementById('roleNewRole');
  var roleWarning = document.getElementById('roleChangeWarning');

  newRoleSel.innerHTML = '';
  if (currentRole === 'resident') {
    roleWarning.innerHTML = '<i class="fas fa-ban"></i> Resident accounts cannot change role.';
    roleWarning.style.display = 'block';
    document.getElementById('roleSubmitBtn').style.display = 'none';
  } else {
    var allowedRoles = [];
    if (currentRole === 'admin') {
      allowedRoles = ['staff'];
      roleWarning.innerHTML = '<i class="fas fa-info-circle"></i> Admin accounts can only be demoted to DCVS Staff.';
      roleWarning.style.display = 'block';
    } else if (currentRole === 'staff') {
      allowedRoles = ['staff', 'admin'];
      roleWarning.innerHTML = '<i class="fas fa-info-circle"></i> Staff can be promoted to Admin or stay as Staff.';
      roleWarning.style.display = 'block';
    } else {
      allowedRoles = ['barangay', 'staff'];
      roleWarning.innerHTML = '<i class="fas fa-info-circle"></i> Barangay Officials can be changed to DCVS Staff.';
      roleWarning.style.display = 'block';
    }
    allowedRoles.forEach(function(r) {
      var opt = document.createElement('option');
      opt.value = r;
      opt.textContent = r.charAt(0).toUpperCase() + r.slice(1);
      if (r === currentRole) opt.selected = true;
      newRoleSel.appendChild(opt);
    });
    document.getElementById('roleSubmitBtn').style.display = 'inline-flex';
  }

  apiPost({action:'get_user', user_id: userId}, function(resp) {
    if (resp.success && resp.user) {
      document.getElementById('roleUserName').textContent = resp.user.full_name;
    }
  });
  openModal('roleModal');
}

function submitRoleChange() {
  var btn = document.getElementById('roleSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  apiPost({action:'change_role', user_id: roleChangeUserId, new_role: document.getElementById('roleNewRole').value}, function(resp) {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Change Role';
    if (resp.success) { showToast(resp.message, 'success'); closeModal('roleModal'); reloadTable(); }
    else { showToast(resp.message, 'error'); }
  });
}

var pendingAction = null;
var pendingUserId = 0;

function confirmAction(action, userId, message) {
  pendingAction = action;
  pendingUserId = userId;
  document.getElementById('confirmText').innerHTML = message;
  var icon = document.getElementById('confirmIcon');
  var btn = document.getElementById('confirmSubmitBtn');
  btn.innerHTML = '<i class="fas fa-check"></i> Confirm';
  btn.className = 'btn-primary';
  icon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>';
  if (action === 'delete_user') {
    icon.innerHTML = '<i class="fas fa-trash" style="color:#dc2626;"></i>';
    btn.className = 'btn-danger';
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
  }
  if (action === 'toggle_status') {
    icon.innerHTML = '<i class="fas fa-ban" style="color:#f59e0b;"></i>';
  }
  if (action === 'archive_user') {
    icon.innerHTML = '<i class="fas fa-archive" style="color:#64748b;"></i>';
  }
  if (action === 'restore_user') {
    icon.innerHTML = '<i class="fas fa-undo" style="color:#059669;"></i>';
    btn.className = 'btn-primary';
  }
  openModal('confirmModal');
}

function submitConfirmedAction() {
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
  apiPost({action: pendingAction, user_id: pendingUserId}, function(resp) {
    btn.disabled = false;
    closeModal('confirmModal');
    if (resp.success) { showToast(resp.message, 'success'); reloadTable(); }
    else { showToast(resp.message, 'error'); }
  });
}

var pwdUserId = 0;
function openPasswordModal(userId, userName) {
  pwdUserId = userId;
  document.getElementById('pwdUserName').textContent = userName;
  document.getElementById('pwdNewPassword').value = '';
  document.getElementById('pwdConfirmPassword').value = '';
  openModal('passwordModal');
}

function submitPasswordReset() {
  var pw = document.getElementById('pwdNewPassword').value;
  var cp = document.getElementById('pwdConfirmPassword').value;
  if (!pw || pw.length < 6) { showToast('Password must be at least 6 characters.', 'error'); return; }
  if (pw !== cp) { showToast('Passwords do not match.', 'error'); return; }
  apiPost({action:'reset_password', user_id: pwdUserId, new_password: pw}, function(resp) {
    if (resp.success) { showToast(resp.message, 'success'); closeModal('passwordModal'); }
    else { showToast(resp.message, 'error'); }
  });
}
</script>

</body>
</html>
<?php $conn->close(); ?>
