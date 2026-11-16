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

function residentRescheduleResponse($status, $message, array $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $status < 400, 'message' => $message], $extra));
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    residentRescheduleResponse(403, 'Unauthorized.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') residentRescheduleResponse(405, 'Invalid request method.');
if (!validateCsrf($_POST['csrf_token'] ?? null)) residentRescheduleResponse(403, 'Your session could not be verified.');

$requestId = (int)($_POST['request_id'] ?? 0);
$response = (string)($_POST['response'] ?? '');
if ($requestId < 1 || !in_array($response, ['accept', 'decline'], true)) {
    residentRescheduleResponse(400, 'Choose whether to accept or decline the clinic’s alternate time.');
}
$residentId = (int)$_SESSION['user_id'];

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT r.appointment_id, r.offered_date, r.offered_time, r.offered_urgency,
            a.status AS appointment_status, a.user_id
        FROM appointment_reschedule_requests r
        JOIN appointments a ON a.appointment_id = r.appointment_id
        WHERE r.request_id = ? AND a.user_id = ? AND a.archived_at IS NULL
        FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Could not prepare the offer response.');
    $stmt->bind_param('ii', $requestId, $residentId);
    if (!$stmt->execute()) throw new RuntimeException('Could not load the offer.');
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$request || !in_array($request['appointment_status'], ['approved', 'scheduled', 'rescheduled'], true)) {
        $conn->rollback();
        residentRescheduleResponse(409, 'This appointment or offer is no longer active.');
    }
    $requestStmt = $conn->prepare("SELECT status FROM appointment_reschedule_requests WHERE request_id = ? FOR UPDATE");
    $requestStmt->bind_param('i', $requestId);
    if (!$requestStmt->execute()) throw new RuntimeException('Could not check the offer status.');
    $requestRow = $requestStmt->get_result()->fetch_row();
    $requestStatus = $requestRow[0] ?? null;
    $requestStmt->close();
    if ($requestStatus !== 'offered') {
        $conn->rollback();
        residentRescheduleResponse(409, 'This offer is no longer awaiting your reply.');
    }

    if ($response === 'accept') {
        dcvsLockAppointmentCapacityDates($conn, [$request['offered_date']]);
        $slot = dcvsRescheduleSlotAvailability(
            $conn,
            (int)$request['appointment_id'],
            $request['offered_date'],
            $request['offered_time'],
            $request['offered_urgency']
        );
        if (!$slot['available']) {
            $conn->rollback();
            residentRescheduleResponse(409, 'That suggested time is no longer available. Please contact DCVS.', ['slot_available' => false]);
        }
        $updateAppointment = $conn->prepare("UPDATE appointments
            SET original_schedule_date = COALESCE(original_schedule_date, scheduled_date, preferred_date),
                original_schedule_time = COALESCE(original_schedule_time, scheduled_time, preferred_time),
                scheduled_date = ?, scheduled_time = ?, urgency_level = ?, status = 'rescheduled'
            WHERE appointment_id = ? AND user_id = ? AND archived_at IS NULL");
        if (!$updateAppointment) throw new RuntimeException('Could not prepare the appointment update.');
        $updateAppointment->bind_param('sssii', $request['offered_date'], $request['offered_time'], $request['offered_urgency'], $request['appointment_id'], $residentId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            throw new RuntimeException('Could not apply the alternate appointment time.');
        }
        $updateAppointment->close();
        $status = 'approved';
        $notice = 'The resident accepted the alternate appointment time.';
    } else {
        $status = 'declined';
        $notice = 'The resident declined the alternate appointment time. The current appointment remains booked.';
    }
    $updateRequest = $conn->prepare("UPDATE appointment_reschedule_requests SET status = ?, responded_at = NOW()
        WHERE request_id = ? AND status = 'offered'");
    if (!$updateRequest) throw new RuntimeException('Could not prepare the offer response update.');
    $updateRequest->bind_param('si', $status, $requestId);
    if (!$updateRequest->execute() || $updateRequest->affected_rows !== 1) {
        throw new RuntimeException('The offer changed while you were responding.');
    }
    $updateRequest->close();
    $conn->commit();
    $staffUsers = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active' AND deleted_at IS NULL");
    if (!$staffUsers) {
        error_log('Could not notify staff of resident reschedule response: ' . $conn->error);
    } else {
        $notificationTitle = $response === 'accept' ? 'Resident accepted alternate appointment time' : 'Resident declined alternate appointment time';
        while ($staff = $staffUsers->fetch_assoc()) {
            createNotification(
                $conn,
                (int)$staff['user_id'],
                'appointment_update',
                $notificationTitle,
                $notice,
                'appointments',
                (int)$request['appointment_id']
            );
        }
        $staffUsers->free();
    }
    residentRescheduleResponse(200, $response === 'accept' ? 'You accepted the alternate time.' : 'You kept your current appointment.', ['status' => $status]);
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Resident reschedule response rollback failed: ' . $rollbackError->getMessage()); }
    error_log('Resident alternate-time response failed: ' . $error->getMessage());
    residentRescheduleResponse(500, 'Could not save your response. Please try again.');
}
