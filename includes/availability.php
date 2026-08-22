<?php
/**
 * Shared availability helpers.
 *
 * Availability = weekly template (availability_weekly) overridden per-date
 * (appointment_slots). A slot with no rule at all defaults to open.
 * Closed = stored 'unavailable'; open = stored 'available' (or absent).
 */

const DCVS_STANDARD_TIMES = ['09:00', '10:00', '11:00', '14:00', '15:00'];

/** Load the weekly template: day_of_week(1-7) => [ 'HH:MM' => bool available ] */
function dcvsWeeklyTemplate($conn) {
    $tpl = [];
    $r = $conn->query("SELECT day_of_week, slot_time, status FROM availability_weekly");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $tpl[(int)$row['day_of_week']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
        }
    }
    return $tpl;
}

/** Load per-date overrides for one date: 'HH:MM' => bool available */
function dcvsDateOverrides($conn, $date) {
    $ov = [];
    $st = $conn->prepare("SELECT slot_time, status FROM appointment_slots WHERE slot_date = ?");
    $st->bind_param("s", $date);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $ov[substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
    }
    $st->close();
    return $ov;
}

/** Effective availability for one date+time. Overrides win over template; default open. */
function dcvsSlotAvailable($conn, $date, $time, $weeklyTemplate = null, $overrides = null) {
    if ($weeklyTemplate === null) $weeklyTemplate = dcvsWeeklyTemplate($conn);
    if ($overrides === null) $overrides = dcvsDateOverrides($conn, $date);
    if (isset($overrides[$time])) return $overrides[$time];
    $dow = (int)date('N', strtotime($date)); // 1=Mon .. 7=Sun
    if (isset($weeklyTemplate[$dow][$time])) return $weeklyTemplate[$dow][$time];
    return true;
}

/** List of blocked 'HH:MM' times for a date. */
function dcvsBlockedTimes($conn, $date, $weeklyTemplate = null, $overrides = null) {
    $blocked = [];
    foreach (DCVS_STANDARD_TIMES as $t) {
        if (!dcvsSlotAvailable($conn, $date, $t, $weeklyTemplate, $overrides)) {
            $blocked[] = $t;
        }
    }
    return $blocked;
}

/** Statuses that hold a slot: a resident appointment occupying that date+time. */
function dcvsHeldStatuses() {
    return ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress'];
}

/** True if a slot already has an active (non-closed) appointment on it.
 *  $excludeId skips one appointment id (used when editing/approving that request). */
function dcvsSlotHeld($conn, $date, $time, $excludeId = 0) {
    $statuses = implode("','", dcvsHeldStatuses());
    $date = $conn->real_escape_string($date);
    $time = $conn->real_escape_string($time);
    $sql = "SELECT COUNT(*) c FROM appointments
            WHERE preferred_date = '$date' AND preferred_time = '$time' AND status IN ('$statuses') AND archived_at IS NULL";
    if ($excludeId) $sql .= " AND appointment_id != " . (int)$excludeId;
    $r = $conn->query($sql);
    $held = $r ? ((int)$r->fetch_assoc()['c'] > 0) : false;
    if ($r) $r->free();
    return $held;
}

/** List of 'HH:MM' times on a date that are held by an active appointment. */
function dcvsHeldTimes($conn, $date) {
    $statuses = implode("','", dcvsHeldStatuses());
    $date = $conn->real_escape_string($date);
    $r = $conn->query("SELECT preferred_time FROM appointments
                       WHERE preferred_date = '$date' AND status IN ('$statuses') AND archived_at IS NULL");
    $held = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $held[substr($row['preferred_time'], 0, 5)] = true;
        }
        $r->free();
    }
    return $held;
}