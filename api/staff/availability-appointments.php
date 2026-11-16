<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid month.']);
    exit;
}

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));
$statuses = "'" . implode("','", dcvsCapacityStatuses()) . "'";
$stmt = $conn->prepare("
    SELECT COALESCE(a.scheduled_date, a.preferred_date) AS appointment_date,
           COALESCE(a.scheduled_time, a.preferred_time) AS appointment_time,
           a.appointment_type, COALESCE(a.urgency_level, 'routine') AS urgency_level,
           a.status, b.barangay_name
    FROM appointments a
    LEFT JOIN users u ON u.user_id = a.user_id
    LEFT JOIN barangays b ON b.barangay_id = u.barangay_id
    WHERE COALESCE(a.scheduled_date, a.preferred_date) BETWEEN ? AND ?
      AND a.status IN ($statuses)
      AND a.archived_at IS NULL
    ORDER BY appointment_date, appointment_time, a.appointment_id
");
if (!$stmt) {
    error_log('Failed to prepare staff schedule preview appointments: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load appointment details.']);
    exit;
}

$stmt->bind_param('ss', $firstDay, $lastDay);
if (!$stmt->execute()) {
    error_log('Failed to load staff schedule preview appointments: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load appointment details.']);
    exit;
}

$appointmentsByDate = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $date = $row['appointment_date'];
    $appointmentsByDate[$date][] = [
        'time' => substr((string)$row['appointment_time'], 0, 5),
        'appointment_type' => $row['appointment_type'],
        'urgency_level' => $row['urgency_level'],
        'status' => $row['status'],
        'barangay_name' => $row['barangay_name'] ?? '',
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['ok' => true, 'appointments' => $appointmentsByDate]);
