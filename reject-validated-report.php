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
    $reason = trim($_POST['reason'] ?? '');

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    if (empty($reason)) {
        $_SESSION['report_error'] = "Reason for rejection is required.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    // Check report exists and is validated
    $checkStmt = $conn->prepare("SELECT report_id, status FROM reports WHERE report_id = ? AND status = 'validated'");
    $checkStmt->bind_param("i", $reportId);
    $checkStmt->execute();
    $report = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$report) {
        $_SESSION['report_error'] = "Report not found or already processed.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    // Reject - use the validated report as a rejection by DCVS
    $stmt = $conn->prepare("UPDATE reports SET status = 'rejected', rejection_reason = ?, confirmed_by = ?, confirmed_at = NOW() WHERE report_id = ?");
    $stmt->bind_param("sii", $reason, $_SESSION['user_id'], $reportId);

    if ($stmt->execute()) {
        // Notify barangay officers about the rejection
        $repStmt = $conn->prepare("SELECT barangay_id, reporter_id FROM reports WHERE report_id = ?");
        $repStmt->bind_param("i", $reportId);
        $repStmt->execute();
        $repRow = $repStmt->get_result()->fetch_assoc();
        $repStmt->close();

        if ($repRow) {
            // Notify barangay officers
            if ($repRow['barangay_id']) {
                $bStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
                $bStmt->bind_param("i", $repRow['barangay_id']);
                $bStmt->execute();
                $bos = $bStmt->get_result();
                while ($bo = $bos->fetch_assoc()) {
                    createNotification($conn, $bo['user_id'], 'report_rejected',
                        'Report Rejected by DCVS',
                        'A report you forwarded was not approved by DCVS. Reason: ' . $reason,
                        'report', $reportId);
                }
                $bStmt->close();
            }
            // Notify resident
            createNotification($conn, $repRow['reporter_id'], 'report_rejected',
                'Report Rejected',
                'Your report was not confirmed by DCVS. Reason: ' . $reason,
                'report', $reportId);
            $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
            sendReportEmail($conn, $reportId, 'rejected', $reason, $staffName);
        }

        $action = "Report Rejected by DCVS";
        $details = "Report #$reportId rejected by DCVS. Reason: $reason";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['report_success'] = "Validated report rejected successfully.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    } else {
        $_SESSION['report_error'] = "Failed to reject report. Please try again.";
        header('Location: dashboard-staff.php#reports-pending');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>