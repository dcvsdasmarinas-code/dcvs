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
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/programs/conflicts.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$startTime = $_GET['start_time'] ?? '';
$endTime = $_GET['end_time'] ?? '';
$staffRequired = (int)($_GET['staff_required_count'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$isValidDate = static function ($date) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
};

if (!$isValidDate($startDate) || !$isValidDate($endDate) || $startDate > $endDate
    || !preg_match('/^\d{2}:\d{2}$/', $startTime)
    || !preg_match('/^\d{2}:\d{2}$/', $endTime)
    || $startTime >= $endTime
    || $staffRequired < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid program dates, hours, and required staff count are required.']);
    exit;
}

$appointments = dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, $programId, $startTime, $endTime, $staffRequired);
$reports = dcvsProgramReportWarnings($conn, $startDate, $endDate);
$scheduleUnchanged = false;
if ($programId > 0) {
    $programStmt = $conn->prepare("SELECT start_date, end_date, program_start_time, program_end_time, staff_required_count FROM programs WHERE program_id = ?");
    if ($programStmt) {
        $programStmt->bind_param('i', $programId);
        $programStmt->execute();
        $currentProgram = $programStmt->get_result()->fetch_assoc();
        $programStmt->close();
        $scheduleUnchanged = $currentProgram
            && $currentProgram['start_date'] === $startDate
            && $currentProgram['end_date'] === $endDate
            && substr((string)$currentProgram['program_start_time'], 0, 5) === $startTime
            && substr((string)$currentProgram['program_end_time'], 0, 5) === $endTime
            && (int)$currentProgram['staff_required_count'] === $staffRequired;
    }
}

echo json_encode([
    'success' => true,
    'schedule_unchanged' => $scheduleUnchanged,
    'appointments' => $appointments,
    'reports' => $reports,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
