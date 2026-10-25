<?php
session_name('DCVS_STAFF_SESSION');
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reschedule-requests.php';

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['available' => false, 'message' => 'Unauthorized.']);
    exit;
}

$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$date = trim((string)($_GET['date'] ?? ''));
$time = trim((string)($_GET['time'] ?? ''));
$urgency = (string)($_GET['urgency'] ?? 'routine');
if ($appointmentId < 1 || !dcvsValidateRescheduleInput($date, $time, $urgency)) {
    http_response_code(400);
    echo json_encode(['available' => false, 'message' => 'Provide a valid date, time, and priority.']);
    exit;
}

try {
    $availability = dcvsRescheduleSlotAvailability($conn, $appointmentId, $date, $time, $urgency);
    echo json_encode([
        'available' => $availability['available'],
        'program_day' => $availability['program_day'],
        'daily_count' => $availability['daily_count'],
        'daily_limit' => $availability['daily_limit'],
        'slot_count' => $availability['slot_count'],
        'slot_limit' => $availability['slot_limit'],
        'message' => $availability['available']
            ? 'Requested slot is available.'
            : 'This slot is now full. Suggest another time or decline.',
    ]);
} catch (Throwable $error) {
    error_log('Staff reschedule availability check failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['available' => false, 'message' => 'Could not check appointment availability.']);
}
