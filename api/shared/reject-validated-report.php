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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

function rejectValidatedRespond($success, $message) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$success, 'message' => $message]);
        exit;
    }
    $_SESSION[$success ? 'report_success' : 'report_error'] = $message;
    header('Location: dashboard-staff.php#reports-pending');
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($reportId === 0) {
        rejectValidatedRespond(false, 'Invalid report ID.');
    }

    if (empty($reason)) {
        rejectValidatedRespond(false, 'Reason for rejection is required.');
    }

    // Check report exists and is validated
    $checkStmt = $conn->prepare("SELECT r.report_id, r.status, r.report_type, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id = ? AND r.status = 'validated'");
    $checkStmt->bind_param("i", $reportId);
    $checkStmt->execute();
    $report = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$report) {
        rejectValidatedRespond(false, 'Report not found or already processed.');
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
            sendReportSms($conn, $reportId, 'rejected', $staffName, $reason);
        }

        $action = "Report Rejected by DCVS";
        $details = ucfirst($report['report_type']) . " report from " . ($report['reporter_name'] ?: 'a resident') . " rejected by DCVS. Reason: $reason";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        rejectValidatedRespond(true, 'Validated report rejected successfully.');
    } else {
        rejectValidatedRespond(false, 'Failed to reject report. Please try again.');
    }

    $stmt->close();
}

$conn->close();
?>