<?php
/**
 * Update Profile Handler
 * Updates user account information
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
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/registration-validation.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== PROFILE UPDATE START ===");
    error_log("FILES: " . print_r($_FILES, true));
    error_log("POST: " . print_r($_POST, true));
    
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    
    // Handle avatar upload (standardized via shared function)
    $avatarUrl = handleAvatarUpload($conn, $_SESSION['user_id'], $_SESSION);
    if ($avatarUrl === false) {
        header('Location: dashboard-resident.php#account-settings');
        exit;
    }

    // Server-side validation
    $errors = [];

    if (empty($firstName)) {
        $errors[] = "First name is required.";
    }
    if (empty($lastName)) {
        $errors[] = "Last name is required.";
    }
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    $phoneError = dcvsRegistrationPhoneError($phone);
    if ($phoneError !== '') {
        $_SESSION['profile_phone_error'] = $phoneError;
        $errors[] = $phoneError;
    } else {
        unset($_SESSION['profile_phone_error']);
    }

    if (!empty($errors)) {
        $_SESSION['profile_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php#account-settings');
        exit;
    }

    // Check if email is being changed and if it's already taken
    if ($email !== $_SESSION['email']) {
        $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $checkEmail->bind_param("si", $email, $_SESSION['user_id']);
        $checkEmail->execute();
        $checkEmail->store_result();

        if ($checkEmail->num_rows > 0) {
            $_SESSION['profile_error'] = "Email address is already registered to another account.";
            header('Location: dashboard-resident.php#account-settings');
            exit;
        }
        $checkEmail->close();
    }

    // Update user information
    $fullName = $firstName . ' ' . $lastName;
    
    // Get current barangay from session
    $barangay = '';
    if (!empty($_SESSION['barangay_id'])) {
        $stmt = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
        $stmt->bind_param("i", $_SESSION['barangay_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $barangay = $row['barangay_name'];
        }
        $stmt->close();
    }

    $fullAddress = $street . ', ' . $barangay . ', Dasmariñas, Cavite';

    $birthdate = empty($birthdate) ? null : $birthdate;
    $gender = empty($gender) ? null : $gender;

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, avatar_url = ?, birthdate = ?, gender = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
    $stmt->bind_param("sssssssi", $fullName, $email, $phone, $fullAddress, $avatarUrl, $birthdate, $gender, $_SESSION['user_id']);

    if ($stmt->execute()) {
        // Update session variables
        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;
        $_SESSION['phone'] = $phone;
        $_SESSION['address'] = $fullAddress;
        $_SESSION['avatar_url'] = $avatarUrl;

        header('Location: dashboard-resident.php#account-settings');
        exit;
    } else {
        $_SESSION['profile_error'] = "Failed to update profile. Please try again.";
        header('Location: dashboard-resident.php#account-settings');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
