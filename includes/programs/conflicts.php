<?php
require_once __DIR__ . '/../appointments/availability.php';

/** Programs do not reserve appointment capacity, so they cannot create appointment conflicts. */
function dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, $excludeProgramId = 0, $startTime = '09:00', $endTime = '15:00') {
    return [];
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
