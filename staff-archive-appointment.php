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

header('Content-Type: application/json');

require_once 'db.php';
require_once 'includes/availability.php';
require_once 'includes/waitlist.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
$ids = array_unique($ids);

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No appointments selected.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$slotStmt = $conn->prepare("SELECT appointment_type, urgency_level, preferred_date, preferred_time FROM appointments
    WHERE appointment_id IN ($placeholders) AND archived_at IS NULL
      AND status IN ('pending','approved','scheduled','rescheduled','in_progress')");
$slotStmt->bind_param($types, ...$ids);
$slotStmt->execute();
$slotsToRelease = $slotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$slotStmt->close();

$stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW() WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
$stmt->bind_param($types, ...$ids);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok || $affected <= 0) {
    echo json_encode(['success' => false, 'message' => 'Failed to archive appointment(s).']);
    exit;
}
foreach ($slotsToRelease as $releasedSlot) {
    dcvsNotifyWaitlistForSlot($conn, $releasedSlot['preferred_date'], $releasedSlot['preferred_time'], $releasedSlot['appointment_type'], $releasedSlot['urgency_level'] ?? 'routine');
}

$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
$staffId = $_SESSION['user_id'] ?? null;
if ($staffId) {
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Appointment Deleted', ?, ?)");
    if ($log) {
        $details = "Deleted/archived " . $affected . " appointment(s).";
        $log->bind_param("iss", $staffId, $details, $_SERVER['REMOTE_ADDR']);
        $log->execute();
        $log->close();
    }
}

echo json_encode(['success' => true, 'message' => $affected . ' appointment(s) deleted.']);
$conn->close();
