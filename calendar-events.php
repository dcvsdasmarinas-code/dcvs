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

require_once 'db.php';
require_once 'includes/availability.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$calendarAppointments = [];
$calStatusColor = [
    'scheduled'   => '#0E8131',
    'rescheduled' => '#7c3aed',
    'approved'    => '#2563eb',
    'pending'     => '#f59e0b',
    'in_progress' => '#2563eb',
    'completed'   => '#6b7280',
    'cancelled'   => '#ef4444',
    'rejected'    => '#b91c1c',
    'no_show'     => '#d97706'
];
$apptsQuery = $conn->query("
    SELECT a.appointment_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.notes, a.reference_id, u.full_name, u.email, u.phone, u.avatar_url, p.pet_name
    FROM appointments a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN pets p ON a.pet_id = p.pet_id
    WHERE a.archived_at IS NULL
");
if ($apptsQuery) {
    while ($row = $apptsQuery->fetch_assoc()) {
        $color = $calStatusColor[$row['status']] ?? '#3b82f6';
        $timeLabel = date('g:i A', strtotime($row['preferred_time']));
        $calendarAppointments[] = [
            'id' => (string)$row['appointment_id'],
            'title' => $timeLabel . ' ' . ucfirst($row['appointment_type']),
            'start' => $row['preferred_date'] . 'T' . $row['preferred_time'],
            'display' => 'block',
            'backgroundColor' => $color,
            'borderColor' => $color,
            'extendedProps' => [
                'id'   => (string)$row['appointment_id'],
                'ref'  => $row['reference_id'],
                'type' => $row['appointment_type'],
                'status' => $row['status'],
                'client' => $row['full_name'],
                'email'  => $row['email'] ?? '',
                'phone'  => $row['phone'] ?? '',
                'avatar' => $row['avatar_url'] ?? '',
                'pet'    => $row['pet_name'] ?? 'N/A',
                'time'   => $timeLabel,
                'date'   => $row['preferred_date'],
                'notes'  => $row['notes'],
                'color'  => $color
            ]
        ];
    }
}

$weeklyTemplate = dcvsWeeklyTemplate($conn);
$blockedDays = [];
$overrideDays = [];
$calStart = date('Y-m-01');
$calEnd = date('Y-m-d', strtotime('+3 months'));
$ovrSet = $conn->query("SELECT DISTINCT slot_date FROM appointment_slots WHERE slot_date >= '$calStart' AND slot_date <= '$calEnd'");
if ($ovrSet) { while ($r = $ovrSet->fetch_assoc()) $overrideDays[$r['slot_date']] = true; }
$bCursor = $calStart;
while ($bCursor <= $calEnd) {
    $blockedTimes = dcvsBlockedTimes($conn, $bCursor, $weeklyTemplate);
    if (count($blockedTimes) >= count(DCVS_STANDARD_TIMES)) {
        $blockedDays[] = $bCursor;
    } elseif (isset($overrideDays[$bCursor])) {
        $calendarAppointments[] = [
            'id' => 'bg_ovr_' . str_replace('-', '', $bCursor),
            'start' => $bCursor,
            'display' => 'background',
            'backgroundColor' => '#fef9c3'
        ];
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

echo json_encode(['ok' => true, 'events' => $calendarAppointments], $jsonFlags);
$conn->close();