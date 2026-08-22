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

require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

// Verify session user still exists in database
$userCheck = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND status = 'active'");
$userCheck->bind_param("i", $_SESSION['user_id']);
$userCheck->execute();
if (!$userCheck->get_result()->fetch_assoc()) {
    session_destroy();
    header('Location: login-barangay.php');
    exit;
}
$userCheck->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $checkReport = $conn->prepare("SELECT report_id, barangay_id FROM reports WHERE report_id = ?");
    $checkReport->bind_param("i", $reportId);
    $checkReport->execute();
    $reportData = $checkReport->get_result()->fetch_assoc();
    $checkReport->close();

    if (!$reportData || $reportData['barangay_id'] != $_SESSION['barangay_id']) {
        $_SESSION['report_error'] = "Invalid report selected.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $barangayNotes = trim($_POST['barangay_notes'] ?? '');

    $stmt = $conn->prepare("UPDATE reports SET status = 'validated', validated_by = ?, validated_at = NOW(), barangay_notes = ? WHERE report_id = ?");
    $stmt->bind_param("isi", $_SESSION['user_id'], $barangayNotes, $reportId);

    if ($stmt->execute()) {
        // Notify resident that their report was forwarded
        $repStmt = $conn->prepare("SELECT reporter_id FROM reports WHERE report_id = ?");
        $repStmt->bind_param("i", $reportId);
        $repStmt->execute();
        $repRow = $repStmt->get_result()->fetch_assoc();
        $repStmt->close();
        if ($repRow) {
            createNotification($conn, $repRow['reporter_id'], 'report_validated',
                'Report Validated by Barangay',
                'Your report has been validated by ' . htmlspecialchars($_SESSION['full_name'] ?? 'Barangay') . ' and forwarded to DCVS for confirmation.',
                'report', $reportId);
        }

        // Notify all staff
        $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        if ($staffStmt) {
            $staffStmt->execute();
            $sr = $staffStmt->get_result();
            while ($staff = $sr->fetch_assoc()) {
                createNotification($conn, $staff['user_id'], 'report_validated',
                    'New Validated Report from Barangay',
                    htmlspecialchars($_SESSION['full_name'] ?? 'A barangay officer') . ' validated and forwarded a report to DCVS.',
                    'report', $reportId);
            }
            $staffStmt->close();
        }

        $action = "Report Validated & Forwarded";
        $details = "Report #$reportId validated and forwarded to DCVS.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['report_success'] = "Report validated successfully! It has been forwarded to DCVS.";
        header('Location: dashboard-barangay.php');
        exit;
    } else {
        error_log("validate-report.php UPDATE failed: " . $stmt->error);
        $_SESSION['report_error'] = "Failed to validate report. Please try again.";
        header('Location: dashboard-barangay.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
