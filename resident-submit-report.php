<?php
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

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reporter_id = $_SESSION['user_id'];
    $report_type = $_POST['report_type'] ?? '';
    $validTypes = ['stray', 'rabies', 'injured', 'dead'];
    if (!in_array($report_type, $validTypes)) $report_type = '';
    $description = $_POST['description'] ?? '';
    $location = $_POST['location'] ?? 'Resident Location';
    $status = 'pending';

    if (empty($report_type) || empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Report type and description are required.']);
        exit;
    }

    $refId = generateReferenceId($conn, '#RPT-', 'reports');
    $stmt = $conn->prepare("INSERT INTO reports (reporter_id, report_type, description, location_address, status, reference_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $reporter_id, $report_type, $description, $location, $status, $refId);
    
    if ($stmt->execute()) {
        $report_id = $conn->insert_id;

        // Notify barangay officers
        $uStmt = $conn->prepare("SELECT u.barangay_id, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.user_id = ?");
        $uStmt->bind_param("i", $reporter_id);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
        $barangayLabel = $uRow ? 'Brgy. ' . $uRow['barangay_name'] : '';
        if ($uRow && $uRow['barangay_id']) {
            $notifyStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            $notifyStmt->bind_param("i", $uRow['barangay_id']);
            $notifyStmt->execute();
            $officers = $notifyStmt->get_result();
            while ($officer = $officers->fetch_assoc()) {
                createNotification($conn, $officer['user_id'], 'new_report',
                    'New Report Submitted',
                    $_SESSION['full_name'] . ' from ' . $barangayLabel . ' submitted a ' . ucfirst($report_type) . ' report.',
                    'report', $report_id);
            }
            $notifyStmt->close();
        }

        // Also notify all active staff members
        $staffNotify = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        $staffNotify->execute();
        $staffResult = $staffNotify->get_result();
        while ($staff = $staffResult->fetch_assoc()) {
            createNotification($conn, $staff['user_id'], 'new_report',
                'New Report Submitted',
                $_SESSION['full_name'] . ' from ' . $barangayLabel . ' submitted a ' . ucfirst($report_type) . ' report.',
                'report', $report_id);
        }
        $staffNotify->close();
        
        // Log activity
        $action = "Submitted a Report";
        $details = ($_SESSION['full_name'] ?? 'A resident') . " submitted a " . ucfirst($report_type) . " report.";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->bind_param("iss", $reporter_id, $action, $details);
        $logStmt->execute();

        echo json_encode(['success' => true, 'message' => 'Report submitted successfully!', 'report_id' => $report_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit report. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
