<?php
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reschedule-requests.php';

function rescheduleRequestResponse($status, $message, array $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $status < 400, 'message' => $message], $extra));
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    rescheduleRequestResponse(403, 'Unauthorized.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rescheduleRequestResponse(405, 'Invalid request method.');
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    rescheduleRequestResponse(403, 'Your session could not be verified. Refresh and try again.');
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$date = trim((string)($_POST['date'] ?? ''));
$time = trim((string)($_POST['time'] ?? ''));
$urgency = (string)($_POST['urgency'] ?? 'routine');
$reason = trim((string)($_POST['reason'] ?? ''));
if ($appointmentId < 1 || !dcvsValidateRescheduleInput($date, $time, $urgency) || strlen($reason) > 2000) {
    rescheduleRequestResponse(400, 'Choose a valid date and time. The optional reason must be 2,000 characters or fewer.');
}
if ($date < date('Y-m-d')) {
    rescheduleRequestResponse(400, 'Past dates cannot be requested. Choose today or a future date.');
}

$residentId = (int)$_SESSION['user_id'];
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT status, COALESCE(scheduled_date, preferred_date) AS appointment_date,
            COALESCE(scheduled_time, preferred_time) AS appointment_time,
            TIMESTAMP(COALESCE(scheduled_date, preferred_date), COALESCE(scheduled_time, preferred_time)) > DATE_ADD(NOW(), INTERVAL 24 HOUR) AS cutoff_open,
            cooldown.cooldown_until AS reschedule_cooldown_until,
            COALESCE(cooldown.cooldown_until > NOW(), 0) AS reschedule_cooldown_active
        FROM appointments a
        LEFT JOIN (
            SELECT approved.appointment_id,
                DATE_ADD(COALESCE(approved.responded_at, approved.reviewed_at, approved.requested_at), INTERVAL 24 HOUR) AS cooldown_until
            FROM appointment_reschedule_requests approved
            JOIN (
                SELECT appointment_id, MAX(request_id) AS request_id
                FROM appointment_reschedule_requests
                WHERE status = 'approved'
                GROUP BY appointment_id
            ) latest_approved ON latest_approved.request_id = approved.request_id
        ) cooldown ON cooldown.appointment_id = a.appointment_id
        WHERE a.appointment_id = ? AND a.user_id = ? AND a.archived_at IS NULL FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Could not prepare the appointment check: ' . $conn->error);
    $stmt->bind_param('ii', $appointmentId, $residentId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load the appointment.');
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appointment || !in_array($appointment['status'], ['approved', 'scheduled', 'rescheduled'], true)) {
        $conn->rollback();
        rescheduleRequestResponse(409, 'Only scheduled appointments can be rescheduled.');
    }
    if ((bool)$appointment['reschedule_cooldown_active']) {
        $conn->rollback();
        rescheduleRequestResponse(409, 'You must wait 24 hours after a reschedule is approved before requesting another change.', [
            'reschedule_cooldown_until' => $appointment['reschedule_cooldown_until'],
        ]);
    }
    if (!(bool)$appointment['cutoff_open']) {
        $conn->rollback();
        rescheduleRequestResponse(409, 'Appointments within 24 hours cannot be rescheduled online. Please contact DCVS.');
    }
    if ($appointment['appointment_date'] === $date && substr($appointment['appointment_time'], 0, 5) === substr($time, 0, 5)) {
        $conn->rollback();
        rescheduleRequestResponse(400, 'Choose a different date or time from your current appointment.');
    }
    $active = $conn->prepare("SELECT request_id FROM appointment_reschedule_requests
        WHERE appointment_id = ? AND status IN ('pending','offered') LIMIT 1");
    if (!$active) throw new RuntimeException('Could not check existing reschedule requests.');
    $active->bind_param('i', $appointmentId);
    if (!$active->execute()) throw new RuntimeException('Could not check existing reschedule requests.');
    $hasActive = $active->get_result()->num_rows > 0;
    $active->close();
    if ($hasActive) {
        $conn->rollback();
        rescheduleRequestResponse(409, 'There is already a reschedule request or staff offer awaiting a response.');
    }

    dcvsLockAppointmentCapacityDates($conn, [$date]);
    $slot = dcvsRescheduleSlotAvailability($conn, $appointmentId, $date, $time, $urgency);
    if (!$slot['available']) {
        $conn->rollback();
        rescheduleRequestResponse(409, 'That time was just taken or is no longer available. Please choose another.', ['slot_available' => false]);
    }
    $insert = $conn->prepare("INSERT INTO appointment_reschedule_requests
        (appointment_id, requested_date, requested_time, requested_urgency, resident_reason)
        VALUES (?, ?, ?, ?, ?)");
    if (!$insert) throw new RuntimeException('Could not prepare the reschedule request.');
    $insert->bind_param('issss', $appointmentId, $date, $time, $urgency, $reason);
    if (!$insert->execute()) throw new RuntimeException('Could not save the reschedule request.');
    $requestId = (int)$insert->insert_id;
    $insert->close();
    $conn->commit();

    $staffQuery = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active' AND deleted_at IS NULL");
    if ($staffQuery) {
        while ($staff = $staffQuery->fetch_assoc()) {
            createNotification(
                $conn,
                (int)$staff['user_id'],
                'appointment_update',
                'Reschedule request needs review',
                'A resident requested a new date and time. The requested slot is not reserved.',
                'appointments',
                $appointmentId
            );
        }
        $staffQuery->free();
    }

    rescheduleRequestResponse(200, 'Your reschedule request was sent. The requested time is not reserved.', [
        'request_id' => $requestId,
        'status' => 'pending',
        'requested_date' => $date,
        'requested_time' => substr($time, 0, 5),
        'requested_urgency' => $urgency,
    ]);
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Reschedule request rollback failed: ' . $rollbackError->getMessage()); }
    error_log('Resident reschedule request failed: ' . $error->getMessage());
    rescheduleRequestResponse(500, 'Could not submit the reschedule request. Please try again.');
}
