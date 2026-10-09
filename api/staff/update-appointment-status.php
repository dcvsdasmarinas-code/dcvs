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

header('Content-Type: application/json');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';
require_once __DIR__ . '/../../includes/waitlist.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';
$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
$ids = array_unique($ids);

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No appointments selected.']);
    exit;
}

$allowed = ['complete', 'cancel', 'reschedule', 'approve', 'reject', 'archive'];
if (!in_array($action, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
$staffId = $_SESSION['user_id'] ?? null;

function logActivity($conn, $staffId, $action, $details, $ip) {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("isss", $staffId, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

if ($action === 'approve') {
    $staffMessage = trim($_POST['staff_message'] ?? '');
    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, urgency_level, preferred_date, preferred_time, status FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
    $selStmt->bind_param($types, ...$ids);
    $selStmt->execute();
    $res = $selStmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $selStmt->close();

    $pending = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'pending') $pending[] = $r;
    }
    if (empty($pending)) {
        echo json_encode(['success' => false, 'message' => 'Selected appointment(s) are not in pending status.']);
        exit;
    }

    foreach ($pending as $appointment) {
        $urgency = $appointment['urgency_level'] ?? 'routine';
        $time = substr($appointment['preferred_time'], 0, 5);
        $isAvailable = $urgency === 'urgent'
            ? dcvsUrgentSlotAvailable($conn, $appointment['preferred_date'], $time)
            : dcvsSlotAvailable($conn, $appointment['preferred_date'], $time);
        $capacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $appointment['preferred_date'], $time);
        $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
        if (!$isAvailable
            || dcvsAppointmentSlotCount($conn, $appointment['preferred_date'], $time, 0, $urgency) > $capacity
            || dcvsAppointmentDayCount($conn, $appointment['preferred_date'], 0, $urgency) > $dailyLimit) {
            echo json_encode(['success' => false, 'message' => 'Cannot approve: ' . date('M d, Y', strtotime($appointment['preferred_date'])) . ' exceeds the available ' . $urgency . ' appointment capacity.']);
            exit;
        }
    }

    $targetIds = array_column($pending, 'appointment_id');
    $tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
    $tTypes = str_repeat('i', count($targetIds));

    $updStmt = $conn->prepare("UPDATE appointments SET status = 'scheduled' WHERE appointment_id IN ($tPlaceholders) AND status = 'pending'");
    $updStmt->bind_param($tTypes, ...$targetIds);
    $ok = $updStmt->execute() && $updStmt->affected_rows > 0;
    $updStmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to approve appointment(s).']);
        exit;
    }

    foreach ($pending as $r) {
        $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
        $notifTitle = $r['appointment_type'] === 'adoption' ? 'Adoption Appointment Approved' : 'Appointment Approved';
        $notifMsg = "Your " . $r['appointment_type'] . " appointment has been approved and scheduled on "
            . date('F j, Y', strtotime($r['preferred_date'])) . " at " . date('g:i A', strtotime($r['preferred_time'])) . ".";
        if (!empty($staffMessage)) $notifMsg .= "\n\nStaff Message: $staffMessage";
        createNotification($conn, $r['user_id'], 'appointment_update', $notifTitle, $notifMsg, 'appointments', $r['appointment_id']);
        sendAppointmentEmail($conn, $r['appointment_id'], 'scheduled', $staffMessage, $staffName);
        sendAppointmentSms($conn, $r['appointment_id'], 'scheduled', $staffName, $staffMessage);
    }

    logActivity($conn, $staffId, 'Appointment Approved', 'Approved ' . count($pending) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($pending) . ' appointment(s) approved.']);
    exit;
}

if ($action === 'reject') {
    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, urgency_level, preferred_date, preferred_time, status FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
    $selStmt->bind_param($types, ...$ids);
    $selStmt->execute();
    $res = $selStmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $selStmt->close();

    $active = [];
    foreach ($rows as $r) {
        if (in_array($r['status'], ['pending', 'approved', 'scheduled', 'rescheduled'], true)) $active[] = $r;
    }
    if (empty($active)) {
        echo json_encode(['success' => false, 'message' => 'Selected appointment(s) cannot be rejected from their current status.']);
        exit;
    }

    $targetIds = array_column($active, 'appointment_id');
    $tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
    $tTypes = str_repeat('i', count($targetIds));

    $updStmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id IN ($tPlaceholders) AND status IN ('pending','approved','scheduled','rescheduled')");
    $updStmt->bind_param($tTypes, ...$targetIds);
    $ok = $updStmt->execute() && $updStmt->affected_rows > 0;
    $updStmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to reject appointment(s).']);
        exit;
    }

    foreach ($active as $r) {
        $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
        $notifTitle = 'Appointment Rejected';
        $notifMsg = "Your " . $r['appointment_type'] . " appointment on " . date('F j, Y', strtotime($r['preferred_date'])) . " at " . date('g:i A', strtotime($r['preferred_time'])) . " was not approved.";
        createNotification($conn, $r['user_id'], 'appointment_update', $notifTitle, $notifMsg, 'appointments', $r['appointment_id']);
        sendAppointmentEmail($conn, $r['appointment_id'], 'cancelled', '', $staffName);
        sendAppointmentSms($conn, $r['appointment_id'], 'cancelled', $staffName);
        dcvsNotifyWaitlistForSlot($conn, $r['preferred_date'], $r['preferred_time'], $r['appointment_type'], $r['urgency_level'] ?? 'routine');
    }

    logActivity($conn, $staffId, 'Appointment Rejected', 'Rejected ' . count($active) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($active) . ' appointment(s) rejected.']);
    exit;
}

if ($action === 'reschedule') {
    $date = trim($_POST['preferred_date'] ?? '');
    $time = trim($_POST['preferred_time'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        echo json_encode(['success' => false, 'message' => 'Invalid date.']);
        exit;
    }
    if (!dcvsIsStandardTime($time)) {
        echo json_encode(['success' => false, 'message' => 'Invalid time.']);
        exit;
    }
    $time = substr($time, 0, 5) . ':00';

    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, urgency_level, preferred_date, preferred_time, status FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
    $selStmt->bind_param($types, ...$ids);
    $selStmt->execute();
    $res = $selStmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $selStmt->close();

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'Appointment(s) not found.']);
        exit;
    }

    $capacityStatuses = "'" . implode("','", dcvsCapacityStatuses()) . "'";
    $targetsByUrgency = ['routine' => 0, 'urgent' => 0];
    foreach ($rows as $row) $targetsByUrgency[$row['urgency_level'] ?? 'routine']++;
    $dailyStmt = $conn->prepare("SELECT urgency_level, COUNT(*) AS c FROM appointments
        WHERE preferred_date = ? AND status IN ($capacityStatuses) AND archived_at IS NULL
          AND appointment_id NOT IN ($placeholders) GROUP BY urgency_level");
    $dailyStmt->bind_param('s' . $types, $date, ...$ids);
    $dailyStmt->execute();
    $dailyCounts = ['routine' => 0, 'urgent' => 0];
    $dailyResult = $dailyStmt->get_result();
    while ($countRow = $dailyResult->fetch_assoc()) $dailyCounts[$countRow['urgency_level']] = (int)$countRow['c'];
    $dailyStmt->close();

    $slotStmt = $conn->prepare("SELECT urgency_level, COUNT(*) AS c FROM appointments
        WHERE preferred_date = ? AND preferred_time = ? AND status IN ($capacityStatuses) AND archived_at IS NULL
          AND appointment_id NOT IN ($placeholders) GROUP BY urgency_level");
    $slotStmt->bind_param('ss' . $types, $date, $time, ...$ids);
    $slotStmt->execute();
    $slotCounts = ['routine' => 0, 'urgent' => 0];
    $slotResult = $slotStmt->get_result();
    while ($countRow = $slotResult->fetch_assoc()) $slotCounts[$countRow['urgency_level']] = (int)$countRow['c'];
    $slotStmt->close();

    $timeKey = substr($time, 0, 5);
    foreach ($targetsByUrgency as $urgency => $targetCount) {
        if ($targetCount === 0) continue;
        $open = $urgency === 'urgent'
            ? dcvsUrgentSlotAvailable($conn, $date, $timeKey)
            : dcvsSlotAvailable($conn, $date, $timeKey);
        $slotLimit = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $timeKey);
        $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
        if (!$open || $slotCounts[$urgency] + $targetCount > $slotLimit
            || $dailyCounts[$urgency] + $targetCount > $dailyLimit) {
            echo json_encode(['success' => false, 'message' => 'That date and time does not have enough ' . $urgency . ' appointment capacity for the selected residents.']);
            exit;
        }
    }

    $updStmt = $conn->prepare("UPDATE appointments SET preferred_date = ?, preferred_time = ?, status = 'rescheduled' WHERE appointment_id IN ($placeholders)");
    $updStmt->bind_param('ss' . $types, $date, $time, ...$ids);
    $ok = $updStmt->execute() && $updStmt->affected_rows > 0;
    $updStmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to reschedule. The appointment may have been updated already.']);
        exit;
    }

    $newDate = date('F j, Y', strtotime($date));
    $newTime = date('g:i A', strtotime($time));
    foreach ($rows as $r) {
        $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
        $title = $r['appointment_type'] === 'adoption' ? 'Adoption Appointment Rescheduled' : 'Appointment Rescheduled';
        $msg = "Your " . $r['appointment_type'] . " appointment has been rescheduled to " . $newDate . " at " . $newTime . ".";
        createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
        sendAppointmentEmail($conn, $r['appointment_id'], 'rescheduled', '', $staffName);
        sendAppointmentSms($conn, $r['appointment_id'], 'rescheduled', $staffName);
        dcvsNotifyWaitlistForSlot($conn, $r['preferred_date'], $r['preferred_time'], $r['appointment_type'], $r['urgency_level'] ?? 'routine');
    }

    logActivity($conn, $staffId, 'Appointment Rescheduled', 'Rescheduled ' . count($rows) . ' appointment(s) to ' . $date . ' ' . $time . '.', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($rows) . ' appointment(s) rescheduled.']);
    exit;
}

if ($action === 'archive') {
    $slotStmt = $conn->prepare("SELECT appointment_type, urgency_level, preferred_date, preferred_time FROM appointments
        WHERE appointment_id IN ($placeholders) AND archived_at IS NULL
          AND status IN ('scheduled','rescheduled')");
    $slotStmt->bind_param($types, ...$ids);
    $slotStmt->execute();
    $slotsToRelease = $slotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $slotStmt->close();
    $stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW() WHERE appointment_id IN ($placeholders) AND archived_at IS NULL AND status IN ('scheduled','rescheduled')");
    $stmt->bind_param($types, ...$ids);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Only scheduled appointments can be archived.']);
        exit;
    }
    foreach ($slotsToRelease as $releasedSlot) {
        dcvsNotifyWaitlistForSlot($conn, $releasedSlot['preferred_date'], $releasedSlot['preferred_time'], $releasedSlot['appointment_type'], $releasedSlot['urgency_level'] ?? 'routine');
    }
    logActivity($conn, $staffId, 'Appointment Archived', 'Archived ' . count($ids) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($ids) . ' appointment(s) archived.']);
    exit;
}

$newStatus = $action === 'complete' ? 'completed' : 'cancelled';

$selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, preferred_date, preferred_time, status FROM appointments WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
$selStmt->bind_param($types, ...$ids);
$selStmt->execute();
$res = $selStmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$selStmt->close();

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'Appointment(s) not found.']);
    exit;
}

$validFrom = $newStatus === 'cancelled'
    ? ['pending', 'approved', 'scheduled', 'rescheduled']
    : ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress'];

$targets = [];
foreach ($rows as $r) {
    if (in_array($r['status'], $validFrom, true)) $targets[] = $r;
}

if (empty($targets)) {
    echo json_encode(['success' => false, 'message' => 'Selected appointment(s) cannot be updated from their current status.']);
    exit;
}

$targetIds = array_column($targets, 'appointment_id');
$tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
$tTypes = str_repeat('i', count($targetIds));

$updStmt = $conn->prepare("UPDATE appointments SET status = '$newStatus' WHERE appointment_id IN ($tPlaceholders) AND status IN ('pending','approved','scheduled','rescheduled','in_progress')");
$updStmt->bind_param($tTypes, ...$targetIds);
$ok = $updStmt->execute() && $updStmt->affected_rows > 0;
$updStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update appointment status.']);
    exit;
}

$actionLabel = $newStatus === 'completed' ? 'Completed' : 'Cancelled';
foreach ($targets as $r) {
    $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
    $title = $newStatus === 'completed' ? 'Appointment Completed' : 'Appointment Cancelled';
    $msg = $newStatus === 'completed'
        ? "Your " . $r['appointment_type'] . " appointment (" . $refId . ") has been marked as completed."
        : "Your " . $r['appointment_type'] . " appointment on " . date('F j, Y', strtotime($r['preferred_date'])) . " at " . date('g:i A', strtotime($r['preferred_time'])) . " has been cancelled.";
    createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
    sendAppointmentEmail($conn, $r['appointment_id'], $newStatus, '', $staffName);
    sendAppointmentSms($conn, $r['appointment_id'], $newStatus, $staffName);
}
if ($newStatus === 'cancelled') {
    foreach ($targets as $r) dcvsNotifyWaitlistForSlot($conn, $r['preferred_date'], $r['preferred_time'], $r['appointment_type'], $r['urgency_level'] ?? 'routine');
}

logActivity($conn, $staffId, 'Appointment ' . $actionLabel, $actionLabel . ' ' . count($targets) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
echo json_encode(['success' => true, 'message' => count($targets) . ' appointment(s) marked ' . strtolower($actionLabel) . '.']);
exit;