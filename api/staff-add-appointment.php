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

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../sms-sender.php';
require_once __DIR__ . '/../includes/waitlist.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
    $urgencyLevel = $_POST['urgency_level'] ?? 'routine';
    $oldAppointment = null;
    if ($appointment_id) {
        $oldApptStmt = $conn->prepare("SELECT urgency_level, appointment_type, preferred_date, preferred_time, status FROM appointments WHERE appointment_id = ?");
        $oldApptStmt->bind_param('i', $appointment_id);
        $oldApptStmt->execute();
        $oldAppointment = $oldApptStmt->get_result()->fetch_assoc();
        $oldApptStmt->close();
    }
    $user_id = $_POST['user_id'] ?? null;
    $pet_id = !empty($_POST['pet_id']) ? (int)$_POST['pet_id'] : null;
    $appointment_type = $_POST['appointment_type'] ?? null;
    $waitlist_id = !empty($_POST['waitlist_id']) ? (int)$_POST['waitlist_id'] : 0;
    $preferred_date = $_POST['preferred_date'] ?? null;
    $preferred_time = $_POST['preferred_time'] ?? null;
    $status = $_POST['status'] ?? 'scheduled';
    $staffMessage = trim($_POST['notes'] ?? $_POST['staff_message'] ?? '');
    $returnSection = $_POST['return_section'] ?? '';
    
    // Quick validation
    if (!$user_id || !$appointment_type || !$preferred_date || !$preferred_time) {
        $_SESSION['appointment_error'] = "All fields are required.";
        header('Location: dashboard-staff.php');
        exit;
    }
    if (!in_array($appointment_type, ['adoption', 'kapon', 'checkup', 'deworming', 'vaccination'], true)
        || !in_array($urgencyLevel, ['routine', 'urgent'], true)
        || ($urgencyLevel === 'urgent' && !in_array($appointment_type, ['vaccination', 'checkup', 'deworming'], true))) {
        $_SESSION['appointment_error'] = "Select a valid appointment type and priority. Urgent appointments are available for Vaccination, Check-up, and Deworming only.";
        header('Location: dashboard-staff.php');
        exit;
    }
    if (!dcvsIsStandardTime($preferred_time)) {
        $_SESSION['appointment_error'] = "Please choose one of the available clinic appointment times.";
        header('Location: dashboard-staff.php');
        exit;
    }
    if ($waitlist_id > 0) {
        $waitlistStmt = $conn->prepare("SELECT appointment_type, urgency_level, preferred_date, preferred_time
            FROM appointment_waitlist
            WHERE waitlist_id = ? AND user_id = ? AND pet_id = ? AND status IN ('waiting','notified')
              AND (status = 'waiting' OR claim_expires_at > NOW())");
        if (!$waitlistStmt) {
            error_log('Failed to prepare staff waitlist booking validation: ' . $conn->error);
            $_SESSION['appointment_error'] = "Could not verify this waitlist entry. Please try again.";
            header('Location: dashboard-staff.php#appointments');
            exit;
        }
        $waitlistStmt->bind_param('iii', $waitlist_id, $user_id, $pet_id);
        if (!$waitlistStmt->execute()) {
            error_log('Failed to validate staff waitlist booking: ' . $waitlistStmt->error);
            $waitlistStmt->close();
            $_SESSION['appointment_error'] = "Could not verify this waitlist entry. Please try again.";
            header('Location: dashboard-staff.php#appointments');
            exit;
        }
        $waitlistEntry = $waitlistStmt->get_result()->fetch_assoc();
        $waitlistStmt->close();
        if (!$waitlistEntry || $waitlistEntry['appointment_type'] !== $appointment_type
            || $waitlistEntry['urgency_level'] !== $urgencyLevel || $waitlistEntry['preferred_date'] !== $preferred_date
            || ($waitlistEntry['preferred_time'] !== null && dcvsTimeKey($waitlistEntry['preferred_time']) !== dcvsTimeKey($preferred_time))) {
            $_SESSION['appointment_error'] = "This waitlist entry is no longer active or its appointment details have changed.";
            header('Location: dashboard-staff.php#appointments');
            exit;
        }
        $status = 'scheduled';
    }

    // Verify user exists and is a resident
    $checkUser = $conn->prepare("SELECT user_id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ? AND role = 'resident'");
    $checkUser->bind_param("i", $user_id);
    $checkUser->execute();
    $resident = $checkUser->get_result()->fetch_assoc();
    if (!$resident) {
        $_SESSION['appointment_error'] = "Invalid Resident ID.";
        header('Location: dashboard-staff.php');
        exit;
    }
    $checkUser->close();

    $pet_name = null;
    if ($pet_id) {
        $petStmt = $conn->prepare("SELECT pet_name FROM pets WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL");
        $petStmt->bind_param('ii', $pet_id, $user_id);
        $petStmt->execute();
        $pet = $petStmt->get_result()->fetch_assoc();
        $petStmt->close();
        if (!$pet) {
            $_SESSION['appointment_error'] = "The selected pet is not registered to this resident.";
            header('Location: dashboard-staff.php#appointments');
            exit;
        }
        $pet_name = $pet['pet_name'];
    }

    $capacityStatuses = ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress'];
    $reservesSlot = in_array($status, $capacityStatuses, true);

    // Slot capacity check
    $activeStatuses = "'pending','approved','scheduled'";
    if ($appointment_id) {
        $excludeClause = " AND appointment_id != ?";
    } else {
        $excludeClause = "";
    }
// Check duplicate for same resident
    if ($reservesSlot) {
        $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses,'in_progress') AND archived_at IS NULL" . $excludeClause);
        if ($appointment_id) { $dupStmt->bind_param("issi", $user_id, $preferred_date, $preferred_time, $appointment_id); }
        else { $dupStmt->bind_param("iss", $user_id, $preferred_date, $preferred_time); }
        $dupStmt->execute();
        if ($dupStmt->get_result()->fetch_row()[0] > 0) {
            $_SESSION['appointment_error'] = "This resident already has an appointment at this date and time.";
            $dupStmt->close(); header('Location: dashboard-staff.php'); exit;
        }
        $dupStmt->close();

        $dailyLimit = $urgencyLevel === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
        if (dcvsAppointmentDayCount($conn, $preferred_date, $appointment_id ?: 0, $urgencyLevel) >= $dailyLimit) {
            $_SESSION['appointment_error'] = "This date has reached its " . $urgencyLevel . " appointment limit.";
            header('Location: dashboard-staff.php'); exit;
        }
        $slotAvailable = $urgencyLevel === 'urgent'
            ? dcvsUrgentSlotAvailable($conn, $preferred_date, $preferred_time)
            : dcvsSlotAvailable($conn, $preferred_date, $preferred_time);
        if (!$slotAvailable) {
            $_SESSION['appointment_error'] = dcvsSlotUnavailableReason($conn, $preferred_date, $preferred_time);
            header('Location: dashboard-staff.php'); exit;
        }
        $slotCapacity = $urgencyLevel === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $preferred_date, $preferred_time);
        if (dcvsAppointmentSlotCount($conn, $preferred_date, $preferred_time, $appointment_id ?: 0, $urgencyLevel) >= $slotCapacity) {
            $_SESSION['appointment_error'] = "This time slot has no remaining " . $urgencyLevel . " capacity.";
            header('Location: dashboard-staff.php'); exit;
        }
    }
    
    if ($appointment_id) {
        // UPDATE existing appointment
        $stmt = $conn->prepare("UPDATE appointments SET user_id=?, appointment_type=?, urgency_level=?, pet_id=?, pet_name=?, preferred_date=?, preferred_time=?, status=?, notes=? WHERE appointment_id=?");
        $stmt->bind_param("ississsssi", $user_id, $appointment_type, $urgencyLevel, $pet_id, $pet_name, $preferred_date, $preferred_time, $status, $staffMessage, $appointment_id);
        $action = "Appointment Updated";
        $details = "Updated " . ucfirst($appointment_type) . " appointment for " . $resident['name']
            . ($pet_name ? " (pet: " . $pet_name . ")" : "")
            . " to status: $status.";
    } else {
        // INSERT new appointment
        $refId = generateReferenceId($conn, '#APT-', 'appointments');
        $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, urgency_level, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isissssss", $user_id, $appointment_type, $pet_id, $pet_name, $preferred_date, $preferred_time, $status, $urgencyLevel, $refId);
$action = "Appointment Scheduled";
        $details = "Scheduled " . ucfirst($appointment_type) . " appointment for " . $resident['name']
            . ($pet_name ? " (pet: " . $pet_name . ")" : "")
            . " on $preferred_date at " . date('g:i A', strtotime($preferred_time)) . ".";
    }
    
    if ($stmt->execute()) {
        dcvsMarkWaitlistBooked($conn, (int)$user_id, $appointment_id ?: (int)$stmt->insert_id, $appointment_type, $urgencyLevel, $preferred_date, $preferred_time, $pet_id, $waitlist_id ?: null);
        if ($oldAppointment && in_array($oldAppointment['status'], dcvsHeldStatuses(), true)
            && (!$reservesSlot || $oldAppointment['appointment_type'] !== $appointment_type
                || $oldAppointment['preferred_date'] !== $preferred_date
                || dcvsTimeKey($oldAppointment['preferred_time']) !== dcvsTimeKey($preferred_time)
                || ($oldAppointment['urgency_level'] ?? 'routine') !== $urgencyLevel)) {
            dcvsNotifyWaitlistForSlot($conn, $oldAppointment['preferred_date'], $oldAppointment['preferred_time'], $oldAppointment['appointment_type'], $oldAppointment['urgency_level'] ?? 'routine');
        }
        // Capture the new appointment id only AFTER a successful insert
        if (!$appointment_id) {
            $appointment_id = $stmt->insert_id;
        }
        // Notify resident if status changed (especially scheduled/completed)
        if (in_array($status, ['scheduled', 'completed', 'rescheduled'])) {
            $notifTitle = $status === 'completed' ? 'Adoption Completed' : 'Appointment ' . ucfirst($status);
            if ($appointment_type === 'adoption') {
                $notifTitle = $status === 'completed' ? 'Adoption Complete' : 'Adoption Appointment Scheduled';
            }
            $notifMsg = "Your $appointment_type appointment has been $status.";
            if ($status === 'scheduled') {
                $notifMsg = "Your $appointment_type appointment is scheduled on $preferred_date at $preferred_time.";
            }
            if (!empty($staffMessage)) {
                $notifMsg .= "\n\nStaff Message: $staffMessage";
            }
            createNotification($conn, $user_id, $appointment_type === 'adoption' ? 'adoption_scheduled' : 'appointment_update', $notifTitle, $notifMsg, 'appointments', $appointment_id);

        }

// Send email notification to resident
        if ($appointment_id) {
            $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
            sendAppointmentEmail($conn, $appointment_id, $status, $staffMessage, $staffName);
            sendAppointmentSms($conn, $appointment_id, $status, $staffName, $staffMessage);
        }

        $_SESSION['appointment_success'] = "Appointment saved successfully.";
        
        // Log activity
        $ip = $_SERVER['REMOTE_ADDR'];
        $staff_id = $_SESSION['user_id'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $staff_id, $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

    } else {
        $_SESSION['appointment_error'] = "Error saving appointment.";
    }
    $stmt->close();
}

header('Location: dashboard-staff.php' . ($returnSection === 'appointments' ? '#appointments' : ''));
exit;
