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
require_once __DIR__ . '/../../includes/appointment-workflow.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing ID parameter']);
    exit;
}

dcvsMarkOverdueAppointmentsNoShow($conn);

// Support both sequential ID and reference_id format (e.g. '12', '#APT-0012', '#APT-X7K29F', 'X7K29F')
$id_input = trim($_GET['id']);
$id_input = str_ireplace('#APT-', '', $id_input);

// Check if the input looks like a reference_id (alphanumeric, 6+ chars) or a numeric ID
$row = null;
if (ctype_alnum($id_input) && strlen($id_input) >= 6) {
    // Search by reference_id
    $refId = '#APT-' . strtoupper($id_input);
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time,
       a.scheduled_date, a.scheduled_time, a.original_schedule_date, a.original_schedule_time,
       a.cancelled_by, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
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
            SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time,
       a.scheduled_date, a.scheduled_time, a.original_schedule_date, a.original_schedule_time,
       a.cancelled_by, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
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
                    SELECT a.appointment_id, a.reference_id, a.user_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time,
       a.scheduled_date, a.scheduled_time, a.original_schedule_date, a.original_schedule_time,
       a.cancelled_by, a.status, a.notes, u.full_name, u.reference_id AS user_reference_id, u.email, u.phone, u.address, u.avatar_url, b.barangay_name,
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
    $rescheduleStmt = $conn->prepare("SELECT request_id, requested_date, requested_time, requested_urgency,
            resident_reason, status AS reschedule_request_status, staff_message, offered_date,
            offered_time, offered_urgency, requested_at
        FROM appointment_reschedule_requests
        WHERE appointment_id = ? AND status IN ('pending','offered')
        ORDER BY request_id DESC LIMIT 1");
    if ($rescheduleStmt) {
        $rescheduleStmt->bind_param('i', $row['appointment_id']);
        if ($rescheduleStmt->execute()) {
            $rescheduleRequest = $rescheduleStmt->get_result()->fetch_assoc();
            if ($rescheduleRequest) $row = array_merge($row, $rescheduleRequest);
        } else {
            error_log('Could not load appointment reschedule request: ' . $rescheduleStmt->error);
            $rescheduleStmt->close();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not load appointment reschedule details.']);
            exit;
        }
        $rescheduleStmt->close();
    } else {
        error_log('Could not prepare appointment reschedule lookup: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not load appointment reschedule details.']);
        exit;
    }

    $outcomeDate = $row['scheduled_date'] ?: $row['preferred_date'];
    $outcomeTime = $row['scheduled_time'] ?: $row['preferred_time'];
    $row['can_mark_outcome'] = in_array($row['status'], ['scheduled', 'rescheduled', 'in_progress'], true)
        && strtotime($outcomeDate . ' ' . $outcomeTime) <= time();
    $rescheduleWindowStart = strtotime($outcomeDate . ' ' . $outcomeTime);
    $row['reschedule_within_24h'] = $rescheduleWindowStart !== false
        && $rescheduleWindowStart > time()
        && $rescheduleWindowStart <= time() + 86400;

    $appointmentHistory = [];
    $historyError = false;
    $historyStmt = $conn->prepare("
        SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time,
               COALESCE(a.scheduled_date, a.preferred_date) AS scheduled_date,
               COALESCE(a.scheduled_time, a.preferred_time) AS scheduled_time,
               a.created_at, COALESCE(a.pet_name, p.pet_name, '') AS pet_name
        FROM appointments a
        LEFT JOIN pets p ON p.pet_id = a.pet_id
        WHERE a.user_id = ?
        ORDER BY a.created_at DESC, a.appointment_id DESC
    ");
    if ($historyStmt) {
        $historyStmt->bind_param('i', $row['user_id']);
        if ($historyStmt->execute()) {
            $historyResult = $historyStmt->get_result();
            while ($historyRow = $historyResult->fetch_assoc()) {
                $scheduleDate = $historyRow['scheduled_date'] ?? '';
                $scheduleTime = $historyRow['scheduled_time'] ?? '';
                $schedule = '';
                if ($scheduleDate !== '' && $scheduleTime !== '') {
                    $scheduleTimestamp = strtotime($scheduleDate . ' ' . $scheduleTime);
                    if ($scheduleTimestamp !== false) {
                        $schedule = date('F j, Y', $scheduleTimestamp) . ' at ' . date('g:i A', $scheduleTimestamp);
                    }
                }
                $description = 'Requested a ' . ucfirst($historyRow['appointment_type']) . ' appointment';
                if ($historyRow['pet_name'] !== '') {
                    $description .= ' for ' . $historyRow['pet_name'];
                }
                if ($schedule !== '') {
                    $description .= '. Preferred schedule: ' . $schedule;
                }
                $appointmentHistory[] = [
                    'title' => 'Appointment requested',
                    'message' => $description . '.',
                    'created_at' => $historyRow['created_at'],
                    'source' => 'Resident request',
                ];
            }
        } else {
            error_log('Could not load resident appointment records: ' . $historyStmt->error);
            $historyError = true;
        }
        $historyStmt->close();
    } else {
        error_log('Could not prepare resident appointment history query: ' . $conn->error);
        $historyError = true;
    }

    if (!$historyError) {
        $notificationStmt = $conn->prepare("
            SELECT n.title, n.message, n.created_at,
                   COALESCE(a.pet_name, p.pet_name, '') AS pet_name
            FROM notifications n
            INNER JOIN appointments a
                ON a.appointment_id = n.reference_id
               AND a.user_id = ?
            LEFT JOIN pets p ON p.pet_id = a.pet_id
            WHERE n.user_id = ?
              AND n.reference_type = 'appointments'
            ORDER BY n.created_at DESC, n.notification_id DESC
        ");
        if ($notificationStmt) {
            $notificationStmt->bind_param('ii', $row['user_id'], $row['user_id']);
            if ($notificationStmt->execute()) {
                $notificationResult = $notificationStmt->get_result();
                while ($notificationRow = $notificationResult->fetch_assoc()) {
                    $message = $notificationRow['message'] ?? '';
                    $petName = trim($notificationRow['pet_name'] ?? '');
                    if ($petName !== '') {
                        $message = preg_replace(
                            '/\(#APT-[A-Z0-9]+\)/i',
                            '(' . $petName . ')',
                            $message
                        );
                    }
                    $appointmentHistory[] = [
                        'title' => $notificationRow['title'],
                        'message' => $message,
                        'created_at' => $notificationRow['created_at'],
                        'source' => 'Appointment update',
                    ];
                }
            } else {
                error_log('Could not load appointment update notifications: ' . $notificationStmt->error);
                $historyError = true;
            }
            $notificationStmt->close();
        } else {
            error_log('Could not prepare appointment update history query: ' . $conn->error);
            $historyError = true;
        }
    }

    usort($appointmentHistory, function ($left, $right) {
        return strtotime($right['created_at'] ?? '') <=> strtotime($left['created_at'] ?? '');
    });
    $row['appointment_history'] = $appointmentHistory;
    $row['appointment_history_error'] = $historyError;
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Appointment request not found.']);
}
?>