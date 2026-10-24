<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/availability.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['available' => false, 'error' => 'Unauthorized']);
    exit;
}

$date = $_GET['date'] ?? '';
$rawTime = (string)($_GET['time'] ?? '');
$time = substr($rawTime, 0, 5);
$excludeId = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;
$urgency = $_GET['urgency'] ?? 'routine';
if (!in_array($urgency, ['routine', 'urgent'], true)) $urgency = 'routine';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))
    || ($rawTime !== '' && (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $rawTime) || !in_array($time, DCVS_STANDARD_TIMES, true)))) {
    http_response_code(400);
    echo json_encode(['available' => false, 'error' => 'A valid date and time are required.']);
    exit;
}

$weeklyTemplate = dcvsWeeklyTemplate($conn);
$firstDay = $date;
$lastDay = date('Y-m-d', strtotime($date . ' +90 days'));
$staffCount = dcvsActiveStaffCount($conn);
$counts = [];
$countStmt = $conn->prepare("SELECT COALESCE(scheduled_date, preferred_date) AS slot_date,
        COALESCE(scheduled_time, preferred_time) AS slot_time, urgency_level, COUNT(*) AS appointment_count
    FROM appointments
    WHERE COALESCE(scheduled_date, preferred_date) BETWEEN ? AND ?
      AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
      AND archived_at IS NULL" . ($excludeId ? " AND appointment_id != ?" : "") . "
    GROUP BY COALESCE(scheduled_date, preferred_date),
        COALESCE(scheduled_time, preferred_time), urgency_level");
if (!$countStmt) {
    error_log('Failed to prepare slot availability count: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['available' => false, 'error' => 'Could not check appointment availability.']);
    exit;
}
if ($excludeId) $countStmt->bind_param('ssi', $firstDay, $lastDay, $excludeId);
else $countStmt->bind_param('ss', $firstDay, $lastDay);
if (!$countStmt->execute()) {
    error_log('Failed to query slot availability counts: ' . $countStmt->error);
    $countStmt->close();
    http_response_code(500);
    echo json_encode(['available' => false, 'error' => 'Could not check appointment availability.']);
    exit;
}
$countResult = $countStmt->get_result();
$dayCounts = [];
while ($row = $countResult->fetch_assoc()) {
    $slotDate = $row['slot_date'];
    $slotTime = substr($row['slot_time'], 0, 5);
    $level = $row['urgency_level'] ?? 'routine';
    $count = (int)$row['appointment_count'];
    $counts[$slotDate][$slotTime][$level] = $count;
    $dayCounts[$slotDate][$level] = ($dayCounts[$slotDate][$level] ?? 0) + $count;
}
$countStmt->close();

$overrideStmt = $conn->prepare("SELECT slot_date, slot_time, status FROM appointment_slots WHERE slot_date BETWEEN ? AND ?");
if (!$overrideStmt) {
    error_log('Failed to prepare date availability lookup: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['available' => false, 'error' => 'Could not check clinic availability.']);
    exit;
}
$overrideStmt->bind_param('ss', $firstDay, $lastDay);
if (!$overrideStmt->execute()) {
    error_log('Failed to query date availability: ' . $overrideStmt->error);
    $overrideStmt->close();
    http_response_code(500);
    echo json_encode(['available' => false, 'error' => 'Could not check clinic availability.']);
    exit;
}
$overrideResult = $overrideStmt->get_result();
$overrides = [];
while ($row = $overrideResult->fetch_assoc()) {
    $overrides[$row['slot_date']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
}
$overrideStmt->close();

$maxPerDay = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
$slotCapacity = static function ($slotDate, $slotTime) use ($urgency, $staffCount) {
    if ($urgency === 'urgent') return $staffCount > 0 ? 1 : 0;
    return max(0, $staffCount);
};
$slotIsOpen = static function ($slotDate, $slotTime) use ($conn, $weeklyTemplate, $overrides, $slotCapacity, $counts, $dayCounts, $urgency, $maxPerDay) {
    if (!dcvsClinicSlotAvailable($conn, $slotDate, $slotTime, $weeklyTemplate, $overrides[$slotDate] ?? [])) return false;
    $capacity = $slotCapacity($slotDate, $slotTime);
    return $capacity > 0
        && (int)($counts[$slotDate][$slotTime][$urgency] ?? 0) < $capacity
        && (int)($dayCounts[$slotDate][$urgency] ?? 0) < $maxPerDay;
};

$clinicOpen = $time === '' || dcvsClinicSlotAvailable($conn, $date, $time, $weeklyTemplate, $overrides[$date] ?? []);
$capacity = $time === '' ? 0 : $slotCapacity($date, $time);
$slotCount = $time === '' ? 0 : (int)($counts[$date][$time][$urgency] ?? 0);
$dayCount = (int)($dayCounts[$date][$urgency] ?? 0);
$clinicBlocked = !$clinicOpen;
$programBlocked = false;
$blocked = $clinicBlocked || $programBlocked;
$held = $time !== '' && $capacity > 0 && $slotCount >= $capacity;
$available = $time === ''
    ? $clinicOpen
    : ($clinicOpen && $capacity > 0 && $slotCount < $capacity && $dayCount < $maxPerDay);

$nearestSlot = null;
if ($time !== '' && !$available) {
    $requestedTimestamp = strtotime($date . ' ' . $time);
    for ($offset = 0; $offset <= 90 && $nearestSlot === null; $offset++) {
        $candidateDate = date('Y-m-d', strtotime($date . ' +' . $offset . ' days'));
        foreach (DCVS_STANDARD_TIMES as $candidateTime) {
            if ($candidateDate === $date && strtotime($candidateDate . ' ' . $candidateTime) < $requestedTimestamp) continue;
            if ($slotIsOpen($candidateDate, $candidateTime)) {
                $nearestSlot = $candidateDate . ' ' . $candidateTime;
                break;
            }
        }
    }
}

echo json_encode([
    'available' => $available,
    'blocked' => $blocked,
    'clinic_blocked' => $clinicBlocked,
    'program_blocked' => $programBlocked,
    'held' => $held,
    'date_full' => $dayCount >= $maxPerDay,
    'slot_count' => $slotCount,
    'slot_capacity' => $capacity,
    'day_count' => $dayCount,
    'max_per_slot' => $capacity,
    'max_per_day' => $maxPerDay,
    'nearest_slot' => $nearestSlot,
    'urgency' => $urgency,
    'error' => null,
]);
