<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/availability.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['available' => false, 'error' => 'Unauthorized']);
    exit;
}

$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';
$excludeId = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;

if (empty($date)) {
    echo json_encode(['available' => false, 'error' => 'Date required']);
    exit;
}

$maxPerSlot = 1;
$maxPerDay = 5;

// Staff-managed schedule: weekly template + per-date overrides
$weeklyTemplate = dcvsWeeklyTemplate($conn);
$blocked = false;
if ($time && !dcvsSlotAvailable($conn, $date, $time, $weeklyTemplate)) {
    $blocked = true;
}

// Held by an active appointment (any resident)
$held = $time ? dcvsSlotHeld($conn, $date, $time, $excludeId) : false;

// Per-slot count
$slotCount = 0;
$scStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL" . ($excludeId ? " AND appointment_id != ?" : ""));
if ($time) {
    if ($excludeId) { $scStmt->bind_param("ssi", $date, $time, $excludeId); }
    else { $scStmt->bind_param("ss", $date, $time); }
    $scStmt->execute();
    $slotCount = (int)$scStmt->get_result()->fetch_row()[0];
    $scStmt->close();
}

// Daily count
$dayCount = 0;
$dcStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL" . ($excludeId ? " AND appointment_id != ?" : ""));
if ($excludeId) { $dcStmt->bind_param("si", $date, $excludeId); }
else { $dcStmt->bind_param("s", $date); }
$dcStmt->execute();
$dayCount = (int)$dcStmt->get_result()->fetch_row()[0];
$dcStmt->close();

// Find nearest free slot if current is unavailable
$nearestSlot = null;
if ($time && ($blocked || $held || $slotCount >= $maxPerSlot || $dayCount >= $maxPerDay)) {
    foreach (DCVS_STANDARD_TIMES as $t) {
        if ($t === $time) continue;
        // Skip staff-closed slots (weekly template + per-date overrides)
        if (!dcvsSlotAvailable($conn, $date, $t, $weeklyTemplate)) continue;
        if (dcvsSlotHeld($conn, $date, $t, $excludeId)) continue;
        $tStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL");
        $tStmt->bind_param("ss", $date, $t);
        $tStmt->execute();
        $tCount = (int)$tStmt->get_result()->fetch_row()[0];
        $tStmt->close();
        if ($tCount < $maxPerSlot) {
            $nearestSlot = $t;
            break;
        }
    }
    // Try next day if no slot today
    if (!$nearestSlot) {
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $nextStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ('pending','approved','scheduled') AND archived_at IS NULL");
        $nextStmt->bind_param("s", $nextDate);
        $nextStmt->execute();
        $nextCount = (int)$nextStmt->get_result()->fetch_row()[0];
        $nextStmt->close();
        if ($nextCount < $maxPerDay) {
            $nearestSlot = $nextDate . ' 09:00';
        }
    }
}

echo json_encode([
    'available' => !$blocked && !$held && (!$time || ($slotCount < $maxPerSlot && $dayCount < $maxPerDay)),
    'blocked' => $blocked,
    'held' => $held,
    'slot_count' => $slotCount,
    'day_count' => $dayCount,
    'max_per_slot' => $maxPerSlot,
    'max_per_day' => $maxPerDay,
    'nearest_slot' => $nearestSlot,
    'error' => null
]);
