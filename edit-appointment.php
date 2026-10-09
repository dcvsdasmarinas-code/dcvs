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
require_once 'includes/availability.php';
require_once 'includes/waitlist.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $appointmentType = $_POST['appt_type'] ?? '';
    $urgencyLevel = $_POST['urgency_level'] ?? 'routine';
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
    if (!in_array($urgencyLevel, ['routine', 'urgent'], true)
        || ($urgencyLevel === 'urgent' && !in_array($appointmentType, ['vaccination', 'checkup', 'deworming'], true))) {
        $errors[] = "Urgent appointments are available for Vaccination, Check-up, and Deworming only.";
    }
    if ($petId === 0) {
        $errors[] = "Please select a pet.";
    }
    if (empty($preferredDate)) {
        $errors[] = "Preferred date is required.";
    }
    if (empty($preferredTime)) {
        $errors[] = "Preferred time is required.";
    } elseif (!dcvsIsStandardTime($preferredTime)) {
        $errors[] = "Please select one of the available clinic appointment times.";
    }

    // Slot capacity check (only if date/time changed)
    if (empty($errors)) {
        $preferredTime = dcvsTimeKey($preferredTime) . ':00';
        $origStmt = $conn->prepare("SELECT preferred_date, preferred_time, urgency_level FROM appointments WHERE appointment_id = ?");
        $origStmt->bind_param("i", $appointmentId);
        $origStmt->execute();
        $orig = $origStmt->get_result()->fetch_assoc();
        $origStmt->close();
        if ($orig && ($orig['preferred_date'] !== $preferredDate || dcvsTimeKey($orig['preferred_time']) !== dcvsTimeKey($preferredTime) || $orig['urgency_level'] !== $urgencyLevel)) {
            $slotAvailable = $urgencyLevel === 'urgent'
                ? dcvsUrgentSlotAvailable($conn, $preferredDate, $preferredTime)
                : dcvsSlotAvailable($conn, $preferredDate, $preferredTime);
            if (!$slotAvailable) {
                $errors[] = "This time is not available under the clinic schedule and program staffing.";
            }
            $dailyLimit = $urgencyLevel === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
            if (dcvsAppointmentDayCount($conn, $preferredDate, $appointmentId, $urgencyLevel) >= $dailyLimit) {
                $errors[] = $urgencyLevel === 'urgent' ? "This date has no remaining urgent appointments." : "This date has reached its routine appointment limit.";
            }
            $slotLimit = $urgencyLevel === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $preferredDate, $preferredTime);
            if (dcvsAppointmentSlotCount($conn, $preferredDate, $preferredTime, $appointmentId, $urgencyLevel) >= $slotLimit) {
                $errors[] = "This time slot has no remaining appointment capacity.";
            }
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
    $oldSlotStmt = $conn->prepare("SELECT appointment_type, preferred_date, preferred_time, urgency_level FROM appointments WHERE appointment_id = ? AND user_id = ?");
    $oldSlotStmt->bind_param('ii', $appointmentId, $_SESSION['user_id']);
    $oldSlotStmt->execute();
    $oldSlot = $oldSlotStmt->get_result()->fetch_assoc();
    $oldSlotStmt->close();
    $stmt = $conn->prepare("UPDATE appointments SET appointment_type = ?, urgency_level = ?, pet_id = ?, preferred_date = ?, preferred_time = ?, notes = ? WHERE appointment_id = ? AND user_id = ?");
    $stmt->bind_param("ssisssii", $appointmentType, $urgencyLevel, $petId, $preferredDate, $preferredTime, $notes, $appointmentId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        dcvsMarkWaitlistBooked($conn, (int)$_SESSION['user_id'], $appointmentId, $appointmentType, $urgencyLevel, $preferredDate, $preferredTime, $petId);
        if ($oldSlot && ($oldSlot['appointment_type'] !== $appointmentType || $oldSlot['preferred_date'] !== $preferredDate || dcvsTimeKey($oldSlot['preferred_time']) !== dcvsTimeKey($preferredTime) || ($oldSlot['urgency_level'] ?? 'routine') !== $urgencyLevel)) {
            dcvsNotifyWaitlistForSlot($conn, $oldSlot['preferred_date'], $oldSlot['preferred_time'], $oldSlot['appointment_type'], $oldSlot['urgency_level'] ?? 'routine');
        }
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
