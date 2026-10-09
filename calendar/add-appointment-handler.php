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

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/availability.php';
require_once dirname(__DIR__) . '/mailer.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$pet_id  = !empty($_POST['pet_id']) ? (int)$_POST['pet_id'] : null;
$pet_name = trim($_POST['pet_name'] ?? '');
$appointment_type = $_POST['appointment_type'] ?? '';
$preferred_date = $_POST['preferred_date'] ?? '';
$preferred_time = $_POST['preferred_time'] ?? '';
$status = $_POST['status'] ?? 'scheduled';
$notes = trim($_POST['notes'] ?? '');

$validTypes = ['adoption', 'kapon', 'vaccination', 'checkup'];
$validStatuses = ['pending', 'approved', 'scheduled', 'completed', 'cancelled', 'no_show', 'rescheduled', 'rejected'];

if (!$user_id || !in_array($appointment_type, $validTypes, true) || !$preferred_date || !$preferred_time) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}
if (!dcvsIsStandardTime($preferred_time)) {
    echo json_encode(['success' => false, 'message' => 'Please choose one of the available clinic appointment times.']);
    exit;
}
if (!in_array($status, $validStatuses, true)) {
    $status = 'scheduled';
}

$checkUser = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND role = 'resident' AND status = 'active'");
$checkUser->bind_param('i', $user_id);
$checkUser->execute();
$resident = $checkUser->get_result()->fetch_assoc();
if (!$resident) {
    echo json_encode(['success' => false, 'message' => 'Invalid resident selected.']);
    $checkUser->close();
    exit;
}
$checkUser->close();

$activeStatuses = "'pending','approved','scheduled'";

$dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses,'in_progress') AND archived_at IS NULL");
$dupStmt->bind_param('iss', $user_id, $preferred_date, $preferred_time);
$dupStmt->execute();
if ($dupStmt->get_result()->fetch_row()[0] > 0) {
    $dupStmt->close();
    echo json_encode(['success' => false, 'message' => 'This resident already has an appointment at this date and time.']);
    exit;
}
$dupStmt->close();

$dCount = dcvsAppointmentDayCount($conn, $preferred_date);
if ($dCount >= DCVS_MAX_APPOINTMENTS_PER_DAY) {
    echo json_encode(['success' => false, 'message' => 'This date is fully booked (max ' . DCVS_MAX_APPOINTMENTS_PER_DAY . ').']);
    exit;
}

if (!dcvsSlotAvailable($conn, $preferred_date, $preferred_time)) {
    echo json_encode(['success' => false, 'message' => 'This time slot is closed by the clinic schedule.']);
    exit;
}
if (dcvsSlotHeld($conn, $preferred_date, $preferred_time)) {
    echo json_encode(['success' => false, 'message' => 'This time slot is already reserved (max 1).']);
    exit;
}
if (dcvsAppointmentSlotCount($conn, $preferred_date, $preferred_time) >= dcvsRoutineSlotCapacity($conn, $preferred_date, substr($preferred_time, 0, 5))) {
    echo json_encode(['success' => false, 'message' => 'This time slot is already at its staff capacity.']);
    exit;
}

$refId = generateReferenceId($conn, '#APT-', 'appointments');
$stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, notes, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param('ississsss', $user_id, $appointment_type, $pet_id, $pet_name, $preferred_date, $preferred_time, $status, $notes, $refId);

if ($stmt->execute()) {
    $appointment_id = $stmt->insert_id;
    $stmt->close();

    if (in_array($status, ['scheduled', 'completed', 'rescheduled'])) {
        $notifTitle = $status === 'completed' ? 'Adoption Completed' : 'Appointment ' . ucfirst($status);
        if ($appointment_type === 'adoption') {
            $notifTitle = $status === 'completed' ? 'Adoption Complete' : 'Adoption Appointment Scheduled';
        }
        $notifMsg = "Your $appointment_type appointment has been $status.";
        if ($status === 'scheduled') {
            $notifMsg = "Your $appointment_type appointment is scheduled on $preferred_date at $preferred_time.";
        }
        createNotification($conn, $user_id, $appointment_type === 'adoption' ? 'adoption_scheduled' : 'appointment_update', $notifTitle, $notifMsg, 'appointments', $appointment_id);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $staff_id = $_SESSION['user_id'];
    $logAction = 'Appointment Scheduled';
    $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $details = "Scheduled " . ucfirst($appointment_type) . " appointment for " . $resident['full_name']
        . ($pet_name !== '' ? " (pet: " . $pet_name . ")" : "")
        . " on $preferred_date at " . date('g:i A', strtotime($preferred_time)) . ".";
    $logStmt->bind_param('isss', $staff_id, $logAction, $details, $ip);
    $logStmt->execute();
    $logStmt->close();

    echo json_encode(['success' => true, 'message' => "Appointment $refId scheduled.", 'reference_id' => $refId]);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Error saving appointment.']);
}
exit;