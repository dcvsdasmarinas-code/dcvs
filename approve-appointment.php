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

require_once 'db.php';
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $staffMessage = trim($_POST['staff_message'] ?? '');

    if ($appointmentId === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment ID.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    // Load the request - must still be pending
    $stmt = $conn->prepare("SELECT a.appointment_id, a.user_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.notes, a.reference_id FROM appointments a WHERE a.appointment_id = ? AND a.archived_at IS NULL");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) {
        $_SESSION['appointment_error'] = "Appointment request not found.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    if ($appt['status'] !== 'pending') {
        $_SESSION['appointment_error'] = "This request has already been processed (current status: " . $appt['status'] . ").";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    $activeStatuses = "'pending','scheduled'";

    // Check duplicate for same resident
    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses,'in_progress') AND archived_at IS NULL AND appointment_id != ?");
    $dupStmt->bind_param("issi", $appt['user_id'], $appt['preferred_date'], $appt['preferred_time'], $appointmentId);
    $dupStmt->execute();
    if ($dupStmt->get_result()->fetch_row()[0] > 0) {
        $dupStmt->close();
        $_SESSION['appointment_error'] = "This resident already has another appointment at this date and time.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
    $dupStmt->close();

    // Check daily capacity (max 5)
    $dStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ($activeStatuses) AND archived_at IS NULL AND appointment_id != ?");
    $dStmt->bind_param("si", $appt['preferred_date'], $appointmentId);
    $dStmt->execute();
    if ($dStmt->get_result()->fetch_row()[0] >= 5) {
        $dStmt->close();
        $_SESSION['appointment_error'] = "Cannot approve: " . date('M d, Y', strtotime($appt['preferred_date'])) . " is fully booked (max 5).";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
    $dStmt->close();

    // Check per-slot capacity (max 1)
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses) AND archived_at IS NULL AND appointment_id != ?");
    $cStmt->bind_param("ssi", $appt['preferred_date'], $appt['preferred_time'], $appointmentId);
    $cStmt->execute();
    if ($cStmt->get_result()->fetch_row()[0] >= 1) {
        $cStmt->close();
        $_SESSION['appointment_error'] = "Cannot approve: this time slot is already reserved by another resident (max 1).";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
    $cStmt->close();

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

        // Log activity
        $action = "Appointment Approved";
        $details = "Approved appointment $refId for " . $appt['appointment_type'] . " on " . $appt['preferred_date'] . ".";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['appointment_success'] = "Appointment request approved and scheduled.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    } else {
        $updStmt->close();
        $_SESSION['appointment_error'] = "Failed to approve appointment. It may have already been processed.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
}

$conn->close();
header('Location: dashboard-staff.php#appointments');
exit;
