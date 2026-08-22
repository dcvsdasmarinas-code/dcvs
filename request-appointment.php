<?php
/**
 * Request Appointment Handler
 * Saves appointment requests to database
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

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    $appointmentType = $_POST['appt_type'] ?? '';
    $petId = intval($_POST['pet'] ?? 0);
    $preferredDate = $_POST['appt_date'] ?? '';
    $preferredTime = $_POST['appt_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    // Server-side validation
    $errors = [];

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

    // Slot capacity check
    if (empty($errors) && !empty($preferredDate) && !empty($preferredTime)) {
        // Check staff-closed slot (weekly template + per-date overrides)
        $weeklyTemplate = dcvsWeeklyTemplate($conn);
        if (!dcvsSlotAvailable($conn, $preferredDate, $preferredTime, $weeklyTemplate)) {
            $errors[] = "This time slot is closed by the clinic schedule.";
        }
        // Check the slot is not already held by another resident's active appointment
        if (empty($errors) && dcvsSlotHeld($conn, $preferredDate, $preferredTime)) {
            $errors[] = "This time slot is already reserved by another resident.";
        }
        // Check duplicate for same user
        if (empty($errors)) {
            $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ('pending','approved','scheduled','in_progress') AND archived_at IS NULL");
            $dupStmt->bind_param("iss", $_SESSION['user_id'], $preferredDate, $preferredTime);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_row()[0] > 0) { $errors[] = "You already have an appointment at this date and time."; }
            $dupStmt->close();
        }
        if (empty($errors)) {
            // Check daily capacity (max 5)
            $dStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL");
            $dStmt->bind_param("s", $preferredDate);
            $dStmt->execute();
            if ($dStmt->get_result()->fetch_row()[0] >= 5) { $errors[] = "This date is fully booked (max 5)."; }
            $dStmt->close();
        }
        if (empty($errors)) {
            // Check per-slot capacity (max 1)
            $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL");
            $cStmt->bind_param("ss", $preferredDate, $preferredTime);
            $cStmt->execute();
            if ($cStmt->get_result()->fetch_row()[0] >= 1) { $errors[] = "This time slot is already reserved (max 1)."; }
            $cStmt->close();
        }
    }

    if (!empty($errors)) {
        $_SESSION['appointment_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Check if pet belongs to user
    $checkPet = $conn->prepare("SELECT pet_id FROM pets WHERE pet_id = ? AND owner_id = ?");
    $checkPet->bind_param("ii", $petId, $_SESSION['user_id']);
    $checkPet->execute();
    $checkPet->store_result();

    if ($checkPet->num_rows === 0) {
        $_SESSION['appointment_error'] = "Invalid pet selected.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $checkPet->close();

    // Generate reference ID
    $refId = generateReferenceId($conn, '#APT-', 'appointments');

    // Insert appointment into database
    error_log("Attempting to insert appointment into database");
    error_log("User ID: " . $_SESSION['user_id']);
    $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, preferred_date, preferred_time, notes, status, reference_id) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['appointment_error'] = "Database error. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $stmt->bind_param("isissss", $_SESSION['user_id'], $appointmentType, $petId, $preferredDate, $preferredTime, $notes, $refId);

    if ($stmt->execute()) {
        $apptId = $stmt->insert_id;

        // Notify staff about any new appointment request
        $notifType = $appointmentType === 'adoption' ? 'adoption_appointment' : 'appointment_update';
        $notifTitle = $appointmentType === 'adoption' ? 'New Adoption Appointment Request' : 'New Appointment Request';
        $notifMsg = $_SESSION['name'] . ' has requested a ' . $appointmentType . ' appointment.';

        $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        $staffStmt->execute();
        $staffResult = $staffStmt->get_result();
        while ($staff = $staffResult->fetch_assoc()) {
            createNotification($conn, $staff['user_id'], $notifType, $notifTitle, $notifMsg, 'appointments', $apptId);
        }
        $staffStmt->close();

        // If adoption appointment, update adoption_listings status
        if ($appointmentType === 'adoption') {
            $alStmt = $conn->prepare("UPDATE adoption_listings SET status = 'pending' WHERE pet_id = ? AND listed_by = ?");
            $alStmt->bind_param("ii", $petId, $_SESSION['user_id']);
            $alStmt->execute();
            $alStmt->close();

            // Confirm to resident that their request was received
            createNotification($conn, $_SESSION['user_id'], 'appointment_update', 'Adoption Appointment Requested',
                'Your adoption appointment request has been submitted. Please wait for staff to schedule a pickup time.',
                'appointments', $apptId);

            $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Adoption Appointment', 'Requested adoption appointment for pet ID: $petId')");
            $logStmt->bind_param("i", $_SESSION['user_id']);
            $logStmt->execute();
            $logStmt->close();
        } else {
            // Confirm to resident
            createNotification($conn, $_SESSION['user_id'], 'appointment_update', 'Appointment Requested',
                'Your ' . $appointmentType . ' appointment request has been submitted. Please wait for staff confirmation.',
                'appointments', $apptId);
        }

        $_SESSION['appointment_success'] = "Appointment request submitted successfully!";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    } else {
        $_SESSION['appointment_error'] = "Failed to submit appointment request. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
