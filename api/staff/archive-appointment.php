<?php
/**
 * Archive Appointment Handler
 * Archives (soft deletes) an appointment request
 */

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

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $appointmentId = intval($_POST['appointment_id'] ?? $_GET['id'] ?? 0);

    if ($appointmentId === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment ID.";
        header('Location: dashboard-resident.php');
        exit;
    }

    // Check if appointment belongs to user
    $checkAppt = $conn->prepare("SELECT appointment_id, appointment_type, urgency_level, preferred_date, preferred_time, status FROM appointments WHERE appointment_id = ? AND user_id = ?");
    $checkAppt->bind_param("ii", $appointmentId, $_SESSION['user_id']);
    $checkAppt->execute();
    $checkAppt->store_result();

    if ($checkAppt->num_rows === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment selected.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $checkAppt->bind_result($existingAppointmentId, $oldService, $oldUrgency, $oldDate, $oldTime, $oldStatus);
    $checkAppt->fetch();
    $checkAppt->close();

    // Archive appointment (soft delete - add archived_at timestamp)
    $stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW() WHERE appointment_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $appointmentId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        if (in_array($oldStatus, dcvsHeldStatuses(), true)) {
            dcvsNotifyWaitlistForSlot($conn, $oldDate, $oldTime, $oldService, $oldUrgency ?? 'routine');
        }
        $_SESSION['appointment_success'] = "Appointment archived successfully!";
        header('Location: dashboard-resident.php#archive');
        exit;
    } else {
        $_SESSION['appointment_error'] = "Failed to archive appointment. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
