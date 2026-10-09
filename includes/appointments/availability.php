<?php
/**
 * Shared availability helpers.
 *
 * Availability = weekly template (availability_weekly) overridden per-date
 * (appointment_slots). A slot with no rule at all defaults to open.
 * Closed = stored 'unavailable'; open = stored 'available' (or absent).
 */

const DCVS_STANDARD_TIMES = ['09:00', '10:00', '11:00', '14:00', '15:00'];
const DCVS_MAX_APPOINTMENTS_PER_DAY = 3;
const DCVS_MAX_APPOINTMENTS_PER_SLOT = 1;
const DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY = 2;

function dcvsTimeKey($time) {
    return substr((string)$time, 0, 5);
}

function dcvsIsStandardTime($time) {
    return preg_match('/^\d{2}:\d{2}(?::00)?$/', (string)$time)
        && in_array(dcvsTimeKey($time), DCVS_STANDARD_TIMES, true);
}

/** Load the weekly template: day_of_week(1-7) => [ 'HH:MM' => bool available ] */
function dcvsWeeklyTemplate($conn) {
    $tpl = [];
    $r = $conn->query("SELECT day_of_week, slot_time, status FROM availability_weekly");
    if (!$r) {
        error_log('Failed to load weekly clinic availability: ' . $conn->error);
        throw new RuntimeException('Could not load clinic availability.');
    }
    while ($row = $r->fetch_assoc()) {
        $tpl[(int)$row['day_of_week']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
    }
    $r->free();
    return $tpl;
}

/** Load per-date overrides for one date: 'HH:MM' => bool available */
function dcvsDateOverrides($conn, $date) {
    $ov = [];
    $st = $conn->prepare("SELECT slot_time, status FROM appointment_slots WHERE slot_date = ?");
    if (!$st) {
        error_log('Failed to prepare date-specific availability lookup: ' . $conn->error);
        throw new RuntimeException('Could not load date-specific availability.');
    }
    $st->bind_param("s", $date);
    if (!$st->execute()) {
        error_log('Failed to query date-specific availability: ' . $st->error);
        $st->close();
        throw new RuntimeException('Could not load date-specific availability.');
    }
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $ov[substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
    }
    $st->close();
    return $ov;
}

/** Clinic closures and manual overrides apply to both routine and urgent visits. */
function dcvsClinicSlotAvailable($conn, $date, $time, $weeklyTemplate = null, $overrides = null) {
    $time = dcvsTimeKey($time);
    if ($weeklyTemplate === null) $weeklyTemplate = dcvsWeeklyTemplate($conn);
    if ($overrides === null) $overrides = dcvsDateOverrides($conn, $date);
    if (isset($overrides[$time])) return $overrides[$time];
    $dow = (int)date('N', strtotime($date)); // 1=Mon .. 7=Sun
    if (isset($weeklyTemplate[$dow][$time])) return $weeklyTemplate[$dow][$time];
    return true;
}

/** Convert program time values, including legacy display strings, to HH:MM bounds. */
function dcvsProgramTimeRange($program) {
    $start = substr((string)($program['program_start_time'] ?? ''), 0, 5);
    $end = substr((string)($program['program_end_time'] ?? ''), 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        preg_match_all('/(\d{1,2}:\d{2})\s*([AP]M)/i', (string)($program['operating_time'] ?? ''), $matches, PREG_SET_ORDER);
        if (count($matches) >= 2) {
            $startTime = DateTime::createFromFormat('g:i A', strtoupper($matches[0][1] . ' ' . $matches[0][2]));
            $endTime = DateTime::createFromFormat('g:i A', strtoupper($matches[1][1] . ' ' . $matches[1][2]));
            if ($startTime && $endTime) {
                $start = $startTime->format('H:i');
                $end = $endTime->format('H:i');
            }
        }
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        return ['09:00', '15:00'];
    }
    return [$start, $end];
}

/** Map program staffing reservations to each appointment slot in a date range. */
function dcvsProgramStaffingMap($conn, $startDate, $endDate, $excludeProgramId = 0, $additionalProgram = null) {
    $sql = "SELECT program_id, start_date, end_date, program_start_time, program_end_time, operating_time, staff_required_count
            FROM programs
            WHERE status IN ('scheduled','live') AND start_date <= ? AND end_date >= ?";
    if ($excludeProgramId > 0) $sql .= " AND program_id <> " . (int)$excludeProgramId;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare program staffing lookup: ' . $conn->error);
        throw new RuntimeException('Could not load program staffing requirements.');
    }
    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        error_log('Failed to query program staffing: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not load program staffing requirements.');
    }
    $result = $stmt->get_result();
    $programs = [];
    while ($row = $result->fetch_assoc()) $programs[] = $row;
    $stmt->close();

    if ($additionalProgram) $programs[] = $additionalProgram;
    $map = [];
    foreach ($programs as $program) {
        $programStart = max($startDate, (string)$program['start_date']);
        $programEnd = min($endDate, (string)$program['end_date']);
        if ($programStart > $programEnd) continue;
        list($timeStart, $timeEnd) = dcvsProgramTimeRange($program);
        $required = max(0, (int)($program['staff_required_count'] ?? 0));
        if ($required === 0) continue;
        for ($day = strtotime($programStart); $day <= strtotime($programEnd); $day = strtotime('+1 day', $day)) {
            $date = date('Y-m-d', $day);
            foreach (DCVS_STANDARD_TIMES as $slot) {
                $slotStart = strtotime($date . ' ' . $slot . ':00');
                $slotEnd = $slotStart + 3600;
                $programSlotStart = strtotime($date . ' ' . $timeStart . ':00');
                $programSlotEnd = strtotime($date . ' ' . $timeEnd . ':00');
                if ($programSlotStart < $slotEnd && $programSlotEnd > $slotStart) {
                    $map[$date][$slot] = ($map[$date][$slot] ?? 0) + $required;
                }
            }
        }
    }
    return $map;
}

function dcvsActiveStaffCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS staff_count FROM users WHERE role = 'staff' AND status = 'active' AND archived_at IS NULL");
    if (!$result) {
        error_log('Failed to count active staff for appointment capacity: ' . $conn->error);
        return 0;
    }
    $count = (int)$result->fetch_assoc()['staff_count'];
    $result->free();
    return $count;
}

/** Number of routine appointments that can be handled in one time slot. */
function dcvsRoutineSlotCapacity($conn, $date, $time, $staffCount = null, $programStaffing = null) {
    $time = dcvsTimeKey($time);
    if ($staffCount === null) $staffCount = dcvsActiveStaffCount($conn);
    if ($programStaffing === null) $programStaffing = dcvsProgramStaffingMap($conn, $date, $date);
    return max(0, (int)$staffCount - (int)($programStaffing[$date][$time] ?? 0));
}

/** Routine appointments follow program staffing; urgent appointments use separately reserved capacity. */
function dcvsSlotAvailable($conn, $date, $time, $weeklyTemplate = null, $overrides = null, $programClosed = null) {
    if ($programClosed === true) return false;
    if (!dcvsClinicSlotAvailable($conn, $date, $time, $weeklyTemplate, $overrides)) return false;
    return dcvsRoutineSlotCapacity($conn, $date, $time) > 0;
}

/** True when a routine or urgent request can use the clinic's regular operating schedule. */
function dcvsUrgentSlotAvailable($conn, $date, $time, $weeklyTemplate = null, $overrides = null) {
    return dcvsActiveStaffCount($conn) > 0
        && dcvsClinicSlotAvailable($conn, $date, $time, $weeklyTemplate, $overrides);
}

/** Explain why a requested appointment date/time cannot be scheduled. */
function dcvsSlotUnavailableReason($conn, $date, $time) {
    if (!dcvsClinicSlotAvailable($conn, $date, $time)) {
        $overrides = dcvsDateOverrides($conn, $date);
        $shortTime = substr($time, 0, 5);
        if (isset($overrides[$shortTime]) && !$overrides[$shortTime]) {
            return 'Cannot schedule: the selected ' . date('g:i A', strtotime($date . ' ' . $shortTime)) . ' slot is blocked for ' . date('F j, Y', strtotime($date)) . ' in the date-specific availability schedule.';
        }
        return 'Cannot schedule: ' . date('l', strtotime($date)) . ' is closed in the clinic weekly availability schedule.';
    }
    if (dcvsRoutineSlotCapacity($conn, $date, substr($time, 0, 5)) === 0) {
        return 'Cannot schedule a routine appointment: all clinic staff are assigned to a DCVS program during this time.';
    }

    return 'Cannot schedule: the selected date and time are not available in the clinic schedule.';
}

/** List of blocked 'HH:MM' times for a date. */
function dcvsBlockedTimes($conn, $date, $weeklyTemplate = null, $overrides = null) {
    if ($weeklyTemplate === null) $weeklyTemplate = dcvsWeeklyTemplate($conn);
    if ($overrides === null) $overrides = dcvsDateOverrides($conn, $date);
    $staffCount = dcvsActiveStaffCount($conn);
    $programStaffing = dcvsProgramStaffingMap($conn, $date, $date);
    $blocked = [];
    foreach (DCVS_STANDARD_TIMES as $t) {
        if (!dcvsClinicSlotAvailable($conn, $date, $t, $weeklyTemplate, $overrides)
            || max(0, $staffCount - (int)($programStaffing[$date][$t] ?? 0)) === 0) {
            $blocked[] = $t;
        }
    }
    return $blocked;
}

/** Statuses that hold a slot: a resident appointment occupying that date+time. */
function dcvsHeldStatuses() {
    return ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress'];
}

/** Appointment statuses that still reserve capacity for their scheduled date/time. */
function dcvsCapacityStatuses() {
    return dcvsHeldStatuses();
}

/** Count appointments consuming daily capacity, optionally excluding one appointment. */
function dcvsAppointmentDayCount($conn, $date, $excludeId = 0, $urgency = 'routine') {
    $statuses = implode("','", dcvsCapacityStatuses());
    $sql = "SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ('$statuses') AND archived_at IS NULL AND urgency_level = ?";
    if ($excludeId) $sql .= " AND appointment_id != ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare appointment day count: ' . $conn->error);
        throw new RuntimeException('Could not check appointment capacity.');
    }
    if ($excludeId) $stmt->bind_param('ssi', $date, $urgency, $excludeId);
    else $stmt->bind_param('ss', $date, $urgency);
    if (!$stmt->execute()) {
        error_log('Failed to count appointments by day: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not check appointment capacity.');
    }
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
}

/** Count appointments consuming one time slot, optionally excluding one appointment. */
function dcvsAppointmentSlotCount($conn, $date, $time, $excludeId = 0, $urgency = 'routine') {
    $time = dcvsTimeKey($time) . ':00';
    $statuses = implode("','", dcvsCapacityStatuses());
    $sql = "SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ('$statuses') AND archived_at IS NULL";
    if ($urgency !== 'all') $sql .= " AND urgency_level = ?";
    if ($excludeId) $sql .= " AND appointment_id != ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare appointment slot count: ' . $conn->error);
        throw new RuntimeException('Could not check appointment capacity.');
    }
    if ($urgency === 'all') {
        if ($excludeId) $stmt->bind_param('ssi', $date, $time, $excludeId);
        else $stmt->bind_param('ss', $date, $time);
    } elseif ($excludeId) {
        $stmt->bind_param('sssi', $date, $time, $urgency, $excludeId);
    } else {
        $stmt->bind_param('sss', $date, $time, $urgency);
    }
    if (!$stmt->execute()) {
        error_log('Failed to count appointments by slot: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not check appointment capacity.');
    }
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
}

/** True if a slot already has an active (non-closed) appointment on it.
 *  $excludeId skips one appointment id (used when editing/approving that request). */
function dcvsSlotHeld($conn, $date, $time, $excludeId = 0, $urgency = 'routine') {
    $count = dcvsAppointmentSlotCount($conn, $date, $time, $excludeId, $urgency);
    if ($urgency === 'all') return $count > 0;
    $capacity = $urgency === 'urgent'
        ? 1
        : dcvsRoutineSlotCapacity($conn, $date, $time);
    return $capacity === 0 || $count >= $capacity;
}

/** List of 'HH:MM' times on a date that are held by an active appointment. */
function dcvsHeldTimes($conn, $date) {
    $statuses = implode("','", dcvsHeldStatuses());
    $stmt = $conn->prepare("SELECT preferred_time FROM appointments
                       WHERE preferred_date = ? AND status IN ('$statuses') AND archived_at IS NULL AND urgency_level = 'routine'");
    if (!$stmt) return [];
    $stmt->bind_param('s', $date);
    if (!$stmt->execute()) {
        error_log('Failed to load held appointment times: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $r = $stmt->get_result();
    $held = [];
    $slotCounts = [];
    while ($row = $r->fetch_assoc()) {
        $time = substr($row['preferred_time'], 0, 5);
        $slotCounts[$time] = ($slotCounts[$time] ?? 0) + 1;
    }
    $stmt->close();
    foreach ($slotCounts as $time => $count) {
        if ($count >= dcvsRoutineSlotCapacity($conn, $date, $time)) $held[$time] = true;
    }
    return $held;
}