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
    $reason = trim($_POST['reason'] ?? '');

    if ($appointmentId === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment ID.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    if (empty($reason)) {
        $_SESSION['appointment_error'] = "Reason for rejection is required.";
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

        // Log activity
        $action = "Appointment Rejected";
        $details = "Rejected appointment $refId. Reason: $reason";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['appointment_success'] = "Appointment request rejected. Resident has been notified.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    } else {
        $updStmt->close();
        $_SESSION['appointment_error'] = "Failed to reject appointment. It may have already been processed.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
}

$conn->close();
header('Location: dashboard-staff.php#appointments');
exit;