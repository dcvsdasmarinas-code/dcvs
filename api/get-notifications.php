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

require_once __DIR__ . '/../db.php';

$userId = (int)$_SESSION['user_id'];
$limit  = min(max((int)($_GET['limit'] ?? 20), 1), 50);
$archived = !empty($_GET['archived']) ? 1 : 0;

$stmt = $conn->prepare("
    SELECT n.notification_id, n.type, n.title, n.message, n.reference_type, n.reference_id,
        n.is_read, n.archived_at, n.created_at, actor.full_name AS actor_name, actor.avatar_url AS actor_avatar
    FROM notifications n
    LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
    LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
    LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
    LEFT JOIN users actor ON actor.user_id = CASE
        WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
        WHEN n.reference_type = 'report' THEN r.reporter_id
        WHEN n.reference_type = 'appointments' THEN a.user_id
        WHEN n.reference_type = 'pets' THEN p.owner_id
        WHEN n.reference_type = 'staff_message' THEN n.reference_id
        ELSE NULL
    END
    WHERE n.user_id = ? AND (n.archived_at IS NOT NULL) = ?
    ORDER BY COALESCE(n.archived_at, n.created_at) DESC
    LIMIT ?
");
$stmt->bind_param('iii', $userId, $archived, $limit);
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id'        => (int)$row['notification_id'],
        'notification_id' => (int)$row['notification_id'],
        'type'      => $row['type'],
        'title'     => $row['title'],
        'message'   => $row['message'],
        'refType'   => $row['reference_type'],
        'refId'     => $row['reference_id'] ? (int)$row['reference_id'] : null,
        'read'      => (bool)$row['is_read'],
        'is_read'   => (bool)$row['is_read'],
        'archived'  => !empty($row['archived_at']),
        'actorName' => $row['actor_name'] ?? '',
        'actorAvatar' => $row['actor_avatar'] ?? '',
        'createdAt' => $row['created_at'],
        'created_at' => $row['created_at'],
    ];
}
$stmt->close();
$unreadStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
if (!$unreadStmt) {
    error_log('Failed to prepare barangay notification count: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load notification count.']);
    exit;
}
$unreadStmt->bind_param('i', $userId);
$unreadStmt->execute();
$unreadCount = (int)$unreadStmt->get_result()->fetch_row()[0];
$unreadStmt->close();
$conn->close();

echo json_encode(['success' => true, 'notifications' => $notifications, 'unread_count' => $archived ? null : $unreadCount]);
