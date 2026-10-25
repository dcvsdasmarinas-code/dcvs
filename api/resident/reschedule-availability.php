<?php
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
require_once __DIR__ . '/../../includes/reschedule-requests.php';

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$date = trim((string)($_GET['date'] ?? ''));
$month = trim((string)($_GET['month'] ?? ''));
$dateValue = DateTime::createFromFormat('!Y-m-d', $date);
if ($appointmentId < 1 || (!$month && (!$dateValue || $dateValue->format('Y-m-d') !== $date))
    || ($month && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
        || !checkdate((int)substr($month, 5, 2), 1, (int)substr($month, 0, 4))))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid appointment date.']);
    exit;
}
$owner = $conn->prepare("SELECT a.status, COALESCE(a.scheduled_date, a.preferred_date) AS appointment_date,
        COALESCE(scheduled_time, preferred_time) AS appointment_time,
        TIMESTAMP(COALESCE(a.scheduled_date, a.preferred_date), COALESCE(a.scheduled_time, a.preferred_time)) > DATE_ADD(NOW(), INTERVAL 24 HOUR) AS cutoff_open,
        cooldown.cooldown_until AS reschedule_cooldown_until,
        COALESCE(cooldown.cooldown_until > NOW(), 0) AS reschedule_cooldown_active
    FROM appointments a
    LEFT JOIN (
        SELECT approved.appointment_id,
            DATE_ADD(COALESCE(approved.responded_at, approved.reviewed_at, approved.requested_at), INTERVAL 24 HOUR) AS cooldown_until
        FROM appointment_reschedule_requests approved
        JOIN (
            SELECT appointment_id, MAX(request_id) AS request_id
            FROM appointment_reschedule_requests
            WHERE status = 'approved'
            GROUP BY appointment_id
        ) latest_approved ON latest_approved.request_id = approved.request_id
    ) cooldown ON cooldown.appointment_id = a.appointment_id
    WHERE a.appointment_id = ? AND a.user_id = ? AND a.archived_at IS NULL");
if (!$owner) {
    error_log('Could not prepare reschedule availability ownership check: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check appointment access.']);
    exit;
}
$owner->bind_param('ii', $appointmentId, $_SESSION['user_id']);
if (!$owner->execute()) {
    error_log('Could not check reschedule availability ownership: ' . $owner->error);
    $owner->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check appointment access.']);
    exit;
}
$appointment = $owner->get_result()->fetch_assoc();
$owner->close();
if (!$appointment || !in_array($appointment['status'], ['approved', 'scheduled', 'rescheduled'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This appointment cannot be rescheduled.']);
    exit;
}
if ((bool)$appointment['reschedule_cooldown_active']) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'You must wait 24 hours after a reschedule is approved before requesting another change.',
        'reschedule_cooldown_until' => $appointment['reschedule_cooldown_until'],
    ]);
    exit;
}
if (!(bool)$appointment['cutoff_open']) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Appointments within 24 hours cannot be rescheduled online. Please contact DCVS.']);
    exit;
}

try {
    $serverToday = date('Y-m-d');
    if ($month) {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $weekly = dcvsWeeklyTemplate($conn);
        $staffCount = dcvsActiveStaffCount($conn);
        $overrides = [];
        $overrideStmt = $conn->prepare('SELECT slot_date, slot_time, status FROM appointment_slots WHERE slot_date BETWEEN ? AND ?');
        if (!$overrideStmt) throw new RuntimeException('Could not load date-specific availability.');
        $overrideStmt->bind_param('ss', $monthStart, $monthEnd);
        if (!$overrideStmt->execute()) {
            $overrideStmt->close();
            throw new RuntimeException('Could not load date-specific availability.');
        }
        $overrideRows = $overrideStmt->get_result();
        while ($row = $overrideRows->fetch_assoc()) {
            $overrides[$row['slot_date']][substr($row['slot_time'], 0, 5)] = $row['status'] === 'available';
        }
        $overrideStmt->close();

        $programs = [];
        $programStmt = $conn->prepare("SELECT start_date, end_date FROM programs
            WHERE status IN ('scheduled', 'live') AND start_date <= ? AND end_date >= ?");
        if (!$programStmt) throw new RuntimeException('Could not check program availability.');
        $programStmt->bind_param('ss', $monthEnd, $monthStart);
        if (!$programStmt->execute()) {
            $programStmt->close();
            throw new RuntimeException('Could not check program availability.');
        }
        $programRows = $programStmt->get_result();
        while ($row = $programRows->fetch_assoc()) $programs[] = $row;
        $programStmt->close();

        $counts = [];
        $heldStatuses = dcvsCapacityStatuses();
        $statusSql = "'" . implode("','", array_map('addslashes', $heldStatuses)) . "'";
        $countStmt = $conn->prepare("SELECT COALESCE(scheduled_date, preferred_date) AS date_key,
                COALESCE(scheduled_time, preferred_time) AS time_key,
                COALESCE(urgency_level, 'routine') AS urgency, COUNT(*) AS appointment_count
            FROM appointments
            WHERE COALESCE(scheduled_date, preferred_date) BETWEEN ? AND ?
                AND status IN ($statusSql) AND archived_at IS NULL AND appointment_id <> ?
            GROUP BY date_key, time_key, urgency");
        if (!$countStmt) throw new RuntimeException('Could not check appointment capacity.');
        $countStmt->bind_param('ssi', $monthStart, $monthEnd, $appointmentId);
        if (!$countStmt->execute()) {
            $countStmt->close();
            throw new RuntimeException('Could not check appointment capacity.');
        }
        $countRows = $countStmt->get_result();
        $slotCounts = [];
        while ($row = $countRows->fetch_assoc()) {
            $appointmentCount = (int)$row['appointment_count'];
            $urgencyKey = $row['urgency'];
            $timeKey = substr($row['time_key'], 0, 5);
            $counts[$row['date_key']][$urgencyKey] = ($counts[$row['date_key']][$urgencyKey] ?? 0) + $appointmentCount;
            $slotCounts[$row['date_key']][$timeKey][$urgencyKey] = $appointmentCount;
        }
        $countStmt->close();

        $calendarDays = [];
        for ($day = new DateTime($monthStart); $day->format('Y-m-d') <= $monthEnd; $day->modify('+1 day')) {
            $dayKey = $day->format('Y-m-d');
            $hasProgram = false;
            foreach ($programs as $program) {
                if ($program['start_date'] <= $dayKey && $program['end_date'] >= $dayKey) {
                    $hasProgram = true;
                    break;
                }
            }
            $dayOverrides = $overrides[$dayKey] ?? [];
            $routineCount = $counts[$dayKey]['routine'] ?? 0;
            $urgentCount = $counts[$dayKey]['urgent'] ?? 0;
            $isPastDate = $dayKey < $serverToday;
            $clinicOpen = $staffCount > 0 && !$isPastDate;
            $hasAvailableSlot = false;
            if ($clinicOpen) {
                $clinicOpen = false;
                foreach (DCVS_STANDARD_TIMES as $time) {
                    if (!dcvsClinicSlotAvailable($conn, $dayKey, $time, $weekly, $dayOverrides)) continue;
                    $clinicOpen = true;
                    $timeCounts = $slotCounts[$dayKey][$time] ?? [];
                    if ($hasProgram) {
                        $routineAvailable = $routineCount < 2 && ($timeCounts['routine'] ?? 0) < $staffCount;
                        $urgentAvailable = $urgentCount < 3 && ($timeCounts['urgent'] ?? 0) < 1;
                    } else {
                        $slotCount = array_sum($timeCounts);
                        $routineAvailable = $routineCount + $urgentCount < 5 && $slotCount < $staffCount;
                        $urgentAvailable = false;
                    }
                    if ($routineAvailable || $urgentAvailable) {
                        $hasAvailableSlot = true;
                        break;
                    }
                }
            }
            $calendarDays[] = [
                'date' => $dayKey,
                'program_day' => $hasProgram,
                'past' => $isPastDate,
                'closed' => !$clinicOpen,
                'full' => $clinicOpen && !$hasAvailableSlot,
            ];
        }
        echo json_encode(['success' => true, 'month' => $month, 'server_today' => $serverToday, 'days' => $calendarDays]);
        exit;
    }

    $hasProgram = dcvsDateHasActiveProgram($conn, $date);
    $weekly = dcvsWeeklyTemplate($conn);
    $overrides = dcvsDateOverrides($conn, $date);
    $dayAvailable = $date >= $serverToday;
    $daily = [];
    foreach ($hasProgram ? ['routine', 'urgent'] : ['routine'] as $priority) {
        $capacity = dcvsAppointmentDailyCapacity($conn, $date, $priority, $appointmentId);
        $daily[$priority] = [
            'count' => $capacity['count'],
            'limit' => $capacity['limit'],
            'available' => $capacity['available'],
        ];
    }
    $slots = [];
    foreach (DCVS_STANDARD_TIMES as $time) {
        $slots[] = [
            'time' => $time,
            'open' => $dayAvailable && dcvsClinicSlotAvailable($conn, $date, $time, $weekly, $overrides),
            'routine' => $dayAvailable ? dcvsRescheduleSlotAvailability($conn, $appointmentId, $date, $time, 'routine') : ['available' => false],
            'urgent' => $hasProgram && $dayAvailable ? dcvsRescheduleSlotAvailability($conn, $appointmentId, $date, $time, 'urgent') : ['available' => false],
        ];
    }
    echo json_encode([
        'success' => true,
        'date' => $date,
        'past' => !$dayAvailable,
        'server_today' => $serverToday,
        'program_day' => $hasProgram,
        'closed' => !$dayAvailable || !array_reduce($slots, function ($open, $slot) { return $open || $slot['open']; }, false),
        'daily' => $daily,
        'slots' => $slots,
        'current_date' => $appointment['appointment_date'],
        'current_time' => substr($appointment['appointment_time'], 0, 5),
    ]);
} catch (Throwable $error) {
    error_log('Resident reschedule availability failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check available times. Please try again.']);
}
