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
    $caseId = intval($_POST['case_id'] ?? 0);
    $resolutionNotes = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($caseId === 0) {
        $_SESSION['report_error'] = "Invalid case ID.";
        header('Location: dashboard-staff.php#reports-active');
        exit;
    }

    // Fetch confirmed case details
    $stmt = $conn->prepare("SELECT cc.*, r.report_id, r.reporter_id, r.barangay_id FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id WHERE cc.case_id = ? AND cc.status = 'active'");
    $stmt->bind_param("i", $caseId);
    $stmt->execute();
    $case = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$case) {
        $_SESSION['report_error'] = "Case not found or already resolved.";
        header('Location: dashboard-staff.php#reports-active');
        exit;
    }

    $reportId = $case['report_id'];

    $conn->begin_transaction();

    try {
        // Update confirmed_cases status to resolved
        $updateCaseStmt = $conn->prepare("UPDATE confirmed_cases SET status = 'resolved', resolved_at = NOW(), notes = ? WHERE case_id = ?");
        $updateCaseStmt->bind_param("si", $resolutionNotes, $caseId);
        $updateCaseStmt->execute();
        $updateCaseStmt->close();

        // Update report status to resolved
        $updateReportStmt = $conn->prepare("UPDATE reports SET status = 'resolved' WHERE report_id = ?");
        $updateReportStmt->bind_param("i", $reportId);
        $updateReportStmt->execute();
        $updateReportStmt->close();

        // Notify resident
        createNotification($conn, $case['reporter_id'], 'report_resolved',
            'Report Resolved',
            'Your report has been marked as resolved by DCVS.' . ($resolutionNotes ? ' Notes: ' . $resolutionNotes : ''),
            'report', $reportId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendReportEmail($conn, $reportId, 'resolved', $resolutionNotes, $staffName);

        // Notify barangay officers
        if ($case['barangay_id']) {
            $bNotifStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            $bNotifStmt->bind_param("i", $case['barangay_id']);
            $bNotifStmt->execute();
            $bOfficers = $bNotifStmt->get_result();
            while ($bo = $bOfficers->fetch_assoc()) {
                createNotification($conn, $bo['user_id'], 'report_resolved',
                    'Case Resolved by DCVS',
                    'A case from your barangay has been resolved by DCVS.' . ($resolutionNotes ? ' Notes: ' . $resolutionNotes : ''),
                    'report', $reportId);
            }
            $bNotifStmt->close();
        }

        // Log activity
        $action = "Case Resolved";
        $details = "Case #$caseId resolved successfully.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        $_SESSION['report_success'] = "Case marked as resolved successfully. Resident and barangay have been notified.";
        header('Location: dashboard-staff.php#reports-resolved');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['report_error'] = "Failed to resolve case: " . $e->getMessage();
        header('Location: dashboard-staff.php#reports-active');
        exit;
    }
}

$conn->close();
?>