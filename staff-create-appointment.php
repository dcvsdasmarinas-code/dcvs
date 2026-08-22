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
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$residentId = isset($_POST['resident_id']) ? (int)$_POST['resident_id'] : 0;
$petId = !empty($_POST['pet_id']) ? (int)$_POST['pet_id'] : null;
$appointmentType = trim($_POST['appointment_type'] ?? '');
$date = trim($_POST['preferred_date'] ?? '');
$time = trim($_POST['preferred_time'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedTypes = ['vaccination', 'checkup', 'kapon', 'adoption', 'followup', 'grooming', 'other'];
if (!in_array($appointmentType, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment type.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}

if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
    echo json_encode(['success' => false, 'message' => 'Invalid time.']);
    exit;
}
$time = substr($time, 0, 5) . ':00';

$userStmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND role = 'resident' AND archived_at IS NULL");
$userStmt->bind_param("i", $residentId);
$userStmt->execute();
$userRow = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$userRow) {
    echo json_encode(['success' => false, 'message' => 'Selected resident was not found.']);
    exit;
}

$petName = null;
if ($petId) {
    $petStmt = $conn->prepare("SELECT pet_name FROM pets WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL");
    $petStmt->bind_param("ii", $petId, $residentId);
    $petStmt->execute();
    $petRow = $petStmt->get_result()->fetch_assoc();
    $petStmt->close();
    if (!$petRow) {
        echo json_encode(['success' => false, 'message' => 'Selected pet was not found for this resident.']);
        exit;
    }
    $petName = $petRow['pet_name'];
}

$weeklyTemplate = dcvsWeeklyTemplate($conn);
$blockedTimes = dcvsBlockedTimes($conn, $date, $weeklyTemplate);
if (in_array(substr($time, 0, 5), $blockedTimes, true)) {
    echo json_encode(['success' => false, 'message' => 'That time slot is not available on this date.']);
    exit;
}

$heldTimes = dcvsHeldTimes($conn, $date);
if (isset($heldTimes[substr($time, 0, 5)])) {
    echo json_encode(['success' => false, 'message' => 'That date and time is already reserved by another appointment.']);
    exit;
}

$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
$staffId = $_SESSION['user_id'] ?? null;

$refId = generateReferenceId($conn, '#APT-', 'appointments');

$stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)");
$stmt->bind_param("isisssss", $residentId, $appointmentType, $petId, $petName, $date, $time, $refId, $notes);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to schedule appointment. Please try again.']);
    exit;
}

$apptId = $conn->insert_id;

$logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Appointment Scheduled', ?, ?)");
$logDetails = "Scheduled " . ucfirst($appointmentType) . " appointment for " . $userRow['full_name'] . " (ID: " . $refId . ") on " . $date . " at " . date('g:i A', strtotime($time)) . ".";
$logStmt->bind_param("iss", $staffId, $logDetails, $_SERVER['REMOTE_ADDR']);
$logStmt->execute();

$msg = "Your " . ucfirst($appointmentType) . " appointment (" . $refId . ") has been scheduled on " . date('F j, Y', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . ".";
createNotification($conn, $residentId, 'appointment_update', 'Appointment Scheduled', $msg, 'appointments', $apptId);
sendAppointmentEmail($conn, $apptId, 'scheduled', '', $staffName);

echo json_encode(['success' => true, 'message' => 'Appointment scheduled successfully.']);