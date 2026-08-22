<?php
/**
 * SMTP Diagnostic Tool
 * Run this from browser: http://localhost/dcvs/test-smtp.php
 * REMOVE or RESTRICT this file after testing.
 */
session_name('DCVS_STAFF_SESSION');
session_start();
require_once 'db.php';
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo "<h2>Access denied (staff only)</h2>"; exit;
}

echo "<h1>DCVS SMTP Diagnostics</h1>";

// 1. Check PHPMailer files
$files = ['phpmailer/PHPMailer.php', 'phpmailer/SMTP.php', 'phpmailer/Exception.php'];
$allOk = true;
foreach ($files as $f) {
    $ok = file_exists(__DIR__ . '/' . $f);
    echo "<p>📁 $f: " . ($ok ? "✅ Found" : "❌ MISSING") . "</p>";
    if (!$ok) $allOk = false;
}
if (!$allOk) { echo "<h2 style='color:red'>PHPMailer files missing — check phpmailer/ directory</h2>"; exit; }

// 2. Check config
echo "<p>🔧 USE_SMTP: " . (defined('USE_SMTP') && USE_SMTP ? '✅ true' : '⚠️ false') . "</p>";
echo "<p>🔧 SMTP_HOST: " . (defined('SMTP_HOST') ? htmlspecialchars(SMTP_HOST) : 'NOT SET') . "</p>";
echo "<p>🔧 SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT SET') . "</p>";
echo "<p>🔧 SMTP_USER: " . (defined('SMTP_USER') ? htmlspecialchars(SMTP_USER) : 'NOT SET') . "</p>";
echo "<p>🔧 SMTP_PASS: " . (defined('SMTP_PASS') ? strlen(SMTP_PASS) . ' chars' : 'NOT SET') . "</p>";
echo "<p>🔧 SMTP_SECURE: " . (defined('SMTP_SECURE') ? SMTP_SECURE : 'NOT SET') . "</p>";

// 3. Test SMTP connection
if (isset($_GET['test'])) {
    echo "<hr><h2>🔌 SMTP Connection Test</h2>";
    try {
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        require_once __DIR__ . '/phpmailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout    = 15;
        $mail->SMTPDebug  = 2;
        $mail->Debugoutput = function($str, $level) { echo htmlspecialchars($str) . "<br>"; };

        echo "<p>Attempting SMTP connection to " . SMTP_HOST . ":" . SMTP_PORT . "...</p>";
        $mail->smtpConnect();
        echo "<p style='color:green;font-weight:bold'>✅ SMTP connection successful!</p>";
        $mail->smtpClose();
    } catch (Exception $e) {
        echo "<p style='color:red;font-weight:bold'>❌ SMTP connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // 4. Test send
    if (isset($_GET['send_to'])) {
        $to = $_GET['send_to'];
        echo "<hr><h2>📨 Email Send Test to: " . htmlspecialchars($to) . "</h2>";
        $html = dcvsEmailTemplate('SMTP Test', '<p>This is a test email from DCVS Diagnostics.</p><p>If you receive this, SMTP is working!</p>');
        $result = sendSmtpMail($to, 'DCVS SMTP Test — ' . date('Y-m-d H:i'), $html);
        if (is_array($result) && $result['success']) {
            echo "<p style='color:green;font-weight:bold'>✅ Email sent successfully!</p>";
        } elseif (is_array($result)) {
            echo "<p style='color:red;font-weight:bold'>❌ Send failed: " . htmlspecialchars($result['error']) . "</p>";
        } else {
            echo "<p style='color:red;font-weight:bold'>❌ Send returned false</p>";
        }
    } else {
        echo "<p>To also send a test email, add <code>&send_to=recipient@gmail.com</code> to the URL.</p>";
    }
} else {
    echo "<hr><p><a href='?test=1' style='padding:10px 20px;background:#0E8131;color:#fff;text-decoration:none;border-radius:8px;'>🔌 Run SMTP Connection Test</a></p>";
    echo "<p>After connecting, you can also send a test email by adding <code>&send_to=email@example.com</code>.</p>";
}

echo "<hr><p><a href='staff-communication.php'>← Back to Communication Page</a></p>";
