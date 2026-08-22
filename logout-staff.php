<?php
session_name('DCVS_STAFF_SESSION');
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
clearRememberMe($conn);
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Logout', 'Staff logged out', ?)");
    $log->bind_param("is", $uid, $ip);
    $log->execute();
    $log->close();
}
$_SESSION = array();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header('Location: login-staff.php');
exit;
?>