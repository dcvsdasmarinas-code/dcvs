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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../mailer.php';

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
    $beginLifecycleTransaction = function($userIds, $mode) use ($conn, $adminId) {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function($id) { return $id > 0; })));
        if (!$userIds) return ['error' => 'Select at least one valid user.'];

        $conn->begin_transaction();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $stmt = $conn->prepare("SELECT user_id, full_name, email, role, status FROM users WHERE user_id IN ($placeholders) AND deleted_at IS NULL FOR UPDATE");
        $stmt->bind_param($types, ...$userIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = [];
        while ($user = $result->fetch_assoc()) $users[(int)$user['user_id']] = $user;
        $stmt->close();

        if (count($users) !== count($userIds)) {
            $conn->rollback();
            return ['error' => 'One or more users no longer exist or have already been deleted. Refresh the page and try again.'];
        }

        $shouldDeactivate = $mode === 'delete' ||
            ($mode === 'toggle' && isset($users[(int)$adminId]) && $users[(int)$adminId]['status'] === 'active') ||
            ($mode === 'deactivate');
        if ($shouldDeactivate && isset($users[(int)$adminId])) {
            $conn->rollback();
            return ['error' => 'You cannot deactivate or delete your own admin account.'];
        }

        if ($mode === 'delete' || $mode === 'deactivate' || $mode === 'toggle') {
            $adminRows = $conn->query("SELECT user_id, status FROM users WHERE role = 'admin' AND deleted_at IS NULL FOR UPDATE");
            $allAdminIds = [];
            $activeAdminIds = [];
            while ($row = $adminRows->fetch_assoc()) {
                $adminIdInDb = (int)$row['user_id'];
                $allAdminIds[] = $adminIdInDb;
                if ($row['status'] === 'active') $activeAdminIds[] = $adminIdInDb;
            }
            $targetAdmins = count(array_intersect($allAdminIds, $userIds));
            if ($mode === 'delete' && $targetAdmins > 0 && count($allAdminIds) - $targetAdmins < 1) {
                $conn->rollback();
                return ['error' => 'The last admin account cannot be deleted.'];
            }
            $targetActiveAdmins = count(array_intersect($activeAdminIds, $userIds));
            if ($targetActiveAdmins > 0 && count($activeAdminIds) - $targetActiveAdmins < 1) {
                $conn->rollback();
                return ['error' => 'The last active admin account cannot be deactivated or deleted.'];
            }
        }

        return ['users' => $users, 'ids' => $userIds];
    };

    if ($action === 'get_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.barangay_id, u.phone, u.address, u.status, u.reference_id, u.created_at, u.avatar_url, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.user_id = ? AND u.deleted_at IS NULL");
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

    if ($action === 'get_user_pets') {
        $userId = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, created_at FROM pets WHERE owner_id = ? AND archived_at IS NULL AND EXISTS (SELECT 1 FROM users WHERE user_id = ? AND deleted_at IS NULL) ORDER BY pet_id ASC");
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $pets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'pets' => $pets]);
        exit;
    }

    if ($action === 'get_user_activity') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId < 1) {
            echo json_encode(['success' => false, 'message' => 'Invalid user.']);
            exit;
        }
        $userStmt = $conn->prepare("SELECT full_name, created_at FROM users WHERE user_id = ? AND deleted_at IS NULL");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $profile = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!$profile) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        $activities = [];
        if (!empty($profile['created_at'])) {
            $activities[] = [
                'action' => 'Account registered',
                'details' => '',
                'created_at' => $profile['created_at'],
                'sort_at' => strtotime($profile['created_at']),
            ];
        }

        $petStmt = $conn->prepare("SELECT pet_name, pet_type, created_at FROM pets WHERE owner_id = ? ORDER BY created_at DESC");
        $petStmt->bind_param("i", $userId);
        $petStmt->execute();
        $petResult = $petStmt->get_result();
        while ($pet = $petResult->fetch_assoc()) {
            $activities[] = [
                'action' => 'Pet registered: ' . $pet['pet_name'],
                'details' => ucfirst((string)$pet['pet_type']),
                'created_at' => $pet['created_at'],
                'sort_at' => strtotime($pet['created_at']),
            ];
        }
        $petStmt->close();

        $reportStmt = $conn->prepare("SELECT report_type, status, created_at FROM reports WHERE reporter_id = ? AND archived_at IS NULL ORDER BY created_at DESC");
        $reportStmt->bind_param("i", $userId);
        $reportStmt->execute();
        $reportResult = $reportStmt->get_result();
        while ($report = $reportResult->fetch_assoc()) {
            $activities[] = [
                'action' => ucfirst((string)$report['report_type']) . ' report submitted',
                'details' => 'Status: ' . ucfirst((string)$report['status']),
                'created_at' => $report['created_at'],
                'sort_at' => strtotime($report['created_at']),
            ];
        }
        $reportStmt->close();

        $appointmentStmt = $conn->prepare("SELECT appointment_type, preferred_date, status, created_at FROM appointments WHERE user_id = ? AND archived_at IS NULL ORDER BY created_at DESC");
        $appointmentStmt->bind_param("i", $userId);
        $appointmentStmt->execute();
        $appointmentResult = $appointmentStmt->get_result();
        while ($appointment = $appointmentResult->fetch_assoc()) {
            $details = 'Status: ' . ucfirst((string)$appointment['status']);
            if (!empty($appointment['preferred_date'])) {
                $details .= ' · Scheduled date: ' . date('M j, Y', strtotime($appointment['preferred_date']));
            }
            $activities[] = [
                'action' => ucfirst((string)$appointment['appointment_type']) . ' appointment requested',
                'details' => $details,
                'created_at' => $appointment['created_at'],
                'sort_at' => strtotime($appointment['created_at']),
            ];
        }
        $appointmentStmt->close();

        usort($activities, function ($left, $right) {
            return $right['sort_at'] <=> $left['sort_at'];
        });
        $activities = array_slice($activities, 0, 100);
        foreach ($activities as &$activity) {
            unset($activity['sort_at']);
        }
        unset($activity);
        echo json_encode(['success' => true, 'activities' => $activities]);
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
                $oldRoleQ = $conn->prepare("SELECT role FROM users WHERE user_id = ? AND deleted_at IS NULL");
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
                $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, username=?, role=?, phone=?, address=?, barangay_id=? WHERE user_id=? AND deleted_at IS NULL");
                $stmt->bind_param("sssssssi", $fullName, $email, $username, $role, $phone, $address, $barangayIdVal, $userId);
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

        $old = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ? AND deleted_at IS NULL");
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
            $_SESSION['flash_msg'] = "Role of '{$u['full_name']}' changed from {$u['role']} to $newRole.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } else {
            $response['message'] = 'Failed to change role.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'toggle_status') {
        $userId = intval($_POST['user_id'] ?? 0);
        $lifecycle = $beginLifecycleTransaction([$userId], 'toggle');
        if (!empty($lifecycle['error'])) { $response['message'] = $lifecycle['error']; echo json_encode($response); exit; }
        $u = $lifecycle['users'][$userId];

        $newStatus = $u['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("si", $newStatus, $userId);
        if ($stmt->execute()) {
            $conn->commit();
            $actionLabel = $newStatus === 'active' ? 'Activated' : 'Deactivated';
            $detail = "$actionLabel user '{$u['full_name']}'";
            $logAction($adminId, "User $actionLabel", $detail);
            $_SESSION['flash_msg'] = "User '{$u['full_name']}' " . strtolower($actionLabel) . " successfully.";
            $_SESSION['flash_type'] = 'success';
            if ($newStatus === 'inactive' && $u['role'] === 'resident') {
                $subject = 'Account Deactivated — Dasmariñas City Veterinary Services';
                $htmlBody = dcvsEmailTemplate('Account Deactivated', '<p style="margin:0 0 16px;">Hello <strong>' . htmlspecialchars($u['full_name']) . '</strong>,</p><p style="margin:0 0 16px;">Your DCVS account has been <strong>deactivated</strong> by an administrator.</p><p style="margin:0 0 16px;">You will not be able to log in until your account is reactivated. Please contact the administrator for more information.</p>');
                sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            }
            $response = ['success' => true];
        } else {
            $conn->rollback();
            $response['message'] = 'Failed to update status.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'archive_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email, role FROM users WHERE user_id = ? AND deleted_at IS NULL");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $stmt = $conn->prepare("UPDATE users SET archived_at = NOW() WHERE user_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $detail = "Archived user '{$u['full_name']}'";
            $logAction($adminId, 'User Archived', $detail);
            $_SESSION['flash_msg'] = "User '{$u['full_name']}' archived successfully.";
            $_SESSION['flash_type'] = 'success';
            if ($u['role'] === 'resident') {
                $subject = 'Account Archived — Dasmariñas City Veterinary Services';
                $htmlBody = dcvsEmailTemplate('Account Archived', '<p style="margin:0 0 16px;">Hello <strong>' . htmlspecialchars($u['full_name']) . '</strong>,</p><p style="margin:0 0 16px;">Your account has been archived from the administrator’s main user list. This organizational action does not change your account access.</p>');
                sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            }
            $response = ['success' => true];
        } else {
            $response['message'] = 'Failed to archive user.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'restore_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $old = $conn->prepare("SELECT full_name, email, role, status FROM users WHERE user_id = ? AND archived_at IS NOT NULL AND deleted_at IS NULL");
        $old->bind_param("i", $userId); $old->execute(); $u = $old->get_result()->fetch_assoc(); $old->close();
        if (!$u) { $response['message'] = 'User not found.'; echo json_encode($response); exit; }

        $stmt = $conn->prepare("UPDATE users SET archived_at = NULL WHERE user_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $detail = "Unarchived user '{$u['full_name']}'";
            $logAction($adminId, 'User Unarchived', $detail);
            $_SESSION['flash_msg'] = "User '{$u['full_name']}' unarchived successfully.";
            $_SESSION['flash_type'] = 'success';
            if ($u['role'] === 'resident') {
                $accessText = $u['status'] === 'active' ? 'Your account access remains active.' : 'Your account remains deactivated until an administrator activates it.';
                $subject = 'Account Unarchived — Dasmariñas City Veterinary Services';
                $htmlBody = dcvsEmailTemplate('Account Unarchived', '<p style="margin:0 0 16px;">Hello <strong>' . htmlspecialchars($u['full_name']) . '</strong>,</p><p style="margin:0 0 16px;">Your account has been removed from the administrator’s archive. ' . $accessText . '</p>');
                sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            }
            $response = ['success' => true];
        } else {
            $response['message'] = 'Failed to restore user.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $lifecycle = $beginLifecycleTransaction([$userId], 'delete');
        if (!empty($lifecycle['error'])) { $response['message'] = $lifecycle['error']; echo json_encode($response); exit; }
        $u = $lifecycle['users'][$userId];

        $subject = 'Account Deleted — Dasmariñas City Veterinary Services';
        $htmlBody = dcvsEmailTemplate('Account Deleted', '<p style="margin:0 0 16px;">Hello <strong>' . htmlspecialchars($u['full_name']) . '</strong>,</p><p style="margin:0 0 16px;">Your DCVS account has been deactivated and marked as deleted by an administrator. Your records remain in the system.</p><p style="margin:0 0 16px;">If you believe this was a mistake, please contact the administrator.</p>');
        try {
            $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE user_id = ? AND deleted_at IS NULL");
            $stmt->bind_param("i", $userId); $stmt->execute(); $stmt->close();
            $tokenStmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
            $tokenStmt->bind_param("i", $userId); $tokenStmt->execute(); $tokenStmt->close();
            $conn->commit();
            if ($u['role'] === 'resident') {
                sendDcvsEmail($u['email'], $subject, $htmlBody, $conn, $u['full_name'], $adminId, 'resident', null, null, 'account_status');
            }
            $detail = "Soft-deleted user '{$u['full_name']}' (ID: $userId)";
            $logAction($adminId, 'User Deleted', $detail);
            $_SESSION['flash_msg'] = "User '{$u['full_name']}' deleted. Their records were retained.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Failed to delete user: ' . $e->getMessage();
        }
        echo json_encode($response); exit;
    }

    if ($action === 'bulk_toggle_status') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['user_ids'] ?? '')), function($id) { return $id > 0; })));
        $newStatus = $_POST['new_status'] ?? 'active';
        if (empty($userIds) || !in_array($newStatus, ['active', 'inactive'])) {
            $response['message'] = 'Invalid parameters.';
            echo json_encode($response); exit;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $lifecycle = $beginLifecycleTransaction($userIds, $newStatus === 'inactive' ? 'deactivate' : 'none');
        if (!empty($lifecycle['error'])) { $response['message'] = $lifecycle['error']; echo json_encode($response); exit; }
        $userIds = $lifecycle['ids'];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $paramTypes = 's' . str_repeat('i', count($userIds));
        $paramVals = array_merge([$newStatus], $userIds);
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->bind_param($paramTypes, ...$paramVals);
        if ($stmt->execute()) {
            $conn->commit();
            $count = $stmt->affected_rows;
            $actionLabel = $newStatus === 'active' ? 'Activated' : 'Deactivated';
            $logAction($adminId, "Bulk $actionLabel", "$actionLabel $count user(s)");
            $_SESSION['flash_msg'] = "$actionLabel $count user(s) successfully.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } else {
            $conn->rollback();
            $response['message'] = 'Failed to update users.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'bulk_archive') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['user_ids'] ?? '')), function($id) { return $id > 0; })));
        if (empty($userIds)) {
            $response['message'] = 'No users selected.';
            echo json_encode($response); exit;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $stmt = $conn->prepare("UPDATE users SET archived_at = NOW() WHERE user_id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->bind_param($types, ...$userIds);
        if ($stmt->execute()) {
            $count = $stmt->affected_rows;
            $logAction($adminId, "Bulk Archive", "Archived $count user(s)");
            $_SESSION['flash_msg'] = "Archived $count user(s) successfully.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } else {
            $response['message'] = 'Failed to archive users.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'bulk_edit') {
        $userIds = array_map('intval', explode(',', $_POST['user_ids'] ?? ''));
        if (empty($userIds)) {
            $response['message'] = 'No users selected.';
            echo json_encode($response); exit;
        }
        $role = $_POST['role'] ?? '';
        $barangayId = !empty($_POST['barangay_id']) ? intval($_POST['barangay_id']) : null;
        $validRoles = ['resident','barangay','staff','admin'];
        if ($role && !in_array($role, $validRoles)) {
            $response['message'] = 'Invalid role.';
            echo json_encode($response); exit;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sets = [];
        $setTypes = '';
        $setVals = [];
        if ($role) { $sets[] = 'role = ?'; $setTypes .= 's'; $setVals[] = $role; }
        if ($barangayId) { $sets[] = 'barangay_id = ?'; $setTypes .= 'i'; $setVals[] = $barangayId; }
        if (empty($sets)) {
            $response['message'] = 'No changes specified.';
            echo json_encode($response); exit;
        }
        $lifecycle = $beginLifecycleTransaction($userIds, 'none');
        if (!empty($lifecycle['error'])) { $response['message'] = $lifecycle['error']; echo json_encode($response); exit; }
        $userIds = $lifecycle['ids'];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE user_id IN ($placeholders) AND deleted_at IS NULL";
        $allVals = array_merge($setVals, $userIds);
        $allTypes = $setTypes . str_repeat('i', count($userIds));
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($allTypes, ...$allVals);
        if ($stmt->execute()) {
            $conn->commit();
            $count = $stmt->affected_rows;
            $changed = [];
            if ($role) $changed[] = "role → $role";
            if ($barangayId) $changed[] = "barangay → #$barangayId";
            $logAction($adminId, "Bulk Edit", "Updated " . implode(', ', $changed) . " for $count user(s)");
            $_SESSION['flash_msg'] = "Updated $count user(s) successfully.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } else {
            $conn->rollback();
            $response['message'] = 'Failed to update users.';
        }
        $stmt->close();
        echo json_encode($response); exit;
    }

    if ($action === 'bulk_delete') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['user_ids'] ?? '')), function($id) { return $id > 0; })));
        if (empty($userIds)) {
            $response['message'] = 'No users selected.';
            echo json_encode($response); exit;
        }
        $lifecycle = $beginLifecycleTransaction($userIds, 'delete');
        if (!empty($lifecycle['error'])) { $response['message'] = $lifecycle['error']; echo json_encode($response); exit; }
        $userIds = $lifecycle['ids'];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        try {
            $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE user_id IN ($placeholders) AND deleted_at IS NULL");
            $stmt->bind_param($types, ...$userIds);
            $stmt->execute();
            $stmt->close();
            $tokenStmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id IN ($placeholders)");
            $tokenStmt->bind_param($types, ...$userIds);
            $tokenStmt->execute();
            $tokenStmt->close();
            $conn->commit();
            $count = count($userIds);
            $logAction($adminId, "Bulk Delete", "Soft-deleted $count user(s); retained linked records");
            $_SESSION['flash_msg'] = "Deleted $count user(s). Their records were retained.";
            $_SESSION['flash_type'] = 'success';
            $response = ['success' => true];
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Failed to delete users: ' . $e->getMessage();
        }
        echo json_encode($response); exit;
    }

    echo json_encode($response);
    exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ── Fetch one page of users for initial display ───────────────────
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$barangayFilter = isset($_GET['barangay']) ? intval($_GET['barangay']) : 0;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$where = "WHERE u.deleted_at IS NULL";
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
if ($barangayFilter) {
    $where .= " AND u.barangay_id = ?";
    $params[] = $barangayFilter;
    $types .= "i";
}

$countQuery = $conn->prepare("SELECT COUNT(*) FROM users u $where");
if ($types) $countQuery->bind_param($types, ...$params);
$countQuery->execute();
$totalUsers = (int)$countQuery->get_result()->fetch_row()[0];
$countQuery->close();

$totalPages = max(1, (int)ceil($totalUsers / $perPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $perPage;
$sql = "SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.barangay_id, u.phone, u.address, u.status, u.reference_id, u.created_at, u.avatar_url, u.archived_at, b.barangay_name
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        $where
        ORDER BY u.created_at DESC
        LIMIT ?, ?";
$q = $conn->prepare($sql);
$queryParams = $params;
$queryParams[] = $offset;
$queryParams[] = $perPage;
$queryTypes = $types . 'ii';
$q->bind_param($queryTypes, ...$queryParams);
$q->execute();
$users = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$barangays = [];
$br = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($br) $barangays = $br->fetch_all(MYSQLI_ASSOC);

$archivedCount = $conn->query("SELECT COUNT(*) FROM users WHERE archived_at IS NOT NULL AND deleted_at IS NULL")->fetch_row()[0];
$pageTitle = 'Role Management';
$activePage = 'users';
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
body { background:#ffffff; }
.main-content { box-sizing:border-box; min-width:0; padding:1.75rem 2rem; }

/* ── Toolbar ── */
.appt-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1rem;}
.appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.appt-search-wrap{display:flex;align-items:center;gap:8px;}
.appt-search-input{box-sizing:border-box;height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
.appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.appt-search-input::placeholder{color:#a1a1aa;}
.appt-status-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;}
.appt-status-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

/* ── Add User button (reuse pet-action-btn pattern) ── */
.pet-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 14px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#71717a;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;text-decoration:none;}
.pet-action-btn:hover{background:#f4f4f5;color:#18181b;border-color:#d4d4d8;}
.pet-action-btn.primary-btn{background:#18181b;color:#fff;border-color:#18181b;}
.pet-action-btn.primary-btn:hover{background:#27272a;}

/* ── Table ── */
.appt-db-table-area{box-sizing:border-box;width:100%;min-width:0;background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;}
.user-table-scroll{width:100%;max-width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;}
.appt-table{width:100%;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
.appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
.appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
.appt-table tbody tr:last-child{border-bottom:none;}
.appt-table tbody tr:hover{background:#f9fafb;}
.appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
.user-reference{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;}
.client-name.user-full-name{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;}
.appt-table tbody td.sd-td-action{text-align:right;}

/* ── Sort buttons ── */
.sd-th{position:relative;}
.th-sort{display:inline-flex;align-items:center;gap:6px;height:28px;margin-left:-12px;padding:0 12px;border-radius:6px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .1s,color .1s;}
.th-sort:hover{background:#e4e4e7;color:#18181b;}
.th-sort.th-sorted{color:#18181b;}
.th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
.th-sorted .th-sort-icon{color:#18181b;}

/* ── Client cell (name + ref) ── */
.client-cell{display:flex;flex-direction:column;gap:2px;}
.client-name{font-weight:600;color:#18181b;}
.contact-main{font-size:14px;font-weight:400;color:#18181b;}

/* ── Status pill ── */
.appt-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}

/* ── Bulk selection ── */
.bulk-bar{display:none;align-items:center;gap:12px;margin-bottom:4px;font-family:'Inter','Segoe UI',sans-serif;}
.bulk-bar.active{display:flex;}
.bulk-bar .bulk-count{font-size:14px;color:#71717a;white-space:nowrap;margin-right:4px;}
.bulk-bar .bulk-actions{display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;}
.bulk-bar .bulk-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:30px;padding:0 12px;border-radius:5px;border:none;background:transparent;color:#52525b;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;}
.bulk-bar .bulk-btn:hover{background:#f4f4f5;color:#18181b;}
.bulk-bar .bulk-btn.danger{color:#dc2626;}
.bulk-bar .bulk-btn.danger:hover{background:#fef2f2;}
.bulk-bar .bulk-btn svg{width:14px;height:14px;flex-shrink:0;}
.bulk-bar .bulk-btn:hover{background:#f4f4f5;color:#18181b;border-color:#d4d4d8;}
.bulk-bar .bulk-btn.danger{color:#dc2626;border-color:#fecaca;}
.bulk-bar .bulk-btn.danger:hover{background:#fef2f2;color:#b91c1c;}
.sd-table-cb{width:16px;height:16px;accent-color:#18181b;cursor:pointer;}

/* ── Role tag (category) ── */
.pet-cat-tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}
.tag-admin{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.tag-staff{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
.tag-resident{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.tag-barangay{background:#faf5ff;color:#6b21a8;border-color:#e9d5ff;}

/* ── Pagination ── */
.appt-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;padding:12px 8px;border-top:1px solid #e4e4e7;}
.dt-pagination-right{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
.dt-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}
.dt-rows select{height:32px;width:70px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;padding:0 8px;font-size:14px;color:#18181b;cursor:pointer;font-family:inherit;}
.dt-rows select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
.dt-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
.dt-page-btns{display:flex;align-items:center;gap:4px;}
.dt-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.dt-page-btn:hover:not(:disabled){background:#f4f4f5;}
.dt-page-btn:disabled{opacity:.5;cursor:not-allowed;}
.dt-page-btn[aria-disabled="true"]{opacity:.5;cursor:not-allowed;pointer-events:none;}

/* ── Reused sd-dialog (shadcn) style ── */
.sd-dialog{width:100%;display:flex;justify-content:center;}
.sd-dialog-content{position:relative;width:100%;max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);animation:modalIn 0.2s ease;max-height:90vh;overflow-y:auto;}
.sd-dialog-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:all 0.15s ease;}
.sd-dialog-close:hover{background:#f4f4f5;color:#18181b;}
.sd-dialog-header{display:flex;flex-direction:column;gap:6px;text-align:left;}
.sd-dialog-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;}
.sd-dialog-description{margin:0;font-size:14px;color:#71717a;line-height:1.5;}
.sd-dialog-footer{display:flex;justify-content:flex-end;align-items:center;gap:8px;}
.alert-dialog-content{max-width:512px;gap:16px;padding:24px;}
.alert-dialog-content .sd-dialog-header{gap:8px;}
.alert-dialog-content .sd-dialog-title{font-size:18px;line-height:1.4;letter-spacing:normal;}
.alert-dialog-content .sd-dialog-description{font-size:14px;line-height:1.5;}
.alert-dialog-content .sd-dialog-footer{padding-top:8px;}
.alert-dialog-content .sd-btn{height:36px;padding:0 16px;border-radius:6px;font-size:14px;font-weight:500;}
.alert-dialog-content .sd-btn-danger{background:#dc2626;border-color:#dc2626;color:#fff;}
.alert-dialog-content .sd-btn-danger:hover{background:#b91c1c;border-color:#b91c1c;}
.sd-field-group{display:flex;flex-direction:column;gap:16px;}
.sd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.sd-field-full{grid-column:1 / -1;}
.sd-field{display:flex;flex-direction:column;gap:5px;}
.sd-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1;color:#18181b;}
.sd-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;width:100%;}
.sd-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.sd-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;width:100%;}
.sd-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
.sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 16px;border-radius:6px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;border:1px solid transparent;text-decoration:none;}
.sd-btn-primary{background:#18181b;color:#fff;border-color:#18181b;}
.sd-btn-primary:hover{background:#27272a;}
.sd-btn-secondary{background:#fff;color:#18181b;border-color:#e4e4e7;}
.sd-btn-secondary:hover{background:#f4f4f5;}
.sd-btn-danger{background:#dc2626;color:#fff;border-color:#dc2626;}
.sd-btn-danger:hover{background:#b91c1c;}
.sd-warning-box{padding:10px 12px;border-radius:8px;border-left:3px solid #f59e0b;background:#fffbeb;font-size:13px;color:#92400e;display:flex;align-items:flex-start;gap:8px;line-height:1.5;}
.sd-warning-box i{margin-top:2px;flex-shrink:0;}
@keyframes modalIn{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}

/* ── Action dropdown ── */
.action-dropdown{position:static;display:inline-block;}
.action-trigger{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#94a3b8;cursor:pointer;transition:all .15s;}
.action-trigger:hover{border-color:#cbd5e1;color:#475569;background:#f8fafc;}
.action-menu{position:fixed;z-index:9999;min-width:210px;background:#fff;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.18);padding:6px;display:none;}
.action-menu.open{display:block;}
.action-menu-item{display:flex;align-items:center;gap:10px;width:100%;padding:8px 12px;border:none;border-radius:6px;background:none;font-size:14px;font-weight:500;color:#475569;cursor:pointer;transition:all .1s;text-align:left;text-decoration:none;font-family:'Inter','Segoe UI',sans-serif;}
.action-menu-item:hover{background:#f8fafc;color:#0f172a;}
.action-menu-item i{width:16px;text-align:center;font-size:14px;color:#94a3b8;}
.action-menu-item.danger{color:#dc2626;}
.action-menu-item.danger:hover{background:#fef2f2;}
.action-menu-item.danger i{color:#dc2626;}
.action-menu-item.warning{color:#d97706;}
.action-menu-item.warning:hover{background:#fffbeb;}
.action-menu-item.warning i{color:#d97706;}
.action-menu-item.success{color:#059669;}
.action-menu-item.success:hover{background:#f0fdf4;}
.action-menu-item.success i{color:#059669;}
.menu-divider{height:1px;background:#f1f5f9;margin:4px 8px;}

/* ── Compact User Modal ── */
#userModal .modal-body{padding:24px 28px 12px 28px;}
#userModal .form-group{margin-bottom:12px;}
#userModal .form-row{gap:14px;}
#userModal .form-group label{font-size:0.8rem;margin-bottom:4px;}
#userModal .form-control{padding:8px 12px;font-size:0.82rem;}
#userModal .modal-actions{padding:14px 28px 24px 28px;}
#userModal textarea{min-height:54px;}

.flash-message{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;font-size:0.85rem;font-weight:500;margin-bottom:1rem;}
.flash-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46;}
.flash-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}

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
@keyframes toastIn{from{transform:translateX(100%);opacity:0;}to{transform:translateX(0);opacity:1;}}

.role-warning{margin-top:8px;padding:10px 12px;border-radius:8px;border-left:3px solid #f59e0b;background:#fffbeb;font-size:0.82rem;color:#92400e;}
.role-warning i{margin-right:6px;}

.confirm-icon{text-align:center;margin-bottom:14px;}
.confirm-icon i{font-size:2.5rem;}
.confirm-text{text-align:center;font-size:0.9rem;color:#64748b;line-height:1.5;}
.confirm-text strong{color:#0f172a;}
.text-muted{color:#94a3b8;font-size:0.82rem;}
.text-xs{font-size:0.72rem;color:#94a3b8;}
.sd-field-group{display:flex;flex-direction:column;gap:16px;}
.sd-section{margin-bottom:16px;}
.sd-section:last-child{margin-bottom:0;}
.sd-section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#18181b;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f4f4f5;}
.sd-section-title span{color:#71717a;font-size:12px;font-weight:600;}
.sd-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;}
.sd-info-item .sd-info-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#71717a;}
.sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;margin-top:2px;word-break:break-word;}
.sd-avatar-lg{width:72px;height:72px;border-radius:50%;overflow:hidden;background:#e4e4e7;color:#71717a;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600;flex-shrink:0;}
.sd-avatar-lg img{width:100%;height:100%;object-fit:cover;}
.sd-profile-head{display:flex;align-items:flex-start;gap:14px;padding-bottom:16px;border-bottom:1px solid #e4e4e7;}
.sd-profile-name{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.025em;margin:0;}
.sd-profile-sub{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#71717a;margin:2px 0 0;}
#viewProfileModal,
#viewProfileModal *:not(i):not(svg){font-family:'Inter','Segoe UI',sans-serif !important;}
#viewProfileModal .sd-profile-head,
#viewProfileModal .sd-section-title{border-bottom:0;}
#viewProfileModal .sd-dialog-title{font-family:'Inter','Segoe UI',sans-serif !important;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;}
#viewProfileModal .sd-dialog-description{font-family:'Inter','Segoe UI',sans-serif !important;font-size:14px;font-weight:400;line-height:1.5;letter-spacing:normal;color:#71717a;}
#viewProfileModal .sd-section-title{font-family:'Inter','Segoe UI',sans-serif !important;font-size:14px;font-weight:600;line-height:1.6;letter-spacing:0;color:#18181b;}
#viewProfileModal .sd-section-title span{font-family:'Inter','Segoe UI',sans-serif !important;font-size:12px;font-weight:600;line-height:1.6;letter-spacing:normal;color:#71717a;}
#viewProfileModal .sd-info-item .sd-info-label{font-family:'Inter','Segoe UI',sans-serif !important;font-size:13px;font-weight:600;line-height:1.6;letter-spacing:normal;color:#71717a;}
#viewProfileModal .sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif !important;font-size:15px;font-weight:400;line-height:1.6;letter-spacing:normal;color:#18181b;word-break:break-word;}
#viewProfileModal .sd-profile-name{font-family:'Inter','Segoe UI',sans-serif !important;font-size:18px;font-weight:600;line-height:1.6;letter-spacing:normal;color:#18181b;}
#viewProfileModal .sd-profile-sub{font-family:'Inter',sans-serif !important;font-size:13px;font-weight:500;line-height:1.6;letter-spacing:normal;}
#viewProfileModal #vpStatus .appt-status-pill{font-family:'Inter',sans-serif !important;font-size:13px;font-weight:500;line-height:1.6;letter-spacing:normal;}
#viewProfileModal .resident-profile-dialog{width:min(600px,calc(100vw - 32px));max-width:600px;max-height:90vh;padding:0;gap:0;overflow:hidden;}
#viewProfileModal .resident-profile-dialog .sd-dialog-header{position:relative;flex:0 0 auto;padding:18px 24px;border-bottom:1px solid #e4e4e7;}
#viewProfileModal .resident-profile-dialog .sd-dialog-title{line-height:1.35;}
#viewProfileModal .resident-profile-dialog .sd-dialog-close{top:18px;right:20px;}
#viewProfileModal .resident-profile-content{display:flex;flex:1;min-height:0;overflow:hidden;}
#viewProfileModal .resident-profile-body{width:100%;min-height:0;padding:0 24px;overflow-y:auto;}
#viewProfileModal .resident-profile-summary{display:flex;align-items:center;gap:16px;padding:22px 0;border-bottom:1px solid #e4e4e7;}
#viewProfileModal .resident-profile-avatar{width:72px;height:72px;flex:0 0 auto;overflow:hidden;border-radius:50%;background:#f4f4f5;color:#52525b;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;}
#viewProfileModal .resident-profile-avatar img{width:100%;height:100%;object-fit:cover;}
#viewProfileModal .resident-profile-name{margin:0;color:#18181b;font-size:18px;font-weight:600;line-height:1.6;letter-spacing:normal;}
#viewProfileModal .resident-profile-badges{display:flex;align-items:center;gap:8px;margin-top:4px;}
#viewProfileModal .resident-profile-badge{display:inline-flex;align-items:center;min-height:22px;padding:2px 10px;border:1px solid #e4e4e7;border-radius:4px;background:#f4f4f5;color:#52525b;font-size:13px;font-weight:500;line-height:1.6;}
#viewProfileModal .resident-profile-badge.active{border-color:#078a34;background:#078a34;color:#fff;}
#viewProfileModal .resident-profile-badge.inactive{border-color:#facc58;background:#fff4cc;color:#92400e;}
#viewProfileModal .resident-profile-registered{margin:6px 0 0;color:#71717a;font-size:13px;font-weight:500;line-height:1.6;}
#viewProfileModal .resident-profile-section{padding:18px 0;border-bottom:1px solid #e4e4e7;}
#viewProfileModal .resident-profile-section:last-child{border-bottom:0;}
#viewProfileModal .resident-profile-section-title{margin:0 0 12px;color:#18181b;font-size:14px;font-weight:600;line-height:1.6;letter-spacing:0;}
#viewProfileModal .resident-profile-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:28px;row-gap:16px;}
#viewProfileModal .resident-profile-info-label{color:#71717a;font-size:13px;font-weight:600;line-height:1.6;}
#viewProfileModal .resident-profile-info-value{color:#18181b;font-size:15px;font-weight:400;line-height:1.6;word-break:break-word;}
#viewProfileModal .resident-profile-info-item.full-width{grid-column:1/-1;}
#viewProfileModal .resident-profile-pets{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
#viewProfileModal .resident-profile-pet{display:flex;align-items:center;gap:10px;min-width:0;padding:10px 12px;border:1px solid #e4e4e7;border-radius:0;}
#viewProfileModal .resident-profile-pet-avatar{width:48px;height:48px;flex:0 0 auto;overflow:hidden;border-radius:50%;background:#f4f4f5;color:#52525b;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;}
#viewProfileModal .resident-profile-pet-avatar img{width:100%;height:100%;object-fit:cover;}
#viewProfileModal .resident-profile-pet-content{min-width:0;}
#viewProfileModal .resident-profile-pet-name{overflow:hidden;color:#18181b;font-size:15px;font-weight:400;line-height:1.6;text-overflow:ellipsis;white-space:nowrap;}
#viewProfileModal .resident-profile-pet-details{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px;}
#viewProfileModal .resident-profile-pet-tag{display:inline-flex;max-width:100%;overflow:hidden;padding:2px 7px;border-radius:5px;background:#f4f4f5;color:#71717a;font-size:13px;font-weight:600;line-height:1.6;text-overflow:ellipsis;white-space:nowrap;}
#viewProfileModal .resident-profile-empty{padding:8px 0;color:#71717a;font-size:13px;line-height:1.6;}
#viewProfileModal .resident-profile-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex:0 0 auto;padding:14px 24px;border-top:1px solid #e4e4e7;}
#viewProfileModal .resident-profile-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;}
#viewProfileModal .resident-profile-footer .sd-btn-danger{background:#dc2626;border-color:#dc2626;color:#fff;}
#viewProfileModal .resident-profile-footer .sd-btn-danger:hover{background:#b91c1c;border-color:#b91c1c;}
#viewProfileModal .resident-profile-content[hidden],
#viewProfileModal .resident-profile-footer[hidden],
#viewProfileModal .legacy-profile-content[hidden]{display:none!important;}
#viewProfileModal.modal{position:fixed;inset:0;z-index:1200;display:none;align-items:stretch;justify-content:flex-end;padding:0;background:rgba(0,0,0,.5);opacity:0;visibility:hidden;transition:opacity .25s ease,visibility .25s ease;}
#viewProfileModal.modal.active{opacity:1;visibility:visible;}
#viewProfileModal .sd-dialog{width:100%;height:100%;display:flex;justify-content:flex-end;}
#viewProfileModal .sd-dialog-content,
#viewProfileModal .sd-dialog-content.resident-profile-dialog{box-sizing:border-box;display:flex;flex-direction:column;flex:0 0 auto;width:min(480px,100vw);min-width:0;max-width:480px;height:100%;max-height:none;min-height:0;gap:0;padding:0;overflow:hidden;border:0;border-radius:0;box-shadow:-8px 0 40px rgba(15,23,32,.18);animation:none;transform:translateX(105%);transition:transform .32s cubic-bezier(.32,.72,.25,1);}
#viewProfileModal.active .sd-dialog-content{transform:translateX(0);}
#viewProfileModal .sd-dialog-header{position:relative;flex:0 0 auto;padding:18px 56px 18px 24px;border-bottom:1px solid #e4e4e7;}
#viewProfileModal .sd-dialog-close{z-index:2;top:18px;right:20px;}
#viewProfileModal .resident-profile-section-title,
#viewProfileModal .sd-section-title{margin:6px 0 10px;padding:0;border:0;color:#18181b;font-size:14px;font-weight:600;line-height:1.45;letter-spacing:0;}
#viewProfileModal .sd-dialog-header,
#viewProfileModal .user-profile-tabs,
#viewProfileModal .resident-profile-summary,
#viewProfileModal .resident-profile-section,
#viewProfileModal .sd-profile-head{border-bottom:0;}
#viewProfileModal .user-profile-tab.active{border-bottom-color:transparent;}
#viewProfileModal .sd-dialog-header{border:0!important;}
#viewProfileModal .resident-profile-info-grid,
#viewProfileModal .sd-info-grid{column-gap:18px;row-gap:16px;}
#viewProfileModal .resident-profile-info-label,
#viewProfileModal .sd-info-item .sd-info-label{margin-bottom:2px;color:#71717a;font-size:13px;font-weight:600;line-height:1.45;letter-spacing:0;}
#viewProfileModal .resident-profile-info-value,
#viewProfileModal .sd-info-item .sd-info-value{margin-top:0;color:#18181b;font-size:15px;font-weight:400;line-height:1.5;letter-spacing:0;word-break:break-word;}
#viewProfileModal .user-profile-tabs{display:flex;flex:0 0 auto;justify-content:center;gap:28px;margin:0 24px 14px;padding:12px 0 0;border-bottom:1px solid #e4e4e7;}
#viewProfileModal .user-profile-tab{appearance:none;margin:0 0 -1px;padding:0 0 12px;border:0;border-bottom:2px solid transparent;background:transparent;color:#71717a;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1.4;cursor:pointer;}
#viewProfileModal .user-profile-tab:hover{color:#18181b;}
#viewProfileModal .user-profile-tab.active{border-bottom-color:#0e8131;color:#0e8131;font-weight:600;}
#viewProfileModal .user-profile-tab:focus-visible{outline:2px solid #18181b;outline-offset:3px;border-radius:2px;}
#viewProfileModal .user-profile-tab-content{display:flex;flex:1;flex-direction:column;min-height:0;overflow:hidden;}
#viewProfileModal .user-profile-details-panel{display:flex;flex:1;flex-direction:column;min-height:0;overflow:hidden;}
#viewProfileModal .user-profile-details-panel[hidden],
#viewProfileModal .user-profile-activity-panel[hidden]{display:none!important;}
#viewProfileModal .resident-profile-content{flex:1;min-height:0;}
#viewProfileModal .legacy-profile-content{flex:1;min-height:0;padding:0 24px 20px;overflow-x:hidden;overflow-y:auto;}
#viewProfileModal .user-profile-activity-panel{flex:1;min-height:0;padding:0 24px 20px;overflow-x:hidden;overflow-y:auto;}
#viewProfileModal .user-activity-heading{margin:0 0 14px;color:#18181b;font-size:14px;font-weight:600;}
#viewProfileModal .user-activity-timeline{position:relative;margin:0 0 14px 3px;padding-left:14px;border-left:2px solid #e4e4e7;color:#09090b;}
#viewProfileModal .user-activity-entry{position:relative;padding:0 0 17px;}
#viewProfileModal .user-activity-entry:last-child{padding-bottom:0;}
#viewProfileModal .user-activity-entry:before{position:absolute;top:3px;left:-20px;width:10px;height:10px;border-radius:50%;background:#22c55e;content:"";box-shadow:0 0 0 2px #fff;}
#viewProfileModal .user-activity-entry-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:3px;}
#viewProfileModal .user-activity-entry-title{min-width:0;color:#09090b;font-size:14px;font-weight:600;line-height:1.35;}
#viewProfileModal .user-activity-entry-date{flex:0 0 auto;color:#71717a;font-size:12px;line-height:1.4;text-align:right;}
#viewProfileModal .user-activity-entry-actor{display:inline-block;margin:0 0 6px;padding:1px 7px;border:1px solid #e4e4e7;border-radius:4px;background:#fafafa;color:#71717a;font-size:12px;line-height:1.35;}
#viewProfileModal .user-activity-entry-copy{margin:0 0 8px;color:#27272a;font-size:14px;font-weight:400;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;}
#viewProfileModal .user-activity-empty{margin:0 0 14px;color:#71717a;font-size:14px;}
#viewProfileModal .resident-profile-footer{flex:0 0 auto;}
.appt-table tbody tr{cursor:pointer;}

@media(max-width:768px){
  #roleModal.modal{box-sizing:border-box;align-items:center!important;justify-content:center;padding:16px!important;overflow-y:auto;}
  #roleModal .sd-dialog{box-sizing:border-box;width:100%;max-width:480px;min-height:0;align-items:center;}
  #roleModal .sd-dialog-content{box-sizing:border-box;width:100%;max-width:480px;max-height:calc(100dvh - 32px);padding:20px;overflow-y:auto;border-radius:10px;transform:none;animation:none;}
  #confirmModal.modal{box-sizing:border-box;align-items:center!important;justify-content:center;padding:16px!important;overflow-y:auto;}
  #confirmModal .sd-dialog{box-sizing:border-box;width:100%;max-width:512px;min-height:0;align-items:center;}
  #confirmModal .alert-dialog-content{box-sizing:border-box;width:100%;max-width:512px;max-height:calc(100dvh - 32px);padding:20px;overflow-y:auto;border-radius:10px;}
  #userModal.modal{box-sizing:border-box;align-items:center!important;justify-content:center;padding:16px!important;overflow-y:auto;}
  #userModal .sd-dialog{box-sizing:border-box;width:100%;max-width:480px;height:auto;min-height:0;align-items:center;}
  #userModal .sd-dialog-content{box-sizing:border-box;width:100%;max-width:480px;max-height:calc(100dvh - 32px);min-height:0;padding:20px;overflow-x:hidden;overflow-y:auto;border-radius:10px;transform:none;animation:none;}
  #userModal h2.sd-dialog-title{font-size:17px!important;}
  #userModal .sd-dialog-description{font-size:13px;line-height:1.45;}
  #userModal .sd-label{font-size:12px;}
  #userModal .sd-input,
  #userModal .sd-select{font-size:13px;}
  #userModal .sd-dialog-footer .sd-btn{font-size:13px;}
  #userModal .sd-dialog-header{padding-right:36px;}
  #userModal .sd-form-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
  #userModal .sd-dialog-footer{flex-wrap:wrap;margin-top:16px!important;}
  #userModal .sd-dialog-footer .sd-btn{flex:1 1 auto;}
  .appt-card-header{flex-direction:column;align-items:stretch;}
  .appt-search-input{min-width:none;width:100%;}
  .appt-status-select{width:100%;}
  .appt-pagination{flex-direction:column;align-items:stretch;text-align:center;}
  #viewProfileModal.modal{width:100vw;height:100vh;height:100dvh;overflow:hidden;}
  #viewProfileModal .sd-dialog{box-sizing:border-box;width:100vw;height:100vh;height:100dvh;max-width:none;min-width:100vw;}
  #viewProfileModal .sd-dialog-content,
  #viewProfileModal .sd-dialog-content.resident-profile-dialog{box-sizing:border-box;flex:0 0 100vw;width:100vw;min-width:100vw;max-width:100vw;height:100vh;height:100dvh;max-height:100dvh;padding:16px 14px calc(14px + env(safe-area-inset-bottom));transform:none;transition:none;}
  #viewProfileModal.active .sd-dialog-content,
  #viewProfileModal.active .sd-dialog-content.resident-profile-dialog{transform:none;}
  #viewProfileModal .sd-dialog-header{padding:8px 36px 12px 0;}
  #viewProfileModal .resident-profile-body{padding:0;}
  #viewProfileModal .resident-profile-summary{padding:16px 0;}
  #viewProfileModal .resident-profile-footer{padding:12px 0;}
  #viewProfileModal .resident-profile-pets{grid-template-columns:1fr;}
  #viewProfileModal .resident-profile-info-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:12px;}
  #viewProfileModal .user-profile-tabs{margin-right:0;margin-left:0;}
  #viewProfileModal .user-profile-activity-panel,
  #viewProfileModal .legacy-profile-content{padding-right:0;padding-left:0;}
  .main-content{width:100%;max-width:100%;min-width:0;padding:0.75rem!important;overflow-x:hidden;}
  .appt-card-header{box-sizing:border-box;width:100%;min-width:0;align-items:stretch;gap:10px;margin-bottom:12px;}
  .appt-header-right{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%;min-width:0;gap:8px;}
  .appt-search-wrap{grid-column:1/-1;width:100%;min-width:0;}
  .appt-search-input{width:100%;min-width:0;}
  .appt-status-select{box-sizing:border-box;width:100%;min-width:0;max-width:100%;}
  #clearFiltersBtn{grid-column:1/-1;width:100%;justify-content:center;}
  .appt-card-header > .pet-action-btn{width:100%;min-height:36px;}
  .bulk-bar{width:100%;min-width:0;align-items:stretch;}
  .bulk-bar .bulk-actions{box-sizing:border-box;width:100%;flex-wrap:wrap;justify-content:center;}
  .bulk-bar .bulk-count{width:100%;text-align:center;}
  .user-table-scroll .appt-table{width:100%;min-width:1050px;}
  .appt-pagination{box-sizing:border-box;width:100%;justify-content:center;padding:12px 8px;}
  .appt-pagination .dt-pagination-right{justify-content:center;gap:12px;}
  .appt-pagination .dt-page-label{width:auto;}
}
@media(max-width:360px){
  .appt-header-right{grid-template-columns:minmax(0,1fr);}
  .appt-search-wrap,#clearFiltersBtn{grid-column:1;}
  .appt-pagination .dt-pagination-right{gap:8px;}
  .appt-pagination .dt-rows{flex-basis:100%;justify-content:center;}
}
</style>
<link rel="stylesheet" href="assets/css/notification.css?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/notification.css'); ?>" />
</head>
<body>

<?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<main class="main-content">

  <?php if (isset($_SESSION['flash_msg'])): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){showToast('<?php echo $_SESSION["flash_type"] === "error" ? "Error" : "Success"; ?>',{type:'<?php echo $_SESSION["flash_type"] === "error" ? "error" : "success"; ?>',description:'<?php echo addslashes($_SESSION["flash_msg"]); ?>'});});</script>
  <?php unset($_SESSION['flash_msg']); unset($_SESSION['flash_type']); endif; ?>

  <div class="appt-card-header portal-mobile-toolbar portal-toolbar-has-primary">
    <div class="appt-header-right portal-toolbar-controls">
      <div class="portal-toolbar-search-row">
      <div class="appt-search-wrap">
        <input type="text" id="searchInput" class="appt-search-input" placeholder="Search by name, email, or reference ID..." value="<?php echo htmlspecialchars($search); ?>" />
      </div>
      </div>
      <div class="portal-toolbar-filter-grid">
      <select class="appt-status-select" id="roleFilter">
        <option value="">All Roles</option>
        <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
        <option value="staff" <?php echo $roleFilter === 'staff' ? 'selected' : ''; ?>>DCVS Staff</option>
        <option value="barangay" <?php echo $roleFilter === 'barangay' ? 'selected' : ''; ?>>Barangay Official</option>
        <option value="resident" <?php echo $roleFilter === 'resident' ? 'selected' : ''; ?>>Resident</option>
      </select>
      <select class="appt-status-select" id="statusFilter">
        <option value="">All Status</option>
        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived <?php echo $archivedCount ? "($archivedCount)" : ''; ?></option>
      </select>
      <select class="appt-status-select portal-toolbar-last-filter" id="barangayFilter">
        <option value="">All Barangays</option>
        <?php foreach ($barangays as $b): ?>
          <option value="<?php echo $b['barangay_id']; ?>" <?php echo $barangayFilter == $b['barangay_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['barangay_name']); ?></option>
        <?php endforeach; ?>
      </select>
      </div>
      <div class="portal-toolbar-actions portal-toolbar-single-secondary">
        <button class="pet-action-btn portal-toolbar-secondary" id="clearFiltersBtn" style="display:none;" onclick="clearAllFilters()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters</button>
      </div>
    </div>
    <button class="pet-action-btn primary-btn portal-toolbar-primary" onclick="openAddModal()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add User</button>
  </div>

  <div class="bulk-bar" id="bulkBar">
    <div class="bulk-actions">
      <span class="bulk-count"><span id="bulkCount">0</span> contacts selected</span>
      <button class="bulk-btn" onclick="bulkAction('deactivate')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg> Activate / Deactivate</button>
      <button class="bulk-btn" onclick="bulkAction('archive')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg> Archive</button>
      <button class="bulk-btn danger" onclick="bulkAction('delete')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Delete</button>
    </div>
  </div>

  <div class="appt-db-table-area" id="tableWrap">
    <div class="user-table-scroll">
      <table class="appt-table">
        <thead>
          <tr>
            <th style="width:40px;"><input type="checkbox" class="sd-table-cb" id="selectAll" onclick="toggleSelectAll(this)" /></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="ref" onclick="RM.sortBy('ref')">Reference<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="name" onclick="RM.sortBy('name')">Full Name<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th>Contact</th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="role" onclick="RM.sortBy('role')">Role<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="barangay" onclick="RM.sortBy('barangay')">Barangay<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="RM.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="registered" onclick="RM.sortBy('registered')">Registered<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th style="width:48px;"></th>
          </tr>
        </thead>
        <tbody id="userTableBody">
          <?php if (empty($users)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px 16px;color:#71717a;font-size:14px;">No users found.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u): ?>
              <tr data-userid="<?php echo $u['user_id']; ?>">
                <td><input type="checkbox" class="sd-table-cb row-cb" value="<?php echo $u['user_id']; ?>" onclick="updateBulkBar()" /></td>
                <td><span class="user-reference"><?php echo htmlspecialchars($u['reference_id'] ?? '—'); ?></span></td>
                <td><div class="client-cell" style="flex-direction:row;align-items:center;gap:8px;">
                  <?php if (!empty($u['avatar_url'])): ?>
                    <img src="<?php echo htmlspecialchars($u['avatar_url']); ?>" alt="Avatar" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                  <?php else: ?>
                    <div style="width:32px;height:32px;border-radius:50%;background:#e4e4e7;color:#52525b;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;"><?php echo strtoupper(substr($u['full_name'] ?? 'U', 0, 1)); ?></div>
                  <?php endif; ?>
                  <span class="client-name user-full-name"><?php echo htmlspecialchars($u['full_name']); ?></span>
                </div></td>
                <td>
                  <div class="client-cell">
                    <span class="contact-main"><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></span>
                    <span style="font-size:13px;color:#71717a;"><?php echo htmlspecialchars($u['email']); ?></span>
                  </div>
                </td>
                <td style="font-size:14px;color:#18181b;"><?php echo ucfirst(htmlspecialchars($u['role'])); ?></td>
                <td style="font-size:14px;color:#18181b;"><?php echo htmlspecialchars($u['barangay_name'] ?? '—'); ?></td>
                <td>
                  <?php if ($u['archived_at']): ?>
                    <span class="appt-status-pill" style="background:#f4f4f5;color:#71717a;border-color:#e4e4e7;">Archived</span>
                  <?php else: ?>
                    <span class="appt-status-pill" style="background:<?php echo $u['status']==='active'?'#f0fdf4':'#fef3c7'; ?>;color:<?php echo $u['status']==='active'?'#166534':'#92400e'; ?>;border-color:<?php echo $u['status']==='active'?'#bbf7d0':'#fde68a'; ?>;"><?php echo ucfirst($u['status']); ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-size:14px;color:#18181b;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td class="sd-td-action">
                  <div class="action-dropdown" data-userid="<?php echo $u['user_id']; ?>">
                    <button class="action-trigger" onclick="toggleDropdown(this)" title="Actions">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                    <div class="action-menu">
                      <button class="action-menu-item" onclick="closeDropdowns();openEditModal(<?php echo $u['user_id']; ?>)">
                        <i class="fas fa-edit"></i> Edit
                      </button>
                      <div class="menu-divider"></div>
                      <?php if ($u['archived_at']): ?>
                        <button class="action-menu-item success" onclick="closeDropdowns();confirmAction('restore_user', <?php echo $u['user_id']; ?>, 'Unarchive user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? Their account access status will not change.')">
                          <i class="fas fa-undo"></i> Unarchive
                        </button>
                      <?php else: ?>
                        <button class="action-menu-item <?php echo $u['status'] === 'active' ? 'warning' : 'success'; ?>" onclick="closeDropdowns();confirmAction('toggle_status', <?php echo $u['user_id']; ?>, '<?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? <?php echo $u['status'] === 'active' ? 'They will no longer be able to sign in or submit requests.' : 'They will regain access.'; ?>')">
                          <i class="fas fa-<?php echo $u['status'] === 'active' ? 'ban' : 'check-circle'; ?>"></i>
                          <?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="action-menu-item" onclick="closeDropdowns();confirmAction('archive_user', <?php echo $u['user_id']; ?>, 'Archive user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? This hides the account from the main list without changing access.')">
                          <i class="fas fa-archive"></i> Archive
                        </button>
                      <?php endif; ?>
                      <div class="menu-divider"></div>
                      <button class="action-menu-item danger" onclick="closeDropdowns();confirmAction('delete_user', <?php echo $u['user_id']; ?>, 'Delete user <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>? This disables account access but retains the account and linked records.')">
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
  </div>
  <div id="rolePagination" class="appt-pagination">
    <div class="dt-pagination-right">
      <div class="dt-rows"><span>Rows per page</span><select onchange="window.rolePageSetPerPage(this.value)"><option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10</option><option value="15" <?php echo $perPage === 15 ? 'selected' : ''; ?>>15</option><option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25</option><option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50</option></select></div>
      <div class="dt-page-label">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></div>
      <div class="dt-page-btns">
        <?php
          $pageParams = $_GET;
          $pageParams['per_page'] = $perPage;
          $paginationUrl = static function (int $page) use ($pageParams): string {
              $pageParams['page'] = $page;
              return 'role-management.php?' . http_build_query($pageParams);
          };
        ?>
        <a class="dt-page-btn" href="<?php echo htmlspecialchars($paginationUrl(1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="First page" <?php echo $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></a>
        <a class="dt-page-btn" href="<?php echo htmlspecialchars($paginationUrl(max(1, $currentPage - 1)), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page" <?php echo $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></a>
        <a class="dt-page-btn" href="<?php echo htmlspecialchars($paginationUrl(min($totalPages, $currentPage + 1)), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page" <?php echo $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></a>
        <a class="dt-page-btn" href="<?php echo htmlspecialchars($paginationUrl($totalPages), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Last page" <?php echo $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></a>
      </div>
    </div>
  </div>
</main>

<div class="toast-container" id="toastContainer"></div>

<!-- ══ Add/Edit User Modal ══ -->
<div class="modal" id="userModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('userModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="userModalTitle">Add New User</h2>
        <p class="sd-dialog-description" id="userModalDesc">Fill in the details to create a new user account.</p>
      </div>
      <form id="userForm" action="role-management.php" method="POST" onsubmit="return handleUserFormSubmit()">
        <input type="hidden" name="user_id" id="editUserId" value="" />
        <input type="hidden" name="action" id="userFormAction" value="create_user" />
        <div class="sd-field-group">
          <div class="sd-form-grid">
            <div class="sd-field">
              <label class="sd-label">Full Name</label>
              <input type="text" class="sd-input" name="full_name" id="uFullName" required />
            </div>
            <div class="sd-field">
              <label class="sd-label">Email</label>
              <input type="email" class="sd-input" name="email" id="uEmail" required />
            </div>
          </div>
          <div class="sd-form-grid">
            <div class="sd-field">
              <label class="sd-label">Username</label>
              <input type="text" class="sd-input" name="username" id="uUsername" required />
            </div>
            <div class="sd-field" id="passwordFieldGroup">
              <label class="sd-label">Password</label>
              <input type="password" class="sd-input" name="password" id="uPassword" placeholder="Min. 6 characters" />
              <div style="font-size:12px;color:#71717a;margin-top:2px;">Leave blank to keep current password when editing.</div>
            </div>
          </div>
          <div class="sd-form-grid">
            <div class="sd-field">
              <label class="sd-label">Role</label>
              <select class="sd-select" name="role" id="uRole">
                <option value="resident">Resident</option>
                <option value="barangay">Barangay Official</option>
                <option value="staff">DCVS Staff</option>
                <option value="admin">Admin</option>
              </select>
              <div id="uRoleHint" style="display:none;margin-top:4px;font-size:12px;color:#d97706;">
                <i class="fas fa-lock"></i> Resident accounts cannot change role.
              </div>
            </div>
          </div>
          <div class="sd-field">
            <label class="sd-label">Phone</label>
            <input type="text" class="sd-input" name="phone" id="uPhone" placeholder="e.g. 09123456789" />
          </div>
          <div class="sd-form-grid">
            <div class="sd-field">
              <label class="sd-label">Barangay</label>
              <select class="sd-select" name="barangay_id" id="uBarangay">
                <option value="">None</option>
                <?php foreach ($barangays as $b): ?>
                  <option value="<?php echo $b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sd-field">
              <label class="sd-label">Address</label>
              <input type="text" class="sd-input" name="address" id="uAddress" placeholder="Street, Barangay, City" />
            </div>
          </div>
        </div>
        <div class="sd-dialog-footer" style="margin-top:20px;">
          <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('userModal')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-primary" id="userFormSubmit"><i class="fas fa-save"></i> <span id="userFormBtnText">Create User</span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Change Role Modal ══ -->
<div class="modal" id="roleModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:480px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('roleModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Change User Role</h2>
        <p class="sd-dialog-description">Update the role for <strong id="roleUserName">—</strong>. Current role: <span id="roleCurrentRole">—</span></p>
      </div>
      <div class="sd-field-group">
        <div class="sd-field">
          <label class="sd-label">New Role</label>
          <select class="sd-select" id="roleNewRole">
            <option value="resident">Resident</option>
            <option value="barangay">Barangay Official</option>
            <option value="staff">DCVS Staff</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div id="roleChangeWarning" class="sd-warning-box">
          <i class="fas fa-exclamation-triangle"></i>
          <span>Changing a role updates the user's permissions immediately. They will only access the dashboard corresponding to their new role.</span>
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('roleModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="roleSubmitBtn" onclick="submitRoleChange()"><i class="fas fa-exchange-alt"></i> Change Role</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Confirm Action Modal ══ -->
<div class="modal" id="confirmModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmText">
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="confirmTitle">Confirm action</h2>
        <p class="sd-dialog-description" id="confirmText">Are you sure?</p>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="confirmSubmitBtn" onclick="submitConfirmedAction()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Bulk Edit Modal ══ -->
<div class="modal" id="bulkEditModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:560px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('bulkEditModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Edit <span id="bulkEditCount">0</span> Users</h2>
        <p class="sd-dialog-description">Update information for all selected users.</p>
      </div>
      <div class="sd-field-group">
        <div class="sd-field">
          <label class="sd-label">Role</label>
          <select class="sd-select" id="bulkRole">
            <option value="">Keep existing roles</option>
            <option value="barangay">Barangay Official</option>
            <option value="staff">DCVS Staff</option>
            <option value="admin">Admin</option>
            <option value="resident">Resident</option>
          </select>
        </div>
        <div class="sd-field">
          <label class="sd-label">Barangay</label>
          <select class="sd-select" id="bulkBarangay">
            <option value="">No change</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?php echo $b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="sd-warning-box" style="margin-top:8px;">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Changes will be applied to all <strong id="bulkEditCountWarn">0</strong> selected users.</span>
      </div>
      <div class="sd-dialog-footer" style="margin-top:16px;">
        <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('bulkEditModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="bulkEditSubmitBtn" onclick="submitBulkEdit()"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ View User Profile Modal ══ -->
<div class="modal" id="viewProfileModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('viewProfileModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">User Profile</h2>
        <p class="sd-dialog-description">View user account details and information.</p>
      </div>
      <div class="user-profile-tabs" role="tablist" aria-label="User profile sections">
        <button type="button" class="user-profile-tab active" id="userProfileDetailsTab" role="tab" aria-selected="true" aria-controls="userProfileDetailsPanel" onclick="switchUserProfileTab('details')">Details</button>
        <button type="button" class="user-profile-tab" id="userProfileActivityTab" role="tab" aria-selected="false" aria-controls="userProfileActivityPanel" onclick="switchUserProfileTab('activity')">Activity</button>
      </div>
      <div class="user-profile-tab-content">
      <div class="user-profile-details-panel" id="userProfileDetailsPanel" role="tabpanel" aria-labelledby="userProfileDetailsTab">
      <div class="resident-profile-content" id="residentProfileContent" hidden>
        <div class="resident-profile-body">
          <div>
            <div class="resident-profile-summary">
              <div class="resident-profile-avatar" id="residentProfileAvatar"></div>
              <div>
                <h3 class="resident-profile-name" id="residentProfileName"></h3>
                <div class="resident-profile-badges">
                  <span class="resident-profile-badge">Resident</span>
                  <span class="resident-profile-badge" id="residentProfileStatus"></span>
                </div>
                <p class="resident-profile-registered" id="residentProfileRegistered"></p>
              </div>
            </div>
            <section class="resident-profile-section">
              <h3 class="resident-profile-section-title">Account</h3>
              <div class="resident-profile-info-grid">
                <div class="resident-profile-info-item">
                  <div class="resident-profile-info-label">Reference ID</div>
                  <div class="resident-profile-info-value" id="residentProfileReference">—</div>
                </div>
                <div class="resident-profile-info-item">
                  <div class="resident-profile-info-label">Username</div>
                  <div class="resident-profile-info-value" id="residentProfileUsername">—</div>
                </div>
                <div class="resident-profile-info-item">
                  <div class="resident-profile-info-label">Email</div>
                  <div class="resident-profile-info-value" id="residentProfileEmail">—</div>
                </div>
                <div class="resident-profile-info-item">
                  <div class="resident-profile-info-label">Phone</div>
                  <div class="resident-profile-info-value" id="residentProfilePhone">—</div>
                </div>
              </div>
            </section>
            <section class="resident-profile-section">
              <h3 class="resident-profile-section-title">Location</h3>
              <div class="resident-profile-info-grid">
                <div class="resident-profile-info-item">
                  <div class="resident-profile-info-label">Barangay</div>
                  <div class="resident-profile-info-value" id="residentProfileBarangay">—</div>
                </div>
                <div class="resident-profile-info-item full-width">
                  <div class="resident-profile-info-label">Address</div>
                  <div class="resident-profile-info-value" id="residentProfileAddress">—</div>
                </div>
              </div>
            </section>
            <section class="resident-profile-section">
              <h3 class="resident-profile-section-title">Pets <span id="residentProfilePetCount"></span></h3>
              <div class="resident-profile-pets" id="residentProfilePets"></div>
              <div class="resident-profile-empty" id="residentProfileNoPets" hidden>No registered pets.</div>
            </section>
          </div>
        </div>
      </div>
      <div class="resident-profile-footer" id="residentProfileFooter" hidden>
        <button type="button" class="sd-btn sd-btn-danger" id="residentProfileSuspend" onclick="toggleResidentProfileStatus()">Deactivate user</button>
        <div class="resident-profile-footer-actions">
          <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('viewProfileModal')">Close</button>
          <button type="button" class="sd-btn sd-btn-primary" onclick="editResidentFromProfile()">Edit user</button>
        </div>
      </div>
      <div class="legacy-profile-content" id="legacyProfileContent">
        <div class="sd-field-group">
          <div class="sd-profile-head">
            <div class="sd-avatar-lg" id="vpAvatar"></div>
            <div>
              <div class="sd-profile-name" id="vpName"></div>
              <div class="sd-profile-sub" id="vpRole"></div>
            </div>
          </div>
          <div class="sd-section">
            <div class="sd-section-title"><span>01</span> Account Information</div>
            <div class="sd-info-grid">
              <div class="sd-info-item"><div class="sd-info-label">Reference ID</div><div class="sd-info-value" id="vpRef">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Username</div><div class="sd-info-value" id="vpUsername">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Email</div><div class="sd-info-value" id="vpEmail">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Phone</div><div class="sd-info-value" id="vpPhone">—</div></div>
            </div>
          </div>
          <div class="sd-section">
            <div class="sd-section-title"><span>02</span> Personal Details</div>
            <div class="sd-info-grid">
              <div class="sd-info-item sd-field-full"><div class="sd-info-label">Address</div><div class="sd-info-value" id="vpAddress">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Barangay</div><div class="sd-info-value" id="vpBarangay">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Status</div><div class="sd-info-value" id="vpStatus">—</div></div>
              <div class="sd-info-item"><div class="sd-info-label">Registered</div><div class="sd-info-value" id="vpRegistered">—</div></div>
            </div>
          </div>
          </div>
          </div>
          </div>
          <div class="user-profile-activity-panel" id="userProfileActivityPanel" role="tabpanel" aria-labelledby="userProfileActivityTab" hidden>
            <div id="userProfileActivityContent" aria-live="polite"><p class="user-activity-empty">Select Activity to view this user's reports, appointments, and registered pets.</p></div>
          </div>
          </div>
        </div>
      </div>
  </div>
</div>

<script>
function toggleDropdown(btn) {
  var openMenu = Array.from(document.querySelectorAll('.action-menu.open')).find(function(menu) {
    return menu._actionTrigger === btn;
  });
  closeDropdowns();
  if (openMenu) return;

  var menu = btn.parentElement.querySelector('.action-menu');
  if (!menu) return;
  menu._actionDropdown = btn.parentElement;
  menu._actionTrigger = btn;
  menu._actionNextSibling = menu.nextSibling;
  document.body.appendChild(menu);
  menu.classList.add('open');

  var rect = btn.getBoundingClientRect();
  var menuHeight = menu.getBoundingClientRect().height;
  var top = rect.bottom + 4;
  if (top + menuHeight > window.innerHeight && rect.top - menuHeight - 4 >= 0) {
    top = rect.top - menuHeight - 4;
  }
  menu.style.top = top + 'px';
  menu.style.left = Math.max(8, Math.min(window.innerWidth - 218, rect.right - 210)) + 'px';
}
function closeDropdowns() {
  document.querySelectorAll('.action-menu.open').forEach(function(menu) {
    menu.classList.remove('open');
    menu.style.top = '';
    menu.style.left = '';
    if (menu._actionDropdown) {
      menu._actionDropdown.insertBefore(menu, menu._actionNextSibling);
      delete menu._actionDropdown;
      delete menu._actionTrigger;
      delete menu._actionNextSibling;
    }
  });
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.action-dropdown')) closeDropdowns();
});

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
  requestAnimationFrame(function() {
    requestAnimationFrame(function() { el.classList.add('show'); });
  });

  el.querySelector('.toast-close').addEventListener('click', function() { dismissToast(el); });

  var timer = setTimeout(function() { dismissToast(el); }, duration);
  el._timer = timer;
  el.addEventListener('mouseenter', function() { clearTimeout(el._timer); });
  el.addEventListener('mouseleave', function() {
    el._timer = setTimeout(function() { dismissToast(el); }, 2000);
  });
}

function dismissToast(el) {
  if (el._dismissed) return;
  el._dismissed = true;
  clearTimeout(el._timer);
  el.classList.remove('show');
  el.classList.add('hiding');
  setTimeout(function() { el.remove(); }, 300);
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
  var br = document.getElementById('barangayFilter').value;
  if (s) p.push('search=' + encodeURIComponent(s));
  if (r) p.push('role=' + encodeURIComponent(r));
  if (st) p.push('status=' + encodeURIComponent(st));
  if (br) p.push('barangay=' + encodeURIComponent(br));
  p.push('per_page=' + encodeURIComponent(new URLSearchParams(window.location.search).get('per_page') || '15'));
  window.location.href = 'role-management.php?' + p.join('&');
}

function updateClearBtn() {
  var s = document.getElementById('searchInput').value;
  var r = document.getElementById('roleFilter').value;
  var st = document.getElementById('statusFilter').value;
  var br = document.getElementById('barangayFilter').value;
  var hasFilters = s || r || st || br;
  document.getElementById('clearFiltersBtn').style.display = hasFilters ? 'inline-flex' : 'none';
}

function clearAllFilters() {
  window.location.href = 'role-management.php';
}

window.rolePageSetPerPage = function(value) {
  var params = new URLSearchParams(window.location.search);
  params.set('per_page', value);
  params.set('page', '1');
  window.location.href = 'role-management.php?' + params.toString();
};

// ── Table sort (all columns) ──
var RM = (function() {
  var tbody = document.getElementById('userTableBody');
  if (!tbody) return {};
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var sortCol = '';
  var sortDir = 'asc';

  function getCellVal(row, col) {
    var cells = row.querySelectorAll('td');
    var idx = {ref:1, name:2, email:3, role:4, barangay:5, status:6, registered:7};
    var i = idx[col];
    return i !== undefined && cells[i] ? cells[i].textContent.trim().toLowerCase() : '';
  }

  function doSort(col) {
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = 'asc'; }
    allRows.sort(function(a, b) {
      var va = getCellVal(a, col), vb = getCellVal(b, col);
      var res = va.localeCompare(vb);
      return sortDir === 'asc' ? res : -res;
    });
    document.querySelectorAll('.appt-table thead .th-sort').forEach(function(btn) {
      var c = btn.getAttribute('data-col');
      btn.classList.toggle('th-sorted', c === sortCol);
      var icon = btn.querySelector('.th-sort-icon');
      if (icon) {
        if (c === sortCol) {
          icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
        } else {
          icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
        }
      }
    });
    tbody.innerHTML = '';
    allRows.forEach(function(r) { tbody.appendChild(r); });
  }

  return { sortBy: doSort };
})();

// ── Bulk selection ──
function toggleSelectAll(cb) {
  var boxes = document.querySelectorAll('.row-cb');
  boxes.forEach(function(b) { b.checked = cb.checked; });
  updateBulkBar();
}

function updateBulkBar() {
  var checked = document.querySelectorAll('.row-cb:checked');
  var count = checked.length;
  document.getElementById('bulkCount').textContent = count;
  document.getElementById('bulkBar').classList.toggle('active', count > 0);
  var selectAll = document.getElementById('selectAll');
  var allBoxes = document.querySelectorAll('.row-cb');
  selectAll.checked = allBoxes.length > 0 && count === allBoxes.length;
  selectAll.indeterminate = count > 0 && count < allBoxes.length;
}

function getSelectedUserIds() {
  var ids = [];
  document.querySelectorAll('.row-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
  return ids;
}

function bulkAction(action) {
  var ids = getSelectedUserIds();
  if (!ids.length) return;
  if (action === 'edit') {
    if (ids.length === 1) { closeDropdowns(); openEditModal(ids[0]); }
    else { openBulkEditModal(ids); }
  } else if (action === 'change_role') {
    if (ids.length !== 1) { showToast('Invalid selection', { type: 'error', description: 'Select exactly 1 user to change role.' }); return; }
    var row = document.querySelector('.row-cb[value="' + ids[0] + '"]').closest('tr');
    var roleTag = row.querySelector('.pet-cat-tag');
    var currentRole = roleTag ? roleTag.textContent.trim().toLowerCase() : '';
    closeDropdowns(); openRoleModal(ids[0], currentRole);
  } else if (action === 'deactivate') {
    var nextStatus = getBulkActionType();
    var statusAction = nextStatus === 'inactive' ? 'Deactivate' : 'Activate';
    confirmAction('bulk_toggle_status', 0, statusAction + ' <strong>' + ids.length + ' user(s)</strong>?');
  } else if (action === 'archive') {
    confirmAction('bulk_archive', 0, 'Archive <strong>' + ids.length + ' user(s)</strong>? This only hides them from the main list and does not change account access.');
  } else if (action === 'delete') {
    confirmAction('bulk_delete', 0, 'Delete <strong>' + ids.length + ' user(s)</strong>? Access will be disabled, while accounts and linked records are retained.');
  }
}

function openBulkEditModal(ids) {
  document.getElementById('bulkEditCount').textContent = ids.length;
  document.getElementById('bulkEditCountWarn').textContent = ids.length;
  document.getElementById('bulkRole').value = '';
  document.getElementById('bulkBarangay').value = '';
  openModal('bulkEditModal');
}

function submitBulkEdit() {
  var ids = getSelectedUserIds();
  if (!ids.length) return;
  var role = document.getElementById('bulkRole').value;
  var barangay = document.getElementById('bulkBarangay').value;
  if (!role && !barangay) { showToast('No changes', { type: 'error', description: 'Please select at least one field to update.' }); return; }
  var btn = document.getElementById('bulkEditSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  var data = { action: 'bulk_edit', user_ids: ids.join(','), role: role, barangay_id: barangay };
  apiPost(data, function(resp) {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
    if (resp.success) {
      closeModal('bulkEditModal');
      var p = [];
      var s = document.getElementById('searchInput').value;
      var r = document.getElementById('roleFilter').value;
      var st = document.getElementById('statusFilter').value;
      var br = document.getElementById('barangayFilter').value;
      if (s) p.push('search=' + encodeURIComponent(s));
      if (r) p.push('role=' + encodeURIComponent(r));
      if (st) p.push('status=' + encodeURIComponent(st));
      if (br) p.push('barangay=' + encodeURIComponent(br));
      window.location.href = 'role-management.php?' + p.join('&');
    } else { showToast('Update failed', { type: 'error', description: resp.message || 'Could not update user.' }); }
  });
}

function getBulkActionType() {
  var allActive = true;
  document.querySelectorAll('.row-cb:checked').forEach(function(cb) {
    var row = cb.closest('tr');
    var statusCell = row.querySelectorAll('td')[6];
    if (statusCell && !statusCell.textContent.trim().match(/^Active$/i)) allActive = false;
  });
  return allActive ? 'inactive' : 'active';
}

var searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimer);
  updateClearBtn();
  searchTimer = setTimeout(reloadTable, 400);
});
document.getElementById('roleFilter').addEventListener('change', reloadTable);
document.getElementById('statusFilter').addEventListener('change', reloadTable);
document.getElementById('barangayFilter').addEventListener('change', reloadTable);

updateClearBtn();

function handleUserFormSubmit() {
  var roleSel = document.getElementById('uRole');
  if (roleSel.disabled) roleSel.disabled = false;
  return true;
}

function openAddModal() {
  document.getElementById('userModalTitle').textContent = 'Add New User';
  document.getElementById('userModalDesc').textContent = 'Fill in the details to create a new user account.';
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
  document.getElementById('uPhone').value = '';
  document.getElementById('uBarangay').value = '';
  document.getElementById('uAddress').value = '';
  openModal('userModal');
}

function openEditModal(userId) {
  document.getElementById('userModalTitle').textContent = 'Edit User';
  document.getElementById('userModalDesc').textContent = 'Update the user account information below.';
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
      showToast('Load failed', { type: 'error', description: resp.message || 'Failed to fetch user data.' });
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
    if (resp.success) {
      closeModal('roleModal');
      var p = [];
      var s = document.getElementById('searchInput').value;
      var r = document.getElementById('roleFilter').value;
      var st = document.getElementById('statusFilter').value;
      var br = document.getElementById('barangayFilter').value;
      if (s) p.push('search=' + encodeURIComponent(s));
      if (r) p.push('role=' + encodeURIComponent(r));
      if (st) p.push('status=' + encodeURIComponent(st));
      if (br) p.push('barangay=' + encodeURIComponent(br));
      window.location.href = 'role-management.php?' + p.join('&');
    } else { showToast('Change failed', { type: 'error', description: resp.message || 'Could not change role.' }); }
  });
}

var pendingAction = null;
var pendingUserId = 0;

function confirmAction(action, userId, message) {
  pendingAction = action;
  pendingUserId = userId;
  document.getElementById('confirmText').innerHTML = message;
  var titleByAction = {
    delete_user: 'Delete this account?',
    bulk_delete: 'Delete selected accounts?',
    toggle_status: 'Change account access?',
    bulk_toggle_status: 'Change account access?',
    archive_user: 'Archive this account?',
    bulk_archive: 'Archive selected accounts?',
    restore_user: 'Unarchive this account?'
  };
  document.getElementById('confirmTitle').textContent = titleByAction[action] || 'Confirm action';
  var btn = document.getElementById('confirmSubmitBtn');
  btn.textContent = 'Confirm';
  btn.className = 'sd-btn sd-btn-primary';
  var isDeactivating = action === 'toggle_status' && message.indexOf('Deactivate') >= 0;
  var isBulkDeactivating = action === 'bulk_toggle_status' && getBulkActionType() === 'inactive';
  if (action === 'delete_user' || action === 'bulk_delete') {
    btn.className = 'sd-btn sd-btn-danger';
    btn.textContent = action === 'bulk_delete' ? 'Delete selected accounts' : 'Delete account';
  }
  if (action === 'toggle_status') btn.textContent = isDeactivating ? 'Deactivate account' : 'Activate account';
  if (action === 'bulk_toggle_status') btn.textContent = isBulkDeactivating ? 'Deactivate selected' : 'Activate selected';
  if (action === 'archive_user') btn.textContent = 'Archive account';
  if (action === 'bulk_archive') btn.textContent = 'Archive selected';
  if (action === 'restore_user') btn.textContent = 'Unarchive account';
  if (isDeactivating || isBulkDeactivating) btn.className = 'sd-btn sd-btn-danger';
  openModal('confirmModal');
}

function submitConfirmedAction() {
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.textContent = 'Processing...';
  var data = {action: pendingAction, user_id: pendingUserId};
  if (pendingAction === 'bulk_delete' || pendingAction === 'bulk_toggle_status' || pendingAction === 'bulk_archive') {
    data.user_ids = getSelectedUserIds().join(',');
    if (pendingAction === 'bulk_toggle_status') {
      data.new_status = getBulkActionType();
    }
  }
  apiPost(data, function(resp) {
    btn.disabled = false;
    closeModal('confirmModal');
    if (resp.success) {
      var p = [];
      var s = document.getElementById('searchInput').value;
      var r = document.getElementById('roleFilter').value;
      var st = document.getElementById('statusFilter').value;
      var br = document.getElementById('barangayFilter').value;
      if (s) p.push('search=' + encodeURIComponent(s));
      if (r) p.push('role=' + encodeURIComponent(r));
      if (st) p.push('status=' + encodeURIComponent(st));
      if (br) p.push('barangay=' + encodeURIComponent(br));
      window.location.href = 'role-management.php?' + p.join('&');
    } else {
      showToast('Action failed', { type: 'error', description: resp.message || 'Could not complete action.' });
    }
  });
}

var residentProfileUser = null;
var currentProfileUser = null;
var userActivityLoadedFor = null;
var userActivityLoadingFor = null;

function switchUserProfileTab(tab) {
  var isActivity = tab === 'activity';
  document.getElementById('userProfileDetailsTab').classList.toggle('active', !isActivity);
  document.getElementById('userProfileDetailsTab').setAttribute('aria-selected', isActivity ? 'false' : 'true');
  document.getElementById('userProfileActivityTab').classList.toggle('active', isActivity);
  document.getElementById('userProfileActivityTab').setAttribute('aria-selected', isActivity ? 'true' : 'false');
  document.getElementById('userProfileDetailsPanel').hidden = isActivity;
  document.getElementById('userProfileActivityPanel').hidden = !isActivity;
  if (isActivity) loadUserProfileActivity();
}

function formatUserActivityDate(value) {
  if (!value) return '';
  var date = new Date(String(value).replace(' ', 'T'));
  if (isNaN(date.getTime())) return String(value);
  return date.toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'});
}

function renderUserProfileActivity(activities) {
  var content = document.getElementById('userProfileActivityContent');
  content.textContent = '';
  if (!activities.length) {
    var empty = document.createElement('p');
    empty.className = 'user-activity-empty';
    empty.textContent = 'No reports, appointments, or pet registrations have been recorded for this user.';
    content.appendChild(empty);
    return;
  }
  var timeline = document.createElement('div');
  timeline.className = 'user-activity-timeline';
  activities.forEach(function(activity) {
    var entry = document.createElement('article');
    entry.className = 'user-activity-entry';
    var head = document.createElement('div');
    head.className = 'user-activity-entry-head';
    var title = document.createElement('span');
    title.className = 'user-activity-entry-title';
    title.textContent = activity.action || 'Resident history';
    var time = document.createElement('time');
    time.className = 'user-activity-entry-date';
    time.textContent = formatUserActivityDate(activity.created_at);
    head.appendChild(title);
    head.appendChild(time);
    var actor = document.createElement('span');
    actor.className = 'user-activity-entry-actor';
    actor.textContent = 'Resident history';
    entry.appendChild(head);
    entry.appendChild(actor);
    if (activity.details) {
      var details = document.createElement('p');
      details.className = 'user-activity-entry-copy';
      details.textContent = activity.details;
      entry.appendChild(details);
    }
    timeline.appendChild(entry);
  });
  content.appendChild(timeline);
}

function loadUserProfileActivity() {
  if (!currentProfileUser) return;
  var userId = Number(currentProfileUser.user_id);
  if (userActivityLoadedFor === userId || userActivityLoadingFor === userId) return;
  var content = document.getElementById('userProfileActivityContent');
  content.textContent = 'Loading resident history…';
  userActivityLoadingFor = userId;
  apiPost({action:'get_user_activity', user_id:userId}, function(resp) {
    if (userActivityLoadingFor === userId) userActivityLoadingFor = null;
    if (!currentProfileUser || Number(currentProfileUser.user_id) !== userId) return;
    if (resp.success && Array.isArray(resp.activities)) {
      userActivityLoadedFor = userId;
      renderUserProfileActivity(resp.activities);
      return;
    }
    content.textContent = '';
    var error = document.createElement('p');
    error.className = 'user-activity-empty';
    error.textContent = resp.message || 'Could not load resident history. Please try again.';
    content.appendChild(error);
    showToast('Could not load resident history', {type:'error', description:error.textContent});
  });
}

function escapeProfileHtml(value) {
  var el = document.createElement('span');
  el.textContent = value == null ? '' : String(value);
  return el.innerHTML;
}

function setResidentProfileAvatar(container, user) {
  container.textContent = '';
  if (user.avatar_url) {
    var image = document.createElement('img');
    image.src = user.avatar_url;
    image.alt = '';
    container.appendChild(image);
    return;
  }
  var initials = (user.full_name || '').trim().split(/\s+/).map(function(part) {
    return part.charAt(0);
  }).join('').toUpperCase().slice(0, 2) || '?';
  container.textContent = initials;
}

function renderResidentProfilePets(pets) {
  var petList = document.getElementById('residentProfilePets');
  var noPets = document.getElementById('residentProfileNoPets');
  document.getElementById('residentProfilePetCount').textContent = ' (' + pets.length + ')';
  petList.textContent = '';
  noPets.hidden = pets.length > 0;
  if (!pets.length) return;

  pets.forEach(function(pet) {
    var card = document.createElement('article');
    card.className = 'resident-profile-pet';

    var avatar = document.createElement('div');
    avatar.className = 'resident-profile-pet-avatar';
    if (pet.photo_url) {
      var image = document.createElement('img');
      image.src = pet.photo_url;
      image.alt = '';
      image.loading = 'lazy';
      avatar.appendChild(image);
    } else {
      var initials = (pet.pet_name || '').trim().split(/\s+/).map(function(part) {
        return part.charAt(0);
      }).join('').toUpperCase().slice(0, 2) || '?';
      avatar.textContent = initials;
    }

    var content = document.createElement('div');
    content.className = 'resident-profile-pet-content';
    var name = document.createElement('div');
    name.className = 'resident-profile-pet-name';
    name.textContent = pet.pet_name || '—';
    var details = document.createElement('div');
    details.className = 'resident-profile-pet-details';
    [pet.pet_type, pet.breed, pet.gender].filter(Boolean).forEach(function(detail) {
      var tag = document.createElement('span');
      tag.className = 'resident-profile-pet-tag';
      tag.textContent = String(detail).charAt(0).toUpperCase() + String(detail).slice(1);
      details.appendChild(tag);
    });
    content.appendChild(name);
    content.appendChild(details);
    card.appendChild(avatar);
    card.appendChild(content);
    petList.appendChild(card);
  });
}

function toggleResidentProfileStatus() {
  if (!residentProfileUser) return;
  var user = residentProfileUser;
  var active = user.status === 'active';
  closeModal('viewProfileModal');
  confirmAction(
    'toggle_status',
    Number(user.user_id),
    (active ? 'Deactivate' : 'Activate') + ' user <strong>' + escapeProfileHtml(user.full_name) + '</strong>? ' +
      (active ? 'They will no longer be able to sign in or submit requests.' : 'They will regain access.')
  );
}

function editResidentFromProfile() {
  if (!residentProfileUser) return;
  var userId = Number(residentProfileUser.user_id);
  closeModal('viewProfileModal');
  openEditModal(userId);
}

function viewUserProfile(u) {
  currentProfileUser = u;
  userActivityLoadedFor = null;
  userActivityLoadingFor = null;
  document.getElementById('userProfileActivityContent').innerHTML = '<p class="user-activity-empty">Select Activity to view this user\'s reports, appointments, and registered pets.</p>';
  switchUserProfileTab('details');
  residentProfileUser = u.role === 'resident' ? u : null;
  var isResident = Boolean(residentProfileUser);
  var dialog = document.querySelector('#viewProfileModal .sd-dialog-content');
  var residentContent = document.getElementById('residentProfileContent');
  var residentFooter = document.getElementById('residentProfileFooter');
  var legacyContent = document.getElementById('legacyProfileContent');
  dialog.classList.toggle('resident-profile-dialog', isResident);
  residentContent.hidden = !isResident;
  residentFooter.hidden = !isResident;
  legacyContent.hidden = isResident;

  if (isResident) {
    document.getElementById('residentProfileName').textContent = u.full_name || '—';
    document.getElementById('residentProfileUsername').textContent = u.username || '—';
    document.getElementById('residentProfileReference').textContent = u.reference_id || '—';
    document.getElementById('residentProfileEmail').textContent = u.email || '—';
    document.getElementById('residentProfilePhone').textContent = u.phone || '—';
    document.getElementById('residentProfileBarangay').textContent = u.barangay_name || '—';
    document.getElementById('residentProfileAddress').textContent = u.address || '—';
    document.getElementById('residentProfileRegistered').textContent = u.created_at
      ? 'Registered ' + new Date(u.created_at).toLocaleDateString('en-US', {year:'numeric', month:'long', day:'numeric'})
      : '';
    var residentStatus = document.getElementById('residentProfileStatus');
    residentStatus.textContent = u.status ? u.status.charAt(0).toUpperCase() + u.status.slice(1) : '—';
    residentStatus.className = 'resident-profile-badge ' + (u.status === 'active' ? 'active' : (u.status === 'inactive' ? 'inactive' : ''));
    var statusButton = document.getElementById('residentProfileSuspend');
    statusButton.textContent = u.status === 'active' ? 'Deactivate user' : 'Activate user';
    statusButton.className = 'sd-btn ' + (u.status === 'active' ? 'sd-btn-danger' : 'sd-btn-primary');
    setResidentProfileAvatar(document.getElementById('residentProfileAvatar'), u);

    var petList = document.getElementById('residentProfilePets');
    var noPets = document.getElementById('residentProfileNoPets');
    document.getElementById('residentProfilePetCount').textContent = '';
    noPets.textContent = 'No registered pets.';
    petList.textContent = 'Loading pets…';
    noPets.hidden = true;
    apiPost({action:'get_user_pets', user_id:u.user_id}, function(resp) {
      if (!residentProfileUser || Number(residentProfileUser.user_id) !== Number(u.user_id)) return;
      if (resp.success && Array.isArray(resp.pets)) {
        renderResidentProfilePets(resp.pets);
      } else {
        petList.textContent = '';
        noPets.textContent = 'Could not load registered pets.';
        noPets.hidden = false;
        showToast('Could not load pets', {type:'error', description:resp.message || 'Please try opening the profile again.'});
      }
    });
    openModal('viewProfileModal');
    return;
  }

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
  if (u.status === 'active') { statusEl.innerHTML = '<span class="appt-status-pill" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">Active</span>'; }
  else if (u.status === 'inactive') { statusEl.innerHTML = '<span class="appt-status-pill" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">Inactive</span>'; }
  else { statusEl.textContent = u.status || '—'; }
  document.getElementById('vpRegistered').textContent = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : '—';
  var avatarEl = document.getElementById('vpAvatar');
  if (u.avatar_url) { avatarEl.innerHTML = '<img src="'+u.avatar_url+'" alt="Avatar" style="width:72px;height:72px;border-radius:50%;object-fit:cover;">'; }
  else { var initials = (u.full_name||'').split(' ').map(function(s){return s.charAt(0)}).join('').toUpperCase().slice(0,2) || '?'; avatarEl.textContent = initials; }
  openModal('viewProfileModal');
}

document.querySelectorAll('.appt-table tbody tr').forEach(function(row) {
  row.addEventListener('click', function(e) {
    if (e.target.closest('input[type="checkbox"]') || e.target.closest('.action-trigger') || e.target.closest('.action-menu') || e.target.closest('.pet-action-btn')) return;
    var userId = row.getAttribute('data-userid');
    if (!userId) return;
    apiPost({action:'get_user', user_id: userId}, function(resp) {
      if (resp.success && resp.user) { viewUserProfile(resp.user); }
    });
  });
});
</script>

</body>
</html>
<?php $conn->close(); ?>
