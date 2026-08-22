<?php
/**
 * Edit Report Handler
 * Updates existing report submission
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $reportType = $_POST['report_type'] ?? '';
    $animalDesc = trim($_POST['animal_desc'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $urgency = $_POST['urgency'] ?? 'medium';

    // Server-side validation
    $errors = [];

    if ($reportId === 0) {
        $errors[] = "Invalid report ID.";
    }
    if (empty($reportType)) {
        $errors[] = "Report type is required.";
    }
    if (empty($animalDesc)) {
        $errors[] = "Animal description is required.";
    }
    if (empty($location)) {
        $errors[] = "Location is required.";
    }
    if (empty($description)) {
        $errors[] = "Detailed description is required.";
    }

    if (!empty($errors)) {
        $_SESSION['report_error'] = implode('<br>', $errors);
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

    // Get barangay_id from user session
    $barangayId = $_SESSION['barangay_id'] ?? null;

    // Update report in database
    $fullDescription = $animalDesc . ' - ' . $description;
    $stmt = $conn->prepare("UPDATE reports SET report_type = ?, description = ?, location_address = ?, barangay_id = ?, urgency_level = ? WHERE report_id = ? AND reporter_id = ?");
    $stmt->bind_param("sssisii", $reportType, $fullDescription, $location, $barangayId, $urgency, $reportId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        $_SESSION['report_success'] = "Report updated successfully!";
        header('Location: dashboard-resident.php');
        exit;
    } else {
        $_SESSION['report_error'] = "Failed to update report. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
