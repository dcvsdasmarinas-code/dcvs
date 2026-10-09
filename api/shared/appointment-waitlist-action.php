<?php
$preferStaffSession = ($_POST['actor'] ?? '') === 'staff' || ($_POST['action'] ?? '') === 'notify';
if ($preferStaffSession && isset($_COOKIE['DCVS_STAFF_SESSION'])) {
    session_name('DCVS_STAFF_SESSION');
} elseif (isset($_COOKIE['PHPSESSID'])) {
    session_name('PHPSESSID');
} elseif (isset($_COOKIE['DCVS_STAFF_SESSION'])) {
    session_name('DCVS_STAFF_SESSION');
}
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/waitlist.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['resident', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to manage waitlist entries.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

dcvsExpireWaitlistClaims($conn);
$waitlistId = (int)($_POST['waitlist_id'] ?? 0);
$action = trim($_POST['action'] ?? '');
if ($waitlistId <= 0 || !in_array($action, ['cancel', 'notify'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a valid waitlist action.']);
    exit;
}
if ($action === 'notify' && ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only staff can send a manual notification.']);
    exit;
}

$stmt = $conn->prepare("SELECT waitlist_id, user_id, appointment_type, urgency_level, preferred_date, preferred_time, status
    FROM appointment_waitlist WHERE waitlist_id = ?");
if (!$stmt) {
    error_log('Failed to prepare waitlist action lookup: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update the waitlist. Please try again.']);
    exit;
}
$stmt->bind_param('i', $waitlistId);
if (!$stmt->execute()) {
    error_log('Failed to query waitlist action entry: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update the waitlist. Please try again.']);
    exit;
}
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$entry || (($_SESSION['role'] ?? '') === 'resident' && (int)$entry['user_id'] !== (int)$_SESSION['user_id'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Waitlist entry not found.']);
    exit;
}

if ($action === 'cancel') {
    if (!in_array($entry['status'], ['waiting', 'notified'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This waitlist entry can no longer be cancelled.']);
        exit;
    }
    $cancelStmt = $conn->prepare("UPDATE appointment_waitlist SET status = 'cancelled', claim_expires_at = NULL
        WHERE waitlist_id = ? AND status IN ('waiting','notified')");
    if (!$cancelStmt) {
        error_log('Failed to prepare waitlist cancellation: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not cancel this waitlist entry.']);
        exit;
    }
    $cancelStmt->bind_param('i', $waitlistId);
    $cancelled = $cancelStmt->execute() && $cancelStmt->affected_rows === 1;
    if (!$cancelled) error_log('Failed to cancel waitlist entry: ' . $cancelStmt->error);
    $cancelStmt->close();
    if (!$cancelled) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This waitlist entry has already changed. Refresh and try again.']);
        exit;
    }
    if ($entry['status'] === 'notified' && $entry['preferred_time']) {
        dcvsNotifyWaitlistForSlot($conn, $entry['preferred_date'], $entry['preferred_time'], $entry['appointment_type'], $entry['urgency_level']);
    }
    echo json_encode(['success' => true, 'message' => 'Waitlist entry cancelled.']);
    exit;
}

if ($entry['status'] !== 'waiting') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Only waiting entries can be manually notified.']);
    exit;
}
$times = $entry['preferred_time'] ? [substr($entry['preferred_time'], 0, 5)] : DCVS_STANDARD_TIMES;
$notified = false;
foreach ($times as $time) {
    if (dcvsNotifyWaitlistForSlot($conn, $entry['preferred_date'], $time, $entry['appointment_type'], $entry['urgency_level'], $waitlistId)) {
        $notified = true;
        break;
    }
}
if (!$notified) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'No matching appointment capacity is currently available.']);
    exit;
}
echo json_encode(['success' => true, 'message' => 'Resident notified. The appointment is not reserved and must be booked before the 24-hour claim expires.']);
