<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../mailer.php';

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

// Generate a unique token (code field kept for DB compatibility but not used in UI)
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO password_resets (email, code, token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
$stmt->bind_param("sss", $email, $code, $token);
if (!$stmt->execute()) {
    header('Location: forgot-password.php?error=' . urlencode('Something went wrong. Please try again.'));
    exit;
}
$stmt->close();

// Build verification link
$verifyUrl = DCVS_PORTAL_URL . '/verify-request.php?token=' . urlencode($token);

// Send email with Verify Request button
$subject = 'Verify Your DCVS Password Reset Request';
$body = '<p style="margin:0 0 16px;">Dear <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>';
$body .= '<p style="margin:0 0 16px;">We received a request to reset your DCVS account password.</p>';
$body .= '<p style="margin:0 0 16px;">If this was you, click the button below to verify your request and proceed with resetting your password.</p>';
$body .= '<p style="margin:20px 0;"><a href="' . $verifyUrl . '" style="display:inline-block;background:#18181b;color:#fafafa;padding:10px 20px;border-radius:6px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:14px;font-weight:500;text-decoration:none;">Verify Request</a></p>';
$body .= '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">If you didn&rsquo;t request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>';
$body .= '<p style="margin:0;font-size:12px;color:#94a3b8;">This verification link will expire in <strong>15 minutes</strong>.</p>';

$html = dcvsEmailTemplate('Verify Request', $body);

$sent = sendDcvsEmail($email, $subject, $html, $conn, $user['full_name']);

if ($sent) {
    header('Location: check-email.php?token=' . urlencode($token));
} else {
    header('Location: forgot-password.php?error=' . urlencode('Failed to send the verification email. Please try again later.'));
}
exit;
