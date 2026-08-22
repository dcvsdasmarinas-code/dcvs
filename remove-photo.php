<?php
$possibleSessions = ['DCVS_ADMIN_SESSION', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION'];
foreach ($possibleSessions as $sn) {
    if (isset($_COOKIE[$sn])) {
        session_name($sn);
        break;
    }
}
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
    $role = $_SESSION['role'] ?? 'resident';
    if ($role === 'admin') header('Location: login-admin.php');
    elseif ($role === 'staff') header('Location: login-staff.php');
    elseif ($role === 'barangay') header('Location: login-barangay.php');
    else header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_SESSION['avatar_url'])) {
        $avatarPath = __DIR__ . '/' . $_SESSION['avatar_url'];
        if (file_exists($avatarPath)) {
            unlink($avatarPath);
        }
    }

    $stmt = $conn->prepare("UPDATE users SET avatar_url = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);

    if ($stmt->execute()) {
        $_SESSION['avatar_url'] = '';
        $_SESSION['profile_success'] = "Profile picture removed successfully!";
    } else {
        $_SESSION['profile_error'] = "Failed to remove profile picture. Please try again.";
    }

    $stmt->close();

    $role = $_SESSION['role'] ?? 'resident';
    if ($role === 'admin') header('Location: dashboard-admin.php#settings');
    elseif ($role === 'staff') header('Location: dashboard-staff.php#account-settings');
    elseif ($role === 'barangay') header('Location: dashboard-barangay.php#account-settings');
    else header('Location: dashboard-resident.php#account-settings');
    exit;
}

header('Location: index.html');
exit;
