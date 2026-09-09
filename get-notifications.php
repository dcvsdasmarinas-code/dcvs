<?php
/**
 * Get Notifications API
 * Returns recent notifications for the logged-in user (barangay session).
 */

session_name('DCVS_BARANGAY_SESSION');
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

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/db.php';

$userId = (int)$_SESSION['user_id'];
$limit  = min(max((int)($_GET['limit'] ?? 20), 1), 50);

$stmt = $conn->prepare("
    SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ?
");
$stmt->bind_param('ii', $userId, $limit);
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id'        => (int)$row['notification_id'],
        'type'      => $row['type'],
        'title'     => $row['title'],
        'message'   => $row['message'],
        'refType'   => $row['reference_type'],
        'refId'     => $row['reference_id'] ? (int)$row['reference_id'] : null,
        'read'      => (bool)$row['is_read'],
        'createdAt' => $row['created_at'],
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'notifications' => $notifications]);
