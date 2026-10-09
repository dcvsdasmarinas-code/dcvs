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

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing ID parameter']);
    exit;
}

// Support both sequential ID and reference_id format (e.g. '12', '#APT-0012', '#APT-X7K29F', 'X7K29F')
$id_input = trim($_GET['id']);
$id_input = str_ireplace('#APT-', '', $id_input);

// Check if the input looks like a reference_id (alphanumeric, 6+ chars) or a numeric ID
$row = null;
if (ctype_alnum($id_input) && strlen($id_input) >= 6) {
    // Search by reference_id
    $refId = '#APT-' . strtoupper($id_input);
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
       COALESCE(a.pet_name, p.pet_name, '-') AS pet_name, a.pet_id, p.pet_type, p.breed, p.gender, p.age, p.vaccination_status, p.photo_url
FROM appointments a
JOIN users u ON a.user_id = u.user_id
LEFT JOIN pets p ON a.pet_id = p.pet_id
LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE a.reference_id = ?
    ");
    $stmt->bind_param("s", $refId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
}

if (!$row) {
    // Fallback: search by numeric ID
    $appointment_id = (int)$id_input;
    if ($appointment_id > 0) {
        $stmt = $conn->prepare("
            SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
       COALESCE(a.pet_name, p.pet_name, '-') AS pet_name, a.pet_id, p.pet_type, p.breed, p.gender, p.age, p.vaccination_status, p.photo_url
FROM appointments a
JOIN users u ON a.user_id = u.user_id
LEFT JOIN pets p ON a.pet_id = p.pet_id
LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
            WHERE a.appointment_id = ?
        ");
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
    }
}

if (!$row) {
    // Fallback: some appointments have an empty reference_id in the DB but display
    // a computed fallback in the table (fallbackRefId in dashboard-staff.php).
    // Check all such appointments and compare against the computed fallback.
    $searchCode = strtoupper(ltrim($id_input, '#'));
    $searchCode = preg_replace('/^APT-/', '', $searchCode);
    if (strlen($searchCode) === 6 && ctype_alnum($searchCode)) {
        $stmt = $conn->prepare("SELECT appointment_id FROM appointments WHERE reference_id IS NULL OR reference_id = ''");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($emptyRef = $result->fetch_assoc()) {
            if (fallbackRefId('#APT-', $emptyRef['appointment_id']) === '#APT-' . $searchCode) {
                $fullStmt = $conn->prepare("
                    SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
       COALESCE(a.pet_name, p.pet_name, '-') AS pet_name, a.pet_id, p.pet_type, p.breed, p.gender, p.age, p.vaccination_status, p.photo_url
FROM appointments a
JOIN users u ON a.user_id = u.user_id
LEFT JOIN pets p ON a.pet_id = p.pet_id
LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
                    WHERE a.appointment_id = ?
                ");
                $fullStmt->bind_param("i", $emptyRef['appointment_id']);
                $fullStmt->execute();
                $fullResult = $fullStmt->get_result();
                $row = $fullResult->fetch_assoc();
                $fullStmt->close();
                break;
            }
        }
        $stmt->close();
    }
}

if ($row) {
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Appointment request not found.']);
}
?>