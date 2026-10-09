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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

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
$urgencyLevel = trim($_POST['urgency_level'] ?? 'routine');
$date = trim($_POST['preferred_date'] ?? '');
$time = trim($_POST['preferred_time'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedTypes = ['vaccination', 'checkup', 'kapon', 'adoption', 'deworming'];
if (!in_array($appointmentType, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment type.']);
    exit;
}
if (!in_array($urgencyLevel, ['routine', 'urgent'], true)
    || ($urgencyLevel === 'urgent' && !in_array($appointmentType, ['vaccination', 'checkup', 'deworming'], true))) {
    echo json_encode(['success' => false, 'message' => 'Urgent appointments are available for Vaccination, Check-up, and Deworming only.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}

if (!dcvsIsStandardTime($time)) {
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

$slotAvailable = $urgencyLevel === 'urgent'
    ? dcvsUrgentSlotAvailable($conn, $date, $time)
    : dcvsSlotAvailable($conn, $date, $time);
$dailyLimit = $urgencyLevel === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
$slotCapacity = $urgencyLevel === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $time);
if (!$slotAvailable
    || dcvsAppointmentSlotCount($conn, $date, $time, 0, $urgencyLevel) >= $slotCapacity
    || dcvsAppointmentDayCount($conn, $date, 0, $urgencyLevel) >= $dailyLimit) {
    echo json_encode(['success' => false, 'message' => 'That time slot has no remaining ' . $urgencyLevel . ' capacity.']);
    exit;
}

$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
$staffId = $_SESSION['user_id'] ?? null;

$refId = generateReferenceId($conn, '#APT-', 'appointments');

$stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, urgency_level, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)");
$stmt->bind_param("isissssss", $residentId, $appointmentType, $petId, $petName, $date, $time, $urgencyLevel, $refId, $notes);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to schedule appointment. Please try again.']);
    exit;
}

$apptId = $conn->insert_id;
dcvsMarkWaitlistBooked($conn, $residentId, $apptId, $appointmentType, $urgencyLevel, $date, $time, $petId);

$logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Appointment Scheduled', ?, ?)");
$logDetails = "Scheduled " . ucfirst($appointmentType) . " appointment for " . $userRow['full_name']
    . ($petName ? " (pet: " . $petName . ")" : "")
    . " on " . $date . " at " . date('g:i A', strtotime($time)) . ".";
$logStmt->bind_param("iss", $staffId, $logDetails, $_SERVER['REMOTE_ADDR']);
$logStmt->execute();

$msg = "Your " . ucfirst($appointmentType) . " appointment (" . $refId . ") has been scheduled on " . date('F j, Y', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . ".";
createNotification($conn, $residentId, 'appointment_update', 'Appointment Scheduled', $msg, 'appointments', $apptId);
sendAppointmentEmail($conn, $apptId, 'scheduled', '', $staffName);
sendAppointmentSms($conn, $apptId, 'scheduled', $staffName);

echo json_encode(['success' => true, 'message' => 'Appointment scheduled successfully.']);