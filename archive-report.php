<?php
/**
 * Archive Report Handler
 * Archives (soft deletes) a report submission
 */

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

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $reportId = intval($_POST['report_id'] ?? $_GET['id'] ?? 0);

    if ($reportId === 0) {
        $_SESSION['report_error'] = "Invalid report ID.";
        header('Location: dashboard-resident.php');
        exit;
    }

    // Check if report belongs to user
    $checkReport = $conn->prepare("SELECT report_id FROM reports WHERE report_id = ? AND reporter_id = ?");
    $checkReport->bind_param("ii", $reportId, $_SESSION['user_id']);
    $checkReport->execute();
    $checkReport->store_result();

    if ($checkReport->num_rows === 0) {
        $_SESSION['report_error'] = "Invalid report selected.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $checkReport->close();

    // Archive report (soft delete - add archived_at timestamp)
    $stmt = $conn->prepare("UPDATE reports SET archived_at = NOW() WHERE report_id = ? AND reporter_id = ?");
    $stmt->bind_param("ii", $reportId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        $_SESSION['report_success'] = "Report archived successfully!";
        header('Location: dashboard-resident.php');
        exit;
    } else {
        $_SESSION['report_error'] = "Failed to archive report. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
