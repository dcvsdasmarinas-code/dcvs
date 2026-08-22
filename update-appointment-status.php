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
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';
$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
$ids = array_unique($ids);

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No appointments selected.']);
    exit;
}

$allowed = ['complete', 'cancel', 'reschedule'];
if (!in_array($action, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
$staffId = $_SESSION['user_id'] ?? null;

function logActivity($conn, $staffId, $action, $details, $ip) {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("isss", $staffId, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

if ($action === 'reschedule') {
    $date = trim($_POST['preferred_date'] ?? '');
    $time = trim($_POST['preferred_time'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        echo json_encode(['success' => false, 'message' => 'Invalid date.']);
        exit;
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        echo json_encode(['success' => false, 'message' => 'Invalid time.']);
        exit;
    }
    $time = substr($time, 0, 5) . ':00';

    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
    $selStmt->bind_param($types, ...$ids);
    $selStmt->execute();
    $res = $selStmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $selStmt->close();

    // Slot availability check (max 1 per date+time among active appts)
    $availStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('pending','scheduled','rescheduled') AND archived_at IS NULL AND appointment_id NOT IN ($placeholders)");
    $notIn = $ids;
    $bindTypes = 'ss' . $types;
    $availStmt->bind_param($bindTypes, $date, $time, ...$notIn);
    $availStmt->execute();
    if ($availStmt->get_result()->fetch_row()[0] > 0) {
        $availStmt->close();
        echo json_encode(['success' => false, 'message' => 'That date and time is already reserved by another appointment.']);
        exit;
    }
    $availStmt->close();

    $updStmt = $conn->prepare("UPDATE appointments SET preferred_date = ?, preferred_time = ?, status = 'rescheduled' WHERE appointment_id IN ($placeholders)");
    $updStmt->bind_param('ss' . $types, $date, $time, ...$ids);
    $ok = $updStmt->execute() && $updStmt->affected_rows > 0;
    $updStmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to reschedule. The appointment may have been updated already.']);
        exit;
    }

    $newDate = date('F j, Y', strtotime($date));
    $newTime = date('g:i A', strtotime($time));
    foreach ($rows as $r) {
        $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
        $title = $r['appointment_type'] === 'adoption' ? 'Adoption Appointment Rescheduled' : 'Appointment Rescheduled';
        $msg = "Your " . $r['appointment_type'] . " appointment has been rescheduled to " . $newDate . " at " . $newTime . ".";
        createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
        sendAppointmentEmail($conn, $r['appointment_id'], 'rescheduled', '', $staffName);
    }

    logActivity($conn, $staffId, 'Appointment Rescheduled', 'Rescheduled ' . count($rows) . ' appointment(s) to ' . $date . ' ' . $time . '.', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($rows) . ' appointment(s) rescheduled.']);
    exit;
}

$newStatus = $action === 'complete' ? 'completed' : 'cancelled';

$selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, preferred_date, preferred_time, status FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
$selStmt->bind_param($types, ...$ids);
$selStmt->execute();
$res = $selStmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$selStmt->close();

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'Appointment(s) not found.']);
    exit;
}

$validFrom = $newStatus === 'cancelled'
    ? ['pending', 'approved', 'scheduled', 'rescheduled']
    : ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress'];

$targets = [];
foreach ($rows as $r) {
    if (in_array($r['status'], $validFrom, true)) $targets[] = $r;
}

if (empty($targets)) {
    echo json_encode(['success' => false, 'message' => 'Selected appointment(s) cannot be updated from their current status.']);
    exit;
}

$targetIds = array_column($targets, 'appointment_id');
$tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
$tTypes = str_repeat('i', count($targetIds));

$updStmt = $conn->prepare("UPDATE appointments SET status = '$newStatus' WHERE appointment_id IN ($tPlaceholders) AND status IN ('pending','approved','scheduled','rescheduled','in_progress')");
$updStmt->bind_param($tTypes, ...$targetIds);
$ok = $updStmt->execute() && $updStmt->affected_rows > 0;
$updStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update appointment status.']);
    exit;
}

$actionLabel = $newStatus === 'completed' ? 'Completed' : 'Cancelled';
foreach ($targets as $r) {
    $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
    $title = $newStatus === 'completed' ? 'Appointment Completed' : 'Appointment Cancelled';
    $msg = $newStatus === 'completed'
        ? "Your " . $r['appointment_type'] . " appointment (" . $refId . ") has been marked as completed."
        : "Your " . $r['appointment_type'] . " appointment on " . date('F j, Y', strtotime($r['preferred_date'])) . " at " . date('g:i A', strtotime($r['preferred_time'])) . " has been cancelled.";
    createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
    sendAppointmentEmail($conn, $r['appointment_id'], $newStatus, '', $staffName);
}

logActivity($conn, $staffId, 'Appointment ' . $actionLabel, $actionLabel . ' ' . count($targets) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
echo json_encode(['success' => true, 'message' => count($targets) . ' appointment(s) marked ' . strtolower($actionLabel) . '.']);
exit;