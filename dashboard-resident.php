<?php
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

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Check if user has resident role
if ($_SESSION['role'] !== 'resident') {
    header('Location: login.php');
    exit;
}

// Adoption flow � check if user came from "Adopt Me" on petlist.php
$showAdoptModal = $_SESSION['show_adopt_modal'] ?? false;
$adoptData = $_SESSION['adopt_data'] ?? null;
$adoptListingId = $_SESSION['adopt_listing_id'] ?? null;
unset($_SESSION['show_adopt_modal'], $_SESSION['adopt_data'], $_SESSION['adopt_listing_id']);

// Handle password change messages
$passwordError = $_SESSION['password_error'] ?? null;
$passwordSuccess = $_SESSION['password_success'] ?? null;
unset($_SESSION['password_error']);
unset($_SESSION['password_success']);

// Handle other operation messages
$petError = $_SESSION['pet_error'] ?? null;
$petSuccess = $_SESSION['pet_success'] ?? null;
$reportError = $_SESSION['report_error'] ?? null;
$reportSuccess = $_SESSION['report_success'] ?? null;
$appointmentError = $_SESSION['appointment_error'] ?? null;
$appointmentSuccess = $_SESSION['appointment_success'] ?? null;
$profileError = $_SESSION['profile_error'] ?? null;
$profileSuccess = $_SESSION['profile_success'] ?? null;
unset($_SESSION['pet_error'], $_SESSION['pet_success']);
unset($_SESSION['report_error'], $_SESSION['report_success']);
unset($_SESSION['appointment_error'], $_SESSION['appointment_success']);
unset($_SESSION['profile_error'], $_SESSION['profile_success']);

// Fetch user's pets from database (excluding archived)
$pets = [];
$stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, created_at, EXISTS(SELECT 1 FROM adoption_listings WHERE pet_id = p.pet_id) AS from_adoption, (SELECT DATE(COALESCE(NULLIF(al.updated_at,''), al.created_at)) FROM adoption_listings al WHERE al.pet_id = p.pet_id ORDER BY al.updated_at DESC LIMIT 1) AS adopted_date FROM pets p WHERE owner_id = ? AND archived_at IS NULL ORDER BY pet_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pets[] = $row;
}
$stmt->close();

// Fetch archived pets
$archivedPets = [];
$stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, created_at, archived_at, photo_url FROM pets WHERE owner_id = ? AND archived_at IS NOT NULL ORDER BY pet_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivedPets[] = $row;
}
$stmt->close();

// Fetch user's reports from database (excluding archived)
$reports = [];
$stmt = $conn->prepare("SELECT report_id, reference_id, report_type, description, location_address, status, created_at, updated_at, photo_url, urgency_level FROM reports WHERE reporter_id = ? AND archived_at IS NULL ORDER BY report_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// Fetch archived reports
$archivedReports = [];
$stmt = $conn->prepare("SELECT report_id, reference_id, report_type, description, location_address, status, created_at, archived_at, photo_url FROM reports WHERE reporter_id = ? AND archived_at IS NOT NULL ORDER BY report_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivedReports[] = $row;
}
$stmt->close();

// Fetch user's appointments from database (excluding archived)
$appointments = [];
$stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.pet_id, a.notes, a.created_at, COALESCE(p.pet_name, '-') AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NULL ORDER BY a.appointment_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

// Fetch archived appointments
$archivedAppointments = [];
$stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.archived_at, COALESCE(p.pet_name, '-') AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NOT NULL ORDER BY a.appointment_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivedAppointments[] = $row;
}
$stmt->close();

// Fetch monthly report counts for chart (current year, by month)
$currentYear = (int)date('Y');
$monthlyCounts = array_fill(1, 12, 0);
$mcq = $conn->prepare("SELECT MONTH(created_at) AS m, COUNT(*) AS c FROM reports WHERE reporter_id = ? AND YEAR(created_at) = ? AND archived_at IS NULL GROUP BY MONTH(created_at)");
$mcq->bind_param("ii", $_SESSION['user_id'], $currentYear);
$mcq->execute();
$mcr = $mcq->get_result();
while ($row = $mcr->fetch_assoc()) $monthlyCounts[(int)$row['m']] = (int)$row['c'];
$mcq->close();

// Fetch category counts for chart
$categoryCounts = ['stray' => 0, 'rabies' => 0, 'injured' => 0, 'dead' => 0];
$ccq = $conn->prepare("SELECT report_type, COUNT(*) AS c FROM reports WHERE reporter_id = ? AND archived_at IS NULL GROUP BY report_type");
$ccq->bind_param("i", $_SESSION['user_id']);
$ccq->execute();
$ccr = $ccq->get_result();
while ($row = $ccr->fetch_assoc()) {
    $t = strtolower($row['report_type']);
    if (isset($categoryCounts[$t])) $categoryCounts[$t] = (int)$row['c'];
}
$ccq->close();

// Calculate active cases (pending + validated reports)
$activeCases = count(array_filter($reports, fn($r) => $r['status'] === 'pending' || $r['status'] === 'validated'));

// Calculate upcoming appointments (future appointments)
$upcomingAppointments = array_filter($appointments, fn($a) => strtotime($a['preferred_date']) >= strtotime('today'));

// Get barangay name from address (format: "street, barangay, Dasmari&ntilde;as, Cavite")
$address = $_SESSION['address'] ?? '';
$barangay = 'Unknown';
if (strpos($address, ',') !== false) {
    $addressParts = explode(',', $address);
    if (count($addressParts) >= 2) {
        $barangay = trim($addressParts[1]);
    }
}

// Get resident reference_id from database
$residentId = '#RES-' . date('Y') . '-' . str_pad($_SESSION['user_id'], 3, '0', STR_PAD_LEFT);
$refStmt = $conn->prepare("SELECT reference_id FROM users WHERE user_id = ?");
$refStmt->bind_param("i", $_SESSION['user_id']);
$refStmt->execute();
$refResult = $refStmt->get_result();
if ($refRow = $refResult->fetch_assoc()) {
    $residentId = $refRow['reference_id'] ?? $residentId;
}
$refStmt->close();

// Get user's barangay name from database if barangay_id exists
$barangayName = $barangay;
if (!empty($_SESSION['barangay_id'])) {
    $stmt = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
    $stmt->bind_param("i", $_SESSION['barangay_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $barangayName = $row['barangay_name'];
    }
    $stmt->close();
}

// Parse full name into first and last name
$fullName = $_SESSION['full_name'] ?? '';
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0] ?? '';
$lastName = $nameParts[1] ?? '';
$pageTitle = 'Dashboard';
$activePage = 'overview';

// Fetch additional user info for account settings (direct from DB)
$userDOB = '';
$userGender = '';
$userStreet = '';
$userEmailDb = $_SESSION['email'] ?? '';
$userPhoneDb = $_SESSION['phone'] ?? '';
$userFullNameDb = $_SESSION['full_name'] ?? '';
$stmt = $conn->prepare("SELECT birthdate, gender, address, full_name, email, phone FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $userDOB = $row['birthdate'] ?? '';
    $userGender = $row['gender'] ?? '';
    $address = $row['address'] ?? $_SESSION['address'] ?? '';
    $addrParts = explode(',', $address);
    $userStreet = trim($addrParts[0] ?? '');
    $userEmailDb = $row['email'] ?? $userEmailDb;
    $userPhoneDb = $row['phone'] ?? $userPhoneDb;
    $userFullNameDb = $row['full_name'] ?? $userFullNameDb;
}
$stmt->close();

// Fetch resident's own adoption listings with appointment status
$adoptionListings = [];
$userId = $_SESSION['user_id'];
$alq = $conn->prepare("
    SELECT al.*, p.pet_name, p.pet_type, p.breed, p.age, p.gender, p.color, p.weight, p.photo_url, p.reference_id AS pet_ref_id,
           pl.description AS source_description,
           (SELECT status FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_status,
           (SELECT preferred_date FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_date,
           (SELECT reference_id FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_ref,
           (SELECT appointment_id FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_id
    FROM adoption_listings al
    JOIN pets p ON al.pet_id = p.pet_id
    LEFT JOIN pet_listings pl ON LOWER(pl.name) = LOWER(p.pet_name)
    WHERE al.listed_by = ? AND al.status IN ('available','pending','adopted')
    ORDER BY FIELD(al.status, 'pending', 'available', 'adopted'), al.created_at DESC
");
$alq->bind_param("i", $userId);
$alq->execute();
$alResult = $alq->get_result();
while ($row = $alResult->fetch_assoc()) $adoptionListings[] = $row;
$alq->close();

// ?? New Dashboard Queries ??

// Today's appointments (for the timeline)
$todayAppts = [];
 $taStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.pet_id, a.notes, a.created_at, COALESCE(p.pet_name, '-') AS pet_name, COALESCE(p.photo_url, '') AS pet_photo, COALESCE(p.breed, '') AS pet_breed FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NULL AND LOWER(a.status) = 'pending' ORDER BY a.preferred_date ASC, a.preferred_time ASC LIMIT 3");
$todayDate = date('Y-m-d');
 $taStmt->bind_param("i", $_SESSION['user_id']);
$taStmt->execute();
$taResult = $taStmt->get_result();
 while ($row = $taResult->fetch_assoc()) $todayAppts[] = $row;
 $taStmt->close();

 // Fall back to the already-loaded appointments so pending records with incomplete
 // or differently formatted dates are still visible on the resident dashboard.
 if (empty($todayAppts)) {
     foreach ($appointments as $appointment) {
         $status = strtolower((string)$appointment['status']);
         if ($status === 'pending') {
             $appointment['pet_photo'] = '';
             $appointment['pet_breed'] = '';
             foreach ($pets as $pet) {
                 if ((int)$pet['pet_id'] === (int)$appointment['pet_id']) {
                     $appointment['pet_photo'] = $pet['photo_url'] ?? '';
                     $appointment['pet_breed'] = $pet['breed'] ?? '';
                     break;
                 }
             }
             $todayAppts[] = $appointment;
         }
     }
     usort($todayAppts, function ($a, $b) {
         return strtotime(($a['preferred_date'] ?? '') . ' ' . ($a['preferred_time'] ?? '')) <=> strtotime(($b['preferred_date'] ?? '') . ' ' . ($b['preferred_time'] ?? ''));
     });
     $todayAppts = array_slice($todayAppts, 0, 3);
 }

 // Upcoming appointments (next 30 days, for timeline fallback)
$upcomingAppts = [];
$uaStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.pet_id, a.notes, a.created_at, COALESCE(p.pet_name, '-') AS pet_name, COALESCE(p.photo_url, '') AS pet_photo, COALESCE(p.breed, '') AS pet_breed FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.preferred_date >= ? AND a.preferred_date <= DATE_ADD(?, INTERVAL 30 DAY) AND a.archived_at IS NULL ORDER BY a.preferred_date ASC, a.preferred_time ASC");
$uaStmt->bind_param("iss", $_SESSION['user_id'], $todayDate, $todayDate);
$uaStmt->execute();
$uaResult = $uaStmt->get_result();
while ($row = $uaResult->fetch_assoc()) $upcomingAppts[] = $row;
$uaStmt->close();

// Active treatments count (appointments with status scheduled/in_progress)
$activeTreatments = 0;
$atStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND status IN ('scheduled','in_progress') AND archived_at IS NULL");
$atStmt->bind_param("i", $_SESSION['user_id']);
$atStmt->execute();
$activeTreatments = (int)$atStmt->get_result()->fetch_row()[0];
$atStmt->close();

// Reports submitted this month
$reportsThisMonth = 0;
$rtmStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND archived_at IS NULL");
$rtmStmt->bind_param("i", $_SESSION['user_id']);
$rtmStmt->execute();
$reportsThisMonth = (int)$rtmStmt->get_result()->fetch_row()[0];
$rtmStmt->close();

// Upcoming appointments count (today onward)
$upcomingApptCount = count(array_filter($appointments, fn($a) => strtotime($a['preferred_date']) >= strtotime('today') && $a['status'] !== 'completed' && $a['status'] !== 'cancelled'));

// Recent activity logs
$recentActivities = [];
$actStmt = $conn->prepare("SELECT log_id, action, details, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$actStmt->bind_param("i", $_SESSION['user_id']);
$actStmt->execute();
$actResult = $actStmt->get_result();
while ($row = $actResult->fetch_assoc()) $recentActivities[] = $row;
$actStmt->close();

// Published announcements
$announcements = [];
$annStmt = $conn->prepare("SELECT announcement_id, title, content, category, created_at FROM announcements WHERE status = 'published' AND is_active = 1 ORDER BY created_at DESC LIMIT 3");
$annStmt->execute();
$annResult = $annStmt->get_result();
 while ($row = $annResult->fetch_assoc()) $announcements[] = $row;
 $annStmt->close();

 // The sidebar is rendered after the page content, so load the header badge here too.
 $dashboardUnreadCount = 0;
 $badgeStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'staff_message' AND is_read = 0");
 if ($badgeStmt) {
     $badgeStmt->bind_param("i", $_SESSION['user_id']);
     $badgeStmt->execute();
     $dashboardUnreadCount = (int)$badgeStmt->get_result()->fetch_row()[0];
     $badgeStmt->close();
 }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Resident Dashboard - Dasmari&ntilde;as City Veterinary Services Animal Welfare System" />
  <title><?php echo htmlspecialchars($pageTitle); ?> - Resident | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
    .page-title { font-size:1.5rem; font-weight:600; color:#18181b; margin:0; font-family:'Inter','Segoe UI',sans-serif; letter-spacing:-0.025em; }
    .page-subtitle { font-size:0.9rem; color:#71717a; margin:4px 0 0; font-family:'Inter','Segoe UI',sans-serif; }
    .header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .dashboard-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .card-header h3 { font-size:1rem; font-weight:700; color:#0f172a; margin:0; }
    .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.75rem; }
    .kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .kpi-label { font-size:0.78rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.03em; }
    .kpi-value { font-size:1.75rem; font-weight:800; color:#0f172a; font-family:'Montserrat',sans-serif; margin-top:4px; }
    .table-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.5rem; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .table-header-actions { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:1rem; }
    .btn-sm { padding:6px 14px; font-size:0.78rem; border-radius:6px; }
    .btn-danger { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:9px; border:none; background:#dc2626; color:#fff; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.2s; }
    .btn-danger:hover { background:#b91c1c; }
    .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:1.25rem; flex-wrap:wrap; }
    .modal-form { }
    .modal-notice { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; font-size:0.82rem; color:#065f46; margin-bottom:1rem; }
    .nav-link-text { }
    .sidebar-brand-text { }
    .settings-layout { display:grid; gap:1.5rem; max-width:1100px; margin:0 auto; width:100%; box-sizing:border-box; }
    .profile-card-full { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:2rem 2.5rem; box-shadow:0 4px 24px rgba(0,0,0,0.06); }
    .settings-section { margin-bottom:2rem; }
    .settings-section-title { font-size:1rem; font-weight:700; color:#0f172a; margin-bottom:1.25rem; display:flex; align-items:center; gap:8px; }
    .settings-section-title i { color:#045e1f; }
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
    .settings-actions { display:flex; gap:12px; margin-top:1.5rem; flex-wrap:wrap; }
    .settings-form { }
    .form-group { display:flex; flex-direction:column; gap:6px; }
    .form-group-full { grid-column:1 / -1; }
    .form-group label { font-size:0.82rem; font-weight:600; color:#334155; }
    .tabs-wrap { display:flex; gap:4px; background:#f1f5f9; padding:4px; border-radius:10px; margin-bottom:1.25rem; overflow-x:auto; }
    .tab-btn { padding:8px 18px; border:none; border-radius:8px; background:transparent; color:#64748b; font-weight:600; font-size:0.82rem; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
    .tab-btn.active { background:#fff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.08); }
    .tab-btn:hover:not(.active) { color:#334155; }
    .tab-content { }
    .pets-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem; }
    .pet-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; transition:all 0.2s; }
    .pet-card:hover { box-shadow:0 8px 32px rgba(0,0,0,0.08); transform:translateY(-2px); }
    .pet-card-photo { width:100%; height:180px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .pet-card-photo img { width:100%; height:100%; object-fit:cover; }
    .pet-card-no-photo { width:100%; height:180px; background:linear-gradient(135deg,#045e1f,#043012); display:flex; align-items:center; justify-content:center; }
    .pet-card-no-photo span { font-size:3rem; color:#fff; font-weight:800; }
    .pet-card-body { padding:1rem 1.25rem; }
    .pet-card-name { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0 0 4px; }
    .pet-card-details { font-size:0.82rem; color:#64748b; }
    .appointments-list { display:flex; flex-direction:column; gap:0.75rem; }
    .appointment-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.25rem; display:flex; align-items:center; gap:1rem; transition:all 0.2s; }
    .appointment-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.06); }
    .appointment-content { flex:1; }
    .appointment-title { font-size:0.88rem; font-weight:700; color:#0f172a; }
    .appointment-time { font-size:0.78rem; color:#64748b; margin-top:2px; }
    .appointment-list { padding: 0.5rem 1.5rem 1.25rem; display: flex; flex-direction: column; gap: 0.25rem; }
    .appointment-item { display:flex; align-items:center; gap:0.85rem; padding:0.75rem 0.85rem; border-bottom:1px solid #f1f5f9; border-radius:8px; }
    .appointment-item:last-child { border-bottom:none; }
    .appointment-item:hover { background:#fafbfc; }
    .appt-icon { width:36px; height:36px; border-radius:10px; background:#f0fdf4; color:#045e1f; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .appt-info { flex:1; }
    .appt-type { font-size:0.82rem; font-weight:600; color:#0f172a; }
    .appt-datetime { font-size:0.75rem; color:#64748b; }
    .activity-timeline { padding: 0.75rem 1.5rem 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; }
    .timeline-item { display:flex; gap:1rem; padding:0.85rem 0; border-bottom:1px solid #f1f5f9; align-items:flex-start; }
    .timeline-item:last-child { border-bottom:none; }
    .timeline-icon { width:32px; height:32px; border-radius:50%; background:#f0fdf4; color:#045e1f; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0; }
    .help-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:0.85rem; }
    .help-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.1rem 1.25rem; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .help-title { font-size:0.9rem; font-weight:700; color:#0f172a; }
    .help-desc { font-size:0.78rem; color:#64748b; margin-top:1px; }
    .timeline-content { flex:1; }
.timeline-title  { font-size:0.82rem; font-weight:600; color:#0f172a; }
.timeline-subtitle { font-size:0.75rem; color:#64748b; }
.timeline-date   { font-size:0.68rem; color:#94a3b8; margin-top:2px; }
    .date-badge { width:48px; text-align:center; background:var(--green); border-radius:8px; padding:6px 0; }
    .date-badge-day { display:block; font-size:1.1rem; font-weight:800; color:#ffffff; line-height:1; }
    .date-badge-month { display:block; font-size:0.6rem; font-weight:700; color:#ffffff; text-transform:uppercase; margin-top:2px; }
    .no-data { text-align:center; padding:2rem; color:#94a3b8; font-size:0.85rem; }
    .no-activity { text-align:center; padding:2rem; color:#94a3b8; font-size:0.85rem; }

    .view-all { font-size:0.78rem; color:#045e1f; font-weight:600; cursor:pointer; }
    .view-all:hover { text-decoration:underline; }
    .archive-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem; }
    .archive-title { font-size:1.1rem; font-weight:600; color:#18181b; margin:0; font-family:'Inter','Segoe UI',sans-serif; letter-spacing:-0.01em; }
    .archive-section { margin-top:0; padding-top:0; border-top:none; }
    .archive-section h2 { font-size:1.1rem; font-weight:600; color:#18181b; margin:0 0 1rem 0; font-family:'Inter','Segoe UI',sans-serif; letter-spacing:-0.01em; }
    #archive .appt-table { font-family:'Inter','Segoe UI',sans-serif; }
    .archive-empty { text-align:center; padding:2.5rem 1rem; color:#71717a; font-family:'Inter','Segoe UI',sans-serif; }
    .archive-empty i { font-size:1.75rem; color:#d4d4d8; display:block; margin-bottom:0.6rem; }
    .archive-empty p { margin:0; font-size:0.9rem; }

    .compact-list { padding: 0.5rem 1.5rem 1.25rem; display: flex; flex-direction: column; gap: 0.25rem; }
    .compact-item { display:flex; align-items:center; gap:0.85rem; padding:0.75rem 0.85rem; border-bottom:1px solid #f1f5f9; border-radius:8px; }
    .compact-item:last-child { border-bottom:none; }
    .compact-item:hover { background:#fafbfc; }
    .compact-id { font-family:monospace; font-size:0.72rem; color:#94a3b8; }
    .compact-title { font-size:0.85rem; font-weight:600; color:#0f172a; flex:1; }
    .compact-main { flex:1; }
    .filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:1rem; }
    .search-box { display:inline-flex; align-items:center; background:#fff; border:1px solid #e4e4e7; border-radius:6px; padding:0 12px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); transition:border-color 0.15s,box-shadow 0.15s; }
    .search-box:focus-within { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .search-box i { color:#71717a; font-size:0.85rem; }
    .search-box input { border:none; background:transparent; padding:8px 10px; font-size:0.85rem; color:#18181b; outline:none; width:100%; min-width:180px; font-family:'Inter','Segoe UI',sans-serif; }
    .search-box input::placeholder { color:#71717a; }
    .filter-select { padding:8px 14px; border-radius:6px; border:1px solid #e4e4e7; font-size:0.85rem; background:#fff; color:#18181b; cursor:pointer; transition:border-color 0.15s; font-family:'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    .filter-select:focus { border-color:#18181b; outline:none; box-shadow:0 0 0 1px #18181b; }
    .action-btn-sm { padding:6px 14px; border-radius:6px; border:none; font-size:0.78rem; font-weight:600; cursor:pointer; transition:all 0.15s; display:inline-flex; align-items:center; gap:6px; }
    .action-btn-sm.green { background:#f0fdf4; color:var(--green); } .action-btn-sm.green:hover { background:#dcfce7; }
    .action-btn-sm.red { background:#fef2f2; color:#dc2626; } .action-btn-sm.red:hover { background:#fee2e2; }
    .action-btn-sm.blue { background:#eff6ff; color:#2563eb; } .action-btn-sm.blue:hover { background:#dbeafe; }
    .action-btn-sm.gray { background:#f1f5f9; color:#475569; } .action-btn-sm.gray:hover { background:#e2e8f0; }
    .action-btn-sm i { font-size:0.75rem; }
    .section-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem; }
    .section-header h2 { font-size:1.1rem; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px; }
    .section-header h2 i { color:var(--green); }

    .table-count-badge { color:#64748b; font-weight:600; font-size:0.85rem; }
    .dashboard-grid-2col { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; }
    .form-control { width:100%; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .form-control:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    .form-group-full { }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .profile-header-banner { background:linear-gradient(135deg,#045e1f,#043012); padding:2rem; border-radius:12px; text-align:center; color:#fff; margin-bottom:1.5rem; }
    .profile-avatar-lg { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid rgba(255,255,255,0.3); }
    .profile-header-avatar-wrapper { position:relative; cursor:pointer; flex-shrink:0; }
    .profile-header-info { margin-top:0.75rem; }
    .profile-id-badge { display:inline-block; background:rgba(255,255,255,0.15); padding:4px 12px; border-radius:999px; font-size:0.75rem; }
    .profile-dropdown { position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.15); padding:6px; display:none; z-index:100; min-width:160px; }
    .profile-dropdown.open { display:block; }
    .profile-dropdown .dropdown-item { display:flex; align-items:center; gap:10px; padding:8px 12px; border:none; border-radius:6px; background:none; font-size:0.82rem; font-weight:500; color:#475569; cursor:pointer; transition:all 0.1s; width:100%; text-align:left; white-space:nowrap; }
    .profile-dropdown .dropdown-item:hover { background:#f8fafc; color:#0f172a; }
    .profile-dropdown .dropdown-item i { width:16px; text-align:center; color:#94a3b8; }
    .profile-photo-view { padding:1rem; }
    .profile-photo-view img { max-width:100%; height:auto; border-radius:12px; display:block; margin:0 auto; }
    .no-profile-photo { text-align:center; padding:1.5rem; color:#94a3b8; }
    .no-profile-photo i { font-size:3rem; display:block; margin-bottom:0.5rem; }

    /* �"?�"? Adoption Listings (premium CRM record design) �"?�"? */
    .adopt-wrap { box-sizing:border-box; }
    .adopt-toolbar { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:24px; }
    .adopt-pills { display:flex; gap:12px; flex-wrap:wrap; }
    .adopt-pill { display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; border-radius:999px; background:#fff; border:1px solid #E5E7EB; color:#374151; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .adopt-pill:hover { border-color:#0E8131; color:#0E8131; }
    .adopt-pill.active { background:#0E8131; border-color:#0E8131; color:#fff; box-shadow:0 4px 12px rgba(14,129,49,0.25); }
    .adopt-tools { display:flex; gap:12px; align-items:center; }
    .adopt-search { position:relative; }
    .adopt-search i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#94a3af; font-size:0.85rem; }
    .adopt-search input { padding:9px 14px 9px 38px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:0.88rem; outline:none; width:220px; background:#f8fafc; color:#334155; transition:all 0.2s; box-sizing:border-box; }
    .adopt-search input:focus { border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,0.1); }
    .adopt-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:24px; margin-bottom:32px; }
    .adopt-card { display:flex; flex-direction:column; background:#fff; border:1px solid #E5E7EB; border-radius:18px; padding:20px; box-shadow:0 1px 2px rgba(16,24,40,0.04),0 4px 12px rgba(16,24,40,0.03); box-sizing:border-box; }
    .adopt-badges { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .adopt-ref-badge { display:inline-flex; align-items:center; gap:6px; background:#F3F4F6; color:#4B5563; font-family:'IBM Plex Mono',monospace; font-size:12px; font-weight:600; padding:5px 11px; border-radius:7px; }
    .adopt-status-badge { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:20px; font-family:'Inter','Segoe UI',sans-serif; white-space:nowrap; }
    .adopt-status-badge.pending { background:#fffbeb; color:#b45309; }
    .adopt-status-badge.available { background:var(--green-100); color:var(--green-700); }
    .adopt-status-badge.adopted { background:#eff6ff; color:#1d4ed8; }
    .adopt-status-badge.cancelled { background:#fef2f2; color:#b91c1c; }
    .adopt-split { display:flex; gap:20px; flex:1; min-height:0; }
    .adopt-media { flex:0 0 38%; }
    .adopt-media img { width:100%; aspect-ratio:320/260; object-fit:cover; border-radius:16px; display:block; }
    .adopt-media-plain { width:100%; aspect-ratio:320/260; border-radius:16px; background:#F3F4F6; display:flex; align-items:center; justify-content:center; color:#9CA3AF; font-size:14px; font-weight:600; font-family:'IBM Plex Mono',monospace; text-align:center; }
    .adopt-info { flex:1; display:flex; flex-direction:column; min-width:0; }
    .adopt-name { font-size:36px; font-weight:800; color:#111827; letter-spacing:-0.02em; line-height:1.05; margin:0; }
    .adopt-breed { font-size:13px; font-weight:600; color:#6B7280; text-transform:uppercase; letter-spacing:.08em; margin:8px 0 0; }
    .adopt-desc { font-size:14px; line-height:1.5; color:#6B7280; margin:12px 0 0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .adopt-divider { border:0; border-top:1px solid #E5E7EB; margin:16px 0; }
    .adopt-info-grid { display:grid; grid-template-columns:1fr 1fr; row-gap:16px; column-gap:28px; }
    .adopt-info-item { display:flex; align-items:center; gap:10px; min-width:0; }
    .adopt-info-item .icon { width:20px; flex-shrink:0; color:#9CA3AF; display:flex; align-items:center; justify-content:center; font-size:14px; }
    .adopt-info-item .ic-label { font-size:11px; font-weight:500; color:#9CA3AF; text-transform:uppercase; letter-spacing:.04em; }
    .adopt-info-item .ic-value { font-size:13px; font-weight:700; color:#111827; }
    .adopt-actions { display:flex; align-items:center; margin-top:auto; padding-top:16px; }
    .adopt-status-btn { display:inline-flex; align-items:center; gap:8px; height:40px; padding:0 18px; border-radius:10px; font-size:13px; font-weight:700; border:1.5px solid transparent; cursor:default; white-space:nowrap; box-sizing:border-box; }
    .adopt-status-btn.pending { background:#FFF7ED; border-color:#FED7AA; color:#C2540B; }
    .adopt-status-btn.adopted { background:#F3F4F6; border-color:#E5E7EB; color:#6B7280; }
    .adopt-status-btn.available { background:#fff; border-color:#0E8131; color:#0E8131; cursor:pointer; }
    .adopt-status-btn.available:hover { background:#0E8131; color:#fff; }
    .adopt-status-btn i { font-size:13px; }
    .adopt-footer { text-align:center; padding:24px 0 8px; }
    .adopt-footer-icon { width:72px; height:72px; margin:12px auto 18px; border-radius:50%; border:2px solid #0E8131; color:#0E8131; display:flex; align-items:center; justify-content:center; font-size:28px; }
    .adopt-footer h3 { font-size:22px; font-weight:800; color:#111827; margin:0; }
    .adopt-footer p { font-size:15px; color:#6B7280; margin:8px 0 0; }
    .adopt-empty { text-align:center; color:#6B7280; padding:3rem 1rem; grid-column:1 / -1; }
    @media (max-width:1000px) { .adopt-grid { grid-template-columns:1fr; } .adopt-split { flex-direction:column; } .adopt-media { flex:auto; } .adopt-media img, .adopt-media-plain { aspect-ratio:16/9; } }
    @media (max-width:560px) { .adopt-search input { width:100%; } .adopt-info-grid { grid-template-columns:1fr; } }
    .modal-body .form-group input,
    .modal-body .form-group select,
    .modal-body .form-group textarea { width:100%; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .modal-body .form-group input:focus,
    .modal-body .form-group select:focus,
    .modal-body .form-group textarea:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    .dashboard-grid { display:grid; gap:1.5rem; }
    .status-legend { display:flex; gap:1rem; padding:0.5rem 0; }
    .charts-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.75rem; }
    .chart-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .chart-card h3 { font-size:0.92rem; font-weight:700; color:#0f172a; margin:0 0 1rem; display:flex; align-items:center; gap:8px; }
    .chart-card h3 i { color:var(--green); }
    .chart-card .chart-wrap { position:relative; height:220px; }
    .chart-card canvas { max-height:220px; }
    .chart-empty { text-align:center;padding:2rem;color:#94a3b8;font-size:0.85rem; }

    @media (max-width: 768px) {
      .charts-grid { grid-template-columns: 1fr; }
      .settings-grid { grid-template-columns: 1fr; }
      .form-row { grid-template-columns: 1fr; }
      .pets-grid { grid-template-columns: 1fr; }
    }
    .badge-category { font-size: 0.8rem; font-weight: 600; color: #475569; white-space: nowrap; }
    .view-all { font-size: 0.85rem; font-weight: 600; color: var(--green); text-decoration: none; transition: color 0.2s; }
    .view-all:hover { color: #084a1c; text-decoration: underline; }
/* �"?�"? Profile Settings (new layout) �"?�"? */
.profile-settings-wrapper * { box-sizing:border-box; }
.profile-settings-wrapper { max-width:680px; margin:0 auto; }
.profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
.profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
.profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
.profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; overflow:hidden; flex-shrink:0; background:#d9d9d9; cursor:pointer; }
.profile-settings-wrapper .ps-avatar img { width:100%; height:100%; object-fit:cover; }
.profile-settings-wrapper .ps-avatar-plain { width:56px; height:56px; border-radius:50%; background:#d9d9d9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#666; cursor:pointer; }
.profile-settings-wrapper .ps-profile-name { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
.profile-settings-wrapper .ps-profile-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; line-height:1.5; margin-top:2px; }
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
.profile-settings-wrapper .ps-actions-row { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
.profile-settings-wrapper .ps-btn { padding:9px 18px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; border-radius:8px; cursor:pointer; border:1px solid transparent; }
.profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
.profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
.profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
.profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
.profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
.profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
.profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
.profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
#account-settings { background:#fff; }

/* �"?�"? Dashboard Layout (corrected) �"?�"? */
:root{--card-bg:#ffffff;--text:#0f172a;--text-2:#475569;--text-3:#94a3b8;--border:#eef2f6;--border-2:#e2e8f0;--green:#045e1f;--shadow:0 1px 3px rgba(0,0,0,0.04);--text-main:var(--text);--text-secondary:var(--text-2);--text-muted:var(--text-3);--accent-blue:var(--green);--shadow-md:0 4px 6px rgba(0,0,0,0.05);}
.section-title{font-size:0.95rem;font-weight:700;color:var(--text);margin-bottom:0.85rem;display:flex;align-items:center;gap:0.5rem;font-family:'Montserrat',sans-serif;}
.title-wrapper{margin-bottom:1rem;}
.badge{background:var(--accent-blue);color:white;font-size:0.75rem;font-weight:600;padding:0.25rem 0.5rem;border-radius:6px;}
.card{background:var(--card-bg);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border-2);overflow:hidden;display:flex;flex-direction:column;}
.card-header{padding:1.5rem 1.5rem 0 1.5rem;}
.card-body{padding:1.5rem;flex:1;}
.quick-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;margin-bottom:2rem;}
.action-card{background:var(--card-bg);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border-2);padding:1.25rem;display:flex;align-items:center;gap:1rem;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s;}
.action-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
.action-icon{color:#000;width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.action-content h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:0.25rem;font-family:'Montserrat',sans-serif;}
.action-content p{font-size:0.85rem;color:var(--text-3);line-height:1.3;}
.dashboard-grid{display:grid;grid-template-columns:2fr 1fr;gap:2rem;}
.dash-main{display:flex;flex-direction:column;gap:2rem;height:100%;}
.dash-sidebar{display:flex;flex-direction:column;gap:2rem;height:100%;}
.top-row{display:grid;grid-template-columns:1fr 1.5fr;gap:2rem;}
.med-list{list-style:none;}
.med-item{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;border-bottom:1px solid var(--border-2);cursor:pointer;transition:background-color 0.2s;}
.med-item:first-child{border-top:1px solid var(--border-2);}
.med-item:hover{background:#f9fafb;}
.med-info{display:flex;align-items:flex-start;gap:0.75rem;min-width:0;flex:1;padding-right:1rem;}
.report-date-box{background:var(--green);color:#fff;border-radius:8px;width:44px;height:44px;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;line-height:1.1;}
.report-date-day{font-size:1.05rem;font-weight:700;}
.report-date-month{font-size:0.65rem;font-weight:700;text-transform:uppercase;}
.med-name{font-weight:600;font-size:0.95rem;color:var(--text);}
.med-desc{font-size:0.85rem;color:var(--text-3);margin-top:0.25rem;display:flex;align-items:center;gap:0.5rem;}
.med-footer{display:flex;justify-content:space-between;padding:1rem 1.5rem;font-size:0.82rem;}
.med-footer a{color:var(--text-main);text-decoration:none;font-weight:500;}
.med-footer a.action{color:var(--text-main);display:flex;align-items:center;gap:0.5rem;}

.calendar-widget{background:#fff;border-radius:12px;padding:1.5rem;display:flex;flex-direction:column;gap:1.5rem;height:100%;border:1px solid var(--border-2);box-shadow:var(--shadow);}
.calendar-header{display:flex;justify-content:space-between;align-items:center;}
.calendar-header h3{font-size:0.88rem;font-weight:700;color:var(--text);margin:0;font-family:'Montserrat',sans-serif;}
.calendar-nav{display:flex;gap:0.5rem;}
.calendar-nav-btn{width:32px;height:32px;border-radius:50%;border:1px solid var(--border-2);background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text);transition:background-color 0.2s;}
.calendar-nav-btn:hover{background:#f3f4f6;}
.calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:0.5rem;text-align:center;}
.calendar-day-name{font-size:0.7rem;font-weight:600;color:var(--text-3);margin-bottom:0.5rem;}
.calendar-date{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:0.82rem;font-weight:500;cursor:pointer;color:var(--text);background:#fff;transition:all 0.2s;margin:0 auto;position:relative;}
.calendar-date:hover{background:#f3f4f6;}
.calendar-date.muted{color:var(--text-3);background:transparent;}
.calendar-date.active{background:#fef08a;color:#854d0e;}
.calendar-date.marked{background:var(--text);color:#fff;}
.calendar-date.has-appointment{color:var(--green);background:#e8f5ec;}
.calendar-date.active.has-appointment{color:var(--green);background:#fef08a;}
.calendar-date.has-multiple::after{content:'';position:absolute;bottom:4px;left:50%;transform:translateX(-50%);width:4px;height:4px;background:var(--green);border-radius:50%;}
.calendar-date.active.has-multiple::after{background:#fff;}
.calendar-tooltip{position:absolute;bottom:110%;left:50%;transform:translateX(-50%);background:#1f2937;color:#fff;padding:0.5rem 0.75rem;border-radius:6px;font-size:0.75rem;white-space:nowrap;opacity:0;visibility:hidden;transition:opacity 0.2s,visibility 0.2s;z-index:20;pointer-events:none;box-shadow:0 4px 6px rgba(0,0,0,0.1);text-align:left;line-height:1.4;}
.calendar-tooltip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border-width:5px;border-style:solid;border-color:#1f2937 transparent transparent transparent;}
.calendar-date:hover .calendar-tooltip{opacity:1;visibility:visible;}
/* Dedicated page container */
.bk-page{max-width:960px;margin:0 auto;padding:32px 40px;font-family:'Inter','Segoe UI',sans-serif;}
.bk-card2{background:#fff;border:1px solid #e4e4e7;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);padding:28px 32px;font-family:'Inter','Segoe UI',sans-serif;}
@media (max-width:640px){ .bk-page{padding:16px 20px;} .bk-card2{padding:20px 18px;} }
/* Two-column body: LEFT = calendar, RIGHT = pet/service/time */
.bk-heading{font-family:'Inter','Segoe UI',sans-serif;}
.bk-heading-title{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.025em;line-height:1;margin:0;}
.bk-heading-sub{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;line-height:1.5;margin:6px 0 0;}
/* Two-column body: LEFT ~45% (calendar), RIGHT ~55% (fields) */
.bk-body{display:grid;grid-template-columns:minmax(0,45%) minmax(0,55%);gap:40px;align-items:start;margin-top:28px;}
.bk-col{min-width:0;}
.bk-col-left{display:flex;flex-direction:column;gap:8px;}
.bk-col-right{display:flex;flex-direction:column;gap:18px;}
@media (max-width:720px){ .bk-body{grid-template-columns:1fr;gap:24px;} }
/* Compact, professional form fields */
.bk-dropdowns{display:flex;flex-direction:column;gap:18px;}
.bk-field{display:flex;flex-direction:column;gap:8px;}
.bk-label{font-size:15px;color:#3f3f46;font-weight:600;}
.bk-label .req{color:#dc2626;}
.bk-select{position:relative;}
.bk-select select{width:100%;padding:0 16px;height:48px;background:#fff;border:1px solid #d7dbdf;border-radius:10px;font-family:inherit;font-size:15px;color:#18181b;appearance:none;-webkit-appearance:none;cursor:pointer;transition:border-color 0.15s,background 0.15s;box-sizing:border-box;}
.bk-select select:hover{border-color:#b9bec4;}
.bk-select select:focus{outline:none;border-color:var(--bk-primary);box-shadow:0 0 0 1px var(--bk-primary);}
.bk-select-chevron{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
/* Calendar */
.bk-cal-area{padding:0;max-width:390px;}
/* Pet profile (shown once a pet is selected) */
.bk-petprofile{padding:0;}
.bk-petprofile-filled{display:none;align-items:center;gap:14px;padding:14px 16px;border:1px solid #e4e4e7;border-radius:12px;background:#f4f4f5;}
.bk-petprofile-avatar{width:56px;height:56px;border-radius:12px;overflow:hidden;background:linear-gradient(135deg,#045e1f,#043012);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.bk-petprofile-avatar img{width:100%;height:100%;object-fit:cover;}
.bk-petprofile-info{flex:1;min-width:0;}
.bk-petprofile-eyebrow{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#18181b;margin:0 0 3px;}
.bk-petprofile-name{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:700;color:#18181b;letter-spacing:-0.02em;margin:0;text-transform:capitalize;}
.bk-petprofile-meta{font-size:14px;color:#71717a;margin:4px 0 0;}
/* Pet + appointment type selectors */
.bk-dropdowns{display:flex;flex-direction:column;gap:12px;padding:0 0 14px;}
.bk-field{display:flex;flex-direction:column;gap:8px;}
.bk-label{font-size:14px;color:#71717a;font-weight:500;}
.bk-label .req{color:#dc2626;}
.bk-select{position:relative;}
.bk-select select{width:100%;padding:12px 36px 12px 12px;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:10px;font-family:inherit;font-size:14px;color:#18181b;appearance:none;-webkit-appearance:none;cursor:pointer;transition:border-color 0.15s,background 0.15s;box-sizing:border-box;}
.bk-select select:focus{outline:none;border-color:#18181b;}
.bk-select-chevron{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
/* Calendar */
.bk-cal-area{padding:0;}
/* Booking primary (teal) - defined globally because the white card wrapper was removed */
:root{--bk-primary:#0d9488;--bk-primary-hover:#0f766e;--bk-primary-soft:#ccfbf1;--bk-fg:#18181b;--bk-muted:#71717a;--bk-border:#e4e4e7;}
.bk-cal{border:1px solid var(--bk-border);border-radius:12px;padding:14px;background:#fff;}
.bk-cal-nav{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:10px;}
.bk-cal-title{font-family:inherit;font-size:16px;font-weight:600;color:var(--bk-fg);min-width:120px;text-align:center;}
.bk-cal-btn{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:none;background:transparent;color:var(--bk-muted);cursor:pointer;transition:background 0.15s,color 0.15s;font-size:18px;}
.bk-cal-btn:hover{background:#f4f4f5;color:var(--bk-fg);}
.bk-cal-btn svg{width:18px;height:18px;}
.bk-weekdays{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));margin-bottom:6px;}
.bk-weekdays span{text-align:center;font-size:13px;font-weight:600;color:var(--bk-muted);height:34px;line-height:34px;text-transform:capitalize;}
.bk-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;}
/* Available date: readable dark text, clearly clickable - box shape */
.bk-day{position:relative;display:flex;align-items:center;justify-content:center;width:100%;max-width:42px;aspect-ratio:1/1;margin:0 auto;border-radius:8px;border:2px solid transparent;font-size:15px;font-weight:500;color:var(--bk-fg);background:transparent;cursor:pointer;transition:background 0.15s,color 0.15s,border-color 0.15s;}
.bk-day:hover:not(:disabled){background:#f4f4f5;color:#18181b;}
/* Today: black box outline */
.bk-day.bk-day-today{border-color:#18181b;color:#18181b;font-weight:700;}
.bk-day.bk-day-today:hover:not(:disabled){background:#f4f4f5;}
/* Selected: box filled black, white text */
.bk-day.bk-day-selected{background:#18181b;color:#fff;font-weight:700;border-color:#18181b;}
/* Past / fully booked: muted gray, disabled */
.bk-day.bk-day-disabled{color:#c9cdd3;cursor:not-allowed;background:transparent;opacity:0.7;}
/* Available Time section */
.bk-slots-wrap{display:flex;flex-direction:column;gap:0;margin-top:4px;}
.bk-slots-title{font-family:inherit;font-size:15px;font-weight:600;color:var(--bk-fg);margin-bottom:4px;}
.bk-slots-hint{font-size:13px;color:var(--bk-muted);margin-bottom:12px;line-height:1.4;}
.bk-cal-hint{font-size:14px;color:var(--bk-muted);margin-top:2px;line-height:1.4;}
.bk-slot-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.bk-slot-btn{width:100%;padding:12px 6px;border-radius:10px;border:1px solid var(--bk-border);background:#fff;font-family:inherit;font-size:14px;font-weight:600;color:var(--bk-fg);cursor:pointer;transition:background 0.15s,border-color 0.15s,color 0.15s;text-align:center;}
.bk-slot-btn:hover:not(:disabled){background:#f4f4f5;border-color:#d4d4d8;color:#18181b;}
.bk-slot-btn.bk-slot-selected{background:#18181b;color:#fff;border-color:#18181b;font-weight:700;}
.bk-slot-btn.bk-slot-unavailable,.bk-slot-btn:disabled{color:#a1a1aa;cursor:not-allowed;background:#f8f8f8;border-color:#ececec;font-weight:500;}
.bk-slot-none{grid-column:1 / -1;font-size:13px;color:#919aa5;padding:8px 2px;}
/* Bottom actions */
.bk-actions{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:28px;padding-top:20px;border-top:1px solid #eef2f6;}
.bk-btn-ghost{height:42px;padding:0 20px;border-radius:10px;border:1px solid #e4e4e7;background:#fff;color:#525252;font-family:inherit;font-size:14px;font-weight:500;cursor:pointer;transition:background 0.15s,border-color 0.15s;}
.bk-btn-ghost:hover{background:#f4f4f5;}
.bk-btn-primary{height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 24px;border-radius:10px;border:none;background:var(--bk-primary);color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.15s;}
.bk-btn-primary:hover:not(:disabled){background:var(--bk-primary-hover);}
.bk-btn-primary:disabled{background:#d7dbdf;color:#8a9099;font-weight:500;cursor:not-allowed;}
.bk-next-arrow{display:inline-flex;font-size:16px;line-height:1;transition:transform 0.15s;}
.bk-btn-primary:hover:not(:disabled) .bk-next-arrow{transform:translateX(2px);}
/* Confirm booking dialog base styles */
.bk-confirm-section{display:flex;flex-direction:column;}
.bk-confirm-summary{display:flex;align-items:center;gap:12px;padding:10px 0;}
.bk-confirm-avatar{width:44px;height:44px;border-radius:8px;background:linear-gradient(135deg,#045e1f,#043012);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;overflow:hidden;flex-shrink:0;}
.bk-confirm-avatar img{width:100%;height:100%;object-fit:cover;}
.bk-confirm-summary h4{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;text-transform:capitalize;}
.bk-confirm-summary p{font-size:13px;color:#71717a;margin:2px 0 0;text-transform:capitalize;}
.bk-confirm-details{display:flex;flex-direction:column;border:1px solid #e4e4e7;border-radius:8px;background:#fff;padding:2px 14px;}
.bk-detail-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid #eef1f4;gap:16px;}
.bk-detail-row:last-child{border-bottom:none;}
.bk-detail-row span{font-size:14px;color:#71717a;flex-shrink:0;}
.bk-detail-row strong{font-size:14px;font-weight:500;color:#18181b;text-align:right;max-width:72%;}
/* Confirm Booking dialog (matches Register a Pet shadcn dialog) */
#confirmBookingModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#confirmBookingModal .sd-dialog { width:100%; display:flex; justify-content:center; }
#confirmBookingModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
#confirmBookingModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
#confirmBookingModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
#confirmBookingModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
#confirmBookingModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
#confirmBookingModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
#confirmBookingModal .sd-field-group { display:flex; flex-direction:column; gap:16px; }
#confirmBookingModal .sd-dialog-footer { display:flex; flex-direction:row-reverse; justify-content:flex-end; gap:8px; }
#confirmBookingModal .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; margin-bottom:8px; }
.appointment-list{display:flex;flex-direction:column;gap:1.25rem;margin-top:0.5rem;max-height:260px;overflow-y:auto;padding-right:0.5rem;}
.appointment-list::-webkit-scrollbar{width:6px;}
.appointment-list::-webkit-scrollbar-track{background:transparent;}
.appointment-list::-webkit-scrollbar-thumb{background-color:#e5e7eb;border-radius:10px;}
.appointment-list::-webkit-scrollbar-thumb:hover{background-color:#d1d5db;}
.appointment-item{display:flex;justify-content:space-between;align-items:center;}
.appointment-user{display:flex;align-items:center;gap:0.85rem;}
.appointment-avatar{width:44px;height:44px;border-radius:12px;object-fit:cover;}
.appointment-info h4{font-size:0.95rem;font-weight:600;color:var(--text);margin:0 0 0.15rem 0;}
.appointment-info p{font-size:0.8rem;color:var(--text-3);margin:0;}
.appointment-status{text-align:right;font-size:0.75rem;}
    .appt-status-pill{display:inline-block;font-size:0.68rem;font-weight:700;padding:2px 9px;border-radius:999px;letter-spacing:.02em;margin-bottom:0.15rem;text-transform:capitalize;}
.status-time{color:#4b5563;font-weight:400;}
.status-time-bold{color:var(--text);font-size:0.88rem;font-weight:600;display:block;margin-top:0.15rem;}
.claims-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;}
.claims-header h3{margin-bottom:0;}
.claims-header a{color:var(--text-2);font-size:0.78rem;text-decoration:underline;}
.claims-table{width:100%;border-collapse:collapse;font-size:0.85rem;}
.claims-table th{text-align:left;padding:0.85rem 1rem;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border-2);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;}
.claims-table td{padding:0.8rem 1rem;border-bottom:1px solid #f1f5f9;color:var(--text-2);}
.claims-table tr:last-child td{border-bottom:none;}
.provider-name{font-weight:600;color:var(--text);display:block;font-size:0.95rem;}
.provider-type{font-size:0.85rem;color:var(--text-3);}
.messages-list{padding:0.5rem 1.5rem 1.5rem;max-height:440px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:transparent transparent;transition:scrollbar-color 0.3s ease;}
.messages-list:hover{scrollbar-color:rgba(0,0,0,0.2) transparent;}
.messages-list::-webkit-scrollbar{width:6px;background:transparent;}
.messages-list::-webkit-scrollbar-thumb{background:transparent;border-radius:4px;}
.messages-list:hover::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.2);}
.messages-list::-webkit-scrollbar-track{background:transparent;}
.message-card{padding:1rem 0;border-bottom:1px solid var(--border-2);}
.message-card:last-child{border-bottom:none;}
.message-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem;}
.message-sender{display:flex;align-items:center;gap:0.75rem;}
.sender-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;}
.sender-info h4{font-size:0.95rem;font-weight:700;color:var(--text);font-family:'Montserrat',sans-serif;}
.sender-info p{font-size:0.85rem;color:var(--text-3);margin:0 0 2px;}
.message-subject{font-size:0.82rem;font-weight:600;color:var(--text-2);}
.message-type-label{font-size:0.75rem;font-weight:600;color:var(--green);text-transform:uppercase;letter-spacing:0.04em;}
.message-preview{font-size:0.9rem;color:var(--text-2);line-height:1.45;}
.message-preview a{color:var(--accent-blue);text-decoration:none;}
.insights-card{background:var(--card-bg);border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.04);border:1px solid var(--border-2);display:flex;flex-direction:column;flex:1;}
.insights-header{padding:1.25rem 1.25rem 1rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-2);}
.insights-title{font-size:0.95rem;font-weight:700;color:var(--text);margin:0;font-family:'Montserrat',sans-serif;}
.period-filter{font-size:0.75rem;font-weight:500;color:var(--text-secondary);background:#f3f4f6;padding:0.25rem 0.75rem;border-radius:20px;display:flex;align-items:center;gap:0.25rem;cursor:pointer;position:relative;}
.period-menu{display:none;position:absolute;top:100%;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.08);z-index:20;min-width:140px;margin-top:4px;}
.period-option{display:block;padding:0.5rem 1rem;font-size:0.78rem;color:#0f172a;text-decoration:none;border-bottom:1px solid #f1f5f9;}
.period-option:last-child{border-bottom:none;}
.period-option:hover{background:#f8fafc;}
.period-option.active{font-weight:600;color:#045e1f;}
.insights-body{padding:1.25rem;display:flex;flex-direction:column;gap:1.5rem;}
.insights-kpi-row{display:flex;align-items:baseline;gap:0.75rem;}
.insights-kpi{font-size:2rem;font-weight:800;color:var(--text);line-height:1;}
.insights-trend{font-size:0.85rem;font-weight:600;color:var(--green);background:#e8f5ec;padding:0.25rem 0.5rem;border-radius:6px;}
.sparkline-container{position:relative;width:100%;height:35px;margin-top:0.5rem;}
.insights-sparkline{width:100%;height:100%;display:block;}
.sparkline-overlay{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;}
.hit-target{position:absolute;top:0;width:20%;height:100%;transform:translateX(-50%);cursor:crosshair;z-index:10;}
.hit-target:nth-child(1){left:0%;width:10%;transform:translateX(0);}
.hit-target:nth-child(2){left:25%;}
.hit-target:nth-child(3){left:50%;}
.hit-target:nth-child(4){left:75%;}
.hit-target:nth-child(5){left:100%;width:10%;transform:translateX(-100%);}
.guide-line{position:absolute;top:0;left:50%;width:1px;height:100%;border-left:1px solid #e2e8f0;opacity:0;transition:opacity 0.2s;pointer-events:none;}
.hit-target:nth-child(1) .guide-line{left:0%}
.hit-target:nth-child(5) .guide-line{left:100%;transform:translateX(-1px)}
.hover-content{position:absolute;left:50%;width:0;height:0;pointer-events:none;}
.hit-target:nth-child(1) .hover-content{left:0%}
.hit-target:nth-child(5) .hover-content{left:100%}
.highlight-dot{position:absolute;left:0;top:0;width:10px;height:10px;background:#fff;border-radius:50%;transform:translate(-50%,-50%) scale(0);transition:transform 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.2),0 0 0 1px rgba(0,0,0,0.05);}
.hit-tooltip{position:absolute;top:0;left:15px;background:#fff;border:1px solid #f1f5f9;box-shadow:0 4px 12px rgba(0,0,0,0.08);border-radius:8px;padding:0.5rem 0.75rem;white-space:nowrap;opacity:0;visibility:hidden;transition:opacity 0.2s,transform 0.2s;transform:translateY(-20px);}
.tooltip-label{font-size:0.75rem;color:#64748b;margin-bottom:0.25rem;}
.tooltip-value{font-size:0.85rem;font-weight:600;color:var(--green);}
.hit-target:nth-child(4) .hit-tooltip,.hit-target:nth-child(5) .hit-tooltip{left:auto;right:15px}
.hit-target:hover .guide-line{opacity:1}
.hit-target:hover .highlight-dot{transform:translate(-50%,-50%) scale(1)}
.hit-target:hover .hit-tooltip{opacity:1;visibility:visible;transform:translateY(-10px)}
.insights-metrics-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.metric-item{display:flex;flex-direction:column;gap:0.25rem;}
.metric-label{font-size:0.75rem;font-weight:500;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;}
.metric-value{font-size:0.95rem;font-weight:600;color:var(--text);}
.insights-status-bar{width:100%;height:8px;border-radius:4px;display:flex;overflow:hidden;background:#f3f4f6;margin-top:0.5rem;}
.status-segment-pending{background:#f59e0b;width:20%}
.status-segment-review{background:#6b7280;width:30%}
.status-segment-resolved{background:#10b981;width:50%}
.status-legend{display:flex;justify-content:space-between;margin-top:0.5rem;font-size:0.75rem;color:var(--text-secondary);}
.legend-item{display:flex;align-items:center;gap:0.35rem;}
.legend-dot{width:6px;height:6px;border-radius:50%;}
.insights-ai{background:linear-gradient(145deg,#f8fafc,#f1f5f9);border:1px solid #e2e8f0;border-radius:12px;padding:0.75rem;margin-bottom:0.5rem;}
.ai-text{font-size:0.9rem;color:var(--text-2);line-height:1.5;}
.ai-text strong{color:#0f172a;}
.insights-summary{border-top:1px solid var(--border-2);padding-top:1rem;display:flex;flex-direction:column;gap:0.5rem;}
.summary-row{display:flex;justify-content:space-between;align-items:center;font-size:0.88rem;}
.summary-label{color:var(--text-secondary);}
.summary-value{font-size:0.78rem;font-weight:600;color:var(--text);}


/* �"?�"? Overflow guard: prevent horizontal scroll on any screen size �"?�"? */
.main-content,#mainContent,.dashboard-grid,.dash-main,.dash-sidebar{max-width:100%;overflow-x:hidden;box-sizing:border-box;}
.dashboard-grid>*,.dash-main>*,.dash-sidebar>*,.top-row>*{min-width:0;}

/* �"?�"? Responsive breakpoints �"?�"? */
@media (max-width:1100px){
  .dashboard-grid{grid-template-columns:1fr}
  .top-row{grid-template-columns:1fr}
  .quick-actions{grid-template-columns:repeat(2,1fr)}
  .quick-actions .action-card{padding:1rem;gap:0.75rem}
  .quick-actions .action-icon{width:40px;height:40px}
  .action-content h3{font-size:0.9rem}
}
@media (max-width:991px){
  .claims-table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch;white-space:nowrap}
  .calendar-widget{padding:1rem}
  .calendar-grid{gap:0.3rem}
  .calendar-date{width:32px;height:32px;font-size:0.75rem}
  .calendar-day-name{font-size:0.65rem}
  .messages-list{max-height:none;padding:0.5rem 1rem 1rem}
  .appointment-list{max-height:none}
  .message-preview{font-size:0.82rem}
  .sender-info h4{font-size:0.88rem}
}
@media (max-width:768px){
  .main-content{padding:1rem 0.75rem !important}
  .dashboard-grid{gap:1rem}
  .top-row{gap:1rem}
  .quick-actions{gap:0.75rem;margin-bottom:1rem}
  .card-body{padding:0.85rem}
  .insights-body{padding:0.85rem;gap:0.75rem}
  .insights-metrics-grid{grid-template-columns:1fr;gap:0.75rem}
  .status-legend{flex-wrap:wrap;gap:0.5rem}
  .insights-kpi{font-size:1.5rem}
  .insights-header{padding:0.75rem 1rem}
  .insights-ai{padding:0.5rem}
  .ai-text{font-size:0.82rem}
  .claims-table th,.claims-table td{padding:0.5rem 0.4rem}
  .claims-header{flex-direction:column;align-items:flex-start;gap:0.5rem}
  .med-item{padding:0.7rem 0.75rem}
  .med-footer{padding:0.7rem 0.75rem;font-size:0.75rem;flex-wrap:wrap;gap:0.5rem}
  .report-date-box{width:36px;height:36px}
  .report-date-day{font-size:0.85rem}
  .report-date-month{font-size:0.55rem}
  .insights-summary{padding-top:0.75rem}
  .action-card{flex-direction:column;text-align:center;padding:0.75rem;gap:0.5rem}
  .action-card .action-icon{width:36px;height:36px;margin:0 auto}
  .sparkline-container{height:25px}
  .hit-tooltip{left:10px;font-size:0.72rem;white-space:normal;max-width:140px}
  .hit-target:nth-child(4) .hit-tooltip,.hit-target:nth-child(5) .hit-tooltip{left:auto;right:10px}
  .act-table-wrap{min-height:200px}
  .act-pagination{padding:0.5rem 0.85rem 0.85rem}
  .messages-list{max-height:280px}
  .appointment-list{max-height:200px}
}
@media (max-width:600px){
  .main-content{padding:0.75rem 0.5rem !important}
  .quick-actions{grid-template-columns:1fr;gap:0.5rem;margin-bottom:0.75rem}
  .quick-actions .action-card{flex-direction:row;text-align:left;gap:0.75rem;padding:0.6rem}
  .action-card .action-icon{width:28px;height:28px;margin:0}
  .action-content h3{font-size:0.8rem}
  .action-content p{font-size:0.72rem}
  .insights-header{flex-direction:column;align-items:flex-start;gap:0.5rem}
  .period-menu{right:0;left:auto}
  .period-filter{font-size:0.68rem}
  .calendar-date{width:24px;height:24px;font-size:0.62rem}
  .calendar-grid{gap:0.15rem}
  .hit-tooltip{white-space:normal;font-size:0.68rem;max-width:120px}
  .sparkline-container{height:20px}
  .card-body{padding:0.65rem}
  .dashboard-grid{gap:0.75rem}
  .top-row{gap:0.75rem}
  .act-table-wrap{min-height:150px}
  .act-pagination{padding:0.4rem 0.65rem 0.65rem;font-size:0.72rem}
  .insights-body{padding:0.75rem;gap:0.5rem}
  .insights-kpi{font-size:1.2rem}
  .insights-header{padding:0.5rem 0.75rem}
  .messages-list{max-height:220px}
  .appointment-list{max-height:160px}
  .welcome-banner{padding:0.75rem 1rem !important;border-radius:10px}
  .welcome-banner .welcome-name{font-size:1.1rem}
  .welcome-banner .welcome-label{font-size:0.72rem}
  .welcome-banner .welcome-sub{font-size:0.65rem}
  .welcome-banner .date-card{padding:0.4rem 0.6rem}
  .welcome-banner .date-day{font-size:0.85rem}
  .welcome-banner .date-month{font-size:0.55rem}
  .welcome-banner .date-year{font-size:0.55rem}
}
</style>
<link rel="stylesheet" href="assets/css/resident-overview.css" />
<style>
  html, body.dashboard, body.dashboard .main-content { background: #ffffff !important; }
</style>
</head>
<body class="dashboard" data-realtime-page="resident">

<?php include 'includes/resident-sidebar.php'; ?>

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.�
     MAIN CONTENT
�.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
<main class="main-content" id="mainContent">

<?php if ($petSuccess): ?>
  <div class="auth-alert auth-alert-success" style="margin: 1rem 0;">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($petSuccess); ?></span>
  </div>
<?php endif; ?>
<?php if ($reportSuccess): ?>
  <div class="auth-alert auth-alert-success" style="margin: 1rem 0;">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($reportSuccess); ?></span>
  </div>
<?php endif; ?>
<?php if ($appointmentSuccess): ?>
  <div class="auth-alert auth-alert-success" style="margin: 1rem 0;">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($appointmentSuccess); ?></span>
  </div>
<?php endif; ?>
<?php if ($profileSuccess): ?>
  <div class="auth-alert auth-alert-success" style="margin: 1rem 0;">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($profileSuccess); ?></span>
  </div>
<?php endif; ?>

<?php if ($passwordError): ?>
  <div class="auth-alert auth-alert-error" style="margin: 1rem 0;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($passwordError); ?></span>
  </div>
<?php endif; ?>

<?php if ($petError): ?>
  <div class="auth-alert auth-alert-error" style="margin: 1rem 0;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($petError); ?></span>
  </div>
<?php endif; ?>

<?php if ($reportError): ?>
  <div class="auth-alert auth-alert-error" style="margin: 1rem 0;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($reportError); ?></span>
  </div>
<?php endif; ?>

<?php if ($appointmentError): ?>
  <div class="auth-alert auth-alert-error" style="margin: 1rem 0;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($appointmentError); ?></span>
  </div>
<?php endif; ?>

<?php if ($profileError): ?>
  <div class="auth-alert auth-alert-error" style="margin: 1rem 0;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($profileError); ?></span>
  </div>
<?php endif; ?>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? OVERVIEW / DASHBOARD �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <section id="overview" class="content-section active">

    <?php if (isset($_SESSION['login_notice'])): ?>
      <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;color:#1e40af;font-size:0.9rem;">
        <i class="fas fa-info-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:#1e40af;cursor:pointer;font-size:1.1rem;">&times;</button>
      </div>
    <?php endif; ?>

     <div class="resident-overview">
         <div class="ro-head">
         <div class="ro-head-right">
           <button class="ro-primary" type="button" onclick="openBookApptModal()"><i class="far fa-calendar-alt"></i> New Appointment <i class="fas fa-chevron-down" style="font-size:9px"></i></button>
         </div>
       </div>

       <div class="ro-kpis">
         <?php $kpis = [
           ['fa-calendar-alt', 'Upcoming Appointments', $upcomingApptCount, 'Today'],
           ['fa-paw', 'My Pets', count($pets), 'View all pets &rarr;'],
           ['fa-file-medical', 'Active Treatments', $activeTreatments, 'Ongoing care'],
           ['fa-file-alt', 'Reports Submitted', $reportsThisMonth, 'This month'],
           ['fa-wallet', 'Balance Due', '?0.00', 'All clear']
         ]; foreach ($kpis as $kpi): ?>
           <div class="ro-kpi"><span class="ro-kpi-icon"><i class="fas <?php echo $kpi[0]; ?>"></i></span><div><p class="ro-kpi-label"><?php echo $kpi[1]; ?></p><p class="ro-kpi-value"><?php echo $kpi[2]; ?></p><p class="ro-kpi-sub <?php echo strpos($kpi[3], 'View') === 0 || $kpi[3] === 'All clear' ? 'ro-link' : ''; ?>"><?php echo $kpi[3]; ?></p></div></div>
         <?php endforeach; ?>
       </div>

       <div class="ro-grid">
         <div class="ro-card ro-schedule">
           <div class="ro-card-head"><div><h2>Today's Schedule</h2><small><?php echo date('l, F j, Y'); ?></small></div><button class="ro-outline" type="button" onclick="switchSection('appointments')"><i class="far fa-calendar-alt"></i> View Calendar</button></div>
           <div class="ro-card-body">
             <?php if (empty($todayAppts)): ?><div class="ro-empty" style="padding:30px 0;text-align:center;color:#667085;font-size:12px">No upcoming appointments.</div><?php else: ?><div class="ro-timeline">
               <?php foreach ($todayAppts as $apt): $isPending = strtolower($apt['status']) === 'pending'; $isFutureDate = $apt['preferred_date'] !== $todayDate; ?>
                 <div class="ro-appt"><span class="ro-time"><?php echo $isFutureDate ? date('M j', strtotime($apt['preferred_date'])) : date('g:i A', strtotime($apt['preferred_time'])); ?></span><span class="ro-dot<?php echo $isPending ? ' pending' : ''; ?>"></span><div class="ro-appt-card">
                   <?php if (!empty($apt['pet_photo'])): ?><img class="ro-pet-img" src="<?php echo htmlspecialchars($apt['pet_photo']); ?>" alt="<?php echo htmlspecialchars($apt['pet_name']); ?>"><?php else: ?><span class="ro-pet-img"><?php echo strtoupper(substr($apt['pet_name'],0,1)); ?></span><?php endif; ?>
                   <div class="ro-appt-info"><strong><?php echo htmlspecialchars($apt['pet_name']); ?></strong><span><?php echo htmlspecialchars(ucfirst($apt['appointment_type'])); ?></span><span><?php echo $isPending ? 'Awaiting confirmation' : 'Veterinary Services'; ?></span></div><span class="ro-status<?php echo $isPending ? ' pending' : ''; ?>"><?php echo ucfirst($apt['status']); ?></span>
                 </div></div>
               <?php endforeach; ?></div><?php endif; ?>
             <button class="ro-full-btn" type="button" onclick="switchSection('appointments')">View All Appointments <span>&rarr;</span></button>
           </div>
         </div>

         <div class="ro-stack">
           <div class="ro-card"><div class="ro-card-head"><h2>My Pets Overview</h2><a class="ro-view" href="#my-pets" onclick="switchSection('my-pets');return false;">View All</a></div><div class="ro-pets">
             <?php if (empty($pets)): ?><div style="width:100%;text-align:center;color:#667085;font-size:12px;padding:10px">No pets registered yet. <a class="ro-link" href="#" onclick="openModal('addPetModal');return false;">Register one</a></div><?php else: foreach (array_slice($pets,0,3) as $pet): ?><div class="ro-pet"><?php if (!empty($pet['photo_url'])): ?><img class="ro-pet-img" src="<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['pet_name']); ?>"><?php else: ?><span class="ro-pet-img"><?php echo strtoupper(substr($pet['pet_name'],0,1)); ?></span><?php endif; ?><strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong><span><?php echo htmlspecialchars($pet['breed'] ?: ucfirst($pet['pet_type'])); ?></span><span class="ro-pet-meta"><?php echo htmlspecialchars($pet['age'] ?: '-'); ?> yrs &bull; <?php echo htmlspecialchars(ucfirst($pet['gender'] ?: '-')); ?></span></div><?php endforeach; ?><a class="ro-pet ro-add-pet" href="#" onclick="openModal('addPetModal');return false;"><span class="ro-plus">+</span><span>Add New Pet</span></a><?php endif; ?>
           </div></div>
           <div class="ro-card"><div class="ro-card-head"><h2>My Tasks</h2></div><div class="ro-tasks">
             <?php $taskAppointments = array_values(array_filter($upcomingAppts, fn($a) => strtolower($a['status']) === 'pending')); if (empty($taskAppointments)): ?><div style="padding:20px 0;text-align:center;color:#667085;font-size:12px">You're all caught up.</div><?php else: foreach (array_slice($taskAppointments,0,3) as $task): ?><div class="ro-task"><input type="checkbox" aria-label="Task"><span class="ro-task-text">Confirm <?php echo htmlspecialchars($task['pet_name']); ?>'s appointment</span><span class="ro-task-date"><i class="far fa-calendar-alt"></i><?php echo date('M j, Y', strtotime($task['preferred_date'])); ?></span></div><?php endforeach; endif; ?><button class="ro-full-btn" type="button" onclick="switchSection('appointments')">View All Tasks <span>&rarr;</span></button>
           </div></div>
         </div>

         <div class="ro-stack"><div class="ro-card"><div class="ro-card-head"><h2>Clinic Status</h2><span class="ro-live">Live Updates</span></div><div class="ro-row"><i class="far fa-clock"></i><span class="ro-row-label">Clinic Operating Hours</span><strong>8:00 AM - 5:00 PM</strong></div><div class="ro-row"><i class="fas fa-users"></i><span class="ro-row-label">Today's Queue</span><span class="ro-moderate">Moderate</span></div><div class="ro-row"><i class="fas fa-user-md"></i><span class="ro-row-label">On-Duty Veterinarians</span><strong>3</strong></div><div class="ro-row"><i class="fas fa-ambulance"></i><span class="ro-row-label">Active Emergency Cases</span><strong>0</strong></div></div>
           <div class="ro-card"><div class="ro-card-head"><h2>Announcements</h2><a class="ro-view" href="#">View All</a></div><?php if (empty($announcements)): ?><div style="padding:25px;text-align:center;color:#667085;font-size:12px">No announcements at this time.</div><?php else: foreach (array_slice($announcements,0,2) as $announcement): ?><div class="ro-announcement"><span class="ro-icon"><i class="fas <?php $annCat = strtolower($announcement['category'] ?? 'announcement'); echo in_array($annCat, ['advisory','schedule','update']) ? 'fa-envelope' : (in_array($annCat, ['event','activity']) ? 'fa-calendar-alt' : 'fa-bullhorn'); ?>"></i></span><div><strong><?php echo htmlspecialchars($announcement['title']); ?></strong><p><?php echo htmlspecialchars(mb_substr($announcement['content'],0,105)) . (mb_strlen($announcement['content']) > 105 ? '...' : ''); ?></p><time><?php echo date('M j, Y', strtotime($announcement['created_at'])); ?></time></div></div><?php endforeach; endif; ?></div>
         </div>
       </div>

       <div class="ro-bottom"><div class="ro-card"><div class="ro-card-head"><h2>Recent Activity</h2><a class="ro-view" href="#">View All</a></div><div class="ro-table-wrap" style="padding:16px 10px"><table class="ro-table"><thead><tr><th>Activity</th><th>Date &amp; Time</th><th>Status</th></tr></thead><tbody><?php if (empty($recentActivities)): ?><tr><td colspan="3" style="text-align:center">No recent activity.</td></tr><?php else: foreach ($recentActivities as $activity): ?><tr><td><?php echo htmlspecialchars($activity['action']); ?><br><span style="font-size:10px;color:#98a2b3"><?php echo htmlspecialchars($activity['details'] ?? ''); ?></span></td><td><?php echo date('M j, Y . g:i A', strtotime($activity['created_at'])); ?></td><td><span class="ro-badge">Completed</span></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
         <div class="ro-card"><div class="ro-card-head"><h2>Need Help?</h2><a class="ro-view" href="#">Contact DCVS</a></div><div class="ro-help"><span class="ro-icon"><i class="fas fa-hospital"></i></span><div class="ro-help-copy"><strong>DCVS Veterinary Services</strong><p>We're here for you and your pets.</p><div class="ro-contact"><i class="fas fa-phone"></i> (046) 123 4567<br><i class="fas fa-envelope"></i> dcvs.dasma@gmail.com<br><i class="fas fa-map-marker-alt"></i> Brgy. Paliparan I, Dasmari�as City</div></div><div class="ro-actions"><button type="button"><i class="fas fa-phone"></i>Call Now</button><button type="button"><i class="fas fa-envelope"></i>Send Email</button><button type="button"><i class="fas fa-location-dot"></i>Directions</button></div></div></div>
       </div>
     </div>

     <div style="display:none" aria-hidden="true">
     <!-- Legacy overview retained for existing dashboard-only controls. -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <p class="welcome-label">Good day,</p>
        <h1 class="welcome-name"><?php echo htmlspecialchars($firstName); ?>!</h1>
        <p class="welcome-sub">
          Brgy. <?php echo htmlspecialchars($barangayName); ?>, Dasmari&ntilde;as City
          &nbsp;&middot;&nbsp;
          <i class="fas fa-user"></i> Resident
        </p>
      </div>
      <div class="welcome-date">
        <div class="date-card">
          <span class="date-day" id="todayDay"></span>
          <span class="date-month" id="todayMonth"></span>
          <span class="date-year" id="todayYear"></span>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <div class="action-card" onclick="openModal('addPetModal')">
        <div class="action-icon">
          <img src="uploads/site/pet-icon.png" alt="Pet" style="width:28px;height:28px;object-fit:contain;" />
        </div>
        <div class="action-content">
          <h3>Register a Pet</h3>
          <p>Add a new pet to your profile</p>
        </div>
      </div>
      <div class="action-card" onclick="openSubmitReportModal()">
        <div class="action-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path>
            <path d="M14 2v4a2 2 0 0 0 2 2h4"></path>
            <path d="M12 9v4"></path>
            <path d="M12 17h.01"></path>
          </svg>
        </div>
        <div class="action-content">
          <h3>Submit a Report</h3>
          <p>Report a stray or incident</p>
        </div>
      </div>
      <div class="action-card" onclick="openBookApptModal()">
        <div class="action-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5"></path>
            <path d="M16 2v4"></path>
            <path d="M8 2v4"></path>
            <path d="M3 10h5"></path>
            <path d="M17.5 17.5 16 16.3V14"></path>
            <circle cx="16" cy="16" r="6"></circle>
          </svg>
        </div>
        <div class="action-content">
          <h3>Request Appointment</h3>
          <p>Schedule a veterinary service</p>
        </div>
      </div>
      <div class="action-card" onclick="switchSection('adoption')">
        <div class="action-icon">
          <i class="fas fa-paw" style="font-size:24px;"></i>
        </div>
        <div class="action-content">
          <h3>Adopt a Pet</h3>
          <p>Give a pet a new home</p>
        </div>
      </div>
    </div>

    <div class="dashboard-grid">
      <div class="dash-main">
        <div class="top-row">
          <!-- Recent Reports Card -->
          <div class="card">
            <div class="card-header">
              <h2 class="section-title">Recent Reports (<?php echo count($reports); ?>)</h2>
            </div>
            <ul class="med-list">
              <?php if (empty($reports)): ?>
                <li class="med-item" style="justify-content:center;padding:2rem;color:#94a3b8;font-size:13px;">No reports submitted yet.</li>
              <?php else: ?>
                <?php foreach (array_slice($reports, 0, 4) as $report): ?>
                  <?php $rptDay = date('d', strtotime($report['created_at'])); $rptMon = strtoupper(date('M', strtotime($report['created_at']))); ?>
                  <li class="med-item">
                    <div class="med-info">
                      <div class="report-date-box">
                        <span class="report-date-day"><?php echo $rptDay; ?></span>
                        <span class="report-date-month"><?php echo $rptMon; ?></span>
                      </div>
                      <div>
                        <div class="med-name"><?php echo ucfirst($report['report_type']); ?></div>
                        <div class="med-desc"><?php echo htmlspecialchars($report['reference_id'] ?? fallbackRefId('#RPT-', $report['report_id'])); ?> &bull; <?php echo date('M d, Y', strtotime($report['created_at'])); ?></div>
                      </div>
                    </div>
<strong style="font-size:0.82rem;"><?php echo ucfirst($report['status']); ?></strong>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
            <div class="med-footer">
              <a href="#" onclick="switchSection('my-reports');return false;">View all reports</a>
              <a href="#" class="action" onclick="openSubmitReportModal();return false;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Submit New
              </a>
            </div>
          </div>

          <!-- Upcoming Appointments Calendar -->
          <?php
          $calM = (int)date('n');
          $calY = (int)date('Y');
          $calDays = (int)date('t');
          $calStart = (int)date('N', mktime(0,0,0,$calM,1,$calY)) - 1; // Mon=0, Sun=6
          if ($calStart < 0) $calStart = 6;
          $calToday = (int)date('j');
          $calMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
          $apptDates = [];
          $apptData = [];
          foreach ($upcomingAppointments as $apt) {
            $ts = strtotime($apt['preferred_date']);
            if ((int)date('n',$ts) === $calM && (int)date('Y',$ts) === $calY) {
              $d = (int)date('j',$ts);
              $apptDates[] = $d;
              if (!isset($apptData[$d])) $apptData[$d] = [];
              $apptData[$d][] = ['pet' => htmlspecialchars($apt['pet_name'] ?? '-'), 'service' => ucfirst($apt['appointment_type']), 'time' => $apt['preferred_time'], 'status' => $apt['status']];
            }
          }
          ?>
          <div class="card" style="display: flex; flex-direction: column;">
            <div class="card-header" style="border-bottom: 1px solid var(--border-2); padding-bottom: 1.25rem;">
              <h2 class="section-title" style="margin: 0;">Upcoming Appointments</h2>
            </div>
            <div class="calendar-widget" style="border: none; box-shadow: none; border-radius: 0; padding-top: 1.5rem;">
              <div class="calendar-header">
                <h3 id="calendarMonthYear"><?php echo $calMonthNames[$calM-1] . ', ' . $calY; ?></h3>
                <div class="calendar-nav">
                  <button class="calendar-nav-btn" id="prevMonthBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                  </button>
                  <button class="calendar-nav-btn" id="nextMonthBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                  </button>
                </div>
              </div>

              <div class="calendar-grid">
                <div class="calendar-day-name">Mon</div>
                <div class="calendar-day-name">Tue</div>
                <div class="calendar-day-name">Wed</div>
                <div class="calendar-day-name">Thu</div>
                <div class="calendar-day-name">Fri</div>
                <div class="calendar-day-name">Sat</div>
                <div class="calendar-day-name">Sun</div>
              </div>
              <div class="calendar-grid" id="calendarGridDates" style="margin-top: -1rem;">
                <?php
                $prevMonthDays = (int)date('t', mktime(0,0,0,$calM-1,1,$calY));
                for ($i = $calStart - 1; $i >= 0; $i--):
                ?>
                  <div class="calendar-date muted"><?php echo $prevMonthDays - $i; ?></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $calDays; $d++):
                  $cls = 'calendar-date';
                  if (isset($apptData[$d])) $cls .= ' has-appointment';
                  if (count($apptData[$d] ?? []) > 1) $cls .= ' has-multiple';
                  if ($d === $calToday) $cls .= ' active';
                ?>
                  <div class="<?php echo $cls; ?>">
                    <?php echo $d; ?>
                    <?php if (isset($apptData[$d])): ?>
                      <div class="calendar-tooltip">
                        <?php foreach ($apptData[$d] as $a): ?>
                          <div style="margin-bottom:2px;"><strong><?php echo $a['pet']; ?></strong><br><?php echo $a['service']; ?> &middot; <?php echo $a['time']; ?></div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endfor; ?>
                <?php
                $totalCells = $calStart + $calDays;
                $nextDays = $totalCells <= 35 ? 35 - $totalCells : 42 - $totalCells;
                for ($i = 1; $i <= $nextDays; $i++):
                ?>
                  <div class="calendar-date muted"><?php echo $i; ?></div>
                <?php endfor; ?>
              </div>

              <div class="appointment-list">
                <?php
                $displayAppts = array_slice(array_values($upcomingAppointments), 0, 4);
                ?>
                <?php if (empty($displayAppts)): ?>
                  <div style="text-align:center;padding:1rem;color:#94a3b8;font-size:13px;">No upcoming appointments.</div>
                <?php else: ?>
                  <?php foreach ($displayAppts as $apt):
                    $petPhoto = '';
                    $petTypeIcon = 'dog';
                    foreach ($pets as $p) {
                      if ($p['pet_id'] == $apt['pet_id']) {
                        $petPhoto = $p['photo_url'] ?? '';
                        $petTypeIcon = $p['pet_type'] ?? 'dog';
                        break;
                      }
                    }
                  ?>
                    <div class="appointment-item">
                      <div class="appointment-user">
                        <?php if ($petPhoto): ?>
                          <img src="<?php echo htmlspecialchars($petPhoto); ?>" alt="<?php echo htmlspecialchars($apt['pet_name'] ?? 'Pet'); ?>" class="appointment-avatar">
                        <?php else: ?>
                          <div class="appointment-avatar" style="background:#f0fdf4;color:var(--green);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;"><?php echo strtoupper(substr(htmlspecialchars($apt['pet_name'] ?? 'P'), 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="appointment-info">
                          <h4><?php echo htmlspecialchars($apt['pet_name'] ?? '-'); ?> (<?php echo ucfirst($petTypeIcon); ?>)</h4>
                          <p><?php echo ucfirst($apt['appointment_type']); ?></p>
                        </div>
                      </div>
                      <div class="appointment-status">
                        <?php
                        $apptStatusStr = $apt['status'];
                        $statusPillColor = '#0E8131';
                        $statusPillBg = '#ECFDF5';
                        $statusPillBorder = '#bbf7d0';
                        if ($apptStatusStr === 'pending') { $statusPillColor = '#C2540B'; $statusPillBg = '#FFF7ED'; $statusPillBorder = '#fed7aa'; }
                        elseif ($apptStatusStr === 'scheduled') { $statusPillColor = '#0E8131'; $statusPillBg = '#ECFDF5'; $statusPillBorder = '#bbf7d0'; }
                        elseif (in_array($apptStatusStr, ['completed','resolved'])) { $statusPillColor = '#10B981'; $statusPillBg = '#ECFDF5'; $statusPillBorder = '#a7f3d0'; }
                        elseif (in_array($apptStatusStr, ['cancelled','rejected'])) { $statusPillColor = '#B91C1C'; $statusPillBg = '#FEF2F2'; $statusPillBorder = '#fecaca'; }
                        $statusPillText = ucfirst(str_replace('_',' ',$apptStatusStr));
                        ?>
                          <span class="appt-status-pill" style="color:<?php echo $statusPillColor; ?>;background:<?php echo $statusPillBg; ?>;border-color:<?php echo $statusPillBorder; ?>;"><?php echo $statusPillText; ?></span>
                        <?php if (!in_array($apptStatusStr, ['completed','resolved','cancelled','rejected'])): ?>
                          <span class="status-time">
                            <?php echo date('M d', strtotime($apt['preferred_date'])); ?>
                            <span class="status-time-bold"><?php echo date('g:i A', strtotime($apt['preferred_time'])); ?></span>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="med-footer">
              <a href="#" onclick="switchSection('appointments');return false;">View all appointments</a>
              <a href="#" class="action" onclick="openBookApptModal();return false;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Submit New
              </a>
            </div>
          </div>
        </div>

        <!-- Recent Activity Section -->
        <div class="card card-body" id="recentActivityCard" style="flex:1;display:flex;flex-direction:column;">
          <div class="claims-header">
            <h2 class="section-title">Recent Activity</h2>
          </div>
          <?php
          $activities = [];
          foreach ($pets as $pet) {
            $activities[] = ['title' => 'Registered ' . htmlspecialchars($pet['pet_name']), 'subtitle' => 'Added new pet (' . ucfirst($pet['pet_type']) . ') to profile', 'date' => $pet['created_at'], 'category' => 'Pet Registration', 'status' => 'Approved'];
          }
          foreach ($reports as $report) {
            $activities[] = ['title' => ucfirst($report['report_type']), 'subtitle' => htmlspecialchars(mb_substr($report['description'] ?? '', 0, 40)) . (mb_strlen($report['description'] ?? '') > 40 ? '...' : ''), 'date' => $report['created_at'], 'category' => 'Reports', 'status' => ucfirst($report['status'])];
          }
          foreach ($appointments as $appt) {
            $activities[] = ['title' => htmlspecialchars($appt['pet_name'] ?? '-') . ' - ' . ucfirst($appt['appointment_type']), 'subtitle' => 'Scheduled appointment', 'date' => $appt['created_at'], 'category' => 'Appointments', 'status' => ucfirst($appt['status'])];
          }
          usort($activities, fn($a,$b) => strtotime($b['date']) - strtotime($a['date']));
          ?>
          <div class="act-table-wrap">
          <table class="claims-table" id="activityTable">
            <thead>
              <tr>
                <th>Activity Details</th>
                <th>Category</th>
                <th>Date & Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activities)): ?>
                <tr id="actNoResults"><td colspan="4" style="text-align:center;padding:2rem;color:#94a3b8;font-size:13px;">No recent activity</td></tr>
              <?php else: ?>
                <?php foreach ($activities as $act): ?>
                  <tr class="act-row">
                    <td>
                      <span class="provider-name"><?php echo $act['title']; ?></span>
                      <span class="provider-type"><?php echo $act['subtitle']; ?></span>
                    </td>
                    <td><?php echo $act['category']; ?></td>
                    <td><?php echo date('M d, Y - g:i A', strtotime($act['date'])); ?></td>
                    <td><strong style="font-size:0.82rem;"><?php echo $act['status']; ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
          <div class="act-pagination" id="actPagination"></div>
        </div>

<style>
.act-table-wrap{flex:1;min-height:360px;}
.act-pagination{display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1.5rem 1.25rem;font-size:0.82rem;color:#64748b;flex-shrink:0;}
#recentActivityCard{overflow:visible;}
.pag-info{font-weight:500;}
.pag-btns{display:flex;gap:0.4rem;}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e2e8f0;color:#475569;text-decoration:none;transition:all 0.15s;}
.pag-btn:hover{background:#f1f5f9;border-color:#cbd5e1;}
</style>
<script>
(function(){
  var PER_PAGE = 6;
  var tbody = document.querySelector('#activityTable tbody');
  var pag = document.getElementById('actPagination');
  if (!tbody || !pag) return;
  var allRows = Array.from(tbody.querySelectorAll('.act-row'));
  var noResults = document.getElementById('actNoResults');
  var currentPage = 1;
  function renderTable() {
    var start = (currentPage - 1) * PER_PAGE;
    var end = Math.min(start + PER_PAGE, allRows.length);
    // Hide ALL rows first, then show current page - prevents visual overlap during reflow
    allRows.forEach(function(r) { r.style.display = 'none'; });
    for (var i = start; i < end; i++) { allRows[i].style.display = ''; }
    if (noResults) noResults.style.display = allRows.length === 0 ? '' : 'none';
    renderPagination();
  }
  function renderPagination() {
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (tp <= 1) { pag.style.display = 'none'; return; }
    pag.style.display = '';
    var h = '';
    h += '<span class="pag-info">Page ' + currentPage + ' of ' + tp + ' &nbsp;\u00B7&nbsp; ' + total + ' activities</span>';
    h += '<div class="pag-btns">';
    h += '<a href="#" onclick="window.actPageGo(' + (currentPage - 1) + ');return false;" class="pag-btn"' + (currentPage <= 1 ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-left"></i></a>';
    h += '<a href="#" onclick="window.actPageGo(' + (currentPage + 1) + ');return false;" class="pag-btn"' + (currentPage >= tp ? ' style="opacity:0.4;pointer-events:none;"' : '') + '><i class="fas fa-chevron-right"></i></a>';
    h += '</div>';
    pag.innerHTML = h;
  }
  window.actPageGo = function(page) {
    var total = allRows.length;
    var tp = Math.ceil(total / PER_PAGE);
    if (page < 1 || page > tp) return;
    currentPage = page;
    renderTable();
  };
  renderTable();
})();
</script>

      </div>

      <!-- Sidebar / Messages -->
      <div class="dash-sidebar">
        <div class="card" style="display:flex;flex-direction:column;">
          <div class="card-header" style="border-bottom: 1px solid var(--border-2); padding-bottom: 1.25rem;">
            <h2 class="section-title" style="margin:0;">New messages <span class="badge"><?php echo $unreadMsgCount; ?></span></h2>
          </div>
          <div class="messages-list">
          <?php if (empty($staffMessages)): ?>
            <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:13px;">No messages from staff.</div>
          <?php else: ?>
            <?php $msgOrder = array_reverse(array_slice($staffMessages, 0, 4)); ?>
            <?php foreach ($msgOrder as $n): ?>
              <?php
                $senderAvatar = $n['sender_avatar'] ?? '';
                $senderName = $n['sender_name'] ?? 'Staff';
                $subject = preg_replace('/^Message from Staff\s*[---]\s*/', '', $n['title'] ?? '');
                $msgTypeLabel = $n['message_type_label'] ?? '';
                $initials = '';
                foreach (explode(' ', trim($senderName)) as $part) {
                  if (trim($part) !== '') $initials .= strtoupper(mb_substr(trim($part), 0, 1));
                }
                $initials = mb_substr($initials, 0, 2);
                // Clean message: strip "Name wrote: " prefix so only the actual message shows
                $cleanMsg = preg_replace('/^.*? wrote:\s*/', '', $n['message'] ?? '');
              ?>
              <div class="message-card">
                <div class="message-header">
                  <div class="message-sender">
                  <?php if (!empty($senderAvatar)): ?>
                    <img class="sender-avatar" src="<?php echo htmlspecialchars($senderAvatar); ?>" alt="<?php echo htmlspecialchars($senderName); ?>">
                  <?php else: ?>
                    <div class="sender-avatar" style="background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;"><?php echo $initials; ?></div>
                  <?php endif; ?>
                  <div class="sender-info">
                    <h4><?php echo htmlspecialchars($senderName); ?></h4>
                    <?php if ($subject): ?>
                      <p class="message-subject"><?php echo htmlspecialchars($subject); ?></p>
                    <?php endif; ?>
                    <?php if ($msgTypeLabel): ?>
                      <p class="message-type-label"><?php echo htmlspecialchars($msgTypeLabel); ?></p>
                    <?php endif; ?>
                    <p><?php echo date('M d, g:i A', strtotime($n['created_at'])); ?></p>
                  </div>
                </div>
                </div>
                <div class="message-preview">
                  <?php echo htmlspecialchars($cleanMsg); ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        </div>

        <!-- Report Trend Insights -->
        <?php
        // Period filter for insights
        $insightPeriod = isset($_GET['period']) ? intval($_GET['period']) : 0;
        $insightReports = $reports;
        if ($insightPeriod > 0) {
            $cutoff = strtotime("-{$insightPeriod} days");
            $insightReports = array_values(array_filter($reports, fn($r) => strtotime($r['created_at']) >= $cutoff));
        } elseif ($insightPeriod !== 0) {
            $insightPeriod = 30;
        }

        // Average resolution time respecting the selected period
        $avgResolutionDays = null;
        if ($insightPeriod > 0) {
            $cutoffDate = date('Y-m-d', strtotime("-{$insightPeriod} days"));
            $resQuery = $conn->prepare("SELECT AVG(DATEDIFF(cc.resolved_at, r.created_at)) AS avg_days FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id WHERE r.reporter_id = ? AND cc.status = 'resolved' AND cc.resolved_at IS NOT NULL AND r.created_at >= ?");
            $resQuery->bind_param("is", $_SESSION['user_id'], $cutoffDate);
        } else {
            $resQuery = $conn->prepare("SELECT AVG(DATEDIFF(cc.resolved_at, r.created_at)) AS avg_days FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id WHERE r.reporter_id = ? AND cc.status = 'resolved' AND cc.resolved_at IS NOT NULL");
            $resQuery->bind_param("i", $_SESSION['user_id']);
        }
        $resQuery->execute();
        $resResult = $resQuery->get_result();
        if ($row = $resResult->fetch_assoc()) {
            $avgResolutionDays = $row['avg_days'];
        }
        $resQuery->close();

        $reportTypes = array_count_values(array_map(fn($r) => $r['report_type'], $insightReports));
        arsort($reportTypes);
        $mostSubmitted = $reportTypes ? ucfirst(array_key_first($reportTypes)) : 'N/A';
        $firstReport = $insightReports ? date('M d, Y', strtotime($insightReports[0]['created_at'])) : 'N/A';
        $lastReport = $insightReports ? date('M d, Y', strtotime($insightReports[count($insightReports)-1]['created_at'])) : 'N/A';
        $totalReports = count($insightReports);
        // Weekly report counts for sparkline (last 5 weeks)
        $weeklyBuckets = [];
        $now = new DateTime();
        for ($i = 4; $i >= 0; $i--) {
            $ws = (clone $now)->modify('monday this week -' . $i . ' weeks');
            $we = (clone $ws)->modify('+6 days');
            $weeklyBuckets[] = [
                'label' => date('M d', $ws->getTimestamp()) . ' - ' . date('M d', $we->getTimestamp()),
                'start' => $ws->format('Y-m-d'),
                'end'   => $we->format('Y-m-d'),
                'count' => 0,
            ];
        }
        foreach ($insightReports as $r) {
            $created = date('Y-m-d', strtotime($r['created_at']));
            foreach ($weeklyBuckets as &$b) {
                if ($created >= $b['start'] && $created <= $b['end']) { $b['count']++; break; }
            }
        }
        unset($b);
        $maxCount = max(array_column($weeklyBuckets, 'count'));
        $maxCount = $maxCount > 0 ? $maxCount : 1;
        $pts = [];
        $xs = [0, 25, 50, 75, 100];
        foreach ($weeklyBuckets as $j => $b) {
            $y = 25 - ($b['count'] / $maxCount) * 20;
            $pts[] = ['x' => $xs[$j], 'y' => round($y, 2), 'count' => $b['count'], 'label' => $b['label']];
        }
        // Smooth cubic bezier through points (avoids sharp zigzag)
        $n = count($pts);
        $linePath = 'M' . $pts[0]['x'] . ',' . $pts[0]['y'];
        if ($n > 1) {
            for ($i = 1; $i < $n; $i++) {
                $p0 = $pts[$i-1];
                $p1 = $pts[$i];
                $pPrev = $pts[max(0, $i-2)];
                $pNext = $pts[min($n-1, $i+1)];
                // Control points - tension 0.25 for a gentle curve
                $cp1x = $p0['x'] + ($p1['x'] - $pPrev['x']) * 0.25;
                $cp1y = $p0['y'] + ($p1['y'] - $pPrev['y']) * 0.25;
                $cp2x = $p1['x'] - ($pNext['x'] - $p0['x']) * 0.25;
                $cp2y = $p1['y'] - ($pNext['y'] - $p0['y']) * 0.25;
                $linePath .= 'C' . $cp1x . ',' . $cp1y . ' ' . $cp2x . ',' . $cp2y . ' ' . $p1['x'] . ',' . $p1['y'];
            }
        }
        $fillPath = $linePath . ' L100,30 L0,30 Z';
        ?>
        <div id="insightsContainer">
        <div class="insights-card">
          <div class="insights-header">
            <h3 class="insights-title">Report Trend Insights</h3>
            <div class="period-filter" onclick="togglePeriodMenu(event)">
              <span id="periodLabel"><?php echo $insightPeriod === 7 ? 'Last 7 Days' : ($insightPeriod === 90 ? 'Last 90 Days' : ($insightPeriod === 0 ? 'All Time' : 'Last 30 Days')); ?></span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
              <div id="periodMenu" class="period-menu">
                <a href="#" class="period-option<?php echo $insightPeriod === 7 ? ' active' : ''; ?>" data-period="7" onclick="return switchPeriod(event,7)">Last 7 Days</a>
                <a href="#" class="period-option<?php echo $insightPeriod === 30 ? ' active' : ''; ?>" data-period="30" onclick="return switchPeriod(event,30)">Last 30 Days</a>
                <a href="#" class="period-option<?php echo $insightPeriod === 90 ? ' active' : ''; ?>" data-period="90" onclick="return switchPeriod(event,90)">Last 90 Days</a>
                <a href="#" class="period-option<?php echo $insightPeriod === 0 ? ' active' : ''; ?>" data-period="0" onclick="return switchPeriod(event,0)">All Time</a>
              </div>
            </div>
          </div>
          <div class="insights-body">
            <div class="insights-kpi-row">
              <span class="insights-kpi"><?php echo $totalReports; ?></span>
              <?php if ($totalReports > 0): ?>
                <span class="insights-trend"><?php echo $activeCases; ?> active</span>
              <?php endif; ?>
            </div>

            <div class="sparkline-container">
              <svg class="insights-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                <path d="<?php echo $linePath; ?>" fill="none" stroke="var(--green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="<?php echo $fillPath; ?>" fill="url(#sparkline-gradient)" opacity="0.2"/>
                <defs>
                  <linearGradient id="sparkline-gradient" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="var(--green)" stop-opacity="1"/>
                    <stop offset="100%" stop-color="var(--green)" stop-opacity="0"/>
                  </linearGradient>
                </defs>
              </svg>
              <div class="sparkline-overlay">
                <?php foreach ($pts as $p): ?>
                <div class="hit-target">
                  <div class="guide-line"></div>
                  <div class="hover-content" style="top: <?php echo 100 - ($p['count'] / $maxCount) * 100; ?>%;">
                    <div class="highlight-dot"></div>
                    <div class="hit-tooltip">
                      <div class="tooltip-label"><?php echo $p['label']; ?></div>
                      <div class="tooltip-value">Reports: <?php echo $p['count']; ?></div>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="insights-metrics-grid">
              <div class="metric-item">
                <span class="metric-label">Most Submitted</span>
                <span class="metric-value"><?php echo $mostSubmitted; ?></span>
              </div>
              <div class="metric-item">
                <span class="metric-label">Avg Resolution</span>
                <span class="metric-value"><?php echo $avgResolutionDays !== null ? round($avgResolutionDays, 1) . ' Days' : 'N/A'; ?></span>
              </div>
            </div>

            <div class="insights-ai">
              <div class="ai-text">
                <strong>Insight:</strong> You have submitted <?php echo $totalReports; ?> report<?php echo $totalReports !== 1 ? 's' : ''; ?> with <?php echo $activeCases; ?> currently active. <?php
                $lastWk = $weeklyBuckets[3]['count'] ?? 0;
                $thisWk = $weeklyBuckets[4]['count'] ?? 0;
                if ($lastWk > 0 || $thisWk > 0):
                    $diff = $thisWk - $lastWk;
                    if ($diff > 0): echo 'Up ' . $diff . ' report' . ($diff !== 1 ? 's' : '') . ' from last week.';
                    elseif ($diff < 0): echo 'Down ' . abs($diff) . ' report' . (abs($diff) !== 1 ? 's' : '') . ' from last week.';
                    else: echo 'Same as last week.';
                    endif;
                endif;
                ?>
              </div>
            </div>

            <div class="insights-summary">
              <div class="summary-row">
                <span class="summary-label">First report</span>
                <span class="summary-value"><?php echo $firstReport; ?></span>
              </div>
              <div class="summary-row">
                <span class="summary-label">Latest report</span>
                <span class="summary-value"><?php echo $lastReport; ?></span>
              </div>
              <div class="summary-row">
                <span class="summary-label">Active cases</span>
                <span class="summary-value"><?php echo $activeCases; ?></span>
              </div>
            </div>
          </div>
        </div>
           </div>

         </div>
    </div>

    <script>
      function togglePeriodMenu(e) {
        e.stopPropagation();
        var menu = document.getElementById('periodMenu');
        if (menu) menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
      }
      document.addEventListener('click', function() {
        var menu = document.getElementById('periodMenu');
        if (menu) menu.style.display = 'none';
      });
      function switchPeriod(e, period) {
        e.preventDefault();
        var url = new URL(window.location.href);
        url.searchParams.set('period', period);
        fetch(url.toString())
          .then(function(r) { return r.text(); })
          .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newC = doc.getElementById('insightsContainer');
            var oldC = document.getElementById('insightsContainer');
            if (newC && oldC) {
              oldC.outerHTML = newC.outerHTML;
              history.pushState({period: period}, '', url.toString());
            }
          });
        return false;
      }
      (function() {
        var monthYearText = document.getElementById('calendarMonthYear');
        var prevBtn = document.getElementById('prevMonthBtn');
        var nextBtn = document.getElementById('nextMonthBtn');
        var gridDates = document.getElementById('calendarGridDates');

        var today = new Date();
        var currentDate = new Date(today.getFullYear(), today.getMonth(), 1);

        var appointmentsData = {};
        <?php
        foreach ($apptData as $day => $apps):
          $list = [];
          foreach ($apps as $a) $list[] = ['pet' => $a['pet'], 'service' => $a['service']];
          echo "appointmentsData['" . $calY . "-" . ($calM-1) . "-" . $day . "'] = " . json_encode($list) . ";\n";
        endforeach;
        ?>

        function renderCalendar() {
          if (!gridDates) return;
          gridDates.innerHTML = '';
          var year = currentDate.getFullYear();
          var month = currentDate.getMonth();

          var monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
          monthYearText.textContent = monthNames[month] + ', ' + year;

          var firstDay = new Date(year, month, 1).getDay();
          var daysInMonth = new Date(year, month + 1, 0).getDate();

          var startDay = firstDay === 0 ? 6 : firstDay - 1;
          var prevMonthDays = new Date(year, month, 0).getDate();

          for (var i = startDay - 1; i >= 0; i--) {
            var div = document.createElement('div');
            div.className = 'calendar-date muted';
            div.textContent = prevMonthDays - i;
            gridDates.appendChild(div);
          }

          for (var i = 1; i <= daysInMonth; i++) {
            var div = document.createElement('div');
            div.className = 'calendar-date';
            div.textContent = i;

            var dateKey = year + '-' + month + '-' + i;
            if (appointmentsData[dateKey]) {
              div.classList.add('has-appointment');
              if (appointmentsData[dateKey].length > 1) {
                div.classList.add('has-multiple');
              }
              var tooltip = document.createElement('div');
              tooltip.className = 'calendar-tooltip';
              var tooltipHtml = '';
              appointmentsData[dateKey].forEach(function(appt) {
                tooltipHtml += '<div style="margin-bottom:2px;"><strong>' + appt.pet + '</strong><br>' + appt.service + '</div>';
              });
              tooltip.innerHTML = tooltipHtml;
              div.appendChild(tooltip);
            }

            if (i === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
              div.classList.add('active');
            }

            gridDates.appendChild(div);
          }

          var totalCells = startDay + daysInMonth;
          var nextDays = totalCells <= 35 ? 35 - totalCells : 42 - totalCells;

          for (var i = 1; i <= nextDays; i++) {
            var div = document.createElement('div');
            div.className = 'calendar-date muted';
            div.textContent = i;
            gridDates.appendChild(div);
          }
        }

        if (prevBtn) {
          prevBtn.addEventListener('click', function() {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
          });
        }

        if (nextBtn) {
          nextBtn.addEventListener('click', function() {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
          });
        }

        renderCalendar();
      })();
    </script>
     </div>
  </section>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? MY PETS �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <section id="my-pets" class="content-section">
    <!-- Overview KPIs -->
    <div class="pet-kpis">
      <div class="pet-kpi-card">
        <p class="pet-kpi-label">Total Pets</p>
        <div class="pet-kpi-value" id="sumTotal">0</div>
        <p class="pet-kpi-sub">Registered pets</p>
      </div>
      <div class="pet-kpi-card">
        <p class="pet-kpi-label">Fully Vaccinated</p>
        <div class="pet-kpi-value" id="sumVaccinated">0</div>
        <p class="pet-kpi-sub">Up to date</p>
      </div>
      <div class="pet-kpi-card">
        <p class="pet-kpi-label">Needs Attention</p>
        <div class="pet-kpi-value" id="sumAttention">0</div>
        <p class="pet-kpi-sub">Pending vaccination</p>
      </div>
    </div>

    <?php if (empty($pets)): ?>
      <div class="pet-empty">
        <i class="fas fa-paw"></i>
        <h3>No pets registered yet</h3>
        <p>Register your first pet to start managing its profile.</p>
        <button type="button" class="sd-btn sd-btn-default" onclick="openModal('addPetModal')"><i class="fas fa-plus"></i> Register a Pet</button>
      </div>
    <?php else: ?>
    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="petSearchInput" class="appt-search-input" placeholder="Search by name, breed, or ID">
        </div>
        <select id="petStatusSelect" class="appt-status-select">
          <option value="all">All Categories</option>
          <option value="owned">Owned</option>
          <option value="adopted">Adopted</option>
        </select>
      </div>
      <button type="button" class="sd-btn sd-btn-default" onclick="openModal('addPetModal')" style="margin-left:auto;">
        <i class="fas fa-plus"></i> Register a Pet
      </button>
    </div>

    <div class="appt-db-table-area dt-container">
      <table class="appt-table" id="petTable">
        <thead>
          <tr class="sd-tr">
            <th class="sd-th"><button type="button" class="th-sort" data-col="pet" onclick="PET.sortBy('pet')">Pet<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="petid" onclick="PET.sortBy('petid')">Pet ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="species" onclick="PET.sortBy('species')">Species<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="breed" onclick="PET.sortBy('breed')">Breed<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="vaccination" onclick="PET.sortBy('vaccination')">Vaccination<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="category" onclick="PET.sortBy('category')">Category<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="adopted" onclick="PET.sortBy('adopted')">Date Adopted<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="registered" onclick="PET.sortBy('registered')">Registered Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th sd-th-action">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
                          $pVaxColors = [
                            'Fully vaccinated' => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
                            'Partially vaccinated' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
                            'Not vaccinated' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
                          ];
          foreach ($pets as $pet):
            $pRef = $pet['reference_id'] ?? '#PET-'.str_pad($pet['pet_id'], 4, '0', STR_PAD_LEFT);
            $pCat = $pet['from_adoption'] ? 'adopted' : 'owned';
            $pCatLabel = $pet['from_adoption'] ? 'Adopted' : 'Owned';
            $pVaxText = !empty($pet['vaccination_status']) ? $pet['vaccination_status'] : 'Not vaccinated';
                          $pVaxColor = $pVaxColors[$pVaxText] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e4e4e7'];
            $pPhoto = $pet['photo_url'] ?? '';
            $pInitial = strtoupper(substr($pet['pet_name'], 0, 1));
            $pAdoptedDate = '';
            $pAdoptedRaw = '';
            if ($pet['from_adoption'] && !empty($pet['adopted_date'])) {
                $pAdoptedDate = date('M d, Y', strtotime($pet['adopted_date']));
                $pAdoptedRaw = $pet['adopted_date'];
            }
            $pRegisteredDate = !empty($pet['created_at']) ? date('M d, Y', strtotime($pet['created_at'])) : '-';
            $pRegisteredRaw = $pet['created_at'] ?? '';
          ?>
          <tr class="sd-tr" data-id="<?php echo (int)$pet['pet_id']; ?>" data-cat="<?php echo $pCat; ?>" data-species="<?php echo htmlspecialchars(strtolower($pet['pet_type'])); ?>" data-breed="<?php echo htmlspecialchars(strtolower($pet['breed'] ?? '')); ?>" data-vaccination="<?php echo htmlspecialchars(strtolower($pVaxText)); ?>" data-adopted="<?php echo htmlspecialchars($pAdoptedRaw); ?>" data-registered="<?php echo htmlspecialchars($pRegisteredRaw); ?>" onclick="PET.view(<?php echo (int)$pet['pet_id']; ?>)">
            <td class="sd-td">
              <div class="client-cell">
                <div class="pet-avatar-box"><?php if ($pPhoto): ?><img src="<?php echo htmlspecialchars($pPhoto); ?>" alt=""><?php else: echo $pInitial; endif; ?></div>
                <div class="pet-cell-main">
                  <div class="client-name"><?php echo htmlspecialchars($pet['pet_name']); ?></div>
                </div>
              </div>
            </td>
            <td class="sd-td">
              <div class="pet-id-label"><?php echo htmlspecialchars($pRef); ?></div>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo ucfirst($pet['pet_type']); ?></div>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo htmlspecialchars($pet['breed'] ?: '-'); ?></div>
            </td>
            <td class="sd-td">
              <span class="appt-status-pill" style="background:<?php echo $pVaxColor['bg']; ?>;color:<?php echo $pVaxColor['color']; ?>;border-color:<?php echo $pVaxColor['border']; ?>;"><?php echo htmlspecialchars($pVaxText); ?></span>
            </td>
            <td class="sd-td">
              <span class="pet-cat-tag <?php echo $pCat; ?>"><?php echo $pCatLabel; ?></span>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo $pAdoptedDate ?: '-'; ?></div>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo $pRegisteredDate; ?></div>
            </td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <div class="pet-action-btns">
                <a href="pet-profile.php?id=<?php echo (int)$pet['pet_id']; ?>&ref=resident" class="pet-action-btn" title="Pet Resume">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </a>
                <button type="button" class="pet-action-btn" title="View Profile" onclick="PET.view(<?php echo (int)$pet['pet_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <?php if (!$pet['from_adoption']): ?>
                <button type="button" class="pet-action-btn" title="Edit Pet" onclick="PET.edit(<?php echo (int)$pet['pet_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                </button>
                <?php endif; ?>
                <button type="button" class="pet-action-btn" title="Archive Pet" onclick="PET.archive(<?php echo (int)$pet['pet_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="petPagination" class="appt-pagination"></div>
    <?php endif; ?>
  </section>

  <style>
        /* ?? My Pets (HubSpot CRM style) ?? */
    #my-pets{font-family:'Inter','Segoe UI',sans-serif;}
    /* White page background for Pet List / Reports / Appointments */
    body.page-white{background:#ffffff;}
    #my-pets .pet-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;}
    #my-pets .pet-kpi-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:18px 20px;background:none;border:none;border-radius:0;box-shadow:none;font-family:'Inter','Segoe UI',sans-serif;}
    #my-pets .pet-kpi-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;}
    #my-pets .pet-kpi-value{font-family:'Inter','Segoe UI',sans-serif;font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;}
    #my-pets .pet-kpi-sub{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;margin:0;}
    @media(max-width:1180px){#my-pets .pet-kpis{grid-template-columns:repeat(2,1fr);}}
    @media(max-width:620px){#my-pets .pet-kpis{grid-template-columns:1fr 1fr;}}

    /* sd buttons (shadcn) */
    .sd-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; white-space:nowrap; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease; }
    .sd-btn-default { background:#18181b; color:#fafafa; }
    .sd-btn-default:hover { background:#27272a; }
    .sd-btn-outline { background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    .sd-btn-outline:hover { background:#f4f4f5; }
    .sd-btn:disabled, .sd-btn[disabled] { opacity:0.5; cursor:not-allowed; pointer-events:none; }
    .sd-btn i { font-size:12px; }

    /* shadcn dialog (Register / Edit Pet) */
    #addPetModal.modal, #editPetModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #addPetModal .sd-dialog, #editPetModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #addPetModal .sd-dialog-content, #editPetModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
    #addPetModal .sd-dialog-close, #editPetModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
    #addPetModal .sd-dialog-close:hover, #editPetModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #addPetModal .sd-dialog-header, #editPetModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
    #addPetModal .sd-dialog-title, #editPetModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #addPetModal .sd-dialog-description, #editPetModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #addPetModal .sd-field-group, #editPetModal .sd-field-group { display:flex; flex-direction:column; gap:16px; }
    #addPetModal .sd-form-grid, #editPetModal .sd-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    #addPetModal .sd-field-full, #editPetModal .sd-field-full { grid-column:1 / -1; }
    #addPetModal .sd-field, #editPetModal .sd-field { display:flex; flex-direction:column; gap:6px; }
    #addPetModal .sd-label, #editPetModal .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; }
    #addPetModal .sd-label .req, #editPetModal .sd-label .req { color:#dc2626; }
    #addPetModal .sd-input, #editPetModal .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; background:transparent; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease; box-sizing:border-box; }
    #addPetModal .sd-input::placeholder, #editPetModal .sd-input::placeholder { color:#a1a1aa; }
    #addPetModal .sd-input:focus-visible, #editPetModal .sd-input:focus-visible { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #addPetModal select.sd-input, #editPetModal select.sd-input { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    #addPetModal textarea.sd-input, #editPetModal textarea.sd-input { height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
    #addPetModal .sd-dialog-footer, #editPetModal .sd-dialog-footer { display:flex; flex-direction:row-reverse; justify-content:flex-end; gap:8px; }
    #addPetModal .sd-photo-upload, #editPetModal .sd-photo-upload { position:relative; display:flex; align-items:center; justify-content:center; min-height:90px; border:2px dashed #e4e4e7; border-radius:6px; background:#fafafa; cursor:pointer; transition:border-color 0.15s ease, background 0.15s ease; }
    #addPetModal .sd-photo-upload.has-image, #editPetModal .sd-photo-upload.has-image { border:none; background:transparent; padding:0; min-height:0; }
    #addPetModal .sd-photo-upload img, #editPetModal .sd-photo-upload img { max-height:220px; max-width:100%; border-radius:8px; display:block; margin:0 auto; }
    #addPetModal .sd-photo-upload .upload-placeholder i, #editPetModal .sd-photo-upload .upload-placeholder i { font-size:1.7rem; color:#a1a1aa; display:block; margin-bottom:0.35rem; }
    #addPetModal .sd-photo-upload .upload-placeholder span, #editPetModal .sd-photo-upload .upload-placeholder span { font-size:0.8rem; color:#a1a1aa; }
    #addPetModal .sd-photo-upload input[type="file"], #editPetModal .sd-photo-upload input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }
    /* edit dialog: photo + upload side by side, compact */
    #editPetModal .sd-photo-row { display:flex; align-items:flex-start; gap:10px; }
    #editPetModal .sd-photo-current { width:200px; height:200px; border-radius:8px; overflow:hidden; background:#f4f4f5; display:flex; align-items:center; justify-content:center; border:1px solid #e4e4e7; flex-shrink:0; }
    #editPetModal .sd-photo-current img { width:100%; height:100%; object-fit:cover; }
    #editPetModal .sd-photo-upload { min-height:96px; width:170px; flex:none; }
    #editPetModal .sd-photo-upload .upload-placeholder { display:flex; flex-direction:column; align-items:center; gap:4px; }
    #editPetModal .sd-photo-upload .upload-placeholder i { margin:0; }
    @media (max-width:560px){ #addPetModal .sd-form-grid, #editPetModal .sd-form-grid { grid-template-columns:1fr; } }

    /* ?? HubSpot-style toolbar ?? */
    .appt-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
    .appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .appt-search-wrap{position:relative;}
    .appt-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
    .appt-search-input{height:36px;padding:0 12px 0 36px;width:280px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s,box-shadow .15s;}
    .appt-search-input:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;background:#fff;}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;padding:0 28px 0 12px;border:1px solid #e4e4e7;border-radius:6px;background-color:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:border-color .15s,box-shadow .15s;}
    .appt-status-select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}

    /* ?? HubSpot-style table ?? */
    .appt-db-table-area{overflow:hidden;border:1px solid #e4e4e7;border-radius:8px;background:#fff;padding:0;}
    .appt-table{width:100%;border-collapse:collapse;color:#18181b;font-size:14px;}
    .appt-table thead th{background:#fafafa;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:600;letter-spacing:0.02em;text-transform:uppercase;color:#71717a;height:40px;padding:0 12px;border-bottom:1px solid #e4e4e7;text-align:left;vertical-align:middle;white-space:nowrap;user-select:none;}
    .appt-table thead th.sd-th-action{text-align:right;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:pointer;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody tr.selected{background:#f4f4f5;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}

    /* ?? Sortable column headers ?? */
    .th-sort{display:inline-flex;align-items:center;gap:4px;height:28px;margin-left:-8px;padding:0 8px;border-radius:4px;background:transparent;border:none;font-size:12px;font-weight:600;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;letter-spacing:0.02em;text-transform:uppercase;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
    .th-sorted .th-sort-icon{color:#18181b;}

    /* ?? Pet cell: avatar + underlined name ?? */
    .client-cell{display:flex;align-items:center;gap:10px;min-width:0;}
    .avatar{width:36px;height:36px;border-radius:50%;background:#f4f4f5;color:#71717a;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;overflow:hidden;flex-shrink:0;border:1px solid #e4e4e7;}
    .avatar img{width:100%;height:100%;object-fit:cover;}
    .client-name{font-family:'Inter','Segoe UI',sans-serif;font-weight:500;color:#18181b;font-size:14px;white-space:nowrap;text-decoration:underline;text-decoration-color:transparent;text-underline-offset:2px;transition:text-decoration-color .15s;}
    .sd-tr:hover .client-name{text-decoration-color:#a1a1aa;}
    .client-id{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin-top:2px;}
    .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
    .contact-main.contact-normal{font-weight:500;}
    .appt-status-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7;}

    /* ?? Pet table cells ?? */
    .pet-avatar-box{width:40px;height:40px;border-radius:50%;background:#f4f4f5;color:#71717a;display:inline-flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;overflow:hidden;flex-shrink:0;border:1px solid #e4e4e7;}
    .pet-avatar-box img{width:100%;height:100%;object-fit:cover;}
    .pet-cell-main{display:flex;flex-direction:column;min-width:0;}
    .pet-id-label{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin-top:2px;}
    .pet-cat-cell{display:flex;flex-direction:column;align-items:flex-start;gap:3px;}
    .pet-cat-tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;white-space:nowrap;line-height:1.4;}
    .pet-cat-tag.owned{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
    .pet-cat-tag.adopted{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
    .pet-adopted-date{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;color:#71717a;white-space:nowrap;}

    /* ?? Row action buttons ?? */
    .pet-action-btns{display:inline-flex;align-items:center;gap:2px;}
    .pet-action-btn{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;background:transparent;border:none;color:#a1a1aa;cursor:pointer;text-decoration:none;transition:background .15s,color .15s;}
    .pet-action-btn:hover{background:#f4f4f5;color:#18181b;}
    .pet-action-btn svg{flex-shrink:0;}

    /* row actions */
    .sd-row-actions{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;background:transparent;border:none;color:#71717a;cursor:pointer;}
    .sd-row-actions:hover{background:#f4f4f5;color:#18181b;}
    .appt-more-dropdown{position:relative;display:inline-block;}
    .appt-dropdown{position:absolute;right:0;top:calc(100% + 4px);min-width:180px;background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);padding:6px;display:none;z-index:30;}
    .appt-more-dropdown.open .appt-dropdown{display:block;}
    .appt-dropdown-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:6px;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;white-space:nowrap;}
    .appt-dropdown-item:hover{background:#f4f4f5;}
    .appt-dropdown-item svg{flex-shrink:0;color:#71717a;}

    /* ?? Pagination ?? */
    .appt-pagination{display:flex;align-items:center;justify-content:flex-end;padding:12px 16px;border-top:1px solid #e4e4e7;}
    .dt-pagination-right{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    .dt-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}
    .dt-rows select{height:32px;width:70px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;padding:0 8px;font-size:14px;color:#18181b;cursor:pointer;font-family:inherit;}
    .dt-rows select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
    .dt-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
    .dt-page-btns{display:flex;align-items:center;gap:4px;}
    .dt-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}
    .dt-page-btn:hover:not(:disabled){background:#f4f4f5;}
    .dt-page-btn:disabled{opacity:.5;cursor:not-allowed;}

    .pet-empty{text-align:center;padding:3.5rem 1.5rem;color:#64748b;border:2px dashed #e2e8f0;border-radius:16px;}
    .pet-empty i{font-size:3rem;color:#94a3b8;display:block;margin-bottom:1rem;}
    .pet-empty h3{font-size:1.05rem;font-weight:700;color:#0f172a;margin:0 0 4px;}
    .pet-empty p{font-size:0.85rem;margin:0 0 1.25rem;}
    .pet-empty .sd-btn{margin:0 auto;}

    /* shared sd form controls (modals) */
    .sd-field { display:flex; flex-direction:column; gap:5px; }
    .sd-field-full { grid-column:1 / -1; }
    .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:500; line-height:1; color:#18181b; }
    .sd-label .req { color:#dc2626; }
    .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; background:#fff; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease; box-sizing:border-box; }
    .sd-input::placeholder { color:#a1a1aa; }
    .sd-input:focus-visible { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    select.sd-input { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    textarea.sd-input { height:auto; min-height:96px; padding:10px 12px; resize:vertical; line-height:1.5; }
    .sd-photo-upload { position:relative; display:flex; align-items:center; justify-content:center; min-height:84px; border:2px dashed #e4e4e7; border-radius:8px; background:#fafafa; cursor:pointer; transition:border-color 0.15s ease, background 0.15s ease; }
    .sd-photo-upload:hover { border-color:#18181b; background:#f4f4f5; }
    .sd-photo-upload .upload-placeholder { text-align:center; display:flex; flex-direction:column; align-items:center; gap:4px; }
    .sd-photo-upload .upload-placeholder i { font-size:1.35rem; color:#a1a1aa; display:block; margin:0; }
    .sd-photo-upload .upload-placeholder span { font-size:0.82rem; color:#a1a1aa; }
    .sd-photo-upload input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }

    /* �"?�"? Pet Profile Drawer (shadcn right drawer) �"?�"? */
    .pet-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:998; display:none; }
    .pet-drawer-overlay.open { display:block; }
    .pet-drawer { position:fixed; top:0; right:0; bottom:0; width:min(480px,92vw); background:#fff; z-index:999; box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform 0.32s cubic-bezier(.32,.72,.25,1); display:flex; flex-direction:column; overflow:hidden; }
    .pet-drawer.open { transform:translateX(0); }
    .pet-drawer-head { display:flex; align-items:flex-start; gap:14px; padding:22px 24px; border-bottom:1px solid #e4e4e7; }
    .pet-drawer-avatar { width:72px; height:72px; border-radius:50%; overflow:hidden; background:#e4e4e7; color:#71717a; display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:600; flex-shrink:0; }
    .pet-drawer-avatar img { width:100%; height:100%; object-fit:cover; }
    .pet-drawer-name { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; margin:0; }
    .pet-drawer-ref { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:2px 0 0; }
    .pet-drawer-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:6px 0 0; }
    .pet-drawer-close { margin-left:auto; width:32px; height:32px; border-radius:6px; background:transparent; border:none; color:#71717a; font-size:16px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s; }
    .pet-drawer-close:hover { background:#f4f4f5; color:#18181b; }
    .pet-drawer-body { flex:1; overflow-y:auto; padding:24px; }
    .pet-drawer-tabs { display:flex; gap:4px; background:#f4f4f5; padding:4px; border-radius:8px; margin-bottom:22px; }
    .pet-drawer-tab { flex:1; padding:8px 12px; border:none; border-radius:6px; background:transparent; color:#71717a; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; cursor:pointer; transition:background .15s,color .15s; }
    .pet-drawer-tab.active { background:#fff; color:#18181b; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .pet-drawer-section-title { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; color:#71717a; text-transform:uppercase; letter-spacing:.04em; margin:0 0 12px; }
    .pet-drawer-info { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; margin-bottom:22px; }
    .pet-drawer-info-item-full { grid-column:1 / -1; }
    .pet-drawer-info.rpt-info-3col { grid-template-columns:repeat(3,1fr); }
    @media (max-width:560px){ .pet-drawer-info.rpt-info-3col { grid-template-columns:1fr; } }
    .pet-drawer-info-item .k { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; }
    .pet-drawer-info-item .v { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin-top:2px; word-break:break-word; }
    .pet-vax-box { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; padding:16px 20px; }
    .pet-vax-box .vax-head { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin-bottom:4px; }
    .pet-vax-box .vax-desc { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; line-height:1.4; }
    .pet-vax-box .sd-btn { margin-top:12px; }
    .pet-drawer-foot { display:flex; gap:10px; padding:16px 24px; border-top:1px solid #e4e4e7; }
    .pet-drawer-foot .sd-btn { flex:1; justify-content:center; }
    @media (max-width:768px) {
      .appt-header-right { width:100%; }
      .appt-search-input { width:100%; }
    }
  </style>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? MY REPORTS �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <section id="my-reports" class="content-section">
    <!-- Overview KPIs -->
    <div class="rpt-kpis">
      <div class="rpt-kpi-card">
        <p class="rpt-kpi-label">Total Reports</p>
        <div class="rpt-kpi-value" id="rptTotal">0</div>
        <p class="rpt-kpi-sub">All submitted reports</p>
      </div>
      <div class="rpt-kpi-card">
        <p class="rpt-kpi-label">In Progress</p>
        <div class="rpt-kpi-value" id="rptInProgress">0</div>
        <p class="rpt-kpi-sub">Under review / handled</p>
      </div>
      <div class="rpt-kpi-card">
        <p class="rpt-kpi-label">Resolved</p>
        <div class="rpt-kpi-value" id="rptResolved">0</div>
        <p class="rpt-kpi-sub">Completed cases</p>
      </div>
    </div>

    <?php
    $knownReportTypes = ['stray' => 'Stray Animal', 'rabies' => 'Rabies Case', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal', 'other' => 'Other'];
    function rptStatusLabel($s) {
        switch ($s) {
            case 'pending': return 'Pending';
            case 'validated': return 'Under Review';
            case 'confirmed': return 'Confirmed';
            case 'scheduled': return 'Scheduled';
            case 'resolved': return 'Resolved';
            case 'rejected': return 'Rejected';
            default: return ucfirst(str_replace('_', ' ', $s));
        }
    }
    function rptStatusClass($s) {
        switch ($s) {
            case 'pending': return 'st-pending';
            case 'validated':
            case 'confirmed':
            case 'scheduled': return 'st-review';
            case 'resolved': return 'st-resolved';
            case 'rejected': return 'st-rejected';
            default: return 'st-pending';
        }
    }
    $rptStatusColors = [
        'pending'   => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
        'validated' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
        'confirmed' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
        'scheduled' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
        'resolved'  => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
        'rejected'  => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
    ];
    ?>
    <?php if (empty($reports)): ?>
      <div class="rpt-empty">
        <i class="fas fa-file-medical-alt"></i>
        <h3>No reports submitted yet</h3>
        <p>Submit your first report to help us keep the community safe.</p>
        <button type="button" class="sd-btn sd-btn-default" onclick="openSubmitReportModal()"><i class="fas fa-plus"></i> Submit a Report</button>
      </div>
    <?php else: ?>
    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="reportSearchInput" class="appt-search-input" placeholder="Search by ID, type, or location">
        </div>
        <select id="reportStatusSelect" class="appt-status-select">
          <option value="all">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="review">Under Review</option>
          <option value="resolved">Resolved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <button type="button" class="sd-btn sd-btn-default" onclick="openSubmitReportModal()" style="margin-left:auto;">
        <i class="fas fa-plus"></i> Submit a Report
      </button>
    </div>

    <div class="appt-db-table-area dt-container">
      <table class="appt-table" id="reportTable">
        <thead>
          <tr class="sd-tr">
            <th class="sd-th"><button type="button" class="th-sort" data-col="ref" onclick="RPT.sortBy('ref')">Report ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="type" onclick="RPT.sortBy('type')">Type<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="location" onclick="RPT.sortBy('location')">Location<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="date" onclick="RPT.sortBy('date')">Date Submitted<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="RPT.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="category" onclick="RPT.sortBy('category')">Category<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="resolved" onclick="RPT.sortBy('resolved')">Date Resolved<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th sd-th-action">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $report):
            $rRef = $report['reference_id'] ?? '#RPT-'.str_pad($report['report_id'], 4, '0', STR_PAD_LEFT);
            $rType = $report['report_type'];
            $rTypeLabel = $knownReportTypes[strtolower($rType)] ?? ucfirst($rType);
            $rStatus = $report['status'];
            $rStatusLabel = rptStatusLabel($rStatus);
            $rStatusClass = rptStatusClass($rStatus);
            $rsc = $rptStatusColors[$rStatus] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e4e4e7'];
            $rDate = date('M d, Y', strtotime($report['created_at']));
            $rResolvedDate = ($rStatus === 'resolved' && !empty($report['updated_at'])) ? date('M d, Y', strtotime($report['updated_at'])) : '-';
            $rResolvedRaw = ($rStatus === 'resolved' && !empty($report['updated_at'])) ? $report['updated_at'] : '';
          ?>
          <tr class="sd-tr" data-id="<?php echo (int)$report['report_id']; ?>" data-status="<?php echo $rStatus; ?>" data-statuscls="<?php echo $rStatusClass; ?>" data-type="<?php echo htmlspecialchars(strtolower($rTypeLabel)); ?>" data-location="<?php echo htmlspecialchars(strtolower($report['location_address'])); ?>" data-date="<?php echo (int)strtotime($report['created_at']); ?>" data-resolved="<?php echo htmlspecialchars($rResolvedRaw); ?>" onclick="RPT.view(<?php echo (int)$report['report_id']; ?>)">
            <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($rRef); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($rTypeLabel); ?> Report</div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($report['location_address']); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $rDate; ?></div></td>
            <td class="sd-td"><span class="appt-status-pill" style="background:<?php echo $rsc['bg']; ?>;color:<?php echo $rsc['color']; ?>;border-color:<?php echo $rsc['border']; ?>;"><?php echo $rStatusLabel; ?></span></td>
            <td class="sd-td"><span class="pet-cat-tag"><?php echo htmlspecialchars($rTypeLabel); ?></span></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $rResolvedDate; ?></div></td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <div class="pet-action-btns">
                <button type="button" class="pet-action-btn" title="View Report" onclick="RPT.view(<?php echo (int)$report['report_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button type="button" class="pet-action-btn" title="Archive Report" onclick="RPT.archive(<?php echo (int)$report['report_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="reportPagination" class="appt-pagination"></div>
    <?php endif; ?>
  </section>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? SUBMIT A REPORT �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <style>
    /* �"?�"? My Reports (My Pets design) �"?�"? */
    #my-reports { font-family:'Inter','Segoe UI',sans-serif; }
    #my-reports .rpt-kpis{ display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px; }
    #my-reports .rpt-kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:18px 20px; background:none; border:none; border-radius:0; box-shadow:none; font-family:'Inter','Segoe UI',sans-serif; }
    #my-reports .rpt-kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; text-transform:uppercase; letter-spacing:0.03em; }
    #my-reports .rpt-kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #my-reports .rpt-kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }

    .rpt-empty { text-align:center; padding:3.5rem 1.5rem; color:#64748b; border:2px dashed #e2e8f0; border-radius:16px; }
    .rpt-empty i { font-size:3rem; color:#94a3b8; display:block; margin-bottom:1rem; }
    .rpt-empty h3 { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0 0 4px; }
    .rpt-empty p { font-size:0.85rem; margin:0 0 1.25rem; }
    .rpt-empty .sd-btn { margin:0 auto; }

    /* �"?�"? Report Details Drawer (shadcn right drawer) �"?�"? */
    .rpt-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:998; display:none; }
    .rpt-drawer-overlay.open { display:block; }
    .rpt-drawer { position:fixed; top:0; right:0; bottom:0; width:min(480px,92vw); background:#fff; z-index:999; box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform 0.32s cubic-bezier(.32,.72,.25,1); display:flex; flex-direction:column; overflow:hidden; }
    .rpt-drawer.open { transform:translateX(0); }
    .rpt-drawer-head { display:flex; align-items:flex-start; gap:14px; padding:22px 24px; border-bottom:1px solid #e4e4e7; }
    .rpt-drawer-title { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .rpt-drawer-ref { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:3px 0 0; }
    .rpt-drawer-close { margin-left:auto; width:32px; height:32px; border-radius:6px; background:transparent; border:none; color:#71717a; font-size:16px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s; flex-shrink:0; }
    .rpt-drawer-close:hover { background:#f4f4f5; color:#18181b; }
    .rpt-drawer-body { flex:1; overflow-y:auto; padding:24px; }
    .rpt-drawer-section-title { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; color:#71717a; text-transform:uppercase; letter-spacing:.04em; margin:20px 0 12px; }
    .rpt-drawer-section-title:first-child { margin-top:0; }
    .rpt-detail-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; margin-bottom:2px; }
    .rpt-detail-value { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; line-height:1.45; word-break:break-word; }
    .rpt-detail-block { margin-bottom:1rem; }
    .rpt-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
    @media (max-width:560px){ .rpt-info-grid { grid-template-columns:1fr; } }
    .rpt-photos { display:flex; gap:12px; flex-wrap:wrap; }
    .rpt-photo-thumb { width:100%; height:220px; border-radius:8px; overflow:hidden; cursor:pointer; background:#f4f4f5; display:flex; align-items:center; justify-content:center; color:#71717a; border:1px solid #e4e4e7; }
    .rpt-photo-thumb img { width:100%; height:100%; object-fit:cover; }
    .rpt-status-line { display:flex; align-items:center; gap:8px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .rpt-status-line i { color:#057b2d; }
    /* appointment drawer sections */
    .rpt-divider { border:0; border-top:1px solid #e4e4e7; margin:20px 0; }
    #apptDrawer .rpt-drawer-foot { justify-content:center; }
    #apptDrawer .rpt-drawer-foot .sd-btn { flex:0 0 auto; }
    /* drawer status badge + timeline */
    .rpt-status-badge { display:inline-flex; align-items:center; gap:6px; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap; }
    .rpt-status-badge .st-dot { width:7px; height:7px; border-radius:50%; }
    .rpt-status-badge.st-pending { background:#fef3c7; color:#92400e; } .rpt-status-badge.st-pending .st-dot { background:#d97706; }
    .rpt-status-badge.st-review { background:#dbeafe; color:#1d4ed8; } .rpt-status-badge.st-review .st-dot { background:#2563eb; }
    .rpt-status-badge.st-resolved { background:#dcebe0; color:#045e1f; } .rpt-status-badge.st-resolved .st-dot { background:#057b2d; }
    .rpt-status-badge.st-rejected { background:#fee2e2; color:#991b1b; } .rpt-status-badge.st-rejected .st-dot { background:#dc2626; }
    .rpt-timeline { display:flex; align-items:center; gap:8px; }
    .tl-step { display:inline-flex; align-items:center; gap:5px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#a1a1aa; }
    .tl-step i { font-size:12px; }
    .tl-step.done { color:#057b2d; }
    .tl-step.current { color:#2563eb; }
    .tl-sep { flex:0 0 28px; height:2px; background:#e4e4e7; border-radius:2px; }
    .rpt-timeline-rejected .tl-step.done { color:#991b1b; }
    .rpt-next { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; padding:16px 20px; }
    .rpt-next-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; margin-bottom:8px; }
    .rpt-next ol { margin:0; padding-left:1.15rem; }
    .rpt-next li { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; line-height:1.5; }
    .rpt-drawer-foot { display:flex; gap:10px; padding:16px 24px; border-top:1px solid #e4e4e7; align-items:center; }
    .rpt-drawer-foot .sd-btn { flex:1; justify-content:center; }
    .rpt-more-wrap { position:relative; }
    .rpt-more-btn { width:32px; height:32px; border-radius:6px; border:none; background:transparent; color:#71717a; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
    .rpt-more-btn:hover { background:#f4f4f5; color:#18181b; }
    .rpt-more-pop { position:absolute; bottom:calc(100% + 6px); right:0; background:#fff; border:1px solid #e4e4e7; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12); padding:6px; min-width:180px; display:none; z-index:30; }
    .rpt-more-pop.open { display:block; }
    .rpt-more-item { display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:none; background:none; border-radius:6px; font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:500; color:#18181b; cursor:pointer; text-align:left; }
    .rpt-more-item:hover { background:#f4f4f5; color:#18181b; }
    .rpt-more-item.danger { color:#dc2626; } .rpt-more-item.danger:hover { background:#fef2f2; }
    .rpt-more-item i { width:16px; text-align:center; }

    /* �"?�"? Submit Report wizard �"?�"? */
    .rpt-step-indicator { display:flex; align-items:center; gap:8px; margin-bottom:1.5rem; }
    .rpt-step { display:flex; align-items:center; gap:6px; }
    .rpt-step-num { width:26px; height:26px; border-radius:50%; background:#e2e8f0; color:#64748b; display:inline-flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700; transition:all 0.2s; }
    .rpt-step-label { font-size:0.72rem; font-weight:600; color:#94a3b8; }
    .rpt-step.active .rpt-step-num { background:#045e1f; color:#fff; }
    .rpt-step.active .rpt-step-label { color:#0f172a; }
    .rpt-step.done .rpt-step-num { background:#057b2d; color:#fff; }
    .rpt-step-line { flex:1; height:2px; background:#e2e8f0; border-radius:2px; }
    .rpt-step-heading { font-size:0.95rem; font-weight:700; color:#0f172a; margin:0 0 0.9rem; }
    .rpt-type-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.6rem; }
    .rpt-type-card { display:flex; flex-direction:column; align-items:center; gap:6px; padding:1rem; border:1.5px solid #e2e8f0; border-radius:12px; cursor:pointer; text-align:center; transition:all 0.15s; background:#fff; }
    .rpt-type-card:hover { border-color:#045e1f; background:#f0fdf4; }
    .rpt-type-card input { display:none; }
    .rpt-type-card:has(input:checked) { border-color:#045e1f; background:#f0fdf4; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    .rpt-type-icon i { font-size:1.4rem; color:#64748b; }
    .rpt-type-card:has(input:checked) .rpt-type-icon i { color:#045e1f; }
    .rpt-type-text { font-size:0.8rem; font-weight:600; color:#334155; }
    .modal-form-control { width:100%; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; outline:none; transition:border-color 0.2s; box-sizing:border-box; font-family:inherit; background:#fff; }
    .modal-form-control:focus { border-color:#045e1f; box-shadow:0 0 0 3px rgba(4,94,31,0.1); }
    .rpt-upload-area { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; padding:2rem 1rem; border:2px dashed #e2e8f0; border-radius:12px; background:#f8fafc; cursor:pointer; text-align:center; transition:all 0.2s; }
    .rpt-upload-area:hover { border-color:#045e1f; background:#f0fdf4; }
    .rpt-upload-area i { font-size:1.8rem; color:#94a3b8; }
    .rpt-upload-title { font-size:0.88rem; font-weight:600; color:#334155; }
    .rpt-upload-sub { font-size:0.78rem; color:#94a3b8; }
    .rpt-upload-area input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }

    /* Submit Report dialog (shadcn) */
    #submitReportModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #submitReportModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #submitReportModal .sd-dialog-content { position:relative; width:100%; max-width:620px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
    #submitReportModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
    #submitReportModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #submitReportModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
    #submitReportModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #submitReportModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #submitReportModal .sd-dialog-footer { display:flex; align-items:center; gap:8px; }
    #submitReportModal .modal-form-control { height:36px; padding:0 12px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    #submitReportModal textarea.modal-form-control { height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
    #submitReportModal select.modal-form-control { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    #submitReportModal .modal-form-control:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #submitReportModal .form-group label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; display:block; margin-bottom:6px; }
    #submitReportModal .form-group label .req { color:#dc2626; }
    #submitReportModal .rpt-type-card { border:1px solid #e4e4e7; border-radius:6px; }
    #submitReportModal .rpt-type-card:hover { border-color:#18181b; background:#f4f4f5; }
    #submitReportModal .rpt-type-card:has(input:checked) { border-color:#18181b; background:#f4f4f5; box-shadow:0 0 0 1px #18181b; }
    #submitReportModal .rpt-upload-area { border:2px dashed #e4e4e7; border-radius:6px; background:#fafafa; }
    #submitReportModal .rpt-upload-area:hover { border-color:#18181b; background:#f4f4f5; }
    #submitReportModal .rpt-step-num { background:#e4e4e7; color:#71717a; }
    #submitReportModal .rpt-step.active .rpt-step-num { background:#18181b; color:#fafafa; }
    #submitReportModal .rpt-step.done .rpt-step-num { background:#18181b; color:#fafafa; }
    #submitReportModal .rpt-step-line { background:#e4e4e7; }
    #submitReportModal .modal-notice { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; color:#71717a; }
    @media (max-width:560px){ .rpt-type-grid { grid-template-columns:1fr; } }
    @media (max-width:1180px){ #my-reports .rpt-kpis{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:768px) {
      #my-reports .rpt-kpis { grid-template-columns:1fr 1fr; }
      .appt-header-right { width:100%; }
      .appt-search-input { width:100%; }
    }

    /* �"?�"? Appointments (My Reports design) �"?�"? */
    #appointments { font-family:'Inter','Segoe UI',sans-serif; }
    #appointments .appt-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    #appointments .appt-kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:18px 20px; background:none; border:none; border-radius:0; box-shadow:none; font-family:'Inter','Segoe UI',sans-serif; }
    #appointments .appt-kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; text-transform:uppercase; letter-spacing:0.03em; }
    #appointments .appt-kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #appointments .appt-kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    @media (max-width:1180px){ #appointments .appt-kpis{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:768px){ #appointments .appt-kpis{ grid-template-columns:1fr 1fr; } }
    @media (max-width:480px){ #appointments .appt-kpis{ grid-template-columns:1fr; } }

    /* Request Appointment dialog (shadcn) */
    /* Edit Appointment dialog (shadcn) */
    #editAppointmentModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #editAppointmentModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #editAppointmentModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
    #editAppointmentModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
    #editAppointmentModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #editAppointmentModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
    #editAppointmentModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #editAppointmentModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #editAppointmentModal .sd-dialog-footer { display:flex; justify-content:flex-end; gap:8px; }
    #editAppointmentModal .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; background:#fff; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:border-color 0.15s, box-shadow 0.15s; box-sizing:border-box; }
    #editAppointmentModal select.sd-input { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    #editAppointmentModal textarea.sd-input { height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
    #editAppointmentModal .sd-input:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #editAppointmentModal .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin-bottom:6px; display:block; }
    #editAppointmentModal .sd-label .req { color:#dc2626; }
    @media (max-width:560px){ #editAppointmentModal .sd-form-grid { grid-template-columns:1fr; } }
  </style>

<!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? APPOINTMENTS �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <section id="calendar-booking" class="content-section">
    <?php include 'calendar-booking.php'; ?>
  </section>
  <section id="appointments" class="content-section">
    <!-- Overview KPIs -->
    <div class="appt-kpis">
      <div class="appt-kpi-card">
        <p class="appt-kpi-label">Total Appointments</p>
        <div class="appt-kpi-value" id="apptTotal">0</div>
        <p class="appt-kpi-sub">All requests</p>
      </div>
      <div class="appt-kpi-card">
        <p class="appt-kpi-label">Pending</p>
        <div class="appt-kpi-value" id="apptPending">0</div>
        <p class="appt-kpi-sub">Awaiting decision</p>
      </div>
      <div class="appt-kpi-card">
        <p class="appt-kpi-label">Scheduled</p>
        <div class="appt-kpi-value" id="apptScheduled">0</div>
        <p class="appt-kpi-sub">Confirmed bookings</p>
      </div>
      <div class="appt-kpi-card">
        <p class="appt-kpi-label">Completed</p>
        <div class="appt-kpi-value" id="apptCompleted">0</div>
        <p class="appt-kpi-sub">Finished</p>
      </div>
    </div>

    <?php
    $apptStatusColors = [
        'pending'     => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
        'scheduled'   => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
        'rescheduled' => ['bg' => '#ede9fe', 'color' => '#6d28d9', 'border' => '#ddd6fe'],
        'completed'   => ['bg' => '#e2e8f0', 'color' => '#475569', 'border' => '#cbd5e1'],
        'cancelled'   => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'],
        'no_show'     => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
    ];
    ?>
    <?php if (empty($appointments)): ?>
      <div class="rpt-empty">
        <i class="fas fa-calendar-alt"></i>
        <h3>No appointments yet</h3>
        <p>Request an appointment to get started.</p>
        <button type="button" class="sd-btn sd-btn-default" onclick="openBookApptModal()"><i class="fas fa-plus"></i> Request Appointment</button>
      </div>
    <?php else: ?>
    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="apptSearchInput" class="appt-search-input" placeholder="Search by ID, service, or pet">
        </div>
        <select id="apptStatusSelect" class="appt-status-select">
          <option value="all">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="scheduled">Scheduled</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
          <option value="rescheduled">Rescheduled</option>
        </select>
      </div>
      <button type="button" class="sd-btn sd-btn-default" onclick="openBookApptModal()" style="margin-left:auto;">
        <i class="fas fa-plus"></i> Request Appointment
      </button>
    </div>

    <div class="appt-db-table-area dt-container">
      <table class="appt-table" id="apptTable">
        <thead>
          <tr class="sd-tr">
            <th class="sd-th"><button type="button" class="th-sort" data-col="ref" onclick="APPT.sortBy('ref')">Request ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="service" onclick="APPT.sortBy('service')">Service<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="pet" onclick="APPT.sortBy('pet')">Pet<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="date" onclick="APPT.sortBy('date')">Preferred Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="time" onclick="APPT.sortBy('time')">Preferred Time<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="submitted" onclick="APPT.sortBy('submitted')">Submitted Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="submittedtime" onclick="APPT.sortBy('submittedtime')">Submitted Time<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="APPT.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th sd-th-action">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $appt):
            $aptRef = $appt['reference_id'] ?? fallbackRefId('#APT-', $appt['appointment_id']);
            $ast = $appt['status'];
            $asc = $apptStatusColors[$ast] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e4e4e7'];
            $astLabel = ucfirst(str_replace('_', ' ', $ast));
            $aDate = date('M d, Y', strtotime($appt['preferred_date']));
            $aTime = date('g:i A', strtotime($appt['preferred_time']));
            $aCreatedDate = date('M d, Y', strtotime($appt['created_at']));
            $aCreatedTime = date('g:i A', strtotime($appt['created_at']));
          ?>
          <tr class="sd-tr" data-id="<?php echo (int)$appt['appointment_id']; ?>" data-status="<?php echo htmlspecialchars($ast); ?>" data-service="<?php echo htmlspecialchars(strtolower($appt['appointment_type'])); ?>" data-pet="<?php echo htmlspecialchars(strtolower($appt['pet_name'] ?? '')); ?>" data-date="<?php echo (int)strtotime($appt['preferred_date']); ?>" data-time="<?php echo (int)strtotime($appt['preferred_time']); ?>" data-submitted="<?php echo (int)strtotime($appt['created_at']); ?>" onclick="APPT.view(<?php echo (int)$appt['appointment_id']; ?>)">
            <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($aptRef); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo ucfirst($appt['appointment_type']); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($appt['pet_name'] ?? '-'); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aDate; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aTime; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aCreatedDate; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aCreatedTime; ?></div></td>
            <td class="sd-td"><span class="appt-status-pill" style="background:<?php echo $asc['bg']; ?>;color:<?php echo $asc['color']; ?>;border-color:<?php echo $asc['border']; ?>;"><?php echo $astLabel; ?></span></td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <div class="pet-action-btns">
                <?php if ($appt['status'] === 'pending'): ?>
                <button type="button" class="pet-action-btn" title="Edit Appointment" onclick="editAppointment(this)"
                  data-appointment-id="<?php echo $appt['appointment_id']; ?>"
                  data-appointment-type="<?php echo htmlspecialchars($appt['appointment_type']); ?>"
                  data-pet-id="<?php echo htmlspecialchars($appt['pet_id'] ?? ''); ?>"
                  data-preferred-date="<?php echo htmlspecialchars($appt['preferred_date']); ?>"
                  data-preferred-time="<?php echo htmlspecialchars($appt['preferred_time']); ?>"
                  data-notes="<?php echo htmlspecialchars($appt['notes'] ?? ''); ?>">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                </button>
                <?php endif; ?>
                <button type="button" class="pet-action-btn" title="Archive Appointment" onclick="APPT.archive(<?php echo (int)$appt['appointment_id']; ?>)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="apptPagination" class="appt-pagination"></div>
    <?php endif; ?>
  </section>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? BOOK AN APPOINTMENT (page) �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  <section id="book-appt" class="content-section">
    <div class="bk-page">
        <div class="bk-heading">
          <h2 class="bk-heading-title">Book an Appointment for Your Pet</h2>
          <p class="bk-heading-sub">Select your pet, service, date, and preferred time.</p>
        </div>

        <div class="bk-body">
          <!-- LEFT column: calendar -->
          <div class="bk-col bk-col-left">
            <div class="bk-cal-area">
              <div class="bk-cal">
                <div class="bk-cal-nav">
                  <button type="button" id="bkPrevBtn" class="bk-cal-btn" aria-label="Previous month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                  <span class="bk-cal-title" id="bkMonthLabel"></span>
                  <button type="button" id="bkNextBtn" class="bk-cal-btn" aria-label="Next month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
                </div>
                <div class="bk-weekdays">
                  <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                </div>
                <div class="bk-days" id="bkGridDates"></div>
              </div>
            </div>
            <div class="bk-cal-hint" id="bkSlotsHint">Select your preferred appointment date.</div>
          </div>

          <!-- RIGHT column: pet, service, available time -->
          <div class="bk-col bk-col-right">
            <div class="bk-dropdowns">
              <div class="bk-field">
                <label class="bk-label">Select Pet <span class="req">*</span></label>
                <div class="bk-select">
                  <select id="petSelect" name="pet" required>
                    <option value="">Select your pet</option>
                    <?php if (empty($pets)): ?>
                      <option value="" disabled>No pets registered yet</option>
                    <?php else: ?>
                      <?php foreach ($pets as $pet): ?>
                        <option value="<?php echo $pet['pet_id']; ?>" data-photo="<?php echo htmlspecialchars($pet['photo_url'] ?? ''); ?>" data-type="<?php echo htmlspecialchars($pet['pet_type']); ?>" data-age="<?php echo (int)$pet['age']; ?>" data-breed="<?php echo htmlspecialchars($pet['breed'] ?? ''); ?>" data-gender="<?php echo htmlspecialchars($pet['gender']); ?>"><?php echo htmlspecialchars($pet['pet_name']); ?> (<?php echo ucfirst($pet['pet_type']); ?>)</option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                  <svg class="bk-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
              <div class="bk-field">
                <label class="bk-label">Select Service <span class="req">*</span></label>
                <div class="bk-select">
                  <select id="apptTypeSelect" name="appt_type" required>
                    <option value="">Select service</option>
                    <option value="adoption">Adoption</option>
                    <option value="kapon">Kapon (Spay/Neuter)</option>
                  </select>
                  <svg class="bk-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
            </div>

            <!-- Pet info (shown once a pet is selected) -->
            <div class="bk-petprofile">
              <div class="bk-petprofile-filled" id="bkPetProfileFilled">
                <div class="bk-petprofile-avatar" id="bkPetAvatar"><i class="fas fa-paw"></i></div>
                <div class="bk-petprofile-info">
                  <p class="bk-petprofile-eyebrow">Booking For</p>
                  <h2 class="bk-petprofile-name" id="bkPetName">Your Pet</h2>
                  <p class="bk-petprofile-meta" id="bkPetMeta"></p>
                </div>
              </div>
            </div>

            <!-- Available Time -->
            <div class="bk-slots-wrap">
              <div class="bk-slots-title">Available Time</div>
              <div class="bk-slot-grid" id="bkSlots"></div>
            </div>
          </div>
        </div>

        <!-- Bottom actions -->
        <div class="bk-actions">
          <button type="button" class="sd-btn sd-btn-outline" onclick="switchSection('appointments')">Cancel</button>
          <button type="button" class="sd-btn sd-btn-default" id="bkNextStep" disabled>Next <span class="bk-next-arrow">&rarr;</span></button>
        </div>
    </div>
  </section>

  <!-- ?????????? CONFIRM BOOKING (dialog) ?????????? -->
  <div class="modal" id="confirmBookingModal">
    <form class="modal-form sd-dialog" id="appointmentForm" action="request-appointment.php" method="POST">
      <input type="hidden" name="return_section" value="appointments">
      <input type="hidden" name="pet" id="cfPet">
      <input type="hidden" name="appt_type" id="cfType">
      <input type="hidden" name="appt_date" id="apptDate">
      <input type="hidden" name="appt_time" id="apptTime">
      <div class="sd-dialog-content" role="dialog" aria-modal="true">
        <button type="button" class="sd-dialog-close" onclick="closeModal('confirmBookingModal')" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title">Confirm Booking</h2>
          <p class="sd-dialog-description">Review your pet, service, and schedule details below.</p>
        </div>
        <div class="sd-field-group">
          <!-- Pet -->
          <div class="bk-confirm-section">
            <p class="sd-label">Pet</p>
            <div class="bk-confirm-summary">
              <div class="bk-confirm-avatar" id="bkConfirmAvatar"><i class="fas fa-paw"></i></div>
              <div>
                <h4 id="bkConfirmPetName">Your Pet</h4>
                <p id="bkConfirmPetType"></p>
              </div>
            </div>
          </div>
          <!-- Appointment Details -->
          <div class="bk-confirm-section">
            <p class="sd-label">Appointment Details</p>
            <div class="bk-confirm-details">
              <div class="bk-detail-row"><span>Date</span><strong id="bkDetailDate">-</strong></div>
              <div class="bk-detail-row"><span>Time</span><strong id="bkDetailTime">-</strong></div>
              <div class="bk-detail-row"><span>Service Type</span><strong id="bkDetailType">-</strong></div>
              <div class="bk-detail-row"><span>Available For</span><strong id="bkDetailAvailableFor">-</strong></div>
              <div class="bk-detail-row"><span>Service Fee</span><strong>Free / No Fee</strong></div>
              <div class="bk-detail-row"><span>Estimated Duration</span><strong>30-60 minutes</strong></div>
              <div class="bk-detail-row"><span>Service Location</span><strong>Dasmari�as City Veterinary Services</strong></div>
              <div class="bk-detail-row"><span>Appointment Required</span><strong>Yes</strong></div>
            </div>
          </div>
          <!-- Notes -->
          <div class="bk-confirm-section">
            <p class="sd-label">Notes / Reason</p>
            <textarea name="notes" rows="3" class="sd-input" style="height:auto;min-height:70px;resize:vertical;line-height:1.5;" placeholder="Any special notes or reasons for the appointment..."></textarea>
          </div>
        </div>
        <div class="sd-dialog-footer">
          <button type="submit" class="sd-btn sd-btn-default">Confirm Booking</button>
          <button type="button" class="sd-btn sd-btn-outline" id="bkEditDetails">Edit Details</button>
        </div>
      </div>
    </form>
  </div>

<!-- ?????????? ARCHIVE ?????????? -->
  <section id="archive" class="content-section">
<!-- Archived Pets -->
    <div class="archive-header">
      <div class="archive-heading">
        <h2 class="archive-title" id="archiveSectionTitle">Archived Pets (<?php echo count($archivedPets); ?>)</h2>
      </div>
      <div class="filter-bar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="archiveSearch" placeholder="Search..." onkeyup="filterArchive()">
        </div>
        <select class="filter-select" id="archiveTypeFilter" onchange="filterArchive()">
          <option value="">All Types</option>
          <option value="pet">Pets</option>
          <option value="report">Reports</option>
          <option value="appointment">Appointments</option>
        </select>
      </div>
    </div>
    <div class="archive-section" data-type="pet" data-count="<?php echo count($archivedPets); ?>">
      <?php if (empty($archivedPets)): ?>
        <div class="archive-empty">
          <i class="fas fa-archive"></i>
          <p>No archived pets.</p>
        </div>
      <?php else: ?>
        <div class="appt-db-table-area dt-container">
          <table class="appt-table">
            <thead>
              <tr class="sd-tr">
                <th class="sd-th">ID</th>
                <th class="sd-th">Name</th>
                <th class="sd-th">Species</th>
                <th class="sd-th">Breed</th>
                <th class="sd-th">Gender</th>
                <th class="sd-th">Age</th>
                <th class="sd-th">Photo</th>
                <th class="sd-th">Registered</th>
                <th class="sd-th">Archived</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($archivedPets as $pet): ?>
                <tr class="sd-tr">
                  <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($pet['reference_id'] ?? '#'); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($pet['pet_name']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo ucfirst($pet['pet_type']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($pet['breed'] ?? '-'); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo ucfirst($pet['gender']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo $pet['age']; ?> yrs</div></td>
                  <td class="sd-td">
                    <?php if (!empty($pet['photo_url'])): ?>
                      <button type="button" class="sd-btn sd-btn-outline" onclick="viewPetImage('<?php echo htmlspecialchars($pet['photo_url']); ?>')"><i class="fas fa-image"></i> View</button>
                    <?php else: ?>
                      <span style="color:#9ca3af;font-size:0.85rem;">No photo</span>
                    <?php endif; ?>
                  </td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($pet['created_at'])); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($pet['archived_at'])); ?></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Archived Reports -->
    <div class="archive-section" data-type="report" data-count="<?php echo count($archivedReports); ?>">
      <?php if (empty($archivedReports)): ?>
        <div class="archive-empty">
          <i class="fas fa-archive"></i>
          <p>No archived reports.</p>
        </div>
      <?php else: ?>
        <div class="appt-db-table-area dt-container">
          <table class="appt-table">
            <thead>
              <tr class="sd-tr">
                <th class="sd-th">ID</th>
                <th class="sd-th">Type</th>
                <th class="sd-th">Location</th>
                <th class="sd-th">Submitted</th>
                <th class="sd-th">Status</th>
                <th class="sd-th">Photo</th>
                <th class="sd-th">Archived</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($archivedReports as $report): ?>
                <tr class="sd-tr">
                  <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($report['reference_id'] ?? '#'); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($report['report_type']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo htmlspecialchars($report['location_address']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></div></td>
                  <td class="sd-td"><span class="appt-status-pill" style="background:<?php echo ($rptStatusColors[strtolower($report['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['bg']; ?>;color:<?php echo ($rptStatusColors[strtolower($report['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['color']; ?>;border-color:<?php echo ($rptStatusColors[strtolower($report['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['border']; ?>;"><?php echo ucfirst($report['status']); ?></span></td>
                  <td class="sd-td">
                    <?php if (!empty($report['photo_url'])): ?>
                      <button type="button" class="sd-btn sd-btn-outline" onclick="viewReportImage('<?php echo htmlspecialchars($report['photo_url']); ?>')"><i class="fas fa-image"></i> View</button>
                    <?php else: ?>
                      <span style="color:#9ca3af;font-size:0.85rem;">No photo</span>
                    <?php endif; ?>
                  </td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($report['archived_at'])); ?></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Archived Appointments -->
    <div class="archive-section" data-type="appointment" data-count="<?php echo count($archivedAppointments); ?>">
      <?php if (empty($archivedAppointments)): ?>
        <div class="archive-empty">
          <i class="fas fa-archive"></i>
          <p>No archived appointments.</p>
        </div>
      <?php else: ?>
        <div class="appt-db-table-area dt-container">
          <table class="appt-table">
            <thead>
              <tr class="sd-tr">
                <th class="sd-th">ID</th>
                <th class="sd-th">Type</th>
                <th class="sd-th">Appointment Date</th>
                <th class="sd-th">Time</th>
                <th class="sd-th">Status</th>
                <th class="sd-th">Archived</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($archivedAppointments as $appt): ?>
                <tr class="sd-tr">
                  <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($appt['reference_id'] ?? '#'); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo ucfirst($appt['appointment_type']); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($appt['preferred_date'])); ?></div></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('h:i A', strtotime($appt['preferred_time'])); ?></div></td>
                  <td class="sd-td"><span class="appt-status-pill" style="background:<?php echo ($apptStatusColors[strtolower($appt['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['bg']; ?>;color:<?php echo ($apptStatusColors[strtolower($appt['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['color']; ?>;border-color:<?php echo ($apptStatusColors[strtolower($appt['status'])] ?? ['bg'=>'#f4f4f5','color'=>'#52525b','border'=>'#e4e4e7'])['border']; ?>;"><?php echo ucfirst($appt['status']); ?></span></td>
                  <td class="sd-td"><div class="contact-main contact-normal"><?php echo date('M d, Y', strtotime($appt['archived_at'])); ?></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? ADOPTION LISTINGS �"?�"?�"?�"?�"?�"?�"?�"?�"?�"? -->
  

<?php
// Adoption & Scheduling workspace data (actual database records)
$vetPets = array();
if (!empty($adoptionListings)) {
    $pids = array_unique(array_filter(array_map(function ($l) { return (int)$l['pet_id']; }, $adoptionListings)));
    $vaxMap = array();
    if (!empty($pids) && $conn) {
        $in = implode(',', $pids);
        $vq = $conn->prepare("SELECT pet_id, vaccination_status, microchip_id FROM pets WHERE pet_id IN ($in)");
        if ($vq) {
            $vq->execute();
            $vr = $vq->get_result();
            while ($row = $vr->fetch_assoc()) $vaxMap[(int)$row['pet_id']] = $row;
            $vq->close();
        }
    }
    $apptsByPet = array();
    foreach ($appointments as $a) $apptsByPet[(int)$a['pet_id']][] = $a;
    $apptTypeLabels = array('adoption' => 'Adoption', 'kapon' => 'Kapon', 'vaccination' => 'Vaccination', 'checkup' => 'Checkup');
    $ownerName = isset($fullName) && $fullName !== '' ? $fullName : 'Resident';
    $animalNames = array('horse', 'cat', 'dog', 'rabbit', 'bird');
    $dash = "\u{2014}";
    foreach ($adoptionListings as $l) {
        $pid = (int)$l['pet_id'];
        $pname = isset($l['pet_name']) && $l['pet_name'] !== '' ? $l['pet_name'] : 'Pet';
        $pTypeRaw = strtolower(trim((string)($l['pet_type'] ?? 'dog')));
        $species = ucfirst($pTypeRaw);
        $breedVal = trim((string)($l['breed'] ?? ''));
        if ($breedVal !== '' && in_array(strtolower($breedVal), $animalNames)) {
            $species = ucfirst(strtolower($breedVal));
            $breedVal = '';
        }
        $breed = $breedVal !== '' ? $breedVal : $dash;
        $gender = isset($l['gender']) && $l['gender'] !== '' ? ucfirst($l['gender']) : $dash;
        $ageN = (int)($l['age'] ?? 0);
        $age = $ageN > 0 ? ($ageN . ' year' . ($ageN > 1 ? 's' : '')) : $dash;
        $lStatus = strtolower(trim((string)($l['status'] ?? '')));
        $apptStatus = strtolower(trim((string)($l['appt_status'] ?? '')));
        $st = 'adopted';



        $ref = isset($l['pet_ref_id']) && $l['pet_ref_id'] !== '' ? $l['pet_ref_id'] : ('#PET-' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT));
        $id = preg_replace('/^#/', '', $ref);
        $regOn = isset($l['created_at']) && $l['created_at'] !== '' ? date('M j, Y', strtotime($l['created_at'])) : $dash;
        $about = trim((string)($l['description'] ?? ''));
        if ($about === '') {
            $sd = trim((string)($l['source_description'] ?? ''));
            if ($sd !== '') $about = $sd;
        }
        if ($about === '') {
            $aboutParts = array();
            $aboutParts[] = $pname . ' is a ' . strtolower($species) . ($breedVal !== '' ? ' (' . strtolower($breedVal) . ')' : '') . ' available for adoption';
            if ($gender !== $dash) $aboutParts[0] .= ' in Dasmari&ntilde;as City';
            $aboutParts[0] .= '.';
            if ($ageN > 0) $aboutParts[] = 'At ' . strtolower($age) . ' old, ' . $pname . ' is looking for a loving forever home.';
            else $aboutParts[] = $pname . ' is looking for a loving forever home.';
            $about = implode(' ', $aboutParts);
        }
        $reqs = array();
        $reqTxt = trim((string)($l['requirements'] ?? ''));
        if ($reqTxt !== '') {
            $reqs = preg_split('/[\r\n;]+/', $reqTxt);
            $reqs = array_values(array_filter(array_map('trim', $reqs)));
        }
        $appElem = array();
        $appQ = $conn ? $conn->query("SELECT aa.application_date, aa.status, u.full_name FROM adoption_applications aa JOIN users u ON aa.applicant_id = u.user_id WHERE aa.listing_id = " . (int)$l['listing_id'] . " ORDER BY aa.application_date DESC") : null;
        if ($appQ) {
            while ($ar = $appQ->fetch_assoc()) {
                $albl = ucfirst(strtolower(trim((string)$ar['status'])));
                $appElem[] = array($ar['full_name'] ?: 'Resident', $albl, date('M j', strtotime($ar['application_date'])));
            }
        }
        $apptElem = array();
        foreach (($apptsByPet[$pid] ?? array()) as $a) {
            $tLbl = isset($apptTypeLabels[strtolower($a['appointment_type'] ?? '')]) ? $apptTypeLabels[strtolower($a['appointment_type'])] : ucfirst(strtolower($a['appointment_type'] ?? 'Appointment'));
            $sLbl = strtolower(trim((string)($a['status'] ?? 'scheduled')));
            if ($sLbl === 'no_show') $sLbl = 'no_show';
            $apptElem[] = array(
                $tLbl,
                $ownerName,
                $a['preferred_date'] ? date('M j, Y', strtotime($a['preferred_date'])) : $dash,
                $a['preferred_time'] ? date('g:i A', strtotime($a['preferred_time'])) : $dash,
                $sLbl
            );
        }
        $tl = array(array('Pet listing added', $regOn, $ownerName, ''));
        foreach (($apptsByPet[$pid] ?? array()) as $a) {
            $tLbl = isset($apptTypeLabels[strtolower($a['appointment_type'] ?? '')]) ? $apptTypeLabels[strtolower($a['appointment_type'])] : 'Appointment';
            if ($a['created_at'] !== '') $tl[] = array(($tLbl . ' appointment scheduled'), date('M j, Y', strtotime($a['created_at'])), $ownerName, '');
        }
        $vaxNote = isset($vaxMap[$pid]['vaccination_status']) && $vaxMap[$pid]['vaccination_status'] !== '' ? $vaxMap[$pid]['vaccination_status'] : '';
        $photo = isset($l['photo_url']) ? (string)$l['photo_url'] : '';
        $weight = isset($l['weight']) && $l['weight'] !== null && $l['weight'] !== '' ? ((float)$l['weight'] > 0 ? rtrim(rtrim(number_format((float)$l['weight'], 1, '.', ''), '0'), '.') . ' kg' : $dash) : $dash;
        $fee = (isset($l['adoption_fee']) && $l['adoption_fee'] !== null && $l['adoption_fee'] !== '') ? ('PHP ' . number_format((float)$l['adoption_fee'], 2)) : $dash;
        $vetPets[] = array(
            'id' => $id,
            'name' => $pname,
            'species' => $species,
            'gender' => $gender,
            'age' => $age,
            'ageNum' => $ageN,
            'breed' => $breed,
            'weight' => $weight,
            'color' => isset($l['color']) && $l['color'] !== '' ? $l['color'] : $dash,
            'photo' => $photo,
            'status' => $st,
            'registered' => $regOn,
            'availableSince' => $dash,
            'added' => $regOn,
            'fee' => $fee,
            "location" => "Dasmari\xC3\xB1as City Veterinary Services",
            'about' => $about,
            'tags' => array(),
            'requirements' => $reqs,
            'temperament' => array(),
            'behaviorNotes' => '',
            'staffNotes' => '',
            'healthStatus' => $dash,
            'vaxNote' => $vaxNote !== '' ? $vaxNote : '',
            'lastExam' => $dash,
            'vet' => $dash,
            'nextCheckup' => $dash,
            'vaccines' => array(),
            'conditions' => $dash,
            'medications' => $dash,
            'applications' => $appElem,
            'appointments' => $apptElem,
            'documents' => array(),
            'timeline' => $tl
        );
    }
}
?>
<style>
.cv-scope{--cv-green:#0E8131;--cv-green-dark:#0a6a28;--cv-green-soft:#eaf7ef;--cv-navy:#0f172a;--cv-slate:#475569;--cv-muted:#64748b;--cv-border:#e5e9f0;--cv-bg:#f4f6f9;--cv-card:#ffffff;--cv-ring:rgba(14,129,49,.14);--green-50:#eef6f0;--green-100:#dcebe0;--green-200:#b8d1c0;--green-500:#057b2d;--green-600:#046b25;--green-700:#045e1f;--ink-900:#0f1720;--ink-700:#334155;--ink-500:#64748b;--ink-400:#94a3b8;--ink-300:#cbd5e1;--ink-200:#e2e8f0;--ink-100:#f1f5f9;--ink-50:#f8fafc;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;font-size:14px;line-height:1.5;max-width:1400px;margin:0 auto;}
.cv-scope *{box-sizing:border-box;margin:0;padding:0;}
.cv-scope svg{vertical-align:middle;}
#adoption.content-section{background:#ffffff;}
.cv-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;font-size:14px;font-weight:500;cursor:pointer;transition:background-color .15s ease,border-color .15s ease,color .15s ease;font-family:'Inter','Segoe UI',sans-serif;white-space:nowrap;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-btn svg{width:15px;height:15px;}
.cv-btn-primary{background:#0E8131;color:#fff;}
.cv-btn-primary:hover{background:#0a6a28;}
.cv-btn-outline{background:#ffffff;border-color:#e4e4e7;color:#18181b;}
.cv-btn-outline:hover{background:#f4f4f5;}
.cv-btn-ghost{background:transparent;color:#475569;box-shadow:none;}
.cv-btn-ghost:hover{background:#f1f5f9;}
.cv-btn-sm{height:32px;padding:0 14px;font-size:13px;font-weight:500;}
/* toolbar */
.cv-toolbar{display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;}
.cv-search{width:280px;flex:none;position:relative;display:flex;align-items:center;background:#fff;border:1px solid #e4e4e7;border-radius:6px;height:36px;padding:0 12px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-search svg{width:15px;height:15px;color:#71717a;flex-shrink:0;}
.cv-search input{flex:1;border:none;outline:none;font-size:14px;color:#18181b;background:transparent;padding-left:10px;font-family:'Inter','Segoe UI',sans-serif;}
.cv-search input::placeholder{color:#a1a1aa;}
.cv-toolbar-actions{margin-left:auto;display:flex;gap:10px;align-items:center;}
'Inter','Segoe UI'.cv-field{display:flex;flex-direction:column;gap:6px;}
.cv-field.full{grid-column:1/-1;}
.cv-label{font-size:14px;font-weight:500;color:#18181b;}
.cv-input,.cv-select{width:100%;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;background:#fff;outline:none;font-family:'Inter','Segoe UI',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s ease,box-shadow .15s ease;}
.cv-input:focus,.cv-select:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
.cv-select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:32px;}
.cv-textarea{width:100%;min-height:86px;padding:10px 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;resize:vertical;outline:none;font-family:'Inter','Segoe UI',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
/* workspace */
.cv-workspace{display:grid;grid-template-columns:520px minmax(0,1fr);gap:18px;align-items:stretch;}
.cv-list-col{display:flex;flex-direction:column;}
.cv-list-col .cv-list-panel{flex:1;display:flex;flex-direction:column;}
.cv-list-col .cv-list{flex:1;}
.cv-list-foot{margin-top:auto;}
/* list panel */
.cv-list-panel{background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;}
.cv-list-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 16px 14px;border-bottom:1px solid #e4e4e7;}
.cv-list-title{font-size:14px;font-weight:600;color:#18181b;}
.cv-list-title span{color:#71717a;font-weight:500;}
.cv-list-tools{display:flex;align-items:center;gap:8px;}
.cv-sort{font-size:14px;color:#18181b;border:1px solid #e4e4e7;border-radius:6px;height:36px;padding:0 8px 0 10px;background:#fff;outline:none;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;display:inline-flex;align-items:center;font-weight:500;appearance:none;-webkit-appearance:none;}
.cv-list{flex:1;overflow-y:auto;max-height:760px;}
.cv-row{display:flex;gap:0;align-items:stretch;padding:0;border-radius:0 8px 8px 0;cursor:pointer;border:1.5px solid transparent;transition:background .12s,border-color .12s;position:relative;overflow:hidden;height:80px;}
.cv-row:hover{background:#f8fafc;}
.cv-row.selected{background:#f8fafc;border-color:#e4e4e7;}
.cv-row-thumb{width:80px;height:100%;object-fit:cover;background:#eef2f6;flex-shrink:0;display:block;}
.cv-row-thumb-c{width:80px;height:100%;background:#eef2f6;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#71717a;font-weight:600;font-size:22px;}
.cv-row-main{flex:1;min-width:0;padding:14px 16px;display:flex;flex-direction:column;justify-content:center;}
.cv-row-right{padding:14px 16px;display:flex;flex-direction:column;justify-content:center;}
.cv-row-main{flex:1;min-width:0;}
.cv-row-name{font-size:14px;font-weight:600;color:#18181b;}
.cv-row-meta{font-size:12px;color:#71717a;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cv-row-right{text-align:right;flex-shrink:0;}
.cv-row-ref{font-size:14px;font-weight:500;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;}
.cv-row-added{font-size:12px;color:#a1a1aa;margin-top:4px;}
.cv-row-tags{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;}
/* status pill — reuse pet list .status-pill styling */
.cv-scope .status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;white-space:nowrap;background:var(--green-100);color:var(--green-700);}
.cv-scope .status-pill.ls-available{background:var(--green-100);color:var(--green-700);}
.cv-scope .status-pill.ls-pending{background:#fffbeb;color:#b45309;}
.cv-scope .status-pill.ls-review{background:#dbeafe;color:#1d4ed8;}
.cv-scope .status-pill.ls-adopted{background:#eff6ff;color:#1d4ed8;}
.cv-scope .status-pill.ls-withdrawn{background:var(--ink-100);color:var(--ink-500);}
.cv-scope .status-pill.ls-unavailable{background:#fef2f2;color:#b91c1c;}
/* pagination */
.cv-list-foot{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;padding:12px 4px;margin-top:8px;}.cv-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}.cv-rows select{height:32px;padding:0 8px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-size:14px;font-family:inherit;cursor:pointer;outline:none;}
.cv-pagination{display:flex;align-items:center;gap:4px;}
.cv-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}.cv-page-btn svg{width:14px;height:14px;}
.cv-page-btn:hover:not(:disabled){background:#f4f4f5;}
.cv-page-btn:disabled{opacity:.5;cursor:not-allowed;}
.cv-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
/* empty */
.cv-empty{text-align:center;color:#71717a;padding:48px 20px;font-size:14px;}
.cv-empty svg{width:34px;height:34px;margin:0 auto 10px;display:block;color:#cbd5e1;}
/* detail panel */
.cv-detail-panel{background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:22px;min-width:0;font-family:'Inter','Segoe UI',sans-serif;}
.cv-detail-top{display:grid;grid-template-columns:300px minmax(0,1fr);gap:24px;}
.cv-detail-img{width:100%;height:220px;object-fit:cover;border-radius:8px;background:#eef2f6;}
.cv-detail-img-c{width:100%;height:220px;border-radius:8px;background:#eef2f6;display:flex;align-items:center;justify-content:center;color:#71717a;font-weight:600;font-size:44px;}
.cv-detail-main{display:flex;flex-direction:column;}
.cv-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}
.cv-detail-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px;}
.cv-detail-sub{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;line-height:1.5;}
.cv-detail-idrow{display:flex;align-items:center;gap:8px;margin-top:10px;}
.cv-detail-id{font-size:14px;font-weight:500;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;}
.cv-copy-btn{background:none;border:none;color:#71717a;cursor:pointer;padding:2px;}
.cv-copy-btn:hover{color:#0E8131;}
.cv-copy-btn svg{width:14px;height:14px;}
.cv-detail-actions{display:flex;gap:8px;align-items:center;flex-shrink:0;}
/* metadata */
.cv-detail-meta{display:grid;grid-template-columns:1fr 1fr;gap:16px 28px;margin-top:22px;padding-top:20px;border-top:1px solid #e4e4e7;border-bottom:1px solid #e4e4e7;padding-bottom:20px;}
.cv-meta-col{display:flex;flex-direction:column;gap:14px;}
.cv-meta-item{display:flex;gap:11px;align-items:flex-start;}
.cv-meta-icon{width:30px;height:30px;color:#18181b;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.cv-meta-icon svg{width:14px;height:14px;}
.cv-meta-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;}
.cv-meta-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;margin-top:1px;}
.cv-meta-value-r{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;margin-top:1px;text-align:right;}
/* tabs */
.cv-tabs{display:flex;gap:6px;margin-top:22px;overflow-x:auto;border-bottom:1px solid #e4e4e7;}
.cv-tab{position:relative;padding:12px 4px;font-size:13px;font-weight:600;color:#18181b;background:none;border:none;cursor:pointer;margin-right:14px;white-space:nowrap;font-family:'Inter','Segoe UI',sans-serif;transition:color .15s;padding-bottom:12px;}
.cv-tab:hover{color:#18181b;}
.cv-tab.active{color:#0E8131;font-weight:600;}
.cv-tab.active::after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;background:#0E8131;border-radius:2px;}
.cv-tab-badge{background:#f4f4f5;color:#475569;font-size:11px;font-weight:600;padding:1px 7px;border-radius:6px;margin-left:5px;vertical-align:1px;}
.cv-tab.active .cv-tab-badge{background:#eaf7ef;color:#0E8131;}
/* panels */
.cv-panel{display:none;padding-top:20px;animation:cvfade .18s ease;}
.cv-panel.active{display:block;}
@keyframes cvfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
.cv-grid2{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;align-items:start;}
.cv-grid2-col{display:flex;flex-direction:column;gap:18px;}
.cv-card{background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:18px;font-family:'Inter','Segoe UI',sans-serif;}
.cv-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;}
.cv-card-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;line-height:1;letter-spacing:0.02em;text-transform:uppercase;color:#71717a;}
.cv-about-text{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#71717a;line-height:1.5;}
.cv-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;}
.cv-chip{font-size:12px;font-weight:500;color:#475569;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:6px;padding:5px 12px;}
.cv-req{display:flex;flex-direction:column;gap:12px;}
.cv-req-item{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:#475569;}
.cv-req-check{width:20px;height:20px;border-radius:50%;background:#eaf7ef;color:#0E8131;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;}
.cv-req-check svg{width:11px;height:11px;}
/* schedule */
.cv-sched-head{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.cv-appt-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f4f4f5;}
.cv-appt-icon{width:40px;height:40px;border-radius:8px;background:#fff;border:1px solid #e4e4e7;color:#0E8131;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.cv-appt-icon svg{width:17px;height:17px;}
.cv-appt-main{flex:1;min-width:0;}
.cv-appt-name{font-size:14px;font-weight:600;color:#18181b;}
.cv-appt-client{font-size:12px;color:#71717a;margin-top:1px;}
.cv-appt-dt{text-align:right;}
.cv-appt-date{font-size:12.5px;font-weight:500;color:#18181b;}
.cv-appt-time{font-size:12px;color:#71717a;margin-top:1px;}
.cv-viewall{display:inline-flex;align-items:center;gap:5px;color:#0E8131;font-size:12.5px;font-weight:500;text-decoration:none;margin-top:12px;background:none;border:none;cursor:pointer;font-family:'Inter','Segoe UI',sans-serif;padding:0;}
.cv-viewall:hover{color:#0a6a28;}
.cv-viewall svg{width:13px;height:13px;}
/* timeline */
.cv-tl{display:flex;flex-direction:column;}
.cv-tl-item{position:relative;padding-left:26px;padding-bottom:18px;}
.cv-tl-item:last-child{padding-bottom:0;}
.cv-tl-item::before{content:'';position:absolute;left:7px;top:16px;bottom:-2px;width:2px;background:#eef2f6;}
.cv-tl-item:last-child::before{display:none;}
.cv-tl-dot{position:absolute;left:0;top:5px;width:10px;height:10px;border-radius:50%;background:#0E8131;box-shadow:0 0 0 3px #eaf7ef;}
.cv-tl-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;}
.cv-tl-text{font-size:13px;font-weight:500;color:#18181b;}
.cv-tl-date{font-size:12.5px;color:#71717a;white-space:nowrap;}
.cv-tl-sub{font-size:12px;color:#71717a;margin-top:2px;}
/* medical */
.cv-med-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;}
.cv-med-field{display:flex;flex-direction:column;gap:5px;}
.cv-med-label{font-size:14px;font-weight:500;color:#18181b;}
.cv-med-value{font-size:14px;font-weight:400;color:#18181b;display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.cv-health{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:6px;background:#eaf7ef;color:#0E8131;width:fit-content;}
.cv-health svg{width:11px;height:11px;}
.cv-health.neutral{background:#f4f4f5;color:#475569;}
.cv-health.neutral svg{display:none;}
/* table */
.cv-table{width:100%;border-collapse:collapse;}
.cv-table th{text-align:left;font-size:12px;font-weight:500;color:#71717a;padding:8px 10px;border-bottom:1px solid #e4e4e7;}
.cv-table td{padding:11px 10px;font-size:14px;color:#18181b;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cv-table tr:last-child td{border-bottom:none;}
.cv-vax-done{display:inline-flex;align-items:center;gap:5px;color:#0E8131;font-size:12.5px;font-weight:500;}
.cv-vax-done svg{width:12px;height:12px;}
.cv-vax-pending{display:inline-flex;align-items:center;gap:5px;color:#b45309;font-size:12.5px;font-weight:500;}
.cv-vax-pending svg{width:12px;height:12px;}
.cv-appt-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-family:'Inter','Segoe UI',sans-serif;font-size:11.5px;font-weight:700;white-space:nowrap;}
/* docs */
.cv-doc{display:flex;align-items:center;gap:12px;padding:13px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;transition:background .12s;cursor:pointer;}
.cv-doc:hover{background:#f8fafc;}
.cv-doc-icon{width:42px;height:42px;border-radius:8px;background:#f4f4f5;color:#475569;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.cv-doc-icon svg{width:18px;height:18px;}
.cv-doc-main{flex:1;min-width:0;}
.cv-doc-name{font-size:14px;font-weight:500;color:#18181b;}
.cv-doc-meta{font-size:12px;color:#71717a;margin-top:2px;}
/* modal */
.cv-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.cv-overlay.open{display:flex;}
.cv-modal{background:#fff;border-radius:8px;width:100%;max-width:640px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);animation:cvpop .18s ease;}
.cv-modal.lg{max-width:760px;}
@keyframes cvpop{from{opacity:0;transform:translateY(8px) scale(0.98)}to{opacity:1;transform:none}}
.cv-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid #e4e4e7;}
.cv-modal-head h3{font-size:18px;font-weight:600;letter-spacing:-.025em;color:#18181b;}
.cv-modal-x{width:32px;height:32px;border-radius:6px;border:none;background:#f4f4f5;color:#475569;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.cv-modal-x:hover{background:#e5e9ef;}
.cv-modal-body{padding:20px 22px;overflow-y:auto;font-size:14px;}
.cv-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid #e4e4e7;}
.cv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.cv-section{margin-bottom:22px;}
.cv-section:last-child{margin-bottom:0;}
.cv-section-title{font-size:14px;font-weight:600;color:#18181b;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #f1f5f9;}
.cv-section-title span{color:#0E8131;}
.cv-upload{min-height:96px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:#71717a;cursor:pointer;font-size:14px;font-weight:500;}
.cv-upload svg{width:22px;height:22px;color:#71717a;}
.cv-upload:hover{border-color:#0E8131;color:#0E8131;}
.cv-upload:hover svg{color:#0E8131;}
/* toast */
.cv-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:#18181b;color:#fff;font-size:14px;font-weight:500;padding:11px 18px;border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);z-index:400;opacity:0;transition:all .2s;pointer-events:none;display:flex;align-items:center;gap:9px;}
.cv-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.cv-toast svg{width:15px;height:15px;color:#6ee7b7;}
/* responsive */
@media (max-width:1280px){.cv-workspace{grid-template-columns:minmax(340px,42%) minmax(0,1fr);}.cv-list{max-height:380px;}}
@media (max-width:1024px){.cv-detail-top{grid-template-columns:1fr;}.cv-detail-img,.cv-detail-img-c{height:280px;}.cv-grid2{grid-template-columns:1fr;}.cv-fgrid{grid-template-columns:1fr;}}
@media (max-width:760px){.cv-detail-meta{grid-template-columns:1fr;}.cv-form-grid{grid-template-columns:1fr;}.cv-list-panel{max-height:none;}.cv-workspace{grid-template-columns:1fr;}}
</style>

<!-- cv-build: 2026-09-04T16:20Z --><section id="adoption" class="content-section">
<div class="cv-scope">

  <div class="cv-toolbar">
    <label class="cv-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="cvSearch" placeholder="Search pet name, ID, species...">
    </label>  </div>

  <div class="cv-workspace">
    <div class="cv-list-col">
      <div class="cv-list-panel">
        <div class="cv-list-head">
          <div class="cv-list-title">Pet Listings <span id="cvCount"></span></div>
          <div class="cv-list-tools">
            <select class="cv-sort" id="cvSort">
              <option value="recent">Sort by: Recently Added</option>
              <option value="az">Name A&ndash;Z</option>
              <option value="za">Name Z&ndash;A</option>
              <option value="age">Age</option>
              <option value="status">Status</option>
            </select>
          </div>
        </div>
        <div class="cv-list" id="cvList"></div>
      </div>
      <div class="cv-list-foot">
        <div class="cv-rows"><span>Rows per page</span><select id="cvPerPage"><option value="5">5</option><option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option></select></div>
        <div class="cv-page-label" id="cvPageLabel">Page 1 of 1</div>
        <div class="cv-pagination" id="cvPagination"></div>
      </div>
    </div>
    <div class="cv-detail-panel" id="cvDetail"></div>
  </div>

</div>
</section>

<div class="cv-overlay" id="cvOverlay">
  <div class="cv-modal" id="cvModal">
    <div class="cv-modal-head">
      <h3 id="cvModalTitle">Title</h3>
      <button type="button" class="cv-modal-x" id="cvModalClose">&times;</button>
    </div>
    <div class="cv-modal-body" id="cvModalBody"></div>
    <div class="cv-modal-foot" id="cvModalFoot"></div>
  </div>
</div>

<div class="cv-toast" id="cvToast"></div>

<script>
(function(){
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  var PETS=<?php echo json_encode($vetPets, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

  var STATUS_META={available:{label:'Available',icon:'check'},pending:{label:'Pending',icon:'hourglass'},review:{label:'Under Review',icon:'clipboard'},adopted:{label:'Adopted',icon:'heart'},unavailable:{label:'Not Available',icon:'minus'}};
  var ICONS={edit:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',chevL:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',chevR:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',dblChevL:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>',dblChevR:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 17 11 12 6 7"/><polyline points="13 17 18 12 13 7"/></svg>',check:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',hourglass:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>',clipboard:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>',heart:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',minus:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',paw:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 20.999 6 19a4 4 0 0 1-2-2.5"/><circle cx="4" cy="12" r="2"/></svg>',clock:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',cal:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',pin:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',weight:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3L3 21h18L12 3z"/><path d="M12 3v6"/></svg>',cake:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M12 3v2"/><path d="M7 3v2"/><path d="M17 3v2"/></svg>',species:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 20.999 6 19a4 4 0 0 1-2-2.5"/><circle cx="4" cy="12" r="2"/></svg>',breed:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="3" x2="12" y2="21"/><line x1="4" y1="8" x2="20" y2="8"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="8" y1="16" x2="16" y2="16"/></svg>',gender:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="16" cy="11" r="3"/></svg>',download:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',doc:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',plus:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',chev:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',file:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',img:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',search:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'};

  var state={selected:(PETS[0]&&PETS[0].id)||'',status:'all',search:'',sort:'recent',species:'',gender:'',age:'',breed:'',elig:'',page:1,perPage:10,grid:false,activeTab:'overview'};
  var els={};

  function filtered(){
    var q=state.search.toLowerCase();
    return PETS.filter(function(p){
      if(state.status!=='all' && p.status!==state.status) return false;
      if(state.species && p.species!==state.species) return false;
      if(state.gender && p.gender!==state.gender) return false;
      if(state.age){ var n=p.ageNum; if(state.age==='1'){ if(!(n>0 && n<1)) return false;} else if(state.age==='3'){ if(!(n>=1 && n<=3)) return false;} else if(state.age==='6'){ if(!(n>=4)) return false;} }
      if(state.breed && p.breed!==state.breed) return false;
      if(state.elig){ var elig=(p.status==='available'); if(state.elig==='eligible' && !elig) return false; if(state.elig==='not' && elig) return false; }
      if(q){ var hay=(p.name+' '+p.id+' '+p.species+' '+p.breed).toLowerCase(); if(!hay.includes(q)) return false; }
      return true;
    });
  }

  function sorted(arr){
    var a=arr.slice();
    if(state.sort==='az') a.sort(function(x,y){return x.name.localeCompare(y.name);});
    else if(state.sort==='za') a.sort(function(x,y){return y.name.localeCompare(x.name);});
    else if(state.sort==='age') a.sort(function(x,y){return x.ageNum-y.ageNum;});
    else if(state.sort==='status') a.sort(function(x,y){return STATUS_META[x.status].label.localeCompare(STATUS_META[y.status].label);});
    return a;
  }

  function statusPill(p){
    var m=STATUS_META[p.status];
    var cls='ls-'+p.status;
    if(p.status==='review') cls='ls-review';
    if(p.status==='unavailable') cls='ls-unavailable';
    if(p.status==='pending') cls='ls-pending';
    return '<span class="status-pill '+cls+'">'+esc(m.label)+'</span>';
  }
  function thumb(p){
    return p.photo? '<img class="cv-row-thumb" src="'+esc(p.photo)+'" alt="">' : '<span class="cv-row-thumb-c">'+esc((p.name||'P').charAt(0).toUpperCase())+'</span>';
  }

  function renderList(){
    var list=filtered(); var total=list.length;
    var sortable=sorted(list);
    var pages=Math.max(1,Math.ceil(total/state.perPage));
    if(state.page>pages) state.page=pages;
    var start=(state.page-1)*state.perPage; var rows=sortable.slice(start,start+state.perPage);
    els.count.textContent='('+total+')';
    if(!rows.length){ els.list.innerHTML='<div class="cv-empty">'+ICONS.search+'<div>No pets match your criteria.</div></div>'; renderPagination(total,pages);  return; }
    els.list.innerHTML=rows.map(function(p){
      return '<div class="cv-row'+(p.id===state.selected?' selected':'')+'" data-id="'+esc(p.id)+'">'+thumb(p)+'<div class="cv-row-main"><div class="cv-row-name">'+esc(p.name)+'</div><div class="cv-row-meta">'+esc(p.species)+' &middot; '+esc(p.gender)+' &middot; '+esc(p.age)+'</div><div class="cv-row-tags">'+statusPill(p)+'</div></div><div class="cv-row-right"><div class="cv-row-ref">ID: '+esc(p.id)+'</div><div class="cv-row-added">Added: '+esc(p.added)+'</div></div></div>';
    }).join('');
    els.list.querySelectorAll('.cv-row').forEach(function(r){
      r.addEventListener('click',function(){ state.selected=r.getAttribute('data-id'); state.activeTab='overview'; renderList(); renderDetail(); });
    });
    renderPagination(total,pages);
  }
  function renderPagination(total,pages){
    if(els.pageLabel) els.pageLabel.textContent='Page '+state.page+' of '+pages;
    var h='<button type="button" class="cv-page-btn" data-p="first" '+(state.page<=1?'disabled':'')+' title="Go to first page">'+ICONS.dblChevL+'</button>';
    h+='<button type="button" class="cv-page-btn" data-p="prev" '+(state.page<=1?'disabled':'')+' title="Go to previous page">'+ICONS.chevL+'</button>';
    h+='<button type="button" class="cv-page-btn" data-p="next" '+(state.page>=pages?'disabled':'')+' title="Go to next page">'+ICONS.chevR+'</button>';
    h+='<button type="button" class="cv-page-btn" data-p="last" '+(state.page>=pages?'disabled':'')+' title="Go to last page">'+ICONS.dblChevR+'</button>';
    els.pagination.innerHTML=h;
    els.pagination.querySelectorAll('.cv-page-btn:not([disabled])').forEach(function(b){
      var p=b.getAttribute('data-p');
      b.addEventListener('click',function(){
        if(p==='first') state.page=1;
        else if(p==='prev') state.page=Math.max(1,state.page-1);
        else if(p==='next') state.page=Math.min(pages,state.page+1);
        else state.page=pages;
        renderList();
      });
    });
  }

  function detailPhoto(p){
    return p.photo? '<img class="cv-detail-img" src="'+esc(p.photo)+'" alt="">':'<div class="cv-detail-img-c">'+esc((p.name||'P').charAt(0).toUpperCase())+'</div>';
  }

  function renderDetail(){
    var p=PETS.find(function(x){return x.id===state.selected;});
    if(!p){ els.detail.innerHTML='<div class="cv-empty">'+ICONS.search+'<div>Select a pet to see its profile.</div></div>'; return; }
    var m=STATUS_META[p.status];
    els.detail.innerHTML=
      '<div class="cv-detail-top">'+detailPhoto(p)+
        '<div class="cv-detail-main">'+
          '<div class="cv-detail-head">'+
            '<div><div class="cv-detail-title">'+esc(p.name)+' '+statusPill(p)+'</div>'+
            '<div class="cv-detail-sub">'+esc(p.species)+' &middot; '+esc(p.gender)+' &middot; '+esc(p.age)+'</div>'+
            '<div class="cv-detail-idrow"><span class="cv-detail-id">ID: '+esc(p.id)+'</span><button type="button" class="cv-copy-btn" id="cvCopy" title="Copy ID"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button></div>'+
            '</div>'+
          '</div>'+
        '</div>'+
      '</div>'+
      '<div class="cv-detail-meta">'+
        '<div class="cv-meta-col">'+metaItem(ICONS.cal,'Registered',p.registered)+metaItem(ICONS.clock,'Available Since',p.availableSince)+metaItem(ICONS.weight,'Weight',p.weight)+'</div>'+
        '<div class="cv-meta-col">'+metaItem(ICONS.pin,'Location',p.location,true)+metaItem(ICONS.species,'Species',p.species)+metaItem(ICONS.breed,'Breed',p.breed)+'</div>'+
      '</div>'+
      '<div class="cv-tabs" id="cvTabs">'+tabsHtml(p)+'</div>'+
      '<div class="cv-panels">'+panelsHtml(p)+'</div>';

    var cop=document.getElementById('cvCopy'); if(cop) cop.addEventListener('click',function(){ if(navigator.clipboard){navigator.clipboard.writeText(p.id);} toast('Pet ID copied to clipboard'); });
    document.getElementById('cvTabs').querySelectorAll('.cv-tab').forEach(function(t){ t.addEventListener('click',function(){ state.activeTab=t.getAttribute('data-tab'); renderDetail(); }); });
    bindPanelActions(p);
  }
  function metaItem(icon,label,val,html){
    return '<div class="cv-meta-item"><div class="cv-meta-icon">'+icon+'</div><div><div class="cv-meta-label">'+esc(label)+'</div><div class="cv-meta-value'+(html?' cv-meta-value-r':'')+'">'+(html?val:esc(val))+'</div></div></div>';
  }
  var TABS=[['overview','Overview'],['medical','Medical &amp; Vaccines'],['behavior','Behavior &amp; Notes'],['applications','Applications'],['documents','Documents']];
  function tabsHtml(p){
    var counts={applications:p.applications.length,appointments:p.appointments.length};
    return TABS.map(function(t){var badge=counts[t[0]]?'<span class="cv-tab-badge">'+counts[t[0]]+'</span>':''; return '<button type="button" class="cv-tab'+(state.activeTab===t[0]?' active':'')+'" data-tab="'+t[0]+'">'+t[1]+badge+'</button>';}).join('');
  }
  function panelsHtml(p){
    var html='<div class="cv-panel'+(state.activeTab==='overview'?' active':'')+'">'+overviewPanel(p)+'</div>';
    html+='<div class="cv-panel'+(state.activeTab==='medical'?' active':'')+'">'+medicalPanel(p)+'</div>';
    html+='<div class="cv-panel'+(state.activeTab==='behavior'?' active':'')+'">'+behaviorPanel(p)+'</div>';
    html+='<div class="cv-panel'+(state.activeTab==='applications'?' active':'')+'">'+applicationsPanel(p)+'</div>';
    html+='<div class="cv-panel'+(state.activeTab==='documents'?' active':'')+'">'+documentsPanel(p)+'</div>';
    return html;
  }
  function overviewPanel(p){
    var reqs=p.requirements.length? p.requirements.map(function(r){return '<div class="cv-req-item"><span>'+esc(r)+'</span></div>';}).join('') : '<div class="cv-req-item"><span>No special requirements noted.</span></div>';
    var appts=p.appointments.length? p.appointments.map(function(a){return '<div class="cv-appt-item"><div class="cv-appt-main"><div class="cv-appt-name">'+esc(a[0])+'</div><div class="cv-appt-client">with '+esc(a[1])+'</div></div><div class="cv-appt-dt"><div class="cv-appt-date">'+esc(a[2])+'</div><div class="cv-appt-time">'+esc(a[3])+'</div></div></div>';}).join('') : '<div class="cv-about-text" style="margin-bottom:10px">No upcoming appointments.</div>';
    var tl=p.timeline.length? p.timeline.map(function(x){return '<div class="cv-tl-item"><span class="cv-tl-dot"></span><div class="cv-tl-head"><span class="cv-tl-text">'+esc(x[0])+'</span><span class="cv-tl-date">'+esc(x[1])+'</span></div><div class="cv-tl-sub">by '+esc(x[2])+(x[3]?' &middot; '+esc(x[3]):'')+'</div></div>';}).join('') : '<div class="cv-about-text">No activity recorded.</div>';
    var chips=p.tags.length? p.tags.map(function(t){return '<span class="cv-chip">'+esc(t)+'</span>';}).join('') : '';
    return '<div class="cv-grid2">'+
      '<div class="cv-grid2-col">'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">About '+esc(p.name)+'</div></div><p class="cv-about-text">'+esc(p.about||'No description available.')+'</p>'+ (chips?'<div class="cv-chips">'+chips+'</div>':'') +'</div>'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Adoption Requirements</div></div><div class="cv-req">'+reqs+'</div></div>'+
      '</div>'+
      '<div class="cv-grid2-col">'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Schedule &amp; Appointments</div></div>'+appts+(p.appointments.length?'<button type="button" class="cv-viewall" data-tab="appointments">View all appointments '+ICONS.chev+'</button>':'')+'</div>'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Activity Timeline</div></div><div class="cv-tl">'+tl+'</div></div>'+
      '</div>'+
    '</div>';
  }
  function medicalPanel(p){
    var vax=p.vaccines.length? '<table class="cv-table"><thead><tr><th>Vaccine</th><th>Date</th><th>Status</th></tr></thead><tbody>'+p.vaccines.map(function(v){var st=v[2]==='done'?'done':'pending';var ic=v[2]==='done'?ICONS.check:ICONS.clock;return '<tr><td>'+esc(v[0])+'</td><td>'+esc(v[1])+'</td><td><span class="cv-vax-'+st+'">'+ic+' '+(st==='done'?'Complete':'Pending')+'</span></td></tr>';}).join('')+'</tbody></table>' : '<div class="cv-about-text">No vaccination records on file.'+(p.vaxNote?'<br><br>On record: '+esc(p.vaxNote)+'.':'')+'</div>';
    var health=(p.healthStatus && p.healthStatus!=='\u2014')? '<span class="cv-health">'+ICONS.check+' '+esc(p.healthStatus)+'</span>' : '<span class="cv-health neutral">'+esc(p.healthStatus||'\u2014')+'</span>';
    return '<div class="cv-grid2">'+
      '<div class="cv-grid2-col">'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Medical Summary</div></div><div class="cv-med-grid">'+
          medField('Health Status',health)+medField('Age',p.age)+medField('Weight',p.weight)+medField('Vaccination Status',p.vaxNote?esc(p.vaxNote):'\u2014')+medField('Microchip / ID',p.id)+medField('Adoption Fee',p.fee)+'</div></div>'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Medical Conditions</div></div><div class="cv-about-text">'+esc(p.conditions)+'</div></div>'+
      '</div>'+
      '<div class="cv-grid2-col">'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Vaccination Records</div></div>'+vax+'</div>'+
        '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Medications</div></div><div class="cv-about-text">'+esc(p.medications)+'</div></div>'+
      '</div>'+
    '</div>';
  }
  function medField(label,val){ return '<div class="cv-med-field"><span class="cv-med-label">'+esc(label)+'</span><div class="cv-med-value">'+val+'</div></div>'; }
  function behaviorPanel(p){
    var chips=p.temperament.length? p.temperament.map(function(t){return '<span class="cv-chip">'+esc(t)+'</span>';}).join('') : '';
    return '<div class="cv-grid2"><div class="cv-grid2-col">'+
      '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Temperament</div></div>'+ (chips? '<div class="cv-chips" style="margin-top:0">'+chips+'</div>':'<div class="cv-about-text">No temperament tags recorded.</div>') +'</div>'+
      '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Behavior Notes</div></div><p class="cv-about-text">'+esc(p.behaviorNotes||'No behavior notes.')+'</p></div>'+
      '</div><div class="cv-grid2-col">'+
      '<div class="cv-card"><div class="cv-card-head"><div class="cv-card-title">Staff Notes</div><button type="button" class="cv-btn cv-btn-outline cv-btn-sm" data-note>'+ICONS.plus+' Add Note</button></div><div class="cv-about-text">'+esc(p.staffNotes||'No staff notes yet.')+'</div></div>'+
      '</div></div>';
  }
  function appClsFor(status){
    if(status==='Approved') return 'available';
    if(status==='Under Review'||status==='Review') return 'review';
    if(status==='Rejected') return 'unavailable';
    return 'pending';
  }
  function applicationsPanel(p){
    if(!p.applications.length) return '<div class="cv-about-text">No adoption applications yet.</div>';
    return '<div class="cv-card" style="padding:4px 0"><table class="cv-table"><thead><tr><th>Applicant</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>'+p.applications.map(function(a){var ac='ls-'+appClsFor(a[1]);return '<tr><td>'+esc(a[0])+'</td><td><span class="status-pill '+ac+'">'+esc(a[1])+'</span></td><td>'+esc(a[2])+'</td><td style="text-align:right"><button type="button" class="cv-viewall" style="margin-top:0">View Application '+ICONS.chev+'</button></td></tr>';}).join('')+'</tbody></table></div>';
  }

  function documentsPanel(p){
    var docs=p.documents.length? p.documents.map(function(d){return '<div class="cv-doc"><div class="cv-doc-icon">'+ICONS.doc+'</div><div class="cv-doc-main"><div class="cv-doc-name">'+esc(d[0])+'</div><div class="cv-doc-meta">'+esc(d[1])+' &middot; '+esc(d[2])+'</div></div>'+ICONS.download+'</div>';}).join('') : '<div class="cv-about-text">No documents uploaded.</div>';
    return '<div class="cv-grid2-col">'+docs+'</div>';
  }

  function bindPanelActions(p){
    document.querySelectorAll('[data-book]').forEach(function(b){ b.addEventListener('click',function(){openBook(p);}); });
    document.querySelectorAll('[data-note]').forEach(function(b){ b.addEventListener('click',function(){toast('Note composer opened');}); });
  }

  function previewMedFile(input){}

  function openTab(p,tab){ state.selected=p.id; state.activeTab=tab; renderList(); renderDetail(); }

  function setStatus(p,status){ p.status=status; renderList(); renderDetail(); toast('Marked as '+STATUS_META[status].label); }

  function openBook(p){
    var types=['Meet & Greet','Adoption Consultation','Home Visit','Medical Check','Vaccination','Follow-up','Adoption Finalization'];
    openModal('Book Appointment',
      '<div class="cv-form-grid">'+
      '<div class="cv-field"><label class="cv-label">Pet</label><input class="cv-input" value="'+esc(p.name)+'" disabled></div>'+
      '<div class="cv-field"><label class="cv-label">Appointment Type</label><select class="cv-select">'+types.map(function(t){return '<option>'+esc(t)+'</option>';}).join('')+'</select></div>'+
      '<div class="cv-field"><label class="cv-label">Client</label><select class="cv-select"><option>Select Client</option><option>'+esc(p.name)+' &ndash; Owner</option></select></div>'+
      '<div class="cv-field"><label class="cv-label">Date</label><input class="cv-input" type="date" value="2026-09-08"></div>'+
      '<div class="cv-field"><label class="cv-label">Time</label><select class="cv-select"><option>10:00 AM</option><option>11:00 AM</option><option>02:00 PM</option><option>04:00 PM</option></select></div>'+
      '<div class="cv-field"><label class="cv-label">Veterinarian / Staff</label><select class="cv-select"><option>Select Staff</option><option>Dr. Santos</option><option>Dr. Cruz</option><option>Clinic Staff</option></select></div>'+
      '<div class="cv-field full"><label class="cv-label">Notes</label><textarea class="cv-textarea" placeholder="Add any notes..."></textarea></div>'+
      '</div>',
      [{label:'Cancel',cls:'cv-btn-outline',fn:closeModal},{label:'Schedule Appointment',cls:'cv-btn-primary',fn:function(){ closeModal(); toast('Appointment scheduled for '+p.name); }}]);
  }
  function openEdit(p){
    openModal('Edit Pet',
      '<div class="cv-form-grid">'+
      '<div class="cv-field"><label class="cv-label">Pet Name</label><input class="cv-input" id="ep_name" value="'+esc(p.name)+'"></div>'+
      '<div class="cv-field"><label class="cv-label">Species</label><input class="cv-input" id="ep_species" value="'+esc(p.species)+'"></div>'+
      '<div class="cv-field"><label class="cv-label">Gender</label><select class="cv-select" id="ep_gender">'+['Male','Female'].map(function(g){return '<option'+(p.gender===g?' selected':'')+'>'+g+'</option>';}).join('')+'</select></div>'+
      '<div class="cv-field"><label class="cv-label">Status</label><select class="cv-select" id="ep_status">'+Object.keys(STATUS_META).map(function(s){return '<option value="'+s+'"'+(p.status===s?' selected':'')+'>'+esc(STATUS_META[s].label)+'</option>';}).join('')+'</select></div>'+
      '<div class="cv-field full"><label class="cv-label">About</label><textarea class="cv-textarea" id="ep_about">'+esc(p.about)+'</textarea></div>'+
      '</div>',
      [{label:'Cancel',cls:'cv-btn-outline',fn:closeModal},{label:'Save Changes',cls:'cv-btn-primary',fn:function(){ saveEdit(p); }}]);
  }
  function saveEdit(p){
    var on=document.getElementById('ep_name'), oc=document.getElementById('ep_about');
    if(on) p.name=on.value; if(oc) p.about=oc.value;
    var os=document.getElementById('ep_status'); if(os) p.status=os.value;
    closeModal(); renderList(); renderDetail(); toast('Pet profile updated');
  }

  function openModal(title,body,footButtons){
    els.overlay.classList.add('open'); els.modal.className='cv-modal'+(title==='Add New Pet'?' lg':'');
    els.modalHead.textContent=title; els.modalBody.innerHTML=body;
    els.modalFoot.innerHTML=footButtons.map(function(b){return '<button type="button" class="cv-btn '+b.cls+'" data-foot="'+b.label+'">'+b.label+'</button>';}).join('');
    els.modalFoot.querySelectorAll('[data-foot]').forEach(function(b){ b.addEventListener('click',function(){ var l=b.getAttribute('data-foot'); var f=footButtons.find(function(x){return x.label===l;}); if(f&&f.fn) f.fn(); }); });
  }
  function closeModal(){ els.overlay.classList.remove('open'); }

  function toast(msg){ els.toast.innerHTML=ICONS.check+' '+esc(msg); els.toast.classList.add('show'); clearTimeout(toast._t); toast._t=setTimeout(function(){els.toast.classList.remove('show');},2200); }


  function init(){
    els.count=document.getElementById('cvCount'); els.list=document.getElementById('cvList');
    els.pagination=document.getElementById('cvPagination'); els.pageLabel=document.getElementById('cvPageLabel'); els.perPage=document.getElementById('cvPerPage'); els.detail=document.getElementById('cvDetail');
    els.search=document.getElementById('cvSearch'); els.sort=document.getElementById('cvSort'); els.overlay=document.getElementById('cvOverlay');
    els.modal=document.getElementById('cvModal'); els.modalHead=document.getElementById('cvModalTitle'); els.modalBody=document.getElementById('cvModalBody'); els.modalFoot=document.getElementById('cvModalFoot');
    els.toast=document.getElementById('cvToast');
    els.modalClose=document.getElementById('cvModalClose');
    els.modalClose.addEventListener('click',closeModal);
    els.overlay.addEventListener('click',function(e){ if(e.target===els.overlay) closeModal(); });
    els.search.addEventListener('input',function(){ state.search=els.search.value; state.page=1; renderList(); });
    els.sort.addEventListener('change',function(){ state.sort=els.sort.value; renderList(); });
    els.perPage.addEventListener('change',function(){ state.perPage=parseInt(els.perPage.value,10)||10; state.page=1; renderList(); });
    renderList(); renderDetail();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>

<section id="account-settings" class="content-section">

    <?php if (!empty($profileError)): ?>
      <div id="profileError" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#991b1b;"><?php echo htmlspecialchars($profileError); ?></div>
    <?php endif; ?>
    <?php if (!empty($profileSuccess)): ?>
      <div id="profileSuccess" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#065f46;"><?php echo htmlspecialchars($profileSuccess); ?></div>
    <?php endif; ?>

    <div class="profile-settings-wrapper">

      <!-- Profile summary -->
      <div class="ps-card ps-profile-card">
        <div class="ps-profile-left">
          <div class="profile-header-avatar-wrapper">
            <div class="ps-avatar" id="residentAvatarContainer" style="<?php echo empty($_SESSION['avatar_url']) ? 'display:none' : ''; ?>">
              <img id="residentAvatarImg" src="<?php echo htmlspecialchars($_SESSION['avatar_url'] ?? ''); ?>" alt="Avatar" onclick="toggleProfileDropdown(event)">
            </div>
            <div class="ps-avatar-plain" id="residentAvatarPlain" onclick="toggleProfileDropdown(event)" style="<?php echo empty($_SESSION['avatar_url']) ? '' : 'display:none'; ?>"><i class="fas fa-user"></i></div>
            <div class="profile-dropdown" id="profileDropdown">
              <div class="dropdown-item" onclick="event.stopPropagation();viewProfilePhoto()">
                <i class="fas fa-eye"></i>
                <span>View Profile</span>
              </div>
              <div class="dropdown-item" onclick="event.stopPropagation();document.getElementById('avatarInput').click()">
                <i class="fas fa-camera"></i>
                <span>Change Profile</span>
              </div>
            </div>
          </div>
          <div>
            <div class="ps-profile-name"><?php echo htmlspecialchars($userFullNameDb); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($userEmailDb); ?></div>
            <div class="ps-profile-sub"><?php echo htmlspecialchars($userStreet ? $userStreet . ', ' : '') . 'Brgy. ' . htmlspecialchars($barangayName) . ', Dasmari&ntilde;as'; ?></div>
          </div>
        </div>
      </div>

      <form id="settingsForm" action="update-profile.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(event,'residentAvatarImg','residentAvatarContainer','residentAvatarPlain')">

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
              <label>First Name</label>
              <input type="text" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Last Name</label>
              <input type="text" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Email Address</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($userEmailDb); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($userPhoneDb); ?>" disabled data-group="personal">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Date of Birth</label>
              <input type="date" name="birthdate" value="<?php echo htmlspecialchars($userDOB); ?>" disabled data-group="personal">
            </div>
            <div class="ps-field">
              <label>Gender</label>
              <select name="gender" disabled data-group="personal">
                <option value="">Select Gender</option>
                <option value="male" <?php echo $userGender === 'male' ? 'selected' : ''; ?>>Male</option>
                <option value="female" <?php echo $userGender === 'female' ? 'selected' : ''; ?>>Female</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Address Information -->
        <div class="ps-card" id="address-card">
          <div class="ps-card-header">
            <h2>Address Information</h2>
            <button type="button" class="ps-edit-btn" onclick="toggleEdit('address')">
              <span class="btn-label">Edit</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
          </div>
          <div class="ps-form-row ps-single">
            <div class="ps-field">
              <label>Street Address</label>
              <input type="text" name="street" value="<?php echo htmlspecialchars($userStreet); ?>" disabled data-group="address">
            </div>
          </div>
          <div class="ps-form-row">
            <div class="ps-field">
              <label>Barangay</label>
              <input type="text" value="<?php echo htmlspecialchars($barangayName); ?>" disabled readonly>
            </div>
            <div class="ps-field">
              <label>City</label>
              <input type="text" value="Dasmari&ntilde;as" disabled readonly>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="ps-footer-actions">
        <button type="button" class="sd-btn sd-btn-outline" onclick="openModal('changePasswordModal')">Change Password</button>
        <button type="submit" class="sd-btn sd-btn-default">Save Changes</button>
        </div>
      </form>
    </div>
  </section>

</main>

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.�
     MODALS
�.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->

<!-- Register Pet Modal -->
<div class="modal" id="addPetModal">
  <form class="modal-form sd-dialog" id="addPetForm" action="add-pet.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="return_section" value="my-pets">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('addPetModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title"><?php echo $showAdoptModal ? 'Register Pet for Adoption' : 'Register a Pet'; ?></h2>
        <p class="sd-dialog-description">Fill in your pet's details to add it to your profile.</p>
      </div>
      <div class="sd-field-group">
      <?php if ($showAdoptModal && $adoptData): ?>
        <div class="adoption-info-card" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem;font-size:0.85rem;">
          <strong style="color:#0f172a;">Adoption Registration</strong><br>
          You're adopting <strong><?php echo htmlspecialchars($adoptData['name']); ?></strong> from the pet list. Pet info is managed by DCVS staff.
        </div>
        <input type="hidden" name="from_adoption" value="1" />
        <input type="hidden" name="photo_url" value="<?php echo htmlspecialchars($adoptData['photo'] ?? ''); ?>" />
        <input type="hidden" name="pet_name" value="<?php echo htmlspecialchars($adoptData['name'] ?? ''); ?>" />
        <input type="hidden" name="species" value="<?php echo htmlspecialchars($adoptData['species'] ?? ''); ?>" />
        <input type="hidden" name="breed" value="<?php echo htmlspecialchars($adoptData['breed'] ?? ''); ?>" />
        <input type="hidden" name="gender" value="<?php echo htmlspecialchars($adoptData['gender'] ?? ''); ?>" />
        <input type="hidden" name="age" value="<?php echo htmlspecialchars((string)($adoptData['age'] ?? '')); ?>" />
        <input type="hidden" name="weight" value="<?php echo htmlspecialchars((string)($adoptData['weight'] ?? '')); ?>" />
        <input type="hidden" name="color" value="<?php echo htmlspecialchars($adoptData['color'] ?? ''); ?>" />
      <?php endif; ?>
      <div class="sd-form-grid">
        <div class="sd-field">
          <label class="sd-label">Pet Name <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo htmlspecialchars($adoptData['name'] ?? ''); ?></p>
          <?php else: ?>
            <input type="text" name="pet_name" class="sd-input" placeholder="e.g. Max" required />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Species <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo ucfirst(htmlspecialchars($adoptData['species'] ?? '')); ?></p>
          <?php else: ?>
            <select name="species" id="addPetSpecies" class="sd-input" required onchange="toggleCustomSpecies('addPetSpecies','addPetSpeciesCustom')">
              <option value="">Select</option>
              <option value="dog">Dog</option>
              <option value="cat">Cat</option>
              <option value="other">Other</option>
            </select>
            <input type="text" name="species_custom" id="addPetSpeciesCustom" class="sd-input" placeholder="Specify species" style="display:none;margin-top:6px;" />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Breed</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo htmlspecialchars($adoptData['breed'] ?? '-'); ?></p>
          <?php else: ?>
            <input type="text" name="breed" class="sd-input" placeholder="e.g. Aspin" />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Gender <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo ucfirst(htmlspecialchars($adoptData['gender'] ?? '')); ?></p>
          <?php else: ?>
            <select name="gender" class="sd-input" required>
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Age (years)</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo htmlspecialchars((string)($adoptData['age'] ?? '')); ?> yr<?php echo ($adoptData['age'] ?? 0) > 1 ? 's' : ''; ?></p>
          <?php else: ?>
            <input type="number" name="age" min="0" class="sd-input" placeholder="e.g. 2" />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Weight (kg)</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo htmlspecialchars((string)($adoptData['weight'] ?? '')); ?> kg</p>
          <?php else: ?>
            <input type="number" name="weight" step="0.1" class="sd-input" placeholder="e.g. 5.5" />
          <?php endif; ?>
        </div>
      </div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Color / Markings</label>
        <?php if ($showAdoptModal): ?>
          <p style="padding:8px 0;color:#18181b;font-weight:500;font-size:14px;"><?php echo htmlspecialchars($adoptData['color'] ?? '-'); ?></p>
        <?php else: ?>
          <input type="text" name="color" class="sd-input" placeholder="e.g. brown with white spots" />
        <?php endif; ?>
      </div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Vaccination Status</label>
        <select name="vaccinated" class="sd-input">
          <option value="yes">Vaccinated</option>
          <option value="no">Not Vaccinated</option>
          <option value="partial">Partially Vaccinated</option>
        </select>
      </div>
      <?php if ($showAdoptModal && !empty($adoptData['photo'])): ?>
        <div class="sd-field sd-field-full">
          <label class="sd-label">Pet Photo</label>
          <p style="margin:0;font-size:0.8rem;color:#71717a;">Photo from pet listing will be used.</p>
        </div>
      <?php else: ?>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Pet Photo (optional)</label>
        <div class="sd-photo-upload" id="addPetPhotoBox" onclick="document.getElementById('addPetPhoto').click()">
          <div class="upload-placeholder">
            <i class="fas fa-cloud-upload-alt"></i>
            <span>Upload a photo of your pet</span>
          </div>
          <img id="addPetPhotoImg" src="" alt="Pet Photo Preview" style="display:none;" />
          <input type="file" name="photo" accept="image/*" id="addPetPhoto" onchange="previewPetImage(this, 'addPetPhotoBox', 'addPetPhotoImg')" />
        </div>
      </div>
      <?php endif; ?>
      </div>
      <div class="sd-dialog-footer">
        <button type="submit" class="sd-btn sd-btn-default"><?php echo $showAdoptModal ? 'Register &amp; Proceed to Adopt' : 'Register Pet'; ?></button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('addPetModal')">Cancel</button>
      </div>
    </div>
  </form>
</div>


<!-- Edit Pet Modal -->
<div class="modal" id="editPetModal">
  <form class="modal-form sd-dialog" id="editPetForm" action="edit-pet.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="return_section" value="my-pets">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('editPetModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Edit Pet</h2>
        <p class="sd-dialog-description">Update your pet's information below.</p>
      </div>
      <div class="sd-field-group">
      <input type="hidden" name="pet_id" id="editPetId">
      <div class="sd-field sd-field-full">
        <label class="sd-label">Pet Photo (optional)</label>
        <div class="sd-photo-row">
          <div id="editPetCurrentPhoto" class="sd-photo-current" style="display:none;">
            <img id="editPetCurrentPhotoImg" src="" alt="Current Pet Photo" />
          </div>
          <div class="sd-photo-upload" id="editPetPhotoBox" onclick="document.getElementById('editPetPhoto').click()">
            <div class="upload-placeholder">
              <i class="fas fa-cloud-upload-alt"></i>
              <span>Upload a new photo</span>
            </div>
            <img id="editPetPhotoImg" src="" alt="Pet Photo Preview" style="display:none;" />
            <input type="file" name="photo" accept="image/*" id="editPetPhoto" onchange="previewPetImage(this, 'editPetPhotoBox', 'editPetPhotoImg')" />
          </div>
        </div>
      </div>
      <div class="sd-form-grid">
        <div class="sd-field">
          <label class="sd-label">Pet Name <span class="req">*</span></label>
          <input type="text" name="pet_name" id="editPetName" class="sd-input" placeholder="e.g. Max" required />
        </div>
        <div class="sd-field">
          <label class="sd-label">Species <span class="req">*</span></label>
          <select name="species" id="editPetSpecies" class="sd-input" required onchange="toggleCustomSpecies('editPetSpecies','editPetSpeciesCustom')">
            <option value="">Select</option>
            <option value="dog">Dog</option>
            <option value="cat">Cat</option>
            <option value="other">Other</option>
          </select>
          <input type="text" name="species_custom" id="editPetSpeciesCustom" class="sd-input" placeholder="Specify species" style="display:none;margin-top:6px;" />
        </div>
        <div class="sd-field">
          <label class="sd-label">Breed</label>
          <input type="text" name="breed" id="editPetBreed" class="sd-input" placeholder="e.g. Aspin" />
        </div>
        <div class="sd-field">
          <label class="sd-label">Gender <span class="req">*</span></label>
          <select name="gender" id="editPetGender" class="sd-input" required>
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        <div class="sd-field">
          <label class="sd-label">Age (years)</label>
          <input type="number" name="age" id="editPetAge" min="0" class="sd-input" placeholder="e.g. 2" />
        </div>
        <div class="sd-field">
          <label class="sd-label">Weight (kg)</label>
          <input type="number" name="weight" id="editPetWeight" step="0.1" class="sd-input" placeholder="e.g. 5.5" />
        </div>
      </div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Color / Markings</label>
        <input type="text" name="color" id="editPetColor" class="sd-input" placeholder="e.g. brown with white spots" />
      </div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Vaccination Status</label>
        <select name="vaccinated" id="editPetVaccinated" class="sd-input">
          <option value="yes">Vaccinated</option>
          <option value="no">Not Vaccinated</option>
          <option value="partial">Partially Vaccinated</option>
        </select>
      </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-save"></i> Update Pet</button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('editPetModal')">Cancel</button>
      </div>
    </div>
  </form>
</div>

<!-- Pet Profile Drawer -->
<div class="pet-drawer-overlay" id="petDrawerOverlay" onclick="closePetDrawer()"></div>
<aside class="pet-drawer" id="petDrawer" aria-label="Pet profile">
  <div class="pet-drawer-head">
    <div class="pet-drawer-avatar" id="drAvatar"><i class="fas fa-paw"></i></div>
    <div style="min-width:0;">
      <h2 class="pet-drawer-name" id="drName"></h2>
      <p class="pet-drawer-ref" id="drRef"></p>
    </div>
    <button type="button" class="pet-drawer-close" onclick="closePetDrawer()" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="pet-drawer-body">
    <div class="pet-drawer-tabs">
      <button type="button" class="pet-drawer-tab active" data-dtab="overview" onclick="setDrawerTab('overview')">Overview</button>
      <button type="button" class="pet-drawer-tab" data-dtab="vaccination" onclick="setDrawerTab('vaccination')">Vaccination</button>
    </div>

    <div id="dtab-overview">
      <p class="pet-drawer-section-title">Pet Information</p>
      <div class="pet-drawer-info">
        <div class="pet-drawer-info-item"><div class="k">Species</div><div class="v" id="drSpecies"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Breed</div><div class="v" id="drBreed"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Category</div><div class="v" id="drCategory"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Sex</div><div class="v" id="drSex"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Age</div><div class="v" id="drAge"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Weight</div><div class="v" id="drWeight"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Color / Markings</div><div class="v" id="drColor"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Registered</div><div class="v" id="drRegistered"></div></div>
      </div>
      <p class="pet-drawer-section-title">Vaccination</p>
      <div class="pet-vax-box">
        <div class="vax-head" id="drVaxHead"></div>
        <div class="vax-desc" id="drVaxDesc"></div>
        <button type="button" class="sd-btn sd-btn-default" id="drVaxBtn" onclick="goToAppointments()">
          <i class="fas fa-calendar-plus"></i> Request Vaccination Appointment
        </button>
      </div>
    </div>

    <div id="dtab-vaccination" style="display:none;">
      <p class="pet-drawer-section-title">Vaccination Status</p>
      <div class="pet-vax-box">
        <div class="vax-head" id="drVaxHead2"></div>
        <div class="vax-desc" id="drVaxDesc2"></div>
      </div>
      <p class="pet-drawer-section-title" style="margin-top:1.25rem;">Records</p>
      <div id="drRecords" style="font-size:0.85rem;color:#64748b;line-height:1.5;">
        Vaccination records will appear here once available.
      </div>
    </div>
  </div>
  <div class="pet-drawer-foot">
    <button type="button" class="sd-btn sd-btn-outline" id="drEditBtn" onclick="editPetFromDrawer()"><i class="fas fa-edit"></i> Edit Pet</button>
    <button type="button" class="sd-btn sd-btn-outline" style="border-color:#fecaca;color:#b91c1c;" id="drArchiveBtn" onclick="archivePetFromDrawer()"><i class="fas fa-archive"></i> Archive Pet</button>
  </div>
</aside>

<script>
window.__petList = <?php
  $jsonPets = [];
  foreach ($pets as $pet) {
      $pVaxRaw = $pet['vaccination_status'] ?? '';
      $pVaccCode = strpos(strtolower($pVaxRaw), 'fully') !== false ? 'yes' : (strpos(strtolower($pVaxRaw), 'partial') !== false ? 'partial' : 'no');
      $jsonPets[] = [
          'id' => (int)$pet['pet_id'],
          'ref' => $pet['reference_id'] ?? '#PET-' . str_pad($pet['pet_id'], 4, '0', STR_PAD_LEFT),
          'name' => $pet['pet_name'],
          'type' => ucfirst($pet['pet_type']),
          'speciesRaw' => $pet['pet_type'],
          'breed' => $pet['breed'] ?? '',
          'category' => $pet['from_adoption'] ? 'Adopted' : 'Owned',
          'catKey' => $pet['from_adoption'] ? 'adopted' : 'owned',
          'sex' => $pet['gender'] ? ucfirst($pet['gender']) : '-',
          'genderRaw' => $pet['gender'] ?? 'male',
          'age' => $pet['age'] !== null ? (int)$pet['age'] . ' yr' . ((int)$pet['age'] != 1 ? 's' : '') : '-',
          'ageNum' => $pet['age'] ?? 0,
          'weight' => ($pet['weight'] !== null && $pet['weight'] !== '') ? number_format((float)$pet['weight'], 1) . ' kg' : '-',
          'weightNum' => $pet['weight'] ?? '',
          'color' => $pet['color'] ?? '-',
          'registered' => date('M d, Y', strtotime($pet['created_at'])),
          'vaccination' => !empty($pet['vaccination_status']) ? $pet['vaccination_status'] : 'Not vaccinated',
          'vaccinated' => $pVaccCode,
          'photo' => $pet['photo_url'] ?? '',
      ];
  }
  echo json_encode($jsonPets);
?>;
</script>

<script>
(function() {
  var petIndex = {};
  window.__petList.forEach(function(p) { petIndex[p.id] = p; });

  function computeSummary() {
    var total = window.__petList.length;
    var vac = 0, att = 0;
    window.__petList.forEach(function(p) {
      var v = (p.vaccination || '').toLowerCase();
      if (v.indexOf('fully') !== -1) vac++;
      else if (v.indexOf('partial') !== -1 || v.indexOf('not') !== -1) att++;
    });
    var el;
    el = document.getElementById('sumTotal'); if (el) el.textContent = total;
    el = document.getElementById('sumVaccinated'); if (el) el.textContent = vac;
    el = document.getElementById('sumAttention'); if (el) el.textContent = att;
  }

  window.openPetProfile = function(id) {
    window.location.href = 'pet-profile.php?id=' + id + '&ref=resident';
  };

  window.closePetDrawer = function() {
    document.getElementById('petDrawer').classList.remove('open');
    document.getElementById('petDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
  };

  window.setDrawerTab = function(tab) {
    document.querySelectorAll('.pet-drawer-tab').forEach(function(b) {
      b.classList.toggle('active', b.getAttribute('data-dtab') === tab);
    });
    document.getElementById('dtab-overview').style.display = tab === 'overview' ? '' : 'none';
    document.getElementById('dtab-vaccination').style.display = tab === 'vaccination' ? '' : 'none';
  };

  window.editPetFromDrawer = function() {
    var id = document.getElementById('drEditBtn').getAttribute('data-pet-id');
    if (!id) return;
    var p = petIndex[id];
    if (!p) return;
    document.getElementById('editPetId').value = p.id;
    document.getElementById('editPetName').value = p.name || '';
    document.getElementById('editPetSpecies').value = p.speciesRaw || '';
    document.getElementById('editPetBreed').value = p.breed || '';
    document.getElementById('editPetGender').value = p.genderRaw || 'male';
    document.getElementById('editPetAge').value = p.ageNum || '';
    document.getElementById('editPetWeight').value = p.weightNum || '';
    document.getElementById('editPetColor').value = (p.color && p.color !== '-') ? p.color : '';
    document.getElementById('editPetVaccinated').value = p.vaccinated || 'no';
    var photoDiv = document.getElementById('editPetCurrentPhoto');
    if (p.photo) {
      photoDiv.style.display = 'block';
      document.getElementById('editPetCurrentPhotoImg').src = p.photo;
    } else {
      photoDiv.style.display = 'none';
    }
    var speciesCustom = document.getElementById('editPetSpeciesCustom');
    if (speciesCustom) { speciesCustom.style.display = 'none'; speciesCustom.required = false; }
    closePetDrawer();
    openModal('editPetModal');
  };

  window.archivePetFromDrawer = function() {
    var id = document.getElementById('drArchiveBtn').getAttribute('data-pet-id');
    if (!id) return;
    closePetDrawer();
    archivePet(parseInt(id));
  };

  window.goToAppointments = function() {
    closePetDrawer();
    if (typeof switchSection === 'function') switchSection('appointments');
  };

  window.togglePetMore = function(btn) {
    var wrap = btn.closest('.appt-more-dropdown');
    if (!wrap) return;
    var isOpen = wrap.classList.contains('open');
    document.querySelectorAll('#my-pets .appt-more-dropdown.open').forEach(function(w) { w.classList.remove('open'); });
    if (!isOpen) wrap.classList.add('open');
  };

  window.PET = window.PET || {};

  (function() {
    var PER_PAGE = 10;
    var currentPage = 1;
    var filterSearch = '';
    var filterCat = 'all';
    var sortCol = '';
    var sortDir = 'asc';
    var table = document.getElementById('petTable');
    var tbody = table ? table.querySelector('tbody') : null;
    var allRows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
    var pag = document.getElementById('petPagination');

    function getSortVal(row, col) {
      switch (col) {
        case 'pet': {
          var nm = row.querySelector('.client-name');
          return (nm ? nm.textContent : '').toLowerCase();
        }
        case 'petid': {
          var pid = row.querySelector('.pet-id-label');
          return (pid ? pid.textContent : '').toLowerCase();
        }
        case 'species': return (row.getAttribute('data-species') || '').toLowerCase();
        case 'breed': return (row.getAttribute('data-breed') || '').toLowerCase();
        case 'vaccination': return (row.getAttribute('data-vaccination') || '').toLowerCase();
        case 'category': return (row.getAttribute('data-cat') || '');
        case 'adopted': return (row.getAttribute('data-adopted') || '').toLowerCase();
        case 'registered': return (row.getAttribute('data-registered') || '').toLowerCase();
      }
      return '';
    }

    window.PET.sortBy = function(col) {
      if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
      else { sortCol = col; sortDir = 'asc'; }
      allRows.sort(function(a, b) {
        var va = getSortVal(a, col), vb = getSortVal(b, col);
        var res = va < vb ? -1 : (va > vb ? 1 : 0);
        return sortDir === 'asc' ? res : -res;
      });
      allRows.forEach(function(r) { tbody.appendChild(r); });
      document.querySelectorAll('#petTable thead .th-sort').forEach(function(btn) {
        var c = btn.getAttribute('data-col');
        btn.classList.toggle('th-sorted', c === sortCol);
        var icon = btn.querySelector('.th-sort-icon');
        if (icon) {
          if (c === sortCol) {
            icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
          } else {
            icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
          }
        }
      });
      currentPage = 1;
      renderTable();
    };

    function getFiltered() {
      return allRows.filter(function(r) {
        var cat = r.getAttribute('data-cat');
        var text = r.textContent.toLowerCase();
        var matchC = filterCat === 'all' || cat === filterCat;
        var matchQ = !filterSearch || text.includes(filterSearch);
        return matchC && matchQ;
      });
    }

    function renderTable() {
      var filtered = getFiltered();
      allRows.forEach(function(r) { r.style.display = 'none'; });
      var start = (currentPage - 1) * PER_PAGE;
      var end = start + PER_PAGE;
      filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
      renderPagination(filtered.length);
    }

    function renderPagination(total) {
      if (!pag) return;
      var tp = Math.max(1, Math.ceil(total / PER_PAGE));
      if (currentPage > tp) currentPage = tp;
      if (currentPage < 1) currentPage = 1;
      var opts = [10,15,20,30,50].map(function(n) {
        return '<option value="' + n + '"' + (PER_PAGE === n ? ' selected' : '') + '>' + n + '</option>';
      }).join('');
      var h = '<div class="dt-pagination-right">';
      h += '<div class="dt-rows"><span>Rows per page</span><select onchange="PET.setPerPage(this.value)">' + opts + '</select></div>';
      h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
      h += '<div class="dt-page-btns">';
      h += '<button type="button" class="dt-page-btn" title="Go to first page" onclick="PET.pageGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to previous page" onclick="PET.pageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to next page" onclick="PET.pageGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to last page" onclick="PET.pageGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
      h += '</div></div>';
      pag.innerHTML = h;
    }

    window.PET.pageGo = function(page) {
      var total = Math.ceil(getFiltered().length / PER_PAGE);
      if (page < 1 || page > total) return;
      currentPage = page;
      renderTable();
    };

    window.PET.setPerPage = function(v) {
      PER_PAGE = parseInt(v, 10);
      currentPage = 1;
      renderTable();
    };

    window.PET.view = function(id) { openPetProfile(id); };
    window.PET.edit = function(id) { editPetFromDrawerById(id); };
    window.PET.archive = function(id) { archivePet(id); };
    window.PET.refresh = function() {
      var t2 = document.getElementById('petTable');
      var tb2 = t2 ? t2.querySelector('tbody') : null;
      if (tb2) { tbody = tb2; allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr')); }
      currentPage = 1;
      renderTable();
    };

    var searchInput = document.getElementById('petSearchInput');
    if (searchInput) {
      searchInput.addEventListener('keyup', function() {
        filterSearch = this.value.toLowerCase().trim();
        currentPage = 1;
        renderTable();
      });
    }
    var statusSelect = document.getElementById('petStatusSelect');
    if (statusSelect) {
      statusSelect.addEventListener('change', function() {
        filterCat = this.value;
        currentPage = 1;
        renderTable();
      });
    }
    document.addEventListener('click', function(e) {
      var wrap = e.target.closest ? e.target.closest('.appt-more-dropdown') : null;
      if (!wrap) {
        document.querySelectorAll('#my-pets .appt-more-dropdown.open').forEach(function(w) { w.classList.remove('open'); });
      }
    });

    renderTable();
  })();

  function editPetFromDrawerById(id) {
    var p = petIndex[id];
    if (!p) return;
    document.getElementById('editPetId').value = p.id;
    document.getElementById('editPetName').value = p.name || '';
    document.getElementById('editPetSpecies').value = p.speciesRaw || '';
    document.getElementById('editPetBreed').value = p.breed || '';
    document.getElementById('editPetGender').value = p.genderRaw || 'male';
    document.getElementById('editPetAge').value = p.ageNum || '';
    document.getElementById('editPetWeight').value = p.weightNum || '';
    document.getElementById('editPetColor').value = (p.color && p.color !== '-') ? p.color : '';
    document.getElementById('editPetVaccinated').value = p.vaccinated || 'no';
    var photoDiv = document.getElementById('editPetCurrentPhoto');
    if (p.photo) {
      photoDiv.style.display = 'block';
      document.getElementById('editPetCurrentPhotoImg').src = p.photo;
    } else {
      photoDiv.style.display = 'none';
    }
    var speciesCustom = document.getElementById('editPetSpeciesCustom');
    if (speciesCustom) { speciesCustom.style.display = 'none'; speciesCustom.required = false; }
    openModal('editPetModal');
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var d = document.getElementById('petDrawer');
      if (d && d.classList.contains('open')) closePetDrawer();
    }
  });

  computeSummary();
})();
</script>

<!-- Report Details Drawer -->
<div class="rpt-drawer-overlay" id="rptDrawerOverlay" onclick="closeReportDrawer()"></div>
<aside class="rpt-drawer" id="rptDrawer" aria-label="Report details">
  <div class="rpt-drawer-head">
    <div style="min-width:0;">
      <h2 class="rpt-drawer-title">Report Details</h2>
      <p class="rpt-drawer-ref" id="rdHeadType"></p>
    </div>
    <button type="button" class="rpt-drawer-close" onclick="closeReportDrawer()" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="rpt-drawer-body">

    <p class="pet-drawer-section-title">Report Information</p>
    <div class="pet-drawer-info rpt-info-3col">
      <div class="pet-drawer-info-item"><div class="k">Date Submitted</div><div class="v" id="rdDate"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Location</div><div class="v" id="rdLocation"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Description</div><div class="v" id="rdDescription"></div></div>
      <div class="pet-drawer-info-item pet-drawer-info-item-full" id="rdPhotoBlock" style="display:none;"><div class="k">Attached Photos</div><div class="v"><div class="rpt-photos" id="rdPhotos"></div></div></div>
    </div>

    <p class="pet-drawer-section-title">Status</p>
    <div id="rdTimeline" style="margin-bottom:1rem;"></div>
    <div class="rpt-detail-block" id="rdResolution" style="display:none;margin-top:0.75rem;">
      <div class="pet-drawer-info-item"><div class="k">Resolution</div><div class="v" id="rdResolutionText"></div></div>
    </div>

    <p class="pet-drawer-section-title" id="rdNextSection">What happens next?</p>
    <div class="rpt-next">
      <div class="rpt-next-title" id="rdNextTitle"><i class="fas fa-info-circle"></i> What happens next?</div>
      <ol id="rdNextList">
        <li>Your report is submitted to your barangay for review.</li>
        <li>DCVS reviews the report and validates the case.</li>
        <li>The case is handled or scheduled for action.</li>
        <li>You receive an update when the report is resolved.</li>
      </ol>
    </div>
  </div>
  <div class="rpt-drawer-foot">
    <button type="button" class="sd-btn sd-btn-default" onclick="RPT.resubmit()"><i class="fas fa-redo"></i> Resubmit</button>
    <button type="button" class="sd-btn sd-btn-outline" style="border-color:#fecaca;color:#b91c1c;" onclick="RPT.archive(window.__currentReportId)"><i class="fas fa-archive"></i> Archive</button>
    <button type="button" class="sd-btn sd-btn-outline" onclick="closeReportDrawer()">Close</button>
  </div>
</aside>

<script>
window.__reportList = <?php
  $jsonReports = [];
  foreach ($reports as $report) {
      $rt = strtolower($report['report_type']);
      $jsonReports[] = [
          'id' => (int)$report['report_id'],
          'ref' => $report['reference_id'] ?? '#RPT-' . str_pad($report['report_id'], 4, '0', STR_PAD_LEFT),
          'type' => ($knownReportTypes[$rt] ?? ucfirst($report['report_type'])) . ' Report',
          'typeKey' => $rt,
          'urgency' => $report['urgency_level'] ?? 'medium',
          'createdRaw' => date('Y-m-d\TH:i', strtotime($report['created_at'])),
          'location' => $report['location_address'] ?? '-',
          'date' => date('F d, Y', strtotime($report['created_at'])),
          'description' => $report['description'] ?? '',
          'photo' => $report['photo_url'] ?? '',
          'status' => $report['status'],
          'statusLabel' => rptStatusLabel($report['status']),
          'statusClass' => rptStatusClass($report['status']),
          'resolution' => $report['status'] === 'resolved' ? 'This report has been resolved by DCVS.' : '',
      ];
  }
  echo json_encode($jsonReports);
?>;
</script>

<script>
(function() {
  var reportIndex = {};
  window.__reportList.forEach(function(r) { reportIndex[r.id] = r; });

  function buildTimeline(status) {
    var reviewed = ['validated','confirmed','scheduled','resolved'].indexOf(status) !== -1;
    var resolved = status === 'resolved';
    var step = function(state, icon, label) {
      var cls = state === 'done' ? 'done' : (state === 'current' ? 'current' : '');
      var ic = (state === 'done' || state === 'current') ? '<i class="fas fa-' + (state === 'done' ? 'check' : 'circle') + '"></i>' : '';
      return '<span class="tl-step ' + cls + '">' + ic + ' ' + label + '</span>';
    };
    if (status === 'rejected') {
      return '<div class="rpt-timeline rpt-timeline-rejected">' + step('done','times','Submitted') + '<span class="tl-sep"></span><span class="tl-step current">Rejected</span></div>';
    }
    var sub = resolved || reviewed ? 'done' : 'current';
    return '<div class="rpt-timeline">' + step(sub, 'check', 'Submitted') + '<span class="tl-sep"></span>' +
      step(reviewed ? 'done' : (status === 'pending' ? 'current' : ''), 'check', 'Reviewed') + '<span class="tl-sep"></span>' +
      step(resolved ? 'done' : '', 'check', 'Resolved') + '</div>';
  }

  function applyNextSteps(status) {
    var section = document.getElementById('rdNextSection');
    var title = document.getElementById('rdNextTitle');
    var list = document.getElementById('rdNextList');
    if (!section || !title || !list) return;
    var items = [];
    var titleText = 'What happens next?';
    if (status === 'resolved') {
      titleText = 'What happened to this case?';
      items = [
        'Your report has been reviewed and the necessary action has been completed.',
        'The reported concern has been addressed by the concerned barangay or DCVS.',
        'The case has been marked as resolved based on the action taken.',
        'You can view the case details and resolution information in your report history.'
      ];
    } else if (['validated','confirmed','scheduled'].indexOf(status) !== -1) {
      items = [
        'Your report has been reviewed and the case is currently being handled.',
        'DCVS or the concerned barangay is taking the necessary action based on the report.',
        'The case may be scheduled for field action, monitoring, rescue, treatment, or other appropriate intervention.',
        'You\u2019ll receive an update once there is significant progress or when the case is resolved.'
      ];
    } else if (status === 'rejected') {
      titleText = 'What happened to this report?';
      items = [
        'Your report was reviewed but was not validated for further action.',
        'The barangay or DCVS assessed the information provided in the report.',
        'The report has been closed without further action.',
        'You may submit a new report if the concern still exists.'
      ];
    } else {
      items = [
        'Your report has been submitted and is awaiting review by your barangay.',
        'The barangay will verify the report and assess the information provided.',
        'Once validated, the report will be forwarded to DCVS for further review when necessary.',
        'You\u2019ll receive an update when the case moves to the next stage.'
      ];
    }
    section.textContent = titleText;
    title.innerHTML = '<i class="fas fa-info-circle"></i> ' + titleText;
    list.innerHTML = items.map(function(t) { return '<li>' + t + '</li>'; }).join('');
  }

  window.openReportDetails = function(id) {
    var r = reportIndex[id];
    if (!r) return;
    var set = function(eid, val) { var e = document.getElementById(eid); if (e) e.textContent = val; };
    set('rdHeadType', r.type);
    set('rdDate', r.date);
    set('rdLocation', r.location);
    set('rdDescription', r.description);
    var badge = document.getElementById('rdBadge');
    if (badge) badge.className = 'rpt-status-badge ' + r.statusClass;
    set('rdBadgeText', r.statusLabel);
    var tlEl = document.getElementById('rdTimeline');
    if (tlEl) tlEl.innerHTML = buildTimeline(r.status);
    var resolved = r.status === 'resolved';
    var res = document.getElementById('rdResolution');
    if (resolved) {
      res.style.display = '';
      set('rdResolutionText', r.resolution);
    } else {
      res.style.display = 'none';
    }
    applyNextSteps(r.status);
    var pb = document.getElementById('rdPhotoBlock');
    if (r.photo) {
      pb.style.display = '';
      document.getElementById('rdPhotos').innerHTML = '<div class="rpt-photo-thumb"><img src="' + r.photo + '" alt="Report photo" onclick="viewReportImage(\'' + r.photo + '\')"></div>';
    } else {
      pb.style.display = 'none';
    }
    document.getElementById('rptDrawer').classList.add('open');
    document.getElementById('rptDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    window.__currentReportId = r.id;
  };

  window.closeReportDrawer = function() {
    document.getElementById('rptDrawer').classList.remove('open');
    document.getElementById('rptDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
  };

  window.RPT = window.RPT || {};

  (function() {
    var PER_PAGE = 10;
    var currentPage = 1;
    var filterSearch = '';
    var filterStatus = 'all';
    var sortCol = '';
    var sortDir = 'asc';
    var table = document.getElementById('reportTable');
    var tbody = table ? table.querySelector('tbody') : null;
    var allRows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
    var pag = document.getElementById('reportPagination');

    function statusFilter(st) {
      if (st === 'pending') return 'pending';
      if (['validated','confirmed','scheduled'].indexOf(st) !== -1) return 'review';
      if (st === 'resolved') return 'resolved';
      if (st === 'rejected') return 'rejected';
      return 'all';
    }

    function getSortVal(row, col) {
      switch (col) {
        case 'ref': { var nm = row.querySelector('.client-name'); return (nm ? nm.textContent : '').toLowerCase(); }
        case 'type': return (row.getAttribute('data-type') || '').toLowerCase();
        case 'location': return (row.getAttribute('data-location') || '').toLowerCase();
        case 'date': return parseInt(row.getAttribute('data-date') || '0', 10);
        case 'status': return (row.getAttribute('data-status') || '');
        case 'category': return (row.getAttribute('data-type') || '').toLowerCase();
        case 'resolved': return (row.getAttribute('data-resolved') || '').toLowerCase();
      }
      return '';
    }

    window.RPT.sortBy = function(col) {
      if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
      else { sortCol = col; sortDir = 'asc'; }
      allRows.sort(function(a, b) {
        var va = getSortVal(a, col), vb = getSortVal(b, col);
        var res = va < vb ? -1 : (va > vb ? 1 : 0);
        return sortDir === 'asc' ? res : -res;
      });
      allRows.forEach(function(r) { tbody.appendChild(r); });
      document.querySelectorAll('#reportTable thead .th-sort').forEach(function(btn) {
        var c = btn.getAttribute('data-col');
        btn.classList.toggle('th-sorted', c === sortCol);
        var icon = btn.querySelector('.th-sort-icon');
        if (icon) {
          if (c === sortCol) {
            icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
          } else {
            icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
          }
        }
      });
      currentPage = 1;
      renderTable();
    };

    function getFiltered() {
      return allRows.filter(function(r) {
        var sf = statusFilter(r.getAttribute('data-status'));
        var text = r.textContent.toLowerCase();
        var matchS = filterStatus === 'all' || sf === filterStatus;
        var matchQ = !filterSearch || text.includes(filterSearch);
        return matchS && matchQ;
      });
    }

    function renderTable() {
      var filtered = getFiltered();
      allRows.forEach(function(r) { r.style.display = 'none'; });
      var start = (currentPage - 1) * PER_PAGE;
      var end = start + PER_PAGE;
      filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
      renderPagination(filtered.length);
    }

    function renderPagination(total) {
      if (!pag) return;
      var tp = Math.max(1, Math.ceil(total / PER_PAGE));
      if (currentPage > tp) currentPage = tp;
      if (currentPage < 1) currentPage = 1;
      var opts = [10,15,20,30,50].map(function(n) {
        return '<option value="' + n + '"' + (PER_PAGE === n ? ' selected' : '') + '>' + n + '</option>';
      }).join('');
      var h = '<div class="dt-pagination-right">';
      h += '<div class="dt-rows"><span>Rows per page</span><select onchange="RPT.setPerPage(this.value)">' + opts + '</select></div>';
      h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
      h += '<div class="dt-page-btns">';
      h += '<button type="button" class="dt-page-btn" title="Go to first page" onclick="RPT.pageGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to previous page" onclick="RPT.pageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to next page" onclick="RPT.pageGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
      h += '<button type="button" class="dt-page-btn" title="Go to last page" onclick="RPT.pageGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
      h += '</div></div>';
      pag.innerHTML = h;
    }

    window.RPT.pageGo = function(page) {
      var total = Math.ceil(getFiltered().length / PER_PAGE);
      if (page < 1 || page > total) return;
      currentPage = page;
      renderTable();
    };

    window.RPT.setPerPage = function(v) {
      PER_PAGE = parseInt(v, 10);
      currentPage = 1;
      renderTable();
    };

    window.RPT.view = function(id) { openReportDetails(id); };
    window.RPT.archive = function(id) { archiveReport(id); };
    window.RPT.resubmit = function() {
      var id = window.__currentReportId;
      var r = reportIndex[id];
      if (!r) return;
      closeReportDrawer();
      openSubmitReportModal();
      var title = document.getElementById('submitReportTitle');
      if (title) title.textContent = 'Resubmit a Report';
      var desc = document.getElementById('submitReportDesc');
      if (desc) desc.textContent = 'Review your details and resubmit the report to DCVS.';
      var form = document.getElementById('submitReportForm');
      if (!form) return;
      rptShowStep(1);
      var typeEl = form.querySelector('input[name="report_type"][value="' + (r.typeKey || '') + '"]');
      if (typeEl) typeEl.checked = true;
      var urg = form.querySelector('select[name="urgency"]');
      if (urg) urg.value = r.urgency || 'medium';
      var loc = form.querySelector('input[name="location"]');
      if (loc) loc.value = (r.location && r.location !== '-') ? r.location : '';
      var dt = form.querySelector('input[name="report_datetime"]');
      if (dt) dt.value = r.createdRaw || '';
      var rdesc = r.description || '';
      var sepIdx = rdesc.indexOf(' - ');
      var animalDesc = sepIdx !== -1 ? rdesc.substring(0, sepIdx) : rdesc;
      var detailDesc = sepIdx !== -1 ? rdesc.substring(sepIdx + 3) : '';
      var ad = form.querySelector('input[name="animal_desc"]');
      if (ad) ad.value = animalDesc;
      var dd = form.querySelector('textarea[name="description"]');
      if (dd) dd.value = detailDesc;
      var ph = form.querySelector('input[name="photo_url"]');
      if (!ph) { ph = document.createElement('input'); ph.type = 'hidden'; ph.name = 'photo_url'; form.appendChild(ph); }
      ph.value = r.photo || '';
      var preview = document.getElementById('reportPhotoPreview');
      if (preview) {
        if (r.photo) {
          preview.style.display = 'block';
          preview.querySelector('img').src = r.photo;
        } else {
          preview.style.display = 'none';
        }
      }
    };
    window.RPT.refresh = function() {
      var t2 = document.getElementById('reportTable');
      var tb2 = t2 ? t2.querySelector('tbody') : null;
      if (tb2) { tbody = tb2; allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr')); }
      currentPage = 1;
      renderTable();
    };

    var searchInput = document.getElementById('reportSearchInput');
    if (searchInput) {
      searchInput.addEventListener('keyup', function() {
        filterSearch = this.value.toLowerCase().trim();
        currentPage = 1;
        renderTable();
      });
    }
    var statusSelect = document.getElementById('reportStatusSelect');
    if (statusSelect) {
      statusSelect.addEventListener('change', function() {
        filterStatus = this.value;
        currentPage = 1;
        renderTable();
      });
    }

    renderTable();
  })();

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var d = document.getElementById('rptDrawer');
      if (d && d.classList.contains('open')) closeReportDrawer();
    }
  });

  function computeSummary() {
    var total = window.__reportList.length;
    var inProg = 0, res = 0;
    window.__reportList.forEach(function(r) {
      if (r.status === 'resolved') res++;
      else if (['pending','validated','confirmed','scheduled'].indexOf(r.status) !== -1) inProg++;
    });
    var el;
    el = document.getElementById('rptTotal'); if (el) el.textContent = total;
    el = document.getElementById('rptInProgress'); if (el) el.textContent = inProg;
    el = document.getElementById('rptResolved'); if (el) el.textContent = res;
  }
  computeSummary();
})();
</script>

<script>
// �"?�"? Submit Report wizard �"?�"?
var __rptCurrentStep = 1;
function rptShowStep(n) {
  var form = document.getElementById('submitReportForm');
  if (!form) return;
  var total = 4;
  __rptCurrentStep = n;
  form.querySelectorAll('.rpt-form-step').forEach(function(s) {
    s.style.display = (parseInt(s.getAttribute('data-step')) === n) ? '' : 'none';
  });
  form.querySelectorAll('.rpt-step-indicator .rpt-step').forEach(function(s) {
    var sn = parseInt(s.getAttribute('data-step'));
    s.classList.toggle('active', sn === n);
    s.classList.toggle('done', sn < n);
  });
  var backBtn = document.getElementById('rptStepBack');
  var nextBtn = document.getElementById('rptStepNext');
  var submitBtn = document.getElementById('rptStepSubmit');
  if (backBtn) backBtn.style.display = n > 1 ? '' : 'none';
  if (nextBtn) nextBtn.style.display = n < total ? '' : 'none';
  if (submitBtn) submitBtn.style.display = n === total ? '' : 'none';
}
function openSubmitReportModal() {
  var form = document.getElementById('submitReportForm');
  if (form) form.reset();
  rptShowStep(1);
  var preview = document.getElementById('reportPhotoPreview');
  if (preview) preview.style.display = 'none';
  var title = document.getElementById('submitReportTitle');
  if (title) title.textContent = 'Submit a Report';
  var desc = document.getElementById('submitReportDesc');
  if (desc) desc.textContent = 'Report stray, rabies or animal welfare concerns to DCVS.';
  openModal('submitReportModal');
}
function rptStep(dir) {
  var form = document.getElementById('submitReportForm');
  var next = __rptCurrentStep + dir;
  var total = 4;
  if (next < 1 || next > total) return;
  if (dir > 0) {
    var stepEl = form.querySelector('.rpt-form-step[data-step="' + __rptCurrentStep + '"]');
    var inputs = stepEl ? stepEl.querySelectorAll('input, select, textarea') : [];
    var valid = true;
    inputs.forEach(function(inp) {
      if (inp.hasAttribute('required') && !inp.value.trim()) valid = false;
      if (inp.type === 'radio' && inp.name) {
        var group = form.querySelectorAll('input[name="' + inp.name + '"]');
        var checked = Array.prototype.some.call(group, function(r) { return r.checked; });
        if (!checked) valid = false;
      }
    });
    if (!valid) {
      showToast('Please complete the required fields before continuing.', 'error');
      return;
    }
  }
  rptShowStep(next);
}
function previewPetImage(input, boxId, imgId) {
  var box = document.getElementById(boxId);
  var img = document.getElementById(imgId);
  if (!img) return;
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      img.style.display = 'block';
      if (box) {
        box.classList.add('has-image');
        var ph = box.querySelector('.upload-placeholder');
        if (ph) ph.style.display = 'none';
      }
      input.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  } else {
    img.style.display = 'none';
    if (box) {
      box.classList.remove('has-image');
      var ph = box.querySelector('.upload-placeholder');
      if (ph) ph.style.display = '';
    }
    input.style.display = '';
  }
}
function previewReportPhoto(input, previewId, imgId) {
  var area = input.closest('.rpt-upload-area') || document.getElementById(previewId);
  var img = document.getElementById(imgId || (previewId || 'reportPhotoPreview'));
  if (!img) return;
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      img.style.display = 'block';
      if (area) {
        var ph = area.querySelectorAll('i, span');
        ph.forEach(function(el) { if (el !== img) el.style.display = 'none'; });
        area.style.border = 'none';
        area.style.background = 'transparent';
        area.style.padding = '0';
      }
      input.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  } else {
    img.style.display = 'none';
    if (area) {
      var ph = area.querySelectorAll('i, span');
      ph.forEach(function(el) { if (el !== img) el.style.display = ''; });
      area.style.border = '';
      area.style.background = '';
      input.style.display = '';
    }
  }
}
document.addEventListener('DOMContentLoaded', function() {
  var form = document.getElementById('submitReportForm');
  if (form) {
    form.addEventListener('submit', function() {
      var typeEl = form.querySelector('input[name="report_type"]:checked');
      if (!typeEl) { showToast('Please select a report type.', 'error'); return false; }
    });
  }
});
</script>

<script>
// �"?�"? Appointments table (search/filter/sort/paginate) �"?�"?
window.APPT = window.APPT || {};

(function() {
  var PER_PAGE = 10;
  var currentPage = 1;
  var filterSearch = '';
  var filterStatus = 'all';
  var sortCol = '';
  var sortDir = 'asc';
  var table = document.getElementById('apptTable');
  var tbody = table ? table.querySelector('tbody') : null;
  var allRows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
  var pag = document.getElementById('apptPagination');

  function getSortVal(row, col) {
    switch (col) {
      case 'ref': { var nm = row.querySelector('.client-name'); return (nm ? nm.textContent : '').toLowerCase(); }
      case 'service': return (row.getAttribute('data-service') || '').toLowerCase();
      case 'pet': return (row.getAttribute('data-pet') || '').toLowerCase();
      case 'date': return parseInt(row.getAttribute('data-date') || '0', 10);
      case 'time': return parseInt(row.getAttribute('data-time') || '0', 10);
      case 'submitted': return parseInt(row.getAttribute('data-submitted') || '0', 10);
      case 'submittedtime': return parseInt(row.getAttribute('data-submitted') || '0', 10);
      case 'status': return (row.getAttribute('data-status') || '');
    }
    return '';
  }

  window.APPT.sortBy = function(col) {
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = 'asc'; }
    allRows.sort(function(a, b) {
      var va = getSortVal(a, col), vb = getSortVal(b, col);
      var res = va < vb ? -1 : (va > vb ? 1 : 0);
      return sortDir === 'asc' ? res : -res;
    });
    allRows.forEach(function(r) { tbody.appendChild(r); });
    document.querySelectorAll('#apptTable thead .th-sort').forEach(function(btn) {
      var c = btn.getAttribute('data-col');
      btn.classList.toggle('th-sorted', c === sortCol);
      var icon = btn.querySelector('.th-sort-icon');
      if (icon) {
        if (c === sortCol) {
          icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
        } else {
          icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
        }
      }
    });
    currentPage = 1;
    renderTable();
  };

  function getFiltered() {
    return allRows.filter(function(r) {
      var status = r.getAttribute('data-status');
      var text = r.textContent.toLowerCase();
      var matchS = filterStatus === 'all' || status === filterStatus;
      var matchQ = !filterSearch || text.includes(filterSearch);
      return matchS && matchQ;
    });
  }

  function renderTable() {
    var filtered = getFiltered();
    allRows.forEach(function(r) { r.style.display = 'none'; });
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });
    renderPagination(filtered.length);
  }

  function renderPagination(total) {
    if (!pag) return;
    var tp = Math.max(1, Math.ceil(total / PER_PAGE));
    if (currentPage > tp) currentPage = tp;
    if (currentPage < 1) currentPage = 1;
    var opts = [10,15,20,30,50].map(function(n) {
      return '<option value="' + n + '"' + (PER_PAGE === n ? ' selected' : '') + '>' + n + '</option>';
    }).join('');
    var h = '<div class="dt-pagination-right">';
    h += '<div class="dt-rows"><span>Rows per page</span><select onchange="APPT.setPerPage(this.value)">' + opts + '</select></div>';
    h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
    h += '<div class="dt-page-btns">';
    h += '<button type="button" class="dt-page-btn" title="Go to first page" onclick="APPT.pageGo(1)"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
    h += '<button type="button" class="dt-page-btn" title="Go to previous page" onclick="APPT.pageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
    h += '<button type="button" class="dt-page-btn" title="Go to next page" onclick="APPT.pageGo(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
    h += '<button type="button" class="dt-page-btn" title="Go to last page" onclick="APPT.pageGo(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
    h += '</div></div>';
    pag.innerHTML = h;
  }

  window.APPT.pageGo = function(page) {
    var total = Math.ceil(getFiltered().length / PER_PAGE);
    if (page < 1 || page > total) return;
    currentPage = page;
    renderTable();
  };

  window.APPT.setPerPage = function(v) {
    PER_PAGE = parseInt(v, 10);
    currentPage = 1;
    renderTable();
  };

  window.APPT.archive = function(id) { archiveAppointment(id); };
  var apptIndex = null;
  function getAppt(id) {
    if (!apptIndex) {
      apptIndex = {};
      (window.__apptList || []).forEach(function(a) { apptIndex[a.id] = a; });
    }
    return apptIndex[id];
  }
  var apptColors = {
    'pending': ['#fef3c7', '#92400e'],
    'scheduled': ['#dcebe0', '#045e1f'],
    'rescheduled': ['#ede9fe', '#6d28d9'],
    'completed': ['#e2e8f0', '#475569'],
    'cancelled': ['#fee2e2', '#991b1b'],
    'no_show': ['#fef3c7', '#92400e']
  };

  window.APPT.view = function(id) {
    var a = getAppt(id);
    if (!a) return;
    window.__currentApptId = a.id;
    var set = function(eid, val) { var e = document.getElementById(eid); if (e) e.textContent = val; };
    set('adHeadService', a.service);
    set('adHeadRef', a.ref);
    set('adRef', a.ref);
    set('adService', a.service);
    set('adPet', a.pet);
    set('adDate', a.date);
    set('adTime', a.time);
    set('adCreated', a.created + ' . ' + a.createdTime);
    set('adNotes', a.notes === '-' ? '-' : a.notes);
    var c = apptColors[a.status] || ['#f1f5f9', '#64748b', '#e4e4e7'];
    var pill = '<span class="appt-status-pill" style="background:' + c[0] + ';color:' + c[1] + ';border-color:' + (c[2] || '#e4e4e7') + ';">' + a.statusLabel + '</span>';
    var stEl = document.getElementById('adStatus');
    if (stEl) stEl.innerHTML = pill;
    var nextEl = document.getElementById('adNextList');
    if (nextEl) {
      var steps = apptNextSteps(a.status);
      nextEl.innerHTML = steps.map(function(t) { return '<li>' + t + '</li>'; }).join('');
    }
    var changeable = ['pending', 'scheduled', 'rescheduled'].indexOf(a.status) !== -1;
    document.querySelectorAll('#apptDrawer .appt-change-btn').forEach(function(b) {
      b.style.display = changeable ? '' : 'none';
    });
    document.getElementById('apptDrawer').classList.add('open');
    document.getElementById('apptDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  };

  function apptNextSteps(status) {
    if (status === 'completed') {
      return [
        'Your appointment has been completed.',
        'You may view the details in your report or service history.',
        'Thank you for using DCVS services.'
      ];
    }
    if (status === 'cancelled' || status === 'no_show') {
      return [
        'This appointment is no longer active.',
        'If you still need this service, please submit a new appointment request.'
      ];
    }
    return [
      'Arrive at the DCVS office on your scheduled date and time.',
      'Bring your pet and required documents.',
      'Your pet will undergo the necessary pre-service assessment.',
      'Wait for the service completion/update.'
    ];
  }

  window.APPT.editFromDrawer = function() {
    var id = window.__currentApptId;
    var a = getAppt(id);
    if (!a) return;
    document.getElementById('editAppointmentId').value = a.id;
    document.getElementById('editAppointmentType').value = (a.service || '').toLowerCase();
    document.getElementById('editAppointmentPet').value = a.petId || '';
    document.getElementById('editAppointmentDate').value = a.dateRaw || '';
    document.getElementById('editAppointmentTime').value = a.timeRaw || '';
    document.getElementById('editAppointmentNotes').value = (a.notes && a.notes !== '-') ? a.notes : '';
    closeApptDrawer();
    openModal('editAppointmentModal');
  };

  window.closeApptDrawer = function() {
    document.getElementById('apptDrawer').classList.remove('open');
    document.getElementById('apptDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var d = document.getElementById('apptDrawer');
      if (d && d.classList.contains('open')) closeApptDrawer();
    }
  });
  window.APPT.refresh = function() {
    var t2 = document.getElementById('apptTable');
    var tb2 = t2 ? t2.querySelector('tbody') : null;
    if (tb2) { tbody = tb2; allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr')); }
    currentPage = 1;
    renderTable();
  };

  function computeSummary() {
    var rows = allRows;
    var total = rows.length, pending = 0, scheduled = 0, completed = 0;
    rows.forEach(function(r) {
      var st = r.getAttribute('data-status');
      if (st === 'pending') pending++;
      else if (st === 'scheduled' || st === 'rescheduled') scheduled++;
      else if (st === 'completed') completed++;
    });
    var el;
    el = document.getElementById('apptTotal'); if (el) el.textContent = total;
    el = document.getElementById('apptPending'); if (el) el.textContent = pending;
    el = document.getElementById('apptScheduled'); if (el) el.textContent = scheduled;
    el = document.getElementById('apptCompleted'); if (el) el.textContent = completed;
  }

  var searchInput = document.getElementById('apptSearchInput');
  if (searchInput) {
    searchInput.addEventListener('keyup', function() {
      filterSearch = this.value.toLowerCase().trim();
      currentPage = 1;
      renderTable();
    });
  }
  var statusSelect = document.getElementById('apptStatusSelect');
  if (statusSelect) {
    statusSelect.addEventListener('change', function() {
      filterStatus = this.value;
      currentPage = 1;
      renderTable();
    });
  }

  renderTable();
  computeSummary();
})();
</script>

<!-- Submit Report Modal -->
<div class="modal" id="submitReportModal">
  <form class="modal-form sd-dialog" id="submitReportForm" action="submit-report.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="return_section" value="my-reports">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:620px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('submitReportModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="submitReportTitle">Submit a Report</h2>
        <p class="sd-dialog-description" id="submitReportDesc">Report stray, rabies or animal welfare concerns to DCVS.</p>
      </div>
      <div class="sd-field-group">
        <div class="rpt-step-indicator">
          <div class="rpt-step active" data-step="1"><span class="rpt-step-num">1</span><span class="rpt-step-label">What?</span></div>
          <div class="rpt-step-line"></div>
          <div class="rpt-step" data-step="2"><span class="rpt-step-num">2</span><span class="rpt-step-label">Where?</span></div>
          <div class="rpt-step-line"></div>
          <div class="rpt-step" data-step="3"><span class="rpt-step-num">3</span><span class="rpt-step-label">Details</span></div>
          <div class="rpt-step-line"></div>
          <div class="rpt-step" data-step="4"><span class="rpt-step-num">4</span><span class="rpt-step-label">Photos</span></div>
        </div>

        <!-- Step 1: What happened? -->
        <div class="rpt-form-step" data-step="1">
          <p class="rpt-step-heading">What would you like to report?</p>
          <div class="rpt-type-grid">
            <?php
            $typeOpts = ['stray' => ['Stray Animal', 'fas fa-paw'], 'injured' => ['Injured Animal', 'fas fa-heart-broken'], 'rabies' => ['Rabies Concern', 'fas fa-exclamation-triangle'], 'dead' => ['Dead Animal', 'fas fa-skull']];
            foreach ($typeOpts as $val => $t):
            ?>
            <label class="rpt-type-card">
              <input type="radio" name="report_type" value="<?php echo $val; ?>" required>
              <span class="rpt-type-icon"><i class="<?php echo $t[1]; ?>"></i></span>
              <span class="rpt-type-text"><?php echo $t[0]; ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="form-group" style="margin-top:1rem;">
            <label>How urgent is this? <span class="req">*</span></label>
            <select name="urgency" class="modal-form-control">
              <option value="low">Low - Not urgent</option>
              <option value="medium" selected>Medium - Needs attention within 24 hours</option>
              <option value="high">High - Immediate attention required</option>
            </select>
          </div>
        </div>

        <!-- Step 2: Where? -->
        <div class="rpt-form-step" data-step="2" style="display:none;">
          <p class="rpt-step-heading">Where did it happen?</p>
          <div class="form-group">
            <label>Location (Barangay / Street) <span class="req">*</span></label>
            <input type="text" name="location" class="modal-form-control" placeholder="e.g. Brgy. <?php echo htmlspecialchars($barangayName); ?>, near the market" required />
          </div>
          <div class="form-group">
            <label>When did it happen? <span class="req">*</span></label>
            <input type="datetime-local" name="report_datetime" id="reportDatetime" class="modal-form-control" min="<?php echo date('Y-m-d\TH:i'); ?>" required />
          </div>
        </div>

        <!-- Step 3: Details -->
        <div class="rpt-form-step" data-step="3" style="display:none;">
          <p class="rpt-step-heading">Describe the situation</p>
          <div class="form-group">
            <label>Animal Description <span class="req">*</span></label>
            <input type="text" name="animal_desc" class="modal-form-control" placeholder="e.g. medium-sized brown dog, no collar" required />
          </div>
          <div class="form-group">
            <label>Detailed Description <span class="req">*</span></label>
            <textarea name="description" rows="4" class="modal-form-control" placeholder="Describe what you observed, including the animal's condition, behavior, and any immediate actions taken..." required></textarea>
          </div>
        </div>

        <!-- Step 4: Photos -->
        <div class="rpt-form-step" data-step="4" style="display:none;">
          <p class="rpt-step-heading">Attach photo evidence (optional)</p>
          <div class="rpt-upload-area" onclick="document.getElementById('reportPhotoInput').click()">
            <i class="fas fa-cloud-upload-alt"></i>
            <span class="rpt-upload-title">Upload photos</span>
            <span class="rpt-upload-sub">PNG / JPG supported &#8212; max 5MB</span>
            <input type="file" id="reportPhotoInput" name="photo" accept="image/*" onchange="previewReportPhoto(this)" style="display:none;" />
            <img id="reportPhotoPreview" src="" alt="Photo preview &#8212; click to change photo" onclick="event.stopPropagation();document.getElementById('reportPhotoInput').click()" style="display:none;max-width:200px;max-height:200px;border-radius:8px;cursor:pointer;margin-top:8px;" title="Click to change photo" />
          </div>
   
        </div>

        <div class="modal-notice" style="margin-top:1rem;">
          <i class="fas fa-info-circle"></i>
          Your report will be sent to your <strong>barangay officer</strong> for validation before being forwarded to DCVS.
        </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="button" class="sd-btn sd-btn-outline" id="rptStepBack" onclick="rptStep(-1)" style="display:none;"><i class="fas fa-arrow-left"></i> Back</button>
        <span style="flex:1;"></span>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('submitReportModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-default" id="rptStepNext" onclick="rptStep(1)">Continue <i class="fas fa-arrow-right"></i></button>
        <button type="submit" class="sd-btn sd-btn-default" id="rptStepSubmit" style="display:none;"><i class="fas fa-paper-plane"></i> Submit Report</button>
      </div>
    </div>
  </form>
</div>

<!-- Report Image Preview Modal -->
<div class="modal" id="reportImageModal">
  <div class="modal-content" style="max-width: 800px;">
    <div class="modal-header">
      <h2><i class="fas fa-image"></i> Report Photo</h2>
      <button class="modal-close" onclick="closeModal('reportImageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align: center;">
      <img id="reportImagePreview" src="" alt="Report Photo" style="max-width: 100%; max-height: 600px; border-radius: 8px;">
    </div>
  </div>
</div>

<!-- Pet Image Modal -->
<div class="modal" id="petImageModal">
  <div class="modal-content" style="max-width: 800px;">
    <div class="modal-header">
      <h2><i class="fas fa-image"></i> Pet Photo</h2>
      <button class="modal-close" onclick="closeModal('petImageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align: center;">
      <img id="petImagePreview" src="" alt="Pet Photo" style="max-width: 100%; max-height: 600px; border-radius: 8px;">
    </div>
  </div>
</div>


<!-- Edit Report Modal -->
<div class="modal" id="editReportModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-file-medical-alt"></i> Edit Report</h2>
      <button class="modal-close" onclick="closeModal('editReportModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="editReportForm" action="edit-report.php" method="POST" enctype="multipart/form-data">
      <div class="modal-body">
      <input type="hidden" name="report_id" id="editReportId">
      <div class="form-row">
        <div class="form-group">
          <label>Report Type <span class="req">*</span></label>
          <select name="report_type" id="editReportType" required>
            <option value="">Select type</option>
            <?php foreach ($knownTypes as $val => $label): ?>
            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date/Time <span class="req">*</span></label>
          <input type="datetime-local" name="report_datetime" id="editReportDatetime" min="<?php echo date('Y-m-d\TH:i'); ?>" required />
        </div>
      </div>
      <div class="form-group">
        <label>Animal Description <span class="req">*</span></label>
        <input type="text" name="animal_desc" id="editReportAnimalDesc" placeholder="e.g. medium-sized brown dog, no collar" required />
      </div>
      <div class="form-group">
        <label>Location (Barangay/Street) <span class="req">*</span></label>
        <input type="text" name="location" id="editReportLocation" placeholder="e.g. Brgy. <?php echo htmlspecialchars($barangayName); ?>, near the market" required />
      </div>
      <div class="form-group">
        <label>Detailed Description <span class="req">*</span></label>
        <textarea name="description" id="editReportDescription" rows="4" placeholder="Describe what you observed..." required></textarea>
      </div>
      <div class="form-group">
        <label>Photo Evidence (optional)</label>
        <div id="editReportCurrentPhoto" style="margin-bottom: 10px; display: none;">
          <p style="font-size: 0.85rem; color: var(--text-3); margin-bottom: 5px;">Current Photo:</p>
          <img id="editReportCurrentPhotoImg" src="" alt="Current Report Photo" style="max-width: 200px; max-height: 200px; border-radius: 8px;" />
        </div>
        <input type="file" name="photo" accept="image/*" id="editReportPhoto" onchange="previewReportImage(this, 'editReportPhotoPreview')" />
        <div id="editReportPhotoPreview" style="margin-top: 10px; display: none;">
          <p style="font-size: 0.85rem; color: var(--text-3); margin-bottom: 5px;">New Photo Preview:</p>
          <img src="" alt="Report Photo Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;" />
        </div>
        <small style="color: var(--text-3);">Upload a new photo to replace the existing one (max 5MB)</small>
      </div>
      <div class="form-group">
        <label>Urgency Level</label>
        <select name="urgency" id="editReportUrgency">
          <option value="low">Low - Not urgent</option>
          <option value="medium">Medium - Needs attention within 24 hours</option>
          <option value="high">High - Immediate attention required</option>
        </select>
      </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-save"></i> Update Report</button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('editReportModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Appointment Details Drawer -->
<div class="rpt-drawer-overlay" id="apptDrawerOverlay" onclick="closeApptDrawer()"></div>
<aside class="rpt-drawer" id="apptDrawer" aria-label="Appointment details">
  <div class="rpt-drawer-head">
    <div style="min-width:0;">
      <h2 class="rpt-drawer-title">Appointment Details</h2>
      <p class="rpt-drawer-ref" id="adHeadService"></p>
      <p class="rpt-drawer-ref" id="adHeadRef"></p>
    </div>
    <button type="button" class="rpt-drawer-close" onclick="closeApptDrawer()" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="rpt-drawer-body">
    <p class="pet-drawer-section-title">Appointment Information</p>
    <div class="pet-drawer-info">
      <div class="pet-drawer-info-item"><div class="k">Request ID</div><div class="v" id="adRef"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Service</div><div class="v" id="adService"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Pet</div><div class="v" id="adPet"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Status</div><div class="v" id="adStatus"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Appointment Date</div><div class="v" id="adDate"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Appointment Time</div><div class="v" id="adTime"></div></div>
      <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="k">Submitted</div><div class="v" id="adCreated"></div></div>
      <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="k">Notes</div><div class="v" id="adNotes"></div></div>
    </div>

    <hr class="rpt-divider" />

    <div class="rpt-next">
      <div class="rpt-next-title"><i class="fas fa-info-circle"></i> What Happens Next?</div>
      <ol id="adNextList"></ol>
    </div>
  </div>
  <div class="rpt-drawer-foot">
    <button type="button" class="sd-btn sd-btn-outline appt-change-btn" onclick="APPT.editFromDrawer()">Reschedule Appointment</button>
    <button type="button" class="sd-btn sd-btn-outline appt-change-btn" style="border-color:#fecaca;color:#b91c1c;" onclick="APPT.archive(window.__currentApptId)">Cancel Appointment</button>
    <button type="button" class="sd-btn sd-btn-outline" onclick="closeApptDrawer()">Close</button>
  </div>
</aside>

<script>
window.__apptList = <?php
  $jsonAppts = [];
  foreach ($appointments as $appt) {
      $jsonAppts[] = [
          'id' => (int)$appt['appointment_id'],
          'ref' => $appt['reference_id'] ?? '#APT-' . str_pad($appt['appointment_id'], 4, '0', STR_PAD_LEFT),
          'service' => ucfirst($appt['appointment_type']),
          'pet' => $appt['pet_name'] ?? '-',
          'petId' => $appt['pet_id'] ?? '',
          'date' => date('M d, Y', strtotime($appt['preferred_date'])),
          'time' => date('g:i A', strtotime($appt['preferred_time'])),
          'dateRaw' => $appt['preferred_date'],
          'timeRaw' => $appt['preferred_time'],
          'created' => date('M d, Y', strtotime($appt['created_at'])),
          'createdTime' => date('g:i A', strtotime($appt['created_at'])),
          'status' => $appt['status'],
          'statusLabel' => ucfirst(str_replace('_', ' ', $appt['status'])),
          'notes' => $appt['notes'] ?? '-',
      ];
  }
  echo json_encode($jsonAppts);
?>;
</script>

<!-- Edit Appointment Modal -->
<div class="modal" id="editAppointmentModal">
  <form class="modal-form sd-dialog" id="editAppointmentForm" action="edit-appointment.php" method="POST">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('editAppointmentModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Edit Appointment</h2>
        <p class="sd-dialog-description">Update your appointment request details below.</p>
      </div>
      <div class="sd-field-group">
      <input type="hidden" name="appointment_id" id="editAppointmentId">
      <div class="sd-form-grid">
        <div class="sd-field">
          <label class="sd-label">Appointment Type <span class="req">*</span></label>
          <select name="appt_type" id="editAppointmentType" class="sd-input" required>
            <option value="">Select type</option>
            <option value="adoption">Adoption</option>
            <option value="kapon">Kapon (Spay/Neuter)</option>
          </select>
        </div>
        <div class="sd-field">
          <label class="sd-label">Select Pet <span class="req">*</span></label>
          <select name="pet" id="editAppointmentPet" class="sd-input" required>
            <option value="">Select pet</option>
            <?php if (empty($pets)): ?>
              <option value="" disabled>No pets registered yet</option>
            <?php else: ?>
              <?php foreach ($pets as $pet): ?>
                <option value="<?php echo $pet['pet_id']; ?>"><?php echo htmlspecialchars($pet['pet_name']); ?> (<?php echo ucfirst($pet['pet_type']); ?>)</option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div class="sd-field">
          <label class="sd-label">Preferred Date <span class="req">*</span></label>
          <input type="date" name="appt_date" id="editAppointmentDate" class="sd-input" min="<?php echo date('Y-m-d'); ?>" required />
        </div>
        <div class="sd-field">
          <label class="sd-label">Preferred Time</label>
          <select name="appt_time" id="editAppointmentTime" class="sd-input">
            <option value="09:00">9:00 AM</option>
            <option value="10:00">10:00 AM</option>
            <option value="11:00">11:00 AM</option>
            <option value="14:00">2:00 PM</option>
            <option value="15:00">3:00 PM</option>
          </select>
        </div>
      </div>
      <div id="editSlotStatus" style="font-size:0.82rem;padding:6px 10px;border-radius:6px;display:none;"></div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Notes / Reason</label>
        <textarea name="notes" id="editAppointmentNotes" rows="3" class="sd-input" placeholder="Any special notes or reasons for the appointment..."></textarea>
      </div>
      </div>
      <div class="sd-dialog-footer">
        <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-save"></i> Update Appointment</button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('editAppointmentModal')">Cancel</button>
      </div>
    </div>
  </form>
</div>

<!-- Adoption Application Modal -->
<div class="modal" id="adoptionModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-heart"></i> Adoption Application</h2>
      <button class="modal-close" onclick="closeModal('adoptionModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="adoptionForm">
      <div class="modal-body">
      <div class="form-group"><label>Reason for Adoption <span class="req">*</span></label>
        <textarea name="reason" rows="3" placeholder="Why do you want to adopt this pet?" required></textarea>
      </div>
      <div class="form-group"><label>Home Type <span class="req">*</span></label>
        <select name="home_type" required>
          <option value="">Select</option>
          <option value="house">House with yard</option>
          <option value="condo">Condominium/Apartment</option>
          <option value="rented">Rented house</option>
        </select>
      </div>
      <div class="form-group"><label>Do you have other pets?</label>
        <select name="other_pets">
          <option value="no">No</option>
          <option value="yes">Yes</option>
        </select>
      </div>
      <div class="modal-notice">
        <i class="fas fa-info-circle"></i>
        Your application will be reviewed by DCVS staff. You will be notified of the schedule.
      </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-paper-plane"></i> Submit Application</button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('adoptionModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal" id="changePasswordModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-key"></i> Change Password</h2>
      <button class="modal-close" onclick="closeModal('changePasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="changePasswordForm" action="change-password.php" method="POST">
      <div class="modal-body">
      <div class="form-group"><label>Current Password <span class="req">*</span></label><input type="password" name="current_password" required /></div>
      <div class="form-group"><label>New Password <span class="req">*</span></label><input type="password" name="new_password" required /></div>
      <div class="form-group"><label>Confirm New Password <span class="req">*</span></label><input type="password" name="confirm_password" required /></div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-save"></i> Change Password</button>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('changePasswordModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Profile Photo View Modal -->
<div class="modal" id="profilePhotoModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Profile Photo</h3>
      <button class="modal-close" onclick="closeModal('profilePhotoModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <div class="profile-photo-view">
        <?php if (!empty($_SESSION['avatar_url'])): ?>
          <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Profile Photo" id="profilePhotoViewImg">
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

<!-- Confirm Archive Modal -->
<style>
  #confirmModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
  #confirmModal .sd-dialog { width:100%; display:flex; justify-content:center; }
  #confirmModal .sd-dialog-content { position:relative; width:100%; max-width:400px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; }
  #confirmModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:center; align-items:center; }
  #confirmModal .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
  #confirmModal .sd-dialog-description { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; line-height:1.5; }
  #confirmModal .sd-dialog-footer { display:flex; justify-content:center; gap:8px; }
  #confirmModal .sd-btn-danger { background:#dc2626; border-color:#dc2626; color:#fff; }
  #confirmModal .sd-btn-danger:hover { background:#b91c1c; border-color:#b91c1c; }
</style>
<div class="modal" id="confirmModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="confirmTitle">Confirm</h2>
        <p class="sd-dialog-description" id="confirmMessage">Are you sure?</p>
      </div>
      <div class="sd-dialog-footer">
        <a id="confirmYesBtn" href="#" class="sd-btn sd-btn-danger">Yes</a>
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('confirmModal')">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle toast-icon"></i>
  <span class="toast-msg" id="toastMsg">Action successful!</span>
</div>

<script>
// Initialize Charts
document.addEventListener('DOMContentLoaded', function() {
    // Live date display
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const now = new Date();
    const dayEl = document.getElementById('todayDay');
    const monthEl = document.getElementById('todayMonth');
    const yearEl = document.getElementById('todayYear');
    if (dayEl) { dayEl.textContent = days[now.getDay()] + ', ' + now.getDate(); }
    if (monthEl) { monthEl.textContent = months[now.getMonth()] + ' ' + now.getFullYear(); }

    });

function viewPetImage(url) {
    document.getElementById('petImagePreview').src = url;
    openModal('petImageModal');
}

function editPet(btn) {
    document.getElementById('editPetId').value = btn.getAttribute('data-pet-id');
    document.getElementById('editPetName').value = btn.dataset.petName || '';
    document.getElementById('editPetSpecies').value = btn.dataset.petSpecies || '';
    document.getElementById('editPetBreed').value = btn.dataset.petBreed || '';
    document.getElementById('editPetGender').value = btn.dataset.petGender || '';
    document.getElementById('editPetAge').value = btn.dataset.petAge || '';
    document.getElementById('editPetWeight').value = btn.dataset.petWeight || '';
    document.getElementById('editPetColor').value = btn.dataset.petColor || '';
    document.getElementById('editPetVaccinated').value = btn.dataset.petVaccinated || 'no';
    var photo = btn.dataset.petPhoto || '';
    var currentPhotoDiv = document.getElementById('editPetCurrentPhoto');
    if (photo) {
        currentPhotoDiv.style.display = 'block';
        document.getElementById('editPetCurrentPhotoImg').src = photo;
    } else {
        currentPhotoDiv.style.display = 'none';
    }
    openModal('editPetModal');
}

function archivePet(id) {
    document.getElementById('confirmTitle').textContent = 'Archive Pet';
    document.getElementById('confirmMessage').textContent = 'Are you sure you want to archive this pet?';
    document.getElementById('confirmYesBtn').href = 'archive-pet.php?id=' + id;
    openModal('confirmModal');
}

function viewReportImage(url) {
    window.open(url, '_blank');
}

function editReport(btn) {
    var id = btn.getAttribute('data-report-id');
    document.getElementById('editReportId').value = id;
    document.getElementById('editReportType').value = btn.dataset.reportType || '';
    document.getElementById('editReportLocation').value = btn.dataset.location || '';
    document.getElementById('editReportUrgency').value = btn.dataset.urgency || 'low';
    document.getElementById('editReportDatetime').value = btn.dataset.createdAt || '';
    // Split combined description (format: "animal_desc - detailed description")
    var desc = btn.dataset.description || '';
    var sepIdx = desc.indexOf(' - ');
    if (sepIdx !== -1) {
        document.getElementById('editReportAnimalDesc').value = desc.substring(0, sepIdx);
        document.getElementById('editReportDescription').value = desc.substring(sepIdx + 3);
    } else {
        document.getElementById('editReportAnimalDesc').value = desc;
        document.getElementById('editReportDescription').value = '';
    }
    openModal('editReportModal');
}

function archiveReport(id) {
    document.getElementById('confirmTitle').textContent = 'Archive Report';
    document.getElementById('confirmMessage').textContent = 'Are you sure you want to archive this report?';
    document.getElementById('confirmYesBtn').href = 'archive-report.php?id=' + id;
    openModal('confirmModal');
}

function editAppointment(btn) {
    var id = btn.getAttribute('data-appointment-id');
    document.getElementById('editAppointmentId').value = id;
    document.getElementById('editAppointmentType').value = btn.dataset.appointmentType || '';
    document.getElementById('editAppointmentPet').value = btn.dataset.petId || '';
    document.getElementById('editAppointmentDate').value = btn.dataset.preferredDate || '';
    document.getElementById('editAppointmentTime').value = btn.dataset.preferredTime || '';
    document.getElementById('editAppointmentNotes').value = btn.dataset.notes || '';
    openModal('editAppointmentModal');
}

function archiveAppointment(id) {
    document.getElementById('confirmTitle').textContent = 'Archive Appointment';
    document.getElementById('confirmMessage').textContent = 'Are you sure you want to archive this appointment?';
    document.getElementById('confirmYesBtn').href = 'archive-appointment.php?id=' + id;
    openModal('confirmModal');
}

function openAdoptionAppt(petId, petName) {
    if (typeof switchSection === 'function') switchSection('book-appt');
    if (typeof window.resetBookingForm === 'function') window.resetBookingForm();
    document.getElementById('apptTypeSelect').value = 'adoption';
    document.getElementById('petSelect').value = petId;
    document.getElementById('petSelect').dispatchEvent(new Event('change'));
}

function openBookApptModal() {
    if (typeof window.resetBookingForm === 'function') window.resetBookingForm();
    if (typeof switchSection === 'function') switchSection('book-appt');
}

function toggleCustomSpecies(selectId, inputId) {
    const sel = document.getElementById(selectId);
    const inp = document.getElementById(inputId);
    if (sel.value === 'other') {
        inp.style.display = 'block';
        inp.required = true;
    } else {
        inp.style.display = 'none';
        inp.required = false;
        inp.value = '';
    }
}

function filterArchive() {
  var q = (document.getElementById('archiveSearch')?.value || '').toLowerCase();
  var t = document.getElementById('archiveTypeFilter')?.value || '';
  var counts = {
    pet: (document.querySelector('#archive .archive-section[data-type="pet"]')?.getAttribute('data-count')) || 0,
    report: (document.querySelector('#archive .archive-section[data-type="report"]')?.getAttribute('data-count')) || 0,
    appointment: (document.querySelector('#archive .archive-section[data-type="appointment"]')?.getAttribute('data-count')) || 0
  };
  var titles = { pet: 'Archived Pets', report: 'Archived Reports', appointment: 'Archived Appointments' };
  var title = document.getElementById('archiveSectionTitle');
  document.querySelectorAll('#archive .archive-section').forEach(function(s) {
    var st = s.getAttribute('data-type');
    s.style.display = (!t || st === t) ? '' : 'none';
  });
  if (title) {
    if (t && titles[t]) { title.textContent = titles[t] + ' (' + counts[t] + ')'; }
    else { title.textContent = 'Archived Items'; }
  }
  document.querySelectorAll('#archive .appt-table tbody tr').forEach(function(r) {
    var hay = (r.textContent || '').toLowerCase();
    r.style.display = (!q || hay.includes(q)) ? '' : 'none';
  });
}

function setArchiveType(type) {
  var sel = document.getElementById('archiveTypeFilter');
  if (sel) sel.value = type || '';
  var q = document.getElementById('archiveSearch');
  if (q) q.value = '';
  var counts = {
    pet: (document.querySelector('#archive .archive-section[data-type="pet"]')?.getAttribute('data-count')) || 0,
    report: (document.querySelector('#archive .archive-section[data-type="report"]')?.getAttribute('data-count')) || 0,
    appointment: (document.querySelector('#archive .archive-section[data-type="appointment"]')?.getAttribute('data-count')) || 0
  };
  var titles = { pet: 'Archived Pets', report: 'Archived Reports', appointment: 'Archived Appointments' };
  var title = document.getElementById('archiveSectionTitle');
  if (title) {
    title.textContent = (type && titles[type]) ? titles[type] + ' (' + counts[type] + ')' : 'Archived Items';
  }
  document.querySelectorAll('#archive .archive-section').forEach(function(s) {
    var match = !type || s.getAttribute('data-type') === type;
    s.style.display = match ? '' : 'none';
  });
  if (typeof filterArchive === 'function') filterArchive();
}

function checkSlot() {
    var id = this ? this.id || '' : '';
    var prefix = id.startsWith('edit') ? 'edit' : '';
    var dateEl = null, timeEl = null, status = null, form = null;
    if (prefix) {
        dateEl = document.getElementById('editAppointmentDate');
        timeEl = document.getElementById('editAppointmentTime');
        status = document.getElementById('editSlotStatus');
        form = document.getElementById('editAppointmentForm');
    } else {
        dateEl = document.getElementById('apptDate');
        timeEl = document.getElementById('apptTime');
        status = document.getElementById('slotStatus');
        form = document.getElementById('appointmentForm');
    }
    var date = dateEl?.value;
    var time = timeEl?.value;
    if (!date || !time || !status) {
        var btn = form ? form.querySelector('button[type="submit"]') : null;
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        if (status) status.style.display = 'none';
        return;
    }
    status.style.display = 'block';
    status.className = '';
    status.style.cssText = 'font-size:0.82rem;padding:6px 10px;border-radius:6px;margin-top:4px;';
    status.style.background = '#fefce8';
    status.style.color = '#92400e';
    status.textContent = 'Checking availability...';
    var exclude = prefix ? (document.getElementById('editAppointmentId')?.value || 0) : 0;
    fetch('includes/check_slot.php?date=' + encodeURIComponent(date) + '&time=' + encodeURIComponent(time) + '&exclude=' + exclude)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            if (d.available) {
                status.style.background = '#f0fdf4';
                status.style.color = '#065f46';
                status.textContent = 'Slot available';
                if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
            } else {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                var msg = d.blocked ? 'This time slot is closed by the clinic schedule.'
                                    : (d.held ? 'This time slot is already reserved by another resident.'
                                              : 'This slot is no longer available.');
                if (d.nearest_slot) {
                    var nearTime = d.nearest_slot.length > 5 ? d.nearest_slot.slice(-5) : d.nearest_slot;
                    msg += ' Nearest free: ' + nearTime;
                }
                status.textContent = msg;
                if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
            }
        })
        .catch(function() {
            status.style.display = 'none';
        });
}
document.addEventListener('DOMContentLoaded', function() {
    var pairs = [
        ['apptDate', 'apptTime'],
        ['editAppointmentDate', 'editAppointmentTime']
    ];
    pairs.forEach(function(p) {
        var d = document.getElementById(p[0]);
        var t = document.getElementById(p[1]);
        if (d) d.addEventListener('change', checkSlot);
        if (t) t.addEventListener('change', checkSlot);
    });
});

// �"?�"? Booking card (Coach Scheduling Card style) inside Request Appointment modal �"?�"?
(function() {
    var BK_SLOTS = ['09:00', '10:00', '11:00', '14:00', '15:00'];
    var bkMonthLabel = document.getElementById('bkMonthLabel');
    var bkGrid = document.getElementById('bkGridDates');
    var bkPrev = document.getElementById('bkPrevBtn');
    var bkNext = document.getElementById('bkNextBtn');
    var bkSlots = document.getElementById('bkSlots');
    var bkSlotsHint = document.getElementById('bkSlotsHint');
    var bkNextStep = document.getElementById('bkNextStep');
    if (!bkGrid) return;

    var today = new Date();
    var viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
    var selStart = null;      // ISO 'YYYY-MM-DD' (selected calendar date)
    var chosenDate = null;    // ISO 'YYYY-MM-DD' of the picked time slot
    var selectedTime = null;  // 'HH:MM'
    var availability = {};    // { 'YYYY-MM-DD': { 'HH:MM': count } }
    var blockedSlots = {};    // staff-managed closed slots
    var heldSlots = {};       // slots with an active appointment

    function isoDate(year, month, day) {
        return year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }

    function fmtTime(t) {
        var h = parseInt(t.slice(0, 2), 10);
        var m = t.slice(3, 5);
        var ap = h >= 12 ? 'PM' : 'AM';
        var hh = h % 12; if (hh === 0) hh = 12;
        return hh + ':' + m + ' ' + ap;
    }

    function isBlocked(dateKey, time) {
        return !!(blockedSlots[dateKey] && blockedSlots[dateKey][time]);
    }
    function isHeld(dateKey, time) {
        return !!(heldSlots[dateKey] && heldSlots[dateKey][time]);
    }

    function fetchAvailability() {
        var y = viewDate.getFullYear();
        var m = viewDate.getMonth() + 1;
        return fetch('appointment-availability.php?year=' + y + '&month=' + m)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                availability = d.slots || {};
                blockedSlots = d.blocked || {};
                heldSlots = d.held || {};
            })
            .catch(function() { availability = {}; blockedSlots = {}; heldSlots = {}; });
    }

    function dayOpenCount(dateKey) {
        var counts = availability[dateKey];
        var open = 0;
        BK_SLOTS.forEach(function(s) {
            if (isBlocked(dateKey, s) || isHeld(dateKey, s)) return;
            var booked = counts ? (counts[s] || 0) : 0;
            if (booked < 1) open++;
        });
        return open;
    }

    function hideConfirm() {
        // Return to the booking page (Edit Details / Back)
        closeModal('confirmBookingModal');
    }

    function clearBooking() {
        chosenDate = null;
        selectedTime = null;
        document.getElementById('apptDate').value = '';
        document.getElementById('apptTime').value = '';
        updateNextState();
    }

    // Next is enabled only when pet, appointment type, date AND time are all chosen
    function updateNextState() {
        if (!bkNextStep) return;
        var petSel = document.getElementById('petSelect');
        var typeSel = document.getElementById('apptTypeSelect');
        var ready = !!(chosenDate && selectedTime && petSel && petSel.value && typeSel && typeSel.value);
        bkNextStep.disabled = !ready;
    }

    // Populate the pet profile area from the selected pet (empty until one is chosen)
    function updatePetProfile() {
        var petSel = document.getElementById('petSelect');
        var opt = petSel && petSel.selectedIndex > -1 ? petSel.options[petSel.selectedIndex] : null;
        var filled = document.getElementById('bkPetProfileFilled');
        if (!opt || !opt.value) {
            if (filled) filled.style.display = 'none';
            return;
        }
        if (filled) filled.style.display = 'flex';

        var photo = opt.getAttribute('data-photo') || '';
        var av = document.getElementById('bkPetAvatar');
        if (av) {
            if (photo) { av.innerHTML = '<img src="' + photo + '" alt="">'; }
            else { av.innerHTML = '<i class="fas fa-paw"></i>'; }
        }
        document.getElementById('bkPetName').textContent = opt.textContent.replace(/\s*\(.*\)$/, '');

        var age = opt.getAttribute('data-age') || '';
        var breed = opt.getAttribute('data-breed') || '';
        var gender = opt.getAttribute('data-gender') || '';
        var type = opt.getAttribute('data-type') || '';
        var parts = [];
        if (age !== '' && age !== '0') parts.push(age + ' yr' + (parseInt(age, 10) > 1 ? 's' : ''));
        if (breed) parts.push(breed.charAt(0).toUpperCase() + breed.slice(1));
        if (gender) parts.push(gender.charAt(0).toUpperCase() + gender.slice(1));
        if (type) parts.push(type.charAt(0).toUpperCase() + type.slice(1));
        document.getElementById('bkPetMeta').textContent = parts.join(' . ');
    }

    function renderCalendar() {
        var year = viewDate.getFullYear();
        var month = viewDate.getMonth();
        var monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
        if (bkMonthLabel) bkMonthLabel.textContent = monthNames[month] + ' ' + year;

        bkGrid.innerHTML = '';
        var firstDay = new Date(year, month, 1).getDay();
        var startDay = firstDay === 0 ? 6 : firstDay - 1; // Mon=0
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var todayIso = isoDate(today.getFullYear(), today.getMonth(), today.getDate());

        for (var i = 0; i < startDay; i++) {
            var pad = document.createElement('div');
            pad.className = 'bk-day';
            pad.style.visibility = 'hidden';
            bkGrid.appendChild(pad);
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var key = isoDate(year, month, day);
            var cellDate = new Date(year, month, day);
            var div = document.createElement('button');
            div.type = 'button';
            div.className = 'bk-day';
            div.textContent = day;
            div.setAttribute('data-date', key);

            var isPast = cellDate < new Date(today.getFullYear(), today.getMonth(), today.getDate());
            var openCount = dayOpenCount(key);
            if (isPast || openCount === 0) {
                div.classList.add('bk-day-disabled');
                div.disabled = true;
            }
            if (key === todayIso) div.classList.add('bk-day-today');
            if (selStart !== null && key === selStart) div.classList.add('bk-day-selected');

            if (!div.disabled) {
                div.addEventListener('click', function() {
                    selectDay(this.getAttribute('data-date'));
                });
            }
            bkGrid.appendChild(div);
        }

        renderSlots();
    }

    // Single-date selection (this is a single veterinary appointment booking)
    function selectDay(key) {
        if (selStart === key) {
            selStart = null; // clicking the same date clears it
        } else {
            selStart = key;
        }
        clearBooking();
        renderCalendar();
    }

    function renderSlots() {
        bkSlots.innerHTML = '';
        if (selStart === null) {
            bkSlotsHint.textContent = 'Select your preferred appointment date.';
            return;
        }
        var dd = new Date(selStart + 'T00:00:00');
        bkSlotsHint.textContent = 'Available times on ' + dd.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) + '.';

        var counts = availability[selStart] || {};
        var anyOpen = false;
        BK_SLOTS.forEach(function(s) {
            var blocked = isBlocked(selStart, s);
            var held = isHeld(selStart, s);
            var booked = counts[s] || 0;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bk-slot-btn';
            btn.setAttribute('data-date', selStart);
            btn.setAttribute('data-time', s);
            if (blocked || held || booked >= 1) {
                btn.classList.add('bk-slot-unavailable');
                btn.textContent = fmtTime(s);
                btn.disabled = true;
                btn.title = 'Unavailable';
            } else {
                anyOpen = true;
                btn.textContent = fmtTime(s);
                btn.addEventListener('click', function() {
                    selectTime(this.getAttribute('data-date'), this.getAttribute('data-time'));
                });
            }
            if (chosenDate === selStart && selectedTime === s) btn.classList.add('bk-slot-selected');
            bkSlots.appendChild(btn);
        });

        if (!anyOpen) {
            var none = document.createElement('div');
            none.className = 'bk-slot-none';
            none.textContent = 'No available slots on this day. Please pick another date.';
            bkSlots.appendChild(none);
        }
    }

    function selectTime(dateKey, time) {
        chosenDate = dateKey;
        selectedTime = time;
        document.getElementById('apptDate').value = dateKey;
        document.getElementById('apptTime').value = time;
        updateNextState();
        var btns = bkSlots.querySelectorAll('.bk-slot-btn');
        btns.forEach(function(b) {
            b.classList.toggle('bk-slot-selected', b.getAttribute('data-date') === dateKey && b.getAttribute('data-time') === time);
        });
    }

    function showConfirm() {
        if (!chosenDate || !selectedTime) return;
        var dt = new Date(chosenDate + 'T00:00:00');
        document.getElementById('bkDetailDate').textContent = dt.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long' });
        document.getElementById('bkDetailTime').textContent = fmtTime(selectedTime);
        var typeSel = document.getElementById('apptTypeSelect');
        var typeVal = typeSel ? typeSel.value : '';
        document.getElementById('bkDetailType').textContent = typeVal === 'adoption' ? 'Pet Adoption' : (typeVal === 'kapon' ? 'Pet Neutering / Castration' : '-');
        var petSel = document.getElementById('petSelect');
        var opt = petSel && petSel.selectedIndex > -1 ? petSel.options[petSel.selectedIndex] : null;
        var petName = 'Your Pet';
        var petType = '';
        var photo = '';
        if (opt && opt.value) {
            petName = opt.textContent.replace(/\s*\(.*\)$/, '');
            petType = opt.getAttribute('data-type') || '';
            photo = opt.getAttribute('data-photo') || '';
        }
        document.getElementById('bkConfirmPetName').textContent = petName;
        document.getElementById('bkConfirmPetType').textContent = petType;
        document.getElementById('bkDetailAvailableFor').textContent = petName;
        var av = document.getElementById('bkConfirmAvatar');
        if (av) {
            if (photo) { av.innerHTML = '<img src="' + photo + '" alt="">'; }
            else { av.innerHTML = '<i class="fas fa-paw"></i>'; }
        }
        // Sync hidden form fields for submission
        document.getElementById('cfPet').value = petSel ? petSel.value : '';
        document.getElementById('cfType').value = typeVal;
        document.getElementById('apptDate').value = chosenDate;
        document.getElementById('apptTime').value = selectedTime;
        // Open the Confirm Booking dialog
        openModal('confirmBookingModal');
    }

    if (bkNextStep) {
        bkNextStep.addEventListener('click', function() {
            if (chosenDate && selectedTime) showConfirm();
        });
    }
    var bkEditDetails = document.getElementById('bkEditDetails');
    if (bkEditDetails) {
        bkEditDetails.addEventListener('click', function() { hideConfirm(); });
    }

    var petSel = document.getElementById('petSelect');
    if (petSel) {
        petSel.addEventListener('change', updatePetProfile);
        petSel.addEventListener('change', updateNextState);
        updatePetProfile();
        updateNextState();
    }
    var typeSel = document.getElementById('apptTypeSelect');
    if (typeSel) {
        typeSel.addEventListener('change', updateNextState);
        updateNextState();
    }

    function resetSelection() {
        selStart = null;
        clearBooking();
    }

    bkPrev.addEventListener('click', function() {
        viewDate.setMonth(viewDate.getMonth() - 1);
        resetSelection();
        fetchAvailability().then(renderCalendar);
    });
    bkNext.addEventListener('click', function() {
        viewDate.setMonth(viewDate.getMonth() + 1);
        resetSelection();
        fetchAvailability().then(renderCalendar);
    });

    fetchAvailability().then(renderCalendar);

    // Reset the booking form so each fresh visit starts clean
    window.resetBookingForm = function() {
        viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
        resetSelection();
        var p = document.getElementById('petSelect');
        if (p) { p.value = ''; }
        var t = document.getElementById('apptTypeSelect');
        if (t) { t.value = ''; }
        updatePetProfile();
        fetchAvailability().then(renderCalendar);
    };
})();

// Guard: prevent submitting the request without a pet, service, date and time
document.addEventListener('DOMContentLoaded', function() {
    var apptForm = document.getElementById('appointmentForm');
    if (apptForm) {
        apptForm.addEventListener('submit', function(e) {
            var ty = document.getElementById('cfType').value;
            var pet = document.getElementById('cfPet').value;
            var d = document.getElementById('apptDate').value;
            var t = document.getElementById('apptTime').value;
            if (!ty || !pet) {
                e.preventDefault();
                alert('Please select your pet and service first.');
                return;
            }
            if (!d || !t) {
                e.preventDefault();
                alert('Please select a date and an available time.');
            }
        });
    }
});

function openAdoptionModal(listingId, petName) {
    openModal('adoptionModal');
}

// �"?�"? Adoption listings: filter / search / sort / more-menu �"?�"?
var _adoptCurrentFilter = 'all';
function setAdoptFilter(cat, btn) {
    _adoptCurrentFilter = cat;
    document.querySelectorAll('#adoptPills .adopt-pill').forEach(function(p) { p.classList.toggle('active', p === btn); });
    filterAdopt();
}
function filterAdopt() {
    var q = (document.getElementById('adoptSearch')?.value || '').toLowerCase();
    var grid = document.getElementById('adoptGrid');
    if (!grid) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.adopt-card'));
    cards.forEach(function(c) {
        var ref = (c.querySelector('.adopt-ref-badge')?.textContent || '').toLowerCase();
        var okCat = _adoptCurrentFilter === 'all' || c.dataset.cat === _adoptCurrentFilter;
        var okQ = !q || (c.dataset.name || '').includes(q) || (c.dataset.type || '').includes(q) || ref.includes(q);
        c.style.display = okCat && okQ ? '' : 'none';
    });
}

// �"?�"? Profile Settings Edit Toggle �"?�"?
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

// Enable all disabled inputs before form submission
document.addEventListener('DOMContentLoaded', function() {
    var sf = document.getElementById('settingsForm');
    if (sf) {
        sf.addEventListener('submit', function() {
            this.querySelectorAll('[disabled]').forEach(function(el) { el.disabled = false; });
        });
    }
    window.previewAvatar = function(e, imgId, containerId, plainId) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var img = document.getElementById(imgId);
                if (img) {
                    img.src = ev.target.result;
                    var cont = document.getElementById(containerId);
                    var plain = document.getElementById(plainId);
                    if (cont) cont.style.display = '';
                    if (plain) plain.style.display = 'none';
                }
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

function switchSection(sectionId) {
  document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
  var target = document.getElementById(sectionId);
  if (target) target.style.display = 'block';
  document.querySelectorAll('.nav-link').forEach(function(l) { l.classList.remove('active'); });
  var nav = document.querySelector('.nav-link[data-section="' + sectionId + '"]');
  if (nav) {
    nav.classList.add('active');
    var group = nav.closest('.nav-group');
    if (group) group.classList.add('open');
  }
  document.querySelectorAll('.nav-group').forEach(function(g) {
    if (!g.contains(document.querySelector('.nav-link[data-section="' + sectionId + '"]'))) {
      g.classList.remove('active');
    }
  });
  var labels = {
    'overview':'Dashboard','my-pets':'My Pets','my-reports':'My Reports',
    'appointments':'Appointments','archive':'Archive','adoption':'Adoption Listings',
    'book-appt':'Book an Appointment',

    'account-settings':'Account Settings'
  };
  var bc = document.getElementById('breadcrumbCurrent');
  if (bc) bc.textContent = labels[sectionId] || sectionId;
  document.body.classList.toggle('page-white', sectionId === 'my-pets' || sectionId === 'my-reports' || sectionId === 'appointments' || sectionId === 'book-appt' || sectionId === 'archive' || sectionId === 'account-settings');
  localStorage.setItem('activeSection', sectionId);
  window.location.hash = sectionId;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.nav-link[data-section]').forEach(function(link) {
    link.addEventListener('click', function(e) {
      var section = this.getAttribute('data-section');
      if (section) { e.preventDefault(); switchSection(section); }
    });
  });
  var hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById(hash)) {
    switchSection(hash);
  }
});

window.addEventListener('hashchange', function() {
  var hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById(hash)) {
    switchSection(hash);
  }
});
</script>
<?php if ($showAdoptModal): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    switchSection('my-pets');
    setTimeout(function() { openModal('addPetModal'); }, 300);
});
</script>
<?php endif; ?>
<script src="includes/realtime.js"></script>
<script>
document.addEventListener('dcvs:realtime', function(e) {
    var data = e.detail;
    // Appointments rows
    if (data.appointments && data.appointments.length) {
        var atable = document.querySelector('#apptTable');
        if (atable) {
            var atbody = atable.querySelector('tbody');
            var aColors = {
                'pending': ['#fef3c7', '#92400e'],
                'scheduled': ['#dcebe0', '#045e1f'],
                'rescheduled': ['#ede9fe', '#6d28d9'],
                'completed': ['#e2e8f0', '#475569'],
                'cancelled': ['#fee2e2', '#991b1b'],
                'no_show': ['#fef3c7', '#92400e']
            };
            data.appointments.forEach(function(appt) {
                if (atable.querySelector('tr[data-id="' + appt.appointment_id + '"]')) return;
                var ref = appt.reference_id || '#APT-' + String(appt.appointment_id).padStart(4, '0');
                var type = appt.appointment_type ? appt.appointment_type.charAt(0).toUpperCase() + appt.appointment_type.slice(1) : '';
                var dt = new Date(appt.preferred_date + 'T' + appt.preferred_time);
                var ds = dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                var ts = dt.toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit'});
                var st = appt.status.replace(/_/g, ' '); st = st.charAt(0).toUpperCase() + st.slice(1);
                var ac = aColors[appt.status] || ['#f1f5f9', '#64748b', '#e4e4e7'];
                var cdt = appt.created_at ? new Date(appt.created_at.replace(' ', 'T')) : null;
                var cds = cdt ? cdt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '-';
                var cts = cdt ? cdt.toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit'}) : '-';
                var atr = document.createElement('tr');
                atr.className = 'sd-tr';
                atr.setAttribute('data-id', appt.appointment_id);
                atr.setAttribute('data-status', appt.status || '');
                atr.setAttribute('data-service', (appt.appointment_type || '').toLowerCase());
                atr.setAttribute('data-pet', (appt.pet_name || '').toLowerCase());
                atr.setAttribute('data-date', appt.preferred_date ? String(Math.floor(new Date(appt.preferred_date).getTime() / 1000)) : '0');
                atr.setAttribute('data-time', appt.preferred_time ? String(Math.floor(new Date('1970-01-01T' + appt.preferred_time).getTime() / 1000)) : '0');
                atr.setAttribute('data-submitted', appt.created_at ? String(Math.floor(new Date(appt.created_at.replace(' ', 'T')).getTime() / 1000)) : '0');
                atr.setAttribute('onclick', 'APPT.view(' + appt.appointment_id + ')');
                atr.innerHTML =
                    '<td class="sd-td"><div class="client-name">' + ref + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + type + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + (appt.pet_name || '-') + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + ds + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + ts + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + cds + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + cts + '</div></td>' +
                    '<td class="sd-td"><span class="appt-status-pill" style="background:' + ac[0] + ';color:' + ac[1] + ';border-color:' + (ac[2] || '#e4e4e7') + ';">' + st + '</span></td>' +
                    '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><div class="pet-action-btns">' +
                    (appt.status === 'pending' ? '<button type="button" class="pet-action-btn" title="Edit Appointment"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></button>' : '') +
                    '<button type="button" class="pet-action-btn" title="Archive Appointment" onclick="APPT.archive(' + appt.appointment_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></button>' +
                    '</div></td>';
                atbody.appendChild(atr);
                if (window.APPT && typeof window.APPT.refresh === 'function') window.APPT.refresh();
            });
        }
    }
    // Reports rows
    if (data.reports && data.reports.length) {
        var rtable = document.querySelector('#reportTable');
        if (rtable) {
            var rtbody = rtable.querySelector('tbody');
            var rColors = {
                'pending':   ['#fef3c7', '#92400e'],
                'validated': ['#dbeafe', '#1d4ed8'],
                'confirmed': ['#dbeafe', '#1d4ed8'],
                'scheduled': ['#dbeafe', '#1d4ed8'],
                'resolved':  ['#dcebe0', '#045e1f'],
                'rejected':  ['#fee2e2', '#991b1b']
            };
            var rLabels = {
                'pending': 'Pending', 'validated': 'Under Review', 'confirmed': 'Confirmed',
                'scheduled': 'Scheduled', 'resolved': 'Resolved', 'rejected': 'Rejected'
            };
            var rKnownTypes = {'stray':'Stray Animal','rabies':'Rabies Case','injured':'Injured Animal','dead':'Dead Animal','other':'Other'};
            data.reports.forEach(function(rpt) {
                if (rtable.querySelector('tr[data-id="' + rpt.report_id + '"]')) return;
                var ref = rpt.reference_id || '#RPT-' + String(rpt.report_id).padStart(4, '0');
                var rc = rColors[rpt.status] || ['#f1f5f9', '#64748b', '#e4e4e7'];
                var rlab = rLabels[rpt.status] || (rpt.status ? rpt.status.charAt(0).toUpperCase() + rpt.status.slice(1) : '');
                var typeLabel = (rKnownTypes[(rpt.report_type || '').toLowerCase()] || rpt.report_type || 'Report') + ' Report';
                var catLabel = (rKnownTypes[(rpt.report_type || '').toLowerCase()] || rpt.report_type || 'Report');
                var dt = new Date((rpt.created_at || '').replace(' ', 'T'));
                var ds = dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                var resolvedDate = '-';
                var resolvedRaw = '';
                if (rpt.status === 'resolved' && rpt.updated_at) {
                    resolvedRaw = rpt.updated_at;
                    resolvedDate = new Date(rpt.updated_at.replace(' ', 'T')).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                }
                var rtr = document.createElement('tr');
                rtr.className = 'sd-tr';
                rtr.setAttribute('data-id', rpt.report_id);
                rtr.setAttribute('data-status', rpt.status);
                rtr.setAttribute('data-type', typeLabel.toLowerCase());
                rtr.setAttribute('data-location', (rpt.location_address || '').toLowerCase());
                rtr.setAttribute('data-date', String(rpt.created_at ? Math.floor(new Date(rpt.created_at.replace(' ', 'T')).getTime() / 1000) : '0'));
                rtr.setAttribute('data-resolved', resolvedRaw);
                rtr.setAttribute('onclick', 'RPT.view(' + rpt.report_id + ')');
                rtr.innerHTML =
                    '<td class="sd-td"><div class="client-name">' + ref + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + typeLabel + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + (rpt.location_address || '-') + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + ds + '</div></td>' +
                    '<td class="sd-td"><span class="appt-status-pill" style="background:' + rc[0] + ';color:' + rc[1] + ';border-color:' + (rc[2] || '#e4e4e7') + ';">' + rlab + '</span></td>' +
                    '<td class="sd-td"><span class="pet-cat-tag">' + catLabel + '</span></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + resolvedDate + '</div></td>' +
                    '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><div class="pet-action-btns">' +
                    '<button type="button" class="pet-action-btn" title="View Report" onclick="RPT.view(' + rpt.report_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>' +
                    '<button type="button" class="pet-action-btn" title="Archive Report" onclick="RPT.archive(' + rpt.report_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></button>' +
                    '</div></td>';
                rtbody.appendChild(rtr);
                if (window.RPT && typeof window.RPT.refresh === 'function') window.RPT.refresh();
            });
        }
    }
    // Pets rows
    if (data.pets && data.pets.length) {
        var table = document.querySelector('#petTable');
        if (table) {
            var tbody = table.querySelector('tbody');
            var vaxColors = {
                'Fully vaccinated': ['#dcebe0', '#045e1f'],
                'Partially vaccinated': ['#fef3c7', '#92400e'],
                'Not vaccinated': ['#fee2e2', '#991b1b']
            };
            data.pets.forEach(function(pet) {
                if (table.querySelector('tr[data-id="' + pet.pet_id + '"]')) return;
                var cat = pet.from_adoption ? 'adopted' : 'owned';
                var catLabel = pet.from_adoption ? 'Adopted' : 'Owned';
                var vaxText = pet.vaccination_status || 'Not vaccinated';
                var vc = vaxColors[vaxText] || ['#f1f5f9', '#64748b', '#e4e4e7'];
                var photo = pet.photo_url || '';
                var initial = (pet.pet_name || 'P').charAt(0).toUpperCase();
                var ref = pet.reference_id || '#PET-' + String(pet.pet_id).padStart(4, '0');
                var sp = (pet.pet_type || '').charAt(0).toUpperCase() + (pet.pet_type || '').slice(1);
                var br = pet.breed || '-';
                var adoptedDate = '';
                var adoptedRaw = '';
                if (cat === 'adopted' && pet.adopted_date) {
                    adoptedRaw = pet.adopted_date;
                    var d = new Date(pet.adopted_date.replace(' ', 'T'));
                    adoptedDate = d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
                }
                var tr = document.createElement('tr');
                tr.className = 'sd-tr';
                tr.setAttribute('data-id', pet.pet_id);
                tr.setAttribute('data-cat', cat);
                tr.setAttribute('data-species', (pet.pet_type || '').toLowerCase());
                tr.setAttribute('data-breed', (pet.breed || '').toLowerCase());
                tr.setAttribute('data-vaccination', vaxText.toLowerCase());
                tr.setAttribute('data-adopted', adoptedRaw);
                tr.setAttribute('data-registered', pet.created_at || '');
                tr.setAttribute('onclick', 'PET.view(' + pet.pet_id + ')');
                var registeredDate = pet.created_at ? new Date(pet.created_at.replace(' ', 'T')).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '-';
                tr.innerHTML =
                    '<td class="sd-td"><div class="client-cell"><div class="pet-avatar-box">' +
                    (photo ? '<img src="' + photo + '" alt="">' : initial) +
                    '</div><div class="pet-cell-main"><div class="client-name">' + (pet.pet_name || '') + '</div></div></div></td>' +
                    '<td class="sd-td"><div class="pet-id-label">' + ref + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + sp + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + br + '</div></td>' +
                    '<td class="sd-td"><span class="appt-status-pill" style="background:' + vc[0] + ';color:' + vc[1] + ';border-color:' + (vc[2] || '#e4e4e7') + ';">' + vaxText + '</span></td>' +
                    '<td class="sd-td"><span class="pet-cat-tag ' + cat + '">' + catLabel + '</span></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + (adoptedDate || '-') + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + registeredDate + '</div></td>' +
                    '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><div class="pet-action-btns">' +
                    '<a href="pet-profile.php?id=' + pet.pet_id + '&ref=resident" class="pet-action-btn" title="Pet Resume"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></a>' +
                    '<button type="button" class="pet-action-btn" title="View Profile" onclick="PET.view(' + pet.pet_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>' +
                    (cat !== 'adopted' ? '<button type="button" class="pet-action-btn" title="Edit Pet" onclick="PET.edit(' + pet.pet_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></button>' : '') +
                    '<button type="button" class="pet-action-btn" title="Archive Pet" onclick="PET.archive(' + pet.pet_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></button>' +
                    '</div></td>';
                tbody.appendChild(tr);
                if (window.PET && typeof window.PET.refresh === 'function') window.PET.refresh();
            });
        }
    }
});
</script>
</body>
</html>
















