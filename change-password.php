<?php
/**
 * Change Password Handler
 * Allows users to change their password
 */

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

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Server-side validation
    $errors = [];

    if (empty($currentPassword)) {
        $errors[] = "Current password is required.";
    }
    if (empty($newPassword)) {
        $errors[] = "New password is required.";
    } elseif (strlen($newPassword) < 8) {
        $errors[] = "New password must be at least 8 characters.";
    }
    if (empty($confirmPassword)) {
        $errors[] = "Please confirm your new password.";
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = "New passwords do not match.";
    }
    if ($currentPassword === $newPassword) {
        $errors[] = "New password must be different from current password.";
    }

    if (!empty($errors)) {
        $_SESSION['password_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php');
        exit;
    }

    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (!password_verify($currentPassword, $user['password'])) {
            $_SESSION['password_error'] = "Current password is incorrect.";
            header('Location: dashboard-resident.php');
            exit;
        }

        // Hash new password
        $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password in database
        $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
        $updateStmt->bind_param("si", $hashedNewPassword, $_SESSION['user_id']);

        if ($updateStmt->execute()) {
            $_SESSION['password_success'] = "Password changed successfully!";
            header('Location: dashboard-resident.php');
            exit;
        } else {
            $_SESSION['password_error'] = "Failed to change password. Please try again.";
            header('Location: dashboard-resident.php');
            exit;
        }

        $updateStmt->close();
    } else {
        $_SESSION['password_error'] = "User not found.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
