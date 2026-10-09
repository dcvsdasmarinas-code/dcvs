<?php
$portal = $_GET['portal'] ?? 'staff';
$sessionNames = [
    'resident' => 'PHPSESSID',
    'barangay' => 'DCVS_BARANGAY_SESSION',
    'staff' => 'DCVS_STAFF_SESSION',
    'admin' => 'DCVS_ADMIN_SESSION',
];
if (!isset($sessionNames[$portal])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid portal.']);
    exit;
}
if ($portal !== 'resident') session_name($sessionNames[$portal]);
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

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $portal) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$notificationId = (int)($_POST['notification_id'] ?? 0);
$action = trim($_POST['action'] ?? '');

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
    if (!$stmt) {
        error_log('Failed to prepare mark-all notification action: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not update notifications.']);
        exit;
    }
    $stmt->bind_param('i', $userId);
} else {
    $updates = [
        'read' => "SET is_read = 1",
        'unread' => "SET is_read = 0",
        'archive' => "SET archived_at = NOW()",
        'unarchive' => "SET archived_at = NULL",
    ];
    if ($notificationId <= 0 || !isset($updates[$action])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid notification action.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE notifications {$updates[$action]} WHERE notification_id = ? AND user_id = ?");
    if (!$stmt) {
        error_log('Failed to prepare notification action: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not update this notification.']);
        exit;
    }
    $stmt->bind_param('ii', $notificationId, $userId);
}

if (!$stmt->execute()) {
    error_log('Failed to execute notification action: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update notifications.']);
    exit;
}
$stmt->close();

echo json_encode(['success' => true]);
