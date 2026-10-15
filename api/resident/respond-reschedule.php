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
require_once __DIR__ . '/../../includes/appointment-reschedule-offers.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'resident') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
    exit;
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$response = (string)($_POST['response'] ?? '');
if ($appointmentId <= 0 || !in_array($response, ['accept', 'decline'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose whether to accept or decline the proposed appointment time.']);
    exit;
}

$residentId = (int)$_SESSION['user_id'];
dcvsExpireAppointmentRescheduleOffers($conn);
$conn->begin_transaction();
$stmt = $conn->prepare("SELECT appointment_type, urgency_level, original_schedule_date, original_schedule_time,
    scheduled_date, scheduled_time, status, reschedule_response_deadline,
    reschedule_response_deadline > NOW() AS response_open
    FROM appointments
    WHERE appointment_id = ? AND user_id = ? AND archived_at IS NULL
    FOR UPDATE");
$stmt->bind_param('ii', $appointmentId, $residentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment || $appointment['status'] !== 'action_needed'
    || !(bool)$appointment['response_open']) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This reschedule offer is no longer awaiting a response. Refresh your appointments.']);
    exit;
}

if ($response === 'accept') {
    if (dcvsSlotHeld($conn, $appointment['scheduled_date'], $appointment['scheduled_time'], $appointmentId, $appointment['urgency_level'] ?: 'routine')
        || dcvsAppointmentDayCount($conn, $appointment['scheduled_date'], $appointmentId, $appointment['urgency_level'] ?: 'routine')
            >= (($appointment['urgency_level'] ?? 'routine') === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY)) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That proposed time is no longer available. Please contact DCVS staff for help.']);
        exit;
    }
    $update = $conn->prepare("UPDATE appointments SET status = 'rescheduled',
        original_schedule_date = NULL, original_schedule_time = NULL,
        reschedule_responded_at = NOW(), reschedule_response_deadline = NULL
        WHERE appointment_id = ? AND user_id = ? AND status = 'action_needed'
          AND reschedule_response_deadline > NOW()");
    $update->bind_param('ii', $appointmentId, $residentId);
    $successMessage = 'You accepted the new appointment time.';
} else {
    $update = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancelled_by = 'resident',
        cancellation_reason = 'Resident declined the proposed appointment time.',
        reschedule_responded_at = NOW(), reschedule_response_deadline = NULL
        WHERE appointment_id = ? AND user_id = ? AND status = 'action_needed'
          AND reschedule_response_deadline > NOW()");
    $update->bind_param('ii', $appointmentId, $residentId);
    $successMessage = 'You declined the proposed time. The appointment has been closed.';
}

$ok = $update->execute() && $update->affected_rows === 1;
$update->close();
if (!$ok) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'The response could not be saved. Refresh and try again.']);
    exit;
}
$conn->commit();

if ($response === 'decline') {
    dcvsNotifyWaitlistForSlot($conn, $appointment['scheduled_date'], $appointment['scheduled_time'], $appointment['appointment_type'], $appointment['urgency_level'] ?: 'routine');
}
echo json_encode(['success' => true, 'status' => $response === 'accept' ? 'rescheduled' : 'cancelled', 'message' => $successMessage]);
