<?php
require_once __DIR__ . '/../appointments/availability.php';

/** Return active appointments that overlap a proposed program's date range. */
function dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, $excludeProgramId = 0, $startTime = '09:00', $endTime = '15:00', $staffRequired = 1) {
    $statuses = implode("','", dcvsHeldStatuses());
    $stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.urgency_level, a.created_at, u.user_id, u.full_name, u.email, u.phone
        FROM appointments a JOIN users u ON u.user_id = a.user_id
        WHERE a.preferred_date BETWEEN ? AND ? AND a.status IN ('$statuses') AND a.archived_at IS NULL
          AND a.urgency_level = 'routine'
        ORDER BY a.preferred_date, a.preferred_time, a.created_at, a.appointment_id");
    if (!$stmt) {
        error_log('Failed to prepare program appointment conflict lookup: ' . $conn->error);
        throw new RuntimeException('Could not check appointments affected by this program.');
    }
    $stmt->bind_param('ss', $startDate, $endDate);
    if (!$stmt->execute()) {
        error_log('Failed to query program appointment conflicts: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not check appointments affected by this program.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $program = [
        'program_id' => 0,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'program_start_time' => $startTime,
        'program_end_time' => $endTime,
        'staff_required_count' => $staffRequired,
    ];
    $staffing = dcvsProgramStaffingMap($conn, $startDate, $endDate, $excludeProgramId, $program);
    $staffCount = dcvsActiveStaffCount($conn);
    $bySlot = [];
    foreach ($rows as $row) $bySlot[$row['preferred_date']][substr($row['preferred_time'], 0, 5)][] = $row;

    $conflicts = [];
    foreach ($bySlot as $date => $times) {
        foreach ($times as $time => $appointments) {
            $capacity = max(0, $staffCount - (int)($staffing[$date][$time] ?? 0));
            if (count($appointments) > $capacity) {
                foreach (array_slice($appointments, $capacity) as $appointment) $conflicts[] = $appointment;
            }
        }
    }
    return $conflicts;
}

/** Return reports in a proposed program's date range for staff awareness. */
function dcvsProgramReportWarnings($conn, $startDate, $endDate) {
    $stmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status, r.location_address, COALESCE(r.incident_at, r.created_at) AS incident_at, b.barangay_name
        FROM reports r LEFT JOIN barangays b ON b.barangay_id = r.barangay_id
        WHERE DATE(COALESCE(r.incident_at, r.created_at)) BETWEEN ? AND ? AND r.archived_at IS NULL
        ORDER BY FIELD(r.urgency_level, 'critical', 'high', 'medium', 'low'), r.incident_at DESC, r.created_at DESC");
    if (!$stmt) {
        error_log('Failed to prepare program report warning lookup: ' . $conn->error);
        throw new RuntimeException('Could not check reports affected by this program.');
    }
    $stmt->bind_param('ss', $startDate, $endDate);
    if (!$stmt->execute()) {
        error_log('Failed to query program report warnings: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Could not check reports affected by this program.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
