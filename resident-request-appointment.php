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
    $user_id = $_SESSION['user_id'];
    $appointment_type = $_POST['appt_type'] ?? '';
    $pet_id = !empty($_POST['pet']) ? (int)$_POST['pet'] : null;
    $preferred_date = $_POST['appt_date'] ?? '';
    $preferred_time = $_POST['appt_time'] ?? '09:00:00';
    $status = 'pending';

    if (empty($appointment_type) || empty($preferred_date)) {
        echo json_encode(['success' => false, 'message' => 'Appointment type and date are required.']);
        exit;
    }

    $pet_name = null;
    if ($pet_id) {
        $pnStmt = $conn->prepare("SELECT pet_name FROM pets WHERE pet_id = ?");
        $pnStmt->bind_param("i", $pet_id);
        $pnStmt->execute();
        $pnr = $pnStmt->get_result();
        if ($pnr && $pnrRow = $pnr->fetch_assoc()) $pet_name = $pnrRow['pet_name'];
        $pnStmt->close();
    }

    $refId = generateReferenceId($conn, '#APT-', 'appointments');
    $stmt = $conn->prepare("INSERT INTO appointments (user_id, appointment_type, pet_id, pet_name, preferred_date, preferred_time, status, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isisssss", $user_id, $appointment_type, $pet_id, $pet_name, $preferred_date, $preferred_time, $status, $refId);
    
    if ($stmt->execute()) {
        $appt_id = $conn->insert_id;
        
        // Log activity
        $action = "Appointment Requested";
        $details = "Requested ".ucfirst($appointment_type)." appointment (ID: ".$refId.")";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->bind_param("iss", $user_id, $action, $details);
        $logStmt->execute();

        echo json_encode(['success' => true, 'message' => 'Appointment requested successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to request appointment. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
