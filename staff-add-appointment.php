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
    $appointment_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
    $user_id = $_POST['user_id'] ?? null;
    $appointment_type = $_POST['appointment_type'] ?? null;
    $preferred_date = $_POST['preferred_date'] ?? null;
    $preferred_time = $_POST['preferred_time'] ?? null;
    $status = $_POST['status'] ?? 'scheduled';
    $staffMessage = trim($_POST['staff_message'] ?? '');
    
    // Quick validation
    if (!$user_id || !$appointment_type || !$preferred_date || !$preferred_time) {
        $_SESSION['appointment_error'] = "All fields are required.";
        header('Location: dashboard-staff.php');
        exit;
    }

    // Verify user exists and is a resident
    $checkUser = $conn->prepare("SELECT user_id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ? AND role = 'resident'");
    $checkUser->bind_param("i", $user_id);
    $checkUser->execute();
    $resident = $checkUser->get_result()->fetch_assoc();
    if (!$resident) {
        $_SESSION['appointment_error'] = "Invalid Resident ID.";
        header('Location: dashboard-staff.php');
        exit;
    }
    $checkUser->close();

    // Slot capacity check
    $activeStatuses = "'pending','approved','scheduled'";
    if ($appointment_id) {
        $excludeClause = " AND appointment_id != ?";
    } else {
        $excludeClause = "";
    }
// Check duplicate for same resident
    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses,'in_progress') AND archived_at IS NULL" . $excludeClause);
    if ($appointment_id) { $dupStmt->bind_param("issi", $user_id, $preferred_date, $preferred_time, $appointment_id); }
    else { $dupStmt->bind_param("iss", $user_id, $preferred_date, $preferred_time); }
    $dupStmt->execute();
    if ($dupStmt->get_result()->fetch_row()[0] > 0) {
        $_SESSION['appointment_error'] = "This resident already has an appointment at this date and time.";
        $dupStmt->close(); header('Location: dashboard-staff.php'); exit;
    }
    $dupStmt->close();
    // Check daily capacity (max 5)
    $dStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND status IN ($activeStatuses) AND archived_at IS NULL" . $excludeClause);
    if ($appointment_id) { $dStmt->bind_param("si", $preferred_date, $appointment_id); }
    else { $dStmt->bind_param("s", $preferred_date); }
    $dStmt->execute();
    if ($dStmt->get_result()->fetch_row()[0] >= 5) {
        $_SESSION['appointment_error'] = "This date is fully booked (max 5).";
        $dStmt->close(); header('Location: dashboard-staff.php'); exit;
    }
    $dStmt->close();
    // Check per-slot capacity (max 5)
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE preferred_date = ? AND preferred_time = ? AND status IN ($activeStatuses) AND archived_at IS NULL" . $excludeClause);
    if ($appointment_id) { $cStmt->bind_param("ssi", $preferred_date, $preferred_time, $appointment_id); }
    else { $cStmt->bind_param("ss", $preferred_date, $preferred_time); }
    $cStmt->execute();
    if ($cStmt->get_result()->fetch_row()[0] >= 5) {
        $_SESSION['appointment_error'] = "This time slot is full (max 5).";
        $cStmt->close(); header('Location: dashboard-staff.php'); exit;
    }
    $cStmt->close();
    
    if ($appointment_id) {
        // UPDATE existing appointment
        $stmt = $conn->prepare("UPDATE appointments SET user_id=?, appointment_type=?, preferred_date=?, preferred_time=?, status=? WHERE appointment_id=?");
        $stmt->bind_param("issssi", $user_id, $appointment_type, $preferred_date, $preferred_time, $status, $appointment_id);
        $action = "Appointment Updated";
        $refStmt = $conn->prepare("SELECT reference_id FROM appointments WHERE appointment_id = ?");
        $refStmt->bind_param("i", $appointment_id);
        $refStmt->execute();
        $refResult = $refStmt->get_result();
        $existingRefId = $refResult->fetch_row()[0] ?? fallbackRefId('#APT-', $appointment_id);
        $refStmt->close();
        $details = "Updated appointment $existingRefId to status: $status.";
    } else {
        // INSERT new appointment
        $refId = generateReferenceId($conn, '#APT-', 'appointments');
        $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, preferred_date, preferred_time, status, reference_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user_id, $appointment_type, $preferred_date, $preferred_time, $status, $refId);
$action = "Appointment Scheduled";
        $details = "Scheduled $appointment_type for User ID: $user_id on $preferred_date (ID: $refId).";
    }
    
    if ($stmt->execute()) {
        // Capture the new appointment id only AFTER a successful insert
        if (!$appointment_id) {
            $appointment_id = $stmt->insert_id;
        }
        // Notify resident if status changed (especially scheduled/completed)
        if (in_array($status, ['scheduled', 'completed', 'rescheduled'])) {
            $notifTitle = $status === 'completed' ? 'Adoption Completed' : 'Appointment ' . ucfirst($status);
            if ($appointment_type === 'adoption') {
                $notifTitle = $status === 'completed' ? 'Adoption Complete' : 'Adoption Appointment Scheduled';
            }
            $notifMsg = "Your $appointment_type appointment has been $status.";
            if ($status === 'scheduled') {
                $notifMsg = "Your $appointment_type appointment is scheduled on $preferred_date at $preferred_time.";
            }
            if (!empty($staffMessage)) {
                $notifMsg .= "\n\nStaff Message: $staffMessage";
            }
            createNotification($conn, $user_id, $appointment_type === 'adoption' ? 'adoption_scheduled' : 'appointment_update', $notifTitle, $notifMsg, 'appointments', $appointment_id);

            // Also update adoption_listings to 'adopted' when completed
            if ($appointment_type === 'adoption' && $status === 'completed') {
                $petStmt = $conn->prepare("SELECT pet_id FROM appointments WHERE appointment_id = ?");
                $petStmt->bind_param("i", $appointment_id);
                $petStmt->execute();
                $p = $petStmt->get_result()->fetch_assoc();
                $petStmt->close();
                if ($p) {
                    $alStmt = $conn->prepare("UPDATE adoption_listings SET status = 'adopted' WHERE pet_id = ?");
                    $alStmt->bind_param("i", $p['pet_id']);
                    $alStmt->execute();
                    $alStmt->close();
                }
            }
        }

// Send email notification to resident
        if ($appointment_id) {
            $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
            sendAppointmentEmail($conn, $appointment_id, $status, $staffMessage, $staffName);
        }

        $_SESSION['appointment_success'] = "Appointment saved successfully.";
        
        // Log activity
        $ip = $_SERVER['REMOTE_ADDR'];
        $staff_id = $_SESSION['user_id'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $staff_id, $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

    } else {
        $_SESSION['appointment_error'] = "Error saving appointment.";
    }
    $stmt->close();
}

header('Location: dashboard-staff.php');
exit;
