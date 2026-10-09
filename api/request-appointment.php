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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/waitlist.php';

function requestAppointmentRespond($success, $message, $section = '', $data = []) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $data));
        exit;
    }
    $_SESSION[$success ? 'appointment_success' : 'appointment_error'] = $message;
    header('Location: dashboard-resident.php' . $section);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    $appointmentType = $_POST['appt_type'] ?? '';
    $urgencyLevel = $_POST['urgency_level'] ?? 'routine';
    $petId = intval($_POST['pet'] ?? 0);
    $preferredDate = $_POST['appt_date'] ?? '';
    $preferredTime = $_POST['appt_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    // Server-side validation
    $errors = [];

    if (empty($appointmentType)) {
        $errors[] = "Appointment type is required.";
    } elseif (!in_array($appointmentType, ['adoption', 'kapon', 'vaccination', 'checkup', 'deworming'], true)) {
        $errors[] = "Please select a valid appointment type.";
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

    // Slot capacity check
    if (empty($errors) && !empty($preferredDate) && !empty($preferredTime)) {
        $preferredTime = dcvsTimeKey($preferredTime) . ':00';
        $existingStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.pet_id, a.created_at, a.status, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.notes, p.photo_url AS pet_photo
            FROM appointments a
            LEFT JOIN pets p ON p.pet_id = a.pet_id AND p.owner_id = a.user_id
            WHERE a.user_id = ? AND a.preferred_date = ? AND a.preferred_time = ?
              AND a.status IN ('pending','approved','scheduled','rescheduled','in_progress')
              AND a.archived_at IS NULL
            ORDER BY a.appointment_id DESC LIMIT 1");
        $existingStmt->bind_param("iss", $_SESSION['user_id'], $preferredDate, $preferredTime);
        $existingStmt->execute();
        $existingRequest = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if ($existingRequest) {
            if ((int)$existingRequest['pet_id'] === $petId && $existingRequest['appointment_type'] === $appointmentType && $existingRequest['urgency_level'] === $urgencyLevel) {
                requestAppointmentRespond(true, 'Appointment request already received.', $sec, [
                    'appointment_id' => (int)$existingRequest['appointment_id'],
                    'reference_id' => $existingRequest['reference_id'],
                    'pet_id' => (int)$existingRequest['pet_id'],
                    'created_at' => $existingRequest['created_at'],
                    'status' => $existingRequest['status'],
                    'appointment_type' => $existingRequest['appointment_type'],
                    'urgency_level' => $existingRequest['urgency_level'],
                    'preferred_date' => $existingRequest['preferred_date'],
                    'preferred_time' => $existingRequest['preferred_time'],
                    'notes' => $existingRequest['notes'],
                    'pet_photo' => $existingRequest['pet_photo'] ?? '',
                ]);
            }
            $errors[] = "You already have an appointment at this date and time.";
        }

        if (empty($errors)) {
            // Check staff-closed slot (weekly template + per-date overrides)
            $weeklyTemplate = dcvsWeeklyTemplate($conn);
            $clinicOpen = $urgencyLevel === 'urgent'
                ? dcvsUrgentSlotAvailable($conn, $preferredDate, $preferredTime, $weeklyTemplate)
                : dcvsSlotAvailable($conn, $preferredDate, $preferredTime, $weeklyTemplate);
            if (!$clinicOpen) {
                $errors[] = $urgencyLevel === 'urgent'
                    ? "This clinic time is closed. For urgent care, call (046) 432-1234 or visit the DCVS office."
                    : "No routine staff capacity is available for this time.";
            }
        }
        if (empty($errors)) {
            $dailyLimit = $urgencyLevel === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
            $dailyCount = dcvsAppointmentDayCount($conn, $preferredDate, 0, $urgencyLevel);
            if ($dailyCount >= $dailyLimit) {
                $errors[] = $urgencyLevel === 'urgent'
                    ? "The urgent appointment slots for this day are full. Call (046) 432-1234 or visit the DCVS office for urgent assistance."
                    : "This date has reached its routine appointment limit of " . DCVS_MAX_APPOINTMENTS_PER_DAY . ".";
            }
        }
        if (empty($errors) && dcvsSlotHeld($conn, $preferredDate, $preferredTime, 0, $urgencyLevel)) {
            $errors[] = $urgencyLevel === 'urgent'
                ? "This urgent time slot is already reserved. Please choose another time."
                : "This time slot is already at its routine capacity.";
        }
    }

    if (!empty($errors)) {
        requestAppointmentRespond(false, implode(' ', $errors), $sec);
    }

    // Check if pet belongs to user
    $checkPet = $conn->prepare("SELECT pet_id, pet_name, photo_url FROM pets WHERE pet_id = ? AND owner_id = ?");
    if (!$checkPet) {
        error_log('Failed to prepare resident appointment pet lookup: ' . $conn->error);
        requestAppointmentRespond(false, 'Database error. Please try again.', $sec);
    }
    $checkPet->bind_param("ii", $petId, $_SESSION['user_id']);
    if (!$checkPet->execute()) {
        error_log('Failed to execute resident appointment pet lookup: ' . $checkPet->error);
        $checkPet->close();
        requestAppointmentRespond(false, 'Database error. Please try again.', $sec);
    }
    $checkPet->store_result();
    if ($checkPet->num_rows === 0) {
        $checkPet->close();
        requestAppointmentRespond(false, 'Invalid pet selected.', $sec);
    }
    $checkPet->bind_result($verifiedPetId, $verifiedPetName, $verifiedPetPhoto);
    if (!$checkPet->fetch()) {
        error_log('Failed to fetch resident appointment pet lookup: ' . $checkPet->error);
        $checkPet->close();
        requestAppointmentRespond(false, 'Database error. Please try again.', $sec);
    }
    $petRow = ['pet_id' => $verifiedPetId, 'pet_name' => $verifiedPetName, 'photo_url' => $verifiedPetPhoto];
    $checkPet->close();

    // Generate reference ID
    $refId = generateReferenceId($conn, '#APT-', 'appointments');

    // Insert appointment into database
    error_log("Attempting to insert appointment into database");
    error_log("User ID: " . $_SESSION['user_id']);
    $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, preferred_date, preferred_time, notes, status, urgency_level, reference_id) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        requestAppointmentRespond(false, 'Database error. Please try again.', $sec);
    }
    $stmt->bind_param("isisssss", $_SESSION['user_id'], $appointmentType, $petId, $preferredDate, $preferredTime, $notes, $urgencyLevel, $refId);

    if ($stmt->execute()) {
        $apptId = $stmt->insert_id;
        dcvsMarkWaitlistBooked($conn, (int)$_SESSION['user_id'], $apptId, $appointmentType, $urgencyLevel, $preferredDate, $preferredTime, $petId);

        // Notify staff about any new appointment request
        $notifType = $appointmentType === 'adoption' ? 'adoption_appointment' : 'appointment_update';
        $notifTitle = $urgencyLevel === 'urgent'
            ? 'Urgent Appointment Request'
            : ($appointmentType === 'adoption' ? 'New Adoption Appointment Request' : 'New Appointment Request');
        $residentName = trim((string)($_SESSION['name'] ?? $_SESSION['full_name'] ?? ''));
        if ($residentName === '') $residentName = 'A resident';
        $notifMsg = $residentName . ' has requested a ' . $urgencyLevel . ' ' . $appointmentType . ' appointment.';

        $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        $staffStmt->execute();
        $staffResult = $staffStmt->get_result();
        while ($staff = $staffResult->fetch_assoc()) {
            createNotification($conn, $staff['user_id'], $notifType, $notifTitle, $notifMsg, 'appointments', $apptId);
        }
        $staffStmt->close();

        if ($appointmentType === 'adoption') {
            // Confirm to resident that their request was received
            createNotification($conn, $_SESSION['user_id'], 'appointment_update', 'Adoption Appointment Requested',
                'Your adoption appointment request has been submitted. Please wait for staff to schedule a pickup time.',
                'appointments', $apptId);

            $petNameForLog = $petRow['pet_name'] ?? 'the selected pet';
            $logDetails = 'Requested an adoption appointment for ' . $petNameForLog . '.';
            $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Adoption Appointment', ?)");
            $logStmt->bind_param("is", $_SESSION['user_id'], $logDetails);
            $logStmt->execute();
            $logStmt->close();
        } else {
            // Confirm to resident
            createNotification($conn, $_SESSION['user_id'], 'appointment_update', 'Appointment Requested',
                'Your ' . $appointmentType . ' appointment request has been submitted. Please wait for staff confirmation.',
                'appointments', $apptId);
        }

        requestAppointmentRespond(true, 'Appointment request submitted successfully!', $sec, [
            'appointment_id' => $apptId,
            'reference_id' => $refId,
            'pet_id' => $petId,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'pending',
            'appointment_type' => $appointmentType,
            'urgency_level' => $urgencyLevel,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'pet_photo' => $petRow['photo_url'] ?? '',
        ]);
    } else {
        requestAppointmentRespond(false, 'Failed to submit appointment request. Please try again.', $sec);
    }

    $stmt->close();
}

$conn->close();
?>
