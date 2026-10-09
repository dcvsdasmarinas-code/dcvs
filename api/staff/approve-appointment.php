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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

// JSON response for the sequential review dialog (ajax=1), session flash + redirect otherwise
function apptRespond($success, $message) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$success, 'message' => $message]);
        exit;
    }
    $_SESSION[$success ? 'appointment_success' : 'appointment_error'] = $message;
    header('Location: dashboard-staff.php#appointments');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $staffMessage = trim($_POST['staff_message'] ?? '');

    if ($appointmentId === 0) {
        apptRespond(false, "Invalid appointment ID.");
    }

    // Load the request - must still be pending
    $stmt = $conn->prepare("SELECT a.appointment_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.notes, a.reference_id, u.full_name AS resident_name, p.pet_name FROM appointments a JOIN users u ON u.user_id = a.user_id LEFT JOIN pets p ON p.pet_id = a.pet_id WHERE a.appointment_id = ? AND a.archived_at IS NULL");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) {
        apptRespond(false, "Appointment request not found.");
    }

    if ($appt['status'] !== 'pending') {
        apptRespond(false, "This request has already been processed (current status: " . $appt['status'] . ").");
    }

    $urgency = $appt['urgency_level'] ?? 'routine';
    $slotIsAvailable = $urgency === 'urgent'
        ? dcvsUrgentSlotAvailable($conn, $appt['preferred_date'], substr($appt['preferred_time'], 0, 5))
        : dcvsSlotAvailable($conn, $appt['preferred_date'], substr($appt['preferred_time'], 0, 5));
    if (!$slotIsAvailable) {
        apptRespond(false, dcvsSlotUnavailableReason($conn, $appt['preferred_date'], $appt['preferred_time']));
    }

    $activeStatuses = "'pending','scheduled'";

    // Check duplicate for same resident
    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses,'in_progress') AND archived_at IS NULL AND appointment_id != ?");
    $dupStmt->bind_param("issi", $appt['user_id'], $appt['preferred_date'], $appt['preferred_time'], $appointmentId);
    $dupStmt->execute();
    if ($dupStmt->get_result()->fetch_row()[0] > 0) {
        $dupStmt->close();
        apptRespond(false, "This resident already has another appointment at this date and time.");
    }
    $dupStmt->close();

    // Check daily capacity, excluding this pending request.
    $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
    if (dcvsAppointmentDayCount($conn, $appt['preferred_date'], $appointmentId, $urgency) >= $dailyLimit) {
        apptRespond(false, "Cannot approve: " . date('M d, Y', strtotime($appt['preferred_date'])) . " is at its " . $urgency . " appointment limit.");
    }

    // Check per-slot capacity.
    $slotCapacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $appt['preferred_date'], substr($appt['preferred_time'], 0, 5));
    if (dcvsAppointmentSlotCount($conn, $appt['preferred_date'], $appt['preferred_time'], $appointmentId, $urgency) >= $slotCapacity) {
        apptRespond(false, "Cannot approve: this time slot no longer has enough staff capacity.");
    }

    // Approve the request using the resident's preferred date/time
    $updStmt = $conn->prepare("UPDATE appointments SET status = 'scheduled' WHERE appointment_id = ? AND status = 'pending'");
    $updStmt->bind_param("i", $appointmentId);
    if ($updStmt->execute() && $updStmt->affected_rows > 0) {
        $updStmt->close();

        $refId = $appt['reference_id'] ?? fallbackRefId('#APT-', $appointmentId);

        // Notify resident
        $notifTitle = $appt['appointment_type'] === 'adoption' ? 'Adoption Appointment Approved' : 'Appointment Approved';
        $notifType = $appt['appointment_type'] === 'adoption' ? 'adoption_scheduled' : 'appointment_update';
        $notifMsg = "Your " . $appt['appointment_type'] . " appointment has been approved and scheduled on "
            . date('F j, Y', strtotime($appt['preferred_date'])) . " at " . date('g:i A', strtotime($appt['preferred_time'])) . ".";
        if (!empty($staffMessage)) {
            $notifMsg .= "\n\nStaff Message: $staffMessage";
        }
        createNotification($conn, $appt['user_id'], $notifType, $notifTitle, $notifMsg, 'appointments', $appointmentId);

        // Send email notification
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendAppointmentEmail($conn, $appointmentId, 'scheduled', $staffMessage, $staffName);
        sendAppointmentSms($conn, $appointmentId, 'scheduled', $staffName, $staffMessage);

        // Log activity
        $action = "Appointment Approved";
        $details = "Approved " . ucfirst($appt['appointment_type']) . " appointment for " . $appt['resident_name']
            . (!empty($appt['pet_name']) ? " (pet: " . $appt['pet_name'] . ")" : "")
            . " on " . $appt['preferred_date'] . ".";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        apptRespond(true, "Appointment request approved and scheduled.");
    } else {
        $updStmt->close();
        apptRespond(false, "Failed to approve appointment. It may have already been processed.");
    }
}

$conn->close();
header('Location: dashboard-staff.php#appointments');
exit;
