<?php
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/waitlist.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'resident') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in as a resident to join the waitlist.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
dcvsExpireWaitlistClaims($conn, $userId);
$petId = (int)($_POST['pet_id'] ?? 0);
$appointmentType = trim($_POST['appointment_type'] ?? '');
$urgency = trim($_POST['urgency_level'] ?? 'routine');
$date = trim($_POST['preferred_date'] ?? '');
$time = trim($_POST['preferred_time'] ?? '');
$validTypes = ['adoption', 'kapon', 'vaccination', 'checkup', 'deworming'];
if ($petId <= 0
    || !in_array($appointmentType, $validTypes, true)
    || !in_array($urgency, ['routine', 'urgent'], true)
    || ($urgency === 'urgent' && !in_array($appointmentType, ['vaccination', 'checkup', 'deworming'], true))
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))
    || $date < date('Y-m-d')
    || ($time !== '' && !dcvsIsStandardTime($time))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid pet, service, urgency, date, and optional appointment time.']);
    exit;
}

$petStmt = $conn->prepare("SELECT pet_id FROM pets WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL");
if (!$petStmt) {
    error_log('Failed to prepare waitlist pet ownership check: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not add you to the waitlist. Please try again.']);
    exit;
}
$petStmt->bind_param('ii', $petId, $userId);
$petStmt->execute();
$petStmt->store_result();
$ownsPet = $petStmt->num_rows > 0;
$petStmt->close();
if (!$ownsPet) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'The selected pet is not registered to your account.']);
    exit;
}
if ($urgency === 'urgent' && !dcvsDateHasActiveProgram($conn, $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Urgent priority is only available on dates with a scheduled program.']);
    exit;
}

$fullTime = $time === '' ? null : (dcvsTimeKey($time) . ':00');
$duplicateStmt = $conn->prepare("SELECT waitlist_id, status FROM appointment_waitlist
    WHERE user_id = ? AND pet_id = ? AND appointment_type = ? AND urgency_level = ? AND preferred_date = ?
      AND ((preferred_time IS NULL AND ? IS NULL) OR preferred_time = ?)
      AND status IN ('waiting','notified') LIMIT 1");
if (!$duplicateStmt) {
    error_log('Failed to prepare waitlist duplicate check: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not add you to the waitlist. Please try again.']);
    exit;
}
$duplicateStmt->bind_param('iisssss', $userId, $petId, $appointmentType, $urgency, $date, $fullTime, $fullTime);
$duplicateStmt->execute();
$existing = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();
if ($existing) {
    echo json_encode([
        'success' => true,
        'message' => $existing['status'] === 'notified' ? 'A slot opened for you. Please book before your claim expires.' : 'You are already on the waitlist for this date.',
        'waitlist_id' => (int)$existing['waitlist_id'],
        'status' => $existing['status'],
    ]);
    exit;
}

$insertStmt = $conn->prepare("INSERT INTO appointment_waitlist (user_id, pet_id, appointment_type, urgency_level, preferred_date, preferred_time) VALUES (?, ?, ?, ?, ?, ?)");
if (!$insertStmt) {
    error_log('Failed to prepare waitlist insert: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not add you to the waitlist. Please try again.']);
    exit;
}
$insertStmt->bind_param('iissss', $userId, $petId, $appointmentType, $urgency, $date, $fullTime);
if (!$insertStmt->execute()) {
    error_log('Failed to add waitlist entry: ' . $insertStmt->error);
    $insertStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not add you to the waitlist. Please try again.']);
    exit;
}
$waitlistId = $insertStmt->insert_id;
$insertStmt->close();
echo json_encode(['success' => true, 'message' => 'You are on the waitlist. We will notify you if a matching slot opens.', 'waitlist_id' => $waitlistId, 'status' => 'waiting']);
