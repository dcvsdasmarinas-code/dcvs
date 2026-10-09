<?php
session_name('DCVS_BARANGAY_SESSION');
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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $reason = htmlspecialchars(trim($_POST['reason'] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    if (empty($reason)) {
        $_SESSION['report_error'] = "Reason for rejection is required.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $checkReport = $conn->prepare("SELECT r.report_id, r.barangay_id, r.reporter_id, r.report_type, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id = ? AND r.status = 'pending'");
    $checkReport->bind_param("i", $reportId);
    $checkReport->execute();
    $reportData = $checkReport->get_result()->fetch_assoc();
    $checkReport->close();

    if (!$reportData || $reportData['barangay_id'] != $_SESSION['barangay_id']) {
        $_SESSION['report_error'] = "Invalid report selected.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $stmt = $conn->prepare("UPDATE reports SET status = 'rejected', rejection_reason = ?, accepted_by = ?, confirmed_at = NOW() WHERE report_id = ?");
    $stmt->bind_param("sii", $reason, $_SESSION['user_id'], $reportId);

    if ($stmt->execute()) {
        createNotification($conn, $reportData['reporter_id'], 'report_rejected',
            'Report Rejected',
            'Your report was not approved by your barangay. Reason: ' . $reason,
            'report', $reportId);

        $action = "Report Rejected by Barangay";
        $details = ucfirst($reportData['report_type']) . " report from " . ($reportData['reporter_name'] ?: 'a resident') . " rejected by barangay. Reason: $reason";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['report_success'] = "Report rejected successfully.";
        header('Location: dashboard-barangay.php');
        exit;
    } else {
        $_SESSION['report_error'] = "Failed to reject report. Please try again.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>