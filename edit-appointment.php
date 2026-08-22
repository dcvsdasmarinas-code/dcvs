<?php
/**
 * Edit Appointment Handler
 * Updates existing appointment request
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
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $appointmentType = $_POST['appt_type'] ?? '';
    $petId = intval($_POST['pet'] ?? 0);
    $preferredDate = $_POST['appt_date'] ?? '';
    $preferredTime = $_POST['appt_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    // Server-side validation
    $errors = [];

    if ($appointmentId === 0) {
        $errors[] = "Invalid appointment ID.";
    }
    if (empty($appointmentType)) {
        $errors[] = "Appointment type is required.";
    }
    if ($petId === 0) {
        $errors[] = "Please select a pet.";
    }
    if (empty($preferredDate)) {
        $errors[] = "Preferred date is required.";
    }
    if (empty($preferredTime)) {
        $errors[] = "Preferred time is required.";
    }

    // Slot capacity check (only if date/time changed)
    if (empty($errors)) {
        $origStmt = $conn->prepare("SELECT preferred_date, preferred_time FROM appointments WHERE appointment_id = ?");
        $origStmt->bind_param("i", $appointmentId);
        $origStmt->execute();
        $orig = $origStmt->get_result()->fetch_assoc();
        $origStmt->close();
        if ($orig && ($orig['preferred_date'] !== $preferredDate || $orig['preferred_time'] !== $preferredTime)) {
            // Check daily capacity (max 5)
            $dStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL AND appointment_id != ?");
            $dStmt->bind_param("si", $preferredDate, $appointmentId);
            $dStmt->execute();
            if ($dStmt->get_result()->fetch_row()[0] >= 5) { $errors[] = "This date is fully booked (max 5)."; }
            $dStmt->close();
            // Check per-slot capacity (max 5)
            $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL AND appointment_id != ?");
            $cStmt->bind_param("ssi", $preferredDate, $preferredTime, $appointmentId);
            $cStmt->execute();
            if ($cStmt->get_result()->fetch_row()[0] >= 5) { $errors[] = "Time slot is full (max 5)."; }
            $cStmt->close();
        }
    }

    if (!empty($errors)) {
        $_SESSION['appointment_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php');
        exit;
    }

    // Check if appointment belongs to user
    $checkAppt = $conn->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = ? AND user_id = ?");
    $checkAppt->bind_param("ii", $appointmentId, $_SESSION['user_id']);
    $checkAppt->execute();
    $checkAppt->store_result();

    if ($checkAppt->num_rows === 0) {
        $_SESSION['appointment_error'] = "Invalid appointment selected.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $checkAppt->close();

    // Check if pet belongs to user
    $checkPet = $conn->prepare("SELECT pet_id FROM pets WHERE pet_id = ? AND owner_id = ?");
    $checkPet->bind_param("ii", $petId, $_SESSION['user_id']);
    $checkPet->execute();
    $checkPet->store_result();

    if ($checkPet->num_rows === 0) {
        $_SESSION['appointment_error'] = "Invalid pet selected.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $checkPet->close();

    // Update appointment in database
    $stmt = $conn->prepare("UPDATE appointments SET appointment_type = ?, pet_id = ?, preferred_date = ?, preferred_time = ?, notes = ? WHERE appointment_id = ? AND user_id = ?");
    $stmt->bind_param("sissssi", $appointmentType, $petId, $preferredDate, $preferredTime, $notes, $appointmentId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        $_SESSION['appointment_success'] = "Appointment updated successfully!";
        header('Location: dashboard-resident.php');
        exit;
    } else {
        $_SESSION['appointment_error'] = "Failed to update appointment. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
