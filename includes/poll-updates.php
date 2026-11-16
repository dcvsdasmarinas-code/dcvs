<?php
/**
 * Real-time poll endpoint — returns only data changed since lastPoll.
 * Called every 5-8 seconds from dashboard JS.
 */

// Open the same session the requesting portal uses (staff/barangay/admin use
// custom names; resident uses the default PHPSESSID). Falling back to PHPSESSID
// prevents a staff poll from silently reading a resident session.
$sessionWhitelist = ['PHPSESSID', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION', 'DCVS_ADMIN_SESSION'];
$sessParam = isset($_GET['sess']) ? trim($_GET['sess']) : '';
if (in_array($sessParam, $sessionWhitelist, true)) {
    session_name($sessParam);
}
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$role     = $_SESSION['role'] ?? 'resident';
$residentReportOverdueSlaSettings = null;
if ($role === 'resident') {
    require_once __DIR__ . '/../includes/reports/deadlines.php';
    require_once __DIR__ . '/../includes/reports/resident-presentation.php';
}
$reportDuplicateVisibility = $role === 'staff'
    ? "NOT IN ('merged', 'suspected')"
    : "<> 'merged'";
$userId   = (int)$_SESSION['user_id'];
$lastPoll = isset($_GET['since']) ? $_GET['since'] : date('Y-m-d H:i:s', time() - 30);
$barangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : null;

// Always respond
$response = [
    'server_time' => date('Y-m-d H:i:s'),
    'notifications' => [],
    'notif_unread_count' => 0,
    'appointments' => [],
    'reports' => [],
    'pets' => [],
    'activities' => [],
    'counts' => [],
];

// ── 1. Notifications (every role) ──
$nStmt = $conn->prepare("SELECT n.notification_id, n.type, n.title, n.message, n.reference_type, n.reference_id, n.is_read, n.created_at,
        activity_user.full_name AS activity_user_name, activity_user.avatar_url AS activity_avatar_url
    FROM notifications n
    LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
    LEFT JOIN appointments a ON n.reference_type = 'appointments' AND n.reference_id = a.appointment_id
    LEFT JOIN pets p ON n.reference_type = 'pets' AND n.reference_id = p.pet_id
    LEFT JOIN users activity_user ON activity_user.user_id = CASE
        WHEN n.reference_type = 'report' AND n.type = 'report_validated' THEN r.validated_by
        WHEN n.reference_type = 'report' THEN r.reporter_id
        WHEN n.reference_type = 'appointments' THEN a.user_id
        WHEN n.reference_type = 'pets' THEN p.owner_id
        WHEN n.reference_type = 'staff_message' THEN n.reference_id
        ELSE NULL
    END
    WHERE n.user_id = ? AND n.created_at > ? AND n.archived_at IS NULL
    ORDER BY n.created_at DESC LIMIT 20");
$nStmt->bind_param("is", $userId, $lastPoll);
$nStmt->execute();
$nr = $nStmt->get_result();
while ($row = $nr->fetch_assoc()) {
    $response['notifications'][] = $row;
}
$nStmt->close();

// Unread badge count — matches what each portal's dropdown actually shows.
if ($role === 'resident') {
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'staff_message' AND is_read = 0 AND archived_at IS NULL");
    $ucStmt->bind_param("i", $userId);
} else {
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications n
        WHERE n.user_id = ? AND n.is_read = 0 AND n.archived_at IS NULL");
    $ucStmt->bind_param("i", $userId);
}
$ucStmt->execute();
$response['notif_unread_count'] = (int)$ucStmt->get_result()->fetch_row()[0];
$ucStmt->close();

// ── 2. Appointments ──
if ($role === 'resident') {
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.pet_id, a.notes, a.created_at,
        COALESCE(p.pet_name, '—') AS pet_name
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        WHERE a.user_id = ? AND a.archived_at IS NULL
        AND a.updated_at > ? ORDER BY a.appointment_id ASC");
    $aStmt->bind_param("is", $userId, $lastPoll);
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
} elseif ($role === 'staff' || $role === 'admin') {
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.created_at, a.updated_at, a.notes,
        u.user_id, u.reference_id AS user_ref, u.full_name, u.email, u.phone, u.address, u.avatar_url, p.pet_name, b.barangay_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE a.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
          AND a.updated_at > ? ORDER BY a.appointment_id ASC");
    $aStmt->bind_param("s", $lastPoll);
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
} elseif ($role === 'barangay') {
    $barWhere = $barangayId ? "AND (r.barangay_id = ? OR r.barangay_id IS NULL)" : "";
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.created_at,
        u.full_name, p.pet_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN reports r ON r.reporter_id = a.user_id
        WHERE a.archived_at IS NULL AND a.updated_at > ? $barWhere ORDER BY a.appointment_id ASC");
    if ($barangayId) { $aStmt->bind_param("si", $lastPoll, $barangayId); }
    else { $aStmt->bind_param("s", $lastPoll); }
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
}

// ── 3. Reports ──
if ($role === 'resident') {
    $rStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.location_address,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_report.status ELSE r.status END AS status,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_report.duplicate_status ELSE r.duplicate_status END AS duplicate_status,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_report.scheduled_at ELSE r.scheduled_at END AS scheduled_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_case.status ELSE cc.status END AS case_status,
            COALESCE(primary_barangay.barangay_name, report_barangay.barangay_name) AS barangay_name,
            r.urgency_level, r.incident_at, r.created_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN GREATEST(r.updated_at, primary_report.updated_at) ELSE r.updated_at END AS updated_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.response_mode ELSE rw.response_mode END AS response_mode,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.state ELSE rw.state END AS response_state,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.response_deadline ELSE rw.response_deadline END AS response_deadline,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.progress_deadline ELSE rw.progress_deadline END AS progress_deadline,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.turnover_deadline ELSE rw.turnover_deadline END AS turnover_deadline,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.escalated_at ELSE rw.escalated_at END AS escalated_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.assigned_at ELSE rw.assigned_at END AS assigned_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_workflow.holding_location ELSE rw.holding_location END AS holding_location,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_resolution.resolution_details ELSE rr.resolution_details END AS resolution_details,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_resolution.animal_disposition ELSE rr.animal_disposition END AS animal_disposition,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_resolution.turnover_required ELSE rr.turnover_required END AS turnover_required,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_resolution.resolved_at ELSE rr.resolved_at END AS response_resolved_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_turnover.status ELSE tt.status END AS turnover_status,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_turnover.due_at ELSE tt.due_at END AS turnover_due_at,
            CASE WHEN primary_report.report_id IS NOT NULL THEN primary_turnover.received_at ELSE tt.received_at END AS turnover_received_at,
            primary_report.reference_id AS primary_reference_id,
            (primary_report.report_id IS NOT NULL) AS is_supporting_report
        FROM reports r
        LEFT JOIN reports primary_report ON r.duplicate_status = 'merged' AND primary_report.report_id = r.parent_report_id
        LEFT JOIN barangays report_barangay ON report_barangay.barangay_id = r.barangay_id
        LEFT JOIN barangays primary_barangay ON primary_barangay.barangay_id = primary_report.barangay_id
        LEFT JOIN confirmed_cases cc ON cc.report_id = r.report_id
        LEFT JOIN confirmed_cases primary_case ON primary_case.report_id = primary_report.report_id
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        LEFT JOIN report_response_workflow primary_workflow ON primary_workflow.report_id = primary_report.report_id
        LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
        LEFT JOIN report_response_resolutions primary_resolution ON primary_resolution.report_id = primary_report.report_id
        LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
        LEFT JOIN report_turnover_tasks primary_turnover ON primary_turnover.report_id = primary_report.report_id
        WHERE r.reporter_id = ? AND r.archived_at IS NULL
          AND (r.updated_at > ? OR (primary_report.report_id IS NOT NULL AND primary_report.updated_at > ?))
        ORDER BY r.created_at DESC");
    $rStmt->bind_param("iss", $userId, $lastPoll, $lastPoll);
    $rStmt->execute();
    $rr = $rStmt->get_result();
    if ($rr->num_rows > 0) {
        $residentReportOverdueSlaSettings = dcvsLoadReportOverdueSlaSettings($conn);
    }
    while ($row = $rr->fetch_assoc()) {
        $isSimilarReportReview = strtolower((string)($row['duplicate_status'] ?? '')) === 'suspected';
        $overdueInfo = $isSimilarReportReview
            ? null
            : dcvsReportOverdueInfo($row, null, $residentReportOverdueSlaSettings);
        $presentation = dcvsResidentReportPresentation($row, $overdueInfo);
        $row['is_overdue'] = $overdueInfo !== null;
        $row['overdue_action'] = (string)($overdueInfo['action'] ?? '');
        $row['overdue_deadline'] = (string)($overdueInfo['deadline'] ?? '');
        $row['resident_status_label'] = $presentation['statusLabel'];
        $row['resident_status_heading'] = $presentation['statusHeading'];
        $row['resident_status_message'] = $presentation['statusMessage'];
        $row['resident_next_step'] = $presentation['nextStep'];
        $response['reports'][] = $row;
    }
    $rStmt->close();
} elseif ($role === 'staff' || $role === 'admin') {
    $rStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.barangay_id, r.location_address, r.description,
        r.status, r.urgency_level, r.created_at, r.updated_at, r.validated_at, r.confirmed_at, r.scheduled_at, r.dcvs_notes,
        rw.state AS response_state,
        u.full_name AS reporter_name, u.phone AS reporter_phone, u.email AS reporter_email, u.avatar_url,
        b.barangay_name, cc.case_id, cc.status AS case_status, cc.confirmed_at AS case_confirmed_at, cc.resolved_at
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        LEFT JOIN confirmed_cases cc ON cc.report_id = r.report_id
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        WHERE r.archived_at IS NULL AND r.duplicate_status $reportDuplicateVisibility AND u.status = 'active' AND u.deleted_at IS NULL
          AND r.updated_at > ? ORDER BY r.created_at DESC");
    $rStmt->bind_param("s", $lastPoll);
    $rStmt->execute();
    $rr = $rStmt->get_result();
    while ($row = $rr->fetch_assoc()) $response['reports'][] = $row;
    $rStmt->close();
} elseif ($role === 'barangay') {
    $barWhere = $barangayId ? "AND r.barangay_id = ?" : "";
    $rStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.location_address, r.status,
        r.urgency_level, r.created_at, r.updated_at, u.full_name AS reporter_name
        FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.archived_at IS NULL AND r.duplicate_status NOT IN ('merged', 'suspected') $barWhere AND r.updated_at > ? ORDER BY r.created_at DESC");
    if ($barangayId) { $rStmt->bind_param("is", $barangayId, $lastPoll); }
    else { $rStmt->bind_param("s", $lastPoll); }
    $rStmt->execute();
    $rr = $rStmt->get_result();
    while ($row = $rr->fetch_assoc()) $response['reports'][] = $row;
    $rStmt->close();
}

// ── 4. Pets (for residents) ──
if ($role === 'resident') {
    $pStmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, gender, age,
        vaccination_status, photo_url, created_at, updated_at,
        EXISTS(SELECT 1 FROM adoption_listings WHERE pet_id = p.pet_id) AS from_adoption
        FROM pets p WHERE owner_id = ? AND archived_at IS NULL AND updated_at > ? ORDER BY pet_id ASC");
    $pStmt->bind_param("is", $userId, $lastPoll);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($row = $pr->fetch_assoc()) {
        $row['from_adoption'] = (bool)$row['from_adoption'];
        $response['pets'][] = $row;
    }
    $pStmt->close();
}

// ── 5. Activity logs (for dashboard Recent Activities) ──
if ($role === 'resident') {
    $lStmt = $conn->prepare("SELECT log_id, action, details, created_at FROM activity_logs
        WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 10");
    $lStmt->bind_param("is", $userId, $lastPoll);
    $lStmt->execute();
    $lr = $lStmt->get_result();
    while ($row = $lr->fetch_assoc()) $response['activities'][] = $row;
    $lStmt->close();
}

// ── 6. Summary counts (for KPI cards) ──
$counts = [];

if ($role === 'resident') {
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM pets WHERE owner_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['pets'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();

    $cStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['reports'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();

    $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['appointments'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();
}

if ($role === 'staff' || $role === 'admin') {
    $cStmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active' AND deleted_at IS NULL");
    $counts['residents'] = (int)$cStmt->fetch_row()[0];
    $counts['active_residents'] = $counts['residents'];
    $cStmt = $conn->query("SELECT COUNT(*) FROM pets p JOIN users u ON p.owner_id = u.user_id
        WHERE p.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['pets'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.archived_at IS NULL AND r.duplicate_status $reportDuplicateVisibility AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.report_type = 'rabies' AND r.status IN ('pending','validated') AND r.archived_at IS NULL AND r.duplicate_status $reportDuplicateVisibility
          AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['rabies'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.archived_at IS NULL AND r.status = 'pending' AND r.duplicate_status $reportDuplicateVisibility AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['pending_reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.archived_at IS NULL AND r.status = 'validated' AND r.duplicate_status $reportDuplicateVisibility AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['validated_reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id = u.user_id
        WHERE a.archived_at IS NULL AND a.status = 'pending' AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['pending_appointments'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM confirmed_cases cc
        JOIN reports r ON cc.report_id = r.report_id
        JOIN users u ON r.reporter_id = u.user_id
        WHERE cc.status = 'active' AND r.duplicate_status $reportDuplicateVisibility AND u.status = 'active' AND u.deleted_at IS NULL");
    $counts['active_cases'] = (int)$cStmt->fetch_row()[0];
}

if ($role === 'barangay') {
    $barWhere = $barangayId ? "AND barangay_id = $barangayId" : "";
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND duplicate_status NOT IN ('merged', 'suspected') AND status = 'pending' $barWhere");
    $counts['pending'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND duplicate_status NOT IN ('merged', 'suspected') AND status = 'validated' $barWhere");
    $counts['validated'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND duplicate_status NOT IN ('merged', 'suspected') AND status = 'confirmed' $barWhere");
    $counts['confirmed'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND duplicate_status NOT IN ('merged', 'suspected') AND status = 'rejected' $barWhere");
    $counts['rejected'] = (int)$cStmt->fetch_row()[0];
}

$response['counts'] = $counts;
echo json_encode($response);
