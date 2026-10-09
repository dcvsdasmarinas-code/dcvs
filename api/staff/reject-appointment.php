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
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/waitlist.php';

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
    $reason = trim($_POST['reason'] ?? '');

    if ($appointmentId === 0) {
        apptRespond(false, "Invalid appointment ID.");
    }

    if (empty($reason)) {
        apptRespond(false, "Reason for rejection is required.");
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

    // Reject - keep original notes, append rejection reason
    $updatedNotes = $appt['notes'];
    if (!empty($updatedNotes)) {
        $updatedNotes .= "\n\n[Rejected] " . $reason;
    } else {
        $updatedNotes = "[Rejected] " . $reason;
    }

    $updStmt = $conn->prepare("UPDATE appointments SET status = 'rejected', notes = ? WHERE appointment_id = ? AND status = 'pending'");
    $updStmt->bind_param("si", $updatedNotes, $appointmentId);
    if ($updStmt->execute() && $updStmt->affected_rows > 0) {
        $updStmt->close();

        $refId = $appt['reference_id'] ?? fallbackRefId('#APT-', $appointmentId);

        // Notify resident
        createNotification($conn, $appt['user_id'], 'appointment_update',
            'Appointment Request Rejected',
            'Your ' . $appt['appointment_type'] . ' appointment request (' . $refId . ') was not approved. Reason: ' . $reason,
            'appointments', $appointmentId);

        // Send email notification
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendAppointmentEmail($conn, $appointmentId, 'rejected', $reason, $staffName);
        sendAppointmentSms($conn, $appointmentId, 'rejected', $staffName, $reason);
        dcvsNotifyWaitlistForSlot($conn, $appt['preferred_date'], $appt['preferred_time'], $appt['appointment_type'], $appt['urgency_level'] ?? 'routine');

        // Log activity
        $action = "Appointment Rejected";
        $details = "Rejected " . ucfirst($appt['appointment_type']) . " appointment for " . $appt['resident_name']
            . (!empty($appt['pet_name']) ? " (pet: " . $appt['pet_name'] . ")" : "")
            . ". Reason: $reason";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        apptRespond(true, "Appointment request rejected. Resident has been notified.");
    } else {
        $updStmt->close();
        apptRespond(false, "Failed to reject appointment. It may have already been processed.");
    }
}

$conn->close();
header('Location: dashboard-staff.php#appointments');
exit;