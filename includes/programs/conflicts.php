<?php
require_once __DIR__ . '/../appointments/availability.php';

/** Find active appointments that exceed the daily limits introduced by a new program day. */
function dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, $excludeProgramId = 0, $startTime = '09:00', $endTime = '15:00', $activateProgram = null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$startDate)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$endDate)
        || !checkdate((int)substr($startDate, 5, 2), (int)substr($startDate, 8, 2), (int)substr($startDate, 0, 4))
        || !checkdate((int)substr($endDate, 5, 2), (int)substr($endDate, 8, 2), (int)substr($endDate, 0, 4))
        || $startDate > $endDate) {
        return [];
    }

    $targetProgram = null;
    if ($excludeProgramId > 0) {
        $current = $conn->prepare("SELECT start_date, end_date, status FROM programs WHERE program_id = ?");
        if (!$current) throw new RuntimeException('Could not check the current program schedule.');
        $current->bind_param('i', $excludeProgramId);
        if (!$current->execute()) {
            $current->close();
            throw new RuntimeException('Could not check the current program schedule.');
        }
        $targetProgram = $current->get_result()->fetch_assoc();
        $current->close();
    }
    if ($activateProgram === null) {
        $activateProgram = $excludeProgramId === 0
            || ($targetProgram && in_array($targetProgram['status'], ['scheduled', 'live'], true));
    }

    $sql = "SELECT start_date, end_date FROM programs WHERE status IN ('scheduled', 'live')";
    if ($excludeProgramId > 0) $sql .= ' AND program_id <> ?';
    $programs = $conn->prepare($sql);
    if (!$programs) throw new RuntimeException('Could not check existing program dates.');
    if ($excludeProgramId > 0) $programs->bind_param('i', $excludeProgramId);
    if (!$programs->execute()) {
        $programs->close();
        throw new RuntimeException('Could not check existing program dates.');
    }
    $programRows = $programs->get_result();
    $otherProgramDays = [];
    $rangeStart = $startDate;
    $rangeEnd = $endDate;
    if ($targetProgram) {
        $rangeStart = min($rangeStart, $targetProgram['start_date']);
        $rangeEnd = max($rangeEnd, $targetProgram['end_date']);
    }
    while ($row = $programRows->fetch_assoc()) {
        $from = max($rangeStart, $row['start_date']);
        $to = min($rangeEnd, $row['end_date']);
        if ($from > $to) continue;
        for ($day = strtotime($from); $day <= strtotime($to); $day = strtotime('+1 day', $day)) {
            $otherProgramDays[date('Y-m-d', $day)] = true;
        }
    }
    $programs->close();

    $baselineProgramDays = $otherProgramDays;
    if ($targetProgram && in_array($targetProgram['status'], ['scheduled', 'live'], true)) {
        for ($day = strtotime($targetProgram['start_date']); $day <= strtotime($targetProgram['end_date']); $day = strtotime('+1 day', $day)) {
            $date = date('Y-m-d', $day);
            if ($date >= $rangeStart && $date <= $rangeEnd) $baselineProgramDays[$date] = true;
        }
    }
    $resultingProgramDays = $otherProgramDays;
    if ($activateProgram) {
        for ($day = strtotime($startDate); $day <= strtotime($endDate); $day = strtotime('+1 day', $day)) {
            $resultingProgramDays[date('Y-m-d', $day)] = true;
        }
    }
    $changedDays = [];
    for ($day = strtotime($rangeStart); $day <= strtotime($rangeEnd); $day = strtotime('+1 day', $day)) {
        $date = date('Y-m-d', $day);
        if (isset($baselineProgramDays[$date]) !== isset($resultingProgramDays[$date])) $changedDays[$date] = true;
    }
    if (!$changedDays) return [];

    $appointments = $conn->prepare("SELECT a.appointment_id, a.user_id, a.reference_id, a.appointment_type,
            COALESCE(a.urgency_level, 'routine') AS urgency_level, a.status,
            COALESCE(a.scheduled_date, a.preferred_date) AS schedule_date,
            COALESCE(a.scheduled_time, a.preferred_time) AS schedule_time,
            a.pet_name, u.full_name, a.created_at
        FROM appointments a
        JOIN users u ON u.user_id = a.user_id
        WHERE COALESCE(a.scheduled_date, a.preferred_date) BETWEEN ? AND ?
          AND a.status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
          AND a.archived_at IS NULL
        ORDER BY schedule_date,
            CASE WHEN a.status IN ('scheduled','rescheduled','approved','in_progress') THEN 0 ELSE 1 END,
            schedule_time, a.created_at, a.appointment_id");
    if (!$appointments) throw new RuntimeException('Could not check appointments affected by this program.');
    $appointments->bind_param('ss', $rangeStart, $rangeEnd);
    if (!$appointments->execute()) {
        $appointments->close();
        throw new RuntimeException('Could not check appointments affected by this program.');
    }

    $counts = [];
    $conflicts = [];
    $result = $appointments->get_result();
    while ($appointment = $result->fetch_assoc()) {
        $date = $appointment['schedule_date'];
        if (!isset($changedDays[$date])) continue;
        $urgency = $appointment['urgency_level'] === 'urgent' ? 'urgent' : 'routine';
        if (isset($resultingProgramDays[$date])) {
            $limit = $urgency === 'urgent'
                ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY
                : DCVS_MAX_ROUTINE_APPOINTMENTS_ON_PROGRAM_DAY;
            $counts[$date][$urgency] = $counts[$date][$urgency] ?? 0;
            if ($counts[$date][$urgency] >= $limit) $conflicts[] = $appointment;
            $counts[$date][$urgency]++;
        } else {
            $counts[$date]['all'] = $counts[$date]['all'] ?? 0;
            if ($urgency === 'urgent' || $counts[$date]['all'] >= DCVS_MAX_APPOINTMENTS_PER_DAY) {
                $conflicts[] = $appointment;
            }
            $counts[$date]['all']++;
        }
    }
    $appointments->close();
    return $conflicts;
}

/** Serialize a program activation change with appointment writes before checking its impact. */
function dcvsProgramCapacityTransitionConflicts($conn, $programId, $startDate, $endDate, $willBeActive) {
    dcvsLockAppointmentCapacityDates($conn, [$startDate, $endDate]);
    return dcvsProgramAppointmentConflicts(
        $conn,
        $startDate,
        $endDate,
        (int)$programId,
        '09:00',
        '15:00',
        (bool)$willBeActive
    );
}

/** Return reports in a proposed program's date range for staff awareness. */
function dcvsProgramReportWarnings($conn, $startDate, $endDate, $barangayIds = null) {
    if ($barangayIds !== null) {
        $barangayIds = array_values(array_unique(array_filter(array_map('intval', $barangayIds), static function ($id) {
            return $id > 0;
        })));
        if (!$barangayIds) return [];
    }
    $sql = "SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status, r.location_address, COALESCE(r.incident_at, r.created_at) AS incident_at, b.barangay_name
        FROM reports r LEFT JOIN barangays b ON b.barangay_id = r.barangay_id
        WHERE DATE(COALESCE(r.incident_at, r.created_at)) BETWEEN ? AND ? AND r.archived_at IS NULL";
    if ($barangayIds !== null) $sql .= ' AND r.barangay_id IN (' . implode(',', array_fill(0, count($barangayIds), '?')) . ')';
    $sql .= " ORDER BY FIELD(r.urgency_level, 'critical', 'high', 'medium', 'low'), r.incident_at DESC, r.created_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare program report warning lookup: ' . $conn->error);
        throw new RuntimeException('Could not check reports affected by this program.');
    }
    if ($barangayIds === null) $stmt->bind_param('ss', $startDate, $endDate);
    else $stmt->bind_param('ss' . str_repeat('i', count($barangayIds)), $startDate, $endDate, ...$barangayIds);
    if (!$stmt->execute()) {
        error_log('Failed to query program report warnings: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not check reports affected by this program.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
