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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $scheduledAt = $_POST['scheduled_at'] ?? '';
    $dcvsNotes = trim($_POST['dcvs_notes'] ?? '');

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    if (empty($scheduledAt)) {
        $_SESSION['report_error'] = "Schedule date and time are required.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    // Fetch report details
    $stmt = $conn->prepare("SELECT * FROM reports WHERE report_id = ? AND status = 'validated'");
    $stmt->bind_param("i", $reportId);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        $_SESSION['report_error'] = "Report not found or already processed.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    $conn->begin_transaction();

    try {
        // Update report status to scheduled
        $updateStmt = $conn->prepare("UPDATE reports SET status = 'scheduled', confirmed_by = ?, confirmed_at = NOW(), scheduled_at = ?, dcvs_notes = ? WHERE report_id = ?");
        $updateStmt->bind_param("issi", $_SESSION['user_id'], $scheduledAt, $dcvsNotes, $reportId);
        $updateStmt->execute();
        $updateStmt->close();

        // Insert into confirmed_cases for geo-mapping
        $insertStmt = $conn->prepare("INSERT INTO confirmed_cases (report_id, case_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, status, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
        $insertStmt->bind_param("issddds", $reportId, $report['report_type'], $report['location_address'], $report['coordinates_lat'], $report['coordinates_long'], $report['barangay_id'], $report['urgency_level']);
        $insertStmt->execute();
        $insertStmt->close();

        $actionDate = date('F j, Y \a\t g:i A', strtotime($scheduledAt));
        $actionMsg = "scheduled for action on " . $actionDate;
        $fullMsg = $dcvsNotes ? $actionMsg . ". Notes: " . $dcvsNotes : $actionMsg . ".";

        // Notify resident
        createNotification($conn, $report['reporter_id'], 'report_confirmed',
            'Report Scheduled',
            'Your report has been confirmed by DCVS and is ' . $fullMsg,
            'report', $reportId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendReportEmail($conn, $reportId, 'scheduled', '', $staffName);

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
        $action = "Case Confirmed & Scheduled";
        $details = "Report #$reportId confirmed and scheduled on $actionDate.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        $_SESSION['report_success'] = "Case confirmed and scheduled successfully. Resident and barangay have been notified.";
        header('Location: dashboard-staff.php#reports-active');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['report_error'] = "Failed to confirm case: " . $e->getMessage();
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }
}

$conn->close();
?>