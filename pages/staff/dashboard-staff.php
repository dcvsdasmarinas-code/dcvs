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
require_once __DIR__ . '/../../sms-sender.php';
require_once __DIR__ . '/../../includes/staff-formatters.php';
require_once __DIR__ . '/../../includes/reports/staff-data.php';
require_once __DIR__ . '/../../includes/programs/conflicts.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/appointment-reschedule-offers.php';
require_once __DIR__ . '/../../includes/appointment-workflow.php';
require_once __DIR__ . '/../../includes/waitlist.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
$appBaseUrl = dcvsStaffAppBaseUrl();

// Fresh login â�?��?T force Analytics tab on first dashboard load
$freshDashboard = !empty($_SESSION['fresh_dashboard']);
if ($freshDashboard) unset($_SESSION['fresh_dashboard']);

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . $appBaseUrl . '/login-staff.php');
    exit;
}
// Check if user has staff role
if ($_SESSION['role'] !== 'staff') {
    header('Location: ' . $appBaseUrl . '/login-staff.php');
    exit;
}

// Re-verify role from database
$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $_SESSION['user_id']);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        $_SESSION = array();
        session_destroy();
        header('Location: ' . $appBaseUrl . '/login-staff.php');
        exit;
    }
}
$roleCheck->close();
dcvsExpireAppointmentRescheduleOffers($conn);
dcvsMarkOverdueAppointmentsNoShow($conn);

loadDcvsEnv();
if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1') {
    $initializeValidatedWorkflows = $conn->query("
        INSERT IGNORE INTO report_response_workflow (report_id, response_mode, state, barangay_proposed)
        SELECT r.report_id, 'undecided', 'awaiting_dcvs_decision', 0
        FROM reports r
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        WHERE r.status = 'validated' AND r.archived_at IS NULL AND rw.report_id IS NULL
    ");
    if (!$initializeValidatedWorkflows) {
        error_log('Could not initialize existing validated report workflows: ' . $conn->error);
    }

    $reminderCutoff = date('Y-m-d H:i:s', time() - 12 * 60 * 60);
    $dueQuery = $conn->prepare("SELECT rw.report_id, rw.state, r.reference_id, r.barangay_id
        FROM report_response_workflow rw
        JOIN reports r ON r.report_id = rw.report_id
        WHERE r.archived_at IS NULL AND (rw.last_reminded_at IS NULL OR rw.last_reminded_at < ?)
          AND ((rw.state IN ('barangay_response_approved', 'barangay_responding') AND rw.response_deadline < NOW())
            OR (rw.state = 'barangay_responding' AND rw.progress_deadline < NOW())
            OR (rw.state = 'awaiting_turnover' AND rw.turnover_deadline < NOW()))");
    if (!$dueQuery) {
        error_log('Could not load overdue barangay response cases: ' . $conn->error);
    } else {
        $dueQuery->bind_param('s', $reminderCutoff);
    if ($dueQuery->execute()) {
        $dueResult = $dueQuery->get_result();
        while ($dueCase = $dueResult->fetch_assoc()) {
            $caseReference = $dueCase['reference_id'] ?: ('Report #' . (int)$dueCase['report_id']);
            $notice = 'Response deadline exceeded for ' . $caseReference . '. Please follow up.';
            $barangayUsers = $conn->prepare("SELECT user_id FROM users WHERE role = 'barangay' AND barangay_id = ? AND status = 'active'");
            if ($barangayUsers) {
                $barangayUsers->bind_param('i', $dueCase['barangay_id']);
                if ($barangayUsers->execute()) {
                    $recipients = $barangayUsers->get_result();
                    while ($recipient = $recipients->fetch_assoc()) {
                        createNotification($conn, (int)$recipient['user_id'], 'response_deadline', 'Case Response Overdue', $notice, 'report', (int)$dueCase['report_id']);
                    }
                } else {
                    error_log('Could not load barangay recipients for response deadline reminder: ' . $barangayUsers->error);
                }
                $barangayUsers->close();
            }
            $staffUsers = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
            if ($staffUsers) {
                while ($recipient = $staffUsers->fetch_assoc()) {
                    createNotification($conn, (int)$recipient['user_id'], 'response_deadline', 'Case Response Overdue', $notice, 'report', (int)$dueCase['report_id']);
                }
            }
            $reminded = $conn->prepare("UPDATE report_response_workflow SET last_reminded_at = NOW() WHERE report_id = ?");
            if ($reminded) {
                $reportIdForReminder = (int)$dueCase['report_id'];
                $reminded->bind_param('i', $reportIdForReminder);
                if (!$reminded->execute()) error_log('Could not mark response deadline reminder: ' . $reminded->error);
                $reminded->close();
            }
        }
        $dueQuery->close();
    } else {
        error_log('Could not execute overdue response query: ' . $dueQuery->error);
        $dueQuery->close();
    }
    }
}
if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1') {
    $turnoverReminderCutoff = date('Y-m-d H:i:s', time() - 12 * 60 * 60);
    $overdueTurnovers = $conn->prepare("SELECT t.turnover_task_id, t.report_id, r.reference_id, r.barangay_id
        FROM report_turnover_tasks t
        JOIN reports r ON r.report_id = t.report_id
        WHERE t.status = 'pending' AND t.due_at IS NOT NULL AND t.due_at < NOW()
          AND (t.last_reminded_at IS NULL OR t.last_reminded_at < ?)
          AND r.archived_at IS NULL");
    if (!$overdueTurnovers) {
        error_log('Could not prepare overdue turnover task reminders: ' . $conn->error);
    } else {
        $overdueTurnovers->bind_param('s', $turnoverReminderCutoff);
        if (!$overdueTurnovers->execute()) {
            error_log('Could not load overdue turnover tasks: ' . $overdueTurnovers->error);
        } else {
            $overdueResult = $overdueTurnovers->get_result();
            while ($overdueTask = $overdueResult->fetch_assoc()) {
                $reportIdForTurnover = (int)$overdueTask['report_id'];
                $turnoverReference = $overdueTask['reference_id'] ?: ('Report #' . $reportIdForTurnover);
                $notice = 'DCVS receipt is overdue for the live animal turnover task on ' . $turnoverReference . '.';
                $staffRecipients = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
                if ($staffRecipients) {
                    while ($recipient = $staffRecipients->fetch_assoc()) {
                        createNotification($conn, (int)$recipient['user_id'], 'turnover_overdue', 'Turnover Task Overdue', $notice, 'report', $reportIdForTurnover);
                    }
                } else {
                    error_log('Could not load DCVS recipients for an overdue turnover task: ' . $conn->error);
                }
                $barangayRecipients = $conn->prepare("SELECT user_id FROM users WHERE role = 'barangay' AND barangay_id = ? AND status = 'active'");
                if ($barangayRecipients) {
                    $barangayRecipients->bind_param('i', $overdueTask['barangay_id']);
                    if ($barangayRecipients->execute()) {
                        $recipientResult = $barangayRecipients->get_result();
                        while ($recipient = $recipientResult->fetch_assoc()) {
                            createNotification($conn, (int)$recipient['user_id'], 'turnover_overdue', 'Turnover Task Overdue', $notice, 'report', $reportIdForTurnover);
                        }
                    } else {
                        error_log('Could not load barangay recipients for an overdue turnover task: ' . $barangayRecipients->error);
                    }
                    $barangayRecipients->close();
                } else {
                    error_log('Could not prepare barangay recipients for an overdue turnover task: ' . $conn->error);
                }
                $overdueHistory = $conn->prepare("INSERT INTO report_response_history
                    (report_id, actor_id, actor_role, action, from_state, to_state, reason)
                    VALUES (?, NULL, 'system', 'turnover_overdue', 'resolved', 'resolved', ?)");
                if ($overdueHistory) {
                    $overdueHistory->bind_param('is', $reportIdForTurnover, $notice);
                    if (!$overdueHistory->execute()) {
                        error_log('Could not record overdue turnover event in report history: ' . $overdueHistory->error);
                    }
                    $overdueHistory->close();
                } else {
                    error_log('Could not prepare overdue turnover event in report history: ' . $conn->error);
                }
                $remindTask = $conn->prepare("UPDATE report_turnover_tasks SET last_reminded_at = NOW() WHERE turnover_task_id = ? AND status = 'pending'");
                if ($remindTask) {
                    $remindTask->bind_param('i', $overdueTask['turnover_task_id']);
                    if (!$remindTask->execute()) error_log('Could not mark turnover reminder sent: ' . $remindTask->error);
                    $remindTask->close();
                } else {
                    error_log('Could not prepare turnover reminder timestamp update: ' . $conn->error);
                }
            }
        }
        $overdueTurnovers->close();
    }
}

// ── Handle SMS settings toggle ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_sms'])) {
    $newValue = ($_POST['sms_enabled'] ?? '0') === '1' ? '1' : '0';
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('sms_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if (!$stmt) {
        error_log('Unable to prepare SMS notification setting update: ' . $conn->error);
        if ($isAjaxRequest) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Could not save the SMS notification setting.']);
            exit;
        }
        header('Location: dashboard-staff.php#account-settings');
        exit;
    }
    $stmt->bind_param("ss", $newValue, $newValue);
    $saved = $stmt->execute();
    $stmt->close();
    if (!$saved) {
        error_log('Unable to save SMS notification setting: ' . $conn->error);
        if ($isAjaxRequest) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Could not save the SMS notification setting.']);
            exit;
        }
    } elseif ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'sms_enabled' => $newValue === '1']);
        exit;
    }
    header('Location: dashboard-staff.php#account-settings');
    exit;
}

// Fetch current staff profile for Account Settings
$staffEmail = '';
$staffPhone = '';
$staffAddress = '';
$staffQuery = $conn->prepare("SELECT email, phone, address FROM users WHERE user_id = ?");
if ($staffQuery) {
    $staffQuery->bind_param("i", $_SESSION['user_id']);
    $staffQuery->execute();
    $staffResult = $staffQuery->get_result();
    if ($staffRow = $staffResult->fetch_assoc()) {
        $staffEmail = $staffRow['email'] ?? '';
        $staffPhone = $staffRow['phone'] ?? '';
        $staffAddress = $staffRow['address'] ?? '';
    }
    $staffQuery->close();
}


// Pagination settings
$perPage = 10;

// Fetch users for the Users Tab - CHRONOLOGICAL (oldest first = No.1)
$users = [];
$usersTotal = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'resident'")->fetch_row()[0] ?? 0;
$usersQuery = $conn->query("SELECT user_id, reference_id, full_name, email, phone, address, created_at FROM users WHERE role = 'resident' ORDER BY created_at ASC, user_id ASC");
if ($usersQuery) {
    while($row = $usersQuery->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch owners with pet count for Client & Pet DB Tab - GROUPED BY OWNER
$clientPets = [];
$petsQuery = $conn->query("
    SELECT 
        u.user_id,
        u.reference_id,
        u.full_name,
        u.email,
        u.phone,
        b.barangay_name,
        u.created_at as registration_date,
        COUNT(p.pet_id) as total_pets
    FROM users u
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    LEFT JOIN pets p ON u.user_id = p.owner_id AND p.archived_at IS NULL
    WHERE u.role = 'resident' AND u.status = 'active' AND u.deleted_at IS NULL
    GROUP BY u.user_id
    ORDER BY u.created_at ASC
");
if ($petsQuery) {
    while($row = $petsQuery->fetch_assoc()) {
        $clientPets[] = $row;
    }
}
$ownersTotal = count($clientPets);

$cpdClients = [];
$cpdCq = $conn->query("
    SELECT u.user_id, u.reference_id, u.full_name, u.email, u.phone, u.address,
           u.barangay_id, u.avatar_url, u.status, u.gender, u.birthdate, u.created_at,
           b.barangay_name,
           (SELECT COUNT(*) FROM pets p WHERE p.owner_id = u.user_id AND p.archived_at IS NULL) AS total_pets
    FROM users u
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE u.role = 'resident' AND u.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY u.created_at DESC, u.user_id DESC
");
if ($cpdCq) {
    while ($r = $cpdCq->fetch_assoc()) {
        $cpdClients[] = [
            'id'       => (int)$r['user_id'],
            'ref'      => $r['reference_id'] ?: '#RES-' . fallbackRefId('#RES-', (int)$r['user_id']),
            'name'     => $r['full_name'],
            'email'    => (string)$r['email'],
            'phone'    => (string)$r['phone'],
            'brgy'     => $r['barangay_name'] ?? '',
            'brgyId'   => $r['barangay_id'] !== null ? (int)$r['barangay_id'] : null,
            'address'  => (string)$r['address'],
            'status'   => $r['status'] === 'active' ? 'Active' : 'Inactive',
            'gender'   => (string)$r['gender'],
            'birthdate'=> (string)$r['birthdate'],
            'pets'     => (int)$r['total_pets'],
            'ts'       => strtotime($r['created_at']),
            'dateKey'  => substr((string)$r['created_at'], 0, 10),
            'date'     => date('M d, Y', strtotime($r['created_at'])),
            'initials' => cpdInitials($r['full_name']),
            'avatar'   => dcvsStaffReportMediaUrl($r['avatar_url']),
        ];
    }
}

$cpdPets = [];
$cpdPq = $conn->query("
    SELECT p.pet_id, p.reference_id, p.pet_name, p.pet_type, p.breed, p.age, p.gender,
           p.vaccination_status, p.photo_url, p.created_at, p.owner_id,
           u.full_name AS owner_name, u.reference_id AS owner_ref,
           b.barangay_name
    FROM pets p
    JOIN users u ON p.owner_id = u.user_id
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE p.archived_at IS NULL AND u.archived_at IS NULL AND u.role = 'resident' AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY p.created_at DESC, p.pet_id DESC
");
if ($cpdPq) {
    while ($r = $cpdPq->fetch_assoc()) {
        $type = cpdTypeLabel($r['pet_type']);
        $vax = (string)$r['vaccination_status'];
        $cpdPets[] = [
            'id'        => (int)$r['pet_id'],
            'ref'       => $r['reference_id'] ?: '#PET-' . fallbackRefId('#PET-', (int)$r['pet_id']),
            'name'      => $r['pet_name'],
            'type'      => strtolower((string)$r['pet_type']),
            'typeLabel' => $type,
            'breed'     => (string)$r['breed'],
            'age'       => cpdAgeLabel($r['age']),
            'gender'    => cpdGenderLabel($r['gender']),
            'vax'       => $vax,
            'vaxOk'     => cpdVaxOk($vax),
            'photo'     => dcvsStaffReportMediaUrl($r['photo_url']),
            'ownerId'   => (int)$r['owner_id'],
            'ownerName' => $r['owner_name'],
            'ownerRef'  => $r['owner_ref'] ?: '',
            'brgy'     => $r['barangay_name'] ?? '',
            'brgyId'   => (int)($r['barangay_id'] ?? 0),
            'ts'        => strtotime($r['created_at']),
            'dateKey'  => substr((string)$r['created_at'], 0, 10),
            'date'     => date('M d, Y', strtotime($r['created_at'])),
            'reported' => $r['created_at'] ? date('M d, Y h:i A', strtotime($r['created_at'])) : '',
            'initials'  => cpdInitials($r['pet_name']),
        ];
    }
}

$cpdBarangays = [];
$cpdBq = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($cpdBq) { while ($r = $cpdBq->fetch_assoc()) $cpdBarangays[] = $r; }
$composeBarangays = $cpdBarangays;
$composeResidents = getUsersByRole($conn, 'resident');
$composeBarangayUsers = getUsersByRole($conn, 'barangay');

$kpiClients = count($cpdClients);
$kpiPets    = count($cpdPets);
$kpiAvg     = $kpiClients > 0 ? round($kpiPets / $kpiClients, 2) : 0;
$kpiNew     = 0;
$withPets   = 0;
$nowYm      = date('Y-m');
foreach ($cpdClients as $c) {
    if ($c['pets'] > 0) $withPets++;
    if (date('Y-m', $c['ts']) === $nowYm) $kpiNew++;
}
$noPets    = $kpiClients - $withPets;
$petsVax   = 0;
$petsNoVax = 0;
foreach ($cpdPets as $p) { if ($p['vaxOk']) $petsVax++; else $petsNoVax++; }
$viewOwner = (int)($_GET['view_owner'] ?? 0);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Fetch ALL appointments (search + status filter) - CHRONOLOGICAL
$pendingAppts = [];
$apptStatusFilter = trim($_GET['appt_status'] ?? 'all');
$apptSearchFilter = trim($_GET['appt_search'] ?? '');

// Build WHERE clause
$apptWhere = "a.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL";
if ($apptStatusFilter !== 'all' && $apptStatusFilter !== '') {
    $safeStatus = $conn->real_escape_string($apptStatusFilter);
    if ($safeStatus === 'scheduled') {
        $apptWhere .= " AND a.status IN ('scheduled','approved','in_progress')";
    } else {
        $apptWhere .= " AND a.status = '$safeStatus'";
    }
}
if ($apptSearchFilter !== '') {
    $safeSearch = $conn->real_escape_string($apptSearchFilter);
    $apptWhere .= " AND (a.appointment_id LIKE '%$safeSearch%' OR u.full_name LIKE '%$safeSearch%' OR a.appointment_type LIKE '%$safeSearch%' OR p.pet_name LIKE '%$safeSearch%')";
}

$pendingQuery = $conn->query("
  SELECT a.appointment_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time,
         COALESCE(a.scheduled_date, a.preferred_date) AS scheduled_date,
         COALESCE(a.scheduled_time, a.preferred_time) AS scheduled_time,
         a.cancelled_by, a.status, a.created_at, a.reference_id, u.user_id, u.reference_id AS user_ref, u.full_name, u.email, u.phone, u.avatar_url, p.pet_name, p.photo_url, p.pet_type, p.breed, p.gender, p.age, p.vaccination_status,
         rr.request_id AS reschedule_request_id, rr.status AS reschedule_request_status,
         rr.requested_date AS reschedule_requested_date, rr.requested_time AS reschedule_requested_time,
         rr.requested_urgency AS reschedule_requested_urgency, rr.resident_reason AS reschedule_resident_reason,
         rr.staff_message AS reschedule_staff_message, rr.offered_date AS reschedule_offered_date,
         rr.offered_time AS reschedule_offered_time, rr.offered_urgency AS reschedule_offered_urgency,
         rr.requested_at AS reschedule_requested_at
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    LEFT JOIN appointment_reschedule_requests rr
      ON rr.appointment_id = a.appointment_id AND rr.status IN ('pending','offered')
    WHERE $apptWhere
    ORDER BY a.created_at ASC, a.appointment_id ASC
");
if ($pendingQuery) {
    while($row = $pendingQuery->fetch_assoc()) {
        $row['avatar_url'] = dcvsStaffReportMediaUrl($row['avatar_url']);
        $row['photo_url'] = dcvsStaffReportMediaUrl($row['photo_url']);
        $pendingAppts[] = $row;
    }
}
$pendingTotal = count($pendingAppts);
$rescheduleRequestCount = 0;
$rescheduleCountResult = $conn->query("SELECT COUNT(*) AS request_count
    FROM appointment_reschedule_requests rr
    JOIN appointments a ON a.appointment_id = rr.appointment_id
    WHERE rr.status = 'pending' AND a.archived_at IS NULL
      AND a.status IN ('approved','scheduled','rescheduled')");
if ($rescheduleCountResult) {
    $rescheduleRequestCount = (int)($rescheduleCountResult->fetch_assoc()['request_count'] ?? 0);
}

// Appointment status pill colors (all-clients style: light bg + accent text)
$statusColors = [
    'pending'     => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
    'scheduled'   => ['bg' => '#0E8131', 'color' => '#ffffff', 'border' => '#0E8131'],
    'approved'    => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
    'rescheduled' => ['bg' => '#ede9fe', 'color' => '#6d28d9', 'border' => '#ddd6fe'],
    'action_needed' => ['bg' => '#ffedd5', 'color' => '#9a3412', 'border' => '#fed7aa'],
    'completed'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#bfdbfe'],
    'no_show'     => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
    'cancelled'   => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
    'rejected'    => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
    'waitlisted'  => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
];

// KPI counts for the appointments overview
$apptCounts = ['all' => $pendingTotal, 'pending' => 0, 'scheduled' => 0, 'completed' => 0];
foreach ($pendingAppts as $ar) {
    $as = $ar['status'] ?? '';
    if ($as === 'pending' || $as === 'action_needed') $apptCounts['pending']++;
    elseif ($as === 'scheduled' || $as === 'rescheduled') $apptCounts['scheduled']++;
    elseif ($as === 'completed') $apptCounts['completed']++;
}

// Fetch ALL appointments for calendar initialization
$calendarAppointments = [];
$apptsQuery = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.urgency_level,
           COALESCE(a.scheduled_date, a.preferred_date) AS preferred_date,
           COALESCE(a.scheduled_time, a.preferred_time) AS preferred_time,
           a.status, a.notes, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
");
if ($apptsQuery) {
    while($row = $apptsQuery->fetch_assoc()) {
        $title = ucfirst($row['appointment_type']) . " - " . $row['full_name'];
        $start = $row['preferred_date'] . 'T' . $row['preferred_time'];
        
        $solidColor = '#3b82f6'; // default blue
        $bgColor = 'rgba(59, 130, 246, 0.2)';
        
        if ($row['status'] == 'scheduled' || $row['status'] == 'rescheduled') {
            $solidColor = '#0E8131'; // theme green
            $bgColor = 'rgba(16, 185, 129, 0.2)';
            $bgColor = '#0E8131';
        } elseif ($row['status'] == 'completed') {
            $solidColor = '#6b7280'; // grey
            $bgColor = 'rgba(107, 114, 128, 0.2)';
        } elseif ($row['status'] == 'cancelled') {
            $solidColor = '#ef4444'; // red
            $bgColor = 'rgba(239, 68, 68, 0.2)';
        } elseif ($row['status'] == 'pending') {
            $solidColor = '#f59e0b'; // orange
            $bgColor = 'rgba(245, 158, 11, 0.2)';
        } elseif ($row['status'] == 'rejected' || $row['status'] == 'no_show') {
            $solidColor = '#b91c1c'; // red
            $bgColor = 'rgba(220, 38, 38, 0.15)';
        }

        // 1. Background event to color the whole cell box
        $calendarAppointments[] = [
            'id' => 'bg_' . $row['appointment_id'],
            'start' => $row['preferred_date'], // just the date so it covers all day
            'display' => 'background',
            'backgroundColor' => $bgColor
        ];
        
        // 2. Clickable block event
        $calendarAppointments[] = [
            'id' => $row['appointment_id'],
            'title' => $title,
            'start' => $start,
            'display' => 'block', // forces it to be a block instead of a dot
            'backgroundColor' => $solidColor,
            'borderColor' => $solidColor,
            'extendedProps' => [
                  'type' => $row['appointment_type'],
                  'urgency' => $row['urgency_level'] ?? 'routine',
                  'status' => $row['status'],
                  'client' => $row['full_name'],
                  'pet' => $row['pet_name'] ?? 'N/A',
                  'time' => date('g:i A', strtotime($row['preferred_time'])),
                  'notes' => $row['notes']
              ]
          ];
}
}
// Grey out days where every standard slot is closed (weekly template + overrides)
require_once __DIR__ . '/../../includes/availability.php';
$weeklyTemplate = dcvsWeeklyTemplate($conn);
$blockedDays = [];
$calStart = date('Y-m-d');
$calEnd = date('Y-m-d', strtotime('+3 months'));
$bCursor = $calStart;
while ($bCursor <= $calEnd) {
    $blockedTimes = dcvsBlockedTimes($conn, $bCursor, $weeklyTemplate);
    if (count($blockedTimes) >= count(DCVS_STANDARD_TIMES)) {
        $blockedDays[] = $bCursor;
    }
    $bCursor = date('Y-m-d', strtotime($bCursor . ' +1 day'));
}
foreach ($blockedDays as $bd) {
    $calendarAppointments[] = [
        'id' => 'bg_blocked_' . str_replace('-', '', $bd),
        'start' => $bd,
        'display' => 'background',
        'backgroundColor' => '#e2e8f0'
    ];
}
$calendarEventsJSON = json_encode($calendarAppointments);

// Fetch upcoming appointments for the calendar reminder panel
$upcomingAppts = [];
$upQ = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.urgency_level,
           COALESCE(a.scheduled_date, a.preferred_date) AS preferred_date,
           COALESCE(a.scheduled_time, a.preferred_time) AS preferred_time,
           a.status, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
      AND COALESCE(a.scheduled_date, a.preferred_date) >= CURDATE()
      AND a.status IN ('pending','scheduled','rescheduled')
    ORDER BY COALESCE(a.scheduled_date, a.preferred_date) ASC, COALESCE(a.scheduled_time, a.preferred_time) ASC
    LIMIT 8
");
if ($upQ) {
    while ($r = $upQ->fetch_assoc()) $upcomingAppts[] = $r;
}

// Fetch completed appointments for the calendar reminder panel
$completedAppts = [];
$cpQ = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.urgency_level,
           COALESCE(a.scheduled_date, a.preferred_date) AS preferred_date,
           COALESCE(a.scheduled_time, a.preferred_time) AS preferred_time,
           a.status, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL AND a.status = 'completed' AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY COALESCE(a.scheduled_date, a.preferred_date) DESC, COALESCE(a.scheduled_time, a.preferred_time) DESC
    LIMIT 6
");
if ($cpQ) {
    while ($r = $cpQ->fetch_assoc()) $completedAppts[] = $r;
}

// Fetch validated reports for Barangay Validation
$validatedReports = [];
$vrq = dcvsStaffValidatedReportsQuery($conn);
if ($vrq) {
    while ($row = $vrq->fetch_assoc()) $validatedReports[] = $row;
}

// Fetch all barangay reports (for full list)
$barangayReports = [];
$brq = dcvsStaffBarangayReportsQuery($conn);
if ($brq) {
    while ($row = $brq->fetch_assoc()) $barangayReports[] = $row;
}

// Fetch barangay officers list
$barangayOfficers = [];
$boq = $conn->query("SELECT u.user_id, u.full_name, u.email, u.phone, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.role = 'barangay' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY b.barangay_name ASC");
if ($boq) {
    while ($row = $boq->fetch_assoc()) $barangayOfficers[] = $row;
}

// Fetch active confirmed cases for tracking
$activeCases = [];
$acq = dcvsStaffActiveCasesQuery($conn);
if ($acq) {
    while ($row = $acq->fetch_assoc()) $activeCases[] = $row;
}

// Fetch resolved confirmed cases
$resolvedCases = [];
$rcq = dcvsStaffResolvedCasesQuery($conn);
if ($rcq) {
    while ($row = $rcq->fetch_assoc()) $resolvedCases[] = $row;
}

$validationEvidenceByReportId = [];
$validationEvidenceQuery = $conn->query("
    SELECT a.attachment_id, a.report_id, a.attachment_type, a.attachment_source, a.file_url, a.uploaded_by,
           a.mime_type, a.caption, a.response_history_id
    FROM report_attachments a
    WHERE attachment_source IN ('barangay_validation', 'barangay_secured', 'barangay_turnover', 'barangay_action', 'staff_action')
    ORDER BY a.report_id, a.display_order, a.attachment_id
");
if ($validationEvidenceQuery) {
    while ($attachment = $validationEvidenceQuery->fetch_assoc()) {
        $validationEvidenceByReportId[(int)$attachment['report_id']][] = [
            'type' => (string)$attachment['attachment_type'],
            'url' => in_array((string)$attachment['attachment_source'], ['barangay_action', 'staff_action'], true)
                ? $appBaseUrl . '/api/shared/report-evidence-file.php?id=' . (int)$attachment['attachment_id'] . '&sess=DCVS_STAFF_SESSION'
                : dcvsStaffReportMediaUrl($attachment['file_url']),
            'mimeType' => (string)$attachment['mime_type'],
            'stage' => (string)$attachment['attachment_source'],
            'caption' => (string)($attachment['caption'] ?? ''),
            'responseHistoryId' => (int)($attachment['response_history_id'] ?? 0),
            'isMine' => (int)($attachment['uploaded_by'] ?? 0) === (int)$_SESSION['user_id'],
            'role' => in_array((string)$attachment['attachment_source'], ['barangay_validation', 'barangay_secured', 'barangay_turnover', 'barangay_action'], true)
                ? 'barangay'
                : 'staff',
        ];
    }
} else {
    error_log('Could not load barangay validation evidence for staff report details: ' . $conn->error);
}

$responseHistoryByReportId = [];
if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1') {
    $responseHistoryQuery = $conn->query("
        SELECT h.history_id, h.report_id, h.actor_role, h.action, h.from_state, h.to_state, h.reason,
               h.created_at, u.full_name AS actor_name
        FROM report_response_history h
        LEFT JOIN users u ON u.user_id = h.actor_id
        ORDER BY h.report_id, h.created_at, h.history_id
    ");
    if ($responseHistoryQuery) {
        while ($entry = $responseHistoryQuery->fetch_assoc()) {
            $responseHistoryByReportId[(int)$entry['report_id']][] = [
                'id' => (int)$entry['history_id'],
                'actor' => (string)($entry['actor_name'] ?: ucfirst((string)$entry['actor_role'])),
                'actorRole' => (string)$entry['actor_role'],
                'action' => (string)$entry['action'],
                'fromState' => (string)($entry['from_state'] ?? ''),
                'toState' => (string)$entry['to_state'],
                'reason' => (string)($entry['reason'] ?? ''),
                'createdAt' => date('M d, Y h:i A', strtotime($entry['created_at'])),
                'createdAtRaw' => (string)$entry['created_at'],
            ];
        }
    } else {
        error_log('Could not load response workflow history for staff report details: ' . $conn->error);
    }

    $correctionHistoryQuery = $conn->query("
        SELECT c.report_id, c.reason, c.before_values, c.after_values, c.created_at,
               u.full_name AS actor_name
        FROM report_correction_history c
        LEFT JOIN users u ON u.user_id = c.actor_id
        ORDER BY c.report_id, c.created_at, c.correction_id
    ");
    if ($correctionHistoryQuery) {
        while ($entry = $correctionHistoryQuery->fetch_assoc()) {
            $afterValues = json_decode((string)$entry['after_values'], true);
            $changedFields = is_array($afterValues) ? implode(', ', array_keys($afterValues)) : 'report details';
            $responseHistoryByReportId[(int)$entry['report_id']][] = [
                'id' => 0,
                'actor' => (string)($entry['actor_name'] ?: 'DCVS Staff'),
                'actorRole' => 'staff',
                'action' => 'report_correction',
                'fromState' => '',
                'toState' => '',
                'reason' => 'Reason: ' . (string)$entry['reason'] . ' Changed fields: ' . $changedFields . '.',
                'createdAt' => date('M d, Y h:i A', strtotime($entry['created_at'])),
                'createdAtRaw' => (string)$entry['created_at'],
            ];
        }
    } else {
        error_log('Could not load audited report corrections for staff report details: ' . $conn->error);
    }
}

function dcvsStaffReportMediaUrl($url) {
    global $appBaseUrl;
    $url = trim((string)$url);
    if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return $url;
    }
    if ($appBaseUrl !== '' && ($url === $appBaseUrl || strpos($url, $appBaseUrl . '/') === 0)) {
        return $url;
    }
    return $appBaseUrl . '/' . preg_replace('~^(?:/|\.{1,2}/)+~', '', $url);
}

$reportItems = [];
$rptQ = dcvsStaffReportItemsQuery($conn);
if ($rptQ) {
    while ($r = $rptQ->fetch_assoc()) {
    $reportedAt = !empty($r['incident_at']) ? $r['incident_at'] : $r['created_at'];
        $reportItems[] = [
            'id'       => (int)$r['report_id'],
            'ref'      => $r['reference_id'] ?: '#RPT-' . fallbackRefId('#RPT-', (int)$r['report_id']),
            'type'     => strtolower((string)$r['report_type']),
            'typeLabel'=> rptTypeLabel($r['report_type']),
            'desc'     => (string)$r['description'],
            'structured' => $r['animal_type'] !== null && $r['animal_type'] !== '',
            'animalType' => (string)($r['animal_type'] ?? ''),
            'animalBreed' => (string)($r['animal_breed'] ?? ''),
            'animalCount' => (string)($r['animal_count'] ?? ''),
            'animalBehavior' => (string)($r['animal_behavior'] ?? ''),
            'location' => (string)$r['location_address'],
            'brgy'     => $r['barangay_name'] ?? '',
            'brgyId'   => (int)($r['barangay_id'] ?? 0),
            'createdAt' => (string)$r['created_at'],
            'submittedTs' => strtotime($r['created_at']) * 1000,
            'status'   => rptReportStatus($r['status'], $r['scheduled_at']),
            'statusRaw'=> strtolower((string)$r['status']),
            'statusLabel' => (string)($r['response_state'] ?? '') === 'barangay_response_approved'
                ? 'Accepted by Barangay'
                : ((string)($r['response_state'] ?? '') === 'barangay_responding' && strtolower((string)$r['status']) === 'validated'
                    ? 'Barangay Responding'
                : ((string)($r['response_state'] ?? '') === 'escalated_to_dcvs'
                    ? 'Escalated to DCVS'
                    : ((string)($r['response_state'] ?? '') === 'unable_to_locate'
                        ? 'Unable to Locate'
                        : (in_array(strtolower((string)$r['status']), ['pending', 'validated'], true)
                            ? 'Pending Barangay Review'
                            : rptStatusLabel(rptReportStatus($r['status'], $r['scheduled_at'])))))),
'scheduled' => $r['scheduled_at'] ? date('M d, Y h:i A', strtotime($r['scheduled_at'])) : '',
           'validated'=> $r['validated_at'] ? date('M d, Y h:i A', strtotime($r['validated_at'])) : '',
           'vts'      => $r['validated_at'] ? strtotime($r['validated_at']) : 0,
            'urgency'  => rptUrgencyLabel($r['urgency_level']),
            'urgencyRaw' => strtolower((string)($r['urgency_level'] ?: 'medium')),
            'photo'    => dcvsStaffReportMediaUrl($r['photo_url']),
            'video'    => dcvsStaffReportMediaUrl($r['video_url']),
            'reporter' => $r['reporter_name'],
            'reporterStatus' => strtolower((string)$r['reporter_status']),
            'reporterId' => (int)$r['reporter_id'],
            'reporterRef' => $r['reporter_ref'] ?: '#RES-' . fallbackRefId('#RES-', (int)$r['reporter_id']),
            'phone'    => (string)$r['reporter_phone'],
            'email'    => (string)$r['reporter_email'],
            'avatar'   => dcvsStaffReportMediaUrl($r['avatar_url']),
            'assigned' => $r['confirmed_staff'] ?: ($r['validated_staff'] ?: ''),
            'typeShort'=> strtok(rptTypeLabel($r['report_type']), ' '),
            'brgyNotes'=> (string)$r['barangay_notes'],
            'brgyValidationStatus' => (string)($r['barangay_validation_status'] ?? ''),
            'brgyValidationNotes' => (string)($r['barangay_validation_notes'] ?? ''),
            'brgyValidationEvidence' => $validationEvidenceByReportId[(int)$r['report_id']] ?? [],
            'responseHistory' => $responseHistoryByReportId[(int)$r['report_id']] ?? [],
            'dcvsNotes'=> (string)$r['dcvs_notes'],
            'rejection'=> (string)$r['rejection_reason'],
            'caseId'   => $r['case_id'] !== null ? (int)$r['case_id'] : null,
            'caseStatus'=> (string)$r['case_status'],
            'confirmed'=> $r['confirmed_at'] ? date('M d, Y h:i A', strtotime($r['confirmed_at'])) : '',
            'resolved' => $r['resolved_at'] ? date('M d, Y h:i A', strtotime($r['resolved_at'])) : '',
            'caseNotes'=> (string)$r['case_notes'],
            'responseMode' => (string)($r['response_mode'] ?? ''),
            'responseState' => (string)($r['response_state'] ?? ''),
            'barangayProposed' => (bool)($r['barangay_proposed'] ?? false),
            'responseDeadline' => (string)($r['response_deadline'] ?? ''),
            'progressDeadline' => (string)($r['progress_deadline'] ?? ''),
            'lastProgressAt' => (string)($r['last_progress_at'] ?? ''),
            'securedAt' => !empty($r['secured_at']) ? date('M d, Y g:i A', strtotime($r['secured_at'])) : '',
            'turnoverDeadline' => (string)($r['turnover_deadline'] ?? ''),
            'urgentEscalatedAt' => (string)($r['urgent_escalated_at'] ?? ''),
            'highRiskOverrideAuthorized' => (int)($r['high_risk_override_by'] ?? 0) === (int)$_SESSION['user_id']
                && trim((string)($r['high_risk_override_reason'] ?? '')) !== '',
            'highRiskOverrideReason' => (string)($r['high_risk_override_reason'] ?? ''),
            'highRiskOverrideStaff' => (string)($r['high_risk_override_staff'] ?? ''),
            'dispatchOfficerId' => (int)($r['dispatch_officer_id'] ?? 0),
            'dispatchOfficerName' => (string)($r['dispatch_officer_name'] ?? ''),
            'holdingLocation' => (string)($r['holding_location'] ?? ''),
            'animalCondition' => (string)($r['animal_condition'] ?? ''),
            'turnoverRequestedAt' => (string)($r['turnover_requested_at'] ?? ''),
            'turnoverReceivedAt' => (string)($r['turnover_received_at'] ?? ''),
            'resolutionDetails' => (string)($r['resolution_details'] ?? ''),
            'animalDisposition' => (string)($r['animal_disposition'] ?? ''),
            'resolutionActor' => (string)($r['resolution_actor'] ?? ''),
            'responseResolvedAt' => (string)($r['response_resolved_at'] ?? ''),
            'turnoverTaskId' => (int)($r['turnover_task_id'] ?? 0),
            'turnoverStatus' => (string)($r['turnover_status'] ?? ''),
            'turnoverDueAt' => (string)($r['turnover_due_at'] ?? ''),
            'turnoverDueAtDisplay' => !empty($r['turnover_due_at']) ? date('M d, Y g:i A', strtotime($r['turnover_due_at'])) : '',
            'turnoverMethod' => (string)($r['turnover_method'] ?? ''),
            'turnoverPlannedAt' => !empty($r['turnover_planned_at']) ? date('M d, Y h:i A', strtotime($r['turnover_planned_at'])) : '',
            'turnoverPlannedAtRaw' => (string)($r['turnover_planned_at'] ?? ''),
            'turnoverHandlerName' => (string)($r['turnover_handler_name'] ?? ''),
            'turnoverContactNumber' => (string)($r['turnover_contact_number'] ?? ''),
            'turnoverPickupLocation' => (string)($r['turnover_pickup_location'] ?? ''),
            'turnoverSafeTransport' => (bool)($r['turnover_safe_transport'] ?? false),
            'turnoverReceiptAt' => (string)($r['turnover_receipt_at'] ?? ''),
            'turnoverReceivedAtDisplay' => !empty($r['turnover_receipt_at']) ? date('M d, Y g:i A', strtotime($r['turnover_receipt_at'])) : '',
            'turnoverReceiver' => (string)($r['turnover_receiver'] ?? ''),
            'ts'       => strtotime($reportedAt),
            'date'     => date('M d, Y', strtotime($reportedAt)),
            'incidentDate' => date('M d, Y', strtotime($reportedAt)),
            'submitted'=> $r['created_at'] ? date('M d, Y g:i A', strtotime($r['created_at'])) : '',
            'validatedRaw' => (string)($r['validated_at'] ?? ''),
            'confirmedRaw' => (string)($r['confirmed_at'] ?? ''),
            'scheduledRaw' => (string)($r['scheduled_at'] ?? ''),
            'resolvedRaw' => (string)($r['resolved_at'] ?? ''),
        ];
    }
}
$rptCounts = ['all' => 0, 'active' => 0, 'pending' => 0, 'resolved' => 0];
$activeResidentReportItems = array_filter($reportItems, function($item) { return $item['reporterStatus'] === 'active'; });
foreach ($activeResidentReportItems as $ri) {
    $rptCounts['all']++;
    if (isset($rptCounts[$ri['status']])) $rptCounts[$ri['status']]++;
}

$staffBarangayResponseStates = [
    'barangay_response_approved',
    'barangay_responding',
    'animal_secured',
    'returned_to_barangay',
];

// Forwarded reports stay pending until DCVS confirms them; Barangay-owned cases use their own queue.
$pendingItems = [];
$pendingCounts = ['all' => 0, 'high' => 0, 'week' => 0, 'awaiting' => 0];
$pendingWeekStart = time() - 7 * 86400;
foreach ($activeResidentReportItems as $ri) {
  if (!in_array($ri['statusRaw'], ['pending', 'validated'], true)) continue;
  if (in_array((string)($ri['responseState'] ?? ''), $staffBarangayResponseStates, true)) continue;
  $awaitingConfirmation = $ri['statusRaw'] === 'validated';
  $ri['status'] = 'pending';
  $ri['statusLabel'] = $awaitingConfirmation ? 'Pending DCVS Review' : 'Pending Barangay Review';
  $ri['caseId'] = null;
  $pendingItems[] = $ri;
  $pendingCounts['all']++;
  if ($awaitingConfirmation) $pendingCounts['awaiting']++;
  if (stripos($ri['urgency'], 'high') !== false || stripos($ri['urgency'], 'critical') !== false) $pendingCounts['high']++;
  if ($ri['vts'] >= $pendingWeekStart) $pendingCounts['week']++;
}

// Active cases require a confirmed DCVS case.
$barangayResponseItems = [];
$barangayResponseCounts = ['all' => 0, 'accepted' => 0, 'responding' => 0, 'secured' => 0, 'high' => 0];
foreach ($activeResidentReportItems as $ri) {
    $responseState = (string)($ri['responseState'] ?? '');
    if (!in_array($responseState, $staffBarangayResponseStates, true)) continue;
    if (in_array($ri['statusRaw'], ['resolved', 'rejected'], true)) continue;
    $ri['status'] = 'active';
    $ri['statusLabel'] = [
        'barangay_response_approved' => 'Accepted by Barangay',
        'barangay_responding' => 'Barangay Responding',
        'animal_secured' => 'Animal Secured',
        'returned_to_barangay' => 'Returned to Barangay',
    ][$responseState];
    $ri['caseId'] = $ri['caseId'] ?? (int)$ri['id'];
    $barangayResponseItems[] = $ri;
    $barangayResponseCounts['all']++;
    if (in_array($responseState, ['barangay_response_approved', 'returned_to_barangay'], true)) {
        $barangayResponseCounts['accepted']++;
    } elseif ($responseState === 'barangay_responding') {
        $barangayResponseCounts['responding']++;
    } elseif ($responseState === 'animal_secured') {
        $barangayResponseCounts['secured']++;
    }
    if (stripos($ri['urgency'], 'high') !== false || stripos($ri['urgency'], 'critical') !== false) {
        $barangayResponseCounts['high']++;
    }
}

$activeItems = [];
$activeCounts = ['all' => 0, 'high' => 0, 'sched' => 0, 'overdue' => 0];
foreach ($activeResidentReportItems as $ri) {
    if ($ri['caseStatus'] !== 'active' || !in_array($ri['statusRaw'], ['confirmed', 'scheduled'], true)) continue;
    $responseState = (string)($ri['responseState'] ?? '');
    if (in_array($responseState, $staffBarangayResponseStates, true)) continue;
    $ri['status'] = 'active';
    $ri['statusLabel'] = [
        'dcvs_dispatch' => 'DCVS Dispatch',
        'dcvs_processing' => 'DCVS Processing',
        'escalated_to_dcvs' => 'Escalated to DCVS',
        'unable_to_locate' => 'Unable to Locate',
        'turned_over' => 'Turned over to DCVS',
    ][$responseState] ?? 'Active';
    $ri['caseId'] = $ri['caseId'] ?? (int)$ri['id'];
    $activeItems[] = $ri;
    $activeCounts['all']++;
    if (stripos($ri['urgency'], 'high') !== false || stripos($ri['urgency'], 'critical') !== false) $activeCounts['high']++;
    if ($ri['scheduled']) $activeCounts['sched']++;
    if ($ri['scheduled'] && strtotime($ri['scheduled']) < time()) $activeCounts['overdue']++;
}

// �"?�"? RESOLVED CASES (completed history) �"?�"?
$resolvedItems = [];
$resolvedCounts = ['all' => 0, 'month' => 0, 'year' => 0, 'high' => 0];
$resolvedMonthStart = strtotime(date('Y-m-01'));
$resolvedYearStart = strtotime(date('Y-01-01'));
foreach ($activeResidentReportItems as $ri) {
    if ($ri['caseStatus'] !== 'resolved') continue;
    $ri['status'] = 'resolved';
    $ri['statusLabel'] = 'Resolved';
    $ri['caseId'] = $ri['caseId'] ?? (int)$ri['id'];
    $resolvedItems[] = $ri;
    $resolvedCounts['all']++;
    if ($ri['ts'] >= $resolvedMonthStart) $resolvedCounts['month']++;
    if ($ri['ts'] >= $resolvedYearStart) $resolvedCounts['year']++;
    if (stripos($ri['urgency'], 'high') !== false || stripos($ri['urgency'], 'critical') !== false) $resolvedCounts['high']++;
}

// ── Handle Program Creation (POST) ──
function dcvsNormalizeProgramBarangayIds($conn, $rawIds)
{
    if (!is_array($rawIds)) return null;
    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_scalar($rawId) || !preg_match('/^[1-9][0-9]*$/', (string)$rawId)) return null;
        $ids[] = (int)$rawId;
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT barangay_id FROM barangays WHERE barangay_id IN ($placeholders)");
    if (!$stmt) return null;
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $foundIds = [];
    while ($row = $result->fetch_assoc()) $foundIds[] = (int)$row['barangay_id'];
    $stmt->close();
    return count($foundIds) === count($ids) ? $ids : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_program'])) {
    if (!validateCsrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['program_error'] = 'Your session could not be verified. Refresh the page and try again.';
        header('Location: dashboard-staff.php#analytics');
        exit;
    }
    $progName    = trim($_POST['program_name'] ?? '');
    $progType    = $_POST['program_type'] ?? 'kapon';
    $progDesc    = trim($_POST['program_description'] ?? '');
    $startDate   = $_POST['start_date'] ?? '';
    $endDate     = $_POST['end_date'] ?? '';
    $programStartTime = $_POST['program_start_time'] ?? '';
    $programEndTime = $_POST['program_end_time'] ?? '';
    $staffRequired = 0;
    $notifyAffected = ($_POST['notify_affected'] ?? '') === '1';
    $conflictChoice = (string)($_POST['program_conflict_choice'] ?? '');
    $rescheduleOffersJson = (string)($_POST['reschedule_offers_json'] ?? '[]');
    $operTime    = trim($_POST['operating_time'] ?? '');
    $locMode     = $_POST['location_mode'] ?? 'single';
    $venue       = trim($_POST['venue'] ?? '');
    $barangayIds = dcvsNormalizeProgramBarangayIds($conn, $_POST['barangay_ids'] ?? []);
    $targetReach = intval($_POST['target_reach'] ?? 0);
    $dogsTarget  = intval($_POST['dogs_target'] ?? 0);
    $catsTarget  = intval($_POST['cats_target'] ?? 0);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
        && checkdate((int)substr($startDate, 5, 2), (int)substr($startDate, 8, 2), (int)substr($startDate, 0, 4))
        && checkdate((int)substr($endDate, 5, 2), (int)substr($endDate, 8, 2), (int)substr($endDate, 0, 4))
        && $startDate <= $endDate) {
      try {
        dcvsLockAppointmentCapacityDates($conn, [$startDate, $endDate]);
      } catch (RuntimeException $error) {
        $_SESSION['program_error'] = $error->getMessage();
        header('Location: dashboard-staff.php#analytics');
        exit;
      }
    }
    $appointmentConflicts = (!empty($startDate) && !empty($endDate) && !empty($programStartTime) && !empty($programEndTime))
        ? dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, 0, $programStartTime, $programEndTime)
        : [];
    $reportWarnings = (!empty($startDate) && !empty($endDate) && is_array($barangayIds)) ? dcvsProgramReportWarnings($conn, $startDate, $endDate, $barangayIds) : [];
    $rescheduleOffers = null;
    $rescheduleOffersError = '';
    if ($conflictChoice === 'reschedule' && !$appointmentConflicts) {
      $rescheduleOffersError = 'There are no affected appointments to reschedule.';
    }

    $validTypes = ['kapon', 'vaccination', 'checkup', 'deworming', 'adoption', 'rabies_prevention', 'microchipping', 'other'];
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

    if (empty($progName) || empty($startDate) || empty($endDate)) {
        $_SESSION['program_error'] = "Program name, start date, and end date are required.";
    } elseif ($startDate < $today || $endDate < $today) {
        $_SESSION['program_error'] = "Program start and end dates cannot be in the past.";
    } elseif ($startDate > $endDate) {
      $_SESSION['program_error'] = "The program end date cannot be before its start date.";
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $programStartTime) || !preg_match('/^\d{2}:\d{2}$/', $programEndTime) || $programStartTime >= $programEndTime) {
      $_SESSION['program_error'] = "Enter a valid program start and end time.";
    } elseif (!empty($appointmentConflicts) && (!$notifyAffected || !in_array($conflictChoice, ['reschedule', 'notify'], true))) {
      $_SESSION['program_error'] = count($appointmentConflicts) . " appointment(s) would exceed the daily capacity limits. Choose how to handle the affected appointments.";
    } elseif ($conflictChoice === 'reschedule' && !$appointmentConflicts) {
      $_SESSION['program_error'] = $rescheduleOffersError;
    } elseif (!in_array($progType, $validTypes)) {
        $_SESSION['program_error'] = "Invalid program type.";
    } elseif (!in_array($locMode, ['single', 'multiple'], true)) {
        $_SESSION['program_error'] = "Invalid program location mode.";
    } elseif ($barangayIds === null) {
        $_SESSION['program_error'] = "Select valid barangays for the program.";
    } elseif ($locMode === 'single' && empty($venue)) {
        $_SESSION['program_error'] = "Please enter a venue for the program.";
    } elseif ($locMode === 'single' && count($barangayIds) !== 1) {
        $_SESSION['program_error'] = "Please select one barangay for the program.";
    } elseif ($locMode === 'multiple' && empty($barangayIds)) {
        $_SESSION['program_error'] = "Please select at least one barangay for the program.";
    } elseif ($targetReach > 0 && ($dogsTarget + $catsTarget) > $targetReach) {
        $_SESSION['program_error'] = "Dogs + Cats cannot exceed Target Reach.";
    } else {
        $staffId = $_SESSION['user_id'];
        $progStatus = 'scheduled';
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO programs (program_name, program_type, description, status, start_date, end_date, program_start_time, program_end_time, staff_required_count, operating_time, location_mode, venue, target_reach, dogs_target, cats_target, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssisssiiii", $progName, $progType, $progDesc, $progStatus, $startDate, $endDate, $programStartTime, $programEndTime, $staffRequired, $operTime, $locMode, $venue, $targetReach, $dogsTarget, $catsTarget, $staffId);
        if ($stmt->execute()) {
            $newProgId = $stmt->insert_id;
            $stmt->close();

            if (!empty($barangayIds)) {
                $bStmt = $conn->prepare("INSERT INTO program_barangays (program_id, barangay_id) VALUES (?, ?)");
                foreach ($barangayIds as $bid) {
                    $bid = intval($bid);
                    if ($bid > 0) {
                        $bStmt->bind_param("ii", $newProgId, $bid);
                        $bStmt->execute();
                    }
                }
                $bStmt->close();
            }

            $releasedAppointmentSlots = [];
            try {
                if ($conflictChoice === 'reschedule') {
                    $rescheduleOffers = dcvsValidateAppointmentRescheduleOffers($conn, $appointmentConflicts, $rescheduleOffersJson, $rescheduleOffersError);
                    if ($rescheduleOffers === false) {
                        throw new RuntimeException($rescheduleOffersError);
                    }
                    $releasedAppointmentSlots = dcvsCreateAppointmentRescheduleOffers($conn, $appointmentConflicts, $rescheduleOffers);
                }
                $conn->commit();
                foreach ($releasedAppointmentSlots as $slot) {
                    dcvsNotifyWaitlistForSlot($conn, $slot['date'], $slot['time'], $slot['appointment_type'], $slot['urgency_level']);
                }
            } catch (Throwable $error) {
                $conn->rollback();
                $_SESSION['program_error'] = 'The program could not be saved with the appointment reschedule offers. Please recheck the conflicts and try again.';
                error_log('Program creation with reschedule offers failed: ' . $error->getMessage());
                header('Location: dashboard-staff.php#analytics');
                exit;
            }

            $_SESSION['program_success'] = "Program \"" . htmlspecialchars($progName) . "\" created successfully.";
            $_SESSION['program_created'] = true;
            if ($appointmentConflicts && $conflictChoice === 'reschedule') {
                $_SESSION['program_success'] .= ' ' . count($appointmentConflicts) . ' resident(s) asked to respond to a new appointment time within 3 days.';
            } elseif ($appointmentConflicts && $notifyAffected) {
                require_once __DIR__ . '/../../includes/program-notifications.php';
                $notifiedCount = dcvsNotifyProgramAffectedAppointments($conn, $appointmentConflicts, $progName, $staffId);
                $_SESSION['program_success'] .= ' ' . $notifiedCount . ' affected resident(s) notified.';
            }
            if ($reportWarnings) $_SESSION['program_success'] .= ' Review ' . count($reportWarnings) . ' report(s) dated in this range on the Reports calendar.';
        } else {
            $conn->rollback();
            $_SESSION['program_error'] = "Failed to create program. Please try again.";
        }
    }

    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Program Edit (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_program'])) {
    if (!validateCsrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['program_error'] = 'Your session could not be verified. Refresh the page and try again.';
        header('Location: dashboard-staff.php#analytics');
        exit;
    }
    $progId      = intval($_POST['program_id'] ?? 0);
    $progName    = trim($_POST['program_name'] ?? '');
    $progType    = $_POST['program_type'] ?? 'kapon';
    $progDesc    = trim($_POST['program_description'] ?? '');
    $startDate   = $_POST['start_date'] ?? '';
    $endDate     = $_POST['end_date'] ?? '';
    $programStartTime = $_POST['program_start_time'] ?? '';
    $programEndTime = $_POST['program_end_time'] ?? '';
    $staffRequired = 0;
    $notifyAffected = ($_POST['notify_affected'] ?? '') === '1';
    $conflictChoice = (string)($_POST['program_conflict_choice'] ?? '');
    $rescheduleOffersJson = (string)($_POST['reschedule_offers_json'] ?? '[]');
    $operTime    = trim($_POST['operating_time'] ?? '');
    $locMode     = $_POST['location_mode'] ?? 'single';
    $venue       = trim($_POST['venue'] ?? '');
    $barangayIds = dcvsNormalizeProgramBarangayIds($conn, $_POST['barangay_ids'] ?? []);
    $targetReach = intval($_POST['target_reach'] ?? 0);
    $dogsTarget  = intval($_POST['dogs_target'] ?? 0);
    $catsTarget  = intval($_POST['cats_target'] ?? 0);
    $existingProgram = null;
    if ($progId > 0) {
      $existingProgramStmt = $conn->prepare("SELECT start_date, end_date, program_start_time, program_end_time, operating_time FROM programs WHERE program_id = ?");
      $existingProgramStmt->bind_param('i', $progId);
      $existingProgramStmt->execute();
      $existingProgram = $existingProgramStmt->get_result()->fetch_assoc();
      $existingProgramStmt->close();
    }
    if ($existingProgram && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
        && checkdate((int)substr($startDate, 5, 2), (int)substr($startDate, 8, 2), (int)substr($startDate, 0, 4))
        && checkdate((int)substr($endDate, 5, 2), (int)substr($endDate, 8, 2), (int)substr($endDate, 0, 4))
        && $startDate <= $endDate) {
      try {
        dcvsLockAppointmentCapacityDates($conn, [$existingProgram['start_date'], $existingProgram['end_date'], $startDate, $endDate]);
      } catch (RuntimeException $error) {
        $_SESSION['program_error'] = $error->getMessage();
        header('Location: dashboard-staff.php#analytics');
        exit;
      }
    }
    $appointmentConflicts = ($existingProgram && !empty($startDate) && !empty($endDate) && !empty($programStartTime) && !empty($programEndTime))
        ? dcvsProgramAppointmentConflicts($conn, $startDate, $endDate, $progId, $programStartTime, $programEndTime)
        : [];
    $reportWarnings = (!empty($startDate) && !empty($endDate) && is_array($barangayIds)) ? dcvsProgramReportWarnings($conn, $startDate, $endDate, $barangayIds) : [];
    $rescheduleOffers = null;
    $rescheduleOffersError = '';
    if ($conflictChoice === 'reschedule' && !$appointmentConflicts) {
      $rescheduleOffersError = 'There are no affected appointments to reschedule.';
    }

    $validTypes = ['kapon', 'vaccination', 'checkup', 'deworming', 'adoption', 'rabies_prevention', 'microchipping', 'other'];

    if ($progId <= 0) {
        $_SESSION['program_error'] = "Invalid program ID.";
    } elseif (!$existingProgram) {
      $_SESSION['program_error'] = "Program not found.";
    } elseif (empty($progName) || empty($startDate) || empty($endDate)) {
        $_SESSION['program_error'] = "Program name, start date, and end date are required.";
    } elseif ($startDate > $endDate) {
      $_SESSION['program_error'] = "The program end date cannot be before its start date.";
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $programStartTime) || !preg_match('/^\d{2}:\d{2}$/', $programEndTime) || $programStartTime >= $programEndTime) {
      $_SESSION['program_error'] = "Enter a valid program start and end time.";
    } elseif (!empty($appointmentConflicts) && (!$notifyAffected || !in_array($conflictChoice, ['reschedule', 'notify'], true))) {
      $_SESSION['program_error'] = count($appointmentConflicts) . " appointment(s) would exceed the daily capacity limits. Choose how to handle the affected appointments.";
    } elseif ($conflictChoice === 'reschedule' && !$appointmentConflicts) {
      $_SESSION['program_error'] = $rescheduleOffersError;
    } elseif (!in_array($progType, $validTypes)) {
        $_SESSION['program_error'] = "Invalid program type.";
    } elseif (!in_array($locMode, ['single', 'multiple'], true)) {
        $_SESSION['program_error'] = "Invalid program location mode.";
    } elseif ($barangayIds === null) {
        $_SESSION['program_error'] = "Select valid barangays for the program.";
    } elseif ($locMode === 'single' && empty($venue)) {
        $_SESSION['program_error'] = "Please enter a venue for the program.";
    } elseif ($locMode === 'single' && count($barangayIds) !== 1) {
        $_SESSION['program_error'] = "Please select one barangay for the program.";
    } elseif ($locMode === 'multiple' && empty($barangayIds)) {
        $_SESSION['program_error'] = "Please select at least one barangay for the program.";
    } elseif ($targetReach > 0 && ($dogsTarget + $catsTarget) > $targetReach) {
        $_SESSION['program_error'] = "Dogs + Cats cannot exceed Target Reach.";
    } else {
        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE programs SET program_name=?, program_type=?, description=?, start_date=?, end_date=?, program_start_time=?, program_end_time=?, staff_required_count=?, operating_time=?, location_mode=?, venue=?, target_reach=?, dogs_target=?, cats_target=?, updated_at=NOW() WHERE program_id=?");
        $stmt->bind_param("sssssssisssiiii", $progName, $progType, $progDesc, $startDate, $endDate, $programStartTime, $programEndTime, $staffRequired, $operTime, $locMode, $venue, $targetReach, $dogsTarget, $catsTarget, $progId);
        if ($stmt->execute()) {
            $stmt->close();

            $conn->query("DELETE FROM program_barangays WHERE program_id = " . $progId);

            if (!empty($barangayIds)) {
                $bStmt = $conn->prepare("INSERT INTO program_barangays (program_id, barangay_id) VALUES (?, ?)");
                foreach ($barangayIds as $bid) {
                    $bid = intval($bid);
                    if ($bid > 0) {
                        $bStmt->bind_param("ii", $progId, $bid);
                        $bStmt->execute();
                    }
                }
                $bStmt->close();
            }

            $releasedAppointmentSlots = [];
            try {
              if ($conflictChoice === 'reschedule') {
                $rescheduleOffers = dcvsValidateAppointmentRescheduleOffers($conn, $appointmentConflicts, $rescheduleOffersJson, $rescheduleOffersError);
                if ($rescheduleOffers === false) {
                  throw new RuntimeException($rescheduleOffersError);
                }
                $releasedAppointmentSlots = dcvsCreateAppointmentRescheduleOffers($conn, $appointmentConflicts, $rescheduleOffers);
              }
              $conn->commit();
              if ($existingProgram) {
                dcvsNotifyWaitlistForDateRange($conn, min($existingProgram['start_date'], $startDate), max($existingProgram['end_date'], $endDate));
              }
              foreach ($releasedAppointmentSlots as $slot) {
                dcvsNotifyWaitlistForSlot($conn, $slot['date'], $slot['time'], $slot['appointment_type'], $slot['urgency_level']);
              }
            } catch (Throwable $error) {
              $conn->rollback();
              $_SESSION['program_error'] = 'The program could not be saved with the appointment reschedule offers. Please recheck the conflicts and try again.';
              error_log('Program update with reschedule offers failed: ' . $error->getMessage());
              header('Location: dashboard-staff.php#analytics');
              exit;
            }

            $_SESSION['program_success'] = "Program \"" . htmlspecialchars($progName) . "\" updated successfully.";
            if ($appointmentConflicts && $conflictChoice === 'reschedule') {
              $_SESSION['program_success'] .= ' ' . count($appointmentConflicts) . ' resident(s) asked to respond to a new appointment time within 3 days.';
            } elseif ($appointmentConflicts && $notifyAffected) {
                require_once __DIR__ . '/../../includes/program-notifications.php';
                $notifiedCount = dcvsNotifyProgramAffectedAppointments($conn, $appointmentConflicts, $progName, $_SESSION['user_id']);
                $_SESSION['program_success'] .= ' ' . $notifiedCount . ' affected resident(s) notified.';
            }
            if ($reportWarnings) $_SESSION['program_success'] .= ' Review ' . count($reportWarnings) . ' report(s) dated in this range on the Reports calendar.';
        } else {
            $conn->rollback();
            $_SESSION['program_error'] = "Failed to update program. Please try again.";
        }
    }

    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Log Progress (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_progress'])) {
    $logProgId   = intval($_POST['program_id'] ?? 0);
    $logDate     = $_POST['log_date'] ?? date('Y-m-d');
    $dogsServed  = intval($_POST['dogs_served'] ?? 0);
    $catsServed  = intval($_POST['cats_served'] ?? 0);
    $logNotes    = trim($_POST['log_notes'] ?? '');

    if ($logProgId <= 0) {
        $_SESSION['program_error'] = "Invalid program.";
    } elseif ($dogsServed < 0 || $catsServed < 0) {
        $_SESSION['program_error'] = "Served counts cannot be negative.";
    } elseif ($dogsServed === 0 && $catsServed === 0) {
        $_SESSION['program_error'] = "Enter at least one dog or cat served.";
    } else {
        $staffId = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO program_progress (program_id, log_date, dogs_served, cats_served, notes, logged_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isiisi", $logProgId, $logDate, $dogsServed, $catsServed, $logNotes, $staffId);
        if ($stmt->execute()) {
            $_SESSION['program_success'] = "Progress logged: +{$dogsServed} dogs, +{$catsServed} cats.";
        } else {
            $_SESSION['program_error'] = "Failed to log progress. Please try again.";
        }
        $stmt->close();
    }

    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Pause Program (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pause_program'])) {
    $progId = intval($_POST['program_id'] ?? 0);
    if ($progId > 0) {
        $rangeStmt = $conn->prepare("SELECT start_date, end_date FROM programs WHERE program_id = ?");
        $rangeStmt->bind_param('i', $progId);
        $rangeStmt->execute();
        $programRange = $rangeStmt->get_result()->fetch_assoc();
        $rangeStmt->close();
        $pauseStmt = $conn->prepare("UPDATE programs SET status = 'paused' WHERE program_id = ?");
        $pauseStmt->bind_param('i', $progId);
        $capacityConflicts = $programRange
            ? dcvsProgramCapacityTransitionConflicts($conn, $progId, $programRange['start_date'], $programRange['end_date'], false)
            : [];
        if ($capacityConflicts) {
            $_SESSION['program_error'] = "Program cannot be paused because its dates would exceed the five-appointment daily limit. Reschedule or cancel the affected appointments first.";
        } elseif ($pauseStmt->execute()) {
            $_SESSION['program_success'] = "Program paused.";
            if ($programRange) dcvsNotifyWaitlistForDateRange($conn, $programRange['start_date'], $programRange['end_date']);
        } else {
            error_log('Failed to pause program ' . $progId . ': ' . $pauseStmt->error);
            $_SESSION['program_error'] = "Could not pause the program. Please try again.";
        }
        $pauseStmt->close();
    }
    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Resume Program (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resume_program'])) {
    $progId = intval($_POST['program_id'] ?? 0);
    if ($progId > 0) {
    $programStmt = $conn->prepare("SELECT start_date, end_date FROM programs WHERE program_id = ? AND status = 'paused'");
    $programStmt->bind_param('i', $progId);
    $programStmt->execute();
    $program = $programStmt->get_result()->fetch_assoc();
    $programStmt->close();
    $resumeLockError = null;
    if ($program) {
      try {
        dcvsLockAppointmentCapacityDates($conn, [$program['start_date'], $program['end_date']]);
      } catch (RuntimeException $error) {
        $resumeLockError = $error->getMessage();
      }
    }
    if (!$program) {
      $_SESSION['program_error'] = "Paused program not found.";
    } elseif ($resumeLockError !== null) {
      $_SESSION['program_error'] = $resumeLockError;
    } elseif (dcvsProgramAppointmentConflicts($conn, $program['start_date'], $program['end_date'], $progId, '09:00', '15:00', true)) {
      $_SESSION['program_error'] = "Program cannot resume because its dates would exceed daily appointment capacity. Reschedule or cancel the affected appointments first.";
    } else {
      $barangayStmt = $conn->prepare("SELECT barangay_id FROM program_barangays WHERE program_id = ?");
      $barangayStmt->bind_param('i', $progId);
      $barangayStmt->execute();
      $barangayResult = $barangayStmt->get_result();
      $programBarangayIds = [];
      while ($barangay = $barangayResult->fetch_assoc()) $programBarangayIds[] = (int)$barangay['barangay_id'];
      $barangayStmt->close();
      $reportWarnings = dcvsProgramReportWarnings($conn, $program['start_date'], $program['end_date'], $programBarangayIds);
      $resumeStmt = $conn->prepare("UPDATE programs SET status = 'live' WHERE program_id = ? AND status = 'paused'");
      $resumeStmt->bind_param('i', $progId);
      $resumeStmt->execute();
      $resumeStmt->close();
      $_SESSION['program_success'] = "Program resumed.";
      if ($reportWarnings) $_SESSION['program_success'] .= ' Review ' . count($reportWarnings) . ' report(s) dated in this range on the Reports calendar.';
    }
    }
    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Cancel Program (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_program'])) {
    $progId = intval($_POST['program_id'] ?? 0);
    if ($progId > 0) {
        $rangeStmt = $conn->prepare("SELECT start_date, end_date FROM programs WHERE program_id = ?");
        $rangeStmt->bind_param('i', $progId);
        $rangeStmt->execute();
        $programRange = $rangeStmt->get_result()->fetch_assoc();
        $rangeStmt->close();
        $cancelStmt = $conn->prepare("UPDATE programs SET status = 'cancelled' WHERE program_id = ?");
        $cancelStmt->bind_param('i', $progId);
        $capacityConflicts = $programRange
            ? dcvsProgramCapacityTransitionConflicts($conn, $progId, $programRange['start_date'], $programRange['end_date'], false)
            : [];
        if ($capacityConflicts) {
            $_SESSION['program_error'] = "Program cannot be cancelled because its dates would exceed the five-appointment daily limit. Reschedule or cancel the affected appointments first.";
        } elseif ($cancelStmt->execute()) {
            $_SESSION['program_success'] = "Program cancelled.";
            if ($programRange) dcvsNotifyWaitlistForDateRange($conn, $programRange['start_date'], $programRange['end_date']);
        } else {
            error_log('Failed to cancel program ' . $progId . ': ' . $cancelStmt->error);
            $_SESSION['program_error'] = "Could not cancel the program. Please try again.";
        }
        $cancelStmt->close();
    }
    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Mark Program as Completed (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $progId = intval($_POST['program_id'] ?? 0);
    if ($progId > 0) {
        $rangeStmt = $conn->prepare("SELECT start_date, end_date, status, target_reach, (SELECT COALESCE(SUM(dogs_served + cats_served), 0) FROM program_progress WHERE program_id = programs.program_id) AS reached FROM programs WHERE program_id = ?");
        $rangeStmt->bind_param('i', $progId);
        $rangeStmt->execute();
        $programRange = $rangeStmt->get_result()->fetch_assoc();
        $rangeStmt->close();
        if (!$programRange) {
            $_SESSION['program_error'] = "Program not found.";
        } elseif ((int)$programRange['target_reach'] > 0 && (int)$programRange['reached'] < (int)$programRange['target_reach']) {
            $_SESSION['program_error'] = "The program cannot be marked completed until its target reach is met.";
        } elseif (dcvsProgramCapacityTransitionConflicts($conn, $progId, $programRange['start_date'], $programRange['end_date'], false)) {
            $_SESSION['program_error'] = "Program cannot be marked completed because its dates would exceed the five-appointment daily limit. Reschedule or cancel the affected appointments first.";
        } else {
            $completeStmt = $conn->prepare("UPDATE programs SET status = 'completed' WHERE program_id = ?");
            if ($completeStmt) {
                $completeStmt->bind_param('i', $progId);
                if ($completeStmt->execute()) {
                    $_SESSION['program_success'] = "Program marked as completed.";
                    dcvsNotifyWaitlistForDateRange($conn, $programRange['start_date'], $programRange['end_date']);
                } else {
                    error_log('Failed to mark program ' . $progId . ' as completed: ' . $completeStmt->error);
                    $_SESSION['program_error'] = "Could not mark the program as completed. Please try again.";
                }
                $completeStmt->close();
            } else {
                error_log('Failed to prepare program completion update: ' . $conn->error);
                $_SESSION['program_error'] = "Could not mark the program as completed. Please try again.";
            }
        }
    }
    header('Location: dashboard-staff.php#analytics');
    exit;
}

// ── Handle Archive Program (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_program'])) {
    $progId = intval($_POST['program_id'] ?? 0);
    if ($progId > 0) {
        $rangeStmt = $conn->prepare("SELECT start_date, end_date FROM programs WHERE program_id = ?");
        $rangeStmt->bind_param('i', $progId);
        $rangeStmt->execute();
        $programRange = $rangeStmt->get_result()->fetch_assoc();
        $rangeStmt->close();
        $archiveStmt = $conn->prepare("UPDATE programs SET status = 'archived' WHERE program_id = ?");
        $archiveStmt->bind_param('i', $progId);
        $capacityConflicts = $programRange
            ? dcvsProgramCapacityTransitionConflicts($conn, $progId, $programRange['start_date'], $programRange['end_date'], false)
            : [];
        if ($capacityConflicts) {
            $_SESSION['program_error'] = "Program cannot be archived because its dates would exceed the five-appointment daily limit. Reschedule or cancel the affected appointments first.";
        } elseif ($archiveStmt->execute()) {
            $_SESSION['program_success'] = "Program archived.";
            if ($programRange) dcvsNotifyWaitlistForDateRange($conn, $programRange['start_date'], $programRange['end_date']);
        } else {
            error_log('Failed to archive program ' . $progId . ': ' . $archiveStmt->error);
            $_SESSION['program_error'] = "Could not archive the program. Please try again.";
        }
        $archiveStmt->close();
    }
    header('Location: dashboard-staff.php#analytics');
    exit;
}

$pageTitle = 'Analytics';
$activePage = 'overview';
$staffWaitlistBooking = null;
if (!empty($_GET['waitlist_booking'])) {
    $waitlistBookingId = (int)$_GET['waitlist_booking'];
    $waitlistBookingStmt = $conn->prepare("SELECT w.waitlist_id, w.user_id, w.pet_id, w.appointment_type, w.urgency_level, w.preferred_date, w.preferred_time,
            u.full_name, p.pet_name
        FROM appointment_waitlist w
        JOIN users u ON u.user_id = w.user_id AND u.role = 'resident'
        JOIN pets p ON p.pet_id = w.pet_id AND p.owner_id = w.user_id AND p.archived_at IS NULL
        WHERE w.waitlist_id = ? AND w.status IN ('waiting','notified')
          AND (w.status = 'waiting' OR w.claim_expires_at > NOW())");
    if ($waitlistBookingStmt) {
        $waitlistBookingStmt->bind_param('i', $waitlistBookingId);
        if ($waitlistBookingStmt->execute()) $staffWaitlistBooking = $waitlistBookingStmt->get_result()->fetch_assoc();
        $waitlistBookingStmt->close();
    } else {
        error_log('Failed to prepare staff waitlist booking lookup: ' . $conn->error);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> &mdash; Staff Portal | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/staff-compose-dialog.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/staff-compose-dialog.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/staff-date-filter.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/staff-date-filter.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <script>window.DCVS_APP_BASE_URL = <?php echo json_encode($appBaseUrl); ?>; window.dcvsStaffUrl = function(path) { path = String(path || '').trim(); return /^(?:[a-z][a-z0-9+.-]*:|\/\/|\/)/i.test(path) ? path : window.DCVS_APP_BASE_URL + '/' + path.replace(/^\.?\/*/, ''); };</script>
  
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <?php if ($freshDashboard): ?>
  <script>try { localStorage.removeItem('activeSection'); } catch (e) {}</script>
  <?php endif; ?>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/dashboard.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <!-- MapCN bundle -->
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.css', ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/geo-map/map.css', ENT_QUOTES, 'UTF-8'); ?>" />
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.umd.cjs?v=' . (int)filemtime(__DIR__ . '/../../dist/mapcn.umd.cjs'), ENT_QUOTES, 'UTF-8'); ?>"></script>
  
  <style>
    #pdfExportHeader { display: none; }

    /* Pagination Bar */
    .pagination-bar {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
        padding: 12px 4px 4px 4px; margin-top: 8px;
        border-top: 1px solid #f1f5f9;
    }
    .pag-info { color: #94a3b8; font-size: 0.82rem; }
    .pag-btns { display: flex; gap: 4px; }
    .pag-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 7px;
        border: 1.5px solid #e2e8f0; background: #f8fafc;
        color: #475569; font-size: 0.82rem; font-weight: 500;
        text-decoration: none; cursor: pointer; transition: all 0.15s;
    }
    .pag-btn:hover { border-color: #10b981; color: #10b981; background: #f0fdf4; }
    .pag-active {
        background: #10b981 !important; color: white !important;
        border-color: #10b981 !important; font-weight: 700 !important;
    }
    .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Inter',sans-serif; }
    .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }
    .header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .dashboard-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .card-header { padding:1rem 1.5rem; border-bottom:1px solid #f1f5f9; }
    .card-header h3 { font-size:1rem; font-weight:700; color:#0f172a; margin:0; }

    .kpi-sub { color:#94a3b8; font-weight:400; margin-left:2px; }
    .table-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.5rem; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .reports-fullpage .table-card { display:flex; flex-direction:column; flex:1; min-height:0; margin:0; padding:0; overflow:hidden; }
    .reports-fullpage .table-card .db-toolbar { margin:0; padding:1.25rem 1.5rem; border-bottom:1px solid #f1f5f9; }
    .reports-fullpage .table-card .db-table-area { padding:1.25rem 1.5rem 0; }
    .reports-fullpage .table-card .pagination { padding:1rem 1.5rem; margin-top:0; border-top:1px solid #f1f5f9; }
    .table-header-actions { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    .btn-sm { padding:6px 14px; font-size:0.78rem; border-radius:6px; }
    .btn-danger { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:9px; border:none; background:#dc2626; color:#fff; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.2s; }
    .btn-danger:hover { background:#b91c1c; }
    .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:1.25rem; flex-wrap:wrap; }
    .modal-form { }
    .half-width { }
    .settings-layout { display:grid; gap:1rem; max-width:1000px; margin:0 auto; width:100%; box-sizing:border-box; }
    .profile-card-full { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:1.25rem 1.5rem; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
    .settings-section { margin-bottom:1rem; }
    .settings-section-title { font-size:0.9rem; font-weight:700; color:#0f172a; margin-bottom:0.75rem; display:flex; align-items:center; gap:8px; }
    .settings-section-title i { color:#045e1f; }
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; }
    .settings-actions { display:flex; gap:10px; margin-top:0.75rem; flex-wrap:wrap; }
    .settings-form { }
    .form-group { display:flex; flex-direction:column; gap:3px; }
    .form-group-full { grid-column:1 / -1; }
    .form-group label { font-size:0.78rem; font-weight:600; color:#334155; }
    .profile-header-banner { background:linear-gradient(135deg,#045e1f,#043012); padding:1.25rem 1.5rem; border-radius:12px; text-align:center; color:#fff; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; }
    .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
    .profile-header-info { text-align:left; flex:1; margin-top:0; }
    .profile-id-badge { display:inline-block; background:rgba(255,255,255,0.15); padding:3px 10px; border-radius:999px; font-size:0.72rem; }
    .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
    .profile-dropdown.open { display:block; }
    .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
    .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
    .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
    .profile-photo-view { text-align:center; padding:1rem; }
    .profile-photo-view img { max-width:300px; max-height:300px; border-radius:50%; object-fit:cover; }
    .no-profile-photo { color:#94a3b8; padding:2rem; }
    .no-profile-photo i { font-size:3rem; display:block; margin-bottom:0.5rem; }
    .profile-photo-delete-btn { position:absolute; top:12px; right:12px; width:36px; height:36px; border-radius:50%; background:#fef2f2; border:1px solid #fecaca; color:#dc2626; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.15s; }
    .profile-photo-delete-btn:hover { background:#fecaca; }
    .form-control { width:100%; padding:7px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.82rem; font-family:inherit; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .form-control:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    textarea.form-control { font-family:inherit; font-size:0.82rem; line-height:1.4; }
    .account-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.65rem; margin-top:0.75rem; }
    .staff-theme { }
    .status-pill.status-pending { background:#fef3c7; color:#92400e; border-color:#fde68a; }
    .status-pill.status-validated { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
    .status-pill.status-confirmed,
    .status-pill.status-scheduled { background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }
    .status-pill.status-resolved { background:#f0fdf4; color:#045e1f; border-color:#a7f3d0; }
    .status-pill.status-rejected { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .status-pill.status-completed { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
    .status-pill.status-cancelled { background:#f1f5f9; color:#64748b; border-color:#e2e8f0; }
    .status-pill.status-rescheduled { background:#ede9fe; color:#6d28d9; border-color:#ddd6fe; }
    .status-pill.status-no_show { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    @media (max-width: 768px) {
      .settings-grid { grid-template-columns:1fr; }
      .geo-map-card { overflow:hidden; }
    }
    @media (max-width: 600px) {
      .table-card { padding:0.75rem; }
      .topbar { padding:0 0.35rem; }
      .geo-map-card { overflow:hidden; }
      .maps-container { height:180px !important; }
    }
    @media (max-width: 900px) {
      /* Prevent horizontal overflow */
      .dashboard-content, .content-area { max-width:100vw; overflow-x:hidden; }
      .table-card { padding:1rem; overflow-x:auto; }
      .table-card input[type="text"],
      .table-card input:not([type="submit"]):not([type="button"]) { max-width:100% !important; width:100% !important; box-sizing:border-box; }
      .table-card .table-header-actions { flex-wrap:wrap; gap:0.5rem; }
      .table-card .table-header-actions > * { flex:1 1 auto; min-width:120px; }
      .data-table { display:block; overflow-x:auto; white-space:nowrap; }
      .notif-panel { width:calc(100vw - 2rem) !important; right:0.5rem !important; }
    }

    /* Notification Dropdown */
    .notif-dropdown { position: relative; }
    .notif-bell { background: none; border: none; font-size: 1.2rem; color: #475569; cursor: pointer; padding: 6px; position: relative; border-radius: 8px; transition: background 0.15s; }
    .notif-bell:hover { background: #f1f5f9; }
    .notif-badge { position: absolute; top: 0; right: 0; background: #dc2626; color: #fff; font-size: 0.6rem; font-weight: 700; min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 4px; line-height: 1; }
    .notif-panel { position: absolute; top: calc(100% + 8px); right: 0; width: 380px; background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.12); border: 1px solid #e2e8f0; z-index: 200; display: none; overflow: hidden; }
    .notif-panel.open { display: block; }
    .notif-panel-header { display: flex; justify-content: space-between; align-items: center; padding: 0.9rem 1rem; border-bottom: 1px solid #e2e8f0; }
    .notif-panel-header h4 { margin: 0; font-size: 0.85rem; font-weight: 700; color: #0f172a; }
    .notif-panel-header a { font-size: 0.75rem; color: #0E8131; text-decoration: none; font-weight: 600; cursor: pointer; }
    .notif-panel-header a:hover { text-decoration: underline; }
    .notif-panel-body { max-height: 360px; overflow-y: auto; }
    .notif-item { display: flex; gap: 0.75rem; padding: 0.85rem 1rem; cursor: pointer; transition: background 0.12s; border-bottom: 1px solid #f1f5f9; }
    .notif-item:hover { background: #f8fafc; }
    .notif-item.unread { background: #f0fdf4; }
    .notif-item.unread:hover { background: #dcebe0; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; background: transparent; color: #000; }
    .notif-content { flex: 1; min-width: 0; }
    .notif-title { font-size: 0.8rem; font-weight: 700; color: #0f172a; }
    .notif-msg { font-size: 0.74rem; color: #64748b; line-height: 1.3; margin-top: 1px; }
    .notif-time { font-size: 0.65rem; color: #94a3b8; margin-top: 3px; }
    .notif-empty { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.82rem; }
    /* â�?��,�â�?��,� Profile Settings (new layout) â�?��,�â�?��,� */
    .profile-settings-wrapper * { box-sizing:border-box; }
    .profile-settings-wrapper { max-width:680px; margin:0 auto; }
    .profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
    .profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
    .profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
    .profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; object-fit:cover; background:#d9d9d9; cursor:pointer; }
    .profile-settings-wrapper .ps-avatar-plain { width:56px; height:56px; border-radius:50%; background:#d9d9d9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#666; cursor:pointer; }
    .profile-settings-wrapper .ps-profile-name { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    .profile-settings-wrapper .ps-profile-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; line-height:1.5; margin-top:2px; }
    .profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .profile-settings-wrapper .ps-card-header h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; margin:0; }
    .profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; cursor:pointer; transition:background .15s,border-color .15s; }
    .profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
    .profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
    .profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
    .profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
    .profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
    .profile-settings-wrapper .ps-field label { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; margin-bottom:6px; }
    .profile-settings-wrapper .ps-field input,
    .profile-settings-wrapper .ps-field select,
    .profile-settings-wrapper .ps-field textarea { width:100%; padding:10px 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; border-radius:8px; border:1px solid #e2e5ea; background:#f9fafb; color:#18181b; outline:none; transition:background .15s,border-color .15s,box-shadow .15s; box-sizing:border-box; }
    .profile-settings-wrapper .ps-field input:disabled,
    .profile-settings-wrapper .ps-field select:disabled,
    .profile-settings-wrapper .ps-field textarea:disabled { color:#71717a; cursor:default; background:#f9fafb; }
    .profile-settings-wrapper .ps-field input:not(:disabled),
    .profile-settings-wrapper .ps-field select:not(:disabled),
    .profile-settings-wrapper .ps-field textarea:not(:disabled) { background:#fff; border-color:#d7dae0; }
    .profile-settings-wrapper .ps-field input:not(:disabled):focus,
    .profile-settings-wrapper .ps-field select:not(:disabled):focus,
    .profile-settings-wrapper .ps-field textarea:not(:disabled):focus { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.08); }
    .profile-settings-wrapper .ps-field textarea { height:42px; min-height:42px; max-height:42px; resize:none; overflow:hidden; line-height:1.5; }
    .profile-settings-wrapper .ps-btn { padding:9px 18px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; border-radius:8px; cursor:pointer; border:1px solid transparent; }
    .profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
    .profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
    .profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
    .profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
    .profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
    .profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
    .profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
    .profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    #account-settings { background:#fff !important; min-height:100vh; }
    .main-content { background:#fff; }
    @media (max-width: 640px) {
      #account-settings .profile-settings-wrapper { width:100%; max-width:100%; min-width:0; }
      #account-settings .ps-card { padding:18px 16px; }
      #account-settings .ps-profile-left { min-width:0; align-items:flex-start; gap:12px; }
      #account-settings .ps-profile-left > div:last-child { min-width:0; overflow-wrap:anywhere; }
      #account-settings .ps-profile-name { line-height:1.25; }
      #account-settings .ps-card-header { gap:12px; }
      #account-settings .ps-card-header h2 { line-height:1.25; }
      #account-settings .ps-footer-actions { flex-direction:column; }
      #account-settings .ps-footer-actions .ps-btn { width:100%; min-height:42px; }
    }

    /* �"?�"? Client & Pet Database (CPD) �"?�"? */
    .cpd{ --green-50:#eef6f0; --green-100:#dcebe0; --green-200:#b8d1c0;
      --green-500:#057b2d; --green-600:#046b25; --green-700:#045e1f;
      --amber-50:#fffbeb; --amber-600:#d97706; --amber-700:#b45309;
      --ink-900:#0f1720; --ink-700:#334155; --ink-500:#64748b;
      --ink-400:#94a3b8; --ink-300:#cbd5e1; --ink-200:#e2e8f0;
      --ink-100:#f1f5f9; --ink-50:#f8fafc; --border:#e6e9ee;
      --white:#ffffff;
      --shadow-sm:0 1px 2px rgba(15,23,32,.04);
      --shadow-md:0 4px 14px rgba(15,23,32,.06);
      --radius:14px; --radius-sm:10px;
      box-sizing:border-box; background:var(--ink-50); color:var(--ink-900); -webkit-font-smoothing:antialiased;
      font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif; font-size:14px;
    }
    .cpd *{ box-sizing:border-box; }
    .cpd-app{ width:100%; padding:8px 0 40px; }

    .cpd .header{ display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:14px; }
    .cpd .header-left{ display:flex; align-items:flex-start; gap:14px; }
    .cpd .header-icon{ width:44px;height:44px; border-radius:12px; background:var(--green-50); display:flex;align-items:center;justify-content:center; flex-shrink:0; color:var(--green-600); }
    .cpd .header-title h1{ font-size:22px; font-weight:700; margin:0 0 2px; letter-spacing:-.01em; color:var(--ink-900); font-family:'Inter',sans-serif; }
    .cpd .header-title p{ margin:0; color:var(--ink-500); font-size:13.5px; }
    .cpd .header-right{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

    .cpd .btn{ display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:13.5px; font-weight:600; border-radius:10px; padding:10px 16px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); cursor:pointer; transition:background .15s ease, box-shadow .15s ease, border-color .15s ease; white-space:nowrap; }
    .cpd .btn:hover{ background:var(--ink-50); border-color:var(--ink-300); }
    .cpd .btn-primary{ background:var(--green-600); border-color:var(--green-600); color:var(--white); box-shadow:var(--shadow-sm); }
    .cpd .btn-primary:hover{ background:var(--green-700); border-color:var(--green-700); }
    .cpd .icon-btn{ width:36px;height:36px; display:inline-flex;align-items:center;justify-content:center; border-radius:10px; border:1px solid var(--border); background:var(--white); color:var(--ink-500); cursor:pointer; }
    .cpd .icon-btn:hover{ background:var(--ink-50); color:var(--ink-700); }
    .cpd .icon-btn.active{ background:var(--green-50); color:var(--green-700); border-color:var(--green-200); }

    .cpd .seg{ display:inline-flex; align-items:center; gap:2px; background:var(--ink-100); border-radius:10px; padding:3px; border:1px solid var(--border); }
    .cpd .seg-btn{ border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:600; color:var(--ink-500); padding:7px 14px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
    .cpd .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }

    /* KPI cards */
    .cpd .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    .cpd .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); }
    .cpd .kpi-card:hover{ border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }
    .cpd .kpi-label{ font-size:12.5px; color:#000; font-weight:600; margin:0 0 8px; }
    .cpd .kpi-value{ font-size:26px; font-weight:700; color:var(--ink-900); letter-spacing:-.02em; line-height:1; margin-bottom:8px; }
    .cpd .kpi-sub{ font-size:12px; color:var(--ink-400); }

    /* Layout */
    .cpd .layout{ display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:0; align-items:stretch; min-height:calc(100vh - 120px); }
    .cpd .panel{ background:var(--white); border:1px solid var(--border); }

    /* Full-page database (no container look) */
    #client-pets, #reports-pending, #reports-accepted, #reports-active, #reports-resolved{ margin:-1.75rem -2rem; width:calc(100% + 4rem); max-width:none; }
    @media(max-width:768px){
      #reports-pending,#reports-accepted,#reports-active,#reports-resolved{
        box-sizing:border-box;
        width:100%;
        max-width:100%;
        margin:-1.75rem 0;
      }
      #client-pets{
        box-sizing:border-box;
        width:100%;
        max-width:100%;
        margin:-1.75rem 0;
      }
      #client-pets .layout,
      #client-pets .db-panel,
      #client-pets .db-table-area,
      #client-pets #tableWrap{
        box-sizing:border-box;
        width:100%;
        max-width:100%;
        min-width:0;
      }
      #client-pets .db-toolbar{
        align-items:stretch;
      }
      #client-pets .search-grow-wrap,
      #client-pets .toolbar-filters{
        width:100%;
        max-width:100%;
        min-width:0;
      }
      #client-pets .toolbar-filters{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
      }
      #client-pets .toolbar-filters .tf-select{
        width:100%;
        min-width:0;
      }
      #client-pets .db-toolbar-actions{
        width:100%;
        margin-left:0;
      }
      #client-pets .db-toolbar-actions .sd-btn{
        flex:1 1 0;
        justify-content:center;
        min-width:0;
        padding-right:8px;
        padding-left:8px;
      }
      #client-pets .bulk-bar,
      #client-pets .bulk-actions{
        flex-wrap:wrap;
      }
    }
    #reports-all thead th, #reports-pending thead th, #reports-accepted thead th, #reports-active thead th, #reports-resolved thead th, #client-pets thead th{ background:#fafafa; border-bottom:1px solid var(--border); }
    .reports-fullpage{ background:var(--white); min-height:calc(100vh - var(--topbar-h, 64px)); }
    .reports-fullpage .cpd-app{ padding:0; }
    .reports-fullpage .layout{ min-height:calc(100vh - var(--topbar-h, 64px)); grid-template-rows:1fr; }
    .reports-fullpage .panel{ background:transparent; border:none; }
    .reports-fullpage .filters{ border-right:1px solid var(--border); }
    .reports-fullpage .db-panel{ border-left:none; border-right:1px solid var(--border); }
    .reports-fullpage .profile-panel{ border-left:none; }

    /* Reports pages: full-width table + right drawer detail panel (appointments style) */
    #reports-all .layout, #reports-pending .layout, #reports-accepted .layout, #reports-active .layout, #reports-resolved .layout, #client-pets .layout{ grid-template-columns:minmax(0,1fr); }
    #reports-all .db-panel, #reports-pending .db-panel, #reports-accepted .db-panel, #reports-active .db-panel, #reports-resolved .db-panel, #client-pets .db-panel{ border-right:none; }
    @media(max-width:1024px){
      #mainContent #client-pets.content-section{
        box-sizing:border-box;
        width:100%;
        max-width:100%;
        margin-left:0;
        margin-right:0;
      }
      #client-pets #tableWrap{ contain:paint; }
    }
    .cd-drawer.modal{ position:fixed; inset:0; background:rgba(0,0,0,0.5); padding:0; z-index:999; display:flex; align-items:stretch; justify-content:flex-end; opacity:0; visibility:hidden; transition:opacity .25s ease, visibility .25s ease; }
    .cd-drawer.modal.active{ opacity:1; visibility:visible; }
    .cd-drawer .sd-dialog{ height:100%; width:100%; display:flex; justify-content:flex-end; }
    .cd-drawer .sd-dialog-content{ box-sizing:border-box; min-height:0; max-width:min(560px,96vw); width:min(560px,96vw); height:100%; max-height:none; border:none; border-radius:0; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); padding:24px; gap:18px; overflow:hidden; }
    .cd-drawer.active .sd-dialog-content{ transform:translateX(0); }
    .cd-drawer .sd-field-group{ flex:1; min-height:0; overflow-x:hidden; overflow-y:auto; }
    .cd-drawer .sd-dialog-footer{ box-sizing:border-box; flex-shrink:0; }
    .cd-drawer #rptDetailFooter{ flex-wrap:wrap; }
    .cd-drawer #rptDetailFooter .sd-btn{ flex:0 1 auto; white-space:nowrap; }
    #rptDetailsModal.rpt-bulk-dialog.modal{ position:fixed; inset:0; z-index:1200; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.5); opacity:0; visibility:hidden; transition:opacity .2s ease,visibility .2s ease; }
    #rptDetailsModal.rpt-bulk-dialog.modal.active{ opacity:1; visibility:visible; }
    #rptDetailsModal.rpt-bulk-dialog .sd-dialog{ width:100%; height:auto; display:flex; justify-content:center; }
    #rptDetailsModal.rpt-bulk-dialog .sd-dialog-content{ width:min(560px,94vw); max-width:min(560px,94vw); height:auto; max-height:calc(100vh - 32px); box-sizing:border-box; overflow-y:auto; border:1px solid #e4e4e7; border-radius:10px; box-shadow:0 20px 25px rgba(15,23,32,.18); transform:none; transition:none; padding:24px; gap:16px; }
    #rptDetailsModal.rpt-bulk-dialog.active .sd-dialog-content{ transform:none; }
    #rptDetailsModal.rpt-bulk-dialog .sd-field-group{ flex:1; min-height:0; overflow-y:auto; }
    @media(max-width:600px){ #rptDetailsModal.rpt-bulk-dialog .sd-dialog-content{ padding:18px; } }

    #reports-pending .search-grow-wrap input, #reports-accepted .search-grow-wrap input, #reports-active .search-grow-wrap input, #reports-resolved .search-grow-wrap input{ height:36px; width:100%; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
    #reports-pending .search-grow-wrap input:focus, #reports-accepted .search-grow-wrap input:focus, #reports-active .search-grow-wrap input:focus, #reports-resolved .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports-pending .search-grow-wrap input::placeholder, #reports-accepted .search-grow-wrap input::placeholder, #reports-active .search-grow-wrap input::placeholder, #reports-resolved .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    #reports-pending .search-grow-wrap svg, #reports-accepted .search-grow-wrap svg, #reports-active .search-grow-wrap svg, #reports-resolved .search-grow-wrap svg{ color:#71717a; }
    #reports-pending .tf-select, #reports-accepted .tf-select, #reports-active .tf-select, #reports-resolved .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background-color:#fff; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #reports-pending .tf-select:focus, #reports-accepted .tf-select:focus, #reports-active .tf-select:focus, #reports-resolved .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports-pending .kpi-label, #reports-accepted .kpi-label, #reports-active .kpi-label, #reports-resolved .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    #reports-pending .kpi-value, #reports-accepted .kpi-value, #reports-active .kpi-value, #reports-resolved .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #reports-pending .kpi-sub, #reports-accepted .kpi-sub, #reports-active .kpi-sub, #reports-resolved .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }

    /* Left filters sidebar rail */
    .cpd .filters{ padding:20px 16px; display:flex; flex-direction:column; border-right:1px solid var(--border); position:sticky; top:0; overflow-y:auto; }
    .cpd .rail-nav{ display:flex; flex-direction:column; gap:20px; }
    .cpd .rail-divider{ height:1px; background:var(--border); margin:18px 0; }
    .cpd .rail-filters{ display:flex; flex-direction:column; gap:15px; }
    .cpd .field{ display:flex; flex-direction:column; gap:6px; }
    .cpd .field-label{ font-size:12px; font-weight:600; color:var(--ink-600); }
    .cpd .clear-btn{ display:flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:9px 12px; border-radius:9px; border:1px solid var(--border); background:var(--white); color:var(--ink-600); font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s ease, color .15s ease, border-color .15s ease; }
    .cpd .clear-btn:hover{ background:var(--ink-50); color:var(--ink-800); border-color:var(--ink-300); }
    .cpd .tip-box{ margin-top:auto; }
    .cpd .filter-list{ display:flex; flex-direction:column; gap:4px; }
    .cpd .filter-item{ display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-radius:9px; cursor:pointer; font-weight:500; color:#000; font-size:13.5px; border:none; background:none; width:100%; font-family:inherit; text-align:left; }
    .cpd .filter-item:hover{ background:var(--ink-50); }
    .cpd .filter-item.active{ background:var(--green-50); color:#000; font-weight:600; }
    .cpd .filter-item-left{ display:flex; align-items:center; gap:9px; }
    .cpd .filter-item-left svg{ flex-shrink:0; color:#000; stroke-width:2.6; }
    .cpd .filter-count{ font-size:12px; font-weight:600; color:var(--ink-400); background:var(--ink-100); padding:2px 8px; border-radius:20px; }
    .cpd .filter-item.active .filter-count{ background:var(--green-100); color:var(--green-700); }
    .cpd .section-label{ font-size:11px; font-weight:700; letter-spacing:.06em; color:var(--ink-400); text-transform:uppercase; margin:0 0 10px 2px; }
    .cpd select.select-input{ width:100%; padding:10px 12px; border-radius:9px; border:1px solid var(--border); background:var(--white); font-family:inherit; font-size:13.5px; color:var(--ink-700); appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; cursor:pointer; }
    .cpd .text-input-wrap{ position:relative; }
    .cpd .text-input-wrap input{ width:100%; padding:10px 12px 10px 34px; border-radius:9px; border:1px solid var(--border); font-family:inherit; font-size:13px; color:var(--ink-700); outline:none; }
    .cpd .text-input-wrap input:focus{ border-color:var(--green-500); }
    .cpd .text-input-wrap input::placeholder{ color:var(--ink-400); }
    .cpd .text-input-wrap svg{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--ink-400); }
    .cpd .tip-box{ background:var(--green-50); border:1px solid var(--green-100); border-radius:var(--radius-sm); padding:16px; }
    .cpd .tip-title{ display:flex; align-items:center; gap:7px; font-weight:700; font-size:13px; color:var(--green-700); margin-bottom:6px; }
    .cpd .tip-box p{ margin:0; font-size:12.5px; line-height:1.5; color:#3f6b4c; }

    /* Center db */
    .cpd .db-panel{ padding:18px; border-left:none; border-right:1px solid var(--border); display:flex; flex-direction:column; }
    .cpd .db-panel .kpis{ flex-shrink:0; }
    .cpd .db-panel .db-toolbar{ flex-shrink:0; }
    .cpd .db-table-area{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .db-table-area #tableWrap{ flex:1; min-height:0; }
    .cpd .db-panel .dt-pagination{ flex-shrink:0; }
    .cpd .db-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .cpd .view-controls{ display:flex; align-items:center; gap:8px; }
    .cpd .search-grow{ flex:0 1 auto; width:300px; min-width:180px; }
    .cpd .search-grow-wrap{ position:relative; flex:0 1 auto; width:380px; min-width:180px; }
    #reports-pending .search-grow-wrap, #reports-active .search-grow-wrap, #reports-resolved .search-grow-wrap{ width:300px; }
    .cpd .search-grow-wrap input{ width:100%; padding:11px 14px 11px 38px; border-radius:10px; border:1px solid var(--border); font-family:inherit; font-size:13.5px; color:var(--ink-700); outline:none; }
    .cpd .search-grow-wrap input:focus{ border-color:var(--green-500); }
    .cpd .search-grow-wrap input::placeholder{ color:var(--ink-400); }
    .cpd .search-grow-wrap svg{ position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--ink-400); }
    .cpd .sort-select{ width:auto; min-width:150px; max-width:180px; }
    .cpd .toolbar-filters{ display:flex; align-items:center; gap:8px; }
    .cpd .tf-label{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--ink-600); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
    .cpd .tf-select{ width:auto; min-width:120px; max-width:150px; }
    .cpd .tf-date{ padding:10px 12px; border-radius:9px; border:1px solid var(--border); font-family:inherit; font-size:12.5px; color:var(--ink-700); background:var(--white); width:145px; box-sizing:border-box; cursor:pointer; }
    .cpd .tf-date:focus{ outline:none; border-color:var(--green-500); }
    .cpd .tf-search{ padding:10px 12px; border-radius:9px; border:1px solid var(--border); font-family:inherit; font-size:12.5px; color:var(--ink-700); background:var(--white); width:170px; box-sizing:border-box; }
    .cpd .tf-search:focus{ outline:none; border-color:var(--green-500); }
    .cpd .tf-search::placeholder{ color:var(--ink-400); }
    .cpd .tf-sep{ color:var(--ink-400); font-size:13px; }
    .cpd .tf-clear{ width:auto; padding:10px 12px; }

    .cpd table{ width:100%; border-collapse:collapse; }
    .cpd thead th{ text-align:left; font-size:11.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#000; padding:10px 12px; background:var(--ink-100); white-space:nowrap; border-bottom:10px solid transparent; }
    .cpd tbody tr{ cursor:pointer; transition:background .12s ease; border-bottom:1px solid var(--ink-100); }
    .cpd tbody tr:hover{ background:var(--ink-50); }
    .cpd tbody tr.selected{ background:var(--ink-50); }
    .cpd tbody td{ padding:14px 12px; vertical-align:middle; font-size:13.5px; color:var(--ink-700); }
    #client-pets tbody td{ padding:17px 12px; }
    .cpd .client-cell{ display:flex; align-items:center; gap:10px; }
    .cpd .avatar{ width:36px;height:36px; border-radius:50%; display:flex;align-items:center;justify-content:center; font-weight:700; font-size:12.5px; color:var(--green-700); background:var(--green-100); flex-shrink:0; overflow:hidden; }
    .cpd .avatar img{ width:100%; height:100%; object-fit:cover; }
    .cpd .client-name{ font-weight:600; color:var(--ink-900); font-size:13.5px; }
    .cpd .client-id{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .contact-main{ color:var(--ink-700); font-weight:500; }
    .cpd .contact-sub{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .pets-badge{ display:inline-flex; align-items:center; justify-content:center; min-width:24px; padding:2px 8px; border-radius:20px; background:transparent; color:#18181b; font-weight:500; font-size:13px; }
    .cpd .status-pill{ display:inline-flex; align-items:center; padding:3px 10px; border-radius:4px; font-family:'Inter',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    .cpd .status-active{ background:#ECFDF3; color:#027A48; border-color:#D1FADF; }
    .cpd .status-inactive{ background:#FEF3C7; color:#92400E; border-color:#FDE68A; }
    .cpd .row-menu{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .row-menu:hover{ background:var(--ink-100); color:var(--ink-700); }

    /* â�?�?"â�?�?" shadcn data-table (exact look) â�?�?"â�?�?" */
    #client-pets .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #client-pets table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #client-pets thead{ }
    #client-pets thead tr{ border-bottom:1px solid #e4e4e7; }
    #client-pets thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; }
    #client-pets thead th.sd-th-action{ text-align:right; }
    #client-pets tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
    #client-pets tbody tr:last-child{ border-bottom:none; }
    #client-pets tbody tr:hover{ background:#fafafa; }
    #client-pets tbody tr.selected, #client-pets tbody tr.selected:hover{ background:#f4f4f5; }
    #client-pets tbody td{ padding:8px; vertical-align:middle; font-size:14px; color:#18181b; }
    #client-pets tbody td.sd-td-action{ text-align:right; }
    /* sortable header (DataTableColumnHeader) */
    #client-pets .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #client-pets .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #client-pets .th-sort.th-sorted{ color:#18181b; }
    #client-pets .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #client-pets .th-sorted .th-sort-icon{ color:#18181b; }
    /* row actions (ghost icon button h-8 w-8) */
    #client-pets .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #client-pets .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    /* selected count / pagination (shadcn DataTablePagination) */
    #client-pets .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; }
    #client-pets .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    #client-pets .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    #client-pets .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    #client-pets .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #client-pets .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    #client-pets .dt-page-btns{ display:flex; align-items:center; gap:4px; }
    #client-pets .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    #client-pets .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
    #client-pets .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
    /* search input (shadcn Input) */
    #client-pets .search-grow-wrap input{ height:36px; width:100%; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
    #client-pets .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #client-pets .search-grow-wrap input::placeholder{ color:#71717a; }
    #client-pets .search-grow-wrap svg{ color:#71717a; }
    /* toolbar */
    #client-pets .db-toolbar{ justify-content:flex-start; }
    #client-pets .db-toolbar-actions{ margin-left:auto; display:flex; align-items:center; gap:8px; }
    /* toolbar filter selects */
    #client-pets .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #client-pets .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }

    #reports-accepted{ font-family:'Inter','Segoe UI',sans-serif; }
    #reports-accepted .db-panel .kpis{ grid-template-columns:repeat(4,minmax(0,1fr)); }
    #reports-accepted .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #reports-accepted table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #reports-accepted thead tr{ border-bottom:1px solid #e4e4e7; }
    #reports-accepted thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; }
    #reports-accepted thead th.sd-th-action{ text-align:right; }
    #reports-accepted tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
    #reports-accepted tbody tr:last-child{ border-bottom:none; }
    #reports-accepted tbody tr:hover{ background:#fafafa; }
    #reports-accepted tbody tr.selected, #reports-accepted tbody tr.selected:hover{ background:#f4f4f5; }
    #reports-accepted tbody td{ padding:8px; vertical-align:middle; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    #reports-accepted tbody td.sd-td-action{ text-align:right; }
    #reports-accepted .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #reports-accepted .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    #reports-accepted .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12.5px; font-weight:400; color:#71717a; line-height:1.4; margin-top:2px; word-break:break-word; }
    #reports-accepted .contact-main.report-id, #reports-accepted .client-name.report-id{ font-weight:400; }
    #reports-accepted .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #reports-accepted .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #reports-accepted .th-sort.th-sorted{ color:#18181b; }
    #reports-accepted .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #reports-accepted .th-sorted .th-sort-icon{ color:#18181b; }
    #reports-accepted .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #reports-accepted .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    @media(max-width:900px){ #reports-accepted .db-panel .kpis{ grid-template-columns:repeat(2,minmax(0,1fr)); } }

    /* dialog font style applied across the clients page */
    #client-pets{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .kpi-card{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    #client-pets .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #client-pets .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    #client-pets .search-grow-wrap input{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    #client-pets tbody td{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #client-pets .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; margin-top:0; }
    #client-pets .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    #client-pets .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    /* dialog font style - right profile panel */
    #client-pets .profile-panel{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .profile-name-row h2{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    #client-pets .profile-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #client-pets .tab{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; }
    #client-pets .tab.active{ color:#18181b; }
    #client-pets .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; color:#71717a; margin-bottom:2px; }
    #client-pets .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; color:#18181b; }
    #client-pets .profile-empty{ color:#71717a; }
    #client-pets .profile-empty p{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    #client-pets .profile-loading{ color:#71717a; }
    #client-pets .pet-name-row .pet-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .pet-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    #client-pets .pet-meta{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; }

    /* Row menu dropdown */
    .cpd .row-menu-pop{ position:fixed!important; z-index:9999!important; background:#ffffff!important; border:1px solid #e6e9ee!important; border-radius:10px!important; box-shadow:0 4px 14px rgba(15,23,32,.06)!important; padding:6px!important; min-width:190px!important; display:none; }
    .cpd .row-menu-pop.open{ display:block!important; }
    .cpd .row-menu-item{ display:flex!important; align-items:center!important; gap:10px!important; width:100%!important; padding:9px 10px!important; border:none!important; background:none!important; border-radius:8px!important; font-family:'Inter','Segoe UI',system-ui,sans-serif!important; font-size:13px!important; font-weight:500!important; color:#334155!important; cursor:pointer!important; text-align:left!important; line-height:1.4!important; }
    .cpd .row-menu-item:hover{ background:#f8fafc!important; }
    .cpd .row-menu-item.danger{ color:#dc2626!important; }
    .cpd .row-menu-item.danger:hover{ background:#fef2f2!important; }
    .cpd .row-menu-item svg{ flex-shrink:0!important; width:15px!important; height:15px!important; }

    .cpd .cards-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
    .cpd .client-card{ border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease; background:var(--white); }
    .cpd .client-card:hover{ border-color:var(--green-500); box-shadow:var(--shadow-md); }
    .cpd .client-card.selected{ background:var(--green-50); border-color:var(--green-500); }
    .cpd .client-card-top{ display:flex; align-items:center; gap:12px; margin-bottom:12px; }
    .cpd .client-card .avatar{ width:46px;height:46px; font-size:15px; }
    .cpd .client-card-name{ font-weight:700; color:var(--ink-900); font-size:14px; }
    .cpd .client-card-id{ font-size:11px; color:var(--ink-400); margin-top:2px; }
    .cpd .client-card-meta{ display:flex; justify-content:space-between; align-items:center; }
    .cpd .client-card-contact{ font-size:12px; color:var(--ink-500); }
.cpd .pet-card{ display:flex; align-items:stretch; border:1px solid var(--border); border-radius:var(--radius-sm); margin-bottom:10px; background:var(--white); cursor:pointer; overflow:hidden; padding:0; }
.cpd .pet-photo{ width:104px; flex-shrink:0; background:var(--ink-100); overflow:hidden; display:flex;align-items:center;justify-content:center; color:var(--green-700); font-weight:800; font-size:22px; min-height:104px; }
.cpd .pet-photo img{ width:100%; height:100%; object-fit:cover; }
.cpd .pet-info{ flex:1; min-width:0; padding:12px; }
.cpd .pet-name-row{ display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:3px; }
.cpd .pet-name-row .pet-name{ font-size:15px; font-weight:700; color:var(--ink-900); }
.cpd .pet-id{ font-size:12.5px; color:var(--ink-400); }
.cpd .pet-meta{ font-size:12.5px; color:var(--ink-500); margin-bottom:7px; }
.cpd .pet-foot{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.cpd .vax-pill{ font-size:12px; font-weight:500; padding:3px 10px; border-radius:4px; display:inline-flex; align-items:center; gap:4px; font-family:'Inter',sans-serif; line-height:1.4; border:1px solid #e4e4e7; white-space:nowrap; }
.cpd .vax-yes{ background:#ECFDF3; color:#027A48; border-color:#D1FADF; }
.cpd .vax-no{ background:#FEE2E2; color:#991B1B; border-color:#FECACA; }
    .cpd .view-pet-btn{ display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:700; color:var(--white); background:var(--green-600); border:none; padding:6px 10px; cursor:pointer; }
    .cpd .view-pet-btn:hover{ background:var(--green-700); }

    .cpd .pagination{ display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:14px; border-top:1px solid var(--border); flex-wrap:wrap; gap:10px; }
    .cpd .pagination-info{ font-size:12.5px; color:var(--ink-500); }
    .cpd .pagination-controls{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .cpd .page-btn{ min-width:32px;height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); font-size:12.5px; font-weight:600; cursor:pointer; display:inline-flex;align-items:center;justify-content:center; }
    .cpd .page-btn:hover{ background:var(--ink-50); }
    .cpd .page-btn.active{ background:var(--green-600); border-color:var(--green-600); color:var(--white); }
    .cpd .page-btn:disabled{ opacity:.45; cursor:not-allowed; }
    .cpd .page-select{ padding:7px 26px 7px 10px; border-radius:8px; border:1px solid var(--border); font-family:inherit; font-size:12.5px; color:var(--ink-700); appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 8px center; }

    /* Right profile */
    .cpd .profile-panel{ padding:18px; border-left:none; display:flex; flex-direction:column; overflow-y:auto; }
    .cpd .profile-panel #profileContent{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .profile-panel #rptDetailContent, .cpd .profile-panel #penDetailContent, .cpd .profile-panel #actDetailContent, .cpd .profile-panel #resDetailContent{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cd-drawer #rptDetailContent, .cd-drawer #penDetailContent, .cd-drawer #actDetailContent, .cd-drawer #resDetailContent{ font-family:'Inter','Segoe UI',sans-serif; }
    .cd-drawer .rpt-detail-ref{ font-family:'Inter','Segoe UI',sans-serif; }
    .cd-drawer .rpt-detail-body h2, .cd-drawer .rpt-detail-body h3, .cd-drawer .rpt-detail-body h4,
    .cd-drawer .rpt-detail-body .rpt-section-title, .cd-drawer .rpt-detail-body .info-row-label,
    .cd-drawer .rpt-detail-body .info-row-value, .cd-drawer .rpt-detail-body .rpt-desc-box,
    .cd-drawer .rpt-detail-body .rpt-notes-box,     .cd-drawer .rpt-detail-body .rpt-h-item{ font-family:'Inter','Segoe UI',sans-serif; }
    .cd-drawer .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; text-transform:none; letter-spacing:0; margin:18px 0 10px; }
    .cpd .rpt-detail-body[data-tab="details"]{ display:flex; flex-direction:column; flex:1; }
    .cpd .profile-header{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
    .cpd .profile-avatar{ width:96px;height:96px; border-radius:14px; background:var(--green-100); color:var(--green-700); font-weight:700; font-size:32px; display:flex;align-items:center;justify-content:center; flex-shrink:0; overflow:hidden; }
    .cpd .profile-avatar img{ width:100%; height:100%; object-fit:cover; }
    .cpd .profile-name-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cpd .profile-name-row h2{ font-size:20px; font-weight:700; margin:0; color:var(--ink-900); font-family:'Inter',sans-serif; }
    .cpd .profile-id{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .close-x{ margin-left:auto; color:var(--ink-400); cursor:pointer; background:none;border:none; padding:4px; }
    .cpd .close-x:hover{ color:var(--ink-700); }
    .cpd .tabs{ display:flex; justify-content:center; gap:28px; border-bottom:1px solid var(--border); margin-bottom:20px; }
    .cpd .tab{ padding:0 0 12px; font-size:14px; font-weight:600; color:var(--ink-400); cursor:pointer; border-bottom:2px solid transparent; background:none;border:none; font-family:inherit; }
    .cpd .tab.active{ color:var(--green-700); border-bottom-color:var(--green-600); }
    .cpd .rpt-tabs{ display:flex; justify-content:center; gap:28px; border-bottom:1px solid var(--border); margin-bottom:20px; }
    .cpd .rpt-tab{ padding:0 0 12px; font-size:14px; font-weight:600; color:var(--ink-400); cursor:pointer; border-bottom:2px solid transparent; background:none;border:none; font-family:inherit; }
    .cpd .rpt-tab.active{ color:var(--green-700); border-bottom-color:var(--green-600); }
    .cpd .rpt-profile-btn{ margin-bottom:18px; }
    .cpd .info-card-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .cpd .info-card-head h3{ font-size:13.5px; font-weight:700; margin:0; color:var(--ink-900); }
    .cpd .link-btn{ display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:var(--green-700); background:none; border:none; cursor:pointer; padding:6px 10px; border-radius:7px; font-family:inherit; }
    .cpd .link-btn:hover{ background:var(--green-50); }
    .cpd .info-rows{ display:flex; flex-direction:column; gap:14px; margin-bottom:22px; }
    .cpd .info-row{ display:flex; align-items:flex-start; gap:11px; }
    .cpd .info-row-avatar{ width:36px;height:36px; border-radius:50%; background:#dcebe0; color:#045e1f; font-weight:700; font-size:12px; overflow:hidden; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
    .cpd .info-row-avatar img{ width:100%; height:100%; object-fit:cover; border-radius:50%; }
    .cpd .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; color:#71717a; margin-bottom:2px; }
    .cpd .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; color:#18181b; word-break:break-word; }
    .cpd .profile-empty{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .profile-empty svg{ margin-bottom:12px; color:var(--ink-300); }
    .cpd .profile-empty p{ margin:0; font-size:13px; }
    .cpd .profile-footer{ display:flex; align-items:center; justify-content:center; gap:10px; margin-top:auto; padding-top:16px; border-top:1px solid var(--border); }
    .cpd .profile-loading{ text-align:center; padding:40px 0; color:var(--ink-400); }

    /* Report-specific */
    .cpd .view-controls{ display:flex; align-items:center; gap:4px; background:var(--ink-100); padding:3px; border-radius:9px; }
    .cpd .view-controls .seg-btn{ border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--ink-500); padding:6px 12px; border-radius:7px; cursor:pointer; }
    .cpd .view-controls .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }
    .cpd .type-badge{ display:inline-flex; align-items:center; gap:6px; padding:0; border-radius:0; font-size:12px; font-weight:600; white-space:nowrap; color:var(--ink-700); background:none; }
    .cpd .type-badge .type-dot{ display:none; }
    .cpd .type-stray, .cpd .type-rabies, .cpd .type-injured, .cpd .type-dead, .cpd .type-rescue{ background:none; color:var(--ink-700); }
    .cpd .rpt-status{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; white-space:nowrap; color:var(--ink-700); }
    .cpd .rpt-status svg{ flex-shrink:0; }
    .cpd .urgency-tag{ display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; }
    .cpd .urgency-low{ background:#eef6f0; color:#045e1f; }
    .cpd .urgency-medium{ background:#fffbeb; color:#b45309; }
    .cpd .urgency-high{ background:#fef2f2; color:#b91c1c; }
    .cpd .urgency-critical{ background:#7f1d1d; color:#fff; }
    .cpd .rpt-cards{ display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
    .cpd .rpt-card{ background:var(--white); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; cursor:pointer; transition:box-shadow .2s, border-color .2s; display:flex; flex-direction:column; gap:4px; }
    .cpd .rpt-card:hover{ box-shadow:var(--shadow-md); border-color:var(--green-200); }
    .cpd .rpt-card.selected{ border-color:var(--green-600); box-shadow:0 0 0 2px rgba(4,107,37,.15); }
    .cpd .rpt-card-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
    .cpd .rpt-card-ref{ font-size:11.5px; font-weight:700; color:var(--ink-500); }
    .cpd .rpt-card-name{ font-size:14px; font-weight:700; color:var(--ink-900); }
    .cpd .rpt-card-brgy{ font-size:12px; color:var(--ink-500); }
    .cpd .rpt-card-foot{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:8px; padding-top:10px; border-top:1px solid var(--border); }
    .cpd .rpt-card-date{ font-size:11.5px; color:var(--ink-400); }
    .cpd .rpt-card-link{ color:var(--green-700); font-weight:700; font-size:11.5px; }
    .cpd .rpt-map-toolbar{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .cpd .rpt-map-search{ position:relative; flex:0 1 auto; width:260px; min-width:180px; }
    .cpd .rpt-map-search svg{ position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--ink-400); pointer-events:none; }
    .cpd .rpt-map-search input{ width:100%; padding:8px 12px 8px 32px; border:1px solid var(--border); border-radius:8px; font-size:13px; outline:none; background:var(--white); color:var(--ink-900); transition:border-color .2s; }
    .cpd .rpt-map-search input:focus{ border-color:var(--green-600); box-shadow:0 0 0 3px rgba(4,107,37,.12); }
    .cpd .rpt-map-stage{ position:relative; border:1px solid var(--border); border-radius:12px; overflow:hidden; background:var(--ink-50); }
    .cpd .rpt-geo-map{ width:100%; height:460px; position:relative; }
    .cpd .rpt-geo-panel{ position:absolute; top:12px; right:12px; width:264px; background:var(--white); border-radius:12px; box-shadow:0 4px 24px rgba(15,23,32,.15); z-index:20; transform:translateX(calc(100% + 40px)); opacity:0; transition:transform .3s cubic-bezier(.4,0,.2,1), opacity .3s ease; pointer-events:none; overflow:hidden; }
    .cpd .rpt-geo-panel.open{ transform:translateX(0); opacity:1; pointer-events:auto; }
    .cpd .rpt-geo-panel-head{ display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border); }
    .cpd .rpt-geo-panel-head h3{ font-size:14px; font-weight:700; color:var(--ink-900); margin:0; }
    .cpd .rpt-geo-panel-close{ background:none; border:none; font-size:18px; line-height:1; color:var(--ink-400); cursor:pointer; padding:2px 6px; border-radius:6px; transition:all .2s; }
    .cpd .rpt-geo-panel-close:hover{ background:var(--ink-100); color:var(--ink-900); }
    .cpd .rpt-geo-panel-body{ padding:14px; }
    .cpd .rpt-geo-risk{ display:inline-block; padding:2px 10px; border-radius:20px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; margin-bottom:10px; background:#dcebe0; color:#045e1f; }
    .cpd .rpt-geo-stat{ display:flex; flex-direction:column; gap:1px; margin-bottom:8px; }
    .cpd .rpt-geo-stat-label{ font-size:11.5px; color:var(--ink-500); }
    .cpd .rpt-geo-stat-value{ font-size:17px; font-weight:700; color:var(--ink-900); }
    .cpd .mapcn-loading{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; gap:8px; color:var(--ink-400); font-size:13px; z-index:5; background:var(--ink-50); }
    .cpd .mapcn-loading svg{ animation:rpt-spin 1s linear infinite; }
    @keyframes rpt-spin{ to{ transform:rotate(360deg); } }
    @media (max-width:600px){
      .cpd .rpt-map-toolbar{ flex-direction:column; align-items:stretch; }
      .cpd .rpt-map-search{ min-width:0; }
      .cpd .rpt-geo-map{ height:380px; }
      .cpd .rpt-geo-panel{ width:230px; top:10px; right:10px; }
    }
    .cpd .map-placeholder{ display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:60px 20px; text-align:center; color:var(--ink-400); border:1px dashed var(--border); border-radius:var(--radius-sm); background:var(--ink-50); }
    .cpd .map-placeholder svg{ color:var(--ink-300); }
    .cpd .map-placeholder p{ margin:0; font-size:13px; max-width:320px; }
    .cpd .rpt-actions-btn{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .rpt-actions-btn:hover{ background:var(--ink-100); color:var(--ink-700); }
    .cpd .rpt-detail-header{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
    .cpd .rpt-detail-grid{ display:grid; grid-template-columns:1fr 1fr; gap:17px 18px; align-items:start; }
    .cpd .rpt-detail-grid .rpt-col .rpt-section-title{ margin-top:6px; }
    .cpd .rpt-detail-grid .info-rows{ gap:12px; margin-bottom:0; }
    .cpd .rpt-detail-grid .info-rows.info-rows-2col{ display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; align-items:start; }
    .cpd .rpt-response-custody-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px 18px; align-items:start; margin-bottom:0; }
    .cpd .rpt-response-custody-section{ margin-bottom:10px; }
    .cpd .response-steps{ display:flex;align-items:flex-start;padding:3px 0 11px; }
    .cpd .response-step{ position:relative;display:flex;flex:1;min-width:0;flex-direction:column;align-items:center;gap:6px;text-align:center;color:#a1a1aa;font-size:11px;font-weight:500;line-height:1.35; }
    .cpd .response-step:not(:last-child)::after{ position:absolute;z-index:0;top:8px;left:calc(50% + 10px);width:calc(100% - 20px);height:2px;content:"";background:#d4d4d8; }
    .cpd .response-step.is-complete:not(:last-child)::after{ background:#22c55e; }
    .cpd .response-step-dot{ position:relative;z-index:1;display:flex;width:18px;height:18px;flex:0 0 18px;align-items:center;justify-content:center;border:2px solid #d4d4d8;border-radius:50%;background:#fff;color:#fff; }
    .cpd .response-step.is-complete .response-step-dot{ border-color:#16a34a;background:#16a34a; }
    .cpd .response-step.is-current{ color:#18181b;font-weight:600; }
    .cpd .response-step.is-current .response-step-dot{ border-color:#16a34a;background:#fff; }
    .cpd .response-step.is-overdue .response-step-dot{ border-color:#ef4444;background:#fff; }
    .cpd .response-step>span:last-child{ max-width:100%;overflow-wrap:anywhere; }
    #brgDetailsModal .brg-drawer-reporter{ display:flex;align-items:center;gap:10px;margin-bottom:14px; }
    #brgDetailsModal .brg-drawer-photo{ width:85px;height:85px;flex:0 0 85px;overflow:hidden;border-radius:8px;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:600;color:#64748b; }
    #brgDetailsModal .brg-drawer-reporter-copy{ display:flex;min-width:0;flex-direction:column;align-items:flex-start;gap:3px; }
    #brgDetailsModal .brg-drawer-reporter-copy span{ color:#71717a;font-size:12px; }
    #brgDetailsModal .brg-drawer-reporter-copy strong{ overflow:hidden;color:#18181b;font-size:18px;font-weight:600;text-overflow:ellipsis;white-space:nowrap; }
    #brgDetailsModal .brg-drawer-reporter-copy .brg-drawer-status{ margin:0 0 2px;flex:0 0 auto;padding:4px 9px;border:1px solid #bfdbfe;border-radius:6px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:500;white-space:nowrap; }
    #brgDetailsModal .brg-drawer-priority{ display:flex;align-items:center;gap:7px;margin:0 0 16px;font-size:14px;font-weight:500;color:#18181b; }
    #brgDetailsModal .brg-response-card{ overflow:hidden;margin:0 0 18px;border:1px solid #e4e4e7;border-radius:10px;background:#fff; }
    #brgDetailsModal .brg-response-heading{ display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:12px 12px 8px; }
    #brgDetailsModal .brg-response-heading>div{ display:flex;min-width:0;flex-direction:column;gap:3px; }
    #brgDetailsModal .brg-response-heading strong{ font-size:14px;font-weight:600;color:#18181b; }
    #brgDetailsModal .brg-response-heading>div>span{ color:#71717a;font-size:12px; }
    #brgDetailsModal .brg-response-deadline{ flex:0 0 auto;padding:4px 8px;border-radius:6px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:500;text-align:right; }
    #brgDetailsModal .brg-response-deadline.is-overdue{ background:#fff7ed;color:#9a3412; }
    #brgDetailsModal .response-steps{ padding:3px 12px 11px; }
    #brgDetailsModal .brg-response-note{ display:flex;align-items:flex-start;gap:8px;padding:10px 12px;background:#eff6ff;color:#1d4ed8;font-size:12px;line-height:1.5; }
    #brgDetailsModal .brg-response-note>span:first-child{ flex:0 0 auto;font-size:14px;font-weight:700; }
    #brgDetailsModal .brg-response-map-link{ width:100%;padding:8px 12px;border:0;background:#fff;color:#2563eb;font-family:inherit;font-size:12px;font-weight:600;text-align:left;cursor:pointer; }
    #brgDetailsModal .brg-response-map-link:hover{ background:#fafafa;text-decoration:underline; }
    #brgDetailsModal .brg-custody-section{ margin:0 0 16px; }
    #brgDetailsModal .brg-custody-section .rpt-section-title{ margin:0 0 14px; }
    @media(max-width:480px){ #brgDetailsModal .brg-drawer-photo{ width:64px;height:64px;flex-basis:64px; } #brgDetailsModal .brg-drawer-status{ white-space:normal;text-align:center; } #brgDetailsModal .brg-response-heading{ flex-direction:column; } }
    #penDetailsModal .profile-footer{ border-top:0; }
    .cpd .handover-plan-card{ margin:12px 0;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:10px;overflow:hidden;background:#fff; }
    .cpd .handover-plan-card.is-planned{ border-color:#60a5fa;border-left-color:#60a5fa; }
    .cpd .handover-plan-card.is-overdue{ border-color:#ef4444;border-left-color:#ef4444; }
    .cpd .handover-plan-heading{ display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#fffbeb; }
    .cpd .handover-plan-card.is-planned .handover-plan-heading{ background:#eff6ff; }
    .cpd .handover-plan-heading>div{ display:flex;min-width:0;flex-direction:column;gap:2px; }
    .cpd .handover-plan-heading strong{ font-size:14px;color:#18181b; }
    .cpd .handover-plan-heading span{ font-size:12px;color:#71717a; }
    .cpd .handover-plan-status{ flex:0 0 auto;padding:3px 8px;border:1px solid #fde68a;border-radius:6px;background:#fef3c7;color:#92400e!important;font-size:12px!important;font-weight:600; }
    .cpd .handover-plan-card.is-planned .handover-plan-status{ border-color:#bfdbfe;background:#dbeafe;color:#1d4ed8!important; }
    .cpd .handover-plan-body{ padding:8px 12px; }
    .cpd .handover-plan-row{ display:flex;justify-content:space-between;gap:12px;padding:4px 0;font-size:13px;line-height:1.5; }
    .cpd .handover-plan-row span{ flex:0 0 38%;color:#71717a;font-size:13px;font-weight:600; }
    .cpd .handover-plan-row strong{ min-width:0;color:#18181b;font-size:15px;font-weight:400;text-align:right;overflow-wrap:anywhere; }
    .cpd .handover-plan-note{ margin:0;padding:9px 12px;border-top:1px solid #e4e4e7;background:#f4f4f5;color:#52525b;font-size:12px;line-height:1.5; }
    .rpt-turnover-confirm{ border:1px solid #18181b;background:#18181b;color:#fff; }
    .rpt-turnover-confirm:hover:not(:disabled){ background:#27272a; }
    .rpt-turnover-confirm:disabled,.rpt-turnover-archive:disabled{ cursor:not-allowed;opacity:.55; }
    .cpd .rpt-full-col{ grid-column:1 / -1; }
    .cpd .rpt-detail-grid + .rpt-full-col, .cpd .rpt-detail-grid ~ .rpt-full-col{ margin-top:6px; }
    #penDetailsModal .pen-reporter-row{ display:flex;align-items:center;gap:12px;margin-bottom:14px; }
    #penDetailsModal .pen-reporter-avatar{ width:42px;height:42px;border-radius:50%;flex:0 0 auto;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;color:#64748b; }
    #penDetailsModal .pen-reporter-avatar img{ width:100%;height:100%;object-fit:cover; }
    #penDetailsModal .pen-reporter-status{ align-self:flex-start;margin:0 0 2px;white-space:nowrap; }
    #penDetailsModal .rpt-status{ display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7; }
    #penDetailsModal .rpt-status.pending{background:#fef3c7;color:#92400e;border-color:#fde68a;}
    #penDetailsModal .rpt-status.validated{background:#dcebe0;color:#045e1f;border-color:#bbf7d0;}
    #penDetailsModal .pen-attachment-count{ display:inline-flex;min-width:18px;height:18px;align-items:center;justify-content:center;margin-left:5px;padding:0 5px;border-radius:999px;background:#eef6f0;color:#045e1f;font-size:11px;font-weight:600;line-height:1;vertical-align:middle; }
    #rptDetailsModal .pen-validation-card,#penDetailsModal .pen-validation-card,#actDetailsModal .pen-validation-card,#resDetailsModal .pen-validation-card{ margin:0 0 18px;border:1px solid #16a34a;border-left:4px solid #16a34a;border-radius:12px;overflow:hidden;background:#fff;font-family:'Inter','Segoe UI',sans-serif; }
    #rptDetailsModal .pen-validation-heading,#penDetailsModal .pen-validation-heading,#actDetailsModal .pen-validation-heading,#resDetailsModal .pen-validation-heading{ display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f0fdf4; }
    #rptDetailsModal .pen-validation-heading-icon,#penDetailsModal .pen-validation-heading-icon,#actDetailsModal .pen-validation-heading-icon,#resDetailsModal .pen-validation-heading-icon{ width:18px;height:18px;flex:0 0 auto;color:#15803d; }
    #rptDetailsModal .pen-validation-heading-copy,#penDetailsModal .pen-validation-heading-copy,#actDetailsModal .pen-validation-heading-copy,#resDetailsModal .pen-validation-heading-copy{ min-width:0; }
    #rptDetailsModal .pen-validation-title,#penDetailsModal .pen-validation-title,#actDetailsModal .pen-validation-title,#resDetailsModal .pen-validation-title{ font-size:14px;font-weight:600;color:#18181b; }
    #rptDetailsModal .pen-validation-meta,#penDetailsModal .pen-validation-meta,#actDetailsModal .pen-validation-meta,#resDetailsModal .pen-validation-meta{ margin-top:2px;font-size:12px;font-weight:400;color:#71717a; }
    #rptDetailsModal .pen-validation-status,#penDetailsModal .pen-validation-status,#actDetailsModal .pen-validation-status,#resDetailsModal .pen-validation-status{ display:inline-flex;align-items:center;margin-left:auto;padding:3px 10px;border-radius:6px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;line-height:1.4;white-space:nowrap; }
    #rptDetailsModal .pen-validation-content,#penDetailsModal .pen-validation-content,#actDetailsModal .pen-validation-content,#resDetailsModal .pen-validation-content{ display:flex;flex-direction:column;gap:10px;padding:12px 14px 14px; }
    #rptDetailsModal .pen-validation-message-field-label{ display:inline;margin:0;font-size:13px;font-weight:600;line-height:1.5;color:#71717a; }
    #rptDetailsModal .pen-validation-message-field-value{ display:inline;font-size:15px;font-weight:400;line-height:1.5;color:#18181b;overflow-wrap:anywhere; }
    #rptDetailsModal .pen-validation-label,#penDetailsModal .pen-validation-label,#actDetailsModal .pen-validation-label,#resDetailsModal .pen-validation-label{ margin-bottom:6px;font-size:13px;font-weight:600;color:#71717a; }
    #rptDetailsModal .pen-validation-message,#penDetailsModal .pen-validation-message,#actDetailsModal .pen-validation-message,#resDetailsModal .pen-validation-message{ padding:10px 12px;border-radius:9px;background:#f4f4f5;font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;line-height:1.5;color:#18181b;white-space:pre-wrap; }
    #rptDetailsModal .pen-validation-evidence-grid,#penDetailsModal .pen-validation-evidence-grid,#actDetailsModal .pen-validation-evidence-grid,#resDetailsModal .pen-validation-evidence-grid{ display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,112px));gap:8px; }
    #rptDetailsModal .pen-validation-evidence,#penDetailsModal .pen-validation-evidence,#actDetailsModal .pen-validation-evidence,#resDetailsModal .pen-validation-evidence{ position:relative;width:100%;aspect-ratio:1/1;padding:0;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;background:#f4f4f5;cursor:pointer; }
    #rptDetailsModal .pen-validation-evidence img,#rptDetailsModal .pen-validation-evidence video,#penDetailsModal .pen-validation-evidence img,#penDetailsModal .pen-validation-evidence video,#actDetailsModal .pen-validation-evidence img,#actDetailsModal .pen-validation-evidence video,#resDetailsModal .pen-validation-evidence img,#resDetailsModal .pen-validation-evidence video{ display:block;width:100%;height:100%;object-fit:cover; }
    #rptDetailsModal .pen-validation-evidence video,#penDetailsModal .pen-validation-evidence video,#actDetailsModal .pen-validation-evidence video,#resDetailsModal .pen-validation-evidence video{ background:#18181b; }
    #rptDetailsModal .pen-validation-evidence:focus-visible,#penDetailsModal .pen-validation-evidence:focus-visible,#actDetailsModal .pen-validation-evidence:focus-visible,#resDetailsModal .pen-validation-evidence:focus-visible{ outline:2px solid #16a34a;outline-offset:2px; }
    #rptDetailsModal .pen-response-proposal{ display:flex;align-items:flex-start;gap:8px;padding:10px 11px;border:1px solid #bfdbfe;border-radius:9px;background:#eff6ff;color:#1d4ed8;font-size:12px;line-height:1.45; }
    #rptDetailsModal .pen-response-proposal>span{ flex:0 0 auto;font-size:15px; }
    #rptDetailsModal .pen-response-proposal strong,#rptDetailsModal .pen-response-proposal div>span{ display:block; }
    #rptDetailsModal .pen-response-proposal strong{ font-weight:700; }
    #rptDetailsModal .pen-response-proposal,#rptDetailsModal .rpt-response-risk,#rptDetailsModal .rpt-response-decision-footer{ font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
    #rptDetailsModal .pen-response-proposal{ font-size:13px;font-weight:400;line-height:1.45; }
    #rptDetailsModal .rpt-response-risk{ margin:-4px 0 16px;padding:11px 12px;border:1px solid #d4d4d8;border-radius:8px;background:#fafafa;color:#18181b;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:13px;font-weight:400;line-height:1.45; }
    #rptDetailsModal .rpt-response-risk.is-high-risk{ border-color:#dc2626;background:#fef2f2;color:#991b1b; }
    #rptDetailsModal .rpt-response-risk.is-low-risk{ border-color:#bbf7d0;background:#f0fdf4;color:#166534; }
    #rptDetailsModal .rpt-response-risk-title{ display:flex;align-items:center;gap:6px;margin-bottom:7px;font-size:14px;font-weight:600;line-height:1.4;letter-spacing:-.01em; }
    #rptDetailsModal .rpt-response-risk-row{ display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:18px; }
    #rptDetailsModal .rpt-response-risk-row>span:last-child{ text-align:right; }
    #rptDetailsModal .rpt-response-risk-label{ display:flex;align-items:center;gap:6px; }
    #rptDetailsModal .rpt-response-risk-row.is-risk{ color:#dc2626; }
    #rptDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-row.is-risk{ color:#f87171; }
    #rptDetailsModal .rpt-response-risk-row.is-safe{ color:#15803d; }
    #rptDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-row.is-safe{ color:#4ade80; }
    #rptDetailsModal .rpt-response-risk-row.is-suggestion{ color:#64748b; }
    #rptDetailsModal .rpt-response-risk-explanation{ margin:7px 0 0;font-size:13px;font-weight:500;line-height:1.45; }
    #rptDetailsModal .rpt-response-risk-note{ margin:5px 0 0;color:#71717a;font-size:11px;font-weight:400;line-height:1.4; }
    #rptDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-note{ color:#7f1d1d; }
    #rptDetailsModal .rpt-response-override-link{ margin-top:3px;padding:0;border:0;background:none;color:#b91c1c;text-decoration:underline;font:inherit;cursor:pointer; }
    #rptDetailsModal .rpt-response-override-recorded{ margin-top:4px;color:#b91c1c;font-size:12px;font-weight:500;overflow-wrap:anywhere; }
    #rptDetailsModal .rpt-response-decision-footer{ width:100%;min-width:0;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
    #rptDetailsModal .rpt-response-decision-hint{ overflow:hidden;margin-bottom:6px;color:#71717a;font-size:12px;font-weight:400;line-height:1.35;text-overflow:ellipsis;white-space:nowrap; }
    #rptDetailsModal .rpt-response-decision-actions{ display:grid;grid-template-columns:36px minmax(0,1fr) minmax(0,1fr);align-items:center;gap:8px;width:100%;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
    #rptDetailsModal .rpt-response-decision-actions>.sd-btn,#rptDetailsModal .rpt-response-more-wrap>.sd-btn{ display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:0;height:36px;padding:0 8px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1;white-space:nowrap; }
    #rptDetailsModal .rpt-response-more-wrap>.sd-btn{ width:36px;padding:0;font-size:22px;font-weight:400; }
    #rptDetailsModal .rpt-response-decision-actions .sd-btn:focus-visible,#rptDetailsModal .rpt-response-more-menu button:focus-visible{ outline:2px solid #18181b;outline-offset:2px; }
    #rptDetailsModal .rpt-response-primary{ border:1px solid #18181b;background:#18181b;color:#fff; }
    #rptDetailsModal .rpt-response-primary:hover{ background:#27272a; }
    #rptDetailsModal .rpt-response-locked{ border:1px solid #e4e4e7;background:#f4f4f5;color:#71717a;cursor:not-allowed; }
    #rptDetailsModal .rpt-response-more-wrap{ position:relative;min-width:0; }
    #rptDetailsModal .rpt-response-more-menu{ position:absolute;z-index:4;bottom:calc(100% + 7px);left:0;min-width:165px;padding:5px;border:1px solid #d4d4d8;border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(0,0,0,.16); }
    #rptDetailsModal .rpt-response-more-menu[hidden]{ display:none; }
    #rptDetailsModal .rpt-response-more-menu button{ display:block;width:100%;padding:8px 9px;border:0;border-radius:5px;background:none;text-align:left;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1.4;color:#18181b;cursor:pointer; }
    #rptDetailsModal .rpt-response-more-menu button:hover{ background:#f4f4f5; }
    #rptDetailsModal .rpt-response-more-menu button.is-danger{ color:#b91c1c; }
    #rptDetailsModal .rpt-response-history-reason{ margin-top:4px;color:#52525b;font-size:12px;white-space:pre-wrap;overflow-wrap:anywhere; }
    #penDetailsModal .pen-response-proposal{ display:flex;align-items:flex-start;gap:8px;padding:10px 11px;border:1px solid #bfdbfe;border-radius:9px;background:#eff6ff;color:#1d4ed8;font-size:12px;line-height:1.45; }
    #penDetailsModal .pen-response-proposal strong,#penDetailsModal .pen-response-proposal div>span{ display:block; }
    #penDetailsModal .pen-response-proposal strong{ font-weight:700; }
    #penDetailsModal .rpt-response-risk{ margin:0 0 16px;padding:11px 12px;border:1px solid #d4d4d8;border-radius:10px;background:#fafafa;color:#18181b;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:13px;font-weight:400;line-height:1.45; }
    #penDetailsModal .rpt-response-risk.is-high-risk{ border-color:#dc2626;background:#fef2f2;color:#991b1b; }
    #penDetailsModal .rpt-response-risk-title{ display:flex;align-items:center;gap:6px;margin-bottom:7px;font-size:14px;font-weight:600;line-height:1.4; }
    #penDetailsModal .rpt-response-risk-row{ display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:18px; }
    #penDetailsModal .rpt-response-risk-row>span:last-child{ text-align:right; }
    #penDetailsModal .rpt-response-risk-label{ display:flex;align-items:center;gap:6px; }
    #penDetailsModal .rpt-response-risk-row.is-risk{ color:#dc2626; }
    #penDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-row.is-risk{ color:#dc2626; }
    #penDetailsModal .rpt-response-risk-row.is-safe{ color:#15803d; }
    #penDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-row.is-safe{ color:#15803d; }
    #penDetailsModal .rpt-response-risk-row.is-suggestion{ color:#64748b; }
    #penDetailsModal .rpt-response-risk-explanation{ margin:7px 0 4px;font-size:13px;font-weight:500;line-height:1.45; }
    #penDetailsModal .rpt-response-risk-note{ margin:5px 0 0;color:#71717a;font-size:11px;font-weight:400;line-height:1.4; }
    #penDetailsModal .rpt-response-risk.is-high-risk .rpt-response-risk-note{ color:#7f1d1d; }
    #penDetailsModal .rpt-response-override-link{ margin-top:3px;padding:0;border:0;background:none;color:#b91c1c;text-decoration:underline;font:inherit;cursor:pointer; }
    #penDetailsModal .rpt-response-override-recorded{ margin-top:4px;color:#b91c1c;font-size:12px;font-weight:500;overflow-wrap:anywhere; }
    #penDetailsModal .rpt-response-decision-footer{ width:100%;min-width:0; }
    #penDetailsModal .rpt-response-decision-hint{ overflow:hidden;margin-bottom:6px;color:#71717a;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:12px;font-weight:400;line-height:1.35;text-overflow:ellipsis;white-space:nowrap; }
    #penDetailsModal .rpt-response-decision-actions{ display:grid;grid-template-columns:36px minmax(0,1fr) minmax(0,1fr);align-items:center;gap:8px;width:100%; }
    #penDetailsModal .rpt-response-decision-actions>.sd-btn,#penDetailsModal .rpt-response-more-wrap>.sd-btn{ display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:0;height:36px;padding:0 8px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:13px;font-weight:500;line-height:1;white-space:nowrap; }
    #penDetailsModal .rpt-response-more-wrap>.sd-btn{ width:36px;padding:0;font-size:18px;font-weight:400; }
    #penDetailsModal .rpt-response-primary{ border:1px solid #18181b;background:#18181b;color:#fff; }
    #penDetailsModal .rpt-response-locked{ border:1px solid #e4e4e7;background:#f4f4f5;color:#71717a;cursor:not-allowed; }
    #penDetailsModal .rpt-response-more-wrap{ position:relative;min-width:0; }
    #penDetailsModal .rpt-response-more-menu{ position:absolute;z-index:4;bottom:calc(100% + 7px);left:0;min-width:165px;padding:5px;border:1px solid #d4d4d8;border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(0,0,0,.16); }
    #penDetailsModal .rpt-response-more-menu[hidden]{ display:none; }
    #penDetailsModal .rpt-response-more-menu button{ display:block;width:100%;padding:8px 9px;border:0;border-radius:5px;background:none;text-align:left;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px;font-weight:500;line-height:1.4;color:#18181b;cursor:pointer; }
    #penDetailsModal .rpt-response-more-menu button.is-danger{ color:#b91c1c; }
    #responseWorkflowModal{ position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.55); }
    #responseWorkflowModal.active{ display:flex; }
    #responseWorkflowModal .response-workflow-dialog{ width:min(440px,calc(100vw - 32px));max-width:min(440px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;padding:22px;border:1px solid #e4e4e7;border-radius:12px;background:#fff;box-shadow:0 18px 48px rgba(0,0,0,.24); }
    #responseWorkflowModal .response-workflow-field{ display:block;margin:0 0 12px;font-size:13px;font-weight:600;color:#27272a; }
    #responseWorkflowModal .response-workflow-field textarea,#responseWorkflowModal .response-workflow-field input,#responseWorkflowModal .response-workflow-field select{ display:block;width:100%;min-height:38px;margin-top:5px;padding:8px;border:1px solid #d4d4d8;border-radius:7px;background:#fff;color:#18181b;font:inherit; }
    #responseWorkflowModal .response-workflow-field textarea{ min-height:82px;resize:vertical; }
    #responseWorkflowModal .handover-pickup-location[hidden]{ display:none; }
    #responseWorkflowModal .handover-method-options{ display:grid;gap:8px;margin:0 0 14px;padding:0;border:0; }
    #responseWorkflowModal .handover-method-options legend{ margin-bottom:6px;font-size:13px;font-weight:600;color:#27272a; }
    #responseWorkflowModal .handover-method-options label{ display:flex;align-items:flex-start;gap:9px;padding:10px;border:1px solid #d4d4d8;border-radius:8px;cursor:pointer; }
    #responseWorkflowModal .handover-method-options label:has(input:checked){ border-color:#16a34a;background:#f0fdf4;box-shadow:inset 0 0 0 1px #16a34a; }
    #responseWorkflowModal .handover-method-options input{ margin:3px 0 0;accent-color:#16a34a; }
    #responseWorkflowModal .handover-method-options span{ display:grid;gap:2px; }
    #responseWorkflowModal .handover-method-options strong{ font-size:13px;font-weight:600; }
    #responseWorkflowModal .handover-method-options small{ font-size:12px;font-weight:400;color:#71717a; }
    #responseWorkflowModal .handover-plan-preview{ display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 12px;margin:0 0 12px;padding:10px 12px;border-radius:9px;background:#f4f4f5; }
    #responseWorkflowModal .handover-plan-preview div{ display:contents; }
    #responseWorkflowModal .handover-plan-preview span{ font-size:12px;color:#71717a; }
    #responseWorkflowModal .handover-plan-preview strong{ min-width:0;font-size:12px;color:#27272a;overflow-wrap:anywhere; }
    #responseWorkflowModal .handover-date-time,#responseWorkflowModal .handover-contact-fields{ display:grid;grid-template-columns:1fr 1fr;gap:10px; }
    #responseWorkflowModal .handover-date-time .response-workflow-field,#responseWorkflowModal .handover-contact-fields .response-workflow-field{ min-width:0; }
    @media(max-width:420px){ #responseWorkflowModal .handover-date-time,#responseWorkflowModal .handover-contact-fields{ grid-template-columns:1fr;gap:0; } }
    #responseWorkflowModal .response-workflow-help{ margin:0;padding:10px 12px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:12px;line-height:1.5; }
    #responseWorkflowModal .response-workflow-dialog .sd-dialog-footer{ display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #e4e4e7; }
    .rpt-response-preview-panel{ position:fixed;z-index:1100;top:16px;left:16px;width:220px;max-height:calc(100vh - 32px);overflow:auto;padding:12px;border:1px solid #27272a;border-radius:12px;background:#18181b;color:#fff;box-shadow:0 8px 28px rgba(0,0,0,.25);font:12px/1.45 'Inter','Segoe UI',sans-serif; }
    .rpt-response-preview-panel>strong{ display:block;margin-bottom:8px;font-size:12px; }
    .rpt-response-preview-options{ display:grid;gap:5px; }
    .rpt-response-preview-options button{ padding:7px 8px;border:1px solid #3f3f46;border-radius:7px;background:transparent;color:#fff;text-align:left;font:inherit;cursor:pointer; }
    .rpt-response-preview-options button[aria-pressed="true"]{ border-color:#fff; }
    .rpt-response-preview-panel>p{ margin:7px 0;color:#a1a1aa;font-size:10px; }
    .rpt-response-preview-proposal{ margin:7px 0;padding:8px;border:1px solid #1d4ed8;border-radius:7px;background:#172554;color:#bfdbfe;font-size:10px; }
    .rpt-response-preview-panel .rpt-response-risk{ margin:0;padding:9px;border:1px solid #3f3f46;border-radius:8px;background:#18181b;color:#fff;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:13px;font-weight:400;line-height:1.45; }
    .rpt-response-preview-panel .rpt-response-risk.is-high-risk{ border-color:#b91c1c;background:#2a1114; }
    .rpt-response-preview-panel .rpt-response-risk-title{ margin-bottom:5px;font-size:14px;font-weight:600;line-height:1.4; }
    .rpt-response-preview-panel .rpt-response-risk-row{ display:flex;justify-content:space-between;gap:5px; }
    .rpt-response-preview-panel .rpt-response-risk-label{ display:flex;gap:4px; }
    .rpt-response-preview-panel .rpt-response-risk-row.is-risk{ color:#f87171; }
    .rpt-response-preview-panel .rpt-response-risk-row.is-safe{ color:#4ade80; }
    .rpt-response-preview-panel .rpt-response-risk-row.is-suggestion,.rpt-response-preview-panel .rpt-response-risk-note{ color:#a1a1aa; }
    .rpt-response-preview-panel .rpt-response-risk-explanation{ margin:6px 0 3px;font-size:13px;font-weight:500;line-height:1.45; }
    .rpt-response-preview-panel .rpt-response-risk-note{ margin:4px 0 0;font-size:11px;font-weight:400;line-height:1.4; }
    .rpt-response-preview-panel .rpt-response-preview-override{ display:block;margin-top:3px;color:#fca5a5;text-decoration:underline; }
    .pen-evidence-viewer{ position:fixed;inset:0;z-index:1300;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.5); }
    .pen-evidence-viewer.open{ display:flex; }
    .pen-evidence-viewer .pen-evidence-dialog{ width:min(640px,calc(100vw - 32px));max-width:min(640px,calc(100vw - 32px));max-height:calc(100vh - 32px);box-sizing:border-box;font-family:'Inter','Segoe UI',sans-serif; }
    .pen-evidence-stage{ display:flex;align-items:center;justify-content:center;width:100%;height:min(56vh,446px);overflow:hidden;border:1px solid #e4e4e7;border-radius:6px;background:#f4f4f5; }
    .pen-evidence-stage img,.pen-evidence-stage video{ display:block;width:100%;height:100%;object-fit:contain; }
    .pen-evidence-controls{ display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;margin-top:10px; }
    .pen-evidence-counter{ color:#71717a;text-align:center;font-size:13px;font-weight:400; }
    .pen-evidence-button{ min-width:64px; }
    .pen-evidence-button:disabled{ opacity:.45;cursor:not-allowed; }
    .pen-evidence-close-row{ display:flex;justify-content:flex-end;margin-top:10px; }
    @media(max-width:600px){
      .pen-evidence-viewer .pen-evidence-dialog{ padding:18px; }
      .pen-evidence-stage{ height:min(52vh,400px); }
      .pen-evidence-controls{ gap:8px; }
      .pen-evidence-button{ min-width:56px; }
      .pen-evidence-counter{ font-size:12px; }
    }
    #penDetailsModal .pen-info-grid{ display:grid;grid-template-columns:1fr 1fr;gap:16px 18px;align-items:start; }
    #penDetailsModal .pen-description{ margin:18px 0 0; }
    @media(max-width:480px){
      #penDetailsModal .pen-validation-heading{ flex-wrap:wrap; }
      #penDetailsModal .pen-validation-status{ margin-left:28px; }
      #penDetailsModal .pen-info-grid{ grid-template-columns:1fr;gap:12px; }
    }
    .cpd .rpt-detail-ref{ font-size:18px; font-weight:600; letter-spacing:-0.025em; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; word-break:break-all; }
    .cpd .rpt-detail-type{ font-size:14px; color:var(--ink-500); margin-top:4px; }
    .cpd .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; text-transform:none; letter-spacing:0; color:#18181b; margin:18px 0 10px; }
    .cpd .rpt-desc-box{ background:#f8f9fa; border:1px solid #e4e4e7; border-radius:8px; padding:12px 14px; font-size:14px; font-weight:500; color:#18181b; line-height:1.5; }
    .cpd .rpt-notes-box{ background:none; border:none; border-radius:0; padding:0; font-size:14px; font-weight:500; color:#18181b; line-height:1.5; }
    .cpd .rpt-photo-box{ display:flex; flex-direction:column; gap:10px; }
    .cpd .rpt-photo-thumb{ width:100%; height:auto; padding:0; border-radius:10px; border:1px solid var(--border); overflow:hidden; cursor:pointer; background:var(--ink-100); display:block; text-align:left; }
    .cpd .rpt-photo-thumb img{ width:100%; height:auto; object-fit:cover; display:block; }
    .cpd .rpt-photo-thumb.rpt-video-thumb{ background:#000; cursor:default; }
    .cpd .rpt-photo-thumb video{ width:100%; display:block; background:#000; }
    .cpd .rpt-photo-none{ font-size:12.5px; color:var(--ink-400); }
    .staff-detail-drawer .rpt-history{ display:flex; flex-direction:column; margin:0; padding:0; }
    .staff-detail-drawer .rpt-history .rpt-h-item{ display:flex; gap:10px; align-items:flex-start; margin-bottom:14px; }
    .staff-detail-drawer .rpt-history .rpt-h-item:last-child{ margin-bottom:0; }
    .staff-detail-drawer .rpt-history .rpt-h-dot{ width:8px; height:8px; border-radius:50%; background:#0E8131; margin-top:6px; flex-shrink:0; }
    .staff-detail-drawer .rpt-history .rpt-h-content{ flex:1; min-width:0; }
    .staff-detail-drawer .rpt-history .rpt-h-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .staff-detail-drawer .rpt-history .rpt-h-meta{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; margin-top:2px; }
    .cd-drawer .rpt-updates-timeline{ position:relative; margin:0 0 14px 3px; padding-left:14px; border-left:2px solid #e4e4e7; color:#09090b; font-family:'Inter','Segoe UI',sans-serif; }
    .cd-drawer .rpt-updates-entry{ position:relative; padding:0 0 17px; }
    .cd-drawer .rpt-updates-entry:last-child{ padding-bottom:0; }
    .cd-drawer .rpt-updates-entry:before{ position:absolute; top:3px; left:-20px; width:10px; height:10px; border-radius:50%; background:#22c55e; content:""; box-shadow:0 0 0 2px #fff; }
    .cd-drawer .rpt-updates-entry-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:3px; }
    .cd-drawer .rpt-updates-entry-title{ min-width:0; color:#09090b; font-size:14px; font-weight:600; line-height:1.35; }
    .cd-drawer .rpt-updates-entry-date{ flex:0 0 auto; color:#71717a; font-size:12px; line-height:1.4; text-align:right; }
    .cd-drawer .rpt-updates-entry-actor{ display:inline-block; margin:0 0 6px; padding:1px 7px; border:1px solid #e4e4e7; border-radius:4px; background:#fafafa; color:#71717a; font-size:12px; line-height:1.35; }
    .cd-drawer .rpt-updates-entry-copy{ margin:0 0 8px; color:#27272a; font-size:14px; font-weight:400; line-height:1.45; white-space:pre-wrap; overflow-wrap:anywhere; }
    .cd-drawer .rpt-updates-photo-grid{ display:flex; flex-wrap:wrap; gap:8px; }
    .cd-drawer .rpt-updates-photo{ width:74px; height:74px; padding:0; overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#f4f4f5; cursor:pointer; }
    .cd-drawer .rpt-updates-photo img,.cd-drawer .rpt-updates-photo video{ display:block; width:100%; height:100%; object-fit:cover; }
    .cd-drawer .rpt-updates-caption{ margin:6px 0 0; color:#18181b; font-size:12px; font-weight:500; }
    .cd-drawer .rpt-updates-empty{ margin:0 0 14px; color:#71717a; font-size:14px; }
    .cd-drawer .rpt-perspective-attachment-section{ margin:0 0 18px; font-family:'Inter','Segoe UI',sans-serif; }
    .cd-drawer .rpt-perspective-attachment-section h3{ display:flex; align-items:center; gap:7px; margin:0 0 10px; color:#18181b; font-size:13px; font-weight:600; }
    .cd-drawer .rpt-perspective-attachment-section h3 span{ color:#71717a; font-size:12px; font-weight:400; }
    .cd-drawer .rpt-perspective-attachment-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(96px,112px)); gap:8px; }
    .cd-drawer .rpt-perspective-attachment{ min-width:0; margin:0; }
    .cd-drawer .rpt-perspective-attachment-thumb{ display:block; width:100%; aspect-ratio:1; padding:0; overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#f4f4f5; cursor:pointer; }
    .cd-drawer .rpt-perspective-attachment-thumb img,.cd-drawer .rpt-perspective-attachment-thumb video{ display:block; width:100%; height:100%; object-fit:cover; }
    .cd-drawer .rpt-perspective-attachment figcaption{ margin-top:4px; color:#52525b; font-size:11px; line-height:1.35; overflow-wrap:anywhere; }
    .cd-drawer .rpt-perspective-attachment-empty{ margin:0; color:#71717a; font-size:12px; line-height:1.5; }
    .cpd .rpt-cards .card-img{ width:100%; height:150px; object-fit:cover; border-radius:var(--radius-sm) var(--radius-sm) 0 0; display:block; background:var(--ink-100); }
    .cpd .rpt-cards .card-img-placeholder{ width:100%; height:150px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-300); }
    .cpd .date-input{ padding:10px 12px; border-radius:9px; border:1px solid var(--border); font-family:inherit; font-size:12.5px; color:var(--ink-700); background:var(--white); width:100%; box-sizing:border-box; }

    /* Activity */
    .cpd .activity-item{ display:flex; align-items:flex-start; gap:11px; padding:10px 0; border-bottom:1px solid var(--ink-100); }
    .cpd .activity-item:last-child{ border-bottom:none; }
    .cpd .activity-icon{ width:32px;height:32px; border-radius:8px; display:flex;align-items:center;justify-content:center; flex-shrink:0; font-size:13px; }
    .cpd .activity-icon.appt{ background:var(--green-50); color:var(--green-700); }
    .cpd .activity-icon.report{ background:var(--amber-50); color:var(--amber-600); }
    .cpd .activity-icon.log{ background:var(--ink-100); color:var(--ink-500); }
    .cpd .activity-title{ font-size:13px; font-weight:600; color:var(--ink-900); }
    .cpd .activity-details{ font-size:12px; color:var(--ink-500); margin-top:2px; }
    .cpd .activity-date{ font-size:11px; color:var(--ink-400); margin-top:2px; }

    /* Form grid for modals */
    .cpd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .cpd-form-grid .form-group-full{ grid-column:1 / -1; }
    .cpd .form-label{ display:block; font-size:12.5px; font-weight:600; color:var(--ink-700); margin-bottom:6px; }

    .cpd-toast{ position:fixed; bottom:24px; right:24px; z-index:10000; padding:13px 18px; border-radius:12px; color:#fff; font-size:13.5px; font-weight:600; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; transform:translateY(20px); opacity:0; pointer-events:none; transition:all .25s ease; }
    .cpd-toast.show{ transform:translateY(0); opacity:1; }
    .cpd-toast.success{ background:var(--green-700); }
    .cpd-toast.error{ background:#dc2626; }

    .cpd .no-results{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .no-results svg{ margin-bottom:10px; color:var(--ink-300); }
    .cpd .no-results p{ margin:0; font-size:13px; }

    @media (max-width:1280px){
      .cpd .layout{ grid-template-columns:1fr; }
      .cpd .db-panel{ border-right:none; }
      .cpd .profile-panel{ grid-column:1 / -1; position:static; border-left:none; border-top:1px solid var(--border); }
    }
    @media (max-width:1180px){
      .cpd .kpis{ grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:820px){
      .cpd .layout{ grid-template-columns:1fr; }
      .cpd .filters{ border-right:none; border-bottom:1px solid var(--border); }
      .cpd .db-panel{ border-right:none; border-bottom:1px solid var(--border); border-radius:0; }
      .cpd .profile-panel{ border-left:none; border-top:1px solid var(--border); }
      .cpd-form-grid{ grid-template-columns:1fr; }
      .cpd .kpis{ grid-template-columns:1fr 1fr; }
      .cpd .rpt-detail-grid{ grid-template-columns:1fr; }
      .cpd .rpt-detail-grid .info-rows.info-rows-2col{ grid-template-columns:1fr; }
    }
  </style>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    // �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ 2. REGISTERED USERS: multi-field search + pagination �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
    (function() {
        var PER_PAGE_USER = 10;
        var tbody = document.querySelector('#usersTable tbody');
        var pag = document.getElementById('userPagination');
        if (!tbody || !pag) return;
        var allRows = Array.from(tbody.querySelectorAll('tr:not(#userNoResults)'));
        var currentPage = 1;
        var filterSearch = '';

        function getFiltered() {
            return allRows.filter(function(r) {
                return !filterSearch || r.textContent.toLowerCase().includes(filterSearch);
            });
        }

        function renderTable() {
            var filtered = getFiltered();
            allRows.forEach(function(r) { r.style.display = 'none'; });
            var start = (currentPage - 1) * PER_PAGE_USER;
            var end = start + PER_PAGE_USER;
            filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
            var noRes = document.getElementById('userNoResults');
            if (noRes) noRes.style.display = filtered.length === 0 ? '' : 'none';
            renderPagination(filtered.length);
        }

        function renderPagination(total) {
            var tp = Math.ceil(total / PER_PAGE_USER);
            if (tp <= 1) { pag.innerHTML = ''; return; }
            var h = '';
            h += '<span class="pag-info">Showing ' + ((currentPage-1)*PER_PAGE_USER+1) + '\u2013' + Math.min(currentPage*PER_PAGE_USER, total) + ' of ' + total + ' residents</span>';
            h += '<div class="pag-btns">';
            h += '<a href="#" onclick="window.userPageGo(' + (currentPage - 1) + ');return false;" class="pag-btn"' + (currentPage <= 1 ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-left"></i></a>';
            h += '<a href="#" onclick="window.userPageGo(' + (currentPage + 1) + ');return false;" class="pag-btn"' + (currentPage >= tp ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-right"></i></a>';
            h += '</div>';
            pag.innerHTML = h;
        }

        window.userPageGo = function(page) {
            var total = Math.ceil(getFiltered().length / PER_PAGE_USER);
            if (page < 1 || page > total) return;
            currentPage = page;
            renderTable();
        };

        var userSearch = document.getElementById('userSearch');
        if (userSearch) {
            userSearch.addEventListener('keyup', function() {
                filterSearch = this.value.toLowerCase().trim();
                currentPage = 1;
                renderTable();
            });
        }

        renderTable();
    })();

    // �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ 3. CLIENT & PET DB: multi-field search �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
    const petSearch = document.getElementById('petSearch');
    if (petSearch) {
        petSearch.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#petsTable tbody tr:not(#petNoResults)');
            let visible = 0;
            rows.forEach(row => {
                const show = !filter || row.textContent.toLowerCase().includes(filter);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const noRes = document.getElementById('petNoResults');
            if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
        });
    }
  });

  // �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ 4. PROFESSIONAL PDF EXPORT �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
  window.exportDashboardPDF = function() {
      window.open('export-report.php', '_blank');
  };
  </script>
</head>
<body class="staff-theme" data-realtime-page="staff" data-app-base-url="<?php echo htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">

<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>

<!-- MAIN CONTENT -->
<main class="main-content" id="mainContent">

  <?php if (isset($_SESSION['login_notice'])): ?>
    <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;color:#1e40af;font-size:0.9rem;">
      <i class="fas fa-info-circle"></i>
      <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
      <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:#1e40af;cursor:pointer;font-size:1.1rem;">&times;</button>
    </div>
  <?php endif; ?>

  <!-- �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ OVERVIEW / ANALYTICS DASHBOARD �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ -->
<section id="analytics" class="content-section active aether-analytics">
<?php include __DIR__ . '/../../includes/aether-dashboard.php'; ?>
</section>

  <!-- �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ USERS TAB �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ -->
<section id="users" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">Registered Users</h1>
        <p class="page-subtitle">Manage resident accounts and information</p>
      </div>
    </div>
    <div class="table-card">
        <div class="table-header-actions" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-bottom:14px; border-bottom:1px solid #f1f5f9; margin-bottom:14px;">
            <span style="color:#64748b; font-size:0.9rem;">Total: <strong><?php echo count($users); ?></strong> residents</span>
            <!-- Modern Search Bar -->
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                <input type="text" id="userSearch" placeholder="Search by ID, Name, Email, or Phone..." style="padding:9px 14px 9px 38px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:0.88rem; outline:none; width:320px; background:#f8fafc; color:#334155; transition:all 0.2s;" onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16,185,129,0.1)'" onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
            </div>
        </div>
        <table class="data-table" id="usersTable">
            <thead>
                <tr>
                    <th style="width:50px;">No.</th>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Registered Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($users)): ?>
                <tr><td colspan="7" style="text-align:center;">No users found.</td></tr>
                <?php else: ?>
                    <?php $uNum = 1; foreach($users as $u): ?>
                    <tr>
                        <td style="color:#94a3b8; font-size:0.85rem; font-weight:600;"><?php echo $uNum++; ?></td>
                        <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($u['reference_id'] ?? '#RES-'.fallbackRefId('#RES-', $u['user_id'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($u['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><?php echo htmlspecialchars($u['phone'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($u['address'] ?? '-'); ?></td>
                        <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="userNoResults" style="display:none;"><td colspan="7" style="text-align:center; color:#94a3b8;">No matching users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="userPagination" class="pagination-bar"></div>
    </div>
  </section>

  <!-- �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ APPOINTMENTS TAB �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ -->
  <section id="appointments" class="content-section">
    <!-- Overview KPIs -->
    <div class="kpis">
      <div class="kpi-card">
        <p class="kpi-label">Total Appointments</p>
        <div class="kpi-value"><?php echo $apptCounts['all']; ?></div>
        <p class="kpi-sub">All appointment requests</p>
      </div>
      <div class="kpi-card">
        <p class="kpi-label">Pending</p>
        <div class="kpi-value"><?php echo $apptCounts['pending']; ?></div>
        <p class="kpi-sub">Awaiting decision</p>
      </div>
      <div class="kpi-card">
        <p class="kpi-label">Scheduled</p>
        <div class="kpi-value"><?php echo $apptCounts['scheduled']; ?></div>
        <p class="kpi-sub">Confirmed bookings</p>
      </div>
      <div class="kpi-card">
        <p class="kpi-label">Completed</p>
        <div class="kpi-value"><?php echo $apptCounts['completed']; ?></div>
        <p class="kpi-sub">Finished appointments</p>
      </div>
    </div>

    <!-- Appointments Table with Search + Status Filter -->
        <?php if (isset($_SESSION['appointment_error'])): ?>
        <div class="toast toast-error" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:6px;margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['appointment_error']); unset($_SESSION['appointment_error']); ?></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['appointment_success'])): ?>
        <div class="toast toast-success" style="background:#eef6f0;border:1px solid #b8d1c0;color:#045e1f;padding:10px 14px;border-radius:6px;margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['appointment_success']); unset($_SESSION['appointment_success']); ?></div>
        <?php endif; ?>

        <div class="appt-card-header staff-mobile-toolbar">
            <div class="appt-header-right">
              <div class="staff-toolbar-search-row">
              <div class="appt-search-wrap staff-toolbar-search">
                <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="apptSearchInput" class="appt-search-input" placeholder="Search appointments...">
              </div>
              <div class="staff-date-filter staff-toolbar-filter" data-date-filter="appt">
                <button class="staff-date-filter-button" type="button" aria-label="Filter appointment requests by date" title="Filter by date" aria-expanded="false">
                  <i class="fas fa-filter" aria-hidden="true"></i>
                </button>
                <input type="hidden" id="apptDateFrom" value="">
                <input type="hidden" id="apptDateTo" value="">
              </div>
              </div>
              <div class="staff-toolbar-filters">
              <button type="button" class="sd-btn sd-btn-outline" id="rescheduleRequestFilter" aria-pressed="false">
                Reschedule requests (<?php echo $rescheduleRequestCount; ?>)
              </button>
              <select id="apptStatusSelect" class="appt-status-select staff-toolbar-select staff-toolbar-last-filter">
                <option value="all">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="scheduled">Scheduled</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="rescheduled">Rescheduled</option>
                <option value="no_show">No Show</option>
              </select>
              </div>
            </div>
            <div class="appt-primary-actions staff-toolbar-actions" style="display:flex; gap:10px; flex-wrap:wrap; margin-left:auto;">
              <a href="availability.php" class="sd-btn sd-btn-outline staff-toolbar-secondary"><i class="fas fa-calendar-alt"></i> Manage Schedule</a>
              <button type="button" class="sd-btn sd-btn-outline staff-toolbar-secondary" onclick="exportAppointmentsCSV()" title="Export appointment requests as CSV">
                Export CSV
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              </button>
              <button type="button" class="sd-btn sd-btn-default staff-toolbar-primary" onclick="openAddAppointmentModal()"><i class="fas fa-clipboard-list"></i> Review Requests</button>
            </div>
          </div>

          <div id="apptBulkActions" class="bulk-bar" style="display:none;">
            <div class="bulk-actions">
              <span class="bulk-count"><span id="apptSelectedCount">0</span> selected</span>
              <button type="button" class="bulk-btn" onclick="APP.bulkReview()" title="Review selected appointment requests">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                Review
              </button>
              <button type="button" class="bulk-btn" onclick="APP.bulkReschedule()" title="Reschedule selected requests">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Reschedule
              </button>
              <button type="button" class="bulk-btn" id="apptBulkCompleteBtn" style="display:none;" onclick="APP.bulkComplete()" title="Mark selected requests as completed">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Mark Completed
              </button>
              <button type="button" class="bulk-btn" id="apptBulkNoShowBtn" style="display:none;" onclick="APP.bulkNoShow()" title="Mark selected requests as no-show">
                Mark No-show
              </button>
              <button type="button" class="bulk-btn danger" id="apptBulkArchiveBtn" style="display:none;" onclick="APP.bulkArchive()" title="Archive selected requests">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                Archive
              </button>
            </div>
          </div>



          <div class="appt-table-wrap" id="appointmentsTable">
              <div class="appt-no-results" id="apptNoResults"><?php echo empty($pendingAppts) ? 'No appointments found.' : 'No appointments match this filter.'; ?></div>
              <div class="appt-db-table-area dt-container">
                <table class="appt-table">
                  <thead>
                    <tr class="sd-tr">
                      <th class="sd-th" style="width:40px;"><input type="checkbox" class="sd-table-cb" id="apptSelectAll" /></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="appt" onclick="window.APP.sortBy('appt')">Appointment ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="service" onclick="window.APP.sortBy('service')">Service<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="resident" onclick="window.APP.sortBy('resident')">Resident<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th">Contact</th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="pet" onclick="window.APP.sortBy('pet')">Pet<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="schedule" onclick="window.APP.sortBy('schedule')">Preferred Schedule<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="submitted" onclick="window.APP.sortBy('submitted')">Time Submitted<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="window.APP.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th sd-th-action"></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  $aNum = 1;
                  foreach($pendingAppts as $req):
                    $aptRef = $req['reference_id'] ?? fallbackRefId('#APT-', $req['appointment_id']);
                    $sc = $statusColors[$req['status']] ?? ['bg'=>'#f1f5f9','color'=>'#64748b'];
                    $statusKey = $req['status'];
                    $hasPendingReschedule = ($req['reschedule_request_status'] ?? '') === 'pending';
                    $statusLabel = $req['status'] === 'cancelled' && $req['cancelled_by'] === 'resident'
                      ? 'Cancelled by Resident'
                      : ($req['status'] === 'cancelled' && $req['cancelled_by'] === 'staff'
                        ? 'Cancelled by Staff' : ucfirst(str_replace('_',' ', $req['status'])));
                    if ($req['status'] === 'no_show') $statusLabel = 'No Show';
                    if ($hasPendingReschedule && $req['status'] === 'scheduled') {
                      $statusLabel = 'Rescheduled';
                      $sc = $statusColors['rescheduled'];
                    }
                    $dt = date('F j, Y', strtotime($req['scheduled_date']));
                    $tm = date('g:i A', strtotime($req['scheduled_time']));
                    $sub = date('M d, Y', strtotime($req['created_at']));
                    $typeLabel = ucfirst($req['appointment_type']);
                    $petName = htmlspecialchars($req['pet_name'] ?? '-');
                    $residentName = htmlspecialchars($req['full_name']);
                    $initials = '';
                    $nameParts = preg_split('/\s+/', trim($residentName));
                    foreach ($nameParts as $np) { if ($np !== '') $initials .= strtoupper(mb_substr($np, 0, 1)); }
                    if ($initials === '') $initials = '?';
                  ?>
                    <?php $canMarkOutcome = in_array($statusKey, ['scheduled', 'rescheduled', 'in_progress'], true) && strtotime($req['scheduled_date'] . ' ' . $req['scheduled_time']) <= time(); ?>
                    <tr class="sd-tr" data-id="<?php echo (int)$req['appointment_id']; ?>" data-status="<?php echo htmlspecialchars($statusKey); ?>" data-reschedule-request="<?php echo htmlspecialchars($req['reschedule_request_status'] ?? ''); ?>" data-can-outcome="<?php echo $canMarkOutcome ? '1' : '0'; ?>" data-date="<?php echo (int)strtotime($req['scheduled_date'] . ' ' . $req['scheduled_time']); ?>" data-date-key="<?php echo htmlspecialchars((string)$req['scheduled_date'], ENT_QUOTES, 'UTF-8'); ?>" data-submitted="<?php echo (int)strtotime($req['created_at']); ?>" onclick="APP.view(<?php echo (int)$req['appointment_id']; ?>)">
                      <td class="sd-td">
                        <input type="checkbox" class="sd-table-cb appt-row-check" data-id="<?php echo (int)$req['appointment_id']; ?>" onclick="event.stopPropagation();APP.toggleSelect(<?php echo (int)$req['appointment_id']; ?>)" />
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo htmlspecialchars($aptRef); ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $typeLabel; ?></div>
                        <?php if (($req['urgency_level'] ?? 'routine') === 'urgent'): ?>
                          <span class="appt-status-pill" style="display:inline-block;margin-top:4px;background:#fee2e2;color:#991b1b;border-color:#fecaca;">Urgent</span>
                        <?php endif; ?>
                      </td>
                      <td class="sd-td">
                        <div class="client-cell">
                          <div class="avatar"><?php if (!empty($req['avatar_url'])): ?><img src="<?php echo htmlspecialchars(dcvsStaffReportMediaUrl($req['avatar_url']), ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: echo $initials; endif; ?></div>
                          <div class="contact-main contact-name"><?php echo $residentName; ?></div>
                        </div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal appointment-email"><?php echo htmlspecialchars($req['email'] ?? '-'); ?></div>
                        <div class="contact-sub appointment-phone"><?php echo htmlspecialchars($req['phone'] ?? '-'); ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="client-cell">
                          <div class="avatar"><?php if (!empty($req['photo_url'])): ?><img src="<?php echo htmlspecialchars(dcvsStaffReportMediaUrl($req['photo_url']), ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><i class="fas fa-paw"></i><?php endif; ?></div>
                          <div class="contact-main contact-normal pet-name-value"><?php echo $petName; ?></div>
                        </div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php if (in_array(($req['reschedule_request_status'] ?? ''), ['pending', 'offered'], true)) echo 'Current: '; ?><?php echo $dt; ?> &middot; <?php echo $tm; ?></div>
                        <?php if (($req['reschedule_request_status'] ?? '') === 'pending'): ?>
                          <div class="contact-sub" style="margin-top:4px;color:#52525b;">→ Requested: <?php echo htmlspecialchars(date('M j', strtotime($req['reschedule_requested_date'])) . ' · ' . date('g:i A', strtotime($req['reschedule_requested_time'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php elseif (($req['reschedule_request_status'] ?? '') === 'offered'): ?>
                          <div class="contact-sub" style="margin-top:4px;color:#9a3412;">→ Offered: <?php echo htmlspecialchars(date('M j', strtotime($req['reschedule_offered_date'])) . ' · ' . date('g:i A', strtotime($req['reschedule_offered_time'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $sub; ?></div>
                      </td>
                      <td class="sd-td">
                        <span class="appt-status-pill" style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;border-color:<?php echo $sc['border'] ?? '#e4e4e7'; ?>;"><?php echo $statusLabel; ?></span>
                        <?php if (($req['reschedule_request_status'] ?? '') === 'offered'): ?>
                          <span class="appt-status-pill" style="display:inline-block;margin-top:5px;background:#fff7ed;color:#9a3412;border-color:#fed7aa;font-weight:700;">Resident reply needed</span>
                        <?php endif; ?>
                      </td>
                      <td class="sd-td sd-td-action">
                        <button type="button" class="sd-row-actions" title="More options" data-appt-id="<?php echo (int)$req['appointment_id']; ?>" data-appt-status="<?php echo htmlspecialchars($statusKey); ?>" onclick="event.stopPropagation();openApptRowMenu(event,<?php echo (int)$req['appointment_id']; ?>,'<?php echo htmlspecialchars($statusKey); ?>')">
                          <span class="sr-only">Open menu</span>
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
          </div>
          <div class="cpd row-menu-pop" id="apptRowMenuPop"></div>

          <!-- Pagination Footer -->
          <div id="apptPagination" class="dt-pagination">
            <div class="dt-pagination-right">
              <div class="dt-rows">
                <span>Rows per page</span>
                <select>
                  <option value="10">10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                </select>
              </div>
              <div class="dt-page-label" id="apptPageLabel">Page 1 of 1</div>
              <div class="dt-page-btns">
                <button class="dt-page-btn" id="apptPageFirst" disabled>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                </button>
                <button class="dt-page-btn" id="apptPagePrev" disabled>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <button class="dt-page-btn" id="apptPageNext" disabled>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <button class="dt-page-btn" id="apptPageLast" disabled>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                </button>
              </div>
            </div>
          </div>

</section>

  <!-- CALENDAR SCHEDULE -->
  <section id="calendar-schedule" class="content-section">
    <?php $isStaffDashboardCalendarEmbed = true; ?>
    <?php include __DIR__ . '/../../calendar/events-calendar.php'; ?>
  </section>

  <!-- �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ CLIENT & PET DATABASE TAB �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬ -->
  <section id="client-pets" class="content-section">
    <div class="cpd reports-fullpage">
      <div class="cpd-app">

        <!-- Main layout -->
        <div class="layout">

          <!-- Center: Database -->
          <div class="panel db-panel">
            <!-- KPI cards -->
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Total Clients</p>
                <div class="kpi-value"><?php echo $kpiClients; ?></div>
                <p class="kpi-sub">Registered residents</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Total Pets</p>
                <div class="kpi-value"><?php echo $kpiPets; ?></div>
                <p class="kpi-sub">Across all clients</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Avg. Pets / Client</p>
                <div class="kpi-value"><?php echo $kpiAvg; ?></div>
                <p class="kpi-sub">Pet ownership rate</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">New This Month</p>
                <div class="kpi-value"><?php echo $kpiNew; ?></div>
                <p class="kpi-sub">Clients registered</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar staff-mobile-toolbar">
              <div class="staff-toolbar-search-row">
              <div class="search-grow-wrap staff-toolbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="mainSearch" placeholder="Search clients..." oninput="setSearch(this.value)">
              </div>
              <div class="staff-toolbar-filter">
                <div class="staff-date-filter" data-date-filter="cpd">
                  <button class="staff-date-filter-button" type="button" aria-label="Filter clients and pets by registration date" title="Filter by registration date" aria-expanded="false">
                    <i class="fas fa-filter" aria-hidden="true"></i>
                  </button>
                  <input type="hidden" id="cpdDateFrom" value="">
                  <input type="hidden" id="cpdDateTo" value="">
                </div>
              </div>
              </div>
              <div class="toolbar-filters staff-toolbar-filters">
                  <select class="select-input tf-select staff-toolbar-select" id="typeFilter" onchange="setType(this.value)">
                    <option value="">All Types</option>
                    <option value="dog">Dog</option>
                    <option value="cat">Cat</option>
                  </select>
                  <select class="select-input tf-select staff-toolbar-select" id="quickFilter" onchange="setQuick(this.value)">
                    <option value="">All</option>
                    <option value="recent">Recently Added</option>
                    <option value="active">Active Clients</option>
                    <option value="vax">Vaccinated Pets</option>
                  </select>
                  <select class="select-input tf-select staff-toolbar-select staff-toolbar-last-filter" id="brgyFilter" onchange="setBrgy(this.value)">
                    <option value="">All Barangays</option>
                    <?php foreach ($cpdBarangays as $b): ?>
                      <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="db-toolbar-actions staff-toolbar-actions">
                <button type="button" class="sd-btn sd-btn-outline staff-toolbar-secondary" id="cpdExportBtn" onclick="exportCSV()" title="Export CSV">
                  Export CSV
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </button>
                <button type="button" class="sd-btn sd-btn-default staff-toolbar-primary" id="cpdAddBtn" onclick="openAddClient()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Add New Client
                </button>
              </div>
            </div>

            <!-- Clients Bulk Actions (checkbox quick actions — Barangay Portal pattern) -->
            <div class="bulk-bar" id="cpdBulkBar">
              <div class="bulk-actions">
                <span class="bulk-count"><span id="cpdBulkCount">0</span> clients selected</span>
                <button type="button" class="bulk-btn" onclick="cpdBulkEdit()" title="Edit selected clients one by one">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  Edit
                </button>
                <button type="button" class="bulk-btn danger" onclick="cpdBulkArchive()" title="Archive selected clients one by one">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                  Archive
                </button>
              </div>
            </div>

            <div class="db-table-area">
              <div id="tableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                  <table>
                    <thead id="tableHead"></thead>
                    <tbody id="dataRows"></tbody>
                  </table>
                </div>
              </div>
              <div id="cardsWrap" class="cards-grid" style="display:none;"></div>
              <div class="dt-pagination">
                <div class="dt-pagination-right">
                  <div class="dt-rows">
                    <span>Rows per page</span>
                    <select onchange="setPerPage(this.value)">
                      <option value="10">10</option>
                      <option value="20">20</option>
                      <option value="30">30</option>
                      <option value="40">40</option>
                      <option value="50">50</option>
                    </select>
                  </div>
                  <div class="dt-page-label" id="pageLabel">Page 1 of 1</div>
                  <div class="dt-page-btns">
                    <button class="dt-page-btn" id="pageFirst" title="Go to first page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                    </button>
                    <button class="dt-page-btn" id="pagePrev" title="Go to previous page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button class="dt-page-btn" id="pageNext" title="Go to next page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <button class="dt-page-btn" id="pageLast" title="Go to last page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          </div>
      </div>

      <!-- Row menu popup -->
      <div class="cpd row-menu-pop" id="rowMenuPop"></div>

      <!-- Client Profile Drawer (right drawer, appointments style) -->
      <div id="clientProfileModal" class="modal cpd cd-drawer staff-detail-drawer">
        <div class="sd-dialog">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('clientProfileModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header" style="padding-bottom:0;">
              <h2 class="sd-dialog-title">Client Profile</h2>
              <p class="sd-dialog-description">Review client information and registered pets.</p>
            </div>
            <div id="profileTabs" class="rpt-tabs" style="margin-bottom:4px;"></div>
            <div id="profileHeader"></div>
            <div class="sd-field-group">
              <div id="profileContent"></div>
            </div>
            <div class="sd-dialog-footer" id="clientProfileFooter" style="display:flex;justify-content:center;gap:8px;"></div>
          </div>
        </div>
      </div>
      <?php require __DIR__ . '/../../includes/staff-compose-dialog.php'; ?>

      <!-- Add Client Modal (shadcn Dialog) -->
      <style>
        /* Checkbox bulk selection (same pattern as Barangay Portal) */
        #client-pets .sd-table-cb{ width:16px; height:16px; accent-color:#18181b; cursor:pointer; }
        #client-pets .bulk-bar{ display:none; align-items:center; gap:12px; margin-bottom:4px; padding-top:10px; font-family:'Inter','Segoe UI',sans-serif; }
        #client-pets .bulk-bar.active{ display:flex; }
        #client-pets .bulk-bar .bulk-count{ font-size:14px; color:#71717a; white-space:nowrap; margin-right:4px; }
        #client-pets .bulk-bar .bulk-actions{ display:flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; }
        #client-pets .bulk-bar .bulk-btn{ display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 12px; border-radius:5px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:500; color:#52525b; cursor:pointer; }
        #client-pets .bulk-bar .bulk-btn:hover{ background:#f4f4f5; color:#18181b; }
        #client-pets .bulk-bar .bulk-btn.danger{ color:#dc2626; }
        #client-pets .bulk-bar .bulk-btn.danger:hover{ background:#fef2f2; }
        #client-pets .bulk-bar .bulk-btn svg{ width:14px; height:14px; flex-shrink:0; }
        #addClientModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; }
        #addClientModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
        #addClientModal .sd-dialog-content{
          position:relative; width:100%; max-width:520px; max-height:calc(100vh - 2rem); overflow-y:auto;
          background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;
          padding:24px; display:flex; flex-direction:column; gap:16px;
          box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
          animation:modalIn 0.2s ease;
        }
        #addClientModal .sd-dialog-close{
          position:absolute; top:16px; right:16px; width:24px; height:24px;
          display:inline-flex; align-items:center; justify-content:center;
          border-radius:4px; border:none; background:transparent;
          color:#71717a; cursor:pointer; transition:all 0.15s ease;
        }
        #addClientModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
        #addClientModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
        #addClientModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
        #addClientModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
        #addClientModal .sd-field-group{ display:flex; flex-direction:column; gap:18px; }
        #addClientModal .sd-form-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        #addClientModal .sd-field-full{ grid-column:1 / -1; }
        #addClientModal .client-avatar-upload{ display:flex; align-items:center; gap:14px; padding:12px; border:1px solid #e4e4e7; border-radius:8px; background:#fafafa; }
        #addClientModal .client-avatar-preview{ width:64px; height:64px; border-radius:50%; overflow:hidden; background:#f4f4f5; display:flex; align-items:center; justify-content:center; color:#a1a1aa; flex:0 0 auto; }
        #addClientModal .client-avatar-preview img{ width:100%; height:100%; object-fit:cover; display:block; }
        #addClientModal .client-avatar-actions{ display:flex; flex-direction:column; gap:6px; }
        #addClientModal .client-avatar-help{ margin:0; color:#71717a; font-size:12px; }
        #addClientModal .client-avatar-remove{ display:none; width:max-content; padding:0; border:0; background:none; color:#71717a; font:500 12px/1.4 Inter,'Segoe UI',sans-serif; cursor:pointer; }
        #addClientModal .client-avatar-remove:hover{ color:#18181b; }
        #addClientModal .sd-field{ display:flex; flex-direction:column; gap:6px; }
        #addClientModal .sd-label{ font-size:14px; font-weight:500; line-height:1; color:#18181b; }
        #addClientModal .sd-input{
          width:100%; height:36px; padding:0 12px;
          font-family:inherit; font-size:14px; color:#18181b;
          background:transparent; border:1px solid #e4e4e7; border-radius:6px;
          box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
          outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease;
        }
        #addClientModal .sd-input::placeholder{ color:#a1a1aa; }
        #addClientModal .sd-input:focus-visible{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
        #addClientModal select.sd-input{
          appearance:none; -webkit-appearance:none;
          background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
          background-repeat:no-repeat; background-position:right 12px center;
          padding-right:36px;
        }
        #addClientModal textarea.sd-input{ height:auto; min-height:70px; padding:8px 12px; resize:vertical; }
        #addClientModal .sd-dialog-footer{ display:flex; flex-direction:column-reverse; gap:8px; }
        @media (min-width:640px){ #addClientModal .sd-dialog-footer{ flex-direction:row; justify-content:flex-end; } }
        @media (max-width:560px){ #addClientModal .sd-form-grid{ grid-template-columns:1fr; } #addClientModal .sd-field-full{ grid-column:auto; } }
        @media (max-width:640px){
          #addClientModal.modal{ position:fixed; inset:0; align-items:stretch; justify-content:stretch; padding:0; }
          #addClientModal .sd-dialog{ width:100%; height:100vh; height:100dvh; min-height:0; }
          #addClientModal .sd-dialog-content{
            box-sizing:border-box; width:100%; max-width:none; height:100vh; height:100dvh;
            max-height:none; border:0; border-radius:0; padding:20px;
            padding-bottom:calc(20px + env(safe-area-inset-bottom)); overflow-y:auto;
          }
        }
      </style>
      <div id="addClientModal" class="modal">
        <form id="addClientForm" class="sd-dialog" enctype="multipart/form-data">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('addClientModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header">
              <h2 class="sd-dialog-title">Add new client</h2>
              <p class="sd-dialog-description">Register a new client record. Click add client when you're done.</p>
            </div>
            <div class="sd-field">
              <label class="sd-label" for="addAvatar">Client display picture</label>
              <div class="client-avatar-upload">
                <div class="client-avatar-preview" id="addAvatarPreview"><i class="fas fa-user"></i></div>
                <div class="client-avatar-actions">
                  <label class="sd-btn sd-btn-outline" for="addAvatar" style="width:max-content;cursor:pointer;">Choose photo</label>
                  <input id="addAvatar" name="avatar" type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                  <p class="client-avatar-help">JPG, PNG, GIF, or WebP up to 2MB.</p>
                  <button type="button" class="client-avatar-remove" id="removeAddAvatar">Remove photo</button>
                </div>
              </div>
            </div>
            <div class="sd-field-group">
              <div class="sd-form-grid">
              <div class="sd-field">
                <label class="sd-label" for="addFullName">Full name</label>
                <input id="addFullName" name="full_name" type="text" class="sd-input" required placeholder="Client full name">
              </div>
              <div class="sd-field">
                <label class="sd-label" for="addEmail">Email</label>
                <input id="addEmail" name="email" type="email" class="sd-input" required placeholder="client@email.com">
              </div>
              <div class="sd-field">
                <label class="sd-label" for="addPhone">Mobile number</label>
                <input id="addPhone" name="phone" type="text" class="sd-input" required placeholder="09XXXXXXXXX">
              </div>
              <div class="sd-field">
                <label class="sd-label" for="addBarangay">Barangay</label>
                <select id="addBarangay" name="barangay_id" class="sd-input" required>
                  <option value="">Select a barangay</option>
                  <?php foreach ($cpdBarangays as $b): ?>
                    <option value="<?php echo (int)$b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="addGender">Gender</label>
                <select id="addGender" name="gender" class="sd-input">
                  <option value="">-</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="prefer_not">Prefer not to say</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="addBirthdate">Birthdate</label>
                <input id="addBirthdate" name="birthdate" type="date" class="sd-input">
              </div>
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="addAddress">Address</label>
                <textarea id="addAddress" name="address" class="sd-input" rows="2" placeholder="Complete address"></textarea>
              </div>
              </div>
            </div>
            <div class="sd-dialog-footer">
              <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('addClientModal')">Cancel</button>
              <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-plus"></i> Add Client</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Edit Client Modal (shadcn Dialog) -->
      <style>
        #editClientModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; }
        .sd-dialog{ width:100%; display:flex; justify-content:center; }
        .sd-dialog-content{
          position:relative; width:100%; max-width:440px; max-height:calc(100vh - 2rem); overflow-y:auto;
          background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;
          padding:24px; display:flex; flex-direction:column; gap:16px;
          box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
          animation:modalIn 0.2s ease;
        }
        .sd-dialog-close{
          position:absolute; top:16px; right:16px; width:24px; height:24px;
          display:inline-flex; align-items:center; justify-content:center;
          border-radius:4px; border:none; background:transparent;
          color:#71717a; cursor:pointer; transition:all 0.15s ease;
        }
        .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
        .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
        .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
        .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
        .sd-field-group{ display:flex; flex-direction:column; gap:24px; }
        .sd-field{ display:flex; flex-direction:column; gap:6px; }
        .sd-label{ font-size:14px; font-weight:500; line-height:1; color:#18181b; }
        .sd-input{
          width:100%; height:36px; padding:0 12px;
          font-family:inherit; font-size:14px; color:#18181b;
          background:transparent; border:1px solid #e4e4e7; border-radius:6px;
          box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
          outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .sd-input::placeholder{ color:#a1a1aa; }
        .sd-input:focus-visible{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
        select.sd-input{
          appearance:none; -webkit-appearance:none;
          background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
          background-repeat:no-repeat; background-position:right 12px center;
          padding-right:36px;
        }
        textarea.sd-input{ height:auto; min-height:70px; padding:8px 12px; resize:vertical; }
        .sd-dialog-footer{ display:flex; flex-direction:column-reverse; gap:8px; }
        @media (min-width:640px){ .sd-dialog-footer{ flex-direction:row; justify-content:flex-end; } }
        .sd-btn{
          display:inline-flex; align-items:center; justify-content:center; gap:8px;
          height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
          font-family:inherit; font-size:14px; font-weight:500; line-height:1;
          white-space:nowrap; cursor:pointer;
          box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
          transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .sd-btn-default{ background:#18181b; color:#fafafa; }
        .sd-btn-default:hover{ background:#27272a; }
        .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
        .sd-btn-outline:hover{ background:#f4f4f5; }
      
    /* Header alignment */
    .page-header { max-width:1550px; margin:0 auto; }
    .content-section { max-width:1550px; margin:0 auto; }

    /* Aether analytics dashboard: full-page white, attached to the page (no gray gutter / no centering) */
    #analytics.aether-analytics {
      margin:-1.75rem -2rem;
      width:calc(100% + 4rem);
      max-width:none;
      background:#ffffff;
      min-height:calc(100vh - var(--topbar-h, 64px));
    }
    @media (max-width: 1024px) {
      #analytics.aether-analytics {
        margin:-1.5rem -1.5rem;
        width:auto;
      }
    }
    @media (max-width: 768px) {
      #analytics.aether-analytics {
        margin: -1rem -0.75rem;
        width: auto;
      }
    }
    @media (max-width: 480px) {
      #analytics.aether-analytics {
        margin-inline: -0.75rem;
        width: calc(100% + 1.5rem);
      }
    }

    /* Appointment Records Table - Enhanced UI */
    #appointments.content-section {
      margin:-1.75rem -2rem;
      width:calc(100% + 4rem);
      max-width:none;
      background:#ffffff;
      min-height:calc(100vh - var(--topbar-h, 64px));
      padding:1.5rem 2rem;
    }
    @media (max-width: 1024px) {
      #appointments.content-section {
        width:auto;
        margin:-1rem -1.5rem;
        padding:1.5rem;
      }
    }
    @media (max-width: 768px) {
      #appointments.content-section {
        width:auto;
        margin:-1rem -0.75rem;
        padding:1rem 0.75rem;
      }
    }
    /* Calendar schedule: full-page white, attached to the page (no gray gutter / no centering) */
    #calendar-schedule.content-section {
      margin:-1.75rem -2rem;
      width:calc(100% + 4rem);
      max-width:none;
      background:#ffffff;
      min-height:calc(100vh - var(--topbar-h, 64px));
      padding:1.5rem 2rem;
    }
    @media (max-width: 1024px) {
      #calendar-schedule.content-section {
        box-sizing:border-box;
        width:100%;
        margin:-1rem 0 0;
        padding:1rem 0;
      }
    }
    .appt-white-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
      overflow: hidden;
    }
    .appt-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
      padding: 20px 24px;
      border-bottom: 1px solid #f1f5f9;
    }
    .appt-header-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .appt-card-title {
      font-size: 1rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
    }
    .appt-count-badge {
      background: #f1f5f9;
      color: #64748b;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 999px;
    }
    .appt-header-right {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }
    .appt-search-wrap {
      position: relative;
    }
    .appt-search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      pointer-events: none;
    }
    .appt-search-input {
      padding: 9px 14px 9px 38px;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      font-size: 0.85rem;
      outline: none;
      width: 250px;
      background: #f8fafc;
      color: #334155;
      transition: all 0.2s;
    }
    .appt-search-input:focus {
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
      background: #fff;
    }
    .appt-status-select {
      padding: 9px 14px;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      font-size: 0.85rem;
      outline: none;
      background: #f8fafc;
      color: #334155;
      cursor: pointer;
      transition: all 0.2s;
    }
    .appt-status-select:focus {
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
    }
    .appt-tabs {
      display: flex;
      align-items: center;
      gap: 0;
      padding: 0 24px;
      border-bottom: 1px solid #e2e8f0;
    }
    .appt-tab {
      border: 0;
      background: transparent;
      color: #64748b;
      padding: 12px 18px;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
      transition: all 0.15s;
    }
    .appt-tab:hover {
      color: #0f172a;
    }
    .appt-tab.active {
      color: #0E8131;
      border-bottom-color: #0E8131;
    }
    .appt-filter-row {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 24px;
      color: #64748b;
      font-size: 0.75rem;
      background: #fafbfc;
      border-bottom: 1px solid #f1f5f9;
    }
    .appt-filter-label {
      font-weight: 600;
    }
    .appt-filter-icon {
      color: #94a3b8;
    }
    .appt-table .sd-table-cb {
      width: 16px;
      height: 16px;
      accent-color: #18181b;
      cursor: pointer;
    }
    .appt-empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: #94a3b8;
    }
    .appt-empty-icon {
      opacity: 0.3;
      margin-bottom: 12px;
    }
    .appt-empty-text {
      font-size: 0.9rem;
    }
    .appt-no-results {
      display: none;
      text-align: center;
      padding: 2.5rem;
      color: #94a3b8;
      font-size: 0.88rem;
    }
    /* Appointment table - All Clients style */
    .appt-db-table-area {
      overflow-x: auto;
      padding: 0 24px 8px;
    }
    .appt-table {
      width: 100%;
      border-collapse: collapse;
    }
    .appt-table thead th {
      text-align: left;
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #000;
      padding: 10px 12px;
      background: #f1f5f9;
      white-space: nowrap;
      border-bottom: 10px solid transparent;
    }
    .appt-table tbody tr {
      cursor: pointer;
      transition: background 0.12s ease;
      border-bottom: 1px solid #f1f5f9;
    }
    .appt-table tbody tr:hover { background: #f8fafc; }
    .appt-table tbody tr.selected { background: #f0fdf4; }
    .appt-table tbody td {
      padding: 16px 12px;
      vertical-align: middle;
      font-size: 13.5px;
      color: #475569;
    }
    .appt-table .appt-check-cell,
    .appt-table .appt-th-check {
      width: 40px;
      text-align: center;
    }
    .appt-table .appt-check-cell input,
    .appt-table .appt-th-check input {
      width: 16px;
      height: 16px;
      accent-color: #0E8131;
      cursor: pointer;
    }
    .appt-table .client-cell { display: flex; align-items: center; gap: 10px; }
    .appt-table .avatar {
      width: 36px; height: 36px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 12.5px;
      color: #047857; background: #d1fae5; flex-shrink: 0;
    }
    .appt-table .client-name { font-weight: 600; color: #0f172a; font-size: 13.5px; }
    .appt-table .client-id { font-size: 11.5px; color: #94a3b8; margin-top: 1px; }
    .appt-table .contact-main { color: #334155; font-weight: 500; }
    .appt-table .contact-sub { font-size: 11.5px; color: #94a3b8; margin-top: 1px; }
    .appt-status-pill {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: 4px;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      font-weight: 500;
      white-space: nowrap;
      line-height: 1.4;
      border: 1px solid #e4e4e7;
    }
    .appt-table .sd-td-action { text-align: right; white-space: nowrap; }
    .appt-table .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    .appt-table .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }

    #apptRowMenuPop{ position:fixed!important; z-index:9999!important; background:#ffffff!important; border:1px solid #e6e9ee!important; border-radius:10px!important; box-shadow:0 4px 14px rgba(15,23,32,.06)!important; padding:6px!important; min-width:190px!important; display:none; }
    #apptRowMenuPop.open{ display:block!important; }
    #apptRowMenuPop .row-menu-item{ display:flex!important; align-items:center!important; gap:10px!important; width:100%!important; padding:9px 10px!important; border:none!important; background:none!important; border-radius:8px!important; font-family:'Inter','Segoe UI',system-ui,sans-serif!important; font-size:13px!important; font-weight:500!important; color:#334155!important; cursor:pointer!important; text-align:left!important; line-height:1.4!important; }
    #apptRowMenuPop .row-menu-item:hover{ background:#f8fafc!important; }
    #apptRowMenuPop .row-menu-item.danger{ color:#dc2626!important; }
    #apptRowMenuPop .row-menu-item.danger:hover{ background:#fef2f2!important; }
    #apptRowMenuPop .row-menu-item svg{ flex-shrink:0!important; width:15px!important; height:15px!important; }
    .appt-table-head {
      display: grid;
      grid-template-columns: 44px minmax(170px, 1.2fr) minmax(160px, 1.1fr) minmax(200px, 1.3fr) 120px 120px;
      align-items: center;
      gap: 12px;
      padding: 12px 24px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    .appt-th {
      font-size: 0.7rem;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .appt-th-actions {
      text-align: right;
    }
    .appt-check-head {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .appt-check-head input,
    .appt-select-cell input {
      width: 17px;
      height: 17px;
      accent-color: #0E8131;
      cursor: pointer;
    }
    .appt-card-list {
      display: flex;
      flex-direction: column;
    }
    .appt-row {
      display: grid;
      grid-template-columns: 44px minmax(170px, 1.2fr) minmax(160px, 1.1fr) minmax(200px, 1.3fr) 120px 120px;
      align-items: center;
      gap: 12px;
      padding: 14px 24px;
      background: #fff;
      border-bottom: 1px solid #f1f5f9;
      cursor: pointer;
      transition: background 0.12s;
      min-height: 72px;
    }
    .appt-row:hover {
      background: #f8fafc;
    }
    .appt-row.selected {
      background: #f0fdf4;
    }
    .appt-td {
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .appt-select-cell {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .appt-record-cell {
      gap: 5px;
    }
    .appt-ref-link {
      color: #0f172a;
      font-family: 'SF Mono', Monaco, monospace;
      font-size: 0.8rem;
      font-weight: 600;
      text-decoration: none;
    }
    .appt-ref-link:hover {
      color: #0E8131;
    }
    .appt-service-badge {
      width: max-content;
      font-size: 0.68rem;
      font-weight: 600;
      color: #475569;
      background: #f1f5f9;
      padding: 3px 8px;
      border-radius: 999px;
    }
    .appt-person-cell {
      gap: 3px;
    }
    .appt-resident-name {
      color: #0f172a;
      font-size: 0.84rem;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .appt-pet-name {
      color: #64748b;
      font-size: 0.75rem;
    }
    .appt-schedule-cell {
      gap: 4px;
    }
    .appt-schedule-main {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .appt-schedule-icon {
      color: #0E8131;
      flex-shrink: 0;
    }
    .appt-schedule-date {
      color: #334155;
      font-size: 0.82rem;
      font-weight: 600;
    }
    .appt-schedule-sub {
      display: flex;
      align-items: center;
      gap: 6px;
      color: #64748b;
      font-size: 0.75rem;
    }
    .appt-time-icon {
      color: #0E8131;
      flex-shrink: 0;
    }
    .appt-submitted {
      color: #94a3b8;
      font-size: 0.7rem;
    }
    .appt-status-cell {
      align-items: flex-start;
    }
    .appt-status-badge {
      font-size: 0.7rem;
      font-weight: 700;
      padding: 5px 10px;
      border-radius: 999px;
      white-space: nowrap;
      text-transform: capitalize;
    }
    .appt-actions-cell {
      align-items: flex-end;
      justify-content: flex-end;
    }
    .appt-actions-wrap {
      display: flex;
      gap: 4px;
      align-items: center;
    }
    .appt-icon-btn {
      width: 30px;
      height: 30px;
      border-radius: 6px;
      border: 1px solid #e2e8f0;
      background: #fff;
      color: #64748b;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.12s;
    }
    .appt-icon-btn:hover {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #cbd5e1;
    }
    .appt-pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 24px;
      border-top: 1px solid #f1f5f9;
    }
    .appt-pagination-info {
      font-size: 0.8rem;
      color: #64748b;
    }
    .appt-pagination-controls {
      display: flex;
      gap: 6px;
    }
    .appt-page-btn {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      border: 1px solid #e2e8f0;
      background: #fff;
      color: #64748b;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.12s;
    }
    .appt-page-btn:hover:not(:disabled) {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #cbd5e1;
    }
    .appt-page-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    @media (max-width: 1100px) {
      .appt-table-head, .appt-row {
        grid-template-columns: 38px minmax(150px, 1fr) minmax(140px, 1fr) minmax(180px, 1fr) 110px 110px;
        gap: 10px;
      }
      .appt-card-header {
        padding: 16px 20px;
      }
      .appt-tabs, .appt-filter-row {
        padding: 0 20px;
      }
      .appt-row {
        padding: 12px 20px;
      }
    }
    @media (max-width: 768px) {
      .appt-table-head {
        display: none;
      }
      .appt-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 10px 14px;
        padding: 14px 20px;
        border-bottom: 1px solid #f1f5f9;
      }
      .appt-select-cell {
        flex: 0 0 24px;
        order: 1;
      }
      .appt-record-cell {
        flex: 1 1 calc(50% - 14px);
        order: 2;
      }
      .appt-person-cell {
        flex: 1 1 calc(50% - 14px);
        order: 3;
      }
      .appt-schedule-cell {
        flex: 1 1 100%;
        order: 5;
        padding-left: 32px;
      }
      .appt-status-cell {
        order: 4;
      }
      .appt-actions-cell {
        width: 100%;
        order: 6;
        justify-content: flex-start;
        padding-top: 8px;
        border-top: 1px solid #f1f5f9;
        margin-top: 4px;
        padding-left: 32px;
      }
      .appt-card-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .appt-header-right {
        width: 100%;
      }
      .appt-search-input {
        width: 100%;
      }
    }

    /* ?? Dialog font + button style (shadcn) - Appointment Requests ?? */
    #appointments { font-family:'Inter','Segoe UI',sans-serif; }
    #appointments .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    #appointments .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); }
    #appointments .kpi-card:hover{ border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }
    #appointments .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    #appointments .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #appointments .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    @media (max-width:1180px){ #appointments .kpis{ grid-template-columns:repeat(2,1fr); } }
    #appointments .appt-card-title { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    #appointments .appt-count-badge { background:#f4f4f5; color:#71717a; }
    #appointments .appt-search-input { height:36px; padding:0 14px 0 38px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #appointments .appt-search-input:focus { border-color:#18181b; box-shadow:0 0 0 1px #18181b; background:#fff; }
    #appointments .appt-search-input::placeholder { color:#a1a1aa; }
    #appointments .appt-search-icon { color:#71717a; }
    #appointments .appt-status-select { height:36px; padding:0 26px 0 12px; border:1px solid #e4e4e7; border-radius:6px; background-color:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #appointments .appt-status-select:focus { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #appointments .bulk-bar{ display:none; align-items:center; gap:12px; margin-bottom:4px; padding-top:10px; font-family:'Inter','Segoe UI',sans-serif; }
    #appointments .bulk-bar.active{ display:flex; }
    #appointments .bulk-bar .bulk-count{ font-size:14px; color:#71717a; white-space:nowrap; margin-right:4px; }
    #appointments .bulk-bar .bulk-actions{ display:flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; }
    #appointments .bulk-bar .bulk-btn{ display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 12px; border-radius:5px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:500; color:#52525b; cursor:pointer; transition:background-color .15s ease, color .15s ease; }
    #appointments .bulk-bar .bulk-btn:hover{ background:#f4f4f5; color:#18181b; }
    #appointments .bulk-bar .bulk-btn.danger{ color:#dc2626; }
    #appointments .bulk-bar .bulk-btn.danger:hover{ background:#fef2f2; }
    #appointments .bulk-bar .bulk-btn svg{ width:14px; height:14px; flex-shrink:0; }
    #appointments .appt-card-header { justify-content:flex-start; }
    #appointments .appt-header-right { flex-wrap:wrap; }
    #appointments .appt-db-table-area { overflow-x:auto; overflow-y:hidden; contain:paint; -webkit-overflow-scrolling:touch; border:1px solid #e4e4e7; border-radius:8px; background:#fff; padding:0; }
    #appointments .appt-table thead th { background:#fafafa; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; letter-spacing:0; text-transform:none; color:#71717a; height:40px; padding:0 8px; border-bottom:1px solid #e4e4e7; }
    #appointments .appt-table tbody tr { border-bottom:1px solid #e4e4e7; }
    #appointments .appt-table tbody tr:hover { background:#f4f4f5; }
    #appointments .appt-table tbody tr.selected { background:#f4f4f5; }
    #appointments .appt-table tbody td { padding:8px; font-size:14px; color:#18181b; }
    #appointments .appt-table-wrap{ min-height:var(--staff-table-min-height); }
    #appointments .appt-empty-state{ min-height:var(--staff-table-min-height); display:flex; flex-direction:column; align-items:center; justify-content:center; }
    #appointments .appt-table-wrap{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #appointments .appt-db-table-area{ border:0; border-radius:0; min-height:100%; }
    #appointments .appt-table .client-name { font-family:'Inter','Segoe UI',sans-serif; font-weight:600; color:#18181b; font-size:14px; }
    #appointments .appt-table .client-id { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; margin-top:2px; }
    #appointments .appt-table .contact-main { font-family:'Inter','Segoe UI',sans-serif; color:#18181b; font-weight:500; font-size:14px; }
    #appointments .appt-table .contact-main.contact-normal { font-weight:400; }
    #appointments .appt-table .contact-main.contact-name { font-weight:500; }
    #appointments .appt-table .contact-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; margin-top:2px; }
    #appointments .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #appointments .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #appointments .th-sort.th-sorted{ color:#18181b; }
    #appointments .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #appointments .th-sorted .th-sort-icon{ color:#18181b; }
    #appointments .appt-table .sd-row-actions { width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #appointments .appt-table .sd-row-actions:hover { background:#f4f4f5; color:#18181b; }
    #appointments .appt-table .avatar { background:#e4e4e7; color:#71717a; overflow:hidden; }
    #appointments .appt-table .avatar img { width:100%; height:100%; object-fit:cover; }
    #appointments .client-name { font-family:'Inter','Segoe UI',sans-serif; font-weight:600; color:#18181b; }
    #appointments .contact-main { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #appointments .contact-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #appointments .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; margin-top:auto; }
    #appointments .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    #appointments .dt-rows { display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    #appointments .dt-rows select { height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    #appointments .dt-rows select:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #appointments .dt-page-label { width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    #appointments .dt-page-btns { display:flex; align-items:center; gap:4px; }
    #appointments .dt-page-btn { height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    #appointments .dt-page-btn:hover:not(:disabled) { background:#f4f4f5; }
    #appointments .dt-page-btn:disabled { opacity:.5; cursor:not-allowed; }
    @media (max-width: 1024px) {
      #appointments .kpis {
        grid-template-columns:repeat(2,minmax(0,1fr));
      }
      #appointments .appt-card-header,
      #appointments .appt-header-right,
      #appointments .appt-card-header > div:last-child {
        min-width:0;
      }
      #appointments .appt-table-wrap,
      #appointments .appt-db-table-area {
        max-width:100%;
        min-width:0;
      }
      #appointments .appt-db-table-area {
        overflow-x:auto !important;
        overflow-y:hidden;
        contain:paint;
        -webkit-overflow-scrolling:touch;
      }
      #appointments .appt-table {
        width:max-content;
        min-width:70rem;
      }
    }
    @media (max-width: 768px) {
      #appointments .appt-card-header {
        flex-direction:column;
        align-items:stretch;
        gap:0.75rem;
        padding:1rem 0;
      }
      #appointments .appt-header-right {
        display:grid;
        grid-template-columns:minmax(0,1fr);
        width:100%;
        gap:0.6rem;
      }
      #appointments .appt-search-wrap,
      #appointments .appt-search-input,
      #appointments .appt-status-select {
        width:100%;
        min-width:0;
      }
      #appointments .appt-card-header > div:last-child {
        width:100%;
        margin-left:0 !important;
      }
      #appointments .appt-card-header > div:last-child .sd-btn {
        flex:1 1 100%;
        justify-content:center;
      }
      #appointments .bulk-bar.active,
      #appointments .bulk-bar .bulk-actions {
        flex-wrap:wrap;
        max-width:100%;
      }
      #appointments .dt-pagination,
      #appointments .dt-pagination-right {
        justify-content:center;
        gap:0.75rem;
      }
      #appointments .dt-pagination {
        padding-inline:0;
      }
    }
    @media (max-width: 480px) {
      #appointments .kpis {
        grid-template-columns:minmax(0,1fr);
        gap:0.75rem;
      }
      #appointments .kpi-card {
        min-width:0;
      }
      #appointments .appt-card-header > div:last-child {
        flex-direction:column;
      }
      #appointments .appt-card-header > div:last-child .sd-btn {
        width:100%;
      }
      #appointments .dt-rows {
        justify-content:center;
        flex-wrap:wrap;
      }
      #appointments .dt-page-label {
        width:auto;
      }
    }
    @media (max-width: 768px) {
      #appointments .appt-primary-actions {
        display:grid !important;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
      }
      #appointments .appt-primary-actions .sd-btn {
        box-sizing:border-box;
        flex:0 0 auto !important;
        width:100%;
        min-width:0;
        min-height:40px;
        height:auto;
        padding:8px;
        line-height:1.25;
        white-space:normal;
      }
      #appointments .appt-primary-actions .sd-btn:last-child {
        grid-column:1 / -1;
      }
    }

    /* Dialog font + button style (shadcn) - Pending / Active / Resolved cases */
    #reports-pending .dt-container, #reports-active .dt-container, #reports-resolved .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #reports-pending table, #reports-active table, #reports-resolved table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #reports-pending thead th, #reports-active thead th, #reports-resolved thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; text-transform:none; letter-spacing:0; border-bottom:1px solid #e4e4e7; }
    #reports-pending thead th.sd-th-action, #reports-active thead th.sd-th-action, #reports-resolved thead th.sd-th-action{ text-align:right; }
    #reports-pending tbody tr, #reports-active tbody tr, #reports-resolved tbody tr{ border-bottom:1px solid #e4e4e7; }
    #reports-pending tbody tr:hover, #reports-active tbody tr:hover, #reports-resolved tbody tr:hover{ background:#f4f4f5; }
    #reports-pending tbody td, #reports-active tbody td, #reports-resolved tbody td{ padding:10px 8px; font-size:14px; color:#18181b; }
    #reports-pending .client-name, #reports-active .client-name, #reports-resolved .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #reports-pending .client-name.report-id, #reports-active .client-name.report-id, #reports-resolved .client-name.report-id{ font-weight:400; }
    #reports-pending .client-id, #reports-active .client-id, #reports-resolved .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; margin-top:2px; }
    #reports-pending .contact-main, #reports-active .contact-main, #reports-resolved .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    #reports-pending .contact-sub, #reports-active .contact-sub, #reports-resolved .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #reports-pending .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12.5px; font-weight:400; color:#71717a; line-height:1.4; margin-top:2px; word-break:break-word; }
    #reports-pending, #reports-active, #reports-resolved{ font-family:'Inter','Segoe UI',sans-serif; }
    #reports-pending tbody td, #reports-active tbody td, #reports-resolved tbody td{ font-family:'Inter','Segoe UI',sans-serif; }
    #reports-pending .client-id, #reports-active .client-id, #reports-resolved .client-id{ font-weight:400; }
    #reports-pending .th-sort, #reports-active .th-sort, #reports-resolved .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #reports-pending .th-sort:hover, #reports-active .th-sort:hover, #reports-resolved .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #reports-pending .th-sort.th-sorted, #reports-active .th-sort.th-sorted, #reports-resolved .th-sort.th-sorted{ color:#18181b; }
    #reports-pending .th-sort-icon, #reports-active .th-sort-icon, #reports-resolved .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #reports-pending .th-sorted .th-sort-icon, #reports-active .th-sorted .th-sort-icon, #reports-resolved .th-sorted .th-sort-icon{ color:#18181b; }
    #reports-pending .sd-row-actions, #reports-active .sd-row-actions, #reports-resolved .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #reports-pending .sd-row-actions:hover, #reports-active .sd-row-actions:hover, #reports-resolved .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    #reports-all .type-badge, #reports-pending .type-badge, #reports-active .type-badge, #reports-resolved .type-badge{ font-size:14px; }
    #reports-all .rpt-status, #reports-pending .rpt-status, #reports-accepted .rpt-status, #reports-active .rpt-status, #reports-resolved .rpt-status{ display:inline-flex; align-items:center; padding:3px 10px; border-radius:4px; font-family:'Inter',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    #reports-all .rpt-status.pending, #reports-pending .rpt-status.pending, #reports-active .rpt-status.pending, #reports-resolved .rpt-status.pending{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
    #reports-all .rpt-status.validated, #reports-pending .rpt-status.validated, #reports-active .rpt-status.validated, #reports-resolved .rpt-status.validated{ background:#dcebe0; color:#045e1f; border-color:#bbf7d0; }
    #reports-all .rpt-status.active, #reports-pending .rpt-status.active, #reports-accepted .rpt-status.active, #reports-active .rpt-status.active, #reports-resolved .rpt-status.active{ background:#EFF6FF; color:#1E40AF; border-color:#BFDBFE; }
    #reports-all .rpt-status.resolved, #reports-pending .rpt-status.resolved, #reports-active .rpt-status.resolved, #reports-resolved .rpt-status.resolved{ background:#0E8131; color:#ffffff; border-color:#0E8131; }
    #reports-all .rpt-status.rejected, #reports-pending .rpt-status.rejected, #reports-active .rpt-status.rejected, #reports-resolved .rpt-status.rejected{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }

  </style>
      <div id="editClientModal" class="modal">
        <form id="editClientForm" class="sd-dialog">
          <input type="hidden" name="user_id" id="editUserId" value="">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('editClientModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header">
              <h2 class="sd-dialog-title">Edit profile</h2>
              <p class="sd-dialog-description">Make changes to your profile here. Click save when you're done.</p>
            </div>
            <div class="sd-field-group">
              <div class="sd-field">
                <label class="sd-label" for="editFullName">Full name</label>
                <input id="editFullName" name="full_name" type="text" class="sd-input" required>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editEmail">Email</label>
                <input id="editEmail" name="email" type="email" class="sd-input" required>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editPhone">Mobile number</label>
                <input id="editPhone" name="phone" type="text" class="sd-input" required>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editBarangay">Barangay</label>
                <select id="editBarangay" name="barangay_id" class="sd-input" required>
                  <option value="">Select a barangay</option>
                  <?php foreach ($cpdBarangays as $b): ?>
                    <option value="<?php echo (int)$b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editGender">Gender</label>
                <select id="editGender" name="gender" class="sd-input">
                  <option value="">�?"</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="prefer_not">Prefer not to say</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editBirthdate">Birthdate</label>
                <input id="editBirthdate" name="birthdate" type="date" class="sd-input">
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editStatus">Status</label>
                <select id="editStatus" name="status" class="sd-input">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editAddress">Address</label>
                <textarea id="editAddress" name="address" class="sd-input" rows="2"></textarea>
              </div>
            </div>
            <div class="sd-dialog-footer">
              <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('editClientModal')">Cancel</button>
              <button type="submit" class="sd-btn sd-btn-default">Save changes</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Archive Confirm Modal -->
      <div id="archiveClientModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:999;">
        <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
          <div style="margin-bottom:16px;">
            <h2 class="sd-dialog-title">Are you absolutely sure?</h2>
            <p class="sd-dialog-description" style="margin-top:8px;">This action cannot be undone. This will permanently archive <strong id="archiveClientName" style="color:#18181b;"></strong> from the database.</p>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button class="sd-btn sd-btn-default" onclick="closeModal('archiveClientModal')">Cancel</button>
            <button class="sd-btn sd-btn-primary" id="archiveConfirmBtn" onclick="executeArchive()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Continue</button>
          </div>
        </div>
      </div>

      <div id="archiveClientsBulkModal" class="modal">
        <div class="sd-dialog">
          <div class="sd-dialog-content" role="dialog" aria-modal="true" aria-labelledby="archiveClientsBulkTitle" style="width:min(480px,94vw);max-width:min(480px,94vw);max-height:calc(100vh - 32px);box-sizing:border-box;overflow-y:auto;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 20px 25px rgba(15,23,32,.18);transform:none;transition:none;padding:24px;gap:16px;">
            <button type="button" class="sd-dialog-close" id="archiveClientsBulkClose" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header">
              <h2 class="sd-dialog-title" id="archiveClientsBulkTitle">Client Details</h2>
              <p class="sd-dialog-description" id="archiveClientsBulkDescription">Review this client profile before archiving.</p>
            </div>
            <div id="archiveClientsBulkProfile" style="padding-bottom:4px;"></div>
            <div class="sd-dialog-footer">
              <button type="button" class="sd-btn sd-btn-outline" id="archiveClientsBulkCancel">Cancel</button>
              <button type="button" class="sd-btn sd-btn-default" id="archiveClientsBulkConfirm" style="background:#dc2626;border-color:#dc2626;color:#fff;">Archive this client</button>
            </div>
          </div>
        </div>
      </div>

      <div class="cpd-toast success" id="cpdToast"></div>
    </div>
  </section>

<script>
var CLIENTS = <?php echo json_encode($cpdClients, $jsonFlags); ?>;
var PETS = <?php echo json_encode($cpdPets, $jsonFlags); ?>;
var VIEW_OWNER = <?php echo $viewOwner; ?>;
</script>
<script>
var residents = <?php echo json_encode($composeResidents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
var barangayUsers = <?php echo json_encode($composeBarangayUsers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/client-database.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/staff-compose-dialog.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/staff-compose-dialog.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>

  <!-- �"?�"? REPORTS: ALL �"?�"? -->
  <section id="reports-all" class="content-section staff-report-section">
    <style>
      /* Dialog font + button style (shadcn) - All Reports */
      #reports-all{ background:var(--white); margin:-1.75rem -2rem; width:calc(100% + 4rem); max-width:none; min-height:calc(100vh - var(--topbar-h, 64px)); }
      /* Checkbox bulk selection (same pattern as Barangay Portal) */
      #reports-all .sd-table-cb{ width:16px; height:16px; accent-color:#18181b; cursor:pointer; }
      #reports-all .bulk-bar{ display:none; align-items:center; gap:12px; margin-bottom:4px; padding-top:10px; font-family:'Inter','Segoe UI',sans-serif; }
      #reports-all .bulk-bar.active{ display:flex; }
      #reports-all .bulk-bar .bulk-count{ font-size:14px; color:#71717a; white-space:nowrap; margin-right:4px; }
      #reports-all .bulk-bar .bulk-actions{ display:flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; }
      #reports-all .bulk-bar .bulk-btn{ display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 12px; border-radius:5px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:500; color:#52525b; cursor:pointer; }
      #reports-all .bulk-bar .bulk-btn:hover{ background:#f4f4f5; color:#18181b; }
      #reports-all .bulk-bar .bulk-btn.danger{ color:#dc2626; }
      #reports-all .bulk-bar .bulk-btn.danger:hover{ background:#fef2f2; }
      #reports-all .bulk-bar .bulk-btn svg{ width:14px; height:14px; flex-shrink:0; }
      #reports-all .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
      #reports-all .dt-container{ min-height:var(--staff-table-min-height); }
      #reports-all table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
      #reports-all thead tr{ border-bottom:1px solid #e4e4e7; }
      #reports-all thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; }
      #reports-all thead th.sd-th-action{ text-align:right; }
      #reports-all tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
      #reports-all tbody tr:last-child{ border-bottom:none; }
      #reports-all tbody tr:hover{ background:#fafafa; }
      #reports-all tbody tr.selected, #reports-all tbody tr.selected:hover{ background:#f4f4f5; }
      #reports-all tbody td{ padding:8px; vertical-align:middle; font-size:14px; color:#18181b; }
      #reports-all tbody td.sd-td-action{ text-align:right; }
      #reports-all .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
      #reports-all .client-name.report-id{ font-weight:400; }
      #reports-all .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
      #reports-all .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
      #reports-all .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12.5px; font-weight:400; color:#71717a; line-height:1.4; margin-top:2px; word-break:break-word; }
      #reports-all .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
      #reports-all .th-sort:hover{ background:#f4f4f5; color:#18181b; }
      #reports-all .th-sort.th-sorted{ color:#18181b; }
      #reports-all .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
      #reports-all .th-sorted .th-sort-icon{ color:#18181b; }
      #reports-all .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
      #reports-all .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
      #reports-all .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
      #reports-all .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
      #reports-all .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
      #reports-all .search-grow-wrap input{ height:36px; width:100%; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
      #reports-all .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #reports-all .search-grow-wrap input::placeholder{ color:#71717a; }
      #reports-all .search-grow-wrap svg{ color:#71717a; }
      #reports-all .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
      #reports-all .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #reports-all .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; }
      #reports-all .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
      #reports-all .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
      #reports-all .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
      #reports-all .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #reports-all .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
      #reports-all .dt-page-btns{ display:flex; align-items:center; gap:4px; }
      #reports-all .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
      #reports-all .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
      #reports-all .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
      #reports-pending .dt-pagination, #reports-accepted .dt-pagination, #reports-active .dt-pagination, #reports-resolved .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; font-family:'Inter','Segoe UI',sans-serif; }
      #reports-pending .dt-pagination-right, #reports-accepted .dt-pagination-right, #reports-active .dt-pagination-right, #reports-resolved .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
      #reports-pending .dt-rows, #reports-accepted .dt-rows, #reports-active .dt-rows, #reports-resolved .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
      #reports-pending .dt-rows select, #reports-accepted .dt-rows select, #reports-active .dt-rows select, #reports-resolved .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
      #reports-pending .dt-rows select:focus, #reports-accepted .dt-rows select:focus, #reports-active .dt-rows select:focus, #reports-resolved .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #reports-pending .dt-page-label, #reports-accepted .dt-page-label, #reports-active .dt-page-label, #reports-resolved .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
      #reports-pending .dt-page-btns, #reports-accepted .dt-page-btns, #reports-active .dt-page-btns, #reports-resolved .dt-page-btns{ display:flex; align-items:center; gap:4px; }
      #reports-pending .dt-page-btn, #reports-accepted .dt-page-btn, #reports-active .dt-page-btn, #reports-resolved .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
      #reports-pending .dt-page-btn:hover:not(:disabled), #reports-accepted .dt-page-btn:hover:not(:disabled), #reports-active .dt-page-btn:hover:not(:disabled), #reports-resolved .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
      #reports-pending .dt-page-btn:disabled, #reports-accepted .dt-page-btn:disabled, #reports-active .dt-page-btn:disabled, #reports-resolved .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
      @media (max-width: 640px){
        #reports-pending .dt-pagination-right, #reports-accepted .dt-pagination-right, #reports-active .dt-pagination-right, #reports-resolved .dt-pagination-right{ justify-content:center; gap:12px; }
      }
      #reports-all{ font-family:'Inter','Segoe UI',sans-serif; }
      #reports-all .kpi-card{ font-family:'Inter','Segoe UI',sans-serif; }
      #reports-all .search-grow-wrap input{ font-family:'Inter','Segoe UI',sans-serif; }
      #reports-all .search-grow-wrap input::placeholder{ color:#a1a1aa; }
      #reports-all tbody td{ font-family:'Inter','Segoe UI',sans-serif; }
      .cpd .rpt-section-title-action{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
      .cpd .cpd-edit-icon{ display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; flex:0 0 30px; padding:0; border:1px solid #e4e4e7; border-radius:6px; background:#fff; cursor:pointer; }
      .cpd .cpd-edit-icon img{ display:block; width:16px; height:16px; object-fit:contain; }
      .cpd .cpd-edit-icon:hover{ border-color:#18181b; background:#f4f4f5; }

      /* Match report summary cards to the Programs & Services stat cards. */
      body.staff-theme #reports-all .kpi-card,
      body.staff-theme #reports-pending .db-panel .kpi-card,
      body.staff-theme #reports-accepted .db-panel .kpi-card,
      body.staff-theme #reports-active .db-panel .kpi-card,
      body.staff-theme #reports-resolved .db-panel .kpi-card {
        display: flex;
        min-width: 0;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 14px 16px;
        border: 1px solid #E5E7EB;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(16,24,40,.05), 0 1px 2px rgba(16,24,40,.03);
        text-align: center;
      }
      body.staff-theme #reports-all .kpi-label,
      body.staff-theme #reports-pending .db-panel .kpi-label,
      body.staff-theme #reports-accepted .db-panel .kpi-label,
      body.staff-theme #reports-active .db-panel .kpi-label,
      body.staff-theme #reports-resolved .db-panel .kpi-label {
        margin: 0 0 6px;
        color: #18181b;
        font-family: 'Inter','Segoe UI',sans-serif;
        font-size: 0.85rem;
        font-weight: 500;
        letter-spacing: 0.03em;
        text-transform: uppercase;
      }
      body.staff-theme #reports-all .kpi-value,
      body.staff-theme #reports-pending .db-panel .kpi-value,
      body.staff-theme #reports-accepted .db-panel .kpi-value,
      body.staff-theme #reports-active .db-panel .kpi-value,
      body.staff-theme #reports-resolved .db-panel .kpi-value {
        margin: 0 0 6px;
        color: #18181b;
        font-family: 'Inter','Segoe UI',sans-serif;
        font-size: 1.75rem !important;
        font-weight: 600;
        letter-spacing: normal;
        line-height: 1;
      }
      body.staff-theme #reports-all .kpi-sub,
      body.staff-theme #reports-pending .db-panel .kpi-sub,
      body.staff-theme #reports-accepted .db-panel .kpi-sub,
      body.staff-theme #reports-active .db-panel .kpi-sub,
      body.staff-theme #reports-resolved .db-panel .kpi-sub {
        margin: 0;
        color: #71717a;
        font-family: 'Inter','Segoe UI',sans-serif;
        font-size: 14px;
      }

      /* Keep report queues within the staff content column; tables scroll in their own wrappers. */
      #reports-all,
      #reports-pending,
      #reports-accepted,
      #reports-active,
      #reports-resolved,
      #reports-all .reports-fullpage,
      #reports-pending .reports-fullpage,
      #reports-accepted .reports-fullpage,
      #reports-active .reports-fullpage,
      #reports-resolved .reports-fullpage,
      #reports-all .cpd-app,
      #reports-pending .cpd-app,
      #reports-accepted .cpd-app,
      #reports-active .cpd-app,
      #reports-resolved .cpd-app,
      #reports-all .layout,
      #reports-pending .layout,
      #reports-accepted .layout,
      #reports-active .layout,
      #reports-resolved .layout,
      #reports-all .db-panel,
      #reports-pending .db-panel,
      #reports-accepted .db-panel,
      #reports-active .db-panel,
      #reports-resolved .db-panel,
      #reports-all .db-table-area,
      #reports-pending .db-table-area,
      #reports-accepted .db-table-area,
      #reports-active .db-table-area,
      #reports-resolved .db-table-area {
        min-width: 0;
        max-width: 100%;
      }
      #reports-all .dt-container,
      #reports-pending .dt-container,
      #reports-accepted .dt-container,
      #reports-active .dt-container,
      #reports-resolved .dt-container {
        width: 100%;
        max-width: 100%;
        min-width: 0;
      }
      #reports-all .dt-container > div,
      #reports-pending .dt-container > div,
      #reports-accepted .dt-container > div,
      #reports-active .dt-container > div,
      #reports-resolved .dt-container > div {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        overflow-x: auto;
        overflow-y: hidden;
        contain: paint;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
      }
      @media (max-width: 1024px) {
        #reports-all .kpis,
        #reports-pending .db-panel .kpis,
        #reports-accepted .db-panel .kpis,
        #reports-active .db-panel .kpis,
        #reports-resolved .db-panel .kpis {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
      @media (max-width: 768px) {
        #reports-all .bulk-bar,
        #reports-all .bulk-actions {
          flex-wrap: wrap;
          max-width: 100%;
        }
      }
      @media (max-width: 480px) {
        body.staff-theme #reports-all .kpis,
        body.staff-theme #reports-pending .db-panel .kpis,
        body.staff-theme #reports-accepted .db-panel .kpis,
        body.staff-theme #reports-active .db-panel .kpis,
        body.staff-theme #reports-resolved .db-panel .kpis {
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 10px;
        }
        body.staff-theme #reports-all .dt-pagination,
        body.staff-theme #reports-pending .dt-pagination,
        body.staff-theme #reports-accepted .dt-pagination,
        body.staff-theme #reports-active .dt-pagination,
        body.staff-theme #reports-resolved .dt-pagination {
          justify-content: center;
          padding-inline: 0;
        }
        body.staff-theme #reports-all .dt-pagination-right,
        body.staff-theme #reports-pending .dt-pagination-right,
        body.staff-theme #reports-accepted .dt-pagination-right,
        body.staff-theme #reports-active .dt-pagination-right,
        body.staff-theme #reports-resolved .dt-pagination-right {
          justify-content: center;
          gap: 0.75rem;
        }
        body.staff-theme #reports-all .dt-page-label,
        body.staff-theme #reports-pending .dt-page-label,
        body.staff-theme #reports-accepted .dt-page-label,
        body.staff-theme #reports-active .dt-page-label,
        body.staff-theme #reports-resolved .dt-page-label {
          width: auto;
        }
        body.staff-theme #reports-all .bulk-bar .bulk-actions {
          justify-content: flex-start;
          gap: 0.25rem;
          padding-inline: 0.5rem;
        }
        body.staff-theme #reports-all .bulk-bar .bulk-count {
          white-space: normal;
        }
        .cd-drawer .sd-dialog-content {
          padding: 1rem;
        }
        .cd-drawer .sd-dialog-footer {
          flex-wrap: wrap;
        }
      }
      @media (max-width: 340px) {
        body.staff-theme #reports-all .kpis,
        body.staff-theme #reports-pending .db-panel .kpis,
        body.staff-theme #reports-accepted .db-panel .kpis,
        body.staff-theme #reports-active .db-panel .kpis,
        body.staff-theme #reports-resolved .db-panel .kpis {
          grid-template-columns: minmax(0, 1fr);
        }
      }
    </style>
    <div class="cpd reports-fullpage">
      <div class="cpd-app">

        <!-- Main layout -->
        <div class="layout">

          <!-- Center: Database -->
          <div class="panel db-panel">
            <!-- Overview KPIs -->
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Total Reports</p>
                <div class="kpi-value"><?php echo $rptCounts['all']; ?></div>
                <p class="kpi-sub">All submitted reports</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Active Cases</p>
                <div class="kpi-value"><?php echo $rptCounts['active']; ?></div>
                <p class="kpi-sub">Being handled by DCVS</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Pending Cases</p>
                <div class="kpi-value"><?php echo $rptCounts['pending']; ?></div>
                <p class="kpi-sub">Awaiting action</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Resolved Cases</p>
                <div class="kpi-value"><?php echo $rptCounts['resolved']; ?></div>
                <p class="kpi-sub">Completed case history</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar staff-report-toolbar staff-mobile-toolbar">
              <div class="staff-toolbar-search-row">
              <div class="search-grow-wrap staff-toolbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="rptMainSearch" placeholder="Search reports..." oninput="rptSetSearch(this.value)">
              </div>
              <div class="staff-date-filter staff-toolbar-filter" data-date-filter="rpt">
                <button class="staff-date-filter-button" type="button" aria-label="Filter reports by date" title="Filter by date" aria-expanded="false">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                </button>
                <input type="hidden" id="rptDateFrom" value="">
                <input type="hidden" id="rptDateTo" value="">
              </div>
              </div>
              <div class="toolbar-filters staff-report-filters staff-toolbar-filters">
                <select class="select-input tf-select staff-toolbar-select" id="rptType" onchange="rptSetType(this.value)">
                  <option value="">All Types</option>
                  <option value="stray">Stray Animal</option>
                  <option value="rabies">Rabies</option>
                  <option value="injured">Injured Animal</option>
                  <option value="dead">Dead Animal</option>
                  <option value="rescue">Rescue</option>
                </select>
                <select class="select-input tf-select staff-toolbar-select" id="rptBrgy" onchange="rptSetBrgy(this.value)">
                  <option value="">All Barangays</option>
                  <?php foreach ($cpdBarangays as $b): ?>
                    <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="select-input tf-select staff-toolbar-select" id="rptStatus" onchange="rptSetStatus(this.value)">
                  <option value="">All Statuses</option>
                  <option value="pending">Pending Barangay Review</option>
                  <option value="validated">Pending DCVS Review</option>
                  <option value="active">Active</option>
                  <option value="resolved">Resolved</option>
                  <option value="rejected">Rejected</option>
                </select>
                <select class="select-input tf-select staff-toolbar-select" id="rptResidentStatus" aria-label="Resident account status" onchange="rptSetResidentStatus(this.value)">
                  <option value="active">Active Residents</option>
                  <option value="inactive">Inactive Resident History</option>
                </select>
              </div>
              <div class="db-toolbar-actions staff-toolbar-actions staff-toolbar-single-secondary">
                <button type="button" class="sd-btn sd-btn-outline staff-toolbar-secondary" onclick="rptExportCSV()" title="Export CSV">
                  Export CSV
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </button>
              </div>
            </div>

            <!-- Reports Bulk Actions (checkbox quick actions — Barangay Portal pattern) -->
            <div class="bulk-bar" id="rptBulkBar">
              <div class="bulk-actions">
                <span class="bulk-count"><span id="rptBulkCount">0</span> reports selected</span>
                <button type="button" class="bulk-btn" onclick="rptBulkViewLocation()" title="View selected reports on the map">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  View Location
                </button>
                <button type="button" class="bulk-btn" onclick="rptBulkConfirm()" title="Confirm selected awaiting-confirmation reports">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Confirm
                </button>
                <button type="button" class="bulk-btn danger" onclick="rptBulkReject()" title="Reject selected awaiting-confirmation reports">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                  Reject
                </button>
                <button type="button" class="bulk-btn" onclick="rptBulkResolve()" title="Mark cases of selected reports as resolved">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Mark as Resolved
                </button>
                <button type="button" class="bulk-btn danger" onclick="rptBulkArchive()" title="Archive selected reports">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                  Archive
                </button>
              </div>
            </div>

            <div class="db-table-area staff-report-table-area">
              <div id="rptTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                  <table>
                    <thead id="rptTableHead"></thead>
                    <tbody id="rptRows"></tbody>
                  </table>
                </div>
              </div>
              <div class="dt-pagination">
                <div class="dt-pagination-right">
                  <div class="dt-rows">
                    <span>Rows per page</span>
                    <select onchange="setPerPage(this.value)">
                      <option value="10">10</option>
                      <option value="20">20</option>
                      <option value="30">30</option>
                      <option value="40">40</option>
                      <option value="50">50</option>
                    </select>
                  </div>
                  <div class="dt-page-label" id="rptPageLabel">Page 1 of 1</div>
                  <div class="dt-page-btns">
                    <button class="dt-page-btn" id="rptPageFirst" title="Go to first page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                    </button>
                    <button class="dt-page-btn" id="rptPagePrev" title="Go to previous page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button class="dt-page-btn" id="rptPageNext" title="Go to next page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <button class="dt-page-btn" id="rptPageLast" title="Go to last page">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Report Details Drawer (right drawer, appointments style) -->
  <div id="rptDetailsModal" class="modal cpd cd-drawer staff-detail-drawer">
    <div class="sd-dialog">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('rptDetailsModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Report Details</h2>
          <p class="sd-dialog-description">Review report information and case details.</p>
        </div>
        <div class="sd-field-group">
          <div id="rptDetailContent"></div>
        </div>
        <div class="sd-dialog-footer" id="rptDetailFooter" style="display:flex;justify-content:flex-start;gap:8px;"></div>
      </div>
    </div>
  </div>

  <div id="archiveReportModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
    <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
      <div style="margin-bottom:16px;">
        <h2 class="sd-dialog-title">Are you absolutely sure?</h2>
        <p class="sd-dialog-description" style="margin-top:8px;">This action cannot be undone. Archive this report from <strong id="archiveReportReporter" style="color:#18181b;"></strong> of <strong id="archiveReportBrgy" style="color:#18181b;"></strong>?</p>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;">
        <button class="sd-btn sd-btn-default" onclick="closeModal('archiveReportModal')">Cancel</button>
        <button class="sd-btn sd-btn-primary" id="archiveReportConfirmBtn" onclick="executeArchiveReport()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Continue</button>
      </div>
    </div>
  </div>

  <style>
    #editReportModal .sd-dialog-content{ max-width:min(480px,92vw); }
    #editReportModal .sd-label{ font-size:13px; font-weight:400; line-height:1.4; color:#71717a; }
    #editReportModal .sd-form-section{ display:flex; flex-direction:column; gap:14px; }
    #editReportModal .sd-form-section + .sd-form-section{ border-top:1px solid #f1f5f9; padding-top:18px; }
    #editReportModal .sd-form-section-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; letter-spacing:0; color:#71717a; }
    #editReportModal .sd-field-grid{ gap:16px 18px; }
    #editReportModal .sd-field-full{ grid-column:1 / -1; }
    #editReportModal .sd-field{ gap:7px; }
  </style>

  <div id="editReportModal" class="modal">
    <form id="editReportForm" class="sd-dialog">
      <input type="hidden" name="report_id" id="editReportId" value="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('editReportModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Edit report</h2>
          <p class="sd-dialog-description">Make changes to this report. Click save when you're done.</p>
        </div>
        <div class="sd-field-group">
          <div class="sd-form-section">
            <div class="sd-form-section-title">Report Information</div>
            <div class="sd-field-grid">
              <div class="sd-field">
                <label class="sd-label" for="editReportDate">Date Submitted</label>
                <input id="editReportDate" name="date_submitted" type="datetime-local" class="sd-input" required>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportLocation">Location</label>
                <input id="editReportLocation" name="location_address" type="text" class="sd-input" required>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportType">Report Type</label>
                <select id="editReportType" name="report_type" class="sd-input" required>
                  <option value="stray">Stray</option>
                  <option value="rabies">Rabies</option>
                  <option value="injured">Injured</option>
                  <option value="dead">Dead</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportUrgency">Urgency level</label>
                <select id="editReportUrgency" name="urgency_level" class="sd-input">
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                  <option value="critical">Critical</option>
                </select>
              </div>
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="editReportBarangay">Barangay</label>
                <select id="editReportBarangay" name="barangay_id" class="sd-input" required>
                  <option value="">Select a barangay</option>
                  <?php foreach ($cpdBarangays as $b): ?>
                    <option value="<?php echo (int)$b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="sd-form-section">
            <div class="sd-form-section-title">Animal Information</div>
            <div class="sd-field-grid">
              <div class="sd-field">
                <label class="sd-label" for="editReportAnimalType">Animal Type</label>
                <select id="editReportAnimalType" name="animal_type" class="sd-input">
                  <option value="">Select type</option>
                  <option value="Dog">Dog</option>
                  <option value="Cat">Cat</option>
                  <option value="Bird">Bird</option>
                  <option value="Horse">Horse</option>
                  <option value="Goat">Goat</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportAnimalBreed">Breed / Appearance</label>
                <input id="editReportAnimalBreed" name="animal_breed" type="text" class="sd-input" placeholder="e.g., Aspin, brown fur, medium size">
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportAnimalCount">Estimated Number of Animals</label>
                <select id="editReportAnimalCount" name="animal_count" class="sd-input">
                  <option value="">How many?</option>
                  <option value="1">1</option>
                  <option value="2-3">2–3</option>
                  <option value="4-5">4–5</option>
                  <option value="6+">6 or more</option>
                </select>
              </div>
              <div class="sd-field">
                <label class="sd-label" for="editReportAnimalBehavior">Animal Behavior</label>
                <select id="editReportAnimalBehavior" name="animal_behavior" class="sd-input">
                  <option value="">Select behavior</option>
                  <option value="Calm / Resting">Calm / Resting</option>
                  <option value="Fearful / Hiding">Fearful / Hiding</option>
                  <option value="Aggressive">Aggressive</option>
                  <option value="Injured / Limping">Injured / Limping</option>
                  <option value="Erratic / Disoriented">Erratic / Disoriented</option>
                  <option value="Deceased">Deceased</option>
                </select>
              </div>
            </div>
          </div>
          <div class="sd-form-section">
            <div class="sd-form-section-title">Incident Details</div>
            <div class="sd-field-grid">
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="editReportDesc">Description</label>
                <textarea id="editReportDesc" name="description" class="sd-input" rows="3" required></textarea>
              </div>
            </div>
          </div>
          <div class="sd-form-section">
            <div class="sd-form-section-title">Audited Correction</div>
            <div class="sd-field-grid">
              <div class="sd-field sd-field-full">
                <label class="sd-label" for="editReportCorrectionReason">Reason for correction</label>
                <textarea id="editReportCorrectionReason" name="correction_reason" class="sd-input" rows="3" maxlength="2000" required></textarea>
              </div>
            </div>
          </div>
        </div>
        <div class="sd-dialog-footer">
          <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('editReportModal')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-default">Save changes</button>
        </div>
      </div>
    </form>
  </div>

  <script>
  var RPT_ITEMS = <?php echo json_encode($reportItems, $jsonFlags); ?>;
  </script>
  <?php $barangayResponseEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1'; ?>
  <script>window.DCVS_BARANGAY_RESPONSE_ENABLED = <?php echo $barangayResponseEnabled ? 'true' : 'false'; ?>; window.DCVS_RESPONSE_ROLE = 'staff'; window.DCVS_RESPONSE_CSRF = <?php echo json_encode(csrfToken()); ?>;</script>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/reports-database.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/reports-database.js'); ?>"></script>

  <!-- �"?�"? REPORTS: PENDING CASES �"?�"? -->
  <section id="reports-pending" class="content-section staff-report-section">
    <?php if (isset($_SESSION['report_error'])): ?>
      <div class="toast toast-error" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:6px;margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['report_error']); unset($_SESSION['report_error']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['report_success'])): ?>
      <div class="toast toast-success" style="background:#eef6f0;border:1px solid #b8d1c0;color:#045e1f;padding:10px 14px;border-radius:6px;margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['report_success']); unset($_SESSION['report_success']); ?></div>
    <?php endif; ?>
    <div class="cpd reports-fullpage">
      <div class="cpd-app">

        <!-- Main layout -->
        <div class="layout">

          <!-- Center: Database -->
          <div class="panel db-panel">
            <!-- Overview KPIs -->
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Total Pending</p>
                <div class="kpi-value"><?php echo $pendingCounts['all']; ?></div>
                <p class="kpi-sub">Awaiting DCVS action</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">High Priority</p>
                <div class="kpi-value"><?php echo $pendingCounts['high']; ?></div>
                <p class="kpi-sub">Needs urgent review</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">New This Week</p>
                <div class="kpi-value"><?php echo $pendingCounts['week']; ?></div>
                <p class="kpi-sub">Validated in 7 days</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Pending DCVS Review</p>
                <div class="kpi-value"><?php echo $pendingCounts['awaiting']; ?></div>
                <p class="kpi-sub">Barangay-verified</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar staff-report-toolbar staff-mobile-toolbar">
                <div class="staff-toolbar-search-row">
                <div class="search-grow-wrap staff-toolbar-search">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="penMainSearch" placeholder="Search pending reports..." oninput="penSetSearch(this.value)">
                </div>
                <div class="staff-date-filter staff-toolbar-filter" data-date-filter="pen">
                  <button class="staff-date-filter-button" type="button" aria-label="Filter pending reports by date" title="Filter by date" aria-expanded="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                  </button>
                  <input type="hidden" id="penDateFrom" value="">
                  <input type="hidden" id="penDateTo" value="">
                </div>
                </div>
                <div class="toolbar-filters staff-report-filters staff-toolbar-filters">
                    <select class="select-input tf-select" id="penType" onchange="penSetType(this.value)">
                      <option value="">All Types</option>
                      <option value="stray">Stray Animal</option>
                      <option value="rabies">Rabies</option>
                      <option value="injured">Injured Animal</option>
                      <option value="dead">Dead Animal</option>
                      <option value="rescue">Rescue</option>
                    </select>
                    <select class="select-input tf-select" id="penBrgy" onchange="penSetBrgy(this.value)">
                      <option value="">All Barangays</option>
                      <?php foreach ($cpdBarangays as $b): ?>
                        <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                </div>
              </div>
              <div class="db-table-area staff-report-table-area">
                <div id="penTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                <table>
                  <thead id="penTableHead"></thead>
                  <tbody id="penRows"></tbody>
                </table>
                </div>
              </div>
              <div id="penCardsWrap" class="cards-grid" style="display:none;"></div>
              <div id="penMapWrap" style="display:none;">
                <div class="map-placeholder">
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                  <p>Map view is coming soon. Geo-mapping will show pending reports distributed across Dasmariñas.</p>
                </div>
              </div>
            </div>

            <div class="dt-pagination">
              <div class="dt-pagination-right">
                <div class="dt-rows">
                  <span>Rows per page</span>
                  <select onchange="window.penSetPerPage(this.value)">
                    <option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option>
                  </select>
                </div>
                <div class="dt-page-label" id="penPageLabel">Page 1 of 1</div>
                <div class="dt-page-btns">
                  <button class="dt-page-btn" id="penPageFirst" title="Go to first page" onclick="window.penGoPage('first')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
                  <button class="dt-page-btn" id="penPagePrev" title="Go to previous page" onclick="window.penGoPage('prev')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
                  <button class="dt-page-btn" id="penPageNext" title="Go to next page" onclick="window.penGoPage('next')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
                  <button class="dt-page-btn" id="penPageLast" title="Go to last page" onclick="window.penGoPage('last')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Pending Case Details Drawer -->
  <div id="penDetailsModal" class="modal cpd cd-drawer staff-detail-drawer">
    <div class="sd-dialog">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('penDetailsModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Pending Case Details</h2>
          <p class="sd-dialog-description">Review the pending report details.</p>
        </div>
        <div class="sd-field-group">
          <div id="penDetailContent"></div>
        </div>
      </div>
    </div>
  </div>

  <div id="penEvidenceViewer" class="pen-evidence-viewer" role="dialog" aria-modal="true" aria-label="Barangay field evidence viewer" onclick="if(event.target===this)closePenEvidenceViewer()">
    <div class="sd-dialog-content pen-evidence-dialog" role="document">
      <div id="penEvidenceStage" class="pen-evidence-stage"></div>
      <div class="pen-evidence-controls">
        <button type="button" class="sd-btn sd-btn-outline pen-evidence-button" id="penEvidencePrev" onclick="stepPenEvidence(-1)">Prev</button>
        <div class="pen-evidence-counter" id="penEvidenceCounter" aria-live="polite"></div>
        <button type="button" class="sd-btn sd-btn-outline pen-evidence-button" id="penEvidenceNext" onclick="stepPenEvidence(1)">Next</button>
      </div>
      <div class="pen-evidence-close-row">
        <button type="button" class="sd-btn sd-btn-outline pen-evidence-button" onclick="closePenEvidenceViewer()">Close</button>
      </div>
    </div>
  </div>

  <script>
  var PENDING_ITEMS = <?php echo json_encode($pendingItems, $jsonFlags); ?>;
  var penBarangayEvidenceItems = [];
  var penBarangayEvidenceIndex = 0;
  var penEvidenceReturnFocus = null;

  function casEsc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character];
    });
  }

  function renderPenEvidenceViewerItem() {
    var item = penBarangayEvidenceItems[penBarangayEvidenceIndex];
    var stage = document.getElementById('penEvidenceStage');
    if (!item || !stage) return;
    stage.textContent = '';
    var media;
    if (item.type === 'video') {
      media = document.createElement('video');
      media.controls = true;
      media.playsInline = true;
      media.preload = 'metadata';
      media.setAttribute('aria-label', 'Barangay field video evidence');
    } else {
      media = document.createElement('img');
      media.alt = 'Barangay field photo evidence';
    }
    media.src = item.url;
    stage.appendChild(media);
    document.getElementById('penEvidenceCounter').textContent =
      (item.type === 'video' ? 'Video ' : 'Photo ') +
      (penBarangayEvidenceIndex + 1) + ' of ' + penBarangayEvidenceItems.length + ' (barangay)';
    document.getElementById('penEvidencePrev').disabled = penBarangayEvidenceIndex === 0;
    document.getElementById('penEvidenceNext').disabled = penBarangayEvidenceIndex === penBarangayEvidenceItems.length - 1;
  }

  function penViewBarangayEvidence(index) {
    if (!penBarangayEvidenceItems.length || index < 0 || index >= penBarangayEvidenceItems.length) return;
    penBarangayEvidenceIndex = index;
    penEvidenceReturnFocus = document.activeElement;
    renderPenEvidenceViewerItem();
    document.getElementById('penEvidenceViewer').classList.add('open');
    document.getElementById('penEvidencePrev').focus();
  }

  function stepPenEvidence(direction) {
    var nextIndex = penBarangayEvidenceIndex + direction;
    if (nextIndex < 0 || nextIndex >= penBarangayEvidenceItems.length) return;
    penBarangayEvidenceIndex = nextIndex;
    renderPenEvidenceViewerItem();
  }

  function closePenEvidenceViewer() {
    var viewer = document.getElementById('penEvidenceViewer');
    if (!viewer) return;
    viewer.classList.remove('open');
    document.getElementById('penEvidenceStage').textContent = '';
    if (penEvidenceReturnFocus && typeof penEvidenceReturnFocus.focus === 'function') {
      penEvidenceReturnFocus.focus();
    }
    penEvidenceReturnFocus = null;
  }

  document.addEventListener('keydown', function(event) {
    if (!document.getElementById('penEvidenceViewer').classList.contains('open')) return;
    if (event.key === 'Escape') closePenEvidenceViewer();
    else if (event.key === 'ArrowLeft') stepPenEvidence(-1);
    else if (event.key === 'ArrowRight') stepPenEvidence(1);
  });

  function casResponseHistoryEntries(r) {
    return (Array.isArray(r.responseHistory) ? r.responseHistory : []).map(function(entry) {
      return {
        t: String(entry.action || 'Case update').replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); }),
        a: entry.actor || 'DCVS',
        d: [entry.createdAt, entry.reason].filter(Boolean).join(' · ')
      };
    });
  }

  function casReportCaseEvidence(r) {
    var evidence = (Array.isArray(r.brgyValidationEvidence) ? r.brgyValidationEvidence : []).slice();
    if (r.photo) evidence.push({ type: 'photo', url: r.photo, stage: 'resident', caption: '', role: 'resident' });
    if (r.video) evidence.push({ type: 'video', url: r.video, stage: 'resident', caption: '', role: 'resident' });
    return evidence;
  }

  function casEvidenceCaption(item) {
    var stageCaptions = {
      barangay_validation: 'Barangay validation',
      barangay_secured: 'Animal secured',
      barangay_turnover: 'Animal turnover'
    };
    return item.caption || stageCaptions[item.stage] || (item.type === 'video' ? 'Report video' : 'Case photo evidence');
  }

  function casReportCaseUpdatesHtml(r) {
    var evidence = casReportCaseEvidence(r);
    var evidenceByHistoryId = {};
    evidence.forEach(function(item, index) {
      var historyId = Number(item.responseHistoryId || 0);
      if (historyId > 0) {
        if (!evidenceByHistoryId[historyId]) evidenceByHistoryId[historyId] = [];
        evidenceByHistoryId[historyId].push({ item: item, index: index });
      }
    });
    penBarangayEvidenceItems = evidence;

    var updates = (Array.isArray(r.responseHistory) ? r.responseHistory : [])
      .filter(function(entry) { return entry.action !== 'report_submitted'; })
      .map(function(entry) { return entry; });
    var historyActions = updates.map(function(entry) { return entry.action; });
    var equivalentHistoryActions = {
      report_resolved: ['resolve', 'resolve_barangay'],
      report_validated: ['accept_barangay_assignment', 'forward_to_dcvs_review'],
      report_scheduled: ['schedule_report']
    };
    [
      { action: 'report_resolved', date: r.resolved, rawDate: r.resolvedRaw, actor: 'DCVS Staff', actorRole: 'staff', reason: 'The report was marked resolved.' },
      { action: 'report_scheduled', date: r.scheduled, rawDate: r.scheduledRaw, actor: 'DCVS Staff', actorRole: 'staff', reason: 'The report was scheduled for action.' },
      { action: 'report_confirmed', date: r.confirmed, rawDate: r.confirmedRaw, actor: 'DCVS Staff', actorRole: 'staff', reason: 'DCVS confirmed this report for handling.' },
      { action: 'report_validated', date: r.validated, rawDate: r.validatedRaw, actor: 'Barangay', actorRole: 'barangay', reason: 'The barangay reviewed this report.' }
    ].forEach(function(entry) {
      var equivalentActions = equivalentHistoryActions[entry.action] || [];
      if (!entry.date || historyActions.indexOf(entry.action) !== -1 ||
          equivalentActions.some(function(action) { return historyActions.indexOf(action) !== -1; })) return;
      updates.push({
        id: 0,
        action: entry.action,
        actor: entry.actor,
        actorRole: entry.actorRole,
        toState: '',
        reason: entry.reason,
        createdAt: entry.date,
        createdAtRaw: entry.rawDate || ''
      });
    });
    updates.push({
      action: 'report_submitted',
      actor: r.reporter || 'Resident',
      actorRole: 'resident',
      toState: '',
      reason: '',
      createdAt: r.submitted || '',
      createdAtRaw: r.createdAt || ''
    });
    updates.sort(function(a, b) {
      return new Date(b.createdAtRaw || b.createdAt || 0).getTime() -
        new Date(a.createdAtRaw || a.createdAt || 0).getTime();
    });

    var stateLabels = {
      awaiting_dcvs_decision: 'Forwarded to DCVS',
      dcvs_dispatch: 'DCVS dispatch',
      barangay_response_approved: 'Accepted by Barangay',
      barangay_responding: 'Response started',
      animal_secured: 'Animal secured',
      awaiting_turnover: 'Awaiting turnover',
      turned_over: 'Turned over to DCVS',
      dcvs_processing: 'DCVS processing',
      unable_to_locate: 'Unable to locate',
      escalated_to_dcvs: 'Escalated to DCVS',
      returned_to_barangay: 'Returned to Barangay',
      closed_not_actionable: 'Closed as not actionable',
      resolved: 'Resolved'
    };
    var actionLabels = {
      add_photo_evidence: 'Photo evidence added',
      report_correction: 'Report details updated',
      report_submitted: 'Report submitted',
      report_resolved: 'Resolved by DCVS',
      report_scheduled: 'Scheduled for action',
      report_confirmed: 'Confirmed by DCVS',
      report_validated: 'Reviewed by Barangay',
      approve_barangay_response: 'Accepted by Barangay',
      accept_barangay_assignment: 'Accepted by Barangay',
      forward_to_dcvs_review: 'Forwarded to DCVS',
      start_response: 'Response started',
      record_site_visit: 'Site visit recorded',
      update_progress: 'Progress update',
      secure_animal: 'Animal secured',
      mark_secured: 'Animal secured',
      set_handover_plan: 'Handover plan set or updated',
      confirm_turnover: 'Animal turnover confirmed',
      turnover_overdue: 'Animal turnover overdue',
      begin_processing: 'DCVS processing started',
      resolve: 'Report resolved',
      resolve_barangay: 'Resolved by Barangay'
    };
    var legacyActionBySource = {
      barangay_validation: ['accept_barangay_assignment', 'forward_to_dcvs_review', 'report_validated'],
      barangay_secured: ['mark_secured', 'secure_animal'],
      barangay_turnover: ['confirm_turnover'],
      resident: ['report_submitted']
    };
    var attachedIndices = Object.create(null);
    function mediaForUpdate(entry) {
      var linked = evidenceByHistoryId[Number(entry.id || 0)] || [];
      if (linked.length) {
        linked.forEach(function(photo) { attachedIndices[photo.index] = true; });
        return linked;
      }
      var matched = [];
      evidence.forEach(function(item, index) {
        if (attachedIndices[index]) return;
        var sourceActions = legacyActionBySource[item.stage] || [];
        if (sourceActions.indexOf(entry.action) !== -1) {
          attachedIndices[index] = true;
          matched.push({ item: item, index: index });
        }
      });
      return matched;
    }

    var updateHtml = updates.map(function(entry) {
      var historyId = Number(entry.id || 0);
      var action = String(entry.action || '');
      var title = actionLabels[action] || stateLabels[entry.toState] ||
        action.replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); }) ||
        'Case update';
      var copy = entry.reason || (action === 'report_submitted'
        ? 'The resident submitted this report.'
        : (action === 'add_photo_evidence'
          ? 'Photo evidence was added to this report.'
          : (entry.toState && stateLabels[entry.toState]
            ? 'The case status was updated to ' + stateLabels[entry.toState] + '.'
            : 'A case update was recorded.')));
      var actorRole = String(entry.actorRole || '').toLowerCase();
      var actorLabel = actorRole === 'system'
        ? 'System'
        : (actorRole === 'barangay'
        ? 'Brgy. ' + (r.brgy || 'Barangay')
        : (actorRole === 'resident' ? 'Resident' : 'DCVS'));
      var photos = mediaForUpdate(entry);
      var photosHtml = photos.length
        ? '<div class="rpt-updates-photo-grid">' + photos.map(function(photo) {
            var caption = casEvidenceCaption(photo.item);
            var media = photo.item.type === 'video'
              ? '<video src="' + casEsc(photo.item.url || '') + '" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>'
              : '<img src="' + casEsc(photo.item.url || '') + '" alt="' + casEsc(caption) + '" loading="lazy">';
            return '<div><button type="button" class="rpt-updates-photo" onclick="penViewBarangayEvidence(' + photo.index + ')" aria-label="View case evidence: ' + casEsc(caption) + '">' +
              media + '</button>' +
              '<p class="rpt-updates-caption">' + casEsc(caption) + '</p>' +
              '</div>';
          }).join('') + '</div>'
        : '';
      return '<article class="rpt-updates-entry"><div class="rpt-updates-entry-head">' +
        '<span class="rpt-updates-entry-title">' + casEsc(title) + '</span>' +
        '<time class="rpt-updates-entry-date">' + casEsc(entry.createdAt || '') + '</time></div>' +
        '<span class="rpt-updates-entry-actor">' + casEsc(actorLabel) + '</span>' +
        '<p class="rpt-updates-entry-copy">' + casEsc(copy) + '</p>' + photosHtml +
        '</article>';
    }).join('');

    var unlinkedPhotos = evidence.map(function(item, index) {
      return attachedIndices[index] ? null : { item: item, index: index };
    }).filter(Boolean);
    var unlinkedHtml = unlinkedPhotos.length
      ? '<article class="rpt-updates-entry"><div class="rpt-updates-entry-head"><span class="rpt-updates-entry-title">Additional attachments</span></div>' +
        '<span class="rpt-updates-entry-actor">DCVS</span><div class="rpt-updates-photo-grid">' +
        unlinkedPhotos.map(function(photo) {
          var caption = casEvidenceCaption(photo.item);
          var media = photo.item.type === 'video'
            ? '<video src="' + casEsc(photo.item.url || '') + '" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>'
            : '<img src="' + casEsc(photo.item.url || '') + '" alt="' + casEsc(caption) + '" loading="lazy">';
          return '<div><button type="button" class="rpt-updates-photo" onclick="penViewBarangayEvidence(' + photo.index + ')" aria-label="View case evidence: ' + casEsc(caption) + '">' +
            media + '</button>' +
            '<p class="rpt-updates-caption">' + casEsc(caption) + '</p></div>';
        }).join('') + '</div></article>'
      : '';
    return updateHtml || unlinkedHtml
      ? '<div class="rpt-section-title">Case updates</div><div class="rpt-updates-timeline">' + updateHtml + unlinkedHtml + '</div>'
      : '<div class="rpt-section-title">Case updates</div><p class="rpt-updates-empty">No case updates have been recorded yet.</p>';
  }
  window.casReportCaseUpdatesHtml = casReportCaseUpdatesHtml;

  function casResponseWorkflowDetails(r, options) {
    options = options || {};
    if (!window.DCVS_BARANGAY_RESPONSE_ENABLED || !r.responseState || r.responseState === 'awaiting_dcvs_decision') return '';
    var labels = {
      awaiting_dcvs_decision: 'Awaiting DCVS response decision', dcvs_dispatch: 'DCVS dispatch',
      barangay_response_approved: 'Barangay response approved', barangay_responding: 'Barangay responding',
      animal_secured: 'Animal secured by barangay', awaiting_turnover: 'Awaiting DCVS turnover',
      turned_over: 'Turned over to DCVS', dcvs_processing: 'DCVS processing',
      unable_to_locate: 'Unable to locate', escalated_to_dcvs: 'Escalated to DCVS',
      returned_to_barangay: 'Returned to barangay', closed_not_actionable: 'Closed — not actionable',
      resolved: 'Resolved'
    };
    var overdue = (
      ['barangay_response_approved', 'barangay_responding'].indexOf(r.responseState) !== -1
      && r.responseDeadline
      && new Date(r.responseDeadline.replace(' ', 'T')).getTime() < Date.now()
    ) || (
      r.responseState === 'awaiting_turnover'
      && r.turnoverDeadline
      && new Date(r.turnoverDeadline.replace(' ', 'T')).getTime() < Date.now()
    );
    var turnoverComplete = r.statusRaw === 'resolved' && r.turnoverStatus === 'receipt_confirmed';
    var turnoverRequired = typeof window.responseWorkflowNeedsHandover === 'function'
      && window.responseWorkflowNeedsHandover(r);
    var overdueTurnover = r.statusRaw === 'resolved' && r.turnoverStatus === 'pending'
      && r.turnoverDueAt && new Date(r.turnoverDueAt.replace(' ', 'T')).getTime() < Date.now();
    var stepsHtml = '';
    if (!options.hideProgress) {
      var completedStage = r.statusRaw === 'resolved'
        ? (turnoverRequired ? (turnoverComplete ? 5 : 4) : 3)
        : (r.responseState === 'barangay_response_approved' ? 0
          : (r.responseState === 'barangay_responding' || r.responseState === 'escalated_to_dcvs' ? 1
            : (r.responseState === 'animal_secured' ? 2 : -1)));
      var steps = ['Accepted', 'Responding', 'Secured', 'Resolved'];
      if (turnoverRequired) steps.push(turnoverComplete ? 'Turned over to DCVS' : 'Turned over');
      stepsHtml = '<div class="response-steps" aria-label="Barangay response progress">' + steps.map(function(label, index) {
        var stepClass = index < completedStage ? ' is-complete'
          : (index === completedStage ? ((overdue && index === 1 || overdueTurnover && index === 4) ? ' is-current is-overdue' : ' is-current') : '');
        var check = index < completedStage
          ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>'
          : '';
        return '<div class="response-step' + stepClass + '"><span class="response-step-dot" aria-hidden="true">' + check + '</span><span>' + label + '</span></div>';
      }).join('') + '</div>';
    }
    var handoverHtml = typeof window.responseWorkflowHandoverHtml === 'function'
      ? window.responseWorkflowHandoverHtml(r, { excludeReceipt: turnoverComplete }) : '';
    return handoverHtml + '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Response &amp; Custody</div>' + stepsHtml + '<div class="info-rows info-rows-2col">' +
      casInfoRow('Response Status', labels[r.responseState] || r.responseState) +
      casInfoRow('Response Mode', r.responseMode === 'barangay_response'
        ? 'Barangay Response'
        : (r.responseMode === 'dcvs_dispatch' ? 'DCVS Dispatch' : 'Undecided')) +
      (r.holdingLocation ? casInfoRow('Holding Location', r.holdingLocation) : '') +
      (r.animalCondition ? casInfoRow('Animal Condition', r.animalCondition) : '') +
      (r.turnoverRequestedAt ? casInfoRow('Pickup Requested', r.turnoverRequestedAt) : '') +
      '</div>' + (overdue ? '<div role="alert" style="margin-top:10px;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:13px;">Response deadline exceeded — follow up is required.</div>' : '') +
      '</div>';
  }

  function casBarangayValidationDetails(r, pendingReviewStyle) {
    var labels = {
      site_visit_pending: 'Site visit pending',
      confirmed_on_site: 'Confirmed on site',
      urgent_response: 'Needs urgent response',
      animal_already_gone: 'Animal already gone',
      wrong_or_unclear_location: 'Wrong / unclear location'
    };
    var evidence = Array.isArray(r.brgyValidationEvidence) ? r.brgyValidationEvidence : [];
    var hasFieldStatus = !!r.brgyValidationStatus;
    var validationTime = r.validated || 'Awaiting barangay validation';
    var workflowLabels = {
      awaiting_dcvs_decision: 'Forwarded to DCVS',
      dcvs_dispatch: 'DCVS dispatch',
      barangay_response_approved: 'Response approved',
      barangay_responding: 'Barangay responding',
      animal_secured: 'Animal secured',
      awaiting_turnover: 'Awaiting turnover',
      turned_over: 'Turned over',
      dcvs_processing: 'DCVS processing',
      unable_to_locate: 'Unable to locate',
      escalated_to_dcvs: 'Escalated to DCVS',
      returned_to_barangay: 'Returned to barangay',
      closed_not_actionable: 'Closed — not actionable',
      resolved: 'Resolved'
    };
    var currentActionStatus = workflowLabels[r.responseState] ||
      ({ confirmed: 'In progress', scheduled: 'In progress', resolved: 'Resolved', rejected: 'Closed' }[r.statusRaw] || '');
    var statusLabel = pendingReviewStyle
      ? 'Pending Barangay Review'
      : (currentActionStatus || labels[r.brgyValidationStatus] ||
        (r.statusRaw === 'validated' ? 'Validation details unavailable' : 'Awaiting barangay validation'));
    var responseHistory = Array.isArray(r.responseHistory) ? r.responseHistory : [];
    var latestWorkflowEntry = null;
    for (var historyIndex = responseHistory.length - 1; historyIndex >= 0; historyIndex--) {
      if (r.responseState && responseHistory[historyIndex].toState === r.responseState) {
        latestWorkflowEntry = responseHistory[historyIndex];
        break;
      }
    }
    var statusClass = 'style="color:#045e1f;background:#eef6f0;border:1px solid #b8d1c0;"';
    if (pendingReviewStyle) {
      statusClass = 'style="color:#92400e;background:#fef3c7;border:1px solid #fde68a;"';
    } else if (currentActionStatus === 'Resolved' || currentActionStatus === 'Response approved') {
      statusClass = 'style="color:#ffffff;background:#0E8131;border:1px solid #0E8131;"';
    } else if (currentActionStatus === 'Closed' || currentActionStatus === 'Unable to locate' || currentActionStatus === 'Closed — not actionable') {
      statusClass = 'style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;"';
    } else if (currentActionStatus) {
      statusClass = 'style="color:#175CD3;background:#eff6ff;border:1px solid #bfdbfe;"';
    } else if (r.brgyValidationStatus === 'site_visit_pending') {
      statusClass = 'style="color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;"';
    } else if (r.brgyValidationStatus === 'urgent_response') {
      statusClass = 'style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;"';
    } else if (r.brgyValidationStatus === 'wrong_or_unclear_location') {
      statusClass = 'style="color:#92400e;background:#fffbeb;border:1px solid #fde68a;"';
    } else if (r.brgyValidationStatus === 'animal_already_gone') {
      statusClass = 'style="color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;"';
    }
    penBarangayEvidenceItems = evidence;
    var evidenceHtml = evidence.length
      ? '<div><div class="pen-validation-label">Barangay evidence (' + evidence.length + ')</div>' +
        '<div class="pen-validation-evidence-grid">' + evidence.map(function(item) {
          var url = casEsc(item.url || '');
          var index = evidence.indexOf(item);
          if (item.type === 'video') {
            return '<button type="button" class="pen-validation-evidence" onclick="penViewBarangayEvidence(' + index + ')" aria-label="View barangay field video evidence"><video src="' + url + '" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video></button>';
          }
          return '<button type="button" class="pen-validation-evidence" onclick="penViewBarangayEvidence(' + index + ')" aria-label="View barangay field photo evidence"><img src="' + url + '" alt="" aria-hidden="true"></button>';
        }).join('') + '</div></div>'
      : '';
    var proposalBanner = window.DCVS_BARANGAY_RESPONSE_ENABLED
      && r.barangayProposed
      && r.responseState === 'awaiting_dcvs_decision'
      ? '<div class="pen-response-proposal" role="status"><span aria-hidden="true">&#10003;</span><div><strong>Barangay is requesting to respond.</strong><span>Brgy. ' +
        casEsc(r.brgy || 'Barangay') +
        ' checked "I can respond to this case". This report was forwarded to DCVS for review and assignment.</span></div></div>'
      : '';
    var workflowMessageRows = [];
    if (currentActionStatus) {
      workflowMessageRows.push({ label: 'Current status', value: currentActionStatus });
    }
    if (r.responseState === 'resolved' || r.statusRaw === 'resolved') {
      if (r.resolutionDetails || r.caseNotes) {
        workflowMessageRows.push({ label: 'Resolution', value: r.resolutionDetails || r.caseNotes });
      }
      if (r.animalDisposition) {
        workflowMessageRows.push({ label: 'Animal disposition', value: r.animalDisposition });
      }
    }
    if (latestWorkflowEntry && latestWorkflowEntry.reason) {
      workflowMessageRows.push({
        label: r.responseState === 'resolved' || r.statusRaw === 'resolved' ? 'Additional update' : 'Action details',
        value: latestWorkflowEntry.reason
      });
    }
    var workflowMessageMeta = latestWorkflowEntry
      ? [latestWorkflowEntry.actor, latestWorkflowEntry.createdAt].filter(Boolean).join(' · ')
      : ([r.resolutionActor, r.responseResolvedAt].filter(Boolean).join(' · '));
    var workflowMessageHtml = workflowMessageRows.map(function(row) {
      return '<div><span class="pen-validation-message-field-label">' + casEsc(row.label) + ':</span> <span class="pen-validation-message-field-value">' + casEsc(row.value) + '</span></div>';
    }).join('');
    return '<section class="pen-validation-card" aria-label="Barangay Validation">' +
      '<div class="pen-validation-heading">' +
      '<svg class="pen-validation-heading-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/></svg>' +
      '<div class="pen-validation-heading-copy"><div class="pen-validation-title">Barangay Validation</div>' +
      '<div class="pen-validation-meta">' + casEsc(r.brgy ? 'Brgy. ' + r.brgy + ' · ' : '') + casEsc(validationTime) + '</div></div>' +
      (pendingReviewStyle ? '' : '<span class="pen-validation-status" ' + statusClass + '>' + casEsc(statusLabel) + '</span>') +
      '</div><div class="pen-validation-content">' +
      (workflowMessageHtml
        ? '<div><div class="pen-validation-label">Latest case update' + (workflowMessageMeta ? ' · ' + casEsc(workflowMessageMeta) : '') + '</div><div class="pen-validation-message pen-validation-message-fields">' + workflowMessageHtml + '</div></div>'
        : '') +
      ((r.brgyValidationNotes || r.brgyNotes)
        ? '<div><div class="pen-validation-label">' + (workflowMessageHtml ? 'Barangay validation message' : 'Message from barangay') + '</div><div class="pen-validation-message">' + casEsc(r.brgyValidationNotes || r.brgyNotes) + '</div></div>'
        : (!hasFieldStatus
          ? '<div class="pen-validation-message">' + (r.statusRaw === 'validated' ? 'Field validation details are not available for this report.' : 'This report is awaiting barangay field validation.') + '</div>'
          : '')) +
      proposalBanner +
      evidenceHtml +
      '</div></section>';
  }

  </script>

  <!-- Barangay-handled response queue -->
  <section id="reports-accepted" class="content-section staff-report-section">
    <div class="cpd reports-fullpage">
      <div class="cpd-app">
        <div class="layout">
          <div class="panel db-panel">
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Total Cases</p>
                <div class="kpi-value"><?php echo $barangayResponseCounts['all']; ?></div>
                <p class="kpi-sub">Currently handled by Barangay</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Accepted</p>
                <div class="kpi-value"><?php echo $barangayResponseCounts['accepted']; ?></div>
                <p class="kpi-sub">Awaiting response</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Responding</p>
                <div class="kpi-value"><?php echo $barangayResponseCounts['responding']; ?></div>
                <p class="kpi-sub">Barangay team dispatched</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Animal Secured</p>
                <div class="kpi-value"><?php echo $barangayResponseCounts['secured']; ?></div>
                <p class="kpi-sub">In Barangay custody</p>
              </div>
            </div>
            <div class="db-toolbar staff-report-toolbar staff-mobile-toolbar">
              <div class="staff-toolbar-search-row">
              <div class="search-grow-wrap staff-toolbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="brgMainSearch" placeholder="Search Barangay cases..." oninput="brgSetSearch(this.value)">
              </div>
              <div class="staff-date-filter staff-toolbar-filter" data-date-filter="brg">
                <button class="staff-date-filter-button" type="button" aria-label="Filter accepted cases by date" title="Filter by date" aria-expanded="false">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                </button>
                <input type="hidden" id="brgDateFrom" value="">
                <input type="hidden" id="brgDateTo" value="">
              </div>
              </div>
              <div class="toolbar-filters staff-report-filters staff-toolbar-filters">
                <select class="select-input tf-select" id="brgType" onchange="brgSetType(this.value)">
                  <option value="">All Types</option>
                  <option value="stray">Stray Animal</option>
                  <option value="rabies">Rabies</option>
                  <option value="injured">Injured Animal</option>
                  <option value="dead">Dead Animal</option>
                  <option value="rescue">Rescue</option>
                </select>
                <select class="select-input tf-select" id="brgBrgy" onchange="brgSetBrgy(this.value)">
                  <option value="">All Barangays</option>
                  <?php foreach ($cpdBarangays as $b): ?>
                    <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="db-table-area staff-report-table-area">
              <div id="brgTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                  <table><thead id="brgTableHead"></thead><tbody id="brgRows"></tbody></table>
                </div>
              </div>
              <div id="brgCardsWrap" class="cards-grid" style="display:none;"></div>
              <div id="brgMapWrap" style="display:none;"></div>
            </div>
            <div class="dt-pagination">
              <div class="dt-pagination-right">
                <div class="dt-rows"><span>Rows per page</span><select onchange="window.brgSetPerPage(this.value)"><option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option></select></div>
                <div class="dt-page-label" id="brgPageLabel">Page 1 of 1</div>
                <div class="dt-page-btns">
                  <button class="dt-page-btn" id="brgPageFirst" title="Go to first page" onclick="window.brgGoPage('first')">«</button>
                  <button class="dt-page-btn" id="brgPagePrev" title="Go to previous page" onclick="window.brgGoPage('prev')">‹</button>
                  <button class="dt-page-btn" id="brgPageNext" title="Go to next page" onclick="window.brgGoPage('next')">›</button>
                  <button class="dt-page-btn" id="brgPageLast" title="Go to last page" onclick="window.brgGoPage('last')">»</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div id="brgDetailsModal" class="modal cpd cd-drawer staff-detail-drawer">
    <div class="sd-dialog">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('brgDetailsModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 6 6"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Accepted by Barangay</h2>
          <p class="sd-dialog-description">Barangay response and custody updates for this report.</p>
        </div>
        <div class="sd-field-group"><div id="brgDetailContent"></div></div>
        <div class="sd-dialog-footer" id="brgDrawerFooter"></div>
      </div>
    </div>
  </div>

  <!-- �"?�"? REPORTS: ACTIVE CASES �"?�"? -->
  <section id="reports-active" class="content-section staff-report-section">
    <div class="cpd reports-fullpage">
      <div class="cpd-app">

        <!-- Main layout -->
        <div class="layout">

          <!-- Center: Database -->
          <div class="panel db-panel">
            <!-- Overview KPIs -->
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Total Active</p>
                <div class="kpi-value"><?php echo $activeCounts['all']; ?></div>
                <p class="kpi-sub">Currently being handled</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">High Priority</p>
                <div class="kpi-value"><?php echo $activeCounts['high']; ?></div>
                <p class="kpi-sub">Needs urgent action</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Scheduled</p>
                <div class="kpi-value"><?php echo $activeCounts['sched']; ?></div>
                <p class="kpi-sub">Have a set schedule</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Overdue</p>
                <div class="kpi-value"><?php echo $activeCounts['overdue']; ?></div>
                <p class="kpi-sub">Past scheduled date</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar staff-report-toolbar staff-mobile-toolbar">
                <div class="staff-toolbar-search-row">
                <div class="search-grow-wrap staff-toolbar-search">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="actMainSearch" placeholder="Search active cases..." oninput="actSetSearch(this.value)">
                </div>
                <div class="staff-date-filter staff-toolbar-filter" data-date-filter="act">
                  <button class="staff-date-filter-button" type="button" aria-label="Filter active cases by date" title="Filter by date" aria-expanded="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                  </button>
                  <input type="hidden" id="actDateFrom" value="">
                  <input type="hidden" id="actDateTo" value="">
                </div>
                </div>
                <div class="toolbar-filters staff-report-filters staff-toolbar-filters">
                    <select class="select-input tf-select" id="actType" onchange="actSetType(this.value)">
                      <option value="">All Types</option>
                      <option value="stray">Stray Animal</option>
                      <option value="rabies">Rabies</option>
                      <option value="injured">Injured Animal</option>
                      <option value="dead">Dead Animal</option>
                      <option value="rescue">Rescue</option>
                    </select>
                    <select class="select-input tf-select" id="actBrgy" onchange="actSetBrgy(this.value)">
                      <option value="">All Barangays</option>
                      <?php foreach ($cpdBarangays as $b): ?>
                        <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                </div>
              </div>
              <div class="db-table-area staff-report-table-area">
                <div id="actTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                <table>
                  <thead id="actTableHead"></thead>
                  <tbody id="actRows"></tbody>
                </table>
                </div>
              </div>
              <div id="actCardsWrap" class="cards-grid" style="display:none;"></div>
              <div id="actMapWrap" style="display:none;"></div>
            </div>

            <div class="dt-pagination">
              <div class="dt-pagination-right">
                <div class="dt-rows">
                  <span>Rows per page</span>
                  <select onchange="window.actSetPerPage(this.value)">
                    <option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option>
                  </select>
                </div>
                <div class="dt-page-label" id="actPageLabel">Page 1 of 1</div>
                <div class="dt-page-btns">
                  <button class="dt-page-btn" id="actPageFirst" title="Go to first page" onclick="window.actGoPage('first')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
                  <button class="dt-page-btn" id="actPagePrev" title="Go to previous page" onclick="window.actGoPage('prev')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
                  <button class="dt-page-btn" id="actPageNext" title="Go to next page" onclick="window.actGoPage('next')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
                  <button class="dt-page-btn" id="actPageLast" title="Go to last page" onclick="window.actGoPage('last')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Active Case Details Drawer -->
  <div id="actDetailsModal" class="modal cpd cd-drawer staff-detail-drawer">
    <div class="sd-dialog">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('actDetailsModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Active Case Details</h2>
          <p class="sd-dialog-description">Review the active case details.</p>
        </div>
        <div class="sd-field-group">
          <div id="actDetailContent"></div>
        </div>
        <div class="sd-dialog-footer" id="actDrawerFooter"></div>
      </div>
    </div>
  </div>

  <script>
  var ACTIVE_ITEMS = <?php echo json_encode($activeItems, $jsonFlags); ?>;
  </script>

  <!-- �"?�"? REPORTS: RESOLVED CASES �"?�"? -->
  <section id="reports-resolved" class="content-section staff-report-section">
    <div class="cpd reports-fullpage">
      <div class="cpd-app">

        <!-- Main layout -->
        <div class="layout">

          <!-- Center: Database -->
          <div class="panel db-panel">
            <!-- Overview KPIs -->
            <div class="kpis">
              <div class="kpi-card">
                <p class="kpi-label">Resolved Cases</p>
                <div class="kpi-value"><?php echo $resolvedCounts['all']; ?></div>
                <p class="kpi-sub">Completed cases</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Resolved This Month</p>
                <div class="kpi-value"><?php echo $resolvedCounts['month']; ?></div>
                <p class="kpi-sub">Current month</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">Resolved This Year</p>
                <div class="kpi-value"><?php echo $resolvedCounts['year']; ?></div>
                <p class="kpi-sub">Since Jan 1</p>
              </div>
              <div class="kpi-card">
                <p class="kpi-label">High Priority</p>
                <div class="kpi-value"><?php echo $resolvedCounts['high']; ?></div>
                <p class="kpi-sub">Urgent, now closed</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar staff-report-toolbar staff-mobile-toolbar">
                <div class="staff-toolbar-search-row">
                <div class="search-grow-wrap staff-toolbar-search">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="resMainSearch" placeholder="Search resolved cases..." oninput="resSetSearch(this.value)">
                </div>
                <div class="staff-date-filter staff-toolbar-filter" data-date-filter="res">
                  <button class="staff-date-filter-button" type="button" aria-label="Filter resolved cases by date" title="Filter by date" aria-expanded="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46v6.54l4 2v-8.54L22 3z"/></svg>
                  </button>
                  <input type="hidden" id="resDateFrom" value="">
                  <input type="hidden" id="resDateTo" value="">
                </div>
                </div>
                <div class="toolbar-filters staff-report-filters staff-toolbar-filters">
                    <select class="select-input tf-select" id="resType" onchange="resSetType(this.value)">
                      <option value="">All Types</option>
                      <option value="stray">Stray Animal</option>
                      <option value="rabies">Rabies</option>
                      <option value="injured">Injured Animal</option>
                      <option value="dead">Dead Animal</option>
                      <option value="rescue">Rescue</option>
                    </select>
                    <select class="select-input tf-select" id="resBrgy" onchange="resSetBrgy(this.value)">
                      <option value="">All Barangays</option>
                      <?php foreach ($cpdBarangays as $b): ?>
                        <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                </div>
              </div>
              <div class="db-table-area staff-report-table-area">
                <div id="resTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                <table>
                  <thead id="resTableHead"></thead>
                  <tbody id="resRows"></tbody>
                </table>
                </div>
              </div>
              <div id="resCardsWrap" class="cards-grid" style="display:none;"></div>
              <div id="resMapWrap" style="display:none;">
                <div class="map-placeholder">
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                  <p>Map view is coming soon. Geo-mapping will show resolved cases distributed across Dasmariñas.</p>
                </div>
              </div>
            </div>

            <div class="dt-pagination">
              <div class="dt-pagination-right">
                <div class="dt-rows">
                  <span>Rows per page</span>
                  <select onchange="window.resSetPerPage(this.value)">
                    <option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option>
                  </select>
                </div>
                <div class="dt-page-label" id="resPageLabel">Page 1 of 1</div>
                <div class="dt-page-btns">
                  <button class="dt-page-btn" id="resPageFirst" title="Go to first page" onclick="window.resGoPage('first')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
                  <button class="dt-page-btn" id="resPagePrev" title="Go to previous page" onclick="window.resGoPage('prev')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
                  <button class="dt-page-btn" id="resPageNext" title="Go to next page" onclick="window.resGoPage('next')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
                  <button class="dt-page-btn" id="resPageLast" title="Go to last page" onclick="window.resGoPage('last')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Resolved Case Details Drawer -->
  <div id="resDetailsModal" class="modal cpd cd-drawer staff-detail-drawer">
    <div class="sd-dialog">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('resDetailsModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Resolved Case Details</h2>
          <p class="sd-dialog-description">Review the resolved case history.</p>
        </div>
        <div class="sd-field-group">
          <div id="resDetailContent"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
  var RESOLVED_ITEMS = <?php echo json_encode($resolvedItems, $jsonFlags); ?>;
  </script>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/case-database.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/case-database.js'); ?>"></script>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/staff-date-filter.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/staff-date-filter.js'); ?>"></script>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/response-workflow.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/response-workflow.js'); ?>"></script>
  <script>
  var BARANGAY_RESPONSE_ITEMS = <?php echo json_encode($barangayResponseItems, $jsonFlags); ?>;
  var STAFF_BARANGAY_RESPONSE_STATES = <?php echo json_encode($staffBarangayResponseStates, $jsonFlags); ?>;
  initCaseDB({
    p: 'brg',
    section: 'reports-accepted',
    modalId: 'brgDetailsModal',
    data: BARANGAY_RESPONSE_ITEMS,
    pageLabel: 'cases',
    emptyText: 'No cases are currently being handled by a Barangay.',
    sortDefault: 'newest',
    columns: [{ label: 'Report', key: 'ref' }, { label: 'Reporter', key: 'reporter' }, { label: 'Contact', key: 'phone' }, { label: 'Type', key: 'typeLabel' }, { label: 'Priority', key: 'urgency' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Date Submitted', key: 'submitted' }, { label: 'Incident Date', key: 'date' }, { label: 'Date Resolved', key: 'resolved' }, 'Actions'],
    groupMatch: function () { return true; },
    rowHtml: function (r) {
      return '<td class="sd-td"><div class="client-name" style="font-weight:400;">' + casEsc(r.ref) + '</div></td>' +
        '<td class="sd-td"><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name">' + casEsc(r.reporter || '-') + '</div></div></div></td>' +
        '<td class="sd-td"><div class="contact-main">' + casEsc(r.phone || '-') + '</div><div class="contact-sub">' + casEsc(r.email || '-') + '</div></td>' +
        '<td class="sd-td"><div class="client-name" style="font-weight:400;">' + casEsc(r.typeLabel || '-') + '</div></td>' +
        '<td class="sd-td">' + casPriorityPill(r.urgency) + '</td>' +
        '<td class="sd-td"><div class="client-name" style="font-weight:400;">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td class="sd-td">' + casStatusPill('active', r.statusLabel) + '</td>' +
        '<td class="sd-td"><div class="contact-main">' + casEsc(r.submitted || '-') + '</div></td>' +
        '<td class="sd-td"><div class="contact-main">' + casEsc(r.incidentDate || r.date || '-') + '</div></td>' +
        '<td class="sd-td"><div class="contact-main">' + casEsc(r.resolved || '-') + '</div></td>' +
        '<td class="sd-td sd-td-action"><button type="button" class="sd-row-actions" title="More options" aria-label="More options" onclick="event.stopPropagation();brgActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var image = r.photo ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">' : '<div class="card-img-placeholder">' + casEsc(r.typeLabel || 'Report') + '</div>';
      return image + '<div style="padding:14px;"><div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill('active', r.statusLabel) +
        '</div><div class="client-card-id">' + casCaseId(r) + ' · ' + casEsc(r.ref) + '</div>' +
        '<div class="client-card-name">' + casEsc(r.reporter || '-') + '</div><div class="client-card-id">' + casEsc(r.brgy || '-') + '</div></div>';
    },
    drawerHtml: function (r) {
      var state = String(r.responseState || '');
      var currentStep = state === 'barangay_responding' ? 1 : (state === 'animal_secured' ? 2 : 0);
      var deadline = state === 'barangay_responding' ? r.progressDeadline : r.responseDeadline;
      var deadlineDate = deadline ? new Date(String(deadline).replace(' ', 'T')) : null;
      var deadlineLabel = deadlineDate && !isNaN(deadlineDate.getTime())
        ? deadlineDate.toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' })
        : 'No response deadline';
      var deadlineIsOverdue = deadlineDate && !isNaN(deadlineDate.getTime()) && deadlineDate.getTime() < Date.now();
      var deadlineBadge = deadlineDate && !isNaN(deadlineDate.getTime())
        ? (deadlineIsOverdue ? 'Barangay response overdue · ' : 'Barangay response due · ') + deadlineLabel
        : 'No response deadline recorded';
      var steps = ['Accepted', 'Responding', 'Secured', 'Resolved'];
      var progressHtml = '<div class="response-steps" aria-label="Barangay response progress">' + steps.map(function (label, index) {
        var stepClass = index < currentStep ? ' is-complete' : (index === currentStep ? ' is-current' : '');
        var check = index < currentStep
          ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>'
          : '';
        return '<div class="response-step' + stepClass + '"><span class="response-step-dot" aria-hidden="true">' + check + '</span><span>' + label + '</span></div>';
      }).join('') + '</div>';
      var responseNote = state === 'barangay_response_approved' || state === 'returned_to_barangay'
        ? 'The Barangay is expected to begin its response when its team is heading to the location. The initial response target is 24 hours.'
        : (state === 'barangay_responding'
          ? 'The Barangay has started its response. Staff can monitor progress updates against the response target.'
          : 'The Barangay has recorded the animal as secured. Staff can review custody details and follow-up updates.');
      var avatarUrl = r.avatar ? (window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(r.avatar) : r.avatar) : '';
      var headerImage = avatarUrl
        ? '<img src="' + casEsc(avatarUrl) + '" alt="" style="width:100%;height:100%;object-fit:cover;">'
        : casAvatar(r);
      var responseStatus = r.statusLabel || window.dcvsStaffBarangayCaseStatusLabel(r);
      var responseMode = r.responseMode === 'barangay_response' ? 'Barangay Response'
        : (r.responseMode === 'dcvs_dispatch' ? 'DCVS Dispatch' : 'Undecided');
      var responseDeadlineValue = deadlineDate && !isNaN(deadlineDate.getTime())
        ? deadlineDate.toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' })
        : 'No response deadline set';
      var lastProgress = r.lastProgressAt
        ? new Date(String(r.lastProgressAt).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' })
        : 'No progress update yet';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="brgDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="updates" onclick="brgDetailGo(\'updates\')">Updates</button></div>' +
        '<div class="rpt-detail-body" data-tab="details">' +
        '<div class="brg-drawer-reporter"><div class="brg-drawer-photo">' + headerImage + '</div>' +
        '<div class="brg-drawer-reporter-copy"><span class="brg-drawer-status">' + casEsc(responseStatus) + '</span><span>Reported by</span><strong>' + casEsc(r.reporter || '—') + '</strong></div>' +
        '</div>' +
        '<div class="brg-drawer-priority"><span>Priority</span>' + casPriorityPill(r.urgency) + '</div>' +
        '<section class="brg-response-card" aria-label="Barangay Response">' +
        '<div class="brg-response-heading"><div><strong>Barangay Response</strong><span>Handled by Brgy. ' + casEsc(r.brgy || '—') + '</span></div>' +
        '<span class="brg-response-deadline' + (deadlineIsOverdue ? ' is-overdue' : '') + '">' + casEsc(deadlineBadge) + '</span></div>' +
        progressHtml + '<div class="brg-response-note"><span aria-hidden="true">ℹ</span><span>' + casEsc(responseNote) + '</span></div>' +
        '<button type="button" class="brg-response-map-link" onclick="brgViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')">View report route on geo map</button>' +
        '</section>' +
        '<section class="rpt-col rpt-full-col brg-custody-section"><div class="rpt-section-title">Response &amp; Custody</div>' +
        '<div class="info-rows info-rows-2col rpt-response-custody-grid">' +
        casInfoRow('Response Status', responseStatus) + casInfoRow('Response Mode', responseMode) +
        casInfoRow('Last Progress Update', lastProgress) + casInfoRow('Response Due', responseDeadlineValue) +
        casInfoRow('Holding Location', r.holdingLocation || 'Not provided yet') + casInfoRow('Animal Condition', r.animalCondition || 'Not reported yet') +
        casInfoRow('Resolution Details', r.resolutionDetails || 'Not resolved yet') +
        casInfoRow('Animal Disposition', r.animalDisposition ? r.animalDisposition.replace(/_/g, ' ') : 'Not recorded yet') +
        casInfoRow('Secured', r.securedAt || 'Not secured yet') +
        '</div></section>' +
        '<div class="rpt-detail-grid"><div class="rpt-col rpt-full-col"><div class="rpt-section-title">Report Information</div><div class="info-rows info-rows-2col">' +
        casInfoRow('Date Submitted', r.submitted || r.date || '—') + casInfoRow('Incident Date', r.incidentDate || r.date || '—') +
        casInfoRow('Location', r.location || '—') + casInfoRow('Report Type', r.typeLabel || '—') +
        '</div></div><div class="rpt-col rpt-full-col"><div class="rpt-section-title">Animal Information</div><div class="info-rows info-rows-2col">' +
        casInfoRow('Animal Type', r.animalType || '—') + casInfoRow('Breed / Appearance', r.animalBreed || '—') +
        casInfoRow('Number of animals', r.animalCount || '—') + casInfoRow('Animal Behavior', r.animalBehavior || '—') +
        '</div></div><div class="rpt-col rpt-full-col"><div class="rpt-section-title">Incident Details</div><div class="rpt-desc-box">' + casEsc(r.desc || 'No description provided.') + '</div></div>' +
        (r.brgyNotes ? '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Barangay Notes</div><div class="rpt-notes-box">' + casEsc(r.brgyNotes) + '</div></div>' : '') +
        '</div></div><div class="rpt-detail-body" data-tab="updates" style="display:none;">' + casReportCaseUpdatesHtml(r) + '</div>';
    },
    drawerFooter: function (r) {
      var actions = '';
      if (window.DCVS_BARANGAY_RESPONSE_ENABLED &&
          ['barangay_response_approved', 'barangay_responding'].indexOf(r.responseState) !== -1) {
        actions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'revoke_approval\')">Switch to Dispatch</button>';
      }
      return actions + '<button class="sd-btn sd-btn-outline" onclick="brgViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')">View Location</button>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="brgSelect(' + r.id + ');caseCloseActions()">View Case Details</button>' +
        (r.photo || r.video ? '<button class="row-menu-item" onclick="brgViewPhoto(\'' + casEsc(r.photo || r.video) + '\');caseCloseActions()">View Attachment</button>' : '') +
        '<button class="row-menu-item" onclick="brgViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ');caseCloseActions()">View Location</button>';
    }
  });
  initCaseDB({
    p: 'pen',
    section: 'reports-pending',
    modalId: 'penDetailsModal',
    data: PENDING_ITEMS,
    pageLabel: 'pending',
    emptyText: 'No pending cases match your filters.',
    sortDefault: 'newest',
    columns: [{ label: 'Report ID', key: 'ref' }, { label: 'Reporter', key: 'reporter' }, { label: 'Contact', key: 'phone' }, { label: 'Type', key: 'typeLabel' }, { label: 'Priority', key: 'urgency' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Date Submitted', key: 'submitted' }, { label: 'Incident Date', key: 'date' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'high') return /high|critical/i.test(r.urgency);
      if (v === 'week') return r.vts >= (Date.now() - 7 * 86400000);
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-name report-id">' + casEsc(r.ref) + '</div></td>' +
        '<td><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name">' + casEsc(r.reporter) + '</div></div></div></td>' +
        '<td><div class="contact-main">' + casEsc(r.phone || '-') + '</div><div class="contact-sub">' + casEsc(r.email || '-') + '</div></td>' +
        '<td><div class="client-name">' + casEsc(r.typeLabel || '-') + '</div></td>' +
        '<td>' + casPriorityPill(r.urgency) + '</td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td>' + casStatusPill(r.statusRaw === 'validated' ? 'validated' : 'pending', r.statusLabel) + '</td>' +
        '<td><div class="contact-main">' + casEsc(r.submitted || '-') + '</div></td>' +
        '<td><div class="contact-main">' + casEsc(r.date) + '</div></td>' +
        '<td><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();penActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var img = r.photo
        ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">'
        : '<div class="card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
      return img +
        '<div style="padding:14px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill(r.statusRaw === 'validated' ? 'validated' : 'pending', r.statusLabel) +
        '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:8px;">' + casEsc(r.ref) + '</div>' +
        '<div class="client-card-name" style="font-size:14px;margin-bottom:2px;">' + casEsc(r.reporter) + '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:10px;">' + casEsc(r.brgy || '-') + '</div>' +
        '<div class="client-card-meta"><span class="client-card-contact">Validated ' + casEsc(r.validated) + '</span>' +
        '<span style="font-size:11.5px;font-weight:700;color:var(--green-700);">Review &rarr;</span></div>' +
        '</div>';
    },
    drawerHtml: function (r) {
      var history = [
        { t: casEsc(r.typeLabel || r.type || 'Report') + ' report submitted', a: casEsc(r.reporter || 'Resident'), d: r.date },
        (r.statusRaw === 'validated' ? { t: 'Verified by Barangay', a: 'Barangay', d: r.validated } : null),
        { t: r.statusLabel, a: r.statusRaw === 'validated' ? 'DCVS Staff' : 'Barangay', d: '—' }
      ].filter(Boolean);
      history = history.concat(casResponseHistoryEntries(r));
      var validationEvidence = Array.isArray(r.brgyValidationEvidence) ? r.brgyValidationEvidence : [];
      var attachmentCount = validationEvidence.length + Number(!!r.photo) + Number(!!r.video);
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="penDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="updates" onclick="penDetailGo(\'updates\')">Updates' + (attachmentCount ? ' <span class="pen-attachment-count">' + attachmentCount + '</span>' : '') + '</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div class="pen-reporter-row" style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">' +
        '<div class="pen-reporter-avatar" style="width:85px;height:85px;border-radius:8px;flex:0 0 85px;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + casAvatar(r) + '</div>' +
        '<div class="pen-reporter-copy" style="display:flex;flex-direction:column;gap:2px;min-width:0;">' +
        casStatusPill(r.statusRaw === 'validated' ? 'validated' : 'pending', r.statusLabel || 'Pending Barangay Review').replace('class="rpt-status ', 'class="rpt-status pen-reporter-status ') +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Reported by</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:normal;overflow-wrap:anywhere;">' + casEsc(r.reporter || '—') + '</span>' +
        '</div>' +
        '</div>' +

        '<div style="display:flex;align-items:center;gap:6px;margin:0 0 16px;">' +
        '<span class="info-row-label" style="margin:0;">Priority</span>' +
        casPriorityPill(r.priority || r.urgency) +
        '</div>' +

        casResponseWorkflowDetails(r) +

        '<section class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Report Information</div>' +
        '<div class="pen-info-grid">' +
        casInfoRow('Date Submitted', r.submitted || r.date || '-') +
        casInfoRow('Incident Date', r.incidentDate || r.date || '-') +
        casInfoRow('Location', r.location || '-') +
        casInfoRow('Report Type', r.typeLabel || '-') +
        '</div></section>' +

        ((r.structured || r.animalType || r.animalBreed || r.animalCount || r.animalBehavior)
          ? '<section class="rpt-col rpt-full-col">' +
            '<div class="rpt-section-title">Animal Information</div>' +
            '<div class="pen-info-grid">' +
            casInfoRow('Animal Type', r.animalType || '-') +
            casInfoRow('Breed / Appearance', r.animalBreed || '-') +
            casInfoRow('Estimated Number of Animals', r.animalCount || '-') +
            casInfoRow('Animal Behavior', r.animalBehavior || '-') +
            '</div></section>'
          : '') +

        '<section class="rpt-col rpt-full-col pen-description">' +
        '<div class="rpt-section-title">Incident Details</div>' +
        '<div class="rpt-desc-box">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</section>' +

        '<section class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Reporter Information</div>' +
        '<div class="pen-info-grid">' +
        casInfoRow('Resident ID', r.reporterRef || '-') +
        casInfoRow('Mobile Number', r.phone || '-') +
        casInfoRow('Email', r.email || '-') +
        casInfoRow('Barangay', r.brgy || '-') +
        '</div></section>' +

        (r.dcvsNotes ? '<section class="rpt-col rpt-full-col"><div class="rpt-section-title">DCVS Notes</div><div class="rpt-notes-box">' + casEsc(r.dcvsNotes) + '</div></section>' : '') +
        (r.brgyNotes ? '<section class="rpt-col rpt-full-col"><div class="rpt-section-title">Barangay Notes</div><div class="rpt-notes-box">' + casEsc(r.brgyNotes) + '</div></section>' : '') +

        '<div class="profile-footer">' +
        (window.DCVS_BARANGAY_RESPONSE_ENABLED && r.statusRaw === 'validated' && r.responseState === 'awaiting_dcvs_decision'
          ? (typeof window.rptResponseDecisionFooter === 'function' ? window.rptResponseDecisionFooter(r) : '')
          : '<button class="sd-btn sd-btn-outline" onclick="penViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')">View Location</button>' +
            (r.statusRaw === 'validated'
              ? '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="openRejectionModal(' + r.id + ')">Reject</button>' +
                '<button class="sd-btn sd-btn-default" onclick="openReportReview(' + r.id + ')">Confirm</button>'
              : '<span style="align-self:center;font-size:13px;color:#92400e;">Awaiting barangay validation</span>')) +
        '</div>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="updates" style="display:none;">' +
        casReportCaseUpdatesHtml(r) +
        '</div>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="penSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Report</button>' +
        (r.photo || r.video ? '<button class="row-menu-item" onclick="penViewPhoto(\'' + casEsc(r.photo || r.video) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="penViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
        '<button class="row-menu-item" onclick="penSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>View History</button>' +
        (r.statusRaw === 'validated'
          ? '<button class="row-menu-item" onclick="openRejectionModal(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="8" y1="8" x2="16" y2="16"/><line x1="16" y1="8" x2="8" y2="16"/></svg>Reject</button>' +
            '<button class="row-menu-item" onclick="openReportReview(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Confirm</button>'
          : '');
    }
  });
  initCaseDB({
    p: 'act',
    section: 'reports-active',
    modalId: 'actDetailsModal',
    data: ACTIVE_ITEMS,
    pageLabel: 'cases',
    emptyText: 'No active cases match your filters.',
    sortDefault: 'newest',
    columns: [{ label: 'Case ID', key: 'id' }, { label: 'Report', key: 'ref' }, { label: 'Reporter', key: 'reporter' }, { label: 'Contact', key: 'phone' }, { label: 'Type', key: 'typeLabel' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Date Submitted', key: 'submitted' }, { label: 'Incident Date', key: 'date' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'high') return /high|critical/i.test(r.urgency);
      if (v === 'sched') return !!r.scheduled;
      if (v === 'overdue') return r.scheduled && new Date(r.scheduled.replace(' PM', ' PM')).getTime() < Date.now();
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-name report-id" style="font-size:14px;font-weight:400;">' + casCaseId(r) + '</div></td>' +
        '<td><div class="client-name report-id" style="font-size:14px;font-weight:400;">' + casEsc(r.ref) + '</div></td>' +
        '<td><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name">' + casEsc(r.reporter || '-') + '</div></div></div></td>' +
        '<td><div class="contact-main">' + casEsc(r.phone || '-') + '</div><div class="contact-sub">' + casEsc(r.email || '-') + '</div></td>' +
        '<td><div class="client-name">' + casEsc(r.typeLabel || '-') + '</div></td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td>' + casStatusPill('active', r.statusLabel || 'Active') + (r.scheduled ? '<div class="client-id" style="margin-top:3px;">Scheduled ' + casEsc(r.scheduled) + '</div>' : '') + '</td>' +
        '<td><div class="contact-main">' + casEsc(r.submitted || '-') + '</div></td>' +
        '<td><div class="contact-main">' + casEsc(r.date) + '</div></td>' +
        '<td><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();actActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var img = r.photo
        ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">'
        : '<div class="card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
      return img +
        '<div style="padding:14px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill('active', r.statusLabel || 'Active') +
        '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:8px;">' + casCaseId(r) + ' &middot; ' + casEsc(r.ref) + '</div>' +
        '<div class="client-card-name" style="font-size:14px;margin-bottom:2px;">' + casEsc(r.reporter) + '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:10px;">' + casEsc(r.brgy || '-') + '</div>' +
        '<div class="client-card-meta"><span class="client-card-contact">Confirmed ' + casEsc(r.confirmed) + '</span>' +
        '<span style="font-size:11.5px;font-weight:700;color:var(--green-700);">View Case &rarr;</span></div>' +
        '</div>';
    },
    drawerHtml: function (r) {
      var statusColor = '#1E40AF', statusBg = '#EFF6FF', statusBorder = '#BFDBFE';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="actDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="updates" onclick="actDetailGo(\'updates\')">Updates</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">' +
        '<div class="avatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + casAvatar(r) + '</div>' +
        '<div class="case-drawer-reporter-copy" style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:0;">' +
        '<span class="case-drawer-status" style="font-size:13px;font-weight:500;color:' + statusColor + ';background:' + statusBg + ';border:1px solid ' + statusBorder + ';padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;">' + casEsc(r.statusLabel || 'Active') + '</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Reported by</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:normal;overflow-wrap:anywhere;">' + casEsc(r.reporter || '—') + '</span>' +
        '</div>' +
        '</div>' +

        '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;">' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:500;color:#64748b;">Priority</span>' +
        casPriorityPill(r.priority || r.urgency) +
        '</div>' +

        casResponseWorkflowDetails(r, { hideProgress: true }) +

        '<div class="rpt-detail-grid">' +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Report Information</div>' +
        '<div class="info-rows info-rows-2col">' +
        casInfoRow('Date Submitted', r.submitted || r.date || '-') +
        casInfoRow('Incident Date', r.incidentDate || r.date || '-') +
        casInfoRow('Location', r.location || '-') +
        casInfoRow('Report Type', r.typeLabel || '-') +
        '</div>' +
        '</div>' +

        ((r.structured || r.animalType || r.animalBreed || r.animalCount || r.animalBehavior)
          ? '<div class="rpt-col rpt-full-col">' +
            '<div class="rpt-section-title">Animal Information</div>' +
            '<div class="info-rows info-rows-2col">' +
            casInfoRow('Animal Type', r.animalType || '-') +
            casInfoRow('Breed / Appearance', r.animalBreed || '-') +
            casInfoRow('Estimated Number of Animals', r.animalCount || '-') +
            casInfoRow('Animal Behavior', r.animalBehavior || '-') +
            '</div>' +
            '</div>'
          : '') +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">General Information</div>' +
        '<div class="info-rows info-rows-2col">' +
        casInfoRow('Resident ID', r.reporterRef || '-') +
        casInfoRow('Mobile Number', r.phone || '-') +
        casInfoRow('Email', r.email || '-') +
        casInfoRow('Barangay', r.brgy || '-') +
        casInfoRow('Status', r.statusLabel || 'Active') +
        casInfoRow('Confirmed', r.confirmed || '-') +
        casInfoRow('Scheduled', r.scheduled || 'Not yet scheduled') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Incident Details</div>' +
        '<p class="info-row-label" style="margin:0 0 6px;">Description</p>' +
        '<div class="rpt-desc-box" style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</div>' +

        (r.dcvsNotes ? '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">DCVS Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.dcvsNotes) + '</div>' +
        '</div>' : '') +

        (r.brgyNotes ? '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Barangay Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.brgyNotes) + '</div>' +
        '</div>' : '') +

        '</div></div>' +

        '<div class="rpt-detail-body" data-tab="updates" style="display:none;">' +
        casReportCaseUpdatesHtml(r) +
        '</div>';
    },
    drawerFooter: function (r) {
      var responseActions = '';
      var canResolveResponse = !window.DCVS_BARANGAY_RESPONSE_ENABLED
        || ['dcvs_dispatch', 'dcvs_processing'].indexOf(r.responseState) !== -1;
      if (window.DCVS_BARANGAY_RESPONSE_ENABLED) {
        if (r.responseState === 'barangay_response_approved' || r.responseState === 'barangay_responding') {
          responseActions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'revoke_approval\')">Switch to Dispatch</button>';
        } else if (r.responseState === 'animal_secured' || r.responseState === 'awaiting_turnover') {
          responseActions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'switch_to_dispatch\')">Request DCVS Pickup</button>';
          responseActions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'return_to_barangay\')">Return to Barangay</button>';
          if (r.responseState === 'awaiting_turnover') responseActions += '<button class="sd-btn sd-btn-default" onclick="responseWorkflowAction(' + Number(r.id) + ',\'confirm_turnover\')">Confirm Turnover Received</button>';
        } else if (r.responseState === 'turned_over') {
          responseActions += '<button class="sd-btn sd-btn-default" onclick="responseWorkflowAction(' + Number(r.id) + ',\'begin_processing\')">Begin DCVS Processing</button>';
        } else if (r.responseState === 'unable_to_locate') {
          responseActions += '<button class="sd-btn sd-btn-default" onclick="responseWorkflowAction(' + Number(r.id) + ',\'reopen_unable_to_locate\')">Dispatch Case</button>';
          responseActions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'close_not_actionable\')">Close Not Actionable</button>';
        } else if (r.responseState === 'escalated_to_dcvs') {
          responseActions += '<button class="sd-btn sd-btn-default" onclick="responseWorkflowAction(' + Number(r.id) + ',\'dispatch_escalation\')">Dispatch DCVS Team</button>';
          responseActions += '<button class="sd-btn sd-btn-outline" onclick="responseWorkflowAction(' + Number(r.id) + ',\'close_not_actionable\')">Close Not Actionable</button>';
        }
      }
      return responseActions + '<button class="sd-btn sd-btn-outline" onclick="actViewGeo(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')">View Location</button>' +
        (canResolveResponse ? '<button class="sd-btn sd-btn-default" onclick="openResolveModal(' + r.caseId + ', \'' + casEsc(r.typeLabel).replace(/'/g, '\\\'') + '\', \'' + casEsc(r.reporter).replace(/'/g, '\\\'') + '\')">Mark Resolved</button>' : '');
    },
    menuHtml: function (r) {
      var canResolveResponse = !window.DCVS_BARANGAY_RESPONSE_ENABLED
        || ['dcvs_dispatch', 'dcvs_processing'].indexOf(r.responseState) !== -1;
      return '<button class="row-menu-item" onclick="actSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Report</button>' +
        (r.photo || r.video ? '<button class="row-menu-item" onclick="actViewPhoto(\'' + casEsc(r.photo || r.video) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="actViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
        '<button class="row-menu-item" onclick="actSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>View History</button>' +
        (canResolveResponse ? '<button class="row-menu-item" onclick="openResolveModal(' + r.caseId + ', \'' + casEsc(r.typeLabel).replace(/'/g, '\\\'') + '\', \'' + casEsc(r.reporter).replace(/'/g, '\\\'') + '\');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Mark Resolved</button>' : '');
    }
  });
  initCaseDB({
    p: 'res',
    section: 'reports-resolved',
    modalId: 'resDetailsModal',
    data: RESOLVED_ITEMS,
    pageLabel: 'cases',
    emptyText: 'No resolved cases match your filters.',
    sortDefault: 'newest',
    columns: [{ label: 'Case ID', key: 'id' }, { label: 'Report', key: 'ref' }, { label: 'Reporter', key: 'reporter' }, { label: 'Contact', key: 'phone' }, { label: 'Type', key: 'typeLabel' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Date Submitted', key: 'submitted' }, { label: 'Incident Date', key: 'date' }, { label: 'Date Resolved', key: 'resolved' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'month') return r.ts >= new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime();
      if (v === 'year') return r.ts >= new Date(new Date().getFullYear(), 0, 1).getTime();
      if (v === 'high') return /high|critical/i.test(r.urgency);
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-name report-id" style="font-size:14px;font-weight:400;">' + casCaseId(r) + '</div></td>' +
        '<td><div class="client-name report-id" style="font-size:14px;font-weight:400;">' + casEsc(r.ref) + '</div></td>' +
        '<td><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name">' + casEsc(r.reporter || '-') + '</div></div></div></td>' +
        '<td><div class="contact-main">' + casEsc(r.phone || '-') + '</div><div class="contact-sub">' + casEsc(r.email || '-') + '</div></td>' +
        '<td><div class="client-name">' + casEsc(r.typeLabel || '-') + '</div></td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td>' + casStatusPill('resolved') + '</td>' +
        '<td><div class="contact-main">' + casEsc(r.submitted || '-') + '</div></td>' +
        '<td><div class="contact-main">' + casEsc(r.date) + '</div></td>' +
        '<td><div class="contact-main">' + casEsc(r.resolved) + '</div></td>' +
        '<td><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();resActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var img = r.photo
        ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">'
        : '<div class="card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
      return img +
        '<div style="padding:14px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill('resolved') +
        '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:8px;">' + casCaseId(r) + ' &middot; ' + casEsc(r.ref) + '</div>' +
        '<div class="client-card-name" style="font-size:14px;margin-bottom:2px;">' + casEsc(r.reporter) + '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:10px;">' + casEsc(r.brgy || '-') + '</div>' +
        '<div class="client-card-meta"><span class="client-card-contact">Resolved ' + casEsc(r.resolved) + '</span>' +
        '<span style="font-size:11.5px;font-weight:700;color:var(--green-700);">View History &rarr;</span></div>' +
        '</div>';
    },
    drawerHtml: function (r) {
      var history = [
        { t: casEsc(r.typeLabel || r.type || 'Report') + ' report submitted', a: casEsc(r.reporter || 'Resident'), d: r.date },
        { t: 'Verified by Barangay', a: 'Barangay', d: r.validated || r.confirmed },
        { t: 'Confirmed by DCVS Staff', a: 'DCVS Staff', d: r.confirmed },
        (r.scheduled ? { t: 'Scheduled for action', a: 'DCVS Staff', d: r.scheduled } : null),
        { t: 'Resolved by DCVS Staff', a: 'DCVS Staff', d: r.resolved }
      ].filter(Boolean);
      history = history.concat(casResponseHistoryEntries(r));
      var statusColor = '#ffffff', statusBg = '#0E8131', statusBorder = '#0E8131';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="resDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="updates" onclick="resDetailGo(\'updates\')">Updates</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">' +
        '<div class="avatar" style="width:85px;height:85px;border-radius:8px;flex:0 0 85px;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + casAvatar(r) + '</div>' +
        '<div class="case-drawer-reporter-copy" style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:0;">' +
        '<span class="case-drawer-status" style="font-size:13px;font-weight:500;color:' + statusColor + ';background:' + statusBg + ';border:1px solid ' + statusBorder + ';padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;">' + casEsc(r.statusLabel || 'Resolved') + '</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Reported by</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:normal;overflow-wrap:anywhere;">' + casEsc(r.reporter || '—') + '</span>' +
        '</div>' +
        '</div>' +

        '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;">' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:500;color:#64748b;">Priority</span>' +
        casPriorityPill(r.priority || r.urgency) +
        '</div>' +

        '<div class="rpt-detail-grid">' +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Report Information</div>' +
        '<div class="info-rows info-rows-2col">' +
        casInfoRow('Date Submitted', r.submitted || r.date || '-') +
        casInfoRow('Incident Date', r.incidentDate || r.date || '-') +
        casInfoRow('Location', r.location || '-') +
        casInfoRow('Report Type', r.typeLabel || '-') +
        '</div>' +
        '</div>' +

        ((r.structured || r.animalType || r.animalBreed || r.animalCount || r.animalBehavior)
          ? '<div class="rpt-col rpt-full-col">' +
            '<div class="rpt-section-title">Animal Information</div>' +
            '<div class="info-rows info-rows-2col">' +
            casInfoRow('Animal Type', r.animalType || '-') +
            casInfoRow('Breed / Appearance', r.animalBreed || '-') +
            casInfoRow('Estimated Number of Animals', r.animalCount || '-') +
            casInfoRow('Animal Behavior', r.animalBehavior || '-') +
            '</div>' +
            '</div>'
          : '') +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">General Information</div>' +
        '<div class="info-rows info-rows-2col">' +
        casInfoRow('Resident ID', r.reporterRef || '-') +
        casInfoRow('Mobile Number', r.phone || '-') +
        casInfoRow('Email', r.email || '-') +
        casInfoRow('Barangay', r.brgy || '-') +
        casInfoRow('Status', r.statusLabel || 'Resolved') +
        casInfoRow('Assigned Staff', r.assigned || '-') +
        casInfoRow('Confirmed', r.confirmed || '-') +
        casInfoRow('Resolved', r.resolved || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Incident Details</div>' +
        '<p class="info-row-label" style="margin:0 0 6px;">Description</p>' +
        '<div class="rpt-desc-box" style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</div>' +

        (r.dcvsNotes ? '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">DCVS Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.dcvsNotes) + '</div>' +
        '</div>' : '') +

        (r.brgyNotes ? '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Barangay Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.brgyNotes) + '</div>' +
        '</div>' : '') +

        '</div>' +

        '</div>' +

        '<div class="rpt-detail-body" data-tab="updates" style="display:none;">' +
        casReportCaseUpdatesHtml(r) +
        '</div>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="resSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Report</button>' +
        (r.photo || r.video ? '<button class="row-menu-item" onclick="resViewPhoto(\'' + casEsc(r.photo || r.video) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="resViewLocation(\'' + casEsc(r.brgy) + '\',' + Number(r.id) + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
        '<button class="row-menu-item" onclick="resSelect(' + r.id + ');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>View History</button>' +
        '<button class="row-menu-item" onclick="window.open(\'export-report.php\',\'_blank\');caseCloseActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Report</button>';
    }
  });
  </script>

  <section id="geomapping" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">Geo-Mapping</h1>
        <p class="page-subtitle">View active cases and reports mapped by location</p>
      </div>
    </div>
    <div class="dashboard-card">
      <div class="card-header">
        <h3>Active Cases Map</h3>
      </div>
      <?php if (empty($activeCases)): ?>
        <p style="text-align:center;color:#8bb89a;padding:2rem;">No active cases to display on map.</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Case ID</th>
              <th>Report Ref</th>
              <th>Type</th>
              <th>Location</th>
              <th>Barangay</th>
              <th>Status</th>
              <th>Confirmed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeCases as $c): ?>
              <tr>
                <td>#<?php echo $c['case_id']; ?></td>
                <td><?php echo htmlspecialchars($c['report_ref'] ?? fallbackRefId('#RPT-', $c['case_id'])); ?></td>
                <td><?php echo ucfirst($c['case_type']); ?></td>
                <td><?php echo htmlspecialchars($c['location_address']); ?></td>
                <td><?php echo htmlspecialchars($c['barangay_name'] ?? '-'); ?></td>
                <td><span class="status-pill status-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                <td><?php echo date('M d, Y', strtotime($c['confirmed_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>

  <section id="account-settings" class="content-section">

    <?php if (isset($_SESSION['profile_error'])): ?>
      <div id="profileError" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#991b1b;"><?php echo htmlspecialchars($_SESSION['profile_error']); unset($_SESSION['profile_error']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['profile_success'])): ?>
      <div id="profileSuccess" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#065f46;"><?php echo htmlspecialchars($_SESSION['profile_success']); unset($_SESSION['profile_success']); ?></div>
    <?php endif; ?>

    <div class="profile-settings-wrapper">

      <!-- Profile summary -->
      <div class="ps-card ps-profile-card">
        <div class="ps-profile-left">
          <div class="profile-header-avatar-wrapper">
            <?php if (!empty($staffAvatar)): ?>
              <div class="ps-avatar"><img id="staffAvatarImg" src="<?php echo htmlspecialchars(dcvsStaffReportMediaUrl($staffAvatar), ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)"></div>
            <?php else: ?>
              <div class="ps-avatar-plain" onclick="toggleProfileDropdown(event)"><i class="fas fa-user"></i></div>
            <?php endif; ?>
            <div class="profile-dropdown" id="profileDropdown">
              <div class="dropdown-item" onclick="event.stopPropagation();viewProfilePhoto()">
                <i class="fas fa-eye"></i> <span>View Profile</span>
              </div>
              <div class="dropdown-item" onclick="event.stopPropagation();document.getElementById('avatarInput').click()">
                <i class="fas fa-camera"></i> <span>Change Profile</span>
              </div>
            </div>
          </div>
          <div>
            <div class="ps-profile-name"><?php echo htmlspecialchars($fullName); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($staffEmail); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($staffAddress); ?></div>
          </div>
        </div>
      </div>

      <!-- SMS Notifications -->
      <div class="ps-card">
        <div class="ps-card-header">
          <h2>SMS Notifications</h2>
        </div>
        <form method="POST" action="dashboard-staff.php#account-settings" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
          <div>
            <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;">Enable SMS Notifications</div>
            <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;color:#71717a;margin-top:2px;">When you send a message to a resident, an SMS will also be sent to their registered phone number via the connected Android phone.</div>
          </div>
          <label style="position:relative;display:inline-block;width:48px;height:26px;flex-shrink:0;">
            <input type="checkbox" name="sms_enabled" value="1" <?php echo isSmsEnabled($conn) ? 'checked' : ''; ?> style="opacity:0;width:0;height:0;">
            <span style="position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:26px;transition:.3s;" id="smsToggleBg"></span>
            <span style="position:absolute;content:'';height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.15);" id="smsToggleDot"></span>
          </label>
          <input type="hidden" name="toggle_sms" value="1">
        </form>
        <div id="smsToggleFeedback" role="status" aria-live="polite" style="min-height:16px;margin-top:8px;font:12px/1.4 Inter,'Segoe UI',sans-serif;color:#64748b;"></div>
        <script>
        (function(){
            var form = document.querySelector('form[action="dashboard-staff.php#account-settings"]');
            var cb = form && form.querySelector('input[name="sms_enabled"][type="checkbox"]');
            var bg = document.getElementById('smsToggleBg');
            var dot = document.getElementById('smsToggleDot');
            var feedback = document.getElementById('smsToggleFeedback');
            if(form && cb && bg && dot && feedback) {
              function updateToggle() {
                bg.style.background = cb.checked ? '#0E8131' : '#d1d5db';
                dot.style.left = cb.checked ? '25px' : '3px';
              }
              cb.addEventListener('change', function() {
                var requestedValue = cb.checked;
                cb.disabled = true;
                feedback.textContent = 'Saving...';
                updateToggle();
                var formData = new FormData(form);
                formData.set('sms_enabled', requestedValue ? '1' : '0');

                fetch(form.action, {
                  method: 'POST',
                  body: formData,
                  headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                  .then(function(response) {
                    return response.json().then(function(data) {
                      if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Could not save the SMS notification setting.');
                      }
                      return data;
                    });
                  })
                  .then(function(data) {
                    feedback.textContent = data.sms_enabled
                      ? 'SMS notifications enabled.'
                      : 'SMS notifications disabled.';
                  })
                  .catch(function(error) {
                    cb.checked = !requestedValue;
                    updateToggle();
                    feedback.textContent = error.message;
                  })
                  .then(function() {
                    cb.disabled = false;
                  });
              });
              updateToggle();
            }
        })();
        </script>
      </div>

      <form id="staffSettingsForm" action="<?php echo htmlspecialchars($appBaseUrl . '/update-staff-profile.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event, 'staffAvatarImg')">

        <!-- Personal Information -->
        <div class="ps-card" id="personal-card">
          <div class="ps-card-header">
            <h2>Personal Information</h2>
            <button type="button" class="ps-edit-btn" onclick="toggleEdit('personal')">
              <span class="btn-label">Edit</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Full Name</label>
              <input type="text" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Email Address</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($staffEmail); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($staffPhone); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Address</label>
              <textarea name="address" disabled data-group="personal"><?php echo htmlspecialchars($staffAddress); ?></textarea>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="ps-footer-actions">
          <button type="button" class="ps-btn ps-btn-secondary" onclick="openModal('changePasswordModal')">Change Password</button>
          <button type="submit" class="ps-btn ps-btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </section>

</main>

<!-- Modals -->
<!-- Profile Photo Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Profile Photo</h3>
      <button class="modal-close" onclick="closeModal('profilePhotoModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <?php if (!empty($staffAvatar)): ?>
        <button class="profile-photo-delete-btn" onclick="confirmRemoveAvatar(); closeModal('profilePhotoModal');" title="Delete Photo">
          <i class="fas fa-trash"></i>
        </button>
      <?php endif; ?>
      <div class="profile-photo-view">
        <?php if (!empty($staffAvatar)): ?>
          <img src="<?php echo htmlspecialchars(dcvsStaffReportMediaUrl($staffAvatar), ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo" id="profilePhotoViewImg">
        <?php else: ?>
          <div class="no-profile-photo">
            <i class="fas fa-user"></i>
            <p>No profile photo uploaded</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-key"></i> Change Password</h2>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="changePasswordForm" action="<?php echo htmlspecialchars($appBaseUrl . '/update-staff-password.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
      <div class="modal-body">
        <div class="form-group"><label>Current Password <span class="req">*</span></label><input type="password" name="current_password" class="form-control" required /></div>
        <div class="form-group"><label>New Password <span class="req">*</span></label><input type="password" name="new_password" class="form-control" required /></div>
        <div class="form-group"><label>Confirm New Password <span class="req">*</span></label><input type="password" name="confirm_password" class="form-control" required /></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Change Password</button>
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Pending Validation confirmation dialogs (shadcn AlertDialog style) -->
<style>
  #confirmReportModal.modal, #rejectReportModal.modal{background:rgba(0,0,0,.5);padding:1rem;z-index:1100;}
  #confirmReportModal .sd-alert-dialog-content, #rejectReportModal .sd-alert-dialog-content{position:relative;width:100%;max-width:420px;max-height:90vh;overflow-y:auto;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:12px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(15,23,42,.1),0 4px 6px -4px rgba(15,23,42,.1);animation:modalIn .2s ease;font-family:'Inter','Segoe UI',sans-serif;}
  .report-alert-header{display:flex;flex-direction:column;gap:6px;padding-right:28px;}
  .report-alert-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1.2;color:#18181b;}
  .report-alert-description{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;color:#71717a;}
  .report-alert-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:4px;background:transparent;color:#71717a;cursor:pointer;}
  .report-alert-close:hover{background:#f4f4f5;color:#18181b;}
  .report-alert-body{display:flex;flex-direction:column;gap:14px;}
  .report-alert-field{display:flex;flex-direction:column;gap:6px;}
  .report-alert-field label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1.25;color:#18181b;}
  .report-alert-field .req{color:#dc2626;}
  .report-alert-field input,.report-alert-field textarea{width:100%;padding:9px 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;outline:none;box-sizing:border-box;}
  .report-alert-field input{height:36px;}
  .report-alert-field textarea{min-height:88px;resize:vertical;line-height:1.5;}
  .report-alert-field input:focus,.report-alert-field textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
  .report-alert-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:2px;}
  .report-alert-danger{background:#dc2626!important;border-color:#dc2626!important;color:#fff!important;}
  .report-alert-danger:hover{background:#b91c1c!important;}
  @media(max-width:480px){.report-alert-footer{flex-direction:column-reverse;}.report-alert-footer .sd-btn{width:100%;}}
</style>
<div class="modal" id="confirmReportModal">
  <form id="confirmReportForm" action="<?php echo htmlspecialchars($appBaseUrl . '/confirm-schedule-report.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="confirmReportTitle" aria-describedby="confirmReportDescription">
      <button type="button" class="report-alert-close" onclick="closeModal('confirmReportModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="report-alert-header">
        <h2 class="report-alert-title" id="confirmReportTitle">Confirm Report</h2>
        <p class="report-alert-description" id="confirmReportDescription">Review the incident date reported by the resident. You may also choose a separate schedule date and time.</p>
      </div>
      <input type="hidden" name="report_id" id="confirmReportId" value="" />
      <div class="report-alert-body">
        <div class="report-alert-field">
          <label for="confirmReportIncidentAt">Incident Date &amp; Time (as reported)</label>
          <input type="text" id="confirmReportIncidentAt" readonly placeholder="Not provided" />
        </div>
        <div class="report-alert-field">
          <label for="confirmReportScheduledAt">Schedule Date &amp; Time <span style="font-weight:400;color:#71717a;">(optional)</span></label>
          <input type="datetime-local" id="confirmReportScheduledAt" name="scheduled_at" />
          <span class="report-alert-description">Leave blank to confirm the report without setting an action schedule.</span>
        </div>
        <div class="report-alert-field">
          <label for="confirmReportNotes">DCVS Notes</label>
          <textarea id="confirmReportNotes" name="dcvs_notes" rows="3" placeholder="Any instructions or notes for this case..."></textarea>
        </div>
      </div>
      <div class="report-alert-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('confirmReportModal')">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default" id="confirmReportSubmitBtn"><i class="fas fa-check"></i> Confirm Report</button>
      </div>
    </div>
  </form>
</div>

<div class="modal" id="rejectReportModal">
  <form id="rejectReportForm" action="<?php echo htmlspecialchars($appBaseUrl . '/reject-validated-report.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="rejectReportTitle" aria-describedby="rejectReportDescription">
      <button type="button" class="report-alert-close" onclick="closeModal('rejectReportModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="report-alert-header">
        <h2 class="report-alert-title" id="rejectReportTitle">Reject Report</h2>
        <p class="report-alert-description" id="rejectReportDescription">This report will be rejected. Enter a reason to explain the decision.</p>
      </div>
      <input type="hidden" name="report_id" id="rejectReportId" value="" />
      <div class="report-alert-body">
        <div class="report-alert-field">
          <label for="rejectReportReason">Rejection Reason <span class="req">*</span></label>
          <textarea id="rejectReportReason" name="reason" rows="4" required placeholder="Explain why this report is being rejected..."></textarea>
        </div>
      </div>
      <div class="report-alert-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('rejectReportModal')">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default report-alert-danger"><i class="fas fa-times"></i> Reject Report</button>
      </div>
    </div>
  </form>
</div>
<!-- Schedule Appointment Modal -->
<!-- Review Appointment Request Modal (shadcn Dialog) -->
<style>
  /* Shared fixed right drawer for report, client profile, and appointment details. */
  .staff-detail-drawer.modal{
    position:fixed;
    inset:0;
    z-index:999;
    display:flex;
    align-items:stretch;
    justify-content:flex-end;
    padding:0;
    overflow:hidden;
    background:rgba(0,0,0,.5);
    opacity:0;
    visibility:hidden;
    transition:opacity .25s ease,visibility .25s ease;
  }
  .staff-detail-drawer.modal.active{ opacity:1; visibility:visible; }
  .staff-detail-drawer .sd-dialog{
    display:flex;
    width:100%;
    height:100dvh;
    max-width:100%;
    min-height:0;
    justify-content:flex-end;
  }
  body.staff-theme .staff-detail-drawer .sd-dialog-content{
    position:relative;
    display:flex;
    box-sizing:border-box;
    flex:0 0 auto;
    flex-direction:column;
    width:min(480px,100vw);
    min-width:0;
    max-width:100vw;
    height:100dvh;
    max-height:100dvh;
    min-height:0;
    gap:18px;
    overflow:hidden;
    align-self:stretch;
    padding:24px;
    border:0;
    border-radius:0;
    box-shadow:-8px 0 40px rgba(15,23,32,.18);
    animation:none;
    transform:translateX(105%);
    transition:transform .32s cubic-bezier(.32,.72,.25,1);
  }
  body.staff-theme .staff-detail-drawer.active .sd-dialog-content{ transform:translateX(0); }
  .staff-detail-drawer .sd-dialog-close{
    position:absolute;
    top:18px;
    right:18px;
    z-index:2;
  }
  .staff-detail-drawer .sd-dialog-header{
    flex:0 0 auto;
    padding-right:28px;
  }
  #rptDetailsModal .sd-dialog-header{
    padding-right:54px;
  }
  #rptDetailsModal .rpt-drawer-reporter-label{
    display:flex;
    align-items:flex-start;
    flex-direction:column;
    gap:4px;
    font-family:'Inter','Segoe UI',sans-serif;
    font-size:12px;
    font-weight:400;
    color:#71717a;
  }
  #rptDetailsModal .rpt-drawer-status{
    display:inline-flex;
    align-items:center;
    flex:0 1 auto;
    max-width:100%;
    overflow:hidden;
    padding:4px 10px;
    border-radius:6px;
    font-family:'Inter','Segoe UI',sans-serif;
    font-size:12px;
    font-weight:500;
    line-height:1.4;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  @media(max-width:600px){
    #rptDetailsModal .sd-dialog-header{padding-right:48px;}
    #rptDetailsModal .rpt-drawer-status{padding:3px 7px;font-size:11px;}
  }
  .staff-detail-drawer .sd-field-group{
    display:flex;
    flex:1 1 auto;
    flex-direction:column;
    min-height:0;
    overflow-x:hidden;
    overflow-y:auto;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
  }
  .staff-detail-drawer .rpt-tabs{
    position:sticky;
    top:0;
    z-index:2;
    flex:0 0 auto;
    margin-top:0;
    padding-top:8px;
    background:#fff;
  }
  #appointmentDetailsModal .rpt-tabs{
    display:flex;
    justify-content:center;
    gap:28px;
    width:100%;
    margin:0 0 14px;
    padding:0 0 0;
    border-bottom:1px solid #e4e4e7;
    box-sizing:border-box;
  }
  #appointmentDetailsModal .rpt-tab{
    appearance:none;
    padding:0 0 12px;
    border:0;
    border-bottom:2px solid transparent;
    margin-bottom:-1px;
    background:transparent;
    color:#71717a;
    font-family:Inter,'Segoe UI',sans-serif;
    font-size:14px;
    font-weight:500;
    line-height:1.4;
    cursor:pointer;
  }
  #appointmentDetailsModal .rpt-tab:hover{ color:#18181b; }
  #appointmentDetailsModal .rpt-tab.active{
    border-bottom-color:transparent;
    color:#0e8131;
    font-weight:600;
  }
  #appointmentDetailsModal .rpt-tab:focus-visible{
    outline:2px solid #18181b;
    outline-offset:3px;
    border-radius:2px;
  }
  #appointmentDetailsModal .appt-history-panel{ flex:1 1 auto; min-height:0; overflow-y:auto; }
  #appointmentDetailsModal .appt-history-timeline{ position:relative; margin:0 0 14px 3px; padding-left:14px; border-left:2px solid #e4e4e7; color:#09090b; font-family:'Inter','Segoe UI',sans-serif; }
  #appointmentDetailsModal .appt-history-entry{ position:relative; padding:0 0 17px; }
  #appointmentDetailsModal .appt-history-entry:last-child{ padding-bottom:0; }
  #appointmentDetailsModal .appt-history-entry:before{ position:absolute; top:3px; left:-20px; width:10px; height:10px; border-radius:50%; background:#22c55e; content:""; box-shadow:0 0 0 2px #fff; }
  #appointmentDetailsModal .appt-history-entry-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:3px; }
  #appointmentDetailsModal .appt-history-entry-title{ min-width:0; color:#09090b; font-size:14px; font-weight:600; line-height:1.35; }
  #appointmentDetailsModal .appt-history-entry-date{ flex:0 0 auto; color:#71717a; font-size:12px; line-height:1.4; text-align:right; }
  #appointmentDetailsModal .appt-history-entry-source{ display:inline-block; margin:0 0 6px; padding:1px 7px; border:1px solid #e4e4e7; border-radius:4px; background:#fafafa; color:#71717a; font-size:12px; line-height:1.35; }
  #appointmentDetailsModal .appt-history-entry-message{ margin:0; color:#27272a; font-size:14px; font-weight:400; line-height:1.45; white-space:pre-wrap; overflow-wrap:anywhere; }
  #appointmentDetailsModal .appt-history-empty{ margin:0 0 14px; color:#71717a; font-size:14px; }
  .staff-detail-drawer .sd-dialog-footer{
    box-sizing:border-box;
    flex:0 0 auto;
    padding-bottom:calc(8px + env(safe-area-inset-bottom));
    background:#fff;
  }
  .staff-detail-drawer #rptDetailFooter{ flex-wrap:wrap; }
  .staff-detail-drawer #rptDetailFooter .sd-btn{ flex:0 1 auto; white-space:nowrap; }
  .staff-detail-drawer .sd-dialog-footer:empty{ display:none; }

  #mainContent > .staff-report-section.content-section{
    box-sizing:border-box;
    width:calc(100% + 4rem);
    max-width:none;
    margin:-1.75rem -2rem 0;
  }
  .staff-report-section .cpd-app{ width:100%; min-width:0; padding:0; }
  .staff-report-section .db-panel{ width:100%; min-width:0; }
  body.staff-theme #mainContent .staff-report-toolbar{
    display:flex;
    box-sizing:border-box;
    width:100%;
    min-width:0;
    align-items:center;
    justify-content:flex-start;
    gap:12px;
    margin:0 0 12px;
    flex-wrap:wrap;
  }
  body.staff-theme #mainContent .staff-report-toolbar .search-grow-wrap{
    box-sizing:border-box;
    flex:0 1 260px;
    width:260px;
    min-width:200px;
    max-width:280px;
  }
  body.staff-theme #mainContent .staff-report-toolbar .search-grow-wrap input{ height:36px; }
  body.staff-theme #mainContent .staff-report-filters{
    display:flex;
    box-sizing:border-box;
    flex:0 1 auto;
    width:auto;
    min-width:0;
    align-items:center;
    gap:8px;
    flex-wrap:nowrap;
  }
  body.staff-theme #mainContent .staff-report-filters .tf-select{
    box-sizing:border-box;
    flex:0 1 auto;
    width:auto;
    min-width:130px;
    max-width:190px;
    height:36px;
    padding:0 26px 0 10px;
  }
  body.staff-theme #mainContent .staff-report-toolbar .db-toolbar-actions{
    display:flex;
    flex:0 0 auto;
    align-items:center;
    gap:8px;
    margin-left:auto;
  }
  .staff-report-table-area{ box-sizing:border-box; width:100%; min-width:0; padding-top:0; }
  @media (max-width: 1100px){
    body.staff-theme #mainContent .staff-report-toolbar .search-grow-wrap{ flex-basis:260px; }
  }
  @media (max-width: 1024px){
    #mainContent > .staff-report-section.content-section{
      width:100%;
      max-width:100%;
      margin:-1rem 0 0;
    }
  }
  @media (max-width: 768px){
    body.staff-theme #mainContent .staff-report-toolbar{ align-items:stretch; gap:10px; }
    body.staff-theme #mainContent .staff-report-toolbar .search-grow-wrap{
      flex:1 1 100%;
      width:100%;
      min-width:0;
    }
    body.staff-theme #mainContent .staff-report-filters{
      display:grid;
      flex:1 1 100%;
      width:100%;
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
    body.staff-theme #mainContent .staff-report-filters .tf-select{
      width:100%;
      min-width:0;
      max-width:100%;
    }
    body.staff-theme #mainContent .staff-report-toolbar .db-toolbar-actions{ width:100%; margin-left:0; }
    body.staff-theme #mainContent .staff-report-toolbar .db-toolbar-actions .sd-btn{ width:100%; }
  }
  @media (max-width: 360px){
    body.staff-theme #mainContent .staff-report-filters{ grid-template-columns:minmax(0,1fr); }
  }
  @media (max-width: 640px){
    .staff-detail-drawer.modal{ z-index:1200; }
    .staff-detail-drawer .sd-dialog{ width:100%; max-width:none; }
    body.staff-theme .staff-detail-drawer .sd-dialog-content{
      flex-basis:100%;
      width:100%;
      max-width:100%;
      height:100dvh;
      max-height:100dvh;
      padding:16px 14px calc(14px + env(safe-area-inset-bottom));
      transform:none;
      transition:none;
    }
    body.staff-theme .staff-detail-drawer.active .sd-dialog-content{ transform:none; }
    .staff-detail-drawer .sd-dialog-footer{ padding-bottom:calc(12px + env(safe-area-inset-bottom)); }
    #rptDetailsModal .rpt-detail-body .info-rows.info-rows-2col{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:14px 16px;
    }
  }

  #appointmentDetailsModal.appt-bulk-dialog.modal{ align-items:center; justify-content:center; padding:16px; }
  #appointmentDetailsModal.appt-bulk-dialog .sd-dialog{ width:100%; height:auto; justify-content:center; }
  #appointmentDetailsModal.appt-bulk-dialog .sd-dialog-content{ width:min(560px,94vw); max-width:min(560px,94vw); height:auto; max-height:calc(100vh - 32px); box-sizing:border-box; overflow-y:auto; border:1px solid #e4e4e7; border-radius:10px; box-shadow:0 20px 25px rgba(15,23,32,.18); transform:none; transition:none; padding:24px; gap:16px; }
  #appointmentDetailsModal.appt-bulk-dialog.active .sd-dialog-content{ transform:none; }
  #appointmentDetailsModal.appt-bulk-dialog.modal{ inset:0; z-index:1100; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.5); }
  #appointmentDetailsModal.appt-bulk-dialog .sd-dialog{ width:100%; height:auto; justify-content:center; }
  #appointmentDetailsModal.appt-bulk-dialog .sd-dialog-content{ width:min(560px,94vw); max-width:min(560px,94vw); height:auto; max-height:calc(100vh - 32px); box-sizing:border-box; overflow-y:auto; border:1px solid #e4e4e7; border-radius:10px; box-shadow:0 20px 25px rgba(15,23,32,.18); transform:none; transition:none; padding:24px; gap:16px; }
  #appointmentDetailsModal.appt-bulk-dialog.active .sd-dialog-content{ transform:none; }
  /* Review Requests = centered dialog */
  #addAppointmentModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
  #addAppointmentModal .sd-dialog-content{ max-width:520px; gap:20px; }
  #scheduleAppointmentDialog.modal{z-index:1200;padding:16px;background:rgba(0,0,0,.5)}
  #scheduleAppointmentDialog .schedule-dialog-content{box-sizing:border-box;width:min(780px,100%);max-width:780px;max-height:calc(100vh - 32px);overflow:auto;display:flex;flex-direction:column;gap:18px;padding:24px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;color:#18181b;box-shadow:0 20px 25px rgba(15,23,32,.18)}
  #scheduleAppointmentDialog .schedule-dialog-header{position:relative;padding:0 28px 18px 0;border-bottom:1px solid #e4e4e7}
  #scheduleAppointmentDialog .schedule-dialog-header h2{margin:0 0 5px;color:#09090b;font:600 18px Inter,'Segoe UI',sans-serif}
  #scheduleAppointmentDialog .schedule-dialog-header p{margin:0;color:#71717a;font:400 13px Inter,'Segoe UI',sans-serif}
  #scheduleAppointmentDialog .schedule-dialog-close{position:absolute;top:0;right:0;display:flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:0;border-radius:6px;background:transparent;color:#71717a;font-size:22px;cursor:pointer}
  #scheduleAppointmentDialog .schedule-dialog-close:hover{background:#f4f4f5;color:#18181b}
  #scheduleAppointmentDialog .schedule-dialog-label{display:block;margin:0 0 7px;color:#18181b;font:500 13px Inter,'Segoe UI',sans-serif}
  #scheduleAppointmentDialog .schedule-date-input{box-sizing:border-box;width:100%;height:40px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:400 14px Inter,'Segoe UI',sans-serif;color-scheme:light;box-shadow:0 1px 2px rgba(0,0,0,.05)}
  #scheduleAppointmentDialog .schedule-date-input:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b}
  #scheduleAppointmentDialog .schedule-time-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  #scheduleAppointmentDialog .schedule-time-option{min-height:44px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif;cursor:pointer;transition:background .15s,border-color .15s}
  #scheduleAppointmentDialog .schedule-time-option:hover{border-color:#a1a1aa;background:#f4f4f5}
  #scheduleAppointmentDialog .schedule-time-option[aria-pressed="true"]{border-color:#18181b;background:#18181b;color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.08)}
  #scheduleAppointmentDialog .schedule-time-option:disabled{opacity:.5;cursor:not-allowed}
  #scheduleAppointmentDialog .schedule-time-option span{display:block;margin-top:3px;color:#71717a;font-size:11px}
  #scheduleAppointmentDialog .schedule-time-option[aria-pressed="true"] span{color:#e4e4e7}
  #scheduleAppointmentDialog .schedule-capacity{padding:14px;border:1px solid #e4e4e7;border-radius:8px;background:#f4f4f5;color:#18181b}
  #scheduleAppointmentDialog .schedule-capacity-heading{display:flex;align-items:center;gap:9px;margin:0 0 14px;font-size:14px;font-weight:600}
  #scheduleAppointmentDialog .schedule-capacity-icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border:1.5px solid #16a34a;border-radius:50%;color:#16a34a;font-size:11px}
  #scheduleAppointmentDialog .schedule-capacity.is-unavailable .schedule-capacity-icon{border-color:#dc2626;color:#dc2626}
  #scheduleAppointmentDialog .schedule-capacity.is-checking .schedule-capacity-icon{border-color:#71717a;color:#71717a}
  #scheduleAppointmentDialog .schedule-capacity-counts{display:flex;justify-content:space-between;gap:12px;color:#52525b;font-size:12px}
  #scheduleAppointmentDialog .schedule-capacity-counts strong{color:#18181b}
  #scheduleAppointmentDialog .schedule-capacity-bar{height:6px;margin-top:10px;overflow:hidden;border-radius:99px;background:#e4e4e7}
  #scheduleAppointmentDialog .schedule-capacity-fill{width:0;height:100%;border-radius:inherit;background:#16a34a;transition:width .2s ease}
  #scheduleAppointmentDialog .schedule-capacity.is-unavailable .schedule-capacity-fill{background:#dc2626}
  #scheduleAppointmentDialog .schedule-capacity-detail{margin:10px 0 0;color:#52525b;font-size:12px;line-height:1.4}
  #scheduleAppointmentDialog .schedule-dialog-error{margin:0;padding:9px 11px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#991b1b;font-size:13px;line-height:1.4}
  #scheduleAppointmentDialog .schedule-dialog-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:-2px}
  #scheduleAppointmentDialog .schedule-dialog-footer .sd-btn-outline{border-color:#e4e4e7;background:#fff;color:#18181b}
  #scheduleAppointmentDialog .schedule-dialog-footer .sd-btn-outline:hover{background:#f4f4f5}
  #scheduleAppointmentDialog .schedule-dialog-footer .sd-btn-default{background:#18181b;border-color:#18181b;color:#fff}
  #scheduleAppointmentDialog .schedule-dialog-footer .sd-btn:disabled{opacity:.5;cursor:not-allowed}
  @media(max-width:520px){#scheduleAppointmentDialog.modal{padding:8px}#scheduleAppointmentDialog .schedule-dialog-content{max-height:calc(100vh - 16px);padding:18px 16px 16px}#scheduleAppointmentDialog .schedule-time-grid{gap:7px}}
  #appointmentDetailsModal .appt-status-progress{display:flex;flex-direction:row;align-items:flex-start;padding:8px 0 16px;position:relative;margin:0 0 4px}
  #appointmentDetailsModal .appt-status-progress:before{content:'';position:absolute;top:17px;left:27px;right:27px;height:2px;background:#d4d4d8}
  #appointmentDetailsModal .appt-progress-step{display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;z-index:1;flex:1}
  #appointmentDetailsModal .appt-progress-dot{width:18px;height:18px;border-radius:50%;border:2px solid #d4d4d8;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;position:relative;color:#fff}
  #appointmentDetailsModal .appt-progress-dot.done{border-color:#16a34a;background:#16a34a;color:#fff}
  #appointmentDetailsModal .appt-progress-dot.active{border-color:#16a34a;background:#fff}
  #appointmentDetailsModal .appt-progress-label{font-family:Inter,'Segoe UI',sans-serif;font-size:11px;font-weight:500;color:#a1a1aa;text-align:center;white-space:normal}
  #appointmentDetailsModal .appt-progress-label.active-label{color:#18181b;font-weight:600}
  #appointmentDetailsModal .staff-reschedule-review{margin:0 0 18px;padding:16px;border:1px solid #e4e4e7;border-radius:8px;background:#fafafa;font-family:'Inter','Segoe UI',sans-serif}
  #appointmentDetailsModal .staff-reschedule-review[hidden],
  #appointmentDetailsModal .staff-reschedule-controls[hidden],
  #appointmentDetailsModal .staff-reschedule-form[hidden],
  #appointmentDetailsModal .staff-reschedule-decline-form[hidden],
  #appointmentDetailsModal .staff-reschedule-warning[hidden],
  #appointmentDetailsModal .staff-reschedule-note[hidden]{display:none!important}
  #appointmentDetailsModal .staff-reschedule-title{font-size:15px;font-weight:600;color:#18181b}
  #appointmentDetailsModal .staff-reschedule-narrative{margin-top:8px;color:#3f3f46;font-size:14px;font-weight:400;line-height:1.5}
  #appointmentDetailsModal .staff-reschedule-meta{display:grid;gap:5px;margin-top:10px}
  #appointmentDetailsModal .staff-reschedule-meta p{margin:0;color:#52525b;font-size:13px;line-height:1.45}
  #appointmentDetailsModal .staff-reschedule-meta strong{color:#3f3f46;font-weight:600}
  #appointmentDetailsModal .staff-reschedule-note,
  #appointmentDetailsModal .staff-reschedule-availability,
  #appointmentDetailsModal .staff-reschedule-warning{margin-top:10px;color:#52525b;font-size:13px;line-height:1.45}
  #appointmentDetailsModal .staff-reschedule-warning{color:#9a3412}
  #appointmentDetailsModal .staff-reschedule-controls{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
  #appointmentDetailsModal .staff-reschedule-decline{color:#b91c1c;border-color:#fecaca}
  #appointmentDetailsModal .staff-reschedule-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
  #appointmentDetailsModal .staff-reschedule-field{display:flex;min-width:0;flex-direction:column;gap:6px;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1.25}
  #appointmentDetailsModal .staff-reschedule-field-full{grid-column:1/-1}
  #appointmentDetailsModal .staff-reschedule-field input,
  #appointmentDetailsModal .staff-reschedule-field select,
  #appointmentDetailsModal .staff-reschedule-field textarea{box-sizing:border-box;width:100%;min-width:0;min-height:36px;padding:8px 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;box-shadow:0 1px 2px rgba(0,0,0,.05);outline:none}
  #appointmentDetailsModal .staff-reschedule-field textarea{resize:vertical}
  #appointmentDetailsModal .staff-reschedule-field input:focus,
  #appointmentDetailsModal .staff-reschedule-field select:focus,
  #appointmentDetailsModal .staff-reschedule-field textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b}
  #appointmentDetailsModal .staff-reschedule-form-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px}
  #appointmentDetailsModal .appt-outcome-unavailable{opacity:.55;cursor:not-allowed}
  #staffEditAppointmentModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:1000; }
  #staffEditAppointmentModal .sd-dialog-content{ max-width:520px; gap:20px; }
  #staffEditAppointmentModal .sd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
  #staffEditAppointmentModal .sd-field{ display:flex; flex-direction:column; gap:5px; }
  #staffEditAppointmentModal .sd-input{ width:100%; height:36px; padding:0 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; background:#fff; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,.05); outline:none; box-sizing:border-box; }
  #staffEditAppointmentModal textarea.sd-input{ height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
  #staffEditAppointmentModal .sd-input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
  #staffEditAppointmentModal .sd-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1.25; color:#18181b; }
  @media (max-width:560px){ #staffEditAppointmentModal .sd-form-grid{ grid-template-columns:1fr; } }
  .appt-request-lookup-row{ display:flex; align-items:center; gap:8px; }
  .appt-request-lookup-row .sd-input{ flex:1; min-width:0; }
  @media (max-width:480px){ .appt-request-lookup-row{ align-items:stretch; flex-direction:column; } }
  /* Details content layout (kept on the drawer) */
  #appointmentDetailsModal .sd-field-group{ gap:22px; }
  #appointmentDetailsModal .ec-detail-rows{ display:flex; flex-direction:column; gap:16px; }
  #appointmentDetailsModal .ec-detail-row{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; }
  #appointmentDetailsModal .ec-detail-row .sd-label{ margin:0; }
  #appointmentDetailsModal .ec-detail-row .sd-value{ text-align:right; }
#rescheduleAppointmentModal .sd-label{ font-size:13px; font-weight:400; line-height:1.4; color:#71717a; }
#rescheduleAppointmentModal .sd-value{ font-size:15px; font-weight:600; line-height:1.4; color:#18181b; word-break:break-word; }
.sd-field-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px 20px; }
.sd-detail-cols{ display:grid; grid-template-columns:1fr 1fr; gap:16px 36px; }
.sd-detail-cols > div{ display:flex; flex-direction:column; gap:16px; }
.sd-detail-cols > div:last-child{ text-align:right; }
.sd-value{ font-size:14px; font-weight:500; color:#18181b; line-height:1.4; word-break:break-word; }
.pet-chip{ display:inline-flex; align-items:center; padding:0; border-radius:0; background:none; color:#18181b; font-size:12.5px; font-weight:500; }
.pet-chip:not(:first-child)::before{ content:'\b7'; margin-right:8px; color:#a1a1aa; }
.resident-id-chip{ display:inline-flex; align-items:center; padding:2px 9px; border-radius:20px; background:#eef2f6; color:#64748b; font-size:11.5px; font-weight:600; font-family:'Inter','Segoe UI',sans-serif; letter-spacing:.02em; white-space:nowrap; }
.copy-id-btn{ display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:4px 8px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s, border-color .15s, color .15s; flex-shrink:0; text-align:center; }
.copy-id-btn:hover{ background:#f4f4f5; border-color:#cbd5e1; }
.copy-id-btn.copied{ background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
  @media (max-width:560px){ .sd-field-grid{ grid-template-columns:1fr; } .sd-detail-cols{ grid-template-columns:1fr; } }
</style>
<div id="addAppointmentModal" class="modal">
  <form id="addAppointmentForm" method="POST" class="sd-dialog">
    <input type="hidden" name="appointment_id" id="formApptId">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="APP.reviewClose()" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Review Appointment Request</h2>
        <p class="sd-dialog-description">Review the request details and approve or reject it.</p>
      </div>
      <div class="sd-field">
        <label class="sd-label" for="lookupRequestId">Quick Request ID Lookup</label>
        <div class="appt-request-lookup-row">
          <input type="search" id="lookupRequestId" class="sd-input" placeholder="#APT-J8CBG8 or appointment ID" autocomplete="off" onkeydown="if(event.key === 'Enter'){event.preventDefault();APP.reviewLookup();}">
          <button type="button" class="sd-btn sd-btn-outline" onclick="APP.reviewLookup()">Lookup</button>
        </div>
      </div>
      <small id="reviewProgress" style="display:none; color:#71717a; font-size:12.5px; line-height:1.5; margin:-10px 0 0;"></small>
      <div class="sd-field-group">
        <div class="sd-field-grid">
          <div class="sd-field">
            <label class="sd-label" for="formResidentName">Resident Name</label>
            <input type="text" id="formResidentName" readonly class="sd-input" placeholder="-">
          </div>
          <div class="sd-field">
            <label class="sd-label" for="formPetName">Pet</label>
            <input type="text" id="formPetName" readonly class="sd-input" placeholder="-">
          </div>
          <div class="sd-field">
            <label class="sd-label" for="formApptType">Service Type</label>
            <input type="text" id="formApptType" readonly class="sd-input" placeholder="-">
          </div>
          <div class="sd-field">
            <label class="sd-label" for="formDate">Appointment Date</label>
            <input type="date" id="formDate" readonly class="sd-input">
          </div>
          <div class="sd-field">
            <label class="sd-label" for="formTime">Appointment Time</label>
            <input type="time" id="formTime" readonly class="sd-input">
          </div>
          <div class="sd-field">
            <label class="sd-label" for="formStatus">Decision <span style="color:#dc2626;">*</span></label>
            <select name="status" id="formStatus" required class="sd-input">
              <option value="">Select decision</option>
              <option value="scheduled">Approve &amp; Schedule</option>
              <option value="rejected">Reject</option>
            </select>
          </div>
        </div>
        <div id="formSlotStatus" style="font-size:12px;padding:6px 10px;border-radius:6px;display:none;"></div>
        <div class="sd-field">
          <label class="sd-label" for="formRequestNotes">Request Notes</label>
          <textarea id="formRequestNotes" readonly rows="2" class="sd-input" placeholder="-"></textarea>
        </div>
        <div id="rejectReasonWrap" style="display:none;">
          <div class="sd-field">
            <label class="sd-label" for="formRejectReason">Rejection Reason <span style="color:#dc2626;">*</span></label>
            <textarea name="reason" id="formRejectReason" rows="2" class="sd-input" placeholder="Explain why this appointment request is being rejected..."></textarea>
          </div>
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="APP.reviewClose()">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default" id="formSubmitBtn">Submit Decision</button>
      </div>
    </div>
  </form>
</div>

<!-- Reschedule Appointment Modal (shadcn Dialog) -->
<div id="rescheduleAppointmentModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1000;">
  <form class="sd-dialog" onsubmit="submitStaffReschedule(event)">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('rescheduleAppointmentModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Reschedule Appointment</h2>
        <p class="sd-dialog-description">Choose a new date and time for this appointment.</p>
      </div>
      <input type="hidden" id="rescheduleApptId">
      <div class="sd-field-group">
        <div class="sd-field"><span class="sd-label">Request ID</span><div class="sd-value" id="rescheduleApptRef">-</div></div>
        <div class="sd-field-grid">
          <div class="sd-field"><span class="sd-label">Resident</span><div class="sd-value" id="rescheduleApptResident">-</div></div>
          <div class="sd-field"><span class="sd-label">Pet</span><div class="sd-value" id="rescheduleApptPet">-</div></div>
        </div>
        <div class="sd-field-grid">
          <div class="sd-field"><label class="sd-label" for="rescheduleApptDate">New date</label><input class="sd-input" type="date" id="rescheduleApptDate" required></div>
          <div class="sd-field"><label class="sd-label" for="rescheduleApptTime">New time</label><input class="sd-input" type="time" id="rescheduleApptTime" required></div>
        </div>
        <div id="rescheduleApptMessage" style="display:none;font-size:12px;padding:8px 10px;border-radius:6px;"></div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('rescheduleAppointmentModal')">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default" id="rescheduleApptSubmit">Reschedule</button>
      </div>
    </div>
  </form>
</div>

<!-- Owner Profile Modal -->
<div id="ownerProfileModal" class="modal">
  <div class="modal-content" style="max-width:640px;">
    <div class="modal-header">
      <h2>Owner Profile</h2>
      <button class="modal-close" onclick="closeModal('ownerProfileModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <!-- Owner Information Section -->
      <div id="ownerInfo" style="background:#f8fafc; border-radius:12px; padding:20px; margin-bottom:20px; border:1px solid #e2e8f0;">
        <h3 style="margin:0 0 16px; font-size:1.1rem; font-weight:700; color:#1e293b;">Resident Information</h3>
        <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:16px;">
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Resident ID</label>
            <div id="modalResidentId" style="color:#334155;">-</div>
          </div>
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Full Name</label>
            <div id="modalFullName" style="font-weight:600; color:#334155;">-</div>
          </div>
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Email Address</label>
            <div id="modalEmail" style="color:#334155;">-</div>
          </div>
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Mobile Number</label>
            <div id="modalPhone" style="color:#334155;">-</div>
          </div>
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Barangay</label>
            <div id="modalBarangay" style="color:#334155;">-</div>
          </div>
          <div>
            <label style="font-size:0.8rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Registration Date</label>
            <div id="modalRegDate" style="color:#334155;">-</div>
          </div>
        </div>
      </div>

      <!-- Pets Section -->
      <div>
        <h3 style="margin:0 0 16px; font-size:1.1rem; font-weight:700; color:#1e293b;">Registered Pets</h3>
        <div id="petsList" style="display:grid; gap:12px;">
          <!-- Pet cards will be dynamically loaded here -->
        </div>
        <div id="noPetsMessage" style="text-align:center; padding:30px; color:#94a3b8; display:none;">
          <p>No pets registered for this resident.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Appointment Details Modal (shadcn Dialog) -->
<div id="appointmentDetailsModal" class="modal staff-detail-drawer">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('appointmentDetailsModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header" id="apptDrawerHeader" style="padding-bottom:12px;">
        <h2 class="sd-dialog-title">Appointment Details</h2>
        <p class="sd-dialog-description">Complete details of this appointment request.</p>
      </div>
      <div class="rpt-tabs" role="tablist" aria-label="Appointment details sections">
        <button type="button" class="rpt-tab active" id="apptDetailsTab" role="tab" aria-selected="true" aria-controls="apptDetailDetailsPanel" onclick="switchAppointmentDetailTab('details')">Details</button>
        <button type="button" class="rpt-tab" id="apptHistoryTab" role="tab" aria-selected="false" aria-controls="apptDetailHistoryPanel" onclick="switchAppointmentDetailTab('history')">History</button>
      </div>
      <div class="sd-field-group" id="apptDetailDetailsPanel" role="tabpanel" aria-labelledby="apptDetailsTab" style="flex:1;min-height:0;overflow-y:auto;">
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">
          <div id="detailAvatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">
            <i class="fas fa-user"></i>
          </div>
          <div style="display:flex;flex-direction:column;gap:2px;min-width:0;">
            <span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Requested by</span>
            <span id="detailClient" style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">-</span>
            <button type="button" class="copy-id-btn" id="copyApptIdBtn" onclick="copyAppointmentId()" style="margin-top:4px;width:fit-content;">
              <span id="copyApptIdLabel">Copy ID</span>
            </button>
          </div>
          <span id="detailStatus" style="margin-left:auto;font-size:13px;font-weight:500;padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;">-</span>
        </div>
        <div id="apptStatusProgress" class="appt-status-progress" role="list" aria-label="Appointment progress"></div>
        <section id="staffRescheduleReview" class="staff-reschedule-review" hidden aria-live="polite">
          <div class="staff-reschedule-title">Reschedule request</div>
          <div id="staffRescheduleSlots" class="staff-reschedule-narrative"></div>
          <div id="staffRescheduleResident" class="staff-reschedule-meta"></div>
          <div id="staffRescheduleUnconfirmed" class="staff-reschedule-note">The requested time isn’t confirmed yet. Review the request before approving or declining it.</div>
          <div id="staffRescheduleAvailability" class="staff-reschedule-availability" role="status"></div>
          <div id="staffRescheduleCutoffWarning" class="staff-reschedule-warning" hidden>This appointment is now less than 24 hours away. Staff approval is still allowed.</div>
          <div id="staffRescheduleWaiting" class="staff-reschedule-note" hidden>An alternate time was offered. Waiting for the resident’s reply.</div>
          <div id="staffRescheduleControls" class="staff-reschedule-controls">
            <button type="button" class="sd-btn sd-btn-default" id="staffRescheduleApprove" onclick="submitStaffRescheduleDecision('approve')">Approve</button>
            <button type="button" class="sd-btn sd-btn-outline" onclick="toggleStaffRescheduleForm('suggest')">Suggest another time</button>
            <button type="button" class="sd-btn sd-btn-outline staff-reschedule-decline" onclick="toggleStaffRescheduleForm('decline')">Decline</button>
          </div>
          <div id="staffRescheduleSuggestForm" class="staff-reschedule-form" hidden>
            <label class="staff-reschedule-field">Date<input type="date" id="staffRescheduleDate"></label>
            <label class="staff-reschedule-field">Time<select id="staffRescheduleTime"><option value="">Choose time</option><option value="09:00">9:00 AM</option><option value="10:00">10:00 AM</option><option value="11:00">11:00 AM</option><option value="14:00">2:00 PM</option><option value="15:00">3:00 PM</option></select></label>
            <label class="staff-reschedule-field">Priority<select id="staffRescheduleUrgency"><option value="routine">Routine</option><option value="urgent">Urgent</option></select></label>
            <label class="staff-reschedule-field staff-reschedule-field-full">Message (optional)<textarea id="staffRescheduleMessage" rows="2" maxlength="2000"></textarea></label>
            <div id="staffRescheduleSuggestAvailability" class="staff-reschedule-availability staff-reschedule-field-full" role="status">Choose a date and time to check availability.</div>
            <div class="staff-reschedule-form-actions"><button type="button" class="sd-btn sd-btn-outline" onclick="toggleStaffRescheduleForm('')">Back</button><button type="button" class="sd-btn sd-btn-default" id="staffRescheduleSuggest" onclick="submitStaffRescheduleDecision('suggest')" disabled>Send suggestion</button></div>
          </div>
          <div id="staffRescheduleDeclineForm" hidden class="staff-reschedule-decline-form">
            <label class="staff-reschedule-field staff-reschedule-field-full">Reason required<textarea id="staffRescheduleReason" rows="3" maxlength="2000"></textarea></label>
            <div class="staff-reschedule-form-actions"><button type="button" class="sd-btn sd-btn-outline" onclick="toggleStaffRescheduleForm('')">Back</button><button type="button" class="sd-btn sd-btn-outline staff-reschedule-decline" onclick="submitStaffRescheduleDecision('decline')">Decline request</button></div>
          </div>
        </section>
        <div>
          <div class="rpt-section-title-action" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:18px 0 10px;"><span style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;">Resident Information</span></div>
          <div class="appt-detail-values-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;">
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Resident ID</p><p id="detailResidentId" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Mobile Number</p><p id="detailPhone" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Email</p><p id="detailEmail" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
          </div>
        </div>
        <div>
          <div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;">Appointment Information</div>
          <div class="appt-detail-values-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;">
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Service</p><p id="detailType" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Pet</p><p id="detailPet" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Preferred Date</p><p id="detailPreferredDate" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Preferred Time</p><p id="detailPreferredTime" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Final Scheduled Date</p><p id="detailFinalDate" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Final Scheduled Time</p><p id="detailFinalTime" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Barangay</p><p id="detailBrgy" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Address</p><p id="detailAddress" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
          </div>
        </div>
        <div>
          <div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;">Pet Details</div>
          <div class="appt-detail-values-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;">
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Species</p><p id="detailPetType" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Breed</p><p id="detailPetBreed" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Sex</p><p id="detailPetGender" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Age</p><p id="detailPetAge" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
            <div><p style="font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Vaccination Status</p><p id="detailPetVax" style="font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">-</p></div>
          </div>
        </div>
        <div id="detailNotesContainer" style="margin-top:8px;">
          <div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;">Notes</div>
          <div id="detailNotes" style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">-</div>
        </div>
        <p id="apptOutcomeGuidance" style="display:none;margin:8px 0 0;color:#52525b;font-size:13px;line-height:1.45;"></p>
      </div>
      <div class="sd-field-group appt-history-panel" id="apptDetailHistoryPanel" role="tabpanel" aria-labelledby="apptHistoryTab" style="display:none;">
        <div id="apptDetailHistoryContent" aria-live="polite"><p class="appt-history-empty">Loading appointment history…</p></div>
      </div>
        <div id="apptBulkReviewMessage" style="display:none;color:#991b1b;background:#fef2f2;border-radius:6px;padding:8px 10px;font-size:13px;line-height:1.45;"></div>
        <div id="apptBulkRejectPanel" style="display:none;">
          <label for="apptBulkRejectReason" style="display:block;font-size:13px;font-weight:600;color:#52525b;margin-bottom:6px;">Rejection Reason <span style="color:#dc2626;">*</span></label>
          <textarea id="apptBulkRejectReason" rows="3" class="sd-input" placeholder="Explain why this appointment request is being rejected..."></textarea>
        </div>
      <div class="sd-dialog-footer" id="apptDetailFooter" style="display:flex;justify-content:center;gap:8px;">
        <button type="button" class="sd-btn sd-btn-default" onclick="closeModal('appointmentDetailsModal')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Staff Appointment Edit Dialog -->
<div id="staffEditAppointmentModal" class="modal">
  <form class="modal-form sd-dialog" action="<?php echo htmlspecialchars($appBaseUrl . '/staff-add-appointment.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('staffEditAppointmentModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Edit Appointment</h2>
        <p class="sd-dialog-description">Update the appointment request details.</p>
      </div>
      <div class="sd-field-group">
        <input type="hidden" name="appointment_id" id="staffEditAppointmentId">
        <input type="hidden" name="user_id" id="staffEditAppointmentUserId">
        <input type="hidden" name="pet_id" id="staffEditAppointmentPetId">
        <input type="hidden" name="waitlist_id" id="staffEditAppointmentWaitlistId">
        <input type="hidden" name="return_section" value="appointments">
        <div class="sd-form-grid">
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentType">Appointment Type <span class="req">*</span></label>
            <select name="appointment_type" id="staffEditAppointmentType" class="sd-input" required>
              <option value="adoption">Adoption</option><option value="kapon">Kapon (Spay/Neuter)</option><option value="checkup">Check-up</option><option value="deworming">Deworming</option><option value="vaccination">Vaccination</option>
            </select>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentUrgency">Priority</label>
            <select name="urgency_level" id="staffEditAppointmentUrgency" class="sd-input">
              <option value="routine">Routine</option><option value="urgent">Urgent</option>
            </select>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentPet">Pet</label>
            <input type="text" id="staffEditAppointmentPet" class="sd-input" readonly>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentDate">Preferred Date <span class="req">*</span></label>
            <input type="date" name="preferred_date" id="staffEditAppointmentDate" class="sd-input" required>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentTime">Preferred Time <span class="req">*</span></label>
            <input type="time" name="preferred_time" id="staffEditAppointmentTime" class="sd-input" required>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="staffEditAppointmentStatus">Status <span class="req">*</span></label>
            <select name="status" id="staffEditAppointmentStatus" class="sd-input" required>
              <option value="pending">Pending</option><option value="approved">Approved</option><option value="scheduled">Scheduled</option><option value="rescheduled">Rescheduled</option><option value="completed">Completed</option>
            </select>
          </div>
        </div>
        <div class="sd-field">
          <label class="sd-label" for="staffEditAppointmentNotes">Notes</label>
          <textarea name="notes" id="staffEditAppointmentNotes" class="sd-input" rows="3" placeholder="Add appointment notes..."></textarea>
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('staffEditAppointmentModal')">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default">Save Appointment</button>
      </div>
    </div>
  </form>
</div>

<div id="archiveApptModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title">Are you absolutely sure?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">This action cannot be undone. Archive appointment for <span id="archiveApptPet" style="color:#18181b;">this appointment</span> from <span id="archiveApptResident" style="color:#18181b;">this client</span>?</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-default" onclick="closeModal('archiveApptModal')">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="archiveApptConfirmBtn" onclick="executeArchiveAppt()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Continue</button>
    </div>
  </div>
</div>

<!-- Resolve Case Modal (shadcn dialog) -->
<div class="modal" id="resolveModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="resolveCaseTitle" aria-describedby="resolveCaseDescription" style="max-width:470px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('resolveModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="resolveCaseTitle">Mark Case as Resolved</h2>
        <p class="sd-dialog-description" id="resolveCaseDescription">Confirm the resolution details before closing this case and notifying the resident and barangay.</p>
      </div>
      <form id="resolveForm" action="<?php echo htmlspecialchars($appBaseUrl . '/resolve-report.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST" style="display:contents;">
        <div class="sd-field-group">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="case_id" id="resolveCaseId" value="">
          <div class="sd-field">
            <div style="background:#eef6f0;border:1px solid #b8d1c0;border-radius:8px;padding:12px 16px;">
              <p style="margin:0;font-size:14px;color:#065f46;line-height:1.5;">
                <i class="fas fa-info-circle"></i>
                You are about to mark the <strong id="resolveCaseRef">case</strong> as resolved.
                This will close the case and notify the resident and barangay.
              </p>
            </div>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="resolveDate">Date Resolved <span style="color:#dc2626;">*</span></label>
            <input type="date" name="resolved_date" id="resolveDate" class="sd-input" required>
          </div>
          <div class="sd-field">
            <label class="sd-label" for="resolveTime">Time Resolved <span style="color:#dc2626;">*</span></label>
            <input type="time" name="resolved_time" id="resolveTime" class="sd-input" required>
          </div>
          <div class="sd-field">
            <span class="sd-label">Resolution Notes</span>
            <textarea name="notes" rows="4" class="sd-input" placeholder="Describe how the case was resolved (e.g., animal rescued, owner found, treatment completed)..."></textarea>
          </div>
        </div>
        <div class="sd-dialog-footer">
          <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('resolveModal')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-check"></i> Mark as Resolved</button>
        </div>
      </form>
    </div>
  </div>
  </div>

  <script>
// Toggle rejection reason field
document.addEventListener('DOMContentLoaded', function() {
    var statusSel = document.getElementById('formStatus');
    var rejectWrap = document.getElementById('rejectReasonWrap');
    if (statusSel) {
        statusSel.addEventListener('change', function() {
            var isReject = statusSel.value === 'rejected';
            rejectWrap.style.display = isReject ? 'block' : 'none';
            var reason = document.getElementById('formRejectReason');
            reason.required = isReject;
        });
    }
});

function viewOwnerProfile(userId) {
    const modal = document.getElementById('ownerProfileModal');
    const petsList = document.getElementById('petsList');
    const noPetsMessage = document.getElementById('noPetsMessage');
    
    // Reset modal content
    document.getElementById('modalResidentId').textContent = 'Loading...';
    document.getElementById('modalFullName').textContent = 'Loading...';
    document.getElementById('modalEmail').textContent = 'Loading...';
    document.getElementById('modalPhone').textContent = 'Loading...';
    document.getElementById('modalBarangay').textContent = 'Loading...';
    document.getElementById('modalRegDate').textContent = 'Loading...';
    petsList.innerHTML = '';
    noPetsMessage.style.display = 'none';
    
    // Show modal
    modal.style.display = 'flex';
    
    // Fetch owner data
    fetch(window.dcvsStaffUrl('staff-get-owner-profile.php') + '?user_id=' + encodeURIComponent(userId))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const owner = data.owner;
                const pets = data.pets;
                
                // Populate owner information
                document.getElementById('modalResidentId').textContent = owner.reference_id ? '#' + owner.reference_id : '#RES-' + String(owner.user_id).padStart(4, '0');
                document.getElementById('modalFullName').textContent = owner.full_name;
                document.getElementById('modalEmail').textContent = owner.email || '-';
                document.getElementById('modalPhone').textContent = owner.phone || '-';
                document.getElementById('modalBarangay').textContent = owner.barangay_name || '-';
                document.getElementById('modalRegDate').textContent = new Date(owner.registration_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                
                // Populate pets list
                if (pets.length > 0) {
                    pets.forEach(pet => {
                        const petCard = document.createElement('div');
                        petCard.style.cssText = 'background:white; border:1px solid #e2e8f0; border-radius:12px; padding:0; display:flex; overflow:hidden;';
                        
                        var petRef = pet.reference_id ? '#' + pet.reference_id : '#PET-' + String(pet.pet_id).padStart(4, '0');
                        petCard.innerHTML = `
                            <div style="width:200px; height:200px; background:#0E8131; display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;">
                                ${pet.photo_url 
                                    ? `<img src="${window.dcvsStaffMediaUrl(pet.photo_url)}" alt="${pet.pet_name}" style="width:100%; height:100%; object-fit:cover;" />`
                                    : `<span style="font-size:3rem; color:white; font-weight:800;">${pet.pet_name.substring(0, 2).toUpperCase()}</span>`
                                }
                            </div>
                            <div style="flex:1; padding:24px; display:flex; flex-direction:column; justify-content:center;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                                    <h4 style="margin:0; font-size:1.5rem; font-weight:800; color:#1e293b;">${pet.pet_name}</h4>
                                    <span style="font-family:monospace; font-size:0.9rem; font-weight:600; color:#64748b; background:#f1f5f9; padding:4px 8px; border-radius:6px;">${petRef}</span>
                                </div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:1rem; color:#475569;">
                                    <div><span style="font-weight:600; color:#64748b;">Species:</span> ${pet.pet_type || 'Unknown'}</div>
                                    <div><span style="font-weight:600; color:#64748b;">Color/Markings:</span> ${pet.color || pet.breed || 'Unknown'}</div>
                                    <div><span style="font-weight:600; color:#64748b;">Sex:</span> ${pet.gender || 'Unknown'}</div>
                                    <div><span style="font-weight:600; color:#64748b;">Age:</span> ${pet.age ? pet.age + ' years' : 'Unknown'}</div>
                                </div>
                                <div style="margin-top:16px; display:flex; gap:8px; align-items:center;">
                                    <span class="status-pill status-${pet.vaccination_status === 'Fully vaccinated' ? 'confirmed' : 'pending'}" style="font-size:12px; font-weight:500; padding:3px 10px; border-radius:4px; font-family:'Inter',sans-serif; line-height:1.4; border:1px solid #e4e4e7;">${pet.vaccination_status || 'Not Specified'}</span>
                                    <a href="pet-profile.php?id=${pet.pet_id}&ref=staff" style="margin-left:auto; display:inline-flex; align-items:center; gap:4px; padding:6px 12px; background:#0E8131; color:#fff; border-radius:8px; font-size:0.85rem; font-weight:600; text-decoration:none;"><i class="fas fa-file-alt"></i> Resume</a>
                                </div>
                            </div>
                        `;
                        petsList.appendChild(petCard);
                    });
                } else {
                    noPetsMessage.style.display = 'block';
                }
            } else {
                alert('Error loading owner profile: ' + (data.error || 'Unknown error'));
                closeModal('ownerProfileModal');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            alert('Network error loading owner profile.');
            closeModal('ownerProfileModal');
        });
}

function openResolveModal(caseId, reportType, reporterName) {
    var form = document.getElementById('resolveForm');
    if (form) form.reset();
    document.getElementById('resolveCaseId').value = caseId;
    document.getElementById('resolveCaseRef').textContent = reportType + ' from ' + reporterName;
    openModal('resolveModal');
}

function openReportReview(reportId) {
    document.getElementById('confirmReportId').value = reportId;
  var scheduledAt = document.getElementById('confirmReportScheduledAt');
  if (scheduledAt) scheduledAt.value = '';
  var report = (window.RPT_ITEMS || []).find(function(item) { return String(item.id) === String(reportId); });
  var incidentAt = document.getElementById('confirmReportIncidentAt');
  if (incidentAt) {
    var rawIncidentDate = report && report.createdAt ? String(report.createdAt) : '';
    var incidentDate = rawIncidentDate ? new Date(rawIncidentDate.replace(' ', 'T')) : null;
    incidentAt.value = incidentDate && !isNaN(incidentDate.getTime())
      ? incidentDate.toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' })
      : 'Not provided';
  }
  updateConfirmReportAction();
    openModal('confirmReportModal');
}

function updateConfirmReportAction() {
  var scheduledAt = document.getElementById('confirmReportScheduledAt');
  var hasSchedule = !!(scheduledAt && scheduledAt.value);
  var title = document.getElementById('confirmReportTitle');
  var submit = document.getElementById('confirmReportSubmitBtn');
  if (title) title.textContent = hasSchedule ? 'Confirm & Schedule Report' : 'Confirm Report';
  if (submit) submit.innerHTML = hasSchedule
    ? '<i class="fas fa-check"></i> Confirm &amp; Schedule'
    : '<i class="fas fa-check"></i> Confirm Report';
}

var confirmReportScheduleField = document.getElementById('confirmReportScheduledAt');
if (confirmReportScheduleField) confirmReportScheduleField.addEventListener('input', updateConfirmReportAction);

window.dcvsStaffCaseBelongsInActiveCases = function(item) {
  var status = String(item && item.statusRaw || '');
  var barangayStates = ['barangay_response_approved', 'barangay_responding', 'animal_secured', 'returned_to_barangay'];
  return String(item && item.caseStatus || '') === 'active'
    && (status === 'confirmed' || status === 'scheduled')
    && barangayStates.indexOf(String(item && item.responseState || '')) === -1;
};

window.dcvsStaffCaseBelongsInBarangayQueue = function(item) {
  var state = String(item && item.responseState || '');
  var status = String(item && item.statusRaw || '');
  return ['barangay_response_approved', 'barangay_responding', 'animal_secured', 'returned_to_barangay'].indexOf(state) !== -1
    && status !== 'resolved'
    && status !== 'rejected';
};

window.dcvsStaffBarangayCaseStatusLabel = function(item) {
  var labels = {
    barangay_response_approved: 'Accepted by Barangay',
    barangay_responding: 'Barangay Responding',
    animal_secured: 'Animal Secured',
    returned_to_barangay: 'Returned to Barangay'
  };
  return labels[String(item && item.responseState || '')] || 'Accepted by Barangay';
};

window.dcvsStaffActiveCaseStatusLabel = function(item) {
  var labels = {
    dcvs_dispatch: 'DCVS Dispatch',
    dcvs_processing: 'DCVS Processing',
    escalated_to_dcvs: 'Escalated to DCVS',
    unable_to_locate: 'Unable to Locate',
    turned_over: 'Turned over to DCVS'
  };
  return labels[String(item && item.responseState || '')] || 'Active';
};

function updateStaffReportKpis() {
  var high = function(items) { return items.filter(function(item) { return /high|critical/i.test(item.urgency || ''); }).length; };
  var pendingWeek = PENDING_ITEMS.filter(function(item) { return Number(item.vts || 0) >= Date.now() - 7 * 86400000; }).length;
  var activeScheduled = ACTIVE_ITEMS.filter(function(item) { return !!item.scheduled; }).length;
  var activeOverdue = ACTIVE_ITEMS.filter(function(item) { return item.scheduled && new Date(item.scheduled).getTime() < Date.now(); }).length;
  var monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime();
  var yearStart = new Date(new Date().getFullYear(), 0, 1).getTime();
  var setValues = function(sectionId, values) {
    var nodes = document.querySelectorAll('#' + sectionId + ' .kpis .kpi-value');
    values.forEach(function(value, index) { if (nodes[index]) nodes[index].textContent = value; });
  };
  setValues('reports-pending', [PENDING_ITEMS.length, high(PENDING_ITEMS), pendingWeek, PENDING_ITEMS.length]);
  setValues('reports-active', [ACTIVE_ITEMS.length, high(ACTIVE_ITEMS), activeScheduled, activeOverdue]);
  var barangayItems = window.BARANGAY_RESPONSE_ITEMS || [];
  var acceptedBarangayCount = barangayItems.filter(function(item) {
    return ['barangay_response_approved', 'returned_to_barangay'].indexOf(String(item.responseState || '')) !== -1;
  }).length;
  var respondingBarangayCount = barangayItems.filter(function(item) {
    return item.responseState === 'barangay_responding';
  }).length;
  var securedBarangayCount = barangayItems.filter(function(item) {
    return item.responseState === 'animal_secured';
  }).length;
  setValues('reports-accepted', [
    barangayItems.length,
    acceptedBarangayCount,
    respondingBarangayCount,
    securedBarangayCount
  ]);
  setValues('reports-resolved', [
    RESOLVED_ITEMS.length,
    RESOLVED_ITEMS.filter(function(item) { return Number(item.ts || 0) >= monthStart; }).length,
    RESOLVED_ITEMS.filter(function(item) { return Number(item.ts || 0) >= yearStart; }).length,
    high(RESOLVED_ITEMS)
  ]);
}

function applyStaffReportTransition(data) {
  var reportId = String(data.report_id);
  var item = PENDING_ITEMS.find(function(r) { return String(r.id) === reportId; }) ||
    ACTIVE_ITEMS.find(function(r) { return String(r.id) === reportId; }) ||
    (window.BARANGAY_RESPONSE_ITEMS || []).find(function(r) { return String(r.id) === reportId; }) ||
    RESOLVED_ITEMS.find(function(r) { return String(r.id) === reportId; }) ||
    (window.RPT_ITEMS || []).find(function(r) { return String(r.id) === reportId; });
  if (!item) return;

  var nowLabel = data.confirmed_at || data.resolved_at || new Date().toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
  if (data.status === 'confirmed' || data.status === 'scheduled') {
    if (window.penRemoveItem) window.penRemoveItem(reportId);
    if (window.brgRemoveItem) window.brgRemoveItem(reportId);
    var scheduled = data.scheduled_at ? new Date(String(data.scheduled_at).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : '';
    var activeItem = Object.assign({}, item, {
      caseId: Number(data.case_id || item.caseId || item.id), caseStatus: 'active', status: 'active', statusRaw: data.status,
      statusLabel: window.dcvsStaffActiveCaseStatusLabel(item), confirmed: nowLabel, scheduled: scheduled
    });
    if (window.dcvsStaffCaseBelongsInActiveCases(activeItem)) {
      if (window.actAddItem) window.actAddItem(activeItem);
    } else if (window.actRemoveItem) {
      window.actRemoveItem(reportId);
    }
    if (window.rptUpdateItem) window.rptUpdateItem(reportId, {
      status: 'active', statusRaw: data.status, statusLabel: data.status === 'scheduled' ? 'Scheduled' : 'Active',
      caseStatus: 'active', caseId: activeItem.caseId, confirmed: nowLabel, scheduled: scheduled
    });
  } else if (data.status === 'resolved') {
    if (window.penRemoveItem) window.penRemoveItem(reportId);
    if (window.actRemoveItem) window.actRemoveItem(reportId);
    if (window.brgRemoveItem) window.brgRemoveItem(reportId);
    var resolvedTimestamp = data.resolved_at_raw
      ? new Date(String(data.resolved_at_raw).replace(' ', 'T')).getTime()
      : Date.now();
    var resolvedItem = Object.assign({}, item, {
      caseId: Number(data.case_id || item.caseId || item.id), caseStatus: 'resolved', status: 'resolved',
      statusLabel: 'Resolved', resolved: nowLabel, ts: Number.isFinite(resolvedTimestamp) ? resolvedTimestamp : Date.now()
    });
    if (window.resAddItem) window.resAddItem(resolvedItem);
    if (window.rptUpdateItem) window.rptUpdateItem(reportId, {
      status: 'resolved', statusRaw: 'resolved', statusLabel: 'Resolved', caseStatus: 'resolved',
      caseId: resolvedItem.caseId, resolved: nowLabel, ts: resolvedItem.ts
    });
  }
  updateStaffReportKpis();
}

function submitReportActionAjax(form, successTitle) {
  var button = form.querySelector('button[type="submit"]');
  var originalHtml = button ? button.innerHTML : '';
  if (button) { button.disabled = true; button.textContent = 'Saving…'; }
  var formData = new FormData(form);
  formData.set('ajax', '1');
  fetch(form.action, { method: 'POST', body: formData })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (!data || !data.success) {
        if (button) { button.disabled = false; button.innerHTML = originalHtml; }
        if (window.portalToast) portalToast('Could not update report', (data && data.message) || 'Please try again.');
        else alert((data && data.message) || 'Could not update report.');
        return;
      }
      applyStaffReportTransition(data);
      closeModal(form.id === 'confirmReportForm' ? 'confirmReportModal' : 'resolveModal');
      if (window.portalToast) portalToast(successTitle, data.message);
      if (button) { button.disabled = false; button.innerHTML = originalHtml; }
    })
    .catch(function() {
      if (button) { button.disabled = false; button.innerHTML = originalHtml; }
      if (window.portalToast) portalToast('Network error', 'The report update could not be saved. Please try again.');
      else alert('The report update could not be saved. Please try again.');
    });
}

var confirmReportForm = document.getElementById('confirmReportForm');
if (confirmReportForm) confirmReportForm.addEventListener('submit', function(event) {
  event.preventDefault();
  submitReportActionAjax(this, 'Report updated');
});

var rejectReportForm = document.getElementById('rejectReportForm');
if (rejectReportForm) rejectReportForm.addEventListener('submit', function(event) {
  if (!window.DCVS_BARANGAY_RESPONSE_ENABLED) return;
  event.preventDefault();
  var form = this;
  var button = form.querySelector('button[type="submit"]');
  var originalHtml = button ? button.innerHTML : '';
  if (button) {
    button.disabled = true;
    button.textContent = 'Rejecting...';
  }
  var formData = new FormData(form);
  formData.set('action', 'reject_report');
  formData.set('csrf_token', window.DCVS_RESPONSE_CSRF || '');
  fetch(window.dcvsStaffUrl('response-workflow.php'), { method: 'POST', body: formData })
    .then(function(response) {
      return response.json().then(function(data) {
        if (!response.ok || !data || !data.success) {
          throw new Error((data && data.message) || 'The report could not be rejected.');
        }
        return data;
      });
    })
    .then(function() {
      closeModal('rejectReportModal');
      window.location.reload();
    })
    .catch(function(error) {
      if (button) {
        button.disabled = false;
        button.innerHTML = originalHtml;
      }
      if (window.portalToast) window.portalToast('Could not reject report', error.message);
      else alert(error.message);
    });
});

var resolveReportForm = document.getElementById('resolveForm');
if (resolveReportForm) resolveReportForm.addEventListener('submit', function(event) {
  event.preventDefault();
  if (window.rptHandleResolveForm && window.rptHandleResolveForm(this)) return;
  submitReportActionAjax(this, 'Case resolved');
});

function openRejectionModal(reportId) {
    document.getElementById('rejectReportId').value = reportId;
    openModal('rejectReportModal');
}

// â�?��,�â�?��,� OWNER SEARCH + PAGINATION â�?��,�â�?��,�
(function() {
    var PER_PAGE_OWNER = 10;
    var tbody = document.querySelector('#ownersTable tbody');
    var pag = document.getElementById('ownerPagination');
    if (!tbody || !pag) return;
    var allRows = Array.from(tbody.querySelectorAll('tr:not(#ownerNoResults)'));
    var currentPage = 1;
    var filterSearch = '';

    function getFiltered() {
        return allRows.filter(function(r) {
            return !filterSearch || r.textContent.toLowerCase().includes(filterSearch);
        });
    }

    function renderTable() {
        var filtered = getFiltered();
        allRows.forEach(function(r) { r.style.display = 'none'; });
        var start = (currentPage - 1) * PER_PAGE_OWNER;
        var end = start + PER_PAGE_OWNER;
        filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
        var noRes = document.getElementById('ownerNoResults');
        if (noRes) noRes.style.display = filtered.length === 0 ? '' : 'none';
        renderPagination(filtered.length);
    }

    function renderPagination(total) {
        var tp = Math.ceil(total / PER_PAGE_OWNER);
        if (tp <= 1) { pag.innerHTML = ''; return; }
        var h = '';
        h += '<span class="pag-info">Showing ' + ((currentPage-1)*PER_PAGE_OWNER+1) + '\u2013' + Math.min(currentPage*PER_PAGE_OWNER, total) + ' of ' + total + ' owners</span>';
        h += '<div class="pag-btns">';
        h += '<a href="#" onclick="window.ownerPageGo(' + (currentPage - 1) + ');return false;" class="pag-btn"' + (currentPage <= 1 ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-left"></i></a>';
        h += '<a href="#" onclick="window.ownerPageGo(' + (currentPage + 1) + ');return false;" class="pag-btn"' + (currentPage >= tp ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-right"></i></a>';
        h += '</div>';
        pag.innerHTML = h;
    }

    window.ownerPageGo = function(page) {
        var total = Math.ceil(getFiltered().length / PER_PAGE_OWNER);
        if (page < 1 || page > total) return;
        currentPage = page;
        renderTable();
    };

    var ownerSearch = document.getElementById('ownerSearch');
    if (ownerSearch) {
        ownerSearch.addEventListener('keyup', function() {
            filterSearch = this.value.toLowerCase().trim();
            currentPage = 1;
            renderTable();
        });
    }

    renderTable();
})();

// â�?��,�â�?��,� APPOINTMENTS: status/search filter + pagination â�?��,�â�?��,�
(function() {
    var PER_PAGE_APPT = 10;
    var tbody = document.querySelector('#appointmentsTable tbody');
    var pag = document.getElementById('apptPagination');
    if (!tbody || !pag) return;
    var allRows = Array.from(tbody.querySelectorAll('tr'));
    allRows = allRows.filter(function(r) { return r.tagName === 'TR' && r.id !== 'apptNoResults'; });
    var currentPage = 1;
    var filterStatus = 'all';
    var filterSearch = '';
    var filterDate = '';
    var filterRescheduleOnly = false;

    function getStatus(row) {
        var st = row.getAttribute('data-status');
        if (st) return st.trim().toLowerCase().replace(/\s+/g, '_');
        var cells = row.querySelectorAll('td');
        return cells.length >= 9 ? (cells[8].textContent || '').trim().toLowerCase().replace(/\s+/g, '_') : '';
    }

    function getFiltered() {
        return allRows.filter(function(r) {
            var status = getStatus(r);
            var text = r.textContent.toLowerCase();
            var matchS = filterStatus === 'all' || status === filterStatus;
            var matchQ = !filterSearch || text.includes(filterSearch);
            var rowDate = (r.getAttribute('data-date-key') || '').slice(0, 10);
            var matchD = !filterDate || rowDate === filterDate;
            var matchR = !filterRescheduleOnly || r.getAttribute('data-reschedule-request') === 'pending';
            return matchS && matchQ && matchD && matchR;
        });
    }

    window.apptSetDate = function() {
        filterDate = document.getElementById('apptDateFrom').value;
        currentPage = 1;
        renderTable();
    };

    function setAppointmentResidentCell(cell, appt) {
        if (!cell) return;
        var clientCell = cell.querySelector('.client-cell');
        if (!clientCell) {
          cell.textContent = '';
          clientCell = document.createElement('div');
          clientCell.className = 'client-cell';
          cell.appendChild(clientCell);
        }
        var avatar = clientCell.querySelector('.avatar');
        if (!avatar) {
          avatar = document.createElement('div');
          avatar.className = 'avatar';
          clientCell.insertBefore(avatar, clientCell.firstChild);
        }
        var name = clientCell.querySelector('.contact-name');
        if (!name) {
          name = document.createElement('div');
          name.className = 'contact-main contact-name';
          clientCell.appendChild(name);
        }
        var currentImage = avatar.querySelector('img');
        var avatarUrl = appt.avatar_url || appt.profile_picture || (currentImage && currentImage.getAttribute('src'));
        var residentName = appt.full_name || name.textContent || '-';
        while (avatar.firstChild) avatar.removeChild(avatar.firstChild);
        if (avatarUrl) {
          var image = document.createElement('img');
          image.src = window.dcvsStaffMediaUrl(avatarUrl);
          image.alt = '';
          avatar.appendChild(image);
        } else {
          avatar.textContent = residentName.trim().split(/\s+/).filter(Boolean).slice(0, 2).map(function(part) {
            return part.charAt(0).toUpperCase();
          }).join('') || '?';
        }
        name.textContent = residentName;
    }

    function setAppointmentPetCell(cell, appt) {
        if (!cell) return;
        var clientCell = cell.querySelector('.client-cell');
        if (!clientCell) {
          cell.textContent = '';
          clientCell = document.createElement('div');
          clientCell.className = 'client-cell';
          cell.appendChild(clientCell);
        }
        var avatar = clientCell.querySelector('.avatar');
        if (!avatar) {
          avatar = document.createElement('div');
          avatar.className = 'avatar';
          clientCell.insertBefore(avatar, clientCell.firstChild);
        }
        var name = clientCell.querySelector('.pet-name-value');
        if (!name) {
          name = document.createElement('div');
          name.className = 'contact-main contact-normal pet-name-value';
          clientCell.appendChild(name);
        }
        var currentImage = avatar.querySelector('img');
        var photoUrl = appt.photo_url || appt.pet_photo_url || (currentImage && currentImage.getAttribute('src'));
        while (avatar.firstChild) avatar.removeChild(avatar.firstChild);
        if (photoUrl) {
          var image = document.createElement('img');
          image.src = window.dcvsStaffMediaUrl(photoUrl);
          image.alt = '';
          avatar.appendChild(image);
        } else {
          var icon = document.createElement('i');
          icon.className = 'fas fa-paw';
          avatar.appendChild(icon);
        }
        name.textContent = appt.pet_name || '-';
    }

    function setAppointmentContactCell(cell, appt) {
        if (!cell) return;
        var email = cell.querySelector('.appointment-email');
        if (!email) {
          email = document.createElement('div');
          email.className = 'contact-main contact-normal appointment-email';
          cell.appendChild(email);
        }
        var phone = cell.querySelector('.appointment-phone');
        if (!phone) {
          phone = document.createElement('div');
          phone.className = 'contact-sub appointment-phone';
          cell.appendChild(phone);
        }
        email.textContent = appt.email || email.textContent || '-';
        phone.textContent = appt.phone || phone.textContent || '-';
    }

    function renderTable() {
        var filtered = getFiltered();
        allRows.forEach(function(r) { r.style.display = 'none'; });
        var start = (currentPage - 1) * PER_PAGE_APPT;
        var end = start + PER_PAGE_APPT;
        filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
        var noRes = document.getElementById('apptNoResults');
        if (noRes) noRes.style.display = filtered.length === 0 ? '' : 'none';
        renderPagination(filtered.length);
        var counts = { pending: 0, scheduled: 0, completed: 0 };
        allRows.forEach(function(row) {
          var status = getStatus(row);
          if (status === 'rescheduled' || status === 'approved') status = 'scheduled';
          if (Object.prototype.hasOwnProperty.call(counts, status)) counts[status]++;
        });
        var kpis = document.querySelectorAll('#appointments .kpis .kpi-value');
        if (kpis[0]) kpis[0].textContent = allRows.length;
        if (kpis[1]) kpis[1].textContent = counts.pending;
        if (kpis[2]) kpis[2].textContent = counts.scheduled;
        if (kpis[3]) kpis[3].textContent = counts.completed;
    }

      window.apptUpdateRow = function(id, status, scheduleLabel, preserveSelection) {
        var row = allRows.find(function(item) { return String(item.getAttribute('data-id')) === String(id); });
        if (!row) return false;
        row.setAttribute('data-status', status);
        row.setAttribute('data-can-outcome', '0');
        var badge = row.querySelector('.appt-status-pill');
        var statusStyles = {
          pending: ['#fef3c7', '#92400e'], scheduled: ['#0E8131', '#ffffff'], approved: ['#dcfce7', '#166534'],
          rescheduled: ['#dbeafe', '#1d4ed8'], completed: ['#dbeafe', '#1e40af'], cancelled: ['#f3f4f6', '#6b7280'],
          rejected: ['#fee2e2', '#991b1b'], no_show: ['#fef2f2', '#b91c1c'], waitlisted: ['#fef3c7', '#92400e']
        };
        if (badge) {
          var style = statusStyles[status] || ['#f4f4f5', '#52525b'];
          badge.textContent = status === 'no_show' ? 'No Show' : status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
          badge.style.background = style[0];
          badge.style.color = style[1];
        }
        var menuButton = row.querySelector('[data-appt-id]');
        if (menuButton) {
          menuButton.setAttribute('data-appt-status', status);
          menuButton.onclick = function(event) { event.stopPropagation(); openApptRowMenu(event, Number(id), status); };
        }
        if (scheduleLabel) {
          var scheduleCell = row.querySelectorAll('td')[6];
          if (scheduleCell) scheduleCell.textContent = scheduleLabel;
        }
        var checkbox = row.querySelector('.appt-row-check');
        if (!preserveSelection) {
          if (checkbox) checkbox.checked = false;
          if (window.APP && APP.deselect) APP.deselect(id);
        }
        currentPage = Math.min(currentPage, Math.max(1, Math.ceil(getFiltered().length / PER_PAGE_APPT)));
        renderTable();
        return true;
      };

      window.apptRemoveRows = function(ids) {
        var removeIds = (ids || []).map(String);
        allRows = allRows.filter(function(row) {
          var id = String(row.getAttribute('data-id'));
          if (removeIds.indexOf(id) < 0) return true;
          if (window.APP && APP.deselect) APP.deselect(id);
          row.remove();
          return false;
        });
        currentPage = Math.min(currentPage, Math.max(1, Math.ceil(getFiltered().length / PER_PAGE_APPT)));
        renderTable();
      };

      window.apptUpsertRealtimeRow = function(appt) {
        if (!appt || !appt.appointment_id) return;
        var id = Number(appt.appointment_id);
        var existing = allRows.find(function(row) { return Number(row.getAttribute('data-id')) === id; });
        var type = appt.appointment_type ? appt.appointment_type.charAt(0).toUpperCase() + appt.appointment_type.slice(1) : '-';
        var date = appt.preferred_date || '';
        var time = appt.preferred_time || '';
        var schedule = date && time ? new Date(date + 'T' + time).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'numeric', minute:'2-digit' }) : '-';
        if (existing) {
          var cells = existing.querySelectorAll('td');
          if (cells[2]) cells[2].textContent = type;
          if (cells[3]) setAppointmentResidentCell(cells[3], appt);
          if (cells[4]) setAppointmentContactCell(cells[4], appt);
          if (cells[5]) setAppointmentPetCell(cells[5], appt);
          window.apptUpdateRow(id, appt.status || 'pending', schedule, true);
          return;
        }
        var row = document.createElement('tr');
        row.className = 'sd-tr';
        row.setAttribute('data-id', String(id));
        row.setAttribute('data-status', appt.status || 'pending');
        row.setAttribute('data-date', String(date ? Math.floor(new Date(date).getTime() / 1000) : 0));
        row.setAttribute('data-submitted', String(appt.created_at ? Math.floor(new Date(String(appt.created_at).replace(' ', 'T')).getTime() / 1000) : 0));
        row.onclick = function() { if (window.APP) APP.view(id); };

        var checkboxCell = document.createElement('td');
        checkboxCell.className = 'sd-td';
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'sd-table-cb appt-row-check';
        checkbox.setAttribute('data-id', String(id));
        checkbox.onclick = function(event) { event.stopPropagation(); if (window.APP) APP.toggleSelect(id); };
        checkboxCell.appendChild(checkbox);
        row.appendChild(checkboxCell);

        [appt.reference_id || ('#APT-' + String(id).padStart(4, '0')), type]
          .forEach(function(value) {
            var cell = document.createElement('td');
            cell.className = 'sd-td';
            var text = document.createElement('div');
            text.className = 'contact-main contact-normal';
            text.textContent = value;
            cell.appendChild(text);
            row.appendChild(cell);
          });

        var residentCell = document.createElement('td');
        residentCell.className = 'sd-td';
        setAppointmentResidentCell(residentCell, appt);
        row.appendChild(residentCell);

        var contactCell = document.createElement('td');
        contactCell.className = 'sd-td';
        setAppointmentContactCell(contactCell, appt);
        row.appendChild(contactCell);

        [schedule,
          appt.created_at ? new Date(String(appt.created_at).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'numeric', minute:'2-digit' }) : '-']
          .forEach(function(value) {
            var cell = document.createElement('td');
            cell.className = 'sd-td';
            var text = document.createElement('div');
            text.className = 'contact-main contact-normal';
            text.textContent = value;
            cell.appendChild(text);
            row.appendChild(cell);
          });

        var petCell = document.createElement('td');
        petCell.className = 'sd-td';
        setAppointmentPetCell(petCell, appt);
        row.insertBefore(petCell, row.children[5]);

        var statusCell = document.createElement('td');
        statusCell.className = 'sd-td';
        var badge = document.createElement('span');
        badge.className = 'appt-status-pill';
        badge.textContent = (appt.status || 'pending').replace(/_/g, ' ').replace(/^./, function(c) { return c.toUpperCase(); });
        statusCell.appendChild(badge);
        row.appendChild(statusCell);

        var actionCell = document.createElement('td');
        actionCell.className = 'sd-td sd-td-action';
        var actionButton = document.createElement('button');
        actionButton.type = 'button';
        actionButton.className = 'sd-row-actions';
        actionButton.title = 'More options';
        actionButton.setAttribute('data-appt-id', String(id));
        actionButton.setAttribute('data-appt-status', appt.status || 'pending');
        actionButton.textContent = '...';
        actionButton.onclick = function(event) { event.stopPropagation(); openApptRowMenu(event, id, appt.status || 'pending'); };
        actionCell.appendChild(actionButton);
        row.appendChild(actionCell);

        allRows.unshift(row);
        tbody.insertBefore(row, tbody.firstChild);
        renderTable();
      };

    function renderPagination(total) {
        var tp = Math.max(1, Math.ceil(total / PER_PAGE_APPT));
        if (currentPage > tp) currentPage = tp;
        if (currentPage < 1) currentPage = 1;
        var opts = [10,15,20,30,50].map(function(n){
            return '<option value="' + n + '"' + (PER_PAGE_APPT === n ? ' selected' : '') + '>' + n + '</option>';
        }).join('');
        var h = '';
        h += '<div class="dt-pagination-right">';
        h += '<div class="dt-rows"><span>Rows per page</span><select onchange="window.apptSetPerPage(this.value)">' + opts + '</select></div>';
        h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
        h += '<div class="dt-page-btns">';
        h += '<button type="button" class="dt-page-btn" title="Go to first page" onclick="window.apptPageGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to previous page" onclick="window.apptPageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to next page" onclick="window.apptPageGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to last page" onclick="window.apptPageGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
        h += '</div>';
        h += '</div>';
        pag.innerHTML = h;
    }

    window.apptPageGo = function(page) {
        var total = Math.ceil(getFiltered().length / PER_PAGE_APPT);
        if (page < 1 || page > total) return;
        currentPage = page;
        renderTable();
    };

    window.apptSetPerPage = function(v) {
        PER_PAGE_APPT = parseInt(v, 10);
        currentPage = 1;
        renderTable();
    };

    var apptSearch = document.getElementById('apptSearchInput');
    if (apptSearch) {
        apptSearch.addEventListener('keyup', function() {
            filterSearch = this.value.toLowerCase().trim();
            currentPage = 1;
            renderTable();
        });
    }

    var apptStatus = document.getElementById('apptStatusSelect');
    if (apptStatus) {
        apptStatus.addEventListener('change', function() {
            filterStatus = this.value;
            currentPage = 1;
            renderTable();
        });
    }
    var rescheduleFilter = document.getElementById('rescheduleRequestFilter');
    if (rescheduleFilter) {
        rescheduleFilter.addEventListener('click', function() {
            filterRescheduleOnly = !filterRescheduleOnly;
            if (filterRescheduleOnly && apptStatus) {
                apptStatus.value = 'all';
                filterStatus = 'all';
            }
            rescheduleFilter.setAttribute('aria-pressed', filterRescheduleOnly ? 'true' : 'false');
            rescheduleFilter.classList.toggle('sd-btn-default', filterRescheduleOnly);
            rescheduleFilter.classList.toggle('sd-btn-outline', !filterRescheduleOnly);
            currentPage = 1;
            renderTable();
        });
    }

    renderTable();
})();

function markAllNotifRead() {
    fetch(window.dcvsStaffUrl('mark-notifications-read.php') + '?type=staff').then(() => {
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        const badge = document.querySelector('.notif-badge');
        if (badge) badge.style.display = 'none';
    });
}
</script>

<!-- Medical History Modal: temporarily hidden - feature coming soon -->

<script>
// Data for Calendar
const calendarEvents = <?php echo $calendarEventsJSON; ?>;

// Initialization when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // 1. Setup Navigation
    const navLinks = document.querySelectorAll('.nav-link[data-section]');
    const sections = document.querySelectorAll('.content-section');
    const breadcrumb = document.getElementById('breadcrumbCurrent');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            navLinks.forEach(l => l.classList.remove('active'));
            sections.forEach(s => s.classList.remove('active'));
            
            link.classList.add('active');
            const targetId = link.getAttribute('data-section');
            document.getElementById(targetId).classList.add('active');
            breadcrumb.textContent = link.querySelector('.nav-link-text').textContent;
            document.body.classList.toggle('white-page', targetId === 'appointments' || targetId === 'account-settings');
            
            // Keep parent dropdown group open + highlighted when a sub-item is active
            const group = link.closest('.nav-group');
            document.querySelectorAll('.nav-group').forEach(g => g.classList.remove('active'));
            if (group) {
                group.classList.add('active');
                group.classList.add('open');
            }
            
            // Save to localStorage
            localStorage.setItem('staffActiveTab', targetId);
        });
    });

    // Save active tab on tab close / page hide
    function saveActiveTab() {
        var active = document.querySelector('.content-section.active');
        if (active) localStorage.setItem('staffActiveTab', active.id);
    }
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) saveActiveTab();
    });
    window.addEventListener('pagehide', saveActiveTab);

    // Restore active tab from hash or localStorage (skip on fresh login â�?��?T land on Analytics)
    const freshLogin = <?php echo $freshDashboard ? 'true' : 'false'; ?>;
    const hashTab = window.location.hash.replace('#', '');
    if (!freshLogin) {
      const savedTab = hashTab || localStorage.getItem('staffActiveTab');
      if (savedTab) {
        const contentTab = ['engine', 'intel', 'architect', 'citydrives', 'adoption', 'cases-analytics', 'appointment-overview', 'programs-services', 'city-drives'].indexOf(savedTab) !== -1 ? 'analytics' : savedTab;
        const targetLink = document.querySelector(`.nav-link[data-section="${contentTab}"]`);
          if (targetLink) {
              targetLink.click();
        } else if (contentTab === 'analytics') {
          sections.forEach(s => s.classList.remove('active'));
          document.getElementById('analytics').classList.add('active');
          }
      }
    } else {
      localStorage.removeItem('staffActiveTab');
    }

    // Initialize Charts
    initCharts();
});

// Keep page background in sync when navigating via URL hash
window.addEventListener('hashchange', function() {
    var targetId = window.location.hash.replace('#', '');
    if (['engine', 'intel', 'architect', 'citydrives', 'adoption', 'cases-analytics', 'appointment-overview', 'programs-services', 'city-drives'].indexOf(targetId) !== -1) targetId = 'analytics';
    if (targetId && document.getElementById(targetId)) {
        document.body.classList.toggle('white-page', targetId === 'appointments' || targetId === 'account-settings');
    } else {
        document.body.classList.remove('white-page');
    }
});

function initCharts() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';
}

// Modal Functions
function openAddAppointmentModal() {
  if (window.APP && APP.reviewOpen) APP.reviewOpen();
}

// View Medical History (AJAX)
function viewMedicalHistory(petId) {
    document.getElementById('medicalHistoryModal').style.display = 'flex';
    document.getElementById('medicalHistoryContent').innerHTML = '<p style="text-align:center;">Loading history...</p>';
    
    // Fetch history
    fetch(window.dcvsStaffUrl('staff-get-history.php') + '?pet_id=' + petId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('medicalHistoryContent').innerHTML = data;
        })
        .catch(err => {
            document.getElementById('medicalHistoryContent').innerHTML = '<p style="color:red;text-align:center;">Failed to load history.</p>';
        });
}

// â�?��,�â�?��,� APPOINTMENTS: status/search filter + pagination â�?��,�â�?��,�
(function() {
    var PER_PAGE_APPT = 10;
    var tbody = document.querySelector('#appointmentsTable tbody');
    var pag = document.getElementById('apptPagination');
    if (!tbody || !pag) return;
    var allRows = Array.from(tbody.querySelectorAll('tr'));
    // Remove empty text nodes / non-tr rows
    allRows = allRows.filter(function(r) { return r.tagName === 'TR'; });
    var currentPage = 1;
    var filterStatus = 'all';
    var filterSearch = '';
    var sortCol = '';
    var sortDir = 'asc';

    function getSortVal(row, col) {
        var cells = row.querySelectorAll('td');
        function txt(n) { return cells[n] ? cells[n].textContent.trim().toLowerCase() : ''; }
        if (col === 'appt') return txt(1);
        if (col === 'service') return txt(2);
        if (col === 'resident') return txt(3);
        if (col === 'pet') return txt(5);
        if (col === 'schedule') return row.getAttribute('data-date') || txt(6);
        if (col === 'submitted') return row.getAttribute('data-submitted') || txt(7);
        if (col === 'status') return txt(8);
        return txt(1);
    }

    window.APP = window.APP || {};
    window.APP.sortBy = function(col) {
        if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
        else { sortCol = col; sortDir = 'asc'; }
        allRows.sort(function(a, b) {
            var va = getSortVal(a, col), vb = getSortVal(b, col);
            var res = va < vb ? -1 : (va > vb ? 1 : 0);
            return sortDir === 'asc' ? res : -res;
        });
        allRows.forEach(function(r) { tbody.appendChild(r); });
        document.querySelectorAll('#appointmentsTable thead .th-sort').forEach(function(btn) {
            var c = btn.getAttribute('data-col');
            btn.classList.toggle('th-sorted', c === sortCol);
            var icon = btn.querySelector('.th-sort-icon');
            if (icon) {
                if (c === sortCol) {
                    icon.innerHTML = sortDir === 'asc'
                        ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>'
                        : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
                } else {
                    icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
                }
            }
        });
        currentPage = 1;
        renderTable();
    };

    function getStatus(row) {
        var st = row.getAttribute('data-status');
        if (st) return st.trim().toLowerCase().replace(/\s+/g, '_');
        var cells = row.querySelectorAll('td');
        return cells.length >= 9 ? (cells[8].textContent || '').trim().toLowerCase().replace(/\s+/g, '_') : '';
    }

    function getFiltered() {
        return allRows.filter(function(r) {
            if (r.id === 'apptNoResults') return false;
            var status = getStatus(r);
            var text = r.textContent.toLowerCase();
            var matchS = filterStatus === 'all' || status === filterStatus;
            var matchQ = !filterSearch || text.includes(filterSearch);
            return matchS && matchQ;
        });
    }

    window.exportAppointmentsCSV = function() {
        var table = document.querySelector('#appointmentsTable table');
        if (!table) return;
        function csvCell(value) {
            var text = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
            if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
            return '"' + text.replace(/"/g, '""') + '"';
        }
        var headers = Array.from(table.querySelectorAll('thead th')).slice(1, -1).map(function(th) {
            return th.textContent.replace(/\s+/g, ' ').trim();
        });
        var rows = [headers.map(csvCell).join(',')];
        getFiltered().forEach(function(row) {
            var cells = Array.from(row.querySelectorAll('td')).slice(1, -1);
            rows.push(cells.map(function(cell) { return csvCell(cell.innerText || cell.textContent); }).join(','));
        });
        var blob = new Blob(['\uFEFF' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'appointment-requests-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function() { URL.revokeObjectURL(link.href); }, 1000);
    };

    function renderTable() {
        var filtered = getFiltered();
        allRows.forEach(function(r) { if (r.style) r.style.display = 'none'; });
        var start = (currentPage - 1) * PER_PAGE_APPT;
        var end = start + PER_PAGE_APPT;
        filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
        // Check if there's a no-results row; if not, we don't need one
        renderPagination(filtered.length);
    }

    function renderPagination(total) {
        var tp = Math.max(1, Math.ceil(total / PER_PAGE_APPT));
        if (currentPage > tp) currentPage = tp;
        if (currentPage < 1) currentPage = 1;
        var opts = [10,15,20,30,50].map(function(n){
            return '<option value="' + n + '"' + (PER_PAGE_APPT === n ? ' selected' : '') + '>' + n + '</option>';
        }).join('');
        var h = '';
        h += '<div class="dt-pagination-right">';
        h += '<div class="dt-rows"><span>Rows per page</span><select onchange="window.apptSetPerPage(this.value)">' + opts + '</select></div>';
        h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
        h += '<div class="dt-page-btns">';
        h += '<button type="button" class="dt-page-btn" title="Go to first page" onclick="window.apptPageGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to previous page" onclick="window.apptPageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to next page" onclick="window.apptPageGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
        h += '<button type="button" class="dt-page-btn" title="Go to last page" onclick="window.apptPageGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
        h += '</div>';
        h += '</div>';
        pag.innerHTML = h;
    }

    window.apptPageGo = function(page) {
        var total = Math.ceil(getFiltered().length / PER_PAGE_APPT);
        if (page < 1 || page > total) return;
        currentPage = page;
        renderTable();
    };

    window.apptSetPerPage = function(v) {
        PER_PAGE_APPT = parseInt(v, 10);
        currentPage = 1;
        renderTable();
    };

    var apptSearch = document.getElementById('apptSearchInput');
    if (apptSearch) {
        apptSearch.addEventListener('keyup', function() {
            filterSearch = this.value.toLowerCase().trim();
            currentPage = 1;
            renderTable();
        });
    }

    var apptStatus = document.getElementById('apptStatusSelect');
    if (apptStatus) {
        apptStatus.addEventListener('change', function() {
            filterStatus = this.value;
            currentPage = 1;
            renderTable();
        });
    }

    renderTable();
})();

// â�?��,�â�?��,� Profile Settings Edit Toggle â�?��,�â�?��,�
function toggleEdit(group) {
    var wrapper = document.querySelector('.profile-settings-wrapper');
    if (!wrapper) return;
    var inputs = wrapper.querySelectorAll('[data-group="' + group + '"]');
    var btn = document.getElementById(group + '-card').querySelector('.ps-edit-btn');
    var isEditing = btn.classList.contains('active');
    inputs.forEach(function(input) { input.disabled = isEditing; });
    btn.classList.toggle('active', !isEditing);
    btn.querySelector('.btn-label').textContent = isEditing ? 'Edit' : 'Done';
    if (!isEditing && inputs.length) inputs[0].focus();
}

document.addEventListener('DOMContentLoaded', function() {
    var sf = document.getElementById('staffSettingsForm');
    if (sf) {
        sf.addEventListener('submit', function() {
            this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
        });
    }
    window.previewAvatar = function(e, imgId) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var img = document.getElementById(imgId);
                if (img) { img.src = ev.target.result; }
            };
            reader.readAsDataURL(file);
        }
    };

    setTimeout(function() {
        var err = document.getElementById('profileError');
        var suc = document.getElementById('profileSuccess');
        if (err) { err.style.transition = 'opacity 0.5s'; err.style.opacity = '0'; setTimeout(function() { err.style.display = 'none'; }, 500); }
        if (suc) { suc.style.transition = 'opacity 0.5s'; suc.style.opacity = '0'; setTimeout(function() { suc.style.display = 'none'; }, 500); }
    }, 3000);
});

function toggleProfileDropdown(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('open');
}

document.addEventListener('click', function(e) {
    var d = document.getElementById('profileDropdown');
    if (d && d.classList.contains('open') && !d.contains(e.target) && !e.target.closest('.profile-header-avatar-wrapper')) {
        d.classList.remove('open');
    }
});

function viewProfilePhoto() {
    document.getElementById('profileDropdown').classList.remove('open');
    openModal('profilePhotoModal');
}

function confirmRemoveAvatar() {
    if (confirm('Remove your profile photo?')) {
        window.location.href = 'remove-photo.php';
    }
}


// â�?��,�â�?��,� Compact map: dim inactive, highlight active-case barangays â�?��,�â�?��,�
(function() {
  var el = document.getElementById('geoMapCompact');
  if (!el) return;
  el.addEventListener('mapcn:load', function(e) {
    var map = e.detail.map;
    var maxAttempts = 40;
    var attempts = 0;
    var timer = setInterval(function() {
      attempts++;
      try {
        var src = map.getSource('barangays');
        if (src && src._data && src._data.features) {
          clearInterval(timer);
          var fc = src._data;
          var allFeatures = fc.features || [];
          var activeFeatures = allFeatures.filter(function(f) {
            return (f.properties.confirmed || 0) > 0;
          });
          // Inactive barangays: barely visible gray fill
          // Active barangays: full risk-based color
          applyMapStyling(map, activeFeatures);
          // Re-fit bounds to active barangays
          if (activeFeatures.length > 0) {
            var bounds = calcBounds(activeFeatures);
            map.fitBounds(bounds, { padding: 30, maxZoom: activeFeatures.length === 1 ? 16 : 13, duration: 800 });
            buildSummary(activeFeatures);
          } else {
            var summaryEl = document.getElementById('activeSummary');
            if (summaryEl) summaryEl.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:4px 0;">No active cases</div>';
          }
        }
      } catch(e) {}
      if (attempts >= maxAttempts) clearInterval(timer);
    }, 200);
  });
  function calcBounds(features) {
    var minLng = 180, maxLng = -180, minLat = 90, maxLat = -90;
    features.forEach(function(f) {
      var coords = extractCoords(f.geometry);
      coords.forEach(function(c) {
        if (c[0] < minLng) minLng = c[0];
        if (c[0] > maxLng) maxLng = c[0];
        if (c[1] < minLat) minLat = c[1];
        if (c[1] > maxLat) maxLat = c[1];
      });
    });
    return [[minLng, minLat], [maxLng, maxLat]];
  }
  function extractCoords(geom) {
    if (!geom) return [];
    if (geom.type === 'Polygon' && geom.coordinates.length) return geom.coordinates[0];
    if (geom.type === 'MultiPolygon') {
      var out = [];
      geom.coordinates.forEach(function(p) { if (p.length) out = out.concat(p[0]); });
      return out;
    }
    return [];
  }
  function computeCenter(map) {
    var s = map.getSource('barangays');
    if (!s || !s._data || !s._data.features) return [120.968, 14.306];
    var sumLng = 0, sumLat = 0, count = 0;
    s._data.features.forEach(function(f) {
      var coords = extractCoords(f.geometry);
      var fLng = 0, fLat = 0, fC = 0;
      coords.forEach(function(c) { fLng += c[0]; fLat += c[1]; fC++; });
      sumLng += fLng / fC; sumLat += fLat / fC; count++;
    });
    return [sumLng / count, sumLat / count];
  }
  function applyMapStyling(map, activeFeatures) {
    if (!map.getLayer('barangay-fill')) return setTimeout(function() { applyMapStyling(map, activeFeatures); }, 100);
    // Dim inactive, highlight active
    map.setPaintProperty('barangay-fill', 'fill-color', [
      'case',
      ['>', ['get', 'confirmed'], 0],
      ['match', ['get', 'risk'], 'Red', '#ef4444', 'Orange', '#f97316', 'Yellow', '#eab308', '#22c55e'],
      '#f1f5f9'
    ]);
    map.setPaintProperty('barangay-fill', 'fill-opacity', [
      'case',
      ['>', ['get', 'confirmed'], 0],
      0.75,
      0.06
    ]);
    if (map.getLayer('barangay-outline')) {
      map.setPaintProperty('barangay-outline', 'line-color', [
        'case',
        ['>', ['get', 'confirmed'], 0],
        '#ffffff', '#e2e8f0'
      ]);
      map.setPaintProperty('barangay-outline', 'line-width', [
        'case',
        ['>', ['get', 'confirmed'], 0],
        1.5, 0.4
      ]);
      map.setPaintProperty('barangay-outline', 'line-opacity', [
        'case',
        ['>', ['get', 'confirmed'], 0],
        0.9, 0.15
      ]);
    }
    if (map.getLayer('barangay-label')) {
      map.setFilter('barangay-label', ['>', ['get', 'confirmed'], 0]);
    }
    // Hide basemap labels (only show Dasmari�f±as barangays)
    var style = map.getStyle();
    if (style && style.layers) {
      style.layers.forEach(function(l) {
        if (l.type === 'symbol' && l.id !== 'barangay-label') {
          try { map.setLayoutProperty(l.id, 'visibility', 'none'); } catch(e) {}
        }
      });
    }
    // Add "Dasmari�f±as" city label at actual city center
    if (!map.getSource('city-label')) {
      var cityCenter = computeCenter(map);
      map.addSource('city-label', {
        type: 'geojson',
        data: { type: 'Feature', geometry: { type: 'Point', coordinates: cityCenter }, properties: { name: 'Dasmari�f±as' } }
      });
      map.addLayer({
        id: 'dasma-city-label',
        type: 'symbol',
        source: 'city-label',
        layout: { 'text-field': 'Dasmari�f±as', 'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'], 'text-size': 14, 'text-offset': [0, -1.5], 'text-anchor': 'center', 'text-allow-overlap': true, 'text-ignore-placement': true },
        paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 3, 'text-halo-blur': 2, 'text-opacity': 0.9 }
      });
    }
    // Hover info panel on active-case barangays
    map.on('mousemove', 'barangay-fill', function(e) {
      if (e.features && e.features.length > 0) {
        var props = e.features[0].properties;
        if ((props.confirmed || 0) > 0) {
          map.getCanvas().style.cursor = 'pointer';
          showInfoPanel(props);
        } else {
          map.getCanvas().style.cursor = '';
        }
      }
    });
    map.on('mouseleave', 'barangay-fill', function() {
      map.getCanvas().style.cursor = '';
      var p = document.getElementById('geoInfoPanel');
      if (p) p.classList.remove('open');
    });
  }
  function showInfoPanel(props) {
    var panel = document.getElementById('geoInfoPanel');
    if (!panel) return;
    var risk = props.risk || 'Green';
    var rs = { Red: { bg: '#fee2e2', text: '#991b1b' }, Orange: { bg: '#ffedd5', text: '#9a3412' }, Yellow: { bg: '#fef9c3', text: '#854d0e' }, Green: { bg: '#dcebe0', text: '#045e1f' } }[risk] || { bg: '#dcebe0', text: '#045e1f' };
    var nameEl = document.getElementById('geoPanelName');
    if (nameEl) nameEl.textContent = props.name || 'Unknown';
    var badge = document.getElementById('geoPanelRisk');
    if (badge) { badge.textContent = risk; badge.style.background = rs.bg; badge.style.color = rs.text; }
    var setText = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
    setText('geoPanelConfirmed', props.confirmed || 0);
    setText('geoPanelPending', props.pending || 0);
    setText('geoPanelResolved', props.resolved || 0);
    panel.classList.add('open');
  }
  function buildSummary(features) {
    var el = document.getElementById('activeSummary');
    if (!el) return;
    var colorMap = { Red: '#ef4444', Orange: '#f97316', Yellow: '#eab308', Green: '#22c55e' };
    var sorted = features.slice().sort(function(a, b) {
      return (b.properties.confirmed || 0) - (a.properties.confirmed || 0);
    });
    var html = '';
    sorted.forEach(function(f) {
      var p = f.properties;
      var color = colorMap[p.risk] || '#22c55e';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;line-height:1.5;">'
        + '<div style="display:flex;align-items:center;gap:8px;min-width:0;">'
        + '<span style="width:9px;height:9px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>'
        + '<span style="color:#0f172a;font-weight:600;font-size:0.78rem;">' + (p.name || 'Unknown') + '</span>'
        + '</div>'
        + '<span style="color:#64748b;font-weight:500;font-size:0.75rem;white-space:nowrap;">' + p.confirmed + ' case' + (p.confirmed !== 1 ? 's' : '') + '</span>'
        + '</div>';
    });
    el.innerHTML = html;
  }
})();


// â�?��,�â�?��,� Slot Availability Check â�?��,�â�?��,�
function checkStaffSlot() {
    var date = document.getElementById('formDate')?.value;
    var time = document.getElementById('formTime')?.value;
    var status = document.getElementById('formSlotStatus');
    if (!date || !time || !status) return;
    status.style.display = 'block';
    status.style.background = '#fefce8';
    status.style.color = '#92400e';
    status.textContent = 'Checking availability...';
    var exclude = document.getElementById('formApptId')?.value || 0;
    fetch(window.dcvsStaffUrl('includes/check_slot.php') + '?date=' + encodeURIComponent(date) + '&time=' + encodeURIComponent(time) + '&exclude=' + exclude)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.available) {
                status.style.background = '#f0fdf4';
                status.style.color = '#065f46';
                status.textContent = 'Slot available';
            } else {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                var msg = d.clinic_blocked ? 'This time slot is closed by schedule.'
                                    : (d.held ? 'This time slot is already reserved by another resident.'
                                              : 'This slot is no longer available.');
                if (d.nearest_slot) {
                    var nearest = d.nearest_slot.split(' ');
                    var nearDate = new Date(nearest[0] + 'T00:00:00');
                    msg += ' Nearest available: ' + nearDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' at ' + nearest[1];
                }
                status.textContent = msg;
            }
        })
        .catch(function() { status.style.display = 'none'; });
}
document.addEventListener('DOMContentLoaded', function() {
    var fd = document.getElementById('formDate');
    var ft = document.getElementById('formTime');
    if (fd) fd.addEventListener('change', checkStaffSlot);
    if (ft) ft.addEventListener('change', checkStaffSlot);
});



// �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.�
// Appointment Actions (statusChange, viewAppt, reschedSingle)
// �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.�
window.toggleApptMore = function(btn) {
  /* replaced by openApptRowMenu below */
};
function openApptRowMenu(ev, apptId, apptStatus){
  ev.stopPropagation();
  var pop = document.getElementById('apptRowMenuPop');
  if (!pop) return;
  var appointmentRow = document.querySelector('#appointmentsTable tr[data-id="' + apptId + '"]');
  var canMarkOutcome = appointmentRow && appointmentRow.getAttribute('data-can-outcome') === '1';
  var items = '';
  items += '<button class="row-menu-item" onclick="closeApptRowMenu();APP.view('+apptId+')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>';
  if (apptStatus === 'pending') {
    items += '<button class="row-menu-item" onclick="closeApptRowMenu();schedulePendingAppointment('+apptId+')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Schedule</button>';
  }
  if (apptStatus === 'approved' || apptStatus === 'scheduled' || apptStatus === 'rescheduled') {
    items += '<button class="row-menu-item" onclick="closeApptRowMenu();APP.reschedSingle('+apptId+')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Reschedule</button>';
  }
  if (apptStatus === 'no_show') {
    items += '<button class="row-menu-item" onclick="closeApptRowMenu();APP.statusChange('+apptId+',\'completed\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Correct to Completed</button>';
  }
  if (canMarkOutcome && (apptStatus === 'scheduled' || apptStatus === 'rescheduled' || apptStatus === 'in_progress')) {
    items += '<button class="row-menu-item" onclick="closeApptRowMenu();APP.statusChange('+apptId+',\'completed\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Mark Completed</button>';
    items += '<button class="row-menu-item" onclick="closeApptRowMenu();APP.statusChange('+apptId+',\'no_show\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6m0-6-6 6"/></svg>Mark No-show</button>';
  }
  if (apptStatus === 'completed' || apptStatus === 'no_show' || apptStatus === 'cancelled' || apptStatus === 'rejected') {
    items += '<button class="row-menu-item danger" onclick="closeApptRowMenu();archiveAppt('+apptId+')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</button>';
  }
  pop.innerHTML = items;
  var r = ev.currentTarget.getBoundingClientRect();
  pop.style.position = 'fixed';
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
  pop.classList.add('open');
  setTimeout(function(){ document.addEventListener('click', closeApptRowMenu); }, 0);
}
function closeApptRowMenu(e) {
  if (e && e.target && e.target.closest && e.target.closest('#apptRowMenuPop')) return;
  var pop = document.getElementById('apptRowMenuPop');
  if (pop) pop.classList.remove('open');
  document.removeEventListener('click', closeApptRowMenu);
}
function confirmAppointmentAction(title, message, onConfirm, confirmLabel) {
  var overlay = document.getElementById('appointmentActionConfirm');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'appointmentActionConfirm';
    overlay.className = 'modal';
    overlay.style.cssText = 'background:rgba(0,0,0,.5);padding:1rem;z-index:1300;';
    overlay.innerHTML = '<div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="appointmentActionConfirmTitle" aria-describedby="appointmentActionConfirmMessage" style="max-width:420px;"><div class="sd-dialog-header"><h2 class="sd-dialog-title" id="appointmentActionConfirmTitle"></h2><p class="sd-dialog-description" id="appointmentActionConfirmMessage"></p></div><div class="sd-dialog-footer"><button type="button" class="sd-btn sd-btn-outline" id="appointmentActionConfirmCancel">Cancel</button><button type="button" class="sd-btn sd-btn-default" id="appointmentActionConfirmOk">Continue</button></div></div>';
    document.body.appendChild(overlay);
    document.getElementById('appointmentActionConfirmCancel').onclick = function() { closeModal('appointmentActionConfirm'); };
  }
  document.getElementById('appointmentActionConfirmTitle').textContent = title;
  document.getElementById('appointmentActionConfirmMessage').textContent = message;
  document.getElementById('appointmentActionConfirmOk').textContent = confirmLabel || 'Continue';
  document.getElementById('appointmentActionConfirmOk').onclick = function() {
    closeModal('appointmentActionConfirm');
    onConfirm();
  };
  openModal('appointmentActionConfirm');
  var confirmButton = document.getElementById('appointmentActionConfirmOk');
  if (confirmButton) confirmButton.focus();
}
function rejectAppointmentFromDrawer(id) {
  var modal = document.getElementById('appointmentRejectReasonDialog');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'appointmentRejectReasonDialog';
    modal.className = 'modal';
    modal.style.cssText = 'background:rgba(0,0,0,.5);padding:1rem;z-index:1200;';
    modal.innerHTML = '<div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="appointmentRejectTitle" aria-describedby="appointmentRejectDescription" style="width:100%;max-width:420px;box-sizing:border-box;background:#fff;border:1px solid #e4e4e7;border-radius:10px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 20px 25px rgba(15,23,32,.18);">' +
      '<button type="button" class="sd-dialog-close" id="appointmentRejectClose" aria-label="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
      '<div class="sd-dialog-header"><h2 class="sd-dialog-title" id="appointmentRejectTitle">Reject Appointment</h2><p class="sd-dialog-description" id="appointmentRejectDescription">Enter a reason for rejecting this appointment request.</p></div>' +
      '<div><label for="appointmentRejectReason" class="sd-label">Rejection Reason <span style="color:#dc2626;">*</span></label><textarea id="appointmentRejectReason" class="sd-input" rows="4" required placeholder="Explain why this appointment request is being rejected..."></textarea></div>' +
      '<p id="appointmentRejectError" style="display:none;margin:0;color:#991b1b;background:#fef2f2;border-radius:6px;padding:8px 10px;font-size:13px;line-height:1.45;"></p>' +
      '<div class="sd-dialog-footer"><button type="button" class="sd-btn sd-btn-outline" id="appointmentRejectCancel">Cancel</button><button type="button" class="sd-btn sd-btn-default" id="appointmentRejectSubmit" style="background:#dc2626;border-color:#dc2626;color:#fff;">Reject Appointment</button></div>' +
      '</div>';
    document.body.appendChild(modal);
    document.getElementById('appointmentRejectClose').onclick = function () { closeModal('appointmentRejectReasonDialog'); };
    document.getElementById('appointmentRejectCancel').onclick = function () { closeModal('appointmentRejectReasonDialog'); };
    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeModal('appointmentRejectReasonDialog');
    });
  }
  var reason = document.getElementById('appointmentRejectReason');
  var error = document.getElementById('appointmentRejectError');
  var submit = document.getElementById('appointmentRejectSubmit');
  reason.value = '';
  error.textContent = '';
  error.style.display = 'none';
  submit.disabled = false;
  submit.textContent = 'Reject Appointment';
  submit.onclick = function () {
    var value = reason.value.trim();
    if (!value) {
      error.textContent = 'Please enter a rejection reason.';
      error.style.display = 'block';
      reason.focus();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Rejecting...';
    var body = new URLSearchParams();
    body.append('ajax', '1');
    body.append('appointment_id', id);
    body.append('reason', value);
    fetch(window.dcvsStaffUrl('reject-appointment.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.success) {
          closeModal('appointmentRejectReasonDialog');
          closeModal('appointmentDetailsModal');
          if (window.apptUpdateRow) window.apptUpdateRow(id, 'rejected');
          if (window.portalToast) portalToast('Appointment rejected', data.message || 'Appointment request rejected.');
          return;
        }
        error.textContent = (data && data.message) || 'Could not reject this appointment.';
        error.style.display = 'block';
        submit.disabled = false;
        submit.textContent = 'Reject Appointment';
      }).catch(function () {
        error.textContent = 'Network error. Could not reject this appointment.';
        error.style.display = 'block';
        submit.disabled = false;
        submit.textContent = 'Reject Appointment';
      });
  };
  openModal('appointmentRejectReasonDialog');
  reason.focus();
}
function schedulePendingAppointment(id, onComplete, staffMessage){
  var modal = document.getElementById('scheduleAppointmentDialog');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'scheduleAppointmentDialog';
    modal.className = 'modal';
    modal.innerHTML = '<section class="sd-dialog-content schedule-dialog-content" role="dialog" aria-modal="true" aria-labelledby="scheduleAppointmentTitle" aria-describedby="scheduleAppointmentDescription">' +
      '<header class="schedule-dialog-header"><button type="button" class="schedule-dialog-close" id="scheduleAppointmentClose" aria-label="Close">&times;</button><h2 id="scheduleAppointmentTitle">Schedule Appointment</h2><p id="scheduleAppointmentDescription">Choose the final appointment date and time. The resident’s requested schedule remains recorded separately.</p></header>' +
      '<div><label class="schedule-dialog-label" for="scheduleAppointmentDate">Final Appointment Date</label><input class="schedule-date-input" id="scheduleAppointmentDate" type="date" required></div>' +
      '<div><span class="schedule-dialog-label">Final Appointment Time</span><div class="schedule-time-grid" id="scheduleAppointmentTimeOptions" role="group" aria-label="Final appointment time"></div><select id="scheduleAppointmentTime" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"><option value="09:00">9:00 AM</option><option value="10:00">10:00 AM</option><option value="11:00">11:00 AM</option><option value="14:00">2:00 PM</option><option value="15:00">3:00 PM</option></select></div>' +
      '<section class="schedule-capacity is-checking" id="scheduleAppointmentCapacity" aria-live="polite"><div class="schedule-capacity-heading"><span class="schedule-capacity-icon" id="scheduleAppointmentCapacityIcon">…</span><span id="scheduleAppointmentCapacityTitle">Checking appointment availability</span></div><div class="schedule-capacity-counts"><span>Daily appointments</span><strong id="scheduleAppointmentDailyCount">Checking…</strong></div><div class="schedule-capacity-bar" aria-hidden="true"><div class="schedule-capacity-fill" id="scheduleAppointmentCapacityFill"></div></div><p class="schedule-capacity-detail" id="scheduleAppointmentCapacityDetail">Checking daily and time-slot capacity…</p></section>' +
      '<p class="schedule-dialog-error" id="scheduleAppointmentError" role="alert" hidden></p>' +
      '<footer class="schedule-dialog-footer"><button type="button" class="sd-btn sd-btn-outline" id="scheduleAppointmentCancel">Cancel</button><button type="button" class="sd-btn sd-btn-default" id="scheduleAppointmentSubmit" disabled>Check Availability</button></footer>' +
      '</section>';
    document.body.appendChild(modal);
  }

  var error = document.getElementById('scheduleAppointmentError');
  var capacity = document.getElementById('scheduleAppointmentCapacity');
  var dateInput = document.getElementById('scheduleAppointmentDate');
  var timeInput = document.getElementById('scheduleAppointmentTime');
  var timeOptions = document.getElementById('scheduleAppointmentTimeOptions');
  var submit = document.getElementById('scheduleAppointmentSubmit');
  var capacityTitle = document.getElementById('scheduleAppointmentCapacityTitle');
  var capacityDailyCount = document.getElementById('scheduleAppointmentDailyCount');
  var capacityFill = document.getElementById('scheduleAppointmentCapacityFill');
  var capacityDetail = document.getElementById('scheduleAppointmentCapacityDetail');
  var capacityIcon = document.getElementById('scheduleAppointmentCapacityIcon');
  function updateSelectedTime() {
    Array.prototype.forEach.call(timeOptions.querySelectorAll('.schedule-time-option'), function (button) {
      var selected = button.dataset.time === timeInput.value;
      button.setAttribute('aria-pressed', String(selected));
      button.querySelector('span').textContent = selected ? 'Selected' : '';
    });
  }
  timeOptions.replaceChildren();
  Array.prototype.forEach.call(timeInput.options, function (option) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'schedule-time-option';
    button.dataset.time = option.value;
    button.setAttribute('aria-pressed', 'false');
    button.textContent = option.textContent;
    var selectedLabel = document.createElement('span');
    button.appendChild(selectedLabel);
    button.addEventListener('click', function () {
      timeInput.value = option.value;
      updateSelectedTime();
      refreshCapacity();
    });
    timeOptions.appendChild(button);
  });
  var today = new Date();
  dateInput.min = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
  var dismissSchedule = function () {
    closeModal('scheduleAppointmentDialog');
    if (typeof onComplete === 'function') onComplete(null);
  };
  document.getElementById('scheduleAppointmentClose').onclick = dismissSchedule;
  document.getElementById('scheduleAppointmentCancel').onclick = dismissSchedule;
  modal.onclick = function (event) {
    if (event.target === modal) dismissSchedule();
  };
  error.textContent = '';
  error.hidden = true;
  capacity.className = 'schedule-capacity is-checking';
  capacityTitle.textContent = 'Loading requested schedule';
  capacityDailyCount.textContent = 'Checking…';
  capacityFill.style.width = '0%';
  capacityDetail.textContent = 'Loading the resident’s requested schedule…';
  capacityIcon.textContent = '…';
  submit.disabled = true;
  submit.textContent = 'Check Availability';
  var previewSequence = 0;
  var slotState = null;

  function refreshCapacity() {
    var request = ++previewSequence;
    var date = dateInput.value;
    var time = timeInput.value;
    slotState = null;
    submit.disabled = true;
    submit.textContent = 'Checking…';
    capacity.className = 'schedule-capacity is-checking';
    capacityTitle.textContent = 'Checking appointment availability';
    capacityDailyCount.textContent = 'Checking…';
    capacityFill.style.width = '0%';
    capacityDetail.textContent = 'Checking daily and time-slot capacity…';
    capacityIcon.textContent = '…';
    error.hidden = true;
    var query = new URLSearchParams({ appointment_id: id, date: date, time: time });
    fetch(window.dcvsStaffUrl('appointment-slot-capacity.php') + '?' + query.toString())
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (request !== previewSequence) return;
        if (!result || !result.success) throw new Error((result && result.message) || 'Could not check appointment capacity.');
        slotState = result;
        capacity.className = 'schedule-capacity';
        capacityIcon.textContent = result.can_schedule ? '✓' : '!';
        capacityTitle.textContent = result.can_schedule ? 'Appointment slot available' : 'Appointment slot unavailable';
        capacityDailyCount.textContent = result.daily_count + ' of ' + result.daily_limit + ' booked';
        capacityFill.style.width = Math.min(100, result.daily_count / Math.max(1, result.daily_limit) * 100) + '%';
        capacityDetail.textContent = result.slot_count + ' of ' + result.slot_capacity + ' booked for this time.';
        if (result.conflict) {
          capacity.classList.add('is-unavailable');
          capacityDetail.textContent += ' This resident already has an appointment at the selected date and time.';
          submit.textContent = 'Resolve Conflict';
          submit.disabled = true;
        } else if (result.past) {
          capacity.classList.add('is-unavailable');
          capacityDetail.textContent += ' The selected time has already passed.';
          submit.textContent = 'Choose a Future Time';
          submit.disabled = true;
        } else if (!result.slot_open) {
          capacity.classList.add('is-unavailable');
          capacityDetail.textContent += ' This clinic time is closed or unavailable.';
          submit.textContent = 'Choose Another Time';
          submit.disabled = true;
        } else if (result.full && !result.has_pet) {
          capacity.classList.add('is-unavailable');
          capacityDetail.textContent += ' Capacity is full, but this request has no linked pet and cannot be added to the pet-required waitlist.';
          submit.textContent = 'Cannot Waitlist';
          submit.disabled = true;
        } else {
          if (result.full) {
            capacity.classList.add('is-unavailable');
            capacityTitle.textContent = 'Capacity reached — waitlist available';
            capacityDetail.textContent = 'This request can be moved to the waitlist.';
          }
          submit.textContent = result.full ? 'Move to Waitlist' : 'Confirm Schedule';
          submit.disabled = false;
        }
      })
      .catch(function (failure) {
        if (request !== previewSequence) return;
        slotState = null;
        capacity.className = 'schedule-capacity is-unavailable';
        capacityTitle.textContent = 'Availability check failed';
        capacityDailyCount.textContent = 'Unavailable';
        capacityDetail.textContent = '';
        error.textContent = failure.message || 'Could not check appointment capacity.';
        error.hidden = false;
        submit.textContent = 'Check Availability';
        submit.disabled = true;
      });
  }

  dateInput.onchange = refreshCapacity;
  timeInput.onchange = refreshCapacity;
  submit.onclick = function () {
    if (!slotState || submit.disabled) return;
    submit.disabled = true;
    submit.textContent = slotState.full ? 'Moving to Waitlist…' : 'Scheduling…';
    var body = new URLSearchParams();
    body.append('ajax', '1');
    body.append('appointment_id', id);
    body.append('scheduled_date', dateInput.value);
    body.append('scheduled_time', timeInput.value);
    body.append('staff_message', staffMessage || '');
    fetch(window.dcvsStaffUrl('approve-appointment.php'), { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.success) {
          closeModal('scheduleAppointmentDialog');
          closeModal('appointmentDetailsModal');
          var nextStatus = data.status || 'scheduled';
          var chosenDate = new Date(dateInput.value + 'T00:00:00');
          var chosenDateLabel = chosenDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
          var chosenTimeLabel = timeInput.options[timeInput.selectedIndex].text;
          if (window.apptUpdateRow) window.apptUpdateRow(id, nextStatus, chosenDateLabel + ' · ' + chosenTimeLabel);
          var tableRow = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
          if (tableRow && nextStatus === 'scheduled') {
            tableRow.setAttribute('data-date', String(Math.floor(new Date(dateInput.value + 'T' + timeInput.value + ':00').getTime() / 1000)));
          }
          if (window.portalToast) portalToast(nextStatus === 'waitlisted' ? 'Appointment waitlisted' : 'Appointment scheduled', data.message || 'Appointment request updated.');
          if (typeof onComplete === 'function') onComplete(nextStatus);
          return;
        }
        error.textContent = (data && data.message) || 'Could not schedule this appointment.';
        error.hidden = false;
        refreshCapacity();
      })
      .catch(function () {
        error.textContent = 'Network error. Please try again.';
        error.hidden = false;
        refreshCapacity();
      });
  };
  fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
    .then(function (response) { return response.json(); })
    .then(function (result) {
      var appointment = result && result.data;
      if (!result || !result.success || !appointment) throw new Error((result && result.message) || 'Could not load the requested schedule.');
      dateInput.value = appointment.preferred_date || '';
      var requestedTime = (appointment.preferred_time || '').substring(0, 5);
      if (requestedTime) timeInput.value = requestedTime;
      updateSelectedTime();
      refreshCapacity();
    })
    .catch(function (failure) {
      error.textContent = failure.message || 'Could not load the requested schedule.';
      error.hidden = false;
      capacity.className = 'schedule-capacity is-unavailable';
      capacityTitle.textContent = 'Could not load schedule';
      capacityDailyCount.textContent = 'Unavailable';
      capacityDetail.textContent = '';
    });
  openModal('scheduleAppointmentDialog');
}
window.APP = window.APP || {};
(function(APP) {
  var apptSelected = {};
  function updateApptBulkActionVisibility() {
    var ids = Object.keys(apptSelected);
    var selectedRows = ids.map(function (id) {
      return document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
    }).filter(Boolean);
    var allFinal = selectedRows.length > 0 && selectedRows.every(function (row) {
      return ['completed', 'no_show', 'cancelled', 'rejected'].indexOf(row.getAttribute('data-status')) !== -1;
    });
    var allOutcomeDue = selectedRows.length > 0 && selectedRows.every(function (row) {
      return ['scheduled', 'rescheduled', 'in_progress'].indexOf(row.getAttribute('data-status')) !== -1
        && row.getAttribute('data-can-outcome') === '1';
    });
    var archive = document.getElementById('apptBulkArchiveBtn');
    var complete = document.getElementById('apptBulkCompleteBtn');
    var noShow = document.getElementById('apptBulkNoShowBtn');
    if (archive) archive.style.display = allFinal ? '' : 'none';
    if (complete) complete.style.display = allOutcomeDue ? '' : 'none';
    if (noShow) noShow.style.display = allOutcomeDue ? '' : 'none';
  }

  /* ── Appt bulk BEGIN: checkbox quick actions (same pattern as All Reports) ── */
  APP.toggleSelect = function(id) {
    id = String(id);
    var chk = document.querySelector('.appt-row-check[data-id="' + id + '"]');
    var row = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
    if (chk && chk.checked) {
      apptSelected[id] = true;
      if (row) row.classList.add('selected');
    } else if (chk) {
      delete apptSelected[id];
      if (row) row.classList.remove('selected');
    }
    var n = Object.keys(apptSelected).length;
    var bar = document.getElementById('apptBulkActions');
    var cnt = document.getElementById('apptSelectedCount');
    if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
    if (cnt) cnt.textContent = n;
    updateApptBulkActionVisibility();
    var all = document.getElementById('apptSelectAll');
    var total = document.querySelectorAll('#appointmentsTable tbody .appt-row-check').length;
    if (all) {
      all.checked = total > 0 && n === total;
      all.indeterminate = n > 0 && n < total;
    }
  };
  APP.deselect = function(id) {
    id = String(id);
    delete apptSelected[id];
    var chk = document.querySelector('.appt-row-check[data-id="' + id + '"]');
    var row = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
    if (chk) chk.checked = false;
    if (row) row.classList.remove('selected');
    var n = Object.keys(apptSelected).length;
    var bar = document.getElementById('apptBulkActions');
    var count = document.getElementById('apptSelectedCount');
    var all = document.getElementById('apptSelectAll');
    var total = document.querySelectorAll('#appointmentsTable tbody .appt-row-check').length;
    if (bar) bar.style.display = n ? 'flex' : 'none';
    if (count) count.textContent = n;
    updateApptBulkActionVisibility();
    if (all) { all.checked = total > 0 && n === total; all.indeterminate = n > 0 && n < total; }
  };

  var apptSeq = null;
  var apptConfirmSaved = null;
  var apptArchiveOrig = null;
  var apptReschedOrig = null;
  var apptDetailsBulkSaved = null;

  function apptEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function apptRowData(id) {
    var row = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
    if (!row) return null;
    var tds = row.querySelectorAll('td');
    return {
      id: String(id),
      status: row.getAttribute('data-status') || '',
      ref: tds[1] ? tds[1].textContent.trim() : '#APT-' + id,
      resident: tds[3] ? tds[3].textContent.trim() : '-',
      pet: tds[4] ? tds[4].textContent.trim() : '-',
      schedule: tds[5] ? tds[5].textContent.trim() : '-'
    };
  }

  function apptSelectedItems() {
    return Object.keys(apptSelected).map(apptRowData).filter(Boolean);
  }

  function apptToast(t, d) {
    if (typeof portalToast === 'function') portalToast(t, d);
    else if (typeof cpdToast === 'function') cpdToast(d ? (t + ': ' + d) : t);
  }

  function apptFlashReload(t, d) { apptToast(t, d); }

  function apptPost(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { return !!(d && d.success); })
      .catch(function () { return false; });
  }

  function apptEnsureSel() {
    var items = apptSelectedItems();
    if (!items.length) { apptToast('Select Appointments', 'Select at least one appointment first.'); return null; }
    return items;
  }

  /* Shared sequential confirm runner (confirmAppointmentAction) */
  function apptShowConfirmStep(cfg) {
    if (!apptSeq || !apptSeq.queue.length) { apptFinishConfirm(cfg); return; }
    var seq = apptSeq;
    var item = seq.queue[0];
    var idx = seq.total - seq.queue.length + 1;
    confirmAppointmentAction(cfg.title, cfg.msg(item, seq, idx), function () {
      if (apptSeq !== seq) return;
      var it = seq.queue.shift();
      var okBtn = document.getElementById('appointmentActionConfirmOk');
      if (okBtn) okBtn.disabled = true;
      cfg.do(it)
        .then(function (good) {
          if (good) {
            seq.applied.push(it);
            if (cfg.nextStatus && window.apptUpdateRow) window.apptUpdateRow(it.id, cfg.nextStatus);
          } else seq.failed = (seq.failed || 0) + 1;
        })
        .catch(function () { seq.failed = (seq.failed || 0) + 1; })
        .then(function () {
          if (okBtn) okBtn.disabled = false;
          if (apptSeq !== seq) return;
          if (seq.queue.length) apptShowConfirmStep(cfg);
          else apptFinishConfirm(cfg);
        });
    });
    var ok = document.getElementById('appointmentActionConfirmOk');
    var cancel = document.getElementById('appointmentActionConfirmCancel');
    if (!apptConfirmSaved) apptConfirmSaved = { label: ok ? ok.textContent : 'Continue', cancel: cancel ? cancel.onclick : null };
    if (ok) { ok.textContent = cfg.okLabel || 'Continue'; ok.disabled = false; }
    if (cancel) cancel.onclick = function () { closeModal('appointmentActionConfirm'); apptAbort(); };
  }

  function apptFinishConfirm(cfg) {
    if (!apptSeq) return;
    var seq = apptSeq;
    apptSeq = null;
    apptRestoreConfirm();
    var m = cfg.finish(seq);
    apptFlashReload(m[0], m[1]);
  }

  function apptRestoreConfirm() {
    if (!apptConfirmSaved) return;
    var ok = document.getElementById('appointmentActionConfirmOk');
    var cancel = document.getElementById('appointmentActionConfirmCancel');
    if (ok) { ok.textContent = apptConfirmSaved.label; ok.disabled = false; }
    if (cancel && apptConfirmSaved.cancel) cancel.onclick = apptConfirmSaved.cancel;
    apptConfirmSaved = null;
  }

  function apptAbort() {
    if (!apptSeq) return;
    var sequence = apptSeq;
    apptSeq = null;
    if (sequence.type.indexOf('details-') === 0) {
      apptDetailsBulkRestore();
      closeModal('appointmentDetailsModal');
    } else if (sequence.type === 'archive') {
      apptRestoreArchive();
      closeModal('archiveApptModal');
      if (sequence.applied.length && window.apptRemoveRows) {
        window.apptRemoveRows(sequence.applied.map(function (item) { return item.id; }));
      }
    } else if (sequence.type === 'reschedule') {
      apptRestoreResched();
      apptDetailsBulkRestore();
      closeModal('rescheduleAppointmentModal');
      closeModal('appointmentDetailsModal');
    } else if (sequence.type === 'confirm') {
      apptRestoreConfirm();
      closeModal('appointmentActionConfirm');
    }
    if (sequence.applied && sequence.applied.length) {
      apptFlashReload('Appointment Action Stopped', sequence.applied.length + ' change(s) applied before cancelling.');
    }
  }

  /* Review — checkbox-driven dialog; details auto-load, decisions advance per selected request */
  var apptReview = null;

  function revEl(id) { return document.getElementById(id); }

  function apptReviewProgress(text, color) {
    var el = revEl('reviewProgress');
    if (!el) return;
    el.style.display = text ? 'block' : 'none';
    el.style.color = color || '#71717a';
    el.textContent = text || '';
  }

  function apptReviewFill(a) {
    var set = function (id, v) { var el = revEl(id); if (el) el.value = v; };
    set('formApptId', a.appointment_id);
    set('lookupRequestId', a.reference_id || ('#APT-' + a.appointment_id));
    set('formResidentName', a.full_name || '-');
    set('formPetName', a.pet_name || '-');
    set('formApptType', a.appointment_type ? a.appointment_type.charAt(0).toUpperCase() + a.appointment_type.slice(1) : '-');
    set('formDate', a.preferred_date || '');
    set('formTime', (a.preferred_time || '').length > 5 ? a.preferred_time.slice(0, 5) : (a.preferred_time || ''));
    set('formRequestNotes', a.notes || '');
    set('formStatus', '');
    set('formRejectReason', '');
    set('formStaffMessage', '');
    var rw = revEl('rejectReasonWrap');
    if (rw) rw.style.display = 'none';
    var rr = revEl('formRejectReason');
    if (rr) rr.required = false;
    var st = revEl('formSlotStatus');
    if (st) st.style.display = 'none';
    var sb = revEl('formSubmitBtn');
    if (sb) { sb.disabled = false; sb.textContent = 'Submit Decision'; }
  }

  function apptReviewSlotCheck(a) {
    var st = revEl('formSlotStatus');
    if (!st || !a.preferred_date || !a.preferred_time) return;
    st.style.display = 'block';
    st.style.background = '#fefce8';
    st.style.color = '#92400e';
    st.textContent = 'Checking availability...';
    fetch(window.dcvsStaffUrl('includes/check_slot.php') + '?date=' + encodeURIComponent(a.preferred_date) + '&time=' + encodeURIComponent(a.preferred_time) + '&exclude=' + a.appointment_id + '&urgency=' + encodeURIComponent(a.urgency_level || 'routine'))
      .then(function (r) { return r.json(); })
      .then(function (sd) {
        if (!sd || typeof sd.available !== 'boolean') { st.style.display = 'none'; return; }
        if (sd.error) {
          st.style.background = '#fef2f2';
          st.style.color = '#991b1b';
          st.textContent = sd.error;
          var submit = revEl('formSubmitBtn');
          if (submit) submit.disabled = true;
          return;
        }
        if (sd.available) {
          st.style.background = '#f0fdf4';
          st.style.color = '#065f46';
          st.textContent = 'Slot available for this time.';
        } else {
          st.style.background = '#fef2f2';
          st.style.color = '#991b1b';
          var m = sd.clinic_blocked ? 'This time slot is closed by schedule (Availability page).'
                            : (sd.held ? 'This time slot is already reserved by another resident.'
                                       : 'This preferred slot is no longer available.');
          if (sd.nearest_slot) {
            var nearest = sd.nearest_slot.split(' ');
            var nearDate = new Date(nearest[0] + 'T00:00:00');
            m += ' Nearest available: ' + nearDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' at ' + nearest[1] + '.';
          }
          st.textContent = m;
        }
      })
      .catch(function (error) {
        console.error('Appointment slot availability check failed:', error);
        st.style.background = '#fef2f2';
        st.style.color = '#991b1b';
        st.textContent = 'Could not verify this appointment slot. Please try again.';
        var submit = revEl('formSubmitBtn');
        if (submit) submit.disabled = true;
      });
  }

  function apptReviewLoadNext() {
    var rv = apptReview;
    if (!rv) return;
    if (!rv.queue.length) return apptReviewFinish();
    var idx = rv.total - rv.queue.length + 1;
    var id = rv.queue[0];
    var sb = revEl('formSubmitBtn');
    if (sb) { sb.disabled = true; sb.textContent = 'Submit Decision'; }
    apptReviewProgress('Loading request ' + idx + ' of ' + rv.total + '…');
    fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (apptReview !== rv) return;
        if (!d || !d.success || !d.data) {
          apptToast('Request Unavailable', (d && d.message) || 'Could not load request ' + id + '.');
          rv.failed++;
          rv.queue.shift();
          if (rv.queue.length) apptReviewLoadNext();
          else if (rv.manual) {
            apptReviewProgress((d && d.message) || 'Request not found. Check the ID and try again.', '#b91c1c');
            var lookup = revEl('lookupRequestId');
            if (lookup) lookup.focus();
          } else apptReviewFinish();
          return;
        }
        var a = d.data;
        apptReviewFill(a);
        if (rv.bulkDetails) {
          closeModal('addAppointmentModal');
          apptDetailsBulkSave();
          APP.view(id, function (detail) {
            if (apptReview !== rv) return;
            apptBulkReviewShowDetails(detail, rv);
          }, function (message) {
            if (apptReview !== rv) return;
            apptToast('Request Unavailable', message || 'Could not load request ' + id + '.');
            rv.failed++;
            rv.queue.shift();
            if (rv.queue.length) apptReviewLoadNext();
            else apptReviewFinish();
          });
          return;
        }
        var line = 'Request ' + idx + ' of ' + rv.total + ' · ' + (a.reference_id || ('#APT-' + id)) + ' — ' + (a.full_name || 'resident');
        if (rv.skipped && idx === 1) line += ' · ' + rv.skipped + ' non-pending selection(s) skipped';
        if (a.status !== 'pending') {
          apptReviewProgress('This request has already been processed (status: ' + a.status + ').', '#b91c1c');
          var b = revEl('formSubmitBtn');
          if (b) b.disabled = true;
        } else {
          apptReviewProgress(line);
          apptReviewSlotCheck(a);
        }
      })
      .catch(function () {
        if (apptReview !== rv) return;
        apptToast('Network Error', 'Could not load request ' + id + '.');
        rv.failed++;
        rv.queue.shift();
        if (rv.queue.length) apptReviewLoadNext();
        else if (rv.manual) {
          apptReviewProgress('Could not load request. Check your connection and try again.', '#b91c1c');
          var lookup = revEl('lookupRequestId');
          if (lookup) lookup.focus();
        } else apptReviewFinish();
      });
  }

  function apptReviewOpen(ids, skipped, bulkDetails, selectedItems) {
    ids = (ids || []).map(String);
    if (!ids.length) {
      apptToast('Nothing to Review', 'Only pending appointment requests can be reviewed and approved.');
      return;
    }
    if (apptReview) return;
    var residentNames = [];
    (selectedItems || []).forEach(function (item) {
      var name = String(item.resident || '').trim();
      if (name && name !== '-' && residentNames.indexOf(name) === -1) residentNames.push(name);
    });
    apptReview = {
      queue: ids,
      total: ids.length,
      selectedCount: ids.length + (skipped || 0),
      residentName: residentNames.length === 1 ? residentNames[0] : '',
      applied: [],
      failed: 0,
      skipped: skipped || 0,
      busy: false,
      bulkDetails: !!bulkDetails
    };
    if (!bulkDetails) openModal('addAppointmentModal');
    apptReviewLoadNext();
  }

  function apptReviewResetForm() {
    var form = revEl('addAppointmentForm');
    if (form) form.reset();
    apptReviewProgress('');
    var slot = revEl('formSlotStatus');
    if (slot) slot.style.display = 'none';
    var rejectWrap = revEl('rejectReasonWrap');
    if (rejectWrap) rejectWrap.style.display = 'none';
    var rejectReason = revEl('formRejectReason');
    if (rejectReason) rejectReason.required = false;
    var submit = revEl('formSubmitBtn');
    if (submit) { submit.disabled = true; submit.textContent = 'Submit Decision'; }
  }

  APP.reviewOpen = function () {
    if (apptReview) return;
    apptReviewResetForm();
    apptReview = { queue: [], total: 0, applied: [], failed: 0, skipped: 0, busy: false, manual: true };
    openModal('addAppointmentModal');
    var lookup = revEl('lookupRequestId');
    if (lookup) lookup.focus();
  };

  APP.reviewLookup = function () {
    var lookup = revEl('lookupRequestId');
    var requestId = lookup ? lookup.value.trim() : '';
    if (!requestId) {
      apptToast('Request ID Required', 'Enter an appointment request ID to look it up.');
      if (lookup) lookup.focus();
      return;
    }
    if (!apptReview) APP.reviewOpen();
    if (apptReview.busy || apptReview.applied.length) return;
    apptReviewResetForm();
    if (lookup) lookup.value = requestId;
    apptReview.queue = [requestId];
    apptReview.total = 1;
    apptReview.failed = 0;
    apptReview.skipped = 0;
    apptReview.manual = true;
    apptReviewLoadNext();
  };

  function apptReviewFinish() {
    var rv = apptReview;
    if (rv && rv.busy) return;
    apptReview = null;
    closeModal('addAppointmentModal');
    if (rv && rv.bulkDetails) {
      apptDetailsBulkRestore();
      closeModal('appointmentDetailsModal');
    }
    if (!rv) return;
    var detail = rv.applied.length + ' of ' + rv.total + ' selected request(s) processed.';
    if (rv.skipped) detail += ' ' + rv.skipped + ' non-pending skipped.';
    if (rv.failed) detail += ' ' + rv.failed + ' failed.';
    if (!rv.applied.length) { apptToast('Nothing Reviewed', detail); return; }
    apptFlashReload('Appointment Requests Reviewed', detail);
  }

  function apptReviewClose() {
    var rv = apptReview;
    if (rv && rv.busy) return;
    apptReview = null;
    closeModal('addAppointmentModal');
    if (rv && rv.bulkDetails) {
      apptDetailsBulkRestore();
      closeModal('appointmentDetailsModal');
    }
    if (rv && rv.applied.length) apptFlashReload('Appointment Action Stopped', rv.applied.length + ' change(s) applied before cancelling.');
  }
  APP.reviewClose = apptReviewClose;
  APP.reviewIds = function (ids, skipped) { apptReviewOpen(ids, skipped); };
  APP.reviewSelection = function () {
    APP.reviewOpen();
  };

  function apptBulkReviewResetControls() {
    var panel = revEl('apptBulkRejectPanel');
    var reason = revEl('apptBulkRejectReason');
    var message = revEl('apptBulkReviewMessage');
    if (panel) panel.style.display = 'none';
    if (reason) reason.value = '';
    if (message) { message.textContent = ''; message.style.display = 'none'; }
  }

  function apptBulkReviewShowDetails(detail, sequence) {
    var modal = revEl('appointmentDetailsModal');
    var title = modal ? modal.querySelector('.sd-dialog-title') : null;
    var description = modal ? modal.querySelector('.sd-dialog-description') : null;
    var footer = revEl('apptDetailFooter');
    var editButton = revEl('apptDrawerEditBtn');
    var pending = detail.status === 'pending';
    var statusMessage = pending ? '' : 'This request has already been processed (status: ' + detail.status + ').';
    apptBulkReviewResetControls();
    if (modal) modal.classList.add('appt-bulk-dialog');
    if (title) title.textContent = 'Appointment Details to Review';
    var selectedText = sequence.selectedCount === 1 ? '1 appointment request' : sequence.selectedCount + ' appointment requests';
    var residentText = sequence.residentName ? ' from ' + sequence.residentName : '';
    var message = 'Review the selected appointment requests before choosing a decision. ' +
      selectedText + residentText + (sequence.selectedCount === 1 ? ' is selected.' : ' are selected.');
    if (sequence.skipped) message += ' Non-pending requests will be skipped.';
    if (description) description.textContent = message;
    if (editButton) editButton.style.display = 'none';
    var message = revEl('apptBulkReviewMessage');
    if (message && statusMessage) { message.textContent = statusMessage; message.style.display = 'block'; }
    if (!footer) return;
    footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="apptBulkReviewCancel">Cancel</button>' +
      '<button type="button" class="sd-btn sd-btn-default" id="apptBulkReviewSchedule"' + (pending ? '' : ' disabled') + '>Schedule</button>' +
      '<button type="button" class="sd-btn sd-btn-outline" id="apptBulkReviewReject" style="color:#b91c1c;border-color:#fecaca;"' + (pending ? '' : ' disabled') + '>Reject</button>';
    var cancel = revEl('apptBulkReviewCancel');
    var reject = revEl('apptBulkReviewReject');
    var schedule = revEl('apptBulkReviewSchedule');
    var close = modal ? modal.querySelector('.sd-dialog-close') : null;
    if (cancel) cancel.onclick = apptReviewClose;
    if (close) close.onclick = apptReviewClose;
    if (reject) reject.onclick = function () { apptBulkReviewShowReject(detail, sequence); };
    if (schedule) schedule.onclick = function () { apptBulkReviewSubmit(detail, sequence, 'scheduled', ''); };
  }

  function apptBulkReviewShowReject(detail, sequence) {
    var panel = revEl('apptBulkRejectPanel');
    var reason = revEl('apptBulkRejectReason');
    var footer = revEl('apptDetailFooter');
    var message = revEl('apptBulkReviewMessage');
    if (panel) panel.style.display = 'block';
    if (message) { message.textContent = ''; message.style.display = 'none'; }
    if (footer) {
      footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="apptBulkReviewCancel">Cancel</button>' +
        '<button type="button" class="sd-btn sd-btn-default" id="apptBulkReviewConfirmReject" style="background:#dc2626;border-color:#dc2626;color:#fff;">Confirm Reject</button>';
      var cancel = revEl('apptBulkReviewCancel');
      var confirm = revEl('apptBulkReviewConfirmReject');
      var close = revEl('appointmentDetailsModal').querySelector('.sd-dialog-close');
      if (cancel) cancel.onclick = apptReviewClose;
      if (close) close.onclick = apptReviewClose;
      if (confirm) confirm.onclick = function () {
        var value = reason ? reason.value.trim() : '';
        if (!value) {
          if (message) { message.textContent = 'Please enter a rejection reason.'; message.style.display = 'block'; }
          if (reason) reason.focus();
          return;
        }
        apptBulkReviewSubmit(detail, sequence, 'rejected', value);
      };
    }
    if (reason) reason.focus();
  }

  function apptBulkReviewSubmit(detail, sequence, decision, reason) {
    if (apptReview !== sequence || sequence.busy) return;
    if (decision === 'scheduled') {
      sequence.busy = true;
      schedulePendingAppointment(detail.appointment_id, function (status) {
        if (apptReview !== sequence) return;
        sequence.busy = false;
        if (!status) return;
        sequence.applied.push({ id: String(detail.appointment_id), status: status });
        sequence.queue.shift();
        if (sequence.queue.length) apptReviewLoadNext();
        else apptReviewFinish();
      });
      return;
    }
    sequence.busy = true;
    var footer = revEl('apptDetailFooter');
    var message = revEl('apptBulkReviewMessage');
    if (footer) footer.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
    var body = new URLSearchParams();
    body.append('ajax', '1');
    body.append('appointment_id', detail.appointment_id);
    body.append('staff_message', '');
    if (decision === 'rejected') body.append('reason', reason);
    fetch(window.dcvsStaffUrl(decision === 'scheduled' ? 'approve-appointment.php' : 'reject-appointment.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) { return response.json(); })
      .then(function (result) {
        if (apptReview !== sequence) return;
        sequence.busy = false;
        if (!result || !result.success) {
          if (message) { message.textContent = (result && result.message) || 'Could not save this decision.'; message.style.display = 'block'; }
          if (footer) footer.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
          return;
        }
        sequence.applied.push({ id: String(detail.appointment_id), status: decision });
        if (window.apptUpdateRow) window.apptUpdateRow(detail.appointment_id, decision);
        sequence.queue.shift();
        closeModal('appointmentDetailsModal');
        if (sequence.queue.length) apptReviewLoadNext();
        else apptReviewFinish();
      }).catch(function () {
        if (apptReview !== sequence) return;
        sequence.busy = false;
        if (message) { message.textContent = 'Network error. Could not save this decision.'; message.style.display = 'block'; }
        if (footer) footer.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
      });
  }

  /* Submit Decision — applies the choice, then advances to the next selected request */
  (function () {
    var form = revEl('addAppointmentForm');
    if (!form || !form.addEventListener) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var rv = apptReview;
      if (!rv || rv.busy) return;
      var statusSel = revEl('formStatus');
      var decision = statusSel ? statusSel.value : '';
      var apptId = (revEl('formApptId') || {}).value;
      if (!apptId) { apptToast('No Request Loaded', 'Select a pending request to review first.'); return; }
      if (decision !== 'scheduled' && decision !== 'rejected') {
        apptToast('Choose a Decision', 'Select Approve & Schedule or Reject.');
        return;
      }
      var reason = revEl('formRejectReason');
      if (decision === 'rejected' && (!reason || !reason.value.trim())) {
        apptToast('Rejection Reason', 'Please enter a rejection reason.');
        if (reason) reason.focus();
        return;
      }
      rv.busy = true;
      var sb = revEl('formSubmitBtn');
      if (sb) { sb.disabled = true; sb.textContent = 'Saving…'; }
      var body = 'ajax=1&appointment_id=' + encodeURIComponent(apptId) +
        '&staff_message=' + encodeURIComponent((revEl('formStaffMessage') || {}).value || '');
      if (decision === 'rejected') body += '&reason=' + encodeURIComponent(reason.value);
      if (decision === 'scheduled') {
        schedulePendingAppointment(apptId, function (status) {
          if (apptReview !== rv) return;
          rv.busy = false;
          if (!status) {
            var reviewSubmit = revEl('formSubmitBtn');
            if (reviewSubmit) reviewSubmit.disabled = false;
            return;
          }
          rv.applied.push({ id: String(apptId), status: status });
          rv.queue.shift();
          if (rv.queue.length) apptReviewLoadNext();
          else apptReviewFinish();
        }, (revEl('formStaffMessage') || {}).value || '');
        return;
      }
      var url = 'reject-appointment.php';
      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (apptReview !== rv) return;
          rv.busy = false;
          if (sb) sb.textContent = 'Submit Decision';
          if (d && d.success) {
            rv.applied.push({ id: String(apptId), status: decision });
            if (window.apptUpdateRow) window.apptUpdateRow(apptId, 'rejected');
            rv.queue.shift();
            if (rv.queue.length) apptReviewLoadNext();
            else apptReviewFinish();
          } else {
            var msg = (d && d.message) || 'Could not save the decision.';
            apptToast('Decision Not Saved', msg);
            var st = revEl('formSlotStatus');
            if (st) {
              st.style.display = 'block';
              st.style.background = '#fef2f2';
              st.style.color = '#991b1b';
              st.textContent = msg;
            }
            if (sb) sb.disabled = false;
          }
        })
        .catch(function () {
          if (apptReview !== rv) return;
          rv.busy = false;
          if (sb) { sb.disabled = false; sb.textContent = 'Submit Decision'; }
          apptToast('Network Error', 'Could not save the decision. Please try again.');
        });
    });
  })();

  /* Cancel/X/backdrop/Escape close the review dialog (partial work kept + flash) */
  document.addEventListener('click', function (e) {
    if (apptReview && apptReview.bulkDetails && e.target && e.target.id === 'appointmentDetailsModal') {
      e.stopPropagation();
      apptReviewClose();
      return;
    }
    if (apptReview && e.target && e.target.id === 'addAppointmentModal') {
      e.stopPropagation();
      apptReviewClose();
    }
  }, true);
  document.addEventListener('keydown', function (e) {
    if (apptReview && e.key === 'Escape') {
      e.stopPropagation();
      apptReviewClose();
    }
  }, true);

  /* Review — pending requests via the Review Appointment Request dialog */
  APP.bulkReview = function () {
    var items = apptEnsureSel();
    if (!items) return;
    var eligible = items.filter(function (r) { return r.status === 'pending'; });
    if (!eligible.length) {
      apptToast('Nothing to Review', 'Only pending appointment requests can be reviewed and approved. Other selected appointments are ignored.');
      return;
    }
    apptReviewOpen(eligible.map(function (r) { return r.id; }), items.length - eligible.length, true, items);
  };

  function apptDetailsBulkSave() {
    if (apptDetailsBulkSaved) return;
    var modal = document.getElementById('appointmentDetailsModal');
    var description = modal ? modal.querySelector('.sd-dialog-description') : null;
    var title = modal ? modal.querySelector('.sd-dialog-title') : null;
    var footer = document.getElementById('apptDetailFooter');
    var close = modal ? modal.querySelector('.sd-dialog-close') : null;
    var editButton = document.getElementById('apptDrawerEditBtn');
    apptDetailsBulkSaved = {
      description: description ? description.textContent : 'Complete details of this appointment request.',
      title: title ? title.textContent : 'Appointment Details',
      footer: footer ? footer.innerHTML : '',
      close: close ? close.onclick : null,
      editDisplay: editButton ? editButton.style.display : ''
    };
  }

  function apptBulkDetailsPrompt(message, actionLabel, onCancel, onContinue, dialogTitle) {
    var modal = document.getElementById('appointmentDetailsModal');
    var description = modal ? modal.querySelector('.sd-dialog-description') : null;
    var title = modal ? modal.querySelector('.sd-dialog-title') : null;
    var footer = document.getElementById('apptDetailFooter');
    if (modal) modal.classList.add('appt-bulk-dialog');
    if (title && dialogTitle) title.textContent = dialogTitle;
    if (dialogTitle === 'Appointment Details to Review') {
      var editButton = document.getElementById('apptDrawerEditBtn');
      if (editButton) editButton.style.display = 'none';
    }
    if (description) description.textContent = message;
    if (!footer) return;
    footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="apptBulkDetailsCancel">Cancel</button>' +
      '<button type="button" class="sd-btn sd-btn-default" id="apptBulkDetailsContinue">' + apptEsc(actionLabel) + '</button>';
    var cancel = document.getElementById('apptBulkDetailsCancel');
    var close = modal ? modal.querySelector('.sd-dialog-close') : null;
    var proceed = document.getElementById('apptBulkDetailsContinue');
    if (cancel) cancel.onclick = onCancel;
    if (close) close.onclick = onCancel;
    if (proceed) proceed.onclick = onContinue;
  }

  function apptDetailsBulkRestore() {
    var modal = document.getElementById('appointmentDetailsModal');
    var description = modal ? modal.querySelector('.sd-dialog-description') : null;
    var title = modal ? modal.querySelector('.sd-dialog-title') : null;
    var footer = document.getElementById('apptDetailFooter');
    var close = modal ? modal.querySelector('.sd-dialog-close') : null;
    if (apptDetailsBulkSaved) {
      if (description) description.textContent = apptDetailsBulkSaved.description;
      if (title) title.textContent = apptDetailsBulkSaved.title;
      if (footer) footer.innerHTML = apptDetailsBulkSaved.footer;
      if (close && apptDetailsBulkSaved.close) close.onclick = apptDetailsBulkSaved.close;
      var editButton = document.getElementById('apptDrawerEditBtn');
      if (editButton) editButton.style.display = apptDetailsBulkSaved.editDisplay;
    }
    apptBulkReviewResetControls();
    if (modal) modal.classList.remove('appt-bulk-dialog');
    apptDetailsBulkSaved = null;
  }

  function apptStartDetailsBulk(type, items, eligible) {
    if (!eligible.length) {
      var emptyMessages = {
        review:'Only pending appointment requests can be reviewed.',
        reschedule:'Only pending, scheduled, or rescheduled appointments can be rescheduled.',
        complete:'Only due scheduled appointments can be marked as completed.',
          'no-show':'Only due scheduled appointments can be marked as no-show.',
          archive:'Only completed, no-show, cancelled, or rejected appointments can be archived.'
      };
      apptToast(type === 'complete' ? 'Nothing to Complete' : 'No eligible appointments', emptyMessages[type]);
      return;
    }
    apptSeq = { type:'details-' + type, queue:eligible.slice(), total:eligible.length, skipped:items.length - eligible.length, applied:[], failed:0, processing:false };
    var modal = document.getElementById('appointmentDetailsModal');
    var description = modal ? modal.querySelector('.sd-dialog-description') : null;
    var footer = document.getElementById('apptDetailFooter');
    apptDetailsBulkSave();
    apptShowDetailsBulkStep();
  }

  function apptBulkActionDescription(appointment, sequence, action, index) {
    var details = [];
    if (appointment.pet_name && appointment.pet_name !== '-') details.push('pet: ' + appointment.pet_name);
    if (appointment.barangay_name) details.push('barangay: ' + appointment.barangay_name);
    var status = String(appointment.status || 'unknown').replace(/_/g, ' ');
    status = status.charAt(0).toUpperCase() + status.slice(1);
    details.push('status: ' + status);
    var message = 'Review the appointment for ' + (appointment.full_name || 'this resident') +
      ' (' + details.join(', ') + ') before ' + action + '. ';
    var selectedCount = sequence.total + sequence.skipped;
    message += selectedCount === 1 ? '1 appointment is selected.' : selectedCount + ' appointments are selected.';
    if (sequence.skipped) message += ' Ineligible appointments will be skipped automatically.';
    if (sequence.total > 1) message += ' Reviewing ' + index + ' of ' + sequence.total + ' eligible appointments.';
    return message;
  }

  function apptShowDetailsBulkStep() {
    if (!apptSeq || !apptSeq.queue.length) { apptFinishDetailsBulk(); return; }
    var sequence = apptSeq;
    var item = sequence.queue[0];
    var index = sequence.total - sequence.queue.length + 1;
    var modal = document.getElementById('appointmentDetailsModal');
    if (modal) modal.classList.add('appt-bulk-dialog');
    APP.view(item.id, function (appointment) {
      if (apptSeq !== sequence) return;
      var description = modal ? modal.querySelector('.sd-dialog-description') : null;
      var footer = document.getElementById('apptDetailFooter');
      var action = sequence.type === 'details-complete' ? 'marking it as completed'
        : (sequence.type === 'details-no-show' ? 'marking it as no-show' : 'archiving it');
      if (description) description.textContent = apptBulkActionDescription(appointment, sequence, action, index);
      if (!footer) return;
      var actionLabel = sequence.type === 'details-complete' ? 'Mark this appointment completed'
        : (sequence.type === 'details-no-show' ? 'Mark this appointment no-show' : 'Archive this appointment');
      var actionStyle = sequence.type === 'details-archive' ? ' style="background:#dc2626;border-color:#dc2626;color:#fff;"' : '';
      footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="apptDetailsBulkCancel">Cancel</button>' +
        '<button type="button" class="sd-btn sd-btn-default" id="apptDetailsBulkConfirm"' + actionStyle + '>' + actionLabel + '</button>';
      var cancel = document.getElementById('apptDetailsBulkCancel');
      var confirm = document.getElementById('apptDetailsBulkConfirm');
      cancel.onclick = function () { closeModal('appointmentDetailsModal'); apptAbort(); };
      confirm.onclick = function () {
        if (apptSeq !== sequence || sequence.processing) return;
        sequence.processing = true;
        confirm.disabled = true;
        cancel.disabled = true;
        confirm.textContent = sequence.type === 'details-complete' ? 'Completing...'
          : (sequence.type === 'details-no-show' ? 'Marking no-show...' : 'Archiving...');
        var operation = sequence.type === 'details-complete'
          ? apptPost('update-appointment-status.php', 'action=complete&ids=' + encodeURIComponent(item.id))
          : (sequence.type === 'details-no-show'
            ? apptPost('update-appointment-status.php', 'action=no_show&ids=' + encodeURIComponent(item.id))
            : fetch(window.dcvsStaffUrl('client-database-handler.php'), { method:'POST', body:(function() { var fd = new FormData(); fd.append('action','archive_appointment'); fd.append('appointment_id',item.id); return fd; })() })
              .then(function (response) { return response.json(); }).then(function (data) { return !!(data && data.success); }).catch(function () { return false; }));
        operation.then(function (success) {
          if (apptSeq !== sequence) return;
          sequence.processing = false;
          if (success) {
            sequence.applied.push(item);
            if (sequence.type === 'details-complete' && window.apptUpdateRow) window.apptUpdateRow(item.id, 'completed');
            if (sequence.type === 'details-no-show' && window.apptUpdateRow) window.apptUpdateRow(item.id, 'no_show');
            if (sequence.type === 'details-archive' && window.apptRemoveRows) window.apptRemoveRows([item.id]);
          } else {
            sequence.failed++;
            apptToast('Appointment action failed', 'Could not update ' + (appointment.reference_id || 'this appointment') + '.');
          }
          sequence.queue.shift();
          if (sequence.queue.length) apptShowDetailsBulkStep();
          else apptFinishDetailsBulk();
        });
      };
    }, function (message) {
      if (apptSeq !== sequence) return;
      sequence.failed++;
      apptToast('Appointment unavailable', message || 'Could not load appointment details.');
      sequence.queue.shift();
      if (sequence.queue.length) apptShowDetailsBulkStep();
      else apptFinishDetailsBulk();
    });
  }

  function apptFinishDetailsBulk() {
    if (!apptSeq) return;
    var sequence = apptSeq;
    apptSeq = null;
    apptDetailsBulkRestore();
    closeModal('appointmentDetailsModal');
    var isComplete = sequence.type === 'details-complete';
    var isNoShow = sequence.type === 'details-no-show';
    var actionName = isComplete ? 'completed' : (isNoShow ? 'marked no-show' : 'archived');
    apptFlashReload(isComplete ? 'Appointments Completed' : (isNoShow ? 'Appointments Marked No-show' : 'Appointments Archived'),
      sequence.applied.length + ' of ' + sequence.total + ' selected appointment(s) ' + actionName + '.' +
      (sequence.skipped ? ' ' + sequence.skipped + ' ineligible appointment(s) skipped.' : '') +
      (sequence.failed ? ' ' + sequence.failed + ' failed.' : ''));
  }

  /* Mark Completed — non-terminal statuses (mirrors row menu rule) */
  APP.bulkComplete = function () {
    var items = apptEnsureSel();
    if (!items) return;
    var eligible = items.filter(function (r) {
      var row = document.querySelector('#appointmentsTable tr[data-id="' + r.id + '"]');
      return row && ['scheduled', 'rescheduled', 'in_progress'].indexOf(r.status) !== -1
        && row.getAttribute('data-can-outcome') === '1';
    });
    apptStartDetailsBulk('complete', items, eligible);
  };

  APP.bulkNoShow = function () {
    var items = apptEnsureSel();
    if (!items) return;
    var eligible = items.filter(function (r) {
      var row = document.querySelector('#appointmentsTable tr[data-id="' + r.id + '"]');
      return row && ['scheduled', 'rescheduled', 'in_progress'].indexOf(r.status) !== -1
        && row.getAttribute('data-can-outcome') === '1';
    });
    apptStartDetailsBulk('no-show', items, eligible);
  };

  /* Archive only final appointment outcomes. */
  APP.bulkArchive = function () {
    var items = apptEnsureSel();
    if (!items) return;
    var eligible = items.filter(function (r) {
      return ['completed', 'no_show', 'cancelled', 'rejected'].indexOf(r.status) !== -1;
    });
    apptStartDetailsBulk('archive', items, eligible);
  };

  function apptShowArchiveStep() {
    if (!apptSeq || !apptSeq.queue.length) { apptFinishArchive(); return; }
    var root = document.getElementById('archiveApptModal');
    var descEl = root ? root.querySelector('.sd-dialog-description') : null;
    var btnOk = document.getElementById('archiveApptConfirmBtn');
    var btnCancel = root ? root.querySelector('.sd-btn-default') : null;
    if (!apptArchiveOrig && descEl && btnOk && btnCancel) {
      apptArchiveOrig = { desc: descEl.innerHTML, ok: btnOk.onclick, okHtml: btnOk.innerHTML, cancel: btnCancel.onclick };
    }
    var seq = apptSeq;
    var item = seq.queue[0];
    var idx = seq.total - seq.queue.length + 1;
    if (descEl) {
      descEl.innerHTML = 'This action cannot be undone. Archive appointment for ' + apptEsc(item.pet) + ' from ' + apptEsc(item.resident) + '?' +
        (seq.total > 1 ? ' Progress: ' + idx + ' of ' + seq.total + ' selected appointments.' : '') +
        (seq.skipped && idx === 1 ? ' ' + seq.skipped + ' appointment(s) will be skipped \u2014 only scheduled, rescheduled, or completed appointments can be archived.' : '');
    }
    if (btnOk) {
      btnOk.disabled = false;
      if (apptArchiveOrig) btnOk.innerHTML = apptArchiveOrig.okHtml;
      btnOk.onclick = function () {
        var s = apptSeq;
        if (!s || !s.queue.length) return;
        var it = s.queue.shift();
        btnOk.disabled = true;
        if (btnCancel) btnCancel.disabled = true;
        btnOk.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
        var fd = new FormData();
        fd.append('action', 'archive_appointment');
        fd.append('appointment_id', it.id);
        fetch(window.dcvsStaffUrl('client-database-handler.php'), { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) { if (data && data.success) s.applied.push(it); else s.failed++; })
          .catch(function () { s.failed++; })
          .then(function () {
            btnOk.disabled = false;
            if (btnCancel) btnCancel.disabled = false;
            if (apptArchiveOrig) btnOk.innerHTML = apptArchiveOrig.okHtml;
            if (apptSeq !== s) return;
            if (s.queue.length) apptShowArchiveStep();
            else { closeModal('archiveApptModal'); apptFinishArchive(); }
          });
      };
    }
    if (btnCancel) btnCancel.onclick = function () { closeModal('archiveApptModal'); apptAbort(); };
    openModal('archiveApptModal');
  }

  function apptRestoreArchive() {
    if (!apptArchiveOrig) return;
    var root = document.getElementById('archiveApptModal');
    var descEl = root ? root.querySelector('.sd-dialog-description') : null;
    var btnOk = document.getElementById('archiveApptConfirmBtn');
    var btnCancel = root ? root.querySelector('.sd-btn-default') : null;
    if (descEl) descEl.innerHTML = apptArchiveOrig.desc;
    if (btnOk) { btnOk.onclick = apptArchiveOrig.ok; btnOk.innerHTML = apptArchiveOrig.okHtml; btnOk.disabled = false; }
    if (btnCancel) { btnCancel.onclick = apptArchiveOrig.cancel; btnCancel.disabled = false; }
    apptArchiveOrig = null;
  }

  function apptFinishArchive() {
    if (!apptSeq) return;
    var seq = apptSeq;
    apptSeq = null;
    apptRestoreArchive();
    if (window.apptRemoveRows) window.apptRemoveRows(seq.applied.map(function(item) { return item.id; }));
    apptFlashReload('Appointments Archived',
      seq.applied.length + ' of ' + seq.total + ' selected appointment(s) archived.' +
      (seq.skipped ? ' ' + seq.skipped + ' skipped (not scheduled, rescheduled, or completed).' : '') +
      (seq.failed ? ' ' + seq.failed + ' failed.' : ''));
  }

  /* Reschedule active appointment requests, one date-time modal at a time */
  APP.bulkReschedule = function () {
    var items = apptEnsureSel();
    if (!items) return;
    var eligible = items.filter(function (r) {
      return ['pending', 'approved', 'scheduled', 'rescheduled'].indexOf(r.status) !== -1;
    });
    if (!eligible.length) {
      apptToast('Cannot Reschedule', 'Only pending, approved, scheduled, or rescheduled appointments can be rescheduled. Other selected appointments are ignored.');
      return;
    }
    apptSeq = { type: 'reschedule', queue: eligible.slice(), total: eligible.length, skipped: items.length - eligible.length, applied: [], failed: 0 };
    apptDetailsBulkSave();
    if (!apptReschedOrig) {
      apptReschedOrig = { submit: window.submitStaffReschedule, cancel: null, close: null, desc: null };
      var root = document.getElementById('rescheduleAppointmentModal');
      var ftr = root ? root.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
      var closeBtn = root ? root.querySelector('.sd-dialog-close') : null;
      var descEl = root ? root.querySelector('.sd-dialog-description') : null;
      apptReschedOrig.cancel = ftr ? ftr.onclick : null;
      apptReschedOrig.close = closeBtn ? closeBtn.onclick : null;
      apptReschedOrig.desc = descEl ? descEl.innerHTML : null;
      if (ftr) ftr.onclick = function () { closeModal('rescheduleAppointmentModal'); apptDetailsBulkRestore(); apptAbort(); };
      if (closeBtn) closeBtn.onclick = function () { closeModal('rescheduleAppointmentModal'); apptDetailsBulkRestore(); apptAbort(); };
      window.submitStaffReschedule = apptReschedSubmit;
    }
    apptShowReschedStep();
  };

  function apptShowReschedStep() {
    if (!apptSeq || !apptSeq.queue.length) { apptFinishResched(); return; }
    var seq = apptSeq;
    var item = seq.queue[0];
    var idx = seq.total - seq.queue.length + 1;
    APP.view(item.id, function (a) {
        if (apptSeq !== seq) return;
        document.getElementById('rescheduleApptId').value = a.appointment_id || '';
        document.getElementById('rescheduleApptRef').textContent = (a.reference_id || ('#APT-' + a.appointment_id)) + (seq.total > 1 ? ' \u00b7 ' + idx + ' of ' + seq.total : '');
        document.getElementById('rescheduleApptResident').textContent = a.full_name || '-';
        document.getElementById('rescheduleApptPet').textContent = a.pet_name || '-';
        document.getElementById('rescheduleApptDate').value = a.scheduled_date || a.preferred_date || '';
        document.getElementById('rescheduleApptTime').value = (a.scheduled_time || a.preferred_time || '').slice(0, 5);
        var detailMessage = apptBulkActionDescription(a, seq, 'rescheduling it', idx);
        var descEl = document.querySelector('#rescheduleAppointmentModal .sd-dialog-description');
        if (descEl) descEl.textContent = detailMessage;
        var msgEl = document.getElementById('rescheduleApptMessage');
        if (msgEl) msgEl.style.display = 'none';
        apptBulkDetailsPrompt(detailMessage, 'Continue to Reschedule', function () {
          closeModal('appointmentDetailsModal');
          apptDetailsBulkRestore();
          apptAbort();
        }, function () {
          closeModal('appointmentDetailsModal');
          openModal('rescheduleAppointmentModal');
        });
      }, function () {
        if (apptSeq !== seq) return;
        seq.failed++;
        seq.queue.shift();
        apptShowReschedStep();
      });
  }

  function apptReschedSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    if (!apptSeq || !apptSeq.queue.length) {
      if (apptReschedOrig && apptReschedOrig.submit) return apptReschedOrig.submit(e);
      return;
    }
    var seq = apptSeq;
    var item = seq.queue[0];
    var id = document.getElementById('rescheduleApptId').value;
    var date = document.getElementById('rescheduleApptDate').value;
    var time = document.getElementById('rescheduleApptTime').value;
    if (!id || !date || !time) return;
    var btn = document.getElementById('rescheduleApptSubmit');
    var msg = document.getElementById('rescheduleApptMessage');
    btn.disabled = true;
    btn.textContent = 'Rescheduling...';
    fetch(window.dcvsStaffUrl('update-appointment-status.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=reschedule&ids=' + encodeURIComponent(id) + '&scheduled_date=' + encodeURIComponent(date) + '&scheduled_time=' + encodeURIComponent(time)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Reschedule';
        if (apptSeq !== seq) return;
        if (data.success) {
          seq.queue.shift();
          seq.applied.push(item);
          var newSchedule = new Date(date + 'T' + time).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'numeric', minute:'2-digit' });
          if (window.apptUpdateRow) window.apptUpdateRow(id, 'rescheduled', newSchedule);
          closeModal('rescheduleAppointmentModal');
          if (msg) msg.style.display = 'none';
          setTimeout(function () {
            if (apptSeq !== seq) return;
            if (seq.queue.length) apptShowReschedStep();
            else apptFinishResched();
          }, 0);
        } else if (msg) {
          msg.textContent = data.message || 'Unable to reschedule this appointment.';
          msg.style.display = 'block';
          msg.style.background = '#fef2f2';
          msg.style.color = '#991b1b';
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Reschedule';
        if (msg) {
          msg.textContent = 'Network error. Please try again.';
          msg.style.display = 'block';
          msg.style.background = '#fef2f2';
          msg.style.color = '#991b1b';
        }
      });
  }

  function apptRestoreResched() {
    if (!apptReschedOrig) return;
    var root = document.getElementById('rescheduleAppointmentModal');
    var ftr = root ? root.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
    var closeBtn = root ? root.querySelector('.sd-dialog-close') : null;
    var descEl = root ? root.querySelector('.sd-dialog-description') : null;
    if (descEl && apptReschedOrig.desc !== null) descEl.innerHTML = apptReschedOrig.desc;
    if (ftr && apptReschedOrig.cancel) ftr.onclick = apptReschedOrig.cancel;
    if (closeBtn && apptReschedOrig.close) closeBtn.onclick = apptReschedOrig.close;
    if (apptReschedOrig.submit) window.submitStaffReschedule = apptReschedOrig.submit;
    apptReschedOrig = null;
  }

  function apptFinishResched() {
    if (!apptSeq) return;
    var seq = apptSeq;
    apptSeq = null;
    apptRestoreResched();
    apptDetailsBulkRestore();
    closeModal('appointmentDetailsModal');
    apptFlashReload('Appointments Rescheduled',
      seq.applied.length + ' of ' + seq.total + ' appointment(s) rescheduled.' +
      (seq.skipped ? ' ' + seq.skipped + ' skipped (not pending, scheduled, or rescheduled).' : '') +
      (seq.failed ? ' ' + seq.failed + ' failed.' : ''));
  }
  // Treat external closes (backdrop click / Escape) as sequence cancellation
  document.addEventListener('click', function (e) {
    if (!apptSeq || !e.target) return;
    if (apptSeq.type.indexOf('details-') === 0) {
      var closeDetails = e.target.id === 'appointmentDetailsModal' || (e.target.closest && e.target.closest('#appointmentDetailsModal .sd-dialog-close'));
      if (apptSeq.processing && closeDetails) { e.preventDefault(); e.stopPropagation(); return; }
      if (closeDetails) apptAbort();
      return;
    }
    if (apptSeq.type === 'archive' && e.target.id === 'archiveApptModal') apptAbort();
    else if (apptSeq.type === 'reschedule' && e.target.id === 'appointmentDetailsModal') { closeModal('appointmentDetailsModal'); apptDetailsBulkRestore(); apptAbort(); }
    else if (apptSeq.type === 'reschedule' && e.target.id === 'rescheduleAppointmentModal') { apptDetailsBulkRestore(); apptAbort(); }
    else if (apptSeq.type === 'confirm' && e.target.id === 'appointmentActionConfirm') apptAbort();
  }, true);
  document.addEventListener('keydown', function (e) {
    if (!apptSeq || e.key !== 'Escape') return;
    if (apptSeq.type.indexOf('details-') === 0) {
      if (apptSeq.processing) { e.preventDefault(); e.stopImmediatePropagation(); return; }
      closeModal('appointmentDetailsModal');
      apptAbort();
      return;
    }
    if (apptSeq.type === 'archive') { closeModal('archiveApptModal'); apptAbort(); }
    else if (apptSeq.type === 'reschedule' && document.getElementById('appointmentDetailsModal').classList.contains('active')) {
      closeModal('appointmentDetailsModal'); apptDetailsBulkRestore(); apptAbort();
    }
    else if (apptSeq.type === 'reschedule') { closeModal('rescheduleAppointmentModal'); apptDetailsBulkRestore(); apptAbort(); }
    else { closeModal('appointmentActionConfirm'); apptAbort(); }
  }, true);
  /* ── Appt bulk END ── */

  document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'apptSelectAll') {
      var checked = e.target.checked;
      e.target.indeterminate = false;
      document.querySelectorAll('#appointmentsTable tbody .appt-row-check').forEach(function(chk) {
        chk.checked = checked;
        var id = chk.getAttribute('data-id');
        if (checked) { apptSelected[id] = true; } else { delete apptSelected[id]; }
        var row = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
        if (row) row.classList.toggle('selected', checked);
      });
      var n = Object.keys(apptSelected).length;
      var bar = document.getElementById('apptBulkActions');
      var cnt = document.getElementById('apptSelectedCount');
      if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
      if (cnt) cnt.textContent = n;
      updateApptBulkActionVisibility();
    }
  });

  function formatApptDate(value) {
    if (!value) return '-';
    var parts = String(value).substring(0, 10).split('-');
    if (parts.length !== 3) return '-';
    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return isNaN(date.getTime()) ? '-' : date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
  }
  function formatApptTime(value) {
    if (!value) return '-';
    var parts = String(value).split(':');
    if (parts.length < 2) return '-';
    var hour = Number(parts[0]);
    var minute = Number(parts[1]);
    if (isNaN(hour) || isNaN(minute)) return '-';
    var suffix = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12 || 12;
    return hour + ':' + String(minute).padStart(2, '0') + ' ' + suffix;
  }
  function formatApptHistoryDate(value) {
    if (!value) return 'Date unavailable';
    var dateText = String(value).trim();
    var date = new Date(dateText.indexOf('T') === -1 ? dateText.replace(' ', 'T') : dateText);
    return isNaN(date.getTime())
      ? 'Date unavailable'
      : date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function renderAppointmentHistory(history, hasError) {
    var target = document.getElementById('apptDetailHistoryContent');
    if (!target) return;
    if (hasError) {
      target.innerHTML = '<p class="appt-history-empty">Appointment history could not be loaded. Please close and reopen the appointment to try again.</p>';
      return;
    }
    if (!Array.isArray(history) || history.length === 0) {
      target.innerHTML = '<p class="appt-history-empty">No appointment history has been recorded for this resident.</p>';
      return;
    }
    target.innerHTML = '<div class="appt-history-timeline">' + history.map(function (item) {
      return '<article class="appt-history-entry"><div class="appt-history-entry-head">' +
        '<strong class="appt-history-entry-title">' + apptEsc(item.title || 'Appointment update') + '</strong>' +
        '<time class="appt-history-entry-date">' + apptEsc(formatApptHistoryDate(item.created_at)) + '</time></div>' +
        '<span class="appt-history-entry-source">' + apptEsc(item.source || 'Appointment history') + '</span>' +
        (item.message ? '<p class="appt-history-entry-message">' + apptEsc(item.message) + '</p>' : '') +
        '</article>';
    }).join('') + '</div>';
  }
  window.switchAppointmentDetailTab = function (tab) {
    var showHistory = tab === 'history';
    var detailsPanel = document.getElementById('apptDetailDetailsPanel');
    var historyPanel = document.getElementById('apptDetailHistoryPanel');
    var detailsTab = document.getElementById('apptDetailsTab');
    var historyTab = document.getElementById('apptHistoryTab');
    if (detailsPanel) detailsPanel.style.display = showHistory ? 'none' : 'flex';
    if (historyPanel) historyPanel.style.display = showHistory ? 'flex' : 'none';
    if (detailsTab) {
      detailsTab.classList.toggle('active', !showHistory);
      detailsTab.setAttribute('aria-selected', String(!showHistory));
    }
    if (historyTab) {
      historyTab.classList.toggle('active', showHistory);
      historyTab.setAttribute('aria-selected', String(showHistory));
    }
  };

  APP.statusChange = function(id, newStatus) {
    var action = newStatus === 'completed' ? 'complete' : (newStatus === 'no_show' ? 'no_show' : (newStatus === 'cancelled' ? 'cancel' : ''));
    if (!action) { alert('Unsupported action.'); return; }
    var currentRow = document.querySelector('#appointmentsTable tr[data-id="' + id + '"]');
    var currentStatus = window._currentApptId === Number(id) ? window._currentApptStatus : '';
    if (currentRow) currentStatus = currentRow.getAttribute('data-status') || currentStatus;
    var correctingNoShow = newStatus === 'completed' && currentStatus === 'no_show';
    var scheduledDate = window._currentApptDate || '';
    var scheduledTime = window._currentApptTime || '';
    var scheduledSlot = scheduledDate
      ? formatApptDate(scheduledDate) + (scheduledTime ? ' at ' + formatApptTime(scheduledTime) : '')
      : 'the scheduled date and time';
    var actionLabel = newStatus === 'completed'
      ? (correctingNoShow
        ? 'This appointment was automatically marked No Show after its scheduled time. Confirm that the service was completed and correct its status to Completed.'
        : 'Confirm that DCVS staff completed this appointment.')
      : (newStatus === 'no_show'
        ? 'Mark the appointment for ' + scheduledSlot + ' as No-show? This releases the appointment date and time so it becomes available for another resident.'
        : 'Update this appointment?');
    var title = newStatus === 'completed' ? (correctingNoShow ? 'Correct to Completed' : 'Mark as Completed') : (newStatus === 'no_show' ? 'Mark as No-show' : 'Update Appointment');
    confirmAppointmentAction(title, actionLabel, function() { fetch(window.dcvsStaffUrl('update-appointment-status.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(action) + '&ids=' + encodeURIComponent(id)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        if (window.apptUpdateRow) window.apptUpdateRow(id, newStatus);
        closeModal('appointmentDetailsModal');
        apptToast('Appointment updated', d.message || 'Appointment status updated.');
      } else if (d.status === 'no_show') {
        if (window.apptUpdateRow) window.apptUpdateRow(id, 'no_show');
        closeModal('appointmentDetailsModal');
        apptToast('Appointment marked No Show', d.message || 'The scheduled time has passed.');
      } else { alert(d.message || 'Failed to update status.'); }
    })
    .catch(function() { alert('Network error.'); }); },
    newStatus === 'no_show' ? 'Confirm No-show' : 'Continue');
  };

  var staffRescheduleAppointmentId = 0;
  window.toggleStaffRescheduleForm = function(form) {
    var suggestForm = document.getElementById('staffRescheduleSuggestForm');
    var declineForm = document.getElementById('staffRescheduleDeclineForm');
    suggestForm.hidden = form !== 'suggest';
    suggestForm.style.display = form === 'suggest' ? 'grid' : 'none';
    declineForm.hidden = form !== 'decline';
    declineForm.style.display = form === 'decline' ? 'block' : 'none';
    if (form === 'suggest') checkStaffRescheduleAvailability(true);
  };

  function checkStaffRescheduleAvailability(suggestion) {
    var date = suggestion
      ? document.getElementById('staffRescheduleDate').value
      : document.getElementById('staffRescheduleSlots').getAttribute('data-requested-date');
    var time = suggestion
      ? document.getElementById('staffRescheduleTime').value
      : document.getElementById('staffRescheduleSlots').getAttribute('data-requested-time');
    var urgency = suggestion
      ? document.getElementById('staffRescheduleUrgency').value
      : document.getElementById('staffRescheduleSlots').getAttribute('data-requested-urgency');
    var result = document.getElementById(suggestion ? 'staffRescheduleSuggestAvailability' : 'staffRescheduleAvailability');
    var actionButton = document.getElementById(suggestion ? 'staffRescheduleSuggest' : 'staffRescheduleApprove');
    actionButton.disabled = true;
    if (!date || !time || !urgency || !staffRescheduleAppointmentId) {
      result.textContent = suggestion ? 'Choose a date and time to check availability.' : 'Could not check the requested slot.';
      result.style.color = '#991b1b';
      return;
    }
    if (suggestion
        && date === document.getElementById('staffRescheduleSlots').getAttribute('data-current-date')
        && time === document.getElementById('staffRescheduleSlots').getAttribute('data-current-time')) {
      result.textContent = 'Choose a different date and time from the resident’s current appointment.';
      result.style.color = '#991b1b';
      return;
    }
    var params = new URLSearchParams({
      appointment_id: String(staffRescheduleAppointmentId),
      date: date,
      time: time,
      urgency: urgency
    });
    fetch(window.dcvsStaffUrl('api/staff/reschedule-availability.php') + '?' + params.toString())
      .then(function(response) {
        return response.json().then(function(data) {
          if (!response.ok) throw new Error(data.message || 'Could not check appointment availability.');
          return data;
        });
      })
      .then(function(data) {
        if (suggestion) {
          if (!data.program_day && document.getElementById('staffRescheduleUrgency').value === 'urgent') {
            document.getElementById('staffRescheduleUrgency').value = 'routine';
            checkStaffRescheduleAvailability(true);
            return;
          }
          result.textContent = data.available ? 'Suggested slot is available.' : data.message;
        } else {
          var remaining = Math.max(0, Number(data.slot_limit || 0) - Number(data.slot_count || 0));
          result.textContent = data.available
            ? 'Requested slot: ' + remaining + ' ' + (urgency === 'urgent' ? 'urgent' : 'routine') + ' slot' + (remaining === 1 ? '' : 's') + ' left.'
            : data.message;
        }
        result.style.color = data.available ? '#166534' : '#991b1b';
        actionButton.disabled = !data.available;
      })
      .catch(function(error) {
        result.textContent = error.message || 'Could not check appointment availability.';
        result.style.color = '#991b1b';
        actionButton.disabled = true;
      });
  }

  function populateStaffRescheduleRequest(appointment) {
    var panel = document.getElementById('staffRescheduleReview');
    var requestId = Number(appointment.request_id || 0);
    panel.hidden = !requestId;
    if (!requestId) {
      staffRescheduleAppointmentId = 0;
      return;
    }
    staffRescheduleAppointmentId = Number(appointment.appointment_id);
    var status = appointment.reschedule_request_status || 'pending';
    var currentDate = appointment.scheduled_date || appointment.preferred_date;
    var currentTime = appointment.scheduled_time || appointment.preferred_time;
    var requestedDate = appointment.requested_date;
    var requestedTime = appointment.requested_time;
    var requestedUrgency = appointment.requested_urgency;
    var slots = document.getElementById('staffRescheduleSlots');
    slots.setAttribute('data-requested-date', appointment.requested_date || '');
    slots.setAttribute('data-requested-time', appointment.requested_time || '');
    slots.setAttribute('data-requested-urgency', appointment.requested_urgency || 'routine');
    slots.setAttribute('data-current-date', currentDate || '');
    slots.setAttribute('data-current-time', String(currentTime || '').substring(0, 5));
    var residentName = appointment.full_name || 'The resident';
    var petName = appointment.pet_name || 'their pet';
    var petPossessive = petName + (/s$/i.test(petName) ? '’' : '’s');
    slots.textContent = residentName + ' requested to move ' + petPossessive + ' appointment from ' +
      formatApptDate(currentDate) + ', at ' + formatApptTime(currentTime) + ' to ' +
      formatApptDate(requestedDate) + ', at ' + formatApptTime(requestedTime) + '.';

    var residentDetail = document.getElementById('staffRescheduleResident');
    residentDetail.replaceChildren();
    function addRescheduleMeta(label, value) {
      var line = document.createElement('p');
      var labelElement = document.createElement('strong');
      labelElement.textContent = label + ': ';
      line.appendChild(labelElement);
      line.appendChild(document.createTextNode(value));
      residentDetail.appendChild(line);
    }
    addRescheduleMeta('Priority', requestedUrgency === 'urgent' ? 'Urgent' : 'Routine');
    var requestedFor = requestedDate
      ? formatApptDate(requestedDate) + ', at ' + formatApptTime(requestedTime)
      : '-';
    addRescheduleMeta('Requested for', requestedFor);
    if (appointment.resident_reason) addRescheduleMeta('Reason', '“' + appointment.resident_reason + '”');
    document.getElementById('staffRescheduleUnconfirmed').hidden = status !== 'pending';
    document.getElementById('staffRescheduleCutoffWarning').hidden = !appointment.reschedule_within_24h;
    document.getElementById('staffRescheduleWaiting').hidden = status !== 'offered';
    document.getElementById('staffRescheduleControls').hidden = status !== 'pending';
    document.getElementById('staffRescheduleControls').style.display = status === 'pending' ? 'flex' : 'none';
    document.getElementById('staffRescheduleAvailability').textContent = 'Checking requested slot…';
    document.getElementById('staffRescheduleAvailability').style.color = '#52525b';
    document.getElementById('staffRescheduleApprove').disabled = true;
    document.getElementById('staffRescheduleDate').value = appointment.requested_date || '';
    document.getElementById('staffRescheduleDate').min = new Date().toISOString().slice(0, 10);
    document.getElementById('staffRescheduleTime').value = '';
    document.getElementById('staffRescheduleUrgency').value = appointment.requested_urgency || 'routine';
    document.getElementById('staffRescheduleMessage').value = '';
    document.getElementById('staffRescheduleReason').value = '';
    window.toggleStaffRescheduleForm('');
    if (status === 'pending') {
      checkStaffRescheduleAvailability(false);
    } else {
      document.getElementById('staffRescheduleAvailability').textContent = 'The alternate time is not reserved unless the resident accepts.';
    }
  }

  ['staffRescheduleDate', 'staffRescheduleTime', 'staffRescheduleUrgency'].forEach(function(id) {
    document.getElementById(id).addEventListener('change', function() {
      checkStaffRescheduleAvailability(true);
    });
  });

  window.submitStaffRescheduleDecision = function(action, confirmed) {
    var requestId = Number(window._currentRescheduleRequestId || 0);
    var body = new URLSearchParams({
      request_id: String(requestId),
      action: action,
      csrf_token: window.DCVS_RESPONSE_CSRF || ''
    });
    if (action === 'suggest') {
      body.set('date', document.getElementById('staffRescheduleDate').value);
      body.set('time', document.getElementById('staffRescheduleTime').value);
      body.set('urgency', document.getElementById('staffRescheduleUrgency').value);
      body.set('message', document.getElementById('staffRescheduleMessage').value.trim());
    } else if (action === 'decline') {
      var reason = document.getElementById('staffRescheduleReason').value.trim();
      if (!reason) {
        alert('A reason is required so the resident knows why the request could not be approved.');
        document.getElementById('staffRescheduleReason').focus();
        return;
      }
      if (!confirm('Decline this reschedule request? The resident will see your reason.')) return;
      body.set('reason', reason);
    } else if (action === 'approve' && !confirmed) {
      var requestedSlots = document.getElementById('staffRescheduleSlots');
      var requestedDate = requestedSlots.getAttribute('data-requested-date');
      var requestedTime = requestedSlots.getAttribute('data-requested-time');
      confirmAppointmentAction(
        'Approve reschedule request?',
        'This will move the appointment to ' + formatApptDate(requestedDate) + ' at ' + formatApptTime(requestedTime) + '. Availability will be checked again before the change is saved.',
        function() { window.submitStaffRescheduleDecision('approve', true); },
        'Approve request'
      );
      return;
    }
    fetch(window.dcvsStaffUrl('api/staff/review-reschedule-request.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function(response) {
      return response.json().then(function(data) {
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not save the reschedule decision.');
        return data;
      });
    }).then(function(data) {
      apptToast('Reschedule request updated', data.message || 'The resident has been notified.');
      window.location.reload();
    }).catch(function(error) {
      alert(error.message || 'Could not save the reschedule decision.');
      checkStaffRescheduleAvailability(action === 'suggest');
    });
  };

  APP.view = function(id, onReady, onError) {
    fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success) {
          if (typeof onError === 'function') onError((d && d.message) || 'Appointment not found.');
          else alert('Appointment not found.');
          return;
        }
        var a = d.data || d.appointment;
        window._currentRescheduleRequestId = Number(a.request_id || 0);
        populateStaffRescheduleRequest(a);
        function set(el, v) { if (el) el.textContent = v || '-'; }
        function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
        var hasPendingReschedule = a.reschedule_request_status === 'pending';
        var statusText = (a.status || '-').replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        if (hasPendingReschedule && a.status === 'scheduled') statusText = 'Rescheduled';
        if (a.status === 'cancelled') statusText = a.cancelled_by === 'resident' ? 'Cancelled by Resident' : (a.cancelled_by === 'staff' ? 'Cancelled by Staff' : 'Cancelled');
        set(document.getElementById('detailClient'), a.full_name);
        set(document.getElementById('detailResidentId'), a.user_reference_id || '-');
        window._currentApptRef = a.reference_id || ('#APT-' + (a.appointment_id || ''));
        window._currentApptId = a.appointment_id || 0;
        window._currentApptStatus = a.status || '';
        window._currentApptDate = a.scheduled_date || a.preferred_date || '';
        window._currentApptTime = a.scheduled_time || a.preferred_time || '';
        set(document.getElementById('detailType'), cap(a.appointment_type) + ((a.urgency_level || 'routine') === 'urgent' ? ' · Urgent' : ' · Routine'));
        var hasPreviousSchedule = !!(a.original_schedule_date || a.original_schedule_time);
        var displayedPreferredDate = hasPreviousSchedule ? (a.scheduled_date || a.preferred_date) : a.preferred_date;
        var displayedPreferredTime = hasPreviousSchedule ? (a.scheduled_time || a.preferred_time) : a.preferred_time;
        set(document.getElementById('detailPreferredDate'), formatApptDate(displayedPreferredDate));
        set(document.getElementById('detailPreferredTime'), formatApptTime(displayedPreferredTime));
        set(document.getElementById('detailFinalDate'), formatApptDate(a.scheduled_date));
        set(document.getElementById('detailFinalTime'), formatApptTime(a.scheduled_time));
        var statusEl = document.getElementById('detailStatus');
        if (statusEl) {
          var stColors = {
            pending: { bg: '#fef3c7', fg: '#92400e', border: '#fde68a' },
            scheduled: { bg: '#0E8131', fg: '#ffffff', border: '#0E8131' },
            rescheduled: { bg: '#ede9fe', fg: '#7c3aed', border: '#DDD6FE' },
            completed: { bg: '#dbeafe', fg: '#1e40af', border: '#bfdbfe' },
            cancelled: { bg: '#fee2e2', fg: '#dc2626', border: '#FECACA' },
            no_show: { bg: '#fee2e2', fg: '#991b1b', border: '#fecaca' },
            rejected: { bg: '#fee2e2', fg: '#dc2626', border: '#FECACA' },
            in_progress: { bg: '#dbeafe', fg: '#1e40af', border: '#BFDBFE' },
            waitlisted: { bg: '#fef3c7', fg: '#92400e', border: '#fde68a' }
          };
          var st = hasPendingReschedule && a.status === 'scheduled'
            ? stColors.rescheduled
            : (stColors[a.status] || { bg: '#f1f5f9', fg: '#475569', border: '#E2E8F0' });
          statusEl.textContent = statusText;
          statusEl.style.color = st.fg;
          statusEl.style.background = st.bg;
          statusEl.style.border = '1px solid ' + st.border;
        }
        var progressEl = document.getElementById('apptStatusProgress');
        if (progressEl) {
          var terminalLabels = {
            completed: 'Completed',
            no_show: 'No Show',
            cancelled: 'Cancelled',
            rejected: 'Rejected',
            waitlisted: 'Waitlisted'
          };
          var terminal = Object.prototype.hasOwnProperty.call(terminalLabels, a.status);
          var hasScheduledSlot = !!(a.scheduled_date || a.scheduled_time);
          var progressStages = [
            {label: 'Request Submitted', done: true},
            {label: 'Pending Review', done: a.status !== 'pending' && a.status !== 'cancelled', active: a.status === 'pending'},
            {
              label: 'Scheduled',
              done: a.status === 'completed' || a.status === 'no_show' || (a.status === 'cancelled' && hasScheduledSlot),
              active: a.status === 'scheduled' || a.status === 'rescheduled' || a.status === 'in_progress'
            },
            {
              label: terminal ? terminalLabels[a.status] : 'Completed',
              done: terminal,
              active: terminal
            }
          ];
          progressEl.replaceChildren();
          progressStages.forEach(function (stage) {
            var step = document.createElement('div');
            step.className = 'appt-progress-step';
            step.setAttribute('role', 'listitem');
            if (stage.active) step.setAttribute('aria-current', 'step');
            var dot = document.createElement('div');
            dot.className = 'appt-progress-dot' + (stage.done ? ' done' : (stage.active ? ' active' : ''));
            if (stage.done) {
              dot.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            }
            var label = document.createElement('span');
            label.className = 'appt-progress-label' + (stage.done || stage.active ? ' active-label' : '');
            label.textContent = stage.label;
            step.appendChild(dot);
            step.appendChild(label);
            progressEl.appendChild(step);
          });
          progressEl.setAttribute('aria-label', 'Appointment progress: ' + statusText);
        }
        set(document.getElementById('detailEmail'), a.email);
        set(document.getElementById('detailPhone'), a.phone);
        set(document.getElementById('detailBrgy'), a.barangay_name);
        set(document.getElementById('detailAddress'), a.address || (a.barangay_name ? a.barangay_name + ', Dasmariñas, Cavite' : ''));
        set(document.getElementById('detailPet'), a.pet_name);
        set(document.getElementById('detailPetType'), cap(a.pet_type));
        set(document.getElementById('detailPetBreed'), a.breed);
        set(document.getElementById('detailPetGender'), cap(a.gender));
        set(document.getElementById('detailPetAge'), a.age === null || a.age === '' ? '-' : a.age + (Number(a.age) === 1 ? ' year' : ' years'));
        set(document.getElementById('detailPetVax'), a.vaccination_status);
        set(document.getElementById('detailNotes'), a.notes);
        var avatarEl = document.getElementById('detailAvatar');
        var pic = a.avatar_url || a.profile_picture;
        if (avatarEl) {
          avatarEl.style.width = '85px';
          avatarEl.style.height = '85px';
          avatarEl.style.borderRadius = '8px';
          avatarEl.style.flexShrink = '0';
          avatarEl.style.overflow = 'hidden';
          avatarEl.style.background = '#e4e4e7';
          avatarEl.style.display = 'flex';
          avatarEl.style.alignItems = 'center';
          avatarEl.style.justifyContent = 'center';
          avatarEl.style.fontWeight = '600';
          avatarEl.style.fontSize = '28px';
          avatarEl.style.color = '#64748b';
          if (pic) {
            avatarEl.innerHTML = '<img src="' + window.dcvsStaffMediaUrl(pic) + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
          } else {
            var initials = (a.full_name || '?').split(' ').map(function(w){return w[0];}).join('').substring(0,2).toUpperCase();
            avatarEl.innerHTML = initials;
            avatarEl.style.width = '42px';
            avatarEl.style.height = '42px';
            avatarEl.style.borderRadius = '50%';
            avatarEl.style.flexShrink = '0';
            avatarEl.style.overflow = 'hidden';
            avatarEl.style.background = '#e4e4e7';
            avatarEl.style.display = 'flex';
            avatarEl.style.alignItems = 'center';
            avatarEl.style.justifyContent = 'center';
            avatarEl.style.fontWeight = '600';
            avatarEl.style.fontSize = '15px';
            avatarEl.style.color = '#64748b';
          }
        }
        openModal('appointmentDetailsModal');
        window.switchAppointmentDetailTab('details');
        renderAppointmentHistory(a.appointment_history, a.appointment_history_error);

        var footer = document.getElementById('apptDetailFooter');
        var outcomeGuidance = document.getElementById('apptOutcomeGuidance');
        if (outcomeGuidance) {
          var isScheduled = a.status === 'scheduled' || a.status === 'rescheduled' || a.status === 'in_progress';
          var autoNoShow = a.status === 'no_show';
          var outcomeDate = a.scheduled_date || a.preferred_date || '';
          var outcomeTime = a.scheduled_time || a.preferred_time || '';
          var outcomeScheduleLabel = outcomeDate
            ? formatApptDate(outcomeDate) + (outcomeTime ? ' at ' + formatApptTime(outcomeTime) : '')
            : 'the scheduled date and time';
          outcomeGuidance.style.display = isScheduled || autoNoShow ? 'block' : 'none';
          outcomeGuidance.textContent = isScheduled
            ? (a.can_mark_outcome
              ? 'DCVS staff records the outcome after the final scheduled date and time: mark Completed if the appointment took place, or No-show if the resident did not attend.'
              : 'These actions become available after ' + outcomeScheduleLabel + '.')
            : (autoNoShow ? 'This appointment was automatically marked No Show after its scheduled time. If the service was completed, you can correct the status below.' : '');
        }
        if (footer) {
          var footerHtml = '';
          if (a.status === 'pending') {
            footerHtml += '<button class="sd-btn sd-btn-default" onclick="schedulePendingAppointment(' + a.appointment_id + ')">Schedule</button>';
            footerHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="rejectAppointmentFromDrawer(' + a.appointment_id + ')">Reject</button>';
          }
          if (a.status === 'scheduled' || a.status === 'rescheduled' || a.status === 'in_progress') {
            var completedButtonClass = a.can_mark_outcome ? 'sd-btn sd-btn-default' : 'sd-btn sd-btn-default appt-outcome-unavailable';
            var noShowButtonClass = a.can_mark_outcome ? 'sd-btn sd-btn-outline' : 'sd-btn sd-btn-outline appt-outcome-unavailable';
            var outcomeTitle = a.can_mark_outcome ? '' : ' title="Available after ' + outcomeScheduleLabel.replace(/"/g, '&quot;') + '"';
            footerHtml += '<button type="button" class="' + completedButtonClass + '" data-appointment-outcome="completed"' + outcomeTitle + '>Mark as Completed</button>';
            footerHtml += '<button type="button" class="' + noShowButtonClass + '" data-appointment-outcome="no_show"' + outcomeTitle + '>Mark No-show</button>';
          }
          if (a.status === 'no_show') {
            footerHtml += '<button type="button" class="sd-btn sd-btn-default" data-appointment-outcome="completed">Correct to Completed</button>';
          }
          if (['completed', 'no_show', 'cancelled', 'rejected'].indexOf(a.status) !== -1) {
            footerHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="archiveAppt(' + a.appointment_id + ', \'' + (a.pet_name || '').replace(/'/g, "\\'") + '\', \'' + (a.full_name || '').replace(/'/g, "\\'") + '\')">Archive</button>';
          }
          footer.innerHTML = footerHtml;
          footer.querySelectorAll('[data-appointment-outcome]').forEach(function (button) {
            button.addEventListener('click', function (event) {
              event.preventDefault();
              event.stopPropagation();
              if (!a.can_mark_outcome && (a.status === 'scheduled' || a.status === 'rescheduled' || a.status === 'in_progress')) {
                var actionDate = a.scheduled_date || a.preferred_date || '';
                var actionTime = a.scheduled_time || a.preferred_time || '';
                var availableAfter = actionDate
                  ? formatApptDate(actionDate) + (actionTime ? ' at ' + formatApptTime(actionTime) : '')
                  : 'the scheduled date and time';
                apptToast('Action not available yet', 'Mark as Completed and Mark No-show will be available after ' + availableAfter + '.');
                return;
              }
              APP.statusChange(a.appointment_id, button.getAttribute('data-appointment-outcome'));
            });
          });
        }
        if (typeof onReady === 'function') onReady(a);
      })
      .catch(function(error) {
        if (typeof onError === 'function') onError(error && error.message ? error.message : 'Error loading appointment.');
        else alert('Error loading appointment.');
      });
  };

  window.editAppointmentFromDrawer = function() {
    var id = window._currentApptId;
    if (!id) return;
    fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d || !d.success || !d.data) {
          alert((d && d.message) || 'Appointment not found.');
          return;
        }
        var a = d.data;
        document.getElementById('staffEditAppointmentId').value = a.appointment_id || '';
        document.getElementById('staffEditAppointmentUserId').value = a.user_id || '';
        document.getElementById('staffEditAppointmentPetId').value = a.pet_id || '';
        document.getElementById('staffEditAppointmentWaitlistId').value = '';
        document.getElementById('staffEditAppointmentType').value = a.appointment_type || 'checkup';
        document.getElementById('staffEditAppointmentUrgency').value = a.urgency_level || 'routine';
        document.getElementById('staffEditAppointmentPet').value = a.pet_name || '-';
        document.getElementById('staffEditAppointmentDate').value = a.preferred_date || '';
        document.getElementById('staffEditAppointmentTime').value = (a.preferred_time || '').slice(0, 5);
        document.getElementById('staffEditAppointmentStatus').value = a.status || 'pending';
        document.getElementById('staffEditAppointmentNotes').value = a.notes && a.notes !== '-' ? a.notes : '';
        closeModal('appointmentDetailsModal');
        openModal('staffEditAppointmentModal');
      })
      .catch(function() { alert('Error loading appointment.'); });
  };

  APP.reschedSingle = function(id) {
    fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success) { alert('Appointment not found.'); return; }
        var a = d.data || d.appointment;
        document.getElementById('rescheduleApptId').value = a.appointment_id || '';
        document.getElementById('rescheduleApptRef').textContent = a.reference_id || ('#APT-' + a.appointment_id);
        document.getElementById('rescheduleApptResident').textContent = a.full_name || '-';
        document.getElementById('rescheduleApptPet').textContent = a.pet_name || '-';
        document.getElementById('rescheduleApptDate').value = a.scheduled_date || a.preferred_date || '';
        document.getElementById('rescheduleApptTime').value = (a.scheduled_time || a.preferred_time || '').slice(0, 5);
        openModal('rescheduleAppointmentModal');
      })
      .catch(function() { alert('Error loading appointment.'); });
  };
})(window.APP);

// Copy appointment ID to clipboard (used in Appointment Details modal)
function copyAppointmentId() {
  var ref = window._currentApptRef || '';
  var btn = document.getElementById('copyApptIdBtn');
  if (!ref) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(ref).then(function() { showCopiedApptId(btn); }).catch(function() { fallbackCopyApptId(ref, btn); });
  } else {
    fallbackCopyApptId(ref, btn);
  }
}
function fallbackCopyApptId(text, btn) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
  showCopiedApptId(btn);
}
function showCopiedApptId(btn) {
  if (!btn) return;
  btn.classList.add('copied');
  btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied';
  clearTimeout(btn._copyTimer);
  btn._copyTimer = setTimeout(function() { btn.classList.remove('copied'); btn.innerHTML = 'Copy ID'; }, 1800);
}

function submitStaffReschedule(e) {
  e.preventDefault();
  var id = document.getElementById('rescheduleApptId').value;
  var date = document.getElementById('rescheduleApptDate').value;
  var time = document.getElementById('rescheduleApptTime').value;
  var btn = document.getElementById('rescheduleApptSubmit');
  var msg = document.getElementById('rescheduleApptMessage');
  if (!id || !date || !time) return;
  btn.disabled = true;
  btn.textContent = 'Rescheduling...';
  msg.style.display = 'none';
  fetch(window.dcvsStaffUrl('update-appointment-status.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=reschedule&ids=' + encodeURIComponent(id) + '&scheduled_date=' + encodeURIComponent(date) + '&scheduled_time=' + encodeURIComponent(time)
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.success) {
      closeModal('rescheduleAppointmentModal');
      var scheduleLabel = new Date(date + 'T' + time).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'numeric', minute:'2-digit' });
      if (window.apptUpdateRow) window.apptUpdateRow(id, 'rescheduled', scheduleLabel);
      if (window.portalToast) portalToast('Appointment rescheduled', data.message || 'Appointment has been rescheduled.');
      return;
    }
    msg.textContent = data.message || 'Unable to reschedule this appointment.';
    msg.style.display = 'block';
    msg.style.background = '#fef2f2';
    msg.style.color = '#991b1b';
  })
  .catch(function() {
    msg.textContent = 'Network error. Please try again.';
    msg.style.display = 'block';
    msg.style.background = '#fef2f2';
    msg.style.color = '#991b1b';
  })
  .finally(function() {
    btn.disabled = false;
    btn.textContent = 'Reschedule';
  });
}

function archiveAppt(id, pet, resident) {
  function fill(p, r) {
    var petEl = document.getElementById('archiveApptPet');
    var resEl = document.getElementById('archiveApptResident');
    if (petEl) petEl.textContent = p || 'this appointment';
    if (resEl) resEl.textContent = r || 'this client';
  }
  document.getElementById('archiveApptConfirmBtn').dataset.id = id;
  if (pet && resident) {
    fill(pet, resident);
    openModal('archiveApptModal');
    return;
  }
  fetch(window.dcvsStaffUrl('staff-get-appointment-request.php') + '?id=' + encodeURIComponent(id))
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success && d.data) fill(d.data.pet_name, d.data.full_name);
      else fill(null, null);
      openModal('archiveApptModal');
    })
    .catch(function () { fill(null, null); openModal('archiveApptModal'); });
}
function executeArchiveAppt() {
  var btn = document.getElementById('archiveApptConfirmBtn');
  var id = btn.dataset.id;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
  btn.disabled = true;
  var fd = new FormData();
  fd.append('action', 'archive_appointment');
  fd.append('appointment_id', id);
  fetch(window.dcvsStaffUrl('client-database-handler.php'), { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        closeModal('archiveApptModal');
        closeModal('appointmentDetailsModal');
        if (window.apptRemoveRows) window.apptRemoveRows([id]);
        if (window.portalToast) portalToast('Appointment archived', data.message || 'Appointment has been archived.');
      } else {
        alert(data.message || 'Archive failed');
        btn.innerHTML = 'Continue';
        btn.disabled = false;
      }
    })
    .catch(function() {
      alert('Network error');
      btn.innerHTML = 'Continue';
      btn.disabled = false;
    });
}

</script>

<?php include __DIR__ . '/../../includes/portal-toast.php'; ?>
<script>
window.applyStaffRealtimeReport = function(source) {
  var id = Number(source.report_id);
  if (!id) return;
  var typeLabels = { stray:'Stray Animal', rabies:'Rabies', injured:'Injured Animal', dead:'Dead Animal', rescue:'Rescue' };
  var rawStatus = String(source.status || 'pending').toLowerCase();
  var allStatus = rawStatus === 'resolved' ? 'resolved' : (rawStatus === 'confirmed' || rawStatus === 'scheduled' ? 'active' : 'pending');
  var typeLabel = typeLabels[String(source.report_type || '').toLowerCase()] || String(source.report_type || 'Report');
  var created = source.created_at || '';
  var confirmed = source.case_confirmed_at || source.confirmed_at || '';
  var scheduled = source.scheduled_at || '';
  var resolved = source.resolved_at || '';
  var pendingItems = window.PENDING_ITEMS || [];
  var activeItems = window.ACTIVE_ITEMS || [];
  var barangayItems = window.BARANGAY_RESPONSE_ITEMS || [];
  var resolvedItems = window.RESOLVED_ITEMS || [];
  var existing = (window.RPT_ITEMS || []).find(function(item) { return Number(item.id) === id; }) ||
    pendingItems.find(function(item) { return Number(item.id) === id; }) ||
    activeItems.find(function(item) { return Number(item.id) === id; }) ||
    barangayItems.find(function(item) { return Number(item.id) === id; }) ||
    resolvedItems.find(function(item) { return Number(item.id) === id; });
  var responseState = Object.prototype.hasOwnProperty.call(source, 'response_state')
    ? String(source.response_state || '')
    : String(existing && existing.responseState || '');
  var statusLabel;
  if (['barangay_response_approved', 'barangay_responding', 'animal_secured', 'returned_to_barangay'].indexOf(responseState) !== -1) {
    statusLabel = window.dcvsStaffBarangayCaseStatusLabel({ responseState: responseState });
  } else if (responseState === 'escalated_to_dcvs') statusLabel = 'Escalated to DCVS';
  else if (rawStatus === 'rejected') statusLabel = 'Closed';
  else if (rawStatus === 'validated') statusLabel = 'Pending Barangay Review';
  else if (rawStatus === 'pending') statusLabel = 'Pending Barangay Review';
  else if (allStatus === 'active') statusLabel = 'In Progress';
  else statusLabel = allStatus.charAt(0).toUpperCase() + allStatus.slice(1);
  var base = Object.assign({}, existing || {}, {
    id:id,
    ref:source.reference_id || ('#RPT-' + String(id).padStart(4, '0')),
    type:String(source.report_type || '').toLowerCase(),
    typeLabel:typeLabel,
    typeShort:typeLabel.split(' ')[0],
    desc:source.description || (existing && existing.desc) || '',
    location:source.location_address || (existing && existing.location) || '-',
    brgy:source.barangay_name || (existing && existing.brgy) || '',
    reporter:source.reporter_name || (existing && existing.reporter) || 'Resident',
    phone:source.reporter_phone || (existing && existing.phone) || '',
    email:source.reporter_email || (existing && existing.email) || '',
    avatar:source.avatar_url || (existing && existing.avatar) || '',
    date:created ? new Date(String(created).replace(' ', 'T')).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' }) : (existing && existing.date) || '',
    createdAt:created || (existing && existing.createdAt) || '',
    ts:created ? new Date(String(created).replace(' ', 'T')).getTime() : (existing && existing.ts) || Date.now(),
    urgency:String(source.urgency_level || (existing && existing.urgency) || 'medium').replace(/^./, function(c) { return c.toUpperCase(); }),
    status:allStatus,
    statusRaw:rawStatus,
    statusLabel:statusLabel,
    responseState:responseState,
    caseId:source.case_id ? Number(source.case_id) : (existing && existing.caseId) || null,
    caseStatus:Object.prototype.hasOwnProperty.call(source, 'case_status')
      ? String(source.case_status || '')
      : String(existing && existing.caseStatus || ''),
    confirmed:confirmed ? new Date(String(confirmed).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : (existing && existing.confirmed) || '',
    validated:source.validated_at ? new Date(String(source.validated_at).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : (existing && existing.validated) || '',
    scheduled:scheduled ? new Date(String(scheduled).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : (existing && existing.scheduled) || '',
    resolved:resolved ? new Date(String(resolved).replace(' ', 'T')).toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : (existing && existing.resolved) || ''
  });
  if (window.rptAddItem) window.rptAddItem(base);
  if (window.dcvsStaffCaseBelongsInBarangayQueue(base)) {
    if (window.penRemoveItem) window.penRemoveItem(id);
    if (window.actRemoveItem) window.actRemoveItem(id);
    if (window.resRemoveItem) window.resRemoveItem(id);
    base.status = 'active';
    base.statusLabel = window.dcvsStaffBarangayCaseStatusLabel(base);
    if (window.brgAddItem) window.brgAddItem(base);
  } else if (rawStatus === 'pending' || rawStatus === 'validated') {
    if (window.actRemoveItem) window.actRemoveItem(id);
    if (window.brgRemoveItem) window.brgRemoveItem(id);
    if (window.resRemoveItem) window.resRemoveItem(id);
    if (window.penAddItem) window.penAddItem(Object.assign({}, base, {
      status:'pending',
      statusLabel:rawStatus === 'validated' ? 'Pending DCVS Review' : 'Pending Barangay Review',
      caseId:null,
      caseStatus:'',
      responseState:responseState
    }));
  } else if (allStatus === 'active') {
    if (window.penRemoveItem) window.penRemoveItem(id);
    if (window.brgRemoveItem) window.brgRemoveItem(id);
    if (window.resRemoveItem) window.resRemoveItem(id);
    base.status = 'active';
    base.statusLabel = window.dcvsStaffActiveCaseStatusLabel(base);
    if (window.dcvsStaffCaseBelongsInActiveCases(base)) {
      if (window.actAddItem) window.actAddItem(base);
    } else if (window.actRemoveItem) {
      window.actRemoveItem(id);
    }
  } else if (allStatus === 'resolved') {
    if (window.penRemoveItem) window.penRemoveItem(id);
    if (window.actRemoveItem) window.actRemoveItem(id);
    if (window.brgRemoveItem) window.brgRemoveItem(id);
    if (window.resAddItem) window.resAddItem(Object.assign({}, base, { status:'resolved', statusLabel:'Resolved', caseStatus:'resolved' }));
  } else if (rawStatus === 'rejected') {
    if (window.penRemoveItem) window.penRemoveItem(id);
    if (window.actRemoveItem) window.actRemoveItem(id);
    if (window.brgRemoveItem) window.brgRemoveItem(id);
    if (window.resRemoveItem) window.resRemoveItem(id);
  }

  var monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime();
  var yearStart = new Date(new Date().getFullYear(), 0, 1).getTime();
  var highCount = function(items) { return items.filter(function(item) { return /high|critical/i.test(item.urgency || ''); }).length; };
  var pendingWeek = pendingItems.filter(function(item) { return Number(item.vts || 0) >= Date.now() - 7 * 86400000; }).length;
  var activeScheduled = activeItems.filter(function(item) { return !!item.scheduled; }).length;
  var activeOverdue = activeItems.filter(function(item) { return item.scheduled && new Date(item.scheduled).getTime() < Date.now(); }).length;
  var kpiValues = {
    'reports-pending':[pendingItems.length, highCount(pendingItems), pendingWeek, pendingItems.length],
    'reports-active':[activeItems.length, highCount(activeItems), activeScheduled, activeOverdue],
    'reports-resolved':[resolvedItems.length,
      resolvedItems.filter(function(item) { return Number(item.ts || 0) >= monthStart; }).length,
      resolvedItems.filter(function(item) { return Number(item.ts || 0) >= yearStart; }).length,
      highCount(resolvedItems)]
  };
  Object.keys(kpiValues).forEach(function(sectionId) {
    document.querySelectorAll('#' + sectionId + ' .kpis .kpi-value').forEach(function(node, index) {
      if (typeof kpiValues[sectionId][index] !== 'undefined') node.textContent = kpiValues[sectionId][index];
    });
  });
};
document.addEventListener('dcvs:realtime', function(event) {
  var data = event.detail || {};
  if (Array.isArray(data.appointments) && window.apptUpsertRealtimeRow) {
    data.appointments.forEach(function(appointment) { window.apptUpsertRealtimeRow(appointment); });
  }
  if (Array.isArray(data.reports)) {
    data.reports.forEach(function(report) { window.applyStaffRealtimeReport(report); });
  }
});
</script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/includes/realtime.js', ENT_QUOTES, 'UTF-8'); ?>?v=20261010-2"></script>
<?php if ($staffWaitlistBooking): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var booking = <?php echo json_encode($staffWaitlistBooking, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  document.getElementById('staffEditAppointmentId').value = '';
  document.getElementById('staffEditAppointmentUserId').value = booking.user_id;
  document.getElementById('staffEditAppointmentPetId').value = booking.pet_id;
  document.getElementById('staffEditAppointmentWaitlistId').value = booking.waitlist_id;
  document.getElementById('staffEditAppointmentType').value = booking.appointment_type;
  document.getElementById('staffEditAppointmentUrgency').value = booking.urgency_level;
  document.getElementById('staffEditAppointmentPet').value = booking.pet_name + ' — ' + booking.full_name;
  document.getElementById('staffEditAppointmentDate').value = booking.preferred_date;
  document.getElementById('staffEditAppointmentTime').value = booking.preferred_time ? booking.preferred_time.slice(0, 5) : '';
  document.getElementById('staffEditAppointmentStatus').value = 'scheduled';
  var title = document.querySelector('#staffEditAppointmentModal .sd-dialog-title');
  var description = document.querySelector('#staffEditAppointmentModal .sd-dialog-description');
  if (title) title.textContent = 'Book for resident';
  if (description) description.textContent = 'Confirm a time for the resident. Appointment capacity will be checked before saving.';
  if (typeof openModal === 'function') openModal('staffEditAppointmentModal');
  else document.getElementById('staffEditAppointmentModal').style.display = 'flex';
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/reports/photo-evidence-dialog.php'; ?>
</body>
</html>
