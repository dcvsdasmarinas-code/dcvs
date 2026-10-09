<?php
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
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/waitlist.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $appointment_type = $_POST['appt_type'] ?? '';
    $urgency_level = $_POST['urgency_level'] ?? 'routine';
    $pet_id = !empty($_POST['pet']) ? (int)$_POST['pet'] : null;
    $preferred_date = $_POST['appt_date'] ?? '';
    $preferred_time = $_POST['appt_time'] ?? '09:00:00';
    $status = 'pending';

    $validTypes = ['adoption', 'kapon', 'vaccination', 'checkup', 'deworming'];
    if (!in_array($appointment_type, $validTypes, true)
        || !in_array($urgency_level, ['routine', 'urgent'], true)
        || ($urgency_level === 'urgent' && !in_array($appointment_type, ['vaccination', 'checkup', 'deworming'], true))
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferred_date)
        || !checkdate((int)substr($preferred_date, 5, 2), (int)substr($preferred_date, 8, 2), (int)substr($preferred_date, 0, 4))
        || !dcvsIsStandardTime($preferred_time)) {
        echo json_encode(['success' => false, 'message' => 'Appointment type, date, and time must be valid.']);
        exit;
    }

    $preferred_time = substr($preferred_time, 0, 5) . ':00';
    $time = substr($preferred_time, 0, 5);
    if ($urgency_level === 'urgent') {
        if (!dcvsUrgentSlotAvailable($conn, $preferred_date, $time)) {
            echo json_encode(['success' => false, 'message' => 'This clinic time is closed. For urgent care, call (046) 432-1234 or visit the DCVS office.']);
            exit;
        }
        if (dcvsAppointmentDayCount($conn, $preferred_date, 0, 'urgent') >= DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY) {
            echo json_encode(['success' => false, 'message' => 'The urgent appointment slots for this day are full. Call (046) 432-1234 or visit the DCVS office for urgent assistance.']);
            exit;
        }
        if (dcvsSlotHeld($conn, $preferred_date, $preferred_time, 0, 'urgent')) {
            echo json_encode(['success' => false, 'message' => 'This urgent time slot is already reserved. Please choose another time.']);
            exit;
        }
    } else {
        if (!dcvsSlotAvailable($conn, $preferred_date, $time)) {
            echo json_encode(['success' => false, 'message' => 'No routine staff capacity is available for this time. Please choose another slot.']);
            exit;
        }
        if (dcvsAppointmentDayCount($conn, $preferred_date) >= DCVS_MAX_APPOINTMENTS_PER_DAY) {
            echo json_encode(['success' => false, 'message' => 'This date has reached its routine appointment limit of ' . DCVS_MAX_APPOINTMENTS_PER_DAY . '.']);
            exit;
        }
        if (dcvsSlotHeld($conn, $preferred_date, $preferred_time)) {
            echo json_encode(['success' => false, 'message' => 'This time slot is already at its routine capacity.']);
            exit;
        }
    }

    $pet_name = null;
    if ($pet_id) {
        $pnStmt = $conn->prepare("SELECT pet_name FROM pets WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL");
        $pnStmt->bind_param("ii", $pet_id, $user_id);
        $pnStmt->execute();
        $pnr = $pnStmt->get_result();
        if ($pnr && $pnrRow = $pnr->fetch_assoc()) $pet_name = $pnrRow['pet_name'];
        $pnStmt->close();
    }

    $refId = generateReferenceId($conn, '#APT-', 'appointments');
    $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, urgency_level, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isissssss", $user_id, $appointment_type, $pet_id, $pet_name, $preferred_date, $preferred_time, $status, $urgency_level, $refId);
    
    if ($stmt->execute()) {
        $appt_id = $conn->insert_id;
        dcvsMarkWaitlistBooked($conn, (int)$user_id, $appt_id, $appointment_type, $urgency_level, $preferred_date, $preferred_time, $pet_id);
        
        // Log activity
        $action = "Appointment Requested";
        $details = ($_SESSION['full_name'] ?? 'A resident') . " requested a " . ucfirst($appointment_type) . " appointment" . ($pet_name ? " for " . $pet_name : '') . ".";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->bind_param("iss", $user_id, $action, $details);
        $logStmt->execute();

        echo json_encode(['success' => true, 'message' => 'Appointment requested successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to request appointment. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
