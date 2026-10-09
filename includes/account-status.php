<?php
$sessionWhitelist = [
    'PHPSESSID' => 'login.php',
    'DCVS_STAFF_SESSION' => 'login-staff.php',
    'DCVS_ADMIN_SESSION' => 'login-admin.php',
    'DCVS_BARANGAY_SESSION' => 'login-barangay.php',
];
$requestedSession = trim($_GET['sess'] ?? '');
$sessionName = isset($sessionWhitelist[$requestedSession]) ? $requestedSession : 'PHPSESSID';
session_name($sessionName);
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['active' => false, 'redirect' => $sessionWhitelist[$sessionName]]);
    exit;
}

echo json_encode(['active' => true]);
