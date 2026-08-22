<?php
/**
 * Real-time poll endpoint — returns only data changed since lastPoll.
 * Called every 5-8 seconds from dashboard JS.
 */

// Open the same session the requesting portal uses (staff/barangay/admin use
// custom names; resident uses the default PHPSESSID). Falling back to PHPSESSID
// prevents a staff poll from silently reading a resident session.
$sessionWhitelist = ['PHPSESSID', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION', 'DCVS_ADMIN_SESSION'];
$sessParam = isset($_GET['sess']) ? trim($_GET['sess']) : '';
if (in_array($sessParam, $sessionWhitelist, true)) {
    session_name($sessParam);
}
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php';

$role     = $_SESSION['role'] ?? 'resident';
$userId   = (int)$_SESSION['user_id'];
$lastPoll = isset($_GET['since']) ? $_GET['since'] : date('Y-m-d H:i:s', time() - 30);
$barangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : null;

// Always respond
$response = [
    'server_time' => date('Y-m-d H:i:s'),
    'notifications' => [],
    'notif_unread_count' => 0,
    'appointments' => [],
    'reports' => [],
    'pets' => [],
    'activities' => [],
    'counts' => [],
];

// ── 1. Notifications (every role) ──
$nStmt = $conn->prepare("SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
    FROM notifications WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 20");
$nStmt->bind_param("is", $userId, $lastPoll);
$nStmt->execute();
$nr = $nStmt->get_result();
while ($row = $nr->fetch_assoc()) {
    $response['notifications'][] = $row;
}
$nStmt->close();

// Unread badge count — matches what each portal's dropdown actually shows.
// Resident bell lists only staff_message notifications; other portals show all
// visible notifications (excluding stale report_validated ones).
if ($role === 'resident') {
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'staff_message' AND is_read = 0");
    $ucStmt->bind_param("i", $userId);
} else {
    $ucStmt = $conn->prepare("SELECT COUNT(*) FROM notifications n
        LEFT JOIN reports r ON n.reference_type = 'report' AND n.reference_id = r.report_id
        WHERE n.user_id = ? AND n.is_read = 0
          AND NOT (n.type = 'report_validated' AND r.report_id IS NOT NULL AND r.status != 'validated')");
    $ucStmt->bind_param("i", $userId);
}
$ucStmt->execute();
$response['notif_unread_count'] = (int)$ucStmt->get_result()->fetch_row()[0];
$ucStmt->close();

// ── 2. Appointments ──
if ($role === 'resident') {
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.pet_id, a.notes, a.created_at,
        COALESCE(p.pet_name, '—') AS pet_name
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        WHERE a.user_id = ? AND a.archived_at IS NULL
        AND a.updated_at > ? ORDER BY a.appointment_id ASC");
    $aStmt->bind_param("is", $userId, $lastPoll);
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
} elseif ($role === 'staff' || $role === 'admin') {
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.created_at, a.updated_at, a.notes,
        u.user_id, u.reference_id AS user_ref, u.full_name, u.email, u.phone, u.address, u.avatar_url, p.pet_name, b.barangay_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE a.archived_at IS NULL AND a.updated_at > ? ORDER BY a.appointment_id ASC");
    $aStmt->bind_param("s", $lastPoll);
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
} elseif ($role === 'barangay') {
    $barWhere = $barangayId ? "AND (r.barangay_id = ? OR r.barangay_id IS NULL)" : "";
    $aStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date,
        a.preferred_time, a.status, a.created_at,
        u.full_name, p.pet_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN reports r ON r.reporter_id = a.user_id
        WHERE a.archived_at IS NULL AND a.updated_at > ? $barWhere ORDER BY a.appointment_id ASC");
    if ($barangayId) { $aStmt->bind_param("si", $lastPoll, $barangayId); }
    else { $aStmt->bind_param("s", $lastPoll); }
    $aStmt->execute();
    $ar = $aStmt->get_result();
    while ($row = $ar->fetch_assoc()) $response['appointments'][] = $row;
    $aStmt->close();
}

// ── 3. Reports ──
if ($role === 'resident') {
    $rStmt = $conn->prepare("SELECT report_id, reference_id, report_type, location_address, status, urgency_level, created_at, updated_at
        FROM reports WHERE reporter_id = ? AND archived_at IS NULL AND updated_at > ? ORDER BY created_at DESC");
    $rStmt->bind_param("is", $userId, $lastPoll);
    $rStmt->execute();
    $rr = $rStmt->get_result();
    while ($row = $rr->fetch_assoc()) $response['reports'][] = $row;
    $rStmt->close();
} elseif ($role === 'staff' || $role === 'admin') {
    $rStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.location_address, r.status,
        r.urgency_level, r.created_at, r.updated_at, u.full_name AS reporter_name,
        b.barangay_name FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE r.archived_at IS NULL AND r.updated_at > ? ORDER BY r.created_at DESC");
    $rStmt->bind_param("s", $lastPoll);
    $rStmt->execute();
    $rr = $rStmt->get_result();
    while ($row = $rr->fetch_assoc()) $response['reports'][] = $row;
    $rStmt->close();
} elseif ($role === 'barangay') {
    $barWhere = $barangayId ? "AND r.barangay_id = ?" : "";
    $rStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.location_address, r.status,
        r.urgency_level, r.created_at, r.updated_at, u.full_name AS reporter_name
        FROM reports r JOIN users u ON r.reporter_id = u.user_id
        WHERE r.archived_at IS NULL $barWhere AND r.updated_at > ? ORDER BY r.created_at DESC");
    if ($barangayId) { $rStmt->bind_param("is", $barangayId, $lastPoll); }
    else { $rStmt->bind_param("s", $lastPoll); }
    $rStmt->execute();
    $rr = $rStmt->get_result();
    while ($row = $rr->fetch_assoc()) $response['reports'][] = $row;
    $rStmt->close();
}

// ── 4. Pets (for residents) ──
if ($role === 'resident') {
    $pStmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, gender, age,
        vaccination_status, photo_url, created_at, updated_at,
        EXISTS(SELECT 1 FROM adoption_listings WHERE pet_id = p.pet_id) AS from_adoption
        FROM pets p WHERE owner_id = ? AND archived_at IS NULL AND updated_at > ? ORDER BY pet_id ASC");
    $pStmt->bind_param("is", $userId, $lastPoll);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($row = $pr->fetch_assoc()) {
        $row['from_adoption'] = (bool)$row['from_adoption'];
        $response['pets'][] = $row;
    }
    $pStmt->close();
}

// ── 5. Activity logs (for dashboard Recent Activities) ──
if ($role === 'resident') {
    $lStmt = $conn->prepare("SELECT log_id, action, details, created_at FROM activity_logs
        WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 10");
    $lStmt->bind_param("is", $userId, $lastPoll);
    $lStmt->execute();
    $lr = $lStmt->get_result();
    while ($row = $lr->fetch_assoc()) $response['activities'][] = $row;
    $lStmt->close();
}

// ── 6. Summary counts (for KPI cards) ──
$counts = [];

if ($role === 'resident') {
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM pets WHERE owner_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['pets'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();

    $cStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['reports'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();

    $cStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND archived_at IS NULL");
    $cStmt->bind_param("i", $userId); $cStmt->execute();
    $counts['appointments'] = (int)$cStmt->get_result()->fetch_row()[0]; $cStmt->close();
}

if ($role === 'staff' || $role === 'admin') {
    $cStmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active'");
    $counts['residents'] = (int)$cStmt->fetch_row()[0];
    $counts['active_residents'] = $counts['residents'];
    $cStmt = $conn->query("SELECT COUNT(*) FROM pets WHERE archived_at IS NULL");
    $counts['pets'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL");
    $counts['reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE report_type = 'rabies' AND status IN ('pending','validated') AND archived_at IS NULL");
    $counts['rabies'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'pending'");
    $counts['pending_reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'validated'");
    $counts['validated_reports'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM appointments WHERE archived_at IS NULL AND status = 'pending'");
    $counts['pending_appointments'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM confirmed_cases WHERE status = 'active'");
    $counts['active_cases'] = (int)$cStmt->fetch_row()[0];
}

if ($role === 'barangay') {
    $barWhere = $barangayId ? "AND barangay_id = $barangayId" : "";
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'pending' $barWhere");
    $counts['pending'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'validated' $barWhere");
    $counts['validated'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'confirmed' $barWhere");
    $counts['confirmed'] = (int)$cStmt->fetch_row()[0];
    $cStmt = $conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL AND status = 'rejected' $barWhere");
    $counts['rejected'] = (int)$cStmt->fetch_row()[0];
}

$response['counts'] = $counts;
echo json_encode($response);
