<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once 'db.php';
require_once 'mailer.php';
require_once 'sms-sender.php';

function confirmScheduleRespond($success, $message, $data = []) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $data));
        exit;
    }
    $_SESSION[$success ? 'report_success' : 'report_error'] = $message;
    header('Location: dashboard-staff.php#' . ($success ? 'reports-active' : 'reports-pending'));
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $scheduledAtInput = trim($_POST['scheduled_at'] ?? '');
    $scheduledAt = null;
    $dcvsNotes = trim($_POST['dcvs_notes'] ?? '');

    if ($reportId === 0) {
        confirmScheduleRespond(false, 'Invalid report ID.');
    }

    if ($scheduledAtInput !== '') {
        $parsedSchedule = DateTime::createFromFormat('Y-m-d\\TH:i', $scheduledAtInput);
        $dateErrors = DateTime::getLastErrors();
        if (!$parsedSchedule || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
            confirmScheduleRespond(false, 'Invalid schedule date and time.');
        }
        $scheduledAt = $parsedSchedule->format('Y-m-d H:i:s');
    }

    // Fetch report details
    $stmt = $conn->prepare("SELECT r.*, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id = ? AND r.status = 'validated'");
    $stmt->bind_param("i", $reportId);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        confirmScheduleRespond(false, 'Report not found or already processed.');
    }

    $conn->begin_transaction();

    try {
        // Confirm the report; only mark it scheduled when staff picked an action time.
        $newStatus = $scheduledAt !== null ? 'scheduled' : 'confirmed';
        $updateStmt = $conn->prepare("UPDATE reports SET status = ?, confirmed_by = ?, confirmed_at = NOW(), scheduled_at = ?, dcvs_notes = ? WHERE report_id = ?");
        $updateStmt->bind_param("sissi", $newStatus, $_SESSION['user_id'], $scheduledAt, $dcvsNotes, $reportId);
        $updateStmt->execute();
        $updateStmt->close();

        // Insert into confirmed_cases for geo-mapping
        $insertStmt = $conn->prepare("INSERT INTO confirmed_cases (report_id, case_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, status, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
        $insertStmt->bind_param("issddds", $reportId, $report['report_type'], $report['location_address'], $report['coordinates_lat'], $report['coordinates_long'], $report['barangay_id'], $report['urgency_level']);
        $insertStmt->execute();
        $caseId = (int)$insertStmt->insert_id;
        $insertStmt->close();

        $actionMsg = $scheduledAt !== null
            ? 'scheduled for action on ' . date('F j, Y \\a\\t g:i A', strtotime($scheduledAt))
            : 'confirmed by DCVS; the action schedule will be coordinated by the staff';
        $fullMsg = $dcvsNotes ? $actionMsg . '. Notes: ' . $dcvsNotes : $actionMsg . '.';

        // Notify resident
        createNotification($conn, $report['reporter_id'], 'report_confirmed',
            $scheduledAt !== null ? 'Report Scheduled' : 'Report Confirmed',
            'Your report has been confirmed by DCVS and is ' . $fullMsg,
            'report', $reportId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendReportEmail($conn, $reportId, $newStatus, '', $staffName);
        sendReportSms($conn, $reportId, $newStatus, $staffName, $dcvsNotes);

        // Notify barangay officers
        if ($report['barangay_id']) {
            $baResult = $conn->query("SELECT barangay_name FROM barangays WHERE barangay_id = " . $report['barangay_id']);
            $baName = $baResult->fetch_assoc()['barangay_name'] ?? '';
            $bNotifStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            $bNotifStmt->bind_param("i", $report['barangay_id']);
            $bNotifStmt->execute();
            $bOfficers = $bNotifStmt->get_result();
            while ($bo = $bOfficers->fetch_assoc()) {
                createNotification($conn, $bo['user_id'], 'report_confirmed',
                    'Report Scheduled by DCVS',
                    'The report from ' . $baName . ' has been confirmed by DCVS and is ' . $fullMsg,
                    'report', $reportId);
            }
            $bNotifStmt->close();
        }

        // Log activity
        $action = $scheduledAt !== null ? 'Case Confirmed & Scheduled' : 'Case Confirmed';
        $details = $scheduledAt !== null
            ? ucfirst($report['report_type']) . " report from " . ($report['reporter_name'] ?: 'a resident') . " confirmed and scheduled on " . date('F j, Y \\a\\t g:i A', strtotime($scheduledAt)) . "."
            : ucfirst($report['report_type']) . " report from " . ($report['reporter_name'] ?: 'a resident') . " confirmed without an action schedule.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        confirmScheduleRespond(true, $scheduledAt !== null
            ? 'Case confirmed and scheduled successfully.'
            : 'Case confirmed successfully without an action schedule.', [
                'report_id' => $reportId,
                'case_id' => $caseId,
                'status' => $newStatus,
                'scheduled_at' => $scheduledAt,
                'confirmed_at' => date('M d, Y h:i A'),
            ]);
    } catch (Exception $e) {
        $conn->rollback();
        confirmScheduleRespond(false, 'Failed to confirm case: ' . $e->getMessage());
    }
}

$conn->close();
?>