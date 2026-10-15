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
require_once __DIR__ . '/../../includes/availability.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$date = trim($_GET['date'] ?? '');
$time = trim($_GET['time'] ?? '');
$dateValue = DateTime::createFromFormat('!Y-m-d', $date);
$timeValue = DateTime::createFromFormat('!H:i', $time);

if ($appointmentId <= 0 || !$dateValue || $dateValue->format('Y-m-d') !== $date
    || !$timeValue || $timeValue->format('H:i') !== $time) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid appointment date and time.']);
    exit;
}

$stmt = $conn->prepare("SELECT user_id, urgency_level, status, pet_id FROM appointments WHERE appointment_id = ? AND archived_at IS NULL");
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment || $appointment['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This appointment is no longer pending.']);
    exit;
}

$urgency = $appointment['urgency_level'] ?: 'routine';
$dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
$dailyCount = dcvsAppointmentDayCount($conn, $date, $appointmentId, $urgency);
$slotCount = dcvsAppointmentSlotCount($conn, $date, $time, $appointmentId, $urgency);
$slotCapacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $time);
$slotIsOpen = dcvsIsStandardTime($time)
    && ($urgency === 'urgent'
        ? dcvsUrgentSlotAvailable($conn, $date, $time)
        : dcvsSlotAvailable($conn, $date, $time));

$duplicate = $conn->prepare("SELECT COUNT(*) FROM appointments
    WHERE user_id = ? AND COALESCE(scheduled_date, preferred_date) = ?
      AND COALESCE(scheduled_time, preferred_time) = ?
      AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
      AND archived_at IS NULL AND appointment_id <> ?");
$duplicate->bind_param('issi', $appointment['user_id'], $date, $time, $appointmentId);
$duplicate->execute();
$hasConflict = (int)$duplicate->get_result()->fetch_row()[0] > 0;
$duplicate->close();

$isPast = strtotime($date . ' ' . $time) <= time();
$isFull = $dailyCount >= $dailyLimit || $slotCount >= $slotCapacity;
$canSchedule = !$isPast && $slotIsOpen && !$hasConflict && !$isFull;

echo json_encode([
    'success' => true,
    'urgency' => $urgency,
    'daily_count' => $dailyCount,
    'daily_limit' => $dailyLimit,
    'slot_count' => $slotCount,
    'slot_capacity' => $slotCapacity,
    'slot_open' => $slotIsOpen,
    'full' => $isFull,
    'conflict' => $hasConflict,
    'past' => $isPast,
    'can_schedule' => $canSchedule,
    'has_pet' => !empty($appointment['pet_id']),
]);
