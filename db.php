<?php
/**
 * Database Connection File
 * Connects to dcvs_system MySQL database
 */

$host = '127.0.0.1';
$username = 'root';
$password = '';
$database = 'dcvs_system_recovered_20261008';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

/**
 * Create a notification for a user.
 */
function createNotification($conn, $userId, $type, $title, $message = '', $referenceType = 'report', $referenceId = null, $messageType = null) {
    if (!$conn || $conn->connect_error) return;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, message_type, reference_type, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("isssssi", $userId, $type, $title, $message, $messageType, $referenceType, $referenceId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Handle avatar upload for any user role.
 * Validates file, deletes old avatar, stores new one, updates DB and session.
 * Returns new avatar URL on success or false on failure (sets $_SESSION['profile_error']).
 */
function handleAvatarUpload($conn, $userId, &$session) {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        return $session['avatar_url'] ?? '';
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024;

    if (!in_array($_FILES['avatar']['type'], $allowedTypes)) {
        $_SESSION['profile_error'] = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP.';
        return false;
    }
    if ($_FILES['avatar']['size'] > $maxSize) {
        $_SESSION['profile_error'] = 'File size exceeds 2MB limit.';
        return false;
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
        if (!is_dir($uploadDir)) {
            error_log("handleAvatarUpload: failed to create upload directory $uploadDir");
            $_SESSION['profile_error'] = 'Server error: upload directory unavailable.';
            return false;
        }
    }

    $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
    $filename = 'user_' . $userId . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . $filename;
    $webPath = 'uploads/avatars/' . $filename;

    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
        error_log("handleAvatarUpload: move_uploaded_file failed for user $userId — src=" . ($_FILES['avatar']['tmp_name'] ?? '?') . " dst=$uploadPath");
        $_SESSION['profile_error'] = 'Failed to save uploaded file. Please check server permissions.';
        return false;
    }

    // Delete old avatar file if it exists and is different
    $oldPath = $session['avatar_url'] ?? '';
    if (!empty($oldPath) && $oldPath !== $webPath) {
        $oldFull = __DIR__ . '/' . $oldPath;
        if (file_exists($oldFull)) unlink($oldFull);
    }

    // Update database
    $stmt = $conn->prepare("UPDATE users SET avatar_url = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $webPath, $userId);
        $stmt->execute();
        $stmt->close();
    }

    // Update session
    $session['avatar_url'] = $webPath;
    $_SESSION['avatar_url'] = $webPath;

    return $webPath;
}

/**
 * Log a sent email to the email_logs table.
 */
function logEmail($conn, $recipientEmail, $recipientName, $subject, $body, $status = 'sent', $errorMessage = null, $sentBy = null, $sentToRole = null, $messageType = null, $portalDelivered = false, $errorDetail = null) {
    if (!$conn || $conn->connect_error) return;
    $stmt = $conn->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, body, status, error_message, error_detail, sent_by, sent_to_role, message_type, portal_delivered) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("sssssssissi", $recipientEmail, $recipientName, $subject, $body, $status, $errorMessage, $errorDetail, $sentBy, $sentToRole, $messageType, $portalDelivered);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get all active users by role for the staff communication page.
 */
function getUsersByRole($conn, $role) {
    $users = [];
    $stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.email, u.phone, u.reference_id, u.barangay_id, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.role = ? AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.full_name ASC");
    if (!$stmt) return $users;
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $users[] = $row;
    $stmt->close();
    return $users;
}

function setRememberMe($conn, $userId) {
    if (!$conn || $conn->connect_error) return;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $hash, $expires);
    $stmt->execute();
    $stmt->close();
    setcookie('remember_token', $token, time() + 30 * 24 * 60 * 60, '/', '', !empty($_SERVER['HTTPS']), true);
}

function clearRememberMe($conn) {
    if (empty($_COOKIE['remember_token'])) return;
    $hash = hash('sha256', $_COOKIE['remember_token']);
    if ($conn && !$conn->connect_error) {
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
        $stmt->bind_param("s", $hash);
        $stmt->execute();
        $stmt->close();
    }
    setcookie('remember_token', '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']), true);
    unset($_COOKIE['remember_token']);
}

function autoLoginRememberMe($conn) {
    if (!empty($_SESSION['logged_in']) || empty($_COOKIE['remember_token']) || !$conn || $conn->connect_error) {
        return;
    }
    $hash = hash('sha256', $_COOKIE['remember_token']);
    $stmt = $conn->prepare("SELECT user_id, expires_at FROM remember_tokens WHERE token_hash = ?");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $token = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($token && strtotime($token['expires_at']) > time()) {
        $userId = (int)$token['user_id'];
        $userStmt = $conn->prepare("SELECT user_id, username, email, full_name, role, barangay_id, phone, address, status, avatar_url, reference_id, deleted_at FROM users WHERE user_id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if ($user && $user['status'] === 'active' && empty($user['deleted_at'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['barangay_id'] = $user['barangay_id'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['address'] = $user['address'];
            $_SESSION['avatar_url'] = $user['avatar_url'] ?? '';
            $_SESSION['reference_id'] = $user['reference_id'] ?? '';
            $_SESSION['logged_in'] = true;
            session_regenerate_id(true);
            return $user;
        }

        if ($user && !empty($user['deleted_at'])) {
            $_SESSION['login_error'] = 'Your account has been deleted by the administrator. Contact us for clarification.';
        } elseif ($user && $user['status'] !== 'active') {
            $_SESSION['login_error'] = 'Your account has been deactivated. Please contact the administrator.';
        }
        $deleteStmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $deleteStmt->bind_param("i", $userId);
        $deleteStmt->execute();
        $deleteStmt->close();
    } elseif ($token) {
        $deleteStmt = $conn->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
        $deleteStmt->bind_param("s", $hash);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
    clearRememberMe($conn);
}

function dcvsEnforceAuthenticatedAccount($conn) {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        return;
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role, status, deleted_at FROM users WHERE user_id = ?");
    if (!$stmt) {
        error_log('Unable to prepare authenticated account status check: ' . $conn->error);
        http_response_code(503);
        exit('Account status validation is temporarily unavailable.');
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $message = '';
    if (!$user) {
        $message = 'Your session is no longer valid. Please sign in again.';
    } elseif (!empty($user['deleted_at'])) {
        $message = 'Your account has been deleted by the administrator. Contact us for clarification.';
    } elseif ($user['status'] !== 'active') {
        $message = 'Your account has been deactivated. Please contact the administrator.';
    } elseif ($user['role'] !== ($_SESSION['role'] ?? '')) {
        $message = 'Your session is no longer valid. Please sign in again.';
    }

    if ($message === '') {
        return;
    }

    $tokenStmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    if ($tokenStmt) {
        $tokenStmt->bind_param("i", $userId);
        $tokenStmt->execute();
        $tokenStmt->close();
    }
    clearRememberMe($conn);

    foreach (['user_id', 'username', 'email', 'full_name', 'role', 'barangay_id', 'phone', 'address', 'avatar_url', 'reference_id', 'logged_in'] as $key) {
        unset($_SESSION[$key]);
    }
    $_SESSION['login_error'] = $message;
    session_regenerate_id(true);
}

function generateReferenceId($conn, $prefix, $table, $column = 'reference_id') {
    if (!$conn || $conn->connect_error) throw new Exception("Database connection failed");
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $maxAttempts = 50;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $refId = $prefix . $suffix;
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        if (!$stmt) throw new Exception("Database prepare failed");
        $stmt->bind_param("s", $refId);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        
        if ($count === 0) {
            return $refId;
        }
    }
    throw new Exception("Failed to generate unique reference ID after $maxAttempts attempts");
}

function fallbackRefId($prefix, $id = null) {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $suffix = '';
    $n = $id !== null ? $id : random_int(1, 999999);
    $seed = crc32((string)$n . $prefix);
    for ($i = 0; $i < 6; $i++) {
        $seed = abs($seed * 1103515245 + 12345) % 2147483647;
        $suffix .= $chars[$seed % strlen($chars)];
    }
    return $prefix . $suffix;
}

function getReportStatusLabel($status) {
    $labels = [
        'pending' => 'Submitted',
        'validated' => 'Validated',
        'forwarded' => 'Forwarded',
        'assessment' => 'Under Assessment',
        'rejected' => 'Rejected',
        'confirmed' => 'Confirmed',
        'resolved' => 'Resolved',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/**
 * Normalize a submitted report type into the reports.report_type ENUM value.
 * Accepts either the stored key (stray, rabies, injured, dead) or the label
 * chosen on the report form (Stray Animal, Rabies Concern, ...).
 * Returns '' when the value cannot be mapped.
 */
function reportTypeKey($value) {
    $map = [
        'stray'          => 'stray',
        'stray animal'   => 'stray',
        'rabies'         => 'rabies',
        'rabies case'    => 'rabies',
        'rabies concern' => 'rabies',
        'injured'        => 'injured',
        'injured animal' => 'injured',
        'dead'           => 'dead',
        'dead animal'    => 'dead',
    ];
    return $map[strtolower(trim((string)$value))] ?? '';
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function validateCsrf($token) {
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

dcvsEnforceAuthenticatedAccount($conn);
?>
