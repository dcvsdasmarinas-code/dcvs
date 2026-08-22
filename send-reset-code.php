<?php
require_once 'db.php';
require_once 'mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot-password.php');
    exit;
}

$email = trim($_POST['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot-password.php?error=' . urlencode('Please enter a valid email address.'));
    exit;
}

// Check if email exists in users table
$stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ? AND status = 'active'");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: forgot-password.php?error=' . urlencode('No account found with that email address.'));
    exit;
}

// Invalidate any previous unused codes for this email
$stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->close();

// Generate 6-digit code and a unique token
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO password_resets (email, code, token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
$stmt->bind_param("sss", $email, $code, $token);
if (!$stmt->execute()) {
    header('Location: forgot-password.php?error=' . urlencode('Something went wrong. Please try again.'));
    exit;
}
$stmt->close();

// Send email with the code
$subject = 'Your DCVS Password Reset Code';
$body = '<h2>Password Reset Request</h2>';
$body .= '<p>Dear <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>';
$body .= '<p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p>';
$body .= '<div style="background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;">';
$body .= '<span style="font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;">' . $code . '</span>';
$body .= '</div>';
$body .= '<p>This code will expire in <strong>15 minutes</strong>.</p>';
$body .= '<p>If you did not request a password reset, please ignore this email.</p>';
$body .= '<p style="margin-top:16px;font-size:13px;color:#94a3b8;">For security reasons, do not share this code with anyone.</p>';

$html = dcvsEmailTemplate('Password Reset Code', $body);

$sent = sendDcvsEmail($email, $subject, $html, $conn, $user['full_name']);

if ($sent) {
    header('Location: verify-reset-code.php?token=' . urlencode($token));
} else {
    header('Location: forgot-password.php?error=' . urlencode('Failed to send the verification code. Please try again later.'));
}
exit;
