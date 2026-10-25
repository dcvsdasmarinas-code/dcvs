<?php
session_name('DCVS_STAFF_SESSION');
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

function staffRescheduleResponse($status, $message, array $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $status < 400, 'message' => $message], $extra));
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    staffRescheduleResponse(403, 'Unauthorized.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') staffRescheduleResponse(405, 'Invalid request method.');
if (!validateCsrf($_POST['csrf_token'] ?? null)) staffRescheduleResponse(403, 'Your session could not be verified.');

$requestId = (int)($_POST['request_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$date = trim((string)($_POST['date'] ?? ''));
$time = trim((string)($_POST['time'] ?? ''));
$urgency = (string)($_POST['urgency'] ?? 'routine');
if ($requestId < 1 || !in_array($action, ['approve', 'suggest', 'decline'], true)
    || ($action === 'decline' && $reason === '')
    || strlen($reason) > 2000 || strlen($message) > 2000
    || ($action === 'suggest' && !dcvsValidateRescheduleInput($date, $time, $urgency))) {
    staffRescheduleResponse(400, 'Provide a valid action and any required reason or suggested appointment time.');
}

$staffId = (int)$_SESSION['user_id'];
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT r.*, a.user_id, a.status AS appointment_status,
            COALESCE(a.scheduled_date, a.preferred_date) AS appointment_date,
            COALESCE(a.scheduled_time, a.preferred_time) AS appointment_time,
            a.appointment_type
        FROM appointment_reschedule_requests r
        JOIN appointments a ON a.appointment_id = r.appointment_id
        WHERE r.request_id = ? AND a.archived_at IS NULL
        FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Could not prepare the reschedule review.');
    $stmt->bind_param('i', $requestId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load the reschedule request.');
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$request || $request['status'] !== 'pending'
        || !in_array($request['appointment_status'], ['approved', 'scheduled', 'rescheduled'], true)) {
        $conn->rollback();
        staffRescheduleResponse(409, 'This reschedule request is no longer active.');
    }
    $appointmentId = (int)$request['appointment_id'];
    $requestedDate = $request['requested_date'];
    $requestedTime = $request['requested_time'];
    $targetDate = $action === 'suggest' ? $date : $requestedDate;
    $targetTime = $action === 'suggest' ? $time : $requestedTime;
    $targetUrgency = $action === 'suggest' ? $urgency : $request['requested_urgency'];
    if ($action === 'suggest'
        && $targetDate === $request['appointment_date']
        && substr($targetTime, 0, 5) === substr($request['appointment_time'], 0, 5)) {
        $conn->rollback();
        staffRescheduleResponse(400, 'Suggest a different date or time from the resident’s current appointment.');
    }
    if ($action !== 'decline') {
        dcvsLockAppointmentCapacityDates($conn, [$targetDate]);
        $slot = dcvsRescheduleSlotAvailability($conn, $appointmentId, $targetDate, $targetTime, $targetUrgency);
        if (!$slot['available']) {
            $conn->rollback();
            staffRescheduleResponse(409, $action === 'approve'
                ? 'This slot is now full. Suggest another time or decline.'
                : 'That suggested time is not available. Choose another time.');
        }
    }

    if ($action === 'approve') {
        $updateAppointment = $conn->prepare("UPDATE appointments
            SET original_schedule_date = COALESCE(original_schedule_date, scheduled_date, preferred_date),
                original_schedule_time = COALESCE(original_schedule_time, scheduled_time, preferred_time),
                scheduled_date = ?, scheduled_time = ?, urgency_level = ?, status = 'scheduled'
            WHERE appointment_id = ? AND status IN ('approved','scheduled','rescheduled') AND archived_at IS NULL");
        if (!$updateAppointment) throw new RuntimeException('Could not prepare the appointment update.');
        $updateAppointment->bind_param('sssi', $targetDate, $targetTime, $targetUrgency, $appointmentId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            throw new RuntimeException('Could not apply the approved appointment change.');
        }
        $updateAppointment->close();
        $setStatus = 'approved';
        $reviewMessage = $message;
    } elseif ($action === 'suggest') {
        $setStatus = 'offered';
        $reviewMessage = $message;
    } else {
        $setStatus = 'declined';
        $reviewMessage = $reason;
    }
    if ($action === 'suggest') {
        $update = $conn->prepare("UPDATE appointment_reschedule_requests SET status = 'offered',
            offered_date = ?, offered_time = ?, offered_urgency = ?, staff_user_id = ?,
            staff_message = ?, reviewed_at = NOW()
            WHERE request_id = ? AND status IN ('pending','offered')");
        if (!$update) throw new RuntimeException('Could not prepare the alternate-time offer.');
        $update->bind_param('sssisi', $targetDate, $targetTime, $targetUrgency, $staffId, $reviewMessage, $requestId);
    } else {
        $update = $conn->prepare("UPDATE appointment_reschedule_requests SET status = ?,
            staff_user_id = ?, staff_message = ?, decline_reason = ?, reviewed_at = NOW()
            WHERE request_id = ? AND status IN ('pending','offered')");
        if (!$update) throw new RuntimeException('Could not prepare the reschedule decision.');
        $declineReason = $action === 'decline' ? $reason : null;
        $update->bind_param('sissi', $setStatus, $staffId, $reviewMessage, $declineReason, $requestId);
    }
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The reschedule request changed while being reviewed.');
    }
    $update->close();
    $conn->commit();

    if ($action === 'approve') {
        $noticeTitle = 'Reschedule request approved';
        $notice = 'Your reschedule request has been approved! Your appointment has been moved from '
            . date('F j, Y', strtotime($request['appointment_date'])) . ', at '
            . date('g:i A', strtotime($request['appointment_time'])) . ' to your requested schedule.';
    } elseif ($action === 'suggest') {
        $noticeTitle = 'Reply needed: alternate appointment time';
        $notice = 'The clinic suggests ' . date('F j, Y', strtotime($targetDate)) . ' at ' . date('g:i A', strtotime($targetTime)) . '. The clinic can’t do ' . date('F j, Y', strtotime($requestedDate)) . '. Your current appointment remains booked until you accept.';
    } else {
        $noticeTitle = 'Reschedule request declined';
        $notice = 'The clinic could not approve your request: ' . $reason . ' Your current appointment remains booked.';
    }
    createNotification($conn, (int)$request['user_id'], 'appointment_update', $noticeTitle, $notice, 'appointments', $appointmentId);
    staffRescheduleResponse(200, $noticeTitle, ['status' => $setStatus]);
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Staff reschedule rollback failed: ' . $rollbackError->getMessage()); }
    error_log('Staff reschedule review failed: ' . $error->getMessage());
    staffRescheduleResponse(500, 'Could not save the reschedule decision.');
}
