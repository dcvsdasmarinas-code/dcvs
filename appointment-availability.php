<?php
$sessionWhitelist = ['PHPSESSID', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION', 'DCVS_ADMIN_SESSION'];
$sessParam = isset($_GET['sess']) ? trim($_GET['sess']) : '';
if (in_array($sessParam, $sessionWhitelist, true)) {
    session_name($sessParam);
}
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/availability.php';

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
$stmt = $conn->prepare("SELECT preferred_date, preferred_time, COUNT(*) AS c FROM appointments
                        WHERE preferred_date >= ? AND preferred_date <= ?
                          AND status IN ('pending','approved','scheduled')
                          AND archived_at IS NULL
                        GROUP BY preferred_date, preferred_time");
$stmt->bind_param("ss", $firstDay, $lastDay);
$stmt->execute();
$result = $stmt->get_result();

$slots = [];
while ($row = $result->fetch_assoc()) {
    $time = substr($row['preferred_time'], 0, 5);
    $slots[$row['preferred_date']][$time] = (int)$row['c'];
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
$blocked = [];
for ($d = 1; $d <= (int)date('j', strtotime($lastDay)); $d++) {
    $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $blockedTimes = dcvsBlockedTimes($conn, $dateKey, $weeklyTemplate);
    if ($blockedTimes) $blocked[$dateKey] = array_fill_keys($blockedTimes, true);
}

// Dates that have explicit per-date override rows (custom schedule)
$ovrStmt = $conn->prepare("SELECT slot_date, slot_time, status FROM appointment_slots WHERE slot_date >= ? AND slot_date <= ? ORDER BY slot_date, slot_time");
$ovrStmt->bind_param("ss", $firstDay, $lastDay);
$ovrStmt->execute();
$ovrResult = $ovrStmt->get_result();
$overrides = [];
while ($row = $ovrResult->fetch_assoc()) {
    $overrides[$row['slot_date']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
}
$ovrStmt->close();

echo json_encode([
    'ok' => true,
    'year' => $year,
    'month' => $month,
    'slots' => $slots,
    'blocked' => $blocked,
    'held' => $held,
    'locked' => $locked,
    'overrides' => $overrides,
    'weekly' => $weeklyTemplate,
    'times' => DCVS_STANDARD_TIMES,
    'maxPerSlot' => 1,
    'maxPerDay' => 5,
]);
$conn->close();