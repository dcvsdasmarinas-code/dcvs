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

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
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
        // Update report status to confirmed
        $updateStmt = $conn->prepare("UPDATE reports SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE report_id = ?");
        $updateStmt->bind_param("ii", $_SESSION['user_id'], $reportId);
        $updateStmt->execute();
        $updateStmt->close();

        // Insert into confirmed_cases for geo-mapping
        $insertStmt = $conn->prepare("INSERT INTO confirmed_cases (report_id, case_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, status, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
        $insertStmt->bind_param("issddds", $reportId, $report['report_type'], $report['location_address'], $report['coordinates_lat'], $report['coordinates_long'], $report['barangay_id'], $report['urgency_level']);
        $insertStmt->execute();
        $insertStmt->close();

        // Notify resident
        createNotification($conn, $report['reporter_id'], 'report_confirmed',
            'Report Confirmed',
            'Your report has been officially confirmed by DCVS. Case is now active.',
            'report', $reportId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendReportEmail($conn, $reportId, 'confirmed', '', $staffName);

        // Notify barangay officers
        if ($report['barangay_id']) {
            $bNotifStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            $bNotifStmt->bind_param("i", $report['barangay_id']);
            $bNotifStmt->execute();
            $bOfficers = $bNotifStmt->get_result();
            while ($bo = $bOfficers->fetch_assoc()) {
                createNotification($conn, $bo['user_id'], 'report_confirmed',
                    'Report Confirmed by DCVS',
                    'A report from your barangay has been officially confirmed by DCVS.',
                    'report', $reportId);
            }
            $bNotifStmt->close();
        }

        // Log activity
        $action = "Case Confirmed";
        $details = "Report #$reportId confirmed as official case by DCVS.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        $_SESSION['report_success'] = "Case confirmed successfully. Resident and barangay have been notified.";
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