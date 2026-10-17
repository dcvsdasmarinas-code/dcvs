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
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/waitlist.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'resident') {
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
$cancellationReason = trim((string)($_POST['cancellation_reason'] ?? ''));
if ($appointmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
    exit;
}
if (strlen($cancellationReason) > 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The cancellation reason must be 1000 characters or fewer.']);
    exit;
}

$residentId = (int)$_SESSION['user_id'];
$conn->begin_transaction();
$stmt = $conn->prepare("SELECT appointment_type, urgency_level, COALESCE(scheduled_date, preferred_date) AS slot_date,
    COALESCE(scheduled_time, preferred_time) AS slot_time, status,
    TIMESTAMP(COALESCE(scheduled_date, preferred_date), COALESCE(scheduled_time, preferred_time)) > DATE_ADD(NOW(), INTERVAL 24 HOUR) AS can_cancel
    FROM appointments WHERE appointment_id = ? AND user_id = ? AND archived_at IS NULL FOR UPDATE");
$stmt->bind_param('ii', $appointmentId, $residentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment || !in_array($appointment['status'], ['pending', 'approved', 'scheduled', 'rescheduled'], true)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This appointment can no longer be cancelled.']);
    exit;
}
if (in_array($appointment['status'], ['approved', 'scheduled', 'rescheduled'], true)
    && !(bool)$appointment['can_cancel']) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Appointments can only be cancelled at least 24 hours before the scheduled time. Please contact DCVS staff for help.']);
    exit;
}

$update = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancelled_by = 'resident', cancellation_reason = ?
    WHERE appointment_id = ? AND user_id = ? AND status IN ('pending','approved','scheduled','rescheduled')");
$update->bind_param('sii', $cancellationReason, $appointmentId, $residentId);
$ok = $update->execute() && $update->affected_rows === 1;
$update->close();
if (!$ok) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'The appointment could not be cancelled. Refresh and try again.']);
    exit;
}
$conn->commit();

if (in_array($appointment['status'], dcvsHeldStatuses(), true)) {
    dcvsNotifyWaitlistForSlot($conn, $appointment['slot_date'], $appointment['slot_time'], $appointment['appointment_type'], $appointment['urgency_level'] ?: 'routine');
}
$log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Appointment Cancelled by Resident', ?)");
$details = 'Resident cancelled appointment #' . $appointmentId . '.';
if ($cancellationReason !== '') {
    $details .= ' Reason: ' . $cancellationReason;
}
$log->bind_param('is', $residentId, $details);
$log->execute();
$log->close();

echo json_encode(['success' => true, 'message' => 'Appointment cancelled successfully.']);
