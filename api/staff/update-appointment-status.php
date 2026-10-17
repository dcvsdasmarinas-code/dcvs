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

$allowed = ['complete', 'no_show', 'cancel', 'reschedule', 'approve', 'reject', 'archive'];
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
    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, pet_id, appointment_type, urgency_level,
        preferred_date, preferred_time, status FROM appointments
        WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
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
    if (count($pending) !== 1) {
        echo json_encode(['success' => false, 'message' => 'Approve appointments individually so capacity can be checked for each final schedule.']);
        exit;
    }
    $appointment = $pending[0];
    $urgency = $appointment['urgency_level'] ?: 'routine';
    $date = $appointment['preferred_date'];
    $time = substr($appointment['preferred_time'], 0, 5);
    if (!dcvsIsStandardTime($time) || strtotime($date . ' ' . $time) <= time()) {
        echo json_encode(['success' => false, 'message' => 'The requested appointment time has passed or is invalid.']);
        exit;
    }
    $open = $urgency === 'urgent'
        ? dcvsUrgentSlotAvailable($conn, $date, $time)
        : dcvsSlotAvailable($conn, $date, $time);
    if (!$open) {
        echo json_encode(['success' => false, 'message' => dcvsSlotUnavailableReason($conn, $date, $time)]);
        exit;
    }
    $duplicate = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ?
        AND COALESCE(scheduled_date, preferred_date) = ?
        AND COALESCE(scheduled_time, preferred_time) = ?
        AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
        AND archived_at IS NULL AND appointment_id <> ?");
    $duplicate->bind_param('issi', $appointment['user_id'], $date, $time, $appointment['appointment_id']);
    $duplicate->execute();
    $hasConflict = (int)$duplicate->get_result()->fetch_row()[0] > 0;
    $duplicate->close();
    if ($hasConflict) {
        echo json_encode(['success' => false, 'message' => 'This resident already has another appointment at the requested date and time.']);
        exit;
    }
    $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
    $dailyFull = dcvsAppointmentDayCount($conn, $date, $appointment['appointment_id'], $urgency) >= $dailyLimit;
    $slotCapacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $time);
    $slotFull = dcvsAppointmentSlotCount($conn, $date, $time, $appointment['appointment_id'], $urgency) >= $slotCapacity;
    $nextStatus = 'scheduled';
    if ($dailyFull || $slotFull) {
        if (empty($appointment['pet_id'])) {
            echo json_encode(['success' => false, 'message' => 'Capacity is full, but this request has no linked pet and cannot be added to the pet-required waitlist. It remains pending.']);
            exit;
        }
        $conn->begin_transaction();
        $waitlist = $conn->prepare("INSERT INTO appointment_waitlist
            (user_id, pet_id, appointment_type, urgency_level, preferred_date, preferred_time, status)
            VALUES (?, ?, ?, ?, ?, ?, 'waiting')");
        $waitTime = $time . ':00';
        $waitlist->bind_param('iissss', $appointment['user_id'], $appointment['pet_id'], $appointment['appointment_type'], $urgency, $date, $waitTime);
        $inserted = $waitlist->execute();
        $waitlist->close();
        if (!$inserted) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Could not add this request to the waitlist.']);
            exit;
        }
        $update = $conn->prepare("UPDATE appointments SET status = 'waitlisted', scheduled_date = NULL, scheduled_time = NULL
            WHERE appointment_id = ? AND status = 'pending'");
        $update->bind_param('i', $appointment['appointment_id']);
        $updated = $update->execute() && $update->affected_rows === 1;
        $update->close();
        if (!$updated) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'The request changed before it could be moved to the waitlist.']);
            exit;
        }
        $conn->commit();
        $nextStatus = 'waitlisted';
        createNotification($conn, $appointment['user_id'], 'appointment_update', 'Appointment Added to Waitlist',
            'Your requested appointment time is full. Your appointment request has been added to the waitlist.',
            'appointments', $appointment['appointment_id']);
        logActivity($conn, $staffId, 'Appointment Waitlisted', 'Moved appointment #' . $appointment['appointment_id'] . ' to the waitlist.', $_SERVER['REMOTE_ADDR']);
        echo json_encode(['success' => true, 'status' => $nextStatus, 'message' => 'The requested date or time is full. The appointment was moved to the waitlist.']);
        exit;
    }

    $scheduledTime = $time . ':00';
    $updStmt = $conn->prepare("UPDATE appointments SET status = 'scheduled', scheduled_date = ?, scheduled_time = ?, cancelled_by = NULL
        WHERE appointment_id = ? AND status = 'pending'");
    $updStmt->bind_param('ssi', $date, $scheduledTime, $appointment['appointment_id']);
    $ok = $updStmt->execute() && $updStmt->affected_rows === 1;
    $updStmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to schedule the appointment.']);
        exit;
    }
    $notifTitle = $appointment['appointment_type'] === 'adoption' ? 'Adoption Appointment Approved' : 'Appointment Approved';
    $notifMsg = 'Your ' . $appointment['appointment_type'] . ' appointment has been scheduled for '
        . date('F j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($scheduledTime)) . '.';
    if ($staffMessage !== '') $notifMsg .= "\n\nStaff Message: " . $staffMessage;
    createNotification($conn, $appointment['user_id'], 'appointment_update', $notifTitle, $notifMsg, 'appointments', $appointment['appointment_id']);
    sendAppointmentEmail($conn, $appointment['appointment_id'], 'scheduled', $staffMessage, $staffName);
    sendAppointmentSms($conn, $appointment['appointment_id'], 'scheduled', $staffName, $staffMessage);
    logActivity($conn, $staffId, 'Appointment Approved', 'Scheduled appointment #' . $appointment['appointment_id'] . ' for ' . $date . ' ' . $scheduledTime . '.', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'status' => 'scheduled', 'message' => 'Appointment scheduled.']);
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
    foreach ($rows as $r) if ($r['status'] === 'pending') $active[] = $r;
    if (empty($active)) {
        echo json_encode(['success' => false, 'message' => 'Selected appointment(s) cannot be rejected from their current status.']);
        exit;
    }

    $targetIds = array_column($active, 'appointment_id');
    $tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
    $tTypes = str_repeat('i', count($targetIds));

    $updStmt = $conn->prepare("UPDATE appointments SET status = 'rejected' WHERE appointment_id IN ($tPlaceholders) AND status = 'pending'");
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
        sendAppointmentEmail($conn, $r['appointment_id'], 'rejected', '', $staffName);
        sendAppointmentSms($conn, $r['appointment_id'], 'rejected', $staffName);
    }

    logActivity($conn, $staffId, 'Appointment Rejected', 'Rejected ' . count($active) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($active) . ' appointment(s) rejected.']);
    exit;
}

if ($action === 'reschedule') {
    $date = trim($_POST['scheduled_date'] ?? $_POST['preferred_date'] ?? '');
    $time = trim($_POST['scheduled_time'] ?? $_POST['preferred_time'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        echo json_encode(['success' => false, 'message' => 'Invalid date.']);
        exit;
    }
    if (!dcvsIsStandardTime($time)) {
        echo json_encode(['success' => false, 'message' => 'Invalid time.']);
        exit;
    }
    if (strtotime($date . ' ' . $time) <= time()) {
        echo json_encode(['success' => false, 'message' => 'Choose a future appointment date and time.']);
        exit;
    }
    $time = substr($time, 0, 5) . ':00';

    $selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, urgency_level,
        preferred_date, preferred_time, scheduled_date, scheduled_time, status FROM appointments
        WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
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

    $reschedulableStatuses = ['pending', 'approved', 'scheduled', 'rescheduled'];
    if (count($rows) !== count($ids) || count(array_filter($rows, function ($row) use ($reschedulableStatuses) {
        return in_array($row['status'], $reschedulableStatuses, true);
    })) !== count($rows)) {
        echo json_encode(['success' => false, 'message' => 'Only pending, approved, scheduled, or rescheduled appointments can be rescheduled.']);
        exit;
    }

    $capacityStatuses = "'" . implode("','", dcvsCapacityStatuses()) . "'";
    $targetsByUrgency = ['routine' => 0, 'urgent' => 0];
    foreach ($rows as $row) $targetsByUrgency[$row['urgency_level'] ?? 'routine']++;
    $dailyStmt = $conn->prepare("SELECT urgency_level, COUNT(*) AS c FROM appointments
        WHERE COALESCE(scheduled_date, preferred_date) = ? AND status IN ($capacityStatuses) AND archived_at IS NULL
          AND appointment_id NOT IN ($placeholders) GROUP BY urgency_level");
    $dailyStmt->bind_param('s' . $types, $date, ...$ids);
    $dailyStmt->execute();
    $dailyCounts = ['routine' => 0, 'urgent' => 0];
    $dailyResult = $dailyStmt->get_result();
    while ($countRow = $dailyResult->fetch_assoc()) $dailyCounts[$countRow['urgency_level']] = (int)$countRow['c'];
    $dailyStmt->close();

    $slotStmt = $conn->prepare("SELECT urgency_level, COUNT(*) AS c FROM appointments
        WHERE COALESCE(scheduled_date, preferred_date) = ? AND COALESCE(scheduled_time, preferred_time) = ? AND status IN ($capacityStatuses) AND archived_at IS NULL
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

    $conn->begin_transaction();
    $updStmt = $conn->prepare("UPDATE appointments SET scheduled_date = ?, scheduled_time = ?, status = 'rescheduled'
        WHERE appointment_id IN ($placeholders) AND status IN ('pending','approved','scheduled','rescheduled') AND archived_at IS NULL");
    $updStmt->bind_param('ss' . $types, $date, $time, ...$ids);
    $ok = $updStmt->execute() && $updStmt->affected_rows === count($rows);
    $updStmt->close();

    if (!$ok) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to reschedule. The appointment may have been updated already.']);
        exit;
    }
    $conn->commit();

    $newDate = date('F j, Y', strtotime($date));
    $newTime = date('g:i A', strtotime($time));
    foreach ($rows as $r) {
        $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
        $title = $r['appointment_type'] === 'adoption' ? 'Adoption Appointment Rescheduled' : 'Appointment Rescheduled';
        $msg = "Your " . $r['appointment_type'] . " appointment has been rescheduled to " . $newDate . " at " . $newTime . ".";
        createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
        sendAppointmentEmail($conn, $r['appointment_id'], 'rescheduled', '', $staffName);
        sendAppointmentSms($conn, $r['appointment_id'], 'rescheduled', $staffName);
        dcvsNotifyWaitlistForSlot($conn, $r['scheduled_date'] ?: $r['preferred_date'], $r['scheduled_time'] ?: $r['preferred_time'], $r['appointment_type'], $r['urgency_level'] ?? 'routine');
    }

    logActivity($conn, $staffId, 'Appointment Rescheduled', 'Rescheduled ' . count($rows) . ' appointment(s) to ' . $date . ' ' . $time . '.', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($rows) . ' appointment(s) rescheduled.']);
    exit;
}

if ($action === 'archive') {
    $slotStmt = $conn->prepare("SELECT appointment_type, urgency_level, COALESCE(scheduled_date, preferred_date) AS slot_date,
        COALESCE(scheduled_time, preferred_time) AS slot_time FROM appointments
        WHERE appointment_id IN ($placeholders) AND archived_at IS NULL
          AND status IN ('completed','no_show','cancelled','rejected')");
    $slotStmt->bind_param($types, ...$ids);
    $slotStmt->execute();
    $slotsToRelease = $slotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $slotStmt->close();
    $stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW() WHERE appointment_id IN ($placeholders) AND archived_at IS NULL AND status IN ('completed','no_show','cancelled','rejected')");
    $stmt->bind_param($types, ...$ids);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Only completed, no-show, cancelled, or rejected appointments can be archived.']);
        exit;
    }
    foreach ($slotsToRelease as $releasedSlot) {
        dcvsNotifyWaitlistForSlot($conn, $releasedSlot['slot_date'], $releasedSlot['slot_time'], $releasedSlot['appointment_type'], $releasedSlot['urgency_level'] ?? 'routine');
    }
    logActivity($conn, $staffId, 'Appointment Archived', 'Archived ' . count($ids) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
    echo json_encode(['success' => true, 'message' => count($ids) . ' appointment(s) archived.']);
    exit;
}

$newStatus = $action === 'complete' ? 'completed' : ($action === 'no_show' ? 'no_show' : 'cancelled');

$selStmt = $conn->prepare("SELECT appointment_id, reference_id, user_id, appointment_type, urgency_level,
    preferred_date, preferred_time, scheduled_date, scheduled_time, status FROM appointments
    WHERE appointment_id IN ($placeholders) AND archived_at IS NULL");
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
    ? ['pending', 'approved', 'scheduled', 'rescheduled', 'in_progress', 'action_needed']
    : ['scheduled', 'rescheduled', 'in_progress'];

$targets = [];
foreach ($rows as $r) {
    if (in_array($r['status'], $validFrom, true)) $targets[] = $r;
}

if (empty($targets)) {
    echo json_encode(['success' => false, 'message' => 'Selected appointment(s) cannot be updated from their current status.']);
    exit;
}

if ($newStatus === 'completed' || $newStatus === 'no_show') {
    foreach ($targets as $r) {
        $date = $r['scheduled_date'] ?: $r['preferred_date'];
        $time = $r['scheduled_time'] ?: $r['preferred_time'];
        if (strtotime($date . ' ' . $time) > time()) {
            echo json_encode(['success' => false, 'message' => 'Only staff can mark an appointment completed or no-show after its scheduled date and time.']);
            exit;
        }
    }
}

$targetIds = array_column($targets, 'appointment_id');
$tPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
$tTypes = str_repeat('i', count($targetIds));

$updateSql = $newStatus === 'cancelled'
    ? "UPDATE appointments SET status = 'cancelled', cancelled_by = 'staff' WHERE appointment_id IN ($tPlaceholders)"
    : "UPDATE appointments SET status = '$newStatus' WHERE appointment_id IN ($tPlaceholders)";
$updStmt = $conn->prepare($updateSql);
$updStmt->bind_param($tTypes, ...$targetIds);
$ok = $updStmt->execute() && $updStmt->affected_rows > 0;
$updStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update appointment status.']);
    exit;
}

$actionLabel = $newStatus === 'completed' ? 'Completed' : ($newStatus === 'no_show' ? 'No-show' : 'Cancelled');
foreach ($targets as $r) {
    $refId = $r['reference_id'] ?? fallbackRefId('#APT-', $r['appointment_id']);
    $title = $newStatus === 'completed' ? 'Appointment Completed' : ($newStatus === 'no_show' ? 'Appointment Marked No-show' : 'Appointment Cancelled');
    $appointmentDate = $r['scheduled_date'] ?: $r['preferred_date'];
    $appointmentTime = $r['scheduled_time'] ?: $r['preferred_time'];
    $msg = $newStatus === 'completed'
        ? "Your " . $r['appointment_type'] . " appointment (" . $refId . ") has been marked as completed by DCVS staff."
        : ($newStatus === 'no_show'
            ? "Your " . $r['appointment_type'] . " appointment (" . $refId . ") was marked as no-show by DCVS staff."
            : "Your " . $r['appointment_type'] . " appointment on " . date('F j, Y', strtotime($appointmentDate)) . " at " . date('g:i A', strtotime($appointmentTime)) . " has been cancelled by DCVS staff.");
    createNotification($conn, $r['user_id'], 'appointment_update', $title, $msg, 'appointments', $r['appointment_id']);
    sendAppointmentEmail($conn, $r['appointment_id'], $newStatus, '', $staffName);
    sendAppointmentSms($conn, $r['appointment_id'], $newStatus, $staffName);
}
if ($newStatus === 'cancelled') {
    foreach ($targets as $r) dcvsNotifyWaitlistForSlot($conn, $r['scheduled_date'] ?: $r['preferred_date'], $r['scheduled_time'] ?: $r['preferred_time'], $r['appointment_type'], $r['urgency_level'] ?? 'routine');
}

logActivity($conn, $staffId, 'Appointment ' . $actionLabel, $actionLabel . ' ' . count($targets) . ' appointment(s).', $_SERVER['REMOTE_ADDR']);
echo json_encode(['success' => true, 'message' => count($targets) . ' appointment(s) marked ' . strtolower($actionLabel) . '.']);
exit;