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

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'admin') {
    header('Location: login-admin.php');
    exit;
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $error = false;
    if (empty($fullName)) { $_SESSION['profile_error'] = 'Full name is required.'; $error = true; }
    if (empty($email)) { $_SESSION['profile_error'] = 'Email is required.'; $error = true; }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $_SESSION['profile_error'] = 'Invalid email format.'; $error = true; }
    if (empty($username)) { $_SESSION['profile_error'] = 'Username is required.'; $error = true; }

    if ($error) {
        header('Location: dashboard-admin.php#settings');
        exit;
    }

    $avatarUrl = handleAvatarUpload($conn, $userId, $_SESSION);
    if ($avatarUrl === false) {
        header('Location: dashboard-admin.php#settings');
        exit;
    }

    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, first_name = ?, last_name = ?, email = ?, username = ?, phone = ?, address = ?, avatar_url = ? WHERE user_id = ?");
    $stmt->bind_param("ssssssssi", $fullName, $firstName, $lastName, $email, $username, $phone, $address, $avatarUrl, $userId);

    if ($stmt->execute()) {
        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;
        $_SESSION['username'] = $username;
        $_SESSION['phone'] = $phone;
        $_SESSION['address'] = $address;
        $_SESSION['avatar_url'] = $avatarUrl;
    } else {
        $_SESSION['profile_error'] = 'Failed to update profile. Please try again.';
    }

    $stmt->close();
    header('Location: dashboard-admin.php#settings');
    exit;
}

header('Location: dashboard-admin.php');
exit;
