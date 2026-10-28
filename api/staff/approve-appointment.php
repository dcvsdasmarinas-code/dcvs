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
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/appointment-reschedule-offers.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

// JSON response for the sequential review dialog (ajax=1), session flash + redirect otherwise
function apptRespond($success, $message, $status = null) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$success, 'message' => $message, 'status' => $status]);
        exit;
    }
    $_SESSION[$success ? 'appointment_success' : 'appointment_error'] = $message;
    header('Location: dashboard-staff.php#appointments');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $staffMessage = trim($_POST['staff_message'] ?? '');

    if ($appointmentId === 0) {
        apptRespond(false, "Invalid appointment ID.");
    }

    $stmt = $conn->prepare("SELECT a.appointment_id, a.user_id, a.pet_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.notes, a.reference_id, u.full_name AS resident_name, p.pet_name FROM appointments a JOIN users u ON u.user_id = a.user_id LEFT JOIN pets p ON p.pet_id = a.pet_id WHERE a.appointment_id = ? AND a.archived_at IS NULL");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) {
        apptRespond(false, "Appointment request not found.");
    }

    $appointmentStatus = strtolower(trim((string)$appt['status']));
    if ($appointmentStatus !== 'pending') {
        apptRespond(false, "This request has already been processed (current status: " . $appt['status'] . ").");
    }

    $scheduledDate = trim($_POST['scheduled_date'] ?? $appt['preferred_date']);
    $scheduledTime = trim($_POST['scheduled_time'] ?? substr($appt['preferred_time'], 0, 5));
    $dateValue = DateTime::createFromFormat('!Y-m-d', $scheduledDate);
    $timeValue = DateTime::createFromFormat('!H:i', $scheduledTime);
    if (!$dateValue || $dateValue->format('Y-m-d') !== $scheduledDate
        || !$timeValue || $timeValue->format('H:i') !== $scheduledTime
        || !dcvsIsStandardTime($scheduledTime)
        || strtotime($scheduledDate . ' ' . $scheduledTime) <= time()) {
        apptRespond(false, 'Choose a valid future appointment date and an available standard time.');
    }

    $urgency = $appt['urgency_level'] ?: 'routine';
    try {
        dcvsLockAppointmentCapacityDates($conn, [$scheduledDate]);
    } catch (RuntimeException $error) {
        apptRespond(false, $error->getMessage());
    }
    $dailyCapacity = dcvsAppointmentDailyCapacity($conn, $scheduledDate, $urgency, $appointmentId);
    if (!$dailyCapacity['has_program'] && $urgency === 'urgent') {
        apptRespond(false, 'Urgent priority is only available on dates with a scheduled program.');
    }
    $slotIsAvailable = $urgency === 'urgent'
        ? dcvsUrgentSlotAvailable($conn, $scheduledDate, $scheduledTime)
        : dcvsSlotAvailable($conn, $scheduledDate, $scheduledTime);
    if (!$slotIsAvailable) {
        apptRespond(false, dcvsSlotUnavailableReason($conn, $scheduledDate, $scheduledTime));
    }

    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ?
        AND COALESCE(scheduled_date, preferred_date) = ?
        AND COALESCE(scheduled_time, preferred_time) = ?
        AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
        AND archived_at IS NULL AND appointment_id <> ?");
    $dupStmt->bind_param('issi', $appt['user_id'], $scheduledDate, $scheduledTime, $appointmentId);
    $dupStmt->execute();
    if ($dupStmt->get_result()->fetch_row()[0] > 0) {
        $dupStmt->close();
        apptRespond(false, "This resident already has another appointment at this date and time.");
    }
    $dupStmt->close();

    $dailyLimit = $dailyCapacity['limit'];
    $dailyCount = $dailyCapacity['count'];
    $slotCapacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $scheduledDate, $scheduledTime);
    $slotCount = dcvsAppointmentSlotCount($conn, $scheduledDate, $scheduledTime, $appointmentId, $dailyCapacity['has_program'] ? $urgency : 'all');
    $isFull = $dailyCount >= $dailyLimit || $slotCount >= $slotCapacity;

    if ($isFull && empty($appt['pet_id'])) {
        apptRespond(false, 'This request has no pet linked, so it cannot be moved to the pet-required waitlist. It remains pending.');
    }

    $requestedTime = dcvsTimeKey($appt['preferred_time']);
    $scheduleChanged = $scheduledDate !== $appt['preferred_date']
        || dcvsTimeKey($scheduledTime) !== $requestedTime;
    $scheduledTimeSql = $scheduledTime . ':00';
    if (!$isFull && $scheduleChanged) {
        $conn->begin_transaction();
        $appointmentForOffer = $appt;
        $appointmentForOffer['schedule_date'] = $appt['preferred_date'];
        $appointmentForOffer['schedule_time'] = $appt['preferred_time'];
        $offer = [
            $appointmentId => [
                'date' => $scheduledDate,
                'time' => dcvsTimeKey($scheduledTime),
                'urgency' => $urgency,
            ],
        ];
        try {
            $releasedSlots = dcvsCreateAppointmentRescheduleOffers($conn, [$appointmentForOffer], $offer);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Staff appointment reschedule offer failed: ' . $error->getMessage());
            apptRespond(false, 'Could not send the proposed time for resident confirmation. The request remains pending.');
        }

        foreach ($releasedSlots as $slot) {
            dcvsNotifyWaitlistForSlot($conn, $slot['date'], $slot['time'], $slot['appointment_type'], $slot['urgency_level']);
        }
        $action = 'Appointment Reschedule Proposed';
        $details = 'Proposed ' . ucfirst($appt['appointment_type']) . ' appointment for ' . $appt['resident_name']
            . (!empty($appt['pet_name']) ? ' (pet: ' . $appt['pet_name'] . ')' : '')
            . ' for ' . $scheduledDate . ' at ' . $scheduledTimeSql . '; awaiting resident response.';
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param('isss', $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        apptRespond(true, 'The proposed time was sent to the resident for confirmation.', 'action_needed');
    }

    $conn->begin_transaction();
    if ($isFull) {
        $existing = $conn->prepare("SELECT waitlist_id FROM appointment_waitlist
            WHERE user_id = ? AND pet_id = ? AND appointment_type = ? AND urgency_level = ?
              AND preferred_date = ? AND preferred_time = ? AND status IN ('waiting','notified')
            LIMIT 1");
        $existing->bind_param('iissss', $appt['user_id'], $appt['pet_id'], $appt['appointment_type'], $urgency, $scheduledDate, $scheduledTime);
        $existing->execute();
        $alreadyWaitlisted = $existing->get_result()->num_rows > 0;
        $existing->close();
        if (!$alreadyWaitlisted) {
            $waitlist = $conn->prepare("INSERT INTO appointment_waitlist
                (user_id, pet_id, appointment_type, urgency_level, preferred_date, preferred_time, status)
                VALUES (?, ?, ?, ?, ?, ?, 'waiting')");
            $waitlistTime = $scheduledTime . ':00';
            $waitlist->bind_param('iissss', $appt['user_id'], $appt['pet_id'], $appt['appointment_type'], $urgency, $scheduledDate, $waitlistTime);
            if (!$waitlist->execute()) {
                $waitlist->close();
                $conn->rollback();
                apptRespond(false, 'Could not add this appointment to the waitlist. The request remains pending.');
            }
            $waitlist->close();
        }
        $updStmt = $conn->prepare("UPDATE appointments SET status = 'waitlisted', scheduled_date = NULL, scheduled_time = NULL WHERE appointment_id = ? AND LOWER(TRIM(status)) = 'pending'");
        $updStmt->bind_param('i', $appointmentId);
        $updated = $updStmt->execute() && $updStmt->affected_rows > 0;
        $updStmt->close();
        if (!$updated) {
            $conn->rollback();
            apptRespond(false, 'Failed to move this request to the waitlist. It may have already been processed.');
        }
        $conn->commit();
        createNotification($conn, $appt['user_id'], 'appointment_update', 'Appointment Added to Waitlist',
            'Your requested appointment time is full. Your appointment request has been added to the waitlist.',
            'appointments', $appointmentId);
        logActivity($conn, $_SESSION['user_id'], 'Appointment Waitlisted',
            'Moved ' . ucfirst($appt['appointment_type']) . ' appointment for ' . $appt['resident_name'] . ' to the waitlist.',
            $_SERVER['REMOTE_ADDR']);
        apptRespond(true, 'The selected date or time is full. The appointment was moved to the waitlist.', 'waitlisted');
    }

    $updStmt = $conn->prepare("UPDATE appointments SET status = 'scheduled', scheduled_date = ?, scheduled_time = ?, cancelled_by = NULL WHERE appointment_id = ? AND LOWER(TRIM(status)) = 'pending'");
    $updStmt->bind_param('ssi', $scheduledDate, $scheduledTimeSql, $appointmentId);
    $updated = $updStmt->execute() && $updStmt->affected_rows > 0;
    $updStmt->close();
    if (!$updated) {
        $conn->rollback();
        apptRespond(false, 'Failed to schedule appointment. It may have already been processed.');
    }
    $conn->commit();

    $refId = $appt['reference_id'] ?? fallbackRefId('#APT-', $appointmentId);

    $notifTitle = $appt['appointment_type'] === 'adoption' ? 'Adoption Appointment Approved' : 'Appointment Approved';
    $notifType = $appt['appointment_type'] === 'adoption' ? 'adoption_scheduled' : 'appointment_update';
    $notifMsg = "Your " . $appt['appointment_type'] . " appointment has been approved and scheduled on "
        . date('F j, Y', strtotime($scheduledDate)) . " at " . date('g:i A', strtotime($scheduledTimeSql)) . ".";
    if (!empty($staffMessage)) $notifMsg .= "\n\nStaff Message: $staffMessage";
    createNotification($conn, $appt['user_id'], $notifType, $notifTitle, $notifMsg, 'appointments', $appointmentId);

    $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
    sendAppointmentEmail($conn, $appointmentId, 'scheduled', $staffMessage, $staffName);
    sendAppointmentSms($conn, $appointmentId, 'scheduled', $staffName, $staffMessage);

    $action = 'Appointment Approved';
    $details = "Approved " . ucfirst($appt['appointment_type']) . " appointment for " . $appt['resident_name']
        . (!empty($appt['pet_name']) ? " (pet: " . $appt['pet_name'] . ")" : "")
        . " for " . $scheduledDate . " at " . $scheduledTimeSql . ".";
    $ip = $_SERVER['REMOTE_ADDR'];
    $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $logStmt->bind_param('isss', $_SESSION['user_id'], $action, $details, $ip);
    $logStmt->execute();
    $logStmt->close();

    apptRespond(true, 'Appointment request scheduled.', 'scheduled');
}

$conn->close();
header('Location: dashboard-staff.php#appointments');
exit;
