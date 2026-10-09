<?php
$sessionWhitelist = ['PHPSESSID', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION', 'DCVS_ADMIN_SESSION'];
$sessParam = isset($_GET['sess']) ? trim($_GET['sess']) : '';
if (in_array($sessParam, $sessionWhitelist, true)) {
    session_name($sessParam);
}
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode(['ok' => false, 'error' => 'Invalid month']);
    exit;
}

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));

// Aggregate booked counts per date + time slot for the requested month.
$capacityStatuses = "'" . implode("','", dcvsCapacityStatuses()) . "'";
$stmt = $conn->prepare("SELECT preferred_date, preferred_time, urgency_level, COUNT(*) AS c FROM appointments
                        WHERE preferred_date >= ? AND preferred_date <= ?
                                                    AND status IN ($capacityStatuses)
                          AND archived_at IS NULL
                        GROUP BY preferred_date, preferred_time, urgency_level");
$stmt->bind_param("ss", $firstDay, $lastDay);
$stmt->execute();
$result = $stmt->get_result();

$slots = [];
$routineSlots = [];
$urgentSlots = [];
while ($row = $result->fetch_assoc()) {
    $time = substr($row['preferred_time'], 0, 5);
    $date = $row['preferred_date'];
    $count = (int)$row['c'];
    $slots[$date][$time] = ($slots[$date][$time] ?? 0) + $count;
    if ($row['urgency_level'] === 'urgent') {
        $urgentSlots[$date][$time] = $count;
    } else {
        $routineSlots[$date][$time] = $count;
    }
}
$stmt->close();

// Held slots: any active appointment locks the slot against other residents.
$heldStmt = $conn->prepare("SELECT preferred_date, preferred_time FROM appointments
                            WHERE preferred_date >= ? AND preferred_date <= ?
                              AND status IN ('pending','approved','scheduled','rescheduled','in_progress')
                              AND archived_at IS NULL");
$heldStmt->bind_param("ss", $firstDay, $lastDay);
$heldStmt->execute();
$heldResult = $heldStmt->get_result();
$held = [];
while ($row = $heldResult->fetch_assoc()) {
    $held[$row['preferred_date']][substr($row['preferred_time'], 0, 5)] = true;
}
$heldStmt->close();

// Locked slots: only confirmed appointments (scheduled/rescheduled/approved) —
// these staff cannot toggle to unavailable in the Availability page.
$lockStmt = $conn->prepare("SELECT preferred_date, preferred_time FROM appointments
                            WHERE preferred_date >= ? AND preferred_date <= ?
                              AND status IN ('scheduled','rescheduled','approved')
                              AND archived_at IS NULL");
$lockStmt->bind_param("ss", $firstDay, $lastDay);
$lockStmt->execute();
$lockResult = $lockStmt->get_result();
$locked = [];
while ($row = $lockResult->fetch_assoc()) {
    $locked[$row['preferred_date']][substr($row['preferred_time'], 0, 5)] = true;
}
$lockStmt->close();

// Effective blocked slots: weekly template + per-date overrides
$weeklyTemplate = dcvsWeeklyTemplate($conn);
$ovrStmt = $conn->prepare("SELECT slot_date, slot_time, status FROM appointment_slots WHERE slot_date >= ? AND slot_date <= ? ORDER BY slot_date, slot_time");
$ovrStmt->bind_param("ss", $firstDay, $lastDay);
$ovrStmt->execute();
$ovrResult = $ovrStmt->get_result();
$overrides = [];
while ($row = $ovrResult->fetch_assoc()) {
    $overrides[$row['slot_date']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
}
$ovrStmt->close();

$staffCount = dcvsActiveStaffCount($conn);
$today = date('Y-m-d');
$availabilityStart = max($firstDay, $today);
$programStaffing = $availabilityStart <= $lastDay
    ? dcvsProgramStaffingMap($conn, $availabilityStart, $lastDay)
    : [];
$blocked = [];
$urgentBlocked = [];
$routineCapacity = [];
$urgentCapacity = [];
for ($d = 1; $d <= (int)date('j', strtotime($lastDay)); $d++) {
    $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
    if ($dateKey < $today) continue;
    foreach (DCVS_STANDARD_TIMES as $time) {
        $dateOverrides = $overrides[$dateKey] ?? [];
        $clinicOpen = dcvsClinicSlotAvailable($conn, $dateKey, $time, $weeklyTemplate, $dateOverrides);
        $routineCapacity[$dateKey][$time] = $clinicOpen
            ? max(0, $staffCount - (int)($programStaffing[$dateKey][$time] ?? 0))
            : 0;
        $urgentCapacity[$dateKey][$time] = $clinicOpen && $staffCount > 0 ? 1 : 0;
        if ($routineCapacity[$dateKey][$time] === 0) $blocked[$dateKey][$time] = true;
        if (!$clinicOpen) $urgentBlocked[$dateKey][$time] = true;
    }
}

$programStmt = $conn->prepare("SELECT p.program_id, p.program_name, p.program_type, p.description, p.start_date, p.end_date, p.program_start_time, p.program_end_time, p.operating_time, p.venue, p.location_mode,
        GROUP_CONCAT(DISTINCT b.barangay_name ORDER BY b.barangay_name SEPARATOR ', ') AS barangay_names
    FROM programs p
    LEFT JOIN program_barangays pb ON pb.program_id = p.program_id
    LEFT JOIN barangays b ON b.barangay_id = pb.barangay_id
    WHERE p.status IN ('scheduled','live') AND p.end_date >= ? AND p.start_date <= ?
      AND p.program_type IN ('vaccination','rabies_prevention')
    GROUP BY p.program_id ORDER BY p.start_date, p.program_name");
$programsByDate = [];
if ($programStmt) {
    $programStmt->bind_param('ss', $firstDay, $lastDay);
    if ($programStmt->execute()) {
        $programResult = $programStmt->get_result();
        while ($program = $programResult->fetch_assoc()) {
            list($programStartTime, $programEndTime) = dcvsProgramTimeRange($program);
            $from = max($availabilityStart, $program['start_date']);
            $to = min($lastDay, $program['end_date']);
            if ($from > $to) continue;
            for ($day = strtotime($from); $day <= strtotime($to); $day = strtotime('+1 day', $day)) {
                $date = date('Y-m-d', $day);
                $programsByDate[$date][] = [
                    'program_name' => $program['program_name'],
                    'program_type' => $program['program_type'],
                    'start_time' => $programStartTime,
                    'end_time' => $programEndTime,
                    'venue' => $program['venue'] ?? '',
                    'barangay_names' => $program['barangay_names'] ?? '',
                ];
            }
        }
    } else {
        error_log('Failed to load public vaccination programs: ' . $programStmt->error);
    }
    $programStmt->close();
}

echo json_encode([
    'ok' => true,
    'year' => $year,
    'month' => $month,
    'slots' => $slots,
    'routineSlots' => $routineSlots,
    'urgentSlots' => $urgentSlots,
    'blocked' => $blocked,
    'urgentBlocked' => $urgentBlocked,
    'routineCapacity' => $routineCapacity,
    'urgentCapacity' => $urgentCapacity,
    'programs' => $programsByDate,
    'held' => $held,
    'locked' => $locked,
    'overrides' => $overrides,
    'weekly' => $weeklyTemplate,
    'times' => DCVS_STANDARD_TIMES,
    'maxPerSlot' => DCVS_MAX_APPOINTMENTS_PER_SLOT,
    'maxPerDay' => DCVS_MAX_APPOINTMENTS_PER_DAY,
    'maxUrgentPerDay' => DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY,
]);
$conn->close();