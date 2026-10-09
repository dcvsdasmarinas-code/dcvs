<?php
session_name('DCVS_BARANGAY_SESSION');
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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['profile_error'] = 'All password fields are required.';
        header('Location: dashboard-barangay.php#account-settings');
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        $_SESSION['profile_error'] = 'New passwords do not match.';
        header('Location: dashboard-barangay.php#account-settings');
        exit;
    }

    if (strlen($newPassword) < 8) {
        $_SESSION['profile_error'] = 'New password must be at least 8 characters.';
        header('Location: dashboard-barangay.php#account-settings');
        exit;
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        $_SESSION['profile_error'] = 'Current password is incorrect.';
        header('Location: dashboard-barangay.php#account-settings');
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
    $update->bind_param("si", $hashedPassword, $userId);

    if ($update->execute()) {
        $_SESSION['profile_success'] = 'Password changed successfully!';
    } else {
        $_SESSION['profile_error'] = 'Failed to update password. Please try again.';
    }

    $update->close();
    header('Location: dashboard-barangay.php#account-settings');
    exit;
}

header('Location: dashboard-barangay.php');
exit;
