<?php
// Determine which session to use based on the type parameter
$type = $_GET['type'] ?? '';
$nid = intval($_GET['nid'] ?? 0);

$sessionParams = [
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
];

if ($type === 'staff') {
    session_name('DCVS_STAFF_SESSION');
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params($sessionParams);
} elseif ($type === 'barangay') {
    session_name('DCVS_BARANGAY_SESSION');
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params($sessionParams);
} elseif ($type === 'resident') {
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params($sessionParams);
}
session_start();
require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    exit;
}

$userId = $_SESSION['user_id'];

if ($nid > 0) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $nid, $userId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
