<?php
/**
 * Submit Report Handler
 * Saves stray/rabies reports to database
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
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    $reportType = $_POST['report_type'] ?? '';
    $reportDatetime = $_POST['report_datetime'] ?? '';
    $animalDesc = trim($_POST['animal_desc'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $urgency = $_POST['urgency'] ?? 'medium';

    // Server-side validation
    $errors = [];

    if (empty($reportType)) {
        $errors[] = "Report type is required.";
    }
    if (empty($reportDatetime)) {
        $errors[] = "Date/Time is required.";
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
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Daily report limit check
    $today = date('Y-m-d');
    $dailyStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND DATE(created_at) = ?");
    $dailyStmt->bind_param("is", $_SESSION['user_id'], $today);
    $dailyStmt->execute();
    $userReportsToday = (int)$dailyStmt->get_result()->fetch_row()[0];
    $dailyStmt->close();
    $totalStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE DATE(created_at) = ?");
    $totalStmt->bind_param("s", $today);
    $totalStmt->execute();
    $totalReportsToday = (int)$totalStmt->get_result()->fetch_row()[0];
    $totalStmt->close();
    if ($userReportsToday >= 5) {
        $_SESSION['report_error'] = 'You have reached the daily limit of 5 reports. Please try again tomorrow.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    if ($totalReportsToday >= 50) {
        $_SESSION['report_error'] = 'The system has reached the daily report capacity. Please try again tomorrow.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Handle photo upload (or reuse existing photo from resubmission)
    $photoUrl = trim($_POST['photo_url'] ?? '');
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($detectedType, $allowedTypes) || !in_array($ext, $allowedExts)) {
            $_SESSION['report_error'] = 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.';
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        if ($_FILES['photo']['size'] > $maxSize) {
            $_SESSION['report_error'] = 'File size exceeds 5MB limit.';
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        $uploadDir = 'uploads/reports/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = uniqid('report_') . '_' . time() . '.' . $ext;
        $uploadPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
            $photoUrl = $uploadPath;
        }
    }

    // Get barangay_id from user session
    $barangayId = $_SESSION['barangay_id'] ?? null;

    // Insert report into database
    error_log("Attempting to insert report into database");
    error_log("User ID: " . $_SESSION['user_id']);
    error_log("Report datetime: " . $reportDatetime);
    $fullDescription = $animalDesc . ' - ' . $description;
    $refId = 'RPT-' . time() . '-' . $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO reports (reporter_id, report_type, description, location_address, barangay_id, urgency_level, photo_url, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['report_error'] = "Database error. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $stmt->bind_param("isssissss", $_SESSION['user_id'], $reportType, $fullDescription, $location, $barangayId, $urgency, $photoUrl, $refId, $reportDatetime);

    if ($stmt->execute()) {
        $reportId = $stmt->insert_id;

        // Notify barangay officers
        if (!empty($barangayId)) {
            $barangayLabel = '';
            $bq = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
            $bq->bind_param("i", $barangayId);
            $bq->execute();
            $br = $bq->get_result();
            if ($row = $br->fetch_assoc()) $barangayLabel = $row['barangay_name'];
            $bq->close();

            $notifyStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            if ($notifyStmt) {
                $notifyStmt->bind_param("i", $barangayId);
                $notifyStmt->execute();
                $nr = $notifyStmt->get_result();
                while ($officer = $nr->fetch_assoc()) {
                    createNotification($conn, $officer['user_id'], 'new_report',
                        'New Report Submitted',
                        $_SESSION['full_name'] . ' from ' . $barangayLabel . ' submitted a ' . ucfirst($reportType) . ' report.',
                        'report', $reportId);
                }
                $notifyStmt->close();
            }
        }

        // Also notify all active staff members
        $staffNotify = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        if ($staffNotify) {
            $staffNotify->execute();
            $staffResult = $staffNotify->get_result();
            while ($staff = $staffResult->fetch_assoc()) {
                createNotification($conn, $staff['user_id'], 'new_report',
                    'New Report Submitted',
                    $_SESSION['full_name'] . ' from ' . $barangayLabel . ' submitted a ' . ucfirst($reportType) . ' report.',
                    'report', $reportId);
            }
            $staffNotify->close();
        }

        // Log activity
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'submit_report', ?, ?)");
        if ($logStmt) {
            $detail = "Submitted " . ucfirst($reportType) . " report #" . $reportId;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $logStmt->bind_param("iss", $_SESSION['user_id'], $detail, $ip);
            $logStmt->execute();
            $logStmt->close();
        }

        $_SESSION['report_success'] = "Report submitted successfully! It will be validated by your barangay officer.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    } else {
        $_SESSION['report_error'] = "Failed to submit report. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
