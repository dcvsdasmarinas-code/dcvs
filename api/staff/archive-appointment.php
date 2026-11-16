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
session_name('DCVS_STAFF_SESSION');
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/waitlist.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? $_GET['id'] ?? 0);

    if ($appointmentId === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment ID.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    $checkAppt = $conn->prepare("SELECT appointment_id, status FROM appointments WHERE appointment_id = ? AND archived_at IS NULL");
    $checkAppt->bind_param("i", $appointmentId);
    $checkAppt->execute();
    $checkAppt->store_result();

    if ($checkAppt->num_rows === 0) {
        $_SESSION['appointment_error'] = "Appointment not found.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }
    $checkAppt->bind_result($existingAppointmentId, $oldStatus);
    $checkAppt->fetch();
    $checkAppt->close();

    if (!in_array($oldStatus, ['completed', 'no_show', 'cancelled', 'rejected'], true)) {
        $_SESSION['appointment_error'] = 'Only final appointment outcomes can be archived.';
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    $stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW()
        WHERE appointment_id = ? AND status IN ('completed','no_show','cancelled','rejected')");
    $stmt->bind_param("i", $appointmentId);

    if ($stmt->execute() && $stmt->affected_rows === 1) {
        $_SESSION['appointment_success'] = "Appointment archived successfully!";
        header('Location: dashboard-staff.php#appointments');
        exit;
    } else {
        $_SESSION['appointment_error'] = "Failed to archive appointment. Please try again.";
        header('Location: dashboard-staff.php#appointments');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
