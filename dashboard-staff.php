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

// Fresh login â�?��?T force Analytics tab on first dashboard load
$freshDashboard = !empty($_SESSION['fresh_dashboard']);
if ($freshDashboard) unset($_SESSION['fresh_dashboard']);

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login-staff.php');
    exit;
}

// Check if user has staff role
if ($_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
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
        header('Location: login-staff.php');
        exit;
    }
}
$roleCheck->close();

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
    WHERE u.role = 'resident'
    GROUP BY u.user_id
    ORDER BY u.created_at ASC
");
if ($petsQuery) {
    while($row = $petsQuery->fetch_assoc()) {
        $clientPets[] = $row;
    }
}
$ownersTotal = count($clientPets);

// �"?�"? Client & Pet Database (CPD): redesigned section data �"?�"?
function cpdInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $init = '';
    foreach ($parts as $p) { if ($p !== '') $init .= strtoupper(mb_substr($p, 0, 1)); }
    return $init !== '' ? $init : '?';
}
function cpdTypeLabel($type) {
    $t = strtolower((string)$type);
    if ($t === 'dog') return 'Dog';
    if ($t === 'cat') return 'Cat';
    return 'Other';
}
function cpdGenderLabel($gender) {
    $g = strtolower((string)$gender);
    if ($g === 'male') return 'Male';
    if ($g === 'female') return 'Female';
    return 'Unknown';
}
function cpdAgeLabel($age) {
    if ($age === null || $age === '') return 'Unknown';
    $a = (int)$age;
    if ($a <= 0) return 'Unknown';
    return $a === 1 ? '1 year' : $a . ' years';
}
function cpdVaxOk($vax) {
    $v = strtolower((string)$vax);
    return $v !== '' && strpos($v, 'vaccinated') !== false && strpos($v, 'not') === false;
}

$cpdClients = [];
$cpdCq = $conn->query("
    SELECT u.user_id, u.reference_id, u.full_name, u.email, u.phone, u.address,
           u.barangay_id, u.avatar_url, u.status, u.gender, u.birthdate, u.created_at,
           b.barangay_name,
           (SELECT COUNT(*) FROM pets p WHERE p.owner_id = u.user_id AND p.archived_at IS NULL) AS total_pets
    FROM users u
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE u.role = 'resident' AND u.archived_at IS NULL
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
            'date'     => date('M d, Y', strtotime($r['created_at'])),
            'initials' => cpdInitials($r['full_name']),
            'avatar'   => (string)$r['avatar_url'],
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
    WHERE p.archived_at IS NULL AND u.archived_at IS NULL AND u.role = 'resident'
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
            'photo'     => (string)$r['photo_url'],
            'ownerId'   => (int)$r['owner_id'],
            'ownerName' => $r['owner_name'],
            'ownerRef'  => $r['owner_ref'] ?: '',
            'brgy'      => $r['barangay_name'] ?? '',
'ts'       => strtotime($r['created_at']),
            'date'     => date('M d, Y', strtotime($r['created_at'])),
            'reported' => $r['created_at'] ? date('M d, Y h:i A', strtotime($r['created_at'])) : '',
            'initials'  => cpdInitials($r['pet_name']),
        ];
    }
}

$cpdBarangays = [];
$cpdBq = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($cpdBq) { while ($r = $cpdBq->fetch_assoc()) $cpdBarangays[] = $r; }

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
$apptWhere = 'a.archived_at IS NULL';
if ($apptStatusFilter !== 'all' && $apptStatusFilter !== '') {
    $safeStatus = $conn->real_escape_string($apptStatusFilter);
    $apptWhere .= " AND a.status = '$safeStatus'";
}
if ($apptSearchFilter !== '') {
    $safeSearch = $conn->real_escape_string($apptSearchFilter);
    $apptWhere .= " AND (a.appointment_id LIKE '%$safeSearch%' OR u.full_name LIKE '%$safeSearch%' OR a.appointment_type LIKE '%$safeSearch%' OR p.pet_name LIKE '%$safeSearch%')";
}

$pendingQuery = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.created_at, a.reference_id, u.user_id, u.reference_id AS user_ref, u.full_name, u.avatar_url, p.pet_name, p.pet_type, p.breed, p.gender, p.age, p.vaccination_status
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE $apptWhere
    ORDER BY a.created_at ASC, a.appointment_id ASC
");
if ($pendingQuery) {
    while($row = $pendingQuery->fetch_assoc()) {
        $pendingAppts[] = $row;
    }
}
$pendingTotal = count($pendingAppts);

// Appointment status pill colors (all-clients style: light bg + accent text)
$statusColors = [
    'pending'     => ['bg' => '#fef3c7', 'color' => '#92400e'],
    'scheduled'   => ['bg' => '#dcebe0', 'color' => '#045e1f'],
    'approved'    => ['bg' => '#dcebe0', 'color' => '#045e1f'],
    'rescheduled' => ['bg' => '#ede9fe', 'color' => '#6d28d9'],
    'completed'   => ['bg' => '#e2e8f0', 'color' => '#475569'],
    'no_show'     => ['bg' => '#fef3c7', 'color' => '#92400e'],
    'cancelled'   => ['bg' => '#fee2e2', 'color' => '#991b1b'],
    'rejected'    => ['bg' => '#fee2e2', 'color' => '#991b1b'],
];

// KPI counts for the appointments overview
$apptCounts = ['all' => $pendingTotal, 'pending' => 0, 'scheduled' => 0, 'completed' => 0];
foreach ($pendingAppts as $ar) {
    $as = $ar['status'] ?? '';
    if ($as === 'pending') $apptCounts['pending']++;
    elseif ($as === 'scheduled' || $as === 'rescheduled') $apptCounts['scheduled']++;
    elseif ($as === 'completed') $apptCounts['completed']++;
}

// Fetch ALL appointments for calendar initialization
$calendarAppointments = [];
$apptsQuery = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.notes, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL
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
        } elseif ($row['status'] == 'rejected') {
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
require_once __DIR__ . '/includes/availability.php';
$weeklyTemplate = dcvsWeeklyTemplate($conn);
$blockedDays = [];
$calStart = date('Y-m-01');
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
    SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL
      AND a.preferred_date >= CURDATE()
      AND a.status IN ('pending','scheduled','rescheduled')
    ORDER BY a.preferred_date ASC, a.preferred_time ASC
    LIMIT 8
");
if ($upQ) {
    while ($r = $upQ->fetch_assoc()) $upcomingAppts[] = $r;
}

// Fetch completed appointments for the calendar reminder panel
$completedAppts = [];
$cpQ = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.reference_id, u.full_name, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL AND a.status = 'completed'
    ORDER BY a.preferred_date DESC, a.preferred_time DESC
    LIMIT 6
");
if ($cpQ) {
    while ($r = $cpQ->fetch_assoc()) $completedAppts[] = $r;
}

// Fetch validated reports for Barangay Validation
$validatedReports = [];
$vrq = $conn->query("SELECT r.*, u.full_name AS reporter_name, u.phone AS reporter_phone, u.address AS reporter_address, b.barangay_name FROM reports r JOIN users u ON r.reporter_id = u.user_id LEFT JOIN barangays b ON r.barangay_id = b.barangay_id WHERE r.status = 'validated' AND r.archived_at IS NULL ORDER BY r.validated_at DESC");
if ($vrq) {
    while ($row = $vrq->fetch_assoc()) $validatedReports[] = $row;
}

// Fetch all barangay reports (for full list)
$barangayReports = [];
$brq = $conn->query("SELECT r.*, u.full_name AS reporter_name, u.phone AS reporter_phone, b.barangay_name FROM reports r JOIN users u ON r.reporter_id = u.user_id LEFT JOIN barangays b ON r.barangay_id = b.barangay_id WHERE r.archived_at IS NULL ORDER BY r.created_at ASC");
if ($brq) {
    while ($row = $brq->fetch_assoc()) $barangayReports[] = $row;
}

// Fetch barangay officers list
$barangayOfficers = [];
$boq = $conn->query("SELECT u.user_id, u.full_name, u.email, u.phone, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.role = 'barangay' AND u.status = 'active' ORDER BY b.barangay_name ASC");
if ($boq) {
    while ($row = $boq->fetch_assoc()) $barangayOfficers[] = $row;
}

// Fetch active confirmed cases for tracking
$activeCases = [];
$acq = $conn->query("SELECT cc.*, r.reference_id AS report_ref, r.report_type, r.location_address, b.barangay_name, u.full_name AS reporter_name FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id LEFT JOIN barangays b ON cc.barangay_id = b.barangay_id LEFT JOIN users u ON r.reporter_id = u.user_id WHERE cc.status = 'active' ORDER BY cc.confirmed_at ASC");
if ($acq) {
    while ($row = $acq->fetch_assoc()) $activeCases[] = $row;
}

// Fetch resolved confirmed cases
$resolvedCases = [];
$rcq = $conn->query("SELECT cc.*, r.reference_id AS report_ref, r.report_type, r.location_address, b.barangay_name, u.full_name AS reporter_name FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id LEFT JOIN barangays b ON cc.barangay_id = b.barangay_id LEFT JOIN users u ON r.reporter_id = u.user_id WHERE cc.status = 'resolved' ORDER BY cc.resolved_at DESC");
if ($rcq) {
    while ($row = $rcq->fetch_assoc()) $resolvedCases[] = $row;
}

// �"?�"? REPORTS DATABASE (unified command-center data) �"?�"?
function rptTypeLabel($t) {
    $map = ['stray' => 'Stray Animal', 'rabies' => 'Rabies', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal', 'rescue' => 'Rescue'];
    return $map[strtolower((string)$t)] ?? ucfirst($t);
}
function rptReportStatus($s, $scheduledAt) {
    $s = strtolower((string)$s);
    if ($s === 'resolved') return 'resolved';
    if ($s === 'confirmed' || $s === 'scheduled') return 'active';
    return 'pending';
}
function rptStatusLabel($s) {
    $s = strtolower((string)$s);
    return $s === 'resolved' ? 'Resolved' : ($s === 'active' ? 'Active' : 'Pending');
}
function rptUrgencyLabel($u) { return ucfirst((string)($u ?: 'medium')); }

$reportItems = [];
$rptQ = $conn->query("
    SELECT r.report_id, r.reference_id, r.report_type, r.description, r.location_address,
           r.status, r.urgency_level, r.photo_url, r.created_at, r.updated_at, r.scheduled_at,
           r.validated_at,
           r.barangay_notes, r.dcvs_notes, r.rejection_reason,
           u.user_id AS reporter_id, u.reference_id AS reporter_ref, u.full_name AS reporter_name,
           u.phone AS reporter_phone, u.avatar_url,
           b.barangay_name,
           cc.case_id, cc.status AS case_status, cc.confirmed_at, cc.resolved_at,
           cc.notes AS case_notes,
           cs.full_name AS confirmed_staff, vs.full_name AS validated_staff
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
    LEFT JOIN confirmed_cases cc ON r.report_id = cc.report_id
    LEFT JOIN users cs ON r.confirmed_by = cs.user_id
    LEFT JOIN users vs ON r.validated_by = vs.user_id
    WHERE r.archived_at IS NULL
    ORDER BY r.created_at DESC
");
if ($rptQ) {
    while ($r = $rptQ->fetch_assoc()) {
        $reportItems[] = [
            'id'       => (int)$r['report_id'],
            'ref'      => $r['reference_id'] ?: '#RPT-' . fallbackRefId('#RPT-', (int)$r['report_id']),
            'type'     => strtolower((string)$r['report_type']),
            'typeLabel'=> rptTypeLabel($r['report_type']),
            'desc'     => (string)$r['description'],
            'location' => (string)$r['location_address'],
            'brgy'     => $r['barangay_name'] ?? '',
            'status'   => rptReportStatus($r['status'], $r['scheduled_at']),
            'statusRaw'=> strtolower((string)$r['status']),
            'statusLabel' => rptStatusLabel(rptReportStatus($r['status'], $r['scheduled_at'])),
'scheduled' => $r['scheduled_at'] ? date('M d, Y h:i A', strtotime($r['scheduled_at'])) : '',
           'validated'=> $r['validated_at'] ? date('M d, Y h:i A', strtotime($r['validated_at'])) : '',
           'vts'      => $r['validated_at'] ? strtotime($r['validated_at']) : 0,
           'urgency'  => rptUrgencyLabel($r['urgency_level']),
            'photo'    => (string)$r['photo_url'],
            'reporter' => $r['reporter_name'],
            'reporterId' => (int)$r['reporter_id'],
            'reporterRef' => $r['reporter_ref'] ?: '#RES-' . fallbackRefId('#RES-', (int)$r['reporter_id']),
            'phone'    => (string)$r['reporter_phone'],
            'avatar'   => (string)$r['avatar_url'],
            'assigned' => $r['confirmed_staff'] ?: ($r['validated_staff'] ?: ''),
            'typeShort'=> strtok(rptTypeLabel($r['report_type']), ' '),
            'brgyNotes'=> (string)$r['barangay_notes'],
            'dcvsNotes'=> (string)$r['dcvs_notes'],
            'rejection'=> (string)$r['rejection_reason'],
            'caseId'   => $r['case_id'] !== null ? (int)$r['case_id'] : null,
            'caseStatus'=> (string)$r['case_status'],
            'confirmed'=> $r['confirmed_at'] ? date('M d, Y h:i A', strtotime($r['confirmed_at'])) : '',
            'resolved' => $r['resolved_at'] ? date('M d, Y h:i A', strtotime($r['resolved_at'])) : '',
            'caseNotes'=> (string)$r['case_notes'],
            'ts'       => strtotime($r['created_at']),
            'date'     => date('M d, Y', strtotime($r['created_at'])),
        ];
    }
}
$rptCounts = ['all' => 0, 'active' => 0, 'pending' => 0, 'resolved' => 0];
foreach ($reportItems as $ri) {
    $rptCounts['all']++;
    if (isset($rptCounts[$ri['status']])) $rptCounts[$ri['status']]++;
}

// �"?�"? PENDING CASES (validated reports awaiting DCVS confirmation) �"?�"?
$pendingItems = [];
$pendingCounts = ['all' => 0, 'high' => 0, 'week' => 0];
$pendingWeekStart = time() - 7 * 86400;
foreach ($reportItems as $ri) {
    if ($ri['statusRaw'] !== 'validated') continue;
    $ri['status'] = 'pending';
    $ri['statusLabel'] = 'Pending';
    $ri['caseId'] = null;
    $pendingItems[] = $ri;
    $pendingCounts['all']++;
    if (stripos($ri['urgency'], 'high') !== false || stripos($ri['urgency'], 'critical') !== false) $pendingCounts['high']++;
    if ($ri['vts'] >= $pendingWeekStart) $pendingCounts['week']++;
}

// �"?�"? ACTIVE CASES (confirmed cases being handled) �"?�"?
$activeItems = [];
$activeCounts = ['all' => 0, 'high' => 0, 'sched' => 0, 'overdue' => 0];
foreach ($reportItems as $ri) {
    if ($ri['caseStatus'] !== 'active') continue;
    $ri['status'] = 'active';
    $ri['statusLabel'] = 'Active';
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
foreach ($reportItems as $ri) {
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

$pageTitle = 'Analytics';
$activePage = 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> â�,��?� Staff | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="assets/js/dashboard.js"></script>
  <!-- MapCN bundle -->
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css" />
  <script src="dist/mapcn.umd.cjs"></script>
  
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
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; }
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
    .status-pill.status-pending { background:#fef3c7; color:#92400e; }
    .status-pill.status-validated { background:#dbeafe; color:#1e40af; }
    .status-pill.status-confirmed,
    .status-pill.status-scheduled { background:#dcebe0; color:#045e1f; }
    .status-pill.status-resolved { background:#f0fdf4; color:#045e1f; }
    .status-pill.status-rejected { background:#fee2e2; color:#991b1b; }
    .status-pill.status-completed { background:#f1f5f9; color:#475569; }
    .status-pill.status-cancelled { background:#f1f5f9; color:#64748b; }
    .status-pill.status-rescheduled { background:#ede9fe; color:#6d28d9; }
    .status-pill.status-no_show { background:#fef3c7; color:#92400e; }
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
    .profile-settings-wrapper .ps-profile-name { font-size:15px; font-weight:700; }
    .profile-settings-wrapper .ps-profile-sub { font-size:13px; color:#6b7280; margin-top:2px; }
    .profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .profile-settings-wrapper .ps-card-header h2 { font-size:16px; font-weight:700; margin:0; }
    .profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-size:13px; font-weight:600; color:#1f2328; cursor:pointer; transition:background .15s,border-color .15s; }
    .profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
    .profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
    .profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
    .profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
    .profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
    .profile-settings-wrapper .ps-field label { display:block; font-size:13px; color:#4b5563; margin-bottom:6px; }
    .profile-settings-wrapper .ps-field input,
    .profile-settings-wrapper .ps-field select,
    .profile-settings-wrapper .ps-field textarea { width:100%; padding:10px 12px; font-size:14px; border-radius:8px; border:1px solid #e2e5ea; background:#f9fafb; color:#1f2328; outline:none; transition:background .15s,border-color .15s,box-shadow .15s; box-sizing:border-box; font-family:inherit; }
    .profile-settings-wrapper .ps-field input:disabled,
    .profile-settings-wrapper .ps-field select:disabled,
    .profile-settings-wrapper .ps-field textarea:disabled { color:#374151; cursor:default; }
    .profile-settings-wrapper .ps-field input:not(:disabled),
    .profile-settings-wrapper .ps-field select:not(:disabled),
    .profile-settings-wrapper .ps-field textarea:not(:disabled) { background:#fff; border-color:#d7dae0; }
    .profile-settings-wrapper .ps-field input:not(:disabled):focus,
    .profile-settings-wrapper .ps-field select:not(:disabled):focus,
    .profile-settings-wrapper .ps-field textarea:not(:disabled):focus { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.08); }
    .profile-settings-wrapper .ps-field textarea { height:42px; min-height:42px; max-height:42px; resize:none; overflow:hidden; line-height:1.5; }
    .profile-settings-wrapper .ps-btn { padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; border:1px solid transparent; }
    .profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
    .profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
    .profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
    .profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
    .profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
    .profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
    .profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
    .profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }

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
    .cpd .header-title h1{ font-size:22px; font-weight:700; margin:0 0 2px; letter-spacing:-.01em; color:var(--ink-900); font-family:'Montserrat',sans-serif; }
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
    .cpd .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:18px 20px; background:none; border:none; border-radius:0; box-shadow:none; }
    .cpd .kpi-label{ font-size:12.5px; color:#000; font-weight:600; margin:0 0 8px; }
    .cpd .kpi-value{ font-size:26px; font-weight:700; color:var(--ink-900); letter-spacing:-.02em; line-height:1; margin-bottom:8px; }
    .cpd .kpi-sub{ font-size:12px; color:var(--ink-400); }

    /* Layout */
    .cpd .layout{ display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:0; align-items:stretch; min-height:calc(100vh - 120px); }
    .cpd .panel{ background:var(--white); border:1px solid var(--border); }

    /* Full-page database (no container look) */
    #reports-all, #client-pets, #reports-pending, #reports-active, #reports-resolved{ margin:-1.75rem -2rem; width:calc(100% + 4rem); max-width:none; }
    #reports-all thead th, #reports-pending thead th, #reports-active thead th, #reports-resolved thead th, #client-pets thead th{ background:transparent; border-bottom:1px solid var(--border); }
    .reports-fullpage{ background:var(--white); min-height:calc(100vh - var(--topbar-h, 64px)); }
    .reports-fullpage .cpd-app{ padding:0; }
    .reports-fullpage .layout{ min-height:calc(100vh - var(--topbar-h, 64px)); grid-template-rows:1fr; }
    .reports-fullpage .panel{ background:transparent; border:none; }
    .reports-fullpage .filters{ border-right:1px solid var(--border); }
    .reports-fullpage .db-panel{ border-left:none; border-right:1px solid var(--border); }
    .reports-fullpage .profile-panel{ border-left:none; }

    /* Reports pages: full-width table + right drawer detail panel (appointments style) */
    #reports-all .layout, #reports-pending .layout, #reports-active .layout, #reports-resolved .layout, #client-pets .layout{ grid-template-columns:minmax(0,1fr); }
    #reports-all .db-panel, #reports-pending .db-panel, #reports-active .db-panel, #reports-resolved .db-panel, #client-pets .db-panel{ border-right:none; }
    .cd-drawer.modal{ position:fixed; inset:0; background:rgba(0,0,0,0.5); padding:0; z-index:999; display:flex; align-items:stretch; justify-content:flex-end; opacity:0; visibility:hidden; transition:opacity .25s ease, visibility .25s ease; }
    .cd-drawer.modal.active{ opacity:1; visibility:visible; }
    .cd-drawer .sd-dialog{ height:100%; width:100%; display:flex; justify-content:flex-end; }
    .cd-drawer .sd-dialog-content{ max-width:min(480px,92vw); width:min(480px,92vw); height:100%; max-height:none; border:none; border-radius:0; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); padding:24px; gap:18px; }
    .cd-drawer.active .sd-dialog-content{ transform:translateX(0); }
    .cd-drawer .sd-field-group{ flex:1; min-height:0; overflow-y:auto; }

    /* Pending/Active/Resolved: KPI cards on top, toolbar styled like All Reports */
    #reports-pending .db-toolbar, #reports-active .db-toolbar, #reports-resolved .db-toolbar{ justify-content:flex-start; }
    #reports-pending .search-grow-wrap input, #reports-active .search-grow-wrap input, #reports-resolved .search-grow-wrap input{ height:36px; width:100%; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
    #reports-pending .search-grow-wrap input:focus, #reports-active .search-grow-wrap input:focus, #reports-resolved .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports-pending .search-grow-wrap input::placeholder, #reports-active .search-grow-wrap input::placeholder, #reports-resolved .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    #reports-pending .search-grow-wrap svg, #reports-active .search-grow-wrap svg, #reports-resolved .search-grow-wrap svg{ color:#71717a; }
    #reports-pending .tf-select, #reports-active .tf-select, #reports-resolved .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background-color:#fff; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #reports-pending .tf-select:focus, #reports-active .tf-select:focus, #reports-resolved .tf-select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #reports-pending .kpi-label, #reports-active .kpi-label, #reports-resolved .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    #reports-pending .kpi-value, #reports-active .kpi-value, #reports-resolved .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #reports-pending .kpi-sub, #reports-active .kpi-sub, #reports-resolved .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }

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
    .cpd .db-panel .pagination{ flex-shrink:0; margin-top:auto; }
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
    .cpd .pets-badge{ display:inline-flex; align-items:center; justify-content:center; min-width:24px; padding:2px 8px; border-radius:20px; background:var(--green-50); color:var(--green-700); font-weight:700; font-size:12.5px; }
    .cpd .status-pill{ display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; }
    .cpd .status-active{ background:var(--green-100); color:var(--green-700); }
    .cpd .status-inactive{ background:var(--ink-100); color:var(--ink-500); }
    .cpd .row-menu{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .row-menu:hover{ background:var(--ink-100); color:var(--ink-700); }

    /* â�?�?"â�?�?" shadcn data-table (exact look) â�?�?"â�?�?" */
    #client-pets .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #client-pets table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #client-pets thead{ }
    #client-pets thead tr{ border-bottom:1px solid #e4e4e7; }
    #client-pets thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:transparent; border-bottom:none; }
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

    /* dialog font style applied across the clients page */
    #client-pets{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .kpi-card{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    #client-pets .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #client-pets .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    #client-pets .search-grow-wrap input{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    #client-pets tbody td{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #client-pets .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #client-pets .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    /* dialog font style - right profile panel */
    #client-pets .profile-panel{ font-family:'Inter','Segoe UI',sans-serif; }
    #client-pets .profile-name-row h2{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    #client-pets .profile-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    #client-pets .tab{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; }
    #client-pets .tab.active{ color:#18181b; }
    #client-pets .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-bottom:2px; }
    #client-pets .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .profile-empty{ color:#71717a; }
    #client-pets .profile-empty p{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    #client-pets .profile-loading{ color:#71717a; }
    #client-pets .pet-name-row .pet-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #client-pets .pet-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    #client-pets .pet-meta{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; }

    /* Row menu dropdown */
    .cpd .row-menu-pop{ position:absolute; z-index:60; background:var(--white); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow-md); padding:6px; min-width:190px; display:none; }
    .cpd .row-menu-pop.open{ display:block; }
    .cpd .row-menu-item{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:none; background:none; border-radius:8px; font-family:inherit; font-size:13px; font-weight:500; color:var(--ink-700); cursor:pointer; text-align:left; }
    .cpd .row-menu-item:hover{ background:var(--ink-50); }
    .cpd .row-menu-item.danger{ color:#dc2626; }
    .cpd .row-menu-item.danger:hover{ background:#fef2f2; }
    .cpd .row-menu-item svg{ flex-shrink:0; }

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
.cpd .vax-pill{ font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; }
.cpd .pet-card .vax-yes{ background:transparent; color:#000; }
.cpd .pet-card .vax-no{ background:transparent; color:#000; }
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
    .cpd .rpt-detail-body[data-tab="details"]{ display:flex; flex-direction:column; flex:1; }
    .cpd .profile-header{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
    .cpd .profile-avatar{ width:96px;height:96px; border-radius:14px; background:var(--green-100); color:var(--green-700); font-weight:700; font-size:32px; display:flex;align-items:center;justify-content:center; flex-shrink:0; overflow:hidden; }
    .cpd .profile-avatar img{ width:100%; height:100%; object-fit:cover; }
    .cpd .profile-name-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cpd .profile-name-row h2{ font-size:20px; font-weight:700; margin:0; color:var(--ink-900); font-family:'Montserrat',sans-serif; }
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
    .cpd .info-row-icon{ width:36px;height:36px; border-radius:9px; background:transparent; color:#000; display:flex;align-items:center;justify-content:center; flex-shrink:0; }
    .cpd .info-row-icon svg{ stroke-width:2.4; }
    .cpd .info-row-icon.info-row-avatar{ background:var(--green-100); color:var(--green-700); font-weight:700; font-size:12px; overflow:hidden; border-radius:50%; }
    .cpd .info-row-icon.info-row-avatar img{ width:100%; height:100%; object-fit:cover; border-radius:50%; }
    .cpd .info-row-label{ font-size:12px; color:var(--ink-400); margin-bottom:2px; }
    .cpd .info-row-value{ font-size:14px; font-weight:600; color:var(--ink-900); word-break:break-word; }
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
    .cpd .rpt-full-col{ grid-column:1 / -1; }
    .cpd .rpt-detail-grid + .rpt-full-col, .cpd .rpt-detail-grid ~ .rpt-full-col{ margin-top:6px; }
    .cpd .rpt-detail-ref{ font-size:20px; font-weight:700; color:var(--ink-900); word-break:break-all; font-family:'Montserrat',sans-serif; }
    .cpd .rpt-detail-type{ font-size:14px; color:var(--ink-500); margin-top:4px; }
    .cpd .rpt-section-title{ font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-400); margin:18px 0 10px; }
    .cpd .rpt-desc-box{ background:none; border:none; border-radius:0; padding:0; font-size:13px; color:var(--ink-700); line-height:1.55; }
    .cpd .rpt-notes-box{ background:none; border:none; border-radius:0; padding:0; font-size:13px; color:var(--ink-700); line-height:1.5; }
    .cpd .rpt-photo-box{ display:flex; flex-direction:column; gap:10px; }
    .cpd .rpt-photo-thumb{ width:100%; height:auto; border-radius:10px; border:1px solid var(--border); overflow:hidden; cursor:pointer; background:var(--ink-100); display:block; }
    .cpd .rpt-photo-thumb img{ width:100%; height:auto; object-fit:cover; display:block; }
    .cpd .rpt-photo-none{ font-size:12.5px; color:var(--ink-400); }
    .cpd .rpt-history{ list-style:none; margin:0; padding:0; }
    .cpd .rpt-history li{ position:relative; padding:0 0 14px 20px; font-size:12.5px; color:var(--ink-600); }
    .cpd .rpt-history li:before{ content:''; position:absolute; left:4px; top:5px; width:8px;height:8px; border-radius:50%; background:var(--green-500); }
    .cpd .rpt-history li:last-child{ padding-bottom:0; }
    .cpd .rpt-history li .h-date{ display:block; font-size:11px; color:var(--ink-400); margin-top:2px; }
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
    }
  </style>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
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
<body class="staff-theme" data-realtime-page="staff">

<?php include 'includes/staff-sidebar.php'; ?>

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
<?php include 'includes/aether-dashboard.php'; ?>
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

        <div class="appt-card-header">
            <div class="appt-header-right">
              <div class="appt-search-wrap">
                <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="apptSearchInput" class="appt-search-input" placeholder="Search by ID, Resident Name, or Pet�?�">
              </div>
              <select id="apptStatusSelect" class="appt-status-select">
                <option value="all">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="scheduled">Scheduled</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="rescheduled">Rescheduled</option>
                <option value="no_show">No Show</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-left:auto;">
              <a href="availability.php" class="sd-btn sd-btn-outline"><i class="fas fa-calendar-alt"></i> Manage Schedule</a>
              <button type="button" class="sd-btn sd-btn-default" onclick="openAddAppointmentModal()"><i class="fas fa-clipboard-list"></i> Review Requests</button>
            </div>
          </div>

          <div id="apptBulkActions" class="appt-bulk-actions" style="display:none;">
            <span class="appt-bulk-count"><span id="apptSelectedCount">0</span> selected</span>
            <div class="appt-bulk-buttons">
              <button type="button" class="appt-bulk-btn appt-bulk-complete" onclick="APP.bulkStatusChange('completed')"><i class="fas fa-check"></i> Mark Completed</button>
              <button type="button" class="appt-bulk-btn appt-bulk-cancel" onclick="APP.bulkStatusChange('cancelled')"><i class="fas fa-times"></i> Cancel</button>
              <button type="button" class="appt-bulk-btn appt-bulk-delete" onclick="APP.bulkDelete()"><i class="fas fa-trash"></i> Delete</button>
            </div>
          </div>



          <div class="appt-table-wrap" id="appointmentsTable">
            <?php if(empty($pendingAppts)): ?>
              <div class="appt-empty-state">
                <svg class="appt-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p class="appt-empty-text">No appointments found.</p>
              </div>
            <?php else: ?>
              <div class="appt-no-results" id="apptNoResults">No appointments match this filter.</div>
              <div class="appt-db-table-area dt-container">
                <table class="appt-table">
                  <thead>
                    <tr class="sd-tr">
                      <th class="sd-th"><button type="button" class="th-sort" data-col="appt" onclick="window.APP.sortBy('appt')">Appointment ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="service" onclick="window.APP.sortBy('service')">Service<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                      <th class="sd-th"><button type="button" class="th-sort" data-col="resident" onclick="window.APP.sortBy('resident')">Resident<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
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
                    $statusLabel = ucfirst(str_replace('_',' ', $req['status']));
                    $dt = date('M d, Y', strtotime($req['preferred_date']));
                    $tm = date('g:i A', strtotime($req['preferred_time']));
                    $sub = date('M d, Y', strtotime($req['created_at']));
                    $typeLabel = ucfirst($req['appointment_type']);
                    $petName = htmlspecialchars($req['pet_name'] ?? '-');
                    $residentName = htmlspecialchars($req['full_name']);
                    $initials = '';
                    $nameParts = preg_split('/\s+/', trim($residentName));
                    foreach ($nameParts as $np) { if ($np !== '') $initials .= strtoupper(mb_substr($np, 0, 1)); }
                    if ($initials === '') $initials = '?';
                  ?>
                    <tr class="sd-tr" data-id="<?php echo (int)$req['appointment_id']; ?>" data-status="<?php echo htmlspecialchars($statusKey); ?>" data-date="<?php echo (int)strtotime($req['preferred_date']); ?>" data-submitted="<?php echo (int)strtotime($req['created_at']); ?>" onclick="APP.view(<?php echo (int)$req['appointment_id']; ?>)">
                      <td class="sd-td">
                        <div class="client-cell">
                          <div class="avatar"><?php if (!empty($req['avatar_url'])): ?><img src="<?php echo htmlspecialchars($req['avatar_url']); ?>" alt=""><?php else: echo $initials; endif; ?></div>
                          <div>
                            <div class="client-name"><?php echo htmlspecialchars($aptRef); ?></div>
                          </div>
                        </div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $typeLabel; ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-name"><?php echo $residentName; ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $petName; ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $dt; ?> &middot; <?php echo $tm; ?></div>
                      </td>
                      <td class="sd-td">
                        <div class="contact-main contact-normal"><?php echo $sub; ?></div>
                      </td>
                      <td class="sd-td">
                        <span class="appt-status-pill" style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;"><?php echo $statusLabel; ?></span>
                      </td>
                      <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
                        <div class="appt-more-dropdown">
                          <button type="button" class="sd-row-actions" title="More options" onclick="event.stopPropagation();toggleApptMore(this)">
                            <span class="sr-only">Open menu</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                          </button>
                          <div class="appt-dropdown">
                            <a class="appt-dropdown-item" href="javascript:void(0)" onclick="APP.view(<?php echo (int)$req['appointment_id']; ?>)">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> View
                            </a>
                            <a class="appt-dropdown-item" href="javascript:void(0)" onclick="APP.reschedSingle(<?php echo (int)$req['appointment_id']; ?>)">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Reschedule
                            </a>
                            <a class="appt-dropdown-item" href="javascript:void(0)" onclick="APP.statusChange(<?php echo (int)$req['appointment_id']; ?>,'completed')">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Mark Completed
                            </a>
                            <a class="appt-dropdown-item" href="javascript:void(0)" onclick="APP.statusChange(<?php echo (int)$req['appointment_id']; ?>,'cancelled')">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Cancel
                            </a>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
          <div id="apptPagination" class="appt-pagination">
            <span class="appt-pagination-info">Showing <span id="apptShowingStart">1</span>�?"<span id="apptShowingEnd"><?php echo min(count($pendingAppts), 7); ?></span> of <span id="apptTotalCount"><?php echo count($pendingAppts); ?></span> appointments</span>
            <div class="appt-pagination-controls">
              <button class="appt-page-btn" id="apptPagePrev" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <button class="appt-page-btn" id="apptPageNext" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
</button>
            </div>
          </div>

</section>

  <!-- CALENDAR SCHEDULE -->
  <section id="calendar-schedule" class="content-section">
    <?php include __DIR__ . '/calendar/events-calendar.php'; ?>
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
            <div class="db-toolbar">
              <div class="search-grow-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="mainSearch" placeholder="Search client or pet name, ID, email..." oninput="setSearch(this.value)">
              </div>
              <div class="toolbar-filters">
                  <select class="select-input tf-select" id="typeFilter" onchange="setType(this.value)">
                    <option value="">All Types</option>
                    <option value="dog">Dog</option>
                    <option value="cat">Cat</option>
                  </select>
                  <select class="select-input tf-select" id="quickFilter" onchange="setQuick(this.value)">
                    <option value="">All</option>
                    <option value="recent">Recently Added</option>
                    <option value="active">Active Clients</option>
                    <option value="vax">Vaccinated Pets</option>
                  </select>
                  <select class="select-input tf-select" id="brgyFilter" onchange="setBrgy(this.value)">
                    <option value="">All Barangays</option>
                    <?php foreach ($cpdBarangays as $b): ?>
                      <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="db-toolbar-actions">
                <button type="button" class="sd-btn sd-btn-outline" id="cpdExportBtn" onclick="exportCSV()" title="Export Report">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </button>
                <button type="button" class="sd-btn sd-btn-default" id="cpdAddBtn" onclick="openAddClient()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Add New Client
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

      <!-- Row menu popup -->
      <div class="cpd row-menu-pop" id="rowMenuPop"></div>

      <!-- Client Profile Drawer (right drawer, appointments style) -->
      <div id="clientProfileModal" class="modal cpd cd-drawer">
        <div class="sd-dialog">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('clientProfileModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header">
              <h2 class="sd-dialog-title">Client Profile</h2>
              <p class="sd-dialog-description">Review client information and registered pets.</p>
            </div>
            <div class="sd-field-group">
              <div id="profileContent"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Client Modal (shadcn Dialog) -->
      <style>
        #addClientModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; }
        #addClientModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
        #addClientModal .sd-dialog-content{
          position:relative; width:100%; max-width:440px;
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
        #addClientModal .sd-field-group{ display:flex; flex-direction:column; gap:24px; }
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
      </style>
      <div id="addClientModal" class="modal">
        <form id="addClientForm" class="sd-dialog">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('addClientModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header">
              <h2 class="sd-dialog-title">Add new client</h2>
              <p class="sd-dialog-description">Register a new client record. Click add client when you're done.</p>
            </div>
            <div class="sd-field-group">
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
              <div class="sd-field">
                <label class="sd-label" for="addAddress">Address</label>
                <textarea id="addAddress" name="address" class="sd-input" rows="2" placeholder="Complete address"></textarea>
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

    /* Appointment Records Table - Enhanced UI */
    #appointments.content-section {
      background: #ffffff;
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
    .appt-bulk-actions {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 12px 24px;
      background: #f0fdf4;
      border-bottom: 1px solid #bbf7d0;
    }
    .appt-bulk-count {
      font-size: 0.82rem;
      font-weight: 600;
      color: #166534;
    }
    .appt-bulk-buttons {
      display: flex;
      gap: 8px;
    }
    .appt-bulk-btn {
      padding: 6px 12px;
      font-size: 0.78rem;
      font-weight: 600;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s;
    }
    .appt-bulk-complete {
      background: #fff;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    .appt-bulk-complete:hover {
      background: #dcfce7;
    }
    .appt-bulk-cancel {
      background: #fff;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    .appt-bulk-cancel:hover {
      background: #fef2f2;
    }
    .appt-bulk-delete {
      background: #fff;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    .appt-bulk-delete:hover {
      background: #fef2f2;
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
      display: inline-block;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 11.5px;
      font-weight: 700;
      white-space: nowrap;
    }
    .appt-table .sd-row-actions {
      width: 28px; height: 28px;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 7px; color: #94a3b8; background: none; border: none; cursor: pointer;
      margin: 0 2px;
    }
    .appt-table .sd-row-actions:hover { background: #f1f5f9; color: #334155; }
    .appt-table .sd-td-action { text-align: right; white-space: nowrap; }
    .appt-more-dropdown { position: relative; display: inline-flex; }
    .appt-dropdown {
      position: absolute; right: 0; top: calc(100% + 6px);
      z-index: 60; min-width: 190px; padding: 6px;
      background: #fff; border: 1px solid #e6e9ee; border-radius: 10px;
      box-shadow: 0 4px 14px rgba(15,23,32,.06);
      display: none;
    }
    .appt-dropdown.open { display: block; }
    .appt-dropdown-item {
      display: flex; align-items: center; gap: 10px;
      width: 100%; padding: 9px 10px; border: none; background: none;
      border-radius: 8px; font-family: 'Inter','Segoe UI',sans-serif; font-size: 13px;
      font-weight: 500; color: #334155; cursor: pointer; text-align: left;
    }
    .appt-dropdown-item:hover { background: #f8fafc; }
    .appt-dropdown-item svg { flex-shrink: 0; }
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
    .appt-more-dropdown {
      position: relative;
    }
    .appt-dropdown {
      display: none;
      position: absolute;
      right: 0;
      top: 100%;
      margin-top: 4px;
      background: #fff;
      border: 1px solid #e6e9ee;
      border-radius: 10px;
      box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
      z-index: 20;
      min-width: 160px;
      padding: 4px;
    }
    .appt-dropdown-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      font-size: 0.78rem;
      color: #334155;
      border-radius: 5px;
      text-decoration: none;
      transition: background 0.1s;
    }
    .appt-dropdown-item:hover {
      background: #f1f5f9;
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
    #appointments .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:18px 20px; background:none; border:none; border-radius:0; box-shadow:none; }
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
#appointments .appt-bulk-actions { background:#fafafa; border-bottom:1px solid #e4e4e7; }
    #appointments .appt-bulk-count { color:#18181b; }
    #appointments .appt-bulk-btn { height:32px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:500; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    #appointments .appt-bulk-btn:hover { background:#f4f4f5; }
    #appointments .appt-card-header { justify-content:flex-start; }
    #appointments .appt-header-right { flex-wrap:wrap; }
    #appointments .appt-db-table-area { overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; padding:0; }
    #appointments .appt-table thead th { background:transparent; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; letter-spacing:0; text-transform:none; color:#71717a; height:40px; padding:0 8px; border-bottom:1px solid #e4e4e7; }
    #appointments .appt-table tbody tr { border-bottom:1px solid #e4e4e7; }
    #appointments .appt-table tbody tr:hover { background:#f4f4f5; }
    #appointments .appt-table tbody tr.selected { background:#f4f4f5; }
    #appointments .appt-table tbody td { padding:10px 8px; font-size:14px; color:#18181b; }
    #appointments .appt-table .client-name { font-family:'Inter','Segoe UI',sans-serif; font-weight:600; color:#18181b; font-size:14px; }
    #appointments .appt-table .client-id { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; margin-top:2px; }
    #appointments .appt-table .contact-main { font-family:'Inter','Segoe UI',sans-serif; color:#18181b; font-weight:500; font-size:14px; }
    #appointments .appt-table .contact-main.contact-normal { font-weight:400; }
    #appointments .appt-table .contact-main.contact-name { font-weight:600; }
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
    #appointments .appt-pagination-info { color:#71717a; }
    #appointments .appt-pagination { display:flex; align-items:center; justify-content:flex-end; padding:12px 16px; border-top:1px solid #e4e4e7; }
    #appointments .dt-pagination-right { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    #appointments .dt-rows { display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    #appointments .dt-rows select { height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    #appointments .dt-rows select:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #appointments .dt-page-label { width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    #appointments .dt-page-btns { display:flex; align-items:center; gap:4px; }
    #appointments .dt-page-btn { height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    #appointments .dt-page-btn:hover:not(:disabled) { background:#f4f4f5; }
    #appointments .dt-page-btn:disabled { opacity:.5; cursor:not-allowed; }

    /* Dialog font + button style (shadcn) - Pending / Active / Resolved cases */
    #reports-pending .dt-container, #reports-active .dt-container, #reports-resolved .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #reports-pending table, #reports-active table, #reports-resolved table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    #reports-pending thead th, #reports-active thead th, #reports-resolved thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:transparent; text-transform:none; letter-spacing:0; border-bottom:1px solid #e4e4e7; }
    #reports-pending thead th.sd-th-action, #reports-active thead th.sd-th-action, #reports-resolved thead th.sd-th-action{ text-align:right; }
    #reports-pending tbody tr, #reports-active tbody tr, #reports-resolved tbody tr{ border-bottom:1px solid #e4e4e7; }
    #reports-pending tbody tr:hover, #reports-active tbody tr:hover, #reports-resolved tbody tr:hover{ background:#f4f4f5; }
    #reports-pending tbody td, #reports-active tbody td, #reports-resolved tbody td{ padding:10px 8px; font-size:14px; color:#18181b; }
    #reports-pending .client-name, #reports-active .client-name, #reports-resolved .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    #reports-pending .client-id, #reports-active .client-id, #reports-resolved .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; margin-top:2px; }
    #reports-pending .contact-main, #reports-active .contact-main, #reports-resolved .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    #reports-pending .th-sort, #reports-active .th-sort, #reports-resolved .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    #reports-pending .th-sort:hover, #reports-active .th-sort:hover, #reports-resolved .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    #reports-pending .th-sort.th-sorted, #reports-active .th-sort.th-sorted, #reports-resolved .th-sort.th-sorted{ color:#18181b; }
    #reports-pending .th-sort-icon, #reports-active .th-sort-icon, #reports-resolved .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    #reports-pending .th-sorted .th-sort-icon, #reports-active .th-sorted .th-sort-icon, #reports-resolved .th-sorted .th-sort-icon{ color:#18181b; }
    #reports-pending .sd-row-actions, #reports-active .sd-row-actions, #reports-resolved .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    #reports-pending .sd-row-actions:hover, #reports-active .sd-row-actions:hover, #reports-resolved .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    #reports-all .type-badge, #reports-pending .type-badge, #reports-active .type-badge, #reports-resolved .type-badge{ font-size:14px; }
    #reports-all .rpt-status, #reports-pending .rpt-status, #reports-active .rpt-status, #reports-resolved .rpt-status{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; white-space:nowrap; }
    #reports-all .rpt-status.pending, #reports-pending .rpt-status.pending, #reports-active .rpt-status.pending, #reports-resolved .rpt-status.pending{ background:#fef3c7; color:#92400e; }
    #reports-all .rpt-status.validated, #reports-all .rpt-status.active, #reports-all .rpt-status.resolved,
    #reports-pending .rpt-status.validated, #reports-pending .rpt-status.active, #reports-pending .rpt-status.resolved,
    #reports-active .rpt-status.validated, #reports-active .rpt-status.active, #reports-active .rpt-status.resolved,
    #reports-resolved .rpt-status.validated, #reports-resolved .rpt-status.active, #reports-resolved .rpt-status.resolved{ background:#dcebe0; color:#045e1f; }
    #reports-all .rpt-status.rejected, #reports-pending .rpt-status.rejected, #reports-active .rpt-status.rejected, #reports-resolved .rpt-status.rejected{ background:#fee2e2; color:#991b1b; }

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
      <div id="archiveClientModal" class="modal">
        <div class="modal-content">
          <div class="modal-header">
            <h2><i class="fas fa-archive" style="color:var(--red);"></i> Archive Client</h2>
            <button class="modal-close" onclick="closeModal('archiveClientModal')"><i class="fas fa-times"></i></button>
          </div>
          <div class="modal-body" style="text-align:center;">
            <p>Are you sure you want to archive <strong id="archiveClientName"></strong>?</p>
            <p style="color:#64748b; font-size:0.85rem; margin-top:6px;">The client will be removed from the database but the record can be restored later.</p>
          </div>
          <div class="modal-actions" style="justify-content:center;">
            <button class="btn-secondary" onclick="closeModal('archiveClientModal')">Cancel</button>
            <button class="btn-danger" id="archiveConfirmBtn" onclick="executeArchive()"><i class="fas fa-archive"></i> Archive Client</button>
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
<script src="assets/js/client-database.js"></script>

  <!-- �"?�"? REPORTS: ALL �"?�"? -->
  <section id="reports-all" class="content-section">
    <style>
      /* Dialog font + button style (shadcn) - All Reports */
      #reports-all .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
      #reports-all table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
      #reports-all thead tr{ border-bottom:1px solid #e4e4e7; }
      #reports-all thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:transparent; border-bottom:none; }
      #reports-all thead th.sd-th-action{ text-align:right; }
      #reports-all tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
      #reports-all tbody tr:last-child{ border-bottom:none; }
      #reports-all tbody tr:hover{ background:#f4f4f5; }
      #reports-all tbody td{ padding:10px 8px; vertical-align:middle; font-size:14px; color:#18181b; }
      #reports-all tbody td.sd-td-action{ text-align:right; }
      #reports-all .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
      #reports-all .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
      #reports-all .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
      #reports-all .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
      #reports-all .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
      #reports-all .th-sort:hover{ background:#f4f4f5; color:#18181b; }
      #reports-all .th-sort.th-sorted{ color:#18181b; }
      #reports-all .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
      #reports-all .th-sorted .th-sort-icon{ color:#18181b; }
      #reports-all .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
      #reports-all .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
      #reports-all .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
      #reports-all .db-toolbar{ justify-content:flex-start; }
      #reports-all .search-grow-wrap input{ height:36px; width:100%; padding:0 12px 0 36px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; font-size:14px; color:#18181b; outline:none; font-family:inherit; }
      #reports-all .search-grow-wrap input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #reports-all .search-grow-wrap input::placeholder{ color:#a1a1aa; }
      #reports-all .search-grow-wrap svg{ color:#71717a; }
      #reports-all .tf-select{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background-color:#fff; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
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
                <p class="kpi-sub">Currently being handled</p>
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
            <div class="db-toolbar">
              <div class="search-grow-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="rptMainSearch" placeholder="Search reports by ref, reporter, description..." oninput="rptSetSearch(this.value)">
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="toolbar-filters">
                  <select class="select-input tf-select" id="rptType" onchange="rptSetType(this.value)">
                    <option value="">All Types</option>
                    <option value="stray">Stray Animal</option>
                    <option value="rabies">Rabies</option>
                    <option value="injured">Injured Animal</option>
                    <option value="dead">Dead Animal</option>
                    <option value="rescue">Rescue</option>
                  </select>
                  <select class="select-input tf-select" id="rptBrgy" onchange="rptSetBrgy(this.value)">
                    <option value="">All Barangays</option>
                    <?php foreach ($cpdBarangays as $b): ?>
                      <option value="<?php echo htmlspecialchars($b['barangay_name']); ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="select-input tf-select" id="rptStatus" onchange="rptSetStatus(this.value)">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="validated">Validated</option>
                    <option value="active">Active</option>
                    <option value="resolved">Resolved</option>
                    <option value="rejected">Rejected</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="db-table-area">
              <div id="rptTableWrap" class="dt-container">
                <div style="overflow-x:auto;">
                  <table>
                    <thead id="rptTableHead"></thead>
                    <tbody id="rptRows"></tbody>
                  </table>
                </div>
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
  </section>

  <!-- Report Details Drawer (right drawer, appointments style) -->
  <div id="rptDetailsModal" class="modal cpd cd-drawer">
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
      </div>
    </div>
  </div>

  <script>
  var RPT_ITEMS = <?php echo json_encode($reportItems, $jsonFlags); ?>;
  </script>
  <script src="assets/js/reports-database.js"></script>

  <!-- �"?�"? REPORTS: PENDING CASES �"?�"? -->
  <section id="reports-pending" class="content-section">
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
                <p class="kpi-label">Awaiting Confirmation</p>
                <div class="kpi-value"><?php echo $pendingCounts['all']; ?></div>
                <p class="kpi-sub">Barangay-verified</p>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar">
                <div class="search-grow-wrap">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="penMainSearch" placeholder="Search pending reports..." oninput="penSetSearch(this.value)">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="toolbar-filters">
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
              </div>
              <div class="db-table-area">
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

            <div class="pagination">
              <span class="pagination-info" id="penPageInfo">Showing 0 pending</span>
              <div class="pagination-controls">
                <button class="page-btn" id="penPagePrev" onclick="penGoPage('prev')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <div id="penPageBtns" style="display:flex; align-items:center; gap:6px;"></div>
                <button class="page-btn" id="penPageNext" onclick="penGoPage('next')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Pending Case Details Drawer -->
  <div id="penDetailsModal" class="modal cpd cd-drawer">
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

  <script>
  var PENDING_ITEMS = <?php echo json_encode($pendingItems, $jsonFlags); ?>;
  </script>

  <!-- �"?�"? REPORTS: ACTIVE CASES �"?�"? -->
  <section id="reports-active" class="content-section">
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
            <div class="db-toolbar">
                <div class="search-grow-wrap">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="actMainSearch" placeholder="Search active cases..." oninput="actSetSearch(this.value)">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="toolbar-filters">
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
              </div>
              <div class="db-table-area">
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

            <div class="pagination">
              <span class="pagination-info" id="actPageInfo">Showing 0 cases</span>
              <div class="pagination-controls">
                <button class="page-btn" id="actPagePrev" onclick="actGoPage('prev')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <div id="actPageBtns" style="display:flex; align-items:center; gap:6px;"></div>
                <button class="page-btn" id="actPageNext" onclick="actGoPage('next')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Active Case Details Drawer -->
  <div id="actDetailsModal" class="modal cpd cd-drawer">
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
  <section id="reports-resolved" class="content-section">
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
            <div class="db-toolbar">
                <div class="search-grow-wrap">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="resMainSearch" placeholder="Search resolved cases..." oninput="resSetSearch(this.value)">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="toolbar-filters">
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
              </div>
              <div class="db-table-area">
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

            <div class="pagination">
              <span class="pagination-info" id="resPageInfo">Showing 0 cases</span>
              <div class="pagination-controls">
                <button class="page-btn" id="resPagePrev" onclick="resGoPage('prev')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <div id="resPageBtns" style="display:flex; align-items:center; gap:6px;"></div>
                <button class="page-btn" id="resPageNext" onclick="resGoPage('next')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- Resolved Case Details Drawer -->
  <div id="resDetailsModal" class="modal cpd cd-drawer">
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
  <script src="assets/js/case-database.js"></script>
  <script>
  initCaseDB({
    p: 'pen',
    section: 'reports-pending',
    modalId: 'penDetailsModal',
    data: PENDING_ITEMS,
    pageLabel: 'pending',
    emptyText: 'No pending cases match your filters.',
    sortDefault: 'newest',
    columns: [{ label: 'Report ID', key: 'ref' }, { label: 'Reporter', key: 'reporter' }, { label: 'Type', key: 'typeLabel' }, { label: 'Barangay', key: 'brgy' }, { label: 'Validated', key: 'validated' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'high') return /high|critical/i.test(r.urgency);
      if (v === 'week') return r.vts >= (Date.now() - 7 * 86400000);
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name" style="font-size:12.5px;">' + casEsc(r.ref) + '</div></div></div></td>' +
        '<td><div class="client-name">' + casEsc(r.reporter) + '</div><div class="client-id">' + casEsc(r.phone) + '</div></td>' +
        '<td>' + casTypeBadge(r.type, r.typeLabel) + '</td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td><div class="contact-main">' + casEsc(r.validated) + '</div></td>' +
        '<td><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();penActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var img = r.photo
        ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">'
        : '<div class="card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
      return img +
        '<div style="padding:14px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill(r.status) +
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
        { t: 'Report submitted', d: r.date },
        { t: 'Verified by Barangay', d: r.validated },
        { t: 'Awaiting DCVS confirmation', d: '�?"' }
      ];
      var photoHtml = r.photo
        ? '<div class="rpt-photo-box"><div class="rpt-photo-thumb" onclick="penViewPhoto(\'' + casEsc(r.photo) + '\')"><img src="' + casEsc(r.photo) + '" alt="Report attachment"></div></div>'
        : '<p class="rpt-photo-none">No attachments.</p>';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="penDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="attachments" onclick="penDetailGo(\'attachments\')">Attachments</button>' +
        '<button class="rpt-tab" data-tab="history" onclick="penDetailGo(\'history\')">History</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div class="rpt-detail-ref" style="margin-bottom:14px;">' + casEsc(r.ref) + '</div>' +

        '<div class="rpt-detail-grid">' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Reporter</div>' +
        '<div class="info-rows">' +
        casAvatarInfoRow('Name', r.reporter, casAvatar(r)) +
        casInfoRow('phone', 'Mobile Number', r.phone || '-') +
        casInfoRow('home', 'Barangay', r.brgy || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Report Details</div>' +
        '<div class="info-rows">' +
        casInfoRow('flag', 'Urgency', r.urgency) +
        casInfoRow('tag', 'Status', 'Pending') +
        casInfoRow('cal', 'Submitted', r.date) +
        casInfoRow('cal', 'Validated', r.validated || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Description</div>' +
        '<div class="rpt-desc-box">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</div>' +

        (r.brgyNotes ? '<div class="rpt-col">' +
        '<div class="rpt-section-title">Barangay Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.brgyNotes) + '</div>' +
        '</div>' : '') +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Location</div>' +
        '<div class="info-rows" style="margin-bottom:22px;">' +
        casInfoRow('home2', 'Address', r.location || '-') +
        '</div>' +
        '</div>' +

        '</div>' +

        '<div class="profile-footer">' +
        '<button class="btn" onclick="penViewLocation(\'' + casEsc(r.location) + '\')">View Location</button>' +
        '<button class="btn btn-secondary" onclick="openRejectionModal(' + r.id + ')">Reject</button>' +
        '<button class="btn btn-primary" onclick="openReportReview(' + r.id + ')">Confirm</button>' +
        '</div>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="attachments" style="display:none;">' +
        '<div class="rpt-section-title">Attachments</div>' + photoHtml +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="history" style="display:none;">' +
        '<div class="rpt-section-title">Action History</div>' +
        '<ul class="rpt-history">' + history.map(function (h) {
          return '<li>' + casEsc(h.t) + '<span class="h-date">' + casEsc(h.d) + '</span></li>';
        }).join('') + '</ul>' +
        '</div>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="penSelect(' + r.id + ');caseCloseActions()">View Report</button>' +
        (r.photo ? '<button class="row-menu-item" onclick="penViewPhoto(\'' + casEsc(r.photo) + '\')">View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="penViewLocation(\'' + casEsc(r.location) + '\')">View Location</button>' +
        '<button class="row-menu-item" onclick="penSelect(' + r.id + ');caseCloseActions()">View History</button>' +
        '<button class="row-menu-item" onclick="openRejectionModal(' + r.id + ');caseCloseActions()">Reject</button>' +
        '<button class="row-menu-item" onclick="openReportReview(' + r.id + ');caseCloseActions()">Confirm</button>';
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
    columns: [{ label: 'Case ID', key: 'id' }, { label: 'Report', key: 'ref' }, { label: 'Type', key: 'typeLabel' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Confirmed', key: 'confirmed' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'high') return /high|critical/i.test(r.urgency);
      if (v === 'sched') return !!r.scheduled;
      if (v === 'overdue') return r.scheduled && new Date(r.scheduled.replace(' PM', ' PM')).getTime() < Date.now();
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-name" style="font-size:12.5px;font-weight:700;">' + casCaseId(r) + '</div></td>' +
        '<td><div class="client-name" style="font-size:12.5px;">' + casEsc(r.ref) + '</div></td>' +
        '<td>' + casTypeBadge(r.type, r.typeLabel) + '</td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td>' + casStatusPill('active') + (r.scheduled ? '<div class="client-id" style="margin-top:3px;">Scheduled ' + casEsc(r.scheduled) + '</div>' : '') + '</td>' +
        '<td><div class="contact-main">' + casEsc(r.confirmed) + '</div></td>' +
        '<td><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();actActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
    },
    cardHtml: function (r) {
      var img = r.photo
        ? '<img class="card-img" src="' + casEsc(r.photo) + '" alt="">'
        : '<div class="card-img-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
      return img +
        '<div style="padding:14px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">' +
        casTypeBadge(r.type, r.typeLabel) + casStatusPill('active') +
        '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:8px;">' + casCaseId(r) + ' &middot; ' + casEsc(r.ref) + '</div>' +
        '<div class="client-card-name" style="font-size:14px;margin-bottom:2px;">' + casEsc(r.reporter) + '</div>' +
        '<div class="client-card-id" style="font-size:12px;margin-bottom:10px;">' + casEsc(r.brgy || '-') + '</div>' +
        '<div class="client-card-meta"><span class="client-card-contact">Confirmed ' + casEsc(r.confirmed) + '</span>' +
        '<span style="font-size:11.5px;font-weight:700;color:var(--green-700);">View Case &rarr;</span></div>' +
        '</div>';
    },
    drawerHtml: function (r) {
      var history = [
        { t: 'Report submitted', d: r.date },
        { t: 'Verified by Barangay', d: r.validated || r.confirmed },
        { t: 'Confirmed by DCVS Staff', d: r.confirmed },
        (r.scheduled ? { t: 'Scheduled for action', d: r.scheduled } : null),
        { t: 'Status: Active', d: 'Being handled' }
      ].filter(Boolean);
      var photoHtml = r.photo
        ? '<div class="rpt-photo-box"><div class="rpt-photo-thumb" onclick="actViewPhoto(\'' + casEsc(r.photo) + '\')"><img src="' + casEsc(r.photo) + '" alt="Report attachment"></div></div>'
        : '<p class="rpt-photo-none">No attachments.</p>';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="actDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="attachments" onclick="actDetailGo(\'attachments\')">Attachments</button>' +
        '<button class="rpt-tab" data-tab="history" onclick="actDetailGo(\'history\')">History</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div class="rpt-detail-ref" style="margin-bottom:14px;">' + casEsc(r.ref) + '</div>' +

        '<div class="rpt-detail-grid">' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Reporter</div>' +
        '<div class="info-rows">' +
        casAvatarInfoRow('Name', r.reporter, casAvatar(r)) +
        casInfoRow('phone', 'Mobile Number', r.phone || '-') +
        casInfoRow('home', 'Barangay', r.brgy || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Case Details</div>' +
        '<div class="info-rows">' +
        casInfoRow('flag', 'Urgency', r.urgency) +
        casInfoRow('tag', 'Status', 'Active') +
        casInfoRow('cal', 'Confirmed', r.confirmed) +
        casInfoRow('cal', 'Scheduled', r.scheduled || 'Not yet scheduled') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Description</div>' +
        '<div class="rpt-desc-box">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</div>' +

        (r.dcvsNotes ? '<div class="rpt-col">' +
        '<div class="rpt-section-title">DCVS Notes</div>' +
        '<div class="rpt-notes-box">' + casEsc(r.dcvsNotes) + '</div>' +
        '</div>' : '') +

        '<div class="rpt-col rpt-full-col">' +
        '<div class="rpt-section-title">Location</div>' +
        '<div class="info-rows" style="margin-bottom:22px;">' +
        casInfoRow('home2', 'Address', r.location || '-') +
        '</div>' +
        '</div>' +

        '</div>' +

        '<div class="rpt-detail-body" data-tab="attachments" style="display:none;">' +
        '<div class="rpt-section-title">Attachments</div>' + photoHtml +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="history" style="display:none;">' +
        '<div class="rpt-section-title">Action History</div>' +
        '<ul class="rpt-history">' + history.map(function (h) {
          return '<li>' + casEsc(h.t) + '<span class="h-date">' + casEsc(h.d) + '</span></li>';
        }).join('') + '</ul>' +
        '</div>';
    },
    drawerFooter: function (r) {
      return '<button class="sd-btn sd-btn-outline" onclick="actViewGeo(\'' + casEsc(r.brgy) + '\')">View Location</button>' +
        '<button class="sd-btn sd-btn-default" onclick="openResolveModal(' + r.caseId + ', \'' + casEsc(r.typeLabel).replace(/'/g, '\\\'') + '\', \'' + casEsc(r.reporter).replace(/'/g, '\\\'') + '\')">Mark Resolved</button>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="actSelect(' + r.id + ');caseCloseActions()">View Report</button>' +
        (r.photo ? '<button class="row-menu-item" onclick="actViewPhoto(\'' + casEsc(r.photo) + '\')">View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="actViewLocation(\'' + casEsc(r.location) + '\')">View Location</button>' +
        '<button class="row-menu-item" onclick="actSelect(' + r.id + ');caseCloseActions()">View History</button>' +
        '<button class="row-menu-item" onclick="openResolveModal(' + r.caseId + ', \'' + casEsc(r.typeLabel).replace(/'/g, '\\\'') + '\', \'' + casEsc(r.reporter).replace(/'/g, '\\\'') + '\');caseCloseActions()">Mark Resolved</button>';
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
    columns: [{ label: 'Case ID', key: 'id' }, { label: 'Report', key: 'ref' }, { label: 'Type', key: 'typeLabel' }, { label: 'Barangay', key: 'brgy' }, { label: 'Status', key: 'status' }, { label: 'Resolved', key: 'resolved' }, 'Actions'],
    groupMatch: function (r, v) {
      if (v === 'month') return r.ts >= new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime();
      if (v === 'year') return r.ts >= new Date(new Date().getFullYear(), 0, 1).getTime();
      if (v === 'high') return /high|critical/i.test(r.urgency);
      return true;
    },
    rowHtml: function (r) {
      return '<td><div class="client-name" style="font-size:12.5px;font-weight:700;">' + casCaseId(r) + '</div></td>' +
        '<td><div class="client-cell"><div class="avatar">' + casAvatar(r) + '</div><div><div class="client-name" style="font-size:12.5px;">' + casEsc(r.ref) + '</div></div></div></td>' +
        '<td>' + casTypeBadge(r.type, r.typeLabel) + '</td>' +
        '<td><div class="client-name">' + casEsc(r.brgy || '-') + '</div></td>' +
        '<td>' + casStatusPill('resolved') + '</td>' +
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
        { t: 'Report submitted', d: r.date },
        { t: 'Verified by Barangay', d: r.validated || r.confirmed },
        { t: 'Confirmed by DCVS Staff', d: r.confirmed },
        (r.scheduled ? { t: 'Scheduled for action', d: r.scheduled } : null),
        { t: 'Resolved by DCVS Staff', d: r.resolved }
      ].filter(Boolean);
      var photoHtml = r.photo
        ? '<div class="rpt-photo-box"><div class="rpt-photo-thumb" onclick="resViewPhoto(\'' + casEsc(r.photo) + '\')"><img src="' + casEsc(r.photo) + '" alt="Report attachment"></div></div>'
        : '<p class="rpt-photo-none">No attachments.</p>';
      return '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="resDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="attachments" onclick="resDetailGo(\'attachments\')">Attachments</button>' +
        '<button class="rpt-tab" data-tab="history" onclick="resDetailGo(\'history\')">History</button>' +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="details">' +

        '<div class="rpt-detail-ref" style="margin-bottom:14px;">' + casEsc(r.ref) + '</div>' +

        '<div class="rpt-detail-grid">' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Reporter</div>' +
        '<div class="info-rows">' +
        casAvatarInfoRow('Name', r.reporter, casAvatar(r)) +
        casInfoRow('phone', 'Mobile Number', r.phone || '-') +
        casInfoRow('home', 'Barangay', r.brgy || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Case Details</div>' +
        '<div class="info-rows">' +
        casInfoRow('flag', 'Urgency', r.urgency) +
        casInfoRow('tag', 'Status', 'Resolved') +
        casInfoRow('cal', 'Confirmed', r.confirmed) +
        casInfoRow('cal', 'Resolved', r.resolved || '-') +
        '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Description</div>' +
        '<div class="rpt-desc-box">' + casEsc(r.desc || 'No description provided.') + '</div>' +
        '</div>' +

        '<div class="rpt-col">' +
        '<div class="rpt-section-title">Location</div>' +
        '<div class="info-rows" style="margin-bottom:22px;">' +
        casInfoRow('home2', 'Address', r.location || '-') +
        '</div>' +
        '</div>' +

        '</div>' +

        '</div>' +

        '<div class="rpt-detail-body" data-tab="attachments" style="display:none;">' +
        '<div class="rpt-section-title">Attachments</div>' + photoHtml +
        '</div>' +

        '<div class="rpt-detail-body" data-tab="history" style="display:none;">' +
        '<div class="rpt-section-title">Resolution Timeline</div>' +
        '<ul class="rpt-history">' + history.map(function (h) {
          return '<li>' + casEsc(h.t) + '<span class="h-date">' + casEsc(h.d) + '</span></li>';
        }).join('') + '</ul>' +
        '</div>';
    },
    menuHtml: function (r) {
      return '<button class="row-menu-item" onclick="resSelect(' + r.id + ');caseCloseActions()">View Report</button>' +
        (r.photo ? '<button class="row-menu-item" onclick="resViewPhoto(\'' + casEsc(r.photo) + '\')">View Attachments</button>' : '') +
        '<button class="row-menu-item" onclick="resViewLocation(\'' + casEsc(r.location) + '\')">View Location</button>' +
        '<button class="row-menu-item" onclick="resSelect(' + r.id + ');caseCloseActions()">View History</button>' +
        '<button class="row-menu-item" onclick="window.open(\'export-report.php\',\'_blank\');caseCloseActions()">Export Report</button>';
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
    <div class="page-header">
      <div>
        <h1 class="page-title" style="font-size:22px;font-weight:800;margin:0;">My Profile</h1>
        <p class="page-subtitle">Manage your staff profile and account information</p>
      </div>
    </div>

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
              <div class="ps-avatar"><img id="staffAvatarImg" src="<?php echo htmlspecialchars($staffAvatar); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onclick="toggleProfileDropdown(event)"></div>
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

      <form id="staffSettingsForm" action="update-staff-profile.php" method="POST" enctype="multipart/form-data">
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
          <img src="<?php echo htmlspecialchars($staffAvatar); ?>" alt="Profile Photo" id="profilePhotoViewImg">
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
    <form class="modal-form" id="changePasswordForm" action="update-staff-password.php" method="POST">
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

<!-- Confirm Report Modal (Schedule) -->
<div class="modal" id="confirmReportModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Confirm & Schedule Report</h2>
      <button class="modal-close" onclick="closeModal('confirmReportModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="confirmReportForm" action="confirm-schedule-report.php" method="POST">
      <div class="modal-body">
        <input type="hidden" name="report_id" id="confirmReportId" value="" />
        <div class="form-group"><label>Schedule Date & Time <span class="req">*</span></label><input type="datetime-local" name="scheduled_at" required /></div>
        <div class="form-group"><label>DCVS Notes</label><textarea name="dcvs_notes" rows="3" placeholder="Any instructions or notes for this case..."></textarea></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-check"></i> Confirm & Schedule</button>
        <button type="button" class="btn-secondary" onclick="closeModal('confirmReportModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Report Modal -->
<div class="modal" id="rejectReportModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-times-circle"></i> Reject Report</h2>
      <button class="modal-close" onclick="closeModal('rejectReportModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="rejectReportForm" action="reject-validated-report.php" method="POST">
      <div class="modal-body">
        <input type="hidden" name="report_id" id="rejectReportId" value="" />
        <div class="form-group"><label>Rejection Reason <span class="req">*</span></label><textarea name="reason" rows="4" required placeholder="Explain why this report is being rejected..."></textarea></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-danger"><i class="fas fa-times"></i> Reject Report</button>
        <button type="button" class="btn-secondary" onclick="closeModal('rejectReportModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>
<!-- Schedule Appointment Modal -->
<!-- Review Appointment Request Modal (shadcn Dialog) -->
<style>
  /* Appointment Details = right drawer */
  #appointmentDetailsModal.modal{ position:fixed; inset:0; background:rgba(0,0,0,0.5); padding:0; z-index:999; display:flex; align-items:stretch; justify-content:flex-end; opacity:0; visibility:hidden; transition:opacity .25s ease, visibility .25s ease; }
  #appointmentDetailsModal.modal.active{ opacity:1; visibility:visible; }
  #appointmentDetailsModal .sd-dialog{ height:100%; width:100%; display:flex; justify-content:flex-end; }
  #appointmentDetailsModal .sd-dialog-content{ max-width:min(480px,92vw); width:min(480px,92vw); height:100%; max-height:none; border:none; border-radius:0; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); padding:24px; gap:18px; }
  #appointmentDetailsModal.active .sd-dialog-content{ transform:translateX(0); }
  /* Review Requests = centered dialog */
  #addAppointmentModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
  #addAppointmentModal .sd-dialog-content{ max-width:470px; gap:20px; }
  /* Details content layout (kept on the drawer) */
  #appointmentDetailsModal .sd-field-group{ gap:22px; }
  #appointmentDetailsModal .ec-detail-rows{ display:flex; flex-direction:column; gap:16px; }
  #appointmentDetailsModal .ec-detail-row{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; }
  #appointmentDetailsModal .ec-detail-row .sd-label{ margin:0; }
  #appointmentDetailsModal .ec-detail-row .sd-value{ text-align:right; }
.sd-field-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px 20px; }
.sd-detail-cols{ display:grid; grid-template-columns:1fr 1fr; gap:16px 36px; }
.sd-detail-cols > div{ display:flex; flex-direction:column; gap:16px; }
.sd-detail-cols > div:last-child{ text-align:right; }
.sd-value{ font-size:14px; font-weight:500; color:#18181b; line-height:1.4; word-break:break-word; }
.pet-chip{ display:inline-flex; align-items:center; padding:0; border-radius:0; background:none; color:#18181b; font-size:12.5px; font-weight:500; }
.pet-chip:not(:first-child)::before{ content:'\b7'; margin-right:8px; color:#a1a1aa; }
.resident-id-chip{ display:inline-flex; align-items:center; padding:2px 9px; border-radius:20px; background:#eef2f6; color:#64748b; font-size:11.5px; font-weight:600; font-family:'Inter','Segoe UI',sans-serif; letter-spacing:.02em; white-space:nowrap; }
.copy-id-btn{ display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s, border-color .15s, color .15s; flex-shrink:0; }
.copy-id-btn:hover{ background:#f4f4f5; border-color:#cbd5e1; }
.copy-id-btn.copied{ background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
  @media (max-width:560px){ .sd-field-grid{ grid-template-columns:1fr; } .sd-detail-cols{ grid-template-columns:1fr; } }
</style>
<div id="addAppointmentModal" class="modal">
  <form id="addAppointmentForm" method="POST" class="sd-dialog">
    <input type="hidden" name="appointment_id" id="formApptId">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('addAppointmentModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Review Appointment Request</h2>
        <p class="sd-dialog-description">Review the request details and approve or reject it.</p>
      </div>
      <div class="sd-field-group">
        <div class="sd-field">
          <label class="sd-label" for="lookupRequestId">Quick Lookup by Request ID</label>
          <div style="display:flex; gap:8px;">
            <input type="text" id="lookupRequestId" class="sd-input" placeholder="e.g. #APT-0012" style="flex:1; min-width:0;" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupAppointmentRequest();}">
            <button type="button" class="sd-btn sd-btn-default" onclick="lookupAppointmentRequest()" style="white-space:nowrap; padding:0 16px;">Find</button>
          </div>
          <small id="lookupMessage" style="color:#71717a; margin-top:6px; display:block; font-size:12px;">Enter a request ID to load the request details. Only the decision below can be changed.</small>
        </div>
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
        <div class="sd-field">
          <label class="sd-label" for="formStaffMessage">Message / Instructions for Resident</label>
          <textarea name="staff_message" id="formStaffMessage" rows="2" class="sd-input" placeholder="e.g. Please bring valid ID and arrive at the DCVS office on time."></textarea>
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('addAppointmentModal')">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default" id="formSubmitBtn">Submit Decision</button>
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
<div id="appointmentDetailsModal" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('appointmentDetailsModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Appointment Details</h2>
        <p class="sd-dialog-description">Complete details of this appointment request.</p>
      </div>
      <div class="sd-field-group">
        <div class="sd-field">
          <span class="sd-label">Resident</span>
          <div style="display:flex; align-items:center; gap:14px;">
            <div id="detailAvatar" style="width:52px;height:52px;border-radius:50%;background:#e4e4e7;color:#71717a;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.15rem;flex-shrink:0;overflow:hidden;">
              <i class="fas fa-user"></i>
            </div>
            <div style="min-width:0; flex:1;">
              <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <div id="detailClient" class="sd-value" style="font-size:16px;">-</div>
                <span id="detailResidentId" class="resident-id-chip">-</span>
              </div>
              <div id="detailType" style="font-size:12.5px;color:#71717a;margin-top:3px;">-</div>
            </div>
            <button type="button" class="copy-id-btn" id="copyApptIdBtn" onclick="copyAppointmentId()" title="Copy appointment ID">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Copy ID
            </button>
          </div>
        </div>
        <div class="ec-detail-rows">
          <div class="ec-detail-row"><span class="sd-label">Email</span><span id="detailEmail" class="sd-value">-</span></div>
          <div class="ec-detail-row"><span class="sd-label">Barangay</span><span id="detailBrgy" class="sd-value">-</span></div>
          <div class="ec-detail-row"><span class="sd-label">Time</span><span id="detailTime" class="sd-value">-</span></div>
          <div class="ec-detail-row"><span class="sd-label">Phone</span><span id="detailPhone" class="sd-value">-</span></div>
          <div class="ec-detail-row"><span class="sd-label">Date</span><span id="detailDate" class="sd-value">-</span></div>
          <div class="ec-detail-row"><span class="sd-label">Status</span><span id="detailStatus" class="sd-value">-</span></div>
        </div>
        <div class="ec-detail-row"><span class="sd-label">Address</span><span id="detailAddress" class="sd-value">-</span></div>
        <div class="sd-field">
          <span class="sd-label">Pet</span>
          <div id="detailPet" class="sd-value">-</div>
          <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;">
            <span class="pet-chip" id="detailPetType">-</span>
            <span class="pet-chip" id="detailPetBreed">-</span>
            <span class="pet-chip" id="detailPetGender">-</span>
            <span class="pet-chip" id="detailPetAge">-</span>
            <span class="pet-chip" id="detailPetVax">-</span>
          </div>
        </div>
        <div class="sd-field" id="detailNotesContainer">
          <span class="sd-label">Notes</span>
          <div id="detailNotes" class="sd-value" style="background:#f8fafc; border:1px solid #e4e4e7; border-radius:6px; padding:10px 12px;">-</div>
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-default" onclick="closeModal('appointmentDetailsModal')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Resolve Case Modal (shadcn dialog) -->
<div class="modal" id="resolveModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:470px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('resolveModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Mark Case as Resolved</h2>
        <p class="sd-dialog-description">Mark the case as resolved to close it and notify the resident and barangay.</p>
      </div>
      <form id="resolveForm" action="resolve-report.php" method="POST" style="display:contents;">
        <div class="sd-field-group">
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
function lookupAppointmentRequest() {
    const id = document.getElementById('lookupRequestId').value;
    const msg = document.getElementById('lookupMessage');
    if (!id) {
        msg.textContent = 'Please enter a Request ID.';
        msg.style.color = 'red';
        return;
    }
    
    msg.textContent = 'Searching...';
    msg.style.color = '#64748b';
    
    fetch('staff-get-appointment-request.php?id=' + encodeURIComponent(id))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const d = data.data;
                if (d.status !== 'pending') {
                    msg.textContent = 'This request has already been processed (status: ' + d.status + ').';
                    msg.style.color = 'red';
                    return;
                }
                msg.textContent = 'Request loaded. Select Approve & Schedule or Reject below.';
                msg.style.color = 'green';
                
                document.getElementById('formApptId').value = d.appointment_id;
                document.getElementById('formResidentName').value = d.full_name;
                document.getElementById('formPetName').value = d.pet_name || 'â�,��?�';
                document.getElementById('formApptType').value = d.appointment_type.charAt(0).toUpperCase() + d.appointment_type.slice(1);
                document.getElementById('formDate').value = d.preferred_date;
                document.getElementById('formTime').value = d.preferred_time.length > 5 ? d.preferred_time.slice(0,5) : d.preferred_time;
                document.getElementById('formRequestNotes').value = d.notes || '';
                document.getElementById('formStatus').value = '';
                document.getElementById('formRejectReason').value = '';
                document.getElementById('formStaffMessage').value = '';
                document.getElementById('rejectReasonWrap').style.display = 'none';

                // Availability check on the resident's preferred slot
                const slotStatus = document.getElementById('formSlotStatus');
                slotStatus.style.display = 'block';
                slotStatus.style.background = '#fefce8';
                slotStatus.style.color = '#92400e';
                slotStatus.textContent = 'Checking availability...';
                fetch('includes/check_slot.php?date=' + encodeURIComponent(d.preferred_date) + '&time=' + encodeURIComponent(d.preferred_time) + '&exclude=' + d.appointment_id)
                    .then(r => r.json())
                    .then(sd => {
                        if (sd.available) {
                            slotStatus.style.background = '#f0fdf4';
                            slotStatus.style.color = '#065f46';
                            slotStatus.textContent = 'Slot available for this time.';
                        } else {
                            slotStatus.style.background = '#fef2f2';
                            slotStatus.style.color = '#991b1b';
                            var m = sd.blocked ? 'This time slot is closed by schedule (Availability page).'
                                                : (sd.held ? 'This time slot is already reserved by another resident.'
                                                           : 'This preferred slot is no longer available.');
                            if (sd.nearest_slot) {
                                var nearTime = sd.nearest_slot.length > 5 ? sd.nearest_slot.slice(-5) : sd.nearest_slot;
                                m += ' Nearest free: ' + nearTime + '.';
                            }
                            slotStatus.textContent = m;
                        }
                    })
                    .catch(() => { slotStatus.style.display = 'none'; });
            } else {
                msg.textContent = data.message || 'Not found.';
                msg.style.color = 'red';
            }
        })
        .catch(err => {
            msg.textContent = 'Network error.';
            msg.style.color = 'red';
        });
}

// Toggle rejection reason field + route form based on decision
document.addEventListener('DOMContentLoaded', function() {
    var statusSel = document.getElementById('formStatus');
    var rejectWrap = document.getElementById('rejectReasonWrap');
    var form = document.getElementById('addAppointmentForm');
    if (statusSel) {
        statusSel.addEventListener('change', function() {
            var isReject = statusSel.value === 'rejected';
            rejectWrap.style.display = isReject ? 'block' : 'none';
            var reason = document.getElementById('formRejectReason');
            reason.required = isReject;
        });
    }
    if (form) {
        form.addEventListener('submit', function(e) {
            var decision = statusSel ? statusSel.value : '';
            var apptId = document.getElementById('formApptId').value;
            if (!apptId) {
                e.preventDefault();
                alert('Look up a pending request first.');
                return;
            }
            if (decision === 'scheduled') {
                form.action = 'approve-appointment.php';
            } else if (decision === 'rejected') {
                var reason = document.getElementById('formRejectReason');
                if (!reason.value.trim()) {
                    e.preventDefault();
                    alert('Please enter a rejection reason.');
                    reason.focus();
                    return;
                }
                form.action = 'reject-appointment.php';
            } else {
                e.preventDefault();
                alert('Please select a decision: Approve & Schedule or Reject.');
                return;
            }
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
    fetch('staff-get-owner-profile.php?user_id=' + encodeURIComponent(userId))
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
                                    ? `<img src="${pet.photo_url}" alt="${pet.pet_name}" style="width:100%; height:100%; object-fit:cover;" />` 
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
                                    <span class="status-pill status-${pet.vaccination_status === 'Fully vaccinated' ? 'confirmed' : 'pending'}" style="font-size:0.9rem; font-weight:600; padding:4px 10px;">${pet.vaccination_status || 'Not Specified'}</span>
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
    document.getElementById('resolveCaseId').value = caseId;
    document.getElementById('resolveCaseRef').textContent = reportType + ' from ' + reporterName;
    openModal('resolveModal');
}

function openReportReview(reportId) {
    document.getElementById('confirmReportId').value = reportId;
    openModal('confirmReportModal');
}

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

    function getStatus(row) {
        var st = row.getAttribute('data-status');
        if (st) return st.trim().toLowerCase().replace(/\s+/g, '_');
        var cells = row.querySelectorAll('td');
        return cells.length >= 6 ? (cells[4].textContent || '').trim().toLowerCase().replace(/\s+/g, '_') : '';
    }

    function getFiltered() {
        return allRows.filter(function(r) {
            var status = getStatus(r);
            var text = r.textContent.toLowerCase();
            var matchS = filterStatus === 'all' || status === filterStatus;
            var matchQ = !filterSearch || text.includes(filterSearch);
            return matchS && matchQ;
        });
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

function markAllNotifRead() {
    fetch('mark-notifications-read.php?type=staff').then(() => {
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
          const targetLink = document.querySelector(`.nav-link[data-section="${savedTab}"]`);
          if (targetLink) {
              targetLink.click();
          }
      }
    } else {
      localStorage.removeItem('staffActiveTab');
    }

    // Initialize Charts
    initCharts();
});

function initCharts() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';
}

// Modal Functions
function openAddAppointmentModal() {
    openModal('addAppointmentModal');
}

// View Medical History (AJAX)
function viewMedicalHistory(petId) {
    document.getElementById('medicalHistoryModal').style.display = 'flex';
    document.getElementById('medicalHistoryContent').innerHTML = '<p style="text-align:center;">Loading history...</p>';
    
    // Fetch history
    fetch('staff-get-history.php?pet_id=' + petId)
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
        if (col === 'appt') return txt(0);
        if (col === 'service') return txt(1);
        if (col === 'resident') return txt(2);
        if (col === 'pet') return txt(3);
        if (col === 'schedule') return row.getAttribute('data-date') || txt(4);
        if (col === 'submitted') return row.getAttribute('data-submitted') || txt(5);
        if (col === 'status') return txt(6);
        return txt(0);
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
        return cells.length >= 6 ? (cells[4].textContent || '').trim().toLowerCase().replace(/\s+/g, '_') : '';
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
    fetch('includes/check_slot.php?date=' + encodeURIComponent(date) + '&time=' + encodeURIComponent(time) + '&exclude=' + exclude)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.available) {
                status.style.background = '#f0fdf4';
                status.style.color = '#065f46';
                status.textContent = 'Slot available';
            } else {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                var msg = d.blocked ? 'This time slot is closed by schedule.'
                                    : (d.held ? 'This time slot is already reserved by another resident.'
                                              : 'This slot is no longer available.');
                if (d.nearest_slot) {
                    var nearTime = d.nearest_slot.length > 5 ? d.nearest_slot.slice(-5) : d.nearest_slot;
                    msg += ' Nearest free: ' + nearTime;
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
  var menu = btn.closest('.appt-more-dropdown');
  if (!menu) return;
  var dd = menu.querySelector('.appt-dropdown');
  if (!dd) return;
  var open = dd.classList.contains('open');
  document.querySelectorAll('#appointmentsTable .appt-dropdown.open').forEach(function(d){ d.classList.remove('open'); });
  if (!open) dd.classList.add('open');
};
document.addEventListener('click', function(){
  document.querySelectorAll('#appointmentsTable .appt-dropdown.open').forEach(function(d){ d.classList.remove('open'); });
});
window.APP = window.APP || {};
(function(APP) {
  var apptSelected = {};

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
    var all = document.getElementById('apptSelectAll');
    var total = document.querySelectorAll('#appointmentsTable tbody .appt-row-check').length;
    if (all) all.checked = total > 0 && n === total;
  };

  APP.bulkStatusChange = function(status) {
    var ids = Object.keys(apptSelected);
    if (!ids.length) { alert('Select appointments first.'); return; }
    var action = status === 'completed' ? 'complete' : (status === 'cancelled' ? 'cancel' : '');
    if (!action) { alert('Unsupported action.'); return; }
    if (!confirm((action === 'cancel' ? 'Cancel' : 'Mark as completed') + ' ' + ids.length + ' selected appointment(s)?')) return;
    fetch('update-appointment-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(action) + '&ids=' + encodeURIComponent(ids.join(','))
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) { location.reload(); } else { alert(d.message || 'Failed to update.'); }
    })
    .catch(function() { alert('Network error.'); });
  };

  APP.bulkDelete = function() {
    var ids = Object.keys(apptSelected);
    if (!ids.length) { alert('Select appointments first.'); return; }
    if (!confirm('Permanently delete ' + ids.length + ' selected appointment(s)? This cannot be undone.')) return;
    fetch('staff-archive-appointment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ids=' + encodeURIComponent(ids.join(','))
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) { location.reload(); } else { alert(d.message || 'Delete failed.'); }
    })
    .catch(function() { alert('Network error.'); });
  };

  document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'apptSelectAll') {
      var checked = e.target.checked;
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
    }
  });

  APP.statusChange = function(id, newStatus) {
    var action = newStatus === 'completed' ? 'complete' : (newStatus === 'cancelled' ? 'cancel' : '');
    if (!action) { alert('Unsupported action.'); return; }
    if (!confirm('Change status to "' + newStatus.replace(/_/g, ' ') + '"?')) return;
    fetch('update-appointment-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(action) + '&ids=' + encodeURIComponent(id)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) { location.reload(); } else { alert(d.message || 'Failed to update status.'); }
    })
    .catch(function() { alert('Network error.'); });
  };

  APP.view = function(id) {
    fetch('staff-get-appointment-request.php?id=' + encodeURIComponent(id))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success) { alert('Appointment not found.'); return; }
        var a = d.data || d.appointment;
        function set(el, v) { if (el) el.textContent = v || '-'; }
        function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
        var statusText = (a.status || '-').replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        set(document.getElementById('detailClient'), a.full_name);
        set(document.getElementById('detailResidentId'), a.user_reference_id || '-');
        window._currentApptRef = a.reference_id || ('#APT-' + (a.appointment_id || ''));
        set(document.getElementById('detailType'), cap(a.appointment_type));
        set(document.getElementById('detailDate'), a.preferred_date ? new Date(a.preferred_date).toLocaleDateString('en-US', {year:'numeric',month:'short',day:'numeric'}) : '-');
        set(document.getElementById('detailTime'), a.preferred_time);
        set(document.getElementById('detailStatus'), statusText);
        set(document.getElementById('detailEmail'), a.email);
        set(document.getElementById('detailPhone'), a.phone);
        set(document.getElementById('detailBrgy'), a.barangay_name);
        set(document.getElementById('detailAddress'), a.address || (a.barangay_name ? a.barangay_name + ', Dasmariñas, Cavite' : ''));
        set(document.getElementById('detailPet'), a.pet_name);
        set(document.getElementById('detailPetType'), cap(a.pet_type));
        set(document.getElementById('detailPetBreed'), a.breed);
        set(document.getElementById('detailPetGender'), cap(a.gender));
        set(document.getElementById('detailPetAge'), a.age);
        set(document.getElementById('detailPetVax'), a.vaccination_status);
        set(document.getElementById('detailNotes'), a.notes);
        var avatarEl = document.getElementById('detailAvatar');
        var pic = a.avatar_url || a.profile_picture;
        if (avatarEl) {
          if (pic) {
            avatarEl.innerHTML = '<img src="' + pic + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
          } else {
            var initials = (a.full_name || '?').split(' ').map(function(w){return w[0];}).join('').substring(0,2).toUpperCase();
            avatarEl.innerHTML = initials;
          }
        }
        openModal('appointmentDetailsModal');
      })
      .catch(function() { alert('Error loading appointment.'); });
  };

  APP.reschedSingle = function(id) {
    fetch('staff-get-appointment-request.php?id=' + encodeURIComponent(id))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success) { alert('Appointment not found.'); return; }
        var a = d.data || d.appointment;
        var fApptId = document.getElementById('formApptId');
        var fName = document.getElementById('formResidentName');
        var fPet = document.getElementById('formPetName');
        var fType = document.getElementById('formApptType');
        var fDate = document.getElementById('formDate');
        var fTime = document.getElementById('formTime');
        var fNotes = document.getElementById('formRequestNotes');
        var fStatus = document.getElementById('formStatus');
        if (fApptId) fApptId.value = a.appointment_id;
        if (fName) fName.value = a.full_name || '';
        if (fPet) fPet.value = a.pet_name || '';
        if (fType) fType.value = a.appointment_type || '';
        if (fDate) fDate.value = a.preferred_date || '';
        if (fTime) fTime.value = a.preferred_time || '';
        if (fNotes) fNotes.value = a.notes || '';
        if (fStatus) fStatus.value = '';
        openModal('addAppointmentModal');
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
  var orig = btn.innerHTML;
  btn.classList.add('copied');
  btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied';
  setTimeout(function() { btn.classList.remove('copied'); btn.innerHTML = orig; }, 1800);
}



</script>

<script src="includes/realtime.js"></script>
</body>
</html>








